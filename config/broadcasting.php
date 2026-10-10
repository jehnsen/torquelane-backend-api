<?php

declare(strict_types=1);

/*
 * Broadcasting. Detailing booking changes are announced on a private channel
 * per branch (`private-detailing.branch.{id}`), after the transaction that
 * made them commits, for the shop monitor and the schedule.
 *
 * `reverb` is the connection the brief names. It needs `laravel/reverb`
 * installed (see CLAUDE.md, "Realtime"): until then keep BROADCAST_CONNECTION
 * at `log` (local) or `null`, and the screens fall back to refreshing on a
 * timer. The key and secret below also sign channel authorisations, which
 * this API issues itself (POST /broadcasting/auth and
 * /display/broadcasting/auth), so they work the same on any Pusher-protocol
 * server.
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],
    ],

    // What a browser needs to connect: never the secret.
    'client' => [
        'key' => env('REVERB_APP_KEY'),
        'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST')),
        'port' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 443)),
        'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'https')),
    ],
];
