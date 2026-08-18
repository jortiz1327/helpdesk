<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SLA por PRIORIDAD. Hasta ahora los plazos vivían solo en la categoría y en horas
 * enteras; pero un caso grave (una caída total) exige respuesta en MINUTOS. Por eso
 * la prioridad gana un plazo propio de primera respuesta y de resolución, en minutos.
 *
 * Regla de uso (en SlaService): la prioridad manda; si esa prioridad no tiene plazo,
 * se usa el de la categoría. Así lo ya configurado por categoría sigue funcionando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_priorities', function (Blueprint $table) {
            $table->unsignedInteger('sla_response_mins')->nullable()->after('is_default');
            $table->unsignedInteger('sla_resolve_mins')->nullable()->after('sla_response_mins');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_priorities', function (Blueprint $table) {
            $table->dropColumn(['sla_response_mins', 'sla_resolve_mins']);
        });
    }
};
