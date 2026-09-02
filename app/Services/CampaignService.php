<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/** Lógica de envío de campañas. Portado de includes/campaign_send.php. */
class CampaignService
{
    /** Reintentos por destinatario ante errores transitorios de Meta (429 / 5xx). */
    public const MAX_REINTENTOS = 5;

    public function __construct(protected WhatsAppService $wa) {}

    /** Recalcula sent/failed de una campaña a partir de sus destinatarios. */
    public function recalc(int $campaignId): void
    {
        DB::update(
            "UPDATE campaigns SET
                sent   = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id=? AND status IN ('sent','delivered','read')),
                failed = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id=? AND status='failed')
             WHERE id=?",
            [$campaignId, $campaignId, $campaignId]
        );
    }

    protected function resolveValue(array $p, object $recipient): string
    {
        if (!isset($p['source']) && isset($p['text'])) return (string) $p['text'];
        $src = $p['source'] ?? 'fixed';
        $fallback = (string) ($p['value'] ?? '');
        if ($src === 'name') {
            $n = trim((string) ($recipient->name ?? ''));
            return $n !== '' ? $n : ($fallback !== '' ? $fallback : 'cliente');
        }
        if ($src === 'phone') {
            $wa = preg_replace('/\D/', '', $recipient->wa_id ?? '');
            return $wa !== '' ? '+' . $wa : $fallback;
        }
        return $fallback;
    }

    protected function resolveComponents(array $spec, object $recipient): array
    {
        $out = [];
        foreach ($spec as $comp) {
            $params = [];
            foreach ($comp['parameters'] ?? [] as $p) {
                $params[] = ['type' => 'text', 'text' => $this->resolveValue($p, $recipient)];
            }
            $c = ['type' => $comp['type']];
            if ($params) $c['parameters'] = $params;
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Procesa hasta $limit destinatarios pendientes.
     * @return array{0:int,1:int,2:int} [enviados, fallidos, pendientes]
     */
    public function process(int $campaignId, int $limit = 30): array
    {
        $camp = DB::selectOne('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
        if (!$camp) return [0, 0, 0];
        if (in_array($camp->status, ['sent', 'canceled', 'draft'], true)) {
            $pending = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')->count();
            return [0, 0, $pending];
        }

        // CANDADO por campaña: solo un proceso la envía a la vez, venga del cron o de un
        // envío inmediato (o un doble clic). Sin esto, dos procesos leen los mismos
        // 'pending' y envían —y PAGAN— dos veces. TTL 300s: si el proceso muere, el candado
        // caduca solo y la campaña se reanuda en el siguiente tick.
        $lock = Cache::lock("campaign:send:{$campaignId}", 300);
        if (!$lock->get()) {
            $pending = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')->count();
            return [0, 0, $pending];   // otro proceso ya la está enviando
        }

        try {
        $limit = max(1, $limit);

        /*
         * RED DE SEGURIDAD DE ENVÍOS (evita que un fallo/ataque dispare miles de mensajes de pago).
         * Se comprueba aquí porque es el ÚNICO punto por donde salen las campañas (inmediato y cron).
         * Los destinatarios no enviados quedan 'pending': se reanudan al reactivar o al día siguiente.
         */
        if ((string) Setting::get('outbound_paused', '0') === '1') {
            $pending = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')->count();
            return [0, 0, $pending]; // interruptor de pánico activo
        }
        // El tope diario protege el gasto de WhatsApp (mensajes de pago). El correo no es de
        // pago por mensaje, así que no lo limita este tope.
        $cap = (int) Setting::get('daily_send_cap', '0'); // 0 = sin tope
        if ($cap > 0 && $camp->channel !== 'email') {
            $sentToday = (int) DB::table('messages')
                ->where('direction', 'out')->where('type', 'template')
                ->where('created_at', '>=', now()->startOfDay())->count();
            $remaining = $cap - $sentToday;
            if ($remaining <= 0) {
                $pending = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')->count();
                return [0, 0, $pending]; // tope diario alcanzado
            }
            $limit = min($limit, $remaining); // no pasar del tope en esta tanda
        }

        $spec = json_decode($camp->components ?: '[]', true) ?: [];
        $lang = $camp->language ?: 'es';
        $name = $camp->template_name;

        if ($camp->status !== 'sending') {
            DB::table('campaigns')->where('id', $campaignId)->update(['status' => 'sending', 'updated_at' => now()]);
        }
        $recipients = DB::select("SELECT * FROM campaign_recipients WHERE campaign_id=? AND status='pending' ORDER BY id ASC LIMIT $limit", [$campaignId]);

        if ($camp->channel === 'email') {
            [$sent, $failed] = $this->enviarCorreos($camp, $recipients);
        } else {
            // BAJAS al momento del ENVÍO: el filtro de opt-out se aplica al CREAR la campaña,
            // pero entre crear y enviar (programadas, o envío en varias tandas) un contacto
            // puede haberse dado de BAJA. A ese NO se le manda y su fila se marca 'skipped'.
            $bajas = array_flip(
                DB::table('contacts')->where('opted_out', 1)->pluck('wa_id')
                    ->map(fn ($w) => preg_replace('/\D/', '', (string) $w))->all()
            );

            $sent = 0;
            $failed = 0;
            foreach ($recipients as $r) {
                if (isset($bajas[preg_replace('/\D/', '', (string) $r->wa_id)])) {
                    DB::table('campaign_recipients')->where('id', $r->id)
                        ->update(['status' => 'skipped', 'error' => 'Dado de baja', 'sent_at' => now()]);
                    continue;
                }
                $to = $r->wa_id;
                $components = $this->resolveComponents($spec, $r);
                [$code, $res] = $this->wa->sendTemplate($to, $name, $lang, $components);
                if ($code >= 200 && $code < 300 && !empty($res['messages'][0]['id'])) {
                    $wamid = $res['messages'][0]['id'];
                    $contactId = ChatService::upsertContact($to, $r->name);
                    ChatService::storeMessage($contactId, $to, 'out', 'template', '📢 ' . $camp->title . ' · ' . $name, ['wamid' => $wamid, 'status' => 'sent']);
                    DB::table('campaign_recipients')->where('id', $r->id)->update(['status' => 'sent', 'wamid' => $wamid, 'sent_at' => now(), 'error' => null]);
                    $sent++;
                } else {
                    $err = $res['error']['message'] ?? ('HTTP ' . $code);
                    // 429 (rate limit) o 5xx son TRANSITORIOS: se deja PENDING para reintentar
                    // en el siguiente tick (hasta MAX_REINTENTOS). Otros errores (4xx) → failed.
                    if (($code === 429 || $code >= 500) && (int) $r->retries < self::MAX_REINTENTOS) {
                        DB::table('campaign_recipients')->where('id', $r->id)
                            ->update(['error' => $err, 'retries' => (int) $r->retries + 1]);
                    } else {
                        DB::table('campaign_recipients')->where('id', $r->id)->update(['status' => 'failed', 'error' => $err, 'sent_at' => now()]);
                        $failed++;
                    }
                }
            }
        }

        $this->recalc($campaignId);

        $pending = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')->count();
        if ($pending === 0) {
            $okCount = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'sent')->count();
            DB::table('campaigns')->where('id', $campaignId)->update(['status' => $okCount === 0 ? 'failed' : 'sent', 'updated_at' => now()]);
        }
        return [$sent, $failed, $pending];
        } finally {
            $lock->release();
        }
    }

    /**
     * Envía una tanda de una campaña por CORREO. Usa el remitente de FUNCIÓN 'campanas'
     * (SMTP aparte del buzón de soporte: sus respuestas NO deben convertirse en tickets).
     * Rechaza las BAJAS al momento del envío —comprobadas por correo— y añade a cada
     * mensaje su enlace de baja firmado.
     * @return array{0:int,1:int} [enviados, fallidos]
     */
    protected function enviarCorreos(object $camp, array $recipients): array
    {
        $acc = EmailAccount::where('funcion', 'campanas')->where('active', true)
            ->whereNotNull('smtp_host')->orderBy('id')->first();
        if (!$acc) {
            return [0, 0];   // sin remitente configurado: se quedan 'pending' (se reanudan al configurarlo)
        }
        $mail = app(MailService::class);

        // BAJAS al momento del ENVÍO (por correo) + id del contacto para el enlace de baja.
        // Se resuelve en UNA consulta el conjunto de correos de esta tanda.
        $emails = array_values(array_unique(array_filter(
            array_map(fn ($r) => mb_strtolower(trim((string) $r->email)), $recipients)
        )));
        $contactos = empty($emails) ? collect() : DB::table('contacts')
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->get(['id', 'email', 'opted_out'])
            ->keyBy(fn ($c) => mb_strtolower(trim((string) $c->email)));

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $r) {
            $email = mb_strtolower(trim((string) $r->email));
            $c = $contactos[$email] ?? null;
            if ($c && (int) $c->opted_out === 1) {
                DB::table('campaign_recipients')->where('id', $r->id)
                    ->update(['status' => 'skipped', 'error' => 'Dado de baja', 'sent_at' => now()]);
                continue;   // no se envía a quien se dio de baja tras crear la campaña
            }
            try {
                $html  = $this->cuerpoCorreo((string) $camp->body_html, $c?->id);
                $msgId = $mail->sendMail($acc, $email, $r->name, (string) $camp->subject, $html);
                DB::table('campaign_recipients')->where('id', $r->id)
                    ->update(['status' => 'sent', 'wamid' => $msgId, 'sent_at' => now(), 'error' => null]);
                $sent++;
            } catch (\Throwable $e) {
                // Fallo de SMTP: normalmente transitorio (conexión, límite del proveedor).
                // Se reintenta en el siguiente tick hasta MAX_REINTENTOS; luego se marca failed.
                $err = mb_substr($e->getMessage(), 0, 240);
                if ((int) $r->retries < self::MAX_REINTENTOS) {
                    DB::table('campaign_recipients')->where('id', $r->id)
                        ->update(['error' => $err, 'retries' => (int) $r->retries + 1]);
                } else {
                    DB::table('campaign_recipients')->where('id', $r->id)
                        ->update(['status' => 'failed', 'error' => $err, 'sent_at' => now()]);
                    $failed++;
                }
            }
        }
        return [$sent, $failed];
    }

    /**
     * Añade al cuerpo de la campaña el pie de BAJA con enlace firmado (obligatorio en
     * comunicaciones comerciales). Sin contacto conocido no se puede personalizar la baja,
     * así que se devuelve el cuerpo tal cual.
     */
    protected function cuerpoCorreo(string $html, ?int $contactId): string
    {
        if (!$contactId) return $html;
        $base = rtrim((string) config('app.url'), '/');
        $url  = $base . URL::signedRoute('campaign.unsubscribe', ['contact' => $contactId], null, false);
        return $html
            . '<div style="margin-top:22px;padding-top:12px;border-top:1px solid #e2e6ea;'
            . 'font-size:12px;color:#8a8f99;text-align:center">'
            . 'Si no deseas recibir más comunicaciones, puedes '
            . '<a href="' . e($url) . '" style="color:#8a8f99">darte de baja aquí</a>.'
            . '</div>';
    }
}
