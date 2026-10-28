<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Services\ScheduledReplyService;
use App\Services\TicketReplyService;
use App\Services\TicketService;
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
    protected const CAMBIO = '2026-10-28 12:00:00';   // desde aquí se cierra directo (sin recordatorio)

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
        // Configurable en «Comportamiento del ticket».
        $dias = max(1, (int) Setting::get('ticket_followup_days', '8'));

        // Solo la categoría SOPORTE (por su clave, estable aunque se cambie el nombre): en
        // garantías o pedidos un silencio del cliente no significa que esté resuelto.
        $soporte = DB::table('ticket_categories')->where('key', self::CATEGORIA)->value('id');
        if (!$soporte) {
            $this->line('No existe la categoría «' . self::CATEGORIA . '»: no se hace seguimiento.');
            return self::SUCCESS;
        }

        $ids = DB::table('tickets')
            ->where('status', 'abierto')
            ->where('category_id', $soporte)
            ->whereIn('channel', ['email', 'web'])
            ->where('last_direction', 'out')
            ->whereNull('snoozed_at')->whereNull('merged_into_id')
            ->where('created_at', '>=', $desde)
            ->pluck('id');   // (sin filtrar por last_message_at: las notas internas también lo mueven)

        $cerrados = 0;
        foreach ($ids as $id) {
            // Lo último que vio el cliente (las notas internas no cuentan).
            $ult = DB::table('messages')->where('ticket_id', $id)->where('is_internal_note', 0)
                ->orderByDesc('id')->first(['direction', 'created_at', 'payload']);
            if (!$ult || $ult->direction !== 'out') continue;
            $esAuto = (json_decode((string) $ult->payload, true)['auto'] ?? null) === self::MARCA;
            $hace = now()->diffInHours($ult->created_at, true);

            if ($esAuto) {
                // Recordatorios del sistema anterior (recordatorio + 24 h): se cierran sin
                // mandar nada más, que el cliente ya recibió su correo. Solo los anteriores al
                // cambio: si un agente reabre uno cerrado por aquí, no se vuelve a cerrar solo.
                if ($hace < 24 || (string) $ult->created_at >= self::CAMBIO) continue;
                if ($this->option('dry')) { $this->line("Cerraría el ticket {$id} (recordatorio antiguo)"); $cerrados++; continue; }
                if ($tickets->setStatus((int) $id, 'cerrado', null, false)) $cerrados++;
            } elseif ($hace >= $dias * 24) {
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
        }

        if ($cerrados) $this->info("Cerrados por falta de respuesta del cliente: {$cerrados}");
        return self::SUCCESS;
    }
}
