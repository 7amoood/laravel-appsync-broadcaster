<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\AppSyncBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\DirectBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\RedisBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\Console\AppSyncWorkerCommand;
use Hamoood\LaravelAppSyncBroadcaster\Transport\HttpTransport;
use Hamoood\LaravelAppSyncBroadcaster\Transport\TransportInterface;
use Hamoood\LaravelAppSyncBroadcaster\Transport\WebSocketTransport;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;

class AppSyncBroadcastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/appsync.php',
            'appsync'
        );
    }

    public function boot(BroadcastManager $broadcastManager): void
    {
        config([
            'broadcasting.connections.appsync' => config('appsync'),
        ]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/appsync.php' => $this->app->configPath('appsync.php'),
            ], 'appsync-config');

            $this->commands([
                AppSyncWorkerCommand::class,
            ]);
        }

        // Laravel 13 rebinds driver closures registered through a Manager's
        // extend() to the manager instance, so `$this` inside this closure is
        // NOT the service provider. Everything the closure needs is therefore
        // reached through the class name or the injected $app / $config.
        $broadcastManager->extend('appsync', static function ($app, $config) {
            return AppSyncBroadcastServiceProvider::makeBroadcaster($config);
        });
    }

    /**
     * Build the broadcaster for the given connection config.
     */
    public static function makeBroadcaster(array $config): AppSyncBroadcaster
    {
        if (($config['mode'] ?? 'direct') === 'redis') {
            return new RedisBroadcaster($config);
        }

        // Direct mode — build the configured transport
        return new DirectBroadcaster($config, static::makeTransport($config));
    }

    /**
     * Build the transport (HTTP or WebSocket) for the given connection config.
     */
    public static function makeTransport(array $config): TransportInterface
    {
        $tokenManager = new TokenManager($config);

        if (($config['transport'] ?? 'http') === 'websocket') {
            return new WebSocketTransport($config, $tokenManager);
        }

        return new HttpTransport($config, $tokenManager);
    }
}
