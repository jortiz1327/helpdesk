<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Models\ZkongServer;
use App\Services\TokenService;
use App\Services\Zkong\ZkongDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sustituciones: etiqueta antigua → posición (artículo en ZKONG) → etiqueta nueva, y en
 * hoteles el ticket de posiciones a nombre del correo del cliente, sin escribirle.
 */
class WarrantyReplacementsTest extends TestCase
{
    use RefreshDatabase;

    private array $h;
    private Warranty $w;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Warranty::forgetStatuses();
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x'), 'active' => 1]);
        $u->givePermissionTo([...self::GARANTIAS, 'warranties.config']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->h = ['X-App-Token' => TokenService::make($u)];
        $this->w = Warranty::create(['code' => 'G-2026-0007', 'received_at' => '2026-10-01', 'status' => 'en_curso',
            'client_name' => 'Invisa', 'project_name' => 'Hotel Cala Blanca', 'category' => 'Hoteles']);
    }

    private function item(string $code, string $res, array $d = []): WarrantyItem
    {
        return WarrantyItem::create($d + ['warranty_id' => $this->w->id, 'code' => $code, 'kind' => 'esl', 'lookup_state' => 'found',
            'aeme_ref' => 'AMC26V', 'resolution' => $res, 'coverage' => $res === 'danada' ? 'cliente' : 'garantia']);
    }

    private function docs(string $action, array $d = [])
    {
        return $this->postJson("/api/warranty_docs.php?action={$action}", ['id' => $this->w->id] + $d, $this->h);
    }

    public function test_apuntar_posiciones_y_etiquetas_nuevas(): void
    {
        $a = $this->item('802556445', 'defectuosa');
        $b = $this->item('802556446', 'danada');
        $this->item('802556447', 'no_reproduce');   // se devuelve la misma: no entra

        $r = $this->getJson("/api/warranty_docs.php?action=replacements&id={$this->w->id}", $this->h)->assertOk()->json();
        $this->assertSame(['802556445', '802556446'], array_column($r['items'], 'code'));
        $this->assertTrue($r['asks_ticket']);

        $fila = fn ($i, $nueva, $pos = 'SC-02') => ['id' => $i->id, 'position' => $pos, 'replaced_by' => $nueva];
        $this->docs('replacements_save', ['items' => [$fila($a, 'AABBCCDDEEFF')]])->assertStatus(400);   // una antena para una etiqueta
        $this->docs('replacements_save', ['items' => [$fila($a, '802556445')]])->assertStatus(400);      // la misma
        $this->docs('replacements_save', ['items' => [$fila($a, '8E9749440'), $fila($b, '8e9749440', 'SC-03')]])->assertStatus(400); // repetida
        $this->docs('replacements_save', ['items' => [$fila($a, ' 8e9749440 '), $fila($b, '', 'SC-03')]])->assertOk()->assertJsonPath('changed', 2);
        $this->assertSame(['SC-02', '8E9749440'], [$a->fresh()->position, $a->fresh()->replaced_by]);
        $this->assertSame(['SC-03', null], [$b->fresh()->position, $b->fresh()->replaced_by]);
        $this->assertStringContainsString('SC-02 802556445 → 8E9749440', DB::table('warranty_events')->latest('id')->value('to_value'));

        // Un dispositivo de otra garantía no se toca.
        $otra = Warranty::create(['code' => 'G-2026-0008', 'received_at' => '2026-10-01', 'status' => 'en_curso', 'client_name' => 'X']);
        $ajeno = WarrantyItem::create(['warranty_id' => $otra->id, 'code' => '802556448', 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'defectuosa']);
        $this->docs('replacements_save', ['items' => [$fila($ajeno, '8E9749441')]])->assertStatus(400);

        // Solo las categorías elegidas en Configuración ofrecen el ticket.
        $this->postJson('/api/warranty_settings.php?action=replacements_save', ['categories' => ['retail'], 'subject' => 'Posiciones {codigo}', 'intro' => 'Hola'], $this->h)->assertOk();
        $this->assertFalse($this->getJson("/api/warranty_docs.php?action=replacements&id={$this->w->id}", $this->h)->json('asks_ticket'));
        $this->postJson('/api/warranty_settings.php?action=replacements_save', ['categories' => ['No existe'], 'subject' => 'x'], $this->h)->assertStatus(400);
    }

    public function test_ticket_de_posiciones_sin_escribir_al_cliente(): void
    {
        $a = $this->item('802556445', 'defectuosa', ['position' => 'SC-02', 'position_title' => 'Habitación 102']);
        $this->docs('replacements_ticket', ['email' => 'recepcion@hotel.com'])->assertStatus(400)->assertJsonPath('error', 'Apunta primero las etiquetas nuevas');
        $a->update(['replaced_by' => '8E9749440']);
        $this->docs('replacements_ticket', ['email' => 'no-es-correo'])->assertStatus(400);

        $r = $this->docs('replacements_ticket', ['email' => 'Recepcion@Hotel.com'])->assertOk()->json();
        $t = DB::table('tickets')->find($r['id']);
        $this->assertSame('Nuevas etiquetas para Hotel Cala Blanca (G-2026-0007)', $t->subject);
        $this->assertSame('recepcion@hotel.com', DB::table('contacts')->where('id', $t->contact_id)->value('email'));
        $body = DB::table('messages')->where('ticket_id', $t->id)->value('body');
        foreach (['SC-02', 'Habitación 102', '802556445', '8E9749440'] as $x) $this->assertStringContainsString($x, $body);
        $this->assertSame($t->id, $this->w->fresh()->positions_ticket_id);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Una vez por garantía.
        $this->docs('replacements_ticket', ['email' => 'recepcion@hotel.com'])->assertStatus(400);
        $this->assertSame($r['code'], $this->getJson("/api/warranty_docs.php?action=replacements&id={$this->w->id}", $this->h)->json('positions_ticket.code'));
    }

    public function test_posicion_desde_zkong(): void
    {
        $s = ZkongServer::create(['name' => 'app01', 'base_url' => 'https://zk.test/zk', 'username' => 'a', 'password' => 'b', 'active' => true]);
        $this->item('802556445', 'defectuosa');
        $this->item('802556446', 'desechada', ['position' => 'A MANO']);   // lo apuntado manda
        $this->item('802556447', 'defectuosa');                              // sin artículo vinculado

        $this->app->instance(ZkongDevices::class, new class($s->id) extends ZkongDevices {
            public function __construct(private int $sid) {}
            public function labels(array $codes, ?int $userId = null): array
            {
                return ['labels' => collect($codes)->mapWithKeys(fn ($c) => [$c => ['rows' => [], 'live' => ['server_id' => $this->sid, 'storeId' => 7, 'barCode' => $c]]])->all(), 'failed' => []];
            }
            public function items($s, array $items): array
            {
                return ['802556445' => ['itemTitle' => 'Habitación 102', 'itemBarCode' => 'SC-02']];
            }
        });

        $this->docs('replacements_lookup')->assertOk()->assertJsonPath('found', 1)->assertJsonPath('missing', 1);
        $i = WarrantyItem::where('warranty_id', $this->w->id)->orderBy('id')->get();
        $this->assertSame(['SC-02', 'Habitación 102'], [$i[0]->position, $i[0]->position_title]);
        $this->assertSame('A MANO', $i[1]->position);
        $this->assertNull($i[2]->position);
    }
}
