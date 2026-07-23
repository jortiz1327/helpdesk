<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/** Candados de funciones bloqueadas por Meta hasta verificar el negocio. Portado de includes/gating.php. */
class GatingService
{
    /** Registro de funciones capadas y sus motivos. */
    public static function features(): array
    {
        return [
            'flow_publish' => [
                'title'   => 'Publicar formularios como Flow de WhatsApp',
                'reasons' => [
                    'Requiere el negocio verificado en Meta Business Suite.',
                    'La cuenta de WhatsApp de prueba bloquea la publicación de Flows (Blocked by Integrity, code 139000).',
                    'Cambia a la WABA real una vez verificada.',
                ],
            ],
            'flow_send' => [
                'title'   => 'Enviar formularios nativos a clientes',
                'reasons' => [
                    'Solo se puede enviar un formulario que ya esté publicado en Meta.',
                    'La publicación está bloqueada hasta verificar el negocio.',
                ],
            ],
            'template_delete' => [
                'title'   => 'Borrar plantillas',
                'reasons' => [
                    'La cuenta de WhatsApp de prueba es compartida de Meta y no permite borrar plantillas (error #100).',
                    'Bórralas desde Meta Business Suite → WhatsApp Manager, o usa la cuenta real verificada.',
                ],
            ],
        ];
    }

    public static function accountVerified(): bool
    {
        return (string) Setting::get('account_verified') === '1';
    }

    /** Motivos si la función está capada, o null si está permitida. */
    public static function locked(string $feature): ?array
    {
        if (self::accountVerified()) {
            return null;
        }
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
                'error'   => $g['title'] . ' no está disponible: la cuenta de Meta no está verificada.',
                'reasons' => $g['reasons'],
            ]);
        }
        return null;
    }
}
