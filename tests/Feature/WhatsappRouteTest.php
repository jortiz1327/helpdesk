<?php

namespace Tests\Feature;

use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Enrutado de WhatsApp: un contacto = UN ticket. Un mensaje nuevo crea el ticket; los
 * siguientes se pegan al MISMO; si estaba cerrado, se REABRE ese mismo. Es lógica con
 * varios caminos donde una regresión (crear tickets duplicados, no reabrir) es sutil.
 */
class WhatsappRouteTest extends TestCase
{
    use RefreshDatabase;

    private function contacto(): int
    {
        return DB::table('contacts')->insertGetId(['name' => 'Cliente WA', 'wa_id' => '34600111222']);
    }

    public function test_primer_mensaje_crea_un_ticket(): void
    {
        $cid = $this->contacto();
        $id = app(TicketService::class)->routeIncoming($cid, 'whatsapp', 'las etiquetas no cargan');

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, DB::table('tickets')->where('contact_id', $cid)->where('channel', 'whatsapp')->count());
    }

    public function test_los_siguientes_mensajes_van_al_mismo_ticket(): void
    {
        $cid = $this->contacto();
        $svc = app(TicketService::class);

        $id1 = $svc->routeIncoming($cid, 'whatsapp', 'hola');
        $id2 = $svc->routeIncoming($cid, 'whatsapp', 'sigo con el problema del display');

        $this->assertSame($id1, $id2);   // el mismo ticket, no uno nuevo
        $this->assertSame(1, DB::table('tickets')->where('contact_id', $cid)->count());
    }

    public function test_un_mensaje_tras_cerrar_reabre_el_mismo_ticket(): void
    {
        $cid = $this->contacto();
        $svc = app(TicketService::class);

        $id = $svc->routeIncoming($cid, 'whatsapp', 'un problema');
        $svc->setStatus($id, 'cerrado', null);
        $this->assertSame('cerrado', DB::table('tickets')->where('id', $id)->value('status'));

        $id2 = $svc->routeIncoming($cid, 'whatsapp', 'ha vuelto a pasar');

        $this->assertSame($id, $id2);   // el MISMO ticket, no uno nuevo
        $this->assertNotSame('cerrado', DB::table('tickets')->where('id', $id)->value('status'));   // reabierto
        $this->assertSame(1, DB::table('tickets')->where('contact_id', $cid)->count());
    }
}
