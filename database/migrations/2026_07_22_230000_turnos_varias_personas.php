<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VARIAS PERSONAS EN EL MISMO TURNO.
 *
 * Hasta ahora una semana tenía exactamente un titular de mañana y uno de tarde: lo
 * imponía la clave única (week_start, shift). En semanas de carga hacen falta dos,
 * así que la única pasa a incluir a la persona.
 *
 * Y con dos titulares aparece una pregunta que antes no existía: si Juan e Ian
 * cubren la mañana y un día viene Robert, ¿los sustituye a los DOS o solo a uno?
 * Por eso la sustitución ahora puede apuntar a QUIÉN releva. Si va vacía (todo lo
 * anterior, y el caso de un solo titular) significa lo de siempre: cubre el turno
 * entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropUnique('shifts_week_start_shift_unique');
            $t->unique(['week_start', 'shift', 'user_id'], 'shifts_semana_turno_persona');
        });

        Schema::table('shift_overrides', function (Blueprint $t) {
            $t->foreignId('replaces_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_overrides', function (Blueprint $t) {
            $t->dropConstrainedForeignId('replaces_user_id');
        });

        /*
         * Para volver atrás hay que dejar UNA persona por turno: si no, la única
         * antigua no se puede crear. Se conserva la primera de cada turno.
         */
        $sobran = \DB::table('shifts as a')->join('shifts as b', function ($j) {
            $j->on('a.week_start', '=', 'b.week_start')->on('a.shift', '=', 'b.shift')
                ->whereColumn('a.id', '>', 'b.id');
        })->pluck('a.id');
        if ($sobran->isNotEmpty()) \DB::table('shifts')->whereIn('id', $sobran)->delete();

        Schema::table('shifts', function (Blueprint $t) {
            $t->dropUnique('shifts_semana_turno_persona');
            $t->unique(['week_start', 'shift'], 'shifts_week_start_shift_unique');
        });
    }
};
