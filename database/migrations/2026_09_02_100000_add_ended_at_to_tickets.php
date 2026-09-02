<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FIN EFECTIVO del ticket para ordenar el historial del agente sin filesort.
 *
 * El historial ordenaba por `COALESCE(closed_at, resolved_at)` (un ticket puede estar
 * «cerrado» sin haber pasado por «resuelto», y viceversa), y esa función sobre columnas
 * impedía usar índice → filesort. Se materializa en una columna GENERADA (STORED, se
 * mantiene sola) y se indexa como (assigned_to, ended_at): así el WHERE por agente + el
 * ORDER BY ended_at entran por índice (el `status IN(...)` queda de filtro residual, que
 * NO rompe el orden). Con la columna en el índice, además, `Using index`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dateTime('ended_at')->storedAs('COALESCE(closed_at, resolved_at)')->nullable();
        });
        Schema::table('tickets', function (Blueprint $t) {
            $t->index(['assigned_to', 'ended_at'], 'tickets_assigned_ended_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropIndex('tickets_assigned_ended_index');
            $t->dropColumn('ended_at');
        });
    }
};
