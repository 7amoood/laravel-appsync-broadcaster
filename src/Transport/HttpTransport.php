<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Transport;

use Hamoood\LaravelAppSyncBroadcaster\TokenManager;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Synchronous HTTP transport for the AppSync Events API.
 *
 * Sends a POST to /event for each publish call with:
 *   - Automatic retry with exponential backoff + jitter
 *   - 401/403 detection → token refresh and single retry
 *   - CloudFront 494 / 5xx error handling
 *   - Persistent base client for connection reuse
 */
class HttpTransport implements TransportInterface
{
    protected array $config;
    protected TokenManager $tokenManager;
    protected ?PendingRequest $baseClient = null;

    public function __construct(array $config, TokenManager $tokenManager)
    {
        $this->config       = $config;
        $this->tokenManager = $tokenManager;
    }

    public function publish(string $channel, array $events): void
    {
        $retryConfig = $this->config['retry'] ?? [];
        $maxAttempts = $retryConfig['max_attempts'] ?? 3;
        $baseDelay   = $retryConfig['base_delay'] ?? 200;
        $maxDelay    = $retryConfig['max_delay'] ?? 5000;
        $multiplier  = $retryConfig['multiplier'] ?? 2.0;

        $attempt        = 0;
        $lastException  = null;
        $tokenRefreshed = false;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $token    = $this->tokenManager->getToken();
                $response = $this->getBaseClient()
                    ->withHeader('Authorization', $token)
                    ->post('/event', [
                        'channel' => $channel,
                        'events'  => $events,
                    ]);

                $status = $response->status();

                if ($response->successful()) {
                    Log::debug('AppSync event published via HTTP', [
                        'channel' => $channel,
                        'attempt' => $attempt,
                    ]);

                    return;
                }

                // 401/403: token expired — refresh once and retry
                if (in_array($status, [401, 403]) && ! $tokenRefreshed) {
                    Log::warning('AppSync HTTP auth error, refreshing token', [
                        'status'  => $status,
                        'channel' => $channel,
                    ]);

                    $this->tokenManager->invalidate();
                    $this->tokenManager->refreshToken();
                    $tokenRefreshed = true;
                    $attempt--;

                    continue;
                }

                // CloudFront errors — not retryable
                if ($status === 494 || ($status >= 520 && $status <= 530)) {
                    throw new BroadcastException(
                        "AppSync CloudFront error (HTTP {$status}): " . $response->body()
                    );
                }

                // 429 / 5xx — retryable
                if ($status === 429 || $status >= 500) {
                    Log::warning('AppSync HTTP retryable error', [
                        'status'  => $status,
                        'channel' => $channel,
                        'attempt' => $attempt,
                    ]);

                    $lastException = new BroadcastException(
                        "AppSync HTTP error (HTTP {$status})"
                    );
                } else {
                    // Other 4xx — not retryable
                    throw new BroadcastException(
                        "AppSync client error (HTTP {$status}): " . $response->body()
                    );
                }
            } catch (ConnectionException $e) {
                Log::warning('AppSync HTTP connection failed', [
                    'channel' => $channel,
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);

                $lastException = $e;
            } catch (BroadcastException $e) {
                // Non-retryable BroadcastExceptions are re-thrown above;
                // retryable ones fall through to backoff below.
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                $lastException = $e;
            }

            // Exponential backoff with jitter
            if ($attempt < $maxAttempts) {
                $delay  = (int) min($baseDelay * pow($multiplier, $attempt - 1), $maxDelay);
                $jitter = random_int(0, (int) ($delay * 0.25));

                Log::info('AppSync HTTP retry scheduled', [
                    'channel'  => $channel,
                    'attempt'  => $attempt + 1,
                    'delay_ms' => $delay + $jitter,
                ]);

                usleep(($delay + $jitter) * 1000);
            }
        }

        throw new BroadcastException(
            "Failed to publish to AppSync [{$channel}] after {$maxAttempts} attempts: "
            . ($lastException?->getMessage() ?? 'Unknown error')
        );
    }

    public function disconnect(): void
    {
        $this->baseClient = null;
    }

    protected function getBaseClient(): PendingRequest
    {
        if ($this->baseClient === null) {
            $httpConfig = $this->config['http'] ?? [];
            $appId      = $this->config['app_id'] ?? '';
            $region     = $this->config['region'] ?? '';

            $this->baseClient = Http::baseUrl("https://{$appId}.appsync-api.{$region}.amazonaws.com")
                ->timeout($httpConfig['timeout'] ?? 10)
                ->connectTimeout($httpConfig['connect_timeout'] ?? 5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'LaravelAppSyncBroadcaster/2.0',
                ])
                ->withOptions(['http_errors' => false]);
        }

        return $this->baseClient;
    }
}
