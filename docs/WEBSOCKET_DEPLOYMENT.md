# WebSocket Server Deployment Guide

Complete deployment guide for the Inbox v2 WebSocket server in production environments.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Running as a Service](#running-as-a-service)
5. [Reverse Proxy Setup](#reverse-proxy-setup)
6. [SSL/TLS Configuration](#ssltls-configuration)
7. [Monitoring & Logging](#monitoring--logging)
8. [Troubleshooting](#troubleshooting)

## Prerequisites

### System Requirements

- **Operating System**: Linux (Ubuntu 20.04+, CentOS 8+, or Debian 10+)
- **Node.js**: Version 14.0.0 or higher
- **NPM**: Version 6.0.0 or higher
- **Redis**: Version 5.0 or higher
- **MySQL/MariaDB**: Version 5.7+ / 10.2+
- **RAM**: Minimum 512MB, recommended 2GB+
- **CPU**: Minimum 1 core, recommended 2+ cores
- **Disk Space**: Minimum 1GB free space

### Network Requirements

- Port 3000 (or custom port) available for WebSocket server
- Port 6379 for Redis (localhost only)
- Port 3306 for MySQL (localhost or remote)
- Outbound HTTPS access for npm packages

### User Permissions

- Root or sudo access for initial setup
- Dedicated user account for running the service (recommended)

## Installation

### Step 1: Install Node.js

#### Ubuntu/Debian

```bash
# Install Node.js 18.x LTS
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# Verify installation
node --version  # Should show v18.x.x
npm --version   # Should show 9.x.x or higher
```

#### CentOS/RHEL

```bash
# Install Node.js 18.x LTS
curl -fsSL https://rpm.nodesource.com/setup_18.x | sudo bash -
sudo yum install -y nodejs

# Verify installation
node --version
npm --version
```

#### Alternative: Using NVM (Node Version Manager)

```bash
# Install NVM
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash

# Reload shell
source ~/.bashrc

# Install Node.js
nvm install 18
nvm use 18
nvm alias default 18
```

### Step 2: Install Project Dependencies

```bash
# Navigate to project directory
cd /var/www/telepharmacy

# Install dependencies
npm install --production

# Verify installation
npm list --depth=0
```

Expected dependencies:
- express: ^4.18.2
- socket.io: ^4.6.1
- mysql2: ^3.6.5
- redis: ^3.1.2
- dotenv: ^16.3.1

### Step 3: Install PM2 Process Manager

PM2 is a production-grade process manager for Node.js applications.

```bash
# Install PM2 globally
sudo npm install -g pm2

# Verify installation
pm2 --version

# Update PM2 (if already installed)
sudo npm update -g pm2
```

## Configuration

### Step 1: Create Environment File

```bash
# Copy example environment file
cp .env.example .env

# Edit configuration
nano .env
```

### Step 2: Configure Environment Variables

```env
# ============================================
# Server Settings
# ============================================
NODE_ENV=production
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=0.0.0.0

# ============================================
# CORS Settings
# ============================================
# Comma-separated list of allowed origins
# Use your actual domain(s) in production
ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com

# ============================================
# Database Configuration
# ============================================
DB_HOST=localhost
DB_USER=telepharmacy_user
DB_PASSWORD=your_secure_database_password
DB_NAME=telepharmacy

# ============================================
# Redis Configuration
# ============================================
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_secure_redis_password

# ============================================
# Session Configuration
# ============================================
SESSION_SECRET=your_random_session_secret_here
```

### Step 3: Generate Secure Secrets

```bash
# Generate random session secret
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"

# Generate Redis password
openssl rand -base64 32
```

### Step 4: Set File Permissions

```bash
# Secure the .env file
chmod 600 .env
chown www-data:www-data .env

# Set proper ownership for project files
sudo chown -R www-data:www-data /var/www/telepharmacy
```

## Running as a Service

### Option 1: PM2 (Recommended)

PM2 provides automatic restarts, log management, and monitoring.

#### Start the Server

```bash
# Start server with PM2
pm2 start websocket-server.js --name telepharmacy-ws

# Or use npm script
npm run pm2:start
```

#### Configure PM2 Startup

```bash
# Generate startup script
pm2 startup systemd

# This will output a command like:
# sudo env PATH=$PATH:/usr/bin pm2 startup systemd -u www-data --hp /home/www-data

# Run the generated command
sudo env PATH=$PATH:/usr/bin pm2 startup systemd -u www-data --hp /home/www-data

# Save PM2 process list
pm2 save
```

#### PM2 Management Commands

```bash
# View status
pm2 status

# View logs (live)
pm2 logs telepharmacy-ws

# View logs (last 100 lines)
pm2 logs telepharmacy-ws --lines 100

# Restart server
pm2 restart telepharmacy-ws

# Stop server
pm2 stop telepharmacy-ws

# Delete from PM2
pm2 delete telepharmacy-ws

# Monitor resources
pm2 monit

# Show detailed info
pm2 show telepharmacy-ws
```

#### PM2 Configuration File (Optional)

Create `ecosystem.config.js`:

```javascript
module.exports = {
  apps: [{
    name: 'telepharmacy-ws',
    script: './websocket-server.js',
    instances: 1,
    exec_mode: 'fork',
    env: {
      NODE_ENV: 'production'
    },
    error_file: './logs/pm2-error.log',
    out_file: './logs/pm2-out.log',
    log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    merge_logs: true,
    max_memory_restart: '500M',
    autorestart: true,
    max_restarts: 10,
    min_uptime: '10s',
    listen_timeout: 3000,
    kill_timeout: 5000
  }]
};
```

Start with config:

```bash
pm2 start ecosystem.config.js
pm2 save
```

### Option 2: Systemd Service

Alternative to PM2 using native systemd.

#### Create Service File

```bash
sudo nano /etc/systemd/system/telepharmacy-ws.service
```

```ini
[Unit]
Description=Telepharmacy WebSocket Server
Documentation=https://github.com/yourorg/telepharmacy
After=network.target redis.service mysql.service
Wants=redis.service mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/telepharmacy
ExecStart=/usr/bin/node websocket-server.js
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=telepharmacy-ws
Environment=NODE_ENV=production

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/telepharmacy/logs

# Resource limits
LimitNOFILE=65536
LimitNPROC=4096

[Install]
WantedBy=multi-user.target
```

#### Enable and Start Service

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable service (start on boot)
sudo systemctl enable telepharmacy-ws

# Start service
sudo systemctl start telepharmacy-ws

# Check status
sudo systemctl status telepharmacy-ws

# View logs
sudo journalctl -u telepharmacy-ws -f
```

#### Systemd Management Commands

```bash
# Start service
sudo systemctl start telepharmacy-ws

# Stop service
sudo systemctl stop telepharmacy-ws

# Restart service
sudo systemctl restart telepharmacy-ws

# Reload configuration
sudo systemctl reload telepharmacy-ws

# View status
sudo systemctl status telepharmacy-ws

# View logs (live)
sudo journalctl -u telepharmacy-ws -f

# View logs (last 100 lines)
sudo journalctl -u telepharmacy-ws -n 100

# View logs (since boot)
sudo journalctl -u telepharmacy-ws -b
```

## Reverse Proxy Setup

### Nginx Configuration

#### Install Nginx

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install nginx

# CentOS/RHEL
sudo yum install nginx

# Start and enable Nginx
sudo systemctl start nginx
sudo systemctl enable nginx
```

#### Create WebSocket Configuration

```bash
sudo nano /etc/nginx/sites-available/websocket
```

```nginx
# Upstream backend
upstream websocket_backend {
    server 127.0.0.1:3000 fail_timeout=0;
    keepalive 32;
}

# HTTP server (redirect to HTTPS)
server {
    listen 80;
    listen [::]:80;
    server_name ws.yourdomain.com;

    # Redirect all HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

# HTTPS server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ws.yourdomain.com;

    # SSL certificates (will be configured in next section)
    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    # SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # WebSocket endpoint
    location /socket.io/ {
        proxy_pass http://websocket_backend;
        proxy_http_version 1.1;
        
        # WebSocket headers
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        
        # Standard proxy headers
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        
        # Timeouts
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 86400s;  # 24 hours for long-lived connections
        
        # Buffering
        proxy_buffering off;
        proxy_cache off;
        
        # Keep-alive
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }

    # Health check endpoint
    location /health {
        proxy_pass http://websocket_backend;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        access_log off;
    }

    # Status endpoint
    location /status {
        proxy_pass http://websocket_backend;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        access_log off;
    }

    # Access and error logs
    access_log /var/log/nginx/websocket-access.log;
    error_log /var/log/nginx/websocket-error.log;
}
```

#### Enable Configuration

```bash
# Create symbolic link
sudo ln -s /etc/nginx/sites-available/websocket /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

### Apache Configuration (Alternative)

```bash
sudo nano /etc/apache2/sites-available/websocket.conf
```

```apache
<VirtualHost *:443>
    ServerName ws.yourdomain.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem

    # Enable required modules
    # a2enmod proxy proxy_http proxy_wstunnel rewrite ssl

    ProxyPreserveHost On
    ProxyRequests Off

    # WebSocket proxy
    ProxyPass /socket.io/ ws://localhost:3000/socket.io/
    ProxyPassReverse /socket.io/ ws://localhost:3000/socket.io/

    # Health check
    ProxyPass /health http://localhost:3000/health
    ProxyPassReverse /health http://localhost:3000/health

    # Logs
    ErrorLog ${APACHE_LOG_DIR}/websocket-error.log
    CustomLog ${APACHE_LOG_DIR}/websocket-access.log combined
</VirtualHost>
```

Enable and restart:

```bash
sudo a2ensite websocket
sudo systemctl restart apache2
```

## SSL/TLS Configuration

### Option 1: Let's Encrypt (Free, Recommended)

#### Install Certbot

```bash
# Ubuntu/Debian
sudo apt install certbot python3-certbot-nginx

# CentOS/RHEL
sudo yum install certbot python3-certbot-nginx
```

#### Obtain Certificate

```bash
# For Nginx
sudo certbot --nginx -d ws.yourdomain.com

# For Apache
sudo certbot --apache -d ws.yourdomain.com

# Or standalone (if no web server running)
sudo certbot certonly --standalone -d ws.yourdomain.com
```

#### Auto-renewal

```bash
# Test renewal
sudo certbot renew --dry-run

# Certbot automatically sets up a cron job or systemd timer
# Verify it's enabled:
sudo systemctl status certbot.timer
```

### Option 2: Custom SSL Certificate

If you have your own SSL certificate:

```bash
# Copy certificate files
sudo cp your-cert.crt /etc/ssl/certs/websocket.crt
sudo cp your-key.key /etc/ssl/private/websocket.key
sudo cp ca-bundle.crt /etc/ssl/certs/websocket-ca.crt

# Set permissions
sudo chmod 644 /etc/ssl/certs/websocket.crt
sudo chmod 600 /etc/ssl/private/websocket.key
sudo chmod 644 /etc/ssl/certs/websocket-ca.crt
```

Update Nginx configuration:

```nginx
ssl_certificate /etc/ssl/certs/websocket.crt;
ssl_certificate_key /etc/ssl/private/websocket.key;
ssl_trusted_certificate /etc/ssl/certs/websocket-ca.crt;
```

## Monitoring & Logging

### Health Check Monitoring

#### Create Health Check Script

```bash
sudo nano /usr/local/bin/check-websocket-health.sh
```

```bash
#!/bin/bash

# Health check script for WebSocket server
HEALTH_URL="http://localhost:3000/health"
LOG_FILE="/var/log/websocket-health.log"
MAX_RETRIES=3

check_health() {
    response=$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_URL" --max-time 5)
    
    if [ "$response" = "200" ]; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') - Health check passed" >> "$LOG_FILE"
        return 0
    else
        echo "$(date '+%Y-%m-%d %H:%M:%S') - Health check failed (HTTP $response)" >> "$LOG_FILE"
        return 1
    fi
}

# Try health check with retries
for i in $(seq 1 $MAX_RETRIES); do
    if check_health; then
        exit 0
    fi
    
    if [ $i -lt $MAX_RETRIES ]; then
        echo "$(date '+%Y-%m-%d %H:%M:%S') - Retry $i/$MAX_RETRIES" >> "$LOG_FILE"
        sleep 5
    fi
done

# All retries failed, restart service
echo "$(date '+%Y-%m-%d %H:%M:%S') - All health checks failed, restarting service" >> "$LOG_FILE"
pm2 restart telepharmacy-ws
# Or for systemd: systemctl restart telepharmacy-ws

exit 1
```

```bash
# Make executable
sudo chmod +x /usr/local/bin/check-websocket-health.sh

# Test script
sudo /usr/local/bin/check-websocket-health.sh
```

#### Add to Crontab

```bash
# Edit crontab
crontab -e

# Add health check every 5 minutes
*/5 * * * * /usr/local/bin/check-websocket-health.sh
```

### Log Management

#### Configure Log Rotation

```bash
sudo nano /etc/logrotate.d/websocket
```

```
/var/log/websocket-health.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
}

/var/log/nginx/websocket-*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        [ -f /var/run/nginx.pid ] && kill -USR1 `cat /var/run/nginx.pid`
    endscript
}
```

#### PM2 Log Management

```bash
# View logs
pm2 logs telepharmacy-ws

# Flush logs
pm2 flush

# Rotate logs
pm2 install pm2-logrotate

# Configure log rotation
pm2 set pm2-logrotate:max_size 10M
pm2 set pm2-logrotate:retain 7
pm2 set pm2-logrotate:compress true
```

### Monitoring Tools

#### PM2 Plus (Optional, Paid)

```bash
# Link to PM2 Plus for advanced monitoring
pm2 link <secret_key> <public_key>
```

#### Custom Monitoring Script

```bash
sudo nano /usr/local/bin/monitor-websocket.sh
```

```bash
#!/bin/bash

# Monitor WebSocket server metrics
STATUS_URL="http://localhost:3000/status"
ALERT_EMAIL="admin@yourdomain.com"
ALERT_THRESHOLD_CLIENTS=1000

# Get status
status=$(curl -s "$STATUS_URL")
clients=$(echo "$status" | jq -r '.clients')

# Check thresholds
if [ "$clients" -gt "$ALERT_THRESHOLD_CLIENTS" ]; then
    echo "High client count: $clients" | mail -s "WebSocket Alert" "$ALERT_EMAIL"
fi

# Log metrics
echo "$(date '+%Y-%m-%d %H:%M:%S') - Clients: $clients" >> /var/log/websocket-metrics.log
```

## Troubleshooting

### Common Issues

#### Issue: Server won't start

**Symptoms:**
- PM2 shows "errored" status
- Systemd service fails to start

**Solutions:**

1. Check logs:
```bash
# PM2
pm2 logs telepharmacy-ws --lines 50

# Systemd
sudo journalctl -u telepharmacy-ws -n 50
```

2. Check port availability:
```bash
sudo lsof -i :3000
# If port is in use, kill the process or change port
```

3. Check file permissions:
```bash
ls -la /var/www/telepharmacy
# Ensure www-data owns the files
sudo chown -R www-data:www-data /var/www/telepharmacy
```

4. Check environment file:
```bash
cat .env
# Ensure all required variables are set
```

#### Issue: Cannot connect from browser

**Symptoms:**
- Connection timeout
- CORS errors
- Authentication failed

**Solutions:**

1. Check firewall:
```bash
# Ubuntu/Debian
sudo ufw status
sudo ufw allow 3000/tcp

# CentOS/RHEL
sudo firewall-cmd --list-all
sudo firewall-cmd --add-port=3000/tcp --permanent
sudo firewall-cmd --reload
```

2. Check CORS configuration:
```bash
# Verify ALLOWED_ORIGINS in .env includes your domain
grep ALLOWED_ORIGINS .env
```

3. Test connection:
```bash
# Test health endpoint
curl http://localhost:3000/health

# Test from external
curl https://ws.yourdomain.com/health
```

4. Check Nginx/Apache logs:
```bash
# Nginx
sudo tail -f /var/log/nginx/websocket-error.log

# Apache
sudo tail -f /var/log/apache2/websocket-error.log
```

#### Issue: High memory usage

**Symptoms:**
- Server using > 500MB RAM
- Slow performance
- Out of memory errors

**Solutions:**

1. Check memory usage:
```bash
# PM2
pm2 show telepharmacy-ws

# System
free -h
top -p $(pgrep -f websocket-server)
```

2. Restart server:
```bash
pm2 restart telepharmacy-ws
```

3. Configure memory limit:
```bash
# In ecosystem.config.js
max_memory_restart: '500M'
```

4. Check for memory leaks:
```bash
# Enable Node.js memory profiling
node --inspect websocket-server.js
```

#### Issue: Redis connection failed

**Symptoms:**
- "Redis connection refused" errors
- Real-time updates not working

**Solutions:**

1. Check Redis status:
```bash
sudo systemctl status redis-server
redis-cli ping
```

2. Start Redis:
```bash
sudo systemctl start redis-server
sudo systemctl enable redis-server
```

3. Check Redis configuration:
```bash
# Verify Redis is listening
sudo netstat -tlnp | grep 6379

# Check Redis password
redis-cli
> AUTH your_password
> PING
```

4. Update .env:
```bash
# Ensure Redis credentials are correct
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_password
```

#### Issue: Database connection failed

**Symptoms:**
- "Cannot connect to MySQL" errors
- Authentication errors

**Solutions:**

1. Check MySQL status:
```bash
sudo systemctl status mysql
```

2. Test connection:
```bash
mysql -h localhost -u telepharmacy_user -p telepharmacy
```

3. Verify credentials in .env:
```bash
grep DB_ .env
```

4. Check MySQL user permissions:
```sql
SHOW GRANTS FOR 'telepharmacy_user'@'localhost';
```

### Performance Issues

#### Slow response times

1. Check server load:
```bash
top
htop
pm2 monit
```

2. Check database queries:
```bash
# Enable MySQL slow query log
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf

# Add:
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 1
```

3. Check Redis performance:
```bash
redis-cli --latency
redis-cli --stat
```

4. Optimize Nginx:
```nginx
# In nginx.conf
worker_processes auto;
worker_connections 4096;
```

#### High CPU usage

1. Check process:
```bash
top -p $(pgrep -f websocket-server)
```

2. Profile Node.js:
```bash
node --prof websocket-server.js
# Analyze with: node --prof-process isolate-*.log
```

3. Reduce polling frequency if using fallback
4. Optimize database queries
5. Consider scaling horizontally

### Debugging Tools

```bash
# Check all services
sudo systemctl status telepharmacy-ws redis-server mysql nginx

# Check network connections
sudo netstat -tlnp | grep -E '3000|6379|3306'

# Check disk space
df -h

# Check system logs
sudo journalctl -xe

# Check application logs
pm2 logs telepharmacy-ws --lines 100

# Test WebSocket connection
wscat -c ws://localhost:3000/socket.io/
```

## Next Steps

After successful deployment:

1. ✅ Test WebSocket connection from browser
2. ✅ Verify real-time message delivery
3. ✅ Test typing indicators
4. ✅ Test sync functionality
5. ✅ Monitor performance metrics
6. ✅ Set up automated backups
7. ✅ Configure monitoring alerts
8. ✅ Document any custom configurations

## Additional Resources

- [Socket.IO Documentation](https://socket.io/docs/)
- [PM2 Documentation](https://pm2.keymetrics.io/docs/)
- [Nginx Documentation](https://nginx.org/en/docs/)
- [Redis Documentation](https://redis.io/documentation)
- [Node.js Best Practices](https://github.com/goldbergyoni/nodebestpractices)

## Support

For deployment issues:
1. Check logs first
2. Review this documentation
3. Test each component individually
4. Contact development team if issues persist
