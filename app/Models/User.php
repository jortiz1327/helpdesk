<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    protected $guarded = ['id'];
    public $timestamps = false; // solo created_at (lo pone la BD)

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password'   => 'hashed',
            'created_at' => 'datetime',
        ];
    }

    /** El superadministrador: tiene bypass a todos los permisos (ver AppServiceProvider). */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('rbac.super_role'));
    }

    /** Compatibilidad con el código anterior (antes: columna role === 'admin'). */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin();
    }

    /** Nombre del rol principal (el primero). Para pintarlo en la UI. */
    public function roleName(): ?string
    {
        return $this->getRoleNames()->first();
    }

    /**
     * Permisos efectivos del usuario. El superadmin los tiene TODOS
     * (incluidos los que añadamos en el futuro).
     */
    public function permissionNames(): array
    {
        if ($this->isSuperAdmin()) {
            return array_keys(config('rbac.permissions'));
        }
        return $this->getAllPermissions()->pluck('name')->all();
    }

    /** IDs de las categorías (áreas) a las que pertenece el agente. */
    public function categoryIds(): array
    {
        return \Illuminate\Support\Facades\DB::table('user_ticket_categories')
            ->where('user_id', $this->id)->pluck('category_id')->map('intval')->all();
    }

    /** Módulos visibles para este usuario (los que su permiso `*.access` permite). */
    public function moduleNames(): array
    {
        $perms = $this->permissionNames();
        $modules = [];
        foreach (config('rbac.modules') as $key => $def) {
            if (in_array($def['access'], $perms, true)) $modules[] = $key;
        }
        return $modules;
    }
}
