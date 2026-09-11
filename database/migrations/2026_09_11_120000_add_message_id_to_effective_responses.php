<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Enlaza la respuesta efectiva con el MENSAJE del que salió (message_id). Así la ⭐ del
 * mensaje funciona como interruptor: si ya existe una respuesta efectiva para ese mensaje,
 * se puede QUITAR; y la ficha del ticket sabe qué mensajes están marcados para pintar la
 * estrella rellena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('effective_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->nullable()->after('ticket_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('effective_responses', function (Blueprint $table) {
            $table->dropColumn('message_id');
        });
    }
};
