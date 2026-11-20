<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Flujo de la IA para los agentes (apartado «Ayuda»). Es una página aparte, solo lectura, que se abre en otra pestaña.
 * Solo entra quien está en la app: la API (con su permiso) da un enlace FIRMADO y con caducidad (relativo, para que no
 * dependa del dominio ni del proxy); la página no se abre sin esa firma.
 */
class AiFlowController extends Controller
{
    /** API: devuelve el enlace firmado (1 hora). Requiere el mismo permiso que usar el helpdesk. */
    public function link(Request $request)
    {
        return response()->json(['ok' => true, 'url' => URL::temporarySignedRoute('ia.flujo.agentes', now()->addHour(), [], false)]);
    }

    /** Página: solo con enlace firmado válido (middleware signed:relative). */
    public function page()
    {
        return response()->view('ia-flujo-agentes')->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'private, no-store');
    }
}
