#!/bin/bash
# Install Redis Locally on Server
# ติดตั้ง Redis บนเครื่อง server เอง (localhost:6379)

echo "═══════════════════════════════════════════════════════"
echo "Installing Redis Locally on Server"
echo "═══════════════════════════════════════════════════════"
echo ""

# Check if Redis is already installed
if command -v redis-server &> /dev/null; then
    echo "✅ Redis is already installed"
    redis-server --version
    echo ""
    echo "Checking if Redis is running..."
    if pgrep redis-server > /dev/null; then
        echo "✅ Redis is running"
        redis-cli ping
    else
        echo "⚠️  Redis is installed but not running"
        echo "Starting Redis..."
        redis-server --daemonize yes
        sleep 2
        redis-cli ping
    fi
    exit 0
fi

echo "Installing Redis..."

# Detect OS
if [ -f /etc/redhat-release ]; then
    # CentOS/RHEL
    echo "Detected: CentOS/RHEL"
    
    # Enable EPEL repository
    sudo yum install -y epel-release
    
    # Install Redis
    sudo yum install -y redis
    
    # Start Redis
    sudo systemctl enable redis
    sudo systemctl start redis
    
elif [ -f /etc/debian_version ]; then
    # Ubuntu/Debian
    echo "Detected: Ubuntu/Debian"
    
    sudo apt-get update
    sudo apt-get install -y redis-server
    
    # Start Redis
    sudo systemctl enable redis-server
    sudo systemctl start redis-server
    
else
    echo "❌ Unknown OS. Trying to install from source..."
    
    # Install from source
    cd /tmp
    wget http://download.redis.io/redis-stable.tar.gz
    tar xvzf redis-stable.tar.gz
    cd redis-stable
    make
    sudo make install
    
    # Setup Redis
    sudo mkdir -p /etc/redis
    sudo cp redis.conf /etc/redis/
    
    # Start Redis
    redis-server --daemonize yes
fi

echo ""
echo "Checking Redis installation..."
sleep 2

if command -v redis-server > /dev/null; then
    echo "✅ Redis installed successfully"
    redis-server --version
    
    echo ""
    echo "Testing Redis connection..."
    redis-cli ping
    
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo "✅ Redis is ready!"
    echo "Host: localhost"
    echo "Port: 6379"
    echo "═══════════════════════════════════════════════════════"
    echo ""
    echo "Update your config to use:"
    echo "  host: localhost"
    echo "  port: 6379"
    echo "  (no password needed for local)"
    
else
    echo "❌ Redis installation failed"
    exit 1
fi
