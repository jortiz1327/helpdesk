<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de AVISO por correo (equivale a «Plantillas» de osTicket, pero solo
 * las que aplican aquí: no hay portal de clientes, así que nada de registro,
 * confirmar cuenta o restablecer contraseña).
 *
 * Nacen DESACTIVADAS a propósito: envían correo automático, así que no se manda
 * nada hasta que alguien las active conscientemente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();      // identificador del evento
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('email_templates')->insert([
            [
                'key'     => 'ticket_created',
                'subject' => 'Hemos recibido tu solicitud [{{codigo}}]',
                'body'    => "<p>Hola {{cliente}},</p>"
                           . "<p>Hemos recibido tu solicitud y ya está en cola de atención. Su referencia es <b>{{codigo}}</b>.</p>"
                           . "<p><b>Asunto:</b> {{asunto}}</p>"
                           . "<p>Te responderemos lo antes posible. Si quieres añadir información, responde a este mismo correo.</p>"
                           . "<p>Un saludo,<br>{{soporte}}</p>",
                'active'  => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key'     => 'ticket_closed',
                'subject' => 'Tu solicitud {{codigo}} se ha {{estado}}',
                'body'    => "<p>Hola {{cliente}},</p>"
                           . "<p>Tu solicitud <b>{{codigo}}</b> ({{asunto}}) se ha marcado como <b>{{estado}}</b>.</p>"
                           . "<p>Si el problema persiste o necesitas algo más, responde a este correo y la reabriremos.</p>"
                           . "<p>Un saludo,<br>{{soporte}}</p>",
                'active'  => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key'     => 'ticket_assigned',
                'subject' => 'Se te ha asignado el ticket {{codigo}}',
                'body'    => "<p>Hola {{agente}},</p>"
                           . "<p>Se te ha asignado el ticket <b>{{codigo}}</b>.</p>"
                           . "<p><b>Asunto:</b> {{asunto}}<br><b>Cliente:</b> {{cliente}}<br><b>Estado:</b> {{estado}}</p>"
                           . "<p>{{soporte}}</p>",
                'active'  => false, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
