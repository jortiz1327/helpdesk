<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/campaigns.php — campañas de difusión. Requiere token. */
class CampaignsController extends Controller
{
    public function handle(Request $request, CampaignService $campaigns)
    {
        $action = $request->query('action', '');

        if ($request->isMethod('post') && $action === 'run') {
            $id = (int) ($request->query('id') ?? $request->input('id') ?? 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            [$sent, $failed, $pending] = $campaigns->process($id, 30);
            return response()->json(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'pending' => $pending]);
        }

        if ($request->isMethod('post') && $action === 'cancel') {
            $id = (int) ($request->query('id') ?? $request->input('id') ?? 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            DB::table('campaigns')->where('id', $id)->whereIn('status', ['scheduled', 'sending'])->update(['status' => 'canceled', 'updated_at' => now()]);
            DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->delete();
            return response()->json(['ok' => true]);
        }

        if ($request->isMethod('post')) {
            return $this->create($request, $campaigns);
        }

        if ($request->isMethod('get') && $request->query('id')) {
            return $this->detail((int) $request->query('id'));
        }

        if ($request->isMethod('get')) {
            $rows = DB::select("
                SELECT c.id, c.title, c.template_name, c.status, c.total, c.sent, c.failed, c.scheduled_at, c.created_at,
                       COALESCE(p.name, CONCAT('🏷 ', l.name)) AS source_name,
                       (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('delivered','read')) AS delivered,
                       (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'read') AS read_count
                FROM campaigns c
                LEFT JOIN phonebooks p ON p.id = c.phonebook_id
                LEFT JOIN labels l ON l.id = c.label_id
                ORDER BY c.id DESC LIMIT 200
            ");
            return response()->json(['ok' => true, 'campaigns' => $rows]);
        }

        if ($request->isMethod('delete')) {
            $id = (int) $request->query('id', 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            DB::table('campaigns')->where('id', $id)->delete();
            DB::table('campaign_recipients')->where('campaign_id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    protected function create(Request $request, CampaignService $campaigns)
    {
        $title    = trim((string) $request->input('title'));
        $template = trim((string) $request->input('template_name'));
        $lang     = $request->input('language', 'es');
        $pbId     = (int) $request->input('phonebook_id');
        $labelId  = (int) $request->input('label_id');
        $components = $request->input('components', []);
        $schedule = $request->input('schedule', ['mode' => 'now']);

        // Interruptor de pánico: si los envíos están pausados, no se crea la campaña.
        if ((string) Setting::get('outbound_paused', '0') === '1') {
            return response()->json(['ok' => false, 'error' => 'Los envíos están PAUSADOS (interruptor de seguridad). Reactívalos en «Seguridad de envíos» para poder lanzar campañas.'], 423);
        }

        if ($title === '')      return response()->json(['ok' => false, 'error' => 'Ponle un título a la campaña'], 400);
        if ($template === '')   return response()->json(['ok' => false, 'error' => 'Elige una plantilla'], 400);
        if (!$pbId && !$labelId) return response()->json(['ok' => false, 'error' => 'Elige una agenda o una etiqueta de destino'], 400);

        // Destinatarios: de una etiqueta (dinámico) o de una agenda (lista fija)
        if ($labelId) {
            $raw = DB::select('SELECT c.wa_id, c.name FROM contacts c JOIN contact_labels cl ON cl.contact_id = c.id WHERE cl.label_id = ?', [$labelId]);
        } else {
            $raw = DB::select('SELECT wa_id, name FROM phonebook_contacts WHERE phonebook_id = ?', [$pbId]);
        }
        if (!$raw) return response()->json(['ok' => false, 'error' => $labelId ? 'No hay contactos con esa etiqueta' : 'La agenda elegida no tiene contactos'], 400);

        // Excluir contactos dados de baja (opt-out). IMPORTANTE: la baja es SOLO de
        // CAMPAÑAS/difusiones, no un bloqueo total. El envío individual (SendController,
        // que usa SOPORTE) NO debe mirar opted_out: un agente puede responder a un
        // ticket aunque el contacto esté dado de baja de campañas. No añadir este filtro allí.
        $out = DB::table('contacts')->where('opted_out', 1)->pluck('wa_id')->all();
        $outSet = array_flip(array_map(fn ($w) => preg_replace('/\D/', '', $w), $out));
        $recipients = [];
        foreach ($raw as $r) {
            if (isset($outSet[preg_replace('/\D/', '', $r->wa_id)])) continue;
            $recipients[] = $r;
        }
        $excluded = count($raw) - count($recipients);
        if (!$recipients) return response()->json(['ok' => false, 'error' => 'Todos los contactos del destino están dados de baja'], 400);

        // Programación
        $mode = $schedule['mode'] ?? 'now';
        $scheduledAt = date('Y-m-d H:i:s');
        if ($mode === 'later' && !empty($schedule['at'])) {
            $ts = strtotime($schedule['at']);
            if ($ts === false) return response()->json(['ok' => false, 'error' => 'Fecha de programación no válida'], 400);
            $scheduledAt = date('Y-m-d H:i:s', $ts);
        }

        $campaignId = DB::table('campaigns')->insertGetId([
            'title' => $title, 'template_name' => $template, 'language' => $lang,
            'components' => json_encode($components, JSON_UNESCAPED_UNICODE),
            'phonebook_id' => $pbId ?: null, 'label_id' => $labelId ?: null,
            'status' => 'scheduled', 'scheduled_at' => $scheduledAt, 'total' => count($recipients),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $insert = array_map(fn ($r) => [
            'campaign_id' => $campaignId,
            'wa_id'       => preg_replace('/\D/', '', $r->wa_id),
            'name'        => $r->name ?? null,
        ], $recipients);
        DB::table('campaign_recipients')->insert($insert);

        // Envío inmediato: procesa una primera tanda ahora (el resto por cron)
        $immediate = ($mode !== 'later') || strtotime($scheduledAt) <= time();
        $stats = ['sent' => 0, 'failed' => 0, 'pending' => count($recipients)];
        if ($immediate) {
            [$s, $f, $p] = $campaigns->process($campaignId, 25);
            $stats = ['sent' => $s, 'failed' => $f, 'pending' => $p];
        }
        return response()->json(['ok' => true, 'id' => $campaignId, 'immediate' => $immediate, 'stats' => $stats, 'excluded' => $excluded]);
    }

    protected function detail(int $id)
    {
        $c = DB::selectOne("SELECT c.*, p.name AS phonebook_name, l.name AS label_name,
                COALESCE(p.name, CONCAT('🏷 ', l.name)) AS source_name
            FROM campaigns c
            LEFT JOIN phonebooks p ON p.id = c.phonebook_id
            LEFT JOIN labels l ON l.id = c.label_id
            WHERE c.id = ?", [$id]);
        if (!$c) return response()->json(['ok' => false, 'error' => 'No encontrada'], 404);
        $c->components = json_decode($c->components ?: '[]', true);
        $c->recipients = DB::select('SELECT wa_id, name, status, error, sent_at FROM campaign_recipients WHERE campaign_id = ? ORDER BY id ASC', [$id]);
        return response()->json(['ok' => true, 'campaign' => $c]);
    }
}
