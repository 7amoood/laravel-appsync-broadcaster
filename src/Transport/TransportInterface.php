<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Transport;

/**
 * Contract for publishing events to the AppSync Event API.
 *
 * Two implementations exist:
 *   HttpTransport      – synchronous HTTP POST per publish
 *   WebSocketTransport – persistent WebSocket connection
 *
 * Both broadcasters (Direct and Redis worker) use this interface,
 * meaning the transport layer is fully interchangeable via config.
 */
interface TransportInterface
{
    /**
     * Publish events to a single AppSync channel.
     *
     * @param string $channel  Namespaced channel (e.g. "default/orders")
     * @param array  $events   Array of JSON-encoded event strings
     *
     * @throws \Illuminate\Broadcasting\BroadcastException
     */
    public function publish(string $channel, array $events): void;

    /**
     * Tear down the underlying connection (if any).
     */
    public function disconnect(): void;
}
