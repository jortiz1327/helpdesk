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

    public function test_un_usuario_de_varios_hoteles_de_una_marca_se_enlaza_a_la_marca(): void
    {
        $madrid = $this->sede('Allegro Madrid');
        $this->sede('Allegro Sevilla');
        $otra = $this->sede('Sol Palma', 'Sol', 'Grupo Meliá');
        $c = $this->contacto('dir@x.com');
        $cH = fn (string ...$n) => array_map(fn ($h) => ['hotel' => $h, 'activo' => true], $n);

        // Dos hoteles de la misma marca: queda la marca, sin sede.
        $this->assertSame('Allegro', \App\Services\Ai\ContactoHotel::recordar($c, $cH('Allegro Madrid', 'Allegro Sevilla')));
        $f = DB::table('contacts')->where('id', $c)->first();
        $this->assertNull($f->sede_id);
        $this->assertSame((int) DB::table('marcas')->where('name', 'Allegro')->value('id'), (int) $f->marca_id);
        $this->assertSame('Allegro', \App\Services\Ai\ContactoHotel::vigenteMarca($c)->name);
        $this->assertNull(\App\Services\Ai\ContactoHotel::vigente($c));
        $r = \App\Services\Ai\ContactoHotel::resumen(null, $f->sede_verificada_at, $f->sede_verificada_por, (int) $f->marca_id);
        $this->assertNull($r['sede']);
        $this->assertSame('Allegro', $r['marca']);
        $this->assertTrue($r['vigente']);

        // Hoteles de marcas distintas, o uno que el panel no conoce: no se adivina nada.
        $c2 = $this->contacto('dir2@x.com');
        $this->assertNull(\App\Services\Ai\ContactoHotel::recordar($c2, $cH('Allegro Madrid', 'Sol Palma')));
        $this->assertNull(\App\Services\Ai\ContactoHotel::recordar($c2, $cH('Allegro Madrid', 'Hotel Inventado')));
        $this->assertNull(DB::table('contacts')->where('id', $c2)->value('marca_id'));

        // Si luego se confirma UN solo hotel, la sede sustituye a la marca.
        \App\Services\Ai\ContactoHotel::recordar($c, $cH('Allegro Madrid'));
        $f = DB::table('contacts')->where('id', $c)->first();
        $this->assertSame($madrid, (int) $f->sede_id);
        $this->assertNull($f->marca_id);

        // «Sin marca» no es una marca real.
        $s = DB::table('sedes')->insertGetId(['marca_id' => DB::table('marcas')->insertGetId(['grupo_id' => DB::table('grupos')->value('id'), 'name' => 'Sin marca', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]), 'name' => 'Hotel A', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sedes')->insert(['marca_id' => DB::table('sedes')->where('id', $s)->value('marca_id'), 'name' => 'Hotel B', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull(\App\Services\Ai\ContactoHotel::recordar($this->contacto('dir3@x.com'), $cH('Hotel A', 'Hotel B')));
    }

    public function test_al_fusionar_el_enlace_por_marca_solo_vale_si_no_hay_sede(): void
    {
        $madrid = $this->sede('Allegro Madrid');
        $marca = (int) DB::table('marcas')->where('name', 'Allegro')->value('id');
        $svc = app(ContactMergeService::class);

        $keep = $this->contacto('k@x.com');
        $otro = $this->contacto('o@x.com', null, now()->subDays(3), 'correo');
        DB::table('contacts')->where('id', $otro)->update(['marca_id' => $marca]);
        $svc->fusionar($keep, $otro);
        $this->assertSame($marca, (int) DB::table('contacts')->where('id', $keep)->value('marca_id'));

        // El principal tiene una sede concreta: la marca del otro no la desplaza.
        $keep2 = $this->contacto('k2@x.com', $madrid, now()->subDays(30), 'correo');
        $otro2 = $this->contacto('o2@x.com', null, now()->subDays(1), 'correo');
        DB::table('contacts')->where('id', $otro2)->update(['marca_id' => $marca]);
        $svc->fusionar($keep2, $otro2);
        $f = DB::table('contacts')->where('id', $keep2)->first();
        $this->assertSame($madrid, (int) $f->sede_id);
        $this->assertNull($f->marca_id);
    }

    public function test_el_correo_que_da_un_contacto_de_whatsapp_se_guarda_en_su_ficha(): void
    {
        $wa = DB::table('contacts')->insertGetId(['name' => 'Manolo Hyatt', 'wa_id' => '34600111222']);
        $this->assertSame(['estado' => 'guardado'], \App\Services\Ai\ContactoHotel::guardarCorreo($wa, ' Manolo@Hotel.COM '));
        $this->assertSame('manolo@hotel.com', DB::table('contacts')->where('id', $wa)->value('email'));

        // Ya tiene correo: no se pisa.
        $this->assertNull(\App\Services\Ai\ContactoHotel::guardarCorreo($wa, 'otro@hotel.com'));
        $this->assertSame('manolo@hotel.com', DB::table('contacts')->where('id', $wa)->value('email'));

        // Correo que ya es de otra ficha: no se toca y se avisa de cuál.
        $wa2 = DB::table('contacts')->insertGetId(['name' => 'Manolo', 'wa_id' => '34600333444']);
        $r = \App\Services\Ai\ContactoHotel::guardarCorreo($wa2, 'manolo@hotel.com');
        $this->assertSame('duplicado', $r['estado']);
        $this->assertSame($wa, $r['otro']);
        $this->assertNull(DB::table('contacts')->where('id', $wa2)->value('email'));

        // Texto que no es un correo.
        $this->assertNull(\App\Services\Ai\ContactoHotel::guardarCorreo($wa2, 'no-es-correo'));
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
