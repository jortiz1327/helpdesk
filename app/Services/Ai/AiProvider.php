<?php

namespace App\Services\Ai;

/**
 * Lo único que el resto de la app sabe de «la IA»: le doy instrucciones y una conversación
 * y me devuelve texto. Cambiar de proveedor es escribir otra clase que cumpla esto.
 */
interface AiProvider
{
    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $messages
     * @return array{text: string, model: string, input_tokens: ?int, output_tokens: ?int, truncated?: bool}
     * @throws AiException
     */
    public function complete(string $system, array $messages): array;

    public function configured(): bool;
}
