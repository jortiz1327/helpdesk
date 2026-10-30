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
