<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOQUEO DE TICKETS (evitar colisión de agentes).
 *
 * Cuando un agente abre un ticket queda «tomado» durante unos minutos: los demás
 * ven quién lo está atendiendo y no pueden responder a la vez. El bloqueo caduca
 * solo, así que un agente que cierre el navegador no deja el ticket atascado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('locked_by')->nullable()->after('assigned_to');
            $table->timestamp('locked_at')->nullable()->after('locked_by');
            $table->foreign('locked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['locked_by']);
            $table->dropColumn(['locked_by', 'locked_at']);
        });
    }
};
