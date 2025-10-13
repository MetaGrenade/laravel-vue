<?php

return [
    'default' => value(function () {
        $configuredDriver = env('BROADCAST_CONNECTION');

        $hasPusherCredentials = env('PUSHER_APP_ID') && env('PUSHER_APP_KEY') && env('PUSHER_APP_SECRET');

        if ($configuredDriver === 'pusher' && ! $hasPusherCredentials) {
            $configuredDriver = null;
        }

        if ($configuredDriver && $configuredDriver !== 'log') {
            return $configuredDriver;
        }

        $forceLog = filter_var(env('BROADCAST_FORCE_LOG', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($forceLog === true) {
            return $configuredDriver ?: 'log';
        }

        if ($hasPusherCredentials) {
            return 'pusher';
        }

        return $configuredDriver ?: 'log';
    }),

    'connections' => [
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST'),
                'port' => env('PUSHER_PORT', value(function () {
                    $scheme = env('PUSHER_SCHEME', 'https');

                    return $scheme === 'https' ? 443 : 80;
                })),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'useTLS' => value(function () {
                    $forcedTls = env('PUSHER_FORCE_TLS');

                    if ($forcedTls === null) {
                        return env('PUSHER_SCHEME', 'https') === 'https';
                    }

                    return filter_var($forcedTls, FILTER_VALIDATE_BOOL);
                }),
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
