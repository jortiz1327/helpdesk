<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Los FLUJOS DE AUTOMATIZACIÓN son SOLO del superadmin: ningún otro rol (ni el encargado
 * de campañas, que antes los editaba) puede tocarlos. Se comprueba sobre la ruta que los
 * gestiona (flows.php → can:automations.manage).
 */
class AutomationsSuperadminOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $u): string
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    public function test_el_encargado_de_campanas_no_gestiona_flujos(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $u = User::create(['name' => 'Camp', 'email' => 'camp@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('encargado_campanas');

        $this->withHeader('X-App-Token', $this->token($u))
            ->getJson('/api/flows.php')->assertStatus(403);
    }

    public function test_el_superadmin_si_gestiona_flujos(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $u = User::create(['name' => 'Jefe', 'email' => 'jefe@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('superadmin');

        $this->withHeader('X-App-Token', $this->token($u))
            ->getJson('/api/flows.php')->assertOk();
    }
}
