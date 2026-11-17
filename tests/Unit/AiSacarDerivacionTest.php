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
        $this->assertSame(['categoria' => 'Garantías', 'motivo' => 'etiqueta sin pila'], $d);
    }

    public function test_con_no_sabe_y_sin_marca(): void
    {
        [$t, $d] = AiSuggestionService::sacarDerivacion("[[NO_SABE]]\n[[DERIVAR:pedidos|material]]");
        $this->assertSame('[[NO_SABE]]', $t);
        $this->assertSame('Pedidos y facturas', $d['categoria']);
        $this->assertSame([ 'Hola', null ], AiSuggestionService::sacarDerivacion('Hola'));
    }

    public function test_categoria_desconocida_se_ignora(): void
    {
        [, $d] = AiSuggestionService::sacarDerivacion('Hola [[DERIVAR:ventas|x]]');
        $this->assertNull($d);
    }
}
