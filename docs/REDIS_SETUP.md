# Redis Setup Guide for Inbox v2

Complete guide for installing, configuring, and securing Redis for the WebSocket server pub/sub functionality.

## Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Security Settings](#security-settings)
5. [Performance Tuning](#performance-tuning)
6. [Monitoring](#monitoring)
7. [Backup & Recovery](#backup--recovery)
8. [Troubleshooting](#troubleshooting)

## Overview

### What is Redis?

Redis (Remote Dictionary Server) is an in-memory data structure store used as a database, cache, and message broker. In the Inbox v2 system, Redis serves as a pub/sub message broker between the PHP application and the WebSocket server.

### Why Redis?

- **Fast**: In-memory operations with microsecond latency
- **Reliable**: Proven pub/sub messaging system
- **Simple**: Easy to install and configure
- **Scalable**: Handles thousands of messages per second

### System Requirements

- **RAM**: Minimum 256MB, recommended 512MB+ dedicated to Redis
- **CPU**: 1+ cores
- **Disk**: 100MB+ for persistence (optional)
- **OS**: Linux (Ubuntu, CentOS, Debian), macOS, or Windows (via WSL)

## Installation

### Ubuntu/Debian

#### Method 1: APT Package Manager (Recommended)

```bash
# Update package list
sudo apt update

# Install Redis
sudo apt install redis-server -y

# Verify installation
redis-server --version
```

#### Method 2: From Source (Latest Version)

```bash
# Install build dependencies
sudo apt install build-essential tcl -y

# Download latest stable version
cd /tmp
wget http://download.redis.io/redis-stable.tar.gz

# Extract and build
tar xzf redis-stable.tar.gz
cd redis-stable
make
make test

# Install
sudo make install

# Create directories
sudo mkdir -p /etc/redis
sudo mkdir -p /var/redis
sudo mkdir -p /var/log/redis

# Copy configuration
sudo cp redis.conf /etc/redis/redis.conf
```

### CentOS/RHEL

#### Enable EPEL Repository

```bash
# CentOS 8
sudo dnf install epel-release -y
sudo dnf install redis -y

# CentOS 7
sudo yum install epel-release -y
sudo yum install redis -y

# Verify installation
redis-server --version
```

### macOS

#### Using Homebrew

```bash
# Install Homebrew (if not installed)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install Redis
brew install redis

# Start Redis
brew services start redis

# Verify installation
redis-cli ping
# Should return: PONG
```

### Windows

#### Using WSL (Windows Subsystem for Linux)

```bash
# Enable WSL and install Ubuntu
wsl --install

# Inside WSL, follow Ubuntu installation steps
sudo apt update
sudo apt install redis-server -y
```

#### Using Redis for Windows (Alternative)

Download from: https://github.com/microsoftarchive/redis/releases

Note: Official Redis doesn't support Windows natively. WSL is recommended.

## Configuration

### Basic Configuration

#### Edit Configuration File

```bash
# Ubuntu/Debian
sudo nano /etc/redis/redis.conf

# CentOS/RHEL
sudo nano /etc/redis.conf

# macOS (Homebrew)
nano /usr/local/etc/redis.conf
```

### Essential Settings

#### 1. Network Configuration

```conf
# Bind to localhost only (more secure)
bind 127.0.0.1 ::1

# Or bind to specific IP
# bind 127.0.0.1 192.168.1.100

# Port (default 6379)
port 6379

# Enable protected mode (recommended)
protected-mode yes

# TCP backlog
tcp-backlog 511

# Timeout (0 = never close idle connections)
timeout 0

# TCP keepalive
tcp-keepalive 300
```

#### 2. General Settings

```conf
# Run as daemon (background process)
daemonize yes

# PID file location
pidfile /var/run/redis/redis-server.pid

# Log level: debug, verbose, notice, warning
loglevel notice

# Log file location
logfile /var/log/redis/redis-server.log

# Number of databases
databases 16
```

#### 3. Persistence Settings

For pub/sub only, persistence is optional but recommended for reliability.

```conf
# Save snapshots
# save <seconds> <changes>
save 900 1      # After 900 sec (15 min) if at least 1 key changed
save 300 10     # After 300 sec (5 min) if at least 10 keys changed
save 60 10000   # After 60 sec if at least 10000 keys changed

# Stop writes on save errors
stop-writes-on-bgsave-error yes

# Compress snapshots
rdbcompression yes

# Checksum snapshots
rdbchecksum yes

# Snapshot filename
dbfilename dump.rdb

# Working directory
dir /var/lib/redis
```

#### 4. Memory Management

```conf
# Maximum memory (adjust based on your system)
maxmemory 256mb

# Eviction policy when maxmemory is reached
# Options: noeviction, allkeys-lru, volatile-lru, allkeys-random, volatile-random, volatile-ttl
maxmemory-policy allkeys-lru

# LRU/LFU algorithm samples
maxmemory-samples 5
```

#### 5. Pub/Sub Settings

```conf
# Pub/Sub client output buffer limits
# client-output-buffer-limit pubsub <hard-limit> <soft-limit> <soft-seconds>
client-output-buffer-limit pubsub 32mb 8mb 60
```

### Apply Configuration

```bash
# Test configuration
redis-server /etc/redis/redis.conf --test-memory 1

# Restart Redis to apply changes
sudo systemctl restart redis-server

# Or for macOS
brew services restart redis
```

## Security Settings

### 1. Enable Authentication

#### Set Password

```bash
# Edit configuration
sudo nano /etc/redis/redis.conf
```

```conf
# Require password for all commands
requirepass your_strong_password_here
```

#### Generate Strong Password

```bash
# Generate random password
openssl rand -base64 32

# Or use pwgen
sudo apt install pwgen
pwgen -s 32 1
```

#### Test Authentication

```bash
# Connect without password (should fail)
redis-cli
> PING
(error) NOAUTH Authentication required.

# Connect with password
redis-cli
> AUTH your_password
OK
> PING
PONG
```

### 2. Rename Dangerous Commands

```conf
# Rename or disable dangerous commands
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command KEYS ""
rename-command CONFIG "CONFIG_a8f5f167f44f4964e6c998dee827110c"
rename-command SHUTDOWN "SHUTDOWN_a8f5f167f44f4964e6c998dee827110c"
```

### 3. Network Security

```conf
# Bind to localhost only
bind 127.0.0.1 ::1

# Enable protected mode
protected-mode yes

# Disable remote connections (if not needed)
# bind 127.0.0.1
```

### 4. Firewall Configuration

```bash
# Ubuntu/Debian (UFW)
sudo ufw allow from 127.0.0.1 to any port 6379
sudo ufw deny 6379

# CentOS/RHEL (firewalld)
sudo firewall-cmd --permanent --add-rich-rule='rule family="ipv4" source address="127.0.0.1" port port="6379" protocol="tcp" accept'
sudo firewall-cmd --reload
```

### 5. File Permissions

```bash
# Secure configuration file
sudo chmod 640 /etc/redis/redis.conf
sudo chown redis:redis /etc/redis/redis.conf

# Secure data directory
sudo chmod 750 /var/lib/redis
sudo chown redis:redis /var/lib/redis

# Secure log file
sudo chmod 640 /var/log/redis/redis-server.log
sudo chown redis:redis /var/log/redis/redis-server.log
```

### 6. Run as Non-Root User

```bash
# Create redis user (if not exists)
sudo useradd -r -s /bin/false redis

# Update configuration
sudo nano /etc/redis/redis.conf
```

```conf
# Run as redis user
user redis
```

### 7. Enable TLS/SSL (Optional, Advanced)

For encrypted connections between PHP/Node.js and Redis:

```conf
# TLS/SSL configuration
port 0
tls-port 6379
tls-cert-file /path/to/redis.crt
tls-key-file /path/to/redis.key
tls-ca-cert-file /path/to/ca.crt
tls-auth-clients no
```

## Performance Tuning

### 1. Memory Optimization

```conf
# Set appropriate maxmemory
maxmemory 512mb

# Use LRU eviction
maxmemory-policy allkeys-lru

# Optimize LRU precision
maxmemory-samples 10
```

### 2. Persistence Optimization

For pub/sub workloads, you can disable persistence for better performance:

```conf
# Disable RDB snapshots
save ""

# Disable AOF
appendonly no
```

Or use minimal persistence:

```conf
# Minimal RDB snapshots
save 3600 1

# AOF with everysec fsync
appendonly yes
appendfsync everysec
```

### 3. Network Optimization

```conf
# Increase TCP backlog
tcp-backlog 511

# Enable TCP keepalive
tcp-keepalive 300

# Disable slow log for pub/sub
slowlog-log-slower-than -1
```

### 4. System Tuning

#### Increase File Descriptors

```bash
# Edit limits
sudo nano /etc/security/limits.conf
```

```conf
redis soft nofile 65536
redis hard nofile 65536
```

#### Disable Transparent Huge Pages

```bash
# Temporary
echo never > /sys/kernel/mm/transparent_hugepage/enabled

# Permanent (add to /etc/rc.local)
echo 'echo never > /sys/kernel/mm/transparent_hugepage/enabled' | sudo tee -a /etc/rc.local
```

#### Optimize Kernel Parameters

```bash
sudo nano /etc/sysctl.conf
```

```conf
# Increase TCP backlog
net.core.somaxconn = 65535

# Optimize memory overcommit
vm.overcommit_memory = 1

# Disable swap
vm.swappiness = 0
```

Apply changes:

```bash
sudo sysctl -p
```

### 5. Benchmark Performance

```bash
# Run benchmark
redis-benchmark -h localhost -p 6379 -a your_password -t ping,set,get -n 100000 -q

# Test pub/sub performance
redis-benchmark -h localhost -p 6379 -a your_password -t pubsub -n 100000 -q
```

## Monitoring

### 1. Redis CLI Monitoring

```bash
# Connect to Redis
redis-cli -a your_password

# Monitor all commands in real-time
> MONITOR

# Get server info
> INFO

# Get memory stats
> INFO memory

# Get stats
> INFO stats

# Get replication info
> INFO replication

# Get client list
> CLIENT LIST

# Get slow log
> SLOWLOG GET 10
```

### 2. Key Metrics to Monitor

```bash
# Memory usage
redis-cli -a your_password INFO memory | grep used_memory_human

# Connected clients
redis-cli -a your_password INFO clients | grep connected_clients

# Commands per second
redis-cli -a your_password INFO stats | grep instantaneous_ops_per_sec

# Pub/Sub channels
redis-cli -a your_password PUBSUB CHANNELS

# Pub/Sub subscribers
redis-cli -a your_password PUBSUB NUMSUB inbox_updates
```

### 3. Monitoring Script

```bash
sudo nano /usr/local/bin/monitor-redis.sh
```

```bash
#!/bin/bash

REDIS_PASSWORD="your_password"
LOG_FILE="/var/log/redis-monitor.log"

# Get metrics
memory=$(redis-cli -a "$REDIS_PASSWORD" INFO memory | grep used_memory_human | cut -d: -f2 | tr -d '\r')
clients=$(redis-cli -a "$REDIS_PASSWORD" INFO clients | grep connected_clients | cut -d: -f2 | tr -d '\r')
ops=$(redis-cli -a "$REDIS_PASSWORD" INFO stats | grep instantaneous_ops_per_sec | cut -d: -f2 | tr -d '\r')

# Log metrics
echo "$(date '+%Y-%m-%d %H:%M:%S') - Memory: $memory, Clients: $clients, Ops/sec: $ops" >> "$LOG_FILE"

# Alert if memory > 80%
max_memory=$(redis-cli -a "$REDIS_PASSWORD" CONFIG GET maxmemory | tail -1)
used_memory=$(redis-cli -a "$REDIS_PASSWORD" INFO memory | grep used_memory: | cut -d: -f2 | tr -d '\r')

if [ "$max_memory" != "0" ]; then
    usage_percent=$((used_memory * 100 / max_memory))
    if [ "$usage_percent" -gt 80 ]; then
        echo "WARNING: Redis memory usage at ${usage_percent}%" | mail -s "Redis Alert" admin@yourdomain.com
    fi
fi
```

```bash
# Make executable
sudo chmod +x /usr/local/bin/monitor-redis.sh

# Add to crontab (every 5 minutes)
crontab -e
*/5 * * * * /usr/local/bin/monitor-redis.sh
```

### 4. Redis Monitoring Tools

#### redis-stat

```bash
# Install
gem install redis-stat

# Run
redis-stat --server=localhost:6379 --auth=your_password
```

#### RedisInsight (GUI)

Download from: https://redis.com/redis-enterprise/redis-insight/

## Backup & Recovery

### 1. RDB Snapshots

#### Manual Backup

```bash
# Trigger immediate save
redis-cli -a your_password BGSAVE

# Wait for completion
redis-cli -a your_password LASTSAVE

# Copy snapshot
sudo cp /var/lib/redis/dump.rdb /backup/redis-$(date +%Y%m%d).rdb
```

#### Automated Backup Script

```bash
sudo nano /usr/local/bin/backup-redis.sh
```

```bash
#!/bin/bash

BACKUP_DIR="/backup/redis"
REDIS_DATA_DIR="/var/lib/redis"
REDIS_PASSWORD="your_password"
RETENTION_DAYS=7

# Create backup directory
mkdir -p "$BACKUP_DIR"

# Trigger save
redis-cli -a "$REDIS_PASSWORD" BGSAVE

# Wait for save to complete
sleep 5

# Copy snapshot
BACKUP_FILE="$BACKUP_DIR/dump-$(date +%Y%m%d-%H%M%S).rdb"
cp "$REDIS_DATA_DIR/dump.rdb" "$BACKUP_FILE"

# Compress
gzip "$BACKUP_FILE"

# Delete old backups
find "$BACKUP_DIR" -name "dump-*.rdb.gz" -mtime +$RETENTION_DAYS -delete

echo "$(date '+%Y-%m-%d %H:%M:%S') - Redis backup completed: $BACKUP_FILE.gz"
```

```bash
# Make executable
sudo chmod +x /usr/local/bin/backup-redis.sh

# Add to crontab (daily at 2 AM)
crontab -e
0 2 * * * /usr/local/bin/backup-redis.sh
```

### 2. Recovery

#### Restore from RDB

```bash
# Stop Redis
sudo systemctl stop redis-server

# Replace dump file
sudo cp /backup/redis-20240101.rdb /var/lib/redis/dump.rdb
sudo chown redis:redis /var/lib/redis/dump.rdb

# Start Redis
sudo systemctl start redis-server

# Verify data
redis-cli -a your_password DBSIZE
```

### 3. Replication (Optional)

For high availability, set up master-slave replication:

#### Master Configuration

```conf
# /etc/redis/redis.conf (master)
bind 0.0.0.0
requirepass master_password
masterauth master_password
```

#### Slave Configuration

```conf
# /etc/redis/redis.conf (slave)
bind 0.0.0.0
requirepass slave_password
masterauth master_password
replicaof master_ip 6379
```

## Troubleshooting

### Common Issues

#### Issue: Redis won't start

**Check logs:**
```bash
sudo tail -f /var/log/redis/redis-server.log
sudo journalctl -u redis-server -n 50
```

**Common causes:**
1. Port already in use
2. Permission issues
3. Invalid configuration
4. Insufficient memory

**Solutions:**
```bash
# Check port
sudo lsof -i :6379

# Fix permissions
sudo chown -R redis:redis /var/lib/redis
sudo chown -R redis:redis /var/log/redis

# Test configuration
redis-server /etc/redis/redis.conf --test-memory 1

# Check memory
free -h
```

#### Issue: Connection refused

**Test connection:**
```bash
redis-cli ping
# If fails, check if Redis is running
sudo systemctl status redis-server
```

**Solutions:**
```bash
# Start Redis
sudo systemctl start redis-server

# Check bind address
grep bind /etc/redis/redis.conf

# Check firewall
sudo ufw status
```

#### Issue: Authentication failed

**Verify password:**
```bash
# Check configuration
grep requirepass /etc/redis/redis.conf

# Test with password
redis-cli -a your_password ping
```

#### Issue: Out of memory

**Check memory usage:**
```bash
redis-cli -a your_password INFO memory
```

**Solutions:**
```bash
# Increase maxmemory
redis-cli -a your_password CONFIG SET maxmemory 512mb

# Or edit configuration
sudo nano /etc/redis/redis.conf
# maxmemory 512mb

# Flush data (if safe)
redis-cli -a your_password FLUSHALL
```

#### Issue: High CPU usage

**Check slow queries:**
```bash
redis-cli -a your_password SLOWLOG GET 10
```

**Solutions:**
1. Optimize queries
2. Increase maxmemory-samples
3. Use pipelining
4. Check for blocking operations

#### Issue: Pub/Sub messages not received

**Check subscribers:**
```bash
redis-cli -a your_password PUBSUB CHANNELS
redis-cli -a your_password PUBSUB NUMSUB inbox_updates
```

**Test pub/sub:**
```bash
# Terminal 1 (subscriber)
redis-cli -a your_password
> SUBSCRIBE inbox_updates

# Terminal 2 (publisher)
redis-cli -a your_password
> PUBLISH inbox_updates "test message"
```

### Debugging Commands

```bash
# Check Redis status
sudo systemctl status redis-server

# Test connection
redis-cli -a your_password ping

# Get server info
redis-cli -a your_password INFO

# Monitor commands
redis-cli -a your_password MONITOR

# Check configuration
redis-cli -a your_password CONFIG GET '*'

# Check memory
redis-cli -a your_password INFO memory

# Check clients
redis-cli -a your_password CLIENT LIST

# Check slow log
redis-cli -a your_password SLOWLOG GET 10

# Check pub/sub
redis-cli -a your_password PUBSUB CHANNELS
redis-cli -a your_password PUBSUB NUMSUB inbox_updates
```

## Best Practices

1. **Always use authentication** - Set requirepass
2. **Bind to localhost** - Unless remote access is needed
3. **Set maxmemory** - Prevent OOM errors
4. **Monitor regularly** - Track memory, clients, ops/sec
5. **Backup regularly** - Daily RDB snapshots
6. **Update regularly** - Keep Redis up to date
7. **Use LRU eviction** - For cache-like workloads
8. **Disable dangerous commands** - Rename FLUSHALL, FLUSHDB
9. **Run as non-root** - Use dedicated redis user
10. **Test before production** - Benchmark and load test

## Integration with WebSocket Server

### Update .env File

```env
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_secure_password
```

### Test Integration

```bash
# Start WebSocket server
npm start

# Check logs for Redis connection
pm2 logs telepharmacy-ws | grep -i redis

# Should see: "Subscribed to inbox_updates channel"
```

### Test Pub/Sub

```bash
# Publish test message
redis-cli -a your_password PUBLISH inbox_updates '{"line_account_id":1,"message":{"id":1,"content":"test"},"unread_count":1}'

# Check WebSocket server logs
pm2 logs telepharmacy-ws
# Should see: "Broadcasted new message to room account_1"
```

## Additional Resources

- [Redis Official Documentation](https://redis.io/documentation)
- [Redis Security Guide](https://redis.io/topics/security)
- [Redis Persistence](https://redis.io/topics/persistence)
- [Redis Pub/Sub](https://redis.io/topics/pubsub)
- [Redis Best Practices](https://redis.io/topics/best-practices)

## Support

For Redis issues:
1. Check logs: `/var/log/redis/redis-server.log`
2. Test connection: `redis-cli ping`
3. Review configuration: `/etc/redis/redis.conf`
4. Check system resources: `free -h`, `top`
5. Contact system administrator if issues persist
