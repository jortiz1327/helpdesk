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
        $views = TicketView::where('user_id', $request->user()->id)
            ->orderBy('position')->orderBy('id')->get(['id', 'name', 'filters', 'color']);

        return response()->json(['ok' => true, 'views' => $views]);
    }

    protected function save(Request $request)
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'Ponle un nombre a la vista'], 400);

        $filters = $request->input('filters');
        if (!is_array($filters)) return response()->json(['ok' => false, 'error' => 'Filtros no válidos'], 400);

        $uid = $request->user()->id;
        $id  = (int) $request->input('id');
        $cur = $id ? TicketView::where('user_id', $uid)->find($id) : null;   // solo las MÍAS
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Vista no encontrada'], 404);

        $color = (string) $request->input('color', '#2563eb');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#2563eb';

        $data = [
            'user_id'  => $uid,
            'name'     => mb_substr($name, 0, 80),
            'filters'  => $filters,
            'color'    => $color,
            'position' => (int) $request->input('position', $cur->position ?? (TicketView::where('user_id', $uid)->max('position') + 1)),
        ];
        $cur ? $cur->update($data) : TicketView::create($data);

        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        $v = TicketView::where('user_id', $request->user()->id)->find((int) $request->input('id'));
        if (!$v) return response()->json(['ok' => false, 'error' => 'Vista no encontrada'], 404);
        $v->delete();
        return response()->json(['ok' => true]);
    }
}
