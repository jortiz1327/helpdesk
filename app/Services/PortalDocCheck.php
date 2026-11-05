<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Los documentos de garantía viven en el portal AEME; aquí solo se consultan y se
 * preparan los presupuestos para crearlos allí. Nunca escribe en el portal.
 *
 * En el portal cada garantía es un proyecto (project_warranty = 1) del cliente; de él
 * cuelgan sus presupuestos y, de cada presupuesto, pedido → expedición → albarán.
 * Mismo criterio que la app de garantías (WarrantyProjectsOverview).
 */
class PortalDocCheck
{
    public const URL = 'https://portal.aemegroup.com';
    public const SINCE = '2024-07-01';   // pedidos, expediciones y albaranes existen desde entonces
    public const DELIVERED = ['sent', 'delivered_by_carrier', 'delivered_and_signed'];

    /** Qué es cada presupuesto, como lo clasifica la app de garantías. */
    public const TIPOS = ['garantia' => 'En garantía', 'pilas' => 'Pilas', 'cliente' => 'Fuera de garantía', 'mano_obra' => 'Mano de obra', 'otro' => 'Otro'];

    /** Proyectos de garantía del cliente con sus presupuestos y la cadena de cada uno, o null sin portal. */
    public static function overview(int $clientId, bool $fresh = false): ?array
    {
        if (! AemePortal::configured()) {
            return null;
        }
        $key = "portal_aeme.warranty_docs.{$clientId}";
        if (! $fresh && ($c = Cache::get($key))) {
            return $c;
        }
        try {
            $db = DB::connection('portal_aeme');
            $projects = $db->table('projects')->where('project_warranty', 1)->where('project_clientid', $clientId)
                ->orderByDesc('project_id')->get(['project_id', 'project_title', 'project_created']);
            $est = $projects->isEmpty() ? collect() : $db->table('estimates')->whereIn('bill_projectid', $projects->pluck('project_id'))
                ->where('bill_created', '>=', self::SINCE)->orderByDesc('bill_estimateid')
                ->get(['bill_estimateid', 'bill_numberEstimate', 'bill_created', 'bill_date', 'bill_status', 'bill_projectid', 'bill_final_amount']);
            $ids = $est->pluck('bill_estimateid');
            $orders = $ids->isEmpty() ? collect() : $db->table('orders')->whereIn('bill_estimateid', $ids)->get(['bill_orderid', 'bill_numberOrder', 'bill_created', 'bill_status', 'bill_estimateid']);
            $exps = $orders->isEmpty() ? collect() : $db->table('expeditions')->whereIn('bill_orderid', $orders->pluck('bill_orderid'))->get(['bill_expeditionid', 'bill_numberExpedition', 'bill_created', 'bill_status', 'bill_orderid']);
            $dels = $exps->isEmpty() ? collect() : $db->table('deliveries')->whereIn('bill_expeditionid', $exps->pluck('bill_expeditionid'))
                ->get(['bill_deliveryid', 'bill_numberDelivery', 'bill_created', 'bill_status', 'bill_expeditionid', 'bill_date_sent_to_customer', 'bill_delivered_to']);
            $lines = $ids->isEmpty() ? collect() : $db->table('lineitems')->where('lineitemresource_type', 'estimate')->whereIn('lineitemresource_id', $ids)
                ->orderBy('lineitem_position')->get(['lineitemresource_id', 'lineitem_ref', 'lineitem_name', 'lineitem_description', 'lineitem_quantity', 'lineitem_unit']);
            $nombres = $db->table('items')->whereIn('item_ref', $lines->pluck('lineitem_ref')->filter()->unique())->pluck('item_name', 'item_ref')
                ->mapWithKeys(fn ($n, $r) => [strtoupper($r) => $n]);
        } catch (Throwable $e) {
            Log::warning("Portal AEME: no se pudieron leer los documentos del cliente {$clientId}: " . $e->getMessage());
            return null;
        }

        $porPedido = $exps->groupBy('bill_orderid');
        $porExp = $dels->groupBy('bill_expeditionid');
        $porPres = $orders->groupBy('bill_estimateid');
        $lineas = $lines->groupBy('lineitemresource_id');
        $porProyecto = $est->map(function ($e) use ($porPres, $porPedido, $porExp, $lineas, $nombres) {
            $o = $porPres->get($e->bill_estimateid, collect());
            $x = $o->flatMap(fn ($p) => $porPedido->get($p->bill_orderid, collect()));
            $a = $x->flatMap(fn ($p) => $porExp->get($p->bill_expeditionid, collect()));
            $l = $lineas->get($e->bill_estimateid, collect());
            return [
                'project_id' => (int) $e->bill_projectid, 'id' => (int) $e->bill_estimateid,
                'code' => self::code('PPTO', $e->bill_created, $e->bill_numberEstimate), 'url' => self::URL . "/estimates/{$e->bill_estimateid}",
                'date' => $e->bill_date, 'status' => (string) $e->bill_status, 'amount' => (float) $e->bill_final_amount,
                'type' => self::tipo((float) $e->bill_final_amount, $l),
                'products' => $l->map(fn ($r) => ['ref' => strtoupper(trim((string) $r->lineitem_ref)),
                    'name' => $nombres->get(strtoupper(trim((string) $r->lineitem_ref))) ?? Str::limit(trim(strip_tags((string) ($r->lineitem_name ?: $r->lineitem_description))), 90),
                    'qty' => (float) $r->lineitem_quantity])->values()->all(),
                'orders' => $o->map(fn ($p) => ['code' => self::code('PED', $p->bill_created, $p->bill_numberOrder), 'status' => (string) $p->bill_status, 'url' => self::URL . "/orders/{$p->bill_orderid}"])->values()->all(),
                'expeditions' => $x->map(fn ($p) => ['code' => self::code('EXP', $p->bill_created, $p->bill_numberExpedition), 'status' => (string) $p->bill_status, 'url' => self::URL . "/expeditions/{$p->bill_expeditionid}"])->values()->all(),
                'deliveries' => $a->map(fn ($p) => ['code' => self::code('ALB', $p->bill_created, $p->bill_numberDelivery), 'status' => (string) $p->bill_status,
                    'url' => self::URL . "/deliveries/{$p->bill_deliveryid}", 'sent_at' => $p->bill_date_sent_to_customer, 'delivered_to' => $p->bill_delivered_to])->values()->all(),
                'stage' => self::etapa($o, $x, $a),
            ];
        })->groupBy('project_id');

        $out = ['loaded_at' => now()->toIso8601String(), 'projects' => $projects->map(function ($p) use ($porProyecto) {
            $titulo = trim((string) $p->project_title);
            return [
                'id' => (int) $p->project_id, 'url' => self::URL . "/projects/{$p->project_id}",
                'code' => preg_match('/\bG-\d{2}-\d{3,6}\b/', $titulo, $m) ? $m[0] : null,
                'title' => implode(' · ', array_filter(array_map('trim', explode(' - ', $titulo)), fn ($t) => ! preg_match('/^G-\d{2}-\d{3,6}$/', $t))) ?: $titulo,
                'created' => $p->project_created,
                'estimates' => $porProyecto->get($p->project_id, collect())->values()->all(),
            ];
        })->filter(fn ($p) => $p['estimates'] || $p['created'] >= self::SINCE)->values()->all()];
        Cache::put($key, $out, now()->addMinutes(5));
        return $out;
    }

    /**
     * Los presupuestos que se crearían en el portal con los dispositivos revisados: uno
     * por «quién paga», con los precios del portal (tarifa del cliente o catálogo). Lo que
     * cubre la garantía va a 0 €. Devuelve qué falta para poder crearlos.
     */
    public static function drafts(Warranty $w): array
    {
        $checks = [];
        $add = function (string $tone, string $text) use (&$checks) { $checks[] = ['tone' => $tone, 'text' => $text]; };
        $fin = function ($drafts = []) use (&$checks) {
            return ['ready' => ! collect($checks)->contains('tone', 'bad') && collect($drafts)->every('ready'), 'checks' => $checks, 'drafts' => $drafts];
        };

        $items = WarrantyItem::where('warranty_id', $w->id)->orderBy('id')->get();
        $revisados = $items->where('resolution', '!=', 'pendiente')->whereNotNull('coverage');
        if ($n = $items->where('resolution', 'pendiente')->count()) {
            $add('warn', ($n === 1 ? 'Queda 1 dispositivo' : "Quedan {$n} dispositivos") . ' sin revisar: no entran.');
        }
        if ($revisados->isEmpty()) {
            $add('bad', 'No hay dispositivos revisados con «quién paga».');
            return $fin();
        }
        if (! AemePortal::configured()) {
            $add('bad', 'No hay conexión con el portal configurada.');
            return $fin();
        }

        try {
            $db = DB::connection('portal_aeme');
            $cliente = $w->portal_client_id ? $db->table('clients')->where('client_id', $w->portal_client_id)->first(['client_id', 'client_company_name']) : null;
            if (! $w->portal_client_id) {
                $add('bad', 'La garantía no tiene enlazado el cliente del portal: edítala y elige el cliente.');
            } elseif (! $cliente) {
                $add('bad', "El cliente enlazado (#{$w->portal_client_id}) ya no existe en el portal.");
            } else {
                $add('ok', 'Cliente del portal: ' . rtrim(trim($cliente->client_company_name), '.') . '.');
            }
            $iva = $db->table('taxrates')->where('taxrate_value', 21)->first(['taxrate_id', 'taxrate_name', 'taxrate_value']);
            $iva ? $add('ok', "Impuesto: {$iva->taxrate_name}.") : $add('bad', 'El portal no tiene el IVA del 21 %.');

            $modelos = DB::table('device_models')->whereIn('id', $revisados->pluck('device_model_id')->filter()->unique())->get(['id', 'battery_type', 'battery_quantity'])->keyBy('id');
            $refs = $revisados->pluck('aeme_ref')->filter()->map(fn ($r) => strtoupper(trim($r)))->unique()->values();
            $catalogo = $refs->isEmpty() ? collect() : $db->table('items')->whereIn('item_ref', $refs)->get(['item_ref', 'item_name', 'item_rate', 'item_unit'])->keyBy(fn ($i) => strtoupper(trim($i->item_ref)));
            $tarifa = $cliente ? $db->table('rates')->where('rate_clientid', $cliente->client_id)->get(['rate_ref', 'rate_rate'])->keyBy(fn ($r) => strtoupper(trim((string) $r->rate_ref))) : collect();
            $pilas = $db->table('items')->where(fn ($q) => $q->where('item_ref', 'like', 'AM-BZ%')->orWhere('item_unit', 'like', 'Pila%'))
                ->get(['item_ref', 'item_name', 'item_rate', 'item_unit']);
        } catch (Throwable $e) {
            Log::warning("Portal AEME: no se pudieron preparar los presupuestos de {$w->code}: " . $e->getMessage());
            $add('bad', 'El portal no responde. Prueba de nuevo en un rato.');
            return $fin();
        }

        $drafts = [];
        foreach (array_keys(WarrantyItem::COVERAGES) as $cov) {
            $suyos = $revisados->where('coverage', $cov);
            if ($suyos->isEmpty()) continue;
            $grupos = [];
            foreach ($suyos as $i) {
                if ($cov === 'pilas') {
                    $m = $modelos->get($i->device_model_id);
                    $tipo = $m && $m->battery_type ? strtoupper($m->battery_type) : '';
                    [$it, $porEtiqueta] = $tipo ? self::pila($pilas, $tipo, max(1, (int) ($m->battery_quantity ?? 1))) : [null, 1];
                    $k = $it ? strtoupper($it->item_ref) : "?{$tipo}";
                    $grupos[$k] ??= ['ref' => $it ? strtoupper($it->item_ref) : null, 'item' => $it, 'name' => $it ? trim($it->item_name) : ($tipo ? "Pilas {$tipo}" : 'Pilas (tipo sin indicar en el catálogo)'), 'qty' => 0, 'codes' => []];
                    $grupos[$k]['qty'] += $porEtiqueta;
                } else {
                    $k = $i->aeme_ref ? strtoupper(trim($i->aeme_ref)) : '';
                    $it = $k !== '' ? $catalogo->get($k) : null;
                    $grupos[$k] ??= ['ref' => $k ?: null, 'item' => $it, 'name' => $it ? trim($it->item_name) : ($i->product_name ?: ($i->server_model ?: ($i->kind === 'ap' ? 'Antena sin modelo' : 'Etiqueta sin modelo'))), 'qty' => 0, 'codes' => []];
                    $grupos[$k]['qty']++;
                }
                $grupos[$k]['codes'][] = $i->code;
            }
            ksort($grupos, SORT_NATURAL);

            $lineas = [];
            $problemas = [];
            foreach ($grupos as $g) {
                $it = $g['item'];
                $precio = 0.0;
                if ($cov !== 'garantia') {
                    $p = $g['ref'] ? ($tarifa->get($g['ref'])?->rate_rate ?? $it?->item_rate) : null;
                    if ($p === null) {
                        $problemas[] = $g['name'];
                    }
                    $precio = (float) ($p ?? 0);
                }
                $lineas[] = [
                    'ref' => $g['ref'], 'name' => $g['name'], 'qty' => $g['qty'], 'codes' => $g['codes'],
                    'unit' => $it && trim((string) $it->item_unit) !== '' ? trim($it->item_unit) : 'Unidad',
                    'rate' => $precio, 'total' => round($precio * $g['qty'], 2), 'found' => (bool) $it,
                    'from' => $cov === 'garantia' ? null : ($g['ref'] && $tarifa->has($g['ref']) ? 'tarifa' : ($it ? 'catálogo' : null)),
                ];
            }
            $avisos = [];
            if ($libres = collect($lineas)->where('found', false)->pluck('name')->all()) {
                $avisos[] = ['tone' => 'warn', 'text' => 'Sin producto en el portal: ' . implode(', ', $libres) . '. ' . (count($libres) === 1 ? 'Irá' : 'Irán') . ' como línea libre.'];
            }
            if ($problemas) {
                $avisos[] = ['tone' => 'bad', 'text' => 'Sin precio en el portal: ' . implode(', ', $problemas) . '. Ponle precio en la tarifa del cliente o en el catálogo del portal.'];
            }
            $base = round(array_sum(array_column($lineas, 'total')), 2);
            $impuesto = $iva ? round($base * (float) $iva->taxrate_value / 100, 2) : 0.0;
            $drafts[] = [
                'coverage' => $cov, 'type' => $cov === 'cliente' ? 'Fuera de garantía' : self::TIPOS[$cov], 'items' => $suyos->count(),
                'lines' => $lineas, 'checks' => $avisos, 'ready' => ! collect($avisos)->contains('tone', 'bad'),
                'base' => $base, 'tax' => $iva?->taxrate_name, 'tax_amount' => $impuesto, 'total' => round($base + $impuesto, 2),
            ];
        }
        return $fin($drafts) + ['client' => $cliente ? trim($cliente->client_company_name) : null, 'prefix' => 'PPTO-' . now()->format('y')];
    }

    /**
     * La batería del portal para una etiqueta: [artículo, cuántos por etiqueta]. Con
     * varias pilas se usa el pack soldado (CR2450 ×2 → AM-BZ2450*2, uno por etiqueta);
     * si no hay pack, pilas sueltas (AM-BZ2450, tantas como lleve).
     */
    private static function pila(Collection $pilas, string $tipo, int $n): array
    {
        $num = preg_replace('/\D/', '', $tipo);
        $porRef = $pilas->keyBy(fn ($p) => strtoupper(trim((string) $p->item_ref)));
        if ($n > 1 && ($pack = $porRef->get("AM-BZ{$num}*{$n}"))) {
            return [$pack, 1];
        }
        $suelta = $porRef->get("AM-BZ{$num}")
            ?? $pilas->first(fn ($p) => preg_match('/^pila$/i', trim((string) $p->item_unit)) && str_contains(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $p->item_name)), preg_replace('/[^A-Z0-9]/', '', $tipo)));
        return [$suelta, $n];
    }

    public static function tipo(float $importe, Collection $lineas): string
    {
        $t = fn ($v) => Str::lower(Str::ascii((string) $v));
        if ($importe == 0.0) return 'garantia';
        if ($lineas->contains(fn ($l) => preg_match('/pila|bateria/', $t($l->lineitem_unit) . ' ' . $t($l->lineitem_name)) || str_starts_with(strtoupper((string) $l->lineitem_ref), 'AM-BZ'))) return 'pilas';
        if ($lineas->contains(fn ($l) => str_contains($t($l->lineitem_name), 'esl'))) return 'cliente';
        if ($lineas->contains(fn ($l) => str_contains((string) $l->lineitem_ref, 'AM-GMG1'))) return 'mano_obra';
        return 'otro';
    }

    private static function etapa(Collection $o, Collection $x, Collection $a): string
    {
        if ($o->isEmpty()) return 'none';
        if ($x->isEmpty()) return 'ordered';
        return $a->isNotEmpty() && $a->every(fn ($d) => in_array($d->bill_status, self::DELIVERED, true)) ? 'done' : 'shipping';
    }

    /** Número como lo imprime el portal: PPTO-26-000123. */
    private static function code(string $prefix, ?string $created, $n): string
    {
        return $prefix . '-' . ($created ? Carbon::parse($created)->format('y') : '--') . '-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
