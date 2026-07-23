<?php

namespace App\Http\Controllers;

use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * APARTADO DE CRONES. Los avisos de crones fallidos, agrupados por cron.
 *
 * No se abren como una conversación —nadie le contesta a un cron—: se ven como una
 * ficha técnica con los datos de la avería y el histórico de ejecuciones.
 */
class CronAlertsController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'detail'  => $this->detail($request),
            'resolve' => $this->resolve($request),
            // Solo los contadores, para el distintivo de la pestaña: pedir la lista
            // entera (hasta 300 filas) solo para pintar un número es un despilfarro.
            'counts'  => response()->json(['ok' => true, 'counts' => $this->contadores()]),
            default   => $this->list($request),
        };
    }

    protected function list(Request $request)
    {
        $q = DB::table('cron_alerts as a')->join('tickets as t', 't.id', '=', 'a.ticket_id');

        if ($s = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('a.cron_name', 'like', "%$s%")
                  ->orWhere('a.params', 'like', "%$s%")
                  ->orWhere('a.last_reason', 'like', "%$s%");
            });
        }

        // Por defecto solo los que siguen fallando; los resueltos se piden aparte.
        $estado = $request->query('status', 'open');
        if ($estado === 'open')        $q->whereIn('t.status', TicketService::OPEN_STATUSES);
        elseif ($estado === 'resolved') $q->whereNotIn('t.status', TicketService::OPEN_STATUSES);

        $filas = $q->orderByDesc('a.last_at')
            ->limit(300)
            ->get([
                'a.id', 'a.ticket_id', 'a.cron_name', 'a.params', 'a.expression',
                'a.fails', 'a.first_at', 'a.last_at', 'a.last_exit_code', 'a.last_reason',
                't.code', 't.status',
            ]);

        return response()->json(['ok' => true, 'alerts' => $filas, 'counts' => $this->contadores()]);
    }

    /** Cuántos crones fallando, cuántos resueltos y cuántos fallos acumulados. */
    protected function contadores(): array
    {
        return [
            'open'     => (clone $this->base())->whereIn('t.status', TicketService::OPEN_STATUSES)->count(),
            'resolved' => (clone $this->base())->whereNotIn('t.status', TicketService::OPEN_STATUSES)->count(),
            'fails'    => (int) (clone $this->base())->whereIn('t.status', TicketService::OPEN_STATUSES)->sum('a.fails'),
            /*
             * Los que han EMPEZADO a fallar en las últimas 24 h. Es el dato que de
             * verdad es noticia: cinco crones rotos desde hace semanas son ruido de
             * fondo, pero uno que empieza hoy es algo que acaba de romperse.
             */
            'nuevos'   => (clone $this->base())->whereIn('t.status', TicketService::OPEN_STATUSES)
                ->where('a.first_at', '>=', now()->subDay())->count(),
        ];
    }

    /** Ficha técnica: los datos de la avería y las últimas ejecuciones. */
    protected function detail(Request $request)
    {
        $a = DB::table('cron_alerts as a')->join('tickets as t', 't.id', '=', 'a.ticket_id')
            ->where('a.id', (int) $request->query('id'))
            ->first(['a.*', 't.code', 't.status', 't.assigned_to']);

        if (!$a) return response()->json(['ok' => false, 'error' => 'Aviso no encontrado'], 404);

        /*
         * Histórico. El resumen sale de los datos ya ANALIZADOS (payload), no del
         * cuerpo del correo: ese trae firmas y cabeceras de reenvío y resumiría
         * «Un saludo, Juan Cruz…» en vez de la avería.
         */
        $ejecuciones = DB::table('messages')->where('ticket_id', $a->ticket_id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)
            ->get(['id', 'created_at', 'payload']);

        foreach ($ejecuciones as $e) {
            $p = json_decode((string) $e->payload, true) ?: [];
            $e->exit_code = $p['exit_code'] ?? null;
            $e->resumen = trim(($p['reason'] ?? 'Fallo') . (!empty($p['output']) ? ' · ' . $p['output'] : ''));
            $e->resumen = mb_substr(preg_replace('/\s+/u', ' ', $e->resumen) ?? $e->resumen, 0, 300);
            unset($e->payload);
        }

        return response()->json(['ok' => true, 'alert' => $a, 'runs' => $ejecuciones]);
    }

    /**
     * Marcar como resuelto (o reabrir), uno o VARIOS de golpe. Si vuelve a fallar,
     * el aviso se reabre solo, así que resolver aquí no es una decisión delicada.
     */
    protected function resolve(Request $request)
    {
        if (!$request->user()->can('tickets.reply')) {
            return response()->json(['ok' => false, 'error' => 'Sin permiso'], 403);
        }

        // Admite `id` suelto o `ids` en lote, para no tener dos rutas que hacen lo mismo.
        $ids = array_filter(array_map('intval', (array) ($request->input('ids') ?: [$request->input('id')])));
        if (!$ids) return response()->json(['ok' => false, 'error' => 'No has elegido ningún aviso'], 400);

        $tickets = DB::table('cron_alerts')->whereIn('id', $ids)->pluck('ticket_id');
        if ($tickets->isEmpty()) return response()->json(['ok' => false, 'error' => 'Aviso no encontrado'], 404);

        $abrir  = filter_var($request->input('reopen', false), FILTER_VALIDATE_BOOLEAN);
        $estado = $abrir ? 'abierto' : 'resuelto';
        $svc    = app(TicketService::class);
        $uid    = (int) $request->user()->id;

        foreach ($tickets as $tid) {
            // sin aviso por correo: el destinatario sería un «noreply@»
            $svc->setStatus((int) $tid, $estado, $uid, false);
        }

        return response()->json(['ok' => true, 'affected' => $tickets->count(), 'counts' => $this->contadores()]);
    }

    protected function base()
    {
        return DB::table('cron_alerts as a')->join('tickets as t', 't.id', '=', 'a.ticket_id');
    }
}
