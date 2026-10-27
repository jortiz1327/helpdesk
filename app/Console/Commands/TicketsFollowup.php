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
 * SEGUIMIENTO AUTOMÁTICO de tickets de CORREO y WEB (nunca WhatsApp ni avisos de cron).
 *
 *   1. Ticket «abierto», lo último lo dijimos nosotros y el cliente lleva N días sin
 *      contestar (8 por defecto) → se le manda el recordatorio (plantilla «ticket_followup»),
 *      en el mismo hilo de correo y visible en la conversación como «Automático».
 *   2. Lo último es ese recordatorio y han pasado N horas (24) sin respuesta → «resuelto».
 * Días, horas y el interruptor se cambian en Configuración › Comportamiento del ticket.
 *
 * Si el cliente contesta, lo último pasa a ser suyo y no se hace nada. Si un agente
 * escribe después del recordatorio, vuelven a contar los días desde su mensaje. Solo los
 * tickets creados desde `ticket_followup_since` (los anteriores se cierran a mano), y
 * nada si la plantilla está apagada. Los pospuestos no se tocan.
 */
class TicketsFollowup extends Command
{
    public const MARCA = 'seguimiento';   // messages.payload {"auto":"seguimiento"}

    protected $signature = 'tickets:followup {--dry : Solo enseña qué haría, sin enviar ni cambiar nada}';

    protected $description = 'Recordatorio al cliente tras N días sin respuesta y resuelto N horas después (correo y web)';

    public function handle(TicketReplyService $reply, ScheduledReplyService $filas, TicketService $tickets): int
    {
        $desde = Setting::get('ticket_followup_since');
        $tpl = EmailTemplate::where('key', 'ticket_followup')->where('active', true)->first();
        if (!$desde || !$tpl) {
            $this->line('Seguimiento automático apagado.');
            return self::SUCCESS;
        }
        // Configurables en «Comportamiento del ticket».
        $dias  = max(1, (int) Setting::get('ticket_followup_days', '8'));
        $horas = max(1, (int) Setting::get('ticket_followup_hours', '24'));

        $ids = DB::table('tickets')
            ->where('status', 'abierto')
            ->whereIn('channel', ['email', 'web'])
            ->where('last_direction', 'out')
            ->whereNull('snoozed_at')->whereNull('merged_into_id')
            ->where('created_at', '>=', $desde)
            ->pluck('id');   // (sin filtrar por last_message_at: las notas internas también lo mueven)

        $recordados = 0; $resueltos = 0;
        foreach ($ids as $id) {
            // Lo último que vio el cliente (las notas internas no cuentan).
            $ult = DB::table('messages')->where('ticket_id', $id)->where('is_internal_note', 0)
                ->orderByDesc('id')->first(['direction', 'created_at', 'payload']);
            if (!$ult || $ult->direction !== 'out') continue;
            $esRecordatorio = (json_decode((string) $ult->payload, true)['auto'] ?? null) === self::MARCA;
            $hace = now()->diffInHours($ult->created_at, true);

            if ($esRecordatorio && $hace >= $horas) {
                if ($this->option('dry')) { $this->line("Resolvería el ticket {$id}"); $resueltos++; continue; }
                if ($tickets->setStatus((int) $id, 'resuelto')) $resueltos++;   // sin usuario = el sistema
            } elseif (!$esRecordatorio && $hace >= $dias * 24) {
                $t = $filas->ticketRow((int) $id);
                if (!$t || !$t->contact_email) continue;
                if ($this->option('dry')) { $this->line("Mandaría el recordatorio al ticket {$id}"); $recordados++; continue; }
                $vars = ['{{codigo}}' => e((string) $t->code), '{{asunto}}' => e((string) $t->subject),
                         '{{cliente}}' => e((string) ($t->contact_name ?: 'cliente')), '{{estado}}' => 'Abierto',
                         '{{agente}}' => 'equipo', '{{soporte}}' => ''];
                $r = $reply->porCorreo($t, strtr($tpl->body, $vars), [], [], [], null, null, true, 'Seguimos pendientes de tu respuesta');
                if (empty($r['ok'])) { $this->warn("Ticket {$id}: " . ($r['error'] ?? 'no se pudo enviar')); continue; }
                DB::table('messages')->where('id', $r['id'])->update(['payload' => json_encode(['auto' => self::MARCA])]);
                $tickets->broadcast('reply', (int) $id);
                $recordados++;
            }
        }

        if ($recordados || $resueltos) $this->info("Recordatorios: {$recordados} · Resueltos sin respuesta: {$resueltos}");
        return self::SUCCESS;
    }
}
