<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * SEGUIMIENTO AUTOMÁTICO (tickets de correo y web, nunca WhatsApp): si el cliente no
 * contesta en 8 días con el ticket «abierto», se le escribe un recordatorio; si a las
 * 24 h sigue sin decir nada, el ticket pasa a «resuelto». Lo hace `tickets:followup`.
 *
 * Solo para los tickets NUEVOS: `ticket_followup_since` guarda el momento del
 * despliegue y los anteriores no se tocan (esos se cierran a mano). La plantilla nace
 * ACTIVA porque se pidió así; apagarla en «Plantillas de aviso» desactiva todo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_templates')->insertOrIgnore([
            'key'        => 'ticket_followup',
            'subject'    => '¿Sigues necesitando ayuda? [{{codigo}}]',
            'body'       => "<p>Hola {{cliente}}, hace unos días te respondimos y no hemos vuelto a saber de ti.</p>"
                          . "<p>¿Sigues necesitando ayuda? Responde a este correo y lo retomamos. "
                          . "Si no recibimos respuesta en las próximas 24 horas, daremos la incidencia por resuelta.</p>",
            'active'     => true,
            'recipients' => json_encode(['client' => true, 'agent' => false, 'category' => false, 'admins' => false]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (\App\Models\Setting::get('ticket_followup_since') === null) {
            \App\Models\Setting::put('ticket_followup_since', now()->toDateTimeString());   // put() limpia la caché
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'ticket_followup')->delete();
        DB::table('settings')->where('key', 'ticket_followup_since')->delete();
    }
};
