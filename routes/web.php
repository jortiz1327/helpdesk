<?php

use Illuminate\Support\Facades\Route;

/*
 * Sirve la SPA (el index.html del build de Vite). Se hace SIEMPRE con cabeceras
 * ANTI-CACHÉ: el index es lo único que apunta a los assets con hash, así que si el
 * navegador se lo guarda, tras un despliegue seguirá pidiendo ficheros que ya no
 * existen (el bug del «módulo con MIME raro» / de ver una versión vieja). Los
 * assets con hash sí se cachean para siempre —su nombre cambia si cambia el
 * contenido—, pero eso se controla en el .htaccess (los sirve el servidor web
 * directamente, no pasan por aquí).
 */
$spa = fn () => response()->file(public_path('index.html'), [
    'Cache-Control' => 'no-cache, no-store, must-revalidate',
    'Pragma'        => 'no-cache',
    'Expires'       => '0',
]);

Route::get('/', $spa);

/*
 * Cualquier otra ruta se resuelve también a la SPA: la vista vive en la URL
 * (`/agentes/tickets`, …), así que recargar ahí tiene que devolver la app.
 *
 * Dos excepciones que sí son 404 de verdad:
 *  · la API, para que un endpoint mal escrito falle como debe y no devuelva HTML;
 *  · los ASSETS: si un index viejo pide un fichero con hash que ya no existe, mejor
 *    un 404 limpio que la página entera (que el navegador leería como «módulo con
 *    MIME type inesperado» y mandaría a buscar el fallo donde no está).
 */
Route::fallback(function () use ($spa) {
    if (request()->is('api/*') || request()->is('assets/*')) abort(404);
    return $spa();
});
