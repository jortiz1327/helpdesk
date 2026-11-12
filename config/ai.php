<?php

/*
 * Asistente de IA del helpdesk (sugerencias de respuesta y, más adelante, respuesta
 * automática fuera de horario). La clave va SOLO en .env (AI_API_KEY); sin ella el
 * asistente queda apagado y no sale ningún dato hacia fuera.
 *
 * El proveedor está detrás de App\Services\Ai\AiProvider, así que cambiar de modelo o de
 * empresa es tocar este archivo (y, si hace falta, escribir otro proveedor).
 */
return [
    'provider'   => env('AI_PROVIDER', 'anthropic'),
    'api_key'    => env('AI_API_KEY'),
    'model'      => env('AI_MODEL', 'claude-haiku-5-5'),
    'base_url'   => env('AI_BASE_URL', 'https://api.anthropic.com'),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 700),
    'timeout'    => (int) env('AI_TIMEOUT', 30),
];
