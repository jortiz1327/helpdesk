<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Huella (sha256) de la clave pública RSA de cada servidor ZKONG. Se apunta la primera vez
 * que se entra; si después el servidor da otra, no se le manda la contraseña (por http
 * alguien en el camino podría dar la suya). Se borra al cambiar dirección, usuario o
 * contraseña del servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zkong_servers', function (Blueprint $t) {
            $t->char('public_key_sha256', 64)->nullable()->after('token_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('zkong_servers', function (Blueprint $t) {
            $t->dropColumn('public_key_sha256');
        });
    }
};
