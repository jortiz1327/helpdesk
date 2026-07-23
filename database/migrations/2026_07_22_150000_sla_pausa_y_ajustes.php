<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAUSA DEL RELOJ DEL SLA.
 *
 * Sin esto el SLA miente: si preguntas algo al cliente y tarda tres días en
 * contestar, un ticket con 24 h de plazo sale vencido por 48 h aunque el equipo
 * respondiera en diez minutos. La consecuencia real no es el número: es que la
 * vista de «SLA vencido» se llena de tickets donde nadie hizo nada mal y en dos
 * semanas deja de mirarla nadie.
 *
 * Estados que PARAN el reloj (decisión del usuario, 22-jul-2026): esperando al
 * cliente, resuelto y cerrado.
 *
 *   · `sla_paused_minutes` — minutos LABORABLES acumulados en pausa.
 *   · `sla_paused_since`   — desde cuándo está pausado ahora (null si corre).
 *
 * Consecuencia aceptada por el usuario: la fecha de vencimiento **deja de ser
 * fija** y se corre cada vez que el ticket entra o sale de pausa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('sla_paused_minutes')->default(0)->after('last_direction');
            $table->timestamp('sla_paused_since')->nullable()->after('sla_paused_minutes');
        });

        /*
         * Los tickets que YA están parados arrancan su pausa ahora, no desde que
         * entraron en ese estado: no se sabe cuándo fue y es mejor no inventarlo.
         */
        DB::table('tickets')
            ->whereIn('status', ['esperando_respuesta', 'resuelto', 'cerrado'])
            ->update(['sla_paused_since' => now()]);

        // Interruptor global: poder apagar el SLA sin vaciar las horas de las categorías.
        DB::table('settings')->updateOrInsert(['key' => 'sla_active'], ['value' => '1']);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['sla_paused_minutes', 'sla_paused_since']);
        });
        DB::table('settings')->where('key', 'sla_active')->delete();
    }
};
