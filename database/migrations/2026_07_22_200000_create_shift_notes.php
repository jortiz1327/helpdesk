<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOTAS DEL CUADRANTE, por DÍA (no por turno).
 *
 * Decisión del usuario (22-jul-2026) con su propio ejemplo: «un día alguien se
 * enfermó y ponemos nota de que hoy todos hacemos soporte». Eso no es de la mañana
 * ni de la tarde, es del día entero; separarlas por turno obligaría a escribir lo
 * mismo dos veces.
 *
 * Una nota por día como mucho: se edita, no se acumulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_notes', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('note', 300);
            $table->unsignedBigInteger('user_id')->nullable();   // quién la escribió
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_notes');
    }
};
