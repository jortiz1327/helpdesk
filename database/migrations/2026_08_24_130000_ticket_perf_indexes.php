<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ÍNDICES DE RENDIMIENTO a escala (medidos con 50k tickets vía `db:bench`).
 *
 * Dos índices compuestos para las consultas CALIENTES (se ejecutan en cada carga de la
 * bandeja). En el banco de pruebas bajaron las filas recorridas ~63 %:
 *   - (category_id, status, last_message_at): el alcance del agente de un área + orden
 *     por última actividad → bandeja 37,8k → 14,1k filas.
 *   - (assigned_to, status): el contador «míos» y el desglose por agente → 13,6k → 5,0k.
 *
 * Los de fecha (created_at, resolved_at) ya existían. Las series diarias de informes
 * hacen filesort por el DATE() del GROUP BY: eso no lo arregla un índice, así que no se
 * toca aquí. Los índices de una sola columna (assigned_to, category_id) se conservan:
 * respaldan sus claves foráneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['category_id', 'status', 'last_message_at'], 'tickets_cat_status_lma_index');
            $table->index(['assigned_to', 'status'], 'tickets_assigned_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_cat_status_lma_index');
            $table->dropIndex('tickets_assigned_status_index');
        });
    }
};
