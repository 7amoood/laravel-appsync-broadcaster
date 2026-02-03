<?php

/**
 * AWS AppSync Broadcasting Configuration
 *
 * This configuration file will be published to config/broadcasting/appsync.php
 * when you run: php artisan vendor:publish --tag=appsync-config
 */

return [
    'driver'    => 'appsync',
    'cache'     => [
        'driver' => env('APPSYNC_CACHE_DRIVER', 'file'),
        'prefix' => env('APPSYNC_CACHE_PREFIX', 'appsync_broadcast_'),
    ],
    'namespace' => env('APPSYNC_NAMESPACE', 'default'),
    'app_id'    => env('APPSYNC_APP_ID'),
    'region'    => env('APPSYNC_EVENT_REGION'),
    'options'   => [
        'cognito_pool'          => env('APPSYNC_COGNITO_POOL'),
        'cognito_region'        => env('APPSYNC_COGNITO_REGION', env('APPSYNC_EVENT_REGION')),
        'cognito_client_id'     => env('APPSYNC_COGNITO_CLIENT_ID'),
        'cognito_client_secret' => env('APPSYNC_COGNITO_CLIENT_SECRET'),
    ],
];
