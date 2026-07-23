<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los roles pasan a ser EDITABLES desde la UI (crear/editar/borrar y asignar
 * permisos). Para eso necesitan guardar su etiqueta y descripción en BD, no en
 * config. config/rbac.php queda como semilla inicial; a partir de ahí manda la BD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $t) {
            $t->string('label')->nullable()->after('name');
            $t->string('description', 500)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $t) {
            $t->dropColumn(['label', 'description']);
        });
    }
};
