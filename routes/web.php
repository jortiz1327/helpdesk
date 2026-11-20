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

// Revisión del conjunto de pruebas de la IA (casos reales anonimizados): SOLO local, igual que /ia.
Route::prefix('ia/casos')->controller(\App\Http\Controllers\AiCasosController::class)->group(function () {
    Route::get('/', 'page');
    Route::get('/app.js', 'script');
    Route::get('/api/lista', 'lista');
    Route::post('/api/{id}', 'guardar')->whereNumber('id');
});

// Flujo de la IA para los agentes (también en producción): solo con el enlace firmado que da la app (Ayuda › Manuales).
Route::get('/flujo-ia', [\App\Http\Controllers\AiFlowController::class, 'page'])->middleware('signed:relative')->name('ia.flujo.agentes');

// Chats de prueba de la IA: SOLO local (el controlador da 404 si config('ai.lab') está apagado).
Route::prefix('ia')->controller(\App\Http\Controllers\AiLabController::class)->group(function () {
    Route::get('/', 'page');
    Route::get('/flujo', 'flujo');
    Route::get('/{id}', 'page')->whereNumber('id');
    Route::get('/app.js', 'script');
    Route::get('/api/chats', 'chats');
    Route::post('/api/chats', 'create');
    Route::get('/api/chats/{id}', 'show')->whereNumber('id');
    Route::delete('/api/chats/{id}', 'destroy')->whereNumber('id');
    Route::post('/api/chats/{id}/send', 'send')->whereNumber('id');
    Route::post('/api/chats/{id}/reply', 'reply')->whereNumber('id');
    Route::post('/api/chats/{id}/regenerate', 'regenerate')->whereNumber('id');
    Route::post('/api/messages/{msg}/rate', 'rate')->whereNumber('msg');
});

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
