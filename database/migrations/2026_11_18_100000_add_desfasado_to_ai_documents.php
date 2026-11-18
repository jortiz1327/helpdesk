<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets antiguos cuya respuesta ya no vale porque el equipo hacía por el cliente lo que hoy hace el propio hotel.
 * 0 = normal · 1 = desfasado (la IA lo ve con un aviso: sirve para entender el problema, no para copiar los pasos)
 * · 2 = excluido (el equipo daba credenciales: nunca se enseña como ejemplo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_documents', function (Blueprint $t) {
            $t->unsignedTinyInteger('desfasado')->default(0)->after('quality');
        });
    }

    public function down(): void
    {
        Schema::table('ai_documents', function (Blueprint $t) {
            $t->dropColumn('desfasado');
        });
    }
};
