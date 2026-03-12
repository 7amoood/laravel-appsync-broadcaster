<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

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

        $broadcastManager->extend('appsync', function ($app, $config) {
            $mode = $config['mode'] ?? 'direct';

            if ($mode === 'redis') {
                return new RedisBroadcaster($config);
            }

            // Direct mode — build the configured transport
            $transport = $this->buildTransport($config);

            return new DirectBroadcaster($config, $transport);
        });
    }

    protected function buildTransport(array $config): TransportInterface
    {
        $tokenManager  = new TokenManager($config);
        $transportType = $config['transport'] ?? 'http';

        if ($transportType === 'websocket') {
            return new WebSocketTransport($config, $tokenManager);
        }

        return new HttpTransport($config, $tokenManager);
    }
}
