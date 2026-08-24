<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de fecha en `tickets` para los INFORMES.
 *
 * El panel de informes filtra por `created_at >= desde` y agrupa por `DATE(created_at)`
 * / `DATE(resolved_at)` en varias agregaciones. Sin índice, cada corte recorre la tabla
 * entera (con 50.000 tickets, varios escaneos completos por carga). Estos dos índices
 * dejan que el filtro de periodo y la serie diaria trabajen por rango.
 *
 * (El `DATE(col)` no usa el índice directamente, pero el rango `col >= desde` sí acota
 * las filas antes de agrupar, que es donde está el coste.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (!$this->existe('tickets_created_at_index'))  $table->index('created_at');
            if (!$this->existe('tickets_resolved_at_index')) $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if ($this->existe('tickets_created_at_index'))  $table->dropIndex('tickets_created_at_index');
            if ($this->existe('tickets_resolved_at_index')) $table->dropIndex('tickets_resolved_at_index');
        });
    }

    protected function existe(string $indice): bool
    {
        if (DB::getDriverName() !== 'mysql') return false;   // sqlite (tests): se añaden sin comprobar
        foreach (DB::select('SHOW INDEX FROM tickets') as $i) {
            if ($i->Key_name === $indice) return true;
        }
        return false;
    }
};
