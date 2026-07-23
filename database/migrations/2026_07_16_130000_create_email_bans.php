<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de correos BLOQUEADOS (banlist). Un correo entrante de una dirección (o
 * dominio) bloqueado y activo NO crea ticket: se descarta. Equivale a «Correos
 * baneados» de osTicket. Sirve para spam, remitentes abusivos y, sobre todo, los
 * MAILER-DAEMON (rebotes) que si no montarían bucles de auto-respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_bans', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();   // dirección completa o «@dominio»/«dominio»
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();         // notas internas
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_bans');
    }
};
