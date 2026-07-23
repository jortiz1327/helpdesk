<?php

namespace App\Console\Commands;

use App\Services\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Procesa las campañas de difusión cuya hora ya llegó. Portado de api/campaign_tick.php. */
class CampaignTick extends Command
{
    protected $signature = 'campaign:tick';
    protected $description = 'Procesa campañas programadas con destinatarios pendientes';

    public function handle(CampaignService $campaigns): int
    {
        $due = DB::table('campaigns')
            ->whereIn('status', ['scheduled', 'sending'])
            ->whereRaw('scheduled_at <= NOW()')
            ->orderBy('scheduled_at')
            ->limit(20)
            ->pluck('id');

        $totalSent = 0; $totalFailed = 0; $processed = 0;
        foreach ($due as $id) {
            [$s, $f] = $campaigns->process((int) $id, 30);
            $totalSent += $s; $totalFailed += $f; $processed++;
        }
        $this->info(json_encode(['ok' => true, 'campaigns' => $processed, 'sent' => $totalSent, 'failed' => $totalFailed]));
        return self::SUCCESS;
    }
}
