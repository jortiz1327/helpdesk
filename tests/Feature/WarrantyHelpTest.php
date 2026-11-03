<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use App\Services\WarrantyHelp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Centro de ayuda: artículos de serie, cambiarlos, volver al original, añadir y quitar. */
class WarrantyHelpTest extends TestCase
{
    use RefreshDatabase;

    private array $h;
    private array $hc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo(['warranties.access', 'warranties.config']);
        $c = User::create(['name' => 'Luis', 'email' => 'luis@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $c->assignRole('consulta_garantias');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
        $this->hc = ['X-App-Token' => TokenService::make($c)];
    }

    private function hacer(string $action, array $d, ?array $h = null)
    {
        return $this->postJson("/api/warranty_help.php?action={$action}", $d, $h ?? $this->h);
    }

    public function test_de_serie_y_cambios(): void
    {
        $r = $this->getJson('/api/warranty_help.php', $this->hc)->assertOk();
        $this->assertFalse($r->json('manage'));
        $this->assertCount(count(WarrantyHelp::DEFAULTS), $r->json('articles'));
        $this->assertSame('que-es', $r->json('articles.0.key'));
        $this->hacer('save', ['key' => 'cerrar', 'title' => 'X', 'body' => 'Y', 'section' => 'config'], $this->hc)->assertStatus(403);

        // Cambiar uno de serie: se mueve de apartado y pierde un relacionado que no existe.
        $this->hacer('save', ['key' => 'cerrar', 'title' => 'Cerrar', 'body' => "Uno\n\nDos", 'section' => 'garantias', 'related' => ['estados', 'nada', 'cerrar']])->assertOk();
        $this->hacer('save', ['key' => 'cerrar', 'title' => '', 'body' => 'x', 'section' => 'garantias'])->assertStatus(400);
        $this->hacer('save', ['key' => 'cerrar', 'title' => 'x', 'body' => 'x', 'section' => 'otra'])->assertStatus(400);
        $a = collect($this->getJson('/api/warranty_help.php', $this->hc)->json('articles'))->keyBy('key');
        $this->assertSame(['Cerrar', 'garantias', ['estados'], true], [$a['cerrar']['title'], $a['cerrar']['section'], $a['cerrar']['related'], $a['cerrar']['edited']]);

        // Ocultarlo: quien consulta no lo ve; el tour deja de enlazar al de su paso.
        $this->hacer('save', ['key' => 'ficha', 'title' => 'Ficha', 'body' => 'x', 'section' => 'garantias', 'active' => false])->assertOk();
        $keys = array_column($this->getJson('/api/warranty_help.php', $this->hc)->json('articles'), 'key');
        $this->assertNotContains('ficha', $keys);
        $this->assertContains('ficha', array_column($this->getJson('/api/warranty_help.php', $this->h)->json('articles'), 'key'));
        $pasos = collect($this->getJson('/api/warranties.php?action=tour', $this->hc)->json('steps'))->keyBy('id');
        $this->assertNull($pasos['ficha']['article']);
        $this->assertSame('crear', $pasos['acciones']['article']);

        // Volver al texto original.
        $this->hacer('remove', ['key' => 'cerrar'])->assertOk();
        $a = collect($this->getJson('/api/warranty_help.php', $this->hc)->json('articles'))->keyBy('key');
        $this->assertSame(['Cerrar y reabrir una garantía', 'documentos', false], [$a['cerrar']['title'], $a['cerrar']['section'], $a['cerrar']['edited']]);
    }

    public function test_anadir_y_quitar(): void
    {
        $k = $this->hacer('save', ['title' => 'Remesas urgentes', 'summary' => 'Cuándo', 'body' => '- Uno', 'section' => 'zkong', 'related' => ['zkong']])->assertOk()->json('key');
        $this->assertMatchesRegularExpression('/^a\d+$/', $k);
        $this->hacer('save', ['key' => 'zkong', 'title' => 'ZKONG', 'body' => 'x', 'section' => 'zkong', 'related' => [$k]])->assertOk();

        $a = collect($this->getJson('/api/warranty_help.php', $this->hc)->json('articles'))->keyBy('key');
        $this->assertTrue($a[$k]['custom']);
        $this->assertSame([$k], $a['zkong']['related']);
        // Los añadidos van al final de su apartado.
        $zk = array_values(array_filter($a->all(), fn ($x) => $x['section'] === 'zkong'));
        $this->assertSame($k, end($zk)['key']);

        $this->hacer('remove', ['key' => $k], $this->hc)->assertStatus(403);
        $this->hacer('remove', ['key' => $k])->assertOk();
        $this->hacer('remove', ['key' => $k])->assertNotFound();
        $a = collect($this->getJson('/api/warranty_help.php', $this->hc)->json('articles'))->keyBy('key');
        $this->assertArrayNotHasKey($k, $a->all());
        $this->assertSame([], $a['zkong']['related']);
    }
}
