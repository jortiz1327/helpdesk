<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * warranties.access pasa a ser solo consulta: editar, revisar, documentos, remesas de
 * ZKONG y el ticket de posiciones tienen su permiso. Quien ya entraba a garantías (rol o
 * usuario) los recibe para no perder nada; luego se recortan desde Roles. Borrar
 * garantías sigue siendo solo del superadmin (o de quien lo reciba a mano).
 */
return new class extends Migration
{
    private const NUEVOS = ['warranties.edit', 'warranties.review', 'warranties.documents', 'warranties.positions_ticket', 'warranties.zkong'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([...self::NUEVOS, 'warranties.delete'] as $p) Permission::findOrCreate($p, 'web');
        $acceso = Permission::where(['name' => 'warranties.access', 'guard_name' => 'web'])->first();
        if (! $acceso) return;

        // Los roles de garantías de config/rbac.php ya traen los suyos (en una BD nueva el
        // seeder corre antes, desde una migración anterior): no se tocan.
        foreach ($acceso->roles()->whereNotIn('name', ['encargado_garantias', 'tecnico_garantias', 'consulta_garantias'])->get() as $rol) {
            if ($rol instanceof Role) $rol->givePermissionTo(self::NUEVOS);
        }
        foreach ($acceso->users()->get() as $u) $u->givePermissionTo(self::NUEVOS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Los permisos los poda el seeder si salen de config/rbac.php.
    }
};
