<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para el REPARTO ALTERNANDO entre los que cubren un turno.
 *
 * Al crear un ticket se pregunta a quién le tocó el último, y eso mira el historial
 * de asignaciones. Sin índice es un recorrido de toda la tabla en cada ticket que
 * entra; con 50.000 tickets eso se nota, y justo en el peor momento (la ráfaga de
 * correos que importa el cron). Lleva `created_at` para que el MAX salga del propio
 * índice sin tocar la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_events', function (Blueprint $t) {
            $t->index(['type', 'to_value', 'created_at'], 'te_reparto');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_events', fn (Blueprint $t) => $t->dropIndex('te_reparto'));
    }
};
