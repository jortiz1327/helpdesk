<?php

namespace Tests\Unit;

use App\Services\Ai\Code128Reader;
use PHPUnit\Framework\TestCase;

/** El lector local de Code 128 con códigos generados aquí (el algoritmo es el de la rama warranties). */
class Code128ReaderTest extends TestCase
{
    private const PAT = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];

    /** Imagen PNG/JPEG con el Code 128 (juego B) de $texto; $inv = barras claras sobre negro. */
    public static function foto(string $texto, int $mod = 3, bool $inv = false, int $giro = 0): string
    {
        $sims = [104];
        $suma = 104;
        foreach (str_split($texto) as $i => $ch) {
            $v = ord($ch) - 32;
            $sims[] = $v;
            $suma += ($i + 1) * $v;
        }
        $sims[] = $suma % 103;
        $sims[] = 106;
        $anchos = [];
        foreach ($sims as $s) {
            foreach (str_split(self::PAT[$s]) as $a) {
                $anchos[] = (int) $a;
            }
        }
        $quiet = 12 * $mod;
        $w = $quiet * 2 + array_sum($anchos) * $mod;
        $h = 160;
        $im = imagecreatetruecolor($w, $h);
        $claro = imagecolorallocate($im, 235, 235, 235);
        $oscuro = imagecolorallocate($im, 20, 20, 20);
        imagefill($im, 0, 0, $inv ? $oscuro : $claro);
        $x = $quiet;
        foreach ($anchos as $i => $a) {
            if ($i % 2 === 0) {
                imagefilledrectangle($im, $x, 20, $x + $a * $mod - 1, $h - 20, $inv ? $claro : $oscuro);
            }
            $x += $a * $mod;
        }
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
        if ($giro) {
            $r = imagerotate($im, $giro, 0);
            imagedestroy($im);
            $im = $r;
        }
        $ruta = tempnam(sys_get_temp_dir(), 'c128') . '.png';
        imagepng($im, $ruta);
        imagedestroy($im);
        return $ruta;
    }

    public function test_lee_un_code128_normal(): void
    {
        $f = self::foto('AB12CD345');
        $this->assertSame(['AB12CD345'], (new Code128Reader())->leerArchivo($f));
        @unlink($f);
    }

    public function test_lee_barras_claras_sobre_fondo_negro_y_pequeño(): void
    {
        $f = self::foto('ZK9X0Q7LM', 2, true);
        $this->assertSame(['ZK9X0Q7LM'], (new Code128Reader())->leerArchivo($f));
        @unlink($f);
    }

    public function test_lee_un_codigo_girado_90_grados(): void
    {
        $f = self::foto('AB12CD345', 3, false, 90);
        $this->assertSame(['AB12CD345'], (new Code128Reader())->leerArchivo($f));
        @unlink($f);
    }

    public function test_ignora_codigos_que_no_son_de_etiqueta(): void
    {
        $f = self::foto('SCL-C-02-XXXXXX');   // 15 caracteres: no es el código de una etiqueta
        $this->assertSame([], (new Code128Reader())->leerArchivo($f));
        @unlink($f);
    }

    public function test_una_imagen_sin_codigo_o_un_archivo_que_no_es_imagen_no_lee_nada(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'c128');
        file_put_contents($f, 'esto no es una imagen');
        $this->assertSame([], (new Code128Reader())->leerArchivo($f));
        $this->assertSame([], (new Code128Reader())->leerArchivo($f . '.no-existe'));
        @unlink($f);
    }
}
