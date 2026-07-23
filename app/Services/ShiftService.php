<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CUADRANTE DE TURNOS: quién cubre soporte en cada momento.
 *
 * Dos turnos que se SOLAPAN (mañana 07-15, tarde 13-21). En la franja común manda
 * el de TARDE: acaba de entrar y tiene la jornada por delante, mientras el de
 * mañana está cerrando lo suyo.
 *
 * Las sustituciones (`shift_overrides`) pisan al titular de la semana mientras dura
 * el periodo, que va de un día suelto a una semana entera.
 *
 * OJO: esto es distinto del HORARIO DE ATENCIÓN (ver BusinessHoursService). Aquel
 * es el compromiso con el cliente y mueve el reloj del SLA; este solo dice a quién
 * se le asigna. Se mantienen separados a propósito: olvidar rellenar el cuadrante
 * no debe parar el SLA.
 */
class ShiftService
{
    public const TURNOS = ['morning' => 'Mañana', 'afternoon' => 'Tarde'];

    /** Horas ya leídas en esta petición (ver horas()). */
    protected array $cacheHoras = [];

    /**
     * Horas de cada turno, configurables. Por defecto las reales: 07-15 y 13-21.
     *
     * Se recuerdan durante la petición: pintar el cuadrante pregunta por el horario
     * una y otra vez (por cada semana y turno), y era una consulta cada vez para
     * releer el mismo par de ajustes.
     */
    public function horas(string $turno): array
    {
        if (isset($this->cacheHoras[$turno])) return $this->cacheHoras[$turno];

        $def = $turno === 'morning' ? '07:00-15:00' : '13:00-21:00';
        $v   = (string) Setting::get('shift_' . $turno, $def);
        [$ini, $fin] = array_pad(explode('-', $v, 2), 2, null);

        return $this->cacheHoras[$turno] = [
            trim((string) $ini) ?: substr($def, 0, 5),
            trim((string) $fin) ?: substr($def, 6, 5),
        ];
    }

    /** Lunes de la semana de una fecha. */
    public function lunes(Carbon $d): string
    {
        return $d->copy()->startOfWeek()->format('Y-m-d');
    }

    /**
     * Turnos activos en un instante, del MÁS PRIORITARIO al menos.
     * En el solape la tarde va primero (es quien se queda con lo nuevo).
     */
    public function turnosEn(Carbon $cuando): array
    {
        $hm = $cuando->format('H:i');
        $activos = [];

        foreach (['afternoon', 'morning'] as $t) {   // tarde primero: manda en el solape
            [$ini, $fin] = $this->horas($t);
            if ($hm >= $ini && $hm < $fin) $activos[] = $t;
        }
        return $activos;
    }

    /**
     * QUIÉN CUBRE un turno un día concreto. Puede ser MÁS DE UNO.
     *
     * Se parte de los titulares de la semana y se aplican las sustituciones de ese
     * día: cada sustitución o releva a una persona concreta (`replaces_user_id`) o,
     * si no lo dice, cubre el turno entero y los titulares no entran. Lo segundo es
     * el comportamiento de siempre y el que tiene sentido con un único titular.
     *
     * Devuelve [['user_id','name','substitute','replaces'], …] en orden estable.
     */
    public function cubren(string $turno, ?Carbon $cuando = null): array
    {
        $dia = ($cuando ?? now())->copy()->format('Y-m-d');

        $titulares = DB::table('shifts as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.week_start', $this->lunes(Carbon::parse($dia)))->where('s.shift', $turno)
            ->orderBy('s.id')->get(['u.id', 'u.name']);

        $subs = DB::table('shift_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'o.replaces_user_id')
            ->where('o.shift', $turno)
            ->where('o.date_from', '<=', $dia)->where('o.date_to', '>=', $dia)
            ->orderBy('o.id')->get(['u.id', 'u.name', 'o.replaces_user_id', 'r.name as replaces_name']);

        if ($subs->isEmpty()) {
            return $titulares->map(fn ($t) => [
                'user_id' => (int) $t->id, 'name' => $t->name, 'substitute' => false, 'replaces' => null,
            ])->all();
        }

        // A quién relevan: si alguna sustitución no lo dice, se van todos los titulares.
        $general = $subs->contains(fn ($s) => $s->replaces_user_id === null);
        $fuera   = $general ? $titulares->pluck('id')->all() : $subs->pluck('replaces_user_id')->filter()->all();

        $gente = [];
        foreach ($subs as $s) {
            $gente[(int) $s->id] = [
                'user_id' => (int) $s->id, 'name' => $s->name, 'substitute' => true,
                'replaces' => $s->replaces_name ?? ($general ? $titulares->pluck('name')->join(' / ') ?: null : null),
            ];
        }
        foreach ($titulares as $t) {
            if (in_array((int) $t->id, array_map('intval', $fuera), true)) continue;
            $gente[(int) $t->id] ??= ['user_id' => (int) $t->id, 'name' => $t->name, 'substitute' => false, 'replaces' => null];
        }

        return array_values($gente);
    }

    /**
     * Quién está de guardia AHORA: ['user_id','name','shift','substitute','equipo'] o null.
     * Devuelve null fuera de horario, en fin de semana o si esa semana no está cubierta.
     *
     * Si el turno lo cubren varios, `user_id` es a quien le toca el siguiente ticket
     * (ver repartir()) y `equipo` son todos, para poder enseñarlos.
     */
    public function deGuardia(?Carbon $cuando = null): ?array
    {
        $c = ($cuando ?? now())->copy();

        foreach ($this->turnosEn($c) as $turno) {
            $gente = $this->cubren($turno, $c);
            if (!$gente) continue;

            $elegido = $this->repartir($gente);

            return [
                'user_id'    => $elegido['user_id'],
                'name'       => $elegido['name'],
                'shift'      => $turno,
                'substitute' => $elegido['substitute'],
                'equipo'     => $gente,
            ];
        }

        return null;   // sin cobertura: el ticket se queda sin asignar
    }

    /**
     * A QUIÉN LE TOCA cuando el turno lo cubren varios.
     *
     * Se reparte ALTERNANDO (decisión del usuario, 22-jul-2026): si hay dos es
     * porque hay carga, y dárselo todo a uno anula el motivo de ponerlos juntos.
     *
     * El «turno de cada uno» no se guarda en ningún contador: se mira quién lleva
     * MÁS TIEMPO sin que le caiga un ticket, según el historial de asignaciones.
     * Sale lo mismo que un contador y además se arregla solo — cuenta también las
     * asignaciones a mano, y quien se incorpora a media semana entra el primero en
     * vez de heredar el contador de otro.
     */
    public function repartir(array $gente): array
    {
        if (count($gente) < 2) return $gente[0];

        $ids = array_column($gente, 'user_id');

        /*
         * Solo los últimos 90 días: el histórico entero no aporta nada (lo que importa
         * es el turno de ahora) y así la consulta no crece con los años. Quien no
         * aparezca lleva más de tres meses sin que le caiga nada, y entra el primero.
         */
        $reciente = DB::table('ticket_events')
            ->where('type', 'assign')->whereIn('to_value', array_map('strval', $ids))
            ->where('created_at', '>=', now()->subDays(90))
            ->groupBy('to_value')
            ->select('to_value', DB::raw('MAX(created_at) as ult'), DB::raw('COUNT(*) as n'))
            ->get()->keyBy('to_value');

        /*
         * Ordena por «hace más que no le toca», y a igualdad por quién lleva menos.
         * Ese segundo criterio no es adorno: `created_at` va al segundo, y el cron de
         * correo importa varios mensajes dentro del mismo segundo. Solo con la fecha,
         * toda la ráfaga empataba y se la comía siempre el mismo.
         */
        $peso = function (array $p) use ($reciente) {
            $r = $reciente[(string) $p['user_id']] ?? null;
            return [$r->ult ?? '', (int) ($r->n ?? 0), $p['user_id']];
        };

        usort($gente, fn ($a, $b) => $peso($a) <=> $peso($b));

        return $gente[0];
    }

    /**
     * Asigna un ticket recién creado al agente de guardia, SI procede.
     * Solo actúa cuando: el ticket no tiene ya responsable (una regla manda más que
     * el turno) y su categoría está marcada para repartirse por turno.
     * Devuelve el id del agente asignado, o null.
     */
    public function asignarSiProcede(int $ticketId): ?int
    {
        try {
            $t = DB::table('tickets as t')->leftJoin('ticket_categories as c', 'c.id', '=', 't.category_id')
                ->where('t.id', $ticketId)->first(['t.assigned_to', 'c.use_shift']);

            if (!$t || $t->assigned_to) return null;      // ya tiene responsable
            if (!$t->use_shift) return null;              // esta categoría no rota (p. ej. facturas)

            $guardia = $this->deGuardia();
            if (!$guardia) return null;                   // fuera de horario o semana sin cubrir

            app(TicketService::class)->assign($ticketId, $guardia['user_id']);
            return $guardia['user_id'];
        } catch (\Throwable $e) {
            // Repartir es una comodidad: si falla, el ticket se queda sin asignar y punto.
            report($e);
            return null;
        }
    }
}
