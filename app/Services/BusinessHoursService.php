<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calculadora de HORAS LABORABLES. Es el motor del SLA.
 *
 * Suma (o mide) tiempo saltándose noches, fines de semana y festivos, para que un
 * plazo de «4 horas» signifique 4 horas de atención real y no 4 horas de reloj de
 * pared. Un ticket que entra un viernes a las 19:00 con 4 h de plazo no vence el
 * viernes a las 23:00: vence el lunes a media mañana.
 *
 * RED DE SEGURIDAD: si no hay horario configurado, se cuenta a horas naturales
 * (24/7). Es preferible un plazo demasiado exigente a que el SLA deje de calcular.
 */
class BusinessHoursService
{
    /** Tramos por día de la semana (1=lunes … 7=domingo). Se memoiza por petición. */
    protected ?array $tramos = null;
    protected ?array $festivos = null;

    /** Tope de días a recorrer: evita bucles infinitos si el horario quedara vacío. */
    protected const MAX_DIAS = 400;

    public function tramos(): array
    {
        if ($this->tramos !== null) return $this->tramos;

        $out = [];
        foreach (DB::table('business_hours')->orderBy('weekday')->orderBy('opens')->get() as $r) {
            $out[(int) $r->weekday][] = [substr((string) $r->opens, 0, 5), substr((string) $r->closes, 0, 5)];
        }
        return $this->tramos = $out;
    }

    /** Festivos como ['Y-m-d' => true]. */
    public function festivos(): array
    {
        return $this->festivos ??= DB::table('holidays')->pluck('date')
            ->mapWithKeys(fn ($d) => [substr((string) $d, 0, 10) => true])->all();
    }

    public function configurado(): bool
    {
        return $this->tramos() !== [];
    }

    public function olvidarCache(): void
    {
        $this->tramos = null;
        $this->festivos = null;
    }

    /**
     * Tramos laborables de un DÍA concreto, como pares de Carbon [inicio, fin].
     * Vacío si es festivo o no se trabaja ese día.
     *
     * Los tramos se FUSIONAN si se solapan o se tocan. Es imprescindible: si se
     * configuran los dos turnos (7-15 y 13-21), esas dos horas comunes se contarían
     * dos veces al medir tiempo consumido. Fusionados quedan en un 7-21 correcto.
     */
    public function tramosDelDia(Carbon $dia): array
    {
        if (isset($this->festivos()[$dia->format('Y-m-d')])) return [];

        $crudos = [];
        foreach ($this->tramos()[$dia->dayOfWeekIso] ?? [] as [$abre, $cierra]) {
            $ini = $dia->copy()->setTimeFromTimeString($abre);
            $fin = $dia->copy()->setTimeFromTimeString($cierra);
            if ($fin->greaterThan($ini)) $crudos[] = [$ini, $fin];
        }
        if (!$crudos) return [];

        usort($crudos, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $out = [array_shift($crudos)];
        foreach ($crudos as [$ini, $fin]) {
            $ultimo = &$out[count($out) - 1];
            if ($ini->lessThanOrEqualTo($ultimo[1])) {           // solapa o pega
                if ($fin->greaterThan($ultimo[1])) $ultimo[1] = $fin;
            } else {
                $out[] = [$ini, $fin];
            }
            unset($ultimo);
        }
        return $out;
    }

    /** ¿Se está atendiendo en este instante? */
    public function abierto(?Carbon $cuando = null): bool
    {
        $c = ($cuando ?? now())->copy();
        if (!$this->configurado()) return true;

        foreach ($this->tramosDelDia($c) as [$ini, $fin]) {
            if ($c->betweenIncluded($ini, $fin)) return true;
        }
        return false;
    }

    /**
     * Fecha límite tras sumar $horas LABORABLES a partir de $desde.
     * Si $desde cae fuera de horario, el reloj empieza en la siguiente apertura.
     */
    public function limite(Carbon $desde, float $horas): Carbon
    {
        $cursor = $desde->copy();
        if (!$this->configurado()) return $cursor->addMinutes((int) round($horas * 60));

        $restan = (int) round($horas * 60);   // en minutos, para no arrastrar decimales
        if ($restan <= 0) return $cursor;

        for ($i = 0; $i < self::MAX_DIAS; $i++) {
            foreach ($this->tramosDelDia($cursor) as [$ini, $fin]) {
                if ($cursor->greaterThanOrEqualTo($fin)) continue;          // tramo ya pasado
                $arranque = $cursor->lessThan($ini) ? $ini->copy() : $cursor->copy();

                $disponible = $arranque->diffInMinutes($fin);
                if ($disponible >= $restan) return $arranque->addMinutes($restan);

                $restan -= $disponible;
                $cursor  = $fin->copy();
            }
            // Se acabó el día: al principio del siguiente.
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $cursor;   // horario tan raro que no cabe: se devuelve lo alcanzado
    }

    /**
     * Minutos LABORABLES transcurridos entre dos instantes (para medir lo consumido).
     */
    public function minutosEntre(Carbon $desde, Carbon $hasta): int
    {
        if ($hasta->lessThanOrEqualTo($desde)) return 0;
        if (!$this->configurado()) return (int) $desde->diffInMinutes($hasta);

        $total  = 0;
        $cursor = $desde->copy();

        for ($i = 0; $i < self::MAX_DIAS && $cursor->lessThan($hasta); $i++) {
            foreach ($this->tramosDelDia($cursor) as [$ini, $fin]) {
                $a = $cursor->greaterThan($ini) ? $cursor : $ini;
                $b = $hasta->lessThan($fin) ? $hasta : $fin;
                if ($b->greaterThan($a)) $total += (int) $a->diffInMinutes($b);
            }
            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $total;
    }
}
