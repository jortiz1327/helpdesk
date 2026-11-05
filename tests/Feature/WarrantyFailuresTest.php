<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PortalWarrantyFailures;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Estadísticas de fallos: salen del portal. Sin portal, avisan en vez de romper. */
class WarrantyFailuresTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_portal_avisa(): void
    {
        config(['database.connections.portal_aeme.host' => '']);   // nunca el portal de verdad
        $this->assertNull(PortalWarrantyFailures::report([]));

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ve', 'email' => 've@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo('warranties.access');
        $tok = ['X-App-Token' => TokenService::make($u)];
        $this->getJson('/api/warranties.php?action=failures&kind=esl', $tok)->assertStatus(502)->assertJsonPath('ok', false);
        $this->getJson('/api/warranties.php?action=portal_stats', $tok)->assertStatus(502)->assertJsonPath('ok', false);
    }
}
