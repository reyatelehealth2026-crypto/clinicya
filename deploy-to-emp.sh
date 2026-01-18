#!/bin/bash
# Deploy script for emp.re-ya.net

echo "🚀 Deploying to emp.re-ya.net..."

# SSH and pull latest code
ssh -p 9922 zrismpsz@z129720-ri35sm.ps09.zwhhosting.com << 'ENDSSH'
cd ~/public_html/emp.re-ya.net

echo "📥 Pulling latest code..."
git pull origin master

echo "🔧 Running migrations..."
php install/run_inbox_v2_performance_migration.php
php install/run_performance_feature_flags_migration.php

echo "🧹 Clearing cache..."
if [ -f "install/clear_opcache.php" ]; then
    php install/clear_opcache.php
fi

echo "✅ Deployment complete!"
echo "🌐 Visit: https://emp.re-ya.net/inbox-v2.php"
ENDSSH

echo "✨ Done!"
