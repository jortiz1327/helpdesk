<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Proveedor Mistral (API chat/completions, formato compatible con OpenAI). */
class MistralProvider implements AiProvider
{
    use ConfiguraProveedor;

    public function name(): string
    {
        return 'mistral';
    }

    public function complete(string $system, array $messages): array
    {
        $this->exigirClave();

        try {
            $r = Http::timeout($this->timeout())
                ->withToken($this->key())
                ->acceptJson()
                ->post($this->url('/v1/chat/completions'), [
                    'model'      => $this->model(),
                    'max_tokens' => $this->maxTokens(),
                    'messages'   => array_merge([['role' => 'system', 'content' => $system]], $messages),
                ]);
        } catch (Throwable) {
            throw new AiException('No se pudo contactar con la IA');
        }
        $this->exigirOk($r);

        return [
            'text'          => trim((string) $r->json('choices.0.message.content', '')),
            'model'         => (string) ($r->json('model') ?: $this->model()),
            'input_tokens'  => $r->json('usage.prompt_tokens'),
            'output_tokens' => $r->json('usage.completion_tokens'),
            'truncated'     => $r->json('choices.0.finish_reason') === 'length',
        ];
    }
}
