<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encuesta al cerrar la garantía: una nota de 1 a 5 (y un comentario opcional) por
 * garantía, desde el enlace del correo de resolución. Se puede cambiar mientras el
 * enlace siga vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_id')->unique()->constrained('warranties')->cascadeOnDelete();
            $t->unsignedTinyInteger('score');   // 1..5
            $t->text('comment')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_ratings');
    }
};
