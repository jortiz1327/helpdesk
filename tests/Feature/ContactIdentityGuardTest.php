<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TokenService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Nadie puede quedarse con la ficha (tickets, WhatsApp, portal) de otro cliente tecleando
 * su teléfono o poniendo su correo: ni el portal público, ni el alta de un agente, ni
 * Campañas (convertir chat en ticket, importar contactos). Y el bot de Campañas no
 * escribe en tickets de Soporte. Auditoría 2026-10-28 (C1, A1-A4).
 */
class ContactIdentityGuardTest extends TestCase
{
    use RefreshDatabase;

    private function tokenCon(array $permisos): string
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $u = User::create(['name' => 'U', 'email' => 'u@x.com', 'password' => bcrypt('x')]);
        $u->givePermissionTo($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return TokenService::make($u);
    }

    /** Cliente de WhatsApp (solo teléfono) con un ticket de soporte. */
    private function victima(): array
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Víctima', 'wa_id' => '34600111222']);
        $tid = DB::table('tickets')->insertGetId([
            'code' => 'TK-1', 'subject' => 'Mi avería', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'whatsapp', 'contact_id' => $cid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$cid, $tid];
    }

    public function test_el_portal_no_fusiona_la_ficha_de_un_telefono_ajeno(): void
    {
        [$cid, $tid] = $this->victima();

        $this->postJson('/api/portal.php?action=create', [
            'email' => 'atacante@x.com', 'phone' => '+34 600 111 222',
            'subject' => 'Hola', 'body' => 'quiero ver tickets ajenos',
        ])->assertOk();

        $this->assertTrue(DB::table('contacts')->where('id', $cid)->where('wa_id', '34600111222')->exists());
        $this->assertSame($cid, (int) DB::table('tickets')->where('id', $tid)->value('contact_id'));
        $this->assertNull(DB::table('contacts')->where('email', 'atacante@x.com')->value('wa_id'));
    }

    public function test_el_alta_de_un_agente_no_fusiona_y_avisa(): void
    {
        [$cid, $tid] = $this->victima();
        $token = $this->tokenCon(['helpdesk.access', 'tickets.create']);

        $r = $this->withHeader('X-App-Token', $token)->post('/api/tickets.php?action=create', [
            'name' => 'Otro', 'email' => 'otro@x.com', 'phone' => '600111222',
            'subject' => 'Alta', 'description' => '<p>texto</p>',
        ])->assertOk()->json();

        $this->assertNotEmpty($r['warnings']);
        $this->assertTrue(DB::table('contacts')->where('id', $cid)->exists());
        $this->assertSame($cid, (int) DB::table('tickets')->where('id', $tid)->value('contact_id'));
    }

    public function test_el_alta_de_un_agente_pone_un_telefono_libre(): void
    {
        $token = $this->tokenCon(['helpdesk.access', 'tickets.create']);

        $r = $this->withHeader('X-App-Token', $token)->post('/api/tickets.php?action=create', [
            'name' => 'Nuevo', 'email' => 'nuevo@x.com', 'phone' => '600999888',
            'subject' => 'Alta', 'description' => '<p>texto</p>',
        ])->assertOk()->json();

        $this->assertSame([], $r['warnings']);
        $this->assertSame('34600999888', DB::table('contacts')->where('email', 'nuevo@x.com')->value('wa_id'));
    }

    public function test_campanas_no_pone_su_correo_a_un_cliente_de_soporte(): void
    {
        [$cid] = $this->victima();
        $token = $this->tokenCon(['campaigns.access']);
        $pedir = fn () => $this->withHeader('X-App-Token', $token)->postJson('/api/conversations.php?action=to_ticket', [
            'contact_id' => $cid, 'email' => 'campanas@x.com', 'subject' => 'Escalado',
        ]);

        // Solo de Soporte: el Chat en vivo ni lo encuentra.
        DB::table('contacts')->where('id', $cid)->update(['en_soporte' => 1]);
        $pedir()->assertStatus(404);
        // De las dos áreas (también habló con el 51): se encuentra, pero el correo no se pone.
        DB::table('contacts')->where('id', $cid)->update(['en_campanas' => 1]);
        $pedir()->assertStatus(409);

        $this->assertNull(DB::table('contacts')->where('id', $cid)->value('email'));
        $this->assertSame(1, DB::table('tickets')->count());
    }

    public function test_campanas_si_convierte_un_chat_sin_historial_de_soporte(): void
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Lead', 'wa_id' => '34600555444', 'en_campanas' => 1]);
        $token = $this->tokenCon(['campaigns.access']);

        $this->withHeader('X-App-Token', $token)->postJson('/api/conversations.php?action=to_ticket', [
            'contact_id' => $cid, 'email' => 'lead@x.com', 'subject' => 'Escalado',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('lead@x.com', DB::table('contacts')->where('id', $cid)->value('email'));
    }

    public function test_importar_no_toca_ni_fusiona_clientes_con_tickets(): void
    {
        [$cid] = $this->victima();
        $soloCorreo = DB::table('contacts')->insertGetId(['name' => 'Correo', 'email' => 'imp@x.com']);
        $token = $this->tokenCon(['contacts.access', 'contacts.edit']);

        $csv = UploadedFile::fake()->createWithContent('c.csv', "Nombre,Email,Telefono\nX,imp@x.com,600111222\n");
        $r = $this->withHeader('X-App-Token', $token)
            ->post('/api/contact.php?action=import', ['file' => $csv])->assertOk()->json();

        $this->assertSame(1, $r['protected']);
        $this->assertTrue(DB::table('contacts')->where('id', $cid)->exists());
        $this->assertNull(DB::table('contacts')->where('id', $cid)->value('email'));
        $this->assertTrue(DB::table('contacts')->where('id', $soloCorreo)->exists());
    }

    public function test_el_bot_de_campanas_no_escribe_en_tickets(): void
    {
        [$cid, $tid] = $this->victima();
        DB::table('tickets')->where('id', $tid)->update(['last_direction' => 'in']);
        $this->mock(WhatsAppService::class, function ($m) {
            $m->shouldReceive('sendText')->andReturn([200, ['messages' => [['id' => 'wamid.bot']]]]);
        });
        $flow = DB::table('flows')->insertGetId(['name' => 'F', 'active' => 1, 'graph' => json_encode([
            'nodes' => [['id' => 'n1', 'data' => ['type' => 'send_message', 'message' => 'Hola desde el bot']]],
            'edges' => [],
        ])]);
        DB::table('flow_sessions')->insert([
            'contact_id' => $cid, 'flow_id' => $flow, 'current_node' => 'n1', 'variables' => '{}',
            'status' => 'waiting', 'resume_at' => now()->subMinute(),
        ]);

        $this->artisan('flow:tick')->assertSuccessful();

        $msg = DB::table('messages')->where('wamid', 'wamid.bot')->first();
        $this->assertNotNull($msg);
        $this->assertNull($msg->ticket_id);
        $this->assertSame('in', DB::table('tickets')->where('id', $tid)->value('last_direction'));
    }
}
