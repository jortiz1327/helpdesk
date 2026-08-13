<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp: un chat = UN ticket por contacto (todo el historial junto). Para
 * distinguir la «conversación más reciente» del historial anterior, se guarda
 * cuándo empezó esa última racha; el frontend pinta ahí un separador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('conversation_since')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('conversation_since');
        });
    }
};
