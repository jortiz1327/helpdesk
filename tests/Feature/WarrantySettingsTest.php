<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración de garantías: estados y categorías editables, reglas de alerta y
 * plantillas de diagnóstico, y cómo eso cambia las garantías (cierre, alertas).
 */
class WarrantySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
    }

    private function agente(array $permisos): array
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    private function cfg(string $action, array $d, array $h)
    {
        return $this->postJson("/api/warranty_settings.php?action={$action}", $d, $h);
    }

    private function garantia(array $h, array $d = []): array
    {
        return $this->postJson('/api/warranties.php?action=save', $d + ['received_at' => '2026-10-20', 'client_name' => 'Hoteles Sol', 'status' => 'no_iniciado'], $h)->assertOk()->json();
    }

    public function test_estados_y_categorias_configurables(): void
    {
        [, $soloAcceso] = $this->agente(['warranties.access']);
        $this->getJson('/api/warranty_settings.php', $soloAcceso)->assertForbidden();
        [$u, $h] = $this->agente(['warranties.access', 'warranties.config']);

        $cfg = $this->getJson('/api/warranty_settings.php', $h)->assertOk()->json();
        $this->assertContains('falta_stock', collect($cfg['statuses'])->pluck('key')->all());
        $this->assertSame(8, count($cfg['alerts']));
        $this->assertNotEmpty($cfg['templates']);

        // Estado nuevo que cuenta como cerrada: pone fecha de cierre y sale de «abiertas».
        $k = $this->cfg('status_save', ['label' => 'Abonada', 'color' => '#12925a', 'closed' => true], $h)->assertOk()->json('key');
        $this->assertSame('abonada', $k);
        $this->cfg('status_save', ['label' => 'abonada', 'color' => '#12925a'], $h)->assertStatus(400);   // nombre repetido
        $this->cfg('status_save', ['label' => 'Otro', 'color' => 'rojo'], $h)->assertStatus(400);
        $w = $this->garantia($h);
        $this->postJson('/api/warranties.php?action=save', ['id' => $w['id'], 'received_at' => '2026-10-20', 'status' => $k], $h)->assertOk();
        $this->assertNotNull(Warranty::find($w['id'])->closed_at);
        $this->assertSame(0, $this->getJson('/api/warranties.php?chip=open', $h)->json('total'));
        $this->assertSame(1, $this->getJson('/api/warranties.php?chip=closed', $h)->json('total'));

        // En uso no se borra; desactivado no se elige, pero quien lo tiene lo conserva.
        $this->cfg('status_delete', ['key' => $k], $h)->assertStatus(400);
        $this->cfg('status_save', ['key' => $k, 'label' => 'Abonada', 'color' => '#12925a', 'closed' => true, 'active' => false], $h)->assertOk();
        $this->postJson('/api/warranties.php?action=save', ['id' => $w['id'], 'received_at' => '2026-10-20', 'status' => $k, 'description' => 'x'], $h)->assertOk();
        $w2 = $this->garantia($h);
        $this->postJson('/api/warranties.php?action=save', ['id' => $w2['id'], 'received_at' => '2026-10-20', 'status' => $k], $h)->assertStatus(400);

        // Los de serie no se borran y los que usa la aplicación no se desactivan ni cambian de sentido.
        $this->cfg('status_delete', ['key' => 'pte_cliente'], $h)->assertStatus(400);
        $this->cfg('status_save', ['key' => 'cerrada', 'label' => 'Finalizada', 'color' => '#16a34a', 'closed' => false, 'active' => false], $h)->assertOk();
        $c = DB::table('warranty_statuses')->where('key', 'cerrada')->first();
        $this->assertSame(['Finalizada', 1, 1], [$c->label, (int) $c->closed, (int) $c->active]);
        $this->assertSame('Finalizada', $this->getJson('/api/warranties.php?action=options', $h)->json('statuses.cerrada'));

        // Orden.
        $this->cfg('order', ['what' => 'statuses', 'ids' => ['descartada', 'no_iniciado']], $h)->assertOk();
        $this->assertSame('descartada', array_key_first($this->getJson('/api/warranties.php?action=options', $h)->json('status_meta')));

        // Categorías: renombrar arrastra las garantías; con uso no se borra.
        $this->postJson('/api/warranties.php?action=save', ['id' => $w2['id'], 'received_at' => '2026-10-20', 'status' => 'en_curso', 'category' => 'Hoteles'], $h)->assertOk();
        $hoteles = DB::table('warranty_categories')->where('name', 'Hoteles')->value('id');
        $this->cfg('category_save', ['id' => $hoteles, 'name' => 'Retail'], $h)->assertStatus(400);
        $this->cfg('category_save', ['id' => $hoteles, 'name' => 'Hostelería'], $h)->assertOk();
        $this->assertSame('Hostelería', Warranty::find($w2['id'])->category);
        $this->cfg('category_delete', ['id' => $hoteles], $h)->assertStatus(400);
        $this->cfg('category_save', ['id' => $hoteles, 'name' => 'Hostelería', 'active' => false], $h)->assertOk();
        $this->assertNotContains('Hostelería', $this->getJson('/api/warranties.php?action=options', $h)->json('categories'));
        $nueva = $this->cfg('category_save', ['name' => 'Gasolineras'], $h)->assertOk()->json('id');
        $this->cfg('category_delete', ['id' => $nueva], $h)->assertOk();

        // Plantillas: las activas llegan a las opciones de la revisión.
        $t = $this->cfg('template_save', ['title' => 'Golpe en esquina', 'body' => 'Se ve un golpe.'], $h)->assertOk()->json('id');
        $this->assertContains('Golpe en esquina', collect($this->getJson('/api/warranties.php?action=options', $h)->json('diagnosis'))->pluck('title')->all());
        $this->cfg('template_save', ['id' => $t, 'title' => 'Golpe en esquina', 'body' => 'Se ve un golpe.', 'active' => false], $h)->assertOk();
        $this->assertNotContains('Golpe en esquina', collect($this->getJson('/api/warranties.php?action=options', $h)->json('diagnosis'))->pluck('title')->all());
        $this->cfg('template_save', ['title' => '', 'body' => 'x'], $h)->assertStatus(400);
        $this->cfg('template_delete', ['id' => $t], $h)->assertOk();
    }

    public function test_alertas_por_reglas(): void
    {
        [$u, $h] = $this->agente(['warranties.access', 'warranties.config']);
        $ana = $u->id;

        // Pendiente de cliente desde hace 9 días (aviso a 7, crítico a 14) y con responsable.
        $a = $this->garantia($h, ['responsible_id' => $ana]);
        $this->postJson('/api/warranties.php?action=save', ['id' => $a['id'], 'received_at' => '2026-10-20', 'status' => 'pte_cliente', 'responsible_id' => $ana], $h)->assertOk();
        Warranty::whereKey($a['id'])->update(['status_changed_at' => now()->subDays(9), 'last_activity_at' => now()]);
        // Sin responsable: crítica al momento.
        $b = $this->garantia($h);

        $rows = collect($this->getJson('/api/warranties.php', $h)->json('rows'))->keyBy('id');
        $this->assertSame([['cliente', 'warn', 'Cliente: 9 días']], array_map(fn ($x) => [$x['key'], $x['level'], $x['text']], $rows[$a['id']]['alerts']));
        $this->assertSame('crit', $rows[$b['id']]['alerts'][0]['level']);
        $this->assertSame(2, $this->getJson('/api/warranties.php?chip=alerts', $h)->json('total'));
        $this->assertSame([$b['id']], collect($this->getJson('/api/warranties.php?chip=critical', $h)->json('rows'))->pluck('id')->all());

        // Bajando los días de crítico pasa a crítica; apagando la regla desaparece.
        $reglas = collect($this->getJson('/api/warranty_settings.php', $h)->json('alerts'));
        $cambia = fn (array $c) => $reglas->map(fn ($r) => $r['key'] === 'cliente' ? array_merge($r, $c) : $r)->all();
        $this->cfg('alerts_save', ['rules' => $cambia(['warn_days' => 10, 'crit_days' => 5])], $h)->assertStatus(400);
        $this->cfg('alerts_save', ['rules' => $cambia(['warn_days' => 3, 'crit_days' => 8])], $h)->assertOk();
        $this->assertSame('crit', $this->getJson("/api/warranties.php?action=get&id={$a['id']}", $h)->json('warranty.alerts.0.level'));
        $this->cfg('alerts_save', ['rules' => $cambia(['active' => false])], $h)->assertOk();
        $this->assertSame([], $this->getJson("/api/warranties.php?action=get&id={$a['id']}", $h)->json('warranty.alerts'));

        // Cambiar de estado reinicia la cuenta; cerrada no tiene alertas.
        $this->postJson('/api/warranties.php?action=save', ['id' => $a['id'], 'received_at' => '2026-10-20', 'status' => 'en_curso', 'responsible_id' => $ana], $h)->assertOk();
        $this->assertTrue(Warranty::find($a['id'])->status_changed_at->isToday());
        $this->postJson('/api/warranties.php?action=save', ['id' => $b['id'], 'received_at' => '2026-10-20', 'status' => 'cerrada'], $h)->assertOk();
        $this->assertSame(0, $this->getJson('/api/warranties.php?chip=alerts', $h)->json('total'));

        // RMA: remesa enviada a ZKONG hace 10 días sin respuesta (aviso a 7, crítico a 15).
        $lote = DB::table('zkong_batches')->insertGetId(['code' => 'R-2026-001', 'status' => 'enviada', 'sent_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('warranty_items')->insert(['warranty_id' => $a['id'], 'code' => '8E9749440', 'kind' => 'esl', 'lookup_state' => 'found',
            'resolution' => 'defectuosa', 'zkong_decision' => 'enviar', 'zkong_batch_id' => $lote, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([['rma', 'warn']], array_map(fn ($x) => [$x['key'], $x['level']], $this->getJson("/api/warranties.php?action=get&id={$a['id']}", $h)->json('warranty.alerts')));
        $this->assertSame([$a['id']], collect($this->getJson('/api/warranties.php?chip=alerts', $h)->json('rows'))->pluck('id')->all());
        $this->assertSame(0, $this->getJson('/api/warranties.php?chip=critical', $h)->json('total'));
    }
}
