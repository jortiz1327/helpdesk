<?php

namespace Tests\Feature;

use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fusión de tickets: al fusionar, el principal debe HEREDAR mensajes, etiquetas,
 * campos personalizados y valoración del absorbido (sin huérfanos), y en conflicto
 * manda el principal. Es la pieza donde una regresión se pierde en silencio.
 */
class TicketMergeTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(int $contactId, string $code): int
    {
        return DB::table('tickets')->insertGetId([
            'code' => $code, 'subject' => 'Asunto ' . $code, 'status' => 'abierto',
            'priority' => 'media', 'channel' => 'email', 'contact_id' => $contactId,
            'opened_at' => now(), 'last_message_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_merge_traslada_todo_y_no_deja_huerfanos(): void
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => 'c@x.com']);
        $a = $this->ticket($cid, 'TK-A');   // principal
        $b = $this->ticket($cid, 'TK-B');   // absorbido

        // Etiquetas: A tiene L1; B tiene L1 (dup) + L2 (única).
        $l1 = DB::table('ticket_labels')->insertGetId(['name' => 'Comun', 'color' => '#888', 'position' => 1, 'active' => 1, 'created_at' => now()]);
        $l2 = DB::table('ticket_labels')->insertGetId(['name' => 'SoloB', 'color' => '#888', 'position' => 2, 'active' => 1, 'created_at' => now()]);
        DB::table('ticket_label_ticket')->insert([
            ['ticket_id' => $a, 'label_id' => $l1],
            ['ticket_id' => $b, 'label_id' => $l1],
            ['ticket_id' => $b, 'label_id' => $l2],
        ]);

        // Campos: A.F='AAA'; B.F='BBB' (conflicto → gana A); B.G='GGG' (se hereda).
        $f = DB::table('ticket_custom_fields')->insertGetId(['key' => 'f', 'label' => 'F', 'type' => 'text', 'required' => 0, 'position' => 1, 'active' => 1, 'created_at' => now()]);
        $g = DB::table('ticket_custom_fields')->insertGetId(['key' => 'g', 'label' => 'G', 'type' => 'text', 'required' => 0, 'position' => 2, 'active' => 1, 'created_at' => now()]);
        DB::table('ticket_field_values')->insert([
            ['ticket_id' => $a, 'field_id' => $f, 'value' => 'AAA'],
            ['ticket_id' => $b, 'field_id' => $f, 'value' => 'BBB'],
            ['ticket_id' => $b, 'field_id' => $g, 'value' => 'GGG'],
        ]);

        // Valoración: solo B.
        DB::table('ticket_ratings')->insert(['ticket_id' => $b, 'score' => 5, 'created_at' => now(), 'updated_at' => now()]);

        [$ok, $err] = app(TicketService::class)->merge($a, $b, null, 'motivo de prueba');
        $this->assertTrue($ok, "merge falló: $err");

        // Etiquetas del principal: L1 + L2, sin duplicar la común.
        $this->assertEquals(
            [$l1, $l2],
            DB::table('ticket_label_ticket')->where('ticket_id', $a)->orderBy('label_id')->pluck('label_id')->all()
        );

        // Campos: A conserva su F='AAA' (gana el principal) y hereda G='GGG'.
        $this->assertSame('AAA', DB::table('ticket_field_values')->where(['ticket_id' => $a, 'field_id' => $f])->value('value'));
        $this->assertSame('GGG', DB::table('ticket_field_values')->where(['ticket_id' => $a, 'field_id' => $g])->value('value'));

        // Valoración movida al principal.
        $this->assertSame(5, (int) DB::table('ticket_ratings')->where('ticket_id', $a)->value('score'));

        // Nada huérfano en el absorbido.
        $this->assertSame(0, DB::table('ticket_label_ticket')->where('ticket_id', $b)->count());
        $this->assertSame(0, DB::table('ticket_field_values')->where('ticket_id', $b)->count());
        $this->assertSame(0, DB::table('ticket_ratings')->where('ticket_id', $b)->count());

        // El absorbido queda cerrado + apuntando al principal.
        $bRow = DB::table('tickets')->where('id', $b)->first();
        $this->assertSame('cerrado', $bRow->status);
        $this->assertSame($a, (int) $bRow->merged_into_id);
    }
}
