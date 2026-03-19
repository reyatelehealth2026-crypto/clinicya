#!/bin/bash

# ═══════════════════════════════════════════════════════════════════════════════
# Deploy to CNY VPS (aaPanel + Nginx 1.24 + PHP 8.3)
# VPS: 47.82.233.152
# Site: cny.re-ya.com
# ═══════════════════════════════════════════════════════════════════════════════

set -e

echo "🚀 Deploying to CNY VPS..."

# ── Configuration ────────────────────────────────────────────────────────────
SERVER="47.82.233.152"
PORT="22"
USER="root"
REMOTE_PATH="/www/wwwroot/cny.re-ya.com"

# ── Step 1: Build minified JS ────────────────────────────────────────────────
echo "📦 Building minified JS..."
if command -v npx &> /dev/null; then
    npx terser odoo-dashboard.js \
        -o odoo-dashboard.min.js \
        --compress passes=2,dead_code,drop_console \
        --mangle \
        --source-map "filename='odoo-dashboard.min.js.map',url='odoo-dashboard.min.js.map'" \
        && echo "✅ JS minified: $(wc -c < odoo-dashboard.min.js) bytes" \
        || echo "⚠️ Terser failed, deploying source JS"
else
    echo "⚠️ npx not found, skipping JS minification"
fi

# ── Step 2: Files to deploy ──────────────────────────────────────────────────
FILES=(
    # Dashboard core
    "odoo-dashboard.php"
    "odoo-dashboard.js"
    "odoo-dashboard.min.js"
    "odoo-dashboard.min.js.map"
    # API endpoints
    "api/odoo-dashboard-fast.php"
    "api/odoo-dashboard-api.php"
    "api/odoo-dashboard-functions.php"
    # Cache + Redis
    "classes/RedisCache.php"
    # Config
    "config/opcache.ini"
    "config/nginx-cny.conf"
    "config/php-fpm-pool.conf"
    ".user.ini"
    # Composer
    "composer.json"
)

echo ""
echo "📋 Files to deploy:"
for file in "${FILES[@]}"; do
    if [ -f "$file" ]; then
        echo "  ✓ $file"
    else
        echo "  ✗ $file (not found, skipping)"
    fi
done

echo ""
echo "🔗 Connecting to $SERVER:$PORT..."

# ── Step 3: Upload files ─────────────────────────────────────────────────────
FAILED=0
for file in "${FILES[@]}"; do
    if [ ! -f "$file" ]; then
        continue
    fi
    
    # Ensure remote directory exists
    REMOTE_DIR=$(dirname "$REMOTE_PATH/$file")
    ssh -p $PORT "$USER@$SERVER" "mkdir -p '$REMOTE_DIR'" 2>/dev/null

    echo "📤 Uploading $file..."
    scp -P $PORT "$file" "$USER@$SERVER:$REMOTE_PATH/$file"

    if [ $? -eq 0 ]; then
        echo "  ✅ $file"
    else
        echo "  ❌ Failed: $file"
        FAILED=$((FAILED + 1))
    fi
done

# ── Step 4: Post-deploy tasks on server ──────────────────────────────────────
echo ""
echo "🔧 Running post-deploy tasks..."

ssh -p $PORT "$USER@$SERVER" << ENDSSH
cd $REMOTE_PATH

# Install composer dependencies if composer.json changed
if command -v composer &> /dev/null; then
    echo "📦 Running composer install..."
    composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || echo "⚠️ Composer install skipped"
fi

# Clear OPcache
echo "🧹 Clearing OPcache..."
if [ -f "install/clear_opcache.php" ]; then
    php install/clear_opcache.php 2>/dev/null || echo "⚠️ OPcache clear via CLI may need web request"
fi

# Restart PHP-FPM to apply opcache changes
echo "🔄 Restarting PHP-FPM..."
/etc/init.d/php-fpm-83 reload 2>/dev/null || service php-fpm-83 reload 2>/dev/null || echo "⚠️ PHP-FPM reload failed (try aaPanel GUI)"

# Fix permissions
echo "🔑 Fixing permissions..."
chown -R www:www $REMOTE_PATH/ 2>/dev/null
find $REMOTE_PATH/ -type f -name "*.php" -exec chmod 644 {} \; 2>/dev/null
find $REMOTE_PATH/ -type d -exec chmod 755 {} \; 2>/dev/null

# Create opcache file cache directory
mkdir -p /tmp/php_opcache_file_cache
chown www:www /tmp/php_opcache_file_cache

echo "✅ Post-deploy tasks complete!"
ENDSSH

echo ""
if [ $FAILED -eq 0 ]; then
    echo "✅ Deployment completed successfully!"
else
    echo "⚠️ Deployment completed with $FAILED failed file(s)"
fi
echo "🌐 URL: https://cny.re-ya.com/odoo-dashboard"
echo ""
echo "📝 Post-deploy checklist:"
echo "  1. aaPanel → PHP 8.3 → Settings → เพิ่ม opcache.ini content"
echo "  2. aaPanel → Website → cny.re-ya.com → Conf → วาง nginx-cny.conf"
echo "  3. curl -I https://cny.re-ya.com/api/odoo-dashboard-fast.php (ดู X-Cache-Status)"
echo "  4. curl https://cny.re-ya.com/odoo-dashboard (ดู JS file loaded)"
