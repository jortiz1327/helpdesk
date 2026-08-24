<?php

namespace App\Http\Controllers;

use App\Models\TicketView;
use Illuminate\Http\Request;

/**
 * Vistas guardadas de la bandeja (las «Colas»). PERSONALES: cada agente solo ve y
 * toca las suyas. La ruta exige helpdesk.access; el filtrado por usuario se hace aquí.
 */
class TicketViewsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            default  => $this->list($request),
        };
    }

    protected function list(Request $request)
    {
        $me = $request->user();
        // Mis vistas personales + las COMPARTIDAS del equipo (estas primero).
        $views = TicketView::where('shared', true)->orWhere('user_id', $me->id)
            ->orderByDesc('shared')->orderBy('position')->orderBy('id')
            ->get(['id', 'name', 'filters', 'color', 'shared']);

        // Para que el frontend ofrezca «compartir» y permita editar las de equipo.
        return response()->json(['ok' => true, 'views' => $views, 'can_share' => $me->can('support.config')]);
    }

    protected function save(Request $request)
    {
        $me = $request->user();
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'Ponle un nombre a la vista'], 400);

        $filters = $request->input('filters');
        if (!is_array($filters)) return response()->json(['ok' => false, 'error' => 'Filtros no válidos'], 400);

        // Compartir con el equipo: solo encargados.
        $shared = filter_var($request->input('shared', false), FILTER_VALIDATE_BOOLEAN);
        if ($shared && !$me->can('support.config')) {
            return response()->json(['ok' => false, 'error' => 'Solo los encargados pueden crear vistas de equipo'], 403);
        }

        $id  = (int) $request->input('id');
        $cur = $id ? TicketView::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Vista no encontrada'], 404);
        if ($cur && !$this->puedeTocar($cur, $me)) {
            return response()->json(['ok' => false, 'error' => 'No puedes editar esta vista'], 403);
        }

        $color = (string) $request->input('color', '#2563eb');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#2563eb';

        $data = [
            'name'     => mb_substr($name, 0, 80),
            'filters'  => $filters,
            'color'    => $color,
            'shared'   => $shared,
            'position' => (int) $request->input('position', $cur->position ?? (TicketView::max('position') + 1)),
        ];
        if ($cur) {
            $cur->update($data);
        } else {
            $data['user_id'] = $me->id;   // el creador (referencia; las compartidas las ven todos)
            TicketView::create($data);
        }

        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        $me = $request->user();
        $v = TicketView::find((int) $request->input('id'));
        if (!$v) return response()->json(['ok' => false, 'error' => 'Vista no encontrada'], 404);
        if (!$this->puedeTocar($v, $me)) {
            return response()->json(['ok' => false, 'error' => 'No puedes borrar esta vista'], 403);
        }
        $v->delete();
        return response()->json(['ok' => true]);
    }

    /** ¿Puede este usuario editar/borrar esta vista? Compartida → encargado; personal → su dueño. */
    protected function puedeTocar(TicketView $v, $me): bool
    {
        return $v->shared ? $me->can('support.config') : ((int) $v->user_id === (int) $me->id);
    }
}
