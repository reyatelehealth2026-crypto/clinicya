#!/bin/bash
# VPS Quick Setup - After Firewall
# รันบน VPS หลังจากเปิด firewall แล้ว

echo "═══════════════════════════════════════════════════════"
echo "🚀 VPS Setup - Next Steps"
echo "═══════════════════════════════════════════════════════"
echo ""

# Check current status
echo "📊 Current Status Check"
echo "------------------------"

echo ""
echo "1. Checking Nginx..."
if systemctl is-active --quiet nginx 2>/dev/null; then
    echo "   ✅ Nginx is running"
else
    echo "   ❌ Nginx not installed or not running"
    echo "   Run: sudo apt install -y nginx && sudo systemctl start nginx"
fi

echo ""
echo "2. Checking PHP..."
if command -v php >/dev/null 2>&1; then
    php -v | head -1
else
    echo "   ❌ PHP not installed"
    echo "   Run: sudo apt install -y php8.3-fpm"
fi

echo ""
echo "3. Checking Redis..."
if systemctl is-active --quiet redis 2>/dev/null || systemctl is-active --quiet redis-server 2>/dev/null; then
    echo "   ✅ Redis is running"
    redis-cli ping 2>/dev/null | grep -q PONG && echo "   ✅ Redis responding"
else
    echo "   ❌ Redis not installed"
    echo "   Run: sudo apt install -y redis-server"
fi

echo ""
echo "4. Checking MySQL..."
if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mysqld 2>/dev/null; then
    echo "   ✅ MySQL is running"
else
    echo "   ❌ MySQL not installed"
    echo "   Run: sudo apt install -y mysql-server"
fi

echo ""
echo "═══════════════════════════════════════════════════════"
echo "📋 Quick Install Commands"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "ถ้าอะไรก็ยังไม่ติดตั้ง รันคำสั่งนี้ทั้งหมด:"
echo ""
echo "sudo apt update"
echo "sudo apt install -y nginx php8.3-fpm php8.3-mysql php8.3-redis redis-server mysql-server"
echo "sudo systemctl start nginx php8.3-fpm redis-server mysql-server"
echo "sudo systemctl enable nginx php8.3-fpm redis-server mysql-server"
echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ Check complete!"
echo "═══════════════════════════════════════════════════════"
