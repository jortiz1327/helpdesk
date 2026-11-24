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
    // Tope de tokens de SALIDA. En Anthropic el razonamiento interno del modelo cuenta aquí: con 2000 las consultas
    // largas se quedaban sin margen y la respuesta salía cortada (se descartaba como «incompleta»).
    'max_tokens' => (int) env('AI_MAX_TOKENS', 4000),
    // Carpeta del conjunto de pruebas (casos reales anonimizados, marcas del equipo y resultados). Fuera de git.
    'eval_dir' => env('AI_EVAL_DIR', storage_path('app/ai-eval')),
    // Anthropic: «default» deja que el modelo razone como siempre; «disabled» lo apaga (menos coste y latencia).
    'anthropic_thinking' => env('AI_THINKING_ANTHROPIC', 'default'),
    // Laboratorio de validación (tickets resueltos, sin enviar nada). Solo existe en local; en producción no se enciende.
    'lab'        => (bool) env('AI_LAB', env('APP_ENV') === 'local'),
    'timeout'    => (int) env('AI_TIMEOUT', 30),
    // Tope mensual de gasto (USD, mes natural) del uso real de los agentes. Se puede cambiar en Ajustes › Asistente IA.
    'budget_month_usd' => (float) env('AI_BUDGET_MONTH_USD', 50),


    // Consulta de SOLO LECTURA a la API de soporte del panel de AppHoteles (hotel del cliente, antenas, etiquetas).
    // Apagado si falta la URL o la clave. AI_SUPPORT_URL=https://<panel>  AI_SUPPORT_KEY=<API_KEY_SUPPORT_READONLY del panel>
    'support' => [
        'url'     => (string) env('AI_SUPPORT_URL', ''),
        'key'     => (string) env('AI_SUPPORT_KEY', ''),
        'timeout' => (int) env('AI_SUPPORT_TIMEOUT', 12),
    ],

    // Manuales (claves de config/manuals.php) que la IA puede consultar. Solo los pensados para clientes.
    'manuals'    => ['cliente'],
    // Catálogo de manuales por ÁREA (ver App\Services\Ai\ManualesCatalogo y docs/manuales-ia.md). 'carpeta' = resources/ai-docs/<carpeta>/ (archivos .md);
    // 'manual' = clave de un PDF de config/manuals.php. 'revision_dias' = cada cuántos días hay que volver a revisarla.
    'areas' => [
        'hoteles'   => ['nombre' => 'Hoteles (buffets)', 'carpeta' => 'hoteles', 'revision_dias' => 45, 'descripcion' => 'Una guía por cada apartado de la aplicación de hoteles (usuarios, platos, etiquetas, servicios…)'],
        'soporte'   => ['nombre' => 'Soporte: reglas y protocolos', 'carpeta' => 'soporte', 'revision_dias' => 30, 'descripcion' => 'Derivación, verificación del cliente, protocolo de garantías y base de conocimiento'],
        'cliente'   => ['nombre' => 'Portal del cliente (PDF)', 'manual' => 'cliente', 'revision_dias' => 90, 'descripcion' => 'La guía que ve el cliente en el portal'],
        'farmacias' => ['nombre' => 'Farmacias (AdminFarm)', 'carpeta' => 'farmacias', 'revision_dias' => 90, 'estado' => 'en_espera', 'descripcion' => 'En espera de la API de AdminFarm; hoy solo el stock en etiquetas'],
        'tiendas'   => ['nombre' => 'Tiendas y supermercados', 'carpeta' => 'tiendas', 'revision_dias' => 90, 'estado' => 'sin_manuales', 'descripcion' => 'Sin manuales todavía (tareas de tiendas en pausa)'],
        'cloud'     => ['nombre' => 'Cloud (etiquetas y ESLAdmin)', 'carpeta' => 'cloud', 'revision_dias' => 60, 'estado' => 'sin_manuales', 'descripcion' => 'Sin manuales todavía (bloque L: conexión con el Cloud)'],
    ],

    // Correos que la IA puede dar al cliente (la revisión de coherencia avisa si un manual o regla da otro). Ver CoherenciaConocimiento.
    'correos_permitidos' => ['postventa@aemegroup.com'],

    // Puntuación mínima absoluta FULLTEXT (0 = solo cuenta la relativa al mejor resultado; subirla con datos reales si se cuelan casos poco relacionados).
    'knowledge_min_score' => (float) env('AI_KNOWLEDGE_MIN_SCORE', 0.0),
    // Al derivar se le dice al cliente «será derivada al departamento X». Pasar a true cuando el ticket se asigne solo (fase 3): «ha sido derivada».
    'derivacion_asignada' => (bool) env('AI_DERIVACION_ASIGNADA', false),
    // La IA puede buscar ella misma en la memoria mientras redacta (herramienta interna; solo proveedores que lo admiten).
    'memoria_herramienta' => (bool) env('AI_MEMORIA_HERRAMIENTA', true),
    'memoria_max_busquedas' => (int) env('AI_MEMORIA_MAX_BUSQUEDAS', 2),

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
