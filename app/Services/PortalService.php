<?php

namespace App\Services;

use App\Models\EmailAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * PORTAL PÚBLICO — la lógica de la cara del cliente.
 *
 * Identidad por correo + código de un solo uso. Todo lo demás (crear tickets,
 * reglas, reparto por turno, SLA, avisos) es el MISMO motor que usa el correo:
 * aquí solo se comprueba quién es quien pide y se le sirve lo suyo.
 */
class PortalService
{
    /** Minutos que vive un código antes de caducar. */
    protected const CODE_TTL = 10;
    /** Cuántos códigos se pueden pedir por correo en una hora (antispam). */
    protected const MAX_POR_HORA = 5;
    /** Intentos de acertar un código antes de quemarlo. */
    protected const MAX_INTENTOS = 5;
    /**
     * Días que dura el «pase» tras acertar el código. Es una ventana DESLIZANTE:
     * cada vez que el cliente entra, se renueva (ver emailFromToken). Así, en un
     * dispositivo que se usa de vez en cuando, no se pide el código nunca más —solo
     * caduca si pasan 90 días SIN entrar, que es cuando conviene volver a confirmar.
     */
    protected const SESION_DIAS = 90;

    /* -------------------------------------------------------------------------
     * 1) PEDIR CÓDIGO
     * ---------------------------------------------------------------------- */
    public function requestCode(string $email, ?string $ip = null): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Ese correo no parece válido'];
        }

        // Antispam: no más de N por correo y hora. Es también lo que evita usar el
        // portal como cañón para bombardear el buzón de otra persona.
        $ultimaHora = DB::table('portal_codes')->where('email', $email)
            ->where('created_at', '>=', now()->subHour())->count();
        if ($ultimaHora >= self::MAX_POR_HORA) {
            return [false, 'Has pedido demasiados códigos. Prueba de nuevo dentro de un rato.'];
        }

        $acc = EmailAccount::where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc) return [false, 'El envío de correo no está configurado. Avisa a soporte.'];

        // Código de 6 dígitos. `random_int` es criptográfico: un código adivinable
        // haría inútil todo lo demás.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('portal_codes')->insert([
            'email'      => $email,
            'code_hash'  => hash('sha256', $code),
            'ip'         => $ip,
            'expires_at' => now()->addMinutes(self::CODE_TTL),
            'created_at' => now(),
        ]);

        /*
         * El correo se envía DESPUÉS de responder (`afterResponse`): hablar con el
         * SMTP tarda uno o dos segundos y no hay razón para que el usuario los
         * espere mirando un botón girando. La respuesta sale al instante y el correo
         * se manda con la petición ya cerrada.
         */
        dispatch(fn () => $this->enviarCodigo($acc, $email, $code))->afterResponse();

        return [true, null];
    }

    protected function enviarCodigo(EmailAccount $acc, string $email, string $code): void
    {
        $marca = $acc->from_name ?: 'Soporte';
        $html = <<<HTML
            <div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:440px;margin:0 auto;color:#0e1e33">
              <p style="font-size:15px;color:#46586e">Tu código para acceder al soporte de {$marca}:</p>
              <div style="font-size:34px;font-weight:700;letter-spacing:.18em;text-align:center;
                          background:#e8f0fe;color:#1a4fd0;padding:18px;border-radius:12px;margin:14px 0">{$code}</div>
              <p style="font-size:13px;color:#8496aa">Caduca en 10 minutos. Si no has sido tú, ignora este correo —
                 no se ha hecho ningún cambio.</p>
            </div>
        HTML;

        // MISMO envío que los avisos de tickets. Un fallo aquí no debe reventar la
        // petición con una traza: el usuario ve «no se pudo enviar» y reintenta.
        try {
            app(MailService::class)->sendMail($acc, $email, null, "Tu código de acceso: {$code}", $html);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* -------------------------------------------------------------------------
     * 2) VERIFICAR CÓDIGO  →  entrega el «pase»
     * ---------------------------------------------------------------------- */
    public function verifyCode(string $email, string $code, ?string $ip = null): array
    {
        $email = mb_strtolower(trim($email));
        $code  = preg_replace('/\D/', '', $code);

        $fila = DB::table('portal_codes')->where('email', $email)
            ->whereNull('used_at')->where('expires_at', '>=', now())
            ->orderByDesc('id')->first();

        if (!$fila) return [false, 'El código ha caducado. Pide uno nuevo.'];
        if ($fila->attempts >= self::MAX_INTENTOS) {
            return [false, 'Demasiados intentos. Pide un código nuevo.'];
        }

        if (!hash_equals($fila->code_hash, hash('sha256', $code))) {
            DB::table('portal_codes')->where('id', $fila->id)->increment('attempts');
            return [false, 'Código incorrecto. Revísalo e inténtalo otra vez.'];
        }

        // Acertado: se quema el código (un solo uso) y se emite el pase.
        DB::table('portal_codes')->where('id', $fila->id)->update(['used_at' => now()]);

        $token = Str::random(48);
        DB::table('portal_sessions')->insert([
            'token_hash'   => hash('sha256', $token),
            'email'        => $email,
            'ip'           => $ip,
            'expires_at'   => now()->addDays(self::SESION_DIAS),
            'last_used_at' => now(),
            'created_at'   => now(),
        ]);

        return [true, ['token' => $token, 'email' => $email]];
    }

    /**
     * Resuelve un pase a su correo, o null si no vale.
     *
     * Cada uso RENUEVA la caducidad (ventana deslizante): quien entra de vez en
     * cuando nunca vuelve a ver el código. El pase solo muere si se está 90 días
     * sin aparecer —ahí sí conviene volver a confirmar que sigue siendo su buzón—.
     */
    public function emailFromToken(?string $token): ?string
    {
        if (!$token) return null;
        $s = DB::table('portal_sessions')->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>=', now())->first(['id', 'email']);
        if (!$s) return null;
        DB::table('portal_sessions')->where('id', $s->id)->update([
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(self::SESION_DIAS),
        ]);
        return $s->email;
    }

    /* -------------------------------------------------------------------------
     * 2 bis) TOKEN DE UN SOLO TICKET
     *
     * Al crear una incidencia SIN código, se devuelve este token: abre ÚNICAMENTE
     * ese ticket (ver y responder), y caduca en unas horas. NO es un pase del correo
     * —no deja ver «mis incidencias» ni entrar en otro ticket—, así que ponerse el
     * correo de otro al crear no sirve para cotillear sus tickets: solo abre el que
     * acabas de crear. Es un token FIRMADO (sin tabla): lleva correo + código + caduca.
     * ---------------------------------------------------------------------- */
    protected const TICKET_TOKEN_HORAS = 24;

    public function makeTicketToken(string $email, string $code): string
    {
        $body = $this->b64url(json_encode([
            'e' => mb_strtolower($email),
            'c' => $code,
            'x' => now()->addHours(self::TICKET_TOKEN_HORAS)->timestamp,
        ]));
        return $body . '.' . $this->firmaTicket($body);
    }

    /** Devuelve el correo si el token es válido y es EXACTAMENTE para ese código. */
    public function emailFromTicketToken(?string $token, string $code): ?string
    {
        if (!$token || !str_contains($token, '.')) return null;
        [$body, $sig] = explode('.', $token, 2);
        if (!hash_equals($this->firmaTicket($body), $sig)) return null;   // firma manipulada
        $p = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (!is_array($p) || ($p['c'] ?? null) !== $code) return null;    // no es para este ticket
        if ((int) ($p['x'] ?? 0) < now()->timestamp) return null;         // caducado
        return $p['e'] ?? null;
    }

    protected function firmaTicket(string $body): string
    {
        return $this->b64url(hash_hmac('sha256', $body, (string) config('app.key'), true));
    }

    protected function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /* -------------------------------------------------------------------------
     * 3) MIS TICKETS
     * ---------------------------------------------------------------------- */
    public function myTickets(string $email): array
    {
        $rows = DB::table('tickets as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereRaw('LOWER(c.email) = ?', [mb_strtolower($email)])
            ->where('t.channel', '!=', 'cron')
            ->whereNull('t.merged_into_id')       // los fusionados viven en su destino
            ->orderByDesc(DB::raw('COALESCE(t.last_message_at, t.created_at)'))
            ->limit(100)
            ->get(['t.id', 't.code', 't.subject', 't.status', 't.created_at', 't.last_message_at', 't.last_direction']);

        // Un fragmento del último mensaje visible de cada ticket, para la tarjeta.
        // Una sola consulta para todos (nada de N+1): el último id por ticket.
        $ids = $rows->pluck('id')->all();
        $previews = [];
        if ($ids) {
            $ultimos = DB::table('messages')->whereIn('ticket_id', $ids)
                ->where('is_internal_note', 0)
                ->select('ticket_id', 'body', 'is_html', 'created_at')
                ->orderBy('ticket_id')->orderByDesc('id')->get()
                ->unique('ticket_id');   // el primero de cada ticket = el más reciente
            foreach ($ultimos as $m) {
                $txt = trim(preg_replace('/\s+/', ' ', strip_tags((string) $m->body)));
                $previews[$m->ticket_id] = mb_substr($txt, 0, 100);
            }
        }

        return $rows->map(fn ($t) => [
            'code'     => $t->code,
            'subject'  => $t->subject,
            'status'   => $t->status,
            'estado'   => TicketService::STATUSES[$t->status] ?? $t->status,
            'fase'     => $this->fase($t->status),
            'fecha'    => Carbon::parse($t->last_message_at ?: $t->created_at)->toIso8601String(),
            'preview'  => $previews[$t->id] ?? '',
            // Quién habló el último: para decir «Soporte te respondió» vs «Enviado,
            // esperando respuesta» sin que el cliente tenga que abrir el ticket.
            'ultimo'   => in_array($t->status, ['resuelto', 'cerrado'], true) ? 'cerrado'
                        : ($t->last_direction === 'out' ? 'soporte' : 'cliente'),
        ])->all();
    }

    /* -------------------------------------------------------------------------
     * 4) DETALLE de un ticket (solo si es SUYO)
     * ---------------------------------------------------------------------- */
    public function ticketDetail(string $email, string $code): ?array
    {
        $t = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.code', $code)
            ->whereRaw('LOWER(c.email) = ?', [mb_strtolower($email)])
            ->first(['t.id', 't.code', 't.subject', 't.status', 't.created_at', 't.resolved_at']);
        if (!$t) return null;   // no existe, o no es de este correo

        // Solo mensajes visibles para el cliente: NADA de notas internas.
        $msgs = DB::table('messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.author_user_id')
            ->where('m.ticket_id', $t->id)->where('m.is_internal_note', 0)
            ->orderBy('m.id')
            ->get(['m.id', 'm.direction', 'm.body', 'm.is_html', 'm.created_at', 'u.name as agent_name']);

        // Adjuntos por mensaje. Los `inline` NO van en la tira: ya están dentro del
        // cuerpo del correo (imágenes de firma), así que se saltan. La URL va FIRMADA:
        // como con las imágenes en línea, la firma es la autorización y así el <img>
        // o el enlace descargan sin cabeceras. Solo se firman los de ESTE ticket,
        // que ya se ha comprobado que es del correo del pase.
        $porMensaje = app(AttachmentService::class)->forTicket((int) $t->id);

        return [
            'code'    => $t->code,
            'subject' => $t->subject,
            'status'  => $t->status,
            'estado'  => TicketService::STATUSES[$t->status] ?? $t->status,
            'fase'    => $this->fase($t->status),
            'fecha'   => Carbon::parse($t->created_at)->toIso8601String(),
            'resuelto_en' => $t->resolved_at ? Carbon::parse($t->resolved_at)->toIso8601String() : null,
            // Hitos de estado que le importan al cliente, para intercalarlos en el
            // hilo: cuándo se puso en marcha, cuándo se resolvió, si se reabrió…
            'hitos'   => $this->hitosCliente((int) $t->id),
            'mensajes' => $msgs->map(fn ($m) => [
                'de'      => $m->direction === 'in' ? 'cliente' : 'soporte',
                'autor'   => $m->direction === 'in' ? null : ($m->agent_name ?: 'Soporte'),
                'html'    => (bool) $m->is_html,
                'cuerpo'  => (string) $m->body,
                'fecha'   => Carbon::parse($m->created_at)->toIso8601String(),
                'adjuntos' => collect($porMensaje[$m->id] ?? [])
                    ->reject(fn ($a) => $a->inline)
                    ->map(fn ($a) => [
                        'name'  => $a->name,
                        'size'  => (int) $a->size,
                        'image' => (bool) $a->is_image,
                        'url'   => URL::signedRoute('portal.file', ['id' => $a->id], now()->addHours(6), false),
                    ])->values()->all(),
            ])->all(),
        ];
    }

    /**
     * HITOS que ve el cliente. De todo el historial interno (`ticket_events`) solo
     * salen los cambios de ESTADO, y traducidos a su idioma: nada de «asignado a
     * Pedro», ni categorías, ni prioridades —eso es cocina de dentro—.
     */
    protected function hitosCliente(int $ticketId): array
    {
        $eventos = DB::table('ticket_events')->where('ticket_id', $ticketId)
            ->where('type', 'status')->orderBy('id')
            ->get(['from_value', 'to_value', 'created_at']);

        $hitos = [];
        foreach ($eventos as $e) {
            $reabre = in_array($e->from_value, ['resuelto', 'cerrado'], true);
            [$label, $fase] = match ($e->to_value) {
                'en_progreso'          => $reabre ? ['Incidencia reabierta', 'en_proceso'] : ['Nuestro equipo se ha puesto con ella', 'en_proceso'],
                'abierto'              => $reabre ? ['Incidencia reabierta', 'en_proceso'] : [null, null],
                'esperando_respuesta'  => ['A la espera de tu respuesta', 'en_proceso'],
                'resuelto'             => ['Marcada como resuelta', 'resuelto'],
                'cerrado'              => ['Incidencia cerrada', 'resuelto'],
                default                => [null, null],
            };
            if ($label) {
                $hitos[] = ['label' => $label, 'fase' => $fase, 'fecha' => Carbon::parse($e->created_at)->toIso8601String()];
            }
        }
        return $hitos;
    }

    /* -------------------------------------------------------------------------
     * 5) CREAR ticket desde el portal
     * ---------------------------------------------------------------------- */
    public function createTicket(string $email, array $data, array $files = []): array
    {
        $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 200);
        $cuerpo  = trim((string) ($data['body'] ?? ''));
        if ($subject === '') return [false, 'Ponle un asunto', null];
        if (mb_strlen($cuerpo) < 5) return [false, 'Cuéntanos un poco más qué ocurre', null];

        $contactId = ChatService::upsertContactByEmail($email, $data['name'] ?? null);

        // Categoría: solo se acepta si existe y está activa; si no, sin categoría.
        $catId = null;
        if (!empty($data['category_id'])) {
            $catId = DB::table('ticket_categories')->where('id', (int) $data['category_id'])
                ->where('active', 1)->value('id');
        }

        // MISMO create() que el correo: reglas, reparto por turno, SLA y acuse de
        // recibo salen gratis. El canal es 'email' para que el hilo se comporte como
        // un correo (el cliente puede seguir por el portal o respondiendo al aviso).
        $ticketId = app(TicketService::class)->create([
            'contact_id'  => $contactId,
            'channel'     => 'email',
            'subject'     => $subject,
            'category_id' => $catId,
            'body'        => $cuerpo,
            'email'       => $email,
        ]);

        // El mensaje inicial del cliente, como entrante del hilo.
        $mid = ChatService::storeMessage($contactId, '', 'in', 'text', nl2br(e($cuerpo)), [
            'ticket_id' => $ticketId,
            'channel'   => 'email',
            'is_html'   => true,
        ]);
        $this->guardarAdjuntos($files, $ticketId, $mid);

        $code = DB::table('tickets')->where('id', $ticketId)->value('code');
        return [true, null, $code];
    }

    /* -------------------------------------------------------------------------
     * 6) RESPONDER a un ticket propio
     * ---------------------------------------------------------------------- */
    public function reply(string $email, string $code, string $body, array $files = []): array
    {
        $cuerpo = trim($body);
        // Con adjuntos, el texto puede ir vacío («aquí va la captura»).
        if (mb_strlen($cuerpo) < 1 && !$files) return [false, 'Escribe tu respuesta o adjunta un archivo'];

        $t = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.code', $code)
            ->whereRaw('LOWER(c.email) = ?', [mb_strtolower($email)])
            ->first(['t.id', 't.contact_id', 't.status']);
        if (!$t) return [false, 'No encontramos esa incidencia'];

        $mid = ChatService::storeMessage((int) $t->contact_id, '', 'in', 'text',
            $cuerpo !== '' ? nl2br(e($cuerpo)) : '<i>(archivo adjunto)</i>', [
                'ticket_id' => $t->id,
                'channel'   => 'email',
                'is_html'   => true,
            ]);
        $this->guardarAdjuntos($files, (int) $t->id, $mid);

        $svc = app(TicketService::class);
        $svc->touch((int) $t->id);
        DB::table('tickets')->where('id', $t->id)->update(['last_direction' => 'in']);
        // Un ticket resuelto que el cliente reabre con una respuesta vuelve a la cola.
        if (in_array($t->status, ['resuelto', 'cerrado'], true)) {
            $svc->setStatus((int) $t->id, 'en_progreso');
        }
        $svc->broadcast('message', (int) $t->id);

        return [true, null];
    }

    /**
     * Cuelga los archivos subidos de un mensaje. Reusa la MISMA tubería que los
     * agentes (`AttachmentService::store`): misma lista blanca de tipos, mismo tope
     * de 10 MB y mismo guardado fuera de public/. Sin autor (lo sube el cliente).
     */
    protected function guardarAdjuntos(array $files, int $ticketId, int $messageId): void
    {
        $files = array_filter($files);
        if ($files) app(AttachmentService::class)->store($files, $ticketId, $messageId, null);
    }

    /** Sirve un adjunto propio (por URL firmada). Solo imágenes van «en línea». */
    public function serveFile(int $id): mixed
    {
        return app(AttachmentService::class)->find($id);   // [ruta, fila] o null
    }

    /**
     * El CLIENTE marca su propia incidencia como resuelta. Solo la suya, y solo si
     * estaba abierta. Reabrirla es tan fácil como responder (ver reply()).
     */
    public function resolve(string $email, string $code): array
    {
        $t = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.code', $code)
            ->whereRaw('LOWER(c.email) = ?', [mb_strtolower($email)])
            ->first(['t.id', 't.status']);
        if (!$t) return [false, 'No encontramos esa incidencia'];
        if (in_array($t->status, ['resuelto', 'cerrado'], true)) return [true, null];   // ya lo estaba

        app(TicketService::class)->setStatus((int) $t->id, 'resuelto');
        return [true, null];
    }

    /**
     * ESTADO por número (público, solo lectura). Se puede consultar sabiendo solo el
     * código —sin correo ni pase—, por eso NO devuelve nada sensible: ni asunto, ni
     * mensajes, ni el correo. Solo la fase (recibida/en proceso/resuelta) y las
     * fechas. Aunque alguien pruebe números al azar, no filtra información del cliente.
     */
    public function statusByCode(string $code): ?array
    {
        $code = mb_strtoupper(trim($code));
        if ($code === '') return null;

        $t = DB::table('tickets')->where('code', $code)
            ->where('channel', '!=', 'cron')->whereNull('merged_into_id')
            ->first(['code', 'status', 'created_at', 'last_message_at', 'resolved_at']);
        if (!$t) return null;

        return [
            'code'        => $t->code,
            'fase'        => $this->fase($t->status),
            'created'     => Carbon::parse($t->created_at)->toIso8601String(),
            'updated'     => Carbon::parse($t->last_message_at ?: $t->created_at)->toIso8601String(),
            'resuelto_en' => $t->resolved_at ? Carbon::parse($t->resolved_at)->toIso8601String() : null,
        ];
    }

    /** Categorías que se ofrecen al cliente en el formulario. */
    public function categories(): array
    {
        return DB::table('ticket_categories')->where('active', 1)->orderBy('position')
            ->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
    }

    /**
     * FAQ publicadas para el portal. Solo las activas y en su orden. Las palabras
     * clave se devuelven como lista para que el buscador del cliente las cruce.
     */
    public function faqs(): array
    {
        return DB::table('faqs')->where('section', 'faq')->where('active', 1)
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'question', 'answer', 'hint', 'keywords', 'category_id'])
            ->map(fn ($f) => [
                'id'          => (int) $f->id,
                'question'    => $f->question,
                'answer'      => $f->answer,
                'hint'        => $f->hint ?: '',
                'keywords'    => $f->keywords
                    ? array_values(array_filter(array_map('trim', explode(',', $f->keywords))))
                    : [],
                'category_id' => $f->category_id ? (int) $f->category_id : null,
            ])->all();
    }

    /**
     * Centro de atención: fichas de info de la empresa (horario, correos, teléfonos).
     * Solo título + contenido; el cliente las lee, no las vota.
     */
    public function info(): array
    {
        return DB::table('faqs')->where('section', 'info')->where('active', 1)
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'question', 'answer'])
            ->map(fn ($f) => ['id' => (int) $f->id, 'title' => $f->question, 'body' => $f->answer])->all();
    }

    /** Suma una vista (analítica). Silencioso: no debe romper la carga del portal. */
    public function faqView(int $id): void
    {
        if ($id > 0) DB::table('faqs')->where('id', $id)->increment('views');
    }

    /** Registra un voto de utilidad 👍/👎. */
    public function faqVote(int $id, bool $helpful): void
    {
        if ($id > 0) DB::table('faqs')->where('id', $id)->increment($helpful ? 'helpful_yes' : 'helpful_no');
    }

    /** Tres fases visibles para el cliente (no los 6 estados internos). */
    protected function fase(string $status): string
    {
        return match ($status) {
            'nuevo', 'abierto'                 => 'recibido',
            'resuelto', 'cerrado'              => 'resuelto',
            default                            => 'en_proceso',   // en_progreso, esperando_respuesta
        };
    }
}
