<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Permisos de Gestión de garantías: consultar no deja cambiar nada; cada parte tiene el suyo. */
class WarrantyPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Warranty $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        $this->w = Warranty::create(['code' => 'G-2026-0001', 'received_at' => '2026-10-01', 'status' => 'en_curso', 'client_name' => 'Hoteles Sol', 'category' => 'Hoteles']);
        WarrantyItem::create(['warranty_id' => $this->w->id, 'code' => '802556445', 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'defectuosa', 'coverage' => 'garantia']);
    }

    private function como(string $rol, array $extra = []): array
    {
        $u = User::create(['name' => $rol, 'email' => "{$rol}@x.com", 'password' => bcrypt('x'), 'active' => 1]);
        $u->assignRole($rol);
        if ($extra) $u->givePermissionTo($extra);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return ['X-App-Token' => TokenService::make($u)];
    }

    public function test_consulta_solo_ve(): void
    {
        $h = $this->como('consulta_garantias');
        $id = $this->w->id;
        $this->getJson('/api/warranties.php', $h)->assertOk();
        $this->getJson("/api/warranties.php?action=get&id={$id}", $h)->assertOk();
        $this->assertSame(['edit' => false, 'review' => false, 'documents' => false, 'positions_ticket' => false, 'zkong' => false, 'delete' => false, 'config' => false],
            $this->getJson('/api/warranties.php?action=options', $h)->json('perms'));
        $this->getJson("/api/warranty_docs.php?action=sticker_data&id={$id}", $h)->assertOk();

        $this->postJson('/api/warranties.php?action=save', ['id' => $id, 'client_name' => 'X'], $h)->assertStatus(403)->assertJsonPath('error', 'No tienes permiso para editar garantías');
        $this->postJson('/api/warranties.php?action=note', ['id' => $id, 'text' => 'hola'], $h)->assertStatus(403);
        $this->postJson('/api/warranties.php?action=review', ['id' => $id, 'item_ids' => [1], 'resolution' => 'correcta'], $h)->assertStatus(403);
        $this->postJson('/api/warranty_docs.php?action=report', ['id' => $id], $h)->assertStatus(403);
        $this->getJson("/api/warranty_docs.php?action=finance&id={$id}", $h)->assertStatus(403);
        $this->postJson('/api/warranty_docs.php?action=replacements_save', ['id' => $id, 'items' => []], $h)->assertStatus(403);
        $this->postJson('/api/zkong_rma.php?action=create', [], $h)->assertStatus(403);
        $this->postJson('/api/warranties.php?action=delete', ['id' => $id], $h)->assertStatus(403);
        $this->getJson('/api/warranty_settings.php', $h)->assertStatus(403);
    }

    public function test_tecnico_revisa_pero_no_hace_documentos(): void
    {
        $h = $this->como('tecnico_garantias');
        $i = WarrantyItem::first();
        $this->postJson('/api/warranties.php?action=review', ['id' => $this->w->id, 'item_ids' => [$i->id], 'resolution' => 'correcta'], $h)->assertOk();
        $this->postJson('/api/warranty_docs.php?action=report', ['id' => $this->w->id], $h)->assertStatus(403);
        $this->postJson('/api/warranty_docs.php?action=replacements_ticket', ['id' => $this->w->id, 'email' => 'a@b.com'], $h)
            ->assertStatus(403)->assertJsonPath('error', 'No tienes permiso para crear el ticket de posiciones');
    }

    public function test_responsable_y_borrar_solo_con_su_permiso(): void
    {
        $h = $this->como('encargado_garantias');
        $p = $this->getJson('/api/warranties.php?action=options', $h)->json('perms');
        $this->assertTrue($p['documents'] && $p['positions_ticket'] && $p['config']);
        $this->assertFalse($p['delete']);
        $this->postJson('/api/warranties.php?action=delete', ['id' => $this->w->id], $h)->assertStatus(403);

        $h2 = $this->como('tecnico_garantias', ['warranties.delete']);
        $this->postJson('/api/warranties.php?action=delete', ['id' => $this->w->id], $h2)->assertOk();
        $this->assertNull(Warranty::find($this->w->id));
    }
}
