<?php

namespace Tests\Feature;

use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una campaña solo puede enviarse desde UN proceso a la vez (candado por campaña). Si
 * no, el cron y un envío inmediato (o un doble clic) leen los mismos destinatarios
 * 'pending' y los envían —y PAGAN— dos veces.
 */
class CampaignLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_envia_si_otro_proceso_tiene_el_candado(): void
    {
        $cid = DB::table('campaigns')->insertGetId([
            'title' => 'Promo', 'template_name' => 'promo', 'language' => 'es',
            'status' => 'sending', 'components' => '[]', 'scheduled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_recipients')->insert([
            'campaign_id' => $cid, 'wa_id' => '34600111222', 'name' => 'Cliente', 'status' => 'pending',
        ]);

        // Otro proceso ya está enviando esta campaña: tiene el candado.
        $lock = Cache::lock("campaign:send:{$cid}", 300);
        $this->assertTrue($lock->get());

        [$sent, $failed, $pending] = app(CampaignService::class)->process($cid, 30);

        $this->assertSame(0, $sent);          // no ha enviado nada
        $this->assertSame(1, $pending);
        // El destinatario sigue intacto (no se ha tocado ni enviado).
        $this->assertSame('pending', DB::table('campaign_recipients')->where('campaign_id', $cid)->value('status'));

        $lock->release();
    }
}
