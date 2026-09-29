<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Modelo de estados nuevo: Nuevo · Abierto · Planificado · Resuelto · Cerrado.
 *  - Se retira «esperando_respuesta» como ESTADO (pasa a ser un simple indicador
 *    por-responder/respondido; el reloj del SLA se pausa solo al esperar al cliente).
 *  - Se añade «planificado» (peticiones que se gestionan a días/semanas).
 * Los tickets que estaban en «esperando_respuesta» (o el ya retirado «en_progreso»)
 * pasan a «abierto». Primero se migran las filas y LUEGO se ajusta el ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tickets')->whereIn('status', ['esperando_respuesta', 'en_progreso'])
            ->update(['status' => 'abierto']);

        DB::statement("ALTER TABLE tickets MODIFY status
            ENUM('nuevo','abierto','planificado','resuelto','cerrado') NOT NULL DEFAULT 'nuevo'");
    }

    public function down(): void
    {
        // Reabre el ENUM a los valores antiguos (sin poder recuperar qué ticket era cuál).
        DB::statement("ALTER TABLE tickets MODIFY status
            ENUM('nuevo','abierto','en_progreso','esperando_respuesta','planificado','resuelto','cerrado') NOT NULL DEFAULT 'nuevo'");
    }
};
