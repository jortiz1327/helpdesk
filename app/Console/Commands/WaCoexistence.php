<?php

namespace App\Console\Commands;

use App\Models\WhatsAppNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Operaciones de WhatsApp COEXISTENCE sobre un número de `whatsapp_numbers` (el 01 de
 * soporte), con SU token: el token no sale del servidor ni se escribe en ningún log.
 *
 *   check     → estado del número (is_on_biz_app / platform_type) y apps suscritas al WABA
 *   subscribe → suscribe el WABA a la app del token (POST /{waba}/subscribed_apps)
 *   contacts  → pide los contactos del móvil   (smb_app_data · smb_app_state_sync)  ¡UNA VEZ!
 *   history   → pide el historial de chats     (smb_app_data · history)             ¡UNA VEZ!
 *   events    → resumen de lo capturado en wa_raw_events
 *
 * Las dos sincronizaciones solo se pueden pedir una vez y dentro de las 24 h siguientes
 * al alta; el webhook TIENE que estar ya configurado o lo que mande Meta se pierde.
 * NUNCA se llama a /register ni /deregister: en Coexistence desconectaría el móvil.
 */
class WaCoexistence extends Command
{
    protected $signature = 'wa:coex {action : check|subscribe|contacts|history|events}
        {--numero= : phone_number_id (por defecto, el primer número activo de soporte)}
        {--force : Confirma las sincronizaciones de un solo uso}';

    protected $description = 'WhatsApp Coexistence: comprobar, suscribir y sincronizar el número de soporte';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'events') {
            foreach (DB::table('wa_raw_events')->selectRaw('field, COUNT(*) n, MIN(created_at) desde, MAX(created_at) hasta')->groupBy('field')->get() as $r) {
                $this->line(sprintf('%-22s %6d  %s → %s', $r->field, $r->n, $r->desde, $r->hasta));
            }
            return self::SUCCESS;
        }

        $num = $this->option('numero')
            ? WhatsAppNumber::where('phone_number_id', (string) $this->option('numero'))->first()
            : WhatsAppNumber::porFuncion('soporte');
        if (!$num || !$num->token || !$num->phone_number_id) {
            $this->error('No hay número de soporte con token y phone_number_id.');
            return self::FAILURE;
        }
        if ($num->funcion === 'campanas') {
            $this->error('Ese número es de Campañas: este comando es solo para el de soporte.');
            return self::FAILURE;
        }

        $base = 'https://graph.facebook.com/' . config('whatsapp.graph_version');
        $http = Http::withToken((string) $num->token)->timeout(30);
        $pid  = (string) $num->phone_number_id;

        switch ($action) {
            case 'check':
                $this->show($http->get("$base/$pid", ['fields' => 'display_phone_number,verified_name,is_on_biz_app,platform_type,quality_rating']));
                if ($num->waba_id) $this->show($http->get("$base/{$num->waba_id}/subscribed_apps"));
                return self::SUCCESS;

            case 'subscribe':
                if (!$num->waba_id) { $this->error('Falta el WABA ID del número.'); return self::FAILURE; }
                $this->show($http->post("$base/{$num->waba_id}/subscribed_apps"));
                return self::SUCCESS;

            case 'contacts':
            case 'history':
                if (!$this->option('force')) {
                    $this->warn('Esta sincronización solo se puede pedir UNA vez. Comprueba que el webhook está configurado y repite con --force.');
                    return self::FAILURE;
                }
                $this->show($http->post("$base/$pid/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type'         => $action === 'contacts' ? 'smb_app_state_sync' : 'history',
                ]));
                return self::SUCCESS;
        }

        $this->error("Acción desconocida: $action");
        return self::FAILURE;
    }

    /** Respuesta de Meta, legible (nunca incluye el token). */
    private function show($resp): void
    {
        $this->line('HTTP ' . $resp->status());
        $this->line(json_encode($resp->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
