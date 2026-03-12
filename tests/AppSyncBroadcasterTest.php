<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\AppSyncBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\DirectBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Hamoood\LaravelAppSyncBroadcaster\Transport\HttpTransport;
use Hamoood\LaravelAppSyncBroadcaster\Transport\TransportInterface;
use Orchestra\Testbench\TestCase;

class AppSyncBroadcasterTest extends TestCase
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
            'mode'      => 'direct',
            'transport' => 'http',
            'cache'     => [
                'store'  => 'array',
                'prefix' => 'appsync_test_',
            ],
            'http'      => [
                'timeout'         => 10,
                'connect_timeout' => 5,
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
            'options'   => [
                'cognito_pool'          => 'test-pool',
                'cognito_region'        => 'us-east-1',
                'cognito_client_id'     => 'test-client-id',
                'cognito_client_secret' => 'test-client-secret',
            ],
        ];
    }

    public function test_broadcaster_can_be_instantiated()
    {
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $transport    = new HttpTransport($config, $tokenManager);
        $broadcaster  = new DirectBroadcaster($config, $transport);

        $this->assertInstanceOf(DirectBroadcaster::class, $broadcaster);
        $this->assertInstanceOf(AppSyncBroadcaster::class, $broadcaster);
    }

    public function test_config_validation_throws_exception_for_missing_required_fields()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config key: app_id');

        $config = $this->getValidConfig();
        unset($config['app_id']);

        $tokenManager = new TokenManager($config);
        $transport    = new HttpTransport($config, $tokenManager);
        new DirectBroadcaster($config, $transport);
    }

    public function test_config_validation_throws_exception_for_missing_cognito_options()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config option: cognito_client_id');

        $config = $this->getValidConfig();
        unset($config['options']['cognito_client_id']);

        $tokenManager = new TokenManager($config);
        $transport    = new HttpTransport($config, $tokenManager);
        new DirectBroadcaster($config, $transport);
    }

    public function test_channel_name_normalization()
    {
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $transport    = new HttpTransport($config, $tokenManager);
        $broadcaster  = new DirectBroadcaster($config, $transport);

        $reflection      = new \ReflectionClass($broadcaster);
        $normalizeMethod = $reflection->getMethod('normalizeChannelName');
        $normalizeMethod->setAccessible(true);

        $this->assertEquals('user.1', $normalizeMethod->invoke($broadcaster, 'test/private-user.1'));
        $this->assertEquals('chat.room.1', $normalizeMethod->invoke($broadcaster, 'test/presence-chat.room.1'));
        $this->assertEquals('public-channel', $normalizeMethod->invoke($broadcaster, 'public-channel'));
    }

    public function test_channel_type_detection()
    {
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $transport    = new HttpTransport($config, $tokenManager);
        $broadcaster  = new DirectBroadcaster($config, $transport);

        $reflection = new \ReflectionClass($broadcaster);

        $isPrivate = $reflection->getMethod('isPrivateChannel');
        $isPrivate->setAccessible(true);

        $isPresence = $reflection->getMethod('isPresenceChannel');
        $isPresence->setAccessible(true);

        $isGuarded = $reflection->getMethod('isGuardedChannel');
        $isGuarded->setAccessible(true);

        // Private channel
        $this->assertTrue($isPrivate->invoke($broadcaster, 'test/private-user.1'));
        $this->assertFalse($isPrivate->invoke($broadcaster, 'test/presence-chat.1'));
        $this->assertFalse($isPrivate->invoke($broadcaster, 'public-channel'));

        // Presence channel
        $this->assertTrue($isPresence->invoke($broadcaster, 'test/presence-chat.1'));
        $this->assertFalse($isPresence->invoke($broadcaster, 'test/private-user.1'));
        $this->assertFalse($isPresence->invoke($broadcaster, 'public-channel'));

        // Guarded channel
        $this->assertTrue($isGuarded->invoke($broadcaster, 'test/private-user.1'));
        $this->assertTrue($isGuarded->invoke($broadcaster, 'test/presence-chat.1'));
        $this->assertFalse($isGuarded->invoke($broadcaster, 'public-channel'));
    }

    public function test_exposes_collaborator_accessors()
    {
        $config       = $this->getValidConfig();
        $tokenManager = new TokenManager($config);
        $transport    = new HttpTransport($config, $tokenManager);
        $broadcaster  = new DirectBroadcaster($config, $transport);

        $this->assertInstanceOf(TokenManager::class, $broadcaster->getTokenManager());
        $this->assertInstanceOf(TransportInterface::class, $broadcaster->getTransport());
    }
}
