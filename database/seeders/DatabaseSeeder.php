<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles y permisos (config/rbac.php). Debe ir ANTES de crear usuarios.
        $this->call(RolesPermissionsSeeder::class);

        // Categorías de ticket por defecto (Soporte, Garantías, Pedidos y facturas):
        // el portal las necesita para el desplegable de «Crear incidencia».
        $this->call(TicketCategoriesSeeder::class);

        // Superadministrador por defecto. El acceso es por EMAIL.
        $admin = User::firstOrCreate(
            ['email' => 'admin@aemegroup.com'],
            [
                'password' => Hash::make('admin1234'),
                'name'     => 'Administrador',
            ]
        );
        $admin->syncRoles([config('rbac.super_role')]);

        // Ajustes por defecto (las credenciales de WhatsApp van vacías:
        // se rellenan desde la pantalla de Configuración o el .env, NUNCA en código).
        $defaults = [
            'business_name'    => 'Helpdesk',
            'account_verified' => '0',
            'consent_enabled'  => '0',
        ];
        foreach ($defaults as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
