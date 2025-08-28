<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;

class AppSyncBroadcastServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Only merge config if not published
        if (! file_exists($this->app->configPath('broadcasting/appsync.php'))) {
            $this->mergeConfigFrom(
                __DIR__ . '/../config/appsync.php',
                'broadcasting.connections.appsync'
            );
        }
    }

    public function boot(BroadcastManager $broadcastManager)
    {
        // Publish config file
        $this->publishes([
            __DIR__ . '/../config/appsync.php' => $this->app->configPath('broadcasting/appsync.php'),
        ], 'appsync-config');

        $broadcastManager->extend('appsync', function ($app, $config) {
            return new AppSyncBroadcaster($config);
        });
    }
}
