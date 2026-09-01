<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cortafuegos anti «correo venenoso»: si un correo del buzón falla siempre al
 * procesarse, antes bloqueaba TODA la importación posterior (se reintentaba sin fin).
 * Guardamos en qué UID estamos atascados y cuántas veces seguidas ha fallado; tras N
 * intentos se salta para no bloquear el buzón.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('fail_uid')->default(0)->after('last_uid');     // UID que está fallando
            $table->unsignedSmallInteger('fail_count')->default(0)->after('fail_uid'); // fallos seguidos sobre él
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn(['fail_uid', 'fail_count']);
        });
    }
};
