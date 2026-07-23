<?php

use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traslada el control de acceso de la columna `users.role` (admin|agent) al
 * RBAC de spatie. Tras esta migración, los roles y permisos son la ÚNICA
 * fuente de verdad y la columna `role` desaparece.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear roles y permisos definidos en config/rbac.php
        (new RolesPermissionsSeeder())->run();

        // 2. Trasladar los usuarios existentes:  admin -> superadmin | agent -> agente
        if (Schema::hasColumn('users', 'role')) {
            foreach (DB::table('users')->select('id', 'role')->get() as $row) {
                $user = User::find($row->id);
                if (!$user) continue;

                $user->syncRoles([
                    ($row->role ?? 'agent') === 'admin'
                        ? config('rbac.super_role')
                        : config('rbac.default_role'),
                ]);
            }

            // 3. La columna deja de ser la fuente de verdad
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('agent')->after('name');
        });

        // Reconstruir la columna a partir de los roles
        foreach (User::with('roles')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'role' => $user->hasRole(config('rbac.super_role')) ? 'admin' : 'agent',
            ]);
        }
    }
};
