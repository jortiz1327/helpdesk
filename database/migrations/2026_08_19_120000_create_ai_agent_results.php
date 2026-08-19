<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Resultados que un agente EXTERNO (p. ej. un Workspace Agent de ChatGPT) manda de
 * vuelta a su webhook cuando termina de ejecutarse. Como el trigger es asíncrono
 * (202 sin cuerpo), la respuesta llega aquí después, y se asocia al ticket por `ref`.
 * Se guarda también el `raw` para poder ajustar la extracción cuando veamos el
 * formato real que envía el agente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_results', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 120)->nullable()->index();   // correlación (p. ej. "ticket:123")
            $table->string('source', 40)->default('workspace_agent');
            $table->longText('answer')->nullable();            // texto extraído de la respuesta
            $table->longText('raw')->nullable();               // payload completo recibido (para depurar)
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_results');
    }
};
