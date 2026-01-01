<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'batirama-key'),
            'secret' => env('PUSHER_APP_SECRET', 'batirama-secret'),
            'app_id' => env('PUSHER_APP_ID', 'batirama-app'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'useTLS' => env('PUSHER_SCHEME', 'http') === 'https',
                // Host interne pour communication Laravel -> Soketi (dans Docker)
                'host' => env('PUSHER_HOST', 'soketi'),
                'port' => env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => false,
            ],
            // Configuration frontend (navigateur -> Soketi via Caddy reverse proxy)
            // Si vide, utilise le même domaine/port que le site (recommandé)
            'frontend' => [
                'host' => env('SOKETI_FRONTEND_HOST', ''),
                'port' => env('SOKETI_FRONTEND_PORT', ''),
                'scheme' => env('SOKETI_FRONTEND_SCHEME', ''),
                'path' => '', // WebSocket via /app/* sur même domaine
            ],
            'client_options' => [
                // Guzzle client options
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
