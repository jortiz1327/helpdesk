<?php

namespace Tests\Feature;

use App\Services\Ai\AiSuggestionService;
use Tests\TestCase;

class AiFraseDerivacionTest extends TestCase
{
    public function test_al_cliente_se_le_dice_el_departamento_ahora_sera_derivada_y_en_fase_3_ha_sido_derivada(): void
    {
        $dest = AiSuggestionService::sacarDerivacion('x [[DERIVAR:garantias|pila]]')[1];
        $corta = 'Gracias por su mensaje. Hemos registrado la incidencia y el equipo la revisará.';
        config(['ai.derivacion_asignada' => false]);
        $this->assertSame('Gracias por su mensaje. Hemos registrado la incidencia y será derivada al departamento de Garantías, que la revisará.', AiSuggestionService::aplicarFraseDerivacion($corta, $dest));
        config(['ai.derivacion_asignada' => true]);
        $this->assertStringContainsString('ha sido derivada al departamento de Garantías', AiSuggestionService::aplicarFraseDerivacion($corta, $dest));
        // Un texto distinto o sin departamento no se toca.
        $this->assertSame('Otra cosa', AiSuggestionService::aplicarFraseDerivacion('Otra cosa', $dest));
        $this->assertSame($corta, AiSuggestionService::aplicarFraseDerivacion($corta, null));
    }
}
