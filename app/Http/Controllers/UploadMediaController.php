<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\Request;

/** Portado de api/upload_media.php — subida reanudable para media de plantillas. Requiere token. */
class UploadMediaController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No se recibió el archivo'], 400);
        }

        [$ok, $handleOrErr, $detail] = $wa->uploadResumable(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $file->getClientMimeType() ?: 'application/octet-stream'
        );

        if ($ok) {
            return response()->json(['ok' => true, 'handle' => $handleOrErr]);
        }
        return response()->json(['ok' => false, 'error' => $handleOrErr, 'detail' => $detail], 502);
    }
}
