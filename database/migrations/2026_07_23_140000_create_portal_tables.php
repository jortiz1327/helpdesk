<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL PÚBLICO: identidad del cliente por CORREO + CÓDIGO de un solo uso.
 *
 * Nadie se registra. El código solo prueba que quien escribe abre ese buzón; a
 * partir de ahí ve sus tickets (los de ese correo) y puede crear y responder. Es
 * la misma identidad que ya usa el canal de correo, solo que por una puerta web.
 *
 * Dos tablas:
 *  · portal_codes    — los códigos enviados, con caducidad e intentos. Se hashean:
 *                      una fuga de la BD no puede revelar códigos en vuelo.
 *  · portal_sessions — el «pase» que se entrega al acertar el código, para no pedirlo
 *                      en cada gesto. También hasheado y con caducidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_codes', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190)->index();
            $t->string('code_hash', 64);            // sha256 del código de 6 dígitos
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('ip', 45)->nullable();       // para limitar por origen
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable()->index();   // para el límite «N por hora»
        });

        Schema::create('portal_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash', 64)->unique();  // sha256 del token que guarda el cliente
            $t->string('email', 190)->index();
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('portal_codes');
    }
};
