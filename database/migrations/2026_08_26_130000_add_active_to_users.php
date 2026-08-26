<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `active` en usuarios: distingue agentes en activo de los que YA NO están (ex-empleados
 * o el histórico de Faveo). Los inactivos conservan su atribución histórica pero no salen
 * en los desplegables de «asignar ticket» y se marcan en el roster de agentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
