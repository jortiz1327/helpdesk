<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/labels.php — etiquetas (orden manual). Requiere token. */
class LabelsController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        // Las etiquetas son de Campañas: crearlas, borrarlas u ordenarlas exige Campañas
        // y poder editar contactos (verlas basta con contacts.access).
        if (!$request->isMethod('get') && !($request->user()->can('campaigns.access') && $request->user()->can('contacts.edit'))) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para gestionar etiquetas'], 403);
        }

        if ($request->isMethod('get')) {
            // grupo: etiquetas excluyentes (interés, tipo); la pantalla quita las hermanas al marcar.
            $rows = DB::select('SELECT id, name, color, grupo, position FROM labels ORDER BY position ASC, name ASC');
            return response()->json(['ok' => true, 'labels' => $rows]);
        }

        if ($request->isMethod('post') && $action === 'reorder') {
            $ids = $request->input('ids', []);
            if (!is_array($ids)) return response()->json(['ok' => false, 'error' => 'ids no válido'], 400);
            foreach (array_values($ids) as $i => $id) {
                DB::table('labels')->where('id', (int) $id)->update(['position' => $i + 1]);
            }
            return response()->json(['ok' => true]);
        }

        if ($request->isMethod('post')) {
            $name  = trim((string) $request->input('name', ''));
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $request->input('color', '')) ? $request->input('color') : '#00a884';
            if ($name === '') return response()->json(['ok' => false, 'error' => 'Falta el nombre'], 400);
            $pos = (int) DB::table('labels')->max('position') + 1;
            $id  = DB::table('labels')->insertGetId(['name' => $name, 'color' => $color, 'position' => $pos]);
            return response()->json(['ok' => true, 'id' => $id]);
        }

        if ($request->isMethod('delete')) {
            $id = (int) $request->query('id', 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            DB::table('labels')->where('id', $id)->delete();
            DB::table('contact_labels')->where('label_id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
}
