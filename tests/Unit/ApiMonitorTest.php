<?php

namespace Tests\Unit;

use App\Services\Ai\ApiMonitor;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_sin_uso_no_inventa_cifras(): void
    {
        $r = ApiMonitor::resumen('adminhoteles');
        $this->assertSame('sin_uso', $r['nivel']);
        $this->assertSame(0, $r['llamadas']);
        $this->assertNull($r['media_ms']);
        $this->assertNull($r['cache']);
    }

    public function test_cuenta_llamadas_fallos_lentas_tiempos_y_cache(): void
    {
        ApiMonitor::registrar('adminhoteles', '/api/support/v1/hotels', 0.4);
        ApiMonitor::registrar('adminhoteles', '/api/support/v1/hotels', 0.6);
        ApiMonitor::registrar('adminhoteles', '/api/support/v1/users/lookup', 8.0);       // lenta (> 6 s)
        ApiMonitor::registrar('adminhoteles', '/api/support/v1/users/lookup', 12.0, false, 'sin respuesta');
        ApiMonitor::cache('adminhoteles', true);
        ApiMonitor::cache('adminhoteles', false);
        ApiMonitor::cache('adminhoteles', true);
        ApiMonitor::cache('adminhoteles', true);
        $r = ApiMonitor::resumen('adminhoteles');
        $this->assertSame(4, $r['llamadas']);
        $this->assertSame(1, $r['errores']);
        $this->assertSame(1, $r['lentas']);
        $this->assertSame(75, $r['cache']['pct']);
        $this->assertSame('/api/support/v1/users/lookup', $r['ultimo_error']['op']);
        $this->assertSame('aviso', $r['nivel']);
        $this->assertSame(2, $r['operaciones'][0]['llamadas']);
        $this->assertSame(12000, $r['max_ms']);
    }

    public function test_tres_fallos_es_mal_y_medir_anota_y_relanza(): void
    {
        foreach ([1, 2, 3] as $i) {
            ApiMonitor::registrar('proveedor', 'mensaje', 1.0, false, 'HTTP 500');
        }
        $this->assertSame('mal', ApiMonitor::resumen('proveedor')['nivel']);
        $this->assertSame(7, ApiMonitor::medir('memoria', 'buscar', fn () => 7));
        try {
            ApiMonitor::medir('memoria', 'buscar', function () { throw new \RuntimeException('x'); });
            $this->fail('debía relanzar');
        } catch (\RuntimeException) {
        }
        $m = ApiMonitor::resumen('memoria');
        $this->assertSame(2, $m['llamadas']);
        $this->assertSame(1, $m['errores']);
    }
}
