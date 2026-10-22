<?php

namespace App\Console\Commands;

use App\Services\SoporteWhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasa a tickets los eventos de WhatsApp de soporte que aún no se procesaron
 * (`wa_raw_events.processed_at` vacío): los que fallaron en el webhook y los que
 * entraron con el ajuste `wa_soporte_tickets` apagado. Es seguro repetirlo: cada
 * mensaje se identifica por su wamid y no se duplica (tampoco lo ya importado con
 * wa:import-history).
 */
class WaProcessRaw extends Command
{
    protected $signature = 'wa:process-raw
        {--dry-run : Solo contar lo pendiente}';

    protected $description = 'Pasa a tickets los eventos pendientes del WhatsApp de soporte';

    public function handle(SoporteWhatsApp $svc): int
    {
        if (!SoporteWhatsApp::activo()) {
            $this->error('El ajuste wa_soporte_tickets está apagado: actívalo antes de crear tickets de WhatsApp.');
            return self::FAILURE;
        }

        $pend = DB::table('wa_raw_events')->whereNull('processed_at')
            ->whereIn('field', ['messages', 'smb_message_echoes']);
        $total = (clone $pend)->count();
        $this->info("Eventos pendientes: {$total}");
        if ($this->option('dry-run') || !$total) return self::SUCCESS;

        $mensajes = 0; $errores = 0;
        foreach ($pend->orderBy('id')->cursor() as $e) {
            try {
                $mensajes += $svc->procesar($e->field, json_decode($e->payload, true) ?: []);
                DB::table('wa_raw_events')->where('id', $e->id)->update(['processed_at' => now()]);
            } catch (\Throwable $ex) {
                $errores++;
                $this->warn("Evento {$e->id}: " . $ex->getMessage());
            }
        }
        $this->info("Mensajes nuevos en tickets: {$mensajes}" . ($errores ? " · {$errores} eventos con error (siguen pendientes)" : ''));
        return $errores ? self::FAILURE : self::SUCCESS;
    }
}
