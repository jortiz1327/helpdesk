<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FECHAS DE VENCIMIENTO del SLA, guardadas en el ticket.
 *
 * El estado del SLA se calculaba al vuelo cruzando la apertura con el plazo de la
 * categoría, el horario de atención y los festivos. Eso vale para PINTAR un ticket,
 * pero no para FILTRAR ni CONTAR: no se puede pedir a la base de datos «dame los
 * vencidos» si el vencimiento no existe en ninguna columna.
 *
 * Se recalculan al crear el ticket, al cambiarle la categoría y en cada entrada o
 * salida de pausa (la pausa mueve el vencimiento). Ver TicketService::recalcularSla().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_response_due_at')->nullable()->after('sla_paused_since');
            $table->timestamp('sla_resolve_due_at')->nullable()->after('sla_response_due_at');

            // Para la vista rápida «SLA vencido»: se filtra por estado + vencimiento.
            $table->index(['status', 'sla_resolve_due_at'], 'tickets_status_sla_resolve_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_status_sla_resolve_index');
            $table->dropColumn(['sla_response_due_at', 'sla_resolve_due_at']);
        });
    }
};
