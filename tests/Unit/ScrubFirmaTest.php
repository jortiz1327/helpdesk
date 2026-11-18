<?php

namespace Tests\Unit;

use App\Services\Ai\Scrub;
use PHPUnit\Framework\TestCase;

class ScrubFirmaTest extends TestCase
{
    public function test_corta_el_aviso_legal_del_final(): void
    {
        $t = "Las etiquetas no se actualizan.\n\nAviso legal: este mensaje es confidencial y va dirigido a su destinatario.\nSegunda línea del aviso.";
        $this->assertSame('Las etiquetas no se actualizan.', Scrub::firma($t));
    }

    public function test_quita_el_bloque_de_firma_tras_el_cierre(): void
    {
        $t = "Buenos días, la etiqueta SC-06 no funciona.\n\nUn saludo\nMarta Gómez\nJefa de cocina\nHotel Ejemplo\nCalle Mayor 12\n28001 Madrid\nTel. 91 000 00 00";
        $r = Scrub::firma($t);
        $this->assertStringContainsString('la etiqueta SC-06 no funciona', $r);
        $this->assertStringNotContainsString('Marta', $r);
        $this->assertStringNotContainsString('Calle Mayor', $r);
        $this->assertStringNotContainsString('28001', $r);
        $this->assertStringNotContainsString('Tel.', $r);
    }

    public function test_quita_lineas_sueltas_de_direccion_telefono_web_y_cargo(): void
    {
        $t = "Necesitamos ayuda.\nAvenida de la Constitución 45\nwww.hotelejemplo.com\nTfno: 600 000 000\nDirector general\nGracias por vuestra ayuda con el problema de las etiquetas";
        $r = Scrub::firma($t);
        $this->assertStringContainsString('Necesitamos ayuda.', $r);
        $this->assertStringContainsString('Gracias por vuestra ayuda con el problema', $r);
        $this->assertStringNotContainsString('Constitución', $r);
        $this->assertStringNotContainsString('www.', $r);
        $this->assertStringNotContainsString('Tfno', $r);
        $this->assertStringNotContainsString('Director', $r);
    }

    public function test_no_toca_un_mensaje_normal(): void
    {
        $t = "Hola, ¿cómo se asigna una etiqueta a una posición?\nGracias";
        $this->assertSame($t, Scrub::firma($t));
    }

    public function test_correo_con_saltos_de_windows_y_aviso_pegado_a_la_firma(): void
    {
        $t = "Buenos días,

Las etiquetas no cambian.

Saludos,

Ana
Directora
03188 Torrevieja (Alicante)
Tel. 966921711 - Fax 

Aviso Legal: De conformidad con el Reglamento (UE) 2016/679, Ud. puede ejercer sus derechos";
        $r = Scrub::firma($t);
        $this->assertStringContainsString('Las etiquetas no cambian', $r);
        $this->assertStringNotContainsString('Aviso Legal', $r);
        $this->assertStringNotContainsString('Torrevieja', $r);
    }

    public function test_una_linea_larga_sin_saltos_no_se_borra_entera(): void
    {
        $t = 'Buenos días, las etiquetas del buffet de planta baja siguen sin actualizarse desde ayer por la tarde y no sabemos qué hacer para que vuelvan a funcionar, el hotel está en 03188 Torrevieja Alicante y mañana abrimos.';
        $this->assertStringContainsString('siguen sin actualizarse', Scrub::firma($t));
    }
}
