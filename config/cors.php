<?php

declare(strict_types=1);

/*
 * The SPA (app.{APP_DOMAIN}, or localhost:3000 in development) calls the API
 * cross-origin with cookies, so credentials are allowed and the origins are
 * an explicit list, never `*`.
 */

$origins = is_string($configured = env('CORS_ALLOWED_ORIGINS')) && $configured !== ''
    ? explode(',', $configured)
    : [
        is_string($domain = env('APP_DOMAIN')) && $domain !== '' ? 'https://app.'.$domain : null,
        in_array(env('APP_ENV'), ['local', 'testing'], true) ? 'http://localhost:3000' : null,
    ];

return [
    'paths' => ['api/v1/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter($origins)),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id', 'X-Branch-Id', 'Idempotency-Key'],

    'exposed_headers' => ['X-Request-Id', 'Idempotent-Replayed', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,
];
