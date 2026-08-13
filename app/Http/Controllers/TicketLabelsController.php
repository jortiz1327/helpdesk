<?php

namespace App\Http\Controllers;

use App\Models\TicketLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ETIQUETAS de ticket (catálogo). Vive en «Configuración de soporte».
 *
 * LISTAR está abierto a cualquier agente (helpdesk.access): lo necesita el selector
 * de la ficha. CREAR/EDITAR/BORRAR el catálogo exige support.config (encargados).
 */
class TicketLabelsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'   => $this->guard($request) ?? $this->save($request),
            'delete' => $this->guard($request) ?? $this->delete($request),
            default  => $this->list(),
        };
    }

    /** Solo los encargados tocan el catálogo. Devuelve 403 si no; null si puede pasar. */
    protected function guard(Request $request)
    {
        return $request->user()?->can('support.config')
            ? null
            : response()->json(['ok' => false, 'error' => 'No tienes permiso para gestionar etiquetas'], 403);
    }

    protected function list()
    {
        $rows = TicketLabel::orderBy('position')->orderBy('id')->get();
        // Cuántos tickets usa cada una (para avisar antes de borrar).
        $uso = DB::table('ticket_label_ticket')->select('label_id', DB::raw('COUNT(*) n'))
            ->groupBy('label_id')->pluck('n', 'label_id');
        foreach ($rows as $r) $r->tickets = (int) ($uso[$r->id] ?? 0);

        return response()->json(['labels' => $rows]);
    }

    protected function save(Request $request)
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);

        $id  = (int) $request->input('id');
        $cur = $id ? TicketLabel::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Etiqueta no encontrada'], 404);

        // No dos etiquetas con el mismo nombre (case-insensitive), para evitar duplicados.
        $dup = TicketLabel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($cur, fn ($q) => $q->where('id', '!=', $cur->id))->exists();
        if ($dup) return response()->json(['ok' => false, 'error' => 'Ya existe una etiqueta con ese nombre'], 409);

        $color = (string) $request->input('color', '#64748b');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#64748b';

        $data = [
            'name'     => mb_substr($name, 0, 60),
            'color'    => $color,
            'position' => (int) $request->input('position', 0),
            'active'   => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
        ];
        $cur ? $cur->update($data) : TicketLabel::create($data);

        TicketLabel::olvidarCache();
        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        $l = TicketLabel::find((int) $request->input('id'));
        if (!$l) return response()->json(['ok' => false, 'error' => 'Etiqueta no encontrada'], 404);

        // A diferencia de categoría/prioridad, borrar una etiqueta en uso NO deja
        // huérfano a ningún ticket (la relación se borra en cascada); aun así se avisa.
        $enUso = DB::table('ticket_label_ticket')->where('label_id', $l->id)->count();
        if ($enUso) {
            return response()->json(['ok' => false, 'error' => "No se puede borrar: {$enUso} ticket(s) la usan. Desactívala en su lugar."], 409);
        }

        $l->delete();
        TicketLabel::olvidarCache();
        return response()->json(['ok' => true]);
    }
}
