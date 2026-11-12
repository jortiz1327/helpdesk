<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Proveedor Google Gemini (API generateContent con clave de API). */
class GeminiProvider implements AiProvider
{
    use ConfiguraProveedor;

    public function name(): string
    {
        return 'gemini';
    }

    public function complete(string $system, array $messages): array
    {
        $this->exigirClave();

        try {
            $r = Http::timeout($this->timeout())
                ->withHeaders(['x-goog-api-key' => $this->key()])
                ->acceptJson()
                ->post($this->url('/v1beta/models/' . rawurlencode($this->model()) . ':generateContent'), [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents'          => array_map(fn ($m) => [
                        'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $m['content']]],
                    ], $messages),
                    'generationConfig'  => ['maxOutputTokens' => $this->maxTokens()],
                ]);
        } catch (Throwable) {
            throw new AiException('No se pudo contactar con la IA');
        }
        $this->exigirOk($r);

        $text = '';
        foreach ((array) $r->json('candidates.0.content.parts', []) as $parte) {
            $text .= (string) ($parte['text'] ?? '');
        }

        return [
            'text'          => trim($text),
            'model'         => $this->model(),
            'input_tokens'  => $r->json('usageMetadata.promptTokenCount'),
            'output_tokens' => $r->json('usageMetadata.candidatesTokenCount'),
            'truncated'     => $r->json('candidates.0.finishReason') === 'MAX_TOKENS',
        ];
    }
}
