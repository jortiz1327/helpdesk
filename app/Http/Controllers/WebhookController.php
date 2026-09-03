<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\WhatsAppNumber;
use App\Services\CampaignService;
use App\Services\ChatService;
use App\Services\FlowEngine;
use App\Services\FlowsMetaService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Webhook de WhatsApp Cloud API. Portado de api/webhook.php. Ruta PÚBLICA (Meta la llama). */
class WebhookController extends Controller
{
    public function __construct(
        protected FlowEngine $flow,
        protected WhatsAppService $wa,
        protected CampaignService $campaigns,
    ) {}

    public function handle(Request $request)
    {
        // Verificación (GET) que hace Meta al suscribir el webhook
        if ($request->isMethod('get')) {
            $mode      = $request->query('hub_mode', '');
            $token     = $request->query('hub_verify_token', '');
            $challenge = $request->query('hub_challenge', '');
            if ($mode === 'subscribe' && $token === Setting::get('wa_verify_token')) {
                return response($challenge, 200);
            }
            return response('Forbidden', 403);
        }

        // Recepción de eventos (POST)
        $raw = $request->getContent();

        /*
         * VERIFICACIÓN DE FIRMA (X-Hub-Signature-256).
         * Meta firma el cuerpo con el App Secret (HMAC-SHA256). Sin esto, cualquiera que
         * conozca la URL podría inyectar mensajes falsos y disparar el bot de Campañas
         * (envíos de pago). Si hay App Secret configurado, la firma es OBLIGATORIA; si no lo
         * hay (p. ej. pruebas locales con curl), se permite pero la protección está INACTIVA
         * (se indica en Ajustes). Configura wa_app_secret antes de producción.
         */
        $appSecret = $this->appSecretDelEvento($raw);
        if ($appSecret === '') {
            /*
             * Sin App Secret no se puede verificar la firma. En LOCAL se permite (pruebas
             * con curl). En PRODUCCIÓN se RECHAZA: procesar un webhook sin firmar es una
             * puerta abierta a inyectar mensajes falsos y disparar el bot de Campañas
             * (envíos de pago). Configura wa_app_secret antes de producción.
             */
            if (app()->environment('production')) {
                Log::warning('Webhook rechazado: sin wa_app_secret en producción, no se puede verificar la firma.');
                return response('Webhook signature not configured', 403);
            }
        } else {
            $sig = (string) $request->header('X-Hub-Signature-256', '');
            $expected = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);
            if ($sig === '' || !hash_equals($expected, $sig)) {
                return response('Invalid signature', 403); // no es Meta: se descarta sin procesar
            }
        }

        // Guardar el payload crudo (mantener los últimos 200)
        try {
            DB::table('webhook_log')->insert(['payload' => $raw]);
            DB::statement('DELETE FROM webhook_log WHERE id < (SELECT * FROM (SELECT MAX(id) - 200 FROM webhook_log) t)');
        } catch (\Throwable $e) { /* silencioso */ }

        $data = json_decode($raw, true);

        // Responder 200 a Meta AL INSTANTE y procesar después de enviar la respuesta,
        // para que el motor nunca haga que Meta reintente por "tardar demasiado".
        if (is_array($data) && !empty($data['entry'])) {
            app()->terminating(fn () => $this->process($data));
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * App Secret con el que verificar la firma de ESTE evento. Cada app de Meta firma
     * con el suyo, así que se resuelve por el `phone_number_id` del evento (todos los de
     * un mismo POST son de la misma app). Si el número no está configurado o no tiene
     * App Secret propio, se cae al global (`wa_app_secret`).
     */
    protected function appSecretDelEvento(string $raw): string
    {
        $data = json_decode($raw, true);
        $phoneId = $data['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '';
        $num = WhatsAppNumber::porPhoneId((string) $phoneId);

        return ($num && $num->app_secret) ? (string) $num->app_secret : (string) Setting::get('wa_app_secret', '');
    }

    protected function process(array $data): void
    {
        // Opción B: si hay números configurados, se enruta por `phone_number_id`. Si la
        // tabla está vacía, todo cae en Campañas (chat en vivo); WhatsApp no crea tickets.
        $enrutar = WhatsAppNumber::hayConfigurados();

        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value   = $change['value'] ?? [];
                $phoneId = (string) ($value['metadata']['phone_number_id'] ?? '');

                // Todo el WhatsApp entrante es de Campañas (chat + bot). Nunca crea tickets.
                $funcion = 'campanas';
                if ($enrutar) {
                    $num = WhatsAppNumber::porPhoneId($phoneId);
                    if (!$num) {
                        Log::info("WhatsApp: evento de un número no configurado ($phoneId), se ignora.");
                        continue;   // número desconocido → no se crea nada
                    }
                    $funcion = $num->funcion;
                }

                $contactNames = [];
                foreach ($value['contacts'] ?? [] as $c) {
                    $contactNames[$c['wa_id']] = $c['profile']['name'] ?? null;
                }

                foreach ($value['messages'] ?? [] as $msg) {
                    $this->handleIncoming($msg, $contactNames, $funcion);
                }

                $this->handleStatuses($value['statuses'] ?? []);
            }
        }
    }

    protected function handleIncoming(array $msg, array $contactNames, string $funcion = 'campanas'): void
    {
        $from  = $msg['from'];
        $wamid = $msg['id'] ?? null;

        // DEDUP: Meta entrega los webhooks «al menos una vez» (reintenta si tardamos o
        // devolvemos 5xx). Si este mensaje ya se guardó, salimos: no duplicar el mensaje,
        // ni el envío de formulario, ni el avance del bot. (messages.wamid está indexado.)
        if ($wamid && DB::table('messages')->where('wamid', $wamid)->exists()) {
            return;
        }

        $name = $contactNames[$from] ?? null;
        $contactId = ChatService::upsertContact($from, $name);

        $type = $msg['type'] ?? 'text';
        $body = '';
        $replyId = null;
        $opts = ['wamid' => $wamid, 'status' => 'received'];

        switch ($type) {
            case 'text':
                $body = $msg['text']['body'] ?? '';
                break;
            case 'button':
                $body = $msg['button']['text'] ?? '';
                break;
            case 'interactive':
                $i = $msg['interactive'] ?? [];
                $body = ($i['type'] ?? '') === 'nfm_reply'
                    ? $this->formatearRespuestaFormulario($i['nfm_reply']['response_json'] ?? '{}')
                    : ($i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '');
                $replyId = $i['button_reply']['id'] ?? $i['list_reply']['id'] ?? null;
                break;
            case 'image': case 'video': case 'audio': case 'document': case 'sticker':
                $media = $msg[$type] ?? [];
                $body = $media['caption'] ?? '';
                $opts['media_url']  = $media['id'] ?? null;
                $opts['media_mime'] = $media['mime_type'] ?? null;
                break;
            case 'location':
                $loc = $msg['location'] ?? [];
                $body = '📍 ' . ($loc['latitude'] ?? '') . ', ' . ($loc['longitude'] ?? '');
                break;
            default:
                $body = '[' . $type . ']';
        }

        // Meta manda un mensaje 'unsupported' como contenedor de álbum (varias fotos a
        // la vez) o para tipos que la API no soporta. Las fotos llegan luego sueltas, así
        // que ese contenedor es ruido: no se guarda ni crea nada.
        if ($type === 'unsupported') {
            return;
        }

        // En esta edición WhatsApp SOLO alimenta Campañas: el mensaje entra como CHAT
        // («Chat en vivo»), NUNCA crea un ticket. Los tickets solo nacen por web o correo.
        $opts['ticket_id'] = null;
        $opts['channel'] = 'whatsapp';
        $opts['funcion'] = $funcion;   // siempre 'campanas'

        ChatService::storeMessage($contactId, $from, 'in', $type, $body, $opts);

        // A partir de aquí: formularios, consentimiento, bajas y motor de flujos (Campañas).

        // Campañas es chat: las respuestas del bot también van sueltas (sin ticket).
        $out = ['channel' => 'whatsapp', 'status' => 'sent'];

        // Respuesta de un WhatsApp Flow (formulario nativo)
        if ($type === 'interactive' && ($msg['interactive']['type'] ?? '') === 'nfm_reply') {
            $resp = json_decode($msg['interactive']['nfm_reply']['response_json'] ?? '{}', true) ?: [];
            $token = (string) ($resp['flow_token'] ?? '');
            unset($resp['flow_token']);
            if (preg_match('/^f(\d+)_/', $token, $mm)) {
                DB::table('form_submissions')->insert([
                    'form_id'    => (int) $mm[1],
                    'contact_id' => $contactId,
                    'data'       => json_encode($resp, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // Palabra/botón normalizado para baja/alta/consentimiento
        $kw = strtoupper(trim(preg_replace('/[^\p{L}\s]/u', '', $body)));
        $textLike = in_array($type, ['text', 'button', 'interactive'], true);
        $isOptKeyword = $textLike && in_array($kw, ['BAJA', 'ALTA'], true);

        // --- Consentimiento (primera vez) ---
        $skipFlow = false;
        if (Setting::get('consent_enabled', '0') === '1') {
            $crow = (array) DB::selectOne('SELECT consent, opted_out FROM contacts WHERE id = ?', [$contactId]);
            $isAccept = ($replyId === 'consent_accept') || ($textLike && $kw === 'ACEPTO');

            if ($isAccept) {
                if ((int) ($crow['consent'] ?? 0) !== 2) {
                    DB::update('UPDATE contacts SET consent = 2, consent_at = NOW() WHERE id = ?', [$contactId]);
                    $ok = '✅ ¡Gracias! Has aceptado recibir nuestras comunicaciones. ¿En qué podemos ayudarte?';
                    [$rc, $rr] = $this->wa->sendText($from, $ok);
                    if ($rc >= 200 && $rc < 300 && !empty($rr['messages'][0]['id'])) {
                        ChatService::storeMessage($contactId, $from, 'out', 'text', $ok, $out + ['wamid' => $rr['messages'][0]['id']]);
                    }
                }
            } elseif (!$isOptKeyword && (int) ($crow['consent'] ?? 0) === 0 && (int) ($crow['opted_out'] ?? 0) !== 1) {
                $txt = (string) Setting::get('consent_message', '') ?: SettingsController::consentDefault();
                $txt = str_replace(['{{{senderName}}}', '{{senderName}}'], $name ?: '', $txt);
                $ix = [
                    'type'   => 'button',
                    'body'   => ['text' => mb_substr($txt, 0, 1024)],
                    'action' => ['buttons' => [
                        ['type' => 'reply', 'reply' => ['id' => 'consent_accept', 'title' => 'Acepto']],
                        ['type' => 'reply', 'reply' => ['id' => 'consent_baja',   'title' => 'BAJA']],
                    ]],
                ];
                [$rc, $rr] = $this->wa->sendInteractive($from, $ix);
                if ($rc >= 200 && $rc < 300 && !empty($rr['messages'][0]['id'])) {
                    ChatService::storeMessage($contactId, $from, 'out', 'interactive', $txt, $out + ['wamid' => $rr['messages'][0]['id'], 'payload' => json_encode($ix, JSON_UNESCAPED_UNICODE)]);
                }
                DB::update('UPDATE contacts SET consent = 1 WHERE id = ?', [$contactId]);
                $skipFlow = true;
            }
        }

        // --- Baja / alta (opt-out) idempotente ---
        if ($isOptKeyword) {
            $want = $kw === 'BAJA' ? 1 : 0;
            $curVal = (int) DB::table('contacts')->where('id', $contactId)->value('opted_out');
            if ($curVal !== $want) {
                if ($want === 1) {
                    DB::update('UPDATE contacts SET opted_out = 1, opted_out_at = NOW() WHERE id = ?', [$contactId]);
                    $reply = '✅ Hecho. No volverás a recibir mensajes promocionales nuestros. Si cambias de idea, escribe ALTA en cualquier momento.';
                } else {
                    DB::update('UPDATE contacts SET opted_out = 0, opted_out_at = NULL WHERE id = ?', [$contactId]);
                    $reply = '✅ Te has vuelto a suscribir. Volverás a recibir nuestras novedades. Escribe BAJA para darte de baja cuando quieras.';
                }
                [$rc, $rr] = $this->wa->sendText($from, $reply);
                if ($rc >= 200 && $rc < 300 && !empty($rr['messages'][0]['id'])) {
                    ChatService::storeMessage($contactId, $from, 'out', 'text', $reply, $out + ['wamid' => $rr['messages'][0]['id']]);
                }
            }
        }

        // --- Motor de automatización ---
        $flowTypes = ['text', 'button', 'interactive', 'image', 'audio', 'video', 'document', 'sticker', 'location'];
        if (!$isOptKeyword && !$skipFlow && in_array($type, $flowTypes, true) && !($textLike && trim($body) === '')) {
            try {
                // Campañas es chat puro: el flujo no cuelga de ningún ticket.
                $this->flow->handle(['id' => $contactId, 'wa_id' => $from, 'ticket_id' => null], $body, $name, $type, $replyId);
            } catch (\Throwable $e) { /* no romper el webhook */ }
        }
    }

    /** Texto legible de una respuesta de formulario, para verlo en el propio chat. */
    private function formatearRespuestaFormulario(string $json): string
    {
        $resp = json_decode($json, true) ?: [];
        $token = (string) ($resp['flow_token'] ?? '');
        unset($resp['flow_token']);
        $formId = preg_match('/^f(\d+)_/', $token, $mm) ? (int) $mm[1] : 0;
        $pares = ($formId && $resp) ? FlowsMetaService::etiquetar($formId, $resp) : [];
        if (!$pares) return '📋 Respuesta de formulario';
        $lineas = array_map(fn ($p) => '• ' . $p['label'] . ': ' . $p['value'], $pares);
        return "📋 Respuesta del formulario:\n" . implode("\n", $lineas);
    }

    protected function handleStatuses(array $statuses): void
    {
        $rankCase = "(CASE status WHEN 'failed' THEN 9 WHEN 'read' THEN 3 WHEN 'delivered' THEN 2 WHEN 'sent' THEN 1 ELSE 0 END)";
        $ranks = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 9];

        foreach ($statuses as $st) {
            $wamid  = $st['id'] ?? null;
            $status = $st['status'] ?? null;
            $nr = $ranks[$status] ?? 0;
            if (!$wamid || !$nr) continue;

            $err = null;
            if ($status === 'failed') {
                $e = $st['errors'][0] ?? [];
                $err = $e['title'] ?? $e['message'] ?? ($e['error_data']['details'] ?? 'Error de entrega');
            }

            DB::update("UPDATE messages SET status = ? WHERE wamid = ? AND ? > $rankCase", [$status, $wamid, $nr]);
            // Motivo del fallo de entrega, para verlo en la ficha (solo mensajes de texto,
            // que no usan payload; no se pisa el payload de interactivos/formularios).
            if ($status === 'failed' && $err) {
                DB::table('messages')->where('wamid', $wamid)->whereNull('payload')
                    ->update(['payload' => json_encode(['delivery_error' => $err], JSON_UNESCAPED_UNICODE)]);
            }
            $affected = DB::update("UPDATE campaign_recipients SET status = ?, error = COALESCE(?, error) WHERE wamid = ? AND ? > $rankCase", [$status, $err, $wamid, $nr]);

            if ($status === 'failed' && $affected > 0) {
                $cid = DB::table('campaign_recipients')->where('wamid', $wamid)->value('campaign_id');
                if ($cid) $this->campaigns->recalc((int) $cid);
            }
        }
    }
}
