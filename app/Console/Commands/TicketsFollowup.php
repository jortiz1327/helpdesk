<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Services\ScheduledReplyService;
use App\Services\TicketReplyService;
use App\Services\TicketService;
use App\Services\UpcomingSends;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEGUIMIENTO AUTOMÁTICO de tickets de CORREO y WEB de la categoría SOPORTE (nunca
 * WhatsApp ni avisos de cron; ni garantías, pedidos o tickets sin categoría).
 *
 * Ticket «abierto», lo último lo dijimos nosotros y el cliente lleva N días sin contestar
 * (8 por defecto) → se le manda el aviso de cierre (plantilla «ticket_followup») en el
 * mismo hilo, visible en la conversación como «Automático», y el ticket pasa a «cerrado»
 * en ese momento (decisión del usuario, 28-oct-2026: antes era recordatorio + resuelto
 * 24 h después). Sin el correo de «Ticket cerrado» ni la encuesta: el cliente ya recibe
 * este. Si luego contesta, el correo reabre el ticket como siempre.
 * Días y el interruptor se cambian en Configuración › Comportamiento del ticket.
 *
 * Si el cliente contesta, lo último pasa a ser suyo y no se hace nada. Solo los tickets
 * creados desde `ticket_followup_since` (los anteriores se cierran a mano), y nada si la
 * plantilla está apagada. Los pospuestos no se tocan.
 */
class TicketsFollowup extends Command
{
    public const MARCA = 'seguimiento';   // messages.payload {"auto":"seguimiento"}
    public const CATEGORIA = 'soporte';   // ticket_categories.key: la única categoría con seguimiento

    protected $signature = 'tickets:followup {--dry : Solo enseña qué haría, sin enviar ni cambiar nada}';

    protected $description = 'Cierra con aviso al cliente los tickets sin respuesta suya tras N días (correo y web)';

    public function handle(TicketReplyService $reply, ScheduledReplyService $filas, TicketService $tickets): int
    {
        $desde = Setting::get('ticket_followup_since');
        $tpl = EmailTemplate::where('key', 'ticket_followup')->where('active', true)->first();
        if (!$desde || !$tpl) {
            $this->line('Seguimiento automático apagado.');
            return self::SUCCESS;
        }
        // Qué tickets toca cerrar: lo calcula UpcomingSends, el mismo cálculo que enseña
        // «Próximos envíos a clientes». Solo categoría SOPORTE (en garantías o pedidos un
        // silencio del cliente no significa que esté resuelto), abierto, correo/web, no
        // pospuesto, y lo último que vio el cliente es nuestro (las notas no cuentan).
        $cerrados = 0;
        foreach (app(UpcomingSends::class)->cierres() as $id => $cuando) {
            if ($cuando->isFuture()) continue;
            $t = $filas->ticketRow((int) $id);
            if (!$t || !$t->contact_email) continue;
            if ($this->option('dry')) { $this->line("Mandaría el aviso y cerraría el ticket {$id}"); $cerrados++; continue; }
            $vars = ['{{codigo}}' => e((string) $t->code), '{{asunto}}' => e((string) $t->subject),
                     '{{cliente}}' => e((string) ($t->contact_name ?: 'cliente')), '{{estado}}' => 'Cerrado',
                     '{{agente}}' => 'equipo', '{{soporte}}' => ''];
            $r = $reply->porCorreo($t, strtr($tpl->body, $vars), [], [], [], null, null, true, 'Tu incidencia se ha cerrado');
            if (empty($r['ok'])) { $this->warn("Ticket {$id}: " . ($r['error'] ?? 'no se pudo enviar')); continue; }
            DB::table('messages')->where('id', $r['id'])->update(['payload' => json_encode(['auto' => self::MARCA])]);
            // Sin el aviso de «Ticket cerrado» ni la encuesta: el cliente ya tiene este correo.
            $tickets->setStatus((int) $id, 'cerrado', null, false);
            $cerrados++;
        }

        if ($cerrados) $this->info("Cerrados por falta de respuesta del cliente: {$cerrados}");
        return self::SUCCESS;
    }
}
