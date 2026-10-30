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
 * Expedientes de garantía: numeración, historial automático de cambios, seguimiento,
 * filtros rápidos y permisos.
 */
class WarrantiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    }

    private function agente(array $permisos = ['warranties.access'], string $name = 'Ana'): array
    {
        $u = User::create(['name' => $name, 'email' => uniqid() . '@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return [$u, ['X-App-Token' => TokenService::make($u)]];
    }

    private function crear(array $h, array $datos = []): array
    {
        return $this->postJson('/api/warranties.php?action=save', $datos + [
            'received_at' => '2026-10-20', 'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro',
            'category' => 'Hoteles', 'urgency' => 'media', 'status' => 'no_iniciado',
        ], $h)->assertOk()->json();
    }

    public function test_sin_permiso_no_entra(): void
    {
        [, $h] = $this->agente(['helpdesk.access']);
        $this->getJson('/api/warranties.php', $h)->assertForbidden();
    }

    public function test_numeracion_por_anio(): void
    {
        [, $h] = $this->agente();
        $a = $this->crear($h);
        $b = $this->crear($h);
        $y = now()->year;
        $this->assertSame("G-{$y}-0001", $a['code']);
        $this->assertSame("G-{$y}-0002", $b['code']);
    }

    public function test_editar_deja_historial_legible_y_cerrar_pone_fecha(): void
    {
        [$ana, $h] = $this->agente();
        [$luis] = $this->agente(['warranties.access'], 'Luis');
        $id = $this->crear($h)['id'];

        $this->postJson('/api/warranties.php?action=save', [
            'id' => $id, 'received_at' => '2026-10-20', 'client_name' => 'Hoteles Sol', 'project_name' => 'Sol Centro',
            'category' => 'Hoteles', 'urgency' => 'muy_urgente', 'status' => 'cerrada', 'responsible_id' => $luis->id,
        ], $h)->assertOk();

        $w = Warranty::find($id);
        $this->assertNotNull($w->closed_at);

        $r = $this->getJson("/api/warranties.php?action=get&id={$id}", $h)->assertOk()->json();
        $ev = collect($r['events'])->keyBy('field');
        $this->assertSame(['Media', 'Muy urgente'], [$ev['urgency']['from_value'], $ev['urgency']['to_value']]);
        $this->assertSame(['No iniciado', 'Cerrada'], [$ev['status']['from_value'], $ev['status']['to_value']]);
        $this->assertSame('Luis', $ev['responsible_id']['to_value']);
        $this->assertFalse($ev->has('client_name'));   // lo que no cambia no se apunta
        $this->assertTrue($ev->has('created'));

        // Reabrir quita la fecha de cierre.
        $this->postJson('/api/warranties.php?action=save', ['id' => $id, 'received_at' => '2026-10-20', 'status' => 'en_curso'], $h)->assertOk();
        $this->assertNull(Warranty::find($id)->closed_at);
    }

    public function test_responsable_debe_tener_acceso_y_ticket_debe_existir(): void
    {
        [, $h] = $this->agente();
        [$otro] = $this->agente(['helpdesk.access'], 'Pepe');
        $this->postJson('/api/warranties.php?action=save', ['received_at' => '2026-10-20', 'responsible_id' => $otro->id], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=save', ['received_at' => '2026-10-20', 'ticket_code' => 'TK-NO-EXISTE'], $h)->assertStatus(400);
        $this->postJson('/api/warranties.php?action=save', ['received_at' => ''], $h)->assertStatus(400);
    }

    public function test_seguimiento_y_filtros_rapidos(): void
    {
        [$ana, $h] = $this->agente();
        $a = $this->crear($h, ['responsible_id' => $ana->id, 'urgency' => 'alta']);
        $b = $this->crear($h, ['status' => 'pte_cliente', 'client_name' => '']);
        $c = $this->crear($h, ['status' => 'cerrada']);
        // b lleva un mes sin moverse.
        Warranty::whereKey($b['id'])->update(['last_activity_at' => now()->subDays(30)]);

        $this->postJson('/api/warranties.php?action=note', ['id' => $a['id'], 'type' => 'llamada', 'body' => 'Llamado al cliente'], $h)->assertOk();
        $this->postJson('/api/warranties.php?action=note', ['id' => $a['id'], 'body' => '  '], $h)->assertStatus(400);
        $notes = $this->getJson("/api/warranties.php?action=get&id={$a['id']}", $h)->json('notes');
        $this->assertSame([['llamada', 'Llamado al cliente', 'Ana']], collect($notes)->map(fn ($n) => [$n['type'], $n['body'], $n['user_name']])->all());

        $ids = fn (string $chip) => collect($this->getJson("/api/warranties.php?chip={$chip}", $h)->json('rows'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$a['id']], $ids('mine'));
        $this->assertSame([$a['id']], $ids('urgent'));
        $this->assertSame([$a['id'], $b['id']], $ids('open'));
        $this->assertSame([$b['id']], $ids('pte_cliente'));
        $this->assertSame([$b['id']], $ids('stale'));
        $this->assertSame([$b['id']], $ids('no_client'));
        $this->assertSame([$c['id']], $ids('closed'));

        $r = $this->getJson('/api/warranties.php?q=' . urlencode($a['code']), $h)->json();
        $this->assertSame(1, $r['total']);
        $this->assertSame(3, $r['all']);
    }

    public function test_bandeja_de_garantias_solo_su_categoria_y_garantia_enlazada_al_ticket(): void
    {
        $catGar = DB::table('ticket_categories')->insertGetId(['key' => 'garantias_t', 'name' => 'Garantías', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $catSop = DB::table('ticket_categories')->insertGetId(['key' => 'soporte_t', 'name' => 'Soporte', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $ticket = function (?int $cat, string $code) {
            $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c' . uniqid() . '@x.com']);
            return DB::table('tickets')->insertGetId([
                'code' => $code, 'subject' => 'Etiquetas sin pantalla', 'status' => 'abierto', 'priority' => 'media',
                'channel' => 'email', 'contact_id' => $cid, 'category_id' => $cat,
                'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $g = $ticket($catGar, 'TK-GAR-1');
        $ticket($catSop, 'TK-SOP-1');
        $ticket(null, 'TK-SIN-1');

        $super = User::create(['name' => 'Root', 'email' => 'root@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $super->assignRole(config('rbac.super_role'));
        $h = ['X-App-Token' => TokenService::make($super)];

        // Estricta: solo la categoría pedida, sin los «sin categoría».
        $codes = fn (string $qs) => collect($this->getJson("/api/tickets.php?action=list&status=all&{$qs}", $h)->assertOk()->json('tickets'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['TK-GAR-1'], $codes("category={$catGar}&category_strict=1"));
        $this->assertSame([], $codes('category=0&category_strict=1'));            // sin categoría válida, nada
        $this->assertSame(['TK-GAR-1', 'TK-SIN-1'], $codes("category={$catGar}"));   // Soporte sigue igual

        // Garantía creada a mano desde el ticket: queda enlazada.
        $w = $this->crear($h, ['ticket_code' => 'TK-GAR-1']);
        $r = $this->getJson("/api/warranties.php?action=by_ticket&ticket_id={$g}", $h)->assertOk()->json('warranties');
        $this->assertSame([$w['code']], array_column($r, 'code'));
    }

    public function test_solo_superadmin_borra(): void
    {
        [, $h] = $this->agente();
        $id = $this->crear($h)['id'];
        $this->postJson('/api/warranties.php?action=delete', ['id' => $id], $h)->assertForbidden();

        $super = User::create(['name' => 'Root', 'email' => 'root@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $super->assignRole(config('rbac.super_role'));
        $this->postJson('/api/warranties.php?action=delete', ['id' => $id], ['X-App-Token' => TokenService::make($super)])->assertOk();
        $this->assertSame(0, Warranty::count());
        $this->assertSame(0, DB::table('warranty_events')->count());
    }
}
