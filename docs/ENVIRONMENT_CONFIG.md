# Environment Configuration Guide

Complete guide for configuring environment variables for the Inbox v2 WebSocket server and performance upgrade features.

## Table of Contents

1. [Overview](#overview)
2. [Required Variables](#required-variables)
3. [Optional Variables](#optional-variables)
4. [Environment-Specific Configurations](#environment-specific-configurations)
5. [Performance Tuning](#performance-tuning)
6. [Security Best Practices](#security-best-practices)
7. [Validation](#validation)
8. [Troubleshooting](#troubleshooting)

## Overview

### Configuration Files

The system uses environment variables stored in `.env` files:

- **`.env`** - Main configuration file (gitignored)
- **`.env.example`** - Template with default values
- **`.env.production`** - Production-specific overrides (optional)
- **`.env.development`** - Development-specific overrides (optional)

### Loading Priority

1. System environment variables (highest priority)
2. `.env.production` or `.env.development` (if NODE_ENV is set)
3. `.env` (default)
4. `.env.example` (fallback)

## Required Variables

These variables MUST be set for the system to function properly.

### Server Configuration

#### NODE_ENV

**Description:** Application environment mode  
**Type:** String  
**Values:** `development`, `production`, `test`  
**Default:** `development`  
**Required:** Yes

```env
NODE_ENV=production
```

**Impact:**
- `production`: Optimized performance, minimal logging, error handling
- `development`: Verbose logging, hot reload, debugging enabled
- `test`: Test mode with mocked services

#### WEBSOCKET_PORT

**Description:** Port for WebSocket server to listen on  
**Type:** Integer  
**Range:** 1024-65535  
**Default:** `3000`  
**Required:** Yes

```env
WEBSOCKET_PORT=3000
```

**Notes:**
- Use ports > 1024 to avoid requiring root privileges
- Ensure port is not already in use
- Configure firewall to allow this port
- Use reverse proxy (Nginx/Apache) for production

#### WEBSOCKET_HOST

**Description:** Host address for WebSocket server to bind to  
**Type:** String (IP address)  
**Values:** `0.0.0.0` (all interfaces), `127.0.0.1` (localhost only), specific IP  
**Default:** `0.0.0.0`  
**Required:** Yes

```env
WEBSOCKET_HOST=0.0.0.0
```

**Security:**
- Use `127.0.0.1` if only local connections needed
- Use `0.0.0.0` with firewall rules for remote access
- Use specific IP for multi-interface servers

### CORS Configuration

#### ALLOWED_ORIGINS

**Description:** Comma-separated list of allowed origins for CORS  
**Type:** String (comma-separated URLs)  
**Default:** `*` (allow all - NOT recommended for production)  
**Required:** Yes

```env
# Production (specific domains)
ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com,https://admin.yourdomain.com

# Development (localhost)
ALLOWED_ORIGINS=http://localhost,http://127.0.0.1,http://localhost:8080

# Multiple environments
ALLOWED_ORIGINS=https://yourdomain.com,https://staging.yourdomain.com,http://localhost:8080
```

**Security:**
- NEVER use `*` in production
- Include all subdomains that need access
- Use HTTPS in production
- Include both www and non-www versions if applicable

### Database Configuration

#### DB_HOST

**Description:** MySQL/MariaDB server hostname or IP  
**Type:** String  
**Default:** `localhost`  
**Required:** Yes

```env
# Local database
DB_HOST=localhost

# Remote database
DB_HOST=192.168.1.100

# Cloud database
DB_HOST=db.example.com
```

#### DB_USER

**Description:** Database username  
**Type:** String  
**Required:** Yes

```env
DB_USER=telepharmacy_user
```

**Best Practices:**
- Use dedicated user (not root)
- Grant only necessary permissions
- Different users for different environments

#### DB_PASSWORD

**Description:** Database password  
**Type:** String  
**Required:** Yes

```env
DB_PASSWORD=your_secure_database_password
```

**Security:**
- Use strong passwords (16+ characters)
- Include uppercase, lowercase, numbers, symbols
- Never commit to version control
- Rotate regularly

#### DB_NAME

**Description:** Database name  
**Type:** String  
**Required:** Yes

```env
DB_NAME=telepharmacy
```

### Redis Configuration

#### REDIS_HOST

**Description:** Redis server hostname or IP  
**Type:** String  
**Default:** `localhost`  
**Required:** Yes

```env
# Local Redis
REDIS_HOST=localhost

# Remote Redis
REDIS_HOST=192.168.1.101

# Redis cluster
REDIS_HOST=redis.example.com
```

#### REDIS_PORT

**Description:** Redis server port  
**Type:** Integer  
**Default:** `6379`  
**Required:** Yes

```env
REDIS_PORT=6379
```

#### REDIS_PASSWORD

**Description:** Redis authentication password  
**Type:** String  
**Default:** Empty (no authentication)  
**Required:** Highly recommended

```env
REDIS_PASSWORD=your_secure_redis_password
```

**Security:**
- Always set password in production
- Use strong passwords
- Match password in Redis configuration

## Optional Variables

These variables have sensible defaults but can be customized.

### Session Configuration

#### SESSION_SECRET

**Description:** Secret key for session encryption  
**Type:** String (random)  
**Default:** Auto-generated (not recommended)  
**Required:** Recommended

```env
SESSION_SECRET=your_random_session_secret_here
```

**Generate:**
```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

### Logging Configuration

#### LOG_LEVEL

**Description:** Logging verbosity level  
**Type:** String  
**Values:** `error`, `warn`, `info`, `debug`, `trace`  
**Default:** `info`  
**Required:** No

```env
# Production
LOG_LEVEL=warn

# Development
LOG_LEVEL=debug

# Troubleshooting
LOG_LEVEL=trace
```

#### LOG_FILE

**Description:** Path to log file  
**Type:** String (file path)  
**Default:** `./logs/websocket.log`  
**Required:** No

```env
LOG_FILE=/var/log/telepharmacy/websocket.log
```

### Performance Configuration

#### MAX_CONNECTIONS

**Description:** Maximum concurrent WebSocket connections  
**Type:** Integer  
**Default:** `1000`  
**Required:** No

```env
# Small server
MAX_CONNECTIONS=500

# Medium server
MAX_CONNECTIONS=1000

# Large server
MAX_CONNECTIONS=5000
```

#### PING_TIMEOUT

**Description:** WebSocket ping timeout in milliseconds  
**Type:** Integer  
**Default:** `60000` (60 seconds)  
**Required:** No

```env
PING_TIMEOUT=60000
```

#### PING_INTERVAL

**Description:** WebSocket ping interval in milliseconds  
**Type:** Integer  
**Default:** `25000` (25 seconds)  
**Required:** No

```env
PING_INTERVAL=25000
```

### Database Pool Configuration

#### DB_CONNECTION_LIMIT

**Description:** Maximum database connections in pool  
**Type:** Integer  
**Default:** `10`  
**Required:** No

```env
# Low traffic
DB_CONNECTION_LIMIT=5

# Medium traffic
DB_CONNECTION_LIMIT=10

# High traffic
DB_CONNECTION_LIMIT=20
```

#### DB_QUEUE_LIMIT

**Description:** Maximum queued connection requests  
**Type:** Integer  
**Default:** `0` (unlimited)  
**Required:** No

```env
DB_QUEUE_LIMIT=0
```

### Redis Configuration (Advanced)

#### REDIS_DB

**Description:** Redis database number  
**Type:** Integer (0-15)  
**Default:** `0`  
**Required:** No

```env
REDIS_DB=0
```

#### REDIS_RETRY_STRATEGY

**Description:** Enable automatic retry on connection failure  
**Type:** Boolean  
**Default:** `true`  
**Required:** No

```env
REDIS_RETRY_STRATEGY=true
```

### Monitoring Configuration

#### ENABLE_METRICS

**Description:** Enable performance metrics collection  
**Type:** Boolean  
**Default:** `true`  
**Required:** No

```env
ENABLE_METRICS=true
```

#### METRICS_INTERVAL

**Description:** Metrics collection interval in milliseconds  
**Type:** Integer  
**Default:** `60000` (1 minute)  
**Required:** No

```env
METRICS_INTERVAL=60000
```

## Environment-Specific Configurations

### Development Environment

**File:** `.env.development`

```env
# Server
NODE_ENV=development
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=127.0.0.1

# CORS (allow localhost)
ALLOWED_ORIGINS=http://localhost,http://127.0.0.1,http://localhost:8080

# Database (local)
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=root
DB_NAME=telepharmacy_dev

# Redis (local)
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=

# Logging (verbose)
LOG_LEVEL=debug
LOG_FILE=./logs/websocket-dev.log

# Performance (relaxed)
MAX_CONNECTIONS=100
PING_TIMEOUT=120000
PING_INTERVAL=30000

# Database (small pool)
DB_CONNECTION_LIMIT=5

# Monitoring (enabled)
ENABLE_METRICS=true
METRICS_INTERVAL=30000
```

### Staging Environment

**File:** `.env.staging`

```env
# Server
NODE_ENV=production
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=0.0.0.0

# CORS (staging domain)
ALLOWED_ORIGINS=https://staging.yourdomain.com

# Database (staging)
DB_HOST=staging-db.yourdomain.com
DB_USER=telepharmacy_staging
DB_PASSWORD=staging_secure_password
DB_NAME=telepharmacy_staging

# Redis (staging)
REDIS_HOST=staging-redis.yourdomain.com
REDIS_PORT=6379
REDIS_PASSWORD=staging_redis_password

# Logging (moderate)
LOG_LEVEL=info
LOG_FILE=/var/log/telepharmacy/websocket-staging.log

# Performance (moderate)
MAX_CONNECTIONS=500
PING_TIMEOUT=60000
PING_INTERVAL=25000

# Database (medium pool)
DB_CONNECTION_LIMIT=10

# Monitoring (enabled)
ENABLE_METRICS=true
METRICS_INTERVAL=60000
```

### Production Environment

**File:** `.env.production`

```env
# Server
NODE_ENV=production
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=0.0.0.0

# CORS (production domains)
ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com

# Database (production)
DB_HOST=prod-db.yourdomain.com
DB_USER=telepharmacy_prod
DB_PASSWORD=production_secure_password_here
DB_NAME=telepharmacy

# Redis (production)
REDIS_HOST=prod-redis.yourdomain.com
REDIS_PORT=6379
REDIS_PASSWORD=production_redis_password_here

# Session
SESSION_SECRET=production_session_secret_here

# Logging (minimal)
LOG_LEVEL=warn
LOG_FILE=/var/log/telepharmacy/websocket.log

# Performance (optimized)
MAX_CONNECTIONS=2000
PING_TIMEOUT=60000
PING_INTERVAL=25000

# Database (large pool)
DB_CONNECTION_LIMIT=20
DB_QUEUE_LIMIT=0

# Monitoring (enabled)
ENABLE_METRICS=true
METRICS_INTERVAL=60000
```

## Performance Tuning

### Low-Traffic Server (< 100 concurrent users)

```env
MAX_CONNECTIONS=200
DB_CONNECTION_LIMIT=5
PING_TIMEOUT=120000
PING_INTERVAL=30000
METRICS_INTERVAL=300000
```

**Resources:**
- RAM: 512MB
- CPU: 1 core
- Redis: 128MB

### Medium-Traffic Server (100-500 concurrent users)

```env
MAX_CONNECTIONS=1000
DB_CONNECTION_LIMIT=10
PING_TIMEOUT=60000
PING_INTERVAL=25000
METRICS_INTERVAL=60000
```

**Resources:**
- RAM: 2GB
- CPU: 2 cores
- Redis: 256MB

### High-Traffic Server (500-2000 concurrent users)

```env
MAX_CONNECTIONS=3000
DB_CONNECTION_LIMIT=20
PING_TIMEOUT=60000
PING_INTERVAL=25000
METRICS_INTERVAL=60000
```

**Resources:**
- RAM: 4GB+
- CPU: 4+ cores
- Redis: 512MB+

### Very High-Traffic Server (2000+ concurrent users)

```env
MAX_CONNECTIONS=5000
DB_CONNECTION_LIMIT=50
PING_TIMEOUT=60000
PING_INTERVAL=25000
METRICS_INTERVAL=60000
```

**Resources:**
- RAM: 8GB+
- CPU: 8+ cores
- Redis: 1GB+
- Consider clustering/load balancing

## Security Best Practices

### 1. Never Commit Secrets

```bash
# Add to .gitignore
echo ".env" >> .gitignore
echo ".env.production" >> .gitignore
echo ".env.staging" >> .gitignore
```

### 2. Use Strong Passwords

```bash
# Generate database password
openssl rand -base64 32

# Generate Redis password
openssl rand -base64 32

# Generate session secret
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

### 3. Restrict File Permissions

```bash
# Secure .env file
chmod 600 .env
chown www-data:www-data .env

# Secure directory
chmod 750 /var/www/telepharmacy
chown -R www-data:www-data /var/www/telepharmacy
```

### 4. Use Environment-Specific Files

```bash
# Development
cp .env.example .env.development

# Staging
cp .env.example .env.staging

# Production
cp .env.example .env.production
```

### 5. Rotate Secrets Regularly

- Database passwords: Every 90 days
- Redis passwords: Every 90 days
- Session secrets: Every 180 days
- SSL certificates: Before expiration

### 6. Use Secrets Management (Advanced)

For enterprise deployments, consider:

- **HashiCorp Vault**
- **AWS Secrets Manager**
- **Azure Key Vault**
- **Google Secret Manager**

## Validation

### Validation Script

Create `scripts/validate-env.js`:

```javascript
#!/usr/bin/env node

require('dotenv').config();

const required = [
    'NODE_ENV',
    'WEBSOCKET_PORT',
    'WEBSOCKET_HOST',
    'ALLOWED_ORIGINS',
    'DB_HOST',
    'DB_USER',
    'DB_PASSWORD',
    'DB_NAME',
    'REDIS_HOST',
    'REDIS_PORT'
];

const recommended = [
    'REDIS_PASSWORD',
    'SESSION_SECRET',
    'LOG_LEVEL'
];

let errors = [];
let warnings = [];

// Check required variables
required.forEach(key => {
    if (!process.env[key]) {
        errors.push(`Missing required variable: ${key}`);
    }
});

// Check recommended variables
recommended.forEach(key => {
    if (!process.env[key]) {
        warnings.push(`Missing recommended variable: ${key}`);
    }
});

// Validate values
if (process.env.NODE_ENV && !['development', 'production', 'test'].includes(process.env.NODE_ENV)) {
    errors.push(`Invalid NODE_ENV: ${process.env.NODE_ENV}`);
}

if (process.env.WEBSOCKET_PORT) {
    const port = parseInt(process.env.WEBSOCKET_PORT);
    if (isNaN(port) || port < 1024 || port > 65535) {
        errors.push(`Invalid WEBSOCKET_PORT: ${process.env.WEBSOCKET_PORT}`);
    }
}

if (process.env.ALLOWED_ORIGINS === '*' && process.env.NODE_ENV === 'production') {
    warnings.push('ALLOWED_ORIGINS is set to * in production (security risk)');
}

// Report results
console.log('Environment Validation Results:');
console.log('================================\n');

if (errors.length > 0) {
    console.log('❌ ERRORS:');
    errors.forEach(err => console.log(`  - ${err}`));
    console.log('');
}

if (warnings.length > 0) {
    console.log('⚠️  WARNINGS:');
    warnings.forEach(warn => console.log(`  - ${warn}`));
    console.log('');
}

if (errors.length === 0 && warnings.length === 0) {
    console.log('✅ All checks passed!');
    process.exit(0);
} else if (errors.length > 0) {
    console.log('❌ Validation failed!');
    process.exit(1);
} else {
    console.log('⚠️  Validation passed with warnings');
    process.exit(0);
}
```

### Run Validation

```bash
# Make executable
chmod +x scripts/validate-env.js

# Run validation
node scripts/validate-env.js

# Or add to package.json
npm run validate-env
```

### Pre-Deployment Checklist

```bash
# 1. Validate environment
node scripts/validate-env.js

# 2. Test database connection
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME -e "SELECT 1"

# 3. Test Redis connection
redis-cli -h $REDIS_HOST -p $REDIS_PORT -a $REDIS_PASSWORD ping

# 4. Check file permissions
ls -la .env

# 5. Verify CORS settings
grep ALLOWED_ORIGINS .env

# 6. Test WebSocket server
npm start
curl http://localhost:$WEBSOCKET_PORT/health
```

## Troubleshooting

### Issue: Environment variables not loaded

**Check:**
```bash
# Verify .env file exists
ls -la .env

# Check file contents
cat .env

# Verify dotenv is installed
npm list dotenv
```

**Solution:**
```bash
# Reinstall dotenv
npm install dotenv

# Ensure require at top of file
# require('dotenv').config();
```

### Issue: Wrong environment loaded

**Check:**
```bash
# Verify NODE_ENV
echo $NODE_ENV

# Check which .env file is loaded
node -e "require('dotenv').config(); console.log(process.env.NODE_ENV)"
```

**Solution:**
```bash
# Set NODE_ENV explicitly
export NODE_ENV=production

# Or in PM2
pm2 start websocket-server.js --env production
```

### Issue: Database connection failed

**Check:**
```bash
# Test connection manually
mysql -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME

# Verify variables
echo $DB_HOST
echo $DB_USER
echo $DB_NAME
```

**Solution:**
- Verify credentials in .env
- Check database server is running
- Verify network connectivity
- Check firewall rules

### Issue: Redis connection failed

**Check:**
```bash
# Test connection
redis-cli -h $REDIS_HOST -p $REDIS_PORT -a $REDIS_PASSWORD ping

# Verify variables
echo $REDIS_HOST
echo $REDIS_PORT
```

**Solution:**
- Verify Redis is running
- Check password matches
- Verify network connectivity
- Check firewall rules

### Issue: CORS errors

**Check:**
```bash
# Verify ALLOWED_ORIGINS
grep ALLOWED_ORIGINS .env
```

**Solution:**
- Add your domain to ALLOWED_ORIGINS
- Include protocol (http:// or https://)
- Include port if non-standard
- Restart server after changes

## Additional Resources

- [dotenv Documentation](https://github.com/motdotla/dotenv)
- [Node.js Environment Variables](https://nodejs.org/api/process.html#process_process_env)
- [12-Factor App Config](https://12factor.net/config)
- [Security Best Practices](https://cheatsheetseries.owasp.org/cheatsheets/Nodejs_Security_Cheat_Sheet.html)

## Support

For configuration issues:
1. Run validation script
2. Check logs for errors
3. Verify all required variables are set
4. Test connections manually
5. Contact development team if issues persist
