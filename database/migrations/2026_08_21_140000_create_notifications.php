<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de notificaciones in-app. Cada fila es un aviso PARA un usuario (user_id).
 * La primera fuente son las @menciones en notas internas; a futuro caben aquí también
 * «te asignaron», «tu SLA va a vencer», etc. (por eso `type`). Se marca leída con
 * `read_at`. El contador de no leídas se sondea desde el frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');                 // destinatario
            $table->string('type', 30)->default('mention');        // mention | assigned | sla_warning | …
            $table->unsignedBigInteger('ticket_id')->nullable();   // a qué ticket lleva
            $table->unsignedBigInteger('actor_user_id')->nullable(); // quién lo generó
            $table->string('body', 500)->nullable();               // texto ya montado del aviso
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // Bandeja del usuario (no leídas primero, por fecha) y contador de no leídas.
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
