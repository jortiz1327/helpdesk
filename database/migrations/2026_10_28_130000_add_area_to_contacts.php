<?php

use App\Support\ContactArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Área de cada ficha guardada en la propia ficha (Soporte / Campañas / las dos), en vez de
 * deducirla en cada consulta. Se rellena con la MISMA regla que se usaba hasta ahora, así
 * que las audiencias de campañas no cambian. Reversible: quitar las dos columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('en_soporte')->default(false)->index();
            $table->boolean('en_campanas')->default(false)->index();
        });

        ContactArea::recalcular();
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['en_soporte']);
            $table->dropIndex(['en_campanas']);
            $table->dropColumn(['en_soporte', 'en_campanas']);
        });
    }
};
