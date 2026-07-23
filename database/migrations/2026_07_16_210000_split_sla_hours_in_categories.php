<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desdobla el SLA de las categorías en los DOS relojes que se miden por separado:
 *
 *   · primera respuesta → cuánto se tarda en CONTESTAR (lo que nota el cliente)
 *   · resolución        → cuánto se tarda en DEJARLO RESUELTO
 *
 * El `sla_hours` que había se traslada a RESOLUCIÓN, que es lo que significaba
 * («SLA 24 h» se entendía como resolver en 24 h), y la primera respuesta nace vacía
 * para que cada quien ponga su compromiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('sla_response_hours')->nullable()->after('sla_hours');
            $table->unsignedSmallInteger('sla_resolve_hours')->nullable()->after('sla_response_hours');
        });

        // Lo que ya estaba configurado se conserva como plazo de resolución.
        DB::statement('UPDATE ticket_categories SET sla_resolve_hours = sla_hours WHERE sla_hours IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn(['sla_response_hours', 'sla_resolve_hours']);
        });
    }
};
