#!/bin/bash
# Redis Connection Diagnostic Script
# Run this on production server to verify actual connection

echo "═══════════════════════════════════════════════════════"
echo "Redis Connection Diagnostic"
echo "═══════════════════════════════════════════════════════"
echo ""

cd /home/zrismpsz/public_html/cny.re-ya.com

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

echo "3. Checking Redis Cloud connection..."
php -r "
require_once 'vendor/predis/predis/autoload.php';
try {
    \$client = new Predis\Client([
        'host' => 'redis-13718.fcrce172.us-east-1-1.ec2.cloud.redislabs.com',
        'port' => 13718,
        'username' => 'default',
        'password' => '8aOsi5ZlcevxIxkXOFn4b4qshhMTHKC5',
        'timeout' => 5
    ]);
    \$client->ping();
    echo '✅ Direct Redis connection: SUCCESS\n';
} catch (Exception \$e) {
    echo '❌ Direct Redis connection: FAILED - ' . \$e->getMessage() . '\n';
}
" 2>&1
echo ""

echo "4. Testing network latency to Redis..."
ping -c 3 redis-13718.fcrce172.us-east-1-1.ec2.cloud.redislabs.com 2>&1 | tail -2
echo ""

echo "═══════════════════════════════════════════════════════"
echo "Check complete!"
echo "═══════════════════════════════════════════════════════"
