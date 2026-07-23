<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de país del teléfono, separado (como en la ficha de osTicket).
 * OJO: `wa_id` sigue guardando el número COMPLETO (país + número) porque es lo
 * que usa WhatsApp para enviar; `country_code` solo permite volver a partirlo
 * de forma fiable al editar la ficha (los prefijos tienen longitudes distintas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('country_code', 6)->nullable()->after('wa_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
