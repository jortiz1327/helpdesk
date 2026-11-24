<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\ManualesCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualesCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private function area(string $clave): array
    {
        return collect(ManualesCatalogo::resumen())->firstWhere('clave', $clave);
    }

    public function test_cada_area_del_catalogo_aparece_y_las_vacias_se_marcan_como_tales(): void
    {
        $claves = array_column(ManualesCatalogo::resumen(), 'clave');
        $this->assertSame(array_keys(config('ai.areas')), $claves);
        foreach (['tiendas', 'cloud'] as $vacia) {
            $this->assertSame('vacia', $this->area($vacia)['nivel']);
            $this->assertSame(0, $this->area($vacia)['archivos']);
        }
        $this->assertGreaterThan(0, $this->area('hoteles')['archivos']);
    }

    public function test_un_area_con_manuales_sin_cargar_esta_pendiente_hasta_que_se_indexa(): void
    {
        $this->assertSame('pendiente', $this->area('soporte')['nivel']);
        $this->assertGreaterThan(0, $this->area('soporte')['pendientes']['nuevos']);
        app(\App\Services\Ai\AiKnowledge::class)->indexarDocumentacion();
        $a = $this->area('soporte');
        $this->assertSame(0, array_sum($a['pendientes']));
        $this->assertGreaterThan(0, $a['trozos']);
        $this->assertSame('revisar', $a['nivel'], 'cargada pero nunca revisada');
    }

    public function test_revisado_hoy_deja_el_area_al_dia_y_caduca_con_el_plazo(): void
    {
        app(\App\Services\Ai\AiKnowledge::class)->indexarDocumentacion();
        $this->assertTrue(ManualesCatalogo::marcarRevisado('hoteles'));
        $a = $this->area('hoteles');
        $this->assertSame('ok', $a['nivel']);
        $this->assertSame(0, $a['dias_revision']);
        // Hace más días que el plazo: toca revisar.
        Setting::put('ai_manuales_revisado', json_encode(['hoteles' => now()->subDays(60)->toDateString()]));
        $this->assertSame('revisar', $this->area('hoteles')['nivel']);
        $this->assertFalse(ManualesCatalogo::marcarRevisado('no-existe'));
    }

    public function test_el_area_de_un_documento_se_saca_de_su_ruta(): void
    {
        $this->assertSame('hoteles', ManualesCatalogo::areaDe('doc:hoteles/03-usuarios.md#2'));
        $this->assertSame('farmacias', ManualesCatalogo::areaDe('doc:farmacias/farmacias-stock-en-etiquetas.md#1'));
        $this->assertSame('cliente', ManualesCatalogo::areaDe('cliente#4'));
        $this->assertNull(ManualesCatalogo::areaDe('doc:otra/cosa.md#1'));
    }

    public function test_el_endpoint_marca_el_area_y_solo_lo_hace_quien_configura(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $enc = \App\Models\User::create(['name' => 'Enc', 'email' => 'e@x.com', 'password' => bcrypt('x')]);
        $enc->assignRole('encargado_soporte');
        $ag = \App\Models\User::create(['name' => 'Ag', 'email' => 'a@x.com', 'password' => bcrypt('x')]);
        $ag->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withHeader('X-App-Token', \App\Services\TokenService::make($ag))->postJson('/api/ai_suggestions.php?action=admin_manual', ['area' => 'hoteles'])->assertStatus(403);
        $r = $this->withHeader('X-App-Token', \App\Services\TokenService::make($enc))->postJson('/api/ai_suggestions.php?action=admin_manual', ['area' => 'hoteles'])->assertOk();
        $this->assertSame(now()->toDateString(), collect($r->json('manuales'))->firstWhere('clave', 'hoteles')['revisado']);
        $this->withHeader('X-App-Token', \App\Services\TokenService::make($enc))->postJson('/api/ai_suggestions.php?action=admin_manual', ['area' => 'x'])->assertStatus(404);
        $this->assertSame(0, DB::table('ai_documents')->count());
    }
}
