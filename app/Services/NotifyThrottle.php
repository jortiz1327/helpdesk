<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Pausa entre avisos del MISMO ticket. En WhatsApp un cliente escribe «hola», «cómo va»,
 * «todo bien?» en diez segundos: sin esto son tres correos y tres pitidos. Cada agente
 * elige, por canal de entrada (web, correo, WhatsApp), cuántos minutos esperar antes de
 * volver a avisarle de ese ticket, dentro del rango que fija el admin.
 */
class NotifyThrottle
{
    public const CANALES = ['web', 'email', 'whatsapp'];

    /** Rango permitido [min, max] en minutos (por defecto 1-30). */
    public static function rango(): array
    {
        $min = max(0, min(60, (int) Setting::get('notify_throttle_min', '1')));
        $max = max($min, min(60, (int) Setting::get('notify_throttle_max', '30')));
        return [$min, $max];
    }

    /** Minutos por canal de un usuario, ya acotados al rango (sin preferencia = el mínimo). */
    public static function deUsuario($raw): array
    {
        [$min, $max] = self::rango();
        $prefs = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $out = [];
        foreach (self::CANALES as $c) {
            $out[$c] = isset($prefs[$c]) ? max($min, min($max, (int) $prefs[$c])) : $min;
        }
        return $out;
    }

    /**
     * Canales de los que el agente QUIERE avisos ({"web":true,…}). Se guarda en el mismo
     * JSON como lista «off»: los que no aparecen están activos (por defecto, todos).
     */
    public static function activos($raw): array
    {
        $prefs = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $off = (array) ($prefs['off'] ?? []);
        $out = [];
        foreach (self::CANALES as $c) $out[$c] = !in_array($c, $off, true);
        return $out;
    }

    /** JSON a guardar: minutos por canal (acotados) + lista de canales apagados. */
    public static function empaquetar(array $minutos, array $activos): string
    {
        $out = self::deUsuario($minutos);
        $out['off'] = array_values(array_filter(self::CANALES, fn($c) => empty($activos[$c])));
        return json_encode($out);
    }

    /** Canal de entrada de un ticket a efectos de avisos (portal y formularios = web). */
    public static function canal(?string $channel): string
    {
        return in_array($channel, self::CANALES, true) ? $channel : 'web';
    }

    /**
     * ¿Se le puede mandar ya el CORREO de este ticket? Si sí, queda apuntado y el siguiente
     * del mismo ticket espera los minutos que haya elegido. Atómico (Cache::add): dos
     * mensajes simultáneos no cuelan dos correos.
     */
    public static function permitirCorreo(int $userId, int $ticketId, string $canal, $prefs): bool
    {
        $min = self::deUsuario($prefs)[self::canal($canal)];
        if ($min <= 0) return true;
        return Cache::add("notif_mail:$userId:$ticketId", 1, $min * 60);
    }

    /** Apunta un correo ya enviado (p. ej. «se te ha asignado») para que el siguiente espere. */
    public static function marcarCorreo(int $userId, int $ticketId, string $canal, $prefs): void
    {
        $min = self::deUsuario($prefs)[self::canal($canal)];
        if ($min > 0) Cache::put("notif_mail:$userId:$ticketId", 1, $min * 60);
    }
}
