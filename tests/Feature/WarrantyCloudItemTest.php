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

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/item/batchImportItem')) {
                $this->enviados[] = $request->data();
                return Http::response(['success' => true, 'code' => 10000, 'data' => $this->rechazo]);
            }
            return Http::response(['success' => false, 'code' => 500, 'message' => 'no simulado'], 404);
        });
        $s = ZkongServer::create(['name' => 'APP02', 'base_url' => 'https://app02.test/zk', 'username' => 'u', 'password' => 'p', 'active' => true]);
        $s->forceFill(['token' => 'tok', 'merchant_id' => 7, 'agency_id' => 8])->save();
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
        $body = end($this->enviados);
        $this->assertSame(['agencyId' => 8, 'merchantId' => 7, 'storeId' => '555', 'unitName' => 0], array_diff_key($body, ['itemList' => 1]));

        $it = $body['itemList'][0];
        $this->assertSame(['default', 'almacen', 'G-2026-0007'], [$it['attrCategory'], $it['attrName'], $it['barCode']]);
        $this->assertSame(['Hotel Sol Centro', 'Grupo Sol'], [$it['itemTitle'], $it['shortTitle']]);
        $this->assertSame(['', '04/11/2026', '05/11/2026', '06/11/2026', ''], [$it['custFeature1'], $it['custFeature2'], $it['custFeature3'], $it['custFeature4'], $it['custFeature5']]);
        $this->assertSame(['AMC42B-W - Cant. 2', 'AMC21-R - Cant. 1', ''], [$it['custFeature10'], $it['custFeature11'], $it['custFeature12']]);
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

    public function test_si_zkong_rechaza_el_producto_no_rompe(): void
    {
        config(['services.warranty_cloud.store_id' => '555']);
        $this->rechazo = [['barCode' => 'G-2026-0007', 'msg' => 'error']];
        $this->assertFalse(WarrantyCloudItem::send($this->garantia()->id));
    }
}
