<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aviso nuevo: «Respuesta del cliente». Cuando el cliente responde (por correo o
 * por el portal) a un ticket que YA tiene agente asignado, se avisa a ese agente.
 * Antes no existía: al agente no le llegaba nada cuando le contestaban.
 *
 * Nace DESACTIVADA como las demás (envía correo automático); se activa a mano en
 * Configuración → Avisos automáticos. Por defecto avisa SOLO al agente asignado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->where('key', 'ticket_reply')->exists()) return;

        $now = now();
        DB::table('email_templates')->insert([
            'key'     => 'ticket_reply',
            'subject' => 'Nueva respuesta en el ticket {{codigo}}',
            'body'    => "<p>Hola {{agente}},</p>"
                       . "<p>El cliente ha respondido en el ticket <b>{{codigo}}</b>.</p>"
                       . "<p><b>Asunto:</b> {{asunto}}<br><b>Cliente:</b> {{cliente}}<br><b>Estado:</b> {{estado}}</p>"
                       . "<p>{{soporte}}</p>",
            'active'      => false,
            'recipients'  => json_encode(['client' => false, 'agent' => true, 'category' => false, 'admins' => false]),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'ticket_reply')->delete();
    }
};
