<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panel de FUNCIONES (interruptores del superadmin): solo el superadmin entra, solo se
 * pueden flipar los ajustes de la lista blanca, y el cambio persiste.
 */
class FeaturesPanelTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): array
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Role::findOrCreate('superadmin', 'web');
        $u = User::create(['name' => ucfirst($rol), 'email' => "$rol@x.com", 'password' => bcrypt('x')]);
        $u->assignRole($rol);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, TokenService::make($u)];
    }

    public function test_solo_el_superadmin_entra(): void
    {
        [, $tokenAgente] = $this->usuario('agente');
        $this->withHeader('X-App-Token', $tokenAgente)
            ->getJson('/api/features.php')->assertStatus(403);
    }

    public function test_el_superadmin_ve_los_grupos_y_enciende_una_funcion(): void
    {
        [, $token] = $this->usuario('superadmin');

        $r = $this->withHeader('X-App-Token', $token)->getJson('/api/features.php');
        $r->assertOk();
        $this->assertNotEmpty($r->json('grupos'));

        // Encender la IA.
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/features.php?action=set', ['key' => 'ia_activa', 'value' => true])
            ->assertOk();
        $this->assertSame('1', Setting::get('ia_activa'));

        // Un número (auto-cierre).
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/features.php?action=set', ['key' => 'ticket_autoclose_days', 'value' => 15])
            ->assertOk();
        $this->assertSame('15', Setting::get('ticket_autoclose_days'));
    }

    public function test_no_se_puede_escribir_un_ajuste_fuera_de_la_lista_blanca(): void
    {
        [, $token] = $this->usuario('superadmin');
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/features.php?action=set', ['key' => 'ia_api_key', 'value' => 'robada'])
            ->assertStatus(400);
        $this->assertNull(Setting::get('ia_api_key'));
    }
}
