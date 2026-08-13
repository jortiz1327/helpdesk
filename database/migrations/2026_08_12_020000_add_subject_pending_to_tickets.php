<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp (un ticket por contacto): cuando empieza una conversación NUEVA, el
 * asunto debe reflejar el tema nuevo, no el de hace meses. Si el primer mensaje
 * de la nueva racha es un saludo, se marca «asunto pendiente» y se rellena con el
 * primer mensaje con enjundia que llegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->boolean('subject_pending')->default(false)->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('subject_pending');
        });
    }
};
