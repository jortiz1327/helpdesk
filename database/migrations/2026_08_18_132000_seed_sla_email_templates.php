<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Plantillas de correo para los avisos de SLA. Como el resto de plantillas, nacen
 * DESACTIVADAS (active=false): no se manda nada hasta que alguien las activa en
 * Configuración → Plantillas de aviso. Destinatarios internos (nunca el cliente):
 *   · sla_warning → el agente asignado.
 *   · sla_breach  → el agente asignado + los administradores (escalado).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $filas = [
            [
                'key'     => 'sla_warning',
                'subject' => 'Aviso de SLA: el ticket {{codigo}} está por vencer',
                'body'    => "<p>Hola {{agente}},</p>"
                    . "<p>El ticket <b>{{codigo}}</b> — «{{asunto}}» está <b>a punto de incumplir su SLA</b>.</p>"
                    . "<p><b>Reloj:</b> {{reloj}}<br><b>Vence:</b> {{vence}}<br><b>Queda:</b> {{retraso}}</p>"
                    . "<p>Cliente: {{cliente}} · Estado actual: {{estado}}.</p>",
                'active'     => false,
                'recipients' => json_encode(['client' => false, 'agent' => true, 'category' => false, 'admins' => false]),
            ],
            [
                'key'     => 'sla_breach',
                'subject' => 'SLA VENCIDO: ticket {{codigo}}',
                'body'    => "<p>Hola {{agente}},</p>"
                    . "<p>El ticket <b>{{codigo}}</b> — «{{asunto}}» ha <b>incumplido su SLA</b>.</p>"
                    . "<p><b>Reloj:</b> {{reloj}}<br><b>Vencía:</b> {{vence}}<br><b>Lleva vencido:</b> {{retraso}}</p>"
                    . "<p>Cliente: {{cliente}} · Estado actual: {{estado}}.</p>",
                'active'     => false,
                'recipients' => json_encode(['client' => false, 'agent' => true, 'category' => false, 'admins' => true]),
            ],
        ];

        foreach ($filas as $f) {
            if (DB::table('email_templates')->where('key', $f['key'])->exists()) continue;
            DB::table('email_templates')->insert($f + ['created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('key', ['sla_warning', 'sla_breach'])->delete();
    }
};
