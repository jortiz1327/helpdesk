<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta de SOLO LECTURA a la API de gestión de AppHoteles (la flota de apps de los hoteles).
 * Sirve para dos cosas: saber de qué hotel/buffet es un correo (¿figura como usuario en la app?)
 * y ver el estado de las antenas de ese buffet.
 *
 * Seguridad:
 *  · La IA NUNCA elige qué se pide: el código llama a estas rutas fijas y le pasa el resultado.
 *  · Lista cerrada de rutas, todas GET (no hay forma de llegar a borrar, restaurar ni revelar credenciales).
 *  · Las claves van SOLO en el .env (AI_HOTELS_APPS). Ojo: la clave de AdminHoteles es la de gestión
 *    completa de esa app; lo ideal es pedir una clave de solo lectura para soporte.
 *  · Sin configuración, todo devuelve vacío y no sale ninguna petición.
 */
class HotelsApi
{
    /** Únicas rutas que se llaman. */
    private const RUTAS = ['/api/management/containers', '/api/management/users'];

    /** Y la ficha de un buffet concreto (su número). */
    private const FICHA = '#^/api/management/buffets/\d+/details$#';

    public function configured(): bool
    {
        return $this->apps() !== [];
    }

    /** @return array<int, array{url:string, key:string}> */
    private function apps(): array
    {
        $apps = [];
        // Lo normal: UNA clave para toda la flota (AI_HOTELS_KEY) y la lista de URLs (AI_HOTELS_URLS, separadas por comas).
        $comun = (string) config('ai.hotels.key', '');
        $lista = array_map(fn ($u) => ['url' => $u, 'key' => $comun], (array) config('ai.hotels.urls', []));
        // AI_HOTELS_APPS permite una clave propia para alguna app concreta.
        foreach (array_merge($lista, (array) config('ai.hotels.apps', [])) as $a) {
            $url = rtrim((string) ($a['url'] ?? ''), '/');
            $key = (string) ($a['key'] ?? '');
            if ($url !== '' && $key !== '' && str_starts_with($url, 'https://')) {
                $apps[] = ['url' => $url, 'key' => $key];
            }
        }
        return $apps;
    }

    /** GET de una ruta permitida. Devuelve el contenido (data) o null si falla. */
    private function get(array $app, string $ruta, array $query = []): ?array
    {
        if (! in_array($ruta, self::RUTAS, true) && ! preg_match(self::FICHA, $ruta)) {
            return null;
        }
        try {
            $r = Http::timeout((int) config('ai.hotels.timeout', 8))->acceptJson()
                ->withHeaders(['X-Api-Key' => $app['key'], 'X-Actor' => 'Helpdesk IA (solo lectura)'])
                ->get($app['url'] . $ruta, $query);
            if (! $r->successful()) {
                return null;
            }
            $j = $r->json();
            // /containers viene sin envoltorio; el resto, dentro de data.
            $d = is_array($j) && array_key_exists('success', $j) ? ($j['data'] ?? null) : $j;
            return is_array($d) ? $d : null;
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Índice correo → buffets donde figura como usuario. Se guarda unas horas (ai.hotels.ttl):
     * montarlo cuesta una petición por buffet.
     *
     * @return array<string, array<int, array{app:string, buffet_id:int, buffet_uuid:string, buffet:string, hotel:string}>>
     */
    public function directorio(bool $rehacer = false): array
    {
        if (! $this->configured()) {
            return [];
        }
        if ($rehacer) {
            Cache::forget('ai_hotels_directorio');
        }
        return Cache::remember('ai_hotels_directorio', (int) config('ai.hotels.ttl', 6 * 3600), function () {
            $idx = [];
            foreach ($this->apps() as $app) {
                $conts = $this->get($app, '/api/management/containers') ?? [];
                $porId = [];
                foreach ($conts as $c) {
                    $porId[(int) ($c['id'] ?? 0)] = $c;
                }
                foreach ($conts as $c) {
                    if ((int) ($c['container_type'] ?? 0) !== 4) {   // 4 = buffet (los usuarios cuelgan de ahí)
                        continue;
                    }
                    $hotel = (string) ($porId[(int) ($c['parent_id'] ?? 0)]['name'] ?? '');
                    $usuarios = $this->get($app, '/api/management/users', ['buffet_id' => $c['uuid']]) ?? [];
                    foreach (array_values((array) $usuarios) as $u) {   // a veces llega como objeto con claves numéricas
                        $mail = mb_strtolower(trim((string) ($u['email'] ?? '')));
                        if ($mail === '' || ! (int) ($u['is_enabled'] ?? 1)) {
                            continue;
                        }
                        $idx[$mail][] = ['app' => $app['url'], 'buffet_id' => (int) $c['id'], 'buffet_uuid' => (string) $c['uuid'], 'buffet' => (string) $c['name'], 'hotel' => $hotel];
                    }
                }
            }
            return $idx;
        });
    }

    /** Dónde figura este correo como usuario activo (puede ser en varios buffets). */
    public function buscarPorCorreo(?string $email): array
    {
        $email = mb_strtolower(trim((string) $email));
        return $email === '' ? [] : ($this->directorio()[$email] ?? []);
    }

    /** La ficha de un buffet (antenas y etiquetas), guardada 2 minutos. null si no se pudo leer. */
    private function ficha(array $sitio): ?array
    {
        foreach ($this->apps() as $app) {
            if ($app['url'] === $sitio['app']) {
                $f = Cache::remember('ai_hotels_ficha_' . md5($app['url']) . '_' . (int) $sitio['buffet_id'], 120,
                    fn () => $this->get($app, '/api/management/buffets/' . (int) $sitio['buffet_id'] . '/details') ?? []);
                return $f ?: null;
            }
        }
        return null;
    }

    /**
     * Estado de las antenas de un buffet: [{name, online, offline_since, esl}]. null si no se pudo leer.
     * No se devuelven IP ni MAC: la IA no los necesita y no deben salir en una respuesta.
     */
    public function antenas(array $sitio): ?array
    {
        $f = $this->ficha($sitio);
        if ($f === null) {
            return null;
        }
        return array_map(fn ($a) => [
            'name' => (string) ($a['apName'] ?? 'Antena'), 'online' => (bool) ($a['online'] ?? false),
            'offline_since' => $a['offline_since'] ?? null, 'esl' => (int) ($a['eslCount'] ?? 0),
        ], array_values((array) ($f['antennas'] ?? [])));
    }

    /**
     * Estado de UNA etiqueta (por su código/MAC) dentro de ese buffet: en línea, batería, señal, última
     * actualización y la antena que la sirve. null si no es de ese buffet. Nunca de otro hotel.
     */
    public function etiqueta(array $sitio, string $codigo): ?array
    {
        $f = $this->ficha($sitio);
        $codigo = mb_strtoupper(trim($codigo));
        if ($f === null || $codigo === '') {
            return null;
        }
        $antenas = [];
        foreach ((array) ($f['antennas'] ?? []) as $a) {
            $antenas[(string) ($a['mac'] ?? '')] = ['name' => (string) ($a['apName'] ?? 'Antena'), 'online' => (bool) ($a['online'] ?? false)];
        }
        foreach ((array) ($f['tags'] ?? []) as $t) {
            if (mb_strtoupper((string) ($t['mac'] ?? '')) === $codigo || mb_strtoupper((string) ($t['name'] ?? '')) === $codigo) {
                $ap = $antenas[(string) ($t['ap_mac'] ?? '')] ?? null;
                return [
                    'code' => $codigo, 'online' => (bool) ($t['is_online'] ?? false), 'battery' => isset($t['battery']) ? (int) $t['battery'] : null,
                    'signal' => isset($t['signal']) ? (int) $t['signal'] : null, 'updated_at' => $t['updated_at'] ?? null, 'antenna' => $ap,
                ];
            }
        }
        return null;
    }
}
