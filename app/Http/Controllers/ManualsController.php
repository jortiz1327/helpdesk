<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Manuales descargables (apartado «Ayuda»). Cada usuario ve SOLO los manuales de
 * su rol (según `perms` del catálogo en config/manuals.php). Los PDF viven fuera de
 * public/ (resources/manuals): se sirven autenticados, nunca por URL directa.
 */
class ManualsController extends Controller
{
    public function handle(Request $request)
    {
        return $request->query('action') === 'download'
            ? $this->download($request)
            : $this->list($request);
    }

    /** Lista los manuales que ESTE usuario puede ver (con su tamaño en KB). */
    protected function list(Request $request)
    {
        $user = $request->user();
        $out  = [];
        foreach ((array) config('manuals.catalog', []) as $key => $m) {
            if (!$this->puede($user, $m['perms'] ?? [])) continue;
            $path = resource_path('manuals/' . $m['file']);
            if (!is_file($path)) continue;   // aún sin PDF: no se ofrece
            $out[] = [
                'key'   => $key,
                'title' => $m['title'],
                'desc'  => $m['desc'] ?? '',
                'kb'    => (int) round(filesize($path) / 1024),
            ];
        }
        return response()->json(['ok' => true, 'manuals' => $out]);
    }

    /** Descarga un manual (comprobando de nuevo el permiso: la lista no es la autorización). */
    protected function download(Request $request)
    {
        $key = (string) $request->query('key', '');
        $m   = config('manuals.catalog.' . $key);
        if (!$m || !$this->puede($request->user(), $m['perms'] ?? [])) {
            abort(403);
        }
        $path = resource_path('manuals/' . $m['file']);
        if (!is_file($path)) abort(404);

        return response()->download($path, $m['file'], ['Content-Type' => 'application/pdf']);
    }

    /** ¿Puede el usuario ver este manual? '*' = cualquiera con sesión; si no, canAny. */
    protected function puede($user, array $perms): bool
    {
        if (in_array('*', $perms, true)) return true;
        return $user && $perms && $user->canAny($perms);
    }
}
