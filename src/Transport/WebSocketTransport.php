<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Transport;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncWebSocketClient;
use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;
use React\EventLoop\LoopInterface;

/**
 * WebSocket transport for the AppSync Event API.
 *
 * Wraps AppSyncWebSocketClient and exposes it through the same
 * TransportInterface that HttpTransport implements, so both
 * broadcasters can use either transport interchangeably.
 *
 * In a worker context the event loop is provided externally and the
 * loop runs continuously. In a direct (synchronous) context the
 * transport creates its own loop and runs it briefly to connect
 * and drain responses.
 */
class WebSocketTransport implements TransportInterface
{
    protected LoopInterface $loop;
    protected AppSyncWebSocketClient $client;
    protected array $config;
    protected bool $externalLoop;

    public function __construct(array $config, TokenManager $tokenManager, ?LoopInterface $loop = null)
    {
        $this->config       = $config;
        $this->externalLoop = $loop !== null;
        $this->loop         = $loop ?? \React\EventLoop\Loop::get();
        $this->client       = new AppSyncWebSocketClient($this->loop, $config, $tokenManager);
    }

    /**
     * Publish events to a channel through the WebSocket connection.
     *
     * The channel receives a leading slash automatically (AppSync WS
     * protocol requirement). Events are chunked into groups of 5
     * (AppSync per-message limit).
     */
    public function publish(string $channel, array $events): void
    {
        $this->ensureConnected();

        $wsChannel = '/' . ltrim($channel, '/');

        foreach (array_chunk($events, 5) as $batch) {
            $id = $this->client->publish($wsChannel, $batch);

            if ($id === null) {
                throw new BroadcastException(
                    "WebSocket not ready, cannot publish to [{$channel}]"
                );
            }
        }

        // In synchronous (non-worker) context, briefly drain the loop
        // so that outgoing frames are actually flushed to the socket.
        if (! $this->externalLoop) {
            $this->loop->addTimer(0.05, fn() => $this->loop->stop());
            $this->loop->run();
        }
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    /**
     * Block until the WebSocket connection is ready (connection_ack
     * received) or a timeout is reached.
     *
     * In worker context (external loop) the caller is responsible for
     * running the loop — this method just initiates the connection.
     */
    public function ensureConnected(float $timeout = 5.0): void
    {
        if ($this->client->isReady()) {
            return;
        }

        $this->client->connect();

        if ($this->externalLoop) {
            // The worker's event loop will drive the handshake.
            return;
        }

        // Synchronous mode: run the loop until connected or timed out.
        $deadline   = microtime(true) + $timeout;
        $checkTimer = $this->loop->addPeriodicTimer(0.01, function () use ($deadline) {
            if ($this->client->isReady() || microtime(true) >= $deadline) {
                $this->loop->stop();
            }
        });

        $this->loop->run();
        $this->loop->cancelTimer($checkTimer);

        if (! $this->client->isReady()) {
            Log::error('AppSync WebSocket connection timed out');

            throw new BroadcastException(
                'WebSocket connection to AppSync timed out'
            );
        }
    }

    // ------------------------------------------------------------------
    // Accessors used by the worker command
    // ------------------------------------------------------------------

    public function getClient(): AppSyncWebSocketClient
    {
        return $this->client;
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    public function isReady(): bool
    {
        return $this->client->isReady();
    }
}
