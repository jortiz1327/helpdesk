<?php

namespace App\Console\Commands;

use App\Services\ScheduledReplyService;
use Illuminate\Console\Command;

/**
 * Envía las RESPUESTAS PROGRAMADAS cuya hora ya ha llegado. Cada minuto desde el
 * planificador. El envío real (SMTP + adjuntos + threading) lo hace por el mismo
 * camino que una respuesta normal; aquí solo se dispara.
 */
class SendScheduledReplies extends Command
{
    protected $signature = 'replies:send';

    protected $description = 'Envía las respuestas programadas que ya toca mandar';

    public function handle(ScheduledReplyService $svc): int
    {
        $n = $svc->dispatchDue();
        $this->info("Respuestas programadas enviadas: {$n}.");
        return self::SUCCESS;
    }
}
