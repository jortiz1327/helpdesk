<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sugerencias de la IA y lo que los agentes opinan de ellas. Es el conjunto de datos con
 * el que el asistente mejora: cada sugerencia guarda con qué fuentes se escribió, y cada
 * valoración negativa guarda la corrección que escribió la persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('trigger_message_id')->nullable();   // el mensaje del cliente al que contesta
            $t->string('mode', 10)->default('suggest');                 // suggest (horario humano) | auto (fuera de horario)
            $t->string('status', 12)->default('ready');                 // ready | abstained (no sabe) | failed
            $t->longText('body')->nullable();                           // la respuesta propuesta (texto plano)
            $t->json('sources')->nullable();                            // {faqs:[ids], examples:[ids]}
            $t->string('model', 60)->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->string('error', 255)->nullable();

            $t->string('rating', 4)->nullable();                        // up | down
            $t->text('correction')->nullable();                         // qué debía decir (obligatorio si down)
            $t->unsignedBigInteger('rated_by')->nullable();
            $t->timestamp('rated_at')->nullable();
            $t->timestamp('used_at')->nullable();                       // el agente la usó como base de su respuesta
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $t->index(['ticket_id', 'trigger_message_id']);
            $t->index('rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
