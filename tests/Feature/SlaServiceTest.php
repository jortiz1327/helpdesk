<?php

namespace Tests\Feature;

use App\Services\SlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SLA: dos reglas delicadas donde una regresión se pierde en silencio —
 *  1) la PRIORIDAD manda sobre la categoría al fijar el plazo, y
 *  2) `activo()` refleja el interruptor POR PETICIÓN (no queda congelado en el proceso).
 */
class SlaServiceTest extends TestCase
{
    use RefreshDatabase;

    /** El plazo efectivo: si la prioridad trae minutos propios, mandan sobre las horas de categoría. */
    public function test_la_prioridad_manda_sobre_la_categoria(): void
    {
        $svc = app(SlaService::class);
        $m = new \ReflectionMethod($svc, 'plazoMinutos');
        $m->setAccessible(true);

        // Prioridad 30 min vs categoría 4 h → gana la prioridad (30).
        $this->assertSame(30, $m->invoke($svc, 30, 4));
        // Sin plazo de prioridad → cae a la categoría (4 h = 240 min).
        $this->assertSame(240, $m->invoke($svc, 0, 4));
        $this->assertSame(240, $m->invoke($svc, null, 4));
        // Sin ninguno → 0 (no hay SLA para ese reloj).
        $this->assertSame(0, $m->invoke($svc, null, null));
    }

    /** El interruptor global se relee por petición: cambiarlo se nota tras vaciar el scope. */
    public function test_activo_refleja_el_ajuste_por_peticion(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'sla_active'], ['value' => '1']);
        app()->forgetScopedInstances();
        $this->assertTrue(SlaService::activo());

        DB::table('settings')->where('key', 'sla_active')->update(['value' => '0']);
        app()->forgetScopedInstances();               // simula nueva petición / trabajo de cola
        $this->assertFalse(SlaService::activo());
    }

    /** Con el SLA apagado, forTicket no devuelve relojes (aunque haya plazos). */
    public function test_sla_apagado_no_devuelve_relojes(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'sla_active'], ['value' => '0']);
        app()->forgetScopedInstances();

        $t = (object) [
            'opened_at' => now()->subHours(50), 'created_at' => now()->subHours(50),
            'pri_resolve_mins' => 60, 'sla_resolve_hours' => 24,
            'first_response_at' => null, 'resolved_at' => null, 'closed_at' => null,
            'sla_paused_minutes' => 0, 'sla_paused_since' => null,
        ];
        $r = app(SlaService::class)->forTicket($t);
        $this->assertNull($r['response']);
        $this->assertNull($r['resolve']);
    }
}
