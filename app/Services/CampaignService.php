<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Lógica de envío de campañas. Portado de includes/campaign_send.php. */
class CampaignService
{
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
        $cap = (int) Setting::get('daily_send_cap', '0'); // 0 = sin tope
        if ($cap > 0) {
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

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $r) {
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
                DB::table('campaign_recipients')->where('id', $r->id)->update(['status' => 'failed', 'error' => $err, 'sent_at' => now()]);
                $failed++;
            }
        }

        $this->recalc($campaignId);

        $pending = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'pending')->count();
        if ($pending === 0) {
            $okCount = DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('status', 'sent')->count();
            DB::table('campaigns')->where('id', $campaignId)->update(['status' => $okCount === 0 ? 'failed' : 'sent', 'updated_at' => now()]);
        }
        return [$sent, $failed, $pending];
    }
}
