<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ENTORNO del número: 'prueba' (el número de prueba de Meta, con límites) o 'real'
 * (un número verificado de producción). Sirve para los CANDADOS por nivel: con un
 * número de prueba se puede responder tickets a destinatarios registrados; las
 * funciones de producción (escribir a cualquiera, plantillas propias) exigen 'real'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $t) {
            $t->string('entorno', 10)->default('prueba')->after('funcion');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $t) {
            $t->dropColumn('entorno');
        });
    }
};
