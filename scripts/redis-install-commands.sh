#!/bin/bash
# Redis Local Installation Guide
# Run on production server

echo "═══════════════════════════════════════════════════════"
echo "Redis Local Installation Commands"
echo "Run these on your production server"
echo "═══════════════════════════════════════════════════════"
echo ""

# Detect OS and provide appropriate commands
if [ -f /etc/redhat-release ]; then
    echo "🖥️  Detected: CentOS/RHEL"
    echo ""
    echo "Run these commands:"
    echo ""
    echo "# 1. Install Redis"
    echo "sudo yum install -y epel-release"
    echo "sudo yum install -y redis"
    echo ""
    echo "# 2. Start Redis"
    echo "sudo systemctl start redis"
    echo "sudo systemctl enable redis"
    echo ""
    echo "# 3. Check status"
    echo "sudo systemctl status redis"
    echo "redis-cli ping"
    
elif [ -f /etc/debian_version ]; then
    echo "🖥️  Detected: Ubuntu/Debian"
    echo ""
    echo "Run these commands:"
    echo ""
    echo "# 1. Install Redis"
    echo "sudo apt-get update"
    echo "sudo apt-get install -y redis-server"
    echo ""
    echo "# 2. Start Redis"
    echo "sudo service redis-server start"
    echo "sudo systemctl enable redis-server"
    echo ""
    echo "# 3. Check status"
    echo "sudo service redis-server status"
    echo "redis-cli ping"
    
else
    echo "🖥️  OS: Unknown"
    echo ""
    echo "Try:"
    echo "which yum && echo 'Use CentOS commands'"
    echo "which apt-get && echo 'Use Ubuntu commands'"
fi

echo ""
echo "═══════════════════════════════════════════════════════"
echo "After installation, test with:"
echo "  php scripts/redis-cache-test.php"
echo ""
echo "Expected result:"
echo "  Type: redis-local"
echo "  Performance: ~1-5ms"
echo "═══════════════════════════════════════════════════════"
