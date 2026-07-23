<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Saneador de HTML por LISTA BLANCA.
 *
 * El editor de respuestas produce HTML. Guardarlo tal cual y pintarlo después
 * sería un XSS de manual: bastaría con que alguien pegara un <script> (o un
 * <img onerror=…>, o un <a href="javascript:…">) para ejecutar código en el
 * navegador de cualquier compañero que abra el ticket.
 *
 * Aquí se hace lo contrario de una lista negra: se PERMITE lo poco que sirve
 * y **todo lo demás se elimina**. Una lista negra siempre se queda corta; una
 * lista blanca no.
 *
 * Hay DOS perfiles sobre el mismo motor (run()):
 *   · clean()      → HTML de NUESTRO editor (estricto: negrita, listas, enlaces,
 *                    y solo imágenes en línea nuestras).
 *   · cleanEmail() → HTML de CORREOS entrantes (permisivo pero seguro: tablas,
 *                    spans, encabezados y estilos en línea de una lista blanca,
 *                    para que el mensaje se vea como en el cliente de correo).
 */
class HtmlSanitizer
{
    /** Perfil EDITOR: etiquetas permitidas => atributos permitidos en cada una. */
    protected const ALLOWED = [
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
        's' => [], 'strike' => [], 'del' => [],                 // tachado
        'p' => ['style'], 'br' => [], 'div' => ['style'],        // style: solo text-align (safeStyle)
        'ul' => [], 'ol' => [], 'li' => ['style'],
        'blockquote' => ['style'],                               // cita
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'class'],                        // solo imágenes EN LÍNEA nuestras
    ];

    /**
     * Perfil CORREO: lista blanca amplia para renderizar HTML de correos.
     * Incluye tablas, spans y encabezados (los usa casi cualquier firma/plantilla).
     * El atributo `style` se recorta a propiedades seguras en safeStyleEmail().
     */
    protected const ALLOWED_EMAIL = [
        'p' => ['style'], 'br' => [], 'div' => ['style'], 'span' => ['style'],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
        's' => [], 'strike' => [], 'del' => [], 'sub' => [], 'sup' => [],
        'small' => [], 'mark' => [], 'font' => [], 'center' => [],
        'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
        'ul' => ['style'], 'ol' => ['style'], 'li' => ['style'], 'dl' => [], 'dt' => [], 'dd' => [],
        'blockquote' => ['style'], 'pre' => ['style'], 'code' => [], 'hr' => [],
        'a' => ['href', 'title', 'style'],
        'img' => ['src', 'alt', 'width', 'height', 'style'],
        'table' => ['style', 'width', 'align', 'cellpadding', 'cellspacing', 'border', 'bgcolor'],
        'thead' => [], 'tbody' => [], 'tfoot' => [], 'caption' => ['style'],
        'tr' => ['style', 'align', 'valign', 'bgcolor'],
        'td' => ['style', 'colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'bgcolor'],
        'th' => ['style', 'colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'bgcolor'],
    ];

    /**
     * Perfil EDITOR: de un style solo se conserva `text-align`.
     * Nada de background/position/expression/url(...), etc.
     */
    protected static function safeStyle(string $style): string
    {
        if (preg_match('/text-align\s*:\s*(left|right|center|justify)/i', $style, $m)) {
            return 'text-align: ' . strtolower($m[1]);
        }
        return '';
    }

    /**
     * Perfil CORREO: conserva solo propiedades de PRESENTACIÓN seguras
     * (color, fuente, alineación, bordes, espaciados, tamaños). Descarta
     * cualquier declaración con url(), expression(), javascript:, @import o «<»
     * (vectores de fuga de datos o de ejecución). NO se permite `position`.
     */
    protected static function safeStyleEmail(string $style): string
    {
        static $allow = [
            'color', 'background-color', 'background', 'text-align', 'text-decoration', 'text-transform',
            'font', 'font-weight', 'font-style', 'font-size', 'font-family', 'line-height', 'letter-spacing',
            'vertical-align', 'white-space', 'list-style', 'list-style-type',
            'width', 'max-width', 'min-width', 'height', 'max-height',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
            'border-width', 'border-style', 'border-color', 'border-radius', 'border-collapse', 'border-spacing',
            'display',
        ];

        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (strpos($decl, ':') === false) continue;
            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val  = trim($val);
            if ($prop === '' || $val === '' || !in_array($prop, $allow, true)) continue;
            if (preg_match('/url\s*\(|expression\s*\(|javascript:|@import|<|>/i', $val)) continue;
            $out[] = $prop . ': ' . mb_substr($val, 0, 120);
        }
        return implode('; ', $out);
    }

    /** Perfil EDITOR (por defecto). */
    public static function clean(?string $html): string
    {
        return self::run($html, self::ALLOWED, [self::class, 'safeStyle'], function (DOMElement $img): bool {
            // SOLO imágenes en línea nuestras (ruta firmada /api/inline/N?...).
            $src = trim((string) $img->getAttribute('src'));
            if (!preg_match('#^(?:https?://[^/]+)?/api/inline/\d+\?#i', $src)) return false;
            // class: solo un tamaño de la lista blanca
            if (!in_array(trim((string) $img->getAttribute('class')), ['sz-100', 'sz-50', 'sz-25'], true)) {
                $img->removeAttribute('class');
            }
            return true;
        });
    }

    /**
     * Perfil CORREO. Se conservan SOLO las imágenes en línea del correo ya
     * resueltas: las «cid:» de la firma se reescriben antes a nuestra ruta firmada
     * (/api/attachment_inline/N?...). Cualquier otra imagen (externa de tracking, o
     * un «cid:» sin resolver) se elimina.
     */
    public static function cleanEmail(?string $html): string
    {
        return self::run($html, self::ALLOWED_EMAIL, [self::class, 'safeStyleEmail'], function (DOMElement $img): bool {
            $src = trim((string) $img->getAttribute('src'));
            if (!preg_match('#^(?:https?://[^/]+)?/api/attachment_inline/\d+\?#i', $src)) return false;
            // Los anchos/altos y estilos en línea ya se filtran por la lista blanca;
            // aquí basta con dejar pasar la imagen resuelta.
            return true;
        });
    }

    /**
     * Motor común. Recorre el árbol, elimina lo peligroso y aplica la lista
     * blanca recibida. $imgHandler decide qué hacer con cada <img> (devuelve
     * true para conservarla —tras limpiarla— o false para eliminarla).
     */
    protected static function run(?string $html, array $allowed, callable $styleFn, callable $imgHandler): string
    {
        $html = trim((string) $html);
        if ($html === '') return '';

        // DOMDocument interpreta el HTML como Latin-1 salvo que se le diga otra cosa,
        // y con documentos de correo el truco del <?xml encoding> no siempre basta
        // (acentos → «Ã³»). Convertir cada carácter no-ASCII a entidad numérica ANTES
        // de cargar elimina toda ambigüedad de codificación (queda ASCII puro).
        $html = preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', fn ($m) => '&#' . mb_ord($m[0], 'UTF-8') . ';', $html) ?? $html;

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // El wrapper con charset evita que DOMDocument destroce los acentos.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xp   = new DOMXPath($doc);
        $root = $doc->getElementById('__root');
        if (!$root) return '';

        // Se recorre al revés: al eliminar nodos no se rompe la iteración.
        $nodes = iterator_to_array($xp->query('//*', $root));
        foreach (array_reverse($nodes) as $node) {
            if (!$node instanceof DOMElement || $node === $root) continue;

            $tag = strtolower($node->nodeName);

            // <script>, <style> y compañía: se borran ENTEROS, contenido incluido.
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'link', 'meta', 'base'], true)) {
                $node->parentNode->removeChild($node);
                continue;
            }

            // Etiqueta no permitida: se elimina el nodo pero se conserva su TEXTO.
            if (!array_key_exists($tag, $allowed)) {
                while ($node->firstChild) {
                    $node->parentNode->insertBefore($node->firstChild, $node);
                }
                $node->parentNode->removeChild($node);
                continue;
            }

            // Atributos: fuera todo lo que no esté permitido (incluidos los on*).
            foreach (iterator_to_array($node->attributes) as $attr) {
                if (!in_array(strtolower($attr->name), $allowed[$tag], true)) {
                    $node->removeAttribute($attr->name);
                }
            }

            // El style que sobreviva se recorta con la función del perfil.
            if ($node->hasAttribute('style')) {
                $safe = $styleFn($node->getAttribute('style'));
                $safe === '' ? $node->removeAttribute('style') : $node->setAttribute('style', $safe);
            }

            // Enlaces: solo http(s) y mailto. Nada de javascript: ni data:
            if ($tag === 'a') {
                $href = trim((string) $node->getAttribute('href'));
                if (!preg_match('#^(https?://|mailto:)#i', $href)) {
                    $node->removeAttribute('href');
                } else {
                    $node->setAttribute('rel', 'noopener noreferrer nofollow');
                    $node->setAttribute('target', '_blank');
                }
            }

            // Imágenes: la política la decide quien llama (perfil editor vs correo).
            if ($tag === 'img') {
                if (!$imgHandler($node)) {
                    $node->parentNode->removeChild($node);
                    continue;
                }
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** Versión en texto plano: para el resumen de la bandeja y las búsquedas. */
    public static function toText(?string $html): string
    {
        $t = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])[^>]*>#i', ' ', (string) $html);
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')));
    }
}
