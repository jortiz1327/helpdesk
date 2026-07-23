<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\Request;

/** Portado de api/media.php — proxy que descarga y sirve un medio de Meta. Requiere token (?token=). */
class MediaController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        $mediaId = preg_replace('/[^0-9]/', '', (string) $request->query('id'));
        if (!$mediaId) return response('id requerido', 400);

        $m = $wa->mediaBinary($mediaId);
        if (!$m) return response('medio no encontrado', 404);

        return response($m['binary'], 200, [
            'Content-Type'  => $m['type'],
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
