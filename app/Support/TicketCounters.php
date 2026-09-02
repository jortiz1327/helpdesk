<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * CONTADORES DE LA BANDEJA — invalidación por versión global.
 *
 * Los contadores de las vistas rápidas se cachean 15 s por agente
 * (`tk.counts.{id}.v{N}`). El problema: una acción de UN agente (asignar, cambiar
 * de estado, responder…) cambia los contadores de VARIOS, así que borrar solo la
 * clave del actor no basta. En vez de recorrer usuario por usuario, se sube una
 * VERSIÓN global: todas las claves `…v{N}` quedan inalcanzables de golpe y se
 * recomputan en la siguiente lectura (las viejas caducan solas por su TTL). Es O(1).
 */
class TicketCounters
{
    private const VER_KEY = 'tk.counts.ver';

    /** Versión actual (1 por defecto, antes de la primera invalidación). */
    public static function version(): int
    {
        return (int) Cache::get(self::VER_KEY, 1);
    }

    /** Clave de caché de los contadores de un agente, ya versionada. */
    public static function key(int $userId): string
    {
        return "tk.counts.{$userId}.v" . self::version();
    }

    /**
     * Sube la versión → invalida los contadores cacheados de TODOS los agentes.
     * `add` asegura que la clave exista (persistente); `increment` es atómico, así
     * que dos acciones simultáneas no se pisan. La primera invalidación pasa de la
     * v1 por defecto a la v2, de modo que sí invalida lo cacheado antes.
     */
    public static function bump(): void
    {
        Cache::add(self::VER_KEY, 1);
        Cache::increment(self::VER_KEY);
    }
}
