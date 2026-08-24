<?php

namespace Tests\Feature;

use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acciones en lote nuevas: cambio de PRIORIDAD (método nuevo) y FUSIÓN de varios en uno.
 * Se prueban las piezas de servicio que orquesta bulk(): setPriority y merge encadenado.
 */
class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function ticket(?int $contactId = null): int
    {
        $n = ++$this->seq;
        $contactId ??= DB::table('contacts')->insertGetId(['name' => "C$n", 'email' => "c$n@x.com"]);
        return DB::table('tickets')->insertGetId([
            'code' => "TK-$n", 'subject' => "Asunto $n", 'status' => 'abierto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $contactId, 'opened_at' => now(),
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_set_priority_cambia_y_registra(): void
    {
        $svc = app(TicketService::class);
        $id = $this->ticket();

        $this->assertTrue($svc->setPriority($id, 'alta'));
        $this->assertSame('alta', DB::table('tickets')->where('id', $id)->value('priority'));
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $id)
            ->where('type', 'priority')->where('to_value', 'alta')->count());

        // Sin cambio real → false y sin evento nuevo.
        $this->assertFalse($svc->setPriority($id, 'alta'));
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $id)->where('type', 'priority')->count());
    }

    public function test_fusion_en_lote_junta_varios_en_el_mas_antiguo(): void
    {
        $svc = app(TicketService::class);
        // Tres tickets del MISMO cliente.
        $cid = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => 'uno@x.com']);
        $a = $this->ticket($cid);   // el más antiguo → principal
        $b = $this->ticket($cid);
        $c = $this->ticket($cid);

        // Igual que bulk(): principal = id menor, el resto se absorbe.
        foreach ([$b, $c] as $tid) {
            [$ok] = $svc->merge($a, $tid, null, 'mismas incidencias');
            $this->assertTrue($ok);
        }

        $this->assertNull(DB::table('tickets')->where('id', $a)->value('merged_into_id'));   // el principal, intacto
        $this->assertSame($a, (int) DB::table('tickets')->where('id', $b)->value('merged_into_id'));
        $this->assertSame($a, (int) DB::table('tickets')->where('id', $c)->value('merged_into_id'));
    }
}
