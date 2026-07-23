<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea y sincroniza roles y permisos a partir de config/rbac.php.
 * Es IDEMPOTENTE: se puede relanzar tras añadir permisos nuevos a la config.
 *
 *     php artisan db:seed --class=RolesPermissionsSeeder
 */
class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Permisos
        foreach (array_keys(config('rbac.permissions')) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // 2. Roles + sus permisos.
        //    Los roles son EDITABLES desde la UI: config es solo la SEMILLA. Por eso
        //    los permisos y etiquetas se fijan SOLO al crear el rol; si ya existe, no
        //    se tocan (mandan los cambios hechos en la interfaz). Tampoco se borran los
        //    roles que no estén en config: los personalizados creados en la UI se quedan.
        foreach (config('rbac.roles') as $name => $def) {
            $isSuper = ($def['permissions'] ?? null) === '*';
            $role = Role::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $isNew = !$role->exists;

            // Etiqueta/descripción: se rellenan al crear o si aún faltan (retrocompatible),
            // sin pisar lo que el admin haya editado.
            if ($isNew || ($role->label ?? '') === '') $role->label = $def['label'] ?? $name;
            if ($isNew || ($role->description ?? '') === '') $role->description = $def['description'] ?? '';
            $role->save();

            // El superadmin no lleva permisos explícitos: tiene bypass (Gate::before).
            // Los demás reciben los de config SOLO al crearse; luego manda la UI.
            if ($isNew && !$isSuper) {
                $role->syncPermissions($def['permissions'] ?? []);
            }
        }

        // 3. Podar permisos que ya NO están en la config (los permisos SÍ están atados
        //    al código, así que su catálogo es fijo). Los roles NO se podan.
        Permission::whereNotIn('name', array_keys(config('rbac.permissions')))->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command?->info('RBAC sincronizado: '
            . count(config('rbac.permissions')) . ' permisos, '
            . count(config('rbac.roles')) . ' roles.');
    }
}
