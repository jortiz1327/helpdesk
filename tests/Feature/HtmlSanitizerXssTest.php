<?php

namespace Tests\Feature;

use App\Services\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * INVARIANTE DE SEGURIDAD: el HTML que entra (editor y correos) se sanea por lista
 * blanca. Un XSS que se colara aquí se ejecutaría en el navegador de un agente con
 * sesión: es de los fallos más caros de recuperar. Se comprueban los vectores típicos
 * en los DOS perfiles (clean = editor estricto, cleanEmail = correo permisivo) + toText.
 */
class HtmlSanitizerXssTest extends TestCase
{
    #[DataProvider('vectores')]
    public function test_clean_neutraliza_vectores_xss(string $entrada): void
    {
        $out = HtmlSanitizer::clean($entrada);
        $this->assertNoXss($out);
    }

    #[DataProvider('vectores')]
    public function test_clean_email_neutraliza_vectores_xss(string $entrada): void
    {
        $out = HtmlSanitizer::cleanEmail($entrada);
        $this->assertNoXss($out);
    }

    public static function vectores(): array
    {
        return [
            'script'          => ['<script>alert(1)</script>'],
            'img onerror'     => ['<img src=x onerror="alert(1)">'],
            'href javascript' => ['<a href="javascript:alert(1)">pincha</a>'],
            'svg onload'      => ['<svg/onload=alert(1)>'],
            'iframe'          => ['<iframe src="javascript:alert(1)"></iframe>'],
            'style expression'=> ['<div style="background:url(javascript:alert(1))">x</div>'],
            'onmouseover'     => ['<b onmouseover="alert(1)">hola</b>'],
        ];
    }

    private function assertNoXss(string $out): void
    {
        $low = mb_strtolower($out);
        $this->assertStringNotContainsString('<script', $low);
        $this->assertStringNotContainsString('onerror', $low);
        $this->assertStringNotContainsString('onload', $low);
        $this->assertStringNotContainsString('onmouseover', $low);
        $this->assertStringNotContainsString('javascript:', $low);
        $this->assertStringNotContainsString('<iframe', $low);
    }

    public function test_conserva_el_contenido_seguro(): void
    {
        // El saneador NO debe cargarse el formato legítimo.
        $out = HtmlSanitizer::clean('<p>Hola <b>mundo</b> y <a href="https://aemegroup.com">enlace</a></p>');
        $this->assertStringContainsString('<b>mundo</b>', $out);
        $this->assertStringContainsString('href="https://aemegroup.com"', $out);

        // cleanEmail es más permisivo: conserva tablas (firmas de correo).
        $email = HtmlSanitizer::cleanEmail('<table><tr><td>Empresa S.L.</td></tr></table><script>alert(1)</script>');
        $this->assertStringContainsString('<table>', $email);
        $this->assertStringNotContainsString('alert(1)', $email);
    }

    public function test_to_text_no_arrastra_script(): void
    {
        $t = HtmlSanitizer::toText('<script>alert(1)</script><p>Buenas tardes</p>');
        $this->assertStringContainsString('Buenas tardes', $t);
        $this->assertStringNotContainsString('alert(1)', $t);
    }
}
