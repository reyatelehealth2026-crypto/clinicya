#!/bin/bash
# Install Predis without Composer
# Run on production server

echo "═══════════════════════════════════════════════════════"
echo "Installing Predis without Composer"
echo "═══════════════════════════════════════════════════════"

cd /home/zrismpsz/public_html/cny.re-ya.com

# Create vendor directory if not exists
mkdir -p vendor/predis

# Download Predis (single file version for compatibility)
echo "Downloading Predis..."
curl -L -o vendor/predis/Autoloader.php https://raw.githubusercontent.com/nrk/predis/v1.1/autoload.php 2>/dev/null

# Alternative: Download full Predis via git
echo "Cloning Predis repository..."
if [ ! -d "vendor/predis/predis" ]; then
    git clone --depth 1 --branch v1.1.10 https://github.com/predis/predis.git vendor/predis/predis 2>/dev/null || \
    git clone --depth 1 https://github.com/predis/predis.git vendor/predis/predis 2>/dev/null
fi

echo ""
echo "✅ Predis installed to: vendor/predis/predis"
echo ""
echo "Testing..."
php -r "require 'vendor/predis/predis/autoload.php'; echo 'Predis loaded successfully\n';"
