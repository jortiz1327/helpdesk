<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Estadísticas de los documentos de los proyectos de garantía del portal AEME
 * (project_warranty = 1): presupuestos, y de ellos pedido → expedición → albarán, y las
 * facturas del proyecto. Solo lectura. Mismo criterio de tipos que PortalDocCheck.
 */
class PortalWarrantyStats
{
    public const ESTADOS = ['accepted' => 'Aceptado', 'declined' => 'Rechazado', 'expired' => 'Caducado', 'new' => 'Nuevo',
        'revised' => 'Revisado', 'draft' => 'Borrador'];
    // Pedido ya servido (total o en parte).
    public const PEDIDO_OK = ['full_shipment', 'partial_shipment'];
    private const PLAZOS =[[0, 2, '0–2 días'], [3, 7, '3–7 días'], [8, 14, '1–2 semanas'], [15, 30, '2–4 semanas'], [31, null, '+1 mes']];

    /** $from, $to: fecha del presupuesto (AAAA-MM-DD); $client: id del cliente del portal. Null sin portal. */
    public static function report(?string $from, ?string $to, ?int $client = null): ?array
    {
        if (! AemePortal::configured()) return null;
        $key = 'portal_aeme.warranty_stats.' . md5(json_encode([$from, $to, $client]));
        if ($c = Cache::get($key)) return $c;
        try {
            $r = self::calcular($from, $to, $client);
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer las estadísticas de garantías: ' . $e->getMessage());
            return null;
        }
        Cache::put($key, $r, now()->addMinutes(15));
        return $r;
    }

    private static function calcular(?string $from, ?string $to, ?int $client): array
    {
        $db = DB::connection('portal_aeme');
        $pq = $db->table('projects')->where('project_warranty', 1);
        if ($client) $pq->where('project_clientid', $client);
        $proyectos = $pq->get(['project_id', 'project_clientid', 'project_created']);
        $pids = $proyectos->pluck('project_id');
        $clientePorProyecto = $proyectos->pluck('project_clientid', 'project_id');
        $nombres = $db->table('clients')->whereIn('client_id', $proyectos->pluck('project_clientid')->unique())->pluck('client_company_name', 'client_id')
            ->map(fn ($n) => trim(preg_replace('/\s+/u', ' ', (string) $n)));

        $eq = $db->table('estimates')->whereIn('bill_projectid', $pids)->where('bill_status', '!=', 'draft');
        if ($from) $eq->where('bill_date', '>=', $from);
        if ($to) $eq->where('bill_date', '<=', $to);
        $est = $eq->get(['bill_estimateid', 'bill_projectid', 'bill_date', 'bill_created', 'bill_status', 'bill_final_amount']);
        $eids = $est->pluck('bill_estimateid');

        $lineas = $eids->isEmpty() ? collect() : $db->table('lineitems')->where('lineitemresource_type', 'estimate')->whereIn('lineitemresource_id', $eids)
            ->get(['lineitemresource_id', 'lineitem_ref', 'lineitem_name', 'lineitem_unit', 'lineitem_quantity'])->groupBy('lineitemresource_id');
        $pedidos = $eids->isEmpty() ? collect() : $db->table('orders')->whereIn('bill_estimateid', $eids)->get(['bill_orderid', 'bill_estimateid', 'bill_status']);
        $exps = $pedidos->isEmpty() ? collect() : $db->table('expeditions')->whereIn('bill_orderid', $pedidos->pluck('bill_orderid'))->get(['bill_expeditionid', 'bill_orderid']);
        $albs = $exps->isEmpty() ? collect() : $db->table('deliveries')->whereIn('bill_expeditionid', $exps->pluck('bill_expeditionid'))
            ->get(['bill_deliveryid', 'bill_expeditionid', 'bill_status', 'bill_created', 'bill_date_sent_to_customer']);

        $iq = $db->table('invoices')->whereIn('bill_projectid', $pids)->where('bill_status', '!=', 'draft');
        if ($from) $iq->where('bill_date', '>=', $from);
        if ($to) $iq->where('bill_date', '<=', $to);
        $facturas = $iq->get(['bill_invoiceid', 'bill_projectid', 'bill_status', 'bill_final_amount', 'bill_date']);

        $esl = collect(AemePortal::products() ?? [])->keyBy('ref');
        $pedPorPres = $pedidos->groupBy('bill_estimateid');
        $expPorPed = $exps->groupBy('bill_orderid');
        $albPorExp = $albs->groupBy('bill_expeditionid');

        // Cada presupuesto con su tipo, su cadena y lo que tardó en salir el albarán.
        $docs = $est->map(function ($e) use ($lineas, $pedPorPres, $expPorPed, $albPorExp, $clientePorProyecto) {
            $l = $lineas->get($e->bill_estimateid, collect());
            $o = $pedPorPres->get($e->bill_estimateid, collect());
            $x = $o->flatMap(fn ($p) => $expPorPed->get($p->bill_orderid, collect()));
            $a = $x->flatMap(fn ($p) => $albPorExp->get($p->bill_expeditionid, collect()));
            $salida = $a->map(fn ($d) => $d->bill_date_sent_to_customer ?: $d->bill_created)->filter()->min();
            $dias = $salida && $e->bill_date ? (int) floor((strtotime(substr((string) $salida, 0, 10)) - strtotime($e->bill_date)) / 86400) : null;
            return (object) ['id' => $e->bill_estimateid, 'project' => $e->bill_projectid, 'client' => $clientePorProyecto[$e->bill_projectid] ?? null,
                'month' => substr((string) $e->bill_date, 0, 7), 'status' => (string) $e->bill_status, 'amount' => (float) $e->bill_final_amount,
                'type' => PortalDocCheck::tipo((float) $e->bill_final_amount, $l), 'lines' => $l,
                'ordered' => $o->isNotEmpty(), 'shipped' => $x->isNotEmpty(),
                // Garantía HECHA: presupuesto aceptado con su pedido servido o su albarán
                // enviado, entregado o firmado. A 0 € es a nuestro cargo: esos casi nunca se
                // marcan «aceptado» en el portal (quedan revisado o caducado aunque se envíen),
                // así que basta con que el pedido o el albarán hayan salido.
                'confirmed' => ((float) $e->bill_final_amount == 0.0 || $e->bill_status === 'accepted')
                    && ($o->contains(fn ($p) => in_array($p->bill_status, self::PEDIDO_OK, true))
                        || $a->contains(fn ($d) => in_array($d->bill_status, PortalDocCheck::DELIVERED, true))),
                'delivered' => $a->isNotEmpty() && $a->every(fn ($d) => in_array($d->bill_status, PortalDocCheck::DELIVERED, true)),
                'days' => $dias !== null && $dias >= 0 ? $dias : null];
        });

        $uds = fn ($d) => $d->lines->filter(fn ($l) => isset($esl[strtoupper(trim((string) $l->lineitem_ref))]))->sum(fn ($l) => (float) $l->lineitem_quantity);
        $conImporte = $docs->filter(fn ($d) => $d->amount > 0);
        $decididos = $conImporte->whereIn('status', ['accepted', 'declined', 'expired']);
        $plazos = $docs->pluck('days')->filter(fn ($v) => $v !== null)->sort()->values();

        // Modelos que se reponen: en garantía (0 €) y pagados por el cliente.
        $modelos = [];
        foreach ($docs as $d) {
            foreach ($d->lines as $l) {
                $ref = strtoupper(trim((string) $l->lineitem_ref));
                if (! isset($esl[$ref])) continue;
                $modelos[$ref] ??= ['ref' => $ref, 'name' => $esl[$ref]['name'], 'kind' => $esl[$ref]['kind'], 'warranty' => 0, 'paid' => 0];
                $modelos[$ref][$d->type === 'garantia' ? 'warranty' : 'paid'] += (float) $l->lineitem_quantity;
            }
        }
        $modelos = collect($modelos)->sortByDesc(fn ($m) => $m['warranty'] + $m['paid'])->values()->take(15)->all();

        $tipos = collect(PortalDocCheck::TIPOS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l, 'n' => $docs->where('type', $k)->count(),
            'amount' => round($docs->where('type', $k)->sum('amount'), 2)])->filter(fn ($t) => $t['n'])->values()->all();

        $meses = $docs->groupBy('month')->sortKeys()->map(fn ($g, $m) => ['month' => $m, 'n' => $g->count(), 'amount' => round($g->sum('amount'), 2),
            'by_type' => $g->countBy('type')->all()])->values()->slice(-18)->values()->all();

        $clientes = $docs->groupBy('client')->map(fn ($g, $c) => ['client' => $nombres[$c] ?? "Cliente {$c}", 'projects' => $g->pluck('project')->unique()->count(),
            'estimates' => $g->count(), 'warranty' => $g->where('type', 'garantia')->count(), 'amount' => round($g->sum('amount'), 2),
            'units' => (int) $g->sum($uds),
            'invoiced' => round($facturas->filter(fn ($f) => ($clientePorProyecto[$f->bill_projectid] ?? null) == $c)->sum('bill_final_amount'), 2)])
            ->sortByDesc('estimates')->values()->take(12)->all();

        $hechos = $docs->where('confirmed', true);
        $nuestros = $hechos->filter(fn ($d) => $d->amount == 0);
        $pagados = $hechos->filter(fn ($d) => $d->amount > 0);

        return [
            'done' => [
                'projects' => $hechos->pluck('project')->unique()->count(),
                'ours' => $nuestros->count(), 'ours_projects' => $nuestros->pluck('project')->unique()->count(), 'ours_units' => (int) $nuestros->sum($uds),
                'paid' => $pagados->count(), 'paid_projects' => $pagados->pluck('project')->unique()->count(), 'paid_amount' => round($pagados->sum('amount'), 2),
                'paid_units' => (int) $pagados->sum($uds),
                'months' => $hechos->groupBy('month')->sortKeys()->map(fn ($g, $m) => ['month' => $m, 'n' => $g->count(),
                    'by_type' => ['ours' => $g->filter(fn ($d) => $d->amount == 0)->count(), 'paid' => $g->filter(fn ($d) => $d->amount > 0)->count()],
                    'amount' => round($g->sum('amount'), 2)])->values()->slice(-18)->values()->all(),
            ],
            'kpis' => [
                'projects' => $docs->pluck('project')->unique()->count(), 'projects_total' => $proyectos->count(),
                'estimates' => $docs->count(), 'warranty' => $docs->where('type', 'garantia')->count(),
                'quoted' => round($conImporte->sum('amount'), 2), 'accepted_amount' => round($conImporte->where('status', 'accepted')->sum('amount'), 2),
                'accept_rate' => $decididos->count() ? round($decididos->where('status', 'accepted')->count() / $decididos->count(), 4) : null,
                'decided' => $decididos->count(),
                'units' => (int) $docs->sum($uds), 'units_warranty' => (int) $docs->where('type', 'garantia')->sum($uds),
                'invoiced' => round($facturas->sum('bill_final_amount'), 2), 'invoices' => $facturas->count(),
                'paid' => round($facturas->whereIn('bill_status', ['paid', 'pay_out', 'part_pay_out'])->sum('bill_final_amount'), 2),
                'median_days' => $plazos->count() ? $plazos[intdiv($plazos->count(), 2)] : null,
                'delivered' => $docs->where('delivered', true)->count(),
            ],
            'funnel' => [
                ['key' => 'estimates', 'label' => 'Presupuestos', 'n' => $docs->count()],
                ['key' => 'accepted', 'label' => 'Aceptados o a 0 €', 'n' => $docs->filter(fn ($d) => $d->status === 'accepted' || $d->amount == 0)->count()],
                ['key' => 'confirmed', 'label' => 'Hechos (pedido o albarán enviado)', 'n' => $hechos->count()],
                ['key' => 'delivered', 'label' => 'Albarán entregado o firmado', 'n' => $hechos->where('delivered', true)->count()],
            ],
            'types' => $tipos,
            'statuses' => collect(self::ESTADOS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l, 'n' => $docs->where('status', $k)->count(),
                'amount' => round($docs->where('status', $k)->sum('amount'), 2)])->filter(fn ($s) => $s['n'])->values()->all(),
            'months' => $meses,
            'models' => $modelos,
            'clients' => $clientes,
            'lead' => array_map(fn ($t) => ['label' => $t[2], 'n' => $plazos->filter(fn ($v) => $v >= $t[0] && ($t[1] === null || $v <= $t[1]))->count()], self::PLAZOS),
            'client_list' => $nombres->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->sortBy('name')->values()->all(),
            'url' => PortalDocCheck::URL,
        ];
    }
}
