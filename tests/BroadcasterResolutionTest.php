<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcastServiceProvider;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\DirectBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\RedisBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\Transport\HttpTransport;
use Hamoood\LaravelAppSyncBroadcaster\Transport\WebSocketTransport;
use Illuminate\Broadcasting\BroadcastManager;
use Orchestra\Testbench\TestCase;

/**
 * Resolves the broadcaster the way Laravel actually does — through the
 * BroadcastManager driver it was registered on.
 *
 * Laravel 13 rebinds `extend()` driver closures to the manager instance, so a
 * closure that reaches for `$this` on the service provider fatals there while
 * working fine on 10/11/12. These tests cover that path on every version.
 */
class BroadcasterResolutionTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AppSyncBroadcastServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('appsync', $this->connectionConfig());
    }

    protected function connectionConfig(array $overrides = []): array
    {
        return array_merge([
            'driver'    => 'appsync',
            'mode'      => 'direct',
            'transport' => 'http',
            'namespace' => 'test',
            'app_id'    => 'test-app-id',
            'region'    => 'us-east-1',
            'cache'     => ['store' => 'array', 'prefix' => 'appsync_test_'],
            'http'      => ['timeout' => 10, 'connect_timeout' => 5],
            'retry'     => ['max_attempts' => 3, 'base_delay' => 100, 'max_delay' => 1000, 'multiplier' => 2.0],
            'token'     => ['buffer' => 120],
            'redis'     => ['connection' => 'default', 'channel' => 'appsync:events'],
            'websocket' => ['batch_size' => 5, 'flush_interval' => 50],
            'options'   => [
                'cognito_pool'          => 'test-pool',
                'cognito_region'        => 'us-east-1',
                'cognito_client_id'     => 'test-client-id',
                'cognito_client_secret' => 'test-client-secret',
            ],
        ], $overrides);
    }

    protected function resolve(array $overrides = [])
    {
        config(['broadcasting.connections.appsync' => $this->connectionConfig($overrides)]);

        $manager = $this->app->make(BroadcastManager::class);

        // Drivers are memoized per connection name — drop the previous one so a
        // second resolve() in the same test picks up the new config.
        $manager->purge('appsync');

        return $manager->connection('appsync');
    }

    public function test_manager_resolves_direct_broadcaster()
    {
        $this->assertInstanceOf(DirectBroadcaster::class, $this->resolve());
    }

    public function test_manager_resolves_redis_broadcaster()
    {
        $this->assertInstanceOf(RedisBroadcaster::class, $this->resolve(['mode' => 'redis']));
    }

    public function test_manager_resolves_configured_transport()
    {
        $this->assertInstanceOf(HttpTransport::class, $this->resolve()->getTransport());

        $this->assertInstanceOf(
            WebSocketTransport::class,
            $this->resolve(['transport' => 'websocket'])->getTransport()
        );
    }

    public function test_factory_defaults_to_direct_mode_and_http_transport()
    {
        $config = $this->connectionConfig();
        unset($config['mode'], $config['transport']);

        $broadcaster = AppSyncBroadcastServiceProvider::makeBroadcaster($config);

        $this->assertInstanceOf(DirectBroadcaster::class, $broadcaster);
        $this->assertInstanceOf(HttpTransport::class, $broadcaster->getTransport());
    }
}
