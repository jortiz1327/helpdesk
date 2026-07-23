<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajusta el modelo a lo que muestra el diseño de referencia (Base44):
 *  - 6 estados (faltaba «abierto»); se renombran dos para hablar el mismo idioma.
 *  - Las categorías llevan SLA en horas y descripción (se gestionan desde Configuración).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tickets MODIFY status
            ENUM('nuevo','abierto','en_progreso','esperando_respuesta','resuelto','cerrado')
            NOT NULL DEFAULT 'nuevo'");

        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->string('description', 200)->nullable()->after('name');
            // Horas de SLA para la primera atención. null = sin compromiso.
            $table->unsignedSmallInteger('sla_hours')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tickets MODIFY status
            ENUM('nuevo','en_proceso','pendiente_cliente','resuelto','cerrado')
            NOT NULL DEFAULT 'nuevo'");

        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn(['description', 'sla_hours']);
        });
    }
};
