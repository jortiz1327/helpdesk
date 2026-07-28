<?php

namespace App\Http\Controllers;

use App\Services\SlaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * INFORMES del helpdesk (rendimiento del equipo). Distinto de AnalyticsController,
 * que es de WhatsApp/campañas. Todo en una llamada para pintar el panel de un viaje:
 * KPIs globales + desglose por agente, por categoría y por canal, en un periodo.
 * Requiere analytics.view.
 */
class TicketReportsController extends Controller
{
    public function handle(Request $request)
    {
        $since = match ($request->query('period', '30d')) {
            'today' => now()->startOfDay(),
            '7d'    => now()->subDays(7),
            '30d'   => now()->subDays(30),
            default => null,   // 'all'
        };

        // Expresiones de métrica reutilizadas (mismo criterio de «vencido» que la bandeja).
        $vencido   = SlaService::activo()
            ? '(t.sla_resolve_due_at < NOW() OR (t.sla_response_due_at < NOW() AND t.first_response_at IS NULL))'
            : '0';
        $abiertos  = "t.status IN ('nuevo','abierto','en_progreso','esperando_respuesta')";
        $resueltos = "t.status IN ('resuelto','cerrado')";
        $resolMin  = 'AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END)';
        $respMin   = 'AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at) END)';

        // Base: tickets del periodo (no-cron, sin fusionar). Se clona para cada corte.
        $base = fn () => DB::table('tickets as t')
            ->where('t.channel', '!=', 'cron')->whereNull('t.merged_into_id')
            ->when($since, fn ($q) => $q->where('t.created_at', '>=', $since));

        $metricas = "COUNT(*) AS total,
            SUM($abiertos) AS abiertos,
            SUM($resueltos) AS resueltos,
            SUM(($abiertos) AND ($vencido)) AS vencidos,
            $resolMin AS resol_min,
            $respMin AS resp_min";

        // KPIs globales
        $k = (clone $base())->selectRaw($metricas)->first();

        // Por agente (incluye «Sin asignar»)
        $byAgent = (clone $base())->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->selectRaw("t.assigned_to AS id, COALESCE(u.name, u.email, 'Sin asignar') AS name, $metricas")
            ->groupByRaw('t.assigned_to, u.name, u.email')->orderByDesc('total')->get();

        // Por categoría
        $byCat = (clone $base())->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
            ->selectRaw("COALESCE(cat.name, 'Sin categoría') AS name, cat.color, $metricas")
            ->groupByRaw('t.category_id, cat.name, cat.color')->orderByDesc('total')->get();

        // Por canal
        $byChannel = (clone $base())->selectRaw('t.channel, COUNT(*) AS n')
            ->groupBy('t.channel')->orderByDesc('n')->pluck('n', 'channel');

        return response()->json([
            'ok'         => true,
            'sla_activo' => SlaService::activo(),
            'kpis'       => $this->fila($k),
            'by_agent'   => $byAgent->map(fn ($r) => $this->fila($r, ['id' => (int) $r->id, 'name' => $r->name]))->all(),
            'by_category' => $byCat->map(fn ($r) => $this->fila($r, ['name' => $r->name, 'color' => $r->color]))->all(),
            'by_channel' => $byChannel,
            'daily'      => $this->daily($request->query('period', '30d')),
        ]);
    }

    /**
     * Serie diaria (creados vs resueltos) para el gráfico de evolución. Rellena a cero
     * los días sin datos. Para «Hoy» muestra la última semana (un punto no es gráfico).
     */
    protected function daily(string $period): array
    {
        $dias  = match ($period) { '7d' => 7, 'today' => 7, default => 30 };
        $desde = now()->subDays($dias - 1)->startOfDay();

        $creados = DB::table('tickets')->where('channel', '!=', 'cron')->whereNull('merged_into_id')
            ->where('created_at', '>=', $desde)
            ->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $resueltos = DB::table('tickets')->where('channel', '!=', 'cron')->whereNull('merged_into_id')
            ->whereNotNull('resolved_at')->where('resolved_at', '>=', $desde)
            ->selectRaw('DATE(resolved_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');

        $out = [];
        for ($i = 0; $i < $dias; $i++) {
            $day = $desde->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $day, 'creados' => (int) ($creados[$day] ?? 0), 'resueltos' => (int) ($resueltos[$day] ?? 0)];
        }
        return $out;
    }

    /** Normaliza una fila de métricas (enteros + tiempos en horas o null). */
    protected function fila($r, array $extra = []): array
    {
        return array_merge($extra, [
            'total'     => (int) $r->total,
            'abiertos'  => (int) $r->abiertos,
            'resueltos' => (int) $r->resueltos,
            'vencidos'  => (int) $r->vencidos,
            'resol_h'   => $r->resol_min !== null ? round($r->resol_min / 60, 1) : null,
            'resp_h'    => $r->resp_min !== null ? round($r->resp_min / 60, 1) : null,
        ]);
    }
}
