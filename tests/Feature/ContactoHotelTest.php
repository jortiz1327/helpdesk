<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ContactMergeService;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** «Hotel recordado»: lo que enseña la ficha del ticket y cómo viaja la sede (grupo › marca › sede) al fusionar contactos duplicados. */
class ContactoHotelTest extends TestCase
{
    use RefreshDatabase;

    private function sede(string $nombre, string $marca = 'Allegro', string $grupo = 'Grupo Barceló'): int
    {
        $g = DB::table('grupos')->where('name', $grupo)->value('id') ?: DB::table('grupos')->insertGetId(['name' => $grupo, 'color' => '#111', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m = DB::table('marcas')->where('name', $marca)->value('id') ?: DB::table('marcas')->insertGetId(['grupo_id' => $g, 'name' => $marca, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('sedes')->insertGetId(['marca_id' => $m, 'name' => $nombre, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function contacto(string $email, ?int $sede = null, $cuando = null, ?string $por = null): int
    {
        return DB::table('contacts')->insertGetId(['name' => 'C ' . $email, 'email' => $email, 'sede_id' => $sede, 'sede_verificada_at' => $cuando, 'sede_verificada_por' => $por]);
    }

    public function test_al_fusionar_la_sede_viaja_con_su_confirmacion(): void
    {
        $madrid = $this->sede('Allegro Madrid');
        $sevilla = $this->sede('Allegro Sevilla');
        $svc = app(ContactMergeService::class);

        // El principal no tiene sede: hereda la del absorbido, con su fecha y su origen.
        $keep = $this->contacto('a@x.com');
        $otro = $this->contacto('b@x.com', $madrid, now()->subDays(5), 'correo');
        $svc->fusionar($keep, $otro);
        $c = DB::table('contacts')->where('id', $keep)->first();
        $this->assertSame($madrid, (int) $c->sede_id);
        $this->assertSame('correo', $c->sede_verificada_por);
        $this->assertNotNull($c->sede_verificada_at);

        // Las dos tienen sede distinta: gana la confirmada más recientemente.
        $keep2 = $this->contacto('c@x.com', $madrid, now()->subMonths(8), 'correo');
        $otro2 = $this->contacto('d@x.com', $sevilla, now()->subDays(2), 'agente');
        $svc->fusionar($keep2, $otro2);
        $c = DB::table('contacts')->where('id', $keep2)->first();
        $this->assertSame($sevilla, (int) $c->sede_id);
        $this->assertSame('agente', $c->sede_verificada_por);

        // Si el principal tiene la más reciente, se queda con la suya.
        $keep3 = $this->contacto('e@x.com', $madrid, now()->subDays(1), 'agente');
        $otro3 = $this->contacto('f@x.com', $sevilla, now()->subMonths(3), 'correo');
        $svc->fusionar($keep3, $otro3);
        $this->assertSame($madrid, (int) DB::table('contacts')->where('id', $keep3)->value('sede_id'));
    }

    public function test_la_ficha_del_ticket_dice_que_hotel_se_recuerda_y_si_caduco(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $cat = DB::table('ticket_categories')->insertGetId(['key' => 'a', 'name' => 'Etiquetas', 'color' => '#888', 'position' => 1, 'active' => 1]);
        $u = User::create(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => bcrypt('x')]);
        $u->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('user_ticket_categories')->insert(['user_id' => $u->id, 'category_id' => $cat]);
        $tok = TokenService::make($u);

        $sede = $this->sede('Allegro Madrid');
        $c = $this->contacto('m@x.com', $sede, now()->subDays(10), 'correo');
        $tk = DB::table('tickets')->insertGetId([
            'code' => 'TK-' . uniqid(), 'subject' => 'x', 'status' => 'abierto', 'priority' => 'media', 'channel' => 'email',
            'contact_id' => $c, 'category_id' => $cat, 'opened_at' => now(), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = $this->withHeader('X-App-Token', $tok)->getJson("/api/tickets.php?action=detail&id=$tk")->assertOk();
        $h = $r->json('ticket.hotel_recordado');
        $this->assertSame('Allegro Madrid', $h['sede']);
        $this->assertSame('Allegro', $h['marca']);
        $this->assertSame('Grupo Barceló', $h['grupo']);
        $this->assertSame('correo', $h['por']);
        $this->assertTrue($h['vigente']);

        DB::table('contacts')->where('id', $c)->update(['sede_verificada_at' => now()->subMonths(13)]);
        $r = $this->withHeader('X-App-Token', $tok)->getJson("/api/tickets.php?action=detail&id=$tk")->assertOk();
        $this->assertFalse($r->json('ticket.hotel_recordado.vigente'));

        DB::table('contacts')->where('id', $c)->update(['sede_id' => null]);
        $r = $this->withHeader('X-App-Token', $tok)->getJson("/api/tickets.php?action=detail&id=$tk")->assertOk();
        $this->assertNull($r->json('ticket.hotel_recordado'));
    }
}
