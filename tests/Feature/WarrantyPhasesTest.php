<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use App\Services\WarrantyAlerts;
use App\Services\WarrantyPhases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tiempo por fase: días en el estado, media por fase y la regla «Fase atascada». */
class WarrantyPhasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('warranty_alert_rules')->update(['active' => false]);
        DB::table('warranty_alert_rules')->where('key', 'fase')->update(['active' => true, 'warn_days' => 10, 'crit_days' => 20]);
        DB::table('warranty_alert_rules')->where('key', 'cliente')->update(['active' => true, 'warn_days' => 7, 'crit_days' => 14]);
    }

    private function garantia(string $status, int $diasEnEstado, string $code): Warranty
    {
        $w = Warranty::create(['code' => $code, 'status' => $status, 'received_at' => '2026-10-01', 'urgency' => 'media', 'responsible_id' => null,
            'last_activity_at' => now(), 'status_changed_at' => now()->subDays($diasEnEstado)]);
        DB::table('warranties')->where('id', $w->id)->update(['created_at' => now()->subDays($diasEnEstado + 4)]);
        return $w->fresh();
    }

    public function test_regla_fase_solo_en_estados_sin_regla_propia(): void
    {
        $a = $this->garantia('en_curso', 12, 'G-1');        // aviso de «fase»
        $b = $this->garantia('pte_presupuesto', 25, 'G-2'); // crítico de «fase»
        $c = $this->garantia('pte_cliente', 12, 'G-3');     // la vigila «cliente», no «fase»
        $d = $this->garantia('en_curso', 3, 'G-4');         // al día

        $al = (new WarrantyAlerts)->alerts(Warranty::all());
        $this->assertSame(['fase', 'warn', 'En «En curso»: 12 días'], [$al[$a->id][0]['key'], $al[$a->id][0]['level'], $al[$a->id][0]['text']]);
        $this->assertSame(['fase', 'crit'], [$al[$b->id][0]['key'], $al[$b->id][0]['level']]);
        $this->assertSame(['cliente'], array_column($al[$c->id], 'key'));
        $this->assertArrayNotHasKey($d->id, $al);

        $q = DB::table('warranties');
        (new WarrantyAlerts)->scope($q, 'crit');
        $this->assertSame([$b->id], $q->pluck('id')->all());
    }

    public function test_media_por_fase_y_ahora(): void
    {
        $w = $this->garantia('pte_presupuesto', 2, 'G-1');
        // Alta hace 6 días → 4 días en «En curso» → pasa a «Pte. presupuesto» hace 2.
        DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'field' => 'status', 'from_value' => 'En curso',
            'to_value' => 'Pte. presupuesto', 'created_at' => now()->subDays(2)]);
        $this->garantia('en_curso', 12, 'G-2');

        $r = WarrantyPhases::report();
        $f = collect($r['phases'])->keyBy('key');
        $this->assertEquals(4, $f['en_curso']['avg']);
        $this->assertSame(1, $f['en_curso']['stays']);
        $this->assertSame([1, 12, 1], [$f['en_curso']['now'], $f['en_curso']['now_max'], $f['en_curso']['warn']]);
        $this->assertSame(1, $f['pte_presupuesto']['now']);
        $this->assertSame('fase', $f['pte_presupuesto']['limit']['rule']);
        $this->assertSame('cliente', $f['pte_cliente']['limit']['rule']);
        $this->assertSame(['G-2', 'G-1'], array_column($r['oldest'], 'code'));
        $this->assertSame([2, 1, 0], [$r['kpis']['open'], $r['kpis']['warn'], $r['kpis']['crit']]);

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ve', 'email' => 've@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo('warranties.access');
        $this->getJson('/api/warranties.php?action=phases', ['X-App-Token' => TokenService::make($u)])
            ->assertOk()->assertJsonPath('kpis.open', 2);
    }

    public function test_recorrido_de_una_garantia_para_la_ficha(): void
    {
        // Alta hace 30 días: 4 en «En curso», 2 en «Pte. cliente», vuelve 3 a «En curso» y lleva 21 en «Pte. presupuesto».
        $w = $this->garantia('pte_presupuesto', 21, 'G-1');
        DB::table('warranties')->where('id', $w->id)->update(['created_at' => now()->subDays(30)]);
        foreach ([[26, 'En curso', 'Pte. cliente'], [24, 'Pte. cliente', 'En curso'], [21, 'En curso', 'Pte. presupuesto']] as [$hace, $de, $a]) {
            DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'field' => 'status', 'from_value' => $de, 'to_value' => $a, 'created_at' => now()->subDays($hace)]);
        }

        $p = collect(WarrantyPhases::forWarranty($w->fresh())['steps'])->keyBy('key');
        $this->assertSame(['done', 7.0, 2], [$p['en_curso']['state'], (float) $p['en_curso']['days'], $p['en_curso']['visits']]);
        $this->assertSame(['done', 2.0], [$p['pte_cliente']['state'], (float) $p['pte_cliente']['days']]);
        $this->assertSame(['current', 21, 'crit'], [$p['pte_presupuesto']['state'], $p['pte_presupuesto']['now'], $p['pte_presupuesto']['level']]);
        $this->assertSame(['todo', null], [$p['enviada']['state'], $p['enviada']['days']]);
        $this->assertSame(['_cierre', 'todo'], [$p->last()['key'], $p->last()['state']]);

        // Cerrada: el último paso es su estado de cierre, y ya no hay nivel.
        DB::table('warranties')->where('id', $w->id)->update(['status' => 'cerrada', 'closed_at' => now(), 'status_changed_at' => now()]);
        DB::table('warranty_events')->insert(['warranty_id' => $w->id, 'field' => 'status', 'from_value' => 'Pte. presupuesto', 'to_value' => 'Cerrada', 'created_at' => now()]);
        $c = WarrantyPhases::forWarranty($w->fresh());
        $this->assertSame([true, 'cerrada', 'current'], [$c['closed'], end($c['steps'])['key'], end($c['steps'])['state']]);
        $pp = collect($c['steps'])->firstWhere('key', 'pte_presupuesto');
        $this->assertSame(['done', 21], [$pp['state'], (int) round($pp['days'])]);
        $this->assertArrayNotHasKey('level', $pp);

        // Y la ficha lo trae.
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ve', 'email' => 've@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo('warranties.access');
        $this->getJson("/api/warranties.php?action=get&id={$w->id}", ['X-App-Token' => TokenService::make($u)])
            ->assertOk()->assertJsonPath('warranty.phases.closed', true);
    }
}
