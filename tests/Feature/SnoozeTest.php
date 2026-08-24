<?php

namespace Tests\Feature;

use App\Services\ChatService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POSPONER (snooze): un ticket duerme y vuelve solo. Se blindan los tres caminos de
 * despertar —respuesta del cliente, vencer la fecha (cron) y a mano— porque una
 * regresión (no despertar, o despertar de más) rompe el flujo diario del agente.
 */
class SnoozeTest extends TestCase
{
    use RefreshDatabase;

    private int $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agente = DB::table('users')->insertGetId([
            'name' => 'Agente', 'email' => 'a@x.com', 'password' => bcrypt('x'), 'created_at' => now(),
        ]);
    }

    private int $seq = 0;

    private function ticket(): int
    {
        $n = ++$this->seq;
        $cid = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => "c$n@x.com"]);
        return DB::table('tickets')->insertGetId([
            'code' => "TK-$n", 'subject' => 'Algo', 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(),
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_posponer_marca_los_campos_y_registra_evento(): void
    {
        $id = $this->ticket();
        app(TicketService::class)->snooze($id, now()->addDays(3), false, $this->agente, 'esperando repuesto');

        $t = DB::table('tickets')->where('id', $id)->first();
        $this->assertNotNull($t->snoozed_at);
        $this->assertSame($this->agente, (int) $t->snoozed_by);
        $this->assertSame('esperando repuesto', $t->snooze_reason);
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $id)->where('type', 'snooze')->count());
    }

    public function test_una_respuesta_del_cliente_despierta_el_ticket(): void
    {
        $id = $this->ticket();
        $cid = (int) DB::table('tickets')->where('id', $id)->value('contact_id');
        app(TicketService::class)->snooze($id, now()->addWeek(), false, $this->agente, null);   // dormido «hasta el próximo lunes»

        // Llega un mensaje ENTRANTE del cliente (pasa por el punto central).
        ChatService::storeMessage($cid, '', 'in', 'text', 'ha vuelto a pasar', ['ticket_id' => $id, 'channel' => 'email']);

        $t = DB::table('tickets')->where('id', $id)->first();
        $this->assertNull($t->snoozed_at);   // despertó, aunque la fecha no había llegado
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $id)
            ->where('type', 'snooze_wake')->where('to_value', 'reply')->count());
    }

    public function test_una_nota_interna_no_despierta(): void
    {
        $id = $this->ticket();
        $cid = (int) DB::table('tickets')->where('id', $id)->value('contact_id');
        app(TicketService::class)->snooze($id, now()->addWeek(), false, $this->agente, null);

        // Nota interna (is_internal_note) NO es una respuesta del cliente: no debe despertar.
        ChatService::storeMessage($cid, '', 'out', 'text', 'apunte del agente',
            ['ticket_id' => $id, 'channel' => 'email', 'is_internal_note' => true]);

        $this->assertNotNull(DB::table('tickets')->where('id', $id)->value('snoozed_at'));
    }

    public function test_el_cron_despierta_los_vencidos_pero_no_los_de_hasta_que_responda(): void
    {
        $vencido = $this->ticket();
        app(TicketService::class)->snooze($vencido, now()->addDay(), false, $this->agente, null);
        // Forzamos que su fecha ya pasó.
        DB::table('tickets')->where('id', $vencido)->update(['snoozed_until' => now()->subMinute()]);

        $porRespuesta = $this->ticket();
        app(TicketService::class)->snooze($porRespuesta, null, true, $this->agente, null);   // sin fecha

        $this->artisan('tickets:wake')->assertSuccessful();

        $this->assertNull(DB::table('tickets')->where('id', $vencido)->value('snoozed_at'));       // despertó
        $this->assertNotNull(DB::table('tickets')->where('id', $porRespuesta)->value('snoozed_at')); // sigue dormido
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $vencido)
            ->where('type', 'snooze_wake')->where('to_value', 'due')->count());
    }
}
