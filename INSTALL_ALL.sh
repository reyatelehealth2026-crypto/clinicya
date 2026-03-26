#!/bin/bash
# Install everything on VPS

echo "═══════════════════════════════════════════════════════"
echo "Installing Nginx + PHP + Redis + MySQL"
echo "═══════════════════════════════════════════════════════"
echo ""

# Update system
echo "📦 Updating system..."
sudo apt update -y

# Install Nginx
echo "📦 Installing Nginx..."
sudo apt install -y nginx

# Install PHP 8.3 and extensions
echo "📦 Installing PHP 8.3..."
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml php8.3-redis

# Install Redis
echo "📦 Installing Redis..."
sudo apt install -y redis-server

# Install MySQL
echo "📦 Installing MySQL..."
sudo apt install -y mysql-server

# Install Git
echo "📦 Installing Git..."
sudo apt install -y git

# Start all services
echo ""
echo "🚀 Starting services..."
sudo systemctl start nginx
sudo systemctl start php8.3-fpm
sudo systemctl start redis-server
sudo systemctl start mysql-server

# Enable auto-start
echo "🔧 Enabling auto-start..."
sudo systemctl enable nginx
sudo systemctl enable php8.3-fpm
sudo systemctl enable redis-server
sudo systemctl enable mysql-server

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ Installation complete!"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "Checking status..."
echo ""

# Check Nginx
if systemctl is-active --quiet nginx; then
    echo "✅ Nginx: Running"
else
    echo "❌ Nginx: Failed"
fi

# Check PHP
if php -v > /dev/null 2>&1; then
    echo "✅ PHP: $(php -v | head -1)"
else
    echo "❌ PHP: Not found"
fi

# Check Redis
if redis-cli ping > /dev/null 2>&1; then
    echo "✅ Redis: Running ($(redis-cli ping))"
else
    echo "❌ Redis: Failed"
fi

# Check MySQL
if systemctl is-active --quiet mysql; then
    echo "✅ MySQL: Running"
else
    echo "❌ MySQL: Failed"
fi

echo ""
echo "Next steps:"
echo "  1. Test: curl -I localhost"
echo "  2. Deploy code: cd /var/www/html && git clone ..."
echo "  3. Setup database"
