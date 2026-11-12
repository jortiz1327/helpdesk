<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Chats de prueba de la IA (página /ia, solo en local): una conversación escrita a mano contra el
 * agente, sin tickets ni clientes reales. Cada respuesta del agente se valora igual que en un ticket
 * y la corrección pasa a la memoria de la IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_lab_chats', function (Blueprint $t) {
            $t->id();
            $t->string('title', 190);
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_email', 190)->nullable();   // para probar la verificación del hotel
            $t->timestamps();
        });

        Schema::create('ai_lab_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('chat_id');
            $t->string('role', 10);                          // user (el «cliente») | assistant (la IA)
            $t->text('body')->nullable();
            $t->string('status', 12)->nullable();            // assistant: ready | abstained | failed
            $t->string('error', 250)->nullable();
            $t->string('model', 60)->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->boolean('superseded')->default(false);       // sustituida por «Otra»: se ve atenuada y no cuenta como contexto
            $t->string('rating', 4)->nullable();             // up | down
            $t->text('correction')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->foreign('chat_id')->references('id')->on('ai_lab_chats')->cascadeOnDelete();
            $t->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_lab_messages');
        Schema::dropIfExists('ai_lab_chats');
    }
};
