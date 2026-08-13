<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separación WhatsApp Soporte ↔ Campañas: cada mensaje de WhatsApp lleva su
 * FUNCIÓN ('soporte' | 'campanas'), según el número por el que entró (Opción B).
 * Sin esto, la bandeja de Campañas mostraba también el histórico de soporte
 * (todo era channel='whatsapp' sin distinción).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('funcion', 16)->nullable()->after('channel')->index();
        });

        // Todo lo que hay ahora de WhatsApp es del histórico de SOPORTE.
        DB::table('messages')->where('channel', 'whatsapp')->update(['funcion' => 'soporte']);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('funcion');
        });
    }
};
