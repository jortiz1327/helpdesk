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
    public function porCorreo(object $t, string $html, array $files, array $cc, array $bcc, $me, ?array $preAttachIds = null, bool $deleteAttachOnFail = true): array
    {
        if (!$t->contact_email) {
            return ['ok' => false, 'error' => 'El contacto no tiene dirección de correo', '_status' => 422];
        }
        $acc = EmailAccount::where('active', true)->whereNotNull('smtp_host')->orderBy('id')->first();
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
            'heading' => 'Se ha respondido a tu incidencia',
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
            'author_user_id' => (int) $me->id,
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
