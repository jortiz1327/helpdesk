<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\NotifyService;
use App\Services\SlaService;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Avisa por correo de los tickets con el SLA por vencer o vencido. Se apoya en
 * [[SlaService]] para el estado de los dos relojes y en [[NotifyService]] para el
 * envío (plantillas sla_warning / sla_breach, que nacen desactivadas).
 *
 * Manda UN solo correo por umbral: al entrar en «por vencer» sella `sla_warned_at`;
 * al vencer, `sla_breached_at`. Así el cron puede correr cada pocos minutos sin repetir.
 */
class SlaCheck extends Command
{
    protected $signature = 'sla:check {--dry : Solo informa, no envía correos ni sella marcas}';
    protected $description = 'Avisa por correo de los tickets con SLA por vencer o vencido';

    public function handle(SlaService $sla, NotifyService $notify): int
    {
        $dry = (bool) $this->option('dry');

        if ((string) Setting::get('sla_alerts_active', '1') !== '1') {
            $this->line('Avisos de SLA por correo desactivados (sla_alerts_active).');
            return self::SUCCESS;
        }
        if (!SlaService::activo()) {
            $this->line('El SLA global está apagado (sla_active).');
            return self::SUCCESS;
        }

        // Candidatos: tickets con el reloj EN MARCHA (no pausados: fuera de
        // esperando_respuesta/resuelto/cerrado), con algún vencimiento fijado y a los
        // que aún les falte algún aviso por mandar.
        $abiertos = array_values(array_diff(TicketService::OPEN_STATUSES, TicketService::SLA_PAUSED_STATUSES));

        $rows = DB::table('tickets as t')
            ->leftJoin('ticket_categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('ticket_priorities as p', 'p.key', '=', 't.priority')
            ->whereIn('t.status', $abiertos)
            ->where('t.channel', '!=', 'cron')
            ->where(fn ($q) => $q->whereNull('t.sla_warned_at')->orWhereNull('t.sla_breached_at'))
            ->where(fn ($q) => $q->whereNotNull('t.sla_response_due_at')->orWhereNotNull('t.sla_resolve_due_at'))
            ->orderBy('t.id')
            ->limit(500)
            ->get([
                't.id', 't.status', 't.opened_at', 't.created_at',
                't.first_response_at', 't.resolved_at', 't.closed_at',
                't.sla_paused_minutes', 't.sla_paused_since',
                't.sla_warned_at', 't.sla_breached_at',
                'c.sla_response_hours', 'c.sla_resolve_hours',
                'p.sla_response_mins as pri_response_mins', 'p.sla_resolve_mins as pri_resolve_mins',
            ]);

        $avisados = 0;
        $vencidos = 0;

        foreach ($rows as $t) {
            $estado = $sla->forTicket($t);

            // El reloj que dispara el aviso: primero el vencido, si no el que está por vencer.
            $late = $this->relojEn($estado, 'late');
            $warn = $this->relojEn($estado, 'warn');

            if ($late && !$t->sla_breached_at) {
                [$reloj, $info] = $late;
                if (!$dry) {
                    $notify->slaAlert('sla_breach', (int) $t->id, $this->vars($reloj, $info, true));
                    DB::table('tickets')->where('id', $t->id)->update(['sla_breached_at' => now()]);
                }
                $vencidos++;
                $this->line(($dry ? '[dry] ' : '') . "Ticket #{$t->id}: VENCIDO ({$reloj})");
            } elseif ($warn && !$t->sla_warned_at) {
                [$reloj, $info] = $warn;
                if (!$dry) {
                    $notify->slaAlert('sla_warning', (int) $t->id, $this->vars($reloj, $info, false));
                    DB::table('tickets')->where('id', $t->id)->update(['sla_warned_at' => now()]);
                }
                $avisados++;
                $this->line(($dry ? '[dry] ' : '') . "Ticket #{$t->id}: por vencer ({$reloj})");
            }
        }

        $this->info("Revisados {$rows->count()} · por vencer {$avisados} · vencidos {$vencidos}" . ($dry ? ' (simulacro)' : ''));
        return self::SUCCESS;
    }

    /**
     * Devuelve [etiquetaReloj, datosDelReloj] del primer reloj (respuesta o resolución)
     * que esté en el estado buscado, o null. La resolución se mira antes por ser el
     * compromiso más visible.
     */
    protected function relojEn(array $estado, string $buscado): ?array
    {
        foreach (['resolve' => 'Resolución', 'response' => 'Primera respuesta'] as $k => $etiqueta) {
            $r = $estado[$k] ?? null;
            if ($r && ($r['state'] ?? null) === $buscado) return [$etiqueta, $r];
        }
        return null;
    }

    /** Variables de la plantilla para ese reloj: cuándo vence y cuánto queda/lleva. */
    protected function vars(string $reloj, array $info, bool $vencido): array
    {
        $vence = isset($info['due']) ? Carbon::parse($info['due'])->format('d/m/Y H:i') : '—';
        $mins  = abs((int) ($info['minutes_left'] ?? 0));
        return [
            '{{reloj}}'   => $reloj,
            '{{vence}}'   => $vence,
            '{{retraso}}' => $this->humano($mins),
        ];
    }

    /** «135» → «2 h 15 min»; en horario laboral (así se miden los relojes). */
    protected function humano(int $mins): string
    {
        if ($mins <= 0) return 'menos de 1 min';
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        if ($h && $m) return "{$h} h {$m} min";
        return $h ? "{$h} h" : "{$m} min";
    }
}
