<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Vista del equipo (carga por responsable) e informes por rango y agrupación. */
class WarrantyTeamReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
    }

    private function agente(string $name): array
    {
        $u = User::create(['name' => $name, 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo(['warranties.access']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    private function w(array $d): Warranty
    {
        static $n = 0;
        $n++;
        return Warranty::create($d + ['code' => 'G-2026-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'received_at' => '2026-10-01',
            'status' => 'en_curso', 'last_activity_at' => now(), 'status_changed_at' => now(), 'client_name' => 'Hoteles Sol']);
    }

    public function test_equipo_por_responsable_y_filtro_de_la_lista(): void
    {
        [$ana, $h] = $this->agente('Ana');
        [$luis] = $this->agente('Luis');
        $this->w(['responsible_id' => $ana->id, 'urgency' => 'muy_urgente']);                                       // crítica y urgente
        $a2 = $this->w(['responsible_id' => $ana->id, 'status' => 'pte_cliente', 'status_changed_at' => now()->subDays(8)]); // aviso cliente
        WarrantyItem::create(['warranty_id' => $a2->id, 'code' => '8E9749440', 'kind' => 'esl', 'lookup_state' => 'found']);
        $this->w(['responsible_id' => $ana->id, 'status' => 'cerrada', 'closed_at' => now()]);                       // no cuenta
        $this->w(['last_activity_at' => now()->subDays(40)]);                                                          // sin responsable

        $rows = collect($this->getJson('/api/warranties.php?action=team', $h)->assertOk()->json('rows'))->keyBy('name');
        $this->assertSame([2, 2, 1, 1, 1, 1], [$rows['Ana']['open'], $rows['Ana']['alerts'], $rows['Ana']['critical'], $rows['Ana']['urgent'], $rows['Ana']['pte_cliente'], $rows['Ana']['unreviewed']]);
        $this->assertSame(0, $rows['Luis']['open']);   // sale aunque no tenga ninguna
        $sin = collect($this->getJson('/api/warranties.php?action=team', $h)->json('rows'))->firstWhere('id', 'none');
        $this->assertSame([1, 1, 1], [$sin['open'], $sin['critical'], $sin['stale']]);

        // Cada número abre la lista con ese filtro.
        $this->assertSame(2, $this->getJson("/api/warranties.php?chip=open&responsible={$ana->id}", $h)->json('total'));
        $this->assertSame(1, $this->getJson("/api/warranties.php?chip=critical&responsible={$ana->id}", $h)->json('total'));
        $this->assertSame([$a2->id], collect($this->getJson("/api/warranties.php?chip=unreviewed&responsible={$ana->id}", $h)->json('rows'))->pluck('id')->all());
        $this->assertSame(1, $this->getJson('/api/warranties.php?chip=open&responsible=none', $h)->json('total'));
        $this->assertSame(0, $this->getJson("/api/warranties.php?responsible={$luis->id}", $h)->json('total'));
    }

    public function test_informe_por_rango_y_agrupacion(): void
    {
        [$ana, $h] = $this->agente('Ana');
        $a = $this->w(['received_at' => '2026-09-01', 'status' => 'cerrada', 'closed_at' => '2026-09-11 10:00', 'category' => 'Hoteles', 'responsible_id' => $ana->id]);
        $this->w(['received_at' => '2026-09-20', 'status' => 'resuelta', 'closed_at' => '2026-09-24 10:00', 'category' => 'Hoteles']);
        $this->w(['received_at' => '2026-10-05', 'category' => 'Retail']);
        $this->w(['received_at' => '2025-01-01']);   // fuera del rango
        WarrantyItem::create(['warranty_id' => $a->id, 'code' => '8E9749440', 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'defectuosa', 'zkong_result' => 'abono']);
        WarrantyItem::create(['warranty_id' => $a->id, 'code' => '8E9749441', 'kind' => 'esl', 'lookup_state' => 'found']);

        $r = $this->getJson('/api/warranties.php?action=report&from=2026-01-01&to=2026-12-31&group=category', $h)->assertOk()->json();
        $this->assertSame([3, 1, 2, 7.0, 2, 1], [$r['total']['total'], $r['total']['open'], $r['total']['closed'], (float) $r['total']['avg_days'], $r['total']['devices'], $r['total']['reviewed']]);
        $g = collect($r['rows'])->keyBy('label');
        $this->assertSame([2, 2, 0], [$g['Hoteles']['total'], $g['Hoteles']['closed'], $g['Hoteles']['open']]);
        $this->assertSame(['defectuosa' => 1, 'pendiente' => 1], collect($r['resolutions'])->sortKeys()->all());
        $this->assertSame(['abono' => 1], $r['zkong_results']);

        $m = $this->getJson('/api/warranties.php?action=report&from=2026-01-01&group=month', $h)->json('rows');
        $this->assertSame(['2026-10', '2026-09'], array_column($m, 'key'));
        $this->assertSame('Sin responsable', collect($this->getJson('/api/warranties.php?action=report&group=responsible', $h)->json('rows'))->firstWhere('total', 3)['label']);
        $this->assertSame(4, $this->getJson('/api/warranties.php?action=report&group=nada', $h)->json('total.total'));   // sin rango: todas; grupo raro → estado
    }
}
