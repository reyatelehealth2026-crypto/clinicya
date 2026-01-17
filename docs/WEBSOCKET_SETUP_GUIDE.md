# WebSocket Server Setup Guide

Quick setup guide for the Inbox v2 WebSocket server.

## Prerequisites

Before starting, ensure you have:

- ✅ Node.js 14+ installed
- ✅ Redis server installed and running
- ✅ MySQL database accessible
- ✅ Root or sudo access (for production setup)

## Quick Start (Development)

### 1. Install Node.js Dependencies

```bash
cd /path/to/telepharmacy
npm install
```

### 2. Configure Environment

```bash
cp .env.example .env
nano .env
```

Update these values:

```env
NODE_ENV=development
WEBSOCKET_PORT=3000
ALLOWED_ORIGINS=http://localhost,http://127.0.0.1
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=your_password
DB_NAME=telepharmacy
REDIS_HOST=localhost
REDIS_PORT=6379
```

### 3. Start Redis (if not running)

```bash
# Check if Redis is running
redis-cli ping

# If not running, start it
sudo systemctl start redis-server
```

### 4. Start WebSocket Server

```bash
npm run dev
```

You should see:

```
WebSocket server running on 0.0.0.0:3000
Subscribed to inbox_updates channel
```

### 5. Test Connection

Open browser console and test:

```javascript
const socket = io('http://localhost:3000', {
  auth: { token: 'your_session_token' }
});

socket.on('connected', (data) => {
  console.log('Connected!', data);
});
```

## Production Setup

### 1. Install PM2 Process Manager

```bash
sudo npm install -g pm2
```

### 2. Configure Production Environment

```bash
nano .env
```

Update for production:

```env
NODE_ENV=production
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=0.0.0.0
ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com
```

### 3. Start with PM2

```bash
npm run pm2:start
```

### 4. Configure PM2 to Start on Boot

```bash
pm2 startup
# Follow the instructions shown
pm2 save
```

### 5. Configure Nginx Reverse Proxy

Create `/etc/nginx/sites-available/websocket`:

```nginx
upstream websocket_backend {
    server 127.0.0.1:3000;
}

server {
    listen 443 ssl http2;
    server_name ws.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    location /socket.io/ {
        proxy_pass http://websocket_backend;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
    }

    location /health {
        proxy_pass http://websocket_backend;
        access_log off;
    }
}
```

Enable and reload:

```bash
sudo ln -s /etc/nginx/sites-available/websocket /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 6. Configure Firewall

```bash
# Allow WebSocket port (if accessing directly)
sudo ufw allow 3000/tcp

# Or just allow Nginx
sudo ufw allow 'Nginx Full'
```

### 7. Set Up Health Check Monitoring

Add to crontab:

```bash
crontab -e
```

Add line:

```cron
*/5 * * * * curl -f http://localhost:3000/health || pm2 restart telepharmacy-ws
```

## Database Setup

The WebSocket server needs the `session_token` and `session_expires` columns in the `admin_users` table.

### Check if columns exist:

```sql
DESCRIBE admin_users;
```

### Add columns if missing:

```sql
ALTER TABLE admin_users 
ADD COLUMN session_token VARCHAR(255) NULL,
ADD COLUMN session_expires DATETIME NULL,
ADD INDEX idx_session_token (session_token);
```

## Redis Setup

### Install Redis (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install redis-server
```

### Configure Redis

Edit `/etc/redis/redis.conf`:

```conf
# Bind to localhost only (more secure)
bind 127.0.0.1

# Set password (recommended)
requirepass your_strong_password_here

# Set memory limit
maxmemory 256mb
maxmemory-policy allkeys-lru
```

Restart Redis:

```bash
sudo systemctl restart redis-server
sudo systemctl enable redis-server
```

### Test Redis

```bash
redis-cli
> AUTH your_password
> PING
PONG
> exit
```

Update `.env` with password:

```env
REDIS_PASSWORD=your_strong_password_here
```

## Verification Checklist

After setup, verify everything works:

- [ ] WebSocket server is running: `pm2 status`
- [ ] Redis is running: `redis-cli ping`
- [ ] Health check works: `curl http://localhost:3000/health`
- [ ] Can connect from browser console
- [ ] Messages are received in real-time
- [ ] Typing indicators work
- [ ] Server restarts automatically on crash
- [ ] Server starts on system boot

## Common Issues

### Issue: "Redis connection refused"

**Solution:**
```bash
sudo systemctl start redis-server
sudo systemctl status redis-server
```

### Issue: "Authentication failed"

**Solution:**
- Check session token is valid
- Verify `admin_users` table has required columns
- Check database connection in `.env`

### Issue: "CORS error in browser"

**Solution:**
- Add your domain to `ALLOWED_ORIGINS` in `.env`
- Restart server: `pm2 restart telepharmacy-ws`

### Issue: "Port 3000 already in use"

**Solution:**
```bash
# Find process using port 3000
sudo lsof -i :3000

# Kill the process
sudo kill -9 <PID>

# Or change port in .env
WEBSOCKET_PORT=3001
```

### Issue: "High memory usage"

**Solution:**
```bash
# Restart server
pm2 restart telepharmacy-ws

# Check logs for issues
pm2 logs telepharmacy-ws

# Monitor resources
pm2 monit
```

## Monitoring Commands

```bash
# View server status
pm2 status

# View logs (live)
pm2 logs telepharmacy-ws

# View logs (last 100 lines)
pm2 logs telepharmacy-ws --lines 100

# Monitor resources
pm2 monit

# View detailed info
pm2 show telepharmacy-ws

# Check health endpoint
curl http://localhost:3000/health | jq

# Check status endpoint
curl http://localhost:3000/status | jq
```

## Updating the Server

```bash
# Pull latest code
git pull

# Install any new dependencies
npm install

# Restart server
pm2 restart telepharmacy-ws

# Check logs for errors
pm2 logs telepharmacy-ws --lines 50
```

## Backup and Recovery

### Backup Configuration

```bash
# Backup .env file
cp .env .env.backup

# Backup PM2 configuration
pm2 save
```

### Recovery

```bash
# Restore from backup
cp .env.backup .env

# Restart server
pm2 restart telepharmacy-ws
```

## Performance Tuning

### For High Traffic

Edit `websocket-server.js` and increase connection limits:

```javascript
const pool = mysql.createPool({
  connectionLimit: 50,  // Increase from 10
  queueLimit: 0
});
```

### For Low Memory Servers

Reduce Redis memory:

```conf
# In /etc/redis/redis.conf
maxmemory 128mb
```

Restart Redis:

```bash
sudo systemctl restart redis-server
```

## Security Hardening

1. **Use HTTPS only** - Configure SSL in Nginx
2. **Restrict CORS** - Set specific domains in `ALLOWED_ORIGINS`
3. **Enable Redis password** - Set `requirepass` in Redis config
4. **Use firewall** - Only allow necessary ports
5. **Regular updates** - Keep Node.js and dependencies updated
6. **Monitor logs** - Check for suspicious activity

## Next Steps

After WebSocket server is running:

1. ✅ Implement PHP integration (Task 10.1-10.2)
2. ✅ Implement frontend RealtimeManager (Task 11.1-11.8)
3. ✅ Test real-time messaging end-to-end
4. ✅ Monitor performance and optimize

## Support

For issues or questions:
- Check logs: `pm2 logs telepharmacy-ws`
- Check health: `curl http://localhost:3000/health`
- Review README: `WEBSOCKET_SERVER_README.md`
