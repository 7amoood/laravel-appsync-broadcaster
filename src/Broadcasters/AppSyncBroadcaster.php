<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Broadcasters;

use Carbon\Carbon;
use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Abstract base for all AppSync broadcasters.
 *
 * Contains the shared logic that every broadcaster needs:
 *   - Configuration validation
 *   - Channel authentication (private / presence / public)
 *   - Channel-name helpers (normalize, detect type, pattern matching)
 *   - TokenManager instance
 *
 * Subclasses only implement broadcast().
 */
abstract class AppSyncBroadcaster extends Broadcaster
{
    protected array $config;
    protected string $namespace;
    protected TokenManager $tokenManager;

    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->config       = $config;
        $this->namespace    = $config['namespace'] ?? 'default';
        $this->tokenManager = new TokenManager($config);
    }

    // =====================================================================
    // Config validation
    // =====================================================================

    protected function validateConfig(array $config): void
    {
        $requiredKeys = ['app_id', 'region', 'namespace'];

        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required config key: {$key}");
            }
        }

        $requiredOptions = ['cognito_pool', 'cognito_client_id', 'cognito_client_secret'];

        foreach ($requiredOptions as $option) {
            if (empty($config['options'][$option] ?? null)) {
                throw new \InvalidArgumentException("Missing required config option: {$option}");
            }
        }
    }

    // =====================================================================
    // Channel authentication (shared by all broadcasters)
    // =====================================================================

    public function auth($request)
    {
        $channel    = $request->channel_name;
        $normalized = $this->normalizeChannelName($channel);

        if (! $this->isGuardedChannel($channel)) {
            return ['auth' => true];
        }

        $user = $this->retrieveUser($request, $normalized);

        if (! $user) {
            Log::warning('Channel auth denied: no authenticated user', ['channel' => $channel]);

            throw new AccessDeniedHttpException("Access denied for channel {$channel}");
        }

        $this->verifyUserCanAccessChannel($request, $normalized);

        $authToken = $this->tokenManager->getToken();

        if ($this->isPresenceChannel($channel)) {
            return [
                'auth'         => $authToken,
                'channel_data' => [
                    'user_id'   => $user->id,
                    'user_info' => [
                        'name'      => $user->name,
                        'timestamp' => Carbon::now()->toISOString(),
                    ],
                ],
            ];
        }

        return ['auth' => $authToken];
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    // =====================================================================
    // Channel-name helpers
    // =====================================================================

    protected function normalizeChannelName($channel): string
    {
        if ($this->isPrivateChannel($channel)) {
            return Str::replaceFirst("{$this->namespace}/private-", '', $channel);
        }

        if ($this->isPresenceChannel($channel)) {
            return Str::replaceFirst("{$this->namespace}/presence-", '', $channel);
        }

        return $channel;
    }

    protected function isPrivateChannel($channel): bool
    {
        return Str::startsWith($channel, "{$this->namespace}/private-");
    }

    protected function isPresenceChannel($channel): bool
    {
        return Str::startsWith($channel, "{$this->namespace}/presence-");
    }

    protected function isGuardedChannel($channel): bool
    {
        return $this->isPrivateChannel($channel) || $this->isPresenceChannel($channel);
    }

    protected function extractChannelKeys($pattern, $channel): array
    {
        preg_match(
            '#^' . preg_replace('/\{(.*?)\}/', '(?<$1>[^/]+)', $pattern) . '#',
            $channel,
            $keys
        );

        return $keys;
    }

    protected function channelNameMatchesPattern($channel, $pattern): bool
    {
        $pattern = str_replace('/', '\/', $pattern);

        return (bool) preg_match(
            '#^' . preg_replace('/\{(.*?)\}/', '([^/]+)', $pattern) . '$#',
            $channel
        );
    }

    // =====================================================================
    // Accessors
    // =====================================================================

    public function getTokenManager(): TokenManager
    {
        return $this->tokenManager;
    }
}
