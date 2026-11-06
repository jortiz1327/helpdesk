<?php

namespace App\Services;

use App\Models\ZkongBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lo que vale cada etiqueta de una remesa a ZKONG, del portal (solo lectura), con la regla
 * del Excel de stock del portal:
 *   · «Precio de coste» (item_precio_coste, en €);
 *   · si está vacío o a 0, «Coste producto» (item_rate_provider, en $), pasado a euros con
 *     el cambio del propio portal (1,04) para poder sumarlo, y marcado como «proveedor».
 * Recuperado = reposiciones y abonos; asumido = rechazadas; sin respuesta, pendiente.
 * Los portes no cuentan.
 */
class ZkongCosts
{
    public const DIVISA = 1.04;          // el mismo cambio fijo que usa el portal
    private const CACHE_MIN = 10;
    public const RECUPERADO = ['reposicion', 'abono'];
    public const ASUMIDO = ['rechazada'];

    /** [REF => ['cost' => float, 'source' => 'coste'|'proveedor']] de las que tienen coste. */
    public function forRefs(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn ($r) => strtoupper(trim((string) $r)), $refs))));
        if (! $refs || ! AemePortal::configured()) return [];
        $clave = 'zkong_costs:' . md5(implode('|', $refs));
        if (($hit = Cache::get($clave)) !== null) return $hit;
        try {
            $out = [];
            foreach (DB::connection('portal_aeme')->table('items')->whereIn('item_ref', $refs)
                ->get(['item_ref', 'item_precio_coste', 'item_rate_provider']) as $i) {
                if ($c = self::costeDe($i->item_precio_coste, $i->item_rate_provider)) $out[strtoupper(trim($i->item_ref))] = $c;
            }
            Cache::put($clave, $out, now()->addMinutes(self::CACHE_MIN));
            return $out;
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudo leer el coste de los productos: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * La misma regla que el Excel de stock del portal: manda el precio de coste (€) y, vacío
     * o a 0, el coste producto ($), que es texto y puede traer coma («0,22»).
     */
    public static function costeDe($precioCoste, $rateProvider): ?array
    {
        if ((float) $precioCoste > 0) return ['cost' => round((float) $precioCoste, 2), 'source' => 'coste'];
        $usd = (float) str_replace(',', '.', trim((string) $rateProvider));
        return $usd > 0 ? ['cost' => round($usd * self::DIVISA, 2), 'source' => 'proveedor'] : null;
    }

    /** Copia el coste del portal en las etiquetas de la remesa que aún no lo tienen. */
    public function snapshot(ZkongBatch $b): void
    {
        $sin = DB::table('warranty_items')->where('zkong_batch_id', $b->id)->whereNull('zkong_unit_cost')->get(['id', 'aeme_ref']);
        if ($sin->isEmpty()) return;
        $costes = $this->forRefs($sin->pluck('aeme_ref')->all());
        foreach ($sin as $i) {
            $c = $costes[strtoupper(trim((string) $i->aeme_ref))] ?? null;
            if ($c) DB::table('warranty_items')->where('id', $i->id)->update(['zkong_unit_cost' => $c['cost'], 'zkong_cost_source' => $c['source']]);
        }
    }

    /**
     * Cuentas de una o varias remesas: [batch_id => {recovered, assumed, pending, total,
     * no_cost, estimated}]. Lo guardado al enviar manda; lo que no tenga, del portal ahora.
     */
    public function summary(array $batchIds): array
    {
        if (! $batchIds) return [];
        $items = DB::table('warranty_items')->whereIn('zkong_batch_id', $batchIds)
            ->get(['zkong_batch_id', 'aeme_ref', 'zkong_result', 'zkong_unit_cost', 'zkong_cost_source']);
        $vivo = $this->forRefs($items->whereNull('zkong_unit_cost')->pluck('aeme_ref')->all());
        $out = [];
        foreach ($batchIds as $id) $out[$id] = ['recovered' => 0.0, 'assumed' => 0.0, 'pending' => 0.0, 'total' => 0.0, 'no_cost' => 0, 'estimated' => 0];
        foreach ($items as $i) {
            $c = $i->zkong_unit_cost !== null ? ['cost' => (float) $i->zkong_unit_cost, 'source' => $i->zkong_cost_source]
                : ($vivo[strtoupper(trim((string) $i->aeme_ref))] ?? null);
            $s = &$out[$i->zkong_batch_id];
            if (! $c) { $s['no_cost']++; unset($s); continue; }
            if ($c['source'] === 'proveedor') $s['estimated']++;
            $s['total'] += $c['cost'];
            $k = in_array($i->zkong_result, self::RECUPERADO, true) ? 'recovered' : (in_array($i->zkong_result, self::ASUMIDO, true) ? 'assumed' : 'pending');
            $s[$k] += $c['cost'];
            unset($s);
        }
        foreach ($out as &$s) foreach (['recovered', 'assumed', 'pending', 'total'] as $k) $s[$k] = round($s[$k], 2);
        return $out;
    }

    /** Coste de cada etiqueta (guardado o del portal ahora): [item_id => {cost, source}|null]. */
    public function perItem(array $rows): array
    {
        $vivo = $this->forRefs(collect($rows)->whereNull('zkong_unit_cost')->pluck('aeme_ref')->all());
        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = $r->zkong_unit_cost !== null ? ['cost' => (float) $r->zkong_unit_cost, 'source' => $r->zkong_cost_source]
                : ($vivo[strtoupper(trim((string) $r->aeme_ref))] ?? null);
        }
        return $out;
    }
}
