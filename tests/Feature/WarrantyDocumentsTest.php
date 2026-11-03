<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Quién paga cada dispositivo, presupuestos para el portal, informe y correo de resolución. */
class WarrantyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private array $h;
    private Warranty $w;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
        $this->w = Warranty::create(['code' => 'G-2026-0001', 'received_at' => '2026-10-01', 'status' => 'en_curso', 'client_name' => 'Hoteles Sol', 'project_name' => 'Hotel Mar']);
    }

    private function item(string $code, array $d = []): WarrantyItem
    {
        return WarrantyItem::create($d + ['warranty_id' => $this->w->id, 'code' => $code, 'kind' => 'esl', 'lookup_state' => 'found', 'aeme_ref' => 'AMC26V', 'product_name' => 'Etiqueta 2,6"']);
    }

    private function review(array $ids, string $res, array $extra = [])
    {
        return $this->postJson('/api/warranties.php?action=review', ['id' => $this->w->id, 'item_ids' => $ids, 'resolution' => $res] + $extra, $this->h);
    }

    public function test_quien_paga_en_la_revision(): void
    {
        $a = $this->item('8E9749440');
        $b = $this->item('8E9749441');
        $this->review([$a->id], 'danada', ['broken_cases' => ['impacts']])->assertOk();
        $this->assertSame('cliente', $a->fresh()->coverage);                         // lo propuesto
        $this->review([$b->id], 'correcta', ['coverage' => 'cliente'])->assertOk();
        $this->assertSame('cliente', $b->fresh()->coverage);                         // lo elegido gana
        $this->review([$b->id], 'desechada', ['note' => 'Rota'])->assertStatus(400);  // desechada: hay que elegir
        $this->review([$b->id], 'desechada', ['note' => 'Rota', 'coverage' => 'garantia'])->assertOk();
        $this->review([$b->id], 'pendiente')->assertOk();
        $this->assertNull($b->fresh()->coverage);
    }

    public function test_informe_de_resolucion_y_correo(): void
    {
        $a = $this->item('8E9749440', ['resolution' => 'defectuosa', 'coverage' => 'garantia', 'zkong_result' => 'reposicion']);
        $b = $this->item('8E9749441');
        $this->postJson('/api/warranty_docs.php?action=report', ['id' => $this->w->id], $this->h)->assertStatus(400)->assertJsonPath('error', 'Queda 1 dispositivo sin revisar');

        $b->update(['resolution' => 'danada', 'coverage' => 'cliente', 'broken_cases' => ['liquids']]);
        $id = $this->postJson('/api/warranty_docs.php?action=report', ['id' => $this->w->id, 'photos' => true], $this->h)->assertOk()->json('id');
        $doc = WarrantyDocument::find($id);
        $y = date('Y');
        $this->assertSame("I-{$y}-0001", $doc->number);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($doc->path));
        // Otra vez: el mismo documento, mismo número, fichero nuevo.
        $id2 = $this->postJson('/api/warranty_docs.php?action=report', ['id' => $this->w->id], $this->h)->assertOk()->json('id');
        $this->assertSame($id, $id2);
        Storage::disk('local')->assertMissing($doc->path);
        $this->assertSame(1, WarrantyDocument::where('kind', 'informe')->count());

        $m = $this->getJson("/api/warranty_docs.php?action=email&id={$this->w->id}", $this->h)->assertOk()->json();
        $this->assertSame('Resolución de la garantía G-2026-0001', $m['subject']);
        $this->assertStringContainsString('Hola Hoteles Sol', $m['body']);
        // El detalle por bloques (los de Configuración): uno por grupo con dispositivos.
        $this->assertStringContainsString('• Sustituidas en garantía (1):', $m['body']);
        $this->assertStringContainsString('• Con daños físicos (1): líquidos.', $m['body']);
        $this->assertStringNotContainsString('{', $m['body']);
    }

    public function test_pegatina_del_paquete(): void
    {
        $this->item('8E9749440');
        $this->item('8E9749441');
        $this->item('AABBCCDDEEFF', ['kind' => 'ap', 'aeme_ref' => 'AMAP-C']);
        $d = $this->getJson("/api/warranty_docs.php?action=sticker_data&id={$this->w->id}", $this->h)->assertOk()->json();
        $this->assertSame(['G-26-0001', '2026-10-01', 'Hotel Mar', 'Hoteles Sol', 8], [$d['code'], $d['received_at'], $d['client'], $d['company'], $d['slots']]);
        $this->assertSame([['name' => 'AMC26V', 'qty' => 2], ['name' => 'AMAP-C', 'qty' => 1]], $d['products']);

        $url = '/api/warranty_docs.php?action=sticker';
        $base = ['id' => $this->w->id, 'code' => 'G-26-0001', 'client' => 'Hotel Mar', 'products' => $d['products']];
        $r = $this->postJson($url, $base + ['format' => 'label'], $this->h)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->postJson($url, $base + ['format' => 'a4', 'slots' => [2, 7]], $this->h)->assertOk();
        $this->postJson($url, $base + ['format' => 'a4', 'slots' => [9]], $this->h)->assertStatus(400);
        $this->postJson($url, $base + ['format' => 'a4'], $this->h)->assertStatus(400);
        $this->postJson($url, ['products' => [['name' => 'X', 'qty' => 0]]] + $base + ['format' => 'label'], $this->h)->assertStatus(400);
    }

    public function test_ajustes_solo_con_configuracion_y_sin_tarifa(): void
    {
        $u = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson('/api/warranty_settings.php?action=docs_save', [], ['X-App-Token' => TokenService::make($u)])->assertStatus(403);

        // Los precios están en el portal: aquí ya no hay tarifa.
        $this->assertArrayNotHasKey('prices', $this->getJson('/api/warranty_settings.php', $this->h)->json());
        $this->postJson('/api/warranty_settings.php?action=docs_save', [
            'company' => ['name' => 'AEME Group', 'tax_id' => 'B123'],
            'resolution_email' => ['subject' => 'Garantía {codigo}', 'body' => 'Hola'],
        ], $this->h)->assertOk();
        $this->assertSame('AEME Group', WarrantyDocument::setting('company')['name']);
    }

    public function test_presupuestos_para_el_portal_solo_se_preparan(): void
    {
        $this->item('8E9749440', ['resolution' => 'danada', 'coverage' => 'cliente']);
        $this->item('8E9749441', ['resolution' => 'defectuosa', 'coverage' => 'garantia']);
        $r = $this->postJson('/api/warranty_docs.php?action=portal_drafts', ['id' => $this->w->id], $this->h)->assertOk()->json();
        // Sin cliente del portal enlazado (o sin portal) no está listo, y crear sigue desactivado.
        $this->assertFalse($r['ready']);
        $this->assertFalse($r['enabled']);
        $this->assertTrue(collect($r['checks'])->contains('tone', 'bad'));

        // Los documentos del portal piden el cliente enlazado; aquí no se crea ningún documento.
        $this->getJson("/api/warranty_docs.php?action=portal&id={$this->w->id}", $this->h)->assertOk()->assertJsonPath('linked', false);
        $this->postJson('/api/warranty_docs.php?action=generate', ['id' => $this->w->id, 'kind' => 'presupuesto', 'coverages' => ['cliente']], $this->h);
        $this->assertSame(0, WarrantyDocument::count());
    }
}
