<?php

namespace Tests\Unit;

use App\Services\Ai\AiKnowledge;
use App\Services\Ai\Scrub;
use PHPUnit\Framework\TestCase;

class AiDesfaseTest extends TestCase
{
    public function test_tapa_contrasenas_con_la_palabra_contrasena(): void
    {
        $t = Scrub::contrasenas("Usuario: pepe\nContraseña: Hotel2026x\nLa contraseña es la misma que usabas.");
        $this->assertStringNotContainsString('Hotel2026x', $t);
        $this->assertStringContainsString('Contraseña: [contraseña]', $t);
        $this->assertStringContainsString('La contraseña es la misma que usabas.', $t);   // sin clave: se queda tal cual
    }

    public function test_tapa_contrasenas_con_forma_de_palabra_y_anio_y_otros_idiomas(): void
    {
        $t = Scrub::contrasenas('Tots utilitzen la contrassenya: EdenRoc@2023 y también AluaBoccaccio@2025TV ok');
        $this->assertStringNotContainsString('EdenRoc', $t);
        $this->assertStringNotContainsString('AluaBoccaccio', $t);
    }

    public function test_no_tapa_enlaces_ni_correos_ni_texto_normal(): void
    {
        $t = Scrub::contrasenas('Para cambiar la contraseña entra en https://app.etiquetas.cloud/perfil2 y pulsa Guardar');
        $this->assertStringContainsString('https://app.etiquetas.cloud/perfil2', $t);
        $this->assertSame('[email] escribe a soporte', Scrub::contrasenas('[email] escribe a soporte'));
    }

    public function test_desfase_detecta_lo_que_hoy_hace_el_hotel(): void
    {
        $this->assertSame(1, AiKnowledge::desfase('Hemos creado el usuario para Daniela.'));
        $this->assertSame(1, AiKnowledge::desfase('Ya he cambiado la posición de la etiqueta.'));
        $this->assertSame(1, AiKnowledge::desfase('Hemos asignado el rol de cocina.'));
        $this->assertSame(0, AiKnowledge::desfase('Hemos forzado el menú, revisa si ya se ve.'));
        $this->assertSame(0, AiKnowledge::desfase('Prueba a quitar las pilas y volver a ponerlas.'));
    }
}
