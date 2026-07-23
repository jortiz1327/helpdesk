<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * BLOQUEO DE TICKETS — evita que dos agentes contesten a la vez.
 *
 * Al abrir un ticket, el agente lo «toma» durante unos minutos (configurable).
 * Mientras dure, los demás lo ven ocupado y no pueden responder. El bloqueo se
 * renueva solo mientras el agente sigue mirando, y CADUCA por su cuenta: si
 * alguien cierra el navegador, el ticket no se queda atascado para siempre.
 *
 * Con los minutos a 0 la función queda desactivada por completo.
 */
class TicketLockService
{
    /** Minutos que dura el bloqueo. 0 = desactivado. */
    public function minutes(): int
    {
        return max(0, (int) Setting::get('ticket_lock_minutes', '2'));
    }

    public function enabled(): bool
    {
        return $this->minutes() > 0;
    }

    /**
     * Intenta tomar el ticket para $userId.
     * Devuelve el estado del bloqueo: ['mine'=>bool, 'user_id'=>?int, 'user_name'=>?string, 'minutes'=>int]
     * o null si la función está desactivada.
     */
    public function acquire(int $ticketId, int $userId): ?array
    {
        if (!$this->enabled()) return null;

        return DB::transaction(function () use ($ticketId, $userId) {
            $t = DB::table('tickets')->where('id', $ticketId)->lockForUpdate()->first(['locked_by', 'locked_at']);
            if (!$t) return null;

            $vivo = $this->vigente($t->locked_by, $t->locked_at);

            // Libre, caducado o ya es mío → lo tomo (y renuevo la marca de tiempo).
            if (!$vivo || (int) $t->locked_by === $userId) {
                DB::table('tickets')->where('id', $ticketId)->update(['locked_by' => $userId, 'locked_at' => now()]);
                return ['mine' => true, 'user_id' => $userId, 'user_name' => null, 'minutes' => $this->minutes()];
            }

            // Lo tiene otro y sigue vigente.
            return [
                'mine'      => false,
                'user_id'   => (int) $t->locked_by,
                'user_name' => DB::table('users')->where('id', $t->locked_by)->value('name'),
                'minutes'   => $this->minutes(),
            ];
        });
    }

    /** Suelta el ticket, solo si lo tenía este usuario (no se pisa el de otro). */
    public function release(int $ticketId, int $userId): void
    {
        DB::table('tickets')->where('id', $ticketId)->where('locked_by', $userId)
            ->update(['locked_by' => null, 'locked_at' => null]);
    }

    /**
     * ¿Puede este usuario escribir en el ticket? Devuelve null si sí, o el nombre
     * de quien lo tiene tomado si no.
     */
    public function blockedBy(int $ticketId, int $userId): ?string
    {
        if (!$this->enabled()) return null;

        $t = DB::table('tickets')->where('id', $ticketId)->first(['locked_by', 'locked_at']);
        if (!$t || !$this->vigente($t->locked_by, $t->locked_at)) return null;
        if ((int) $t->locked_by === $userId) return null;

        return DB::table('users')->where('id', $t->locked_by)->value('name') ?: 'otro agente';
    }

    /** ¿Sigue vigente el bloqueo o ya ha caducado? */
    protected function vigente(?int $lockedBy, ?string $lockedAt): bool
    {
        if (!$lockedBy || !$lockedAt) return false;
        return strtotime($lockedAt) > strtotime('-' . $this->minutes() . ' minutes');
    }
}
