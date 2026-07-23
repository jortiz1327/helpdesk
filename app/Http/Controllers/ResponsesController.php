<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/responses.php — respuestas capturadas por el bot (+ CSV). Requiere token. */
class ResponsesController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', 'list');
        $q = trim((string) $request->query('q', ''));

        $sql = 'SELECT r.id, r.contact_id, r.flow_id, r.session_id, r.variable, r.value, r.created_at,
                       c.name AS contact_name, c.wa_id, f.name AS flow_name
                FROM flow_responses r
                LEFT JOIN contacts c ON c.id = r.contact_id
                LEFT JOIN flows f ON f.id = r.flow_id';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE c.name LIKE ? OR c.wa_id LIKE ? OR r.variable LIKE ? OR r.value LIKE ?';
            $like = "%$q%";
            $params = [$like, $like, $like, $like];
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 2000';

        $rows = DB::select($sql, $params);

        if ($action === 'csv') {
            $fh = fopen('php://temp', 'r+');
            fputcsv($fh, ['Fecha', 'Contacto', 'Telefono', 'Flujo', 'Variable', 'Respuesta']);
            foreach ($rows as $r) {
                fputcsv($fh, [$r->created_at, $r->contact_name ?? '', $r->wa_id ?? '', $r->flow_name ?? '', $r->variable, $r->value]);
            }
            rewind($fh);
            $csv = "\xEF\xBB\xBF" . stream_get_contents($fh); // BOM para Excel
            fclose($fh);
            return response($csv, 200, [
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="respuestas-bot.csv"',
            ]);
        }

        return response()->json(['ok' => true, 'responses' => $rows]);
    }
}
