<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POSPONER TICKETS (snooze) + RECIBIMIENTO MATUTINO.
 *
 * Un ticket se aparta de la cola («duerme») hasta una fecha o hasta que el cliente
 * responda. El snooze es DEL TICKET (lo ve el equipo), no un recordatorio personal.
 *
 * «Duerme» mientras:  snoozed_until > now()  (por tiempo)
 *                 o   snooze_wake_on_reply = 1  (hasta que responda).
 * Al despertar (respuesta del cliente o vencer la fecha) los campos se limpian y el
 * despertar queda en ticket_events (type='snooze_wake'), que alimenta el recibimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('snoozed_until')->nullable()->after('last_direction');
            $table->boolean('snooze_wake_on_reply')->default(false)->after('snoozed_until');
            $table->unsignedBigInteger('snoozed_by')->nullable()->after('snooze_wake_on_reply');
            $table->dateTime('snoozed_at')->nullable()->after('snoozed_by');
            $table->string('snooze_reason', 160)->nullable()->after('snoozed_at');

            $table->foreign('snoozed_by')->references('id')->on('users')->nullOnDelete();
            // La bandeja filtra los dormidos por estas dos columnas en cada carga.
            $table->index(['snoozed_until']);
            $table->index(['snooze_wake_on_reply']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Última vez que el agente vio el recibimiento (para mostrarlo solo 1 vez/día).
            $table->dateTime('snooze_briefing_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['snoozed_by']);
            $table->dropIndex(['snoozed_until']);
            $table->dropIndex(['snooze_wake_on_reply']);
            $table->dropColumn(['snoozed_until', 'snooze_wake_on_reply', 'snoozed_by', 'snoozed_at', 'snooze_reason']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('snooze_briefing_at');
        });
    }
};
