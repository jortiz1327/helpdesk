<?php

namespace Tests\Feature;

use App\Services\PortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IDEMPOTENCIA del alta del portal: un doble-submit (doble clic, reintento de red) no
 * debe crear dos incidencias. Se dedup por contacto+asunto en una ventana corta, igual
 * que el correo dedup por Message-ID.
 */
class PortalIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_doble_submit_no_crea_dos_incidencias(): void
    {
        $svc  = app(PortalService::class);
        $data = ['name' => 'Cliente', 'subject' => 'No cargan las etiquetas', 'body' => 'Desde esta mañana no cargan'];

        [$ok1, , $code1] = $svc->createTicket('c@x.com', $data);
        [$ok2, , $code2] = $svc->createTicket('c@x.com', $data);   // el mismo envío, repetido

        $this->assertTrue($ok1);
        $this->assertTrue($ok2);
        $this->assertSame($code1, $code2);   // idempotente: devuelve la MISMA incidencia
        $this->assertSame(1, DB::table('tickets')->where('subject', $data['subject'])->count());
    }

    public function test_asuntos_distintos_del_mismo_cliente_si_crean_dos(): void
    {
        $svc = app(PortalService::class);

        [, , $a] = $svc->createTicket('c@x.com', ['name' => 'Cliente', 'subject' => 'Etiquetas rotas', 'body' => 'no cargan']);
        [, , $b] = $svc->createTicket('c@x.com', ['name' => 'Cliente', 'subject' => 'Menú digital caído', 'body' => 'otra cosa']);

        $this->assertNotSame($a, $b);   // el dedup NO se pasa de listo: son casos distintos
        $this->assertSame(2, DB::table('tickets')->count());
    }
}
