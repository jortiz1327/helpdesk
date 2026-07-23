<?php

namespace App\Http\Controllers;

use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * attachment.php — descarga de un adjunto.
 *
 * Los ficheros viven FUERA de public/, así que solo se llega a ellos por aquí,
 * y por aquí se pasa por el middleware de token. Además se comprueba que el
 * usuario pueda ver el ticket al que pertenece el adjunto: si no, un agente
 * podría bajarse ficheros de tickets que no le corresponden probando ids.
 */
class AttachmentController extends Controller
{
    public function __construct(protected AttachmentService $attachments) {}

    public function handle(Request $request)
    {
        $me = $request->user();
        $id = (int) $request->query('id');

        $found = $this->attachments->find($id);
        if (!$found) {
            return response()->json(['error' => 'Adjunto no encontrado'], 404);
        }
        [$path, $row] = $found;

        // ¿Puede este usuario ver el ticket del adjunto?
        if (!$me->can('tickets.view_all')) {
            $assigned = DB::table('tickets')->where('id', $row->ticket_id)->value('assigned_to');
            if ((int) $assigned !== (int) $me->id) {
                return response()->json(['error' => 'Sin acceso a este adjunto'], 403);
            }
        }

        // inline para las imágenes (se ven en el hilo), descarga para el resto
        $inline = str_starts_with((string) $row->mime, 'image/');

        return response()->file($path, [
            'Content-Type'        => $row->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($row->name) . '"',
        ]);
    }

    /**
     * Sirve una imagen EN LÍNEA del correo (las «cid:» de la firma) por URL FIRMADA,
     * sin token: igual que las imágenes del editor, la firma es la autorización y así
     * el <img> del cuerpo carga sin cabeceras. SOLO imágenes (nunca ficheros arbitrarios).
     */
    public function serveInline(int $id)
    {
        $row = DB::table('attachments')->where('id', $id)->first();
        if (!$row || !str_starts_with((string) $row->mime, 'image/') || !Storage::disk('local')->exists($row->path)) {
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
