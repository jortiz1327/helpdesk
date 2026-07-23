<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Exige un token válido; deja el usuario disponible en $request->user() y en el guard. */
class TokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('X-App-Token')
            ?: $request->bearerToken()
            ?: $request->query('token');

        $user = TokenService::verify($token);
        if (!$user) {
            return response()->json(['error' => 'No autenticado', 'authenticated' => false], 401);
        }

        $request->setUserResolver(fn () => $user);

        // Registrar el usuario en el guard (sin sesión). Es lo que hace que funcionen
        // Gate, $user->can() y los middleware de permisos de spatie, que resuelven el
        // usuario a través de Auth::guard()->user() y no de $request->user().
        Auth::setUser($user);

        return $next($request);
    }
}
