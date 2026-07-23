<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Respuestas predefinidas ("canned responses"): textos reutilizables que el
 * agente inserta al responder, o escribiendo «/atajo» en el editor.
 *
 * Las gestiona quien tiene support.config; las USA cualquier agente que responda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            // Atajo que se escribe tras «/» (ej: «saludo» → /saludo). Único y sin espacios.
            $table->string('shortcut', 40)->unique();
            $table->string('title', 120);       // nombre visible en el menú
            $table->text('body');               // el texto que se inserta
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
    }
};
