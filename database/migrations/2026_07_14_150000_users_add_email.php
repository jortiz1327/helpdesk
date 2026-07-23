<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los usuarios internos (agentes) no tenían email: la tabla se creó con
 * usuario/nombre/contraseña. Se añade para identificarlos en la interfaz
 * (y de cara al canal correo: un agente responde desde su dirección).
 *
 * Nullable: el login sigue siendo por `username`, no por email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });
    }
};
