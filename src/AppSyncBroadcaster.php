<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

use Carbon\Carbon;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AppSyncBroadcaster extends Broadcaster
{
    protected array $config;
    protected ?PendingRequest $client = null;
    protected string $namespace;
    protected ?string $cachedToken = null;

    protected const MAX_RETRY_ATTEMPTS = 3;
    protected const RETRY_DELAY        = 100; // milliseconds
    protected const TOKEN_BUFFER       = 60;  // seconds before expiry to refresh

    public function __construct(array $config)
    {
        $this->config    = $config;
        $this->namespace = $config['namespace'];
    }


    /**
     * Get or create the HTTP client (lazy initialization)
     */
    protected function getClient(): PendingRequest
    {
        if ($this->client === null) {
            $this->client = $this->createHttpClient();
        }

        return $this->client;
    }

    /**
     * Create a fresh HTTP client with current auth token
     */
    protected function createHttpClient(): PendingRequest
    {
        return Http::baseUrl($this->getApiBaseUrl())
            ->timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent'   => 'AppSyncBroadcaster/1.0',
            ]);
    }

    /**
     * Get the API base URL
     */
    protected function getApiBaseUrl(): string
    {
        return "https://{$this->config['app_id']}.appsync-api.{$this->config['region']}.amazonaws.com";
    }

    /**
     * Reset the HTTP client (forces re-creation on next use)
     */
    protected function resetClient(): void
    {
        $this->client      = null;
        $this->cachedToken = null;
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
            } catch (UnauthorizedException $e) {
                // Token expired, reset client and retry once
                $this->resetClient();
                $this->clearAuthTokenCache();

                try {
                    $this->broadcastToChannel($channel, $event, $payload);
                    $successes++;
                } catch (\Exception $retryException) {
                    $failures[] = [
                        'channel' => $channel,
                        'error'   => $retryException->getMessage(),
                    ];
                    Log::error("Broadcast retry failed for channel {$channel}: " . $retryException->getMessage());
                }
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
                $response = $this->getClient()
                    ->withHeader('Authorization', $this->getAuthToken())
                    ->post('event', [
                        'channel' => $fullChannel,
                        'events'  => [json_encode($eventData)],
                    ]);

                if ($response->successful()) {
                    return;
                }

                // Handle 401 specifically
                if ($response->status() === 401) {
                    throw new UnauthorizedException("Unauthorized: Invalid or expired token");
                }

                throw new BroadcastException(
                    "AppSync broadcast failed with status {$response->status()}: " . $response->body()
                );
            } catch (UnauthorizedException $e) {
                // Let this bubble up for handling in broadcast()
                throw $e;
            } catch (ConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY * 1000 * $attempt); // Exponential backoff
                    Log::warning("Retrying broadcast to {$channel} (attempt {$attempt})");
                }
            } catch (RequestException $e) {
                if ($e->response && $e->response->status() === 401) {
                    throw new UnauthorizedException("Unauthorized: Invalid or expired token");
                }

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

    /**
     * Get cache key for auth token
     */
    protected function getAuthTokenCacheKey(): string
    {
        return $this->config['cache']['prefix'] . 'auth_token';
    }

    /**
     * Get cache driver instance
     */
    protected function getCacheDriver()
    {
        return Cache::driver($this->config['cache']['driver']);
    }

    /**
     * Clear the auth token from cache
     */
    protected function clearAuthTokenCache(): void
    {
        $this->getCacheDriver()->forget($this->getAuthTokenCacheKey());
        $this->cachedToken = null;
    }

    /**
     * Get auth token with proper caching using Cache::remember()
     */
    protected function getAuthToken(): string
    {
        // Use in-memory cache for same request
        if ($this->cachedToken !== null) {
            return $this->cachedToken;
        }

        try {
            $cacheKey = $this->getAuthTokenCacheKey();
            $cache    = $this->getCacheDriver();

            // Try to get from cache first
            $token = $cache->get($cacheKey);

            if ($token) {
                $this->cachedToken = $token;

                return $token;
            }

            // Token not in cache, fetch new one
            $token             = $this->fetchNewAuthToken();
            $this->cachedToken = $token;

            return $token;
        } catch (\Exception $e) {
            Log::error("Auth token generation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch a new auth token from Cognito
     */
    protected function fetchNewAuthToken(): string
    {
        $cognitoUrl = sprintf(
            "https://%s.auth.%s.amazoncognito.com/oauth2/token",
            $this->config['options']['cognito_pool'],
            $this->config['options']['cognito_region']
        );

        $response = Http::asForm()
            ->timeout(10)
            ->retry(2, 100)
            ->post($cognitoUrl, [
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

        $token     = $response->json('access_token');
        $expiresIn = $response->json('expires_in', 3600);

        if (! $token) {
            throw new BroadcastException("No access token received from Cognito");
        }

        // Cache the token with buffer time before expiry
        $this->getCacheDriver()->put(
            $this->getAuthTokenCacheKey(),
            $token,
            $expiresIn - self::TOKEN_BUFFER
        );

        return $token;
    }
}
