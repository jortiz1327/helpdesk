<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Services\AemePortal;
use App\Services\AttachmentService;
use App\Services\PortalDocCheck;
use App\Services\TicketLockService;
use App\Services\TicketReplyService;
use App\Services\WarrantyDocuments;
use App\Services\WarrantyLabels;
use App\Services\WarrantyPermissions;
use App\Services\WarrantyReplacements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * warranty_docs.php — documentos de una garantía. Presupuestos, pedidos y albaranes
 * viven en el portal AEME: aquí se consultan y se preparan los presupuestos para
 * crearlos allí (crear aún no está activado). Lo propio: informe y correo de resolución.
 */
class WarrantyDocumentsController extends Controller
{
    public function handle(Request $request)
    {
        if ($no = WarrantyPermissions::deny($request->user(), 'docs', (string) $request->query('action', 'list'))) return $no;
        return match ($request->query('action', 'list')) {
            'portal'        => $this->portal($request),
            'portal_drafts' => $this->portalDrafts($request),
            'download'      => $this->download($request),
            'report'        => $this->report($request),
            'email'         => $this->email($request),
            'send_email'    => $this->sendEmail($request),
            'finance'       => $this->finance($request),
            'sticker_data'  => $this->stickerData($request),
            'sticker'       => $this->sticker($request),
            'replacements'        => $this->replacements($request),
            'replacements_lookup' => $this->replacementsLookup($request),
            'replacements_save'   => $this->replacementsSave($request),
            'replacements_ticket' => $this->replacementsTicket($request),
            default         => $this->list($request),
        };
    }

    // Pegatina del paquete: lo que se propone (se retoca en el modal antes de imprimir).
    private function stickerData(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        return response()->json(['ok' => true, 'slots' => WarrantyDocuments::STICKER_SLOTS] + WarrantyDocuments::stickerData($w));
    }

    // El PDF de la pegatina con lo del modal: suelta o en posiciones de una hoja A4.
    private function sticker(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $v = validator($request->all(), [
            'code' => 'required|string|max:30', 'client' => 'nullable|string|max:120', 'company' => 'nullable|string|max:120',
            'shipped_at' => 'nullable|date', 'received_at' => 'nullable|date',
            'products' => 'array|max:16', 'products.*.name' => 'required|string|max:40', 'products.*.qty' => 'required|integer|min:1|max:99999',
            'format' => 'required|in:label,a4',
            'slots' => 'required_if:format,a4|array|min:1|max:' . WarrantyDocuments::STICKER_SLOTS,
            'slots.*' => 'integer|distinct|min:1|max:' . WarrantyDocuments::STICKER_SLOTS,
        ], [], ['products.*.name' => 'producto', 'products.*.qty' => 'cantidad', 'slots' => 'posiciones de la hoja']);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'error' => $v->errors()->first()], 400);
        }
        $d = $v->validated();
        $slots = collect($d['slots'] ?? [1])->map(fn ($s) => (int) $s)->sort()->values()->all();
        $bin = WarrantyDocuments::sticker($d, $d['format'], $slots);
        $name = "Pegatina-{$w->code}.pdf";
        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) . '"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
        ]);
    }

    // Aviso a Finanzas de los presupuestos a crear (para copiarlo; formato en Configuración).
    private function finance(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        return response()->json(['ok' => true, 'text' => WarrantyDocuments::financeText($w)]);
    }

    private function list(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        $informe = WarrantyDocument::where('warranty_id', $w->id)->where('kind', 'informe')->latest('id')->first();
        $sust = WarrantyReplacements::items($w);
        return response()->json(['ok' => true, 'summary' => WarrantyDocuments::summary($w),
            'replacements' => ['total' => $sust->count(), 'done' => $sust->filter(fn ($i) => $i->replaced_by)->count(), 'ticket' => (bool) $w->positions_ticket_id],
            'report' => $informe ? ['id' => $informe->id, 'number' => $informe->number, 'note' => $informe->note,
                'updated_at' => $informe->updated_at?->toDateTimeString()] : null]);
    }

    // Documentos de garantía del cliente en el portal (solo lectura).
    private function portal(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        if (! $w->portal_client_id) {
            return response()->json(['ok' => true, 'linked' => false, 'projects' => []]);
        }
        $r = PortalDocCheck::overview((int) $w->portal_client_id, $request->boolean('fresh'));
        return $r === null
            ? response()->json(['ok' => false, 'error' => 'El portal no responde. Prueba de nuevo en un rato.'], 502)
            : response()->json(['ok' => true, 'linked' => true] + $r);
    }

    // Presupuestos que se crearían en el portal y qué falta (solo lee; crear aún no está activado).
    private function portalDrafts(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        return response()->json(['ok' => true, 'enabled' => false] + PortalDocCheck::drafts($w));
    }

    private function download(Request $request)
    {
        $d = WarrantyDocument::findOrFail((int) $request->query('id'));
        $f = WarrantyDocuments::file($d);
        if (! $f) {
            return response()->json(['ok' => false, 'error' => 'El fichero ya no está'], 404);
        }
        [$bin, $mime, $name] = $f;
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        return response($bin, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
        ]);
    }

    private function report(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $r = WarrantyDocuments::report($w, $request->boolean('photos'), $request->user()->id);
        return is_string($r)
            ? response()->json(['ok' => false, 'error' => $r], 400)
            : response()->json(['ok' => true, 'id' => $r->id]);
    }

    /**
     * Correo de resolución: la plantilla rellenada, a quién (el último usado o el del
     * contacto del ticket), correos que se pueden sugerir y si este usuario puede enviarlo
     * desde el ticket. La resolución va SIEMPRE por correo: si el ticket es de WhatsApp,
     * hay que preguntarle al cliente su correo.
     */
    private function email(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        $me = $request->user();
        $motivo = $this->puedeEnviar($w, $me);
        return response()->json(['ok' => true] + $this->correosCliente($w, $me)
            + ['can_send' => $motivo === null, 'cannot_send' => $motivo]
            + WarrantyDocuments::resolutionEmail($w, $me->name));
    }

    /**
     * El correo del cliente: el usado antes en la garantía o el del contacto del ticket
     * (lo del ticket solo si quien pregunta lo ve en el Helpdesk), y los que se pueden
     * sugerir (también los del cliente en el portal AEME).
     */
    private function correosCliente(Warranty $w, $me): array
    {
        $t = $w->ticketVisibleTo($me) ? DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.id', $w->ticket_id)->first(['t.code', 't.channel', 'c.email as contact_email']) : null;

        $sugerencias = [];
        if ($w->customer_email) $sugerencias[$w->customer_email] = 'Usado antes en esta garantía';
        if ($t?->contact_email) $sugerencias[mb_strtolower($t->contact_email)] ??= 'Contacto del ticket';
        foreach ($this->correosPortal($w) as $email => $nombre) $sugerencias[$email] ??= "Portal AEME · {$nombre}";

        return ['to' => $w->customer_email ?: ($t->contact_email ?? null),
            'suggestions' => collect($sugerencias)->map(fn ($l, $e) => ['email' => $e, 'label' => $l])->values()->all(),
            'ticket' => $t ? ['code' => $t->code, 'channel' => $t->channel] : null];
    }

    /**
     * Sustituciones: las etiquetas que no se devuelven, su posición (artículo en ZKONG) y la
     * nueva; si la categoría lo pide, se ofrece el ticket de posiciones al correo del cliente.
     */
    private function replacements(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->query('id'));
        $items = WarrantyReplacements::items($w)->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'kind' => $i->kind,
            'product' => $i->aeme_ref ?: $i->product_name, 'resolution' => $i->resolution, 'coverage' => $i->coverage,
            'position' => $i->position, 'position_title' => $i->position_title, 'replaced_by' => $i->replaced_by])->values();
        $t = $w->positions_ticket_id ? DB::table('tickets')->where('id', $w->positions_ticket_id)->first(['id', 'code']) : null;
        $me = $request->user();
        return response()->json(['ok' => true, 'items' => $items, 'asks_ticket' => WarrantyReplacements::asksTicket($w),
            'category' => $w->category, 'positions_ticket' => $t ? ['id' => $t->id, 'code' => $t->code, 'visible' => Warranty::canSeeTicket($me, $t->id)] : null,
            'email' => $this->correosCliente($w, $me)]);
    }

    private function replacementsLookup(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        return response()->json(['ok' => true] + WarrantyReplacements::lookup($w, $request->user()->id));
    }

    // Lo que apunta el agente: posición y etiqueta nueva de cada una (la nueva, código válido).
    private function replacementsSave(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $propias = WarrantyReplacements::items($w)->keyBy('id');
        $filas = (array) $request->input('items', []);
        $nuevas = [];
        foreach ($filas as $f) {
            $i = $propias[(int) ($f['id'] ?? 0)] ?? null;
            if (! $i) return response()->json(['ok' => false, 'error' => 'Un dispositivo no es de esta garantía o ya no se sustituye'], 400);
            $nueva = trim((string) ($f['replaced_by'] ?? ''));
            if ($nueva !== '') {
                [$ok] = WarrantyLabels::parse([$nueva]);
                if (! $ok || WarrantyLabels::kindOf($ok[0]) !== $i->kind) {
                    return response()->json(['ok' => false, 'error' => "«{$nueva}» no es un código de " . ($i->kind === 'ap' ? 'antena (MAC de 12)' : 'etiqueta (9 caracteres)')], 400);
                }
                if ($ok[0] === $i->code) return response()->json(['ok' => false, 'error' => "La nueva de {$i->code} no puede ser la misma"], 400);
                if (in_array($ok[0], $nuevas, true)) return response()->json(['ok' => false, 'error' => "{$ok[0]} está puesta dos veces"], 400);
                $nuevas[] = $nueva = $ok[0];
            }
            $i->fill(['replaced_by' => $nueva ?: null,
                'position' => mb_substr(trim((string) ($f['position'] ?? '')), 0, 80) ?: null,
                'position_title' => mb_substr(trim((string) ($f['position_title'] ?? '')), 0, 191) ?: null]);
        }
        $cambios = 0;
        DB::transaction(function () use ($propias, &$cambios) {
            foreach ($propias as $i) if ($i->isDirty()) { $i->save(); $cambios++; }
        });
        if ($cambios) WarrantyDocuments::evento($w->id, $request->user()->id, 'Sustituciones: ' . ($propias->filter(fn ($i) => $i->replaced_by)
            ->map(fn ($i) => ($i->position ? "{$i->position} " : '') . "{$i->code} → {$i->replaced_by}")->implode(', ') ?: 'sin etiquetas nuevas'));
        return response()->json(['ok' => true, 'changed' => $cambios]);
    }

    private function replacementsTicket(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $email = trim((string) $request->input('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return response()->json(['ok' => false, 'error' => 'El correo del cliente no es válido'], 400);
        $r = WarrantyReplacements::createTicket($w, $email, $request->user());
        return is_string($r) ? response()->json(['ok' => false, 'error' => $r], 400) : response()->json(['ok' => true] + $r);
    }

    /** Por qué este usuario no puede enviar el correo desde el ticket, o null si puede. */
    private function puedeEnviar(Warranty $w, $me): ?string
    {
        if (! $w->ticket_id) return 'La garantía no tiene ticket: vincúlala a uno para enviarlo desde él.';
        if (! $w->ticketVisibleTo($me) || ! $me->can('tickets.reply')) return 'No puedes responder a este ticket en el Helpdesk.';
        return null;
    }

    /** Correos de las personas del cliente en el portal AEME (solo lectura). [correo => nombre] */
    private function correosPortal(Warranty $w): array
    {
        if (! $w->portal_client_id || ! AemePortal::configured()) return [];
        try {
            return DB::connection('portal_aeme')->table('users')->where('clientid', $w->portal_client_id)->where('type', 'client')
                ->whereNotNull('email')->where('email', '!=', '')->orderByDesc('account_owner')->limit(10)
                ->get(['email', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($u) => [mb_strtolower(trim($u->email)) => trim("{$u->first_name} {$u->last_name}") ?: 'cliente'])->all();
        } catch (Throwable $e) {
            Log::warning('Portal AEME: no se pudieron leer los correos del cliente: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Envía el correo de resolución como respuesta del ticket de la garantía (queda en la
     * conversación con el cliente), con el informe en PDF si se pide. Mismo envío y mismas
     * reglas que responder desde el Helpdesk; el correo se guarda en la garantía, no en la
     * ficha del contacto.
     */
    private function sendEmail(Request $request)
    {
        $w = Warranty::findOrFail((int) $request->input('id'));
        $me = $request->user();
        if ($motivo = $this->puedeEnviar($w, $me)) {
            return response()->json(['ok' => false, 'error' => $motivo], 403);
        }
        $to = mb_strtolower(trim((string) $request->input('to')));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Escribe un correo válido del cliente'], 400);
        }
        $asunto = trim((string) $request->input('subject'));
        $texto = trim((string) $request->input('body'));
        if ($asunto === '' || $texto === '') {
            return response()->json(['ok' => false, 'error' => 'Faltan el asunto o el texto'], 400);
        }

        $t = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')->where('t.id', $w->ticket_id)
            ->first(['t.id', 't.code', 't.subject', 't.assigned_to', 't.contact_id', 'c.name as contact_name', 'c.wa_id as contact_wa',
                'cat.signature as cat_signature', 'cat.name as category_name']);
        // Las mismas reglas que al responder en el Helpdesk: el ticket de otro agente, no.
        if (! $me->can('tickets.view_all') && $t->assigned_to && (int) $t->assigned_to !== (int) $me->id) {
            $otro = DB::table('users')->where('id', $t->assigned_to)->value('name') ?: 'otro agente';
            return response()->json(['ok' => false, 'error' => "El ticket {$t->code} lo tiene {$otro}: pídele que lo envíe o que te lo pase."], 403);
        }
        if ($quien = app(TicketLockService::class)->blockedBy((int) $t->id, (int) $me->id)) {
            return response()->json(['ok' => false, 'error' => "{$quien} está atendiendo el ticket {$t->code} ahora mismo"], 409);
        }
        $t->contact_email = $to;
        $t->subject = $asunto;   // la respuesta lleva [código del ticket] para volver al mismo hilo

        $adjuntos = null;
        if ($request->boolean('attach_report')) {
            $doc = WarrantyDocument::where('warranty_id', $w->id)->where('kind', 'informe')->where('source', 'generado')->latest('id')->first();
            $f = $doc ? WarrantyDocuments::file($doc) : null;
            if (! $f) return response()->json(['ok' => false, 'error' => 'Todavía no hay informe de resolución: genéralo antes'], 400);
            $aid = app(AttachmentService::class)->storeRaw($f[2], $f[0], $f[1], (int) $t->id, null, (int) $me->id);
            if (! $aid) return response()->json(['ok' => false, 'error' => 'No se pudo adjuntar el informe'], 500);
            $adjuntos = [$aid];
        }

        $html = collect(preg_split('/\R{2,}/u', $texto))->map(fn ($p) => '<p>' . nl2br(e(trim($p)), false) . '</p>')->implode('');
        $r = app(TicketReplyService::class)->porCorreo($t, $html, [], [], [], $me, $adjuntos, true, 'Resolución de tu garantía');
        if (! ($r['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $r['error'] ?? 'No se pudo enviar el correo'], $r['_status'] ?? 502);
        }

        $w->forceFill(['customer_email' => $to])->save();
        WarrantyDocuments::evento($w->id, $me->id, "Correo de resolución enviado a {$to} desde el ticket {$t->code}" . ($adjuntos ? ' (con el informe)' : ''));
        return response()->json(['ok' => true, 'ticket' => $t->code]);
    }
}
