<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Estado del SLA de un ticket. Dos relojes independientes:
 *
 *   · RESPUESTA  — desde que se abre hasta la primera contestación del agente.
 *                  Para el reloj en cuanto se responde, aunque el ticket siga vivo.
 *   · RESOLUCIÓN — desde que se abre hasta que queda resuelto o cerrado.
 *
 * El plazo de cada reloj sale de la PRIORIDAD (en minutos) y, si esa prioridad no
 * tiene plazo propio, de la CATEGORÍA (en horas). Se cuentan en horas laborables
 * (ver [[BusinessHoursService]]): un plazo de 4 h no vence de madrugada, y uno de
 * 15 min de un caso grave se mide igual sobre el horario de atención.
 *
 * Un ticket sin plazo por ninguna de las dos vías simplemente no tiene SLA: no se
 * inventa uno.
 */
class SlaService
{
    /** Se avisa («por vencer») cuando queda este porcentaje del plazo o menos. */
    protected const AVISO_DESDE = 0.25;

    public function __construct(protected BusinessHoursService $horario) {}

    /**
     * Estado de los dos relojes de un ticket.
     * $t necesita: opened_at/created_at, first_response_at, resolved_at, closed_at,
     * status y los plazos de su categoría (sla_response_hours / sla_resolve_hours).
     *
     * Devuelve ['response' => …, 'resolve' => …], cada uno null (sin plazo) o:
     *   ['state' => ok|warn|late|met|missed, 'due' => ISO, 'minutes_left' => int]
     */
    public function forTicket(object $t): array
    {
        // Interruptor global: apagarlo no borra las horas de las categorías.
        if (!self::activo()) return ['response' => null, 'resolve' => null];

        $inicio = $this->aCarbon($t->opened_at ?? $t->created_at ?? null);
        if (!$inicio) return ['response' => null, 'resolve' => null];

        $pausado = $this->minutosEnPausa($t);

        return [
            'response' => $this->reloj(
                $inicio,
                $this->plazoMinutos($t->pri_response_mins ?? null, $t->sla_response_hours ?? null),
                $this->aCarbon($t->first_response_at ?? null),
                $pausado,
            ),
            'resolve' => $this->reloj(
                $inicio,
                $this->plazoMinutos($t->pri_resolve_mins ?? null, $t->sla_resolve_hours ?? null),
                $this->aCarbon($t->resolved_at ?? null) ?: $this->aCarbon($t->closed_at ?? null),
                $pausado,
            ),
        ];
    }

    /**
     * Plazo efectivo de un reloj, en MINUTOS. La PRIORIDAD manda: si trae plazo propio
     * (en minutos) se usa ese; si no, se cae al de la CATEGORÍA (que va en horas). Así
     * un caso grave puede exigir respuesta en minutos y lo ya configurado por categoría
     * sigue valiendo para el resto.
     */
    protected function plazoMinutos($prioMin, $catHoras): int
    {
        $p = (int) ($prioMin ?? 0);
        if ($p > 0) return $p;
        return (int) ($catHoras ?? 0) * 60;
    }

    /** ¿Está encendido el SLA? Se consulta mucho, así que se recuerda en la petición. */
    public static function activo(): bool
    {
        static $v = null;
        return $v ??= (string) \App\Models\Setting::get('sla_active', '1') === '1';
    }

    /**
     * Minutos laborables que el ticket ha estado en pausa: los acumulados más, si
     * ahora mismo sigue parado, lo que lleva parado.
     */
    protected function minutosEnPausa(object $t): int
    {
        $mins = (int) ($t->sla_paused_minutes ?? 0);

        if ($desde = $this->aCarbon($t->sla_paused_since ?? null)) {
            $mins += max(0, $this->horario->minutosEntre($desde, now()));
        }
        return $mins;
    }

    /**
     * Un reloj. $cumplido es el instante en que se paró (respondido / resuelto),
     * o null si sigue corriendo.
     *
     *   met    → se cumplió a tiempo          missed → se cumplió tarde
     *   ok     → corriendo, con margen        warn   → corriendo, queda poco
     *   late   → corriendo y ya vencido
     */
    protected function reloj(Carbon $inicio, $minutos, ?Carbon $cumplido, int $pausado = 0): ?array
    {
        $minutos = (int) ($minutos ?? 0);
        if ($minutos <= 0) return null;   // sin plazo configurado: no hay SLA

        /*
         * El vencimiento se corre hacia adelante tanto como haya estado el reloj
         * parado. Se suma en el propio cálculo de horario laborable —no como días
         * naturales— para que la noche y el fin de semana no se cuenten dos veces.
         * El motor de horario razona en horas, así que los minutos se pasan a horas.
         */
        $vence = $this->horario->limite($inicio->copy(), ($minutos + $pausado) / 60);

        if ($cumplido) {
            return [
                'state' => $cumplido->lessThanOrEqualTo($vence) ? 'met' : 'missed',
                'due'   => $vence->toIso8601String(),
                'at'    => $cumplido->toIso8601String(),
                'minutes_left' => 0,
            ];
        }

        // Sigue corriendo: lo que queda se mide también en horas laborables.
        $ahora = now();
        $vencido = $ahora->greaterThan($vence);
        $restan  = $vencido ? -$this->horario->minutosEntre($vence, $ahora)
                            :  $this->horario->minutosEntre($ahora, $vence);

        $umbral = (int) round($minutos * self::AVISO_DESDE);

        return [
            'state' => $vencido ? 'late' : ($restan <= $umbral ? 'warn' : 'ok'),
            'due'   => $vence->toIso8601String(),
            'minutes_left' => $restan,
        ];
    }

    protected function aCarbon($v): ?Carbon
    {
        if (!$v) return null;
        return $v instanceof Carbon ? $v : Carbon::parse($v);
    }
}
