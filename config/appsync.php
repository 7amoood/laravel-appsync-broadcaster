<?php

/**
 * AWS AppSync Broadcasting Configuration
 *
 * Two axes of configuration:
 *
 *   mode      – How Laravel publishes events:
 *                'direct' = Laravel → Transport → AppSync
 *                'redis'  = Laravel → Redis Pub/Sub → Worker → AppSync
 *
 *   transport – How events reach AppSync:
 *                'http'      = one HTTP POST per publish
 *                'websocket' = persistent WebSocket connection
 */

return [
    'driver'    => 'appsync',

    // Broadcasting mode: 'direct' or 'redis'
    'mode'      => env('APPSYNC_MODE', 'direct'),

    // Transport: 'http' or 'websocket'
    'transport' => env('APPSYNC_TRANSPORT', 'http'),

    'namespace' => env('APPSYNC_NAMESPACE', 'default'),
    'app_id'    => env('APPSYNC_APP_ID'),
    'region'    => env('APPSYNC_EVENT_REGION'),

    // Cognito OAuth2 credentials
    'options'   => [
        'cognito_scope'         => env('APPSYNC_COGNITO_SCOPE'),
        'cognito_pool'          => env('APPSYNC_COGNITO_POOL'),
        'cognito_region'        => env('APPSYNC_COGNITO_REGION', env('APPSYNC_EVENT_REGION')),
        'cognito_client_id'     => env('APPSYNC_COGNITO_CLIENT_ID'),
        'cognito_client_secret' => env('APPSYNC_COGNITO_CLIENT_SECRET'),
    ],

    'cache'     => [
        'store'  => env('APPSYNC_CACHE_STORE', env('CACHE_STORE', 'redis')),
        'prefix' => env('APPSYNC_CACHE_PREFIX', 'appsync_broadcast_'),
    ],

    // Token refresh settings
    'token'     => [
        'buffer' => (int) env('APPSYNC_TOKEN_BUFFER', 120), // seconds before expiry to refresh
    ],

    // HTTP transport settings
    'http'      => [
        'timeout'         => (int) env('APPSYNC_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('APPSYNC_HTTP_CONNECT_TIMEOUT', 5),
    ],

    // Retry settings (used by HTTP transport)
    'retry'     => [
        'max_attempts' => (int) env('APPSYNC_RETRY_MAX_ATTEMPTS', 3),
        'base_delay'   => (int) env('APPSYNC_RETRY_BASE_DELAY', 200), // milliseconds
        'max_delay'    => (int) env('APPSYNC_RETRY_MAX_DELAY', 5000), // milliseconds
        'multiplier'   => (float) env('APPSYNC_RETRY_MULTIPLIER', 2.0),
    ],

    // Redis Pub/Sub settings (used when mode = 'redis')
    'redis'     => [
        'connection'     => env('APPSYNC_REDIS_CONNECTION', 'default'),
        'channel'        => env('APPSYNC_REDIS_CHANNEL', 'appsync:events'),
        'batch_size'     => (int) env('APPSYNC_REDIS_BATCH_SIZE', 50),
        'flush_interval' => (int) env('APPSYNC_REDIS_FLUSH_INTERVAL', 100), // milliseconds
    ],

    // WebSocket transport settings
    'websocket' => [
        'batch_size'            => (int) env('APPSYNC_WS_BATCH_SIZE', 5),      // max 5 per AppSync limit
        'flush_interval'        => (int) env('APPSYNC_WS_FLUSH_INTERVAL', 50), // milliseconds
        'max_buffer_size'       => (int) env('APPSYNC_WS_MAX_BUFFER', 10000),  // back-pressure limit
        'ka_timeout_multiplier' => (float) env('APPSYNC_WS_KA_TIMEOUT_MULTIPLIER', 1.5),
        'stats_interval'        => (int) env('APPSYNC_WS_STATS_INTERVAL', 30), // seconds
    ],
];
