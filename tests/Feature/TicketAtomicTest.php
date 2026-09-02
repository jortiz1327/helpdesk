<?php

namespace Tests\Feature;

use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La creación de un ticket (ticket + primer mensaje) va en una TRANSACCIÓN: si algo
 * falla a media (p. ej. al guardar el mensaje), no puede quedar un ticket huérfano
 * sin mensaje. Aquí se prueba que create() participa de la transacción del llamador.
 */
class TicketAtomicTest extends TestCase
{
    use RefreshDatabase;

    public function test_si_algo_falla_tras_crear_el_ticket_se_revierte(): void
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c@x.com']);

        try {
            DB::transaction(function () use ($cid) {
                app(TicketService::class)->create([
                    'contact_id' => $cid, 'channel' => 'email', 'subject' => 'x', 'body' => 'y',
                ], notify: false);
                throw new \RuntimeException('fallo simulado tras crear el ticket');
            });
            $this->fail('La transacción debería haber lanzado la excepción');
        } catch (\RuntimeException $e) {
            // esperado
        }

        // La transacción se revirtió: no queda ni el ticket ni su evento.
        $this->assertSame(0, DB::table('tickets')->count());
        $this->assertSame(0, DB::table('ticket_events')->count());
    }

    public function test_una_creacion_normal_deja_ticket_y_es_confirmada(): void
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'C', 'email' => 'c@x.com']);

        $id = app(TicketService::class)->create([
            'contact_id' => $cid, 'channel' => 'email', 'subject' => 'x', 'body' => 'y',
        ], notify: false);

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, DB::table('tickets')->where('id', $id)->count());
    }
}
