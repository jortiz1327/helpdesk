<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

/** Portado de api/send_media.php — envío de imagen/vídeo/audio/documento. Requiere token. */
class SendMediaController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'Método no permitido'], 405);
        }

        $contactId = (int) $request->input('contact_id');
        $to        = preg_replace('/\D/', '', (string) $request->input('to'));
        $type      = $request->input('type', '');
        $caption   = trim((string) $request->input('caption'));

        $allowed = ['image', 'video', 'audio', 'document'];
        if (!$to) return response()->json(['error' => 'Falta el número de destino'], 400);
        if (!in_array($type, $allowed, true)) return response()->json(['error' => 'Tipo de medio no válido'], 400);

        $file = $request->file('file');
        if (!$file || !$file->isValid()) return response()->json(['error' => 'No se recibió el archivo'], 400);

        if (!$contactId) $contactId = ChatService::upsertContact($to);

        $mime  = $file->getClientMimeType() ?: 'application/octet-stream';
        $fname = $file->getClientOriginalName() ?: 'archivo';

        // 1) Subir a Meta -> media_id
        [$uc, $ur] = $wa->uploadMedia($file->getRealPath(), $mime, $fname);
        $mediaId = $ur['id'] ?? null;
        if (!$mediaId) {
            return response()->json(['ok' => false, 'error' => $ur['error']['message'] ?? 'No se pudo subir el archivo', 'detail' => $ur], $uc ?: 502);
        }

        // 2) Enviar el mensaje
        [$code, $res] = $wa->sendMedia($to, $type, $mediaId, $caption, $type === 'document' ? $fname : null);

        if ($code >= 200 && $code < 300 && !empty($res['messages'][0]['id'])) {
            $wamid = $res['messages'][0]['id'];
            $body  = $caption !== '' ? $caption : ($type === 'document' ? $fname : '');
            $msgId = ChatService::storeMessage($contactId, $to, 'out', $type, $body, [
                'wamid'      => $wamid,
                'status'     => 'sent',
                'sent_by'    => $request->user()->id,
                'media_url'  => $mediaId,
                'media_mime' => $mime,
            ]);
            return response()->json(['ok' => true, 'message_id' => $msgId, 'wamid' => $wamid, 'media_id' => $mediaId]);
        }

        $err = $res['error']['message'] ?? 'Error desconocido al enviar';
        return response()->json(['ok' => false, 'error' => $err, 'detail' => $res], $code ?: 500);
    }
}
