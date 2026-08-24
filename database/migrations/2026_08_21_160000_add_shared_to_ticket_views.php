<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistas guardadas COMPARTIDAS con el equipo (las «colas» comunes: «Sin asignar
 * urgentes», «VIP»…). Hasta ahora eran solo personales (por user_id). Con `shared`,
 * un encargado crea una vista que ven todos; el `user_id` sigue siendo el creador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_views', function (Blueprint $table) {
            $table->boolean('shared')->default(false)->after('user_id');
            $table->index('shared');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_views', function (Blueprint $table) {
            $table->dropIndex(['shared']);
            $table->dropColumn('shared');
        });
    }
};
