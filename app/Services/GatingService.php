<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/** Candados de funciones bloqueadas por Meta hasta verificar el negocio. Portado de includes/gating.php. */
class GatingService
{
    /**
     * Registro de funciones capadas AHORA MISMO y sus motivos. Dos fuentes:
     *  · negocio sin verificar en Meta  → publicar/enviar Flows, borrar plantillas;
     *  · WhatsApp SIN configurar (sin credenciales) → todo lo que envía por WhatsApp
     *    (responder, campañas, plantillas). Esto es lo que deja el entorno de demo en
     *    solo lectura; se desbloquea solo al rellenar las credenciales.
     */
    public static function features(): array
    {
        $f = [];

        if (!self::accountVerified()) {
            $f['flow_publish'] = [
                'title'   => 'Publicar formularios como Flow de WhatsApp',
                'reasons' => [
                    'Requiere el negocio verificado en Meta Business Suite.',
                    'La cuenta de WhatsApp de prueba bloquea la publicación de Flows (Blocked by Integrity, code 139000).',
                    'Cambia a la WABA real una vez verificada.',
                ],
            ];
            $f['flow_send'] = [
                'title'   => 'Enviar formularios nativos a clientes',
                'reasons' => [
                    'Solo se puede enviar un formulario que ya esté publicado en Meta.',
                    'La publicación está bloqueada hasta verificar el negocio.',
                ],
            ];
            $f['template_delete'] = [
                'title'   => 'Borrar plantillas',
                'reasons' => [
                    'La cuenta de WhatsApp de prueba es compartida de Meta y no permite borrar plantillas (error #100).',
                    'Bórralas desde Meta Business Suite → WhatsApp Manager, o usa la cuenta real verificada.',
                ],
            ];
        }

        if (!self::whatsappConfigured()) {
            $motivo = [
                'WhatsApp no está configurado en este entorno.',
                'Rellena las credenciales en Configuración → WhatsApp para activar el envío.',
            ];
            $f['wa_send']     = ['title' => 'Responder y enviar por WhatsApp', 'reasons' => $motivo];
            $f['wa_campaign'] = ['title' => 'Lanzar campañas y difusiones', 'reasons' => $motivo];
            $f['wa_template'] = ['title' => 'Crear, editar y enviar plantillas', 'reasons' => $motivo];
            $f['wa_flow']     = ['title' => 'Publicar y enviar formularios (Flows)', 'reasons' => $motivo];
        }

        return $f;
    }

    public static function accountVerified(): bool
    {
        return (string) Setting::get('account_verified') === '1';
    }

    /** ¿Hay credenciales de WhatsApp (token + phone number id)? */
    public static function whatsappConfigured(): bool
    {
        return (string) Setting::get('wa_token', '') !== '' && (string) Setting::get('wa_phone_number_id', '') !== '';
    }

    /** Motivos si la función está capada AHORA, o null si está permitida. */
    public static function locked(string $feature): ?array
    {
        return self::features()[$feature] ?? null;
    }

    /** Devuelve una respuesta de "candado" si la función está capada, o null si permitida. */
    public static function guard(string $feature): ?JsonResponse
    {
        $g = self::locked($feature);
        if ($g) {
            return response()->json([
                'ok'      => false,
                'locked'  => true,
                'feature' => $feature,
                'error'   => $g['title'] . ' no está disponible en este entorno.',
                'reasons' => $g['reasons'],
            ]);
        }
        return null;
    }
}
