<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Proveedor Anthropic (API Messages). La clave sale de config('ai.api_key'). */
class AnthropicProvider implements AiProvider
{
    public function configured(): bool
    {
        return trim((string) config('ai.api_key')) !== '';
    }

    public function complete(string $system, array $messages): array
    {
        if (! $this->configured()) {
            throw new AiException('La IA no está configurada');
        }

        try {
            $r = Http::timeout((int) config('ai.timeout', 30))
                ->withHeaders([
                    'x-api-key'         => (string) config('ai.api_key'),
                    'anthropic-version' => '2023-06-01',
                ])
                ->acceptJson()
                ->post(rtrim((string) config('ai.base_url'), '/') . '/v1/messages', [
                    'model'      => config('ai.model'),
                    'max_tokens' => (int) config('ai.max_tokens', 700),
                    'system'     => $system,
                    'messages'   => $messages,
                ]);
        } catch (Throwable $e) {
            throw new AiException('No se pudo contactar con la IA');
        }

        if (! $r->successful()) {
            // El cuerpo del error del proveedor no se enseña (puede llevar datos de la petición).
            throw new AiException('La IA respondió con un error (' . $r->status() . ')');
        }

        $text = '';
        foreach ((array) $r->json('content', []) as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $text .= (string) ($bloque['text'] ?? '');
            }
        }

        return [
            'text'          => trim($text),
            'model'         => (string) ($r->json('model') ?: config('ai.model')),
            'input_tokens'  => $r->json('usage.input_tokens'),
            'output_tokens' => $r->json('usage.output_tokens'),
        ];
    }
}
