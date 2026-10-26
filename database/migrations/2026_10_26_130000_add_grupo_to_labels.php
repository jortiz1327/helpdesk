<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ETIQUETAS EXCLUYENTES (Campañas). Las etiquetas de un mismo `grupo` no se pueden
 * tener a la vez: al poner una se quitan las demás del grupo (ver Label::excluyentes).
 * Sin grupo (null) son libres, como siempre.
 *   interes → Interés alto / medio / bajo
 *   tipo    → Cliente / No cliente / Excliente
 */
return new class extends Migration
{
    private const GRUPOS = [
        'interes' => ['Interés alto', 'Interés medio', 'Interés bajo'],
        'tipo'    => ['Cliente', 'No cliente', 'Excliente'],
    ];

    public function up(): void
    {
        Schema::table('labels', function (Blueprint $t) {
            $t->string('grupo', 30)->nullable()->after('color');
        });
        foreach (self::GRUPOS as $grupo => $nombres) {
            DB::table('labels')->whereIn('name', $nombres)->update(['grupo' => $grupo]);
        }
    }

    public function down(): void
    {
        Schema::table('labels', fn (Blueprint $t) => $t->dropColumn('grupo'));
    }
};
