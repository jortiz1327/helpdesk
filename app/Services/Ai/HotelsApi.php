<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
    private function get(string $ruta, array $query = []): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        try {
            $r = Http::timeout((int) config('ai.support.timeout', 12))->acceptJson()
                ->withHeaders(['X-Api-Key' => (string) config('ai.support.key'), 'X-Actor' => 'IA-Soporte'])
                ->get($this->base() . $ruta, $query);
            if (! $r->successful()) {
                return null;
            }
            $d = $r->json('data');
            return is_array($d) ? $d : null;
        } catch (Throwable $e) {
            report($e);
            return null;
        }
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

    private static function nombre($x): string
    {
        return is_array($x) ? (string) self::pick($x, ['nombre', 'name', 'hotel', 'buffet'], '') : (string) $x;
    }

    /**
     * Dónde figura este correo como usuario activo (puede ser en varios buffets).
     *
     * @return array<int, array{buffet_id:int, buffet:string, hotel:string}>
     */
    public function buscarPorCorreo(?string $email): array
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $this->configured()) {
            return [];
        }
        $d = $this->get('/api/support/v1/users/lookup', ['email' => $email]);
        if (! $d || empty($d['encontrado'])) {
            return [];
        }
        $out = [];
        foreach ((array) ($d['coincidencias'] ?? []) as $c) {
            $hoteles = array_values((array) ($c['hoteles'] ?? []));
            foreach ((array) ($c['buffets'] ?? []) as $b) {
                $id = is_array($b) ? (int) self::pick($b, ['id', 'buffet_id'], 0) : 0;
                if ($id <= 0) {
                    continue;
                }
                $hotel = is_array($b) && self::pick($b, ['hotel', 'hotel_nombre', 'hotel_name']) !== null
                    ? self::nombre(self::pick($b, ['hotel', 'hotel_nombre', 'hotel_name']))
                    : (count($hoteles) === 1 ? self::nombre($hoteles[0]) : '');
                $out[$id] = ['buffet_id' => $id, 'buffet' => self::nombre($b), 'hotel' => $hotel];
            }
        }
        return array_values($out);
    }

    /**
     * Estado de las antenas de un buffet: [{name, online, offline_since, esl}]. null si no se pudo leer.
     */
    public function antenas(array $sitio): ?array
    {
        $id = (int) ($sitio['buffet_id'] ?? 0);
        if ($id <= 0) {
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
        ], array_values((array) $lista));
    }

    /**
     * Estado de UNA etiqueta (por su código) dentro de ese buffet: en línea, batería, señal, última
     * actualización y su antena. null si no es de ese buffet. Nunca de otro hotel.
     */
    public function etiqueta(array $sitio, string $codigo): ?array
    {
        $id = (int) ($sitio['buffet_id'] ?? 0);
        $codigo = mb_strtoupper(trim($codigo));
        if ($id <= 0 || $codigo === '') {
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
