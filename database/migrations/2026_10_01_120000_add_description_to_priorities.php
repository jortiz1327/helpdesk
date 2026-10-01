<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Descripción de cada prioridad: una frase corta que explica CUÁNDO usarla, para
 * guiar al agente. Se ve bajo el nombre en el selector de prioridad y es editable
 * en «Configuración de soporte → Prioridades».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_priorities', function (Blueprint $table) {
            $table->string('description', 160)->nullable()->after('name');
        });

        // Textos por defecto para las 4 de siempre (por si el admin no las edita).
        $def = [
            'urgente' => 'Servicio completamente caído o impacto grave',
            'alta'    => 'Impacto significativo al servicio',
            'media'   => 'Problema con solución rápida o impacto limitado',
            'baja'    => 'Consulta, mejora o incidencia menor',
        ];
        foreach ($def as $key => $desc) {
            DB::table('ticket_priorities')->where('key', $key)->update(['description' => $desc]);
        }
    }

    public function down(): void
    {
        Schema::table('ticket_priorities', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
