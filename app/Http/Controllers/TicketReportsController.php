<?php

namespace App\Http\Controllers;

use App\Services\SlaService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $period = $request->query('period', '30d');
        if (!in_array($period, ['today', '7d', '30d', 'all'], true)) $period = '30d';

        // Son datos de GESTIÓN, no transaccionales: se cachean unos minutos por periodo.
        // Así el panel no relanza 8 agregaciones sobre 50k en cada carga/recarga. El
        // desfase máximo (p. ej. un «vencido» que acaba de saltar) es de minutos, asumible.
        $payload = Cache::remember("reports.$period", now()->addMinutes(10), fn () => $this->build($period));

        return response()->json($payload);
    }

    /** Calcula el panel completo para un periodo (lo que se cachea). */
    protected function build(string $period): array
    {
        $since = match ($period) {
            'today' => now()->startOfDay(),
            '7d'    => now()->subDays(7),
            '30d'   => now()->subDays(30),
            default => null,   // 'all'
        };

        // Expresiones de métrica reutilizadas (mismo criterio de «vencido» que la bandeja:
        // un ticket con el reloj en pausa —sla_paused_since— no cuenta como vencido).
        $vencido   = SlaService::activo()
            ? '(t.sla_paused_since IS NULL AND (t.sla_resolve_due_at < NOW() OR (t.sla_response_due_at < NOW() AND t.first_response_at IS NULL)))'
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

        // Por agente (incluye «Sin asignar»). Se trae `active` para pintar en gris a los
        // agentes deshabilitados y priorizar a los habilitados en la tabla.
        $byAgent = (clone $base())->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->selectRaw("t.assigned_to AS id, COALESCE(u.name, u.email, 'Sin asignar') AS name, COALESCE(u.active, 1) AS active, $metricas")
            ->groupByRaw('t.assigned_to, u.name, u.email, u.active')->orderByDesc('total')->get();

        // Reparto por estado del periodo (para el gráfico «Tickets por estado» de Informes).
        $byStatus = array_fill_keys(array_keys(TicketService::STATUSES), 0);
        foreach ((clone $base())->groupBy('t.status')->get([DB::raw('t.status'), DB::raw('COUNT(*) AS n')]) as $f) {
            if (array_key_exists($f->status, $byStatus)) $byStatus[$f->status] = (int) $f->n;
        }

        // Por categoría
        $byCat = (clone $base())->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
            ->selectRaw("t.category_id AS cat_id, COALESCE(cat.name, 'Sin categoría') AS name, cat.color, $metricas")
            ->groupByRaw('t.category_id, cat.name, cat.color')->orderByDesc('total')->get();

        // Por canal
        $byChannel = (clone $base())->selectRaw('t.channel, COUNT(*) AS n')
            ->groupBy('t.channel')->orderByDesc('n')->pluck('n', 'channel');

        // CSAT por agente y por categoría (mismo filtro que la tarjeta global: portal).
        $csatAg  = $this->csatPor('assigned_to', $since);
        $csatCat = $this->csatPor('category_id', $since);

        return [
            'ok'         => true,
            'sla_activo' => SlaService::activo(),
            'kpis'       => $this->fila($k),
            'by_agent'   => $byAgent->map(fn ($r) => $this->fila($r, [
                'id' => (int) $r->id, 'name' => $r->name, 'active' => (bool) $r->active,
            ] + $this->csatFila($csatAg->get($r->id))))->all(),
            'by_status'    => $byStatus,
            'status_meta'  => TicketService::statusMeta(),   // etiqueta + color por estado
            'by_category' => $byCat->map(fn ($r) => $this->fila($r, [
                'name' => $r->name, 'color' => $r->color,
            ] + $this->csatFila($csatCat->get($r->cat_id))))->all(),
            'by_channel' => $byChannel,
            'daily'      => $this->daily($period),
            'csat'       => $this->csat($since),
        ];
    }

    /** CSAT (media + nº de valoraciones) agrupado por una columna del ticket. */
    protected function csatPor(string $col, $since): \Illuminate\Support\Collection
    {
        return DB::table('ticket_ratings as r')
            ->join('tickets as t', 't.id', '=', 'r.ticket_id')
            ->where('t.source', 'portal')->whereNull('t.merged_into_id')
            ->when($since, fn ($q) => $q->where('t.created_at', '>=', $since))
            ->groupBy("t.$col")
            ->get(["t.$col as k", DB::raw('AVG(r.score) media'), DB::raw('COUNT(*) n')])
            ->keyBy('k');
    }

    /** Normaliza una fila de CSAT: nota media (1 decimal) + nº, o nulos si no hay. */
    protected function csatFila($row): array
    {
        return [
            'csat'   => $row ? round((float) $row->media, 1) : null,
            'csat_n' => $row ? (int) $row->n : 0,
        ];
    }

    /**
     * SATISFACCIÓN (CSAT). Sobre las incidencias del PORTAL valoradas en el periodo:
     * nº de respuestas, nota media, % de satisfechos (4-5★) y el reparto 1..5 para
     * una mini-barra. Si no hay valoraciones, medias a null (la UI lo oculta).
     */
    protected function csat($since): array
    {
        $q = fn () => DB::table('ticket_ratings as r')
            ->join('tickets as t', 't.id', '=', 'r.ticket_id')
            ->where('t.source', 'portal')->whereNull('t.merged_into_id')
            ->when($since, fn ($qq) => $qq->where('t.created_at', '>=', $since));

        $agg  = $q()->selectRaw('COUNT(*) n, AVG(r.score) media, SUM(r.score >= 4) satisfechos')->first();
        $dist = $q()->selectRaw('r.score, COUNT(*) n')->groupBy('r.score')->pluck('n', 'score');

        $n = (int) $agg->n;
        return [
            'respuestas'      => $n,
            'media'           => $n ? round((float) $agg->media, 1) : null,
            'satisfechos_pct' => $n ? (int) round(100 * $agg->satisfechos / $n) : null,
            'dist'            => array_map(fn ($s) => (int) ($dist[$s] ?? 0), [1, 2, 3, 4, 5]),
        ];
    }

    /**
     * Serie diaria (creados vs resueltos) para el gráfico de evolución. Rellena a cero
     * los días sin datos. Para «Hoy» muestra la última semana (un punto no es gráfico).
     */
    protected function daily(string $period): array
    {
        $dias  = match ($period) { '7d' => 7, 'today' => 7, default => 30 };
        $desde = now()->subDays($dias - 1)->startOfDay();

        // ORDER BY NULL: el GROUP BY DATE() ordena implícitamente por el día (una función,
        // no indexable) → filesort inútil, porque el bucle de abajo reordena por día en PHP.
        $creados = DB::table('tickets')->where('channel', '!=', 'cron')->whereNull('merged_into_id')
            ->where('created_at', '>=', $desde)
            ->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->orderByRaw('NULL')->pluck('n', 'd');
        $resueltos = DB::table('tickets')->where('channel', '!=', 'cron')->whereNull('merged_into_id')
            ->whereNotNull('resolved_at')->where('resolved_at', '>=', $desde)
            ->selectRaw('DATE(resolved_at) d, COUNT(*) n')->groupBy('d')->orderByRaw('NULL')->pluck('n', 'd');

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
