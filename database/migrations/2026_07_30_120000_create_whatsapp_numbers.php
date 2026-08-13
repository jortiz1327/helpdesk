<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NÚMEROS de WhatsApp (opción B: un webhook, varios números, se enruta por número).
 *
 * Cada número entrante trae su `phone_number_id`; con esta tabla el webhook sabe a
 * qué FUNCIÓN pertenece (helpdesk/soporte → crea tickets · campañas → chat/flujos)
 * y con qué token/App Secret responder y verificar la firma. Un número que no esté
 * aquí no crea nada. Mientras la tabla esté VACÍA, se mantiene el comportamiento de
 * siempre (config global → todo a tickets), para no romper nada antes de configurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numbers', function (Blueprint $t) {
            $t->id();
            $t->string('label', 60);                         // «Soporte», «Campañas»
            $t->string('phone_number_id', 40)->unique();     // la CLAVE de enrutado
            $t->string('funcion', 20)->default('soporte');   // soporte | campanas
            $t->text('token')->nullable();                   // token de acceso (por WABA)
            $t->string('app_secret', 120)->nullable();       // para verificar la firma
            $t->string('waba_id', 40)->nullable();
            $t->string('app_id', 40)->nullable();
            $t->string('display_number', 30)->nullable();    // +34… (informativo)
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_numbers');
    }
};
