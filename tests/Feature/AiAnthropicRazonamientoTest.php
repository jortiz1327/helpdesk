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
}
