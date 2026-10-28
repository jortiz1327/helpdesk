<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatService;
use App\Services\TicketService;
use App\Services\TokenService;
use App\Support\ContactArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Área de cada ficha (Soporte / Campañas) guardada en la ficha: se rellena con la misma
 * regla que se deducía antes (audiencias idénticas), se marca sola con la actividad, y
 * cada área solo ve y toca sus fichas.
 */
class ContactAreaTest extends TestCase
{
    use RefreshDatabase;

    private function tokenCon(array $permisos): string
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'U', 'email' => uniqid() . '@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    private function ticket(int $cid): int
    {
        return DB::table('tickets')->insertGetId([
            'code' => 'TK-' . $cid, 'subject' => 'x', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function msg(int $cid, string $funcion): void
    {
        DB::table('messages')->insert(['contact_id' => $cid, 'wa_id' => '1', 'direction' => 'in', 'channel' => 'whatsapp',
            'funcion' => $funcion, 'type' => 'text', 'body' => 'x', 'status' => 'received', 'created_at' => now()]);
    }

    private function contacto(array $datos, array $area = []): int
    {
        return DB::table('contacts')->insertGetId($datos + $area);
    }

    public function test_el_relleno_da_la_misma_audiencia_que_la_regla_antigua(): void
    {
        $lab = DB::table('labels')->insertGetId(['name' => 'L', 'color' => '#000', 'position' => 1]);
        $chat51   = $this->contacto(['wa_id' => '34600000001']); $this->msg($chat51, 'campanas');
        $etiqueta = $this->contacto(['email' => 'e@x.com']); DB::table('contact_labels')->insert(['contact_id' => $etiqueta, 'label_id' => $lab]);
        $soloTel  = $this->contacto(['wa_id' => '34600000002']);
        $telTk    = $this->contacto(['wa_id' => '34600000003']); $this->ticket($telTk);
        $tel01    = $this->contacto(['wa_id' => '34600000004']); $this->msg($tel01, 'soporte');
        $correo   = $this->contacto(['email' => 'c@x.com']);
        $correoTk = $this->contacto(['email' => 'd@x.com']); $this->ticket($correoTk);
        $mixto    = $this->contacto(['wa_id' => '34600000005']); $this->ticket($mixto); $this->msg($mixto, 'campanas');

        $antes = DB::table('contacts as c')->whereRaw(ContactArea::SQL_CAMPANAS_DEDUCIDO)->orderBy('c.id')->pluck('c.id')->all();
        ContactArea::recalcular();
        $ahora = DB::table('contacts as c')->whereRaw(ContactArea::SQL_CAMPANAS)->orderBy('c.id')->pluck('c.id')->all();

        $this->assertSame($antes, $ahora);
        $this->assertEqualsCanonicalizing([$chat51, $etiqueta, $soloTel, $mixto], $ahora);
        $this->assertEqualsCanonicalizing([$telTk, $tel01, $correoTk, $mixto],
            DB::table('contacts')->where('en_soporte', 1)->pluck('id')->all());
    }

    public function test_borrar_el_ultimo_ticket_no_lo_pasa_a_campanas(): void
    {
        $cid = $this->contacto(['wa_id' => '34600000003']);
        app(TicketService::class)->create(['subject' => 'x', 'channel' => 'email', 'contact_id' => $cid], notify: false);
        DB::table('tickets')->where('contact_id', $cid)->delete();

        $this->assertFalse(DB::table('contacts as c')->where('c.id', $cid)->whereRaw(ContactArea::SQL_CAMPANAS)->exists());
        $this->assertSame(1, (int) DB::table('contacts')->where('id', $cid)->value('en_soporte'));
    }

    public function test_la_actividad_marca_el_area(): void
    {
        $a = $this->contacto(['wa_id' => '34611111111']);
        ChatService::storeMessage($a, '34611111111', 'in', 'text', 'hola', ['funcion' => 'soporte']);
        $b = $this->contacto(['wa_id' => '34622222222']);
        ChatService::storeMessage($b, '34622222222', 'in', 'text', 'hola');   // WhatsApp sin más = Campañas
        $c = $this->contacto(['email' => 'z@x.com']);
        ChatService::storeMessage($c, '', 'in', 'text', 'hola', ['channel' => 'email']);   // correo suelto: sin área

        $this->assertSame([1, 0], [(int) DB::table('contacts')->where('id', $a)->value('en_soporte'), (int) DB::table('contacts')->where('id', $a)->value('en_campanas')]);
        $this->assertSame([0, 1], [(int) DB::table('contacts')->where('id', $b)->value('en_soporte'), (int) DB::table('contacts')->where('id', $b)->value('en_campanas')]);
        $this->assertSame([0, 0], [(int) DB::table('contacts')->where('id', $c)->value('en_soporte'), (int) DB::table('contacts')->where('id', $c)->value('en_campanas')]);
    }

    public function test_alta_a_mano_con_telefono_es_de_campanas(): void
    {
        $token = $this->tokenCon(['contacts.access', 'contacts.edit', 'campaigns.access']);
        $id = $this->withHeader('X-App-Token', $token)->postJson('/api/contact.php?action=create', [
            'name' => 'N', 'country_code' => '34', 'phone' => '633444555',
        ])->assertOk()->json('id');
        $this->assertSame(1, (int) DB::table('contacts')->where('id', $id)->value('en_campanas'));
    }

    public function test_campanas_no_ve_ni_toca_una_ficha_solo_de_soporte(): void
    {
        $cid = $this->contacto(['wa_id' => '34600000003', 'name' => 'Cliente'], ['en_soporte' => 1]);
        $token = $this->tokenCon(['contacts.access', 'contacts.edit', 'campaigns.access']);

        $this->withHeader('X-App-Token', $token)->postJson('/api/contact.php?action=save', ['contact_id' => $cid, 'name' => 'Otro'])->assertStatus(403);
        $this->withHeader('X-App-Token', $token)->postJson('/api/contacts.php?action=bulk_label', ['contact_ids' => [$cid], 'label_id' => 1])->assertStatus(403);
        $this->withHeader('X-App-Token', $token)->getJson('/api/conversations.php?action=messages&contact_id=' . $cid)->assertStatus(404);
        $this->assertSame('Cliente', DB::table('contacts')->where('id', $cid)->value('name'));
    }

    public function test_soporte_no_toca_una_ficha_solo_de_campanas(): void
    {
        $cid = $this->contacto(['wa_id' => '34600000009', 'name' => 'Lead'], ['en_campanas' => 1]);
        $token = $this->tokenCon(['contacts.access', 'contacts.edit', 'helpdesk.access']);

        $this->withHeader('X-App-Token', $token)->postJson('/api/contact.php?action=save', ['contact_id' => $cid, 'name' => 'Otro'])->assertStatus(403);
        $this->withHeader('X-App-Token', $token)->postJson('/api/contacts.php?action=set_optout', ['contact_ids' => [$cid], 'value' => 1])->assertStatus(403);
        $this->assertSame(0, (int) DB::table('contacts')->where('id', $cid)->value('opted_out'));
    }

    public function test_en_una_ficha_de_las_dos_areas_campanas_edita_datos_pero_no_la_identidad(): void
    {
        $cid = $this->contacto(['wa_id' => '34600000007', 'name' => 'Ambos'], ['en_soporte' => 1, 'en_campanas' => 1]);
        $token = $this->tokenCon(['contacts.access', 'contacts.edit', 'campaigns.access']);

        $this->withHeader('X-App-Token', $token)->postJson('/api/contact.php?action=save', ['contact_id' => $cid, 'empresa' => 'ACME'])->assertOk();
        $this->withHeader('X-App-Token', $token)->postJson('/api/contact.php?action=save', ['contact_id' => $cid, 'email' => 'mio@x.com'])->assertStatus(403);
        $this->withHeader('X-App-Token', $token)->postJson('/api/contact.php?action=save', ['contact_id' => $cid, 'country_code' => '34', 'phone' => '699000111'])->assertStatus(403);

        $c = DB::table('contacts')->where('id', $cid)->first();
        $this->assertSame(['ACME', null, '34600000007'], [$c->empresa, $c->email, $c->wa_id]);
    }

    public function test_campanas_no_fusiona_una_ficha_de_soporte(): void
    {
        $sop  = $this->contacto(['email' => 's@x.com'], ['en_soporte' => 1, 'en_campanas' => 1]);
        $camp = $this->contacto(['wa_id' => '34600000008'], ['en_campanas' => 1]);
        $token = $this->tokenCon(['contacts.access', 'contacts.edit', 'campaigns.access']);

        $this->withHeader('X-App-Token', $token)->postJson('/api/contacts.php?action=merge', ['keep_id' => $sop, 'merge_id' => $camp])->assertStatus(403);
        $this->assertSame(2, DB::table('contacts')->count());
    }

    public function test_la_lista_de_cada_area_sale_de_la_marca(): void
    {
        $sop  = $this->contacto(['email' => 's@x.com'], ['en_soporte' => 1]);
        $camp = $this->contacto(['wa_id' => '34600000010'], ['en_campanas' => 1]);

        $tk = $this->tokenCon(['contacts.access', 'helpdesk.access']);
        $ids = collect($this->withHeader('X-App-Token', $tk)->getJson('/api/contacts.php')->assertOk()->json('contacts'))->pluck('id')->all();
        $this->assertSame([$sop], $ids);

        $tk = $this->tokenCon(['contacts.access', 'campaigns.access']);
        $ids = collect($this->withHeader('X-App-Token', $tk)->getJson('/api/contacts.php')->assertOk()->json('contacts'))->pluck('id')->all();
        $this->assertSame([$camp], $ids);
    }
}
