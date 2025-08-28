# Laravel AppSync Broadcaster

A Laravel Broadcasting driver for AWS AppSync that enables real-time communication using AWS AppSync GraphQL subscriptions.

## Features

- **Real-time Broadcasting**: Leverage AWS AppSync for real-time messaging
- **Channel Support**: Support for public, private, and presence channels
- **Authentication**: Cognito-based authentication for private channels
- **Frontend Integration**: WebSocket connection examples for JavaScript/Node.js
- **Retry Logic**: Built-in retry mechanism with exponential backoff
- **Error Handling**: Comprehensive error handling and logging
- **Laravel Integration**: Seamless integration with Laravel's Broadcasting system

## Installation

Install the package via Composer:

```bash
composer require 7amoood/laravel-appsync-broadcaster
```

The service provider will be automatically registered due to Laravel's package auto-discovery.

## Configuration

1. Publish the configuration file:

```bash
php artisan vendor:publish --tag=appsync-config
```

2. Add the following environment variables to your `.env` file:

```env
APPSYNC_NAMESPACE=your-app-namespace-or-remove-it-to-set-default
APPSYNC_APP_ID=your-appsync-app-id
APPSYNC_EVENT_REGION=us-east-1
APPSYNC_COGNITO_POOL=your-cognito-pool-id
APPSYNC_COGNITO_REGION=us-east-1-or-remove-it-for-same
APPSYNC_COGNITO_CLIENT_ID=your-cognito-client-id
APPSYNC_COGNITO_CLIENT_SECRET=your-cognito-client-secret
```

3. Set AppSync as your default broadcast driver in `.env`:

```env
BROADCAST_DRIVER=appsync
```

> **Note**: The AppSync broadcasting connection is automatically registered. You don't need to manually add it to your `config/broadcasting.php` file.

## Usage

### Basic Broadcasting

Use Laravel's standard broadcasting features:

```php
// In your event class
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

// Dispatch the event
event(new OrderUpdated($order));
```

### Presence Channels

For presence channels, use the `presence-` prefix:

```php
public function broadcastOn()
{
    return new PresenceChannel('chat/room/' . $this->roomId);
}
```

### Manual Broadcasting

You can also broadcast manually:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('test-channel')->send('test-event', [
    'message' => 'Hello World!'
]);
```

## Channel Authentication

For private and presence channels, you need to define authorization logic in your `routes/channels.php`:

```php
// Private channel authorization
Broadcast::channel('user/{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
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

The broadcaster includes comprehensive error handling:

- **Connection failures**: Automatic retry with exponential backoff
- **Authentication errors**: Detailed logging and error messages
- **Partial failures**: Continues broadcasting to other channels if some fail
- **Configuration validation**: Validates required configuration parameters

## Logging

All errors and important events are logged using Laravel's logging system. Check your logs for:

- Authentication failures
- Broadcast failures
- Retry attempts
- Configuration errors

## Requirements

- PHP ^8.0
- Laravel ^9.0|^10.0|^11.0
- AWS AppSync API
- AWS Cognito User Pool

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email the package maintainer instead of using the issue tracker.
