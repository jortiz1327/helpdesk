<?php

namespace App\Http\Controllers;

use App\Models\TicketPriority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prioridades de ticket configurables. Vive en «Configuración de soporte».
 * Requiere support.config.
 */
class TicketPrioritiesController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            default  => $this->list(),
        };
    }

    protected function list()
    {
        $rows = TicketPriority::orderBy('position')->orderBy('id')->get();
        // Cuántos tickets usa cada una: si tiene, no se puede borrar.
        $uso = DB::table('tickets')->select('priority', DB::raw('COUNT(*) n'))->groupBy('priority')->pluck('n', 'priority');
        foreach ($rows as $r) $r->tickets = (int) ($uso[$r->key] ?? 0);

        return response()->json(['priorities' => $rows]);
    }

    protected function save(Request $request)
    {
        $name = trim((string) $request->input('name'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'El nombre es obligatorio'], 400);

        $id  = (int) $request->input('id');
        $cur = $id ? TicketPriority::find($id) : null;
        if ($id && !$cur) return response()->json(['ok' => false, 'error' => 'Prioridad no encontrada'], 404);

        // La clave se genera del nombre y NO se cambia después: es lo que hay guardado
        // en los tickets, y tocarla dejaría huérfanos los que ya la usan.
        $key = $cur?->key ?: Str::slug($name, '_');
        if ($key === '') return response()->json(['ok' => false, 'error' => 'El nombre no es válido'], 400);
        if (!$cur && TicketPriority::where('key', $key)->exists()) {
            return response()->json(['ok' => false, 'error' => 'Ya existe una prioridad con ese nombre'], 409);
        }

        $color  = (string) $request->input('color', '#64748b');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#64748b';

        $data = [
            'key'      => $key,
            'name'     => mb_substr($name, 0, 60),
            'color'    => $color,
            'position' => (int) $request->input('position', 0),
            'active'   => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
        ];

        DB::transaction(function () use ($request, $cur, $data) {
            $esDefecto = filter_var($request->input('is_default', false), FILTER_VALIDATE_BOOLEAN);
            // Solo puede haber una por defecto.
            if ($esDefecto) TicketPriority::query()->update(['is_default' => false]);

            $data['is_default'] = $esDefecto;
            $cur ? $cur->update($data) : TicketPriority::create($data);

            // Siempre tiene que quedar una por defecto ACTIVA para los tickets nuevos.
            if (!TicketPriority::where('is_default', true)->where('active', true)->exists()) {
                $primera = TicketPriority::where('active', true)->orderBy('position')->first();
                if ($primera) $primera->update(['is_default' => true]);
            }
        });

        TicketPriority::olvidarCache();
        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        $p = TicketPriority::find((int) $request->input('id'));
        if (!$p) return response()->json(['ok' => false, 'error' => 'Prioridad no encontrada'], 404);

        // En uso: borrarla dejaría tickets apuntando a una prioridad inexistente.
        $enUso = DB::table('tickets')->where('priority', $p->key)->count();
        if ($enUso) {
            return response()->json(['ok' => false, 'error' => "No se puede borrar: {$enUso} ticket(s) la usan. Desactívala en su lugar."], 409);
        }
        if (TicketPriority::count() <= 1) {
            return response()->json(['ok' => false, 'error' => 'Debe quedar al menos una prioridad'], 409);
        }

        $eraDefecto = $p->is_default;
        $p->delete();

        if ($eraDefecto) {
            $primera = TicketPriority::where('active', true)->orderBy('position')->first();
            if ($primera) $primera->update(['is_default' => true]);
        }

        TicketPriority::olvidarCache();
        return response()->json(['ok' => true]);
    }
}
