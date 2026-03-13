<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Persistent WebSocket client for the AWS AppSync Event API.
 *
 * Maintains a long-lived WebSocket connection to the AppSync realtime
 * endpoint and publishes events through it, eliminating the per-event
 * HTTP overhead.
 *
 * Protocol handled:
 *   connection_init   → sent after TCP+WS handshake
 *   connection_ack    → received, transitions to READY state
 *   ka                → keep-alive heartbeat from server
 *   publish           → sent to push events to a channel
 *   publish_success   → received confirmation
 *   publish_error     → received error (may trigger token refresh)
 *
 * Thread-safety: this class is NOT thread-safe. It is designed to run
 * entirely within a single ReactPHP event loop.
 */
class AppSyncWebSocketClient
{
    // Connection lifecycle states
    protected const STATE_DISCONNECTED = 'disconnected';
    protected const STATE_CONNECTING   = 'connecting';
    protected const STATE_CONNECTED    = 'connected';
    protected const STATE_READY        = 'ready'; // After connection_ack

    protected LoopInterface $loop;
    protected array $config;
    protected TokenManager $tokenManager;

    protected ?WebSocket $connection = null;
    protected string $state          = self::STATE_DISCONNECTED;

    // Reconnection
    protected int $reconnectAttempts = 0;
    protected float $reconnectBaseDelay;
    protected float $reconnectMaxDelay;

    // Keep-alive monitoring
    protected ?float $lastKaReceived   = null;
    protected ?TimerInterface $kaTimer = null;
    protected int $connectionTimeoutMs = 300000;
    protected float $kaTimeoutMultiplier;

    // Callbacks
    protected ?\Closure $onReady      = null;
    protected ?\Closure $onDisconnect = null;

    // Metrics
    protected int $publishSentCount    = 0;
    protected int $publishSuccessCount = 0;
    protected int $publishErrorCount   = 0;
    protected int $reconnectCount      = 0;

    public function __construct(LoopInterface $loop, array $config, TokenManager $tokenManager)
    {
        $this->loop         = $loop;
        $this->config       = $config;
        $this->tokenManager = $tokenManager;

        $retryConfig = $config['retry'] ?? [];
        $wsConfig    = $config['websocket'] ?? [];

        // Convert millisecond config values to seconds for ReactPHP timers
        $this->reconnectBaseDelay  = ($retryConfig['base_delay'] ?? 1000) / 1000.0;
        $this->reconnectMaxDelay   = ($retryConfig['max_delay'] ?? 60000) / 1000.0;
        $this->kaTimeoutMultiplier = (float) ($wsConfig['ka_timeout_multiplier'] ?? 1.5);
    }

    // =========================================================================
    // Connection lifecycle
    // =========================================================================

    /**
     * Initiate a WebSocket connection to the AppSync realtime endpoint.
     *
     * Idempotent: calling while already connecting/connected is a no-op.
     */
    public function connect() : void
    {
        if ($this->state !== self::STATE_DISCONNECTED) {
            return;
        }

        $this->state = self::STATE_CONNECTING;

        try {
            $token = $this->tokenManager->getToken();
        } catch (\Throwable $e) {
            Log::error('AppSync WS: failed to get token for connection', [
                'error' => $e->getMessage(),
            ]);

            $this->state = self::STATE_DISCONNECTED;
            $this->scheduleReconnect();

            return;
        }

        $url          = $this->buildConnectionUrl();
        $authProtocol = $this->buildAuthProtocol($token);
        $connector    = new Connector($this->loop);

        Log::info('AppSync WS: connecting', [
            'attempt' => $this->reconnectAttempts + 1,
        ]);

        $connector($url, ['aws-appsync-event-ws', $authProtocol])
            ->then(
                function (WebSocket $conn) {
                    $this->onConnected($conn);
                },
                function (\Exception $e) {
                    $this->onConnectionFailed($e);
                }
            );
    }

    /**
     * Cleanly close the connection and cancel all timers.
     */
    public function disconnect() : void
    {
        if ($this->connection) {
            $this->connection->close();
        } else {
            $this->cleanup();
        }
    }

    // =========================================================================
    // Publishing
    // =========================================================================

    /**
     * Publish events to a single AppSync channel via WebSocket.
     *
     * @param string $channel Full channel path with leading slash (e.g. "/default/orders")
     * @param array  $events  Array of JSON-encoded event strings (max 5 per AppSync limit)
     * @return string|null    The publish message ID, or null if the connection is not ready
     */
    public function publish(string $channel, array $events): ?string
    {
        if ($this->state !== self::STATE_READY || ! $this->connection) {
            return null;
        }

        $id     = Str::uuid()->toString();
        $token  = $this->tokenManager->getToken();
        $appId  = $this->config['app_id'];
        $region = $this->config['region'];

        $this->send([
            'type'          => 'publish',
            'id'            => $id,
            'channel'       => $channel,
            'events'        => array_slice($events, 0, 5),
            'authorization' => [
                'Authorization' => "Bearer {$token}",
                'host'          => "{$appId}.appsync-api.{$region}.amazonaws.com",
            ],
        ]);

        $this->publishSentCount++;

        return $id;
    }

    // =========================================================================
    // State queries & callbacks
    // =========================================================================

    public function isReady(): bool
    {
        return $this->state === self::STATE_READY;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function onReady(\Closure $cb): self
    {
        $this->onReady = $cb;

        return $this;
    }

    public function onDisconnect(\Closure $cb): self
    {
        $this->onDisconnect = $cb;

        return $this;
    }

    public function getMetrics(): array
    {
        return [
            'state'           => $this->state,
            'publish_sent'    => $this->publishSentCount,
            'publish_success' => $this->publishSuccessCount,
            'publish_error'   => $this->publishErrorCount,
            'reconnect_count' => $this->reconnectCount,
        ];
    }

    // =========================================================================
    // Connection event handlers
    // =========================================================================

    protected function onConnected(WebSocket $conn): void
    {
        $this->connection = $conn;
        $this->state      = self::STATE_CONNECTED;

        Log::info('AppSync WS: TCP+WS handshake complete, sending connection_init');

        $conn->on('message', function (MessageInterface $msg) {
            $this->handleMessage((string) $msg);
        });

        $conn->on('close', function ($code = null, $reason = null) {
            $this->onClose($code, $reason);
        });

        $conn->on('error', function (\Exception $e) {
            Log::error('AppSync WS: transport error', ['error' => $e->getMessage()]);
        });

        $this->send(['type' => 'connection_init']);
    }

    protected function onConnectionFailed(\Exception $e): void
    {
        Log::error('AppSync WS: connection failed', [
            'error'   => $e->getMessage(),
            'attempt' => $this->reconnectAttempts + 1,
        ]);

        $this->state = self::STATE_DISCONNECTED;
        $this->scheduleReconnect();
    }

    protected function onClose($code, $reason): void
    {
        Log::warning('AppSync WS: connection closed', [
            'code'   => $code,
            'reason' => $reason,
        ]);

        $this->cleanup();
        $this->scheduleReconnect();
    }

    // =========================================================================
    // Protocol message handling
    // =========================================================================

    protected function handleMessage(string $raw): void
    {
        $data = json_decode($raw, true);

        if (! $data || ! isset($data['type'])) {
            Log::warning('AppSync WS: received unparseable message', [
                'raw' => Str::limit($raw, 200),
            ]);

            return;
        }

        switch ($data['type']) {
            case 'connection_ack':
                $this->handleConnectionAck($data);
                break;

            case 'ka':
                $this->lastKaReceived = microtime(true);
                break;

            case 'publish_success':
                $this->handlePublishSuccess($data);
                break;

            case 'publish_error':
                $this->handlePublishError($data);
                break;

            case 'connection_error':
                $this->handleConnectionError($data);
                break;

            default:
                Log::debug('AppSync WS: unhandled message type', ['type' => $data['type']]);
        }
    }

    protected function handleConnectionError(array $data): void
    {
        $errors = $data['errors'] ?? [];

        Log::error('AppSync WS: connection_error received', [
            'errors' => $errors,
        ]);

        // Force close — AppSync will close the socket after sending
        // connection_error, but we clean up proactively.
        if ($this->connection) {
            $this->connection->close();
        }
    }

    protected function handleConnectionAck(array $data): void
    {
        $this->state               = self::STATE_READY;
        $this->connectionTimeoutMs = $data['connectionTimeoutMs'] ?? 300000;
        $this->reconnectAttempts   = 0;
        $this->lastKaReceived      = microtime(true);

        Log::info('AppSync WS: connection_ack received, ready', [
            'connection_timeout_ms' => $this->connectionTimeoutMs,
        ]);

        $this->startKaMonitor();

        if ($this->onReady) {
            ($this->onReady)();
        }
    }

    protected function handlePublishSuccess(array $data): void
    {
        $this->publishSuccessCount++;

        Log::debug('AppSync WS: publish_success', ['id' => $data['id'] ?? '']);
    }

    protected function handlePublishError(array $data): void
    {
        $this->publishErrorCount++;
        $errors = $data['errors'] ?? [];

        Log::error('AppSync WS: publish_error', [
            'id'     => $data['id'] ?? '',
            'errors' => $errors,
        ]);

        // If the error is an auth error, refresh the token so the next
        // publish attempt uses a valid one.
        foreach ($errors as $err) {
            $errorType = $err['errorType'] ?? '';

            if (str_contains($errorType, 'Unauthorized') || str_contains($errorType, 'Forbidden')) {
                Log::warning('AppSync WS: auth error on publish, refreshing token');
                $this->tokenManager->invalidate();
                $this->tokenManager->refreshToken();

                break;
            }
        }
    }

    // =========================================================================
    // Keep-alive monitoring
    // =========================================================================

    /**
     * Start a periodic timer that checks for keep-alive messages.
     *
     * If the server stops sending `ka` messages beyond the connection
     * timeout * multiplier, the connection is considered dead and we
     * force a reconnect.
     */
    protected function startKaMonitor(): void
    {
        $this->stopKaMonitor();

        // Check at half the server's advertised timeout
        $checkInterval = ($this->connectionTimeoutMs / 1000.0) * 0.5;

        $this->kaTimer = $this->loop->addPeriodicTimer($checkInterval, function () {
            if ($this->lastKaReceived === null) {
                return;
            }

            $elapsed = microtime(true) - $this->lastKaReceived;
            $timeout = ($this->connectionTimeoutMs / 1000.0) * $this->kaTimeoutMultiplier;

            if ($elapsed > $timeout) {
                Log::warning('AppSync WS: keep-alive timeout, forcing reconnect', [
                    'elapsed_s' => round($elapsed, 2),
                    'timeout_s' => round($timeout, 2),
                ]);

                $this->disconnect();
            }
        });
    }

    protected function stopKaMonitor(): void
    {
        if ($this->kaTimer) {
            $this->loop->cancelTimer($this->kaTimer);
            $this->kaTimer = null;
        }
    }

    // =========================================================================
    // Reconnection
    // =========================================================================

    /**
     * Schedule a reconnection attempt with exponential backoff + jitter.
     *
     * The counter is never capped to a max-and-stop: a long-running worker
     * should always keep trying. The delay is capped instead.
     */
    protected function scheduleReconnect(): void
    {
        $this->reconnectAttempts++;
        $this->reconnectCount++;

        $delay = min(
            $this->reconnectBaseDelay * pow(2, $this->reconnectAttempts - 1),
            $this->reconnectMaxDelay
        );

        // Add 0-25% jitter to de-synchronise multiple workers
        $jitter  = $delay * (mt_rand(0, 250) / 1000.0);
        $delay  += $jitter;

        Log::info('AppSync WS: scheduling reconnect', [
            'delay_s' => round($delay, 2),
            'attempt' => $this->reconnectAttempts,
        ]);

        $this->loop->addTimer($delay, function () {
            $this->connect();
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build the plain WebSocket URL (no auth in query params).
     *
     * AppSync Event API expects auth via the WebSocket subprotocol header,
     * not as URL query parameters.
     */
    protected function buildConnectionUrl(): string
    {
        $appId  = $this->config['app_id'];
        $region = $this->config['region'];

        return "wss://{$appId}.appsync-realtime-api.{$region}.amazonaws.com/event/realtime";
    }

    /**
     * Build the base64url-encoded auth subprotocol string.
     *
     * AppSync Event API requires auth to be passed as a WebSocket subprotocol
     * in the format: header-<base64url_encoded_json_auth>
     */
    protected function buildAuthProtocol(string $token): string
    {
        $appId  = $this->config['app_id'];
        $region = $this->config['region'];

        $header = json_encode([
            'host' => "{$appId}.appsync-api.{$region}.amazonaws.com",
            'Authorization' => "Bearer {$token}",
        ]);

        // Base64url encoding (RFC 4648 §5): replace +/ with -_, strip padding
        $encoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');

        return "header-{$encoded}";
    }

    protected function send(array $data): void
    {
        if ($this->connection) {
            $this->connection->send(json_encode($data));
        }
    }

    /**
     * Reset all connection state without scheduling a reconnect.
     */
    protected function cleanup(): void
    {
        $previousState        = $this->state;
        $this->state          = self::STATE_DISCONNECTED;
        $this->connection     = null;
        $this->lastKaReceived = null;
        $this->stopKaMonitor();

        if ($previousState !== self::STATE_DISCONNECTED && $this->onDisconnect) {
            ($this->onDisconnect)();
        }
    }
}
