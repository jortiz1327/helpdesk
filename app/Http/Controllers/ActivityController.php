<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * REGISTRO DE ACCIONES (auditoría). Solo lectura. La ruta ya exige activity.view,
 * que solo tiene el superadministrador. Aquí se listan las acciones con filtros por
 * usuario / apartado / fecha y buscador, en páginas para el scroll infinito.
 */
class ActivityController extends Controller
{
    private const POR_PAGINA = 60;

    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'meta'  => $this->meta(),
            default => $this->list($request),
        };
    }

    /* Opciones para los filtros: quién ha hecho algo y en qué apartados. */
    private function meta()
    {
        $usuarios = DB::table('activity_log')
            ->select('user_id', DB::raw('MAX(user_name) as user_name'), DB::raw('COUNT(*) as n'))
            ->groupBy('user_id')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($u) => ['id' => $u->user_id, 'name' => $u->user_name ?: 'Sistema', 'n' => (int) $u->n]);

        $apartados = DB::table('activity_log')
            ->select('section', DB::raw('COUNT(*) as n'))
            ->groupBy('section')
            ->orderByDesc('n')
            ->pluck('section');

        return response()->json([
            'ok'        => true,
            'usuarios'  => $usuarios,
            'apartados' => $apartados,
            'total'     => DB::table('activity_log')->count(),
        ]);
    }

    private function list(Request $request)
    {
        $q = DB::table('activity_log');

        if (($uid = $request->query('user_id')) !== null && $uid !== '') {
            $q->where('user_id', (int) $uid);
        }
        if (($sec = $request->query('section')) && $sec !== 'all') {
            $q->where('section', $sec);
        }
        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to . ' 23:59:59');
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('summary', 'like', $like)
                  ->orWhere('subject', 'like', $like)
                  ->orWhere('user_name', 'like', $like);
            });
        }

        $page = max(1, (int) $request->query('page', 1));
        $rows = $q->orderByDesc('id')
            ->offset(($page - 1) * self::POR_PAGINA)
            ->limit(self::POR_PAGINA + 1)   // +1 para saber si hay más
            ->get([
                'id', 'user_id', 'user_name', 'section', 'action',
                'summary', 'subject', 'ip', 'created_at',
            ]);

        $hayMas = $rows->count() > self::POR_PAGINA;

        return response()->json([
            'ok'      => true,
            'rows'    => $rows->take(self::POR_PAGINA)->values(),
            'has_more'=> $hayMas,
            'page'    => $page,
        ]);
    }
}
