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
            'unread'        => $this->unread($request),
            'read'          => $this->read($request),
            'read_all'      => $this->readAll($request),
            'briefing'      => $this->briefing($request),
            'briefing_seen' => $this->briefingSeen($request),
            default         => $this->list($request),
        };
    }

    /**
     * RECIBIMIENTO MATUTINO: los tickets que POSPUSISTE y han despertado (por vencer la
     * fecha o por responder el cliente) desde tu última visita al recibimiento. El
     * frontend lo pide al cargar y lo enseña una vez. Solo tickets aún vivos.
     */
    protected function briefing(Request $request)
    {
        $me = $request->user();

        // Desde la última vez que lo vio; nunca más de 24 h atrás (no arrastrar días).
        $last  = DB::table('users')->where('id', $me->id)->value('snooze_briefing_at');
        $since = $last ? \Illuminate\Support\Carbon::parse($last) : now()->subDay();
        if ($since->lt(now()->subDay())) $since = now()->subDay();

        $rows = DB::table('ticket_events as e')
            ->join('tickets as t', 't.id', '=', 'e.ticket_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('e.type', 'snooze_wake')
            ->where('e.to_value', '!=', 'manual')   // reactivado a mano: no hace falta recordarlo
            ->where('e.user_id', $me->id)
            ->where('e.created_at', '>', $since)
            ->where('t.status', '!=', 'cerrado')
            ->whereNull('t.merged_into_id')
            ->orderByDesc('e.created_at')
            ->limit(20)
            ->get(['t.id', 't.code', 't.subject', 'e.to_value as reason', 'e.created_at',
                   'c.name as contact_name']);

        // Un ticket pudo despertar más de una vez: nos quedamos con el más reciente.
        $items = [];
        foreach ($rows as $r) { $items[$r->id] ??= $r; }

        return response()->json(['ok' => true, 'items' => array_values($items)]);
    }

    /** Marca el recibimiento como visto (para no repetirlo hasta que despierte algo nuevo). */
    protected function briefingSeen(Request $request)
    {
        DB::table('users')->where('id', $request->user()->id)->update(['snooze_briefing_at' => now()]);
        return response()->json(['ok' => true]);
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
