<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pasos por tipo de cliente: las listas se definen en Configuración (por categoría o por
 * cliente, p. ej. Expofarm) y aquí queda qué paso se marcó en cada garantía, quién y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_step_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $t->string('list_id', 20);
            $t->string('step_id', 20);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_at')->useCurrent();
            $t->unique(['warranty_id', 'list_id', 'step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_step_checks');
    }
};
