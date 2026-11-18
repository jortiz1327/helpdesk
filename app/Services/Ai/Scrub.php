<?php

namespace App\Services\Ai;

/** Tapa datos personales de un texto antes de que salga hacia un proveedor o se guarde como conocimiento. */
class Scrub
{
    public static function tapar(string $texto, ?string $nombre = null): string
    {
        $t = (string) preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[email]', $texto);
        $t = (string) preg_replace('/\+?\d[\d .\/-]{6,}\d/', '[tel]', $t);
        // Enlaces con parámetros o muy largos: pueden llevar tokens o identificadores de un cliente.
        $t = (string) preg_replace_callback('#https?://[^\s)>"\]]+#i', fn ($m) => (str_contains($m[0], '?') || strlen($m[0]) > 70) ? '[enlace]' : $m[0], $t);
        $t = self::contrasenas($t);
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

    /**
     * Tapa las contraseñas que el equipo escribía en las respuestas («la contraseña es X»): tras la palabra contraseña/clave/password,
     * en la misma línea, cualquier palabra de 6 o más caracteres que mezcle letras con cifras o símbolos.
     */
    public static function contrasenas(string $texto): string
    {
        // Las contraseñas con la forma «PalabraAño» (HotelX@2024), aunque no vayan tras la palabra contraseña.
        $texto = (string) preg_replace('/(?<![\w.+-])(\[cliente\]|[\p{L}\d]{2,})@(19|20)\d\d\w*/u', '[contraseña]', $texto);
        return (string) preg_replace_callback(
            '/(contrase[ñn]a|contra?ssenya|constrase[ñn]a|password|senha|claves?|credenciales|\bpas)([^\n]{0,90})/iu',
            fn ($m) => $m[1] . preg_replace_callback('/\S{6,}/u', function ($w) {
                $x = $w[0];
                $esClave = ! str_contains($x, '/') && ! str_starts_with($x, '[') && preg_match('/\p{L}/u', $x) && preg_match('/[\d@#$%&!*]/', $x);
                return $esClave ? '[contraseña]' : $x;
            }, $m[2]),
            $texto,
        );
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
