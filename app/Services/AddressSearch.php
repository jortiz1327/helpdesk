<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buscador de direcciones (para «Dirección desde la que enviarás las etiquetas»).
 *
 * Con GOOGLE_PLACES_KEY, Google Places (API nueva): sugerencias mientras escribe y, al
 * elegir una, su dirección completa; encuentra también hoteles y tiendas por su nombre,
 * en cualquier país. Sin clave, Photon (OpenStreetMap), gratis y mundial pero menos fino
 * con los números de portal. La clave solo la usa el servidor.
 *
 * Las sugerencias llevan `id` y, si ya se sabe, `address` (Photon la da a la vez; Google
 * pide un segundo paso, place()). Una dirección son líneas: calle y número / CP y ciudad /
 * provincia y país; el cliente la puede retocar (piso, puerta…).
 */
class AddressSearch
{
    private const TIMEOUT = 6;

    public function provider(): string
    {
        return config('services.places.google_key') ? 'google' : 'photon';
    }

    /** Sugerencias para lo escrito: [['id', 'main', 'secondary', 'address'?]]. */
    public function search(string $q, string $lang, string $session): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q));
        if (mb_strlen($q) < 3) return [];
        $q = mb_substr($q, 0, 120);
        $lang = in_array($lang, ['es', 'en', 'pt'], true) ? $lang : 'es';
        $p = $this->provider();
        // Lo mismo buscado por otros (o al borrar y volver a escribir) no se vuelve a pedir.
        return Cache::remember("addr.{$p}.{$lang}." . sha1(mb_strtolower($q)), now()->addDay(), function () use ($p, $q, $lang, $session) {
            try {
                // Si Google falla (clave sin Places, cuota…) o ya se gastó lo del día, Photon
                // en su lugar: mejor algo que nada.
                if ($p === 'google' && $this->cupoGoogle('search') && ($r = $this->googleSearch($q, $lang, $session)) !== null) return $r;
                return $this->photonSearch($q, $lang);
            } catch (Throwable $e) {
                Log::warning("Direcciones ({$p}): " . $e->getMessage());
                return [];
            }
        }) ?: [];
    }

    /** La dirección completa de una sugerencia de Google (en líneas), o null. */
    public function place(string $id, string $lang, string $session): ?string
    {
        if ($this->provider() !== 'google' || ! preg_match('/^[A-Za-z0-9_\-]{10,300}$/', $id)) return null;
        $lang = in_array($lang, ['es', 'en', 'pt'], true) ? $lang : 'es';
        $cache = "addr.place.{$lang}.{$id}";
        if (Cache::has($cache)) return Cache::get($cache);
        // Gastado lo del día: el formulario deja escribirla a mano.
        if (! $this->cupoGoogle('details')) return null;
        return Cache::remember($cache, now()->addDays(7), function () use ($id, $lang, $session) {
            try {
                $r = Http::timeout(self::TIMEOUT)->withHeaders([
                    'X-Goog-Api-Key' => config('services.places.google_key'),
                    'X-Goog-FieldMask' => 'displayName,formattedAddress,addressComponents,types',
                ])->get('https://places.googleapis.com/v1/places/' . $id, array_filter(['languageCode' => $lang, 'sessionToken' => $session ?: null]));
                if (! $r->ok()) { Log::warning('Direcciones (google place): HTTP ' . $r->status()); return null; }
                return $this->lineasGoogle((array) $r->json());
            } catch (Throwable $e) {
                Log::warning('Direcciones (google place): ' . $e->getMessage());
                return null;
            }
        });
    }

    /* ----------------------------------------------------------------- Google */

    /**
     * Tope de llamadas a Google al día, para TODO el portal (el cupo por correo no basta:
     * con varios correos se multiplica). Por defecto, lo gratis de Google repartido por días
     * (10.000 sugerencias y 5.000 detalles al mes). Pasado el tope, Photon o a mano.
     */
    private function cupoGoogle(string $tipo): bool
    {
        $max = (int) config("services.places.google_daily_{$tipo}", $tipo === 'search' ? 300 : 150);
        $k = "places_google.{$tipo}." . now()->toDateString();
        Cache::add($k, 0, now()->endOfDay());
        if ((int) Cache::get($k, 0) >= $max) return false;
        Cache::increment($k);
        return true;
    }

    /** Sugerencias de Google, o null si Google no responde bien. */
    private function googleSearch(string $q, string $lang, string $session): ?array
    {
        try {
            $r = Http::timeout(self::TIMEOUT)->withHeaders(['X-Goog-Api-Key' => config('services.places.google_key')])
                ->post('https://places.googleapis.com/v1/places:autocomplete', array_filter([
                    'input' => $q, 'languageCode' => $lang, 'sessionToken' => $session ?: null,
                ]));
        } catch (Throwable $e) {
            Log::warning('Direcciones (google): ' . $e->getMessage());
            return null;
        }
        if (! $r->ok()) { Log::warning('Direcciones (google): HTTP ' . $r->status() . ' ' . $r->json('error.status', '')); return null; }
        $out = [];
        foreach ((array) $r->json('suggestions', []) as $s) {
            $p = $s['placePrediction'] ?? null;
            if (! $p || empty($p['placeId'])) continue;
            $out[] = [
                'id' => (string) $p['placeId'],
                'main' => (string) ($p['structuredFormat']['mainText']['text'] ?? $p['text']['text'] ?? ''),
                'secondary' => (string) ($p['structuredFormat']['secondaryText']['text'] ?? ''),
            ];
        }
        return array_slice($out, 0, 6);
    }

    /** Un sitio de Google en líneas: su nombre si es un establecimiento, y la dirección. */
    private function lineasGoogle(array $p): ?string
    {
        $dir = trim((string) ($p['formattedAddress'] ?? ''));
        if ($dir === '') return null;
        $c = [];
        foreach ((array) ($p['addressComponents'] ?? []) as $comp) {
            foreach ((array) ($comp['types'] ?? []) as $t) $c[$t] ??= (string) ($comp['longText'] ?? '');
        }
        $calle = trim(($c['route'] ?? '') . (isset($c['street_number']) ? ' ' . $c['street_number'] : ''));
        $lineas = [];
        $nombre = trim((string) ($p['displayName']['text'] ?? ''));
        $esSitio = (bool) array_intersect((array) ($p['types'] ?? []), ['establishment', 'lodging', 'store', 'point_of_interest']);
        if ($esSitio && $nombre !== '' && ! str_contains($dir, $nombre)) $lineas[] = $nombre;
        if ($calle !== '' && isset($c['locality'])) {
            $lineas[] = $calle . (isset($c['subpremise']) ? ', ' . $c['subpremise'] : '');
            $lineas[] = trim(($c['postal_code'] ?? '') . ' ' . $c['locality']);
            $lineas[] = implode(', ', array_filter([$c['administrative_area_level_2'] ?? ($c['administrative_area_level_1'] ?? null), $c['country'] ?? null]));
        } else {
            $lineas[] = $dir;   // sin calle desglosada (zonas sin números): la de Google tal cual
        }
        return implode("\n", array_values(array_unique(array_filter($lineas, fn ($l) => trim($l) !== ''))));
    }

    /* ----------------------------------------------------------------- Photon */

    private function photonSearch(string $q, string $lang): array
    {
        $r = Http::timeout(self::TIMEOUT)->withHeaders(['User-Agent' => 'AEME-Helpdesk/1.0 (portal garantias)'])
            ->get(config('services.places.photon_url'), ['q' => $q, 'limit' => 6, 'lang' => $lang === 'en' ? 'en' : 'default']);
        if (! $r->ok()) { Log::warning('Direcciones (photon): HTTP ' . $r->status()); return []; }
        $out = [];
        foreach ((array) $r->json('features', []) as $f) {
            $p = (array) ($f['properties'] ?? []);
            $calle = trim(($p['street'] ?? '') . (isset($p['housenumber']) ? ' ' . $p['housenumber'] : ''));
            $nombre = trim((string) ($p['name'] ?? ''));
            $ciudad = trim(($p['postcode'] ?? '') . ' ' . ($p['city'] ?? $p['town'] ?? $p['village'] ?? $p['district'] ?? ''));
            $zona = implode(', ', array_filter([($p['state'] ?? null) !== ($p['city'] ?? null) ? ($p['state'] ?? null) : null, $p['country'] ?? null]));
            $main = $nombre !== '' && $nombre !== $calle ? $nombre : $calle;
            if ($main === '') continue;
            $lineas = array_filter([$nombre !== '' && $nombre !== $calle && $calle !== '' ? $nombre : null, $calle ?: $nombre, $ciudad, $zona]);
            $out[] = [
                'id' => ($p['osm_type'] ?? '') . ($p['osm_id'] ?? md5(json_encode($p))),
                'main' => $main,
                'secondary' => implode(', ', array_filter([$nombre !== '' && $calle !== '' && $nombre !== $calle ? $calle : null, $ciudad, $zona])),
                'address' => implode("\n", array_values(array_unique($lineas))),
            ];
        }
        return collect($out)->unique('address')->values()->all();
    }
}
