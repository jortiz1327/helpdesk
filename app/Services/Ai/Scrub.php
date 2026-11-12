<?php

namespace App\Services\Ai;

/** Tapa datos personales de un texto antes de que salga hacia un proveedor o se guarde como conocimiento. */
class Scrub
{
    public static function tapar(string $texto, ?string $nombre = null): string
    {
        $t = (string) preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[email]', $texto);
        $t = (string) preg_replace('/\+?\d[\d .\/-]{6,}\d/', '[tel]', $t);
        $nombre = trim((string) $nombre);
        if (mb_strlen($nombre) >= 3) {
            $t = str_ireplace($nombre, '[cliente]', $t);
            // Y cada palabra del nombre por separado: «Hola Marta» también delata a «Marta Gómez».
            foreach (preg_split('/\s+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
                if (mb_strlen($parte) >= 3) {
                    $t = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($parte, '/') . '(?![\p{L}\p{N}])/iu', '[cliente]', $t);
                }
            }
        }
        return $t;
    }

    /** HTML de un correo → texto plano. */
    public static function plano(string $html): string
    {
        $h = preg_replace('#<(br|/p|/div|/li)\s*/?>#i', "\n", $html);
        $h = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = preg_replace("/[ \t]+/", ' ', (string) $h);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $h));
    }
}
