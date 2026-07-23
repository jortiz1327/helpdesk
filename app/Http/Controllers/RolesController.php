<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * roles.php — catálogo y EDICIÓN de roles/permisos (pantalla de Usuarios).
 *
 * Modelo: los PERMISOS son un catálogo fijo (atado al código, definido en
 * config/rbac.php). Los ROLES son editables desde la UI: se pueden crear, editar
 * (etiqueta, descripción y qué permisos tienen) y borrar. El superadmin está
 * BLOQUEADO (bypass total, no se toca). Exige users.manage (ver routes/api.php).
 */
class RolesController extends Controller
{
    public function handle(Request $request)
    {
        if ($request->isMethod('post'))   return $this->save($request);
        if ($request->isMethod('delete')) return $this->destroy($request);
        return $this->list();
    }

    /** GET — roles (con etiqueta/permisos de BD) + catálogo de permisos. */
    protected function list()
    {
        $perms   = config('rbac.permissions');
        $super   = config('rbac.super_role');
        $allNames = array_keys($perms);

        $roles = Role::withCount('users')->orderBy('id')->get()->map(function ($r) use ($super, $allNames) {
            $isSuper = $r->name === $super;
            return [
                'name'        => $r->name,
                'label'       => $r->label ?: $r->name,
                'description' => $r->description ?: '',
                'users_count' => $r->users_count,
                'is_super'    => $isSuper,
                'permissions' => $isSuper ? $allNames : $r->permissions->pluck('name')->all(),
            ];
        });

        // Permisos agrupados por módulo, con su etiqueta legible
        $permissions = [];
        foreach ($perms as $name => [$label, $module]) {
            $permissions[] = ['name' => $name, 'label' => $label, 'module' => $module];
        }

        return response()->json([
            'roles'       => $roles,
            'permissions' => $permissions,
            'modules'     => config('rbac.modules'),
            'default'     => config('rbac.default_role'),
            'super_role'  => $super,
        ]);
    }

    /** POST — crear o editar un rol (etiqueta, descripción, permisos). */
    protected function save(Request $request)
    {
        $name  = trim((string) $request->input('name'));      // vacío = nuevo
        $label = trim((string) $request->input('label'));
        $desc  = trim((string) $request->input('description'));
        $wanted = (array) $request->input('permissions', []);

        if ($label === '') {
            return response()->json(['ok' => false, 'error' => 'El nombre del rol es obligatorio'], 400);
        }

        // Solo permisos del catálogo real (los demás se ignoran)
        $valid  = array_keys(config('rbac.permissions'));
        $wanted = array_values(array_intersect(array_map('strval', $wanted), $valid));

        if ($name !== '') {
            // --- Editar rol existente ---
            $role = Role::where('name', $name)->first();
            if (!$role) return response()->json(['ok' => false, 'error' => 'Rol no encontrado'], 404);
            if ($role->name === config('rbac.super_role')) {
                return response()->json(['ok' => false, 'error' => 'El superadministrador no se puede editar: tiene acceso total'], 400);
            }
            $role->label = $label;
            $role->description = $desc;
            $role->save();
            $role->syncPermissions($wanted);
        } else {
            // --- Crear rol nuevo --- (slug único a partir de la etiqueta)
            $base = Str::slug($label, '_') ?: 'rol';
            $slug = $base;
            for ($i = 2; Role::where('name', $slug)->exists(); $i++) $slug = $base . '_' . $i;

            $role = new Role(['name' => $slug, 'guard_name' => 'web']);
            $role->label = $label;
            $role->description = $desc;
            $role->save();
            $role->syncPermissions($wanted);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        return response()->json(['ok' => true, 'name' => $role->name]);
    }

    /** DELETE — borrar un rol (no el superadmin, ni el rol por defecto, ni con usuarios). */
    protected function destroy(Request $request)
    {
        $name = (string) $request->query('name', '');
        $role = Role::where('name', $name)->withCount('users')->first();
        if (!$role) return response()->json(['ok' => false, 'error' => 'Rol no encontrado'], 404);

        if ($role->name === config('rbac.super_role')) {
            return response()->json(['ok' => false, 'error' => 'El superadministrador no se puede borrar'], 400);
        }
        if ($role->name === config('rbac.default_role')) {
            return response()->json(['ok' => false, 'error' => 'Es el rol por defecto de los usuarios nuevos: no se puede borrar'], 400);
        }
        if ($role->users_count > 0) {
            return response()->json(['ok' => false, 'error' => "Aún hay {$role->users_count} usuario(s) con este rol. Reasígnalos antes de borrarlo."], 400);
        }

        $role->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        return response()->json(['ok' => true]);
    }
}
