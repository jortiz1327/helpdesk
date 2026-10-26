<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cuentas que NO reciben tickets asignados (p. ej. gerencia): pueden entrar, ver y
 * responder, pero nadie —ni a mano, ni las reglas, ni el turno, ni el escalado de SLA,
 * ni la autoasignación al responder— les pone un ticket. Por defecto, todos sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('assignable')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('assignable'));
    }
};
