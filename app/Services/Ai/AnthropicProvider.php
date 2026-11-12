<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Proveedor Anthropic (API Messages). */
class AnthropicProvider implements AiProvider
{
    use ConfiguraProveedor;

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, array $messages): array
    {
        $this->exigirClave();

        try {
            $r = Http::timeout($this->timeout())
                ->withHeaders(['x-api-key' => $this->key(), 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->post($this->url('/v1/messages'), [
                    'model'      => $this->model(),
                    'max_tokens' => $this->maxTokens(),
                    'system'     => $system,
                    'messages'   => $messages,
                ]);
        } catch (Throwable) {
            throw new AiException('No se pudo contactar con la IA');
        }
        $this->exigirOk($r);

        $text = '';
        foreach ((array) $r->json('content', []) as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $text .= (string) ($bloque['text'] ?? '');
            }
        }

        return [
            'text'          => trim($text),
            'model'         => (string) ($r->json('model') ?: $this->model()),
            'input_tokens'  => $r->json('usage.input_tokens'),
            'output_tokens' => $r->json('usage.output_tokens'),
            'truncated'     => $r->json('stop_reason') === 'max_tokens',
        ];
    }
}
