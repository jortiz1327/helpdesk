<?php

namespace App\Support;

use App\Models\User;

/**
 * ÚNICA fuente de verdad de «qué tickets ve un usuario»:
 *   · con `tickets.view_all` → todos;
 *   · si no → los de SUS categorías, los que tiene asignados, y los CERRADOS
 *     (histórico compartido por todos).
 *
 * Se usa como filtro de consulta (la bandeja, acciones en lote) Y para comprobar el
 * acceso a un adjunto concreto (`AttachmentController`). Antes ese criterio estaba
 * copiado a mano en dos sitios: si cambiaba en uno y no en el otro, aparecía una fuga
 * o un 403 espurio. Ahora vive aquí una sola vez.
 */
class TicketVisibility
{
    /** Aplica el filtro de visibilidad a una consulta de tickets (con alias `t`). */
    public static function scope($query, User $me)
    {
        if ($me->can('tickets.view_all')) {
            return $query;
        }

        $cats = $me->categoryIds();
        return $query->where(function ($q) use ($cats, $me) {
            if ($cats) $q->whereIn('t.category_id', $cats);
            $q->orWhere('t.assigned_to', $me->id);
            $q->orWhere('t.status', 'cerrado');   // los cerrados, para todos
        });
    }

    /** ¿Puede este usuario ver el ticket $ticketId? (mismo criterio que scope()). */
    public static function puedeVer(User $me, ?int $ticketId): bool
    {
        if (!$ticketId) return false;
        return self::scope(
            \Illuminate\Support\Facades\DB::table('tickets as t')->where('t.id', $ticketId),
            $me
        )->exists();
    }
}
