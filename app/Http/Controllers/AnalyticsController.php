<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * Panel de ANALÍTICAS del área de CAMPAÑAS.
 *
 * TODO se mide sobre WhatsApp. Antes contaba la base entera y mezclaba el helpdesk:
 * con 18 contactos en total pero solo 2 de WhatsApp, la pantalla decía «18 contactos»
 * —lo detectó el usuario—, y lo mismo pasaba con los mensajes, el tiempo de primera
 * respuesta y el ranking de usuarios, que sumaban las respuestas por correo.
 *
 * El criterio de «contacto de campañas» es el MISMO que usa la lista de contactos:
 * tener algún mensaje de WhatsApp. Que dos pantallas cuenten distinto es peor que
 * cualquier número concreto.
 */
class AnalyticsController extends Controller
{
    /** Solo WhatsApp: es lo que gestiona Campañas. */
    protected const CANAL = "channel = 'whatsapp'";

    public function handle()
    {
        $canal = self::CANAL;

        // Tiempo medio de primera respuesta (segundos), solo en conversaciones de WhatsApp.
        $frt = DB::selectOne("
            SELECT AVG(TIMESTAMPDIFF(SECOND, fi.first_in, fo.first_out)) AS avg_sec, COUNT(*) AS n
            FROM (SELECT contact_id, MIN(created_at) AS first_in FROM messages WHERE direction='in' AND $canal GROUP BY contact_id) fi
            JOIN (
                SELECT m.contact_id, MIN(m.created_at) AS first_out
                FROM messages m
                JOIN (SELECT contact_id, MIN(created_at) AS first_in FROM messages WHERE direction='in' AND $canal GROUP BY contact_id) x
                  ON x.contact_id = m.contact_id
                WHERE m.direction='out' AND m.$canal AND m.created_at > x.first_in
                GROUP BY m.contact_id
            ) fo ON fo.contact_id = fi.contact_id
        ");
        $firstResponse = [
            'avg_seconds' => $frt->avg_sec !== null ? (int) round($frt->avg_sec) : null,
            'count'       => (int) $frt->n,
        ];

        // Etiquetas: solo las de contactos de campañas.
        $byLabel = DB::select("
            SELECT l.name, l.color, COUNT(cl.contact_id) AS n
            FROM labels l
            LEFT JOIN contact_labels cl ON cl.label_id = l.id
              AND EXISTS (SELECT 1 FROM messages mw WHERE mw.contact_id = cl.contact_id AND mw.$canal)
            GROUP BY l.id, l.name, l.color ORDER BY n DESC, l.name ASC
        ");

        $deCampanas = DB::table('contacts as c')->whereExists(
            fn ($q) => $q->select(DB::raw(1))->from('messages as mw')
                ->whereColumn('mw.contact_id', 'c.id')->where('mw.channel', 'whatsapp'),
        );

        $funnel = [
            ['k' => 'Contactos',        'v' => (clone $deCampanas)->count()],
            ['k' => 'Con conversación', 'v' => DB::table('messages')->where('channel', 'whatsapp')->distinct()->count('contact_id')],
            ['k' => 'Etiquetados',      'v' => (clone $deCampanas)->whereExists(
                fn ($q) => $q->select(DB::raw(1))->from('contact_labels as cl')->whereColumn('cl.contact_id', 'c.id'),
            )->count()],
            ['k' => 'Respondidos',      'v' => DB::table('messages')->where('channel', 'whatsapp')
                ->where('direction', 'out')->distinct()->count('contact_id')],
        ];

        $camp = DB::selectOne("
            SELECT
                COUNT(*) AS recipients,
                SUM(status IN ('sent','delivered','read')) AS sent,
                SUM(status IN ('delivered','read')) AS delivered,
                SUM(status = 'read') AS readed,
                SUM(status = 'failed') AS failed
            FROM campaign_recipients
        ");
        $campaigns = [
            'total'      => DB::table('campaigns')->count(),
            'recipients' => (int) ($camp->recipients ?? 0),
            'sent'       => (int) ($camp->sent ?? 0),
            'delivered'  => (int) ($camp->delivered ?? 0),
            'read'       => (int) ($camp->readed ?? 0),
            'failed'     => (int) ($camp->failed ?? 0),
        ];

        // Quién ha respondido por WhatsApp (no las respuestas del helpdesk por correo).
        $byAgent = DB::select("
            SELECT COALESCE(u.name, u.email) AS name, COUNT(*) AS n
            FROM messages m JOIN users u ON u.id = m.sent_by
            WHERE m.direction='out' AND m.sent_by IS NOT NULL AND m.$canal
            GROUP BY u.id, u.name, u.email ORDER BY n DESC LIMIT 10
        ");

        return response()->json([
            'ok'             => true,
            'first_response' => $firstResponse,
            'by_label'       => $byLabel,
            'funnel'         => $funnel,
            'campaigns'      => $campaigns,
            'by_agent'       => $byAgent,
        ]);
    }
}
