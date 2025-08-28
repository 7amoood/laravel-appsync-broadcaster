<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

use Carbon\Carbon;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AppSyncBroadcaster extends Broadcaster
{
    protected $config;
    protected $client;
    protected $namespace;
    protected const MAX_RETRY_ATTEMPTS = 3;
    protected const RETRY_DELAY        = 100; // milliseconds

    public function __construct($config)
    {
        $this->validateConfig($config);
        $this->config    = $config;
        $this->namespace = $config['namespace'];
        $this->initializeHttpClient();
    }

    /**
     * Validate the configuration array
     */
    protected function validateConfig(array $config): void
    {
        $required        = ['app_id', 'region', 'namespace', 'options'];
        $requiredOptions = ['cognito_pool', 'cognito_region', 'cognito_client_id', 'cognito_client_secret'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config key: {$key}");
            }
        }

        foreach ($requiredOptions as $key) {
            if (empty($config['options'][$key])) {
                throw new \InvalidArgumentException("Missing required config option: {$key}");
            }
        }
    }

    /**
     * Initialize HTTP client with proper configuration
     */
    protected function initializeHttpClient(): void
    {
        $this->client = Http::baseUrl("https://{$this->config['app_id']}.appsync-api.{$this->config['region']}.amazonaws.com")
            ->timeout(30)
            ->retry(self::MAX_RETRY_ATTEMPTS, self::RETRY_DELAY)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'AppSyncBroadcaster/1.0',
                'Authorization' => $this->getAuthToken(),
            ]);
    }

    /**
     * Extract channel keys from pattern
     */
    protected function extractChannelKeys($pattern, $channel)
    {
        preg_match('#^' . preg_replace('/\{(.*?)\}/', '(?<$1>[^/]+)', $pattern) . '#', $channel, $keys);

        return $keys;
    }

    /**
     * Check if channel name matches pattern
     */
    protected function channelNameMatchesPattern($channel, $pattern)
    {
        $pattern = str_replace('/', '\/', $pattern);

        return preg_match('#^' . preg_replace('/\{(.*?)\}/', '([^/]+)', $pattern) . '$#', $channel);
    }

    /**
     * Authenticate the incoming request for private/presence channels
     */
    public function auth($request)
    {
        try {
            $channel    = $request->channel_name;
            $normalized = $this->normalizeChannelName($channel);

            $user = $this->retrieveUser($request, $normalized);

            if ($this->isGuardedChannel($channel)) {
                if (! $user) {
                    Log::warning("Access denied for channel {$channel}: No authenticated user");
                    throw new AccessDeniedHttpException("Access Denied for channel {$channel}");
                }

                $this->verifyUserCanAccessChannel($request, $normalized);

                $authKey = $this->getAuthToken();

                if ($this->isPresenceChannel($channel)) {
                    return [
                        'auth'         => $authKey,
                        'channel_data' => [
                            'user_id'   => $user->id,
                            'user_info' => [
                                'name'      => $user->name,
                                'timestamp' => Carbon::now()->toISOString(),
                            ],
                        ],
                    ];
                }

                return ['auth' => $authKey];
            }

            return ['auth' => true]; // public channels
        } catch (\Exception $e) {
            Log::error("Authentication failed for channel {$request->channel_name}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate authentication response
     */
    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    /**
     * Broadcast event to multiple channels with improved error handling
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        $failures  = [];
        $successes = 0;

        foreach ($channels as $channel) {
            try {
                $this->broadcastToChannel($channel, $event, $payload);
                $successes++;
            } catch (\Exception $e) {
                $failures[] = [
                    'channel' => $channel,
                    'error'   => $e->getMessage(),
                ];
                Log::error("Broadcast failed for channel {$channel}: " . $e->getMessage());
            }
        }

        if (! empty($failures) && $successes === 0) {
            throw new BroadcastException("All broadcasts failed: " . json_encode($failures));
        }

        if (! empty($failures)) {
            Log::warning("Partial broadcast failure", ['failures' => $failures, 'successes' => $successes]);
        }
    }

    /**
     * Broadcast to a single channel with retry logic
     */
    protected function broadcastToChannel(string $channel, string $event, array $payload): void
    {
        $fullChannel = "{$this->namespace}/{$channel}";
        $eventData   = [
            'event'     => $event,
            'data'      => $payload,
            'channel'   => $fullChannel,
            'timestamp' => Carbon::now()->toISOString(),
        ];

        $attempt       = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            try {
                $response = $this->client->post('event', [
                    'channel' => $fullChannel,
                    'events'  => [json_encode($eventData)],
                ]);

                if ($response->successful()) {
                    return;
                }

                throw new BroadcastException(
                    "AppSync broadcast failed with status {$response->status()}: " . $response->body()
                );
            } catch (ConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY * 1000 * $attempt); // Exponential backoff
                    Log::warning("Retrying broadcast to {$channel} (attempt {$attempt})");
                }
            } catch (RequestException $e) {
                throw new BroadcastException("HTTP request failed: " . $e->getMessage());
            }
        }

        throw new BroadcastException(
            "Failed to broadcast to {$channel} after " . self::MAX_RETRY_ATTEMPTS . " attempts: " .
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    protected function normalizeChannelName($channel)
    {
        if ($this->isPrivateChannel($channel)) {
            return Str::replaceFirst("{$this->namespace}/private-", '', $channel);
        }

        if ($this->isPresenceChannel($channel)) {
            return Str::replaceFirst("{$this->namespace}/presence-", '', $channel);
        }

        return $channel;
    }

    protected function isPrivateChannel($channel)
    {
        return Str::startsWith($channel, "{$this->namespace}/private-");
    }

    protected function isPresenceChannel($channel)
    {
        return Str::startsWith($channel, "{$this->namespace}/presence-");
    }

    protected function isGuardedChannel($channel)
    {
        return $this->isPrivateChannel($channel) || $this->isPresenceChannel($channel);
    }

    protected function getAuthToken()
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post("https://{$this->config['options']['cognito_pool']}.auth.{$this->config['options']['cognito_region']}.amazoncognito.com/oauth2/token", [
                    'grant_type'    => 'client_credentials',
                    'scope'         => 'default-m2m-resource-server-l0ryrn/read',
                    'client_id'     => $this->config['options']['cognito_client_id'],
                    'client_secret' => $this->config['options']['cognito_client_secret'],
                ]);

            if ($response->failed()) {
                Log::error("Failed to get auth token", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new BroadcastException("Failed to authenticate with Cognito: " . $response->body());
            }

            $token = $response->json('access_token');

            if (! $token) {
                throw new BroadcastException("No access token received from Cognito");
            }

            return $token;
        } catch (\Exception $e) {
            Log::error("Auth token generation failed: " . $e->getMessage());
            throw $e;
        }
    }
}
