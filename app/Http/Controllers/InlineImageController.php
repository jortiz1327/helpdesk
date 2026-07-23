<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Imágenes EN LÍNEA del editor (pegar/insertar dentro del texto de respuestas y notas).
 * Seguridad:
 *  - Solo imágenes (mime real + extensión en lista blanca), límite de tamaño.
 *  - Se guardan en disco PRIVADO (fuera de public/), nombre UUID (sin travesía).
 *  - Se sirven por URL FIRMADA (middleware 'signed'): a prueba de manipulación y sin
 *    token del usuario en el HTML guardado. Se devuelve con nosniff (no se "adivina" el tipo).
 */
class InlineImageController extends Controller
{
    protected const EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    protected const MAX = 8 * 1024 * 1024; // 8 MB

    /** Sube una imagen y devuelve su URL firmada (relativa, portable entre hosts). */
    public function upload(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No se recibió la imagen'], 400);
        }

        $ext  = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();   // detectado por el servidor, no el del cliente
        if (!in_array($ext, self::EXT, true) || !str_starts_with($mime, 'image/')) {
            return response()->json(['ok' => false, 'error' => 'Solo se admiten imágenes (jpg, png, gif, webp)'], 400);
        }
        if ($file->getSize() > self::MAX) {
            return response()->json(['ok' => false, 'error' => 'La imagen supera los 8 MB'], 400);
        }

        $path = $file->storeAs('inline/' . date('Y/m'), Str::uuid() . '.' . $ext, 'local');
        $id = DB::table('inline_uploads')->insertGetId([
            'path' => $path, 'mime' => $mime, 'size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id, 'created_at' => now(),
        ]);

        return response()->json([
            'ok'  => true,
            'url' => URL::signedRoute('inline.image', ['id' => $id], null, false), // relativa
        ]);
    }

    /** Sirve la imagen; la validez la garantiza la firma de la URL (middleware 'signed:relative'). */
    public function serve(int $id)
    {
        $row = DB::table('inline_uploads')->find($id);
        if (!$row || !Storage::disk('local')->exists($row->path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($row->path), 200, [
            'Content-Type'           => $row->mime,
            'Content-Disposition'    => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=31536000',
        ]);
    }
}
