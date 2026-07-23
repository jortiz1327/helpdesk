<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de la tabla de MENSAJES, la que más crece de todas.
 *
 * Medido con 120.000 mensajes (mediana de varias pasadas, en caliente):
 *
 *   · `(direction, contact_id, created_at)` — lo usa el cálculo del TIEMPO MEDIO DE
 *     PRIMERA RESPUESTA de Analíticas, que agrupa por contacto el primer mensaje de
 *     entrada y el primero de salida. Pasó de 312 ms a 56 ms. También acelera los
 *     «cuántos contactos han escrito» (70 ms → 17 ms).
 *
 *   · `(created_at)` — para los recuentos por fecha del Centro de Soporte. Con él,
 *     «mensajes de hoy» baja de 22 ms a 0,6 ms, SIEMPRE QUE la consulta compare por
 *     rango; envolver la columna en DATE() anula el índice (por eso se reescribió).
 *
 *   · `(sent_by, direction)` — para «mensajes por agente» de Analíticas. OJO: este
 *     índice es OBLIGATORIO junto al primero. Al añadir solo `(direction, …)`, el
 *     optimizador empezó a usarlo para esa consulta y luego tenía que ir a buscar
 *     `sent_by` fila a fila: pasó de 54 ms a 2.028 ms. Con este, 0,5 ms. Añadir un
 *     índice puede EMPEORAR otra consulta; hay que medir las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['direction', 'contact_id', 'created_at'], 'messages_dir_contact_fecha_index');
            $table->index('created_at', 'messages_created_at_index');
            $table->index(['sent_by', 'direction'], 'messages_sent_by_dir_index');
            /*
             * Para el «¿este contacto tiene mensajes de WhatsApp?» de la lista de
             * conversaciones, que se pregunta UNA VEZ POR CONTACTO (la separación
             * entre Campañas y Soporte). Con 3.000 contactos: 101 ms → 45 ms.
             */
            $table->index(['contact_id', 'channel'], 'messages_contact_channel_index');
        });
    }

    public function down(): void
    {
        // Uno a uno y tolerante: si falta alguno, que no deje la reversión a medias.
        foreach ([
            'messages_dir_contact_fecha_index',
            'messages_created_at_index',
            'messages_sent_by_dir_index',
            'messages_contact_channel_index',
        ] as $indice) {
            try {
                Schema::table('messages', fn (Blueprint $table) => $table->dropIndex($indice));
            } catch (\Throwable $e) {
                // ya no estaba
            }
        }
    }
};
