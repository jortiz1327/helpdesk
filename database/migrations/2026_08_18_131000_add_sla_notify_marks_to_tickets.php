<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marcas para NO repetir los avisos de SLA por correo. El cron `sla:check` manda un
 * solo correo por umbral: al entrar en «por vencer» sella `sla_warned_at`, y al vencer
 * sella `sla_breached_at`. Se limpian si el ticket se REABRE (ver TicketService::setStatus)
 * para que un ticket reabierto pueda volver a avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_warned_at')->nullable()->after('sla_resolve_due_at');
            $table->timestamp('sla_breached_at')->nullable()->after('sla_warned_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['sla_warned_at', 'sla_breached_at']);
        });
    }
};
