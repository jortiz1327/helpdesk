<?php

namespace Tests\Feature;

use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cuando el cliente RESPONDE a un ticket en el que esperábamos su respuesta (abierto y
 * con nuestro mensaje el último: el reloj del SLA está en pausa), el reloj tiene que
 * REANUDARSE. Si no, el SLA quedaría congelado para siempre — regresión cara de detectar.
 * (El antiguo estado «esperando respuesta» ya no existe: ahora es solo quién habló el último.)
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
            'code' => "TK-$n", 'subject' => 'Algo', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now()->subHours(2), 'last_direction' => 'out',
            'sla_paused_since' => now()->subMinutes(30), 'sla_paused_minutes' => 0,
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$id, $cid];
    }

    public function test_la_respuesta_del_cliente_reanuda_el_sla(): void
    {
        [$id, $cid] = $this->ticketEsperando();

        ChatService::storeMessage($cid, '', 'in', 'text', 'ya te contesto', ['ticket_id' => $id, 'channel' => 'email']);

        $t = DB::table('tickets')->where('id', $id)->first();
        $this->assertSame(['abierto', 'in'], [$t->status, $t->last_direction]);   // la pelota vuelve a nuestro tejado
        $this->assertNull($t->sla_paused_since);         // reloj reanudado (deja de estar pausado)
        // (los minutos acumulados son «laborables» y los calcula BusinessHoursService,
        //  con su propio test; aquí solo importa que la pausa se cierra.)
    }

    public function test_una_nota_interna_no_reanuda_el_sla(): void
    {
        [$id, $cid] = $this->ticketEsperando();

        // Nota interna (del agente) NO es respuesta del cliente: el ticket sigue esperando.
        ChatService::storeMessage($cid, '', 'out', 'text', 'apunte',
            ['ticket_id' => $id, 'channel' => 'email', 'is_internal_note' => true]);

        $t = DB::table('tickets')->where('id', $id)->first();
        $this->assertSame('out', $t->last_direction);
        $this->assertNotNull($t->sla_paused_since);   // sigue pausado
    }
}
