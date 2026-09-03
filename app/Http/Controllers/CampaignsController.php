<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CampaignService;
use App\Services\GatingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/campaigns.php — campañas de difusión. Requiere token. */
class CampaignsController extends Controller
{
    /*
     * Entregados/leídos: UNA sola definición para la lista y el detalle. Antes la lista los
     * sacaba con estas subconsultas y el detalle los recontaba en el frontend desde los
     * destinatarios; al ser dos caminos distintos podían mostrar cifras distintas. Ahora
     * los dos beben de aquí (alias `c` = campaigns en ambas consultas).
     */
    private const SQL_ENTREGA =
        "(SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('delivered','read')) AS delivered,
         (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'read') AS read_count";

    /** 403 si el usuario no tiene el permiso (el superadmin lo tiene por bypass); null si sí. */
    protected function exigir(Request $request, string $permiso)
    {
        if (!$request->user()?->can($permiso)) {
            return response()->json(['ok' => false, 'error' => 'No tienes permiso para esta acción'], 403);
        }
        return null;
    }

    public function handle(Request $request, CampaignService $campaigns)
    {
        $action = $request->query('action', '');

        if ($request->isMethod('post') && $action === 'run') {
            if ($r = $this->exigir($request, 'campaigns.send')) return $r;
            $id = (int) ($request->query('id') ?? $request->input('id') ?? 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            // El candado «WhatsApp configurado» solo aplica a campañas de WhatsApp; las de
            // correo salen por su propio SMTP y no dependen de Meta.
            $ch = DB::table('campaigns')->where('id', $id)->value('channel');
            if ($ch !== 'email' && ($locked = GatingService::guard('wa_campaign'))) return $locked;
            [$sent, $failed, $pending] = $campaigns->process($id, 30);
            return response()->json(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'pending' => $pending]);
        }

        if ($request->isMethod('post') && $action === 'cancel') {
            if ($r = $this->exigir($request, 'campaigns.send')) return $r;
            $id = (int) ($request->query('id') ?? $request->input('id') ?? 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            // Cancelar la campaña y borrar sus pendientes van JUNTOS (transacción): que no
            // quede «cancelada» pero con destinatarios en cola, ni al revés.
            DB::transaction(function () use ($id) {
                DB::table('campaigns')->where('id', $id)->whereIn('status', ['scheduled', 'sending'])->update(['status' => 'canceled', 'updated_at' => now()]);
                DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->delete();
            });
            return response()->json(['ok' => true]);
        }

        if ($request->isMethod('post')) {
            if ($r = $this->exigir($request, 'campaigns.send')) return $r;
            // El candado de WhatsApp no aplica a campañas de correo (SMTP propio, sin Meta).
            if ($request->input('channel') !== 'email' && ($locked = GatingService::guard('wa_campaign'))) return $locked;
            return $this->create($request, $campaigns);
        }

        // Estimación de coste ANTES de enviar (destinatarios × tarifa de la categoría).
        // Solo lectura: basta con campaigns.access (ya lo exige la ruta).
        if ($request->isMethod('get') && $action === 'estimate') {
            return $this->estimate($request);
        }

        if ($request->isMethod('get') && $request->query('id')) {
            return $this->detail((int) $request->query('id'));
        }

        if ($request->isMethod('get')) {
            $rows = DB::select("
                SELECT c.id, c.title, c.channel, c.subject, c.template_name, c.status, c.total, c.sent, c.failed, c.scheduled_at, c.created_at,
                       COALESCE(p.name, CONCAT('🏷 ', l.name)) AS source_name,
                       " . self::SQL_ENTREGA . "
                FROM campaigns c
                LEFT JOIN phonebooks p ON p.id = c.phonebook_id
                LEFT JOIN labels l ON l.id = c.label_id
                ORDER BY c.id DESC LIMIT 200
            ");
            return response()->json(['ok' => true, 'campaigns' => $rows]);
        }

        if ($request->isMethod('delete')) {
            if ($r = $this->exigir($request, 'campaigns.delete')) return $r;
            $id = (int) $request->query('id', 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            DB::table('campaigns')->where('id', $id)->delete();
            DB::table('campaign_recipients')->where('campaign_id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    /** Tarifa (EUR/mensaje) de una categoría de plantilla, configurable en Ajustes. */
    public static function tarifaCategoria(string $category): float
    {
        return match (strtoupper($category)) {
            'MARKETING'      => (float) Setting::get('wa_price_marketing', '0.06'),
            'UTILITY'        => (float) Setting::get('wa_price_utility', '0.0166'),
            'AUTHENTICATION' => (float) Setting::get('wa_price_authentication', '0.0166'),
            default          => (float) Setting::get('wa_price_service', '0'),   // SERVICE u otras: gratis
        };
    }

    /**
     * Estima el coste de una campaña de WhatsApp antes de enviarla:
     * destinatarios entregables (con teléfono y sin baja) × tarifa de la categoría.
     * Mismo criterio de destinatarios que create() para que cuadre con lo real.
     */
    protected function estimate(Request $request)
    {
        $category = (string) $request->query('category', '');
        $pbId     = (int) $request->query('phonebook_id');
        $labelId  = (int) $request->query('label_id');

        $recipients = 0; $excluded = 0;
        if ($pbId || $labelId) {
            $raw = $labelId
                ? DB::select('SELECT c.wa_id FROM contacts c JOIN contact_labels cl ON cl.contact_id = c.id WHERE cl.label_id = ?', [$labelId])
                : DB::select('SELECT wa_id FROM phonebook_contacts WHERE phonebook_id = ?', [$pbId]);
            $outSet = array_flip(array_map(fn ($w) => preg_replace('/\D/', '', (string) $w),
                DB::table('contacts')->where('opted_out', 1)->pluck('wa_id')->all()));
            foreach ($raw as $r) {
                $wa = preg_replace('/\D/', '', (string) $r->wa_id);
                if ($wa === '') continue;
                if (isset($outSet[$wa])) { $excluded++; continue; }
                $recipients++;
            }
        }

        $rate = self::tarifaCategoria($category);
        return response()->json([
            'ok'         => true,
            'recipients' => $recipients,
            'excluded'   => $excluded,
            'category'   => strtoupper($category),
            'rate'       => $rate,
            'cost'       => round($recipients * $rate, 2),
            'currency'   => 'EUR',
        ]);
    }

    protected function create(Request $request, CampaignService $campaigns)
    {
        $title    = trim((string) $request->input('title'));
        $channel  = $request->input('channel') === 'email' ? 'email' : 'whatsapp';
        $labelId  = (int) $request->input('label_id');
        $schedule = $request->input('schedule', ['mode' => 'now']);

        // Interruptor de pánico: si los envíos están pausados, no se crea la campaña.
        if ((string) Setting::get('outbound_paused', '0') === '1') {
            return response()->json(['ok' => false, 'error' => 'Los envíos están PAUSADOS (interruptor de seguridad). Reactívalos en «Seguridad de envíos» para poder lanzar campañas.'], 423);
        }
        if ($title === '') return response()->json(['ok' => false, 'error' => 'Ponle un título a la campaña'], 400);

        // --- Destinatarios + campos propios del canal ---
        if ($channel === 'email') {
            $subject = trim((string) $request->input('subject'));
            $body    = trim((string) $request->input('body_html'));
            if ($subject === '') return response()->json(['ok' => false, 'error' => 'Ponle un asunto al correo'], 400);
            if ($body === '')    return response()->json(['ok' => false, 'error' => 'Escribe el cuerpo del correo'], 400);
            if (!$labelId)       return response()->json(['ok' => false, 'error' => 'Elige una etiqueta de destino (se enviará a los contactos con correo de esa etiqueta)'], 400);
            if (!\App\Models\EmailAccount::where('funcion', 'campanas')->where('active', true)->whereNotNull('smtp_host')->exists()) {
                return response()->json(['ok' => false, 'error' => 'No hay un remitente de campañas por correo (SMTP). Configúralo en Campañas → Ajustes → Correo de campañas.'], 400);
            }

            // Contactos de esa etiqueta que TENGAN correo. La baja (opt-out) se comprueba por correo.
            $raw = DB::table('contacts as c')->join('contact_labels as cl', 'cl.contact_id', '=', 'c.id')
                ->where('cl.label_id', $labelId)->whereNotNull('c.email')->where('c.email', '<>', '')
                ->get(['c.email', 'c.name', 'c.opted_out']);
            if ($raw->isEmpty()) return response()->json(['ok' => false, 'error' => 'Ningún contacto de esa etiqueta tiene correo'], 400);

            $recipients = [];
            $vistos = [];
            foreach ($raw as $r) {
                $e = mb_strtolower(trim((string) $r->email));
                if ((int) $r->opted_out === 1 || isset($vistos[$e])) continue;
                $vistos[$e] = true;
                $recipients[] = ['email' => $e, 'wa_id' => null, 'name' => $r->name ?? null];
            }
            $excluded = $raw->count() - count($recipients);
            if (!$recipients) return response()->json(['ok' => false, 'error' => 'Todos los contactos de esa etiqueta están dados de baja'], 400);

            $campos = [
                'channel' => 'email', 'template_name' => null, 'language' => 'es', 'components' => '[]',
                'subject' => mb_substr($subject, 0, 255), 'body_html' => \App\Services\HtmlSanitizer::cleanEmail($body),
                'phonebook_id' => null, 'label_id' => $labelId,
            ];
        } else {
            $template = trim((string) $request->input('template_name'));
            $lang     = $request->input('language', 'es');
            $pbId     = (int) $request->input('phonebook_id');
            $components = $request->input('components', []);
            if ($template === '')    return response()->json(['ok' => false, 'error' => 'Elige una plantilla'], 400);
            if (!$pbId && !$labelId) return response()->json(['ok' => false, 'error' => 'Elige una agenda o una etiqueta de destino'], 400);

            // Destinatarios: de una etiqueta (dinámico) o de una agenda (lista fija)
            $raw = $labelId
                ? DB::select('SELECT c.wa_id, c.name FROM contacts c JOIN contact_labels cl ON cl.contact_id = c.id WHERE cl.label_id = ?', [$labelId])
                : DB::select('SELECT wa_id, name FROM phonebook_contacts WHERE phonebook_id = ?', [$pbId]);
            if (!$raw) return response()->json(['ok' => false, 'error' => $labelId ? 'No hay contactos con esa etiqueta' : 'La agenda elegida no tiene contactos'], 400);

            // Excluir contactos dados de baja (opt-out). La baja es SOLO de CAMPAÑAS; el envío
            // individual de SOPORTE (SendController) NO mira opted_out (un agente puede responder
            // un ticket aunque el contacto esté de baja). No añadir este filtro allí.
            $outSet = array_flip(array_map(fn ($w) => preg_replace('/\D/', '', $w),
                DB::table('contacts')->where('opted_out', 1)->pluck('wa_id')->all()));
            $recipients = [];
            foreach ($raw as $r) {
                if (isset($outSet[preg_replace('/\D/', '', $r->wa_id)])) continue;
                $recipients[] = ['email' => null, 'wa_id' => preg_replace('/\D/', '', $r->wa_id), 'name' => $r->name ?? null];
            }
            $excluded = count($raw) - count($recipients);
            if (!$recipients) return response()->json(['ok' => false, 'error' => 'Todos los contactos del destino están dados de baja'], 400);

            $campos = [
                'channel' => 'whatsapp', 'template_name' => $template, 'language' => $lang,
                'components' => json_encode($components, JSON_UNESCAPED_UNICODE),
                'subject' => null, 'body_html' => null,
                'phonebook_id' => $pbId ?: null, 'label_id' => $labelId ?: null,
            ];
        }

        // Programación (común a ambos canales)
        $mode = $schedule['mode'] ?? 'now';
        $scheduledAt = date('Y-m-d H:i:s');
        if ($mode === 'later' && !empty($schedule['at'])) {
            $ts = strtotime($schedule['at']);
            if ($ts === false) return response()->json(['ok' => false, 'error' => 'Fecha de programación no válida'], 400);
            $scheduledAt = date('Y-m-d H:i:s', $ts);
        }

        $campaignId = DB::table('campaigns')->insertGetId(array_merge([
            'title' => $title, 'status' => 'scheduled', 'scheduled_at' => $scheduledAt,
            'total' => count($recipients), 'created_at' => now(), 'updated_at' => now(),
        ], $campos));

        $insert = array_map(fn ($r) => [
            'campaign_id' => $campaignId,
            'wa_id'       => $r['wa_id'],
            'email'       => $r['email'],
            'name'        => $r['name'],
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

    /**
     * BAJA de campañas por CORREO. Llega desde el enlace firmado del pie de cada correo
     * (ruta pública, la firma es la autenticación). Marca al contacto como opted_out y
     * devuelve una página de confirmación. Idempotente: dar de baja dos veces no falla.
     */
    public function unsubscribe(Request $request, int $contact)
    {
        DB::table('contacts')->where('id', $contact)->update(['opted_out' => 1]);
        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Baja confirmada</title></head>'
            . '<body style="margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
            . 'background:#f4f6f8;color:#1f2733;display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<div style="max-width:440px;background:#fff;border-radius:14px;padding:34px 30px;'
            . 'box-shadow:0 6px 24px rgba(15,23,42,.08);text-align:center">'
            . '<div style="font-size:44px;line-height:1;margin-bottom:12px">✅</div>'
            . '<h1 style="margin:0 0 8px;font-size:20px">Te has dado de baja</h1>'
            . '<p style="margin:0;color:#5b6572;font-size:14.5px;line-height:1.5">'
            . 'No volverás a recibir comunicaciones comerciales por correo. '
            . 'Si fue un error, ponte en contacto con nosotros y lo revertimos.</p>'
            . '</div></body></html>';
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    protected function detail(int $id)
    {
        $c = DB::selectOne("SELECT c.*, p.name AS phonebook_name, l.name AS label_name,
                COALESCE(p.name, CONCAT('🏷 ', l.name)) AS source_name,
                " . self::SQL_ENTREGA . "
            FROM campaigns c
            LEFT JOIN phonebooks p ON p.id = c.phonebook_id
            LEFT JOIN labels l ON l.id = c.label_id
            WHERE c.id = ?", [$id]);
        if (!$c) return response()->json(['ok' => false, 'error' => 'No encontrada'], 404);
        $c->components = json_decode($c->components ?: '[]', true);
        $c->recipients = DB::select('SELECT wa_id, email, name, status, error, sent_at FROM campaign_recipients WHERE campaign_id = ? ORDER BY id ASC', [$id]);
        return response()->json(['ok' => true, 'campaign' => $c]);
    }
}
