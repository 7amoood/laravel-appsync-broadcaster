<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Console;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncWebSocketClient;
use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Hamoood\LaravelAppSyncBroadcaster\Transport\HttpTransport;
use Hamoood\LaravelAppSyncBroadcaster\Transport\WebSocketTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Long-running worker that subscribes to Redis Pub/Sub and relays
 * events to AppSync using the configured transport (HTTP or WebSocket).
 *
 * HTTP transport  : blocking Redis SUBSCRIBE loop, one HTTP POST per batch.
 * WebSocket transport: ReactPHP event loop, persistent WS connection.
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
    protected $signature = 'appsync:worker
        {--transport=      : Transport to use: http or websocket (default from config)}
        {--batch-size=     : Max events to batch before flushing}
        {--flush-interval= : Max ms before flushing a partial batch}
        {--redis-channel=  : Redis Pub/Sub channel to subscribe to}
        {--max-buffer=     : Max buffered events before dropping}
        {--stats-interval= : Seconds between stats log lines (default 30)}';

    protected $description = 'Subscribe to Redis and relay events to AppSync (HTTP or WebSocket)';

    // Shared metrics
    protected int $eventsReceived  = 0;
    protected int $eventsPublished = 0;
    protected int $eventsDropped   = 0;
    protected int $flushCount      = 0;
    protected float $startTime;

    // Buffer: channel => [event_json_strings]
    protected array $buffer    = [];
    protected int $bufferCount = 0;

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
    // HTTP transport path (blocking subscribe)
    // =====================================================================

    protected function runWithHttp(array $config): int
    {
        $tokenManager  = new TokenManager($config);
        $httpTransport = new HttpTransport($config, $tokenManager);

        $redisConfig   = $config['redis'] ?? [];
        $redisChannel  = $this->option('redis-channel') ?: ($redisConfig['channel'] ?? 'appsync:events');
        $redisConn     = $redisConfig['connection'] ?? 'default';
        $batchSize     = (int) ($this->option('batch-size') ?: ($redisConfig['batch_size'] ?? 50));
        $flushInterval = (int) ($this->option('flush-interval') ?: ($redisConfig['flush_interval'] ?? 100));

        $this->startTime = microtime(true);
        $this->printBanner('http', $redisChannel, $batchSize, $flushInterval);

        $this->registerPcntlSignals();

        $lastFlush = $this->currentTimeMs();

        Redis::connection($redisConn)->subscribe([$redisChannel], function (string $message) use (
            $httpTransport, $batchSize, $flushInterval, &$lastFlush
        ) {
            $this->eventsReceived++;

            $event = json_decode($message, true);

            if (! $event || empty($event['channel'])) {
                Log::warning('AppSync worker: invalid message', [
                    'payload' => Str::limit($message, 200),
                ]);

                return;
            }

            $channel      = $event['channel'];
            $eventPayload = json_encode([
                'event'   => $event['event'] ?? '',
                'data'    => $event['data'] ?? [],
                'channel' => $channel,
            ]);

            $this->buffer[$channel][] = $eventPayload;
            $this->bufferCount++;

            $elapsed = $this->currentTimeMs() - $lastFlush;

            if ($this->bufferCount >= $batchSize || $elapsed >= $flushInterval) {
                $this->flushHttpBuffer($httpTransport);
                $lastFlush = $this->currentTimeMs();
            }
        });

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
            try {
                $transport->publish($channel, $events);
                $this->eventsPublished += count($events);
                $this->flushCount++;
            } catch (\Throwable $e) {
                $this->eventsDropped += count($events);

                Log::error('AppSync worker HTTP publish failed', [
                    'channel' => $channel,
                    'count'   => count($events),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->buffer       = [];
        $this->bufferCount  = 0;
    }

    // =====================================================================
    // WebSocket transport path (ReactPHP event loop)
    // =====================================================================

    protected function runWithWebSocket(array $config): int
    {
        $wsConfig      = $config['websocket'] ?? [];
        $redisConfig   = $config['redis'] ?? [];
        $redisChannel  = $this->option('redis-channel') ?: ($redisConfig['channel'] ?? 'appsync:events');
        $batchSize     = min((int) ($this->option('batch-size') ?: ($wsConfig['batch_size'] ?? 5)), 5);
        $flushInterval = (int) ($this->option('flush-interval') ?: ($wsConfig['flush_interval'] ?? 50));
        $maxBuffer     = (int) ($this->option('max-buffer') ?: ($wsConfig['max_buffer_size'] ?? 10000));
        $statsInterval = (int) ($this->option('stats-interval') ?: ($wsConfig['stats_interval'] ?? 30));

        // Laravel's Redis client applies a prefix (e.g. "laravel_database_") to
        // all commands including PUBLISH. clue/redis-react doesn't know about
        // this prefix, so we must prepend it manually to match the channel.
        $redisChannel = $this->getPrefixedRedisChannel($redisChannel, $redisConfig);

        $this->startTime = microtime(true);
        $this->printBanner('websocket', $redisChannel, $batchSize, $flushInterval);

        // ReactPHP loop
        $loop = \React\EventLoop\Loop::get();

        // Transport
        $tokenManager = new TokenManager($config);
        $wsTransport  = new WebSocketTransport($config, $tokenManager, $loop);
        $wsClient     = $wsTransport->getClient();

        $wsClient->onReady(function () {
            $this->info('WebSocket connection ready');
        });

        $wsClient->onDisconnect(function () {
            $this->warn('WebSocket disconnected — events will buffer until reconnect');
        });

        // Async Redis subscriber
        $redisUrl   = $this->buildRedisUrl($config);
        $redisFact  = new \Clue\React\Redis\Factory($loop);
        $subscriber = $redisFact->createLazyClient($redisUrl);

        $subscriber->on('error', function (\Exception $e) {
            Log::error('AppSync worker: Redis error', ['error' => $e->getMessage()]);
            $this->error("Redis error: {$e->getMessage()}");
        });

        $subscriber->on('close', function () {
            Log::error('AppSync worker: Redis connection closed');
            $this->error('Redis connection closed');
        });

        $subscriber->subscribe($redisChannel);

        $subscriber->on('message', function (string $channel, string $payload) use ($batchSize, $maxBuffer, $wsClient) {
            $this->eventsReceived++;

            $event = json_decode($payload, true);

            if (! $event || empty($event['channel'])) {
                Log::warning('AppSync worker: invalid message', [
                    'payload' => Str::limit($payload, 200),
                ]);

                return;
            }

            if ($this->bufferCount >= $maxBuffer) {
                $this->eventsDropped++;

                if ($this->eventsDropped % 100 === 1) {
                    Log::warning('AppSync worker: buffer full, dropping', [
                        'buffer_count'  => $this->bufferCount,
                        'total_dropped' => $this->eventsDropped,
                    ]);
                }

                return;
            }

            $appSyncChannel = '/' . ltrim($event['channel'], '/');

            $eventPayload = json_encode([
                'event'   => $event['event'] ?? '',
                'data'    => $event['data'] ?? [],
                'channel' => $event['channel'],
            ]);

            $this->buffer[$appSyncChannel][] = $eventPayload;
            $this->bufferCount++;

            if (count($this->buffer[$appSyncChannel]) >= $batchSize) {
                $this->flushWsChannel($appSyncChannel, $batchSize, $wsClient);
            }
        });

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

        // Signal handling
        $shutdownFn = function (int $signal) use ($loop, $wsClient, $batchSize) {
            $name = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
            $this->info("{$name} received, shutting down...");
            Log::info("AppSync worker received {$name}");

            $this->flushAllWsChannels($batchSize, $wsClient);
            $wsClient->disconnect();
            $loop->stop();
        };

        if (defined('SIGTERM')) {
            $loop->addSignal(SIGTERM, $shutdownFn);
        }
        if (defined('SIGINT')) {
            $loop->addSignal(SIGINT, $shutdownFn);
        }

        $wsClient->connect();
        $loop->run();

        $this->printFinalStats($wsClient);

        return self::SUCCESS;
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

    protected function getPrefixedRedisChannel(string $channel, array $redisConfig): string
    {
        $connection      = $redisConfig['connection'] ?? 'default';
        $connectionPrefix = config("database.redis.{$connection}.prefix");
        $globalPrefix     = config('database.redis.options.prefix', '');

        $prefix = $connectionPrefix ?? $globalPrefix;

        return $prefix . $channel;
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
            });

            pcntl_signal(SIGINT, function () {
                $this->info('SIGINT received, shutting down...');
                Log::info('AppSync worker received SIGINT');
            });
        }
    }

    protected function printBanner(string $transport, string $redisChannel, int $batchSize, int $flushInterval): void
    {
        $this->info("AppSync worker starting [transport={$transport}]");
        $this->info("  Redis channel  : {$redisChannel}");
        $this->info("  Batch size     : {$batchSize}");
        $this->info("  Flush interval : {$flushInterval}ms");

        Log::info('AppSync worker starting', [
            'transport'      => $transport,
            'redis_channel'  => $redisChannel,
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
