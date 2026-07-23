<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿QUIÉN HABLÓ EL ÚLTIMO en cada ticket, guardado en el propio ticket.
 *
 *   'in'  = habló el cliente  → la pelota está en nuestro tejado (sin responder)
 *   'out' = hablamos nosotros → ya está contestado, esperamos al cliente
 *
 * Hasta ahora se calculaba con una subconsulta correlacionada («el último mensaje
 * de ESTE ticket») que se ejecutaba UNA VEZ POR TICKET. Con 50.000 tickets, el
 * contador «Sin responder» costaba 35 de los 62 ms de todos los contadores juntos
 * —y eso con solo 41 mensajes en la tabla: con volumen real de mensajes, peor—.
 * También lo pagaban la columna de la lista y los filtros de respondido/sin responder.
 *
 * Las notas internas NO cuentan: no son una respuesta al cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('last_direction', ['in', 'out'])->nullable()->after('last_message_at');
            // El contador «Sin responder» filtra por estado + dirección.
            $table->index(['status', 'last_direction'], 'tickets_status_last_direction_index');
        });

        // Relleno con lo que ya hay: el último mensaje no interno de cada ticket.
        DB::statement("
            UPDATE tickets t
            SET t.last_direction = (
                SELECT m.direction FROM messages m
                WHERE m.ticket_id = t.id AND m.is_internal_note = 0
                ORDER BY m.id DESC LIMIT 1
            )
        ");
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_status_last_direction_index');
            $table->dropColumn('last_direction');
        });
    }
};
