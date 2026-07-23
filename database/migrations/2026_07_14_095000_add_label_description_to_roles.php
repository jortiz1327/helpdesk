<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los roles pasan a ser EDITABLES desde la UI (crear/editar/borrar y asignar
 * permisos). Para eso necesitan guardar su etiqueta y descripción en BD, no en
 * config. config/rbac.php queda como semilla inicial; a partir de ahí manda la BD.
 *
 * OJO ORDEN: va JUSTO DESPUÉS de crear las tablas de permisos y ANTES de
 * `..._100000_migrate_users_role_to_rbac`, que ya inserta roles CON `label`/
 * `description` (vía RolesPermissionsSeeder). Si esta migración corriese después,
 * un `migrate` limpio reventaría con «Unknown column 'label'». Es idempotente por
 * si las columnas ya existen (BD que venía del orden antiguo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'label')) return;
        Schema::table('roles', function (Blueprint $t) {
            $t->string('label')->nullable()->after('name');
            $t->string('description', 500)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('roles', 'label')) return;
        Schema::table('roles', function (Blueprint $t) {
            $t->dropColumn(['label', 'description']);
        });
    }
};
