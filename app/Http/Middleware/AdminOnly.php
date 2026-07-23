<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Solo administradores (se aplica DESPUÉS de 'token'). */
class AdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (!$u || !$u->isAdmin()) {
            return response()->json(['ok' => false, 'error' => 'Solo el administrador puede realizar esta acción'], 403);
        }
        return $next($request);
    }
}
