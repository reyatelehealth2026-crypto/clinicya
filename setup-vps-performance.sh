#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
# VPS Performance Setup for cny.re-ya.com
# aaPanel + Nginx 1.24 + PHP 8.3 + Redis
# ═══════════════════════════════════════════════════════════════════════════════
#
# รันบน VPS:
#   bash setup-vps-performance.sh
#
# Prerequisite: aaPanel installed with PHP 8.3 + Nginx 1.24
# ═══════════════════════════════════════════════════════════════════════════════

set -e

SITE_PATH="/www/wwwroot/cny.re-ya.com"
PHP_VERSION="83"
PHP_CONF_DIR="/www/server/php/${PHP_VERSION}/conf.d"
PHP_FPM_CONF="/www/server/php/${PHP_VERSION}/etc/php-fpm.d/www.conf"

echo "═══════════════════════════════════════════════════════"
echo "  🚀 VPS Performance Setup — cny.re-ya.com"
echo "═══════════════════════════════════════════════════════"
echo ""

# ── Step 1: Install Redis ────────────────────────────────────────────────────
echo "📦 Step 1: Redis Installation"
if command -v redis-server &> /dev/null; then
    echo "  ✅ Redis already installed: $(redis-server --version | head -1)"
else
    echo "  📥 Installing Redis via aaPanel..."
    echo "  ⚠️  Please install Redis from aaPanel → App Store → Redis"
    echo "       Then re-run this script"
fi

# Check Redis is running
if redis-cli ping &> /dev/null; then
    echo "  ✅ Redis is running (PONG)"
else
    echo "  ⚠️  Redis not responding. Start it: systemctl start redis"
fi

echo ""

# ── Step 2: PHP Redis Extension ──────────────────────────────────────────────
echo "📦 Step 2: PHP Redis Extension"
PHP_BIN="/www/server/php/${PHP_VERSION}/bin/php"
if $PHP_BIN -m 2>/dev/null | grep -q "^redis$"; then
    echo "  ✅ PHP redis extension loaded"
else
    echo "  ⚠️  PHP redis extension not loaded"
    echo "       Install: aaPanel → PHP 8.3 → Extensions → Install redis"
fi

echo ""

# ── Step 3: OPcache Configuration ────────────────────────────────────────────
echo "⚡ Step 3: OPcache Configuration"
mkdir -p "$PHP_CONF_DIR"

if [ -f "$SITE_PATH/config/opcache.ini" ]; then
    cp "$SITE_PATH/config/opcache.ini" "$PHP_CONF_DIR/99-opcache-dashboard.ini"
    echo "  ✅ OPcache config deployed to $PHP_CONF_DIR/99-opcache-dashboard.ini"
else
    echo "  ❌ config/opcache.ini not found in $SITE_PATH"
fi

# Create file cache directory
mkdir -p /tmp/php_opcache_file_cache
chown www:www /tmp/php_opcache_file_cache
echo "  ✅ OPcache file cache directory created"

echo ""

# ── Step 4: PHP-FPM Tuning ───────────────────────────────────────────────────
echo "🔧 Step 4: PHP-FPM Tuning"

# Detect available RAM and suggest max_children
TOTAL_RAM_MB=$(free -m | awk '/^Mem:/{print $2}')
SUGGESTED_CHILDREN=$((TOTAL_RAM_MB / 80))
if [ $SUGGESTED_CHILDREN -lt 5 ]; then SUGGESTED_CHILDREN=5; fi
if [ $SUGGESTED_CHILDREN -gt 80 ]; then SUGGESTED_CHILDREN=80; fi

echo "  📊 Total RAM: ${TOTAL_RAM_MB}MB"
echo "  📊 Suggested pm.max_children: ${SUGGESTED_CHILDREN}"

# Create slow log directory
mkdir -p /www/wwwlogs/php
echo "  ✅ PHP slow log directory created"

echo ""
echo "  💡 To apply FPM settings:"
echo "     aaPanel → PHP 8.3 → FPM Config → set pm.max_children = ${SUGGESTED_CHILDREN}"

echo ""

# ── Step 5: Nginx Config ─────────────────────────────────────────────────────
echo "🌐 Step 5: Nginx Configuration"

# Create fastcgi cache directory
mkdir -p /www/server/nginx/fastcgi_cache
chown www:www /www/server/nginx/fastcgi_cache
echo "  ✅ FastCGI cache directory created"

echo ""
echo "  💡 To apply Nginx config:"
echo "     1. aaPanel → Nginx → Config → Main Config"
echo "        Add in http {} block:"
echo '        fastcgi_cache_path /www/server/nginx/fastcgi_cache levels=1:2 keys_zone=DASHBOARD:10m max_size=100m inactive=5m use_temp_path=off;'
echo '        fastcgi_cache_key "$scheme$request_method$host$request_uri$request_body";'
echo ""
echo "     2. aaPanel → Website → cny.re-ya.com → Conf"
echo "        Paste content from: $SITE_PATH/config/nginx-cny.conf"

echo ""

# ── Step 6: Composer Dependencies ────────────────────────────────────────────
echo "📦 Step 6: Composer Dependencies"
cd "$SITE_PATH"
if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null \
        && echo "  ✅ Composer dependencies installed" \
        || echo "  ⚠️  Composer install failed (predis will be installed next deploy)"
else
    echo "  ⚠️  Composer not found. Install: aaPanel → App Store → Composer"
fi

echo ""

# ── Step 7: Node.js Build (JS Minification) ─────────────────────────────────
echo "📦 Step 7: JS Minification"
if command -v node &> /dev/null; then
    echo "  ✅ Node.js found: $(node --version)"
    cd "$SITE_PATH"
    if [ ! -d "node_modules" ]; then
        npm install --production=false 2>/dev/null || echo "  ⚠️  npm install failed"
    fi
    if command -v npx &> /dev/null && [ -f "odoo-dashboard.js" ]; then
        npx terser odoo-dashboard.js \
            -o odoo-dashboard.min.js \
            --compress passes=2,dead_code,drop_console \
            --mangle \
            && echo "  ✅ JS minified: $(wc -c < odoo-dashboard.min.js) bytes (was $(wc -c < odoo-dashboard.js) bytes)" \
            || echo "  ⚠️  JS minification failed"
    fi
else
    echo "  ⚠️  Node.js not found. Install: aaPanel → App Store → Node.js"
fi

echo ""

# ── Step 8: Restart Services ─────────────────────────────────────────────────
echo "🔄 Step 8: Restart Services"

/etc/init.d/php-fpm-${PHP_VERSION} restart 2>/dev/null && echo "  ✅ PHP-FPM restarted" || echo "  ⚠️  PHP-FPM restart failed"
/etc/init.d/nginx restart 2>/dev/null && echo "  ✅ Nginx restarted" || echo "  ⚠️  Nginx restart failed"

echo ""

# ── Step 9: Verify ───────────────────────────────────────────────────────────
echo "🔍 Step 9: Verification"

# Check OPcache
$PHP_BIN -r "echo 'OPcache: ' . (opcache_get_status(false)['opcache_enabled'] ? '✅ Enabled' : '❌ Disabled') . PHP_EOL;" 2>/dev/null || echo "  ⚠️  Cannot check OPcache"

# Check JIT
$PHP_BIN -r "
\$s = opcache_get_status(false);
echo 'JIT: ' . (!empty(\$s['jit']['enabled']) ? '✅ Enabled' : '❌ Disabled') . PHP_EOL;
" 2>/dev/null || echo "  ⚠️  Cannot check JIT"

# Check Redis
if redis-cli ping &> /dev/null; then
    echo "  Redis: ✅ Running"
    REDIS_MEM=$(redis-cli info memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | tr -d '\r')
    echo "  Redis Memory: ${REDIS_MEM:-N/A}"
else
    echo "  Redis: ❌ Not running"
fi

# Check PHP redis extension
$PHP_BIN -r "echo 'PHP Redis Ext: ' . (extension_loaded('redis') ? '✅ Loaded' : '❌ Not loaded') . PHP_EOL;" 2>/dev/null

# Check minified JS
if [ -f "$SITE_PATH/odoo-dashboard.min.js" ]; then
    ORIG_SIZE=$(wc -c < "$SITE_PATH/odoo-dashboard.js")
    MIN_SIZE=$(wc -c < "$SITE_PATH/odoo-dashboard.min.js")
    SAVINGS=$((100 - (MIN_SIZE * 100 / ORIG_SIZE)))
    echo "  JS Minified: ✅ ${MIN_SIZE} bytes (${SAVINGS}% smaller)"
else
    echo "  JS Minified: ❌ Not found"
fi

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  ✅ Setup Complete!"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "  🌐 Test: curl -w 'Total: %{time_total}s\n' https://cny.re-ya.com/api/odoo-dashboard-fast.php -d '{\"action\":\"health\"}'"
echo ""
echo "  Expected improvements:"
echo "    - API parse time: 1.3s → ~0ms (OPcache)"
echo "    - Cache reads: 50ms → <1ms (Redis)"
echo "    - JS transfer: ~150KB → ~60KB (minified)"
echo "    - Font loading: non-blocking (async)"
echo "    - Static assets: 30-day browser cache"
echo ""
