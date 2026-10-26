<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CampaignAudience;
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

        // --- Audiencias (a quién enviar) ---
        if ($request->isMethod('get') && $action === 'audience_meta') {
            return $this->audienceMeta();
        }
        if ($request->isMethod('post') && $action === 'audience_save') {
            if ($r = $this->exigir($request, 'campaigns.send')) return $r;
            $name = mb_substr(trim((string) $request->input('name')), 0, 80);
            $aud = CampaignAudience::normalizar($request->input('rules'));
            if ($name === '') return response()->json(['ok' => false, 'error' => 'Ponle un nombre a la audiencia'], 400);
            if (!$aud || in_array($aud['type'], ['audience', 'phonebook'], true)) {
                return response()->json(['ok' => false, 'error' => 'Solo se guardan segmentos, etiquetas, provincias o «todos»'], 400);
            }
            $id = DB::table('campaign_audiences')->insertGetId(['name' => $name, 'rules' => json_encode($aud, JSON_UNESCAPED_UNICODE),
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['ok' => true, 'id' => $id]);
        }
        if ($request->isMethod('post') && $action === 'audience_delete') {
            if ($r = $this->exigir($request, 'campaigns.send')) return $r;
            DB::table('campaign_audiences')->where('id', (int) $request->input('id'))->delete();
            return response()->json(['ok' => true]);
        }

        // Registro de consentimientos y bajas (RGPD). Solo Campañas (la ruta ya exige
        // campaigns.access) y además quien administra los ajustes: es un dato sensible.
        if ($request->isMethod('get') && $action === 'consent_export') {
            if ($r = $this->exigir($request, 'settings.manage')) return $r;
            return $this->consentExport();
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

        // Historial/trazabilidad de campañas (con coste real). Solo lectura.
        if ($request->isMethod('get') && $action === 'history') {
            return $this->history($request);
        }

        if ($request->isMethod('get') && $request->query('id')) {
            return $this->detail((int) $request->query('id'));
        }

        if ($request->isMethod('get')) {
            $rows = DB::select("
                SELECT c.id, c.title, c.channel, c.subject, c.template_name, c.status, c.total, c.sent, c.failed, c.scheduled_at, c.created_at,
                       c.daily_limit, COALESCE(c.audience_desc, p.name, CONCAT('🏷 ', l.name)) AS source_name,
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

    /**
     * Lo que necesita la pantalla «¿A quién se lo envías?»: etiquetas por grupo con cuántos
     * contactos tiene cada una, provincias de los contactos de Campañas y audiencias guardadas.
     */
    protected function audienceMeta()
    {
        $labels = DB::select('SELECT l.id, l.name, l.color, l.grupo,
                (SELECT COUNT(*) FROM contact_labels cl WHERE cl.label_id = l.id) AS contacts
            FROM labels l ORDER BY l.position, l.name');
        $provincias = DB::table('contacts as c')->whereRaw(\App\Http\Controllers\ContactsController::SQL_CAMPANAS)
            ->whereNotNull('c.provincia')->where('c.provincia', '<>', '')
            ->groupBy('c.provincia')->orderBy('c.provincia')
            ->get([DB::raw('c.provincia AS name'), DB::raw('COUNT(*) AS contacts')]);
        $guardadas = DB::table('campaign_audiences')->orderBy('name')->get(['id', 'name', 'rules'])
            ->map(function ($a) {
                $r = CampaignAudience::normalizar($a->rules);
                return ['id' => $a->id, 'name' => $a->name, 'desc' => $r ? CampaignAudience::describir($r) : '—'];
            });
        $total = DB::table('contacts as c')->whereRaw(\App\Http\Controllers\ContactsController::SQL_CAMPANAS)->count();
        return response()->json(['ok' => true, 'labels' => $labels, 'provincias' => $provincias,
            'audiences' => $guardadas, 'all_contacts' => $total, 'grupos' => CampaignAudience::GRUPOS]);
    }

    /**
     * CSV del registro de consentimientos y bajas (RGPD): una fila por cambio, con la
     * situación actual del contacto. Solo contactos que tienen algún movimiento.
     */
    protected function consentExport()
    {
        $rows = DB::table('consent_log as g')->join('contacts as c', 'c.id', '=', 'g.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'g.user_id')
            ->orderBy('g.created_at')->orderBy('g.id')
            ->get(['c.name', 'c.wa_id', 'c.email', 'c.opted_out', 'g.event', 'g.source', 'g.created_at', 'u.name as agente']);

        $esc = function ($v) {
            $v = (string) $v;
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v;   // sin fórmulas en Excel
            return '"' . str_replace('"', '""', $v) . '"';
        };
        $out = "\xEF\xBB\xBF" . implode(';', array_map($esc, ['Fecha', 'Contacto', 'Teléfono', 'Correo', 'Movimiento', 'Origen', 'Agente', 'Situación actual'])) . "\r\n";
        foreach ($rows as $r) {
            $out .= implode(';', array_map($esc, [
                $r->created_at, $r->name, (string) $r->wa_id, $r->email,
                \App\Services\ConsentLog::EVENTOS[$r->event] ?? $r->event,
                \App\Services\ConsentLog::ORIGENES[$r->source] ?? $r->source,
                $r->agente ?? '', (int) $r->opted_out === 1 ? 'De baja' : 'Recibe comunicaciones',
            ])) . "\r\n";
        }
        return response($out)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="registro-consentimientos-' . date('Y-m-d') . '.csv"')
            ->header('Cache-Control', 'no-store');
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
        $canal    = $request->query('channel') === 'email' ? 'email' : 'whatsapp';
        // Misma regla que create(): audiencia, o label_id / phonebook_id sueltos.
        $aud = CampaignAudience::normalizar($request->query('audience'));
        if (!$aud && ($l = (int) $request->query('label_id'))) $aud = ['type' => 'labels', 'ids' => [$l], 'match' => 'any'];
        if (!$aud && ($p = (int) $request->query('phonebook_id'))) $aud = ['type' => 'phonebook', 'id' => $p];

        $recipients = 0; $excluded = 0; $desc = '';
        if ($aud) {
            [$lista, $excluded, $desc] = CampaignAudience::resolver($aud, $canal);
            $recipients = count($lista);
        }
        if ($canal === 'email') {
            return response()->json(['ok' => true, 'recipients' => $recipients, 'excluded' => $excluded, 'desc' => $desc]);
        }

        // El COSTE solo se muestra al superadmin; al resto, el resumen sin importe.
        $isSuper = (bool) $request->user()?->hasRole(config('rbac.super_role'));
        $resp = [
            'ok'         => true,
            'recipients' => $recipients,
            'excluded'   => $excluded,
            'desc'       => $desc,
            'category'   => strtoupper($category),
            'show_cost'  => $isSuper,
            'currency'   => 'EUR',
        ];
        if ($isSuper) {
            $rate = self::tarifaCategoria($category);
            $resp['rate'] = $rate;
            $resp['cost'] = round($recipients * $rate, 2);
        }
        return response()->json($resp);
    }

    /**
     * Historial de campañas con trazabilidad y coste real:
     * quién la lanzó, destino, plantilla, categoría, resultados (enviados/entregados/
     * leídos/fallidos) y coste (entregados × tarifa aplicada). Más totales del mes.
     */
    protected function history(Request $request)
    {
        $rows = DB::select("
            SELECT c.id, c.title, c.channel, c.template_name, c.category, c.unit_cost, c.status,
                   c.total, c.sent, c.failed, c.scheduled_at, c.created_at,
                   COALESCE(c.audience_desc, p.name, CONCAT('🏷 ', l.name)) AS source_name,
                   u.name AS by_name,
                   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('delivered','read')) AS delivered,
                   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'read') AS read_count
            FROM campaigns c
            LEFT JOIN phonebooks p ON p.id = c.phonebook_id
            LEFT JOIN labels l ON l.id = c.label_id
            LEFT JOIN users u ON u.id = c.created_by
            ORDER BY c.id DESC LIMIT 300
        ");

        // El COSTE (y la tarifa) solo se exponen al superadmin. El resto ve la
        // trazabilidad completa (destino, plantilla, categoría, quién, resultados) sin importes.
        $isSuper = (bool) $request->user()?->hasRole(config('rbac.super_role'));

        $inicioMes = date('Y-m-01 00:00:00');
        $costeMes = 0.0; $nMes = 0; $costeAll = 0.0;
        foreach ($rows as $r) {
            $r->delivered  = (int) $r->delivered;
            $r->read_count = (int) $r->read_count;
            $r->sent       = (int) $r->sent;
            $r->failed     = (int) $r->failed;
            $r->total      = (int) $r->total;
            $unit = (float) $r->unit_cost;
            // Coste real = entregados × tarifa (solo WhatsApp; el correo no lo cobra Meta).
            $cost = $r->channel === 'whatsapp' ? round($r->delivered * $unit, 2) : 0.0;
            $costeAll += $cost;
            if ((string) $r->created_at >= $inicioMes) { $costeMes += $cost; $nMes++; }
            if ($isSuper) { $r->cost = $cost; $r->unit_cost = $unit; }
            else { unset($r->unit_cost); }   // no filtrar ni tarifa ni coste al usuario común
        }

        return response()->json([
            'ok'        => true,
            'show_cost' => $isSuper,
            'campaigns' => $rows,
            'totals'    => $isSuper ? [
                'month_cost'  => round($costeMes, 2),
                'month_count' => $nMes,
                'all_cost'    => round($costeAll, 2),
                'all_count'   => count($rows),
                'currency'    => 'EUR',
            ] : ['month_count' => $nMes, 'all_count' => count($rows)],
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

        // --- A quién: la AUDIENCIA (regla). Compatibilidad: label_id / phonebook_id sueltos. ---
        $aud = CampaignAudience::normalizar($request->input('audience'));
        if (!$aud && $labelId) $aud = ['type' => 'labels', 'ids' => [$labelId], 'match' => 'any'];
        if (!$aud && (int) $request->input('phonebook_id')) $aud = ['type' => 'phonebook', 'id' => (int) $request->input('phonebook_id')];
        if (!$aud) return response()->json(['ok' => false, 'error' => 'Elige a quién enviar la campaña'], 400);
        if ($channel === 'email' && $aud['type'] === 'phonebook') {
            return response()->json(['ok' => false, 'error' => 'Las agendas son listas de teléfonos: para correo elige etiquetas, un segmento u otra audiencia'], 400);
        }
        [$recipients, $excluded, $audDesc] = CampaignAudience::resolver($aud, $channel);
        if (!$recipients) {
            return response()->json(['ok' => false, 'error' => $excluded
                ? 'Todos los contactos de esa audiencia están dados de baja'
                : ($channel === 'email' ? 'Ningún contacto de esa audiencia tiene correo' : 'Ningún contacto de esa audiencia tiene teléfono')], 400);
        }
        $origen = [
            'audience' => json_encode($aud, JSON_UNESCAPED_UNICODE), 'audience_desc' => $audDesc,
            'label_id' => ($aud['type'] === 'labels' && count($aud['ids']) === 1) ? $aud['ids'][0] : null,
            'phonebook_id' => $aud['type'] === 'phonebook' ? $aud['id'] : null,
        ];

        // --- Campos propios del canal ---
        if ($channel === 'email') {
            $subject = trim((string) $request->input('subject'));
            $body    = trim((string) $request->input('body_html'));
            if ($subject === '') return response()->json(['ok' => false, 'error' => 'Ponle un asunto al correo'], 400);
            if ($body === '')    return response()->json(['ok' => false, 'error' => 'Escribe el cuerpo del correo'], 400);
            if (!\App\Models\EmailAccount::where('funcion', 'campanas')->where('active', true)->whereNotNull('smtp_host')->exists()) {
                return response()->json(['ok' => false, 'error' => 'No hay un remitente de campañas por correo (SMTP). Configúralo en Campañas → Ajustes → Correo de campañas.'], 400);
            }
            $campos = $origen + [
                'channel' => 'email', 'template_name' => null, 'language' => 'es', 'components' => '[]',
                'subject' => mb_substr($subject, 0, 255), 'body_html' => \App\Services\HtmlSanitizer::cleanEmail($body),
            ];
        } else {
            $template = trim((string) $request->input('template_name'));
            $lang     = $request->input('language', 'es');
            $components = $request->input('components', []);
            if ($template === '') return response()->json(['ok' => false, 'error' => 'Elige una plantilla'], 400);

            // Las bajas (opt-out) ya las quita la audiencia. La baja es SOLO de CAMPAÑAS; el
            // envío individual de SOPORTE (SendController) NO mira opted_out. No añadirlo allí.
            // Trazabilidad/coste: categoría de la plantilla + tarifa aplicada AHORA.
            $cat = strtoupper((string) $request->input('category', ''));
            $campos = $origen + [
                'channel' => 'whatsapp', 'template_name' => $template, 'language' => $lang,
                'components' => json_encode($components, JSON_UNESCAPED_UNICODE),
                'subject' => null, 'body_html' => null,
                'category' => $cat ?: null, 'unit_cost' => self::tarifaCategoria($cat),
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

        // Tramos: como mucho N destinatarios al día (vacío/0 = todos de una vez). Si cabe en
        // un solo tramo no hace falta repartir.
        $porDia = max(0, (int) $request->input('daily_limit', 0));
        $porDia = ($porDia > 0 && $porDia < count($recipients)) ? $porDia : null;

        $campaignId = DB::table('campaigns')->insertGetId(array_merge([
            'title' => $title, 'status' => 'scheduled', 'scheduled_at' => $scheduledAt,
            'total' => count($recipients), 'daily_limit' => $porDia, 'created_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
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
        return response()->json(['ok' => true, 'id' => $campaignId, 'immediate' => $immediate, 'stats' => $stats, 'excluded' => $excluded,
            // Lanzada «ya» pero hoy no sale (fin de semana o festivo): para avisar en pantalla.
            'waiting' => $immediate ? $campaigns->esperaHoy() : null]);
    }

    /**
     * BAJA de campañas por CORREO. Llega desde el enlace firmado del pie de cada correo
     * (ruta pública, la firma es la autenticación). Marca al contacto como opted_out y
     * devuelve una página de confirmación. Idempotente: dar de baja dos veces no falla.
     */
    public function unsubscribe(Request $request, int $contact)
    {
        // Idempotente: solo la primera vez cuenta (fecha en la ficha + línea en el registro).
        if (DB::table('contacts')->where('id', $contact)->where('opted_out', 0)->update(['opted_out' => 1, 'opted_out_at' => now()])) {
            \App\Services\ConsentLog::anotar($contact, 'optout', 'email');
        }
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
                COALESCE(c.audience_desc, p.name, CONCAT('🏷 ', l.name)) AS source_name,
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
