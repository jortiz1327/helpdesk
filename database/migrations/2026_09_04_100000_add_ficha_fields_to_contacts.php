<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de ficha comercial del contacto (para la importación por Excel/CSV de
 * Campañas y editables en la ficha): empresa, tienda, cargo, provincia y comentarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('empresa', 160)->nullable()->after('name');
            $t->string('tienda', 160)->nullable()->after('empresa');
            $t->string('cargo', 120)->nullable()->after('tienda');
            $t->string('provincia', 120)->nullable()->after('cargo');
            $t->text('comentarios')->nullable()->after('provincia');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropColumn(['empresa', 'tienda', 'cargo', 'provincia', 'comentarios']);
        });
    }
};
