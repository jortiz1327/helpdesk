<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las acciones sensibles de Campañas exigen el permiso concreto, no solo «acceder»:
 * enviar/crear/cancelar → campaigns.send · borrar → campaigns.delete. Antes bastaba
 * con campaigns.access, así que un rol «solo ver» podía enviar (coste real) y borrar.
 */
class CampaignPermsTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioCon(array $permisos): string
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'U', 'email' => 'u@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    public function test_sin_permiso_de_envio_no_puede_lanzar_una_campana(): void
    {
        $token = $this->usuarioCon(['campaigns.access']);   // ve, pero no envía
        $this->withHeader('X-App-Token', $token)
            ->postJson('/api/campaigns.php?action=run&id=1')
            ->assertStatus(403);
    }

    public function test_sin_permiso_de_borrado_no_puede_eliminar_una_campana(): void
    {
        $token = $this->usuarioCon(['campaigns.access']);
        $this->withHeader('X-App-Token', $token)
            ->deleteJson('/api/campaigns.php?id=1')
            ->assertStatus(403);
    }

    public function test_con_permiso_de_envio_pasa_la_autorizacion(): void
    {
        $token = $this->usuarioCon(['campaigns.access', 'campaigns.send']);
        // Con el permiso ya no es 403 por autorización (el gating/id podrá dar otra cosa, pero no 403).
        $r = $this->withHeader('X-App-Token', $token)->postJson('/api/campaigns.php?action=run&id=1');
        $this->assertNotSame(403, $r->status());
    }
}
