<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** Portado de api/stats.php — métricas del Dashboard. Requiere token. */
class StatsController extends Controller
{
    public function handle()
    {
        $daily = [];
        $rows = DB::select("
            SELECT DATE(created_at) d, direction, COUNT(*) c
            FROM messages
            WHERE created_at >= CURDATE() - INTERVAL 6 DAY
            GROUP BY DATE(created_at), direction
        ");
        $map = [];
        foreach ($rows as $r) {
            $map[$r->d][$r->direction] = (int) $r->c;
        }
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $in  = $map[$day]['in']  ?? 0;
            $out = $map[$day]['out'] ?? 0;
            $daily[] = ['date' => $day, 'in' => $in, 'out' => $out, 'total' => $in + $out];
        }

        $recent = DB::select("
            SELECT id, wa_id, name, last_message, last_time, unread
            FROM contacts
            ORDER BY (last_time IS NULL), last_time DESC, id DESC
            LIMIT 6
        ");

        return response()->json([
            'contacts'       => DB::table('contacts')->count(),
            'unread'         => (int) DB::table('contacts')->sum('unread'),
            'unread_chats'   => DB::table('contacts')->where('unread', '>', 0)->count(),
            'messages'       => DB::table('messages')->count(),
            /*
             * Rango de fechas en vez de DATE(created_at) = CURDATE(). Envolver la
             * columna en una función IMPIDE usar el índice: MySQL tiene que calcular
             * DATE() fila a fila sobre toda la tabla. Con 120.000 mensajes eran 22 ms
             * frente a 0,6 ms del rango, y la diferencia crece con el histórico.
             */
            'messages_today' => DB::table('messages')
                ->where('created_at', '>=', DB::raw('CURDATE()'))
                ->where('created_at', '<', DB::raw('CURDATE() + INTERVAL 1 DAY'))
                ->count(),
            'labels'         => DB::table('labels')->count(),
            'daily'          => $daily,
            'recent'         => $recent,
        ]);
    }
}
