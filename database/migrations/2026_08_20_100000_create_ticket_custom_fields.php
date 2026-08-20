<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Campos personalizados de ticket (GLOBALES: los mismos para todos los tickets).
 *   · ticket_custom_fields → las DEFINICIONES (etiqueta, tipo, opciones, obligatorio…).
 *   · ticket_field_values  → los VALORES por ticket (un valor por campo).
 * Tipos: text | textarea | number | select | checkbox | date. Por categoría queda a futuro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();               // slug estable
            $table->string('label', 120);
            $table->string('type', 20)->default('text');       // text|textarea|number|select|checkbox|date
            $table->json('options')->nullable();               // opciones (solo select)
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ticket_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('field_id');
            $table->text('value')->nullable();
            $table->unique(['ticket_id', 'field_id']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('field_id')->references('id')->on('ticket_custom_fields')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_field_values');
        Schema::dropIfExists('ticket_custom_fields');
    }
};
