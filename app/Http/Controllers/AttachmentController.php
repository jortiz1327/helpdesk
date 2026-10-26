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

        // ¿Puede este usuario ver el ticket del adjunto? MISMO criterio que la bandeja,
        // en una ÚNICA fuente (TicketVisibility): con view_all todos; si no, los de sus
        // categorías, los asignados a él, o los cerrados. Así no diverge de la lista.
        if (!\App\Support\TicketVisibility::puedeVer($me, (int) $row->ticket_id)) {
            return response()->json(['error' => 'Sin acceso a este adjunto'], 403);
        }

        // inline para las imágenes (se ven en el hilo), descarga para el resto. El tipo sale
        // de NUESTRA extensión en disco, no del que declaró quien lo envió.
        $mime   = \App\Services\AttachmentService::mimeDe((string) $row->path);
        $inline = \App\Services\AttachmentService::imagenSegura($mime);

        return response()->file($path, [
            'Content-Type'        => $mime,
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
        $mime = $row ? \App\Services\AttachmentService::mimeDe((string) $row->path) : null;
        if (!$row || !\App\Services\AttachmentService::imagenSegura($mime) || !Storage::disk('local')->exists($row->path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($row->path), 200, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=31536000',
        ]);
    }
}
