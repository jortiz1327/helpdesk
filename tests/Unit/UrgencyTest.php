<?php

namespace Tests\Unit;

use App\Support\Urgency;
use PHPUnit\Framework\TestCase;

class UrgencyTest extends TestCase
{
    public function test_un_hotel_sin_servicio_es_urgencia_alta(): void
    {
        $r = Urgency::detectar('Buenas, ninguna etiqueta del buffet funciona y tenemos el desayuno en una hora.');
        $this->assertSame('alta', $r['level']);
        $this->assertContains('Parece que no funciona nada o casi nada', $r['reasons']);
    }

    public function test_piden_urgencia_con_un_problema_es_alta(): void
    {
        $this->assertSame('alta', Urgency::detectar('Es urgente, las antenas están sin conexión')['level']);
    }

    public function test_servicio_de_hoy_con_problema_es_alta_y_sin_hoy_es_media(): void
    {
        $this->assertSame('alta', Urgency::detectar('Hoy tenemos una boda y el menú no cambia en las pantallas')['level']);
        $this->assertSame('media', Urgency::detectar('El menú del buffet no cambia en las pantallas')['level']);
    }

    public function test_decir_que_no_es_urgente_o_una_consulta_normal_no_avisa(): void
    {
        $this->assertNull(Urgency::detectar('No es urgente, pero cuando podáis me decís cómo cambiar un logo.'));
        $this->assertNull(Urgency::detectar('Hola, quería saber cómo añadir un usuario nuevo al hotel. Gracias.'));
        $this->assertNull(Urgency::detectar('ok'));
    }

    public function test_pedir_prisa_sin_problema_es_media(): void
    {
        $this->assertSame('media', Urgency::detectar('Necesitamos el presupuesto cuanto antes por favor')['level']);
    }
}
