# Laravel AppSync Broadcaster

A Laravel broadcasting driver for AWS AppSync that supports **two publishing modes** (`direct`, `redis`) and **two transports** (`http`, `websocket`) for real-time event delivery.

## Features

- **Two publishing modes**: publish directly from Laravel or queue through Redis
- **Two transport layers**: send via HTTP or a persistent WebSocket connection
- **Background worker support**: `php artisan appsync:worker` relays Redis events to AppSync
- **Channel support**: public, private, and presence channels
- **Authentication**: Cognito-based auth for secured channels
- **Retry, buffering, and back-pressure controls**
- **Frontend integration**: WebSocket subscription examples for JavaScript and browsers
- **Laravel integration**: plugs into Laravel's native broadcasting system

## Installation

Install the package via Composer:

```bash
composer require 7amoood/laravel-appsync-broadcaster
```

The service provider is auto-discovered by Laravel.

## Configuration

1. Publish the configuration file:

```bash
php artisan vendor:publish --tag=appsync-config
```

2. Set AppSync as your default broadcast driver and configure the core variables in `.env`:

```env
BROADCAST_DRIVER=appsync

APPSYNC_NAMESPACE=default
APPSYNC_APP_ID=your-appsync-app-id
APPSYNC_EVENT_REGION=us-east-1

# Publishing architecture
APPSYNC_MODE=direct
APPSYNC_TRANSPORT=http

# Cognito OAuth2 credentials
APPSYNC_COGNITO_SCOPE=
APPSYNC_COGNITO_POOL=your-cognito-pool-id
APPSYNC_COGNITO_REGION=us-east-1
APPSYNC_COGNITO_CLIENT_ID=your-cognito-client-id
APPSYNC_COGNITO_CLIENT_SECRET=your-cognito-client-secret
```

3. Optional tuning values from `config/appsync.php`:

```env
# Cache / token
APPSYNC_CACHE_STORE=redis
APPSYNC_CACHE_PREFIX=appsync_broadcast_
APPSYNC_TOKEN_BUFFER=120

# HTTP transport
APPSYNC_HTTP_TIMEOUT=10
APPSYNC_HTTP_CONNECT_TIMEOUT=5
APPSYNC_RETRY_MAX_ATTEMPTS=3
APPSYNC_RETRY_BASE_DELAY=200
APPSYNC_RETRY_MAX_DELAY=5000
APPSYNC_RETRY_MULTIPLIER=2.0

# Redis mode
APPSYNC_REDIS_CONNECTION=default
APPSYNC_REDIS_CHANNEL=appsync:events
APPSYNC_REDIS_BATCH_SIZE=50
APPSYNC_REDIS_FLUSH_INTERVAL=100

# WebSocket transport
APPSYNC_WS_BATCH_SIZE=5
APPSYNC_WS_FLUSH_INTERVAL=50
APPSYNC_WS_MAX_BUFFER=10000
APPSYNC_WS_KA_TIMEOUT_MULTIPLIER=1.5
APPSYNC_WS_STATS_INTERVAL=30
```

> **Note**: The AppSync connection is registered automatically. You do not need to manually add it to `config/broadcasting.php`.

### Modes

| Mode | Description | Worker required |
| --- | --- | --- |
| `direct` | Laravel publishes straight to AppSync using the configured transport | No |
| `redis` | Laravel publishes to Redis, and a worker relays the events to AppSync | Yes |

### Transports

| Transport | Description | Best for |
| --- | --- | --- |
| `http` | Sends one or more events using HTTP requests to AppSync | Simple/default setup |
| `websocket` | Uses a persistent WebSocket connection with batching | Lower overhead / higher throughput |

### Supported combinations

| `APPSYNC_MODE` | `APPSYNC_TRANSPORT` | Behavior |
| --- | --- | --- |
| `direct` | `http` | Laravel sends events directly over HTTP |
| `direct` | `websocket` | Laravel sends events directly over WebSocket |
| `redis` | `http` | Laravel publishes to Redis, worker flushes to AppSync over HTTP |
| `redis` | `websocket` | Laravel publishes to Redis, worker relays over persistent WebSocket |

## Worker Usage (`redis` mode)

If you choose `APPSYNC_MODE=redis`, you must run the worker process:

```bash
php artisan appsync:worker
```

Common overrides:

```bash
php artisan appsync:worker --transport=websocket
php artisan appsync:worker --batch-size=100 --flush-interval=200
php artisan appsync:worker --redis-channel=appsync:events --stats-interval=30
```

> **Production note**: Run the worker under **Supervisor** or **systemd** in production.

## Usage

### Basic Broadcasting

Use Laravel's standard broadcasting flow:

```php
class OrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('orders/' . $this->order->id);
    }
}

event(new OrderUpdated($order));
```

### Presence Channels

For presence channels, use Laravel's `PresenceChannel` as usual:

```php
public function broadcastOn()
{
    return new PresenceChannel('chat/room/' . $this->roomId);
}
```

### How publishing works

- **`direct` mode**: your Laravel request publishes to AppSync immediately.
- **`redis` mode**: your Laravel request only pushes the event to Redis, and the worker handles delivery.
- **`http` transport**: uses HTTP requests with retry and exponential backoff.
- **`websocket` transport**: keeps a live connection open and batches events efficiently.

## Channel Authentication

For private and presence channels, define authorization logic in `routes/channels.php`:

```php
// Private channel authorization
Broadcast::channel('orders/{orderID}', function ($user, $orderID) {
    return (int) $user->id === (int) Order::find($orderID)->user_id;
});

// Presence channel authorization
Broadcast::channel('chat/room/{roomId}', function ($user, $roomId) {
    if ($user->canAccessRoom($roomId)) {
        return ['id' => $user->id, 'name' => $user->name];
    }
});
```

## Frontend Integration

To subscribe to channels from your frontend application, you need to establish a WebSocket connection to AWS AppSync. Here's how to do it:

### JavaScript/Node.js Example

```javascript
const WebSocket = require("ws");
const axios = require("axios");

// Your AppSync configuration
const API_ID = "your-appsync-app-id";
const REGION = "your-region"; // e.g., "us-east-1"
const TOKEN = 'your-user-token'; // JWT token for authenticated users
const CHANNEL = "default/private-orders/123"; // Channel to subscribe to

// AppSync endpoints
const AUTH_URL = 'https://your-app.com/broadcasting/auth';
const REALTIME_DOMAIN = `${API_ID}.appsync-realtime-api.${REGION}.amazonaws.com`;
const HTTP_DOMAIN = `${API_ID}.appsync-api.${REGION}.amazonaws.com`;

async function connectAppSync() {
    try {
        // Step 1: Authenticate with your Laravel app to get AppSync API key
        const res = await axios.post(AUTH_URL, {
            channel_name: CHANNEL,
        }, {
            headers: { Authorization: `Bearer ${TOKEN}` }
        });
        const API_KEY = res.data.auth;
        console.log("API_KEY obtained:", API_KEY);

        // Step 2: Connect to AppSync WebSocket
        const headerObj = {
            host: HTTP_DOMAIN,
            Authorization: API_KEY
        };
        
        // Base64 encode headers (URL safe)
        const header = Buffer.from(JSON.stringify(headerObj))
            .toString("base64")
            .replace(/\+/g, "-")
            .replace(/\//g, "_")
            .replace(/=+$/, "");
            
        const url = `wss://${REALTIME_DOMAIN}/event/realtime`;
        const ws = new WebSocket(url, [`header-${header}`, "aws-appsync-event-ws"]);
        
        // Step 3: Handle connection
        ws.on("open", () => {
            console.log("✅ Connected to AppSync Realtime");
            ws.send(JSON.stringify({ type: "connection_init" }));
        });

        // Step 4: Handle messages
        ws.on("message", (msg) => {
            const data = JSON.parse(msg.toString());
            console.log("📩 Message:", data);

            // Handle connection acknowledgement
            if (data.type === "connection_ack") {
                console.log("✅ Connection ACK received, subscribing to channel");

                // Subscribe to the channel
                ws.send(JSON.stringify({
                    id: crypto.randomUUID(), // Generate unique subscription ID
                    type: "subscribe",
                    channel: CHANNEL,
                    authorization: {
                        "Authorization": API_KEY,
                        "host": HTTP_DOMAIN
                    }
                }));
            }

            // Handle incoming data
            if (data.type === "data") {
                const event = JSON.parse(data.event);
                console.log("📦 Event received:", event);
                
                // Handle your event data
                switch (event.event) {
                    case 'OrderUpdated':
                        console.log('Order updated:', event.data);
                        break;
                    case 'UserMessage':
                        console.log('New message:', event.data);
                        break;
                    // Add more event handlers as needed
                }
            }
        });

        // Handle disconnection
        ws.on("close", () => console.log("❌ Disconnected"));
        ws.on("error", (err) => console.error("⚠️ Error:", err));

    } catch (err) {
        console.error("Error connecting to AppSync:", err.message);
    }
}

// Start the connection
connectAppSync();
```

### Browser Example (ES6+)

```javascript
class AppSyncSubscriber {
    constructor(config) {
        this.apiId = config.apiId;
        this.region = config.region;
        this.authUrl = config.authUrl;
        this.token = config.token;
        this.ws = null;
        this.subscriptions = new Map();
    }

    async connect() {
        const realtimeDomain = `${this.apiId}.appsync-realtime-api.${this.region}.amazonaws.com`;
        const httpDomain = `${this.apiId}.appsync-api.${this.region}.amazonaws.com`;

        try {
            // Get API key from Laravel
            const response = await fetch(this.authUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.token}`
                },
                body: JSON.stringify({ channel_name: 'default' })
            });
            
            const { auth: apiKey } = await response.json();

            // Create WebSocket connection
            const headerObj = { host: httpDomain, Authorization: apiKey };
            const header = btoa(JSON.stringify(headerObj))
                .replace(/\+/g, "-")
                .replace(/\//g, "_")
                .replace(/=+$/, "");

            this.ws = new WebSocket(
                `wss://${realtimeDomain}/event/realtime`,
                [`header-${header}`, "aws-appsync-event-ws"]
            );

            this.ws.onopen = () => {
                console.log("Connected to AppSync");
                this.ws.send(JSON.stringify({ type: "connection_init" }));
            };

            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleMessage(data, apiKey, httpDomain);
            };

            this.ws.onclose = () => console.log("Disconnected");
            this.ws.onerror = (err) => console.error("WebSocket error:", err);

        } catch (error) {
            console.error("Connection failed:", error);
        }
    }

    handleMessage(data, apiKey, httpDomain) {
        if (data.type === "connection_ack") {
            this.onReady && this.onReady();
        } else if (data.type === "data") {
            const event = JSON.parse(data.event);
            this.onEvent && this.onEvent(event);
        }
    }

    subscribe(channel, callback) {
        const id = crypto.randomUUID();
        this.subscriptions.set(id, callback);

        this.ws.send(JSON.stringify({
            id,
            type: "subscribe",
            channel,
            authorization: {
                "Authorization": this.apiKey,
                "host": this.httpDomain
            }
        }));

        return id; // Return subscription ID for unsubscribing
    }

    unsubscribe(subscriptionId) {
        this.ws.send(JSON.stringify({
            id: subscriptionId,
            type: "unsubscribe"
        }));
        this.subscriptions.delete(subscriptionId);
    }
}

// Usage
const subscriber = new AppSyncSubscriber({
    apiId: 'your-appsync-app-id',
    region: 'us-east-1',
    authUrl: '/broadcasting/auth',
    token: 'your-jwt-token'
});

subscriber.onReady = () => {
    console.log("Ready to subscribe!");
    
    // Subscribe to channels
    subscriber.subscribe('default/private-orders/123', (event) => {
        console.log('Order event:', event);
    });
};

subscriber.onEvent = (event) => {
    console.log('Received event:', event);
};

subscriber.connect();
```

### Channel Name Format

Channels follow this format: `{namespace}/{channel_type}-{channel_name}`

- **namespace**: From your `APPSYNC_NAMESPACE` config (default: "default")
- **channel_type**: 
  - `public` for public channels
  - `private` for private channels  
  - `presence` for presence channels
- **channel_name**: Your channel identifier

Examples:
- `default/orders` (public channel)
- `default/private-user/123` (private channel)
- `default/presence-chat/room1` (presence channel)

### Authentication Flow

1. **Frontend** sends channel name to your Laravel app's `/broadcasting/auth` endpoint
2. **Laravel** validates the request and returns an AppSync API key
3. **Frontend** uses the API key to establish WebSocket connection
4. **Frontend** subscribes to specific channels using the authenticated connection

### Event Structure

Events received from AppSync have this structure:

```javascript
{
  event: "EventName",           // The broadcast event name
  channel: "default/orders",    // Channel name
  data: {                       // Your event data
    order_id: 123,
    status: "completed"
  }
}
```

## AWS AppSync Setup

1. Create an AppSync Event API in your AWS Console
2. Set up Cognito User Pool for authentication
3. Create a Cognito App Client with client credentials flow enabled
4. Attach Cognito to AppSync as auth method

## Error Handling

The broadcaster includes built-in protections for both direct and worker-based delivery:

- **HTTP retry logic** with exponential backoff and jitter
- **Token refresh handling** for expired or invalid auth tokens
- **Partial failure handling** so one failed channel does not stop the rest
- **WebSocket reconnect/buffering support** for long-lived connections
- **Worker buffer limits and stats logging** in `redis` mode

## Logging

Important lifecycle and failure details are logged through Laravel's logging system, including:

- Authentication failures
- HTTP retry attempts
- WebSocket disconnects/reconnects
- Redis worker publish/drop statistics
- Configuration and transport errors

## Requirements

- PHP ^8.0
- Laravel ^9.0|^10.0|^11.0
- AWS AppSync API
- AWS Cognito User Pool
- Redis server (only when using `APPSYNC_MODE=redis`)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email the package maintainer instead of using the issue tracker.
