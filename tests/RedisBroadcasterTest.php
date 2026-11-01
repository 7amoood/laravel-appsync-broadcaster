<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcastServiceProvider;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\RedisBroadcaster;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;

class RedisBroadcasterTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AppSyncBroadcastServiceProvider::class,
        ];
    }

    protected function config(array $redis): array
    {
        return [
            'mode'      => 'redis',
            'namespace' => 'test',
            'app_id'    => 'test-app-id',
            'region'    => 'us-east-1',
            'cache'     => ['store' => 'array', 'prefix' => 'appsync_test_'],
            'redis'     => $redis,
            'options'   => [
                'cognito_pool'          => 'test-pool',
                'cognito_region'        => 'us-east-1',
                'cognito_client_id'     => 'test-client-id',
                'cognito_client_secret' => 'test-client-secret',
            ],
        ];
    }

    protected function expectPipeline(): MockInterface
    {
        $pipe       = Mockery::mock();
        $connection = Mockery::mock();

        $connection->shouldReceive('pipeline')->once()->andReturnUsing(function ($callback) use ($pipe) {
            $callback($pipe);

            return [];
        });

        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

        return $pipe;
    }

    public function test_events_are_pushed_onto_the_list_and_the_list_is_capped()
    {
        $pushed = null;

        $pipe = $this->expectPipeline();
        $pipe->shouldReceive('rpush')->once()->andReturnUsing(function ($key, ...$messages) use (&$pushed) {
            $pushed = [$key, $messages];
        });
        $pipe->shouldReceive('ltrim')->once()->with('appsync:events', -10000, -1);

        (new RedisBroadcaster($this->config(['connection' => 'default', 'key' => 'appsync:events'])))
            ->broadcast(['private-orders.1', 'private-orders.2'], 'created', ['id' => 7]);

        $this->assertSame('appsync:events', $pushed[0]);
        $this->assertCount(2, $pushed[1]);

        $first = json_decode($pushed[1][0], true);

        $this->assertSame('test/private-orders.1', $first['channel']);
        $this->assertSame('created', $first['event']);
        $this->assertSame(['id' => 7], $first['data']);
        $this->assertIsInt($first['ts']);
        $this->assertSame('test/private-orders.2', json_decode($pushed[1][1], true)['channel']);
    }

    public function test_a_max_length_of_zero_leaves_the_list_uncapped()
    {
        $pipe = $this->expectPipeline();
        $pipe->shouldReceive('rpush')->once();
        $pipe->shouldNotReceive('ltrim');

        (new RedisBroadcaster($this->config(['connection' => 'default', 'key' => 'appsync:events', 'max_length' => 0])))
            ->broadcast(['private-orders.1'], 'created');
    }

    public function test_the_pre_1_2_channel_setting_still_names_the_list()
    {
        $pipe = $this->expectPipeline();
        $pipe->shouldReceive('rpush')->once()->with('legacy:events', Mockery::type('string'));
        $pipe->shouldReceive('ltrim')->once()->with('legacy:events', -10000, -1);

        (new RedisBroadcaster($this->config(['connection' => 'default', 'channel' => 'legacy:events'])))
            ->broadcast(['private-orders.1'], 'created');
    }

    public function test_nothing_is_pushed_without_channels()
    {
        Redis::shouldReceive('connection')->never();

        (new RedisBroadcaster($this->config(['connection' => 'default', 'key' => 'appsync:events'])))
            ->broadcast([], 'created');
    }
}
