<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos de los mensajes de un ticket (capturas, facturas, logs…).
 *
 * Los ficheros NO se guardan en public/: se guardan fuera del alcance web y se
 * sirven por un endpoint que comprueba el token. Un adjunto de soporte puede
 * llevar datos personales; no puede quedar accesible por URL a quien la adivine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('name', 190);            // nombre original, para descargar
            $table->string('path', 255);            // ruta interna en storage
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->index('message_id');
        });

        /*
         * ¿El cuerpo del mensaje lleva HTML con formato?
         *   0 = texto plano (lo que llega de WhatsApp o del correo): se escapa al pintarlo.
         *   1 = HTML ya SANEADO en el servidor (lo que escribe un agente en el editor).
         * Sin esta marca no se sabe qué es seguro pintar y qué hay que escapar.
         */
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_html')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('is_html'));
        Schema::dropIfExists('attachments');
    }
};
