<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use App\Services\WarrantyTour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Tour guiado de garantías: textos editables y ofrecido una sola vez por usuario. */
class WarrantyTourTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
    }

    public function test_se_ofrece_una_vez(): void
    {
        $r = $this->getJson('/api/warranties.php?action=tour', $this->h)->assertOk();
        $this->assertFalse($r->json('seen'));
        $this->assertSame(array_column(WarrantyTour::DEFAULTS, 'id'), array_column($r->json('steps'), 'id'));

        $this->postJson('/api/warranties.php?action=tour', [], $this->h)->assertOk();
        $this->assertTrue($this->getJson('/api/warranties.php?action=tour', $this->h)->json('seen'));

        // Quien solo consulta también lo ve (y lo marca).
        $c = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $c->assignRole('consulta_garantias');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $hc = ['X-App-Token' => TokenService::make($c)];
        $this->assertFalse($this->getJson('/api/warranties.php?action=tour', $hc)->assertOk()->json('seen'));
        $this->postJson('/api/warranties.php?action=tour', [], $hc)->assertOk();
    }

    public function test_textos_en_configuracion(): void
    {
        $guardar = fn (array $steps) => $this->postJson('/api/warranty_settings.php?action=tour_save', ['steps' => $steps], $this->h);
        $guardar([['id' => 'pasos', 'title' => '  ', 'text' => 'x']])->assertStatus(400);

        $guardar([
            ['id' => 'pasos', 'title' => 'Checklist', 'text' => 'Lo que hay que hacer.', 'active' => true],
            ['id' => 'informes', 'title' => 'Informes', 'text' => collect(WarrantyTour::DEFAULTS)->firstWhere('id', 'informes')['text'], 'active' => false],
            ['id' => 'inventado', 'title' => 'No', 'text' => 'No'],
        ])->assertOk();
        $pasos = collect($this->getJson('/api/warranties.php?action=tour', $this->h)->json('steps'))->keyBy('id');
        $this->assertSame('Checklist', $pasos['pasos']['title']);
        $this->assertFalse($pasos->has('informes'));
        $this->assertFalse($pasos->has('inventado'));
        $this->assertFalse(collect($this->getJson('/api/warranty_settings.php', $this->h)->json('tour'))->firstWhere('id', 'informes')['active']);

        // Volver a los de serie.
        $guardar([])->assertOk();
        $this->assertSame(collect(WarrantyTour::DEFAULTS)->firstWhere('id', 'pasos')['title'], collect($this->getJson('/api/warranties.php?action=tour', $this->h)->json('steps'))->firstWhere('id', 'pasos')['title']);
    }
}
