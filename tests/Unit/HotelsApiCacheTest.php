<?php

namespace Tests\Unit;

use App\Services\Ai\HotelsApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HotelsApiCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.support.url' => 'https://panel.test', 'ai.support.key' => 'k']);
        Cache::flush();
    }

    public function test_hotel_encontrado_se_pregunta_una_sola_vez(): void
    {
        Http::fake(['panel.test/*' => Http::response(['data' => ['hoteles' => [['id' => '12', 'nombre' => 'Hotel Poseidón', 'grupo' => 'X']]]])]);
        $api = new HotelsApi();
        $this->assertSame('Hotel Poseidón', $api->buscarHoteles('Hotel Poseidón')[0]['nombre']);
        $this->assertSame('Hotel Poseidón', $api->buscarHoteles('hotel poseidon')[0]['nombre']);
        Http::assertSentCount(1);
    }

    public function test_hotel_no_encontrado_se_vuelve_a_preguntar(): void
    {
        Http::fake(['panel.test/*' => Http::response(['data' => ['hoteles' => []]])]);
        $api = new HotelsApi();
        $api->buscarHoteles('Hotel Nuevo');
        $api->buscarHoteles('Hotel Nuevo');
        Http::assertSentCount(2);
    }

    public function test_correo_encontrado_se_recuerda(): void
    {
        Http::fake(['panel.test/*' => Http::response(['data' => ['encontrado' => true, 'coincidencias' => [['hoteles' => [['id' => '1', 'nombre' => 'H']], 'buffets' => [['id' => '5', 'nombre' => 'B', 'hotel_id' => '1']], 'usuario' => ['rol' => 'Admin', 'activo' => true]]]]])]);
        $api = new HotelsApi();
        $this->assertSame('encontrado', $api->verificar('a@b.es')['estado']);
        $this->assertSame('encontrado', $api->verificar('A@B.es')['estado']);
        Http::assertSentCount(1);
    }

    public function test_un_fallo_no_se_recuerda(): void
    {
        Http::fake(['panel.test/*' => Http::response('', 500)]);
        $api = new HotelsApi();
        $this->assertSame('incompleto', $api->verificar('c@d.es')['estado']);
        $this->assertSame('incompleto', $api->verificar('c@d.es')['estado']);
        Http::assertSentCount(2);
    }
}
