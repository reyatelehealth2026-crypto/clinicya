#!/bin/bash
# Redis Connection Diagnostic Script
# Run this on production server to verify actual connection
#
# Reads the target from the environment, the same vars config/config.php uses.
# Nothing is hardcoded here: this repository is public, so a connection string
# written into a diagnostic script is a published one.
#
#   REDIS_HOST=... REDIS_PORT=... REDIS_USERNAME=... REDIS_PASSWORD=... \
#     bash scripts/check-redis-connection.sh

echo "═══════════════════════════════════════════════════════"
echo "Redis Connection Diagnostic"
echo "═══════════════════════════════════════════════════════"
echo ""

cd "$(cd "$(dirname "$0")/.." && pwd)"

REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_USERNAME="${REDIS_USERNAME:-}"
REDIS_PASSWORD="${REDIS_PASSWORD:-}"
export REDIS_HOST REDIS_PORT REDIS_USERNAME REDIS_PASSWORD

echo "1. Checking Predis installation..."
if [ -f "vendor/predis/predis/autoload.php" ]; then
    echo "   ✅ Predis found at vendor/predis/predis"
else
    echo "   ❌ Predis not found"
fi
echo ""

echo "2. Running cache test..."
php scripts/redis-cache-test.php 2>&1 | grep -A5 "Cache Type Detection"
echo ""

echo "3. Checking Redis connection (host: $REDIS_HOST:$REDIS_PORT)..."
php -r "
require_once 'vendor/predis/predis/autoload.php';
\$params = [
    'host'    => getenv('REDIS_HOST'),
    'port'    => (int) getenv('REDIS_PORT'),
    'timeout' => 5,
];
// Predis treats an empty username/password as credentials and tries to AUTH with
// them; omit the keys entirely when unset so an unauthenticated local Redis works.
if (getenv('REDIS_USERNAME') !== '') { \$params['username'] = getenv('REDIS_USERNAME'); }
if (getenv('REDIS_PASSWORD') !== '') { \$params['password'] = getenv('REDIS_PASSWORD'); }
try {
    \$client = new Predis\Client(\$params);
    \$client->ping();
    echo '✅ Direct Redis connection: SUCCESS\n';
} catch (Exception \$e) {
    echo '❌ Direct Redis connection: FAILED - ' . \$e->getMessage() . '\n';
}
" 2>&1
echo ""

echo "4. Testing network latency to Redis..."
ping -c 3 "$REDIS_HOST" 2>&1 | tail -2
echo ""

echo "═══════════════════════════════════════════════════════"
echo "Check complete!"
echo "═══════════════════════════════════════════════════════"
