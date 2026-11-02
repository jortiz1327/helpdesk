<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Models\ZkongBatch;
use App\Services\AemePortal;
use App\Services\PortalDocCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clientes y proyectos (Gestión de garantías). Solo consulta: cada cliente con garantías,
 * sea porque tiene garantías aquí o proyectos de garantía en el portal AEME, y su ficha
 * con sus hoteles o tiendas, sus garantías, los documentos del portal y las remesas a
 * ZKONG en las que han ido sus etiquetas. Nunca escribe en el portal.
 *
 * Cada fila se identifica por «p:<id del cliente del portal>» o, si la garantía no está
 * enlazada con el portal, por «n:<nombre del cliente>».
 */
class WarrantyClientsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'get'   => $this->get($request),
            default => $this->list(),
        };
    }

    private function list()
    {
        $portal = $this->portalClients();
        $estados = Warranty::statuses();
        $cerrados = Warranty::closedStatuses();

        $ws = Warranty::query()->orderByDesc('received_at')->orderByDesc('id')
            ->get(['id', 'code', 'status', 'received_at', 'client_name', 'portal_client_id']);
        $grupos = $ws->groupBy(fn ($w) => $w->portal_client_id ? "p:{$w->portal_client_id}" : 'n:' . $this->nombre($w->client_name));

        $filas = [];
        foreach ($grupos as $key => $xs) {
            $ultima = $xs->first();
            $filas[$key] = [
                'key' => $key, 'portal_id' => $ultima->portal_client_id, 'name' => trim((string) $ultima->client_name) ?: 'Sin cliente',
                'commercial' => null, 'vat' => null, 'city' => null, 'portal_warranties' => 0, 'portal_last' => null,
                'open' => $xs->whereNotIn('status', $cerrados)->count(), 'total' => $xs->count(),
                'last' => ['id' => $ultima->id, 'code' => $ultima->code, 'date' => $ultima->received_at?->toDateString(),
                    'status' => $estados[$ultima->status] ?? $ultima->status, 'closed' => in_array($ultima->status, $cerrados, true)],
            ];
        }
        foreach ($portal ?? [] as $c) {
            $key = "p:{$c['id']}";
            $filas[$key] = array_merge($filas[$key] ?? [
                'key' => $key, 'portal_id' => $c['id'], 'open' => 0, 'total' => 0, 'last' => null,
            ], [
                'name' => $c['name'], 'commercial' => $c['commercial'], 'vat' => $c['vat'], 'city' => $c['city'],
                'portal_warranties' => $c['warranties'], 'portal_last' => $c['last'],
            ]);
        }
        // Un cliente enlazado que el portal no devuelve se queda con el nombre de su garantía.
        $filas = collect($filas)->map(function ($f) {
            $f['last_at'] = max($f['last']['date'] ?? '', substr((string) ($f['portal_last']['date'] ?? ''), 0, 10)) ?: null;
            return $f;
        })->sortByDesc('last_at')->values();

        return response()->json([
            'ok' => true, 'portal' => $portal !== null, 'rows' => $filas,
            'kpis' => [
                'clients' => $filas->count(),
                'with_open' => $filas->where('open', '>', 0)->count(),
                'open' => $ws->whereNotIn('status', $cerrados)->count(),
                'unlinked' => $ws->whereNull('portal_client_id')->count(),
            ],
        ]);
    }

    /**
     * Clientes del portal con proyectos de garantía: nombre, CIF, ciudad, cuántos y el
     * último. Null si el portal no está o no responde.
     */
    private function portalClients(): ?array
    {
        if (! AemePortal::configured()) {
            return null;
        }
        if ($c = Cache::get('portal_aeme.warranty_clients')) {
            return $c;
        }
        try {
            $db = DB::connection('portal_aeme');
            $proy = $db->table('projects')->where('project_warranty', 1)->orderByDesc('project_created')
                ->get(['project_id', 'project_clientid', 'project_title', 'project_created'])->groupBy('project_clientid');
            $clientes = $proy->isEmpty() ? collect() : $db->table('clients')->whereIn('client_id', $proy->keys())
                ->get(['client_id', 'client_company_name', 'client_commercial_name', 'client_vat', 'client_billing_city']);
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer los clientes con garantías: ' . $e->getMessage());
            return null;
        }
        $out = $clientes->map(function ($c) use ($proy) {
            $ps = $proy->get($c->client_id);
            $p = $ps->first();
            return [
                'id' => (int) $c->client_id, 'name' => $this->limpio($c->client_company_name),
                'commercial' => $this->limpio($c->client_commercial_name) ?: null,
                'vat' => trim((string) $c->client_vat) ?: null, 'city' => trim((string) $c->client_billing_city) ?: null,
                'warranties' => $ps->count(),
                'last' => ['code' => $this->codigoPortal($p->project_title), 'date' => $p->project_created, 'url' => PortalDocCheck::URL . "/projects/{$p->project_id}"],
            ];
        })->values()->all();
        Cache::put('portal_aeme.warranty_clients', $out, now()->addMinutes(5));
        return $out;
    }

    private function get(Request $request)
    {
        $key = (string) $request->query('key', '');
        if (! preg_match('/^(p|n):(.+)$/s', $key, $m)) {
            return response()->json(['ok' => false, 'error' => 'Cliente no válido'], 400);
        }
        $portalId = $m[1] === 'p' ? (int) $m[2] : null;
        $q = Warranty::query()->leftJoin('users as u', 'u.id', '=', 'warranties.responsible_id');
        $portalId
            ? $q->where('warranties.portal_client_id', $portalId)
            : $q->whereNull('warranties.portal_client_id')->where(fn ($w) => $m[2] === ''
                ? $w->whereNull('warranties.client_name')->orWhere('warranties.client_name', '')
                : $w->whereRaw('lower(trim(warranties.client_name)) = ?', [$m[2]]));
        $ws = $q->orderByDesc('warranties.received_at')->orderByDesc('warranties.id')
            ->get(['warranties.id', 'warranties.code', 'warranties.status', 'warranties.received_at', 'warranties.closed_at',
                'warranties.client_name', 'warranties.project_name', 'warranties.portal_project_id', 'u.name as responsible_name']);

        // Solo clientes de esta lista: con garantías aquí o con proyectos de garantía en el
        // portal. Cualquier otro id del portal no enseña sus datos de contacto.
        if ($ws->isEmpty() && ! ($portalId && in_array($portalId, array_column($this->portalClients() ?? [], 'id'), true))) {
            return response()->json(['ok' => false, 'error' => 'Ese cliente no tiene garantías'], 404);
        }

        $estados = Warranty::statuses();
        $cerrados = Warranty::closedStatuses();
        $items = DB::table('warranty_items')->whereIn('warranty_id', $ws->pluck('id'))->groupBy('warranty_id')
            ->get(['warranty_id', DB::raw('count(*) as n'), DB::raw("sum(resolution <> 'pendiente') as reviewed"),
                DB::raw("sum(zkong_result = 'reposicion' or zkong_result = 'abono') as accepted")])->keyBy('warranty_id');

        $garantias = $ws->map(fn ($w) => [
            'id' => $w->id, 'code' => $w->code, 'status' => $estados[$w->status] ?? $w->status,
            'closed' => in_array($w->status, $cerrados, true),
            'received_at' => $w->received_at?->toDateString(), 'closed_at' => $w->closed_at?->toDateString(),
            'project' => trim((string) $w->project_name) ?: null, 'portal_project_id' => $w->portal_project_id,
            'responsible' => $w->responsible_name,
            'items' => (int) ($items[$w->id]->n ?? 0), 'reviewed' => (int) ($items[$w->id]->reviewed ?? 0),
        ])->values();

        // Remesas a ZKONG en las que han ido etiquetas de estas garantías.
        $remesas = DB::table('warranty_items as i')->join('zkong_batches as b', 'b.id', '=', 'i.zkong_batch_id')
            ->whereIn('i.warranty_id', $ws->pluck('id'))
            ->groupBy('b.id', 'b.code', 'b.status', 'b.sent_at', 'b.received_at')
            ->orderByDesc('b.id')
            ->get(['b.id', 'b.code', 'b.status', 'b.sent_at', 'b.received_at', DB::raw('count(*) as n'),
                DB::raw('sum(i.zkong_result is not null) as answered'),
                DB::raw("sum(i.zkong_result = 'reposicion' or i.zkong_result = 'abono') as accepted"),
                DB::raw("group_concat(distinct i.warranty_id) as warranty_ids")])
            ->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'status' => ZkongBatch::STATUSES[$b->status] ?? $b->status, 'status_key' => $b->status,
                'sent_at' => $b->sent_at ? substr($b->sent_at, 0, 10) : null, 'received_at' => $b->received_at ? substr($b->received_at, 0, 10) : null,
                'items' => (int) $b->n, 'answered' => (int) $b->answered, 'accepted' => (int) $b->accepted,
                'warranties' => $ws->whereIn('id', array_map('intval', explode(',', (string) $b->warranty_ids)))->pluck('code')->values(),
            ]);

        $cliente = null;
        $sitios = null;
        $docs = null;
        if ($portalId) {
            try {
                $c = DB::connection('portal_aeme')->table('clients')->where('client_id', $portalId)
                    ->first(['client_id', 'client_company_name', 'client_commercial_name', 'client_vat', 'client_phone', 'client_website',
                        'client_billing_street', 'client_billing_city', 'client_billing_zip', 'client_billing_state', 'client_status']);
                if ($c) {
                    $cliente = [
                        'id' => (int) $c->client_id, 'name' => $this->limpio($c->client_company_name),
                        'commercial' => $this->limpio($c->client_commercial_name) ?: null, 'vat' => trim((string) $c->client_vat) ?: null,
                        'phone' => trim((string) $c->client_phone) ?: null, 'website' => trim((string) $c->client_website) ?: null,
                        'address' => implode(', ', array_filter(array_map(fn ($v) => trim((string) $v), [$c->client_billing_street, trim($c->client_billing_zip . ' ' . $c->client_billing_city), $c->client_billing_state]))) ?: null,
                        'active' => $c->client_status === 'active', 'url' => PortalDocCheck::URL . "/clients/{$c->client_id}",
                    ];
                }
            } catch (Throwable $e) {
                Log::warning("Portal AEME: no se pudo leer el cliente {$portalId}: " . $e->getMessage());
            }
            $sitios = AemePortal::projects($portalId);
            $docs = PortalDocCheck::overview($portalId, $request->boolean('fresh'));
        }

        // Hoteles o tiendas: los del portal más los que solo aparecen en las garantías de aquí.
        $lugares = collect($sitios ?? [])->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'url' => PortalDocCheck::URL . "/projects/{$p['id']}"]);
        foreach ($garantias->pluck('project')->filter()->unique() as $nombre) {
            if (! $lugares->contains(fn ($l) => mb_strtolower($l['name']) === mb_strtolower($nombre))) {
                $lugares->push(['id' => null, 'name' => $nombre, 'url' => null]);
            }
        }
        $lugares = $lugares->map(function ($l) use ($garantias) {
            $suyas = $garantias->filter(fn ($g) => ($l['id'] && $g['portal_project_id'] === $l['id']) || ($g['project'] && mb_strtolower($g['project']) === mb_strtolower($l['name'])));
            return $l + ['total' => $suyas->count(), 'open' => $suyas->where('closed', false)->count()];
        })->sortBy([['total', 'desc'], [fn ($a, $b) => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))]])->values();

        return response()->json([
            'ok' => true, 'key' => $key, 'portal' => $portalId ? AemePortal::configured() : null,
            'client' => $cliente ?? ['id' => $portalId, 'name' => trim((string) ($ws->first()->client_name ?? '')) ?: 'Sin cliente', 'url' => $portalId ? PortalDocCheck::URL . "/clients/{$portalId}" : null],
            'places' => $lugares, 'warranties' => $garantias, 'batches' => $remesas,
            'portal_projects' => $docs['projects'] ?? null, 'loaded_at' => $docs['loaded_at'] ?? null,
            'kpis' => [
                'open' => $garantias->where('closed', false)->count(), 'closed' => $garantias->where('closed', true)->count(),
                'items' => $garantias->sum('items'), 'accepted' => (int) $items->sum('accepted'),
                'answered' => (int) $remesas->sum('answered'),
            ],
        ]);
    }

    private function nombre(?string $n): string
    {
        return mb_strtolower(trim((string) $n));
    }

    private function limpio(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }

    private function codigoPortal(?string $titulo): ?string
    {
        return preg_match('/\bG-\d{2}-\d{3,6}\b/', (string) $titulo, $m) ? $m[0] : null;
    }
}
