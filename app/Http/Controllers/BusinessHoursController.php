<?php

namespace App\Http\Controllers;

use App\Services\BusinessHoursService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Horario de atención y festivos. Es la base del SLA: fuera de horario el reloj
 * se para. Requiere support.config.
 *
 * OJO: esto NO es el cuadrante de turnos (quién trabaja cada semana), es el
 * compromiso de cuándo se atiende.
 */
class BusinessHoursController extends Controller
{
    protected const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function handle(Request $request)
    {
        return match ($request->query('action', 'get')) {
            'save'          => $this->save($request),
            'add_holiday'   => $this->addHoliday($request),
            'del_holiday'   => $this->delHoliday($request),
            'toggle_sla'    => $this->toggleSla($request),
            default         => $this->get(),
        };
    }

    protected function get()
    {
        $svc = app(BusinessHoursService::class);

        // Tramos por día, tal cual están guardados (sin fusionar: aquí se editan).
        $porDia = [];
        foreach (self::DIAS as $d => $nombre) $porDia[$d] = [];
        foreach (DB::table('business_hours')->orderBy('weekday')->orderBy('opens')->get() as $r) {
            $porDia[(int) $r->weekday][] = ['opens' => substr((string) $r->opens, 0, 5), 'closes' => substr((string) $r->closes, 0, 5)];
        }

        // Horas semanales que salen del horario (ya fusionado: sin contar solapes dos veces).
        $minutos = 0;
        $lunes = Carbon::now()->startOfWeek();
        for ($i = 0; $i < 7; $i++) {
            foreach ($svc->tramosDelDia($lunes->copy()->addDays($i)) as [$ini, $fin]) {
                $minutos += (int) $ini->diffInMinutes($fin);
            }
        }

        return response()->json([
            'days'      => self::DIAS,
            'hours'     => $porDia,
            'holidays'  => DB::table('holidays')->orderBy('date')->get(['id', 'date', 'name']),
            'week_hours' => round($minutos / 60, 1),
            'open_now'  => $svc->abierto(),
            // Interruptor general del SLA + qué categorías tienen plazo puesto.
            'sla_active' => (string) \App\Models\Setting::get('sla_active', '1') === '1',
            'sla_cats'   => DB::table('ticket_categories')
                ->where(fn ($q) => $q->whereNotNull('sla_response_hours')->orWhereNotNull('sla_resolve_hours'))
                ->pluck('name'),
        ]);
    }

    /**
     * Enciende o apaga el SLA en general. Apagarlo NO borra las horas de las
     * categorías: se deja de contar, y al volver a encenderlo todo sigue donde estaba.
     */
    protected function toggleSla(Request $request)
    {
        \App\Models\Setting::put('sla_active', $request->boolean('active') ? '1' : '0');

        return response()->json(['ok' => true, 'active' => $request->boolean('active')]);
    }

    /** Reemplaza TODO el horario semanal de una vez (es como se edita en pantalla). */
    protected function save(Request $request)
    {
        $entrada = (array) $request->input('hours', []);
        $filas = [];

        foreach ($entrada as $dia => $tramos) {
            $dia = (int) $dia;
            if (!isset(self::DIAS[$dia])) continue;

            foreach ((array) $tramos as $t) {
                $abre   = $this->hora($t['opens'] ?? null);
                $cierra = $this->hora($t['closes'] ?? null);
                if (!$abre || !$cierra) continue;
                if ($cierra <= $abre) {
                    return response()->json(['ok' => false, 'error' => 'En ' . self::DIAS[$dia] . ', la hora de cierre debe ser posterior a la de apertura'], 400);
                }
                $filas[] = ['weekday' => $dia, 'opens' => $abre . ':00', 'closes' => $cierra . ':00', 'created_at' => now(), 'updated_at' => now()];
            }
        }

        DB::transaction(function () use ($filas) {
            DB::table('business_hours')->delete();
            if ($filas) DB::table('business_hours')->insert($filas);
        });

        app(BusinessHoursService::class)->olvidarCache();
        return response()->json(['ok' => true]);
    }

    protected function addHoliday(Request $request)
    {
        $fecha = trim((string) $request->input('date'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return response()->json(['ok' => false, 'error' => 'Fecha no válida'], 400);
        }
        if (DB::table('holidays')->where('date', $fecha)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Ese día ya está en la lista'], 409);
        }

        DB::table('holidays')->insert([
            'date' => $fecha,
            'name' => mb_substr(trim((string) $request->input('name')), 0, 120) ?: null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(BusinessHoursService::class)->olvidarCache();
        return response()->json(['ok' => true]);
    }

    protected function delHoliday(Request $request)
    {
        DB::table('holidays')->where('id', (int) $request->input('id'))->delete();
        app(BusinessHoursService::class)->olvidarCache();
        return response()->json(['ok' => true]);
    }

    /** Normaliza «9:5» o «09:05» a «09:05»; null si no vale. */
    protected function hora($v): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $v), $m)) return null;
        [$h, $min] = [(int) $m[1], (int) $m[2]];
        if ($h > 23 || $min > 59) return null;
        return sprintf('%02d:%02d', $h, $min);
    }
}
