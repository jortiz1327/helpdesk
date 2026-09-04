<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Panel de ANALÍTICAS del área de CAMPAÑAS.
 *
 * Enfocado en quien LANZA campañas y difusiones (WhatsApp y correo) y formularios:
 * rendimiento de campañas por canal, formularios, salud de la base de contactos
 * (alcanzables por canal + bajas) y segmentación por etiqueta. NADA de soporte
 * (el tiempo de 1ª respuesta y demás viven en el Helpdesk / Informes).
 */
class AnalyticsController extends Controller
{
    public function handle()
    {
        $mes = date('Y-m-01 00:00:00');

        // ---- KPIs de campañas (destinatarios por estado) ----
        $r = DB::selectOne("
            SELECT SUM(status IN ('sent','delivered','read')) sent,
                   SUM(status IN ('delivered','read'))        delivered,
                   SUM(status = 'read')                       readed
            FROM campaign_recipients");
        $sent = (int) ($r->sent ?? 0);
        $delivered = (int) ($r->delivered ?? 0);

        // La «lectura» solo tiene sentido en WhatsApp (el correo no traquea aperturas).
        $rw = DB::selectOne("
            SELECT SUM(cr.status IN ('sent','delivered','read')) sent, SUM(cr.status = 'read') readed
            FROM campaign_recipients cr JOIN campaigns c ON c.id = cr.campaign_id
            WHERE c.channel = 'whatsapp'");
        $sentWa = (int) ($rw->sent ?? 0);
        $readWa = (int) ($rw->readed ?? 0);

        $kpi = [
            'campaigns_total' => (int) DB::table('campaigns')->count(),
            'campaigns_month' => (int) DB::table('campaigns')->where('created_at', '>=', $mes)->count(),
            'msgs_sent'       => $sent,
            'msgs_delivered'  => $delivered,
            'delivery_rate'   => $sent ? (int) round($delivered / $sent * 100) : 0,
            'read_rate'       => $sentWa ? (int) round($readWa / $sentWa * 100) : 0,
            'optout_total'    => (int) DB::table('contacts')->where('opted_out', 1)->count(),
            'optout_month'    => (int) DB::table('contacts')->where('opted_out', 1)->where('opted_out_at', '>=', $mes)->count(),
        ];

        // ---- Rendimiento por canal (WhatsApp vs Correo) ----
        $vacio = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
        $channels = ['whatsapp' => $vacio, 'email' => $vacio];
        foreach (DB::select("
            SELECT c.channel,
                   SUM(r.status IN ('sent','delivered','read')) sent,
                   SUM(r.status IN ('delivered','read'))        delivered,
                   SUM(r.status = 'read')                       readed,
                   SUM(r.status = 'failed')                     failed
            FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
            GROUP BY c.channel") as $row) {
            if (!isset($channels[$row->channel])) continue;
            $channels[$row->channel] = [
                'sent' => (int) $row->sent, 'delivered' => (int) $row->delivered,
                'read' => (int) $row->readed, 'failed' => (int) $row->failed,
            ];
        }

        // ---- Formularios ----
        $forms = [
            'published'   => (int) DB::table('forms')->where('status', 'published')->count(),
            'total'       => (int) DB::table('forms')->count(),
            'sends'       => (int) DB::table('messages')->where('direction', 'out')->where('body', 'like', '📋 Formulario:%')->count(),
            'submissions' => (int) DB::table('form_submissions')->count(),
        ];
        $forms['response_rate'] = $forms['sends'] ? (int) round($forms['submissions'] / $forms['sends'] * 100) : 0;

        // ---- Salud de la base de contactos ----
        $conEmail = fn ($q) => $q->whereNotNull('email')->where('email', '<>', '');
        $contacts = [
            'whatsapp'  => (int) DB::table('contacts')->whereNotNull('wa_id')->count(),
            'email'     => (int) DB::table('contacts')->where($conEmail)->count(),
            'optout'    => $kpi['optout_total'],
            'new_month' => (int) DB::table('contacts')->where('created_at', '>=', $mes)->count(),
            'total'     => (int) DB::table('contacts')->where(fn ($q) => $q->whereNotNull('wa_id')->orWhere($conEmail))->count(),
        ];

        // ---- Contactos por etiqueta (segmentación) ----
        $byLabel = DB::select("
            SELECT l.name, l.color, COUNT(cl.contact_id) AS n
            FROM labels l
            LEFT JOIN contact_labels cl ON cl.label_id = l.id
            GROUP BY l.id, l.name, l.color ORDER BY n DESC, l.name ASC");

        // ---- Últimas campañas ----
        $recent = DB::select("
            SELECT c.id, c.title, c.channel, c.total, c.sent, c.failed,
                   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('delivered','read')) AS delivered,
                   (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'read') AS readed
            FROM campaigns c ORDER BY c.id DESC LIMIT 6");
        foreach ($recent as $c) {
            $c->total = (int) $c->total; $c->sent = (int) $c->sent; $c->failed = (int) $c->failed;
            $c->delivered = (int) $c->delivered; $c->read = (int) $c->readed; unset($c->readed);
        }

        return response()->json([
            'ok'        => true,
            'kpi'       => $kpi,
            'channels'  => $channels,
            'forms'     => $forms,
            'contacts'  => $contacts,
            'by_label'  => $byLabel,
            'recent'    => $recent,
        ]);
    }
}
