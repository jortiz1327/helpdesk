<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Services\TokenService;
use App\Services\WarrantyTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Seguimiento para el cliente: enlace con clave propia (se renueva y anula el anterior), la
 * fase que ve el cliente en lugar del estado interno y solo cifras de los dispositivos.
 */
class WarrantyTrackingTest extends TestCase
{
    use RefreshDatabase;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
    }

    private function garantia(string $status = 'en_curso'): Warranty
    {
        $w = Warranty::create(['code' => 'G-2026-0099', 'status' => $status, 'urgency' => 'media', 'client_name' => 'Hotel Secreto',
            'description' => 'Nota interna que no debe verse', 'last_activity_at' => now(), 'status_changed_at' => now()]);
        foreach (['A1' => 'correcta', 'A2' => 'pendiente', 'A3' => 'defectuosa'] as $code => $res) {
            DB::table('warranty_items')->insert(['warranty_id' => $w->id, 'code' => $code, 'kind' => 'esl', 'resolution' => $res, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $w->fresh();
    }

    private function enlace(Warranty $w, bool $renew = false): string
    {
        $r = $this->postJson('/api/warranties.php?action=tracking', ['id' => $w->id, 'renew' => $renew], $this->h)->assertOk();
        return parse_url($r->json('tracking.url'), PHP_URL_PATH);
    }

    public function test_el_cliente_ve_la_fase_y_solo_cifras(): void
    {
        $w = $this->garantia('pte_presupuesto');
        $path = $this->enlace($w);
        $this->assertMatchesRegularExpression('#^/seguimiento/[A-Za-z0-9]{32}$#', $path);

        $html = $this->get($path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
        $this->assertStringContainsString('Necesitamos tu respuesta', $html);   // la fase, no «Pte. presupuesto»
        $this->assertStringNotContainsString('Pte. presupuesto', $html);
        $this->assertStringContainsString('G-2026-0099', $html);
        $this->assertStringContainsString('<b>3</b><span>dispositivos', $html);
        $this->assertStringContainsString('<b>2</b><span>revisados', $html);
        $this->assertStringNotContainsString('Hotel Secreto', $html);
        $this->assertStringNotContainsString('Nota interna', $html);
        $this->assertStringNotContainsString('A1', $html);   // ni los códigos de las etiquetas

        $this->assertSame(1, (int) $w->fresh()->track_views);
        $this->get($path . '?previa=1')->assertOk();   // el agente mirando: no cuenta
        $this->assertSame(1, (int) $w->fresh()->track_views);
    }

    public function test_nuevo_enlace_anula_el_anterior_y_cerrada_caduca(): void
    {
        $w = $this->garantia();
        $viejo = $this->enlace($w);
        $this->assertSame($viejo, $this->enlace($w));   // copiar otra vez: el mismo
        $nuevo = $this->enlace($w, true);
        $this->assertNotSame($viejo, $nuevo);
        $this->get($viejo)->assertNotFound();
        $this->get($nuevo)->assertOk();

        $w->forceFill(['status' => 'cerrada', 'closed_at' => now()->subDays(WarrantyTracking::DIAS_TRAS_CIERRE + 1)])->saveQuietly();
        $this->get($nuevo)->assertNotFound();
        $this->assertTrue($this->getJson('/api/warranties.php?action=get&id=' . $w->id, $this->h)->json('warranty.tracking.expired'));
    }

    public function test_fases_y_textos_en_configuracion(): void
    {
        $guardar = fn (array $v) => $this->postJson('/api/warranty_settings.php?action=tracking_save', $v, $this->h);
        $guardar(['days' => 30, 'stages' => ['revision' => ['label' => ' ', 'text' => 'x']]])->assertStatus(400);
        $guardar(['days' => 15, 'map' => ['en_curso' => 'fabricante'], 'stages' => ['fabricante' => ['label' => 'En ZKONG', 'text' => 'Está fuera.']]])->assertOk();

        $w = $this->garantia('en_curso');
        $html = $this->get($this->enlace($w))->getContent();
        $this->assertStringContainsString('En ZKONG', $html);
        $this->assertStringContainsString('Está fuera.', $html);
        $this->assertSame(15, WarrantyTracking::settings()['days']);

        // Desactivado: el enlace no enseña nada y no se crean más.
        $guardar(['active' => false])->assertOk();
        $this->postJson('/api/warranties.php?action=tracking', ['id' => $w->id], $this->h)->assertStatus(400);
    }

    public function test_sin_permiso_de_edicion_no_crea_enlaces(): void
    {
        $w = $this->garantia();
        $c = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $c->assignRole('consulta_garantias');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->postJson('/api/warranties.php?action=tracking', ['id' => $w->id], ['X-App-Token' => TokenService::make($c)])->assertForbidden();
        $this->assertNull($w->fresh()->track_token);
        $this->get('/seguimiento/' . str_repeat('a', 32))->assertNotFound();
    }
}
