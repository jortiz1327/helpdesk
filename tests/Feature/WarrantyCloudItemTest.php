<?php

namespace Tests\Feature;

use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Models\ZkongServer;
use App\Services\WarrantyCloudItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Producto de la garantía en el cloud (plantilla default / almacen). ZKONG simulado: nunca
 * se llama a un servidor de verdad.
 */
class WarrantyCloudItemTest extends TestCase
{
    use RefreshDatabase;

    private array $enviados = [];
    private ?array $rechazo = null;   // lo que ZKONG dice que no entró
    private array $vinculos = [];     // etiquetas emparejadas
    private array $desvinculos = [];  // y desemparejadas

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/item/batchImportItem')) {
                $this->enviados[] = $request->data();
                return Http::response(['success' => true, 'code' => 10000, 'data' => $this->rechazo]);
            }
            if (str_contains($request->url(), '/bind/batchBind')) {
                $this->vinculos[] = $request->data();
                return Http::response(['success' => true, 'code' => 10000]);
            }
            if (str_contains($request->url(), '/bind/batchUnbind')) {
                $this->desvinculos[] = $request->data();
                return Http::response(['success' => true, 'code' => 10000]);
            }
            return Http::response(['success' => false, 'code' => 500, 'message' => 'no simulado'], 404);
        });
        $s = ZkongServer::create(['name' => 'APP02', 'base_url' => 'https://app02.test/zk', 'username' => 'u', 'password' => 'p', 'active' => true]);
        // La cuenta de administrador (otro comercio) no se usa para los productos.
        $s->forceFill(['token' => 'tok', 'merchant_id' => 1, 'agency_id' => 1,
            'product_username' => 'jortiz', 'product_password' => 'q', 'product_token' => 'ptok', 'product_merchant_id' => 7, 'product_agency_id' => 8])->save();
    }

    private function garantia(): Warranty
    {
        $w = Warranty::create(['code' => 'G-2026-0007', 'status' => 'no_iniciado', 'received_at' => '2026-11-04', 'client_name' => 'Grupo Sol',
            'project_name' => 'Hotel Sol Centro', 'last_activity_at' => now(), 'status_changed_at' => now()]);
        foreach (['A1', 'A2', 'A3'] as $i => $c) {
            WarrantyItem::create(['warranty_id' => $w->id, 'code' => $c, 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'pendiente',
                'aeme_ref' => $i < 2 ? 'AMC42B-W' : 'AMC21-R', 'reviewed_at' => $i === 0 ? '2026-11-05 10:00:00' : null]);
        }
        return $w;
    }

    public function test_sin_tienda_configurada_no_se_manda_nada(): void
    {
        config(['services.warranty_cloud.store_id' => null]);
        $w = $this->garantia();
        WarrantyCloudItem::queue($w->id);
        Http::assertNothingSent();
    }

    public function test_el_producto_lleva_los_campos_de_la_plantilla(): void
    {
        config(['services.warranty_cloud.store_id' => '555']);
        $w = $this->garantia();
        DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'field' => 'status', 'from_value' => 'x',
            'to_value' => Warranty::statuses()['pte_presupuesto'] ?? 'pte_presupuesto', 'created_at' => '2026-11-06 09:00:00']);

        $this->assertTrue(WarrantyCloudItem::send($w->id));
        // Con la cuenta de productos, no con la de administrador.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'batchImportItem') && $r->header('Authorization') === ['ptok']);
        $body = end($this->enviados);
        $this->assertSame(['agencyId' => 8, 'merchantId' => 7, 'storeId' => '555', 'unitName' => 0], array_diff_key($body, ['itemList' => 1]));

        $it = $body['itemList'][0];
        $this->assertSame(['default', 'almacen', 'G-2026-0007'], [$it['attrCategory'], $it['attrName'], $it['barCode']]);
        $this->assertSame(['Hotel Sol Centro', 'Grupo Sol'], [$it['itemTitle'], $it['shortTitle']]);
        $this->assertSame(['', '04/11/2026', '05/11/2026', '06/11/2026', ''], [$it['custFeature1'], $it['custFeature2'], $it['custFeature3'], $it['custFeature4'], $it['custFeature5']]);
        $this->assertSame(['AMC42B-W - 2', 'AMC21-R - 1', ''], [$it['custFeature10'], $it['custFeature11'], $it['custFeature12']]);
        $this->assertArrayHasKey('custFeature29', $it);
        $this->assertArrayNotHasKey('custFeature30', $it);
    }

    public function test_mas_de_20_referencias_el_ultimo_hueco_dice_cuantas_faltan(): void
    {
        $w = $this->garantia();
        foreach (range(1, 22) as $n) {
            WarrantyItem::create(['warranty_id' => $w->id, 'code' => "X{$n}", 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'pendiente', 'aeme_ref' => "REF{$n}"]);
        }
        $it = WarrantyCloudItem::item($w->fresh());
        $this->assertSame('+5 más', $it['custFeature29']);   // 24 referencias: 19 a la vista y 5 más
    }

    public function test_emparejar_manda_el_producto_y_luego_la_etiqueta(): void
    {
        config(['services.warranty_cloud.store_id' => '555']);
        $w = $this->garantia();
        WarrantyCloudItem::pair($w, '874d-61962');
        $this->assertSame([['storeId' => '555', 'tagItemBinds' => [['eslBarcode' => '874D61962', 'itemBarcode' => 'G-2026-0007']]]], $this->vinculos);
        $this->assertNotEmpty($this->enviados);
        $this->assertTrue(DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'cloud_label')->exists());
        $this->assertSame('874D61962', $w->fresh()->cloud_label);
    }

    public function test_cambiar_quitar_y_una_etiqueta_en_una_sola_garantia(): void
    {
        config(['services.warranty_cloud.store_id' => '555']);
        $a = $this->garantia();
        $b = Warranty::create(['code' => 'G-2026-0008', 'status' => 'no_iniciado', 'received_at' => '2026-11-04', 'client_name' => 'Otro', 'last_activity_at' => now(), 'status_changed_at' => now()]);

        WarrantyCloudItem::pair($a, '874D61962');
        WarrantyCloudItem::pair($a->fresh(), '111111111');   // cambia: la anterior se desempareja
        $this->assertSame([['storeId' => '555', 'tagItemBinds' => [['eslBarcode' => '874D61962', 'itemBarCode' => 'G-2026-0007']]]], $this->desvinculos);
        $this->assertSame('111111111', $a->fresh()->cloud_label);

        WarrantyCloudItem::pair($b, '111111111');            // la misma etiqueta en otra garantía
        $this->assertNull($a->fresh()->cloud_label);
        $this->assertSame('111111111', $b->fresh()->cloud_label);

        WarrantyCloudItem::unpair($b->fresh());
        $this->assertNull($b->fresh()->cloud_label);
        $this->assertCount(2, $this->desvinculos);

        $this->expectException(\App\Services\Zkong\ZkongException::class);
        WarrantyCloudItem::pair($b, 'CORTO');
    }

    public function test_el_endpoint_pide_editar_y_devuelve_la_etiqueta(): void
    {
        config(['services.warranty_cloud.store_id' => '555']);
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $w = $this->garantia();
        $ver = \App\Models\User::create(['name' => 'Ve', 'email' => 've@x.com', 'password' => bcrypt('x')]);
        $ver->givePermissionTo('warranties.access');
        $edita = \App\Models\User::create(['name' => 'Edita', 'email' => 'ed@x.com', 'password' => bcrypt('x')]);
        $edita->givePermissionTo(['warranties.access', 'warranties.edit']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $url = '/api/warranties.php?action=cloud_label';

        $this->postJson($url, ['id' => $w->id, 'code' => '874D61962'], ['X-App-Token' => \App\Services\TokenService::make($ver)])->assertStatus(403);
        $tok = ['X-App-Token' => \App\Services\TokenService::make($edita)];
        $this->postJson($url, ['id' => $w->id, 'code' => '874d61962'], $tok)->assertOk()->assertJsonPath('cloud_label', '874D61962');
        $this->getJson("/api/warranties.php?action=get&id={$w->id}", $tok)->assertJsonPath('warranty.cloud_label', '874D61962')->assertJsonPath('cloud.enabled', true);
        $this->postJson($url, ['id' => $w->id, 'remove' => true], $tok)->assertOk()->assertJsonPath('cloud_label', null);
    }

    public function test_si_zkong_rechaza_el_producto_no_rompe(): void
    {
        config(['services.warranty_cloud.store_id' => '555']);
        $this->rechazo = [['barCode' => 'G-2026-0007', 'msg' => 'error']];
        $this->assertFalse(WarrantyCloudItem::send($this->garantia()->id));
    }
}
