<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * warranties.devices: forzar, actualizar el firmware y reiniciar etiquetas desde el
 * Centro de dispositivos. Lo reciben el responsable y el técnico de garantías.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('warranties.devices', 'web');
        foreach (Role::whereIn('name', ['encargado_garantias', 'tecnico_garantias'])->where('guard_name', 'web')->get() as $rol) {
            $rol->givePermissionTo('warranties.devices');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Lo poda el seeder si sale de config/rbac.php.
    }
};
