<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Valida la FORMA de la entrada y devuelve los datos ya validados. Como esta app
     * despacha por `?action=` (un `handle()` por controlador), no encajan los Form
     * Requests; este helper cumple el mismo papel en una línea. Si falla, lanza
     * ValidationException, que `bootstrap/app.php` convierte en un 422 JSON uniforme.
     * Las reglas de NEGOCIO/estado (404/403/409) siguen como comprobaciones aparte.
     */
    protected function validar(Request $request, array $reglas, array $mensajes = []): array
    {
        return $request->validate($reglas, $mensajes);
    }
}
