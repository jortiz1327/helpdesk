<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Plantilla de correo para la ENCUESTA DE SATISFACCIÓN (CSAT). Como el resto, nace
 * DESACTIVADA: no se envía hasta activarla en Configuración → Plantillas de aviso.
 * Destinatario: el cliente. La variable {{valoracion}} la sustituye NotifyService::csat()
 * por las 5 estrellas clicables (enlace firmado, 1-clic).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->where('key', 'csat_survey')->exists()) return;

        DB::table('email_templates')->insert([
            'key'     => 'csat_survey',
            'subject' => '¿Cómo valorarías nuestra atención? · {{codigo}}',
            'body'    => "<p>Hola {{cliente}},</p>"
                . "<p>Hemos dado por resuelta tu incidencia <b>{{codigo}}</b>. Nos ayudaría mucho saber "
                . "cómo valoras nuestra atención:</p>"
                . "<p>{{valoracion}}</p>"
                . "<p>Gracias por tu tiempo.</p>",
            'active'     => false,
            'recipients' => json_encode(['client' => true, 'agent' => false, 'category' => false, 'admins' => false]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'csat_survey')->delete();
    }
};
