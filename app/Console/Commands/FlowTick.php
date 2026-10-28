<?php

namespace App\Console\Commands;

use App\Services\FlowEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reanuda los flujos en espera (nodos de Retraso) cuyo tiempo ya venció. Portado de api/flow_tick.php. */
class FlowTick extends Command
{
    protected $signature = 'flow:tick';
    protected $description = 'Reanuda flujos en espera (nodos Retraso) cuyo tiempo ya venció';

    public function handle(FlowEngine $engine): int
    {
        $due = DB::select("SELECT * FROM flow_sessions WHERE status='waiting' AND resume_at IS NOT NULL AND resume_at <= NOW() LIMIT 50");
        $count = 0;
        foreach ($due as $row) {
            $s = (array) $row;
            $flow = (array) DB::selectOne('SELECT * FROM flows WHERE id=?', [$s['flow_id']]);
            if (empty($flow['id'])) {
                DB::update("UPDATE flow_sessions SET status='done' WHERE id=?", [$s['id']]);
                continue;
            }
            $contact = (array) DB::selectOne('SELECT id, wa_id FROM contacts WHERE id=?', [$s['contact_id']]);
            if (empty($contact['id'])) continue;

            // Los flujos son de Campañas: sus mensajes van al chat, NUNCA a un ticket de
            // Soporte (si no, el ticket parecería contestado y alteraría SLA y avisos).
            $contact['ticket_id'] = null;

            $s['status'] = 'active';
            $engine->run($s, $flow, $s['current_node'], $contact);
            $count++;
        }
        $this->info(json_encode(['ok' => true, 'resumed' => $count]));
        return self::SUCCESS;
    }
}
