<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Índice para el filtro «contactos con mensaje de un canal» (Contactos por área y el
 * inbox de Chat en vivo). Ese EXISTS materializaba un escaneo sobre TODO `messages`
 * (~129k). Con (channel, funcion, contact_id) el conjunto de contactos se construye con
 * un escaneo ÍNDICE-ONLY acotado al canal/función: campañas baja de 129k a ~60k y el
 * inbox (channel=whatsapp + funcion=campanas) a ~2,4k, ambos sin tocar la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->index(['channel', 'funcion', 'contact_id'], 'messages_channel_funcion_contact_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropIndex('messages_channel_funcion_contact_index');
        });
    }
};
