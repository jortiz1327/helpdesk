<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lectura del portal AEME (conexión portal_aeme): los clientes y, de cada uno, los
 * hoteles o tiendas de los que puede colgar una garantía. Mismo criterio que la app
 * de garantías. Nunca escribe en el portal.
 */
class AemePortal
{
    public static function configured(): bool
    {
        return (string) config('database.connections.portal_aeme.host') !== '';
    }

    /** [['id' => int, 'name' => string]] por nombre, o null si el portal no responde. */
    public static function clients(): ?array
    {
        if (! self::configured()) {
            return null;
        }
        $list = Cache::get('portal_aeme.clients');
        if ($list) {
            return $list;
        }
        try {
            $list = DB::connection('portal_aeme')->table('clients')
                ->where('client_company_name', '!=', '')
                ->orderBy('client_company_name')
                ->get(['client_id', 'client_company_name'])
                ->map(fn ($c) => ['id' => (int) $c->client_id, 'name' => trim(preg_replace('/\s+/u', ' ', (string) $c->client_company_name))])
                ->values()->all();
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer los clientes: ' . $e->getMessage());
            return null;
        }
        Cache::put('portal_aeme.clients', $list, now()->addMinutes(5));
        return $list;
    }

    /** Hoteles o tiendas del cliente: [['id', 'name', 'title']], o null si el portal no responde. */
    public static function projects(int $clientId): ?array
    {
        if (! self::configured()) {
            return null;
        }
        try {
            $rows = DB::connection('portal_aeme')->table('projects')
                ->where('project_clientid', $clientId)
                ->get(['project_id', 'project_title']);
        } catch (Throwable $e) {
            Log::warning("Portal AEME: no se pudieron leer los proyectos del cliente {$clientId}: " . $e->getMessage());
            return null;
        }
        return self::bases($rows);
    }

    // Categorías del portal: etiquetas electrónicas y puntos de acceso (antenas).
    public const CAT_ESL = 8;
    public const CAT_AP = 115;

    /**
     * Etiquetas y antenas del portal, para elegir el producto de una garantía:
     * [['ref', 'name', 'kind' => esl|ap]], o null si el portal no está o no responde.
     */
    public static function products(): ?array
    {
        if (! self::configured()) {
            return null;
        }
        if ($list = Cache::get('portal_aeme.products')) {
            return $list;
        }
        try {
            $list = DB::connection('portal_aeme')->table('items')
                ->whereIn('item_categoryid', [self::CAT_ESL, self::CAT_AP])->where('item_ref', '!=', '')
                ->orderBy('item_ref')->get(['item_ref', 'item_name', 'item_categoryid'])
                ->map(fn ($i) => ['ref' => strtoupper(trim($i->item_ref)), 'name' => trim((string) $i->item_name), 'kind' => (int) $i->item_categoryid === self::CAT_AP ? 'ap' : 'esl'])
                ->values()->all();
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer los productos: ' . $e->getMessage());
            return null;
        }
        Cache::put('portal_aeme.products', $list, now()->addMinutes(10));
        return $list;
    }

    /**
     * Productos del portal por su referencia: [ref => ['name', 'stock']]. Vacío si el
     * portal no está o no responde (la garantía se guarda igual, sin el nombre).
     */
    public static function items(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn ($r) => strtoupper(trim((string) $r)), $refs))));
        if (! $refs || ! self::configured()) {
            return [];
        }
        try {
            return DB::connection('portal_aeme')->table('items')->whereIn('item_ref', $refs)
                ->get(['item_ref', 'item_name', 'item_available_stock'])
                ->mapWithKeys(fn ($i) => [strtoupper($i->item_ref) => [
                    'name'  => trim((string) $i->item_name),
                    'stock' => $i->item_available_stock !== null ? (int) $i->item_available_stock : null,
                ]])->all();
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer los productos: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Un hotel o tienda por nombre (el proyecto más reciente si tiene varios pedidos),
     * ordenados por nombre.
     */
    public static function bases(iterable $rows): array
    {
        return collect($rows)
            ->map(function ($r) {
                $title = trim((string) $r->project_title);
                $name = self::baseName($title);
                return $name === null ? null : ['id' => (int) $r->project_id, 'name' => $name, 'title' => $title];
            })
            ->filter()
            ->sortByDesc('id')
            ->unique(fn ($p) => mb_strtolower($p['name']))
            ->sortBy(fn ($p) => mb_strtolower($p['name']))
            ->values()->all();
    }

    /**
     * El hotel o tienda de un proyecto del portal, o null si no es base de garantías:
     *   "Fergus Club Europa - Proyecto"             → "Fergus Club Europa"
     *   "Farmacia Remunta - Comanda 981 - Expofarm" → "Farmacia Remunta"
     *   "Soporte - …", "(Nulo) …", ampliaciones y "Stock …" → null
     */
    public static function baseName(string $title): ?string
    {
        $title = trim($title);
        if (str_starts_with($title, 'Soporte - ') || str_starts_with($title, '(Nulo)') || preg_match('/ampliaci[oó]n/iu', $title)) {
            return null;
        }
        $name = null;
        if (preg_match('/^(?<name>.+?) - Proyecto$/u', $title, $m) || preg_match('/^(?<name>.+?) - Comanda\s*\d*(?: - .+)?$/iu', $title, $m)) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $m['name']));
        }
        if ($name === null || preg_match('/^stock\b/iu', $name)) {
            return null;
        }
        return $name;
    }
}
