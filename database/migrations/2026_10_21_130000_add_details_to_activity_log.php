<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * REGISTRO DE ACCIONES: el DETALLE de cada acción (qué cambió). Lista JSON de frases
 * cortas: «Agente: Ana → Robert», «Prioridad: Media → Alta», el texto de la respuesta…
 * Además `subject` pasa a 200 para que quepan el código y el asunto del ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $t) {
            $t->text('details')->nullable()->after('subject');
            $t->string('subject', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $t) {
            $t->dropColumn('details');
            $t->string('subject', 120)->nullable()->change();
        });
    }
};
