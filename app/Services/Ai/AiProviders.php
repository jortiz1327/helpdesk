<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/** Fábrica de proveedores por nombre (el activo sale de config('ai.provider')). */
class AiProviders
{
    public const NOMBRES = ['anthropic' => AnthropicProvider::class, 'gemini' => GeminiProvider::class, 'mistral' => MistralProvider::class];

    public static function make(string $nombre): AiProvider
    {
        $clase = self::NOMBRES[$nombre] ?? throw new InvalidArgumentException("Proveedor de IA desconocido: $nombre");
        return new $clase();
    }

    public static function activo(): AiProvider
    {
        return self::make((string) config('ai.provider', 'anthropic'));
    }
}
