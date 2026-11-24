<?php

namespace App\Services\Ai;

/**
 * Proveedor que, además de contestar, puede dejar que la IA pida consultar una herramienta (p. ej. buscar en la memoria)
 * antes de dar su respuesta. Si el proveedor no lo implementa, la sugerencia funciona igual con lo que ya se le dio.
 */
interface AiProviderConHerramientas extends AiProvider
{
    /**
     * @param  array<int, array{name: string, description: string, input_schema: array}>  $herramientas
     * @param  callable(string $nombre, array $entrada): string  $ejecutar  devuelve el texto que se le enseña a la IA
     * @return array{text: string, model: string, input_tokens: ?int, output_tokens: ?int, truncated?: bool, usos: int}
     * @throws AiException
     */
    public function completeConHerramientas(string $system, array $messages, array $herramientas, callable $ejecutar, int $maxRondas = 3): array;
}
