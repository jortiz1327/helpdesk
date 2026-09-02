<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CUARENTENA DE CORREOS (dead-letter). Un correo entrante que falla al convertirse en
 * ticket tras MAX_MAIL_ATTEMPTS se SALTA para no bloquear el buzón; antes solo quedaba
 * un log + un aviso por correo y el correo «desaparecía» de la app. Ahora aterriza aquí:
 * el admin ve QUÉ correo falló (remitente, asunto, error) y puede descartarlo o
 * reintentar su procesado. El correo sigue en el servidor IMAP (se referencia por UID).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_quarantine', function (Blueprint $t) {
            $t->id();
            $t->foreignId('email_account_id');
            $t->unsignedBigInteger('uid');                 // UID IMAP en el buzón
            $t->string('message_id', 512)->nullable();
            $t->string('from_email')->nullable();
            $t->string('from_name')->nullable();
            $t->string('subject')->nullable();
            $t->text('error')->nullable();
            $t->text('body_preview')->nullable();          // fragmento en texto, para contexto
            $t->timestamp('received_at')->nullable();      // fecha del propio correo
            $t->timestamp('created_at')->nullable();       // cuándo entró en cuarentena
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolution', 20)->nullable();      // discarded | retried
            $t->unique(['email_account_id', 'uid']);       // un correo, una entrada
            $t->index(['email_account_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_quarantine');
    }
};
