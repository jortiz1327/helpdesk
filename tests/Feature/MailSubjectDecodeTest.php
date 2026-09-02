<?php

namespace Tests\Feature;

use App\Services\MailService;
use Tests\TestCase;

/**
 * Un cliente que REENVÍA suele mandar el asunto codificado en MIME («=?utf-8?B?…?=»).
 * Si no se decodifica: el ticket sale con el churro de asunto Y no se reconoce como
 * reenvío → se recorta la conversación → ticket casi vacío. decodeSubject lo arregla.
 */
class MailSubjectDecodeTest extends TestCase
{
    private function llamar(string $metodo, $arg)
    {
        $m = new \ReflectionMethod(MailService::class, $metodo);
        $m->setAccessible(true);
        return $m->invoke(null, $arg);
    }

    public function test_decodifica_un_asunto_mime_y_detecta_el_reenvio(): void
    {
        // «RV: [ETIQUETAS ELECTRÓNICAS] Configuración USB_Dongle» codificado (caso real).
        $raw = '=?utf-8?B?UlY6IFtFVElRVUVUQVMgRUxFQ1RSw5NOSUNBU10gQ29uZmlndXJhY2nDs24g?= =?utf-8?Q?USB_Dongle?=';

        $dec = $this->llamar('decodeSubject', $raw);

        $this->assertStringStartsWith('RV: [ETIQUETAS ELECTRÓNICAS]', $dec);
        $this->assertTrue($this->llamar('esReenvio', $dec));   // ahora SÍ se ve como reenvío
    }

    public function test_un_asunto_ya_legible_no_se_toca(): void
    {
        $this->assertSame('Configuración USB', $this->llamar('decodeSubject', 'Configuración USB'));
        $this->assertSame('', $this->llamar('decodeSubject', ''));
    }
}
