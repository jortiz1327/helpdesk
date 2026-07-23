<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/settings.php — solo admin. Dispatch por ?action=. */
class SettingsController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        $action = $request->query('action', '');

        if ($action === 'get') {
            return $this->get($request);
        }
        if ($action === 'save' && $request->isMethod('post')) {
            return $this->save($request);
        }
        if ($action === 'test') {
            return $this->test($wa);
        }
        return response()->json(['error' => 'Acción no válida'], 400);
    }

    protected function get(Request $request)
    {
        $base = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');

        return response()->json([
            'business_name'      => Setting::get('business_name'),
            'wa_phone_number_id' => Setting::get('wa_phone_number_id'),
            'wa_business_id'     => Setting::get('wa_business_id'),
            'wa_app_id'          => Setting::get('wa_app_id'),
            'wa_token'           => Setting::get('wa_token'),
            'wa_app_secret'      => Setting::get('wa_app_secret'),
            'wa_verify_token'    => Setting::get('wa_verify_token'),
            // Firma del webhook: activa solo si hay App Secret configurado
            'webhook_signature_active' => (string) Setting::get('wa_app_secret', '') !== '',
            'account_verified'   => (string) Setting::get('account_verified', '0') === '1',
            'consent_enabled'    => (string) Setting::get('consent_enabled', '0') === '1',
            'consent_message'    => (string) Setting::get('consent_message', '') ?: self::consentDefault(),
            'webhook_url'        => $base . '/api/webhook.php',
            // Red de seguridad de envíos
            'outbound_paused'    => (string) Setting::get('outbound_paused', '0') === '1',
            'daily_send_cap'     => (int) Setting::get('daily_send_cap', '0'),
            'sent_today'         => (int) DB::table('messages')
                ->where('direction', 'out')->where('type', 'template')
                ->where('created_at', '>=', now()->startOfDay())->count(),
        ]);
    }

    protected function save(Request $request)
    {
        $in = $request->all();
        $allowed = ['wa_token', 'wa_phone_number_id', 'wa_business_id', 'wa_app_id', 'wa_app_secret', 'wa_verify_token', 'business_name', 'consent_message'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $in)) {
                Setting::put($k, trim((string) $in[$k]));
            }
        }
        if (array_key_exists('account_verified', $in)) {
            Setting::put('account_verified', !empty($in['account_verified']) ? '1' : '0');
        }
        if (array_key_exists('consent_enabled', $in)) {
            Setting::put('consent_enabled', !empty($in['consent_enabled']) ? '1' : '0');
        }
        // Red de seguridad de envíos
        if (array_key_exists('outbound_paused', $in)) {
            Setting::put('outbound_paused', !empty($in['outbound_paused']) ? '1' : '0');
        }
        if (array_key_exists('daily_send_cap', $in)) {
            Setting::put('daily_send_cap', (string) max(0, (int) $in['daily_send_cap']));
        }
        return response()->json(['ok' => true]);
    }

    protected function test(WhatsAppService $wa)
    {
        $phoneId = Setting::get('wa_phone_number_id');
        [$code, $res] = $wa->graph('GET', (string) $phoneId, null, [
            'fields' => 'verified_name,display_phone_number,quality_rating,platform_type',
        ]);
        if ($code >= 200 && $code < 300 && !empty($res['display_phone_number'])) {
            return response()->json(['ok' => true, 'info' => $res]);
        }
        return response()->json(['ok' => false, 'error' => $res['error']['message'] ?? 'No se pudo conectar']);
    }

    /** Texto por defecto del mensaje de consentimiento. */
    public static function consentDefault(): string
    {
        return "¡Hola {{{senderName}}}! 👋\n\n"
            . "Gracias por escribirnos. En [Tu Empresa] abrimos este canal de WhatsApp para estar más cerca de ti y ofrecerte de forma más cómoda nuestros productos y servicios, así como ventajas exclusivas.\n\n"
            . "Puedes consultar nuestra Política de Privacidad en [Enlace a tu web]. "
            . "Si no deseas recibir nuestras ofertas y novedades por este canal de WhatsApp, pulsa *BAJA* y dejaremos de escribirte por aquí.\n\n"
            . "Pulsa *Acepto* para continuar.";
    }
}
