<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sustituciones: la etiqueta que no se devuelve se cambia por una nueva. Se apunta la
 * posición donde estaba (el artículo vinculado en ZKONG, p. ej. SC-02) y la etiqueta
 * nueva, para que el cliente la vincule; en hoteles, con un ticket de las posiciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->string('position', 80)->nullable()->after('zkong_result_note');
            $t->string('position_title', 191)->nullable()->after('position');
            $t->string('replaced_by', 40)->nullable()->after('position_title');
        });
        Schema::table('warranties', fn (Blueprint $t) => $t->unsignedBigInteger('positions_ticket_id')->nullable()->after('ticket_id'));
    }

    public function down(): void
    {
        Schema::table('warranties', fn (Blueprint $t) => $t->dropColumn('positions_ticket_id'));
        Schema::table('warranty_items', fn (Blueprint $t) => $t->dropColumn(['position', 'position_title', 'replaced_by']));
    }
};
