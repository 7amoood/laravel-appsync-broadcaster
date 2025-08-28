<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcaster;
use Orchestra\Testbench\TestCase;

class AppSyncBroadcasterTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcastServiceProvider::class,
        ];
    }

    protected function getValidConfig()
    {
        return [
            'namespace' => 'test',
            'app_id'    => 'test-app-id',
            'region'    => 'us-east-1',
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
        $config      = $this->getValidConfig();
        $broadcaster = new AppSyncBroadcaster($config);

        $this->assertInstanceOf(AppSyncBroadcaster::class, $broadcaster);
    }

    public function test_config_validation_throws_exception_for_missing_required_fields()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config key: app_id');

        $config = $this->getValidConfig();
        unset($config['app_id']);

        $broadcaster = new AppSyncBroadcaster($config);
    }

    public function test_config_validation_throws_exception_for_missing_cognito_options()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config option: cognito_client_id');

        $config = $this->getValidConfig();
        unset($config['options']['cognito_client_id']);

        $broadcaster = new AppSyncBroadcaster($config);
    }

    public function test_channel_name_normalization()
    {
        $config      = $this->getValidConfig();
        $broadcaster = new AppSyncBroadcaster($config);

        $reflection      = new \ReflectionClass($broadcaster);
        $normalizeMethod = $reflection->getMethod('normalizeChannelName');
        $normalizeMethod->setAccessible(true);

        // Test private channel normalization
        $result = $normalizeMethod->invoke($broadcaster, 'test/private-user.1');
        $this->assertEquals('user.1', $result);

        // Test presence channel normalization
        $result = $normalizeMethod->invoke($broadcaster, 'test/presence-chat.room.1');
        $this->assertEquals('chat.room.1', $result);

        // Test public channel (no change)
        $result = $normalizeMethod->invoke($broadcaster, 'public-channel');
        $this->assertEquals('public-channel', $result);
    }

    public function test_channel_type_detection()
    {
        $config      = $this->getValidConfig();
        $broadcaster = new AppSyncBroadcaster($config);

        $reflection = new \ReflectionClass($broadcaster);

        $isPrivateMethod = $reflection->getMethod('isPrivateChannel');
        $isPrivateMethod->setAccessible(true);

        $isPresenceMethod = $reflection->getMethod('isPresenceChannel');
        $isPresenceMethod->setAccessible(true);

        $isGuardedMethod = $reflection->getMethod('isGuardedChannel');
        $isGuardedMethod->setAccessible(true);

        // Test private channel detection
        $this->assertTrue($isPrivateMethod->invoke($broadcaster, 'test/private-user.1'));
        $this->assertFalse($isPrivateMethod->invoke($broadcaster, 'test/presence-chat.1'));
        $this->assertFalse($isPrivateMethod->invoke($broadcaster, 'public-channel'));

        // Test presence channel detection
        $this->assertTrue($isPresenceMethod->invoke($broadcaster, 'test/presence-chat.1'));
        $this->assertFalse($isPresenceMethod->invoke($broadcaster, 'test/private-user.1'));
        $this->assertFalse($isPresenceMethod->invoke($broadcaster, 'public-channel'));

        // Test guarded channel detection
        $this->assertTrue($isGuardedMethod->invoke($broadcaster, 'test/private-user.1'));
        $this->assertTrue($isGuardedMethod->invoke($broadcaster, 'test/presence-chat.1'));
        $this->assertFalse($isGuardedMethod->invoke($broadcaster, 'public-channel'));
    }
}
