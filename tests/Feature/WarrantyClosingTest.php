<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Cerrar pide motivo (de Configuración); reabrir, por qué. En la ficha, el formulario y en lote. */
class WarrantyClosingTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
    }

    private function guardar(array $d)
    {
        return $this->postJson('/api/warranties.php?action=save', $d + ['received_at' => '2026-10-20', 'client_name' => 'Hotel Sol'], $this->h);
    }

    private function eventos(int $id): array
    {
        return DB::table('warranty_events')->where('warranty_id', $id)->whereIn('field', ['close', 'reopen'])->orderBy('id')->pluck('to_value', 'field')->all();
    }

    public function test_cerrar_y_reabrir(): void
    {
        $id = $this->guardar(['status' => 'en_curso'])->assertOk()->json('id');

        $this->guardar(['id' => $id, 'status' => 'cerrada'])->assertStatus(400)->assertJson(['error' => 'Elige el motivo de cierre']);
        $this->guardar(['id' => $id, 'status' => 'cerrada', 'close_reason' => 'inventado'])->assertStatus(400);
        $this->guardar(['id' => $id, 'status' => 'cerrada', 'close_reason' => 'sustitucion', 'close_resolution' => 'Se cambiaron 3 etiquetas'])->assertOk();
        $w = Warranty::find($id);
        $this->assertSame(['sustitucion', 'Se cambiaron 3 etiquetas'], [$w->close_reason, $w->close_resolution]);
        $this->assertNotNull($w->closed_at);
        $this->assertSame('Sustitución realizada · Se cambiaron 3 etiquetas', $this->eventos($id)['close']);

        // Sigue cerrada: se corrige el motivo; otro estado cerrado no pide nada más.
        $this->guardar(['id' => $id, 'status' => 'cerrada', 'close_reason' => 'reparacion', 'close_resolution' => 'Se cambiaron 3 etiquetas'])->assertOk();
        $this->assertSame('reparacion', Warranty::find($id)->close_reason);
        $this->guardar(['id' => $id, 'status' => 'descartada'])->assertOk();

        // Reabrir: pide por qué y borra el motivo (queda en el historial).
        $this->guardar(['id' => $id, 'status' => 'en_curso', 'reopen_reason' => '  '])->assertStatus(400)->assertJson(['error' => 'Indica por qué se reabre']);
        $this->guardar(['id' => $id, 'status' => 'en_curso', 'reopen_reason' => 'Vuelven a fallar'])->assertOk();
        $w = Warranty::find($id);
        $this->assertNull($w->close_reason);
        $this->assertNull($w->closed_at);
        $this->assertSame('Vuelven a fallar', $this->eventos($id)['reopen']);
    }

    public function test_en_lote(): void
    {
        $a = $this->guardar(['status' => 'en_curso'])->json('id');
        $b = $this->guardar(['status' => 'pte_cliente'])->json('id');
        $lote = fn (array $d) => $this->postJson('/api/warranties.php?action=bulk', ['ids' => [$a, $b]] + $d, $this->h);

        $lote(['status' => 'cerrada'])->assertStatus(400);
        $lote(['status' => 'cerrada', 'close_reason' => 'duplicada'])->assertOk()->assertJson(['changed' => 2]);
        $this->assertSame(['duplicada', 'duplicada'], Warranty::whereIn('id', [$a, $b])->pluck('close_reason')->all());

        $lote(['status' => 'en_curso'])->assertStatus(400);
        $lote(['status' => 'en_curso', 'reopen_reason' => 'Era otra cosa'])->assertOk();
        $this->assertSame(0, Warranty::whereIn('id', [$a, $b])->whereNotNull('close_reason')->count());
        // Entre estados abiertos no pide nada.
        $lote(['status' => 'pte_cliente'])->assertOk();
    }

    public function test_motivos_en_configuracion_e_informe(): void
    {
        $id = $this->guardar(['status' => 'cerrada', 'close_reason' => 'aceptada'])->assertOk()->json('id');
        $this->guardar(['status' => 'en_curso'])->assertOk();

        $cfg = fn (array $list) => $this->postJson('/api/warranty_settings.php?action=close_reasons_save', ['list' => $list], $this->h);
        $lista = $this->getJson('/api/warranty_settings.php', $this->h)->json('close_reasons');
        $this->assertSame(1, collect($lista)->firstWhere('key', 'aceptada')['used']);

        // Quitar uno usado no; desactivarlo y renombrarlo sí (la garantía conserva la clave).
        $cfg(collect($lista)->reject(fn ($r) => $r['key'] === 'aceptada')->values()->all())->assertStatus(400);
        $cfg(collect($lista)->map(fn ($r) => $r['key'] === 'aceptada' ? ['label' => 'Aceptada por AEME', 'active' => false] + $r : $r)->push(['label' => 'Abonada', 'active' => true])->all())->assertOk();
        $nuevos = collect($this->getJson('/api/warranties.php?action=options', $this->h)->json('close_reasons'));
        $this->assertSame('Aceptada por AEME', $nuevos->firstWhere('key', 'aceptada')['label']);
        $this->assertNotEmpty($nuevos->firstWhere('label', 'Abonada')['key']);
        $cfg([['label' => 'Uno', 'active' => false], ['key' => 'aceptada', 'label' => 'x', 'active' => false]])->assertStatus(400);

        // Desactivado no se puede elegir para cerrar.
        $this->guardar(['status' => 'cerrada', 'close_reason' => 'aceptada'])->assertStatus(400);

        $filas = collect($this->getJson('/api/warranties.php?action=report&group=close_reason', $this->h)->assertOk()->json('rows'));
        $this->assertSame(['Aceptada por AEME', 'Abiertas'], $filas->pluck('label')->sort()->values()->reverse()->values()->all());
        $this->assertSame(1, Warranty::whereKey($id)->whereNotNull('close_reason')->count());
    }
}
