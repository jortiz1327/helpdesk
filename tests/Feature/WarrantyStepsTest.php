<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Pasos por tipo de cliente: listas por categoría o por cliente, marcables en cada garantía. */
class WarrantyStepsTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
    }

    private function garantia(array $d): Warranty
    {
        static $n = 0;
        return Warranty::create($d + ['code' => sprintf('G-2026-%04d', ++$n), 'received_at' => '2026-10-01', 'status' => 'en_curso']);
    }

    private function pasos(Warranty $w): array
    {
        return $this->getJson("/api/warranties.php?action=get&id={$w->id}", $this->h)->assertOk()->json('steps');
    }

    public function test_listas_de_serie_por_categoria_y_cliente(): void
    {
        $hotel = $this->garantia(['client_name' => 'Invisa', 'project_name' => 'Hotel Cala', 'category' => 'Hoteles']);
        $farma = $this->garantia(['client_name' => 'EXPOFARM S.L.', 'project_name' => 'Farmacia Centro', 'category' => 'Farmacias']);
        $otra = $this->garantia(['client_name' => 'Bigmat', 'category' => 'Retail']);

        $this->assertSame(['Todas', 'Hoteles'], array_column($this->pasos($hotel), 'name'));
        $this->assertSame(['Todas', 'Expofarm'], array_column($this->pasos($farma), 'name'));
        $this->assertSame(['Postman', 'Actualizar el firmware', 'Reiniciar el contador de cambios'], array_column($this->pasos($farma)[1]['steps'], 'text'));
        $this->assertSame(['Todas'], array_column($this->pasos($otra), 'name'));

        // Marcar y desmarcar: queda quién y cuándo, y en el historial.
        $r = $this->postJson('/api/warranties.php?action=step', ['id' => $farma->id, 'list' => 'expofarm', 'step' => 'e1', 'done' => true], $this->h)->assertOk()->json('steps');
        $this->assertSame('Ana', $r[1]['steps'][0]['done_by']);
        $this->assertNotNull($r[1]['steps'][0]['done_at']);
        $this->assertSame('✓ Postman (Expofarm)', DB::table('warranty_events')->where('field', 'steps')->latest('id')->value('to_value'));
        $this->postJson('/api/warranties.php?action=step', ['id' => $farma->id, 'list' => 'expofarm', 'step' => 'e1', 'done' => false], $this->h)->assertOk();
        $this->assertNull($this->pasos($farma)[1]['steps'][0]['done_at']);
        // Un paso de una lista que no le toca no se marca.
        $this->postJson('/api/warranties.php?action=step', ['id' => $otra->id, 'list' => 'expofarm', 'step' => 'e1', 'done' => true], $this->h)->assertStatus(400);
    }

    public function test_configuracion_de_las_listas(): void
    {
        $cfg = fn (array $lists) => $this->postJson('/api/warranty_settings.php?action=steps_save', ['lists' => $lists], $this->h);
        $cfg([['name' => 'Sin destino', 'steps' => [['text' => 'Algo']]]])->assertStatus(400);
        $cfg([['name' => 'Rara', 'categories' => ['No existe'], 'steps' => [['text' => 'Algo']]]])->assertStatus(400);
        $cfg([['name' => 'Vacía', 'all' => true, 'steps' => [['text' => '  ']]]])->assertStatus(400);

        $cfg([['name' => 'Farmacias', 'active' => true, 'categories' => ['farmacias'], 'steps' => [['text' => 'Llamar a la farmacia'], ['text' => '']]]])->assertOk();
        $l = $this->getJson('/api/warranty_settings.php', $this->h)->json('checklists');
        $this->assertSame([['Farmacias'], 1], [$l[0]['categories'], count($l[0]['steps'])]);
        $w = $this->garantia(['client_name' => 'X', 'category' => 'Farmacias']);
        $this->assertSame(['Farmacias'], array_column($this->pasos($w), 'name'));

        // Renombrar la categoría se lleva sus listas; sin ninguna lista no vuelven las de serie.
        $id = DB::table('warranty_categories')->where('name', 'Farmacias')->value('id');
        $this->postJson('/api/warranty_settings.php?action=category_save', ['id' => $id, 'name' => 'Parafarmacias', 'active' => true], $this->h)->assertOk();
        $this->assertSame(['Parafarmacias'], $this->getJson('/api/warranty_settings.php', $this->h)->json('checklists.0.categories'));
        $cfg([])->assertOk();
        $this->assertSame([], $this->getJson('/api/warranty_settings.php', $this->h)->json('checklists'));
    }

    public function test_marcar_pide_poder_editar(): void
    {
        $w = $this->garantia(['client_name' => 'X', 'category' => 'Hoteles']);
        $u = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->assignRole('consulta_garantias');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson('/api/warranties.php?action=step', ['id' => $w->id, 'list' => 'general', 'step' => 'g1', 'done' => true], ['X-App-Token' => TokenService::make($u)])->assertStatus(403);
    }
}
