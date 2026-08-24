<?php

namespace App\Console\Commands;

use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DESPERTAR tickets pospuestos cuya FECHA ya venció.
 *
 * La cola ya deja de ocultar un ticket en cuanto pasa su `snoozed_until` (el filtro
 * compara contra NOW()), así que la visibilidad no depende de este cron. Su trabajo es
 * el MANTENIMIENTO: limpiar los campos de snooze (para que el chip «💤» desaparezca) y
 * registrar el despertar en el historial (type='snooze_wake', motivo 'due'), que es lo
 * que alimenta el recibimiento matutino del agente que lo pospuso.
 *
 * Los de «hasta que responda» (sin fecha) NO los toca: esos despiertan con el mensaje
 * del cliente, no por tiempo.
 */
class TicketsWake extends Command
{
    protected $signature = 'tickets:wake';

    protected $description = 'Despierta los tickets pospuestos cuya fecha ya ha vencido';

    public function handle(TicketService $tickets): int
    {
        $ids = DB::table('tickets')
            ->whereNotNull('snoozed_at')
            ->where('snooze_wake_on_reply', 0)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->pluck('id');

        foreach ($ids as $id) {
            $tickets->wake((int) $id, 'due');
        }

        $this->info("Despertados {$ids->count()} ticket(s) pospuestos por vencimiento.");
        return self::SUCCESS;
    }
}
