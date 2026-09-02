<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Manuales (apartado «Ayuda»): cada rol ve SOLO los suyos, y la descarga vuelve a
 * comprobar el permiso (la lista no es la autorización).
 */
class ManualsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $u): string
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    private function usuario(string $rol): User
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => $rol, 'email' => $rol . '@x.com', 'password' => bcrypt('x')]);
        $u->assignRole($rol);
        return $u;
    }

    private function claves(array $json): array
    {
        return array_column($json['manuals'] ?? [], 'key');
    }

    public function test_el_agente_ve_su_manual_pero_no_el_de_campanas_ni_el_de_roles(): void
    {
        $u = $this->usuario('agente');
        $r = $this->withHeader('X-App-Token', $this->token($u))->getJson('/api/manuals.php')->assertOk();
        $keys = $this->claves($r->json());

        $this->assertContains('usuario_soporte', $keys);
        $this->assertNotContains('usuario_campanas', $keys);
        $this->assertNotContains('roles', $keys);
        $this->assertNotContains('encargado_soporte', $keys); // manual de gestión, no de agente
        $this->assertNotContains('cliente', $keys);           // el agente no gestiona el portal
    }

    public function test_el_encargado_de_soporte_ve_su_manual_de_gestion(): void
    {
        $u = $this->usuario('encargado_soporte');
        $r = $this->withHeader('X-App-Token', $this->token($u))->getJson('/api/manuals.php')->assertOk();
        $keys = $this->claves($r->json());

        $this->assertContains('encargado_soporte', $keys); // tiene support.config
        $this->assertContains('cliente', $keys);            // la descarga para dársela al cliente
        $this->assertContains('usuario_soporte', $keys);
        $this->assertContains('roles', $keys);
        $this->assertNotContains('usuario_campanas', $keys);
    }

    public function test_el_encargado_de_campanas_ve_campanas_y_roles_pero_no_soporte(): void
    {
        $u = $this->usuario('encargado_campanas');
        $r = $this->withHeader('X-App-Token', $this->token($u))->getJson('/api/manuals.php')->assertOk();
        $keys = $this->claves($r->json());

        $this->assertContains('usuario_campanas', $keys);
        $this->assertContains('roles', $keys);          // tiene campaigns.delete
        $this->assertNotContains('usuario_soporte', $keys);
    }

    public function test_el_superadmin_ve_todos(): void
    {
        $u = $this->usuario('superadmin');
        $r = $this->withHeader('X-App-Token', $this->token($u))->getJson('/api/manuals.php')->assertOk();
        $keys = $this->claves($r->json());

        $this->assertContains('usuario_soporte', $keys);
        $this->assertContains('usuario_campanas', $keys);
        $this->assertContains('roles', $keys);
        $this->assertContains('encargado_soporte', $keys);
        $this->assertContains('cliente', $keys);
    }

    public function test_la_descarga_respeta_el_permiso(): void
    {
        $u = $this->usuario('agente');
        $tok = $this->token($u);

        // Puede descargar el suyo…
        $this->withHeader('X-App-Token', $tok)
            ->get('/api/manuals.php?action=download&key=usuario_soporte')
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        // …pero NO el de campañas.
        $this->withHeader('X-App-Token', $tok)
            ->get('/api/manuals.php?action=download&key=usuario_campanas')
            ->assertStatus(403);
    }

    public function test_sin_token_no_hay_manuales(): void
    {
        $this->getJson('/api/manuals.php')->assertStatus(401);
    }
}
