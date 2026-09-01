<?php

namespace Tests\Feature;

use App\Services\CampaignService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un 429 (rate limit) o un 5xx de Meta es TRANSITORIO: el destinatario debe quedar
 * pendiente y reintentarse (hasta N veces), no marcarse `failed` para siempre.
 */
class CampaignRetryTest extends TestCase
{
    use RefreshDatabase;

    private function campana(): int
    {
        return DB::table('campaigns')->insertGetId([
            'title' => 'Promo', 'template_name' => 'promo', 'language' => 'es',
            'status' => 'sending', 'components' => '[]', 'scheduled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mock429(): void
    {
        $this->mock(WhatsAppService::class, function ($m) {
            $m->shouldReceive('sendTemplate')->andReturn([429, ['error' => ['message' => 'rate limit']]]);
        });
    }

    public function test_un_429_deja_pending_y_suma_un_reintento(): void
    {
        $this->mock429();
        $cid = $this->campana();
        DB::table('campaign_recipients')->insert(['campaign_id' => $cid, 'wa_id' => '34600', 'name' => 'C', 'status' => 'pending']);

        [$sent, $failed, $pending] = app(CampaignService::class)->process($cid, 30);

        $this->assertSame(0, $sent);
        $this->assertSame(0, $failed);   // un 429 NO cuenta como fallido
        $this->assertSame(1, $pending);
        $r = DB::table('campaign_recipients')->where('campaign_id', $cid)->first(['status', 'retries']);
        $this->assertSame('pending', $r->status);
        $this->assertSame(1, (int) $r->retries);
    }

    public function test_tras_agotar_los_reintentos_se_marca_failed(): void
    {
        $this->mock429();
        $cid = $this->campana();
        DB::table('campaign_recipients')->insert([
            'campaign_id' => $cid, 'wa_id' => '34600', 'name' => 'C', 'status' => 'pending',
            'retries' => CampaignService::MAX_REINTENTOS,   // ya no quedan reintentos
        ]);

        [, $failed] = app(CampaignService::class)->process($cid, 30);

        $this->assertSame(1, $failed);
        $this->assertSame('failed', DB::table('campaign_recipients')->where('campaign_id', $cid)->value('status'));
    }
}
