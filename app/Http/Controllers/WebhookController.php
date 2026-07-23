<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CampaignService;
use App\Services\ChatService;
use App\Services\FlowEngine;
use App\Services\TicketService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Webhook de WhatsApp Cloud API. Portado de api/webhook.php. Ruta PÚBLICA (Meta la llama). */
class WebhookController extends Controller
{
    public function __construct(
        protected FlowEngine $flow,
        protected WhatsAppService $wa,
        protected CampaignService $campaigns,
        protected TicketService $tickets,
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
         * conozca la URL podría inyectar mensajes falsos → tickets falsos y disparar el bot
         * (envíos de pago). Si hay App Secret configurado, la firma es OBLIGATORIA; si no lo
         * hay (p. ej. pruebas locales con curl), se permite pero la protección está INACTIVA
         * (se indica en Ajustes). Configura wa_app_secret antes de producción.
         */
        $appSecret = (string) Setting::get('wa_app_secret', '');
        if ($appSecret !== '') {
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

    protected function process(array $data): void
    {
        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                $contactNames = [];
                foreach ($value['contacts'] ?? [] as $c) {
                    $contactNames[$c['wa_id']] = $c['profile']['name'] ?? null;
                }

                foreach ($value['messages'] ?? [] as $msg) {
                    $this->handleIncoming($msg, $contactNames);
                }

                $this->handleStatuses($value['statuses'] ?? []);
            }
        }
    }

    protected function handleIncoming(array $msg, array $contactNames): void
    {
        $from = $msg['from'];
        $name = $contactNames[$from] ?? null;
        $contactId = ChatService::upsertContact($from, $name);

        $type = $msg['type'] ?? 'text';
        $body = '';
        $replyId = null;
        $opts = ['wamid' => $msg['id'] ?? null, 'status' => 'received'];

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
                    ? '📋 Respuesta de formulario'
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

        /*
         * EL ROUTER: ¿este mensaje pertenece a un ticket abierto o abre uno nuevo?
         * Si el contacto tiene un ticket abierto en el canal WhatsApp, el mensaje se
         * añade a ese ticket; si no, se crea uno (con el texto como asunto provisional).
         */
        $ticketId = $this->tickets->routeIncoming($contactId, 'whatsapp', $body ?: "[$type]");
        $opts['ticket_id'] = $ticketId;
        $opts['channel'] = 'whatsapp';

        ChatService::storeMessage($contactId, $from, 'in', $type, $body, $opts);

        // Aviso en tiempo real: hay un mensaje nuevo del cliente en este ticket.
        $this->tickets->broadcast('message', $ticketId);

        // Todo lo que respondamos a este mensaje va al MISMO ticket.
        $out = ['ticket_id' => $ticketId, 'channel' => 'whatsapp', 'status' => 'sent'];

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
                // Se pasa el ticket para que las respuestas del bot caigan en el mismo hilo.
                $this->flow->handle(['id' => $contactId, 'wa_id' => $from, 'ticket_id' => $ticketId], $body, $name, $type, $replyId);
            } catch (\Throwable $e) { /* no romper el webhook */ }
        }
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
            $affected = DB::update("UPDATE campaign_recipients SET status = ?, error = COALESCE(?, error) WHERE wamid = ? AND ? > $rankCase", [$status, $err, $wamid, $nr]);

            if ($status === 'failed' && $affected > 0) {
                $cid = DB::table('campaign_recipients')->where('wamid', $wamid)->value('campaign_id');
                if ($cid) $this->campaigns->recalc((int) $cid);
            }
        }
    }
}
