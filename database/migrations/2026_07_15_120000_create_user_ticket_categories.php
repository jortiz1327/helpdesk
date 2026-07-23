<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Áreas de un agente: a qué CATEGORÍAS pertenece. Un agente solo ve los tickets
 * de sus categorías (no todos). La categoría hace de "departamento": clasifica el
 * ticket Y define quién lo atiende. Sin duplicar conceptos.
 *
 * A los roles con `tickets.view_all` (encargado, superadmin) esto no les afecta:
 * ellos ven todo igualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ticket_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('category_id');
            $table->primary(['user_id', 'category_id']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('ticket_categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ticket_categories');
    }
};
