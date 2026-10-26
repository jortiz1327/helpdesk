<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Portado de api/stats.php — métricas del Dashboard del WhatsApp de CAMPAÑAS (y el badge
 * de no leídos del Chat en vivo). Solo Campañas (ruta con campaigns.access): todo se
 * filtra a funcion='campanas', sin mensajes ni clientes del Helpdesk.
 */
class StatsController extends Controller
{
    public function handle()
    {
        $daily = [];
        $rows = DB::select("
            SELECT DATE(created_at) d, direction, COUNT(*) c
            FROM messages
            WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND channel = 'whatsapp' AND funcion = 'campanas'
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
            SELECT c.id, c.wa_id, c.name, c.last_message, c.last_time, c.unread
            FROM contacts c
            WHERE EXISTS (SELECT 1 FROM messages m WHERE m.contact_id = c.id AND m.channel = 'whatsapp' AND m.funcion = 'campanas')
            ORDER BY (c.last_time IS NULL), c.last_time DESC, c.id DESC
            LIMIT 6
        ");
        $campanas = fn ($q) => $q->where('channel', 'whatsapp')->where('funcion', 'campanas');

        // El badge de no-leídos es el de la «Chat en vivo» de Campañas: solo cuenta
        // contactos con conversación de CAMPAÑAS (no soporte ni contactos sueltos).
        $esDeCampanas = fn ($q) => $q->select(DB::raw(1))->from('messages as mu')
            ->whereColumn('mu.contact_id', 'contacts.id')
            ->where('mu.channel', 'whatsapp')->where('mu.funcion', 'campanas');

        return response()->json([
            'contacts'       => (int) (DB::selectOne('SELECT COUNT(*) n FROM contacts c WHERE ' . ContactsController::SQL_CAMPANAS)->n ?? 0),
            'unread'         => (int) DB::table('contacts')->where('unread', '>', 0)->whereExists($esDeCampanas)->sum('unread'),
            'unread_chats'   => DB::table('contacts')->where('unread', '>', 0)->whereExists($esDeCampanas)->count(),
            'messages'       => $campanas(DB::table('messages'))->count(),
            /*
             * Rango de fechas en vez de DATE(created_at) = CURDATE(). Envolver la
             * columna en una función IMPIDE usar el índice: MySQL tiene que calcular
             * DATE() fila a fila sobre toda la tabla. Con 120.000 mensajes eran 22 ms
             * frente a 0,6 ms del rango, y la diferencia crece con el histórico.
             */
            'messages_today' => $campanas(DB::table('messages'))
                ->where('created_at', '>=', DB::raw('CURDATE()'))
                ->where('created_at', '<', DB::raw('CURDATE() + INTERVAL 1 DAY'))
                ->count(),
            'labels'         => DB::table('labels')->count(),
            'daily'          => $daily,
            'recent'         => $recent,
        ]);
    }
}
