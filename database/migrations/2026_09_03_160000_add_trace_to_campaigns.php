<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad y coste de campañas: guarda por campaña su categoría (Meta), la
 * tarifa aplicada en el momento (para que el histórico sea fiel aunque cambien
 * las tarifas) y quién la lanzó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->string('category', 32)->nullable()->after('template_name');   // MARKETING/UTILITY/AUTHENTICATION
            $t->decimal('unit_cost', 8, 4)->default(0)->after('category');     // EUR/mensaje aplicado
            $t->unsignedBigInteger('created_by')->nullable()->after('unit_cost'); // usuario que la lanzó
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropColumn(['category', 'unit_cost', 'created_by']);
        });
    }
};
