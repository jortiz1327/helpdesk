<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Simplifica los CUERPOS de las plantillas de aviso. Ahora los correos van envueltos
 * en una plantilla de marca (cabecera + titular + «código · asunto» + firma + pie),
 * así que el cuerpo ya no tiene que repetir el saludo largo, el código/asunto ni el
 * cierre «Un saludo, {{soporte}}»: se queda con el mensaje esencial.
 *
 * Es una migración de DATOS: reescribe el cuerpo de estas 6 claves. Si alguien los
 * había personalizado a mano, se sobrescriben (en esta fase eran los textos por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cuerpos = [
            'ticket_created' =>
                "<p>Hola {{cliente}}, hemos recibido tu solicitud y ya está en cola de atención.</p>"
                . "<p>Te responderemos lo antes posible. Puedes añadir información respondiendo a este mismo correo.</p>",

            'ticket_closed' =>
                "<p>Hola {{cliente}}, hemos marcado tu incidencia como <b>{{estado}}</b>.</p>"
                . "<p>Si el problema persiste o necesitas algo más, responde a este correo y la reabriremos.</p>",

            'ticket_assigned' =>
                "<p>Hola {{agente}}, se te ha asignado este ticket.</p>"
                . "<p><b>Cliente:</b> {{cliente}} · <b>Estado:</b> {{estado}}</p>",

            'sla_warning' =>
                "<p>Hola {{agente}}, este ticket está <b>a punto de incumplir su SLA</b>.</p>"
                . "<p><b>Reloj:</b> {{reloj}} · <b>Vence:</b> {{vence}} · <b>Queda:</b> {{retraso}}</p>"
                . "<p>Cliente: {{cliente}} · Estado: {{estado}}.</p>",

            'sla_breach' =>
                "<p>Hola {{agente}}, este ticket ha <b>incumplido su SLA</b>.</p>"
                . "<p><b>Reloj:</b> {{reloj}} · <b>Vencía:</b> {{vence}} · <b>Lleva vencido:</b> {{retraso}}</p>"
                . "<p>Cliente: {{cliente}} · Estado: {{estado}}.</p>",

            'csat_survey' =>
                "<p>Hola {{cliente}}, hemos dado por resuelta tu incidencia. ¿Cómo valoras nuestra atención?</p>"
                . "<p>{{valoracion}}</p>",
        ];

        foreach ($cuerpos as $key => $body) {
            DB::table('email_templates')->where('key', $key)->update([
                'body'       => $body,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Migración de datos: los cuerpos son contenido editable en la propia app
        // (Plantillas de aviso), así que revertir no los restaura — no es necesario.
    }
};
