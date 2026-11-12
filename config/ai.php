<?php

/*
 * Asistente de IA del helpdesk (sugerencias de respuesta y, más adelante, respuesta
 * automática fuera de horario). Las claves van SOLO en .env; sin la del proveedor activo
 * el asistente queda apagado y no sale ningún dato hacia fuera.
 *
 * El proveedor activo (AI_PROVIDER) está detrás de App\Services\Ai\AiProvider: cambiar de
 * empresa es cambiar AI_PROVIDER. Cada proveedor tiene su clave, modelo y URL, para poder
 * compararlos a la vez con `php artisan ai:comparar`.
 *
 * AI_API_KEY / AI_MODEL / AI_BASE_URL siguen valiendo para el proveedor activo.
 */
$activo = env('AI_PROVIDER', 'anthropic');

return [
    'provider'   => $activo,
    'max_tokens' => (int) env('AI_MAX_TOKENS', 2000),
    // Laboratorio de validación (tickets resueltos, sin enviar nada). Solo existe en local; en producción no se enciende.
    'lab'        => (bool) env('AI_LAB', env('APP_ENV') === 'local'),
    'timeout'    => (int) env('AI_TIMEOUT', 30),

    // Consulta de SOLO LECTURA a la flota de AppHoteles (hotel del cliente y estado de antenas). Apagado si no hay apps.
    // AI_HOTELS_APPS=[{"url":"https://hotel-sol.example.com","key":"..."}]  (una entrada por app; solo https)
    'hotels' => [
        'apps'    => json_decode((string) env('AI_HOTELS_APPS', '[]'), true) ?: [],
        'timeout' => (int) env('AI_HOTELS_TIMEOUT', 8),
        'ttl'     => (int) env('AI_HOTELS_TTL', 6 * 3600),
    ],

    // Manuales (claves de config/manuals.php) que la IA puede consultar. Solo los pensados para clientes.
    'manuals'    => ['cliente'],
    // Puntuación mínima absoluta FULLTEXT (0 = solo cuenta la relativa al mejor resultado; subirla con datos reales si se cuelan casos poco relacionados).
    'knowledge_min_score' => (float) env('AI_KNOWLEDGE_MIN_SCORE', 0.0),

    // Precio en USD por millón de tokens [entrada, salida], solo para ESTIMAR el gasto en Ajustes (la factura real es la del proveedor).
    // Comprobar en la web del proveedor; se puede ajustar sin tocar código con AI_PRICE_IN / AI_PRICE_OUT.
    'prices' => [
        'default' => [(float) env('AI_PRICE_IN', 1.0), (float) env('AI_PRICE_OUT', 5.0)],
    ],

    'providers' => [
        'anthropic' => [
            'api_key'  => env('AI_KEY_ANTHROPIC', $activo === 'anthropic' ? env('AI_API_KEY') : null),
            'model'    => env('AI_MODEL_ANTHROPIC', $activo === 'anthropic' ? env('AI_MODEL', 'claude-haiku-5-5') : 'claude-haiku-5-5'),
            'base_url' => env('AI_BASE_URL_ANTHROPIC', 'https://api.anthropic.com'),
        ],
        'gemini' => [
            'api_key'  => env('AI_KEY_GEMINI', $activo === 'gemini' ? env('AI_API_KEY') : null),
            'model'    => env('AI_MODEL_GEMINI', $activo === 'gemini' ? env('AI_MODEL', 'gemini-2.5-flash') : 'gemini-2.5-flash'),
            'base_url' => env('AI_BASE_URL_GEMINI', 'https://generativelanguage.googleapis.com'),
        ],
        'mistral' => [
            'api_key'  => env('AI_KEY_MISTRAL', $activo === 'mistral' ? env('AI_API_KEY') : null),
            'model'    => env('AI_MODEL_MISTRAL', $activo === 'mistral' ? env('AI_MODEL', 'mistral-small-latest') : 'mistral-small-latest'),
            'base_url' => env('AI_BASE_URL_MISTRAL', 'https://api.mistral.ai'),
        ],
    ],
];
