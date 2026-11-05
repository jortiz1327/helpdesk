<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Avisos al cliente cuando su garantía cambia de fase. Solo salen en producción, para
    // que en local o en pruebas nunca le llegue un correo de verdad a un cliente.
    // Producto de cada garantía en el cloud de ZKONG (plantilla default / almacen).
    // server: nombre (o id) del servidor ZKONG configurado; store_id: la tienda del almacén.
    // Sin tienda no se manda nada.
    'warranty_cloud' => [
        'server'   => env('WARRANTY_CLOUD_SERVER', 'APP02'),
        'store_id' => env('WARRANTY_CLOUD_STORE_ID'),
    ],

    'warranty_notices' => [
        'send' => (bool) env('WARRANTY_NOTICES_SEND', env('APP_ENV') === 'production'),
    ],

    // Buscador de direcciones del portal (garantías). Con clave, Google Places (la mejor
    // en cualquier país); sin ella, Photon (OpenStreetMap, gratis). La clave solo la usa
    // el servidor: el navegador nunca la ve.
    'places' => [
        'google_key' => env('GOOGLE_PLACES_KEY'),
        // Tope diario para todo el portal (lo gratis de Google repartido por días).
        'google_daily_search' => (int) env('GOOGLE_PLACES_DAILY_SEARCH', 300),
        'google_daily_details' => (int) env('GOOGLE_PLACES_DAILY_DETAILS', 150),
        'photon_url' => env('PHOTON_URL', 'https://photon.komoot.io/api/'),
    ],

];
