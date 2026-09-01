<?php

namespace Tests\Feature;

use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cuando el cliente RESPONDE a un ticket que estaba «esperando respuesta», el reloj
 * del SLA (que se pausa en ese estado) tiene que REANUDARSE. Antes no cambiaba el
 * estado y el SLA quedaba congelado para siempre — regresión cara de detectar.
 */
class SlaResumeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function ticketEsperando(): array
    {
        $n = ++$this->seq;
        $cid = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => "c$n@x.com"]);
        $id = DB::table('tickets')->insertGetId([
            'code' => "TK-$n", 'subject' => 'Algo', 'status' => 'esperando_respuesta', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now()->subHours(2),
            'sla_paused_since' => now()->subMinutes(30), 'sla_paused_minutes' => 0,
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$id, $cid];
    }

    public function test_la_respuesta_del_cliente_reanuda_el_sla_y_pasa_a_en_progreso(): void
    {
        [$id, $cid] = $this->ticketEsperando();

        ChatService::storeMessage($cid, '', 'in', 'text', 'ya te contesto', ['ticket_id' => $id, 'channel' => 'email']);

        $t = DB::table('tickets')->where('id', $id)->first();
        $this->assertSame('en_progreso', $t->status);   // la pelota vuelve a nuestro tejado
        $this->assertNull($t->sla_paused_since);         // reloj reanudado (deja de estar pausado)
        // (los minutos acumulados son «laborables» y los calcula BusinessHoursService,
        //  con su propio test; aquí solo importa que la pausa se cierra.)
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $id)
            ->where('type', 'status')->where('to_value', 'en_progreso')->count());
    }

    public function test_una_nota_interna_no_reanuda_el_sla(): void
    {
        [$id, $cid] = $this->ticketEsperando();

        // Nota interna (del agente) NO es respuesta del cliente: el ticket sigue esperando.
        ChatService::storeMessage($cid, '', 'out', 'text', 'apunte',
            ['ticket_id' => $id, 'channel' => 'email', 'is_internal_note' => true]);

        $t = DB::table('tickets')->where('id', $id)->first();
        $this->assertSame('esperando_respuesta', $t->status);
        $this->assertNotNull($t->sla_paused_since);   // sigue pausado
    }
}
