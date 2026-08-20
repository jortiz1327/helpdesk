<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Revocación de tokens. El token de acceso es un HMAC sin estado de 30 días: hasta
 * ahora, cambiar la contraseña no invalidaba los tokens ya emitidos ni había forma
 * de expulsar a un usuario. Con este contador por usuario, el token lleva la versión
 * con la que se emitió; al subir la versión (p. ej. al cambiar la contraseña) todos
 * los tokens anteriores dejan de valer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('token_version')->default(1)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('token_version');
        });
    }
};
