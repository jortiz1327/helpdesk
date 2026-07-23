<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\EmailBan;
use App\Models\Message;
use App\Models\Setting;
use App\Services\CronAlertService;
use App\Services\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;
use Webklex\PHPIMAP\ClientManager;

/**
 * Canal CORREO · entrada (Paso 3).
 *
 * Sondea los buzones IMAP activos y convierte cada correo NUEVO en un ticket:
 *   · Contacto: se busca/crea por la dirección del remitente (From).
 *   · Ticket:   si el asunto trae el código [TK-AAMM-NNNN] se añade a ese ticket;
 *               si no, se abre uno nuevo con el asunto del correo.
 *   · Mensaje:  entrante (direction=in, channel=email). De momento se guarda el
 *               cuerpo como TEXTO (seguro); el HTML enriquecido se pulirá aparte.
 *   · Adjuntos: se guardan con la misma lista blanca que el resto del helpdesk.
 *
 * Dedup: el Message-ID del correo se guarda en messages.wamid; si ya existe, se
 * ignora (evita duplicar si el buzón devuelve el mismo correo dos veces).
 */
class MailService
{
    /** Máximo de correos a procesar por buzón y pasada (evita atascos). */
    public const MAX_PER_RUN = 50;

    public function __construct(
        protected TicketService $tickets,
        protected AttachmentService $attachments,
    ) {}

    /**
     * Sondea TODOS los buzones activos.
     * Devuelve ['tickets_nuevos'=>int, 'mensajes'=>int, 'adjuntos'=>int, 'errores'=>string[]].
     */
    public function fetchAll(): array
    {
        $totals = ['tickets_nuevos' => 0, 'mensajes' => 0, 'adjuntos' => 0, 'errores' => []];

        foreach (EmailAccount::where('active', true)->orderBy('id')->get() as $acc) {
            if (!$acc->imap_host || !$acc->imap_user) continue;   // sin IMAP configurado
            try {
                $r = $this->fetchAccount($acc);
                $totals['tickets_nuevos'] += $r['tickets_nuevos'];
                $totals['mensajes']       += $r['mensajes'];
                $totals['adjuntos']       += $r['adjuntos'];
                $totals['errores']         = array_merge($totals['errores'], $r['errores']);
            } catch (\Throwable $e) {
                $totals['errores'][] = "[{$acc->email}] {$e->getMessage()}";
                Log::warning('MailService: fallo al sondear buzón', ['email' => $acc->email, 'error' => $e->getMessage()]);
            }
        }

        return $totals;
    }

    /** Sondea un buzón concreto (incremental por UID de IMAP). */
    public function fetchAccount(EmailAccount $acc): array
    {
        $r = ['tickets_nuevos' => 0, 'mensajes' => 0, 'adjuntos' => 0, 'errores' => []];

        $client = (new ClientManager())->make([
            'host'          => $acc->imap_host,
            'port'          => (int) $acc->imap_port,
            'encryption'    => $acc->imap_encryption === 'none' ? false : $acc->imap_encryption, // 'ssl'|'tls'|false
            'validate_cert' => false,
            'username'      => $acc->imap_user,
            'password'      => (string) $acc->imap_password,
            'protocol'      => 'imap',
            'timeout'       => 20,
        ]);
        $client->connect();

        $inbox   = $client->getFolder('INBOX');
        $lastUid = (int) ($acc->last_uid ?? 0);

        // PRIMER ARRANQUE (sin baseline): NO importamos el histórico del buzón.
        // Solo anotamos el UID más alto actual; a partir de ahí se procesan los nuevos.
        if ($lastUid <= 0) {
            $maxUid = 0;
            foreach ($inbox->query()->whereUid('1:*')->leaveUnread()->setFetchBody(false)->get() as $m) {
                $maxUid = max($maxUid, (int) $m->getUid());
            }
            $client->disconnect();
            DB::table('email_accounts')->where('id', $acc->id)->update(['last_uid' => $maxUid, 'last_check_at' => now()]);
            Log::info('MailService: baseline fijado', ['email' => $acc->email, 'last_uid' => $maxUid]);
            return $r;
        }

        // INCREMENTAL: solo UID > lastUid. Ojo con IMAP: «UID N:*» siempre devuelve
        // al menos el de UID máximo aunque no haya ninguno mayor que N, por eso
        // filtramos <= lastUid en PHP. leaveUnread(): no tocamos el flag leído.
        $messages = $inbox->query()
            ->whereUid(($lastUid + 1) . ':*')
            ->leaveUnread()
            ->setFetchOrder('asc')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $maxSeen = $lastUid;
        foreach ($messages as $message) {
            $uid = (int) $message->getUid();
            if ($uid <= $lastUid) continue;

            try {
                $res = $this->handleMessage($acc, $message);
                $r['tickets_nuevos'] += $res['ticket_nuevo'] ? 1 : 0;
                $r['mensajes']       += $res['mensaje'] ? 1 : 0;
                $r['adjuntos']       += $res['adjuntos'];
                $maxSeen = $uid;   // avanza solo tras éxito
            } catch (\Throwable $e) {
                $r['errores'][] = "[{$acc->email}] " . $e->getMessage();
                Log::warning('MailService: fallo al procesar correo', ['email' => $acc->email, 'uid' => $uid, 'error' => $e->getMessage()]);
                // Paramos en el primer fallo: así no nos saltamos este correo (se
                // reintenta en la próxima pasada, sin perder los ya procesados).
                break;
            }
        }

        $client->disconnect();

        DB::table('email_accounts')->where('id', $acc->id)->update(['last_uid' => $maxSeen, 'last_check_at' => now()]);

        return $r;
    }

    /**
     * Procesa un correo: contacto → ticket → mensaje → adjuntos.
     * Devuelve ['ticket_nuevo'=>bool, 'mensaje'=>bool, 'adjuntos'=>int].
     */
    protected function handleMessage(EmailAccount $acc, $message): array
    {
        // --- Remitente ---
        // getFrom() devuelve un Webklex\...\Attribute (ArrayAccess), NO un array nativo,
        // así que se accede por índice directamente (sirve para ambos).
        $from  = $message->getFrom();
        $addr  = $from[0] ?? null;
        $email = $addr->mail ?? null;
        $name  = trim((string) ($addr->personal ?? '')) ?: null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ticket_nuevo' => false, 'mensaje' => false, 'adjuntos' => 0];   // sin remitente válido, se descarta
        }

        // BANLIST: remitente (o su dominio) bloqueado → se descarta sin crear ticket.
        // Corta spam y bucles de MAILER-DAEMON (rebotes). Se marcará leído en el caller.
        if (EmailBan::isBanned($email)) {
            Log::info('MailService: correo descartado por banlist', ['from' => $email]);
            return ['ticket_nuevo' => false, 'mensaje' => false, 'adjuntos' => 0];
        }

        // --- Dedup por Message-ID (guardado en wamid) ---
        $msgId = mb_substr(trim((string) $message->getMessageId()), 0, 128) ?: null;
        if ($msgId && Message::where('wamid', $msgId)->exists()) {
            return ['ticket_nuevo' => false, 'mensaje' => false, 'adjuntos' => 0];   // ya importado
        }

        $subject = trim((string) $message->getSubject());

        // Fecha REAL del correo (cabecera Date:), no la del sondeo. Así el hilo
        // muestra cuándo lo envió el cliente. getDate()->toDate() da el instante
        // correcto (con su offset); se convierte a la zona de la app (Europe/Madrid)
        // para que cuadre con el resto de mensajes. Si falla, se usa la hora actual.
        $sentAt = now();
        try {
            $d = $message->getDate();
            $carbon = ($d && method_exists($d, 'toDate')) ? $d->toDate() : null;
            if ($carbon) $sentAt = \Illuminate\Support\Carbon::instance($carbon)->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Throwable $e) {
            $sentAt = now();
        }

        /*
         * Cuerpo HTML ya limpio de la CITA (la respuesta anterior arrastrada). Se
         * calcula AQUÍ, antes del ticket, porque las reglas automáticas necesitan el
         * texto para decidir; dejarlo más abajo lo dejaba sin definir.
         */
        $rawHtml = self::stripQuoted(trim((string) $message->getHTMLBody()), $subject);

        // --- Contacto ---
        $contactId = ChatService::upsertContactByEmail($email, $name);

        /*
         * AVISO DE CRON: no es un ticket de cliente, nadie contesta a un cron. Se
         * desvía a su propio apartado, donde se AGRUPA por cron en vez de crear un
         * ticket por correo. Va antes de todo lo demás para saltarse de una vez las
         * reglas, el reparto por turno y el acuse de recibo (que contestaría a un
         * `noreply@`). Si no se puede analizar, sigue como correo normal.
         */
        $cron  = app(CronAlertService::class);
        $texto = HtmlSanitizer::toText($rawHtml) ?: trim((string) $message->getTextBody());
        if ($cron->esAviso($subject, $texto, $email)) {
            if ($datos = $cron->parse($texto)) {
                $cuerpo = $rawHtml !== '' ? HtmlSanitizer::cleanEmail($rawHtml) : e($texto);
                [$tid, $nuevo] = $cron->registrar($datos, $contactId, $cuerpo, $datos['executed_at'] ?? $sentAt);

                // El Message-ID se guarda igual, para no reimportar el mismo correo.
                if ($msgId) {
                    DB::table('messages')->where('ticket_id', $tid)->orderByDesc('id')->limit(1)
                        ->update(['wamid' => $msgId]);
                }
                return ['ticket_nuevo' => $nuevo, 'mensaje' => true, 'adjuntos' => 0];
            }
        }

        // --- Ticket: threading por código en el asunto, si no, nuevo ---
        $ticketId  = $this->ticketByCode($subject);
        $ticketNew = false;
        if ($ticketId) {
            $this->tickets->touch($ticketId);
        } else {
            $ticketId  = $this->tickets->create([
                'contact_id' => $contactId,
                'channel'    => 'email',
                'subject'    => $subject !== '' ? $subject : 'Sin asunto',
                // Contexto para las reglas automáticas (asignar/categorizar solo).
                'body'       => HtmlSanitizer::toText($rawHtml) ?: trim((string) $message->getTextBody()),
                'email'      => $email,
            ]);
            $ticketNew = true;
        }

        // --- Adjuntos PRIMERO: los necesitamos para resolver las imágenes «cid:» ---
        // Se guardan sin message_id (aún no existe el mensaje); se les cuelga al final.
        $count    = 0;
        $savedIds = [];
        $cidMap   = [];   // content-id normalizado => id de adjunto (para reescribir el cuerpo)
        foreach ($message->getAttachments() as $att) {
            $contentId = (string) $att->getId();
            $saved = $this->attachments->storeRaw(
                (string) ($att->getName() ?: 'adjunto'),
                (string) $att->getContent(),
                $att->getMimeType(),
                $ticketId,
                null,                       // message_id: se rellena al final
                null,                       // uploaded_by
                $contentId ?: null,
                false,                      // inline: se marca tras reescribir el cuerpo
            );
            if ($saved) {
                $count++;
                $savedIds[] = $saved;
                $cid = self::normalizeCid($contentId);
                if ($cid !== '' && str_starts_with((string) $att->getMimeType(), 'image/')) {
                    $cidMap[$cid] = $saved;
                }
            }
        }

        // --- Reescribir «cid:» del HTML a nuestra ruta firmada; marcar esas img como inline ---
        $usedInline = [];
        if ($rawHtml !== '' && $cidMap) {
            $rawHtml = preg_replace_callback('/cid:([^"\'\s>]+)/i', function ($m) use ($cidMap, &$usedInline) {
                $key = self::normalizeCid($m[1]);
                if (isset($cidMap[$key])) {
                    $usedInline[$cidMap[$key]] = true;
                    return URL::signedRoute('attachment.inline', ['id' => $cidMap[$key]], null, false);
                }
                return $m[0];   // «cid:» sin adjunto que casar: el saneador lo eliminará
            }, $rawHtml);
        }
        if ($usedInline) {
            DB::table('attachments')->whereIn('id', array_keys($usedInline))->update(['inline' => true]);
        }

        // --- Cuerpo: preferimos el HTML (se ve como en el cliente de correo) ---
        $isHtml = false;
        $body   = '';
        if ($rawHtml !== '') {
            $body   = HtmlSanitizer::cleanEmail($rawHtml);
            $isHtml = $body !== '' && trim(strip_tags($body)) !== '';
        }
        if (!$isHtml) {
            // Sin HTML útil: texto plano (o el HTML reducido a texto).
            $body = self::stripQuotedText(trim((string) $message->getTextBody()), $subject);
            if ($body === '' && $rawHtml !== '') {
                $body = trim(html_entity_decode(strip_tags($rawHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        if ($body === '' && $subject !== '') $body = $subject;

        // Resumen de bandeja SIEMPRE en texto (aunque el cuerpo sea HTML).
        $preview = $isHtml ? HtmlSanitizer::toText($body) : $body;

        /*
         * COPIAS: quien venía en Cc forma parte de la conversación y debe seguir
         * en ella cuando respondamos. Se guarda tal cual llegó (sin filtrar), para
         * que el hilo sea fiel; ya se depura al componer la respuesta.
         * El Cco de un correo entrante no existe para nosotros: el servidor no lo
         * manda, que es justamente para lo que sirve.
         */
        $cc = self::direcciones($message->getCc());

        $messageId = ChatService::storeMessage($contactId, '', 'in', 'text', $body, [
            'ticket_id'  => $ticketId,
            'channel'    => 'email',
            'is_html'    => $isHtml,
            'preview'    => $preview,
            'cc'         => $cc ? implode(', ', $cc) : null,
            'wamid'      => $msgId,
            'status'     => 'received',
            'created_at' => $sentAt,   // fecha real del correo (cabecera Date:)
        ]);

        // Ahora que existe el mensaje, se le cuelgan los adjuntos.
        if ($savedIds) {
            DB::table('attachments')->whereIn('id', $savedIds)->update(['message_id' => $messageId]);
        }

        return ['ticket_nuevo' => $ticketNew, 'mensaje' => true, 'adjuntos' => $count];
    }

    /**
     * Añade el PIE configurable al final del correo, separado por una línea.
     * Si está desactivado o vacío, devuelve el cuerpo tal cual.
     */
    protected function conPie(string $html): string
    {
        if ((string) Setting::get('email_footer_active', '0') !== '1') return $html;

        $pie = trim((string) Setting::get('email_footer', ''));
        if ($pie === '') return $html;

        return $html . '<br><div style="margin-top:18px;padding-top:12px;border-top:1px solid #e2e6ea;'
             . 'font-size:12.5px;color:#666">' . $pie . '</div>';
    }

    /** Normaliza un content-id para casar «cid:» del HTML con el id del adjunto. */
    protected static function normalizeCid(string $cid): string
    {
        $cid = preg_replace('/^cid:/i', '', trim($cid));
        return strtolower(trim($cid, "<> \t\r\n"));
    }

    /**
     * Recorta el mensaje CITADO de una respuesta (el correo anterior que el cliente
     * arrastra al contestar). Corta en el primer marcador de cita conocido —divisor
     * y cabecera «De:/Enviado:» de Outlook, gmail_quote, «Mensaje original», «El … escribió:»—
     * conservando SOLO lo que el cliente escribió de nuevo (y su firma). Conservador:
     * si no reconoce ningún marcador, no toca nada.
     */
    protected static function stripQuoted(string $html, string $subject = ''): string
    {
        if (trim($html) === '') return $html;

        // En un REENVÍO la cita ES el mensaje: recortarla deja el ticket en blanco.
        if (self::esReenvio($subject)) return $html;

        $markers = [
            '/<div[^>]*id=["\']?(?:divRplyFwdMsg|appendonsend|appendonreply)["\']?/i',
            '/<div[^>]*class=["\'][^"\']*gmail_quote/i',
            '/<b>\s*(?:De|From)\s*:\s*<\/b>/i',
            '/-{3,}\s*(?:Original Message|Mensaje original)\s*-{3,}/i',
            '/El\s.{0,90}?escribió\s*:/iu',
            '/On\s.{0,140}?wrote\s*:/i',
        ];

        $cut = null;
        foreach ($markers as $re) {
            if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if ($cut === null || $pos < $cut) $cut = $pos;
            }
        }
        if ($cut === null) return $html;

        $head = substr($html, 0, $cut);
        // Si la cita viene tras un <hr> (divisor típico de Outlook), cortar desde ahí.
        $hr = strripos($head, '<hr');
        if ($hr !== false && $hr >= strlen($head) - 120) {
            $head = substr($head, 0, $hr);
        }
        $head = rtrim($head);

        // RED DE SEGURIDAD: si al recortar no queda mensaje, es que el marcador no
        // separaba una cita sino el contenido. Más vale un ticket con cita de más
        // que un ticket vacío.
        return self::quedaMensaje($head) ? $head : $html;
    }

    /**
     * Saca las direcciones de una cabecera tipo Cc/To, en minúsculas y sin repetir.
     *
     * OJO: Webklex devuelve un `Attribute`, que implementa ArrayAccess pero NO es
     * iterable — un `foreach` directo sobre él no recorre las direcciones y devuelve
     * vacío en silencio. Hay que pedirle `all()`. (Mismo tropiezo que con getFrom().)
     */
    protected static function direcciones($attr): array
    {
        if ($attr === null) return [];
        $lista = is_object($attr) && method_exists($attr, 'all') ? $attr->all() : (array) $attr;

        $out = [];
        foreach ($lista as $a) {
            $mail = mb_strtolower(trim((string) (is_object($a) ? ($a->mail ?? '') : $a)));
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) $out[] = $mail;
        }
        return array_values(array_unique($out));
    }

    /** ¿El asunto dice que es un reenvío? («RV:», «FW:», «Fwd:»…) */
    protected static function esReenvio(string $subject): bool
    {
        return (bool) preg_match('/^\s*(?:RV|RE?F|FWD?|Reenv)\w*\s*:/iu', $subject);
    }

    /**
     * ¿Lo que queda tras recortar tiene mensaje de verdad? Se mira el TEXTO visible,
     * porque un resto de `<style>` o una firma suelta ocupan mucho y no dicen nada.
     */
    protected static function quedaMensaje(string $html): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', HtmlSanitizer::toText($html)) ?? '');
        return mb_strlen($t) >= 40;
    }

    /** Versión en TEXTO plano del recorte de cita (para el cuerpo de respaldo). */
    protected static function stripQuotedText(string $text, string $subject = ''): string
    {
        if (trim($text) === '') return $text;

        if (self::esReenvio($subject)) return $text;   // igual que en HTML: la cita es el mensaje

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $ln) {
            $t = trim($ln);
            if (preg_match('/^-{3,}\s*(?:Original Message|Mensaje original)/i', $t)) break;
            if (preg_match('/^_{5,}\s*$/', $t)) break;                  // divisor Outlook
            if (preg_match('/^(?:De|From)\s*:\s.+@/i', $t)) break;      // cabecera «De: … @»
            if (preg_match('/^El\s.+escribió\s*:\s*$/iu', $t)) break;
            if (preg_match('/^On\s.+wrote\s*:\s*$/i', $t)) break;
            $out[] = $ln;
        }
        $head = rtrim(implode("\n", $out));

        // Misma red de seguridad que en HTML: vale más de sobra que en blanco.
        return mb_strlen(trim(preg_replace('/\s+/u', ' ', $head) ?? '')) >= 40 ? $head : $text;
    }

    /**
     * Canal CORREO · SALIDA (Paso 2). Envía un correo por SMTP con la cuenta dada
     * y devuelve el Message-ID generado (para registro/hilo). Lanza excepción si falla.
     *
     * @param array   $attachments cada uno ['path'=>ruta absoluta, 'name'=>?, 'mime'=>?]
     * @param ?string $inReplyTo   Message-ID (sin <>) del mensaje al que se responde
     * @param array   $references  cadena de Message-IDs previos (sin <>) para el hilo
     * @param array   $cc          copias visibles
     * @param array   $bcc         copias ocultas
     */
    public function sendMail(EmailAccount $acc, string $toEmail, ?string $toName, string $subject, string $html, array $attachments = [], ?string $inReplyTo = null, array $references = [], array $cc = [], array $bcc = []): string
    {
        $enc = $acc->smtp_encryption ?: 'ssl';
        // tls=true => TLS implícito (SSL, 465); null => STARTTLS/auto (587); false => sin cifrar.
        $tls = $enc === 'ssl' ? true : ($enc === 'none' ? false : null);

        $transport = new EsmtpTransport((string) $acc->smtp_host, (int) $acc->smtp_port, $tls);
        if ($acc->smtp_user) {
            $transport->setUsername((string) $acc->smtp_user);
            $transport->setPassword((string) $acc->smtp_password);
        }

        // PIE de empresa: se añade solo AL ENVIAR, no se guarda en el hilo del ticket.
        // Así la conversación interna queda limpia (sin la firma repetida en cada mensaje).
        $html = $this->conPie($html);

        $email = (new MimeEmail())
            ->from(new Address((string) $acc->email, (string) ($acc->from_name ?: $acc->email)))
            ->to(new Address($toEmail, (string) ($toName ?? '')))
            ->subject($subject)
            ->html($html)
            ->text(HtmlSanitizer::toText($html));   // alternativa en texto plano

        // COPIAS, depuradas en el último momento (ver copiasLimpias).
        foreach ($this->copiasLimpias($cc, $toEmail, $acc) as $d)  $email->addCc(new Address($d));
        foreach ($this->copiasLimpias($bcc, $toEmail, $acc) as $d) $email->addBcc(new Address($d));

        foreach ($attachments as $a) {
            if (!empty($a['path']) && is_file($a['path'])) {
                $email->attachFromPath($a['path'], $a['name'] ?? null, $a['mime'] ?? null);
            }
        }

        $headers = $email->getHeaders();

        // Message-ID PROPIO y válido: algunos SMTP devuelven solo un id de cola (p.ej.
        // «A688D1205C5», sin @dominio) que NO es un Message-ID RFC. Generamos el nuestro
        // para poder encadenarlo de forma fiable en el hilo.
        $domain = substr(strrchr((string) $acc->email, '@') ?: '@localhost', 1) ?: 'localhost';
        $msgId  = bin2hex(random_bytes(12)) . '@' . $domain;
        $headers->addIdHeader('Message-ID', $msgId);

        // Encadenado del HILO: In-Reply-To / References hacen que el cliente de correo
        // agrupe la respuesta con los anteriores. Solo se aceptan Message-IDs VÁLIDOS
        // (con @dominio y sin espacios); los ids de cola no-RFC se descartan.
        $valid = function ($id) {
            $c = trim((string) $id, "<> \t\r\n");
            return ($c !== '' && str_contains($c, '@') && !preg_match('/[\s<>]/', $c)) ? $c : null;
        };
        if ($inReplyTo && ($v = $valid($inReplyTo))) {
            $headers->addIdHeader('In-Reply-To', $v);
        }
        $refs = array_values(array_filter(array_map($valid, $references)));
        if ($refs) {
            $headers->addIdHeader('References', $refs);
        }

        $transport->send($email);
        return $msgId;
    }

    /**
     * Depura una lista de copias justo antes de enviar. Quita:
     *   · el destinatario principal, que si no recibiría el correo dos veces;
     *   · NUESTROS propios buzones, que volverían a importarse creando un bucle
     *     de correos con uno mismo (el peor fallo posible aquí);
     *   · repetidos y direcciones inválidas.
     */
    public function copiasLimpias(array $dirs, string $toEmail, EmailAccount $acc): array
    {
        $fuera = array_filter([
            mb_strtolower(trim($toEmail)),
            mb_strtolower((string) $acc->email),
            mb_strtolower((string) $acc->imap_user),
            mb_strtolower((string) $acc->smtp_user),
        ]);

        $ok = [];
        foreach ($dirs as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d === '' || in_array($d, $fuera, true) || in_array($d, $ok, true)) continue;
            if (filter_var($d, FILTER_VALIDATE_EMAIL)) $ok[] = $d;
        }
        return $ok;
    }

    /**
     * Busca un ticket por el código [TK-AAMM-NNNN] presente en el asunto.
     *
     * Si ese ticket se FUSIONÓ en otro, el correo entra en el que sobrevive: el
     * cliente sigue respondiendo al hilo antiguo (su correo lleva el código viejo)
     * y no tiene por qué enterarse de que por dentro se juntaron dos tickets.
     */
    protected function ticketByCode(string $subject): ?int
    {
        if (preg_match('/TK-\d{4}-\d{4}/i', $subject, $m)) {
            $id = DB::table('tickets')->where('code', strtoupper($m[0]))->value('id');
            return $id ? app(TicketService::class)->ticketFinal((int) $id) : null;
        }
        return null;
    }
}
