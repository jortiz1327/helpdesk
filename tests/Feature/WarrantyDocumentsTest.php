<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyDocument;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Quién paga cada dispositivo, tarifa, presupuestos y albaranes, informe y correo de resolución. */
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
        $u->givePermissionTo(['warranties.access', 'warranties.config']);
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

    public function test_presupuestos_por_quien_paga_con_la_tarifa_y_albaran(): void
    {
        $modelo = DB::table('device_models')->insertGetId(['battery_type' => 'CR2450', 'battery_quantity' => 2, 'has_batteries' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/warranty_settings.php?action=price_save', ['kind' => 'product', 'ref' => 'amc26v', 'price' => '12,50'], $this->h)->assertOk();
        $this->postJson('/api/warranty_settings.php?action=price_save', ['kind' => 'battery', 'ref' => 'CR2450', 'price' => '0.80'], $this->h)->assertOk();
        $d1 = $this->item('8E9749440', ['resolution' => 'danada', 'coverage' => 'cliente']);
        $this->item('8E9749441', ['resolution' => 'danada', 'coverage' => 'cliente']);
        $this->item('8E9749442', ['resolution' => 'bateria', 'coverage' => 'pilas', 'device_model_id' => $modelo]);
        $this->item('8E9749443', ['resolution' => 'bateria', 'coverage' => 'pilas', 'device_model_id' => $modelo]);
        $this->item('8E9749444', ['resolution' => 'defectuosa', 'coverage' => 'garantia']);
        $this->item('8E9749445');                                                    // sin revisar: no entra

        $s = $this->getJson("/api/warranty_docs.php?id={$this->w->id}", $this->h)->json('summary');
        $this->assertSame([2, 2, 1, 1], [$s['coverage']['cliente']['items'], $s['coverage']['pilas']['items'], $s['coverage']['garantia']['items'], $s['pending']]);

        $ids = $this->postJson('/api/warranty_docs.php?action=generate', ['id' => $this->w->id, 'kind' => 'presupuesto', 'coverages' => ['garantia', 'cliente', 'pilas']], $this->h)->assertOk()->json('ids');
        $docs = WarrantyDocument::whereIn('id', $ids)->get()->keyBy('coverage');
        $y = date('Y');
        $this->assertSame(["P-{$y}-0001", "P-{$y}-0002", "P-{$y}-0003"], $docs->sortBy('id')->pluck('number')->values()->all());
        $this->assertSame(30.25, $docs['cliente']->total);                           // 2 × 12,50 + 21 %
        $this->assertSame(3.87, $docs['pilas']->total);                              // 4 pilas × 0,80 + 21 %
        $this->assertSame(0.0, $docs['garantia']->total);                            // sin cargo
        $l = $this->getJson("/api/warranty_docs.php?action=get&id={$docs['pilas']->id}", $this->h)->json('document.lines.0');
        $this->assertSame(['CR2450', 4, ['8E9749442', '8E9749443']], [$l['ref'], (int) $l['qty'], $l['codes']]);

        // Editar líneas recalcula; el estado cambia; un enviado no se borra.
        $cli = $docs['cliente'];
        $this->postJson('/api/warranty_docs.php?action=save', ['id' => $cli->id, 'vat' => 21, 'note' => '', 'status' => 'borrador', 'lines' => [
            ['ref' => 'AMC26V', 'description' => 'Etiqueta', 'qty' => 2, 'price' => 12.5, 'codes' => [$d1->code]],
            ['ref' => '', 'description' => 'Gastos de envío', 'qty' => 1, 'price' => 10],
        ]], $this->h)->assertOk()->assertJsonPath('document.totals.total', 42.35);
        $this->postJson('/api/warranty_docs.php?action=status', ['id' => $cli->id, 'status' => 'entregado'], $this->h)->assertStatus(400);
        $this->postJson('/api/warranty_docs.php?action=status', ['id' => $cli->id, 'status' => 'aceptado'], $this->h)->assertOk();
        $this->postJson('/api/warranty_docs.php?action=delete', ['id' => $cli->id], $this->h)->assertStatus(400);

        $alb = $this->postJson('/api/warranty_docs.php?action=to_albaran', ['id' => $cli->id], $this->h)->assertOk()->json();
        $this->assertSame("A-{$y}-0001", $alb['number']);
        $this->assertSame(42.35, WarrantyDocument::find($alb['id'])->total);

        $pdf = $this->get("/api/warranty_docs.php?action=download&id={$cli->id}", $this->h)->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // Historial: 3 creados, modificado, aceptado y el albarán.
        $this->assertSame(6, DB::table('warranty_events')->where('warranty_id', $this->w->id)->where('field', 'documents')->count());
    }

    public function test_documentos_subidos(): void
    {
        $r = $this->post('/api/warranty_docs.php?action=upload', ['id' => $this->w->id, 'kind' => 'albaran', 'coverage' => 'cliente', 'number' => 'ALB-889', 'total' => '120,40',
            'file' => UploadedFile::fake()->create('albaran.pdf', 40, 'application/pdf')], $this->h)->assertOk();
        $d = WarrantyDocument::find($r->json('id'));
        $this->assertSame(['subido', 'ALB-889', 'cliente', 120.4, 'borrador'], [$d->source, $d->number, $d->coverage, $d->total, $d->status]);
        Storage::disk('local')->assertExists($d->path);
        $this->get("/api/warranty_docs.php?action=download&id={$d->id}", $this->h)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->post('/api/warranty_docs.php?action=upload', ['id' => $this->w->id, 'kind' => 'otro',
            'file' => UploadedFile::fake()->create('x.exe', 1)], $this->h)->assertStatus(400);

        $this->postJson('/api/warranty_docs.php?action=delete', ['id' => $d->id], $this->h)->assertOk();   // los subidos se borran siempre
        Storage::disk('local')->assertMissing($d->path);
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
        $this->assertStringContainsString('Dispositivos revisados: 2 de 2', $m['body']);
        $this->assertStringContainsString('Garantía: 1 · Paga el cliente: 1', $m['body']);
        $this->assertStringNotContainsString('{', $m['body']);
    }

    public function test_tarifa_y_ajustes_solo_con_configuracion(): void
    {
        $u = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo(['warranties.access']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson('/api/warranty_settings.php?action=price_save', ['kind' => 'product', 'ref' => 'X', 'price' => 1], ['X-App-Token' => TokenService::make($u)])->assertStatus(403);

        $this->item('8E9749440');
        $hints = collect($this->getJson('/api/warranty_settings.php', $this->h)->json('price_hints'));
        $this->assertSame(1, $hints->firstWhere('ref', 'AMC26V')['used']);
        $this->postJson('/api/warranty_settings.php?action=price_save', ['kind' => 'product', 'ref' => 'AMC26V', 'price' => -1], $this->h)->assertStatus(400);

        $this->postJson('/api/warranty_settings.php?action=docs_save', [
            'company' => ['name' => 'AEME Group', 'tax_id' => 'B123'], 'documents' => ['vat' => 10, 'validity_days' => 15, 'conditions' => 'X'],
            'resolution_email' => ['subject' => 'Garantía {codigo}', 'body' => 'Hola'],
        ], $this->h)->assertOk();
        $this->assertSame(10.0, (float) WarrantyDocument::setting('documents')['vat']);
        $this->assertSame('AEME Group', WarrantyDocument::setting('company')['name']);
    }
}
