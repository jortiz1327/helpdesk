<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * La valoración (CSAT) por correo NO debe poder falsearse por GET: los antivirus de
 * correo (SafeLinks, Mimecast…) hacen prefetch de todos los enlaces. Un GET con
 * ?score solo PRE-SELECCIONA; solo el POST (clic humano en la página) guarda.
 */
class CsatRatePageTest extends TestCase
{
    use RefreshDatabase;

    private function ticketResuelto(): int
    {
        $cid = DB::table('contacts')->insertGetId(['name' => 'Cliente', 'email' => 'c@x.com']);
        return DB::table('tickets')->insertGetId([
            'code' => 'TK-1', 'subject' => 'Algo', 'status' => 'resuelto', 'priority' => 'media',
            'channel' => 'email', 'contact_id' => $cid, 'opened_at' => now(),
            'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_un_get_con_score_no_crea_valoracion(): void
    {
        $id = $this->ticketResuelto();
        $url = URL::signedRoute('portal.rate', ['ticket' => $id, 'score' => 5], now()->addDay(), false);

        $this->get($url)->assertOk();

        $this->assertDatabaseMissing('ticket_ratings', ['ticket_id' => $id]);   // el GET NO guarda
    }

    public function test_un_post_con_score_si_crea_valoracion(): void
    {
        $id = $this->ticketResuelto();
        $url = URL::signedRoute('portal.rate', ['ticket' => $id], now()->addDay(), false);

        $this->post($url, ['score' => 5])->assertOk();

        $this->assertSame(5, (int) DB::table('ticket_ratings')->where('ticket_id', $id)->value('score'));
    }
}
