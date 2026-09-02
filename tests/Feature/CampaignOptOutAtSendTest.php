<?php

namespace Tests\Feature;

use App\Services\CampaignService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BAJA (opt-out) respetada AL ENVIAR: el filtro de bajas se aplica al crear la campaña,
 * pero entre crear y enviar (programadas / envío por tandas) un contacto puede darse de
 * BAJA. Ese NO debe recibir el mensaje. Se comprueba que process() lo salta ('skipped') y
 * NO llama al envío para él.
 */
class CampaignOptOutAtSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_envia_a_quien_se_dio_de_baja_tras_crear_la_campana(): void
    {
        DB::table('contacts')->insert(['name' => 'Baja', 'wa_id' => '34600111222', 'opted_out' => 1, 'opted_out_at' => now()]);

        $cid = DB::table('campaigns')->insertGetId([
            'title' => 'Promo', 'template_name' => 'promo', 'language' => 'es', 'components' => '[]',
            'status' => 'sending', 'total' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_recipients')->insert([
            ['campaign_id' => $cid, 'wa_id' => '34600111222', 'status' => 'pending'],   // dado de baja
            ['campaign_id' => $cid, 'wa_id' => '34600999888', 'status' => 'pending'],   // normal
        ]);

        $enviados = [];
        $this->mock(WhatsAppService::class, function ($m) use (&$enviados) {
            $m->shouldReceive('sendTemplate')->andReturnUsing(function ($to) use (&$enviados) {
                $enviados[] = $to;
                return [200, ['messages' => [['id' => 'w_' . $to]]]];
            });
        });

        app(CampaignService::class)->process($cid, 30);

        // Al de baja NO se le envió y su fila quedó 'skipped'.
        $this->assertNotContains('34600111222', $enviados, 'se envió a un contacto dado de baja');
        $this->assertSame('skipped', DB::table('campaign_recipients')->where('wa_id', '34600111222')->value('status'));

        // Al normal SÍ se le envió y quedó 'sent'.
        $this->assertContains('34600999888', $enviados);
        $this->assertSame('sent', DB::table('campaign_recipients')->where('wa_id', '34600999888')->value('status'));
    }
}
