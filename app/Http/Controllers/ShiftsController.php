<?php

namespace App\Http\Controllers;

use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CUADRANTE DE TURNOS. Sustituye al Excel de soporte.
 *
 * Ver el cuadrante lo puede hacer cualquiera del helpdesk (a todos les interesa
 * saber quién está de guardia); EDITARLO requiere support.config, porque es cosa
 * del encargado.
 */
class ShiftsController extends Controller
{
    public function handle(Request $request)
    {
        $accion = $request->query('action', 'get');

        if ($accion !== 'get' && !$request->user()->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Solo el encargado puede tocar el cuadrante'], 403);
        }

        return match ($accion) {
            'assign'   => $this->assign($request),
            'override' => $this->override($request),
            'rotate'   => $this->rotate($request),
            // Misma cuenta pero sin escribir: para enseñar cómo quedaría y qué pisaría.
            'rotate_preview' => $this->rotate($request, true),
            'note'     => $this->note($request),
            'month'    => $this->month($request),
            default    => $this->get($request),
        };
    }

    protected function get(Request $request)
    {
        $svc = app(ShiftService::class);

        // Ventana de semanas: por defecto arranca en la semana actual.
        $desde   = $this->lunesDe($request->query('from')) ?: Carbon::now()->startOfWeek();
        $cuantas = max(1, min(26, (int) $request->query('weeks', 8)));

        $ini = $desde->copy();
        $fin = $desde->copy()->addWeeks($cuantas - 1)->endOfWeek();

        // Titulares y sustituciones de toda la ventana, en dos consultas.
        $titulares = DB::table('shifts')->whereBetween('week_start', [$ini->format('Y-m-d'), $fin->format('Y-m-d')])
            ->get()->groupBy(fn ($r) => $r->week_start . '|' . $r->shift);

        // Una sustitución entra si SOLAPA con la ventana, no solo si empieza dentro.
        $subs = DB::table('shift_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.date_from', '<=', $fin->format('Y-m-d'))
            ->where('o.date_to', '>=', $ini->format('Y-m-d'))
            ->orderBy('o.date_from')
            ->get(['o.id', 'o.date_from', 'o.date_to', 'o.shift', 'o.user_id', 'o.notes', 'u.name as user_name']);

        $semanas = [];
        for ($i = 0; $i < $cuantas; $i++) {
            $lunes  = $desde->copy()->addWeeks($i);
            $viernes = $lunes->copy()->addDays(4);
            $clave  = $lunes->format('Y-m-d');

            $finSemana = $lunes->copy()->addDays(6)->format('Y-m-d');

            $turnos = [];
            foreach (array_keys(ShiftService::TURNOS) as $t) {
                $filas = $titulares->get($clave . '|' . $t) ?? collect();
                $fila  = $filas->first();
                $turnos[$t] = [
                    'user_id'  => $fila ? (int) $fila->user_id : null,
                    'user_ids' => $filas->pluck('user_id')->map('intval')->values()->all(),
                    'notes'    => $fila->notes ?? null,
                    // Sustituciones que TOCAN esta semana, para pintarlas en la celda.
                    'overrides' => $subs->filter(fn ($s) => $s->shift === $t
                        && $s->date_from <= $finSemana && $s->date_to >= $clave)
                        ->map(fn ($s) => [
                            'id'        => $s->id,
                            'date_from' => $s->date_from,
                            'date_to'   => $s->date_to,
                            'user_id'   => (int) $s->user_id,
                            'user_name' => $s->user_name,
                            'notes'     => $s->notes,
                            // «Vie» o «Lun–Mié», recortado a los días que caen en ESTA semana.
                            'dias'      => $this->diasLabel(max($s->date_from, $clave), min($s->date_to, $finSemana)),
                            'completo'  => $this->rangoLabel($s->date_from, $s->date_to),
                        ])->values(),
                ];
            }

            $semanas[] = [
                'week_start' => $clave,
                'label'      => $this->etiqueta($lunes, $viernes),
                'current'    => $lunes->isSameWeek(Carbon::now()),
                'past'       => $viernes->isBefore(Carbon::now()->startOfDay()),
                'shifts'     => $turnos,
            ];
        }

        return response()->json([
            'weeks'    => $semanas,
            'from'     => $desde->format('Y-m-d'),
            'shifts'   => ShiftService::TURNOS,
            'hours'    => ['morning' => $svc->horas('morning'), 'afternoon' => $svc->horas('afternoon')],
            'on_duty'  => $svc->deGuardia(),
            'agents'   => $this->agentes(),
            // Categorías que se reparten por turno: da contexto de para qué sirve esto.
            'shift_categories' => DB::table('ticket_categories')->where('use_shift', 1)->pluck('name'),
        ]);
    }

    /**
     * EL MES, DÍA A DÍA. Es la vista principal del cuadrante.
     *
     * Por cada día laborable resuelve, para cada turno, quién está: el titular de la
     * semana o el SUSTITUTO si ese día hay uno. Devuelve ambos, porque saber «Juan
     * sustituye a Ian» es justo lo que la versión anterior escondía.
     *
     * Solo se devuelven días LABORABLES (lunes a viernes): el turno es L-V y sacar
     * sábado y domingo solo robaba sitio a los días que importan.
     */
    protected function month(Request $request)
    {
        $svc = app(ShiftService::class);

        $mes = $this->mesDe($request->query('month')) ?: Carbon::now()->startOfMonth();
        $ini = $mes->copy()->startOfMonth();
        $fin = $mes->copy()->endOfMonth();

        // Todo lo del mes de una vez: titulares, sustituciones, notas y festivos.
        // Varias personas por turno: se AGRUPAN, no se indexan por semana+turno.
        $titulares = DB::table('shifts as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->whereBetween('s.week_start', [$ini->copy()->startOfWeek()->format('Y-m-d'), $fin->format('Y-m-d')])
            ->orderBy('s.id')
            ->get(['s.week_start', 's.shift', 's.user_id', 'u.name'])
            ->groupBy(fn ($r) => $r->week_start . '|' . $r->shift);

        $subs = DB::table('shift_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'o.replaces_user_id')
            ->where('o.date_from', '<=', $fin->format('Y-m-d'))
            ->where('o.date_to', '>=', $ini->format('Y-m-d'))
            ->orderBy('o.id')
            ->get(['o.id', 'o.date_from', 'o.date_to', 'o.shift', 'o.user_id', 'o.notes',
                   'o.replaces_user_id', 'u.name', 'r.name as replaces_name']);

        $notas = DB::table('shift_notes')->whereBetween('date', [$ini->format('Y-m-d'), $fin->format('Y-m-d')])
            ->pluck('note', 'date');

        $festivos = DB::table('holidays')->whereBetween('date', [$ini->format('Y-m-d'), $fin->format('Y-m-d')])
            ->pluck('name', 'date');

        $dias = [];
        for ($d = $ini->copy(); $d->lte($fin); $d->addDay()) {
            if ($d->isWeekend()) continue;   // el turno es de lunes a viernes

            $iso    = $d->format('Y-m-d');
            $semana = $svc->lunes($d);
            $turnos = [];

            foreach (array_keys(ShiftService::TURNOS) as $t) {
                $tit  = ($titulares->get($semana . '|' . $t) ?? collect())->values();
                $hoy  = $subs->filter(fn ($s) => $s->shift === $t && $s->date_from <= $iso && $s->date_to >= $iso)->values();

                /*
                 * Quién está ESE día. Una sustitución sin `replaces_user_id` cubre el
                 * turno entero (lo de siempre); si dice a quién releva, solo sale esa
                 * persona y los demás titulares siguen ahí.
                 */
                $general = $hoy->contains(fn ($s) => $s->replaces_user_id === null);
                $fuera   = $general
                    ? $tit->pluck('user_id')->map('intval')->all()
                    : $hoy->pluck('replaces_user_id')->filter()->map('intval')->all();

                $gente = $hoy->map(fn ($s) => [
                    'user_id'    => (int) $s->user_id,
                    'name'       => $s->name,
                    'substitute' => true,
                    // Con qué fila se deshace el cambio, sin tener que buscarla.
                    'override_id' => $s->id,
                    'replaces_id' => $s->replaces_user_id ? (int) $s->replaces_user_id : null,
                    'replaces'   => $s->replaces_name ?? ($general ? ($tit->pluck('name')->join(' / ') ?: null) : null),
                    // El motivo va CON su sustitución: suelto al final del turno
                    // parecía de la otra persona.
                    'reason'     => $s->notes,
                ])->all();

                foreach ($tit as $x) {
                    if (in_array((int) $x->user_id, $fuera, true)) continue;
                    $gente[] = ['user_id' => (int) $x->user_id, 'name' => $x->name, 'substitute' => false, 'replaces' => null];
                }

                $turnos[$t] = [
                    'people'  => $gente,
                    // «Juan / Ian»: ya montado, que es como se lee en la rejilla.
                    'names'   => implode(' / ', array_column($gente, 'name')),
                    'substitute' => (bool) count($hoy),
                    // Los TITULARES de la semana, que lo siguen siendo aunque hoy les
                    // cubra otro. Sin esto, la pantalla decía «sin cubrir».
                    'holders' => $tit->map(fn ($x) => ['user_id' => (int) $x->user_id, 'name' => $x->name])->all(),
                    'holder_names' => $tit->pluck('name')->join(' / '),
                    // A quién se sustituye: sin esto, un cambio no se distingue de un titular.
                    'replaces' => implode(' · ', array_values(array_filter(array_column($gente, 'replaces')))) ?: null,
                    'overrides' => $hoy->map(fn ($s) => [
                        'id' => $s->id, 'user_id' => (int) $s->user_id, 'name' => $s->name,
                        'replaces_id' => $s->replaces_user_id ? (int) $s->replaces_user_id : null,
                        'replaces' => $s->replaces_name, 'reason' => $s->notes,
                    ])->all(),
                    'reason' => $hoy->pluck('notes')->filter()->join(' · ') ?: null,
                ];
            }

            $dias[] = [
                'date'    => $iso,
                'day'     => (int) $d->day,
                'dow'     => (int) $d->dayOfWeekIso,          // 1 = lunes
                'week'    => $semana,
                'today'   => $d->isToday(),
                'past'    => $d->isBefore(Carbon::today()),
                'holiday' => $festivos[$iso] ?? null,
                'note'    => $notas[$iso] ?? null,
                'shifts'  => $turnos,
            ];
        }

        return response()->json([
            'ok'      => true,
            'month'   => $ini->format('Y-m'),
            'label'   => ucfirst($ini->locale('es')->isoFormat('MMMM [de] YYYY')),
            'prev'    => $ini->copy()->subMonth()->format('Y-m'),
            'next'    => $ini->copy()->addMonth()->format('Y-m'),
            'days'    => $dias,
            'shifts'  => ShiftService::TURNOS,
            'hours'   => ['morning' => $svc->horas('morning'), 'afternoon' => $svc->horas('afternoon')],
            'on_duty' => $svc->deGuardia(),
            'agents'  => $this->agentes(),
            'can_edit' => $request->user()->can('support.config'),
            'gaps'     => $this->huecosProximos(),
        ]);
    }

    /**
     * SEMANAS PRÓXIMAS SIN CUBRIR. Se mira SIEMPRE desde hoy, no desde el mes que
     * estés viendo: el problema de olvidarse de rellenar el cuadrante es que nadie
     * se entera hasta que entra un ticket y se queda huérfano. Si el aviso solo
     * saliera al navegar hasta ese mes, no serviría de nada.
     */
    protected function huecosProximos(int $semanas = 8): array
    {
        $lunes = Carbon::now()->startOfWeek();
        $fin   = $lunes->copy()->addWeeks($semanas - 1);

        $puesto = DB::table('shifts')
            ->whereBetween('week_start', [$lunes->format('Y-m-d'), $fin->format('Y-m-d')])
            ->get(['week_start', 'shift'])
            ->groupBy('week_start');

        $huecos = [];
        for ($i = 0; $i < $semanas; $i++) {
            $s = $lunes->copy()->addWeeks($i);
            $tiene = $puesto->get($s->format('Y-m-d'), collect())->pluck('shift')->all();
            $faltan = array_values(array_diff(array_keys(ShiftService::TURNOS), $tiene));

            if (!$faltan) continue;

            $huecos[] = [
                'week_start' => $s->format('Y-m-d'),
                'label'      => $this->etiqueta($s, $s->copy()->addDays(4)),
                'month'      => $s->format('Y-m'),
                'turnos'     => array_map(fn ($t) => ShiftService::TURNOS[$t], $faltan),
                'esta'       => $i === 0,   // la de esta semana urge más
            ];
        }

        return $huecos;
    }

    /** Nota de un DÍA (no de un turno). Vacía = se borra. */
    protected function note(Request $request)
    {
        $fecha = trim((string) $request->input('date'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return response()->json(['ok' => false, 'error' => 'Fecha no válida'], 400);
        }

        $texto = mb_substr(trim((string) $request->input('note')), 0, 300);

        if ($texto === '') {
            DB::table('shift_notes')->where('date', $fecha)->delete();
            return response()->json(['ok' => true, 'note' => null]);
        }

        DB::table('shift_notes')->updateOrInsert(
            ['date' => $fecha],
            ['note' => $texto, 'user_id' => (int) $request->user()->id, 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['ok' => true, 'note' => $texto]);
    }

    /** Normaliza «2026-07» al primer día de ese mes. */
    protected function mesDe($v): ?Carbon
    {
        if (!$v || !preg_match('/^\d{4}-\d{2}$/', trim((string) $v))) return null;
        return Carbon::parse(trim((string) $v) . '-01')->startOfMonth();
    }

    /** Titular de una semana y turno. user_id vacío = dejarlo sin asignar. */
    protected function assign(Request $request)
    {
        $lunes = $this->lunesDe($request->input('week_start'));
        $turno = (string) $request->input('shift');
        if (!$lunes || !isset(ShiftService::TURNOS[$turno])) {
            return response()->json(['ok' => false, 'error' => 'Semana o turno no válidos'], 400);
        }

        /*
         * Un turno lo puede cubrir MÁS DE UNA persona. Llega la lista completa de
         * quién queda en ese turno esa semana (`user_ids`), no un alta suelta: así
         * quitar a alguien es mandar la lista sin él, y no hace falta un borrado
         * aparte que se pueda quedar a medias.
         */
        $ids = collect((array) ($request->input('user_ids') ?? [$request->input('user_id')]))
            ->map(fn ($v) => (int) $v)->filter()->unique()->values();

        foreach ($ids as $uid) {
            if (!$this->esAgente($uid)) {
                return response()->json(['ok' => false, 'error' => 'Ese usuario no puede cubrir soporte'], 400);
            }
        }

        $semana = $lunes->format('Y-m-d');
        $nota   = mb_substr(trim((string) $request->input('notes')), 0, 190) ?: null;

        DB::transaction(function () use ($semana, $turno, $ids, $nota) {
            DB::table('shifts')->where('week_start', $semana)->where('shift', $turno)
                ->whereNotIn('user_id', $ids->all() ?: [0])->delete();

            foreach ($ids as $uid) {
                DB::table('shifts')->updateOrInsert(
                    ['week_start' => $semana, 'shift' => $turno, 'user_id' => $uid],
                    ['notes' => $nota, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        return response()->json(['ok' => true, 'count' => $ids->count()]);
    }

    /**
     * Sustitución en los DÍAS marcados. Sin user_id, se quita.
     *
     * Los días no tienen por qué ir seguidos: «el lunes y el viernes» es tan normal
     * como la semana entera. Se marcan sueltos y aquí se agrupan en tramos seguidos,
     * que es como se guardan (un tramo = una fila).
     */
    protected function override(Request $request)
    {
        if ($id = (int) $request->input('delete_id')) {
            DB::table('shift_overrides')->where('id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        $turno = (string) $request->input('shift');
        $uid   = (int) $request->input('user_id');

        $dias = collect((array) $request->input('days'))
            ->map(fn ($d) => trim((string) $d))
            ->filter(fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))
            ->unique()->sort()->values()->all();

        if (!$dias || !isset(ShiftService::TURNOS[$turno]) || !$uid) {
            return response()->json(['ok' => false, 'error' => 'Marca al menos un día y elige quién lo cubre'], 400);
        }
        if (!$this->esAgente($uid)) {
            return response()->json(['ok' => false, 'error' => 'Ese usuario no puede cubrir soporte'], 400);
        }

        $nota = mb_substr(trim((string) $request->input('notes')), 0, 190) ?: null;
        $recortadas = 0;

        /*
         * A QUIÉN RELEVA. Solo hace falta decirlo cuando el turno lo cubren varios:
         * con un único titular, o sin ninguno, la sustitución cubre el turno entero
         * (vacío) y significa lo mismo que ha significado siempre.
         */
        $releva = (int) $request->input('replaces_user_id') ?: null;

        /*
         * NADIE SE SUSTITUYE A SÍ MISMO. Si en un día esa persona ya es el titular de
         * la semana, poner ahí una sustitución no significa nada: lo que se está
         * diciendo es «ese día lo cubre el de siempre». Así que esos días no crean
         * sustitución — y además se limpia la que hubiera, que es justo lo que el
         * usuario quiere decir al elegir al titular.
         */
        $titulares = DB::table('shifts')->where('shift', $turno)
            ->whereIn('week_start', array_unique(array_map(
                fn ($d) => Carbon::parse($d)->startOfWeek()->format('Y-m-d'), $dias,
            )))
            ->get(['user_id', 'week_start'])
            ->groupBy('week_start')
            ->map(fn ($g) => $g->pluck('user_id')->map('intval')->all());

        $esTitular = fn ($dia) => in_array($uid,
            $titulares[Carbon::parse($dia)->startOfWeek()->format('Y-m-d')] ?? [], true);

        $sobran   = array_values(array_filter($dias, $esTitular));
        $utiles   = array_values(array_filter($dias, fn ($d) => !$esTitular($d)));

        DB::transaction(function () use ($utiles, $sobran, $turno, $uid, $nota, $releva, &$recortadas) {
            // Días en los que ya es el titular: se quita cualquier sustitución previa.
            foreach ($this->tramos($sobran) as [$desde, $hasta]) {
                $recortadas += $this->guardarTramo($desde, $hasta, $turno, null, null, $releva);
            }
            foreach ($this->tramos($utiles) as [$desde, $hasta]) {
                $recortadas += $this->guardarTramo($desde, $hasta, $turno, $uid, $nota, $releva);
            }
        });

        return response()->json(['ok' => true, 'trimmed' => $recortadas]);
    }

    /** Agrupa días sueltos ya ordenados en tramos seguidos: [inicio, fin]. */
    protected function tramos(array $dias): array
    {
        $tramos = [];
        foreach ($dias as $d) {
            $ultimo = count($tramos) - 1;
            if ($ultimo >= 0 && $this->dia($tramos[$ultimo][1], 1) === $d) {
                $tramos[$ultimo][1] = $d;      // pega con el anterior
            } else {
                $tramos[] = [$d, $d];          // empieza uno nuevo
            }
        }
        return $tramos;
    }

    /**
     * Guarda un tramo RECORTANDO lo que se solape en ese turno, en vez de duplicarlo:
     * lo último que dice el encargado manda, pero solo en los días que ha tocado.
     * Poner a Ian el miércoles dentro de la semana de Juan deja a Juan el lunes-martes
     * y el jueves-viernes. Devuelve cuántas sustituciones previas se han ajustado.
     *
     * Solo se recorta lo que ocupa EL MISMO HUECO: las sustituciones que relevan a la
     * misma persona (o, si no se dice a quién, las que tampoco lo dicen). Con dos
     * titulares, cubrir a Juan no puede borrar de un plumazo a quien cubre a Ian.
     */
    protected function guardarTramo(string $desde, string $hasta, string $turno, ?int $uid, ?string $nota, ?int $releva = null): int
    {
        $recortadas = 0;

        $chocan = DB::table('shift_overrides')->where('shift', $turno)
            ->where('date_from', '<=', $hasta)->where('date_to', '>=', $desde)
            ->where(fn ($q) => $releva ? $q->where('replaces_user_id', $releva) : $q->whereNull('replaces_user_id'))
            ->lockForUpdate()->get();

        foreach ($chocan as $o) {
            $recortadas++;
            DB::table('shift_overrides')->where('id', $o->id)->delete();

            // Lo que sobresale por delante y por detrás del tramo nuevo se conserva.
            foreach ([[$o->date_from, $this->dia($desde, -1)], [$this->dia($hasta, 1), $o->date_to]] as [$a, $b]) {
                if ($a <= $b && $a >= $o->date_from && $b <= $o->date_to) {
                    DB::table('shift_overrides')->insert([
                        'date_from' => $a, 'date_to' => $b, 'shift' => $turno,
                        'user_id' => $o->user_id, 'replaces_user_id' => $o->replaces_user_id, 'notes' => $o->notes,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        // Sin persona, el tramo solo servía para LIMPIAR: se recortó y no se inserta.
        if ($uid) {
            DB::table('shift_overrides')->insert([
                'date_from' => $desde, 'date_to' => $hasta, 'shift' => $turno,
                'user_id' => $uid, 'replaces_user_id' => $releva, 'notes' => $nota,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $recortadas;
    }

    /** Un día antes o después de una fecha ISO. */
    protected function dia(string $iso, int $n): string
    {
        return Carbon::parse($iso)->addDays($n)->format('Y-m-d');
    }

    /**
     * GENERAR ROTACIÓN a partir de un PATRÓN que define el usuario.
     *
     * La v1 llevaba el ciclo de AEME cocido en el código (mañana = agentes[i], tarde
     * = agentes[i+1]). El usuario lo rechazó con razón: «estás intuyendo que el que
     * hace tarde la semana que viene hace mañana… ¿y si no es así, o si cambia?».
     *
     * Ahora el patrón es una lista de semanas con SUS DOS TURNOS INDEPENDIENTES, del
     * largo que sea, y se repite hasta la fecha de fin. Cualquier reparto es posible,
     * incluida la misma persona en los dos turnos o un hueco a propósito.
     *
     * `action=rotate_preview` calcula lo mismo pero no escribe: sirve para enseñar
     * cómo quedaría Y QUÉ SEMANAS SE PISARÍAN antes de decidir.
     */
    protected function rotate(Request $request, bool $soloPrevia = false)
    {
        [$plan, $error] = $this->planRotacion($request);
        if ($error) return response()->json(['ok' => false, 'error' => $error], 400);

        $sobrescribir = filter_var($request->input('overwrite', false), FILTER_VALIDATE_BOOLEAN);
        $ocupadas = array_values(array_filter($plan, fn ($s) => $s['ocupada']));

        // Notas y sustituciones que quedarían descuadradas (solo si se va a pisar).
        $revisar = $sobrescribir ? $this->aRevisar($plan) : [];

        if ($soloPrevia) {
            return response()->json([
                'ok'        => true,
                'weeks'     => $plan,
                'conflicts' => array_map(fn ($s) => ['week_start' => $s['week_start'], 'label' => $s['label']], $ocupadas),
                'revisar'   => $revisar,
            ]);
        }

        /*
         * SE PARA AQUÍ si hay cosas anotadas en las semanas que van a cambiar de
         * titular. No es por perderlas —la rotación no borra notas ni sustituciones—
         * sino porque quedan MINTIENDO: comprobado que si el nuevo titular es quien
         * antes sustituía, ese día queda marcado como sustitución sin decir de quién.
         * Que alguien las mire antes es más barato que descubrirlo dentro de un mes.
         */
        if ($revisar) {
            return response()->json([
                'ok'      => false,
                'error'   => 'Hay notas o sustituciones en las semanas que vas a cambiar. Revísalas antes.',
                'revisar' => $revisar,
            ], 409);
        }

        $puestas = 0;
        DB::transaction(function () use ($plan, $sobrescribir, &$puestas) {
            foreach ($plan as $semana) {
                foreach (['morning', 'afternoon'] as $turno) {
                    // Lo que ya estaba puesto a mano solo se toca si se pidió.
                    if ($semana['taken'][$turno] && !$sobrescribir) continue;

                    $uid = $semana['plan'][$turno];

                    /*
                     * La rotación pone UNA persona por turno: es un ciclo. Que un turno
                     * lo cubran dos es un ajuste puntual de una semana concreta, y se
                     * hace desde el calendario. Por eso aquí se borra lo que hubiera y
                     * se pone lo del patrón, en vez de añadirse a lo que ya estaba.
                     */
                    DB::table('shifts')->where('week_start', $semana['week_start'])->where('shift', $turno)->delete();

                    // Un hueco del patrón deja la semana sin cubrir a propósito.
                    if (!$uid) continue;

                    DB::table('shifts')->insert([
                        'week_start' => $semana['week_start'], 'shift' => $turno,
                        'user_id' => $uid, 'updated_at' => now(), 'created_at' => now(),
                    ]);
                    $puestas++;
                }
            }
        });

        return response()->json(['ok' => true, 'filled' => $puestas, 'weeks' => count($plan)]);
    }

    /**
     * Qué hay anotado en las semanas que CAMBIAN DE TITULAR.
     *
     * Solo miran las que de verdad cambian de persona: si la rotación deja al mismo,
     * no descuadra nada y no hay por qué molestar. Devuelve una entrada por semana
     * con sus notas y sus sustituciones, para que se puedan repasar.
     */
    protected function aRevisar(array $plan): array
    {
        $semanas = array_column($plan, 'week_start');
        if (!$semanas) return [];

        $ini = min($semanas);
        $fin = Carbon::parse(max($semanas))->addDays(6)->format('Y-m-d');

        $titulares = DB::table('shifts')
            ->whereIn('week_start', $semanas)->get()
            ->groupBy(fn ($r) => $r->week_start . '|' . $r->shift)
            ->map(fn ($g) => $g->pluck('user_id')->map('intval')->sort()->values()->all());

        $notas = DB::table('shift_notes')->whereBetween('date', [$ini, $fin])->get(['date', 'note']);
        $subs  = DB::table('shift_overrides as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.date_from', '<=', $fin)->where('o.date_to', '>=', $ini)
            ->get(['o.date_from', 'o.date_to', 'o.shift', 'u.name']);

        $salida = [];
        foreach ($plan as $s) {
            $desde = $s['week_start'];
            $hasta = Carbon::parse($desde)->addDays(6)->format('Y-m-d');

            /*
             * ¿Cambia de gente en algún turno? Se comparan CONJUNTOS: si la semana la
             * cubren dos y la rotación deja a uno, también cambia —y con ella pueden
             * quedar colgadas las sustituciones que relevaban al que se va.
             */
            $cambia = false;
            foreach (['morning', 'afternoon'] as $t) {
                $actual = $titulares->get($desde . '|' . $t, []);
                $nuevo  = array_values(array_filter([(int) ($s['plan'][$t] ?? 0)]));
                if ($actual && $actual !== $nuevo) $cambia = true;
            }
            if (!$cambia) continue;

            $misNotas = $notas->filter(fn ($n) => $n->date >= $desde && $n->date <= $hasta)->values();
            $misSubs  = $subs->filter(fn ($o) => $o->date_from <= $hasta && $o->date_to >= $desde)->values();
            if ($misNotas->isEmpty() && $misSubs->isEmpty()) continue;

            $salida[] = [
                'week_start' => $desde,
                'label'      => $s['label'],
                'month'      => substr($desde, 0, 7),
                'notas'      => $misNotas->map(fn ($n) => ['date' => $n->date, 'note' => $n->note])->all(),
                'subs'       => $misSubs->map(fn ($o) => [
                    'quien' => $o->name,
                    'turno' => ShiftService::TURNOS[$o->shift],
                    'desde' => $o->date_from,
                    'hasta' => $o->date_to,
                ])->all(),
            ];
        }

        return $salida;
    }

    /**
     * Calcula qué tocaría a cada semana. Devuelve [plan, error].
     * Cada entrada: week_start, label, el reparto que le toca y si ya había algo.
     */
    protected function planRotacion(Request $request): array
    {
        $desde = $this->lunesDe($request->input('from'));
        if (!$desde) return [null, 'Elige desde qué fecha empieza la rotación'];

        // El fin puede venir como fecha o como número de semanas.
        $hasta = $this->lunesDe($request->input('to'));
        $cuantas = $hasta
            ? ((int) $desde->diffInWeeks($hasta)) + 1
            : max(1, (int) $request->input('weeks', 8));

        if ($cuantas < 1)  return [null, 'La fecha de fin es anterior a la de inicio'];
        if ($cuantas > 104) return [null, 'Son más de dos años: acorta el periodo'];

        // Patrón: [{morning: id|null, afternoon: id|null}, …]
        $patron = [];
        foreach ((array) $request->input('pattern', []) as $fila) {
            $m = (int) ($fila['morning'] ?? 0);
            $t = (int) ($fila['afternoon'] ?? 0);
            foreach ([$m, $t] as $uid) {
                if ($uid && !$this->esAgente($uid)) return [null, 'Hay alguien que no puede cubrir soporte'];
            }
            $patron[] = ['morning' => $m ?: null, 'afternoon' => $t ?: null];
        }

        if (!$patron) return [null, 'Define al menos una semana en el patrón'];
        if (!array_filter($patron, fn ($p) => $p['morning'] || $p['afternoon'])) {
            return [null, 'El patrón está vacío: pon a alguien en alguna semana'];
        }

        // Qué semanas del tramo ya tienen algo puesto (para avisar antes de pisarlo).
        $fin = $desde->copy()->addWeeks($cuantas - 1);
        $ya = DB::table('shifts')
            ->whereBetween('week_start', [$desde->format('Y-m-d'), $fin->format('Y-m-d')])
            ->get(['week_start', 'shift'])
            ->groupBy('week_start');

        $plan = [];
        for ($i = 0; $i < $cuantas; $i++) {
            $lunes  = $desde->copy()->addWeeks($i);
            $clave  = $lunes->format('Y-m-d');
            $puesto = $ya->get($clave, collect())->pluck('shift')->all();

            $plan[] = [
                'week_start' => $clave,
                'label'      => $this->etiqueta($lunes, $lunes->copy()->addDays(4)),
                'plan'       => $patron[$i % count($patron)],
                'taken'      => [
                    'morning'   => in_array('morning', $puesto, true),
                    'afternoon' => in_array('afternoon', $puesto, true),
                ],
                'ocupada'    => (bool) $puesto,
            ];
        }

        return [$plan, null];
    }

    /**
     * QUIÉN PUEDE ESTAR EN EL CUADRANTE.
     *
     * No vale «cualquiera con acceso al helpdesk»: eso metía al superadministrador,
     * que no hace guardias, y el ciclo propuesto salía con gente que no rota.
     * La regla (decidida con el usuario, 22-jul-2026) es:
     *   · tiene alguna CATEGORÍA que se reparte por turno,
     *   · y NO es superadministrador ni encargado de soporte.
     *
     * Si todavía no hay ninguna categoría marcada para repartir, se cae a «cualquier
     * categoría»: dejar la lista vacía haría el cuadrante inservible sin decir por qué.
     *
     * Los roles y permisos se cargan POR ADELANTADO: sin eso, cada `can()` iba a la
     * base de datos a por los permisos de ese usuario, una consulta por agente.
     */
    protected function agentes()
    {
        return \App\Models\User::with('roles.permissions', 'permissions')
            ->whereIn('id', $this->idsElegibles())
            ->orderByRaw('name IS NULL, name ASC')->get()
            ->filter(fn ($u) => $u->can('helpdesk.access') && !$this->esMando($u))
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name ?: $u->email])
            ->values();
    }

    /** Ids de usuarios con categoría de soporte (las que rotan, si las hay). */
    protected function idsElegibles(): array
    {
        $conTurno = DB::table('user_ticket_categories as uc')
            ->join('ticket_categories as c', 'c.id', '=', 'uc.category_id')
            ->where('c.use_shift', 1)->distinct()->pluck('uc.user_id')->all();

        if ($conTurno) return $conTurno;

        return DB::table('user_ticket_categories')->distinct()->pluck('user_id')->all();
    }

    /** El superadministrador y el encargado no entran al cuadrante. */
    protected function esMando(\App\Models\User $u): bool
    {
        return $u->hasAnyRole(array_filter([config('rbac.super_role'), 'encargado_soporte']));
    }

    /** Misma regla que la lista: no se puede asignar a quien no se ofrece. */
    protected function esAgente(int $id): bool
    {
        $u = \App\Models\User::find($id);
        return $u && $u->can('helpdesk.access') && !$this->esMando($u)
            && in_array($id, array_map('intval', $this->idsElegibles()), true);
    }

    /** Normaliza cualquier fecha al lunes de su semana. */
    protected function lunesDe($v): ?Carbon
    {
        if (!$v || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $v))) return null;
        return Carbon::parse(trim((string) $v))->startOfWeek();
    }

    /** Días de la semana que cubre: «Vie» o «Lun–Mié». Sin el punto de «vie.». */
    protected function diasLabel(string $desde, string $hasta): string
    {
        $dow = fn ($d) => rtrim(Carbon::parse($d)->locale('es')->isoFormat('ddd'), '.');
        return $desde === $hasta ? $dow($desde) : $dow($desde) . '–' . $dow($hasta);
    }

    /** El periodo completo en claro, para el tooltip: «del 20 al 24 de julio». */
    protected function rangoLabel(string $desde, string $hasta): string
    {
        $d = Carbon::parse($desde)->locale('es');
        $h = Carbon::parse($hasta)->locale('es');

        if ($desde === $hasta) return 'el ' . $d->isoFormat('D [de] MMMM');

        return $d->month === $h->month
            ? 'del ' . $d->day . ' al ' . $h->isoFormat('D [de] MMMM')
            : 'del ' . $d->isoFormat('D [de] MMMM') . ' al ' . $h->isoFormat('D [de] MMMM');
    }

    /** «20 – 24 jul» o «31 ago – 4 sep» si cruza de mes (el caso que el Excel erraba). */
    protected function etiqueta(Carbon $lunes, Carbon $viernes): string
    {
        $mes = fn (Carbon $d) => rtrim($d->locale('es')->isoFormat('MMM'), '.');

        return $lunes->month === $viernes->month
            ? $lunes->day . ' – ' . $viernes->day . ' ' . $mes($viernes)
            : $lunes->day . ' ' . $mes($lunes) . ' – ' . $viernes->day . ' ' . $mes($viernes);
    }
}
