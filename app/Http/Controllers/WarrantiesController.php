<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\AemePortal;
use App\Services\WarrantyLabels;
use App\Services\WarrantyPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Expedientes de garantía. Lista con filtros rápidos, ficha (resumen, seguimiento e
 * historial), alta y edición. Cada cambio de campo deja una línea en el historial con
 * el valor ya legible (nombres, no ids) para que no dependa de datos que cambien luego.
 */
class WarrantiesController extends Controller
{
    private const STALE_DAYS = 15;   // «Sin actualizar»: abiertas sin movimiento en estos días
    private const DEFAULT_CATEGORIES = ['Hoteles', 'Retail', 'Farmacia', 'Otros'];

    private function labels(): WarrantyLabels
    {
        return app(WarrantyLabels::class);
    }

    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'get'     => $this->get($request),
            'save'    => $this->save($request),
            'note'    => $this->note($request),
            'delete'  => $this->delete($request),
            'options' => $this->options(),
            'by_ticket' => $this->byTicket($request),
            'labels_check'    => $this->labelsCheck($request),
            'labels_add'      => $this->labelsAdd($request),
            'labels_recheck'  => $this->labelsRecheck($request),
            'label_model'     => $this->labelModel($request),
            'label_delete'    => $this->labelDelete($request),
            'catalog'         => response()->json(['ok' => true, 'models' => $this->labels()->catalog()]),
            'portal_clients'  => $this->portalClients(),
            'portal_projects' => $this->portalProjects($request),
            'review'            => $this->review($request),
            'photo_upload'      => $this->photoUpload($request),
            'photo_from_ticket' => $this->photoFromTicket($request),
            'photo_delete'      => $this->photoDelete($request),
            'ticket_images'     => $this->ticketImages($request),
            default   => $this->list($request),
        };
    }

    private function list(Request $request)
    {
        $me = $request->user();
        $q = Warranty::query()
            ->leftJoin('users as u', 'u.id', '=', 'warranties.responsible_id')
            ->leftJoin('tickets as t', 't.id', '=', 'warranties.ticket_id');

        $texto = trim((string) $request->query('q', ''));
        if ($texto !== '') {
            $like = '%' . addcslashes($texto, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('warranties.code', 'like', $like)
                ->orWhere('warranties.client_name', 'like', $like)
                ->orWhere('warranties.project_name', 'like', $like)
                ->orWhere('t.code', 'like', $like));
        }

        $abierta = fn ($w) => $w->where('warranties.status', '!=', 'cerrada');
        $vacio = fn (string $col) => fn ($w) => $w->whereNull($col)->orWhere($col, '');
        match ((string) $request->query('chip', '')) {
            'mine'        => $q->where('warranties.responsible_id', $me->id),
            'open'        => $q->where($abierta),
            'urgent'      => $q->where($abierta)->whereIn('warranties.urgency', ['alta', 'muy_urgente']),
            'pte_cliente', 'pte_zkong', 'pte_envio' => $q->where('warranties.status', $request->query('chip')),
            'stale'       => $q->where($abierta)->where(fn ($w) => $w->whereNull('warranties.last_activity_at')
                ->orWhere('warranties.last_activity_at', '<', now()->subDays(self::STALE_DAYS))),
            'no_owner'    => $q->where($abierta)->whereNull('warranties.responsible_id'),
            'no_client'   => $q->where($vacio('warranties.client_name')),
            'no_project'  => $q->where($vacio('warranties.project_name')),
            'no_category' => $q->where($vacio('warranties.category')),
            'closed'      => $q->where('warranties.status', 'cerrada'),
            default       => null,
        };

        [$col, $dir] = match ((string) $request->query('sort', '')) {
            'received_asc' => ['warranties.received_at', 'asc'],
            'code'         => ['warranties.code', 'desc'],
            'activity'     => ['warranties.last_activity_at', 'desc'],
            default        => ['warranties.received_at', 'desc'],
        };

        $size = min(100, max(10, (int) $request->query('size', 25)));
        $page = max(0, (int) $request->query('page', 0));
        $total = (clone $q)->count();
        $rows = $q->orderBy($col, $dir)->orderByDesc('warranties.id')
            ->offset($page * $size)->limit($size)
            ->select(['warranties.*', 'u.name as responsible_name', 't.code as ticket_code'])
            ->addSelect(['labels_count' => WarrantyItem::selectRaw('count(*)')->whereColumn('warranty_id', 'warranties.id')])
            ->get()
            ->map(fn ($w) => $this->row($w));

        return response()->json(['ok' => true, 'rows' => $rows, 'total' => $total, 'all' => Warranty::count()]);
    }

    private function row($w): array
    {
        return [
            'id'               => $w->id,
            'code'             => $w->code,
            'first_contact_at' => $w->first_contact_at?->toDateString(),
            'received_at'      => $w->received_at?->toDateString(),
            'portal_client_id'  => $w->portal_client_id,
            'client_name'       => $w->client_name,
            'portal_project_id' => $w->portal_project_id,
            'project_name'      => $w->project_name,
            'category'         => $w->category,
            'urgency'          => $w->urgency,
            'status'           => $w->status,
            'responsible_id'   => $w->responsible_id,
            'responsible_name' => $w->responsible_name ?? null,
            'ticket_id'        => $w->ticket_id,
            'ticket_code'      => $w->ticket_code ?? null,
            'closed_at'        => $w->closed_at?->toDateTimeString(),
            'last_activity_at' => $w->last_activity_at?->toDateTimeString(),
            'created_at'       => $w->created_at?->toDateTimeString(),
            'labels_count'     => (int) ($w->labels_count ?? 0),
        ];
    }

    private function get(Request $request)
    {
        $w = Warranty::query()
            ->leftJoin('users as u', 'u.id', '=', 'warranties.responsible_id')
            ->leftJoin('tickets as t', 't.id', '=', 'warranties.ticket_id')
            ->leftJoin('users as c', 'c.id', '=', 'warranties.created_by')
            ->where('warranties.id', (int) $request->query('id'))
            ->first(['warranties.*', 'u.name as responsible_name', 't.code as ticket_code', 't.subject as ticket_subject', 'c.name as created_by_name']);
        if (! $w) {
            return response()->json(['ok' => false, 'error' => 'La garantía no existe'], 404);
        }

        $notes = DB::table('warranty_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.warranty_id', $w->id)->orderByDesc('n.created_at')->orderByDesc('n.id')
            ->get(['n.id', 'n.type', 'n.body', 'n.created_at', 'u.name as user_name']);
        $events = DB::table('warranty_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.warranty_id', $w->id)->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->get(['e.id', 'e.field', 'e.from_value', 'e.to_value', 'e.created_at', 'u.name as user_name']);

        return response()->json(['ok' => true, 'warranty' => $this->row($w) + [
            'description'     => $w->description,
            'ticket_subject'  => $w->ticket_subject,
            'created_by_name' => $w->created_by_name,
        ], 'notes' => $notes, 'events' => $events, 'items' => $this->items($w->id)]);
    }

    // Garantías creadas a partir de un ticket (para enseñarlas en su ficha).
    private function byTicket(Request $request)
    {
        $rows = Warranty::where('ticket_id', (int) $request->query('ticket_id'))->orderBy('id')
            ->get(['id', 'code', 'status'])
            ->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'status' => $w->status, 'status_label' => Warranty::STATUSES[$w->status] ?? $w->status]);
        return response()->json(['ok' => true, 'warranties' => $rows]);
    }

    /* --- Etiquetas (productos) --- */

    // Comprueba los códigos pegados sin guardar nada (en el alta y al añadir a una garantía).
    private function labelsCheck(Request $request)
    {
        [$codes, $invalid] = WarrantyLabels::parse((string) $request->input('codes', ''));
        if (count($codes) > WarrantyLabels::MAX) {
            return response()->json(['ok' => false, 'error' => 'Como mucho ' . WarrantyLabels::MAX . ' etiquetas de una vez'], 400);
        }
        $w = $request->input('id') ? Warranty::find((int) $request->input('id')) : null;
        $ctx = $w ? WarrantyLabels::contextOf($w)
            : ['client' => (string) $request->input('client_name', ''), 'project' => (string) $request->input('project_name', '')];
        $ya = $w ? WarrantyItem::where('warranty_id', $w->id)->pluck('code')->all() : [];

        $results = $codes ? $this->labels()->check($codes, $ctx, $request->user()->id) : [];
        foreach ($results as &$r) {
            $r['already'] = in_array($r['code'], $ya, true);
            $r['model_label'] = $r['aeme_ref'];
        }
        return response()->json(['ok' => true, 'results' => $results, 'invalid' => $invalid]);
    }

    private function labelsAdd(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        [$codes] = WarrantyLabels::parse((string) $request->input('codes', ''));
        if (! $codes) {
            return response()->json(['ok' => false, 'error' => 'Escribe al menos un código: etiqueta (9 letras o números) o MAC de antena (12)'], 400);
        }
        if (count($codes) > WarrantyLabels::MAX) {
            return response()->json(['ok' => false, 'error' => 'Como mucho ' . WarrantyLabels::MAX . ' etiquetas de una vez'], 400);
        }
        $r = $this->anadirEtiquetas($w, $codes, (array) $request->input('manual', []), $request->user()->id);
        return response()->json(['ok' => true] + $r);
    }

    private function anadirEtiquetas(Warranty $w, array $codes, array $manual, int $userId): array
    {
        $r = $this->labels()->add($w, $codes, $manual, $userId);
        if ($r['added']) {
            $this->evento($w->id, $userId, 'items', null, '+' . count($r['added']) . ': ' . $this->lista($r['added']));
            $w->forceFill(['last_activity_at' => now()])->save();
        }
        return $r;
    }

    private function labelsRecheck(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $this->labels()->recheck($w, $request->user()->id);
        return response()->json(['ok' => true]);
    }

    private function labelModel(Request $request)
    {
        $item = WarrantyItem::findOrFail((int) $request->input('item_id'));
        $antes = $item->aeme_ref;
        if (! $this->labels()->setModel($item, (string) $request->input('aeme_ref', ''))) {
            return response()->json(['ok' => false, 'error' => 'Ese modelo no está en el catálogo'], 400);
        }
        if ($antes !== $item->aeme_ref) {
            $this->evento($item->warranty_id, $request->user()->id, 'item_model', $antes ? "{$item->code}: {$antes}" : null, "{$item->code}: {$item->aeme_ref}");
            Warranty::whereKey($item->warranty_id)->update(['last_activity_at' => now()]);
        }
        return response()->json(['ok' => true]);
    }

    private function labelDelete(Request $request)
    {
        $item = WarrantyItem::findOrFail((int) $request->input('item_id'));
        WarrantyPhotos::purgeFiles([$item->id]);
        $item->delete();
        $this->evento($item->warranty_id, $request->user()->id, 'items', null, '−1: ' . $item->code);
        Warranty::whereKey($item->warranty_id)->update(['last_activity_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** «A, B, C y 12 más» para el historial. */
    private function lista(array $codes): string
    {
        $n = count($codes);
        return implode(', ', array_slice($codes, 0, 10)) . ($n > 10 ? ' y ' . ($n - 10) . ' más' : '');
    }

    private function items(int $warrantyId): array
    {
        $items = WarrantyItem::query()->leftJoin('users as r', 'r.id', '=', 'warranty_items.reviewed_by')
            ->where('warranty_id', $warrantyId)->orderBy('warranty_items.id')
            ->get(['warranty_items.*', 'r.name as reviewed_by_name']);
        $fotos = WarrantyPhotos::forItems($items->pluck('id')->all());

        return $items->map(fn ($i) => [
            'id' => $i->id, 'code' => $i->code, 'kind' => $i->kind, 'lookup_state' => $i->lookup_state, 'server' => $i->server,
            'store_name' => $i->store_name, 'merchant_name' => $i->merchant_name, 'online' => $i->online,
            'last_commu_at' => $i->last_commu_at?->toDateTimeString(), 'server_model' => $i->server_model,
            'aeme_ref' => $i->aeme_ref, 'product_name' => $i->product_name, 'model_manual' => $i->model_manual,
            'warnings' => $i->warnings ?? [], 'checked_at' => $i->checked_at?->toDateTimeString(),
            'resolution' => $i->resolution, 'resolution_note' => $i->resolution_note,
            'broken_cases' => $i->broken_cases ?? [], 'battery_changed' => $i->battery_changed,
            'zkong_decision' => $i->zkong_decision, 'zkong_issue' => $i->zkong_issue,
            'reviewed_at' => $i->reviewed_at?->toDateTimeString(), 'reviewed_by_name' => $i->reviewed_by_name,
            'photos' => $fotos[$i->id] ?? [],
        ])->all();
    }

    /* --- Revisión de los dispositivos --- */

    /**
     * Marca uno o varios dispositivos con una solución. Lo que acompaña a cada solución
     * (motivos del daño, decisión y fallo para ZKONG…) se valida aquí; las fotos NO bloquean (se marcan diez como defectuosas y luego se
     * van subiendo): la ficha las muestra como pendientes hasta que estén todas.
     */
    private function review(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $ids = array_map('intval', (array) $request->input('item_ids', []));
        $items = WarrantyItem::where('warranty_id', $w->id)->whereIn('id', $ids)->get();
        if ($items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Elige al menos un dispositivo'], 400);
        }
        $res = (string) $request->input('resolution');
        if (! isset(WarrantyItem::RESOLUTIONS[$res])) {
            return response()->json(['ok' => false, 'error' => 'Solución no válida'], 400);
        }

        $nota = $this->texto($request->input('note'), 2000);
        $casos = array_values(array_intersect(array_keys(WarrantyItem::BROKEN_CASES), (array) $request->input('broken_cases', [])));
        $decision = (string) $request->input('zkong_decision', 'decidir');
        $fallo = $this->texto($request->input('zkong_issue'), 500);

        $zk = WarrantyItem::RESOLUTIONS[$res]['zkong'];
        $error = match (true) {
            $res === 'danada' && ! $casos                    => 'Indica por qué está dañada (al menos un motivo)',
            $res === 'desechada' && ! $nota                  => 'Indica por qué se desecha',
            $zk && ! isset(WarrantyItem::ZKONG_DECISIONS[$decision]) => 'Decisión de ZKONG no válida',
            $zk && $decision === 'enviar' && ! $fallo        => 'Describe el fallo para el RMA de ZKONG',
            default => null,
        };
        if ($error) {
            return response()->json(['ok' => false, 'error' => $error], 400);
        }

        $pendiente = $res === 'pendiente';
        $datos = [
            'resolution'      => $res,
            'resolution_note' => $nota,
            'broken_cases'    => $res === 'danada' ? $casos : null,
            'battery_changed' => $res === 'bateria' && $request->boolean('battery_changed'),
            'zkong_decision'  => $zk ? $decision : null,
            'zkong_issue'     => $zk ? $fallo : null,
            'reviewed_at'     => $pendiente ? null : now(),
            'reviewed_by'     => $pendiente ? null : $request->user()->id,
        ];
        WarrantyItem::whereIn('id', $items->pluck('id'))->update(
            array_merge($datos, ['broken_cases' => $datos['broken_cases'] ? json_encode($datos['broken_cases']) : null])
        );

        $this->evento($w->id, $request->user()->id, 'review', null,
            WarrantyItem::RESOLUTIONS[$res]['label'] . ' (' . $items->count() . '): ' . $this->lista($items->pluck('code')->all()));
        $w->forceFill(['last_activity_at' => now()])->save();

        return response()->json(['ok' => true, 'items' => $this->items($w->id)]);
    }

    /** El dispositivo de una petición y el tipo de foto, comprobando que ese tipo existe para él. */
    private function fotoDe(Request $request): array
    {
        $item = WarrantyItem::findOrFail((int) $request->input('item_id'));
        $type = (string) $request->input('type');
        if (! isset(WarrantyItem::photoTypes($item->kind)[$type])) {
            abort(response()->json(['ok' => false, 'error' => 'Tipo de foto no válido'], 400));
        }
        return [$item, $type];
    }

    private function photoUpload(Request $request)
    {
        [$item, $type] = $this->fotoDe($request);
        $file = $request->file('file');
        $error = $file ? WarrantyPhotos::upload($item, $type, $file, $request->user()->id) : 'Falta la foto';
        return $this->trasFoto($item, $error);
    }

    private function photoFromTicket(Request $request)
    {
        [$item, $type] = $this->fotoDe($request);
        $w = Warranty::findOrFail($item->warranty_id);
        $error = WarrantyPhotos::fromTicket($w, $item, $type, (string) $request->input('src'), $request->user()->id);
        return $this->trasFoto($item, $error);
    }

    private function photoDelete(Request $request)
    {
        $p = WarrantyPhotos::delete((int) $request->input('photo_id'));
        $item = $p ? WarrantyItem::find($p->warranty_item_id) : null;
        return $item ? $this->trasFoto($item, null) : response()->json(['ok' => false, 'error' => 'La foto ya no está'], 404);
    }

    private function trasFoto(WarrantyItem $item, ?string $error)
    {
        if ($error) {
            return response()->json(['ok' => false, 'error' => $error], 400);
        }
        Warranty::whereKey($item->warranty_id)->update(['last_activity_at' => now()]);
        return response()->json(['ok' => true, 'photos' => WarrantyPhotos::forItems([$item->id])[$item->id] ?? []]);
    }

    private function ticketImages(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        return response()->json(['ok' => true, 'images' => WarrantyPhotos::ticketImages($w)]);
    }

    /**
     * warranty_photo.php — sirve una foto de un dispositivo (?id=) o una imagen del ticket
     * de una garantía (?w=&src=). Va por ?token= de medios para poder usarse en <img>.
     */
    public function photo(Request $request)
    {
        if ($request->filled('id')) {
            $f = WarrantyPhotos::file((int) $request->query('id'));
            if (! $f) abort(404);
            return response()->file($f[0], ['Content-Type' => $f[1], 'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=31536000']);
        }
        $w = Warranty::find((int) $request->query('w'));
        $img = $w ? WarrantyPhotos::ticketImage($w, (string) $request->query('src')) : null;
        if (! $img) abort(404);
        return response($img[0], 200, ['Content-Type' => $img[1], 'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
    }

    // Clientes del portal AEME. available=false si no está configurado o no responde:
    // entonces el formulario deja escribir cliente y proyecto a mano.
    private function portalClients()
    {
        $list = AemePortal::clients();
        return response()->json(['ok' => true, 'available' => $list !== null, 'clients' => $list ?? []]);
    }

    private function portalProjects(Request $request)
    {
        $list = AemePortal::projects((int) $request->query('client_id'));
        return response()->json(['ok' => true, 'available' => $list !== null, 'projects' => $list ?? []]);
    }

    private function save(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->input('id');

        $datos = [
            'received_at'    => (string) $request->input('received_at', ''),
            'portal_client_id'  => $request->input('portal_client_id') ? (int) $request->input('portal_client_id') : null,
            'client_name'       => $this->texto($request->input('client_name'), 160),
            'portal_project_id' => $request->input('portal_project_id') ? (int) $request->input('portal_project_id') : null,
            'project_name'      => $this->texto($request->input('project_name'), 160),
            'category'       => $this->texto($request->input('category'), 60),
            'urgency'        => $request->input('urgency') ?: null,
            'status'         => (string) $request->input('status', 'no_iniciado'),
            'responsible_id' => $request->input('responsible_id') ? (int) $request->input('responsible_id') : null,
            'description'    => $this->texto($request->input('description'), 5000),
        ];

        if (! $this->esFecha($datos['received_at'])) {
            return response()->json(['ok' => false, 'error' => 'Indica la fecha de recepción'], 400);
        }
        // Fechas opcionales: vacías se quedan sin poner.
        $contacto = (string) $request->input('first_contact_at', '');
        $cierre = (string) $request->input('closed_at', '');
        foreach ([[$contacto, 'primera fecha de contacto'], [$cierre, 'fecha de cierre']] as [$v, $nombre]) {
            if ($v !== '' && ! $this->esFecha($v)) {
                return response()->json(['ok' => false, 'error' => "La {$nombre} no es válida"], 400);
            }
        }
        $datos['first_contact_at'] = $contacto ?: null;
        if (! isset(Warranty::STATUSES[$datos['status']])) {
            return response()->json(['ok' => false, 'error' => 'Estado no válido'], 400);
        }
        if ($datos['urgency'] !== null && ! isset(Warranty::URGENCIES[$datos['urgency']])) {
            return response()->json(['ok' => false, 'error' => 'Urgencia no válida'], 400);
        }
        if ($datos['responsible_id'] && ! $this->agentes()->has($datos['responsible_id'])) {
            return response()->json(['ok' => false, 'error' => 'Ese responsable no tiene acceso a garantías'], 400);
        }

        // El ticket se indica por su código (TK-…); vacío lo desvincula.
        $ticketCode = trim((string) $request->input('ticket_code', ''));
        $datos['ticket_id'] = null;
        if ($ticketCode !== '') {
            $datos['ticket_id'] = DB::table('tickets')->where('code', $ticketCode)->value('id');
            if (! $datos['ticket_id']) {
                return response()->json(['ok' => false, 'error' => "No existe el ticket {$ticketCode}"], 400);
            }
        }

        // Cerrada: el día indicado (con la hora de ahora) o, si no se indica, hoy.
        // Cualquier otro estado no tiene fecha de cierre.
        $cerrarEl = fn ($w) => $datos['status'] !== 'cerrada' ? null
            : ($cierre === '' ? ($w?->closed_at ?? now())
                : ($w?->closed_at?->toDateString() === $cierre ? $w->closed_at : \Illuminate\Support\Carbon::parse($cierre)->setTimeFrom(now())));

        $w = DB::transaction(function () use ($id, $datos, $me, $cerrarEl) {
            if (! $id) {
                $w = Warranty::create($datos + [
                    'code'             => Warranty::nextCode(),
                    'created_by'       => $me->id,
                    'closed_at'        => $cerrarEl(null),
                    'last_activity_at' => now(),
                ]);
                $this->evento($w->id, $me->id, 'created', null, $w->code);
                return $w;
            }

            $w = Warranty::lockForUpdate()->findOrFail($id);
            $valor = fn ($campo) => $w->{$campo} instanceof \DateTimeInterface ? $w->{$campo}->format('Y-m-d') : $w->{$campo};
            $antes = [];
            foreach (Warranty::TRACKED as $campo => $_) {
                $antes[$campo] = $valor($campo);
            }
            $w->fill($datos);
            $w->closed_at = $cerrarEl($w);

            $cambios = 0;
            foreach (Warranty::TRACKED as $campo => $_) {
                $nuevo = $valor($campo);
                if ((string) ($antes[$campo] ?? '') !== (string) ($nuevo ?? '')) {
                    $this->evento($w->id, $me->id, $campo, $this->legible($campo, $antes[$campo] ?? null), $this->legible($campo, $nuevo));
                    $cambios++;
                }
            }
            if ($cambios) {
                $w->last_activity_at = now();
            }
            $w->save();
            return $w;
        });

        // En el alta llegan las etiquetas ya comprobadas en el formulario.
        $etiquetas = ['added' => [], 'skipped' => []];
        if (! $id && trim((string) $request->input('labels', '')) !== '') {
            [$codes] = WarrantyLabels::parse((string) $request->input('labels'));
            $etiquetas = $this->anadirEtiquetas($w, array_slice($codes, 0, WarrantyLabels::MAX), (array) $request->input('manual', []), $me->id);
        }

        return response()->json(['ok' => true, 'id' => $w->id, 'code' => $w->code] + $etiquetas);
    }

    private function note(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $body = trim((string) $request->input('body', ''));
        $type = (string) $request->input('type', 'nota');
        if ($body === '') {
            return response()->json(['ok' => false, 'error' => 'Escribe el seguimiento'], 400);
        }
        if (! isset(Warranty::NOTE_TYPES[$type])) {
            $type = 'nota';
        }
        DB::table('warranty_notes')->insert([
            'warranty_id' => $w->id, 'user_id' => $request->user()->id, 'type' => $type,
            'body' => mb_substr($body, 0, 5000), 'created_at' => now(),
        ]);
        $w->forceFill(['last_activity_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    // Borrar un expediente se lleva su seguimiento e historial: solo el superadmin.
    private function delete(Request $request)
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['ok' => false, 'error' => 'Solo el superadministrador puede borrar garantías'], 403);
        }
        $id = (int) $request->input('id');
        WarrantyPhotos::purgeFiles(WarrantyItem::where('warranty_id', $id)->pluck('id')->all());
        Warranty::where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    private function options()
    {
        $usadas = Warranty::whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')->all();
        $cats = array_values(array_unique(array_merge(self::DEFAULT_CATEGORIES, $usadas)));
        sort($cats, SORT_NATURAL | SORT_FLAG_CASE);

        return response()->json([
            'ok'         => true,
            'statuses'   => Warranty::STATUSES,
            'urgencies'  => Warranty::URGENCIES,
            'note_types' => Warranty::NOTE_TYPES,
            'categories' => $cats,
            'agents'     => $this->agentes()->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values(),
            'stale_days' => self::STALE_DAYS,
            'review'     => [
                'resolutions'  => WarrantyItem::RESOLUTIONS,
                'zkong'        => WarrantyItem::ZKONG_DECISIONS,
                'broken_cases' => WarrantyItem::BROKEN_CASES,
                'photo_types'  => WarrantyItem::PHOTO_TYPES,
            ],
        ]);
    }

    /** Usuarios activos con acceso a garantías: [id => nombre]. */
    private function agentes()
    {
        return User::where('active', 1)->orderBy('name')->get()
            ->filter(fn ($u) => $u->can('warranties.access'))
            ->mapWithKeys(fn ($u) => [$u->id => $u->name]);
    }

    private function evento(int $warrantyId, ?int $userId, string $field, ?string $from, ?string $to): void
    {
        DB::table('warranty_events')->insert([
            'warranty_id' => $warrantyId, 'user_id' => $userId, 'field' => $field,
            'from_value' => $from, 'to_value' => $to, 'created_at' => now(),
        ]);
    }

    /** Valor del historial tal como se lee en pantalla (nombres y etiquetas, no claves). */
    private function legible(string $campo, $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return match ($campo) {
            'status'         => Warranty::STATUSES[$v] ?? $v,
            'urgency'        => Warranty::URGENCIES[$v] ?? $v,
            'responsible_id' => User::whereKey($v)->value('name') ?? "#{$v}",
            'ticket_id'      => DB::table('tickets')->where('id', $v)->value('code') ?? "#{$v}",
            'received_at', 'first_contact_at', 'closed_at' => date('d/m/Y', strtotime((string) $v)),
            default          => mb_substr((string) $v, 0, 500),
        };
    }

    private function esFecha(string $v): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function texto($v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
