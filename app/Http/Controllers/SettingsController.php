<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/settings.php — solo admin. Dispatch por ?action=. */
class SettingsController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        if ($action === 'get') {
            return $this->get($request);
        }
        if ($action === 'save' && $request->isMethod('post')) {
            return $this->save($request);
        }
        return response()->json(['error' => 'Acción no válida'], 400);
    }

    protected function get(Request $request)
    {
        $base = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');

        // El Verify Token del webhook se AUTOGENERA si falta: nunca debe estar vacío,
        // o la verificación de Meta compararía contra "" y devolvería 403. Es un
        // secreto compartido entre la app y Meta (se pega igual en los dos sitios).
        $verifyToken = (string) Setting::get('wa_verify_token', '');
        if ($verifyToken === '') {
            $verifyToken = \Illuminate\Support\Str::random(32);
            Setting::put('wa_verify_token', $verifyToken);
        }

        // La conexión con Meta (token, phone_number_id, WABA, App Secret) se configura
        // POR NÚMERO en whatsapp_numbers; aquí solo quedan el nombre del negocio, el
        // Verify Token del webhook (global) y el mensaje de consentimiento.
        return response()->json([
            'business_name'      => Setting::get('business_name'),
            'wa_verify_token'    => $verifyToken,
            // Firma del webhook: activa si hay App Secret global (legacy) O en cualquier
            // número de la Opción B (el webhook usa el App Secret del número del evento).
            'webhook_signature_active' => (string) Setting::get('wa_app_secret', '') !== ''
                || \App\Models\WhatsAppNumber::whereNotNull('app_secret')->where('app_secret', '!=', '')->exists(),
            'account_verified'   => (string) Setting::get('account_verified', '0') === '1',
            'consent_enabled'    => (string) Setting::get('consent_enabled', '0') === '1',
            'consent_message'    => (string) Setting::get('consent_message', '') ?: self::consentDefault(),
            'webhook_url'        => $base . '/api/webhook.php',
            // Red de seguridad de envíos
            'outbound_paused'    => (string) Setting::get('outbound_paused', '0') === '1',
            'daily_send_cap'     => (int) Setting::get('daily_send_cap', '0'),
            // Tarifas de WhatsApp (EUR/mensaje) para la estimación de coste de campañas.
            'wa_prices'          => [
                'marketing'      => (float) Setting::get('wa_price_marketing', '0.06'),
                'utility'        => (float) Setting::get('wa_price_utility', '0.0166'),
                'authentication' => (float) Setting::get('wa_price_authentication', '0.0166'),
                'service'        => (float) Setting::get('wa_price_service', '0'),
            ],
            'sent_today'         => (int) DB::table('messages')
                ->where('direction', 'out')->where('type', 'template')
                ->where('created_at', '>=', now()->startOfDay())->count(),
        ]);
    }

    protected function save(Request $request)
    {
        $in = $request->all();
        $allowed = ['wa_verify_token', 'business_name', 'consent_message'];
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
        // Tarifas de WhatsApp por categoría (EUR/mensaje). No negativas.
        if (array_key_exists('wa_prices', $in) && is_array($in['wa_prices'])) {
            $mapa = [
                'marketing'      => 'wa_price_marketing',
                'utility'        => 'wa_price_utility',
                'authentication' => 'wa_price_authentication',
                'service'        => 'wa_price_service',
            ];
            foreach ($mapa as $campo => $clave) {
                if (array_key_exists($campo, $in['wa_prices'])) {
                    Setting::put($clave, (string) max(0, (float) $in['wa_prices'][$campo]));
                }
            }
        }
        return response()->json(['ok' => true]);
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
