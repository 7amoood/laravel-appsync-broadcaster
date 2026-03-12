<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Broadcasters;

use Hamoood\LaravelAppSyncBroadcaster\Transport\TransportInterface;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;

/**
 * Broadcasts events directly to AppSync from the Laravel process.
 *
 * Each broadcast() call invokes the configured transport (HTTP or
 * WebSocket) synchronously, so the result is available immediately.
 *
 * Best suited for moderate throughput or when you want the simplest
 * possible setup (no worker process required).
 */
class DirectBroadcaster extends AppSyncBroadcaster
{
    protected TransportInterface $transport;

    public function __construct(array $config, TransportInterface $transport)
    {
        parent::__construct($config);

        $this->transport = $transport;
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $failures  = [];
        $successes = 0;

        foreach ($channels as $channel) {
            $channelName = (string) $channel;
            $fullChannel = "{$this->namespace}/{$channelName}";

            try {
                $eventData = json_encode([
                    'event'   => $event,
                    'data'    => $payload,
                    'channel' => $fullChannel,
                ]);

                $this->transport->publish($fullChannel, [$eventData]);
                $successes++;

                Log::debug('Event published to AppSync', [
                    'channel' => $fullChannel,
                    'event'   => $event,
                ]);
            } catch (\Throwable $e) {
                $failures[] = ['channel' => $fullChannel, 'error' => $e->getMessage()];

                Log::error('Broadcast failed', [
                    'channel' => $fullChannel,
                    'event'   => $event,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if (! empty($failures) && $successes === 0) {
            throw new BroadcastException(
                'All broadcasts failed: ' . json_encode($failures)
            );
        }

        if (! empty($failures)) {
            Log::warning('Partial broadcast failure', [
                'successes' => $successes,
                'failures'  => $failures,
            ]);
        }
    }

    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }
}
