<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncWebSocketClient;
use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Orchestra\Testbench\TestCase;

class AppSyncWebSocketClientTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcastServiceProvider::class,
        ];
    }

    protected function getValidConfig(): array
    {
        return [
            'namespace' => 'test',
            'app_id'    => 'test-app-id',
            'region'    => 'us-east-1',
            'mode'      => 'redis',
            'cache'     => [
                'store'  => 'array',
                'prefix' => 'appsync_ws_test_',
            ],
            'retry'     => [
                'max_attempts' => 3,
                'base_delay'   => 100,
                'max_delay'    => 1000,
                'multiplier'   => 2.0,
            ],
            'token'     => [
                'buffer' => 120,
            ],
            'websocket' => [
                'batch_size'            => 5,
                'flush_interval'        => 50,
                'max_buffer_size'       => 10000,
                'ka_timeout_multiplier' => 1.5,
                'stats_interval'        => 30,
            ],
            'options'   => [
                'cognito_pool'          => 'test-pool',
                'cognito_region'        => 'us-east-1',
                'cognito_client_id'     => 'test-client-id',
                'cognito_client_secret' => 'test-client-secret',
            ],
        ];
    }

    public function test_client_starts_disconnected()
    {
        if (! class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop not installed');
        }

        $loop         = \React\EventLoop\Loop::get();
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $client       = new AppSyncWebSocketClient($loop, $config, $tokenManager);

        $this->assertFalse($client->isReady());
        $this->assertEquals('disconnected', $client->getState());
    }

    public function test_publish_returns_null_when_not_connected()
    {
        if (! class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop not installed');
        }

        $loop         = \React\EventLoop\Loop::get();
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $client       = new AppSyncWebSocketClient($loop, $config, $tokenManager);

        $result = $client->publish('/test/channel', ['{"event":"test"}']);

        $this->assertNull($result);
    }

    public function test_metrics_initial_state()
    {
        if (! class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop not installed');
        }

        $loop         = \React\EventLoop\Loop::get();
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $client       = new AppSyncWebSocketClient($loop, $config, $tokenManager);

        $metrics = $client->getMetrics();

        $this->assertEquals('disconnected', $metrics['state']);
        $this->assertEquals(0, $metrics['publish_sent']);
        $this->assertEquals(0, $metrics['publish_success']);
        $this->assertEquals(0, $metrics['publish_error']);
        $this->assertEquals(0, $metrics['reconnect_count']);
    }

    public function test_connection_url_format()
    {
        if (! class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop not installed');
        }

        $loop         = \React\EventLoop\Loop::get();
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $client       = new AppSyncWebSocketClient($loop, $config, $tokenManager);

        // Use reflection to test the URL builder
        $reflection = new \ReflectionClass($client);
        $method     = $reflection->getMethod('buildConnectionUrl');
        $method->setAccessible(true);

        $url = $method->invoke($client, 'test-token-123');

        // Verify URL structure
        $this->assertStringStartsWith('wss://test-app-id.appsync-realtime-api.us-east-1.amazonaws.com/event/realtime?', $url);
        $this->assertStringContainsString('header=', $url);
        $this->assertStringContainsString('payload=', $url);

        // Verify header contains auth info
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $header = json_decode(base64_decode($params['header']), true);

        $this->assertEquals('test-app-id.appsync-api.us-east-1.amazonaws.com', $header['host']);
        $this->assertEquals('Bearer test-token-123', $header['Authorization']);

        // Verify payload is empty JSON object
        $payload = base64_decode($params['payload']);
        $this->assertEquals('{}', $payload);
    }

    public function test_callback_registration()
    {
        if (! class_exists(\React\EventLoop\Loop::class)) {
            $this->markTestSkipped('react/event-loop not installed');
        }

        $loop         = \React\EventLoop\Loop::get();
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $client       = new AppSyncWebSocketClient($loop, $config, $tokenManager);

        // Verify fluent interface
        $result = $client->onReady(function () {});
        $this->assertSame($client, $result);

        $result = $client->onDisconnect(function () {});
        $this->assertSame($client, $result);
    }
}
