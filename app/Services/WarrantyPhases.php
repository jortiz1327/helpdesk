<?php

namespace App\Services;

use App\Models\Warranty;
use Illuminate\Support\Facades\DB;

/**
 * Tiempo en cada fase (estado) de las garantías.
 *   · Ahora: cuántos días lleva cada garantía abierta en su estado, y si pasa de los días
 *     de aviso o de crítico de la regla que vigila ese estado (la suya o «Fase atascada»).
 *   · Media: lo que duraron las estancias ya terminadas en cada estado, sacado del
 *     historial de cambios de estado (warranty_events guarda los nombres, no las claves).
 * El periodo filtra las estancias por la fecha en que terminaron.
 */
class WarrantyPhases
{
    public static function report(?string $from = null, ?string $to = null): array
    {
        $estados = Warranty::statusRows();
        $porNombre = [];
        foreach ($estados as $k => $s) $porNombre[mb_strtolower($s['label'])] = $k;
        $clave = fn ($v) => $v === null ? null : ($porNombre[mb_strtolower(trim((string) $v))] ?? (isset($estados[$v]) ? $v : null));
        $cerrados = Warranty::closedStatuses();
        $reglas = (new WarrantyAlerts)->rules();
        $limite = function (string $st) use ($reglas) {
            $k = WarrantyAlerts::ruleForStatus($st);
            $r = $reglas[$k] ?? null;
            return $r && $r['active'] ? ['rule' => $k, 'warn' => $r['warn_days'], 'crit' => $r['crit_days']] : ['rule' => $k, 'warn' => null, 'crit' => null];
        };
        $dias = fn ($a, $b) => max(0, (strtotime((string) $b) - strtotime((string) $a)) / 86400);

        // Estancias terminadas: de un cambio de estado (o del alta) al siguiente.
        $altas = DB::table('warranties')->pluck('created_at', 'id');
        $estancias = [];
        foreach (DB::table('warranty_events')->where('field', 'status')->orderBy('warranty_id')->orderBy('created_at')->orderBy('id')
            ->get(['warranty_id', 'from_value', 'created_at'])->groupBy('warranty_id') as $wid => $evs) {
            $inicio = $altas[$wid] ?? null;
            foreach ($evs as $e) {
                $st = $clave($e->from_value);
                $fin = substr((string) $e->created_at, 0, 10);
                if ($st && $inicio && (! $from || $fin >= $from) && (! $to || $fin <= $to)) $estancias[$st][] = $dias($inicio, $e->created_at);
                $inicio = $e->created_at;
            }
        }

        // Las abiertas, con lo que llevan en su estado.
        $abiertas = DB::table('warranties as w')->leftJoin('users as u', 'u.id', '=', 'w.responsible_id')
            ->whereNotIn('w.status', $cerrados)
            ->get(['w.id', 'w.code', 'w.client_name', 'w.status', 'w.urgency', 'w.status_changed_at', 'w.created_at', 'u.name as responsible'])
            ->map(function ($w) use ($limite, $dias, $estados) {
                $l = $limite((string) $w->status);
                $d = (int) floor($dias($w->status_changed_at ?? $w->created_at, now()));
                return ['id' => $w->id, 'code' => $w->code, 'client' => $w->client_name, 'status' => $w->status,
                    'status_label' => $estados[$w->status]['label'] ?? $w->status, 'color' => $estados[$w->status]['color'] ?? null,
                    'responsible' => $w->responsible, 'urgency' => $w->urgency, 'days' => $d,
                    'level' => $l['crit'] !== null && $d >= $l['crit'] ? 'crit' : ($l['warn'] !== null && $d >= $l['warn'] ? 'warn' : null)];
            });

        $mediana = function (array $v) {
            if (! $v) return null;
            sort($v);
            $n = count($v);
            return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
        };
        $fases = [];
        foreach ($estados as $k => $s) {
            if ($s['closed']) continue;
            $ahora = $abiertas->where('status', $k);
            $hechas = $estancias[$k] ?? [];
            if (! $s['active'] && ! $ahora->count() && ! $hechas) continue;
            $fases[] = ['key' => $k, 'label' => $s['label'], 'color' => $s['color'], 'limit' => $limite($k),
                'avg' => $hechas ? round(array_sum($hechas) / count($hechas), 1) : null, 'median' => $mediana($hechas), 'stays' => count($hechas),
                'now' => $ahora->count(), 'now_avg' => $ahora->count() ? round($ahora->avg('days'), 1) : null, 'now_max' => $ahora->max('days'),
                'warn' => $ahora->where('level', 'warn')->count(), 'crit' => $ahora->where('level', 'crit')->count()];
        }

        // Del alta al cierre, de las cerradas en el periodo.
        $cq = DB::table('warranties')->whereIn('status', $cerrados)->whereNotNull('closed_at');
        if ($from) $cq->where('closed_at', '>=', $from);
        if ($to) $cq->where('closed_at', '<=', $to . ' 23:59:59');
        $ciclo = $cq->get(['created_at', 'closed_at'])->map(fn ($w) => $dias($w->created_at, $w->closed_at))->all();

        return [
            'kpis' => [
                'open' => $abiertas->count(), 'warn' => $abiertas->where('level', 'warn')->count(), 'crit' => $abiertas->where('level', 'crit')->count(),
                'median_now' => $mediana($abiertas->pluck('days')->all()),
                'cycle_avg' => $ciclo ? round(array_sum($ciclo) / count($ciclo), 1) : null, 'cycle_median' => $mediana($ciclo), 'closed' => count($ciclo),
            ],
            'phases' => $fases,
            'oldest' => $abiertas->sortByDesc('days')->values()->take(15)->all(),
        ];
    }
}
