<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcastServiceProvider;
use Hamoood\LaravelAppSyncBroadcaster\Console\AppSyncWorkerCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the HTTP worker loop against a mocked Redis list and a faked AppSync.
 */
class RedisWorkerTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AppSyncBroadcastServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('appsync', [
            'driver'    => 'appsync',
            'mode'      => 'redis',
            'transport' => 'http',
            'namespace' => 'test',
            'app_id'    => 'test-app-id',
            'region'    => 'us-east-1',
            'cache'     => ['store' => 'array', 'prefix' => 'appsync_test_'],
            'http'      => ['timeout' => 10, 'connect_timeout' => 5],
            'retry'     => ['max_attempts' => 1, 'base_delay' => 1, 'max_delay' => 1, 'multiplier' => 1.0],
            'token'     => ['buffer' => 120],
            'redis'     => ['connection' => 'default', 'key' => 'appsync:events', 'batch_size' => 50, 'flush_interval' => 60000],
            'options'   => [
                'cognito_pool'          => 'test-pool',
                'cognito_region'        => 'us-east-1',
                'cognito_client_id'     => 'test-client-id',
                'cognito_client_secret' => 'test-client-secret',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        parent::tearDown();
    }

    protected function message(string $event): string
    {
        return json_encode(['channel' => 'test/private-orders.1', 'event' => $event, 'data' => [], 'ts' => 1]);
    }

    /**
     * Serve $queue through a mocked BLPOP/LPOP; once it is empty, the next
     * BLPOP stops the worker the way SIGTERM would.
     */
    protected function runWorker(array &$queue, ?\Closure $beforePop = null): string
    {
        Http::fake([
            '*amazoncognito.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*appsync-api*'        => Http::response(['successful' => [], 'failed' => []]),
        ]);

        $command = $this->app->make(AppSyncWorkerCommand::class);
        $command->setLaravel($this->app);

        $stop = fn () => (fn () => $this->stopping = true)->call($command);

        $connection = Mockery::mock();
        $connection->shouldReceive('blpop')->andReturnUsing(function ($key, $timeout) use (&$queue, $stop, $beforePop) {
            $this->assertSame('appsync:events', $key);

            if ($beforePop) {
                $beforePop();
            }

            if (! $queue) {
                $stop();

                return null;
            }

            return [$key, array_shift($queue)];
        });
        $connection->shouldReceive('lpop')->andReturnUsing(function () use (&$queue) {
            return array_shift($queue);
        });

        Redis::shouldReceive('connection')->with('default')->andReturn($connection);

        $output = new BufferedOutput();
        $command->run(new ArrayInput(['--transport' => 'http']), $output);

        return $output->fetch();
    }

    protected function publishedBatches(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/event'))
            ->map(fn ($pair) => array_map(fn ($e) => json_decode($e, true)['event'], $pair[0]['events']))
            ->values()
            ->all();
    }

    public function test_it_drains_the_list_and_publishes_at_most_five_events_per_request()
    {
        $queue   = array_map(fn ($i) => $this->message("e{$i}"), range(1, 7));
        $queue[] = 'not json';

        $output = $this->runWorker($queue);

        $this->assertSame([['e1', 'e2', 'e3', 'e4', 'e5'], ['e6', 'e7']], $this->publishedBatches());
        $this->assertStringContainsString('Events received  : 8', $output);
        $this->assertStringContainsString('Events published : 7', $output);
    }

    public function test_a_redis_error_costs_one_pop_not_the_worker()
    {
        $queue = [$this->message('after-error')];
        $calls = 0;

        $output = $this->runWorker($queue, function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Connection lost');
            }
        });

        $this->assertSame([['after-error']], $this->publishedBatches());
        $this->assertStringContainsString('Redis pop failed: Connection lost', $output);
    }
}
