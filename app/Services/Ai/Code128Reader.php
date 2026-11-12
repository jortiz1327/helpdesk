<?php

namespace App\Services\Ai;

/**
 * Lector propio de Code 128 (el código de las etiquetas electrónicas), en local: la foto no
 * sale del servidor. Es el mismo algoritmo que frontend/src/code128.js de la rama
 * warranties, pasado a PHP con GD.
 *
 *  · los bordes se sitúan con precisión de subpíxel;
 *  · cada carácter se mide de borde a borde y se ajusta por mínimos cuadrados;
 *  · si un carácter queda entre dos símbolos se prueban los dos, y solo vale la lectura
 *    que cuadra con el dígito de control (así no se inventa un código);
 *  · se prueban las dos polaridades, los dos sentidos y la foto girada 90°.
 *
 * leerArchivo() devuelve solo códigos que parecen de etiqueta (9 letras/números) o de
 * antena (MAC de 12), el más claro primero. Vacío si no lee ninguno.
 */
class Code128Reader
{
    private const LIMITE = 0.3;
    private const DUDA = 0.07;
    private const MAX_RAMAS = 16;
    private const LADO = 1800;   // lado largo al que se reduce la foto

    private const PAT = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];

    /** @var array<int, array{p:array<int,int>, pp:float, ps:float, n:int}> */
    private array $aj = [];

    public function __construct()
    {
        foreach (self::PAT as $s) {
            $p = array_map('intval', str_split($s));
            $pp = 0;
            $ps = 0;
            foreach ($p as $j => $v) {
                $pp += $v * $v;
                $ps += $j % 2 ? -$v : $v;
            }
            $this->aj[] = ['p' => $p, 'pp' => $pp, 'ps' => $ps, 'n' => count($p)];
        }
    }

    /** @return string[] códigos de etiqueta/antena leídos en la imagen (ruta de archivo) */
    public function leerArchivo(string $ruta): array
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($ruta) || filesize($ruta) > 15 * 1024 * 1024) {
            return [];
        }
        if (! @getimagesize($ruta)) {
            return [];
        }
        $img = @imagecreatefromstring((string) file_get_contents($ruta));
        if (! $img) {
            return [];
        }
        $img = $this->orientar($img, $ruta);
        $halla = [];
        try {
            foreach ([0, 90] as $giro) {
                [$lum, $w, $h] = $this->grises($img, $giro);
                foreach ([1, 2] as $desde) {
                    foreach ($this->leer($lum, $w, $h, 2, $desde) as $t => $v) {
                        $c = strtoupper(preg_replace('/[\s:\-]/', '', (string) $t));
                        if (! preg_match('/^[A-Z0-9]{9}$/', $c) && ! preg_match('/^[0-9A-F]{12}$/', $c)) {
                            continue;
                        }
                        $x = $halla[$c] ?? ['n' => 0, 'seguro' => false];
                        $x['n'] += $v['n'];
                        $x['seguro'] = $x['seguro'] || $v['seguro'];
                        $halla[$c] = $x;
                    }
                    if ($halla) {
                        break;
                    }
                }
            }
        } finally {
            imagedestroy($img);
        }
        if (array_filter($halla, fn ($v) => $v['seguro'])) {
            $halla = array_filter($halla, fn ($v) => $v['seguro']);
        }
        uasort($halla, fn ($a, $b) => $b['n'] <=> $a['n']);
        return array_map('strval', array_keys($halla));
    }

    /** Respeta la orientación EXIF del móvil. */
    private function orientar($img, string $ruta)
    {
        if (! function_exists('exif_read_data') || (@getimagesize($ruta)[2] ?? 0) !== IMAGETYPE_JPEG) {
            return $img;
        }
        $e = @exif_read_data($ruta);
        $giro = match ((int) ($e['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($giro) {
            $r = imagerotate($img, $giro, 0);
            if ($r) {
                imagedestroy($img);
                return $r;
            }
        }
        return $img;
    }

    /** @return array{0: array<int,int>, 1:int, 2:int} luminancia (fila a fila), ancho y alto, con la foto girada $giro° */
    private function grises($img, int $giro): array
    {
        $w0 = imagesx($img);
        $h0 = imagesy($img);
        $k = min(1, self::LADO / max($w0, $h0));
        $w = max(1, (int) round($w0 * $k));
        $h = max(1, (int) round($h0 * $k));
        $c = imagecreatetruecolor($w, $h);
        imagecopyresampled($c, $img, 0, 0, 0, 0, $w, $h, $w0, $h0);
        if ($giro) {
            $r = imagerotate($c, -$giro, 0);
            imagedestroy($c);
            $c = $r;
            $w = imagesx($c);
            $h = imagesy($c);
        }
        $lum = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = imagecolorat($c, $x, $y);
                $lum[$y * $w + $x] = ((($p >> 16) & 255) * 299 + (($p >> 8) & 255) * 587 + ($p & 255) * 114) / 1000;
            }
        }
        imagedestroy($c);
        return [$lum, $w, $h];
    }

    private function error(array $w, int $i, array $a): float
    {
        $pw = 0.0;
        $sw = 0.0;
        $ww = 0.0;
        for ($j = 0; $j < $a['n']; $j++) {
            $v = $w[$i + $j];
            $pw += $a['p'][$j] * $v;
            $sw += $j % 2 ? -$v : $v;
            $ww += $v * $v;
        }
        $ss = $a['n'];
        $det = $a['pp'] * $ss - $a['ps'] * $a['ps'];
        $u = ($pw * $ss - $a['ps'] * $sw) / $det;
        $e = ($a['pp'] * $sw - $a['ps'] * $pw) / $det;
        if (! ($u > 0) || abs($e) > 0.75 * $u) {
            return INF;
        }
        $r = max(0.0, $ww - $u * $pw - $e * $sw);
        return sqrt($r / $a['n']) / $u;
    }

    /** Símbolos que encajan con 6 anchos medidos: [], [s] o [s, alternativa]. */
    private function candidatos(array $w, int $i): array
    {
        $s1 = -1;
        $d1 = INF;
        $s2 = -1;
        $d2 = INF;
        for ($s = 0; $s < 106; $s++) {
            $d = $this->error($w, $i, $this->aj[$s]);
            if ($d < $d1) {
                $s2 = $s1; $d2 = $d1; $s1 = $s; $d1 = $d;
            } elseif ($d < $d2) {
                $s2 = $s; $d2 = $d;
            }
        }
        if ($d1 > self::LIMITE) {
            return [];
        }
        return $d2 <= self::LIMITE && $d2 - $d1 < self::DUDA ? [$s1, $s2] : [$s1];
    }

    private function esParada(array $w, int $i): bool
    {
        return $i + 7 <= count($w) && $this->error($w, $i, $this->aj[106]) <= self::LIMITE * 1.5;
    }

    private function texto(array $sims): ?string
    {
        $n = count($sims);
        if ($n < 3) {
            return null;
        }
        $suma = $sims[0];
        for ($i = 1; $i < $n - 1; $i++) {
            $suma += $i * $sims[$i];
        }
        if ($suma % 103 !== $sims[$n - 1]) {
            return null;
        }
        $set = $sims[0] === 103 ? 'A' : ($sims[0] === 104 ? 'B' : 'C');
        $out = '';
        foreach (array_slice($sims, 1, $n - 2) as $v) {
            if ($set === 'C') {
                if ($v < 100) {
                    $out .= str_pad((string) $v, 2, '0', STR_PAD_LEFT);
                } elseif ($v === 100) {
                    $set = 'B';
                } elseif ($v === 101) {
                    $set = 'A';
                }
            } elseif ($v === 99) {
                $set = 'C';
            } elseif ($v === 100 && $set === 'A') {
                $set = 'B';
            } elseif ($v === 101 && $set === 'B') {
                $set = 'A';
            } elseif ($v < 96) {
                $out .= $set === 'B' ? chr($v + 32) : chr($v < 64 ? $v + 32 : $v - 64);
            }
        }
        return $out !== '' ? $out : null;
    }

    /** Intenta leer desde cada barra de $w (anchos alternos, empezando por una barra). */
    private function leerAnchos(array $w, callable $apunta): void
    {
        $n = count($w);
        for ($i = 0; $i + 6 <= $n; $i += 2) {
            if ($this->error($w, $i, $this->aj[103]) > self::LIMITE && $this->error($w, $i, $this->aj[104]) > self::LIMITE && $this->error($w, $i, $this->aj[105]) > self::LIMITE) {
                continue;
            }
            $ini = array_values(array_filter($this->candidatos($w, $i), fn ($s) => $s >= 103 && $s <= 105));
            if (! $ini) {
                continue;
            }
            $pos = [$ini];
            $j = $i + 6;
            $fin = false;
            while ($j + 6 <= $n && count($pos) < 40) {
                if ($this->esParada($w, $j)) {
                    $fin = true;
                    break;
                }
                $c = $this->candidatos($w, $j);
                if (! $c) {
                    break;
                }
                $pos[] = $c;
                $j += 6;
            }
            if (! $fin || count($pos) < 3) {
                continue;
            }
            $ramas = 1;
            foreach ($pos as $c) {
                $ramas *= count($c);
            }
            if ($ramas > self::MAX_RAMAS) {
                continue;
            }
            $combos = [[]];
            foreach ($pos as $c) {
                $nuevo = [];
                foreach ($combos as $a) {
                    foreach ($c as $s) {
                        $nuevo[] = [...$a, $s];
                    }
                }
                $combos = $nuevo;
            }
            foreach ($combos as $sims) {
                $t = $this->texto($sims);
                if ($t !== null) {
                    $apunta($t, $ramas > 1);
                }
            }
            $i = $j;   // lo leído no se vuelve a probar desde dentro
        }
    }

    /**
     * Lee los Code 128 de una imagen en grises, recorriendo filas cada $paso px desde $desde.
     * @return array<string, array{n:int, seguro:bool}> texto → filas en que salió y si alguna vez salió sin dudas
     */
    private function leer(array $lum, int $w, int $h, int $paso, int $desde): array
    {
        $votos = [];
        $fila = array_fill(0, $w, 0.0);
        $umbral = array_fill(0, $w, 0.0);
        $pref = array_fill(0, $w + 1, 0.0);
        $ventanas = array_values(array_unique([5, 10, 20, max(30, (int) round($w / 12))]));
        foreach ($ventanas as $ventana) {
            for ($y = max(1, $desde); $y < $h - 1; $y += $paso) {
                $mn = 255;
                $mx = 0;
                $o = ($y - 1) * $w;
                for ($x = 0; $x < $w; $x++) {
                    $v = ($lum[$o + $x] + 2 * $lum[$o + $w + $x] + $lum[$o + 2 * $w + $x]) / 4;
                    $fila[$x] = $v;
                    $pref[$x + 1] = $pref[$x] + $v;
                    if ($v < $mn) $mn = $v;
                    if ($v > $mx) $mx = $v;
                }
                if ($mx - $mn < 35) {
                    continue;
                }
                for ($x = 0; $x < $w; $x++) {
                    $a = max(0, $x - $ventana);
                    $b = min($w, $x + $ventana + 1);
                    $umbral[$x] = ($pref[$b] - $pref[$a]) / ($b - $a);
                }
                // Bordes con precisión de subpíxel.
                $pos = [];
                $oscuro = $fila[0] < $umbral[0];
                $primeroOscuro = $oscuro;
                for ($x = 1; $x < $w; $x++) {
                    $os = $fila[$x] < $umbral[$x];
                    if ($os !== $oscuro) {
                        $a = $fila[$x - 1] - $umbral[$x - 1];
                        $b = $fila[$x] - $umbral[$x];
                        $pos[] = $x - 1 + ($a == $b ? 0.5 : $a / ($a - $b));
                        $oscuro = $os;
                    }
                }
                if (count($pos) < 30) {
                    continue;
                }
                $anchos = [];
                for ($i = 1; $i < count($pos); $i++) {
                    $anchos[] = $pos[$i] - $pos[$i - 1];
                }
                $empiezaOscuro = ! $primeroOscuro;
                $apunta = function (string $t, bool $dudoso) use (&$votos, $y) {
                    $v = $votos[$t] ?? ['filas' => [], 'seguro' => false];
                    $v['filas'][$y] = true;
                    if (! $dudoso) $v['seguro'] = true;
                    $votos[$t] = $v;
                };
                foreach ([true, false] as $barraOscura) {
                    $p = $empiezaOscuro === $barraOscura ? 0 : 1;
                    $this->leerAnchos(array_slice($anchos, $p), $apunta);
                    $rev = array_reverse($anchos);
                    $ultimoOscuro = (count($anchos) - 1) % 2 === 0 ? $empiezaOscuro : ! $empiezaOscuro;
                    $this->leerAnchos(array_slice($rev, $ultimoOscuro === $barraOscura ? 0 : 1), $apunta);
                }
            }
        }
        $out = [];
        foreach ($votos as $t => $v) {
            if ($v['seguro'] || count($v['filas']) >= 2) {
                $out[(string) $t] = ['n' => count($v['filas']), 'seguro' => $v['seguro']];
            }
        }
        return $out;
    }
}
