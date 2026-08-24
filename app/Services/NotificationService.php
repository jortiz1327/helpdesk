<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Centro de notificaciones in-app. Un aviso = una fila para UN usuario. La primera
 * fuente son las @menciones en notas internas; a futuro, mismo `push()` para «te
 * asignaron», «tu SLA va a vencer», etc. El contador de no leídas se sondea desde el
 * frontend (no hay websocket por usuario en este bloque).
 */
class NotificationService
{
    /** Crea una notificación para un usuario. Devuelve su id (o 0 si se omite). */
    public function push(int $userId, string $type, string $body, ?int $ticketId = null, ?int $actorId = null): int
    {
        if ($userId <= 0) return 0;
        // No te notificas a ti mismo (mencionarte en tu propia nota no avisa).
        if ($actorId && $actorId === $userId) return 0;

        return (int) DB::table('notifications')->insertGetId([
            'user_id'       => $userId,
            'type'          => $type,
            'ticket_id'     => $ticketId,
            'actor_user_id' => $actorId,
            'body'          => mb_substr($body, 0, 500),
            'created_at'    => now(),
        ]);
    }

    /** Nº de notificaciones sin leer de un usuario. */
    public function unread(int $userId): int
    {
        return (int) DB::table('notifications')->where('user_id', $userId)->whereNull('read_at')->count();
    }
}
