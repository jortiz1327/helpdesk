<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REGLAS AUTOMÁTICAS de tickets (equivale al «Flujo de trabajo» de osTicket).
 *
 * Al crearse un ticket se evalúan las reglas activas por orden: si sus condiciones
 * casan (asunto/cuerpo/remitente contienen X…), se aplican sus acciones (asignar
 * agente, poner categoría, subir prioridad).
 *
 * OJO: no tiene nada que ver con los flujos/bots de Campañas (`flows`), que
 * conversan por WhatsApp. Esto solo clasifica y reparte tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);          // orden de ejecución
            $table->string('channel', 20)->default('any');            // any|email|whatsapp|web
            $table->enum('match', ['any', 'all'])->default('any');    // ¿cualquiera o todas las condiciones?
            $table->json('conditions');                               // [{field,op,value}, …]
            $table->json('actions');                                  // {assign_to, category_id, priority}
            $table->boolean('stop')->default(false);                  // si casa, no seguir con más reglas
            $table->timestamps();

            $table->index(['active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_rules');
    }
};
