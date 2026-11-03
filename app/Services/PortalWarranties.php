<?php

namespace App\Services;

use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Models\WarrantyItem;
use App\Models\ZkongBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GARANTÍAS en el portal público. Quien entra (correo verificado con código) ve las
 * garantías de SU EMPRESA: su correo se busca como contacto de un cliente del portal
 * AEME (solo lectura) y salen todas las de ese cliente. Si el correo no está en el
 * portal, solo las suyas (las de su correo o las de sus tickets).
 *
 * Lo que ve es la cara del cliente: fases sencillas, el resultado de cada etiqueta sin
 * comentarios internos, sus documentos y la conversación del ticket. Solicitar una
 * garantía crea SOLO un ticket (más completo); el equipo crea la garantía desde él.
 */
class PortalWarranties
{
    // Fotos que se piden de cada dispositivo (las mismas que pide la revisión).
    public const PHOTOS = [
        'esl' => ['front', 'back_batteries', 'back_no_batteries'],
        'ap'  => ['front', 'back'],
    ];

    // Lo que puede decir el cliente que le pasa. La clave viaja; el texto, en su idioma.
    public const ISSUES = [
        'no_enciende'  => 'No enciende / pantalla en blanco',
        'pantalla'     => 'Pantalla rota o rayada',
        'sin_conexion' => 'No se actualiza / sin conexión',
        'bateria'      => 'Batería',
        'otro'         => 'Otro',
    ];

    private const MAX_ITEMS = 60;
    private const DETAIL_MIN = 15;   // lo mínimo que hay que contar de cada dispositivo (igual que el formulario)
    private const PHOTO_HOURS = 6;   // tras solicitar, las fotos se pueden subir durante este rato

    public function __construct(private PortalService $portal) {}

    /* ------------------------------------------------------------------ Quién */

    /**
     * Cliente del portal AEME de este correo: ['id' => int, 'name' => string] o null.
     * Solo contactos de tipo cliente y activos. Si el portal no responde, null (verá solo
     * lo suyo, nunca más de la cuenta).
     */
    public function company(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if (! AemePortal::configured() || $email === '') return null;
        return Cache::remember('portal_wr_company.' . sha1($email), now()->addMinutes(10), function () use ($email) {
            try {
                $db = DB::connection('portal_aeme');
                $cid = $db->table('users')->where('type', 'client')->where('status', 'active')
                    ->where('email', $email)->whereNotNull('clientid')->value('clientid');
                if (! $cid) return null;
                $name = $db->table('clients')->where('client_id', $cid)->value('client_company_name');
                return ['id' => (int) $cid, 'name' => trim(preg_replace('/\s+/u', ' ', (string) $name))];
            } catch (Throwable $e) {
                Log::warning('Portal garantías: no se pudo leer el cliente del correo: ' . $e->getMessage());
                return null;
            }
        }) ?: null;
    }

    /** Las garantías que puede ver este correo (de su empresa, o solo las suyas). */
    private function visibles(string $email)
    {
        $email = mb_strtolower(trim($email));
        $empresa = $this->company($email);
        $suyos = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('c.email', $email)->select('t.id');
        return Warranty::query()->where(function ($q) use ($email, $empresa, $suyos) {
            if ($empresa) $q->orWhere('portal_client_id', $empresa['id']);
            $q->orWhere('customer_email', $email)->orWhereIn('ticket_id', $suyos);
        });
    }

    /** Solicitudes que aún no son garantía, de este correo o de su empresa. */
    private function solicitudes(string $email)
    {
        $email = mb_strtolower(trim($email));
        $empresa = $this->company($email);
        return DB::table('warranty_requests as r')->join('tickets as t', 't.id', '=', 'r.ticket_id')
            ->where(fn ($q) => $empresa ? $q->where('r.email', $email)->orWhere('r.portal_client_id', $empresa['id']) : $q->where('r.email', $email))
            ->whereNotExists(fn ($e) => $e->from('warranties as w')->whereColumn('w.ticket_id', 'r.ticket_id'))
            ->whereNull('t.merged_into_id');
    }

    /** Hoteles o tiendas de su empresa, para elegir dónde están las etiquetas. */
    public function places(string $email): array
    {
        $empresa = $this->company($email);
        $lista = $empresa ? (AemePortal::projects($empresa['id']) ?? []) : [];
        return ['company' => $empresa['name'] ?? null,
            'places' => array_map(fn ($p) => ['id' => (int) $p['id'], 'name' => (string) $p['name']], $lista)];
    }

    /* ------------------------------------------------------------------ Lista */

    public function list(string $email): array
    {
        $ws = $this->visibles($email)->orderByDesc('last_activity_at')->orderByDesc('id')->limit(200)->get();
        $ids = $ws->pluck('id')->all();
        $items = $ids ? WarrantyItem::whereIn('warranty_id', $ids)->get(['id', 'warranty_id', 'kind', 'resolution', 'reviewed_at', 'zkong_batch_id', 'zkong_result'])->groupBy('warranty_id') : collect();
        $lotes = $this->lotes($items->flatten());

        $out = $ws->map(fn ($w) => $this->resumen($w, $items[$w->id] ?? collect(), $lotes))->all();

        // Solicitudes que el equipo aún no ha pasado a garantía: salen como «Solicitada».
        foreach ($this->solicitudes($email)->where('t.status', '!=', 'cerrado')->orderByDesc('r.id')->limit(50)
            ->get(['r.id', 'r.items', 'r.project_name', 'r.client_name', 'r.created_at', 't.code', 't.last_message_at']) as $r) {
            $its = collect(json_decode((string) $r->items, true) ?: []);
            $out[] = [
                'type' => 'request', 'code' => $r->code, 'ticket' => $r->code,
                'place' => (string) ($r->project_name ?: $r->client_name),
                'labels' => $its->where('kind', 'esl')->count(), 'aps' => $its->where('kind', 'ap')->count(),
                'phase' => 'solicitada', 'open' => true, 'hint' => null,
                'steps' => $this->pasos('solicitada', ['solicitada' => (string) $r->created_at]),
                'updated' => Carbon::parse($r->last_message_at ?: $r->created_at)->toIso8601String(),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['updated'], $a['updated']));
        return ['company' => $this->company($email)['name'] ?? null, 'warranties' => $out];
    }

    /** Lotes de ZKONG de esos dispositivos: [batch_id => {status, sent_at}]. */
    private function lotes($items): array
    {
        $ids = collect($items)->pluck('zkong_batch_id')->filter()->unique()->all();
        return $ids ? ZkongBatch::whereIn('id', $ids)->get(['id', 'status', 'sent_at'])->keyBy('id')->all() : [];
    }

    /** Lo que se cuenta de una garantía en la lista. */
    private function resumen(Warranty $w, $items, array $lotes): array
    {
        [$fase, $fechas, $aviso] = $this->fase($w, $items, $lotes);
        return [
            'type' => 'warranty', 'code' => $w->code,
            'ticket' => $w->ticket_id ? DB::table('tickets')->where('id', $w->ticket_id)->value('code') : null,
            'place' => (string) ($w->project_name ?: $w->client_name),
            'labels' => $items->where('kind', '!=', 'ap')->count(), 'aps' => $items->where('kind', 'ap')->count(),
            'phase' => $fase, 'open' => $w->isOpen(), 'hint' => $aviso,
            'steps' => $this->pasos($fase, $fechas),
            'updated' => ($w->last_activity_at ?? $w->updated_at ?? $w->created_at)?->toIso8601String(),
        ];
    }

    /**
     * Fase para el cliente: recibida → en revisión → en el fabricante → resuelta, con la
     * fecha de cada una, y un aviso cuando la pelota está en su tejado o esperamos algo.
     * [fase, [fase => fecha], aviso|null]
     */
    private function fase(Warranty $w, $items, array $lotes): array
    {
        $estado = (string) $w->status;
        $enZk = $items->filter(fn ($i) => $i->zkong_batch_id && ! $i->zkong_result
            && in_array($lotes[$i->zkong_batch_id]->status ?? '', ['enviada', 'recibida'], true));
        $fueZk = $items->filter(fn ($i) => $i->zkong_batch_id && isset($lotes[$i->zkong_batch_id]) && $lotes[$i->zkong_batch_id]->sent_at);
        $revisado = $items->pluck('reviewed_at')->filter()->min();

        $fechas = ['recibida' => $w->received_at?->toDateString() ?? $w->created_at?->toDateString()];
        $fechas['revision'] = $revisado ? Carbon::parse($revisado)->toDateString()
            : ($estado !== Warranty::STATUS_NEW ? $w->status_changed_at?->toDateString() : null);
        $envio = $fueZk->map(fn ($i) => $lotes[$i->zkong_batch_id]->sent_at)->min();
        if ($envio) $fechas['fabricante'] = Carbon::parse($envio)->toDateString();

        if (! $w->isOpen()) {
            $fechas['resuelta'] = $w->closed_at?->toDateString();
            return ['resuelta', $fechas, null];
        }
        if (in_array($estado, ['pte_envio', 'enviada'], true)) {
            return ['resuelta', $fechas, $estado === 'enviada' ? 'devuelta' : 'preparando_envio'];
        }
        if ($enZk->isNotEmpty() || $estado === 'pte_zkong') return ['fabricante', $fechas, 'fabricante'];
        $aviso = match ($estado) {
            'pte_cliente', 'pte_presupuesto', 'rechazado_cliente' => 'tu_respuesta',
            'falta_stock' => 'material',
            default => null,
        };
        if ($fechas['revision'] || $items->where('resolution', '!=', 'pendiente')->isNotEmpty()) return ['revision', $fechas, $aviso];
        return ['recibida', $fechas, $aviso];
    }

    /** Las fases en orden con su estado (hecha | actual | pendiente) y su fecha. */
    private function pasos(string $actual, array $fechas): array
    {
        $orden = ['solicitada', 'recibida', 'revision', 'fabricante', 'resuelta'];
        $pos = array_search($actual, $orden, true);
        $out = [];
        foreach ($orden as $i => $k) {
            if ($k === 'solicitada' && $actual !== 'solicitada') continue;   // solo mientras no es garantía
            $estado = $i < $pos ? 'done' : ($i === $pos ? ($k === 'resuelta' ? 'done' : 'current') : 'todo');
            // El fabricante es un paso que muchas no dan: si se saltó, se dice.
            if ($k === 'fabricante' && $estado === 'done' && empty($fechas['fabricante'])) $estado = 'skipped';
            $out[] = ['key' => $k, 'state' => $estado, 'date' => $fechas[$k] ?? null];
        }
        return $out;
    }

    /* ----------------------------------------------------------------- Ficha */

    /** La garantía si este correo puede verla, o null. */
    public function find(string $email, string $code): ?Warranty
    {
        return $this->visibles($email)->where('code', strtoupper(trim($code)))->first();
    }

    public function detail(string $email, string $code): ?array
    {
        $w = $this->find($email, $code);
        if (! $w) return $this->requestDetail($email, $code);

        $items = WarrantyItem::where('warranty_id', $w->id)->orderBy('id')->get();
        $lotes = $this->lotes($items);
        $base = $this->resumen($w, $items, $lotes);
        $t = $w->ticket_id ? DB::table('tickets')->where('id', $w->ticket_id)->first(['id', 'code', 'status', 'merged_into_id']) : null;

        return $base + [
            'company' => $w->client_name, 'received_at' => $w->received_at?->toDateString(), 'created_at' => $w->created_at?->toIso8601String(),
            'closed_at' => $w->closed_at?->toIso8601String(),
            'items' => $items->map(fn ($i) => [
                'code' => $i->code, 'kind' => $i->kind === 'ap' ? 'ap' : 'esl',
                'model' => (string) ($i->product_name ?: $i->aeme_ref),
                'issue' => $i->reported_issue,
                'result' => $this->resultado($i, $lotes),
            ])->values()->all(),
            'documents' => $this->documentos($w),
            'messages' => $t ? $this->portal->mensajesCliente((int) $t->id) : [],
            'can_reply' => (bool) $t,
        ];
    }

    /** Una solicitud que aún no es garantía, por el código de su ticket. */
    private function requestDetail(string $email, string $code): ?array
    {
        $r = $this->solicitudes($email)->where('t.code', strtoupper(trim($code)))
            ->first(['r.*', 't.code', 't.id as tid', 't.last_message_at']);
        if (! $r) return null;
        $its = collect(json_decode((string) $r->items, true) ?: []);
        return [
            'type' => 'request', 'code' => $r->code, 'ticket' => $r->code,
            'place' => (string) ($r->project_name ?: $r->client_name), 'company' => $r->client_name,
            'labels' => $its->where('kind', 'esl')->count(), 'aps' => $its->where('kind', 'ap')->count(),
            'phase' => 'solicitada', 'open' => true, 'hint' => null,
            'steps' => $this->pasos('solicitada', ['solicitada' => (string) $r->created_at]),
            'updated' => Carbon::parse($r->last_message_at ?: $r->created_at)->toIso8601String(),
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(), 'received_at' => null, 'closed_at' => null,
            'items' => $its->map(fn ($i) => ['code' => $i['code'], 'kind' => $i['kind'], 'model' => '',
                'issue' => $this->textoFallo($i), 'issue_key' => $i['issue'] ?? 'otro', 'detail' => $i['detail'] ?? '',
                'photos' => count((array) ($i['photos'] ?? [])), 'result' => ['key' => 'pendiente_envio']])->values()->all(),
            'documents' => [],
            'messages' => $this->portal->mensajesCliente((int) $r->tid),
            'can_reply' => true,
        ];
    }

    /**
     * Resultado de una etiqueta tal y como lo ve el cliente: una clave (la UI la traduce)
     * y, si no la cubre la garantía, el motivo. Nada de notas internas ni de ZKONG.
     */
    private function resultado(WarrantyItem $i, array $lotes): array
    {
        if ($i->replaced_by) return ['key' => 'sustituida'];
        if ($i->zkong_batch_id && ! $i->zkong_result && in_array($lotes[$i->zkong_batch_id]->status ?? '', ['enviada', 'recibida'], true)) {
            return ['key' => 'fabricante'];
        }
        $r = (string) $i->resolution;
        if ($r === 'danada') {
            // Claves: la UI las traduce al idioma del cliente.
            $motivos = array_values(array_filter((array) $i->broken_cases, fn ($c) => isset(WarrantyItem::BROKEN_CASES[$c])));
            return ['key' => 'no_cubierta', 'reasons' => $motivos];
        }
        if ($r === 'defectuosa') return ['key' => $i->coverage === 'cliente' ? 'defectuosa' : 'cubierta'];
        return ['key' => in_array($r, ['pendiente', 'correcta', 'bateria', 'recuperada', 'reparada', 'no_reproduce', 'desechada'], true) ? $r : 'pendiente'];
    }

    /**
     * Documentos que puede bajar el cliente: la pegatina del paquete siempre, y el informe
     * de resolución cuando ya se le ha mandado (o la garantía está cerrada).
     */
    private function documentos(Warranty $w): array
    {
        $out = [['key' => 'sticker']];
        $informe = WarrantyDocument::where('warranty_id', $w->id)->where('kind', 'informe')->where('source', 'generado')->latest('id')->first(['id', 'number', 'updated_at']);
        $enviado = DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'documents')
            ->where('to_value', 'like', 'Correo de resolución enviado%')->exists();
        if ($informe && ($enviado || ! $w->isOpen())) {
            $out[] = ['key' => 'report', 'number' => $informe->number, 'date' => $informe->updated_at?->toIso8601String()];
        }
        return $out;
    }

    /** El PDF que pide: [contenido, nombre] o null si no puede verlo o no hay. */
    public function pdf(string $email, string $code, string $doc): ?array
    {
        $w = $this->find($email, $code);
        if (! $w) return null;
        if ($doc === 'sticker') {
            return [WarrantyDocuments::sticker(WarrantyDocuments::stickerData($w)), 'pegatina-' . $w->code . '.pdf'];
        }
        if ($doc === 'report' && collect($this->documentos($w))->contains('key', 'report')) {
            $d = WarrantyDocument::where('warranty_id', $w->id)->where('kind', 'informe')->where('source', 'generado')->latest('id')->first();
            $f = $d ? WarrantyDocuments::file($d) : null;
            return $f ? [$f[0], 'informe-' . $w->code . '.pdf'] : null;
        }
        return null;
    }

    /* -------------------------------------------------------------- Responder */

    /** Responder en la conversación de una garantía (o de una solicitud). */
    public function reply(string $email, string $code, string $body, array $files): array
    {
        $w = $this->find($email, $code);
        $tid = $w?->ticket_id;
        if (! $w) {
            $tid = $this->solicitudes($email)->where('t.code', strtoupper(trim($code)))->value('r.ticket_id');
        }
        $t = $tid ? DB::table('tickets')->where('id', $tid)->first(['id', 'contact_id', 'status']) : null;
        if (! $t) return [false, 'Esta garantía no tiene conversación'];

        // Responde alguien de la empresa que no es el contacto del ticket: que se sepa quién.
        $dueño = DB::table('contacts')->where('id', $t->contact_id)->value('email');
        $cuerpo = trim($body);
        if ($cuerpo !== '' && $dueño && mb_strtolower($dueño) !== mb_strtolower($email)) {
            $cuerpo .= "\n\n— " . mb_strtolower($email);
        }
        return $this->portal->responderTicket($t, $cuerpo, $files);
    }

    /* -------------------------------------------------------------- Solicitar */

    /**
     * Solicitud de garantía: crea el ticket (categoría Garantías) con todo ordenado y
     * guarda la solicitud para que el equipo cree la garantía desde él. Las fotos se suben
     * después, por tandas (photo()). [ok, error, código del ticket].
     */
    public function request(string $email, array $in): array
    {
        $email = mb_strtolower(trim($email));
        $empresa = $this->company($email);

        // Dónde: un hotel o tienda de su empresa, u otro sitio escrito a mano.
        $lugar = null;
        $pid = (int) ($in['place_id'] ?? 0);
        if ($pid && $empresa) {
            $lugar = collect(AemePortal::projects($empresa['id']) ?? [])->firstWhere('id', $pid);
            if (! $lugar) return [false, 'Ese hotel o tienda no es de tu empresa', null];
        }
        $otro = mb_substr(trim((string) ($in['place_other'] ?? '')), 0, 190);
        if (! $lugar && $otro === '') return [false, 'Indica dónde están las etiquetas', null];

        // Qué: códigos válidos, sin repetir, con lo que les pasa.
        $items = [];
        foreach (array_slice((array) ($in['items'] ?? []), 0, self::MAX_ITEMS) as $i) {
            $code = strtoupper(preg_replace('/[\s:\-]/', '', (string) ($i['code'] ?? '')));
            $kind = preg_match(WarrantyLabels::AP_PATTERN, $code) && ! preg_match('/^\d{9}$/', $code) ? 'ap' : 'esl';
            if (! preg_match(WarrantyLabels::PATTERN, $code) && ! preg_match(WarrantyLabels::AP_PATTERN, $code)) {
                return [false, "«{$code}» no parece el código de una etiqueta ni de una antena", null];
            }
            if (isset($items[$code])) continue;
            // Qué le pasa y explicado: sin eso no se distingue un fallo de algo que no cubre la garantía.
            $issue = (string) ($i['issue'] ?? '');
            if (! isset(self::ISSUES[$issue])) return [false, "Elige qué le pasa a {$code}", null];
            $detalle = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($i['detail'] ?? ''))), 0, 300);
            if (mb_strlen($detalle) < self::DETAIL_MIN) return [false, "Explica qué le pasa a {$code} (qué ves, desde cuándo y qué has probado)", null];
            $items[$code] = ['code' => $code, 'kind' => $kind, 'issue' => $issue, 'detail' => $detalle, 'photos' => []];
        }
        if (! $items) return [false, 'Añade al menos una etiqueta o antena', null];
        if (count((array) ($in['items'] ?? [])) > self::MAX_ITEMS) return [false, 'Como mucho ' . self::MAX_ITEMS . ' dispositivos por solicitud', null];

        $nombre = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        $tel = mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40);
        $dir = mb_substr(trim((string) ($in['address'] ?? '')), 0, 600);
        if ($nombre === '') return [false, 'Indica tu nombre', null];
        if ($dir === '') return [false, 'Indica la dirección desde la que enviarás las etiquetas', null];

        $sitio = $lugar['name'] ?? $otro;
        $nE = collect($items)->where('kind', 'esl')->count();
        $nA = collect($items)->where('kind', 'ap')->count();
        $cuantos = implode(' y ', array_filter([$nE ? ($nE === 1 ? '1 etiqueta' : "{$nE} etiquetas") : null, $nA ? ($nA === 1 ? '1 antena' : "{$nA} antenas") : null]));

        // El cuerpo del ticket, ordenado para quien lo lee en el Helpdesk.
        $lineas = ["Solicitud de garantía desde el portal", '',
            'Empresa: ' . ($empresa['name'] ?? '(su correo no está en el portal AEME)'),
            'Dónde: ' . $sitio, '', "Dispositivos ({$cuantos}):"];
        foreach ($items as $i) {
            $lineas[] = '- ' . $i['code'] . ($i['kind'] === 'ap' ? ' (antena)' : '') . ': ' . self::ISSUES[$i['issue']] . ($i['detail'] !== '' ? ' — ' . $i['detail'] : '');
        }
        $lineas = array_merge($lineas, ['', 'Contacto: ' . $nombre . ($tel !== '' ? " · {$tel}" : '') . " · {$email}", 'Envío desde: ' . $dir]);

        $cat = DB::table('ticket_categories')->where('active', 1)->where('name', 'like', 'Garant%')->value('id');
        [$ok, $error, $code] = $this->portal->createTicket($email, [
            'subject' => mb_substr("Garantía · {$sitio} · {$cuantos}", 0, 200),
            'body' => implode("\n", $lineas), 'category_id' => $cat, 'name' => $nombre, 'phone' => $tel,
        ]);
        if (! $ok) return [false, $error, null];

        $tid = (int) DB::table('tickets')->where('code', $code)->value('id');
        DB::table('warranty_requests')->updateOrInsert(['ticket_id' => $tid], [
            'email' => $email, 'portal_client_id' => $empresa['id'] ?? null, 'client_name' => $empresa['name'] ?? null,
            'portal_project_id' => $lugar['id'] ?? null, 'project_name' => $sitio,
            'items' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE),
            'contact_name' => $nombre, 'phone' => $tel ?: null, 'address' => $dir,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [true, null, $code];
    }

    /**
     * Una foto de un dispositivo de la solicitud: va como adjunto del primer mensaje del
     * ticket y queda apuntada en la solicitud, para pasarla sola a la garantía.
     */
    public function photo(string $email, string $ticketCode, string $code, string $type, ?UploadedFile $file): array
    {
        $r = DB::table('warranty_requests as r')->join('tickets as t', 't.id', '=', 'r.ticket_id')
            ->where('t.code', strtoupper(trim($ticketCode)))->where('r.email', mb_strtolower(trim($email)))
            ->first(['r.id', 'r.ticket_id', 'r.items', 'r.created_at']);
        if (! $r) return [false, 'No encontramos esa solicitud'];
        if (Carbon::parse($r->created_at)->lt(now()->subHours(self::PHOTO_HOURS))) return [false, 'Esta solicitud ya no admite fotos: mándalas en la conversación'];

        $items = json_decode((string) $r->items, true) ?: [];
        $k = collect($items)->search(fn ($i) => $i['code'] === strtoupper(trim($code)));
        if ($k === false) return [false, 'Ese código no está en la solicitud'];
        if (! in_array($type, self::PHOTOS[$items[$k]['kind']] ?? [], true)) return [false, 'Tipo de foto no válido'];
        if (! $file || ! $file->isValid()) return [false, 'La foto no ha llegado bien'];
        if (! WarrantyPhotos::mimeReal((string) file_get_contents($file->getRealPath()))) return [false, 'Eso no parece una foto'];

        $mid = DB::table('messages')->where('ticket_id', $r->ticket_id)->orderBy('id')->value('id');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $nombre = "{$items[$k]['code']}-{$type}.{$ext}";
        $bin = (string) file_get_contents($file->getRealPath());
        $aid = app(AttachmentService::class)->storeRaw($nombre, $bin, $file->getMimeType(), (int) $r->ticket_id, $mid ? (int) $mid : null);
        if (! $aid) return [false, 'No se pudo guardar la foto (formato o tamaño)'];

        $items[$k]['photos'][$type] = $aid;
        DB::table('warranty_requests')->where('id', $r->id)->update(['items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return [true, null];
    }

    /** Lo que dijo el cliente que le pasa a un dispositivo, en una línea. */
    public function textoFallo(array $i): string
    {
        return trim((self::ISSUES[$i['issue'] ?? 'otro'] ?? 'Otro') . (! empty($i['detail']) ? ' — ' . $i['detail'] : ''));
    }

    /* ---------------------------------------------------- Del lado del equipo */

    /** La solicitud de un ticket, para «Crear garantía desde el ticket»: o null. */
    public static function forTicket(int $ticketId): ?array
    {
        $r = DB::table('warranty_requests')->where('ticket_id', $ticketId)->first();
        if (! $r) return null;
        return [
            'portal_client_id' => $r->portal_client_id, 'client_name' => $r->client_name,
            'portal_project_id' => $r->portal_project_id, 'project_name' => $r->project_name,
            'customer_email' => $r->email, 'contact_name' => $r->contact_name, 'phone' => $r->phone, 'address' => $r->address,
            'items' => json_decode((string) $r->items, true) ?: [],
        ];
    }

    /**
     * Al añadir dispositivos a una garantía cuyo ticket vino del portal: a cada uno, lo que
     * dijo el cliente que le pasaba y sus fotos (frontal, trasera, sin pilas) ya en su sitio.
     */
    public static function applyToItems(Warranty $w, array $codes, int $userId): void
    {
        $req = $w->ticket_id ? self::forTicket((int) $w->ticket_id) : null;
        if (! $req || ! $codes) return;
        $porCodigo = collect($req['items'])->keyBy('code');
        $tickets = [$w->ticket_id => ''];
        foreach (WarrantyItem::where('warranty_id', $w->id)->whereIn('code', $codes)->get() as $item) {
            $i = $porCodigo[$item->code] ?? null;
            if (! $i) continue;
            if (! $item->reported_issue) $item->update(['reported_issue' => mb_substr(app(self::class)->textoFallo($i), 0, 300)]);
            $ya = DB::table('warranty_item_photos')->where('warranty_item_id', $item->id)->pluck('type')->all();
            foreach ((array) ($i['photos'] ?? []) as $type => $aid) {
                if (in_array($type, $ya, true) || ! array_key_exists($type, WarrantyItem::photoTypes($item->kind ?: 'esl'))) continue;
                WarrantyPhotos::fromTicket($tickets, $item, $type, 'a:' . (int) $aid, $userId);
            }
        }
    }
}
