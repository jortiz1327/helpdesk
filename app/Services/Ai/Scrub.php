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

    /**
     * Quita de UN mensaje lo que no es contenido y sí es dato personal de terceros: el aviso legal del final, el bloque de firma
     * (nombre, cargo, dirección, teléfono, web) y las líneas sueltas de dirección, código postal, teléfono, web o cargo.
     * Se aplica mensaje a mensaje (no a una conversación entera: cortaría lo que viene después).
     */
    public static function firma(string $texto): string
    {
        // Líneas en blanco o con solo espacios → vacías, y como mucho una en blanco seguida.
        $t = "\n" . (string) preg_replace("/\n[ \t]*(?=\n)/", "\n", $texto);
        $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);
        // 1. Historial citado y aviso legal: desde la marca (al inicio de línea) hasta el final del mensaje.
        $t = (string) preg_replace('/\n[ \t>]*(el (lun|mar|mi[eé]|jue|vie|s[aá]b|dom)[\p{L}.]*,? .{0,80}escribi[oó]:?|on .{0,80}wrote:?|de:\s|from:\s|-{2,} ?(mensaje|original|forwarded)|_{5,}|aviso legal|de conformidad con|en cumplimiento (de|del)|este (mensaje|correo|e-?mail) (y sus|va dirigido|puede contener|contiene informaci)|la informaci[oó]n (contenida|transmitida) (en|a trav[eé]s)|this (e-?mail|message) (and|is|may|contains)|sus datos (est[aá]n|personales)|confidencialidad|antes de imprimir|please consider|enviado desde mi|sent from my)\b.*$/isu', '', $t);
        // 2. Cierre («Un saludo», «Gracias»…) y, tras él, un bloque de hasta 25 líneas hasta el final: es la firma.
        $t = (string) preg_replace('/(\n[ \t]*(?:un (?:cordial )?saludo|saludos?(?: cordiales)?(?:, saluti.*?)?|atentamente|cordialmente|muchas gracias|gracias|salut|cumprimentos|best regards|kind regards|regards)[ \t,.!]*)\n(?:[^\n]*\n?){1,25}\z/iu', '$1', $t);
        // 3. Líneas sueltas con dirección, código postal + ciudad, teléfono/fax, web, CIF o cargo.
        $lineas = preg_split('/\R/u', $t) ?: [];
        $lineas = array_filter($lineas, function ($l) {
            $x = trim($l);
            if ($x === '') {
                return true;
            }
            return ! (preg_match('/\b(calle|c\/|avda\.?|avenida|avinguda|carrer|plaza|paseo|carretera|ctra\.?|delegaci[oó]n de)\s+[\wÁÉÍÓÚáéíóúñ\' .-]{3,40},?\s*(n[ºo°.]?\s*)?\d*/iu', $x) && preg_match('/\d/', $x)
                || preg_match('/\b\d{5}\b\s*[–-]?\s*[\p{Lu}]/u', $x)
                || preg_match('/^\W*(tel|fax|m[oó]vil|tfno|cif|nif)\b/iu', $x)
                || preg_match('/^\W*(www\.|https?:\/\/)\S+\W*$/iu', $x)
                || (mb_strlen($x) <= 60 && preg_match('/^(jefe|jefa|director|directora|gerente|metre|maitre|responsable|t[eé]cnic[oa]|chef|segundo|coordinador|coordinadora|administraci[oó]n)\b/iu', $x))
                || (mb_strlen($x) <= 60 && preg_match('/\b(f&b|f\&b)\b.*(coordinator|manager)|^(f&b|food).{0,20}(coordinator|manager)/iu', $x)));
        });
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lineas)));
    }
}
