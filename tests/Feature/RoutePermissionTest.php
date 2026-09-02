<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * INVARIANTE DE SEGURIDAD: las rutas sensibles están tras `can:<permiso>`. Un agente sin
 * el permiso NO debe poder tocarlas (403), y sin token NO debe entrar (401). El
 * superadministrador pasa por bypass. Se prueba con una ruta representativa de config
 * (`email.php` → can:support.config).
 */
class RoutePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $u): string
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    private function usuario(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => bcrypt('x')]);
    }

    public function test_sin_token_no_se_entra(): void
    {
        $this->getJson('/api/email.php')->assertStatus(401);
    }

    public function test_sin_permiso_403_y_con_permiso_pasa(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        // Sin el permiso support.config → 403.
        $sin = $this->usuario('sin@x.com');
        $this->withHeader('X-App-Token', $this->token($sin))
            ->getJson('/api/email.php')->assertStatus(403);

        // Con el permiso concreto → ya no lo corta el middleware (200).
        $con = $this->usuario('con@x.com');
        $con->givePermissionTo('support.config');
        $this->withHeader('X-App-Token', $this->token($con))
            ->getJson('/api/email.php')->assertOk();
    }

    public function test_superadmin_pasa_por_bypass(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $super = $this->usuario('jefe@x.com');
        $super->assignRole('superadmin');
        $this->withHeader('X-App-Token', $this->token($super))
            ->getJson('/api/email.php')->assertOk();
    }
}
