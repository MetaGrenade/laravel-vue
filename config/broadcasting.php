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

        $hasPusherCredentials = env('PUSHER_APP_ID') && env('PUSHER_APP_KEY') && env('PUSHER_APP_SECRET');

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
            'options' => value(function () {
                $scheme = env('PUSHER_SCHEME', 'https');

                $options = [
                    'cluster' => env('PUSHER_APP_CLUSTER'),
                    'scheme' => $scheme,
                ];

                $host = env('PUSHER_HOST');

                if ($host !== null && $host !== '') {
                    $options['host'] = $host;
                }

                $port = env('PUSHER_PORT');

                if ($port === null || $port === '') {
                    $options['port'] = $scheme === 'https' ? 443 : 80;
                } else {
                    $options['port'] = (int) $port;
                }

                $forcedTls = env('PUSHER_FORCE_TLS');

                if ($scheme !== 'https') {
                    $options['useTLS'] = false;
                } elseif ($forcedTls === null || $forcedTls === '') {
                    $options['useTLS'] = true;
                } else {
                    $options['useTLS'] = filter_var($forcedTls, FILTER_VALIDATE_BOOL);
                }

                $verifySsl = env('PUSHER_VERIFY_SSL');

                $clientOptions = [];

                if ($verifySsl !== null && $verifySsl !== '') {
                    $verifySsl = filter_var($verifySsl, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                    if ($verifySsl !== null) {
                        $clientOptions['verify'] = $verifySsl;
                    }
                }

                $caBundle = env('PUSHER_CA_BUNDLE');

                if ($caBundle !== null && $caBundle !== '') {
                    $clientOptions['verify'] = $caBundle;
                }

                if ($clientOptions !== []) {
                    $options['client_options'] = $clientOptions;
                }

                return $options;
            }),
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
