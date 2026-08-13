<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ORIGEN del ticket. Hoy un ticket creado desde el PORTAL se guarda con
 * channel='email' (para que el hilo se comporte como un correo), así que no se
 * distingue de un correo de verdad. `source` guarda de dónde nació de verdad
 * (portal / email / whatsapp / agent) sin tocar el comportamiento del canal.
 * Lo necesita el CSAT: la encuesta solo aplica a incidencias nacidas en el portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->string('source', 20)->nullable()->after('channel')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropColumn('source');
        });
    }
};
