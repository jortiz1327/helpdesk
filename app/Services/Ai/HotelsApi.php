<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta de SOLO LECTURA a la API de soporte del panel de AppHoteles (/api/support/v1): saber de qué
 * hotel/buffet es un correo y ver el estado de las antenas y etiquetas de ese buffet. El panel reparte
 * las consultas entre las apps; aquí no hay URLs ni claves de hoteles, solo la del panel (AI_SUPPORT_*).
 *
 * Seguridad:
 *  · La IA NUNCA elige qué se pide: el código llama a estas rutas fijas y le pasa el resultado.
 *  · Solo GET, solo https, ids siempre enteros. La respuesta ya viene filtrada por el panel (sin IP ni MAC).
 *  · Sin URL o sin clave, todo devuelve vacío y no sale ninguna petición.
 *  · Si el panel no contesta (o contesta con errores), se devuelve vacío: la IA no inventa ni verifica.
 */
class HotelsApi
{
    private const CACHE_HOTELES = 86400;   // 1 día
    private const CACHE_CORREO = 14400;    // 4 horas
    private const ESPERA_CORREO = 25.0;     // lo máximo que espera un agente por la comprobación del correo (todo incluido)
    private const LENTA = 6.0;              // una consulta que tarda más se anota como «lenta»
    private const INCIDENCIAS = 'ai_support_incidencias';

    public function configured(): bool
    {
        return $this->base() !== '' && (string) config('ai.support.key', '') !== '';
    }

    private function base(): string
    {
        $u = rtrim((string) config('ai.support.url', ''), '/');
        return str_starts_with($u, 'https://') ? $u : '';
    }

    /** GET de una ruta fija. Devuelve data o null si falla. */
    private function get(string $ruta, array $query = [], ?float $timeout = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        $t0 = microtime(true);
        try {
            $r = Http::timeout($timeout ?? (float) config('ai.support.timeout', 12))->acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('ai.support.key'), 'X-Actor' => 'IA-Soporte'])
                ->get($this->base() . $ruta, $query);
            if (! $r->successful()) {
                $this->anotar($ruta, microtime(true) - $t0, 'HTTP ' . $r->status());
                return null;
            }
            $d = $r->json('data');
            $seg = microtime(true) - $t0;
            $sinApp = is_array($d) && ! empty($d['apps_sin_respuesta']);
            if ($sinApp || $seg > self::LENTA) {
                $this->anotar($ruta, $seg, $sinApp ? 'una app del panel no respondió' : 'lenta');
            }
            return is_array($d) ? $d : null;
        } catch (Throwable $e) {
            $this->anotar($ruta, microtime(true) - $t0, 'sin respuesta');
            return null;
        }
    }

    /** Apunta que el panel ha ido lento o ha fallado (solo la ruta, nunca correos ni nombres). Se ve en Ajustes › Asistente IA. */
    private function anotar(string $ruta, float $segundos, string $motivo): void
    {
        $ruta = preg_replace('#/[0-9a-fA-F-]{8,40}(?=/|$)|/[0-9]+(?=/|$)#', '/{id}', $ruta);
        try {
            $l = (array) Cache::get(self::INCIDENCIAS, []);
            array_unshift($l, ['at' => now()->toIso8601String(), 'ruta' => $ruta, 'seg' => round($segundos, 1), 'motivo' => $motivo]);
            Cache::put(self::INCIDENCIAS, array_slice($l, 0, 40), 604800);
            Log::warning('AdminHoteles: ' . $motivo . ' (' . round($segundos, 1) . ' s) en ' . $ruta);
        } catch (Throwable $e) {
            // anotar nunca debe romper una sugerencia
        }
    }

    /**
     * Cómo ha ido el panel en las últimas 24 h, para avisar a quien lo gestiona.
     *
     * @return array{nivel:string, fallos:int, apps:int, lentas:int, ultima:?array, recientes:array}
     */
    public static function estadoPanel(): array
    {
        $desde = now()->subDay();
        $l = array_values(array_filter((array) Cache::get(self::INCIDENCIAS, []), fn ($x) => ! empty($x['at']) && Carbon::parse($x['at'])->gte($desde)));
        $apps = count(array_filter($l, fn ($x) => $x['motivo'] === 'una app del panel no respondió'));
        $lentas = count(array_filter($l, fn ($x) => $x['motivo'] === 'lenta'));
        $fallos = count($l) - $apps - $lentas;
        return [
            'nivel'     => $fallos + $apps >= 3 ? 'mal' : ($l ? 'aviso' : 'ok'),
            'fallos'    => $fallos, 'apps' => $apps, 'lentas' => $lentas,
            'ultima'    => $l[0] ?? null,
            'recientes' => array_slice($l, 0, 5),
        ];
    }

    /** Primer valor presente de una lista de posibles nombres de campo. */
    private static function pick(array $a, array $claves, $defecto = null)
    {
        foreach ($claves as $c) {
            if (array_key_exists($c, $a) && $a[$c] !== null && $a[$c] !== '') {
                return $a[$c];
            }
        }
        return $defecto;
    }

    /** Los ids del panel son UUID (o números): nada más entra en una ruta. */
    private static function idValido(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F-]{8,40}$|^\d{1,10}$/', $id);
    }

    private static function nombre($x): string
    {
        return is_array($x) ? (string) self::pick($x, ['nombre', 'name', 'hotel', 'buffet'], '') : (string) $x;
    }

    /**
     * Dónde figura este correo como usuario activo (puede ser en varios buffets).
     *
     * @return array<int, array{buffet_id:string, buffet:string, hotel:string}>
     */
    public function buscarPorCorreo(?string $email): array
    {
        return $this->verificar($email)['sitios'];
    }

    /**
     * ¿Este correo es de un usuario de la aplicación? Estados:
     *  · apagada       sin configurar: no se pregunta nada
     *  · sin_correo    no hay un correo válido que comprobar
     *  · encontrado    figura como usuario activo (sitios = dónde)
     *  · no_encontrado ninguna app lo conoce (y TODAS contestaron)
     *  · incompleto    no se pudo comprobar del todo (el panel no contesta o alguna app no respondió):
     *                  NO se puede afirmar que el correo no pertenece al hotel
     *
     * @return array{estado:string, sitios:array<int, array{buffet_id:string, buffet:string, hotel:string}>}
     */
    public function verificar(?string $email): array
    {
        if (! $this->configured()) {
            return ['estado' => 'apagada', 'sitios' => []];
        }
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['estado' => 'sin_correo', 'sitios' => []];
        }
        // Un correo que figura se recuerda unas horas: el lookup obliga al panel a preguntar a cada app de hotel (a veces 12-25 s)
        // y el mismo cliente se consulta varias veces (regenerar, varios mensajes). Solo se guarda «encontrado»: un fallo o un
        // «no figura» se vuelve a comprobar siempre.
        $clave = 'ai_support_lookup_' . sha1($email);
        $guardado = Cache::get($clave);
        if (is_array($guardado) && ! empty($guardado['sitios'])) {
            return $guardado;
        }
        $inicio = microtime(true);
        $d = $this->get('/api/support/v1/users/lookup', ['email' => $email], self::ESPERA_CORREO);
        // El panel contesta pero a veces una de sus apps no: una segunda consulta suele salir bien, y evita decir «no hemos podido
        // comprobar su correo» cuando sí figura. Si el panel mismo no contesta (null, tras el tiempo máximo) NO se reintenta: sería
        // esperar otra vez lo mismo con el agente mirando.
        $queda = self::ESPERA_CORREO - (microtime(true) - $inicio) - 0.7;
        if ($d !== null && empty($d['encontrado']) && ((array) ($d['apps_sin_respuesta'] ?? [])) && $queda >= 2) {
            usleep(700000);
            $d2 = $this->get('/api/support/v1/users/lookup', ['email' => $email], $queda);
            if ($d2 !== null) {
                $d = $d2;
            }
        }
        if ($d === null) {
            return ['estado' => 'incompleto', 'sitios' => []];
        }
        if (! empty($d['encontrado'])) {
            $r = ['estado' => 'encontrado', 'sitios' => $this->completarBuffets($this->sitiosDe($d))];
            if ($r['sitios']) {
                Cache::put($clave, $r, self::CACHE_CORREO);
            }
            return $r;
        }
        return ['estado' => ((array) ($d['apps_sin_respuesta'] ?? [])) ? 'incompleto' : 'no_encontrado', 'sitios' => []];
    }

    /**
     * Con el índice central el lookup NO trae buffets (solo el hotel, el rol y si está activo): sin su id no se pueden consultar las antenas ni
     * las etiquetas. Se completan desde el propio hotel (hotels/{id}/buffets, en caché). Solo si son pocos hoteles: un usuario de cadena con
     * decenas de hoteles se queda con los nombres, que es lo que hace falta para verificar.
     *
     * @param  array<int, array<string, mixed>>  $sitios
     * @return array<int, array<string, mixed>>
     */
    private function completarBuffets(array $sitios): array
    {
        $faltan = array_filter($sitios, fn ($x) => ($x['buffet_id'] ?? '') === '' && self::idValido((string) ($x['hotel_id'] ?? '')));
        if (! $faltan || count($faltan) > 4) {
            return $sitios;
        }
        $out = [];
        foreach ($sitios as $x) {
            $bs = isset($faltan[array_search($x, $sitios, true)]) ? $this->buffetsDeHotel((string) $x['hotel_id']) : null;
            if (! $bs) {
                $out['x' . count($out)] = $x;
                continue;
            }
            foreach ($bs as $b) {
                $out['b' . $b['buffet_id']] = array_merge($x, ['buffet_id' => $b['buffet_id'], 'buffet' => $b['buffet'], 'hotel' => $x['hotel'] !== '' ? $x['hotel'] : $b['hotel']]);
            }
        }
        return array_values($out);
    }

    /** Hoteles/buffets de las coincidencias de /users/lookup (tolera variantes de nombres de campo). */
    private function sitiosDe(array $d): array
    {
        $out = [];
        foreach ((array) ($d['coincidencias'] ?? []) as $c) {
            $hoteles = array_values((array) ($c['hoteles'] ?? []));
            // Rol y estado del usuario en esa app (la API no da los permisos uno a uno, solo el nombre del rol).
            $u = (array) ($c['usuario'] ?? []);
            $rol = isset($u['rol']) && is_string($u['rol']) && trim($u['rol']) !== '' ? trim($u['rol']) : null;
            $activo = array_key_exists('activo', $u) ? (bool) $u['activo'] : null;
            // Cada buffet trae solo hotel_id: el nombre del hotel está en la lista «hoteles» de la misma coincidencia.
            $nombrePorId = [];
            foreach ($hoteles as $h) {
                if (is_array($h) && isset($h['id'])) {
                    $nombrePorId[(string) $h['id']] = self::nombre($h);
                }
            }
            $conBuffet = false;
            foreach ((array) ($c['buffets'] ?? []) as $b) {
                $id = is_array($b) ? (string) self::pick($b, ['id', 'buffet_id'], '') : '';
                if (! self::idValido($id)) {
                    continue;
                }
                $conBuffet = true;
                $hotel = is_array($b) && self::pick($b, ['hotel', 'hotel_nombre', 'hotel_name']) !== null
                    ? self::nombre(self::pick($b, ['hotel', 'hotel_nombre', 'hotel_name']))
                    : ($nombrePorId[(string) (is_array($b) ? ($b['hotel_id'] ?? '') : '')] ?? (count($hoteles) === 1 ? self::nombre($hoteles[0]) : ''));
                $hid = is_array($b) ? (string) ($b['hotel_id'] ?? '') : '';
                if ($hid === '' && count($hoteles) === 1 && is_array($hoteles[0])) {
                    $hid = (string) ($hoteles[0]['id'] ?? '');
                }
                $out[$id] = ['buffet_id' => $id, 'buffet' => self::nombre($b), 'hotel' => $hotel, 'hotel_id' => $hid, 'rol' => $rol, 'activo' => $activo];
            }
            if (! $conBuffet) {   // sin buffets utilizables: al menos el nombre de los hoteles
                foreach ($hoteles as $h) {
                    $out['h:' . self::norm(self::nombre($h))] = ['buffet_id' => '', 'buffet' => '', 'hotel' => self::nombre($h), 'hotel_id' => is_array($h) ? (string) ($h['id'] ?? '') : '', 'rol' => $rol, 'activo' => $activo];
                }
            }
        }
        return array_values($out);
    }

    /** Texto sin tildes, minúsculas y solo letras/números, para comparar nombres de hotel. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /**
     * Busca hoteles por nombre en el inventario del panel. Devuelve hasta 5 [{nombre, grupo}],
     * o null si no se pudo consultar (distinto de «no hay ninguno», que es []).
     */
    public function buscarHoteles(string $nombre): ?array
    {
        $nombre = trim($nombre);
        if (! $this->configured() || mb_strlen($nombre) < 3) {
            return null;
        }
        // La lista de hoteles casi no cambia: lo que ya se encontró se recuerda un día. Lo que NO se encuentra no se guarda
        // (puede ser un hotel recién dado de alta) y se vuelve a preguntar al panel.
        $clave = 'ai_support_hoteles_' . sha1(self::norm($nombre));
        $guardado = Cache::get($clave);
        if (is_array($guardado) && $guardado) {
            return $guardado;
        }
        $d = $this->get('/api/support/v1/hotels', ['q' => $nombre, 'per_page' => 5]);
        if ($d === null) {
            return null;
        }
        // «Playas de Guardamar» no encuentra «Hotel Playas Guardamar»: si no hay nada, se reintenta sin las palabras de enlace.
        if (! ((array) ($d['hoteles'] ?? []))) {
            $sin = trim(preg_replace('/\b(de|del|la|las|los|el|y)\b\s*/iu', '', $nombre));
            if ($sin !== '' && $sin !== $nombre && mb_strlen($sin) >= 3) {
                $d = $this->get('/api/support/v1/hotels', ['q' => $sin, 'per_page' => 5]) ?? $d;
            }
        }
        $res = array_map(fn ($h) => ['id' => (string) ($h['id'] ?? ''), 'nombre' => (string) ($h['nombre'] ?? ''), 'grupo' => (string) ($h['grupo'] ?? '')], array_slice(array_values((array) ($d['hoteles'] ?? [])), 0, 5));
        // El buscador del panel exige la frase seguida («Bahía Príncipe Turquesa» no encuentra «Bahia Principe Grand Turquesa»):
        // si no hay nada, se busca palabra a palabra y solo valen los hoteles que contienen varias de ellas.
        if (! $res && $d !== null) {
            $res = $this->buscarPorPalabras($nombre);
        }
        if ($res) {
            Cache::put($clave, $res, self::CACHE_HOTELES);
        }
        return $res;
    }

    /** Palabras de un nombre que sirven para identificar un hotel (sin tildes, sin palabras genéricas). */
    public static function palabrasClave(string $nombre): array
    {
        $gen = ['hotel', 'hoteles', 'resort', 'grupo', 'spa', 'beach', 'club', 'apartamentos', 'aparthotel', 'del', 'los', 'las'];
        return array_values(array_unique(array_filter(explode(' ', self::norm($nombre)), fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, $gen, true))));
    }

    /** Hoteles que comparten al menos 2 palabras clave con el nombre (los de más coincidencias, hasta 5). [] si ninguno. */
    private function buscarPorPalabras(string $nombre): array
    {
        $pal = array_slice(self::palabrasClave($nombre), 0, 4);
        if (count($pal) < 2) {
            return [];
        }
        $vistos = [];
        foreach ($pal as $w) {
            $d = $this->get('/api/support/v1/hotels', ['q' => $w, 'per_page' => 10]);
            foreach ((array) ($d['hoteles'] ?? []) as $h) {
                $vistos[(string) ($h['id'] ?? $h['nombre'] ?? '')] = ['id' => (string) ($h['id'] ?? ''), 'nombre' => (string) ($h['nombre'] ?? ''), 'grupo' => (string) ($h['grupo'] ?? '')];
            }
        }
        $max = 0;
        $mejores = [];
        foreach ($vistos as $h) {
            $n = ' ' . self::norm($h['nombre']) . ' ';
            $p = count(array_filter($pal, fn ($w) => str_contains($n, " $w ")));
            if ($p > $max) { $max = $p; $mejores = []; }
            if ($p === $max && $p > 0) { $mejores[] = $h; }
        }
        return $max >= 2 ? array_slice($mejores, 0, 5) : [];
    }

    /**
     * Buffets de un hotel ya localizado (para ver el estado de sus antenas sin verificar el correo: ese dato no es sensible).
     * Misma forma que los «sitios» de verificar(): [{buffet_id, buffet, hotel}]. null si no se pudo consultar.
     */
    public function buffetsDeHotel(string $hotelId): ?array
    {
        if (! self::idValido($hotelId)) {
            return null;
        }
        $d = Cache::remember("ai_support_hotel_buffets_$hotelId", 300, fn () => $this->get("/api/support/v1/hotels/$hotelId/buffets") ?? []);
        if (! $d) {
            return null;
        }
        $hotel = self::nombre($d['hotel'] ?? '');
        $out = [];
        foreach (array_slice(array_values((array) ($d['buffets'] ?? [])), 0, 6) as $b) {
            $id = is_array($b) ? (string) self::pick($b, ['id', 'buffet_id'], '') : '';
            if (self::idValido($id)) {
                $out[] = ['buffet_id' => $id, 'buffet' => self::nombre($b), 'hotel' => $hotel];
            }
        }
        return $out;
    }

    /**
     * Estado de las antenas de un buffet: [{name, online, offline_since, esl}]. null si no se pudo leer.
     */
    public function antenas(array $sitio): ?array
    {
        $id = (string) ($sitio['buffet_id'] ?? '');
        if (! self::idValido($id)) {
            return null;
        }
        $d = Cache::remember("ai_support_antenas_$id", 120, fn () => $this->get("/api/support/v1/buffets/$id/antennas") ?? []);
        if (! $d) {
            return null;
        }
        $lista = isset($d['antenas']) ? $d['antenas'] : (isset($d['antennas']) ? $d['antennas'] : $d);
        return array_map(fn ($a) => [
            'name'          => (string) self::pick((array) $a, ['nombre', 'name', 'apName'], 'Antena'),
            'online'        => (bool) self::pick((array) $a, ['en_linea', 'online', 'is_online'], false),
            'offline_since' => self::pick((array) $a, ['caida_desde', 'offline_since']),
            'esl'           => (int) self::pick((array) $a, ['etiquetas', 'esl', 'eslCount'], 0),
            'consultado'    => $d['consultado'] ?? null,
            'en_vivo'       => $d['en_vivo'] ?? null,
        ], array_values((array) $lista));
    }

    /**
     * Estado de UNA etiqueta (por su código) dentro de ese buffet: en línea, batería, señal, última
     * actualización y su antena. null si no es de ese buffet. Nunca de otro hotel.
     */
    public function etiqueta(array $sitio, string $codigo): ?array
    {
        $id = (string) ($sitio['buffet_id'] ?? '');
        $codigo = mb_strtoupper(trim($codigo));
        if (! self::idValido($id) || $codigo === '') {
            return null;
        }
        $d = $this->get("/api/support/v1/buffets/$id/tags", ['code' => $codigo]);
        if (! $d) {
            return null;
        }
        $t = isset($d[0]) && is_array($d[0]) ? $d[0] : (isset($d['data'][0]) ? $d['data'][0] : (isset($d['etiqueta']) ? $d['etiqueta'] : $d));
        if (! is_array($t) || $t === [] || (isset($t[0]) && ! is_array($t[0]))) {
            return null;
        }
        $ap = self::pick($t, ['antena', 'antenna']);
        $bat = self::pick($t, ['bateria', 'battery']);
        $sig = self::pick($t, ['senal', 'señal', 'signal']);
        return [
            'code'       => $codigo,
            'online'     => (bool) self::pick($t, ['en_linea', 'online', 'is_online'], false),
            'battery'    => $bat !== null ? (int) $bat : null,
            'signal'     => $sig !== null ? (int) $sig : null,
            'updated_at' => self::pick($t, ['actualizada', 'ultima_actualizacion', 'updated_at']),
            'antenna'    => is_array($ap) ? ['name' => self::nombre($ap), 'online' => (bool) self::pick($ap, ['en_linea', 'online'], false)] : null,
        ];
    }
}
