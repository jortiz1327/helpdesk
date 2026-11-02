<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use App\Services\WarrantyTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lo que sale de los procedimientos de garantías, configurable: plazos de garantía
 * (generales y por categoría), el texto para Finanzas y el correo por bloques.
 */
class WarrantyTermsTest extends TestCase
{
    use RefreshDatabase;

    private array $h;
    private Warranty $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        WarrantyTerms::forget();
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo(['warranties.access', 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
        $this->w = Warranty::create(['code' => 'G-2026-0032', 'received_at' => '2026-10-01', 'status' => 'en_curso', 'client_name' => 'Invisa', 'project_name' => 'Hotel Invisa Cala Blanca']);
    }

    private function item(string $code, array $d = []): WarrantyItem
    {
        return WarrantyItem::create($d + ['warranty_id' => $this->w->id, 'code' => $code, 'kind' => 'esl', 'lookup_state' => 'found', 'aeme_ref' => 'AMC42V']);
    }

    private function review(array $ids, string $res, array $extra = [])
    {
        return $this->postJson('/api/warranties.php?action=review', ['id' => $this->w->id, 'item_ids' => $ids, 'resolution' => $res] + $extra, $this->h)->assertOk();
    }

    private function cfg(string $action, array $d)
    {
        return $this->postJson("/api/warranty_settings.php?action={$action}", $d, $this->h);
    }

    private function items(): \Illuminate\Support\Collection
    {
        return collect($this->getJson("/api/warranties.php?action=get&id={$this->w->id}", $this->h)->assertOk()->json('items'))->keyBy('code');
    }

    public function test_plazo_de_cada_dispositivo_y_quien_paga(): void
    {
        $viejo = $this->item('8E9749440', ['installed_at' => '2024-03-01']);   // 2 años y medio
        $nuevo = $this->item('8E9749441', ['installed_at' => '2025-11-15']);
        $this->item('8E9749442');                                               // ZKONG no sabe cuándo

        $i = $this->items();
        $this->assertSame(['2026-03-01', false, false], [$i['8E9749440']['term']['hardware_until'], $i['8E9749440']['term']['hardware_ok'], $i['8E9749440']['term']['battery_ok']]);
        $this->assertSame([true, true, 'zkong'], [$i['8E9749441']['term']['hardware_ok'], $i['8E9749441']['term']['battery_ok'], $i['8E9749441']['term']['source']]);
        $this->assertNull($i['8E9749442']['term']);

        // Fuera de plazo, lo que cubría la garantía se propone a cargo del cliente; lo elegido gana.
        $this->review([$viejo->id], 'defectuosa');
        $this->assertSame('cliente', $viejo->fresh()->coverage);
        $this->review([$viejo->id], 'defectuosa', ['coverage' => 'garantia']);
        $this->assertSame('garantia', $viejo->fresh()->coverage);
        $this->review([$viejo->id, $nuevo->id], 'defectuosa');                  // no todos fuera: garantía
        $this->assertSame('garantia', $viejo->fresh()->coverage);

        // Una categoría con plazos propios (4 años) y la fecha de instalación de la garantía.
        DB::table('warranty_categories')->insert(['name' => 'Clínicas', 'active' => true, 'sort' => 1]);
        $this->cfg('terms_save', ['hardware_months' => 24, 'battery_months' => 12, 'battery_cycles' => 4000, 'reference' => 'received',
            'out_of_term_to_client' => true, 'categories' => [['category' => 'clínicas', 'hardware_months' => 48, 'battery_months' => 12]]])->assertOk();
        $this->w->update(['category' => 'Clínicas']);
        $this->assertTrue($this->items()['8E9749440']['term']['hardware_ok']);
        $this->postJson('/api/warranties.php?action=save', ['id' => $this->w->id, 'received_at' => '2026-10-01', 'status' => 'en_curso',
            'client_name' => 'Invisa', 'project_name' => 'Hotel Invisa Cala Blanca', 'category' => 'Clínicas', 'installed_at' => '2020-01-10'], $this->h)->assertOk();
        $t = $this->items()['8E9749442']['term'];
        $this->assertSame(['2020-01-10', 'manual', false], [$t['installed_at'], $t['source'], $t['hardware_ok']]);

        // Renombrar la categoría se lleva sus plazos.
        $id = DB::table('warranty_categories')->where('name', 'Clínicas')->value('id');
        $this->cfg('category_save', ['id' => $id, 'name' => 'Clínicas Sur', 'active' => true])->assertOk();
        $this->assertSame('Clínicas Sur', $this->getJson('/api/warranty_settings.php', $this->h)->json('terms.categories.0.category'));
    }

    public function test_configuracion_de_plazos_valida(): void
    {
        $ok = ['hardware_months' => 24, 'battery_months' => 12, 'battery_cycles' => 4000, 'reference' => 'received', 'categories' => []];
        $this->cfg('terms_save', ['hardware_months' => 0] + $ok)->assertStatus(400);
        $this->cfg('terms_save', ['reference' => 'otra'] + $ok)->assertStatus(400);
        $this->cfg('terms_save', ['categories' => [['category' => 'No existe', 'hardware_months' => 12, 'battery_months' => 12]]] + $ok)->assertStatus(400);
        $this->cfg('terms_save', ['hardware_months' => 36, 'reference' => 'first_contact'] + $ok)->assertOk();
        $this->assertSame([36, 'first_contact', false], [WarrantyTerms::settings()['hardware_months'], WarrantyTerms::settings()['reference'], WarrantyTerms::settings()['out_of_term_to_client']]);
    }

    public function test_texto_para_finanzas(): void
    {
        $m = DB::table('device_models')->insertGetId(['battery_type' => 'CR2450', 'battery_quantity' => 2, 'has_batteries' => true]);
        foreach (['8E9749440', '8E9749441'] as $c) $buenas[] = $this->item($c, ['device_model_id' => $m])->id;
        $pila = $this->item('8E9749442', ['device_model_id' => $m])->id;
        $mala = $this->item('8E9749443')->id;
        $rota = $this->item('8E9749444')->id;
        $ap = $this->item('AABBCCDDEEFF', ['kind' => 'ap', 'aeme_ref' => 'AMAP-C'])->id;
        $this->review($buenas, 'no_reproduce');
        $this->review([$pila], 'bateria', ['battery_changed' => true]);
        $this->review([$mala], 'defectuosa');
        $this->review([$rota, $ap], 'danada', ['broken_cases' => ['impacts']]);
        $this->item('8E9749445');   // sin revisar: no entra

        $t = $this->getJson("/api/warranty_docs.php?action=finance&id={$this->w->id}", $this->h)->assertOk()->json('text');
        $this->assertSame("Hotel Invisa Cala Blanca - G-26-0032 - Garantía material\n\n"
            . "PPTO 1 (no cuenta stock):\n3 etiquetas AMC42V a coste 0\n(etiquetas enviadas por el cliente que están en buen estado)\n\n"
            . "PPTO 2 (cuenta stock):\n2 pilas AM-BZ2450 a coste cliente\n\n"
            . "PPTO 3 (cuenta stock):\n1 etiqueta AMC42V a coste 0\n(fallo de fábrica, sustitución en garantía)\n\n"
            . "PPTO 4 (cuenta stock):\n1 antena AMAP-C a coste cliente\n1 etiqueta AMC42V a coste cliente\n(dispositivos dañados por el cliente o fuera de garantía)", $t);

        // Configurable: sin el grupo de pilas, otra cabecera y otra nota.
        $f = $this->getJson('/api/warranty_settings.php', $this->h)->json('finance');
        $f['header'] = 'Garantía {codigo} ({empresa})';
        $f['groups']['pilas']['active'] = false;
        $f['groups']['devueltas']['note'] = '';
        $this->cfg('finance_save', $f)->assertOk();
        $t = $this->getJson("/api/warranty_docs.php?action=finance&id={$this->w->id}", $this->h)->json('text');
        $this->assertStringStartsWith("Garantía G-26-0032 (Invisa)\n\nPPTO 1 (no cuenta stock):\n3 etiquetas AMC42V a coste 0\n\nPPTO 2 (cuenta stock):\n1 etiqueta AMC42V a coste 0", $t);
    }

    public function test_correo_de_resolucion_por_bloques(): void
    {
        $this->item('8E9749440', ['installed_at' => '2025-06-01']);
        $this->item('8E9749441', ['installed_at' => '2025-06-01']);
        $this->item('8E9749442', ['installed_at' => '2023-01-01']);
        $ids = WarrantyItem::orderBy('id')->pluck('id')->all();
        $this->review([$ids[0]], 'no_reproduce');
        $this->review([$ids[1]], 'danada', ['broken_cases' => ['impacts', 'liquids']]);
        $this->review([$ids[2]], 'no_reproduce');   // fuera de plazo → paga el cliente

        $body = $this->getJson("/api/warranty_docs.php?action=email&id={$this->w->id}", $this->h)->json('body');
        $this->assertStringContainsString('• Reacondicionadas (1):', $body);
        $this->assertStringContainsString('• Con daños físicos (1): golpes o caídas, líquidos.', $body);
        $this->assertStringContainsString('• Fuera de garantía (1): la cobertura es de 2 años desde la instalación', $body);
        $this->assertStringNotContainsString('Sustituidas', $body);
        $this->assertLessThan(strpos($body, 'Con daños'), strpos($body, 'Reacondicionadas'));

        // Un bloque desactivado no sale; otro con su texto.
        $docs = $this->getJson('/api/warranty_settings.php', $this->h)->json('docs');
        $docs['resolution_blocks']['danadas']['active'] = false;
        $docs['resolution_blocks']['cliente']['text'] = 'Fuera de plazo: {codigos} ({plazo_hardware}).';
        $this->cfg('docs_save', $docs)->assertOk();
        $body = $this->getJson("/api/warranty_docs.php?action=email&id={$this->w->id}", $this->h)->json('body');
        $this->assertStringNotContainsString('Con daños', $body);
        $this->assertStringContainsString('Fuera de plazo: 8E9749442 (2 años).', $body);
    }
}
