<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/flows.php — automatizaciones. Solo admin. */
class FlowsController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        if ($request->isMethod('get') && $action === 'schema') {
            return $this->schema();
        }

        if ($request->isMethod('get')) {
            $id = (int) $request->query('id', 0);
            if ($id) {
                $row = DB::selectOne('SELECT * FROM flows WHERE id = ?', [$id]);
                if (!$row) return response()->json(['ok' => false, 'error' => 'No encontrado'], 404);
                $row->graph = json_decode($row->graph ?: '{}', true);
                return response()->json(['ok' => true, 'flow' => $row]);
            }
            $rows = DB::select('SELECT id, name, active, updated_at FROM flows ORDER BY updated_at DESC');
            return response()->json(['ok' => true, 'flows' => $rows]);
        }

        if ($request->isMethod('post')) {
            return $this->save($request);
        }

        if ($request->isMethod('delete')) {
            $id = (int) $request->query('id', 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            DB::table('flows')->where('id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    /** Esquema de la BD para el nodo "Consultar base de datos" (excluye tablas sensibles/internas). */
    protected function schema()
    {
        $blacklist = [
            'settings', 'users', 'webhook_log', 'flow_sessions', 'flow_responses',
            'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens',
        ];
        $schema = [];
        foreach (DB::select('SHOW TABLES') as $row) {
            $t = array_values((array) $row)[0];
            if (in_array($t, $blacklist, true)) continue;
            $schema[$t] = array_map(fn ($c) => $c->Field, DB::select("SHOW COLUMNS FROM `$t`"));
        }
        return response()->json(['ok' => true, 'schema' => $schema]);
    }

    protected function save(Request $request)
    {
        $id     = (int) $request->input('id');
        $name   = trim((string) $request->input('name')) ?: 'Sin título';
        $active = $request->boolean('active') ? 1 : 0;
        $graph  = json_encode($request->input('graph') ?: ['nodes' => [], 'edges' => []], JSON_UNESCAPED_UNICODE);

        if ($id) {
            DB::table('flows')->where('id', $id)->update(['name' => $name, 'active' => $active, 'graph' => $graph, 'updated_at' => now()]);
        } else {
            $id = DB::table('flows')->insertGetId(['name' => $name, 'active' => $active, 'graph' => $graph, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Solo puede haber UN chatbot activo.
        if ($active) {
            DB::table('flows')->where('id', '<>', $id)->update(['active' => 0]);
            DB::table('flow_sessions')->where('flow_id', '<>', $id)->whereIn('status', ['active', 'waiting'])->update(['status' => 'done']);
        } else {
            DB::table('flow_sessions')->where('flow_id', $id)->whereIn('status', ['active', 'waiting'])->update(['status' => 'done']);
        }

        return response()->json(['ok' => true, 'id' => $id]);
    }
}
