<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * notifications.php — el centro de notificaciones del agente (campana).
 *   · list      → últimas notificaciones del usuario (no leídas primero)
 *   · unread    → solo el contador (lo sondea el frontend cada ~45s)
 *   · read      → marca una leída · read_all → todas
 */
class NotificationsController extends Controller
{
    public function __construct(protected NotificationService $notifs) {}

    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'unread'   => $this->unread($request),
            'read'     => $this->read($request),
            'read_all' => $this->readAll($request),
            default    => $this->list($request),
        };
    }

    protected function list(Request $request)
    {
        $me = $request->user();
        $rows = DB::table('notifications as n')
            ->leftJoin('tickets as t', 't.id', '=', 'n.ticket_id')
            ->leftJoin('users as a', 'a.id', '=', 'n.actor_user_id')
            ->where('n.user_id', $me->id)
            ->orderByRaw('n.read_at IS NULL DESC')   // no leídas arriba
            ->orderByDesc('n.id')
            ->limit(40)
            ->get([
                'n.id', 'n.type', 'n.ticket_id', 'n.body', 'n.read_at', 'n.created_at',
                't.code as ticket_code', 'a.name as actor_name',
            ]);

        return response()->json([
            'ok'            => true,
            'notifications' => $rows,
            'unread'        => $this->notifs->unread((int) $me->id),
        ]);
    }

    protected function unread(Request $request)
    {
        return response()->json(['ok' => true, 'unread' => $this->notifs->unread((int) $request->user()->id)]);
    }

    protected function read(Request $request)
    {
        DB::table('notifications')
            ->where('id', (int) $request->input('id'))
            ->where('user_id', $request->user()->id)   // solo las propias
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => $this->notifs->unread((int) $request->user()->id)]);
    }

    protected function readAll(Request $request)
    {
        DB::table('notifications')
            ->where('user_id', $request->user()->id)->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => 0]);
    }
}
