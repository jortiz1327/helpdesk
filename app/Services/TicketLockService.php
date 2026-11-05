<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * BLOQUEO DE TICKETS — evita que dos agentes contesten a la vez.
 *
 * Al abrir un ticket, el agente lo «toma». Mientras lo tenga abierto, los demás lo ven
 * ocupado y no pueden responder. El candado es CORTO (LEASE_SECONDS) y la ventana del
 * ticket lo renueva con un latido cada pocos segundos mientras el agente siga activo:
 *   · si cierra el ticket, la pestaña o el navegador, se suelta en el acto (y si ni eso
 *     llega —se cuelga, se queda sin red—, caduca solo en minuto y medio);
 *   · si lo deja abierto sin tocar nada durante los minutos configurados, el latido para
 *     y se libera igual.
 * Antes el candado duraba los minutos configurados enteros desde el último latido, así
 * que un ticket abierto en un PC vacío o en un navegador cerrado seguía «tomado».
 *
 * Con los minutos a 0 la función queda desactivada por completo.
 */
class TicketLockService
{
    /** Segundos que vive el candado sin latido (el frontend late cada ~30 s). */
    public const LEASE_SECONDS = 90;

    /** Minutos SIN ACTIVIDAD tras los que se suelta un ticket abierto. 0 = desactivado. */
    public function minutes(): int
    {
        return max(0, (int) Setting::get('ticket_lock_minutes', '2'));
    }

    public function enabled(): bool
    {
        return $this->minutes() > 0;
    }

    /**
     * Intenta tomar el ticket para $userId. $idle = segundos desde la última actividad del
     * agente (el latido lo manda; al abrir es 0), para la cuenta atrás que ven los demás.
     * Devuelve el estado del bloqueo (ver mio()/deOtro()) o null si la función está desactivada.
     */
    public function acquire(int $ticketId, int $userId, int $idle = 0): ?array
    {
        if (!$this->enabled()) return null;
        $idle = max(0, min($idle, $this->minutes() * 60));

        return DB::transaction(function () use ($ticketId, $userId, $idle) {
            $t = DB::table('tickets')->where('id', $ticketId)->lockForUpdate()->first(self::COLS);
            if (!$t) return null;

            $vivo = $this->vigente($t->locked_by, $t->locked_at);

            // Libre, caducado o ya es mío → lo tomo (y renuevo la marca de tiempo).
            if (!$vivo || (int) $t->locked_by === $userId) {
                DB::table('tickets')->where('id', $ticketId)->update([
                    'locked_by' => $userId, 'locked_at' => now(), 'locked_active_at' => now()->subSeconds($idle),
                ]);
                return $this->mio($userId);
            }

            // Lo tiene otro y sigue vigente.
            return $this->deOtro($t);
        });
    }

    /** Suelta el ticket, solo si lo tenía este usuario (no se pisa el de otro). */
    public function release(int $ticketId, int $userId): void
    {
        DB::table('tickets')->where('id', $ticketId)->where('locked_by', $userId)
            ->update(['locked_by' => null, 'locked_at' => null, 'locked_active_at' => null]);
    }

    private const COLS = ['locked_by', 'locked_at', 'locked_active_at'];

    /**
     * Estado del candado SIN tomarlo ni renovar la marca de tiempo. Para los refrescos
     * de FONDO (sondeo, mensaje entrante): así un agente que abrió el ticket y se fue no
     * mantiene el candado vivo eternamente por el mero hecho de tener el modal abierto —
     * solo lo renueva el «latido», que va por actividad real. Misma forma que acquire().
     */
    public function status(int $ticketId, int $userId): ?array
    {
        if (!$this->enabled()) return null;

        $t = DB::table('tickets')->where('id', $ticketId)->first(self::COLS);
        if (!$t) return null;

        // Solo se considera ocupado si el candado de OTRO sigue vigente.
        if ($this->vigente($t->locked_by, $t->locked_at) && (int) $t->locked_by !== $userId) {
            return $this->deOtro($t);
        }
        // Libre, caducado o mío: se informa como «mío» pero NO se renueva locked_at.
        return $this->mio($userId);
    }

    /** minutes = inactividad tolerada; lease = vida del candado sin latido (el frontend late a un tercio). */
    protected function mio(int $userId): array
    {
        return ['mine' => true, 'user_id' => $userId, 'user_name' => null, 'minutes' => $this->minutes(), 'lease' => self::LEASE_SECONDS];
    }

    /**
     * remaining = segundos hasta que quede libre si su dueño no toca nada (los minutos sin
     * actividad; al llegar a 0 su ventana lo suelta). Si su navegador se cerró o se colgó, cae
     * antes, por falta de latido. Se calcula aquí para no depender del reloj del navegador.
     */
    protected function deOtro(object $t): array
    {
        $activo = strtotime($t->locked_active_at ?: $t->locked_at);
        $libre = $activo + $this->minutes() * 60;
        return [
            'mine'      => false,
            'user_id'   => (int) $t->locked_by,
            'user_name' => DB::table('users')->where('id', $t->locked_by)->value('name'),
            'minutes'   => $this->minutes(),
            'lease'     => self::LEASE_SECONDS,
            'remaining' => max(0, $libre - time()),
        ];
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

    /** ¿Sigue vigente el bloqueo (ha latido hace menos de LEASE_SECONDS) o ya ha caducado? */
    protected function vigente(?int $lockedBy, ?string $lockedAt): bool
    {
        if (!$lockedBy || !$lockedAt) return false;
        return strtotime($lockedAt) > time() - self::LEASE_SECONDS;
    }
}
