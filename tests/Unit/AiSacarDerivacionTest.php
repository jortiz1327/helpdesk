<?php

namespace Tests\Unit;

use App\Services\Ai\AiSuggestionService;
use PHPUnit\Framework\TestCase;

class AiSacarDerivacionTest extends TestCase
{
    public function test_quita_la_nota_interna_y_la_categoria_queda_aparte(): void
    {
        [$t, $d] = AiSuggestionService::sacarDerivacion("Gracias por su mensaje.\n[[DERIVAR:Garantías|etiqueta sin pila]]");
        $this->assertSame('Gracias por su mensaje.', $t);
        $this->assertSame(['categoria' => 'Garantías', 'departamento' => 'Garantías', 'clave' => 'garantias', 'categoria_key' => 'garantias', 'motivo' => 'etiqueta sin pila'], $d);
    }

    public function test_con_no_sabe_y_sin_marca(): void
    {
        [$t, $d] = AiSuggestionService::sacarDerivacion("[[NO_SABE]]\n[[DERIVAR:pedidos|material]]");
        $this->assertSame('[[NO_SABE]]', $t);
        $this->assertSame('Pedidos y facturas', $d['categoria']);
        $this->assertSame([ 'Hola', null ], AiSuggestionService::sacarDerivacion('Hola'));
    }

    public function test_nombre_de_dominio_solo_en_correos_de_empresa(): void
    {
        $this->assertSame('estivalcenturion', AiSuggestionService::nombreDeDominio('info@hotelestivalcenturion.com'));
        $this->assertSame('playasguardamar', AiSuggestionService::nombreDeDominio('Recepcion@playasguardamar.es'));
        $this->assertNull(AiSuggestionService::nombreDeDominio('juan@gmail.com'));
        $this->assertNull(AiSuggestionService::nombreDeDominio('no-es-un-correo'));
    }

    public function test_categoria_desconocida_se_ignora(): void
    {
        [, $d] = AiSuggestionService::sacarDerivacion('Hola [[DERIVAR:ventas|x]]');
        $this->assertNull($d);
    }

    public function test_postventa_es_la_categoria_pedidos_y_facturas_y_pedidos_sigue_valiendo(): void
    {
        foreach (['postventa', 'pedidos', 'Pedido'] as $k) {
            [, $d] = AiSuggestionService::sacarDerivacion("x [[DERIVAR:$k|material]]");
            $this->assertSame('Postventa', $d['departamento']);
            $this->assertSame('Pedidos y facturas', $d['categoria']);
            $this->assertSame('pedidos_facturas', $d['categoria_key']);
        }
        [, $p] = AiSuggestionService::sacarDerivacion('x [[DERIVAR:proyectos|y]]');
        $this->assertSame('Proyectos', $p['departamento']);
    }

    public function test_cada_derivacion_fijada_en_codigo_lleva_la_frase_con_su_departamento(): void
    {
        $d = AiSuggestionService::decidir('Queremos la garantía', [['role' => 'user', 'text' => 'Queremos la garantía']], 'verificado', false);
        $this->assertStringContainsString('departamento de Garantías', $d['instruccion']);
    }
}
