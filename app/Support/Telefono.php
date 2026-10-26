<?php

namespace App\Support;

/**
 * Teléfono en UN solo formato: solo cifras y CON prefijo de país (como lo manda
 * WhatsApp: 34612345678). Antes el portal, la creación a mano y la importación de Faveo
 * guardaban «612345678» y WhatsApp «34612345678»: la misma persona acababa con dos fichas.
 */
class Telefono
{
    /** Prefijo que se asume para un número nacional de 9 cifras (España). */
    public const PAIS = '34';

    public static function normalizar(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($d, '00')) $d = substr($d, 2);           // 0034… → 34…
        if ($d === '') return null;
        // Número español sin prefijo: 9 cifras que empiezan por 6, 7, 8 o 9.
        if (strlen($d) === 9 && preg_match('/^[6-9]/', $d)) $d = self::PAIS . $d;
        return $d;
    }
}
