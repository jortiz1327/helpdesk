<?php

namespace Tests\Feature;

use App\Services\Ai\AnthropicProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAnthropicRazonamientoTest extends TestCase
{
    private function llamar(?string $thinking): array
    {
        config(['ai.providers.anthropic.api_key' => 'K-prueba', 'ai.providers.anthropic.base_url' => 'https://api.anthropic.example',
            'ai.anthropic_thinking' => $thinking, 'ai.max_tokens' => 4000]);
        Http::fake(['api.anthropic.example/*' => Http::response(['model' => 'm', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'Hola']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 9]])]);
        $r = (new AnthropicProvider())->complete('sistema', [['role' => 'user', 'content' => 'x']]);
        $enviado = [];
        Http::assertSent(function ($req) use (&$enviado) { $enviado = $req->data(); return true; });
        return [$r, $enviado];
    }

    public function test_por_defecto_deja_razonar_y_el_texto_ignora_el_bloque_de_razonamiento(): void
    {
        [$r, $enviado] = $this->llamar('default');
        $this->assertSame('Hola', $r['text']);
        $this->assertFalse($r['truncated']);
        $this->assertArrayNotHasKey('thinking', $enviado);
        $this->assertSame(4000, $enviado['max_tokens']);
    }

    public function test_se_puede_apagar_el_razonamiento_por_configuracion(): void
    {
        [, $enviado] = $this->llamar('disabled');
        $this->assertSame(['type' => 'disabled'], $enviado['thinking']);
    }

    public function test_con_herramientas_pide_la_busqueda_devuelve_el_resultado_y_suma_los_tokens(): void
    {
        config(['ai.providers.anthropic.api_key' => 'K-prueba', 'ai.providers.anthropic.base_url' => 'https://api.anthropic.example', 'ai.anthropic_thinking' => 'disabled', 'ai.max_tokens' => 4000]);
        Http::fake(['api.anthropic.example/*' => Http::sequence()
            ->push(['model' => 'm', 'stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'buscar_memoria', 'input' => ['consulta' => 'idioma etiqueta']]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]])
            ->push(['model' => 'm', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Entre en Idiomas.']], 'usage' => ['input_tokens' => 150, 'output_tokens' => 20]])]);
        $pedido = [];
        $r = (new AnthropicProvider())->completeConHerramientas('sistema', [['role' => 'user', 'content' => 'x']],
            [['name' => 'buscar_memoria', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => []]]],
            function ($n, $e) use (&$pedido) { $pedido = [$n, $e]; return 'MANUAL Idiomas'; });
        $this->assertSame(['buscar_memoria', ['consulta' => 'idioma etiqueta']], $pedido);
        $this->assertSame('Entre en Idiomas.', $r['text']);
        $this->assertSame(250, $r['input_tokens']);
        $this->assertSame(30, $r['output_tokens']);
        $this->assertSame(1, $r['usos']);
        $segundo = null;
        Http::assertSent(function ($req) use (&$segundo) { $segundo = $req->data(); return true; });
        $ultimo = end($segundo['messages']);
        $this->assertSame('tool_result', $ultimo['content'][0]['type']);
        $this->assertSame('MANUAL Idiomas', $ultimo['content'][0]['content']);
    }
}
