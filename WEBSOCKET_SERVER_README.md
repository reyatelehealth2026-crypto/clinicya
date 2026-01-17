# WebSocket Server for Inbox v2

Real-time messaging server for LINE Telepharmacy Platform using Socket.IO, Redis pub/sub, and MySQL.

## Features

- ✅ Real-time message delivery via WebSocket
- ✅ Redis pub/sub integration for PHP-to-WebSocket communication
- ✅ Authentication with session tokens
- ✅ Room-based broadcasting by LINE account
- ✅ Typing indicators
- ✅ Sync endpoint for missed messages
- ✅ Graceful shutdown handling
- ✅ Health check endpoints
- ✅ Automatic reconnection with exponential backoff

## Requirements

- Node.js >= 14.0.0
- Redis server
- MySQL database
- PM2 (recommended for production)

## Installation

### 1. Install Dependencies

```bash
npm install
```

### 2. Configure Environment

Copy `.env.example` to `.env` and configure:

```bash
cp .env.example .env
nano .env
```

Required environment variables:

```env
# Server Settings
NODE_ENV=production
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=0.0.0.0

# CORS Settings
ALLOWED_ORIGINS=https://yourdomain.com

# Database
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=your_password
DB_NAME=telepharmacy

# Redis
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=
```

### 3. Install Redis (if not already installed)

**Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

**CentOS/RHEL:**
```bash
sudo yum install redis
sudo systemctl enable redis
sudo systemctl start redis
```

**macOS:**
```bash
brew install redis
brew services start redis
```

### 4. Verify Redis is Running

```bash
redis-cli ping
# Should return: PONG
```

## Running the Server

### Development Mode (with auto-reload)

```bash
npm run dev
```

### Production Mode

```bash
npm start
```

### Production with PM2 (Recommended)

PM2 is a production process manager for Node.js applications.

**Install PM2:**
```bash
npm install -g pm2
```

**Start server:**
```bash
npm run pm2:start
```

**Other PM2 commands:**
```bash
npm run pm2:stop      # Stop server
npm run pm2:restart   # Restart server
npm run pm2:logs      # View logs
npm run pm2:monit     # Monitor server
```

**Configure PM2 to start on system boot:**
```bash
pm2 startup
pm2 save
```

## Health Check Endpoints

### GET /health

Returns detailed health information:

```json
{
  "status": "ok",
  "timestamp": 1234567890,
  "uptime": 3600,
  "connections": {
    "total": 5,
    "byAccount": [
      {"accountId": 1, "count": 3},
      {"accountId": 2, "count": 2}
    ]
  },
  "redis": "connected",
  "database": "connected"
}
```

### GET /status

Returns basic status:

```json
{
  "status": "running",
  "version": "1.0.0",
  "timestamp": 1234567890,
  "clients": 5,
  "rooms": 2,
  "typingIndicators": 1
}
```

## Socket.IO Events

### Client → Server

#### `join`
Join a room for a LINE account.

```javascript
socket.emit('join', {
  line_account_id: 123
});
```

#### `typing`
Send typing indicator.

```javascript
socket.emit('typing', {
  user_id: 'U1234567890',
  is_typing: true
});
```

#### `sync`
Request missed messages.

```javascript
socket.emit('sync', {
  last_check: 1234567890000  // Unix timestamp in milliseconds
});
```

#### `ping`
Send heartbeat.

```javascript
socket.emit('ping');
```

### Server → Client

#### `connected`
Connection confirmation.

```javascript
socket.on('connected', (data) => {
  console.log(data);
  // {userId: 1, username: 'admin', lineAccountId: 123, timestamp: 1234567890}
});
```

#### `new_message`
New message received.

```javascript
socket.on('new_message', (message) => {
  console.log(message);
  // {id: 1, user_id: 'U123', content: 'Hello', ...}
});
```

#### `conversation_update`
Conversation metadata updated.

```javascript
socket.on('conversation_update', (data) => {
  console.log(data);
  // {user_id: 'U123', last_message_at: '2024-01-01 12:00:00', unread_count: 5}
});
```

#### `typing`
Typing indicator from another admin.

```javascript
socket.on('typing', (data) => {
  console.log(data);
  // {user_id: 'U123', is_typing: true, admin_id: 2, admin_username: 'admin2'}
});
```

#### `sync_response`
Response to sync request.

```javascript
socket.on('sync_response', (data) => {
  console.log(data);
  // {new_messages: [...], timestamp: 1234567890}
});
```

#### `pong`
Response to ping.

```javascript
socket.on('pong', (data) => {
  console.log(data);
  // {timestamp: 1234567890}
});
```

#### `server_shutdown`
Server is shutting down.

```javascript
socket.on('server_shutdown', (data) => {
  console.log(data);
  // {message: 'Server is shutting down', timestamp: 1234567890}
});
```

#### `error`
Error occurred.

```javascript
socket.on('error', (error) => {
  console.error(error);
  // {message: 'Error description'}
});
```

## PHP Integration

### Publishing Messages to WebSocket

Use the `WebSocketNotifier` class (to be created in task 10.1):

```php
require_once 'classes/WebSocketNotifier.php';

$notifier = new WebSocketNotifier();
$notifier->notifyNewMessage([
    'id' => 123,
    'user_id' => 'U1234567890',
    'content' => 'Hello from PHP',
    'direction' => 'incoming',
    'type' => 'text',
    'created_at' => date('Y-m-d H:i:s')
], $lineAccountId);
```

This publishes to Redis channel `inbox_updates`, which the WebSocket server subscribes to.

## Frontend Integration

### Connecting to WebSocket

```javascript
// Import Socket.IO client
<script src="https://cdn.socket.io/4.6.1/socket.io.min.js"></script>

// Connect with authentication
const socket = io('http://localhost:3000', {
  auth: {
    token: 'your_session_token_here'
  },
  transports: ['websocket', 'polling']
});

// Handle connection
socket.on('connected', (data) => {
  console.log('Connected:', data);
  
  // Join room
  socket.emit('join', {
    line_account_id: data.lineAccountId
  });
});

// Listen for new messages
socket.on('new_message', (message) => {
  console.log('New message:', message);
  // Update UI
});

// Listen for conversation updates
socket.on('conversation_update', (data) => {
  console.log('Conversation updated:', data);
  // Bump conversation to top
});

// Send typing indicator
function sendTypingIndicator(userId, isTyping) {
  socket.emit('typing', {
    user_id: userId,
    is_typing: isTyping
  });
}

// Sync missed messages
function syncMessages(lastCheck) {
  socket.emit('sync', {
    last_check: lastCheck
  });
}

socket.on('sync_response', (data) => {
  console.log('Synced messages:', data.new_messages);
  // Process missed messages
});
```

## Troubleshooting

### Connection Issues

**Problem:** Cannot connect to WebSocket server

**Solutions:**
1. Check if server is running: `pm2 status` or `ps aux | grep node`
2. Check firewall: `sudo ufw allow 3000/tcp`
3. Check CORS settings in `.env`
4. Check browser console for errors

### Redis Connection Issues

**Problem:** Redis connection refused

**Solutions:**
1. Check if Redis is running: `redis-cli ping`
2. Start Redis: `sudo systemctl start redis-server`
3. Check Redis configuration: `/etc/redis/redis.conf`
4. Check Redis password in `.env`

### Database Connection Issues

**Problem:** Cannot connect to MySQL

**Solutions:**
1. Check database credentials in `.env`
2. Check if MySQL is running: `sudo systemctl status mysql`
3. Test connection: `mysql -u root -p`
4. Check database exists: `SHOW DATABASES;`

### Authentication Issues

**Problem:** Authentication failed

**Solutions:**
1. Check session token is valid
2. Check `admin_users` table has `session_token` and `session_expires` columns
3. Verify token is not expired
4. Check database connection

### High Memory Usage

**Problem:** Server using too much memory

**Solutions:**
1. Check number of connections: `curl http://localhost:3000/health`
2. Restart server: `npm run pm2:restart`
3. Check for memory leaks in logs
4. Increase server resources

## Monitoring

### PM2 Monitoring

```bash
# View logs
pm2 logs telepharmacy-ws

# Monitor resources
pm2 monit

# View detailed info
pm2 show telepharmacy-ws
```

### Health Checks

Set up automated health checks:

```bash
# Add to crontab
*/5 * * * * curl -f http://localhost:3000/health || systemctl restart telepharmacy-ws
```

### Log Files

PM2 logs are stored in:
- `~/.pm2/logs/telepharmacy-ws-out.log` - stdout
- `~/.pm2/logs/telepharmacy-ws-error.log` - stderr

## Security Considerations

1. **Use HTTPS in production** - Configure reverse proxy (nginx/Apache)
2. **Restrict CORS origins** - Set specific domains in `ALLOWED_ORIGINS`
3. **Use strong session secrets** - Generate random `SESSION_SECRET`
4. **Enable Redis authentication** - Set `REDIS_PASSWORD`
5. **Firewall rules** - Only allow necessary ports
6. **Keep dependencies updated** - Run `npm audit` regularly

## Performance Tuning

### Node.js

```bash
# Increase memory limit if needed
node --max-old-space-size=4096 websocket-server.js
```

### Redis

Edit `/etc/redis/redis.conf`:

```conf
maxmemory 256mb
maxmemory-policy allkeys-lru
```

### MySQL

Optimize connection pool in `websocket-server.js`:

```javascript
const pool = mysql.createPool({
  connectionLimit: 20,  // Increase for more concurrent connections
  queueLimit: 0
});
```

## Deployment

### Nginx Reverse Proxy

```nginx
upstream websocket {
    server localhost:3000;
}

server {
    listen 443 ssl http2;
    server_name ws.yourdomain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location /socket.io/ {
        proxy_pass http://websocket;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }

    location /health {
        proxy_pass http://websocket;
    }

    location /status {
        proxy_pass http://websocket;
    }
}
```

### Systemd Service (Alternative to PM2)

Create `/etc/systemd/system/telepharmacy-ws.service`:

```ini
[Unit]
Description=Telepharmacy WebSocket Server
After=network.target redis.service mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/telepharmacy
ExecStart=/usr/bin/node websocket-server.js
Restart=always
RestartSec=10
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=telepharmacy-ws
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable telepharmacy-ws
sudo systemctl start telepharmacy-ws
sudo systemctl status telepharmacy-ws
```

## License

MIT

## Support

For issues or questions, contact the development team.
