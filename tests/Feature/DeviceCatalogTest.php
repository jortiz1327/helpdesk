<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Catálogo de modelos: pilas por modelo y referencias ZKONG ↔ AEME. */
class DeviceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function agente(array $perms): array
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return ['X-App-Token' => TokenService::make($u)];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    }

    public function test_alta_y_edicion_con_parejas_y_etiquetas_que_siguen_a_su_referencia(): void
    {
        $h = $this->agente(['warranties.access', 'warranties.config']);
        $w = Warranty::create(['code' => 'G-2026-0001', 'received_at' => '2026-10-01', 'status' => 'en_curso']);
        $it = WarrantyItem::create(['warranty_id' => $w->id, 'code' => '8E9749440', 'kind' => 'esl', 'lookup_state' => 'found', 'aeme_ref' => 'AMX99V']);

        $id = $this->postJson('/api/device_catalog.php?action=save', [
            'size' => '2,6', 'eink_colors' => 'BWR', 'color' => 'white', 'has_batteries' => true, 'battery_type' => 'cr2450', 'battery_quantity' => 2,
            'refs' => [['zkong' => 'ZKX99V (White)', 'aeme' => 'amx99v'], ['zkong' => '', 'aeme' => 'AMX99V-B']],
        ], $h)->assertOk()->assertJsonPath('relinked', 1)->json('id');
        $this->assertSame($id, $it->fresh()->device_model_id);   // la etiqueta ya tiene modelo y, con él, su pila

        $m = collect($this->getJson('/api/device_catalog.php', $h)->assertOk()->json('models'))->firstWhere('id', $id);
        $this->assertSame(['CR2450', 2, 1], [$m['battery_type'], $m['battery_quantity'], $m['used']]);
        $this->assertSame([['zkong' => 'ZKX99V (White)', 'aeme' => 'AMX99V'], ['zkong' => '', 'aeme' => 'AMX99V-B']], $m['refs']);
        $z = DB::table('device_model_references')->where('name', 'ZKX99V (White)')->first();
        $this->assertSame('AMX99V', DB::table('device_model_references')->where('id', $z->pair_id)->value('name'));

        // Repetida en otro modelo, sin pilas pero con tipo, sin referencias: no.
        $otro = ['size' => '4,2', 'eink_colors' => 'BW', 'color' => 'black', 'refs' => [['zkong' => 'zkx99v (white)', 'aeme' => '']]];
        $this->postJson('/api/device_catalog.php?action=save', $otro, $h)->assertStatus(400)->assertJsonPath('error', 'La referencia zkx99v (white) ya está en otro modelo');
        $this->postJson('/api/device_catalog.php?action=save', ['refs' => []] + $otro, $h)->assertStatus(400);
        $this->postJson('/api/device_catalog.php?action=save', ['has_batteries' => true, 'battery_type' => 'CR2032', 'battery_quantity' => 0] + $otro, $h)->assertStatus(400);

        // Quitar la referencia AEME de la etiqueta la deja sin modelo.
        $this->postJson('/api/device_catalog.php?action=save', ['id' => $id, 'size' => '2.6', 'eink_colors' => 'BWR', 'color' => 'white', 'has_batteries' => false,
            'refs' => [['zkong' => 'ZKX99V (White)', 'aeme' => 'AMX99V-B']]], $h)->assertOk();
        $this->assertNull($it->fresh()->device_model_id);
        $this->assertNull(DB::table('device_models')->where('id', $id)->value('battery_type'));

        // Borrar un modelo en uso pide confirmación.
        $it->update(['device_model_id' => $id]);
        $this->postJson('/api/device_catalog.php?action=delete', ['id' => $id], $h)->assertStatus(409)->assertJsonPath('used', 1);
        $this->postJson('/api/device_catalog.php?action=delete', ['id' => $id, 'force' => true], $h)->assertOk();
        $this->assertNull($it->fresh()->device_model_id);
        $this->assertSame(0, DB::table('device_model_references')->where('device_model_id', $id)->count());
    }

    public function test_una_zkong_con_varias_aeme(): void
    {
        $h = $this->agente(['warranties.access', 'warranties.config']);
        $id = $this->postJson('/api/device_catalog.php?action=save', [
            'size' => '4,2', 'eink_colors' => 'BWRY', 'color' => 'white', 'has_batteries' => false,
            'refs' => [['zkong' => 'ZKX77B-NE4 (White)', 'aeme' => 'AMX77B-W'], ['zkong' => 'ZKX77B-NE4 (White)', 'aeme' => 'AMX77B-W-X']],
        ], $h)->assertOk()->json('id');

        // Una sola ZKONG guardada; las dos AEME apuntan a ella y salen como dos parejas.
        $this->assertSame(1, DB::table('device_model_references')->where('device_model_id', $id)->where('provider', 'zkong')->count());
        $m = collect($this->getJson('/api/device_catalog.php', $h)->json('models'))->firstWhere('id', $id);
        $this->assertSame([['zkong' => 'ZKX77B-NE4 (White)', 'aeme' => 'AMX77B-W'], ['zkong' => 'ZKX77B-NE4 (White)', 'aeme' => 'AMX77B-W-X']], $m['refs']);

        // La misma AEME dos veces sigue sin valer.
        $this->postJson('/api/device_catalog.php?action=save', ['id' => $id, 'size' => '4,2', 'eink_colors' => 'BWRY', 'color' => 'white',
            'refs' => [['zkong' => 'ZKX77B-NE4', 'aeme' => 'AMX77B-W'], ['zkong' => '', 'aeme' => 'AMX77B-W']]], $h)->assertStatus(400);
    }

    public function test_consultar_si_cambiar_no_sin_configuracion(): void
    {
        $h = $this->agente(['warranties.access']);
        $this->getJson('/api/device_catalog.php', $h)->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/api/device_catalog.php?action=save', ['eink_colors' => 'BW', 'color' => 'white', 'refs' => [['zkong' => 'X', 'aeme' => '']]], $h)->assertStatus(403);
        $this->postJson('/api/device_catalog.php?action=delete', ['id' => 1], $h)->assertStatus(403);
    }
}
