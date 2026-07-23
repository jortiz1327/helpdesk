<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MOTIVO en el historial del ticket.
 *
 * Hasta ahora un evento solo podía decir «de X a Y» (`from_value`/`to_value`), que
 * sirve para un cambio de estado pero no para una FUSIÓN: ahí lo que hay que poder
 * contestar dentro de seis meses es *por qué* se juntaron dos tickets, y eso no
 * cabe en un par de códigos.
 *
 * Se deja genérico a propósito: cualquier evento futuro que necesite una frase
 * («se reabrió porque…») ya tiene dónde ponerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_events', function (Blueprint $t) {
            $t->string('note', 300)->nullable()->after('to_value');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_events', fn (Blueprint $t) => $t->dropColumn('note'));
    }
};
