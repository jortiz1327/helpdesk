<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Clientes y proyectos: clientes con garantías (aquí o en el portal) y su ficha. Solo consulta. */
class WarrantyClientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        config(['database.connections.portal_aeme.host' => '']);   // sin portal: solo lo de aquí
    }

    private function agente(array $perms = ['warranties.access']): array
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    private function w(array $d): Warranty
    {
        static $n = 0;
        $n++;
        return Warranty::create($d + ['code' => 'G-2026-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'received_at' => '2026-10-01',
            'status' => 'en_curso', 'last_activity_at' => now(), 'status_changed_at' => now()]);
    }

    public function test_lista_agrupa_por_cliente_del_portal_o_por_nombre(): void
    {
        [, $h] = $this->agente();
        $this->w(['client_name' => 'Fergus', 'portal_client_id' => 474, 'project_name' => 'Hotel Hortizo', 'received_at' => '2026-10-20']);
        $this->w(['client_name' => 'Fergus SL', 'portal_client_id' => 474, 'status' => 'cerrada', 'closed_at' => now()]);
        $this->w(['client_name' => 'Hoteles Sol ', 'project_name' => 'Sol Centro']);
        $this->w(['client_name' => 'hoteles sol', 'status' => 'cerrada', 'closed_at' => now()]);

        $r = $this->getJson('/api/warranty_clients.php', $h)->assertOk()->json();
        $this->assertFalse($r['portal']);
        $rows = collect($r['rows'])->keyBy('key');
        $this->assertSame([1, 2, 'G-2026-0001'], [$rows['p:474']['open'], $rows['p:474']['total'], $rows['p:474']['last']['code']]);
        $this->assertSame([1, 2], [$rows['n:hoteles sol']['open'], $rows['n:hoteles sol']['total']]);
        $this->assertSame(['clients' => 2, 'with_open' => 2, 'open' => 2, 'unlinked' => 2], $r['kpis']);
    }

    public function test_ficha_con_sus_garantias_lugares_y_remesas(): void
    {
        [$u, $h] = $this->agente();
        $a = $this->w(['client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro', 'responsible_id' => $u->id]);
        $this->w(['client_name' => 'Otro cliente']);
        WarrantyItem::create(['warranty_id' => $a->id, 'code' => '8E9749440', 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'defectuosa']);
        WarrantyItem::create(['warranty_id' => $a->id, 'code' => '8E9749441', 'kind' => 'esl', 'lookup_state' => 'found']);

        $d = $this->getJson('/api/warranty_clients.php?action=get&key=' . urlencode('n:hoteles sol'), $h)->assertOk()->json();
        $this->assertCount(1, $d['warranties']);
        $this->assertSame([2, 1, 'Ana'], [$d['warranties'][0]['items'], $d['warranties'][0]['reviewed'], $d['warranties'][0]['responsible']]);
        $this->assertSame([['id' => null, 'name' => 'Sol Centro', 'url' => null, 'total' => 1, 'open' => 1]], $d['places']);
        $this->assertNull($d['portal_projects']);
        $this->assertSame(2, $d['kpis']['items']);

        $this->getJson('/api/warranty_clients.php?action=get&key=x', $h)->assertStatus(400);
        // Un cliente del portal sin garantías (ni aquí ni en el portal) no se puede consultar.
        $this->getJson('/api/warranty_clients.php?action=get&key=p:999', $h)->assertNotFound();
        $this->getJson('/api/warranty_clients.php?action=get&key=' . urlencode('n:nadie'), $h)->assertNotFound();
    }

    public function test_requiere_acceso_a_garantias(): void
    {
        [, $h] = $this->agente([]);
        $this->getJson('/api/warranty_clients.php', $h)->assertForbidden();
    }
}
