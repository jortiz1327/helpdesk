<?php

namespace Tests\Unit;

use App\Services\Ai\AiKnowledge;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Palabras del cliente que apuntan a una guía por su título (medido con `php artisan ai:evaluar-busqueda`). */
class AiBusquedaSinonimosTest extends TestCase
{
    private function raices(string $texto): array
    {
        $k = (new \ReflectionClass(AiKnowledge::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($k, 'raicesPorSinonimo');
        $m->setAccessible(true);
        return $m->invoke($k, $texto);
    }

    public function test_antenas_y_conexion_apuntan_a_conectividad(): void
    {
        $this->assertContains('conectiv', $this->raices('no puedo cambiar etiquetas porque me aparece que la antena está desconectada'));
        $this->assertContains('conectiv', $this->raices('Las etiquetas no van, tenemos internet'));
        $this->assertContains('conectiv', $this->raices('estamos sin señal en el buffet'));
    }

    public function test_activar_o_asignar_una_etiqueta_apunta_a_posiciones(): void
    {
        $this->assertContains('posicion', $this->raices('necesitaríamos la activación de esta etiqueta en la posición SC-05'));
        $this->assertContains('posicion', $this->raices('estos extras los necesito en el Tuvalu 3, etiquetas SP-01'));
    }

    public function test_forzar_menu_y_buffet_reducido_apuntan_a_ruedas(): void
    {
        $this->assertContains('rued', $this->raices('Tan solo le teníamos que dar a forzar menú'));
        $this->assertContains('rued', $this->raices('cambio de buffet a buffet reducido por la baja ocupación'));
    }

    public function test_una_isla_apunta_a_isletas_y_lo_demas_no_dispara_nada(): void
    {
        $this->assertContains('isleta', $this->raices('nos ha faltado la isla de cereales'));
        $this->assertSame([], $this->raices('Quería comprar tapas para las pilas, gracias'));
        $this->assertSame(['servic'], $this->raices('el horario del desayuno'));
    }
}
