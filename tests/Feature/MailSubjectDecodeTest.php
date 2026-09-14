<?php

namespace Tests\Feature;

use App\Services\MailService;
use Tests\TestCase;

/**
 * Un cliente que REENVÍA suele mandar el asunto codificado en MIME («=?utf-8?B?…?=»).
 * Si no se decodifica: el ticket sale con el churro de asunto Y no se reconoce como
 * reenvío → se recorta la conversación → ticket casi vacío. decodeHeader lo arregla.
 *
 * Lo mismo pasa con el NOMBRE del remitente: sin decodificar, el contacto quedaba como
 * «=?UTF-8?Q?Fusi=C3=B3n_Ribera?=» en la bandeja.
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

        $dec = $this->llamar('decodeHeader', $raw);

        $this->assertStringStartsWith('RV: [ETIQUETAS ELECTRÓNICAS]', $dec);
        $this->assertTrue($this->llamar('esReenvio', $dec));   // ahora SÍ se ve como reenvío
    }

    public function test_un_asunto_ya_legible_no_se_toca(): void
    {
        $this->assertSame('Configuración USB', $this->llamar('decodeHeader', 'Configuración USB'));
        $this->assertSame('', $this->llamar('decodeHeader', ''));
    }

    /** Nombres de remitente reales que llegaron codificados, en Q y en B. */
    public function test_decodifica_el_nombre_del_remitente(): void
    {
        $casos = [
            '=?UTF-8?Q?Fusi=C3=B3n_Ribera?='           => 'Fusión Ribera',
            '=?utf-8?Q?Miguel_Trav=C3=A9?='            => 'Miguel Travé',
            '=?utf-8?B?TWFyaWEgUGVyacOxYW4=?='         => 'Maria Periñan',
            '=?UTF-8?B?SVbDgU4gTE9SRU5URSBHQVJDw41B?=' => 'IVÁN LORENTE GARCÍA',
        ];

        foreach ($casos as $raw => $esperado) {
            $this->assertSame($esperado, $this->llamar('decodeHeader', $raw), "Decodificando {$raw}");
        }
    }

    public function test_un_nombre_ya_legible_no_se_toca(): void
    {
        $this->assertSame('Fusión Ribera', $this->llamar('decodeHeader', 'Fusión Ribera'));
        $this->assertSame('Juan Pérez', $this->llamar('decodeHeader', '  Juan Pérez  '));
    }
}
