<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Broadcasters;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * High-throughput broadcaster that publishes events to Redis Pub/Sub.
 *
 * The Laravel process never talks to AppSync directly — it only
 * does a Redis PUBLISH (sub-millisecond). A separate worker process
 * (php artisan appsync:worker) subscribes and relays to AppSync
 * using whichever transport (HTTP or WebSocket) is configured.
 */
class RedisBroadcaster extends AppSyncBroadcaster
{
    protected string $redisConnection;
    protected string $redisChannel;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->redisConnection = $config['redis']['connection'] ?? 'default';
        $this->redisChannel    = $config['redis']['channel'] ?? 'appsync:events';
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $connection = Redis::connection($this->redisConnection);

        foreach ($channels as $channel) {
            $channelName = (string) $channel;
            $fullChannel = "{$this->namespace}/{$channelName}";

            $message = json_encode([
                'channel' => $fullChannel,
                'event'   => $event,
                'data'    => $payload,
                'ts'      => Carbon::now()->getTimestampMs(),
            ], JSON_THROW_ON_ERROR);

            $connection->publish($this->redisChannel, $message);
        }

        Log::debug('Events published to Redis', [
            'channels' => count($channels),
            'event'    => $event,
        ]);
    }
}
