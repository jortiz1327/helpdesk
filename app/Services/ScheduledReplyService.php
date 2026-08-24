<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RESPUESTAS PROGRAMADAS (solo correo). El agente redacta ahora; el mensaje sale a su
 * hora. Aquí se guarda la pendiente (con los adjuntos ya subidos) y un cron la envía por
 * el MISMO camino que una respuesta normal (TicketReplyService::porCorreo).
 */
class ScheduledReplyService
{
    public function __construct(
        protected AttachmentService $attachments,
        protected TicketReplyService $reply,
    ) {}

    /** La fila del ticket con lo que porCorreo necesita (contacto, firma, categoría). */
    public function ticketRow(int $id): ?object
    {
        return DB::table('tickets as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
            ->where('t.id', $id)
            ->first(['t.id', 't.code', 't.subject', 't.channel', 't.contact_id',
                'c.email as contact_email', 'c.name as contact_name', 'c.wa_id as contact_wa',
                'cat.signature as cat_signature', 'cat.name as category_name']);
    }

    /**
     * Programa una respuesta: sube los adjuntos ya (para tenerlos al enviar) y crea la
     * fila pendiente. Devuelve el id de la programación.
     */
    public function schedule(int $ticketId, string $html, array $files, array $cc, array $bcc, \DateTimeInterface $sendAt, int $userId): int
    {
        $attachIds = [];
        if ($files) { [$attachIds] = $this->attachments->store($files, $ticketId, null, $userId); }

        return (int) DB::table('scheduled_replies')->insertGetId([
            'ticket_id'      => $ticketId,
            'user_id'        => $userId,
            'body'           => $html,
            'cc'             => $cc ? json_encode(array_values($cc)) : null,
            'bcc'            => $bcc ? json_encode(array_values($bcc)) : null,
            'attachment_ids' => $attachIds ? json_encode(array_values($attachIds)) : null,
            'send_at'        => $sendAt,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /** Las programaciones PENDIENTES de un ticket (para pintarlas en el hilo). */
    public function pendingFor(int $ticketId): array
    {
        return DB::table('scheduled_replies as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.ticket_id', $ticketId)->where('s.status', 'pending')
            ->orderBy('s.send_at')
            ->get(['s.id', 's.body', 's.send_at', 's.user_id', 'u.name as author_name'])->all();
    }

    /**
     * Cancela una programación pendiente (y borra sus adjuntos huérfanos). Devuelve
     * false si no existe o ya no está pendiente.
     */
    public function cancel(int $id): bool
    {
        $sr = DB::table('scheduled_replies')->where('id', $id)->where('status', 'pending')->first();
        if (!$sr) return false;

        $this->borrarAdjuntos($sr);
        DB::table('scheduled_replies')->where('id', $id)
            ->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);
        return true;
    }

    /** Envía las programaciones VENCIDAS. Lo llama el cron. Devuelve cuántas salieron. */
    public function dispatchDue(): int
    {
        $due = DB::table('scheduled_replies')
            ->where('status', 'pending')->where('send_at', '<=', now())
            ->orderBy('send_at')->limit(50)->get();

        $n = 0;
        foreach ($due as $sr) {
            $t      = $this->ticketRow((int) $sr->ticket_id);
            $author = $sr->user_id ? User::find($sr->user_id) : null;

            if (!$t || !$author) { $this->fail($sr, 'El ticket o el autor ya no existen'); continue; }

            $cc  = $sr->cc  ? (json_decode($sr->cc, true) ?: []) : [];
            $bcc = $sr->bcc ? (json_decode($sr->bcc, true) ?: []) : [];
            $ids = $sr->attachment_ids ? (json_decode($sr->attachment_ids, true) ?: []) : [];

            // Reenvío por el camino normal, con los adjuntos YA guardados y sin borrarlos
            // si falla (habrá reintento).
            $r = $this->reply->porCorreo($t, (string) $sr->body, [], $cc, $bcc, $author, $ids, false);

            if (!empty($r['ok'])) {
                DB::table('scheduled_replies')->where('id', $sr->id)
                    ->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
                $n++;
            } else {
                $this->retry($sr, (string) ($r['error'] ?? 'Error de envío'));
            }
        }
        return $n;
    }

    /** Un intento fallido: reintenta hasta 5 veces; luego se marca fallida y se avisa. */
    protected function retry(object $sr, string $err): void
    {
        $intentos = (int) $sr->attempts + 1;
        if ($intentos >= 5) { $this->fail($sr, $err); return; }
        DB::table('scheduled_replies')->where('id', $sr->id)
            ->update(['attempts' => $intentos, 'error' => mb_substr($err, 0, 200), 'updated_at' => now()]);
    }

    /** Marca la programación como fallida y avisa al autor por la campana. */
    protected function fail(object $sr, string $err): void
    {
        DB::table('scheduled_replies')->where('id', $sr->id)
            ->update(['status' => 'failed', 'attempts' => (int) $sr->attempts + 1, 'error' => mb_substr($err, 0, 200), 'updated_at' => now()]);

        if ($sr->user_id) {
            $code = DB::table('tickets')->where('id', $sr->ticket_id)->value('code');
            app(NotificationService::class)->push((int) $sr->user_id, 'sched_failed',
                "No se pudo enviar tu respuesta programada del ticket {$code}: {$err}", (int) $sr->ticket_id, null);
        }
    }

    /** Borra de disco + tabla los adjuntos de una programación (al cancelar). */
    protected function borrarAdjuntos(object $sr): void
    {
        $ids = $sr->attachment_ids ? (json_decode($sr->attachment_ids, true) ?: []) : [];
        foreach ($ids as $aid) {
            if ($f = $this->attachments->find((int) $aid)) {
                try { Storage::disk('local')->delete($f[1]->path); } catch (\Throwable $e) {}
            }
        }
        if ($ids) DB::table('attachments')->whereIn('id', $ids)->delete();
    }
}
