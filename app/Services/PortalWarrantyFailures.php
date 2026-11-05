<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallos que se repiten, sacados del portal AEME (solo lectura): las etiquetas y antenas que
 * salieron en los albaranes de los proyectos de garantía, frente a lo vendido a cada cliente
 * en sus facturas.
 *
 * El portal no guarda qué unidad concreta se cambia, así que cada unidad repuesta se reparte
 * entre las facturas de ese modelo a ese cliente anteriores al envío, en proporción a lo
 * comprado. De ahí salen el año de venta (el «lote» aproximado) y la edad al volver.
 */
class PortalWarrantyFailures
{
    // Albarán que salió de verdad (no la muestra devuelta).
    public const ENVIADO = ['sent', 'delivered_by_carrier', 'delivered_and_signed'];
    public const UMBRAL = 0.10;   // % de vuelta que se marca en rojo
    private const EDADES = [[0, 6, '0–6 meses'], [6, 12, '6–12'], [12, 18, '12–18'], [18, 24, '18–24'], [24, 36, '2–3 años'], [36, null, '+3 años']];

    /** $f: from, to (fecha de envío AAAA-MM-DD), kind (esl|ap), client (id del portal). Null sin portal. */
    public static function report(array $f = []): ?array
    {
        if (! AemePortal::configured()) return null;
        $f = ['from' => $f['from'] ?? null, 'to' => $f['to'] ?? null, 'kind' => in_array($f['kind'] ?? '', ['esl', 'ap'], true) ? $f['kind'] : null,
            'client' => ! empty($f['client']) ? (int) $f['client'] : null];
        $key = 'portal_aeme.warranty_failures.' . md5(json_encode($f));
        if ($c = Cache::get($key)) return $c;
        try {
            $r = self::calcular($f);
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer los fallos de garantía: ' . $e->getMessage());
            return null;
        }
        Cache::put($key, $r, now()->addMinutes(15));
        return $r;
    }

    private static function calcular(array $f): array
    {
        $db = DB::connection('portal_aeme');
        $catalogo = collect(AemePortal::products() ?? [])->keyBy('ref');
        if ($f['kind']) $catalogo = $catalogo->filter(fn ($p) => $p['kind'] === $f['kind']);
        $norm = fn ($r) => strtoupper(trim((string) $r));

        $pids = $db->table('projects')->where('project_warranty', 1)->pluck('project_id');

        // Albaranes de garantía que salieron, con sus líneas de etiquetas y antenas.
        $aq = $db->table('deliveries')->whereIn('bill_projectid', $pids)->whereIn('bill_status', self::ENVIADO);
        if ($f['client']) $aq->where('bill_clientid', $f['client']);
        $albs = $aq->get(['bill_deliveryid', 'bill_clientid', 'bill_projectid', 'bill_estimateid', 'bill_expeditionid', 'bill_date', 'bill_date_sent_to_customer', 'bill_created'])
            ->map(function ($a) {
                $a->date = substr((string) ($a->bill_date_sent_to_customer ?: ($a->bill_date ?: $a->bill_created)), 0, 10);
                return $a;
            })
            ->filter(fn ($a) => (! $f['from'] || $a->date >= $f['from']) && (! $f['to'] || $a->date <= $f['to']))->values();
        // El albarán no guarda su presupuesto: se llega por expedición → pedido → presupuesto.
        $pedPorExp = $albs->isEmpty() ? collect() : $db->table('expeditions')->whereIn('bill_expeditionid', $albs->pluck('bill_expeditionid')->filter()->unique())
            ->pluck('bill_orderid', 'bill_expeditionid');
        $presPorPed = $pedPorExp->isEmpty() ? collect() : $db->table('orders')->whereIn('bill_orderid', $pedPorExp->values()->unique())->pluck('bill_estimateid', 'bill_orderid');
        $albs->each(function ($a) use ($pedPorExp, $presPorPed) {
            $a->bill_estimateid = $a->bill_estimateid ?: ($presPorPed[$pedPorExp[$a->bill_expeditionid] ?? 0] ?? null);
        });
        $importe = $albs->isEmpty() ? collect() : $db->table('estimates')->whereIn('bill_estimateid', $albs->pluck('bill_estimateid')->filter()->unique())
            ->pluck('bill_final_amount', 'bill_estimateid');
        $lineas = $albs->isEmpty() ? collect() : $db->table('lineitems')->where('lineitemresource_type', 'delivery')->whereIn('lineitemresource_id', $albs->pluck('bill_deliveryid'))
            ->get(['lineitemresource_id', 'lineitem_ref', 'lineitem_quantity'])->groupBy('lineitemresource_id');

        // Lo vendido: líneas de facturas que NO son de proyectos de garantía.
        $ventas = $db->table('lineitems as l')->join('invoices as i', 'i.bill_invoiceid', '=', 'l.lineitemresource_id')
            ->where('l.lineitemresource_type', 'invoice')->where('i.bill_status', '!=', 'draft')
            ->where(fn ($q) => $q->whereNull('i.bill_projectid')->orWhereNotIn('i.bill_projectid', $pids))
            ->whereIn('l.lineitem_ref', $catalogo->keys())->where('l.lineitem_quantity', '>', 0)
            ->get(['i.bill_clientid as client', 'i.bill_date as date', 'l.lineitem_ref as ref', 'l.lineitem_quantity as qty'])
            ->map(fn ($v) => (object) ['client' => (int) $v->client, 'date' => substr((string) $v->date, 0, 10), 'ref' => $norm($v->ref), 'qty' => (float) $v->qty]);
        $vendidoAnio = [];   // ref → año → uds
        foreach ($ventas as $v) $vendidoAnio[$v->ref][(int) substr($v->date, 0, 4)] = ($vendidoAnio[$v->ref][(int) substr($v->date, 0, 4)] ?? 0) + $v->qty;
        $compras = $ventas->groupBy(fn ($v) => "{$v->client}|{$v->ref}");

        // Cada línea repuesta: a quién, qué, cuándo, a cargo de quién, y su reparto por factura.
        $uds = [];
        foreach ($albs as $a) {
            $nuestro = (float) ($importe[$a->bill_estimateid] ?? 0) == 0.0;
            foreach ($lineas->get($a->bill_deliveryid, collect()) as $l) {
                $ref = $norm($l->lineitem_ref);
                $q = (float) $l->lineitem_quantity;
                if (! isset($catalogo[$ref]) || $q <= 0) continue;
                $previas = $compras->get("{$a->bill_clientid}|{$ref}", collect())->filter(fn ($v) => $v->date <= $a->date);
                $total = $previas->sum('qty');
                $uds[] = (object) ['client' => (int) $a->bill_clientid, 'project' => $a->bill_projectid, 'delivery' => $a->bill_deliveryid, 'date' => $a->date,
                    'ref' => $ref, 'qty' => $q, 'ours' => $nuestro,
                    'share' => $total ? $previas->map(fn ($v) => ['year' => (int) substr($v->date, 0, 4),
                        'months' => max(0, (strtotime($a->date) - strtotime($v->date)) / 2629800), 'qty' => $q * $v->qty / $total])->values()->all() : []];
            }
        }
        $uds = collect($uds);
        $nombres = $db->table('clients')->whereIn('client_id', $uds->pluck('client')->unique()->merge($f['client'] ? [$f['client']] : []))
            ->pluck('client_company_name', 'client_id')->map(fn ($n) => trim(preg_replace('/\s+/u', ' ', (string) $n)));
        $nombre = fn ($c) => $nombres[$c] ?? "Cliente {$c}";
        $vendidoA = fn ($c, $ref) => $compras->get("{$c}|{$ref}", collect())->sum('qty');
        $tasa = fn ($n, $de) => $de > 0 ? round($n / $de, 4) : null;
        $vendidoTotal = fn ($ref) => array_sum($vendidoAnio[$ref] ?? []);

        // Por modelo.
        $modelos = $uds->groupBy('ref')->map(function ($g, $ref) use ($catalogo, $vendidoTotal, $tasa, $f) {
            $vend = $f['client'] ? null : $vendidoTotal($ref);
            return ['ref' => $ref, 'name' => $catalogo[$ref]['name'] ?? null, 'kind' => $catalogo[$ref]['kind'] ?? null,
                'units' => (int) $g->sum('qty'), 'ours' => (int) $g->where('ours', true)->sum('qty'), 'paid' => (int) $g->where('ours', false)->sum('qty'),
                'shipments' => $g->pluck('delivery')->unique()->count(), 'clients' => $g->pluck('client')->unique()->count(),
                'sold' => $vend, 'rate' => $vend !== null ? $tasa($g->sum('qty'), $vend) : null];
        })->sortByDesc('units')->values();
        if ($f['client']) {   // Con un cliente: lo vendido es lo que compró ese cliente.
            $modelos = $modelos->map(fn ($m) => [...$m, 'sold' => $s = $vendidoA($f['client'], $m['ref']), 'rate' => $tasa($m['units'], $s)]);
        }

        // Modelo × año de venta (reparto por factura).
        $celdas = [];
        $sinVenta = 0;
        foreach ($uds as $u) {
            if (! $u->share) { $sinVenta += $u->qty; $celdas["{$u->ref}|"]['units'] = ($celdas["{$u->ref}|"]['units'] ?? 0) + $u->qty; continue; }
            foreach ($u->share as $s) $celdas["{$u->ref}|{$s['year']}"]['units'] = ($celdas["{$u->ref}|{$s['year']}"]['units'] ?? 0) + $s['qty'];
        }
        $anios = collect(array_keys($celdas))->map(fn ($k) => explode('|', $k)[1])->filter()->map(fn ($y) => (int) $y)->unique()->sort()->values()->all();
        $topRefs = $modelos->take(14)->pluck('ref')->all();
        $cells = [];
        foreach ($celdas as $k => $c) {
            [$ref, $y] = explode('|', $k);
            if (! in_array($ref, $topRefs, true)) continue;
            $vend = $y === '' ? null : ($vendidoAnio[$ref][(int) $y] ?? null);
            $cells[] = ['ref' => $ref, 'year' => $y === '' ? null : (int) $y, 'units' => round($c['units']), 'sold' => $vend,
                'rate' => $vend && ! $f['client'] ? $tasa($c['units'], $vend) : null];
        }

        // Edad al volver (meses desde la factura), ponderada por el reparto.
        $edades = array_map(fn ($t) => ['label' => $t[2], 'n' => 0], self::EDADES);
        foreach ($uds as $u) foreach ($u->share as $s) {
            foreach (self::EDADES as $i => $t) if ($s['months'] >= $t[0] && ($t[1] === null || $s['months'] < $t[1])) { $edades[$i]['n'] += $s['qty']; break; }
        }
        $edades = array_map(fn ($e) => [...$e, 'n' => (int) round($e['n'])], $edades);
        $pesos = $uds->flatMap(fn ($u) => $u->share)->sortBy('months')->values();
        $mitad = $pesos->sum('qty') / 2;
        $acc = 0;
        $mediana = null;
        foreach ($pesos as $s) { $acc += $s['qty']; if ($acc >= $mitad) { $mediana = (int) round($s['months']); break; } }

        $clientes = $uds->groupBy('client')->map(function ($g, $c) use ($vendidoA, $tasa, $nombre) {
            $vend = $g->pluck('ref')->unique()->sum(fn ($r) => $vendidoA($c, $r));
            return ['client' => $nombre($c), 'id' => (int) $c, 'units' => (int) $g->sum('qty'), 'ours' => (int) $g->where('ours', true)->sum('qty'),
                'projects' => $g->pluck('project')->unique()->count(), 'shipments' => $g->pluck('delivery')->unique()->count(),
                'sold' => $vend ?: null, 'rate' => ($t = $tasa($g->sum('qty'), $vend)) !== null && $t <= 1 ? $t : null,
                'outside' => $g->sum('qty') > $vend,   // repuestas > compradas en el portal
                'models' => $g->groupBy('ref')->map(fn ($m) => $m->sum('qty'))->sortDesc()->keys()->take(4)->values()->all()];
        })->sortByDesc('units')->values()->take(12)->all();

        $meses = $uds->groupBy(fn ($u) => substr($u->date, 0, 7))->sortKeys()->map(fn ($g, $m) => ['month' => $m, 'n' => (int) $g->sum('qty'),
            'by' => ['ours' => (int) $g->where('ours', true)->sum('qty'), 'paid' => (int) $g->where('ours', false)->sum('qty')],
            'shipments' => $g->pluck('delivery')->unique()->count()])->values()->slice(-18)->values()->all();

        $total = $uds->sum('qty');
        $conVenta = $modelos->filter(fn ($m) => $m['sold'])->values();
        $todos = $db->table('clients')->whereIn('client_id', $db->table('projects')->where('project_warranty', 1)->pluck('project_clientid')->unique())
            ->pluck('client_company_name', 'client_id')->map(fn ($n, $id) => ['id' => (int) $id, 'name' => trim(preg_replace('/\s+/u', ' ', (string) $n))])
            ->sortBy('name')->values()->all();

        return [
            'kpis' => [
                'units' => (int) $total, 'ours' => (int) $uds->where('ours', true)->sum('qty'), 'paid' => (int) $uds->where('ours', false)->sum('qty'),
                'esl' => (int) $uds->filter(fn ($u) => ($catalogo[$u->ref]['kind'] ?? '') === 'esl')->sum('qty'),
                'ap' => (int) $uds->filter(fn ($u) => ($catalogo[$u->ref]['kind'] ?? '') === 'ap')->sum('qty'),
                'shipments' => $uds->pluck('delivery')->unique()->count(), 'projects' => $uds->pluck('project')->unique()->count(),
                'clients' => $uds->pluck('client')->unique()->count(), 'models' => $modelos->count(),
                'sold' => (int) $conVenta->sum('sold'),
                'per_1000' => $conVenta->sum('sold') ? round($conVenta->sum('units') / $conVenta->sum('sold') * 1000, 1) : null,
                'median_age' => $mediana, 'no_sale' => (int) round($sinVenta),
            ],
            'models' => $modelos->take(25)->all(),
            'heatmap' => ['models' => $topRefs, 'years' => $anios, 'cells' => $cells, 'no_year' => (int) round($sinVenta)],
            'months' => $meses,
            'clients' => $clientes,
            'ages' => $edades,
            'client_list' => $todos,
            'threshold' => self::UMBRAL,
            'url' => PortalDocCheck::URL,
        ];
    }

}
