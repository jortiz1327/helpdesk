<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * VALORACIÓN del cliente (CSAT). Una por ticket (unique), actualizable unos días.
 * Nota de 1 a 5 estrellas + comentario opcional. La rellena el cliente desde el
 * portal cuando su incidencia queda resuelta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_ratings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id')->unique();
            $t->unsignedTinyInteger('score');          // 1..5
            $t->text('comment')->nullable();
            $t->timestamps();

            $t->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_ratings');
    }
};
