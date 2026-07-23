<?php

return [
    // Versión de la Graph API de Meta.
    'graph_version' => env('WA_GRAPH_VERSION', 'v21.0'),

    // El token, phone_number_id, etc. se leen de la tabla `settings`
    // (editables desde la pantalla de Configuración), no de aquí.
];
