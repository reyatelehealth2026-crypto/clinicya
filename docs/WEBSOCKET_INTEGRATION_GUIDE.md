# WebSocket Integration Guide

## Overview

The WebSocket integration enables real-time message updates in the Inbox v2 interface. When new messages arrive via the LINE webhook, they are immediately pushed to connected admin clients through WebSocket, eliminating the need for constant polling.

## Architecture

```
LINE Webhook → webhook.php → WebSocketNotifier → Redis Pub/Sub → WebSocket Server → Connected Clients
```

## Components

### 1. WebSocketNotifier (PHP)
**File**: `classes/WebSocketNotifier.php`

A PHP class that publishes real-time updates to Redis pub/sub channel.

**Key Methods**:
- `notifyNewMessage()` - Notify of incoming/outgoing messages
- `notifyConversationUpdate()` - Notify of conversation changes
- `notifyTypingIndicator()` - Notify when admins are typing

**Usage Example**:
```php
$notifier = new WebSocketNotifier('localhost', 6379);

if ($notifier->isConnected()) {
    $notifier->notifyNewMessage(
        [
            'id' => $messageId,
            'user_id' => $userId,
            'content' => 'Hello',
            'direction' => 'incoming',
            'type' => 'text',
            'created_at' => date('Y-m-d H:i:s')
        ],
        $lineAccountId,
        [
            'display_name' => 'Customer Name',
            'picture_url' => 'https://...'
        ]
    );
}
```

### 2. Webhook Integration
**File**: `webhook.php`

The webhook automatically notifies the WebSocket server when:
- **Incoming messages** arrive from LINE users
- **Outgoing messages** are sent by admins/AI

**Integration Points**:
1. After incoming message is saved (line ~785)
2. In `saveOutgoingMessage()` function (line ~2730)

### 3. WebSocket Server
**File**: `websocket-server.js`

Node.js server that:
- Subscribes to Redis `inbox_updates` channel
- Broadcasts messages to connected admin clients
- Manages Socket.IO connections by LINE account

## Setup Instructions

### Prerequisites

1. **Redis Server**
   ```bash
   # Ubuntu/Debian
   sudo apt-get install redis-server
   sudo systemctl start redis
   sudo systemctl enable redis
   
   # Check if running
   redis-cli ping
   # Should return: PONG
   ```

2. **PHP Redis Extension**
   ```bash
   # Ubuntu/Debian
   sudo apt-get install php-redis
   sudo systemctl restart apache2  # or php-fpm
   
   # Verify installation
   php -m | grep redis
   ```

3. **Node.js** (for WebSocket server)
   ```bash
   # Already installed based on websocket-server.js
   node --version
   npm --version
   ```

### Configuration

1. **Redis Configuration** (Optional)
   
   Edit `/etc/redis/redis.conf` if you need to:
   - Change port (default: 6379)
   - Set password: `requirepass your_password`
   - Bind to specific IP: `bind 127.0.0.1`

2. **WebSocketNotifier Configuration**
   
   The default configuration connects to `localhost:6379` with no password.
   
   To customize, modify the instantiation in `webhook.php`:
   ```php
   $wsNotifier = new WebSocketNotifier(
       'localhost',  // Redis host
       6379,         // Redis port
       'password'    // Redis password (optional)
   );
   ```

3. **Environment Variables** (for WebSocket server)
   
   Create `.env` file in project root:
   ```env
   REDIS_HOST=localhost
   REDIS_PORT=6379
   REDIS_PASSWORD=
   WEBSOCKET_PORT=3000
   DB_HOST=localhost
   DB_USER=your_db_user
   DB_PASSWORD=your_db_password
   DB_NAME=your_db_name
   ALLOWED_ORIGINS=https://yourdomain.com
   ```

### Starting the WebSocket Server

1. **Install Dependencies** (if not already done)
   ```bash
   npm install
   ```

2. **Start the Server**
   ```bash
   # Development
   node websocket-server.js
   
   # Production (with PM2)
   pm2 start websocket-server.js --name "inbox-websocket"
   pm2 save
   pm2 startup
   ```

3. **Verify Server is Running**
   ```bash
   # Check if listening on port 3000
   netstat -tlnp | grep 3000
   
   # Check PM2 status
   pm2 status
   
   # View logs
   pm2 logs inbox-websocket
   ```

## Testing

### 1. Test Redis Connection

```bash
# Test Redis is running
redis-cli ping

# Monitor Redis pub/sub
redis-cli
> SUBSCRIBE inbox_updates
# Keep this terminal open
```

### 2. Test PHP Integration

Create `test-websocket-notifier.php`:
```php
<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/WebSocketNotifier.php';

$notifier = new WebSocketNotifier();

if (!$notifier->isConnected()) {
    die("❌ Redis not connected\n");
}

echo "✅ Redis connected\n";

// Test notification
$result = $notifier->notifyNewMessage(
    [
        'id' => 999,
        'user_id' => 1,
        'content' => 'Test message',
        'direction' => 'incoming',
        'type' => 'text',
        'created_at' => date('Y-m-d H:i:s')
    ],
    1,
    [
        'display_name' => 'Test User',
        'picture_url' => ''
    ]
);

echo $result ? "✅ Message published\n" : "❌ Failed to publish\n";
```

Run:
```bash
php test-websocket-notifier.php
```

You should see the message in the Redis monitor terminal.

### 3. Test WebSocket Server

Open `test-websocket-connection.html` in a browser and check the console for connection status.

### 4. Test End-to-End

1. Start Redis: `sudo systemctl start redis`
2. Start WebSocket server: `node websocket-server.js`
3. Open inbox-v2.php in browser
4. Send a message from LINE to your bot
5. Message should appear instantly in inbox without refresh

## Troubleshooting

### Redis Not Connected

**Symptom**: WebSocketNotifier logs "Redis extension not available" or "Failed to connect to Redis"

**Solutions**:
1. Install PHP Redis extension: `sudo apt-get install php-redis`
2. Restart web server: `sudo systemctl restart apache2`
3. Check Redis is running: `redis-cli ping`
4. Check Redis port: `netstat -tlnp | grep 6379`

### WebSocket Server Not Starting

**Symptom**: `Error: listen EADDRINUSE :::3000`

**Solutions**:
1. Port already in use: `lsof -i :3000`
2. Kill existing process: `kill -9 <PID>`
3. Or change port in `.env`: `WEBSOCKET_PORT=3001`

### Messages Not Appearing in Real-time

**Symptom**: Messages only appear after page refresh

**Solutions**:
1. Check WebSocket server is running: `pm2 status`
2. Check browser console for WebSocket errors
3. Verify Redis pub/sub is working:
   ```bash
   redis-cli
   > SUBSCRIBE inbox_updates
   # Send test message from LINE
   # Should see message in Redis
   ```
4. Check webhook.php logs for WebSocket notification errors

### Redis Authentication Failed

**Symptom**: "NOAUTH Authentication required"

**Solutions**:
1. Add password to WebSocketNotifier:
   ```php
   $wsNotifier = new WebSocketNotifier('localhost', 6379, 'your_password');
   ```
2. Or disable Redis password in `/etc/redis/redis.conf`:
   ```
   # requirepass your_password
   ```
   Then restart: `sudo systemctl restart redis`

## Performance Considerations

### Redis Memory Usage

Monitor Redis memory:
```bash
redis-cli info memory
```

Redis pub/sub doesn't store messages, so memory usage is minimal.

### WebSocket Connections

Each admin viewing inbox-v2.php creates one WebSocket connection.

Monitor connections:
```bash
# Check active connections
pm2 logs inbox-websocket | grep "Client connected"

# Check connection count
netstat -an | grep :3000 | grep ESTABLISHED | wc -l
```

### Graceful Degradation

If Redis or WebSocket server is unavailable:
- ✅ Webhook continues to work normally
- ✅ Messages are saved to database
- ✅ Inbox falls back to polling (every 3 seconds)
- ✅ No errors shown to users

The system is designed to work without WebSocket, just with reduced real-time performance.

## Security Notes

1. **Redis Security**
   - Use password authentication in production
   - Bind to localhost only: `bind 127.0.0.1`
   - Use firewall to block external access to port 6379

2. **WebSocket Security**
   - Use SSL/TLS in production (wss://)
   - Implement authentication tokens
   - Validate CORS origins in `ALLOWED_ORIGINS`

3. **Error Handling**
   - All WebSocket errors are caught and logged
   - Webhook never fails due to WebSocket issues
   - Graceful fallback to polling

## Monitoring

### Health Check Endpoints

Add to your monitoring:
```bash
# Check Redis
redis-cli ping

# Check WebSocket server
curl http://localhost:3000/health

# Check PM2 process
pm2 status inbox-websocket
```

### Logs

```bash
# WebSocket server logs
pm2 logs inbox-websocket

# PHP error logs
tail -f /var/log/apache2/error.log | grep WebSocket

# Redis logs
tail -f /var/log/redis/redis-server.log
```

## Production Deployment

1. **Use PM2 for WebSocket Server**
   ```bash
   pm2 start websocket-server.js --name inbox-websocket
   pm2 startup
   pm2 save
   ```

2. **Enable Redis Persistence** (optional)
   ```
   # /etc/redis/redis.conf
   save 900 1
   save 300 10
   save 60 10000
   ```

3. **Use SSL for WebSocket**
   - Configure nginx/apache as reverse proxy
   - Use wss:// instead of ws://
   - Update ALLOWED_ORIGINS

4. **Monitor Resource Usage**
   ```bash
   pm2 monit
   ```

## Next Steps

After completing this task, the next phase is:
- **Task 11**: Implement RealtimeManager (Frontend)
  - Create `assets/js/realtime-manager.js`
  - Connect to WebSocket server with Socket.IO client
  - Implement polling fallback
  - Handle typing indicators

See `docs/WEBSOCKET_SETUP_GUIDE.md` for frontend integration details.
