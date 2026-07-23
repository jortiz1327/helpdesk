<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridades CONFIGURABLES (antes eran una lista fija en el código).
 *
 * Dos cambios:
 *  1. Nueva tabla con las prioridades, su color y si están activas.
 *  2. `tickets.priority` deja de ser ENUM y pasa a VARCHAR: un ENUM no admite
 *     valores nuevos, así que impediría crear prioridades. Los datos existentes
 *     se conservan tal cual (las claves no cambian).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();       // lo que se guarda en tickets.priority
            $table->string('name', 60);                // etiqueta visible
            $table->string('color', 9)->default('#64748b');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);   // la que se pone a un ticket nuevo
            $table->timestamps();
        });

        // Las cuatro de siempre, con los colores que ya tenían en la interfaz.
        $now = now();
        DB::table('ticket_priorities')->insert([
            ['key' => 'baja',    'name' => 'Baja',    'color' => '#64748b', 'position' => 1, 'active' => true, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'media',   'name' => 'Normal',  'color' => '#2563eb', 'position' => 2, 'active' => true, 'is_default' => true,  'created_at' => $now, 'updated_at' => $now],
            ['key' => 'alta',    'name' => 'Alta',    'color' => '#f59e0b', 'position' => 3, 'active' => true, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'urgente', 'name' => 'Urgente', 'color' => '#ef4444', 'position' => 4, 'active' => true, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ENUM -> VARCHAR conservando los valores actuales.
        DB::statement("ALTER TABLE tickets MODIFY priority VARCHAR(30) NOT NULL DEFAULT 'media'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tickets MODIFY priority ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media'");
        Schema::dropIfExists('ticket_priorities');
    }
};
