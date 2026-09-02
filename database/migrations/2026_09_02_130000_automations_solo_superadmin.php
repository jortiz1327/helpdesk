<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Los FLUJOS DE AUTOMATIZACIÓN pasan a ser SOLO del superadmin. Antes el «Encargado de
 * campañas» podía crear/editar flujos (automations.manage) y el «Encargado de soporte»
 * verlos (automations.access). Se revocan ambos permisos de TODOS los roles menos el
 * superadmin (que los tiene por bypass). El cambio en config/rbac.php cubre los seeds
 * futuros; esta migración pone al día la BD ya existente.
 */
return new class extends Migration
{
    private array $perms = ['automations.access', 'automations.manage'];

    public function up(): void
    {
        $super     = config('rbac.super_role', 'superadmin');
        $superId   = DB::table('roles')->where('name', $super)->value('id');
        $permIds   = DB::table('permissions')->whereIn('name', $this->perms)->pluck('id');
        if ($permIds->isEmpty()) return;

        DB::table('role_has_permissions')
            ->whereIn('permission_id', $permIds)
            ->when($superId, fn ($q) => $q->where('role_id', '!=', $superId))
            ->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Restaura el reparto anterior: campañas (ver + editar), soporte (solo ver).
        $reasignar = [
            'encargado_campanas' => ['automations.access', 'automations.manage'],
            'encargado_soporte'  => ['automations.access'],
        ];
        foreach ($reasignar as $rol => $perms) {
            $roleId = DB::table('roles')->where('name', $rol)->value('id');
            if (!$roleId) continue;
            foreach (DB::table('permissions')->whereIn('name', $perms)->pluck('id') as $pid) {
                DB::table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $roleId]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
