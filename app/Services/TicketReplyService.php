<?php

namespace App\Services;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Envío de la RESPUESTA de un ticket, por CORREO o por WhatsApp. Extraído de
 * TicketsController (que era un controlador-Dios): aquí vive la orquestación de envío
 * —SMTP con adjuntos + threading + firma + rollback, o WhatsApp con multimedia—, y el
 * controlador se queda como frontera HTTP (auth, candado, validación, traducir el
 * resultado a una respuesta).
 *
 * Los métodos NO devuelven Response: devuelven un array con la forma de la respuesta y,
 * si hay error, `_status` (el código HTTP). El controlador lo traduce.
 */
class TicketReplyService
{
    public function __construct(
        protected AttachmentService $attachments,
        protected TicketService $tickets,
        protected MailService $mail,
    ) {}

    /**
     * Responde por CORREO. `$t` es la fila del ticket (con contact_email/name, code,
     * subject, cat_signature, category_name). `$cc`/`$bcc` ya vienen depurados.
     */
    /**
     * @param ?array $preAttachIds  Adjuntos YA guardados en disco (attachments.id), para
     *   la respuesta programada: el cron no tiene UploadedFiles, sino ficheros ya subidos.
     *   Si se pasa, no se guarda nada nuevo y no se borran al fallar (habrá reintento).
     */
    /**
     * @param ?object $me      null = lo envía el sistema (seguimiento automático): sin autor.
     * @param ?string $heading Titular de la plantilla de marca (por defecto, «Se ha respondido…»).
     */
    public function porCorreo(object $t, string $html, array $files, array $cc, array $bcc, $me, ?array $preAttachIds = null, bool $deleteAttachOnFail = true, ?string $heading = null): array
    {
        if (!$t->contact_email) {
            return ['ok' => false, 'error' => 'El contacto no tiene dirección de correo', '_status' => 422];
        }
        $acc = EmailAccount::where('funcion', 'soporte')->where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc) {
            return ['ok' => false, 'error' => 'No hay un buzón SMTP configurado', '_status' => 422];
        }

        // Adjuntos primero (validados + en disco), pero SIN mensaje aún: si el SMTP
        // falla, se limpian y no queda un mensaje fantasma en el hilo.
        $savedIds = [];
        $warnings = [];
        $forMail  = [];
        if ($preAttachIds !== null) {
            // Respuesta programada: los adjuntos ya estaban subidos, solo se referencian.
            $savedIds = array_map('intval', $preAttachIds);
            foreach ($savedIds as $aid) {
                if ($f = $this->attachments->find($aid)) {
                    [$path, $row] = $f;
                    $forMail[] = ['path' => $path, 'name' => $row->name, 'mime' => $row->mime];
                }
            }
        } elseif ($files) {
            [$savedIds, $warnings] = $this->attachments->store($files, (int) $t->id, null, (int) $me->id);
            foreach ($savedIds as $aid) {
                if ($f = $this->attachments->find($aid)) {
                    [$path, $row] = $f;
                    $forMail[] = ['path' => $path, 'name' => $row->name, 'mime' => $row->mime];
                }
            }
        }

        $subject = $this->replySubject((string) $t->subject, (string) $t->code);

        // Encadenado del hilo: cadena de Message-IDs previos (In-Reply-To = el último;
        // References = toda la cadena) para que el cliente agrupe la conversación.
        $refs = DB::table('messages')->where('ticket_id', $t->id)->whereNotNull('wamid')
            ->orderBy('id')->pluck('wamid')
            ->map(fn ($w) => trim((string) $w, "<> \t\r\n"))
            ->filter()->values()->all();
        $inReplyTo = $refs ? end($refs) : null;

        // Firma del DEPARTAMENTO (categoría del ticket), con {{agente}}/{{departamento}}.
        $firma = trim((string) ($t->cat_signature ?? ''));
        if ($firma !== '') {
            $firma = strtr($firma, [
                '{{agente}}'       => e((string) ($me->name ?? '')),
                '{{departamento}}' => e((string) ($t->category_name ?? '')),
            ]);
        }

        // Plantilla de marca: envuelve la respuesta en una cabecera + tarjeta «Se ha
        // respondido a tu incidencia» (la firma y el pie los mete la propia plantilla).
        $layout = [
            'heading' => $heading ?? 'Se ha respondido a tu incidencia',
            'meta'    => trim(((string) $t->code) . ($t->subject ? ' · ' . (string) $t->subject : '')),
        ];

        try {
            $smtpId = $this->mail->sendMail(
                $acc, (string) $t->contact_email, (string) $t->contact_name,
                $subject, $this->absolutizeInline($html), $forMail, $inReplyTo, $refs, $cc, $bcc, $firma, $layout
            );
        } catch (\Throwable $e) {
            // Deshacer adjuntos guardados (ficheros + filas) para no dejar basura. En la
            // respuesta programada NO se borran: el cron reintentará y los necesita.
            if ($deleteAttachOnFail) {
                foreach ($savedIds as $aid) {
                    if ($f = $this->attachments->find($aid)) { try { Storage::disk('local')->delete($f[1]->path); } catch (\Throwable $x) {} }
                }
                if ($savedIds) DB::table('attachments')->whereIn('id', $savedIds)->delete();
            }

            return ['ok' => false, 'error' => 'No se pudo enviar el correo: ' . mb_substr($e->getMessage(), 0, 160), '_status' => 502];
        }

        // Enviado: guardar el mensaje saliente y colgarle los adjuntos.
        $messageId = ChatService::storeMessage((int) $t->contact_id, (string) ($t->contact_wa ?? ''), 'out', 'text', $html, [
            'ticket_id'      => (int) $t->id,
            'author_user_id' => $me?->id ? (int) $me->id : null,
            'is_html'        => true,
            'channel'        => 'email',
            'status'         => 'sent',
            'cc'             => $cc ? implode(', ', $cc) : null,
            'bcc'            => $bcc ? implode(', ', $bcc) : null,
            'wamid'          => $smtpId ? mb_substr($smtpId, 0, 128) : null,
        ]);
        if ($savedIds) {
            DB::table('attachments')->whereIn('id', $savedIds)->update(['message_id' => $messageId]);
        }

        return ['ok' => true, 'id' => $messageId, 'warnings' => $warnings];
    }

    /**
     * Responde por WHATSAPP desde el número de SOPORTE (nunca desde el de Campañas). El
     * texto sale con el formato que admite WhatsApp (*negrita*, _cursiva_, ~tachado~) y
     * cada adjunto como un mensaje aparte. Los adjuntos se guardan también aquí (Meta
     * borra sus archivos a las pocas semanas). Sin bot ni consentimiento: es soporte.
     */
    public function porWhatsApp(object $t, string $html, array $files, $me): array
    {
        $to = preg_replace('/\D+/', '', (string) ($t->contact_wa ?? ''));
        if ($to === '') return ['ok' => false, 'error' => 'El contacto no tiene número de WhatsApp', '_status' => 422];

        $num = \App\Models\WhatsAppNumber::porFuncion('soporte');
        $wa  = $num ? app(WhatsAppService::class)->paraNumero($num) : null;
        if (!$wa || !$num->token || !$wa->configured()) {
            return ['ok' => false, 'error' => 'El número de WhatsApp de soporte no tiene token para enviar (Configuración → WhatsApp)', '_status' => 422];
        }

        // Ventana de 24 h: fuera de ella Meta solo admite plantillas aprobadas.
        $ultimo = DB::table('messages')->where('contact_id', $t->contact_id)->where('channel', 'whatsapp')
            ->where('funcion', 'soporte')->where('direction', 'in')->max('created_at');
        if (!$ultimo || \Illuminate\Support\Carbon::parse($ultimo)->addDay()->isPast()) {
            return ['ok' => false, 'error' => self::SOLO_MOVIL, '_status' => 422];
        }

        $texto = self::aTextoWhatsApp($html);
        if ($texto === '' && !$files) return ['ok' => false, 'error' => 'La respuesta está vacía', '_status' => 400];
        if (mb_strlen($texto) > 4096) return ['ok' => false, 'error' => 'WhatsApp admite hasta 4096 caracteres por mensaje', '_status' => 422];

        $opts = ['ticket_id' => (int) $t->id, 'author_user_id' => (int) $me->id, 'sent_by' => (int) $me->id,
                 'channel' => 'whatsapp', 'funcion' => 'soporte', 'status' => 'sent'];
        $ids = [];

        if ($texto !== '') {
            [$code, $res] = $wa->sendText($to, $texto);
            if (!($code >= 200 && $code < 300 && !empty($res['messages'][0]['id']))) {
                return ['ok' => false, 'error' => self::errorMeta($res), '_status' => 502];
            }
            $ids[] = ChatService::storeMessage((int) $t->contact_id, $to, 'out', 'text', $texto, $opts + ['wamid' => $res['messages'][0]['id']]);
        }

        // Adjuntos: subir a Meta, enviar y guardar copia propia colgada del mensaje.
        $warnings = [];
        foreach ($files as $f) {
            if (!$f || !$f->isValid()) continue;
            $mime = $f->getClientMimeType() ?: 'application/octet-stream';
            $name = $f->getClientOriginalName() ?: 'archivo';
            $tipo = match (true) {
                in_array($mime, ['image/jpeg', 'image/png'], true)            => 'image',
                in_array($mime, ['video/mp4', 'video/3gpp'], true)           => 'video',
                str_starts_with($mime, 'audio/')                             => 'audio',
                default                                                      => 'document',
            };
            [$uc, $ur] = $wa->uploadMedia($f->getRealPath(), $mime, $name);
            if (empty($ur['id'])) { $warnings[] = "«{$name}» no se pudo subir a WhatsApp: " . self::errorMeta($ur); continue; }
            [$code, $res] = $wa->sendMedia($to, $tipo, (string) $ur['id'], '', $tipo === 'document' ? $name : null);
            if (!($code >= 200 && $code < 300 && !empty($res['messages'][0]['id']))) { $warnings[] = "«{$name}» no se envió: " . self::errorMeta($res); continue; }

            // Sin texto: el archivo (con su nombre) se ve como adjunto del mensaje.
            $mid = ChatService::storeMessage((int) $t->contact_id, $to, 'out', $tipo, '',
                $opts + ['wamid' => $res['messages'][0]['id']]);
            [$saved, $w] = $this->attachments->store([$f], (int) $t->id, $mid, (int) $me->id);
            $warnings = array_merge($warnings, $w);
            $ids[] = $mid;
        }

        if (!$ids) return ['ok' => false, 'error' => $warnings[0] ?? 'No se envió nada', '_status' => 502];
        $this->tickets->broadcast('reply', (int) $t->id);   // respuesta nuestra: refresca, no avisa
        return ['ok' => true, 'id' => end($ids), 'warnings' => $warnings];
    }

    /*
     * Fuera de la ventana de 24 h la API no deja escribir, pero la app del MÓVIL sí
     * (Coexistence): lo que se envíe desde allí entra en el ticket como «Desde el móvil».
     */
    public const SOLO_MOVIL = 'Solo puedes escribirle desde el móvil: han pasado más de 24 h desde su último mensaje y WhatsApp ya no deja contestar desde el Helpdesk.';

    /** Mensaje legible de un error de la Graph API (con los casos típicos de WhatsApp). */
    protected static function errorMeta(array $res): string
    {
        $code = (int) ($res['error']['code'] ?? 0);
        return match ($code) {
            131047  => self::SOLO_MOVIL,
            131026  => 'Ese número no puede recibir el mensaje (¿no tiene WhatsApp?).',
            190     => 'El token del número de soporte ha caducado o no es válido.',
            default => 'WhatsApp: ' . mb_substr((string) ($res['error']['error_data']['details'] ?? $res['error']['message'] ?? 'error desconocido'), 0, 200),
        };
    }

    /**
     * HTML del editor → texto de WhatsApp: negrita *x*, cursiva _x_, tachado ~x~, saltos
     * de línea; los enlaces quedan como «texto (url)». Lo demás se queda en texto plano.
     */
    public static function aTextoWhatsApp(string $html): string
    {
        $h = preg_replace('#<br\s*/?>#i', "\n", $html);
        $h = preg_replace('#</(p|div|li|blockquote|h\d)>#i', "\n", $h);
        $h = preg_replace('#<li[^>]*>#i', '• ', $h);
        $h = preg_replace_callback('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
            $txt = trim(strip_tags($m[2]));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return ($txt === '' || $txt === $url) ? $url : "{$txt} ({$url})";
        }, $h);
        // Marcas de formato. El espacio pegado a la marca rompe el formato en WhatsApp
        // («*hola *» no sale en negrita), así que se saca fuera.
        $marca = function (string $tags, string $m) use (&$h) {
            $h = preg_replace_callback('#<(' . $tags . ')(\s[^>]*)?>(.*?)</\1>#is', function ($x) use ($m) {
                if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $x[3], $p) || trim(strip_tags($p[2])) === '') return $x[3];
                return $p[1] . $m . $p[2] . $m . $p[3];
            }, $h);
        };
        $marca('b|strong', '*');
        $marca('i|em', '_');
        $marca('s|strike|del', '~');

        $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{00A0}", ' ', $t);
        $t = preg_replace("/[ \t]+\n/", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }

    protected function replySubject(string $subject, string $code): string
    {
        $s = trim($subject) ?: 'Sin asunto';
        if (stripos($s, 're:') !== 0)     $s = 'Re: ' . $s;
        if (stripos($s, $code) === false) $s .= ' [' . $code . ']';
        return mb_substr($s, 0, 200);
    }

    /** Rutas de imágenes EN LÍNEA (/api/inline/.., /api/attachment_inline/..) a ABSOLUTAS. */
    protected function absolutizeInline(string $html): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') return $html;
        return preg_replace('#(src=")(/api/(?:inline|attachment_inline)/)#i', '$1' . $base . '$2', $html);
    }

}
