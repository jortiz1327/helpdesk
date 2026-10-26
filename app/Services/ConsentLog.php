<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Registro de consentimientos y bajas de CAMPAÑAS (RGPD). Una línea por cambio, con fecha
 * y origen, para poder demostrar cuándo y cómo aceptó o se dio de baja un contacto.
 * Solo lo escriben los puntos de Campañas (WhatsApp del 51, enlace de baja del correo,
 * un agente desde Contactos › Campañas). El Helpdesk no lo toca ni lo ve.
 */
class ConsentLog
{
    public const EVENTOS = ['consent' => 'Acepta comunicaciones', 'optout' => 'Baja', 'optin' => 'Vuelve a suscribirse'];
    public const ORIGENES = ['whatsapp' => 'WhatsApp', 'email' => 'Enlace del correo', 'agente' => 'Un agente', 'previo' => 'Anterior al registro'];

    public static function anotar(int $contactId, string $evento, string $origen, ?int $userId = null): void
    {
        try {
            DB::table('consent_log')->insert([
                'contact_id' => $contactId, 'event' => $evento, 'source' => $origen,
                'user_id' => $userId, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Nunca romper una baja por no poder anotarla (la baja en la ficha es lo que cuenta).
            report($e);
        }
    }
}
