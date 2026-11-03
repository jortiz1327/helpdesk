<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Protocolos y guías: subir (configuración), consultar (garantías) y los de cada garantía. */
class WarrantyGuidesTest extends TestCase
{
    use RefreshDatabase;

    private array $h;
    private array $hc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        $c = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $c->assignRole('consulta_garantias');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
        $this->hc = ['X-App-Token' => TokenService::make($c)];
    }

    private function subir(array $d, array $h = null)
    {
        return $this->post('/api/warranty_guides.php?action=save', $d + [
            'kind' => 'protocolos', 'language' => 'es', 'active' => '1',
            'file' => UploadedFile::fake()->create('procedimiento.pdf', 120, 'application/pdf'),
        ], $h ?? $this->h);
    }

    public function test_subir_consultar_y_ficha(): void
    {
        $this->subir(['title' => ''])->assertStatus(400);
        $this->subir(['title' => 'Virus', 'file' => UploadedFile::fake()->create('x.exe', 10)])->assertStatus(400)->assertJsonFragment(['ok' => false]);
        $this->subir(['title' => 'Rara', 'categories' => json_encode(['No existe'])])->assertStatus(400);
        $this->subir(['title' => 'Nada'], $this->hc)->assertStatus(403);

        $gen = $this->subir(['title' => 'Condiciones generales', 'kind' => 'condiciones'])->assertOk()->json('id');
        $expo = $this->subir(['title' => 'Procedimiento Expofarm', 'kind' => 'clientes', 'clients' => json_encode(['Expofarm'])])->assertOk()->json('id');
        $hotel = $this->subir(['title' => 'Hoteles', 'categories' => json_encode(['hoteles'])])->assertOk()->json('id');
        $oculto = $this->subir(['title' => 'Borrador', 'active' => '0'])->assertOk()->json('id');
        Storage::disk('local')->assertExists(DB::table('warranty_guides')->where('id', $gen)->value('path'));

        // Quien consulta ve los visibles y puede abrirlos; los ocultos, no.
        $r = $this->getJson('/api/warranty_guides.php', $this->hc)->assertOk();
        $this->assertFalse($r->json('manage'));
        $this->assertEqualsCanonicalizing([$gen, $expo, $hotel], array_column($r->json('guides'), 'id'));
        $this->assertCount(4, $this->getJson('/api/warranty_guides.php', $this->h)->json('guides'));
        $this->get("/api/warranty_guide.php?id={$gen}", $this->hc)->assertOk()->assertHeader('Content-Disposition');
        $this->get("/api/warranty_guide.php?id={$oculto}", $this->hc)->assertNotFound();

        // En la ficha: los de su cliente o categoría y los generales.
        $w = Warranty::create(['code' => 'G-1', 'status' => 'en_curso', 'received_at' => '2026-10-01', 'client_name' => 'EXPOFARM ROBOTICS SLU', 'category' => 'Farmacias']);
        $f = $this->getJson("/api/warranty_guides.php?action=for_warranty&id={$w->id}", $this->hc)->assertOk();
        $this->assertSame([$expo], array_column($f->json('specific'), 'id'));
        $this->assertSame([$gen], array_column($f->json('general'), 'id'));
        // La categoría se guarda con el nombre de Configuración aunque llegue en minúsculas.
        $this->assertSame('["Hoteles"]', DB::table('warranty_guides')->where('id', $hotel)->value('categories'));
    }

    public function test_cambiar_y_quitar(): void
    {
        $id = $this->subir(['title' => 'Guía'])->assertOk()->json('id');
        $antes = DB::table('warranty_guides')->where('id', $id)->value('path');

        // Sin archivo nuevo, se conserva; con uno nuevo, el anterior se borra.
        $this->post('/api/warranty_guides.php?action=save', ['id' => $id, 'title' => 'Guía v2', 'kind' => 'tecnicas', 'language' => 'en', 'version' => '2.0'], $this->h)->assertOk();
        $g = DB::table('warranty_guides')->find($id);
        $this->assertSame(['Guía v2', 'tecnicas', 'en', '2.0', $antes], [$g->title, $g->kind, $g->language, $g->version, $g->path]);
        $this->post('/api/warranty_guides.php?action=save', ['id' => $id, 'title' => 'Guía v3', 'kind' => 'tecnicas', 'language' => 'en',
            'file' => UploadedFile::fake()->create('nueva.docx', 50)], $this->h)->assertOk();
        Storage::disk('local')->assertMissing($antes);
        $nuevo = DB::table('warranty_guides')->where('id', $id)->value('path');

        $this->postJson('/api/warranty_guides.php?action=delete', ['id' => $id], $this->hc)->assertStatus(403);
        $this->postJson('/api/warranty_guides.php?action=delete', ['id' => $id], $this->h)->assertOk();
        $this->assertSame(0, DB::table('warranty_guides')->count());
        Storage::disk('local')->assertMissing($nuevo);
    }
}
