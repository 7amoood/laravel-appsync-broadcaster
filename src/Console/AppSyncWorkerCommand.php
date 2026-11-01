<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Console;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncWebSocketClient;
use Hamoood\LaravelAppSyncBroadcaster\Broadcasters\RedisBroadcaster;
use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Hamoood\LaravelAppSyncBroadcaster\Transport\HttpTransport;
use Hamoood\LaravelAppSyncBroadcaster\Transport\WebSocketTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use React\EventLoop\LoopInterface;

/**
 * Long-running worker that pops events from a Redis list and relays
 * them to AppSync using the configured transport (HTTP or WebSocket).
 *
 * The list is durable: events pushed while this worker is down, restarting
 * or reconnecting to Redis wait there until it pops them.
 *
 * HTTP transport     : blocking BLPOP loop, one HTTP POST per batch.
 * WebSocket transport: ReactPHP event loop, async BLPOP, persistent WS connection.
 *                      It only pops while the WebSocket is ready and the buffer has
 *                      room, so an AppSync outage leaves events in Redis instead of
 *                      dropping them. Every pop is a fresh command, so a dropped Redis
 *                      connection is re-opened by the next one.
 *
 * Usage:
 *   php artisan appsync:worker
 *   php artisan appsync:worker --transport=websocket
 *   php artisan appsync:worker --batch-size=5 --flush-interval=50
 *
 * Supervisor / systemd recommended for production.
 */
class AppSyncWorkerCommand extends Command
{
    /**
     * AppSync accepts at most this many events in one publish, over HTTP or WebSocket.
     */
    public const MAX_EVENTS_PER_PUBLISH = 5;

    protected $signature = 'appsync:worker
        {--transport=      : Transport to use: http or websocket (default from config)}
        {--batch-size=     : Max events to batch before flushing}
        {--flush-interval= : Max ms before flushing a partial batch}
        {--redis-key=      : Redis list to pop events from (default from config)}
        {--redis-channel=  : Deprecated alias of --redis-key}
        {--max-buffer=     : Max buffered events before the worker stops popping}
        {--stats-interval= : Seconds between stats log lines (default 30)}';

    protected $description = 'Pop events from a Redis list and relay them to AppSync (HTTP or WebSocket)';

    // Shared metrics
    protected int $eventsReceived  = 0;
    protected int $eventsPublished = 0;
    protected int $eventsDropped   = 0;
    protected int $flushCount      = 0;
    protected float $startTime;

    // Buffer: channel => [event_json_strings]
    protected array $buffer    = [];
    protected int $bufferCount = 0;

    // Set by SIGTERM / SIGINT; the loops finish their current pop, flush and exit
    protected bool $stopping = false;

    public function handle(): int
    {
        $config = config('broadcasting.connections.appsync');

        if (empty($config)) {
            $this->error('AppSync broadcasting config not found.');

            return self::FAILURE;
        }

        $transport = $this->option('transport') ?: ($config['transport'] ?? 'http');

        if ($transport === 'websocket') {
            return $this->runWithWebSocket($config);
        }

        return $this->runWithHttp($config);
    }

    // =====================================================================
    // HTTP transport path (blocking pop)
    // =====================================================================

    protected function runWithHttp(array $config): int
    {
        $tokenManager  = new TokenManager($config);
        $httpTransport = new HttpTransport($config, $tokenManager);

        $redisConfig   = $config['redis'] ?? [];
        $redisKey      = $this->redisKey($config);
        $redis         = Redis::connection($redisConfig['connection'] ?? 'default');
        $blockTimeout  = $this->blockTimeout($redisConfig);
        $batchSize     = (int) ($this->option('batch-size') ?: ($redisConfig['batch_size'] ?? 50));
        $flushInterval = (int) ($this->option('flush-interval') ?: ($redisConfig['flush_interval'] ?? 100));

        $this->startTime = microtime(true);
        $this->printBanner('http', $redisKey, $batchSize, $flushInterval);

        $this->registerPcntlSignals();

        $lastFlush = $this->currentTimeMs();

        while (! $this->stopping) {
            try {
                // Block only while the buffer is empty; with events waiting to be
                // flushed, take whatever else is already queued and flush the moment
                // the list runs dry.
                $message = $this->bufferCount > 0
                    ? $redis->lpop($redisKey)
                    : ($redis->blpop($redisKey, $blockTimeout)[1] ?? null);
            } catch (\Throwable $e) {
                if ($this->stopping) {
                    break;
                }

                Log::error('AppSync worker: Redis pop failed', ['error' => $e->getMessage()]);
                $this->error("Redis pop failed: {$e->getMessage()}");

                sleep(1);

                continue;
            }

            $drained = ! is_string($message);

            if (! $drained && ($event = $this->decodeEvent($message))) {
                $this->buffer[$event['channel']][] = json_encode([
                    'event'   => $event['event'] ?? '',
                    'data'    => $event['data'] ?? [],
                    'channel' => $event['channel'],
                ]);
                $this->bufferCount++;
            }

            if ($this->bufferCount > 0 && (
                $drained
                || $this->bufferCount >= $batchSize
                || $this->currentTimeMs() - $lastFlush >= $flushInterval
            )) {
                $this->flushHttpBuffer($httpTransport);
                $lastFlush = $this->currentTimeMs();
            }
        }

        // Flush remaining on shutdown
        if ($this->bufferCount > 0) {
            $this->flushHttpBuffer($httpTransport);
        }

        $this->printFinalStats();

        return self::SUCCESS;
    }

    protected function flushHttpBuffer(HttpTransport $transport): void
    {
        foreach ($this->buffer as $channel => $events) {
            foreach (array_chunk($events, self::MAX_EVENTS_PER_PUBLISH) as $batch) {
                try {
                    $transport->publish($channel, $batch);
                    $this->eventsPublished += count($batch);
                    $this->flushCount++;
                } catch (\Throwable $e) {
                    $this->eventsDropped += count($batch);

                    Log::error('AppSync worker HTTP publish failed', [
                        'channel' => $channel,
                        'count'   => count($batch),
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->buffer      = [];
        $this->bufferCount = 0;
    }

    // =====================================================================
    // WebSocket transport path (ReactPHP event loop)
    // =====================================================================

    protected function runWithWebSocket(array $config): int
    {
        $wsConfig      = $config['websocket'] ?? [];
        $redisConfig   = $config['redis'] ?? [];
        $batchSize     = min((int) ($this->option('batch-size') ?: ($wsConfig['batch_size'] ?? 5)), self::MAX_EVENTS_PER_PUBLISH);
        $flushInterval = (int) ($this->option('flush-interval') ?: ($wsConfig['flush_interval'] ?? 50));
        $maxBuffer     = (int) ($this->option('max-buffer') ?: ($wsConfig['max_buffer_size'] ?? 10000));
        $statsInterval = (int) ($this->option('stats-interval') ?: ($wsConfig['stats_interval'] ?? 30));
        $blockTimeout  = $this->blockTimeout($redisConfig);

        // Laravel's Redis client applies a prefix (e.g. "laravel_database_") to
        // every key, the RPUSH included. clue/redis-react doesn't know about
        // this prefix, so we must prepend it manually to pop the same list.
        $redisKey = $this->getPrefixedRedisKey($this->redisKey($config), $redisConfig);

        $this->startTime = microtime(true);
        $this->printBanner('websocket', $redisKey, $batchSize, $flushInterval);

        // ReactPHP loop
        $loop = \React\EventLoop\Loop::get();

        // Transport
        $wsClient = $this->makeWebSocketClient($config, $loop);

        $wsClient->onReady(function () {
            $this->info('WebSocket connection ready');
        });

        $wsClient->onDisconnect(function () {
            $this->warn('WebSocket disconnected — events stay in Redis until reconnect');
        });

        // Async Redis client. Lazy: it (re)connects on the next command, so a
        // dropped connection costs one failed pop, never the worker.
        $redis = (new \Clue\React\Redis\Factory($loop))->createLazyClient($this->buildRedisUrl($config));

        $finished = false;

        $finish = function () use (&$finished, $loop, $wsClient, $redis, $batchSize) {
            if ($finished) {
                return;
            }

            $finished = true;

            $this->flushAllWsChannels($batchSize, $wsClient);
            $wsClient->disconnect();
            $redis->close();
            $loop->stop();
        };

        $pop = function () use (&$pop, $finish, $loop, $redis, $redisKey, $blockTimeout, $maxBuffer, $batchSize, $wsClient) {
            if ($this->stopping) {
                $finish();

                return;
            }

            // Back-pressure: leave events in Redis while AppSync can't take them
            if (! $wsClient->isReady() || $this->bufferCount >= $maxBuffer) {
                $loop->addTimer(0.1, $pop);

                return;
            }

            $redis->blpop($redisKey, $blockTimeout)->then(
                function ($item) use (&$pop, $loop, $batchSize, $wsClient) {
                    try {
                        if (is_array($item) && isset($item[1])) {
                            $this->receiveWsEvent($item[1], $batchSize, $wsClient);
                        }
                    } catch (\Throwable $e) {
                        Log::error('AppSync worker: failed to buffer event', ['error' => $e->getMessage()]);
                    } finally {
                        $loop->futureTick($pop);
                    }
                },
                function (\Throwable $e) use (&$pop, $loop) {
                    if (! $this->stopping) {
                        Log::error('AppSync worker: Redis pop failed', ['error' => $e->getMessage()]);
                        $this->error("Redis pop failed: {$e->getMessage()}");
                    }

                    $loop->addTimer(1.0, $pop);
                }
            );
        };

        // Periodic flush
        $loop->addPeriodicTimer($flushInterval / 1000.0, function () use ($wsClient, $batchSize) {
            if ($this->bufferCount === 0 || ! $wsClient->isReady()) {
                return;
            }

            $this->flushAllWsChannels($batchSize, $wsClient);
        });

        // Periodic stats
        $loop->addPeriodicTimer($statsInterval, function () use ($wsClient) {
            Log::info('AppSync worker stats', array_merge([
                'events_received'  => $this->eventsReceived,
                'events_published' => $this->eventsPublished,
                'events_dropped'   => $this->eventsDropped,
                'flush_count'      => $this->flushCount,
                'buffer_count'     => $this->bufferCount,
                'uptime_s'         => round(microtime(true) - $this->startTime, 1),
            ], $wsClient->getMetrics()));
        });

        // Signal handling: stop popping; $pop flushes and stops the loop
        // once the pop in flight (at most block_timeout seconds) returns.
        $shutdownFn = function (int $signal) {
            $name = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
            $this->info("{$name} received, shutting down...");
            Log::info("AppSync worker received {$name}");

            $this->stopping = true;
        };

        if (defined('SIGTERM')) {
            $loop->addSignal(SIGTERM, $shutdownFn);
        }
        if (defined('SIGINT')) {
            $loop->addSignal(SIGINT, $shutdownFn);
        }

        $wsClient->connect();
        $loop->futureTick($pop);
        $loop->run();

        $this->printFinalStats($wsClient);

        return self::SUCCESS;
    }

    protected function makeWebSocketClient(array $config, LoopInterface $loop): AppSyncWebSocketClient
    {
        return (new WebSocketTransport($config, new TokenManager($config), $loop))->getClient();
    }

    protected function receiveWsEvent(string $message, int $batchSize, AppSyncWebSocketClient $wsClient): void
    {
        $event = $this->decodeEvent($message);

        if (! $event) {
            return;
        }

        $appSyncChannel = '/' . ltrim($event['channel'], '/');

        $this->buffer[$appSyncChannel][] = json_encode([
            'event'   => $event['event'] ?? '',
            'data'    => $event['data'] ?? [],
            'channel' => $event['channel'],
        ]);
        $this->bufferCount++;

        if (count($this->buffer[$appSyncChannel]) >= $batchSize) {
            $this->flushWsChannel($appSyncChannel, $batchSize, $wsClient);
        }
    }

    protected function flushWsChannel(string $channel, int $batchSize, AppSyncWebSocketClient $wsClient): void
    {
        if (! $wsClient->isReady() || empty($this->buffer[$channel])) {
            return;
        }

        $events  = $this->buffer[$channel];
        unset($this->buffer[$channel]);
        $this->bufferCount -= count($events);

        foreach (array_chunk($events, $batchSize) as $batch) {
            $id = $wsClient->publish($channel, $batch);

            if ($id !== null) {
                $this->eventsPublished += count($batch);
                $this->flushCount++;
            } else {
                $this->eventsDropped += count($batch);
            }
        }
    }

    protected function flushAllWsChannels(int $batchSize, AppSyncWebSocketClient $wsClient): void
    {
        foreach (array_keys($this->buffer) as $channel) {
            $this->flushWsChannel($channel, $batchSize, $wsClient);
        }
    }

    // =====================================================================
    // Shared helpers
    // =====================================================================

    protected function decodeEvent(string $message): ?array
    {
        $this->eventsReceived++;

        $event = json_decode($message, true);

        if (! is_array($event) || empty($event['channel'])) {
            Log::warning('AppSync worker: invalid message', [
                'payload' => Str::limit($message, 200),
            ]);

            return null;
        }

        return $event;
    }

    protected function redisKey(array $config): string
    {
        return $this->option('redis-key')
            ?: $this->option('redis-channel')
            ?: RedisBroadcaster::queueKey($config);
    }

    protected function blockTimeout(array $redisConfig): int
    {
        // 0 would block forever and the worker could never stop
        return max(1, (int) ($redisConfig['block_timeout'] ?? 1));
    }

    protected function getPrefixedRedisKey(string $key, array $redisConfig): string
    {
        $connection       = $redisConfig['connection'] ?? 'default';
        $connectionPrefix = config("database.redis.{$connection}.prefix");
        $globalPrefix     = config('database.redis.options.prefix', '');

        $prefix = $connectionPrefix ?? $globalPrefix;

        return $prefix . $key;
    }

    protected function buildRedisUrl(array $config): string
    {
        $connection  = $config['redis']['connection'] ?? 'default';
        $redisConfig = config("database.redis.{$connection}", []);

        $scheme   = ($redisConfig['scheme'] ?? 'tcp') === 'tls' ? 'rediss' : 'redis';
        $host     = $redisConfig['host'] ?? '127.0.0.1';
        $port     = $redisConfig['port'] ?? 6379;
        $database = $redisConfig['database'] ?? 0;
        $password = $redisConfig['password'] ?? null;
        $username = $redisConfig['username'] ?? null;

        $auth = '';

        if ($password) {
            $auth = $username
                ? urlencode($username) . ':' . urlencode($password) . '@'
                : ':' . urlencode($password) . '@';
        }

        return "{$scheme}://{$auth}{$host}:{$port}/{$database}";
    }

    protected function registerPcntlSignals(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->info('SIGTERM received, shutting down...');
                Log::info('AppSync worker received SIGTERM');

                $this->stopping = true;
            });

            pcntl_signal(SIGINT, function () {
                $this->info('SIGINT received, shutting down...');
                Log::info('AppSync worker received SIGINT');

                $this->stopping = true;
            });
        }
    }

    protected function printBanner(string $transport, string $redisKey, int $batchSize, int $flushInterval): void
    {
        $this->info("AppSync worker starting [transport={$transport}]");
        $this->info("  Redis list     : {$redisKey}");
        $this->info("  Batch size     : {$batchSize}");
        $this->info("  Flush interval : {$flushInterval}ms");

        Log::info('AppSync worker starting', [
            'transport'      => $transport,
            'redis_key'      => $redisKey,
            'batch_size'     => $batchSize,
            'flush_interval' => $flushInterval,
        ]);
    }

    protected function printFinalStats(?AppSyncWebSocketClient $wsClient = null): void
    {
        $elapsed = round(microtime(true) - $this->startTime, 2);

        $this->info('AppSync worker stopped');
        $this->info("  Uptime           : {$elapsed}s");
        $this->info("  Events received  : {$this->eventsReceived}");
        $this->info("  Events published : {$this->eventsPublished}");
        $this->info("  Events dropped   : {$this->eventsDropped}");
        $this->info("  Flush count      : {$this->flushCount}");

        $logContext = [
            'uptime_s'         => $elapsed,
            'events_received'  => $this->eventsReceived,
            'events_published' => $this->eventsPublished,
            'events_dropped'   => $this->eventsDropped,
            'flush_count'      => $this->flushCount,
        ];

        if ($wsClient) {
            $metrics    = $wsClient->getMetrics();
            $logContext = array_merge($logContext, $metrics);

            $this->info("  WS reconnects    : {$metrics['reconnect_count']}");
            $this->info("  WS publish errors : {$metrics['publish_error']}");
        }

        Log::info('AppSync worker stopped', $logContext);
    }

    protected function currentTimeMs(): float
    {
        return microtime(true) * 1000;
    }
}
