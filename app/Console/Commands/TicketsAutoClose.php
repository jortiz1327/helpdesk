<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AUTO-CIERRE de tickets: cierra los que llevan X días RESUELTOS sin moverse.
 *
 * Solo toca los «resueltos»: un ticket abierto sin resolver nunca se cierra solo,
 * porque eso sería dar por atendido algo que no lo está. Con 0 días queda apagado.
 * Se ejecuta una vez al día desde el planificador.
 */
class TicketsAutoClose extends Command
{
    protected $signature = 'tickets:autoclose {--dry : Solo enseña cuáles se cerrarían, sin tocar nada}';

    protected $description = 'Cierra los tickets que llevan X días resueltos sin actividad';

    public function handle(TicketService $tickets): int
    {
        $dias = (int) Setting::get('ticket_autoclose_days', '0');
        if ($dias <= 0) {
            $this->line('Auto-cierre desactivado (0 días).');
            return self::SUCCESS;
        }

        $avisar = (string) Setting::get('ticket_autoclose_notify', '0') === '1';
        $limite = now()->subDays($dias);

        // Resueltos cuya última actividad (o resolución) es anterior al límite.
        $ids = DB::table('tickets')
            ->where('status', 'resuelto')
            ->whereRaw('COALESCE(last_message_at, resolved_at, opened_at) < ?', [$limite])
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No hay tickets que cerrar.');
            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->info("Se cerrarían {$ids->count()} ticket(s) resueltos hace más de {$dias} día(s).");
            return self::SUCCESS;
        }

        $n = 0;
        foreach ($ids as $id) {
            // userId null = lo hizo el sistema, y así queda en el historial del ticket.
            if ($tickets->setStatus((int) $id, 'cerrado', null, $avisar)) $n++;
        }

        $this->info("Cerrados {$n} ticket(s) tras {$dias} día(s) resueltos." . ($avisar ? ' Se avisó al cliente.' : ''));
        return self::SUCCESS;
    }
}
