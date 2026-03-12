<?php
namespace Hamoood\LaravelAppSyncBroadcaster;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages Cognito OAuth2 token lifecycle with cache-only storage.
 *
 * No in-memory caching -- every call reads from the cache store (Redis).
 * This is safe for long-running queue workers: when a token is refreshed
 * by any process, all other processes see the new token immediately.
 */
class TokenManager
{
    protected array $config;
    protected string $cacheStore;
    protected string $cachePrefix;
    protected int $tokenBuffer;

    public function __construct(array $config)
    {
        $this->config      = $config;
        $this->cacheStore  = $config['cache']['store'] ?? 'redis';
        $this->cachePrefix = $config['cache']['prefix'] ?? 'appsync_broadcast_';
        $this->tokenBuffer = $config['token']['buffer'] ?? 120;
    }

    /**
     * Get a valid authentication token.
     *
     * Always reads from the cache store -- never from process memory.
     * If no cached token exists, fetches a new one from Cognito.
     */
    public function getToken(): string
    {
        $token = $this->cache()->get($this->cacheKey());

        if ($token !== null) {
            return $token;
        }

        Log::info('AppSync token not in cache, fetching new token from Cognito');

        return $this->refreshToken();
    }

    /**
     * Force-refresh the token: clear cache, fetch new, store, return.
     *
     * Uses an atomic lock to prevent multiple workers from simultaneously
     * hitting Cognito when a token expires (thundering herd).
     */
    public function refreshToken(): string
    {
        $lockKey = $this->cachePrefix . 'token_refresh_lock';
        $lock    = Cache::store($this->cacheStore)->lock($lockKey, 10);

        // Try to acquire the lock for up to 5 seconds
        try {
            return $lock->block(5, function () {
                // Double-check: another process may have refreshed while we waited
                $existing = $this->cache()->get($this->cacheKey());

                if ($existing !== null) {
                    Log::debug('AppSync token was refreshed by another process');

                    return $existing;
                }

                return $this->fetchAndCacheToken();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Lock acquisition timed out; try to fetch anyway as a fallback
            Log::warning('AppSync token refresh lock timed out, fetching without lock');

            return $this->fetchAndCacheToken();
        }
    }

    /**
     * Invalidate the current cached token.
     */
    public function invalidate(): void
    {
        $this->cache()->forget($this->cacheKey());

        Log::info('AppSync auth token invalidated');
    }

    /**
     * Fetch a new token from Cognito and store it in the cache.
     */
    protected function fetchAndCacheToken(): string
    {
        $options = $this->config['options'] ?? [];

        $cognitoUrl = sprintf(
            'https://%s.auth.%s.amazoncognito.com/oauth2/token',
            $options['cognito_pool'] ?? '',
            $options['cognito_region'] ?? $this->config['region'] ?? ''
        );

        $params = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $options['cognito_client_id'] ?? '',
            'client_secret' => $options['cognito_client_secret'] ?? '',
            'scope'         => $options['cognito_scope'] ?? '',
        ];

        Log::debug('Requesting new Cognito token', ['url' => $cognitoUrl]);

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(2, 500)
            ->post($cognitoUrl, $params);

        if ($response->failed()) {
            Log::error('Cognito token request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new BroadcastException(
                'Failed to authenticate with Cognito: HTTP ' . $response->status()
            );
        }

        $token     = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if (empty($token)) {
            throw new BroadcastException('No access_token in Cognito response');
        }

        // Cache with buffer so the token is refreshed before actual expiry
        $cacheTtl = max($expiresIn - $this->tokenBuffer, 60);

        $this->cache()->put($this->cacheKey(), $token, $cacheTtl);

        Log::info('AppSync Cognito token cached', [
            'expires_in' => $expiresIn,
            'cache_ttl'  => $cacheTtl,
        ]);

        return $token;
    }

    protected function cacheKey(): string
    {
        return $this->cachePrefix . 'auth_token';
    }

    protected function cache()
    {
        return Cache::store($this->cacheStore);
    }
}
