<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use App\Services\GatingService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

/** Portado de api/send.php — envío de texto/plantilla/interactivo. Requiere token. */
class SendController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        if (!$request->isMethod('post')) {
            return response()->json(['error' => 'Método no permitido'], 405);
        }

        // Candado: sin WhatsApp configurado no se envía nada (respuesta limpia al UI).
        if ($locked = GatingService::guard('wa_send')) return $locked;

        $contactId = (int) $request->input('contact_id');
        $to        = preg_replace('/\D/', '', (string) $request->input('to'));
        $type      = $request->input('type', 'text');

        if (!$to) return response()->json(['error' => 'Falta el número de destino'], 400);

        if (!$contactId) {
            $contactId = ChatService::upsertContact($to);
        }

        $payload = null;

        if ($type === 'template') {
            $name = $request->input('template_name', '');
            $lang = $request->input('language', 'es');
            $components = $request->input('components', []);
            if (!$name) return response()->json(['error' => 'Falta el nombre de la plantilla'], 400);
            [$code, $res] = $wa->sendTemplate($to, $name, $lang, is_array($components) ? $components : []);
            $bodyPreview = '📋 Plantilla: ' . $name;
        } elseif ($type === 'interactive') {
            $interactive = $request->input('interactive');
            if (!is_array($interactive) || empty($interactive['type'])) {
                return response()->json(['error' => 'Mensaje interactivo no válido'], 400);
            }
            [$code, $res] = $wa->sendInteractive($to, $interactive);
            $bodyPreview = trim($interactive['body']['text'] ?? '');
            if ($bodyPreview === '') {
                $bodyPreview = ($interactive['type'] ?? '') === 'list' ? '📋 Lista' : '🔘 Botones';
            }
            $payload = json_encode($interactive, JSON_UNESCAPED_UNICODE);
        } else {
            $body = trim((string) $request->input('body'));
            if ($body === '') return response()->json(['error' => 'El mensaje está vacío'], 400);
            [$code, $res] = $wa->sendText($to, $body);
            $bodyPreview = $body;
        }

        if ($code >= 200 && $code < 300 && !empty($res['messages'][0]['id'])) {
            $wamid = $res['messages'][0]['id'];
            $msgId = ChatService::storeMessage($contactId, $to, 'out', $type, $bodyPreview, [
                'wamid'   => $wamid,
                'status'  => 'sent',
                'sent_by' => $request->user()->id,
                'payload' => $payload,
            ]);
            return response()->json(['ok' => true, 'message_id' => $msgId, 'wamid' => $wamid]);
        }

        $err = $res['error']['message'] ?? 'Error desconocido al enviar';
        return response()->json(['ok' => false, 'error' => $err, 'detail' => $res], $code ?: 500);
    }
}
