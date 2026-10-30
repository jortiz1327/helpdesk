<?php

namespace App\Services;

use App\Models\Warranty;
use Illuminate\Support\Facades\DB;

/**
 * Alertas de las garantías abiertas según las reglas de la configuración: cada regla
 * tiene días de aviso y de crítico (las «al momento», como muy urgente o sin
 * responsable, son críticas siempre). Se evalúan sobre las filas ya cargadas (alerts())
 * o como filtro SQL (scope()) para el chip «Con alertas» y la vista del equipo.
 */
class WarrantyAlerts
{
    // key => [nombre, descripción, estado del que depende (null = otra cosa), al momento]
    public const RULES = [
        'cliente'         => ['Cliente',         'Pendiente de cliente demasiados días',          'pte_cliente', false],
        'muy_urgente'     => ['Muy urgente',     'Garantía muy urgente sin cerrar',               null,          true],
        'zkong'           => ['ZKONG',           'Pendiente de ZKONG demasiados días',            'pte_zkong',   false],
        'envio'           => ['Envío',           'Pendiente de envío demasiados días',            'pte_envio',   false],
        'sin_responsable' => ['Sin responsable', 'Garantía abierta sin responsable',              null,          true],
        'stock'           => ['Stock',           'En «Falta stock» demasiados días',              'falta_stock', false],
        'inactividad'     => ['Inactividad',     'Sin ningún movimiento demasiados días',         null,          false],
        'rma'             => ['RMA',             'Remesa enviada a ZKONG sin respuesta',          null,          false],
    ];

    private ?array $reglas = null;

    /** Reglas guardadas: [key => {active, warn_days, crit_days}]. */
    public function rules(): array
    {
        return $this->reglas ??= DB::table('warranty_alert_rules')->get()
            ->mapWithKeys(fn ($r) => [$r->key => ['active' => (bool) $r->active, 'warn_days' => (int) $r->warn_days, 'crit_days' => (int) $r->crit_days]])
            ->all();
    }

    private function activas(): array
    {
        return array_filter($this->rules(), fn ($r, $k) => $r['active'] && isset(self::RULES[$k]), ARRAY_FILTER_USE_BOTH);
    }

    /** Desde cuándo espera cada garantía la respuesta de ZKONG a una remesa enviada. */
    private function rmaDesde(array $ids): array
    {
        if (! $ids) return [];
        return DB::table('warranty_items as i')->join('zkong_batches as b', 'b.id', '=', 'i.zkong_batch_id')
            ->whereIn('i.warranty_id', $ids)->where('b.status', 'enviada')->whereNull('i.zkong_result')
            ->groupBy('i.warranty_id')->selectRaw('i.warranty_id, min(b.sent_at) as desde')->pluck('desde', 'warranty_id')->all();
    }

    /**
     * Alertas de cada garantía: [warranty_id => [{key, level: warn|crit, text}]], la más
     * grave primero. Las filas necesitan id, status, urgency, responsible_id,
     * last_activity_at, status_changed_at y created_at.
     */
    public function alerts(iterable $rows): array
    {
        $rows = collect($rows);
        $rma = isset($this->activas()['rma']) ? $this->rmaDesde($rows->pluck('id')->all()) : [];
        $cerrados = Warranty::closedStatuses();
        $out = [];
        foreach ($rows as $w) {
            if (in_array($w->status, $cerrados, true)) continue;
            $lista = [];
            foreach ($this->activas() as $k => $r) {
                [$nombre, , $estado, $alMomento] = self::RULES[$k];
                $desde = match (true) {
                    $estado !== null    => $w->status === $estado ? ($w->status_changed_at ?? $w->created_at) : null,
                    $k === 'muy_urgente' => $w->urgency === 'muy_urgente' ? true : null,
                    $k === 'sin_responsable' => $w->responsible_id ? null : true,
                    $k === 'inactividad' => $w->last_activity_at ?? $w->created_at,
                    $k === 'rma'         => $rma[$w->id] ?? null,
                };
                if ($desde === null) continue;
                if ($alMomento) {
                    $lista[] = ['key' => $k, 'level' => 'crit', 'text' => $nombre];
                    continue;
                }
                $dias = (int) floor((time() - strtotime((string) $desde)) / 86400);
                $nivel = $dias >= $r['crit_days'] ? 'crit' : ($dias >= $r['warn_days'] ? 'warn' : null);
                if ($nivel) $lista[] = ['key' => $k, 'level' => $nivel, 'text' => "{$nombre}: {$dias} " . ($dias === 1 ? 'día' : 'días')];
            }
            usort($lista, fn ($a, $b) => ($a['level'] === 'crit' ? 0 : 1) <=> ($b['level'] === 'crit' ? 0 : 1));
            if ($lista) $out[$w->id] = $lista;
        }
        return $out;
    }

    /** Filtra la consulta (sobre «warranties») a las garantías con alguna alerta de ese nivel o más. */
    public function scope($q, string $level = 'warn'): void
    {
        $reglas = $this->activas();
        $q->whereNotIn('warranties.status', Warranty::closedStatuses())->where(function ($o) use ($reglas, $level) {
            $o->whereRaw('1 = 0');
            foreach ($reglas as $k => $r) {
                [, , $estado] = self::RULES[$k];
                $hasta = now()->subDays($level === 'crit' ? $r['crit_days'] : $r['warn_days']);
                match (true) {
                    $estado !== null         => $o->orWhere(fn ($w) => $w->where('warranties.status', $estado)
                        ->whereRaw('coalesce(warranties.status_changed_at, warranties.created_at) <= ?', [$hasta])),
                    $k === 'muy_urgente'     => $o->orWhere('warranties.urgency', 'muy_urgente'),
                    $k === 'sin_responsable' => $o->orWhereNull('warranties.responsible_id'),
                    $k === 'inactividad'     => $o->orWhereRaw('coalesce(warranties.last_activity_at, warranties.created_at) <= ?', [$hasta]),
                    $k === 'rma'             => $o->orWhereExists(fn ($e) => $e->selectRaw('1')->from('warranty_items as ai')
                        ->join('zkong_batches as ab', 'ab.id', '=', 'ai.zkong_batch_id')
                        ->whereColumn('ai.warranty_id', 'warranties.id')->where('ab.status', 'enviada')
                        ->whereNull('ai.zkong_result')->where('ab.sent_at', '<=', $hasta)),
                };
            }
        });
    }
}
