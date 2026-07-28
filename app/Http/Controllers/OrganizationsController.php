<?php

namespace App\Http\Controllers;

use App\Models\Grupo;
use App\Models\Marca;
use App\Models\Sede;
use App\Services\SlaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ORGANIZACIÓN de clientes: Grupo → Marca → Sede.
 * Leer el árbol lo puede cualquiera del helpdesk (para el selector de la ficha de
 * contacto); crear/editar/borrar exige `contacts.edit`.
 */
class OrganizationsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'tree')) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            'report' => $this->report($request),
            default  => $this->tree(),
        };
    }

    /** El árbol completo con el nº de contactos por sede (para pintarlo de un viaje). */
    protected function tree()
    {
        $contactosPorSede = DB::table('contacts')->whereNotNull('sede_id')
            ->select('sede_id', DB::raw('COUNT(*) n'))->groupBy('sede_id')->pluck('n', 'sede_id');

        // Tickets (no-cron, sin fusionar) por sede, para pintar el total en cada nodo.
        $ticketsPorSede = DB::table('tickets as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereNotNull('c.sede_id')->where('t.channel', '!=', 'cron')->whereNull('t.merged_into_id')
            ->select('c.sede_id', DB::raw('COUNT(*) n'))->groupBy('c.sede_id')->pluck('n', 'c.sede_id');

        $grupos = Grupo::with(['marcas' => fn ($q) => $q->orderBy('name'), 'marcas.sedes' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')->get();

        $out = $grupos->map(fn ($g) => [
            'id'     => $g->id,
            'name'   => $g->name,
            'color'  => $g->color,
            'note'   => $g->note,
            'active' => $g->active,
            'marcas' => $g->marcas->map(fn ($m) => [
                'id'     => $m->id,
                'name'   => $m->name,
                'active' => $m->active,
                'sedes'  => $m->sedes->map(fn ($s) => [
                    'id'        => $s->id,
                    'name'      => $s->name,
                    'city'      => $s->city,
                    'address'   => $s->address,
                    'active'    => $s->active,
                    'contactos' => (int) ($contactosPorSede[$s->id] ?? 0),
                    'tickets'   => (int) ($ticketsPorSede[$s->id] ?? 0),
                ])->all(),
            ])->all(),
        ])->all();

        return response()->json(['ok' => true, 'grupos' => $out]);
    }

    /**
     * INFORME por nivel (grupo / marca / sede): nº de tickets, abiertos, resueltos,
     * SLA vencidos y tiempos medios de respuesta y resolución. Una fila por nodo.
     */
    protected function report(Request $request)
    {
        $level = in_array($request->query('level'), ['grupo', 'marca', 'sede'], true) ? $request->query('level') : 'grupo';

        // «Vencido» = mismo criterio que la bandeja (plazo pasado y ticket vivo), y solo
        // si el SLA está activo globalmente.
        $vencido   = SlaService::activo()
            ? '(t.sla_resolve_due_at < NOW() OR (t.sla_response_due_at < NOW() AND t.first_response_at IS NULL))'
            : '0';
        $abiertos  = "t.status IN ('nuevo','abierto','en_progreso','esperando_respuesta')";
        $resueltos = "t.status IN ('resuelto','cerrado')";

        [$idCol, $label, $group] = match ($level) {
            'sede'  => ['s.id', "CONCAT(g.name,' · ',m.name,' · ',s.name)", 's.id, g.name, m.name, s.name'],
            'marca' => ['m.id', "CONCAT(g.name,' · ',m.name)", 'm.id, g.name, m.name'],
            default => ['g.id', 'g.name', 'g.id, g.name'],
        };

        $rows = DB::table('tickets as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->join('sedes as s', 's.id', '=', 'c.sede_id')
            ->join('marcas as m', 'm.id', '=', 's.marca_id')
            ->join('grupos as g', 'g.id', '=', 'm.grupo_id')
            ->where('t.channel', '!=', 'cron')->whereNull('t.merged_into_id')
            ->selectRaw("$idCol AS id, $label AS label,
                COUNT(*) AS total,
                SUM($abiertos) AS abiertos,
                SUM($resueltos) AS resueltos,
                SUM(($abiertos) AND ($vencido)) AS vencidos,
                AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) AS resol_min,
                AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at) END) AS resp_min")
            ->groupByRaw($group)
            ->orderByDesc('total')
            ->get();

        $out = $rows->map(fn ($r) => [
            'id'        => (int) $r->id,
            'label'     => $r->label,
            'total'     => (int) $r->total,
            'abiertos'  => (int) $r->abiertos,
            'resueltos' => (int) $r->resueltos,
            'vencidos'  => (int) $r->vencidos,
            'resol_h'   => $r->resol_min !== null ? round($r->resol_min / 60, 1) : null,
            'resp_h'    => $r->resp_min !== null ? round($r->resp_min / 60, 1) : null,
        ])->all();

        return response()->json(['ok' => true, 'level' => $level, 'sla_activo' => SlaService::activo(), 'rows' => $out]);
    }

    protected function save(Request $request)
    {
        if ($no = $this->soloEditores($request)) return $no;

        $level = $request->input('level');
        $name  = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);

        $id     = (int) $request->input('id');
        $active = filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN);

        return match ($level) {
            'grupo' => $this->saveGrupo($request, $id, $name, $active),
            'marca' => $this->saveHijo(Marca::class, 'grupo_id', 'grupos', $request, $id, $name, $active),
            'sede'  => $this->saveSede($request, $id, $name, $active),
            default => response()->json(['ok' => false, 'error' => 'Nivel no válido'], 400),
        };
    }

    protected function saveGrupo(Request $request, int $id, string $name, bool $active)
    {
        $data = [
            'name'   => mb_substr($name, 0, 160),
            'note'   => mb_substr(trim((string) $request->input('note', '')), 0, 65535) ?: null,
            'active' => $active,
        ];
        $color = (string) $request->input('color', '');
        $data['color'] = preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : null;

        $cur = $id ? Grupo::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Grupo no encontrado'], 404);
        $cur ? $cur->update($data) : Grupo::create($data);
        return response()->json(['ok' => true]);
    }

    /** Marca (hijo de grupo). Reutilizable por si hiciera falta. */
    protected function saveHijo(string $model, string $fk, string $parentTable, Request $request, int $id, string $name, bool $active)
    {
        $parentId = (int) $request->input($fk);
        if (!$parentId || !DB::table($parentTable)->where('id', $parentId)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Falta el padre o no existe'], 400);
        }
        $cur = $id ? $model::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'No encontrado'], 404);

        $data = [$fk => $parentId, 'name' => mb_substr($name, 0, 160), 'active' => $active];
        $cur ? $cur->update($data) : $model::create($data);
        return response()->json(['ok' => true]);
    }

    protected function saveSede(Request $request, int $id, string $name, bool $active)
    {
        $marcaId = (int) $request->input('marca_id');
        if (!$marcaId || !DB::table('marcas')->where('id', $marcaId)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Falta la marca o no existe'], 400);
        }
        $cur = $id ? Sede::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Sede no encontrada'], 404);

        $data = [
            'marca_id' => $marcaId,
            'name'     => mb_substr($name, 0, 160),
            'city'     => mb_substr(trim((string) $request->input('city', '')), 0, 120) ?: null,
            'address'  => mb_substr(trim((string) $request->input('address', '')), 0, 200) ?: null,
            'active'   => $active,
        ];
        $cur ? $cur->update($data) : Sede::create($data);
        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        if ($no = $this->soloEditores($request)) return $no;

        $id = (int) $request->input('id');
        return match ($request->input('level')) {
            'grupo' => $this->borrar(Grupo::find($id), fn ($g) => $g->marcas()->count(), 'El grupo tiene marcas. Bórralas antes.'),
            'marca' => $this->borrar(Marca::find($id), fn ($m) => $m->sedes()->count(), 'La marca tiene sedes. Bórralas antes.'),
            'sede'  => $this->borrarSede($id),
            default => response()->json(['ok' => false, 'error' => 'Nivel no válido'], 400),
        };
    }

    protected function borrar($modelo, \Closure $cuentaHijos, string $error)
    {
        if (!$modelo) return response()->json(['ok' => false, 'error' => 'No encontrado'], 404);
        if ($cuentaHijos($modelo) > 0) return response()->json(['ok' => false, 'error' => $error], 409);
        $modelo->delete();
        return response()->json(['ok' => true]);
    }

    protected function borrarSede(int $id)
    {
        $sede = Sede::find($id);
        if (!$sede) return response()->json(['ok' => false, 'error' => 'Sede no encontrada'], 404);
        // Los contactos de la sede se quedan sin sede (FK nullOnDelete). No se borran.
        $sede->delete();
        return response()->json(['ok' => true]);
    }

    /** Crear/editar/borrar el árbol exige permiso de edición de contactos. */
    protected function soloEditores(Request $request)
    {
        if (!$request->user()?->can('contacts.edit')) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para gestionar la organización'], 403);
        }
        return null;
    }
}
