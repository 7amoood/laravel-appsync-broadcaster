<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Broadcasters;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * High-throughput broadcaster that pushes events onto a Redis list.
 *
 * The Laravel process never talks to AppSync directly — it only does an
 * RPUSH (sub-millisecond). A separate worker process (php artisan
 * appsync:worker) pops the list and relays to AppSync using whichever
 * transport (HTTP or WebSocket) is configured.
 *
 * A list, unlike Pub/Sub, keeps what nobody has read yet: events pushed
 * while the worker is restarting, reconnecting or down wait in Redis.
 * The list is capped at `redis.max_length` (oldest dropped first) so a
 * worker that never comes back cannot fill Redis.
 */
class RedisBroadcaster extends AppSyncBroadcaster
{
    protected string $redisConnection;
    protected string $redisKey;
    protected int $maxLength;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->redisConnection = $config['redis']['connection'] ?? 'default';
        $this->redisKey        = static::queueKey($config);
        $this->maxLength       = (int) ($config['redis']['max_length'] ?? 10000);
    }

    /**
     * The Redis list events are pushed to. Falls back to the pre-1.2
     * `redis.channel` key so an already-published config keeps working.
     */
    public static function queueKey(array $config): string
    {
        return $config['redis']['key'] ?? $config['redis']['channel'] ?? 'appsync:events';
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (empty($channels)) {
            return;
        }

        $timestamp = Carbon::now()->getTimestampMs();
        $messages  = [];

        foreach ($channels as $channel) {
            $messages[] = json_encode([
                'channel' => "{$this->namespace}/{$channel}",
                'event'   => $event,
                'data'    => $payload,
                'ts'      => $timestamp,
            ], JSON_THROW_ON_ERROR);
        }

        Redis::connection($this->redisConnection)->pipeline(function ($pipe) use ($messages) {
            $pipe->rpush($this->redisKey, ...$messages);

            if ($this->maxLength > 0) {
                $pipe->ltrim($this->redisKey, -$this->maxLength, -1);
            }
        });

        Log::debug('Events pushed to Redis', [
            'channels' => count($channels),
            'event'    => $event,
        ]);
    }
}
