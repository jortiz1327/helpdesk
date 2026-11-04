<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cuerpo de un mensaje cabía en un TEXT (64 KB). Un correo de Outlook con todo el hilo
 * citado y sus estilos lo pasa de largo: «Data too long for column 'body'», el correo
 * fallaba al guardarse, se reintentaba (avisando al agente cada vez) y acababa en
 * cuarentena sin ticket. MEDIUMTEXT da 16 MB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->mediumText('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sin vuelta atrás a TEXT: cortaría los cuerpos largos que ya se hayan guardado.
    }
};
