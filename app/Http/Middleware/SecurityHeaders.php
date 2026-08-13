<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para toda respuesta. Endurece el navegador frente a
 * clickjacking, sniffing de tipo, fuga de referer y carga de recursos ajenos.
 *
 * La CSP está ajustada a lo que usa la app: su propio JS/CSS (self), estilos en
 * línea de React, y las tipografías de Google (Inter). El websocket de Reverb va
 * por connect-src ws/wss. Si en el futuro se añade algún recurso externo, hay que
 * permitirlo aquí explícitamente.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HSTS solo sobre HTTPS (en HTTP el navegador lo ignora; se evita mandarlo en local).
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Política de contenido. self + estilos en línea (React) + Google Fonts + ws de Reverb.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "media-src 'self' data: blob:",
            "connect-src 'self' ws: wss:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
