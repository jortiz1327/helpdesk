<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Aviso de tickets duplicados o relacionados: mismo cliente, o mismo hotel con un problema parecido. Solo lee, sin IA. */
class TicketRelatedTest extends TestCase
{
    use RefreshDatabase;

    private int $cat;
    private string $tok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = DB::table('ticket_categories')->insertGetId(['key' => 'a', 'name' => 'Etiquetas', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('user_ticket_categories')->insert(['user_id' => $u->id, 'category_id' => $this->cat]);
        $this->tok = TokenService::make($u);
    }

    private function contacto(string $nombre, ?string $empresa = null): int
    {
        return DB::table('contacts')->insertGetId(['name' => $nombre, 'email' => strtolower($nombre) . '@x.com', 'empresa' => $empresa]);
    }

    private function ticket(int $contacto, string $asunto, string $texto, int $dias = 0, ?int $cat = null): int
    {
        $id = DB::table('tickets')->insertGetId([
            'code' => 'TK-' . uniqid(), 'subject' => $asunto, 'status' => 'abierto', 'priority' => 'media', 'channel' => 'email',
            'contact_id' => $contacto, 'category_id' => $cat ?? $this->cat, 'opened_at' => now()->subDays($dias), 'last_message_at' => now(),
            'created_at' => now()->subDays($dias), 'updated_at' => now(),
        ]);
        DB::table('messages')->insert(['contact_id' => $contacto, 'wa_id' => '', 'ticket_id' => $id, 'direction' => 'in', 'body' => $texto, 'created_at' => now()->subDays($dias)]);
        return $id;
    }

    private function relacionados(int $id)
    {
        return $this->withHeader('X-App-Token', $this->tok)->getJson("/api/ticket_related.php?ticket=$id");
    }

    public function test_avisa_de_otro_ticket_del_mismo_cliente_y_marca_si_parece_el_mismo_problema(): void
    {
        $ana = $this->contacto('Marta', 'Hotel Sol');
        $nuevo = $this->ticket($ana, 'Antenas sin conexión', 'Las antenas del buffet siguen sin conexión desde ayer');
        $igual = $this->ticket($ana, 'Re: antenas', 'Las antenas del buffet no tienen conexión', 2);
        $otro = $this->ticket($ana, 'Cambiar logo', 'Queremos cambiar el logo del hotel', 3);

        $r = $this->relacionados($nuevo)->assertOk();
        $ids = array_column($r->json('related'), 'id');
        $this->assertSame([$igual, $otro], $ids, 'el parecido va primero');
        $this->assertTrue($r->json('related.0.similar'));
        $this->assertFalse($r->json('related.1.similar'));
        $this->assertSame('cliente', $r->json('related.0.relation'));
        $this->assertSame(2, $r->json('related.0.days'));
    }

    public function test_otra_persona_del_mismo_hotel_solo_cuenta_si_el_problema_se_parece(): void
    {
        $a = $this->contacto('Marta', 'Hotel Sol');
        $b = $this->contacto('Luis', ' hotel sol ');
        $nuevo = $this->ticket($a, 'Antenas sin conexión', 'Las antenas del buffet siguen sin conexión');
        $mismo = $this->ticket($b, 'Antenas caídas', 'Las antenas del buffet están sin conexión', 1);
        $this->ticket($b, 'Factura de marzo', 'Necesito la factura del pedido de marzo', 1);

        $r = $this->relacionados($nuevo)->assertOk();
        $this->assertSame([$mismo], array_column($r->json('related'), 'id'));
        $this->assertSame('empresa', $r->json('related.0.relation'));
    }

    public function test_no_avisa_de_tickets_viejos_ni_fusionados_ni_de_otra_area_que_no_ve(): void
    {
        $a = $this->contacto('Marta', 'Hotel Sol');
        $nuevo = $this->ticket($a, 'Antenas', 'Antenas sin conexión');
        $this->ticket($a, 'Antenas viejo', 'Antenas sin conexión', 40);
        $fus = $this->ticket($a, 'Antenas fusionado', 'Antenas sin conexión', 1);
        DB::table('tickets')->where('id', $fus)->update(['merged_into_id' => $nuevo]);
        $otraCat = DB::table('ticket_categories')->insertGetId(['key' => 'b', 'name' => 'Garantías', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $this->ticket($a, 'Antenas garantías', 'Antenas sin conexión', 1, $otraCat);

        $this->assertSame([], $this->relacionados($nuevo)->assertOk()->json('related'));
    }

    public function test_avisa_de_urgencia_segun_lo_ultimo_que_escribe_el_cliente_y_no_en_tickets_cerrados(): void
    {
        $a = $this->contacto('Marta', 'Hotel Sol');
        $tk = $this->ticket($a, 'Buffet', 'Ninguna etiqueta del buffet funciona y el desayuno es en una hora');
        $r = $this->relacionados($tk)->assertOk();
        $this->assertSame('alta', $r->json('urgency.level'));
        $normal = $this->ticket($a, 'Usuario', 'Hola, ¿cómo añado un usuario nuevo?');
        $this->assertNull($this->relacionados($normal)->json('urgency'));
        DB::table('tickets')->where('id', $tk)->update(['status' => 'cerrado']);
        $this->assertNull($this->relacionados($tk)->json('urgency'));
    }
    public function test_un_ticket_que_no_ves_da_404(): void
    {
        $a = $this->contacto('Marta');
        $otraCat = DB::table('ticket_categories')->insertGetId(['key' => 'b', 'name' => 'Garantías', 'color' => '#888', 'position' => 2, 'active' => 1]);
        $ajeno = $this->ticket($a, 'Algo', 'Algo', 0, $otraCat);
        $this->relacionados($ajeno)->assertNotFound();
    }
    public function test_no_hace_una_consulta_por_candidato_y_frena_el_abuso(): void
    {
        $c = $this->contacto('Marta', 'Hotel Sol');
        $id = $this->ticket($c, 'Etiqueta no cambia', 'La etiqueta del plato no cambia');
        foreach (range(1, 12) as $i) {
            $this->ticket($c, "Otro $i", "Otro problema $i");
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->relacionados($id)->assertOk();
        $mensajes = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "messages"') || str_contains($q['query'], 'from `messages`'))->count();
        $this->assertLessThanOrEqual(4, $mensajes, 'los primeros mensajes se piden en bloque, no uno por ticket');
        foreach (range(1, 70) as $i) {
            $r = $this->relacionados($id);
        }
        $r->assertStatus(429);
    }
}
