<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TICKETS SIN CONTESTAR: el cliente escribió lo último y lleva X días (4 por defecto)
 * esperando, con el ticket en «nuevo» o «abierto» (planificado, resuelto y cerrado
 * quedan fuera: ya están gestionados). Nunca los avisos de cron.
 *
 * Lo usa el correo diario a quien está de turno (`tickets:stale-digest`). A propósito
 * no hay vista en la app (decisión del usuario): la idea es que haya pocos.
 *
 * «Lo último» se mira en los mensajes PÚBLICOS: una nota interna no es contestar al
 * cliente, así que no para el reloj. Lo enviado desde el móvil (WhatsApp) sí cuenta.
 */
class StaleTickets
{
    public const ESTADOS = ['nuevo', 'abierto'];

    public static function dias(): int
    {
        return max(1, min(60, (int) Setting::get('stale_alert_days', '4')));
    }

    /**
     * Condición SQL (tabla con alias `t`): el último mensaje público es del cliente y
     * no hay ninguno público desde hace X días. Lleva un `?` para la fecha límite.
     */
    public static function sql(): string
    {
        return "t.status IN ('" . implode("','", self::ESTADOS) . "') AND t.channel <> 'cron' AND t.merged_into_id IS NULL"
            . " AND (SELECT m.direction FROM messages m WHERE m.ticket_id = t.id AND m.is_internal_note = 0 ORDER BY m.id DESC LIMIT 1) = 'in'"
            . " AND NOT EXISTS (SELECT 1 FROM messages m2 WHERE m2.ticket_id = t.id AND m2.is_internal_note = 0 AND m2.created_at >= ?)";
    }

    /** Fecha límite: lo que no se mueve desde antes de esto está atascado. */
    public static function limite(?int $dias = null): Carbon
    {
        return now()->subDays($dias ?? self::dias());
    }

    /** Restringe una consulta de tickets (alias `t`) a los que esperan respuesta. */
    public static function filtrar($q): void
    {
        $q->whereRaw('(' . self::sql() . ')', [self::limite()]);
    }

    /**
     * Desde cuándo espera cada ticket (fecha de su último mensaje público, si es del
     * cliente). Una consulta para todos: [ticket_id => Carbon].
     */
    public static function esperandoDesde(array $ids): array
    {
        if (!$ids) return [];
        $ultimos = DB::table('messages')->whereIn('ticket_id', $ids)->where('is_internal_note', 0)
            ->groupBy('ticket_id')->selectRaw('MAX(id) id')->pluck('id');
        if ($ultimos->isEmpty()) return [];

        return DB::table('messages')->whereIn('id', $ultimos)->where('direction', 'in')
            ->get(['ticket_id', 'created_at'])
            ->mapWithKeys(fn ($m) => [(int) $m->ticket_id => Carbon::parse($m->created_at)])->all();
    }
}
