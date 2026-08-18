<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Preferencias de aviso por correo de cada usuario. Por defecto activadas: quien no
 * quiera recibirlos los desactiva desde su ficha en Usuarios.
 *   · notify_sla      → avisos de SLA (por vencer / vencido) de sus tickets.
 *   · notify_assigned → aviso cuando se le asigna un ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_sla')->default(true)->after('email');
            $table->boolean('notify_assigned')->default(true)->after('notify_sla');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_sla', 'notify_assigned']);
        });
    }
};
