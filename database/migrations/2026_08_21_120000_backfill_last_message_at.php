<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rellena `last_message_at` donde estuviera en NULL (filas históricas antiguas).
 *
 * La bandeja ordenaba con `ORDER BY COALESCE(last_message_at, created_at)`: envolver
 * la columna en una función impide usar el índice `(status, last_message_at)` y fuerza
 * un filesort en CADA listado sobre 50k. Todas las vías de alta ya rellenan la columna
 * (create, importador, seeder); este backfill cubre lo que quedara antiguo para poder
 * ordenar por `last_message_at` a secas y dejar que el índice haga su trabajo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tickets')->whereNull('last_message_at')->update([
            'last_message_at' => DB::raw('COALESCE(created_at, opened_at, resolved_at)'),
        ]);
    }

    public function down(): void
    {
        // Irreversible: no se puede saber qué filas estaban en NULL. No-op.
    }
};
