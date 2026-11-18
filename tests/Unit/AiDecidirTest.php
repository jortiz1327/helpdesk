<?php

namespace Tests\Unit;

use App\Services\Ai\AiSuggestionService;
use PHPUnit\Framework\TestCase;

class AiDecidirTest extends TestCase
{
    private function conv(string $texto, bool $conRespuesta = false): array
    {
        $c = [['role' => 'user', 'text' => 'hola']];
        if ($conRespuesta) {
            $c[] = ['role' => 'assistant', 'text' => '¿Con qué correo accede?'];
            $c[] = ['role' => 'user', 'text' => $texto];
        }
        return $c;
    }

    public function test_alergenos_y_totems_siempre_derivan(): void
    {
        $d = AiSuggestionService::decidir('Las etiquetas no muestran los alérgenos', $this->conv('x'), null, false);
        $this->assertSame('derivar', $d['decision']);
        $this->assertStringContainsString('[[DERIVAR:soporte|alérgenos]]', $d['instruccion']);
        $this->assertSame('derivar', AiSuggestionService::decidir('Queremos un tótem nuevo', $this->conv('x'), 'verificado', true)['decision']);
    }

    public function test_garantia_deriva_pero_la_de_farmacia_la_decide_el_modelo(): void
    {
        $this->assertSame('garantias', explode('|', explode('DERIVAR:', AiSuggestionService::decidir('Queremos la garantía', $this->conv('x'), null, false)['instruccion'])[1])[0]);
        $this->assertNull(AiSuggestionService::decidir('Garantía de las etiquetas de la farmacia', $this->conv('x'), null, false));
    }

    public function test_permisos_con_hotel_identificado_derivan_sin_preguntar(): void
    {
        $d = AiSuggestionService::decidir('Necesitamos ampliar permisos al jefe de cocina', $this->conv('x'), null, true);
        $this->assertSame('derivar', $d['decision']);
        // Sin hotel identificado no se decide en código.
        $this->assertNull(AiSuggestionService::decidir('Necesitamos ampliar permisos al jefe de cocina', $this->conv('x'), null, false));
    }

    public function test_problema_de_uso_sin_hotel_ni_correo_pide_datos_solo_la_primera_vez(): void
    {
        $d = AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x'), null, false);
        $this->assertSame('pedir_datos', $d['decision']);
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x', true), null, false), 'en un seguimiento decide el modelo');
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x'), null, true), 'con hotel identificado decide el modelo');
        $this->assertNull(AiSuggestionService::decidir('Las etiquetas no se actualizan', $this->conv('x'), 'verificado', false), 'con correo verificado decide el modelo');
    }

    public function test_un_mensaje_sin_tema_claro_lo_decide_el_modelo(): void
    {
        $this->assertNull(AiSuggestionService::decidir('Gracias, ya funciona', $this->conv('x'), null, false));
        $this->assertNull(AiSuggestionService::decidir('Necesito 30 pilas', $this->conv('x'), null, true));
    }
}
