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

        try {
            $smtpId = $this->mail->sendMail(
                $acc, (string) $t->contact_email, (string) $t->contact_name,
                $subject, $this->absolutizeInline($html), $forMail, $inReplyTo, $refs, $cc, $bcc, $firma
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

    /**
     * Responde por WhatsApp (número de SOPORTE). El candado y los permisos los ha
     * comprobado ya el controlador; aquí solo se envía. WhatsApp es texto plano; el
     * HTML del editor se aplana, y el texto acompaña a la primera imagen/vídeo como pie.
     */
    public function porWhatsapp(object $t, string $html, array $files, $me): array
    {
        if (!$t->contact_wa) {
            return ['ok' => false, 'error' => 'El contacto no tiene número de WhatsApp', '_status' => 422];
        }

        $medios = $this->recopilarMedios($html, $files);
        $texto  = trim(HtmlSanitizer::toText($html));
        if (!$medios && $texto === '') {
            return ['ok' => false, 'error' => 'La respuesta está vacía', '_status' => 400];
        }

        $wa   = app(WhatsAppService::class)->paraFuncion('soporte');
        $to   = (string) $t->contact_wa;
        $base = [
            'ticket_id'      => $t->id,
            'channel'        => 'whatsapp',
            'funcion'        => 'soporte',   // respuesta de soporte: va al Helpdesk, no a Campañas
            'status'         => 'sent',
            'author_user_id' => $me->id,
        ];
        $ultimoId = null;

        if ($medios) {
            foreach ($medios as $i => $md) {
                $type    = $this->tipoMediaPorMime($md['mime']);
                $caption = ($i === 0 && $type !== 'document') ? $texto : '';

                [$uc, $ur] = $wa->uploadMedia($md['path'], $md['mime'], $md['name']);
                $mediaId   = $ur['id'] ?? null;
                if (!$mediaId) {
                    return ['ok' => false, 'error' => $ur['error']['message'] ?? 'No se pudo subir el archivo a WhatsApp', '_status' => 422];
                }

                [$code, $res] = $wa->sendMedia($to, $type, $mediaId, $caption, $type === 'document' ? $md['name'] : null);
                if ($code < 200 || $code >= 300) {
                    return ['ok' => false, 'error' => $res['error']['message'] ?? 'No se pudo enviar por WhatsApp', '_status' => 422];
                }

                $body = $caption !== '' ? nl2br(e($caption)) : ($type === 'document' ? e($md['name']) : '');
                $ultimoId = ChatService::storeMessage($t->contact_id, $to, 'out', $type, $body, $base + [
                    'is_html'    => $caption !== '',
                    'wamid'      => $res['messages'][0]['id'] ?? null,
                    'media_url'  => $mediaId,
                    'media_mime' => $md['mime'],
                ]);
            }
        } else {
            [$code, $res] = $wa->sendText($to, $texto);
            if ($code < 200 || $code >= 300) {
                // La ventana de 24 h de WhatsApp: fuera de ella Meta rechaza el texto libre.
                return ['ok' => false, 'error' => $res['error']['message'] ?? 'No se pudo enviar por WhatsApp', '_status' => 422];
            }
            $ultimoId = ChatService::storeMessage($t->contact_id, $to, 'out', 'text', nl2br(e($texto)), $base + [
                'is_html' => true,
                'wamid'   => $res['messages'][0]['id'] ?? null,
            ]);
        }

        $this->tickets->broadcast('message', (int) $t->id);

        return ['ok' => true, 'id' => $ultimoId];
    }

    /** Asunto de la respuesta: «Re: …» + [CÓDIGO] para que la contestación vuelva al hilo. */
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

    /**
     * Reúne el multimedia de una respuesta de WhatsApp: adjuntos (clip) + imágenes EN
     * LÍNEA del editor (en el HTML como <img src=".../inline/ID">). Devuelve
     * [['path','mime','name'], ...] en orden.
     */
    protected function recopilarMedios(string $html, array $files): array
    {
        $medios = [];

        foreach ($files as $f) {
            if ($f && $f->isValid()) {
                $medios[] = [
                    'path' => $f->getRealPath(),
                    'mime' => $f->getClientMimeType() ?: 'application/octet-stream',
                    'name' => $f->getClientOriginalName() ?: 'archivo',
                ];
            }
        }

        if (preg_match_all('#/inline/(\d+)#', $html, $mm)) {
            foreach (array_unique($mm[1]) as $iid) {
                $row = DB::table('inline_uploads')->find((int) $iid);
                if ($row && Storage::disk('local')->exists($row->path)) {
                    $medios[] = [
                        'path' => Storage::disk('local')->path($row->path),
                        'mime' => $row->mime ?: 'application/octet-stream',
                        'name' => basename($row->path),
                    ];
                }
            }
        }

        return $medios;
    }

    /** Traduce el MIME a uno de los tipos de mensaje de WhatsApp. */
    protected function tipoMediaPorMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        return 'document';
    }
}
