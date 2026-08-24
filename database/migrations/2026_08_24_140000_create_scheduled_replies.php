<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RESPUESTAS PROGRAMADAS: el agente redacta ahora y el mensaje sale a una hora (la
 * próxima apertura del horario laboral, o una fecha a medida). Solo canal CORREO.
 *
 * Los adjuntos se suben YA (a la tabla attachments, con message_id null) y aquí se
 * guardan sus ids; al enviarse, el cron los engancha al mensaje real. El cuerpo es el
 * HTML tal cual saldría; cc/bcc como en una respuesta normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_replies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('user_id')->nullable();      // autor (quién la dejó lista)
            $t->longText('body');                                // HTML del mensaje
            $t->json('cc')->nullable();
            $t->json('bcc')->nullable();
            $t->json('attachment_ids')->nullable();              // adjuntos ya subidos (attachments.id)
            $t->dateTime('send_at');                             // cuándo debe salir
            $t->string('status', 20)->default('pending');        // pending | sent | canceled | failed
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('canceled_at')->nullable();
            $t->string('error', 200)->nullable();
            $t->timestamps();

            $t->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $t->index(['status', 'send_at']);                    // el cron busca pendientes vencidas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_replies');
    }
};
