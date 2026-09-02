<?php

namespace Tests\Feature;

use App\Services\MailService;
use Tests\TestCase;

/**
 * Al REENVIAR un hilo, el correo arrastra el mismo bloque (firma, aviso legal…) una
 * vez por cada mensaje citado. stripQuoted conserva el hilo (para no vaciar el ticket)
 * pero colapsa esas copias repetidas: deja la primera y quita las demás. Los mensajes
 * DISTINTOS del hilo se conservan, y las líneas cortas repetidas NO se tocan.
 */
class MailForwardCollapseTest extends TestCase
{
    private function stripQuoted(string $html, string $subject): string
    {
        $m = new \ReflectionMethod(MailService::class, 'stripQuoted');
        $m->setAccessible(true);
        return $m->invoke(null, $html, $subject);
    }

    public function test_colapsa_la_firma_repetida_pero_conserva_los_mensajes(): void
    {
        $firma = '<div>Juan Perez - Soporte Tecnico - Etiquetas Electronicas S.L. - Tel 900 123 456</div>';
        $html = '<div>Hola, me ayudais con esto? Os reenvio el hilo de abajo, gracias.</div>'
            . '<hr>'
            . '<div>Primer mensaje del hilo, con su contenido propio bien distinto.</div>' . $firma
            . '<hr>'
            . '<div>Segundo mensaje del hilo, otro contenido diferente por aqui.</div>' . $firma
            . '<hr>'
            . '<div>Tercer mensaje, y aqui se termina el hilo con algo mas de texto.</div>' . $firma;

        $out = $this->stripQuoted($html, 'RV: incidencia dongle');

        // La firma (bloque con sustancia, repetido 3x) queda una sola vez.
        $this->assertSame(1, substr_count($out, 'Tel 900 123 456'), 'la firma no se colapsó a una sola copia');

        // Los tres mensajes distintos siguen ahí.
        $this->assertStringContainsString('Primer mensaje del hilo', $out);
        $this->assertStringContainsString('Segundo mensaje del hilo', $out);
        $this->assertStringContainsString('Tercer mensaje', $out);
    }

    public function test_no_toca_lineas_cortas_repetidas(): void
    {
        // «Gracias» (< 25 caracteres) se repite de forma legítima: no se debe borrar.
        $html = '<div>Os reenvio la conversacion completa para que le echeis un vistazo.</div>'
            . '<div>Gracias</div><hr><div>Un mensaje del hilo con contenido suficiente aqui.</div><div>Gracias</div>';

        $out = $this->stripQuoted($html, 'FW: consulta');

        $this->assertSame(2, substr_count($out, 'Gracias'), 'se borró una línea corta repetida');
    }

    public function test_reenvio_sin_repeticiones_no_se_altera(): void
    {
        $html = '<div>Os reenvio esta consulta de un cliente para que la gestioneis.</div>'
            . '<hr><div>Contenido original del correo reenviado, sin nada repetido.</div>';

        $out = $this->stripQuoted($html, 'RV: consulta cliente');

        $this->assertSame($html, $out);   // sin repeticiones → intacto
    }
}
