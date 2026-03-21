#!/bin/bash
# Quick Deploy Script for New VPS
# Deploy โค้ดขึ้น VPS ก่อน ค่อยตั้งค่าทีหลัง

echo "═══════════════════════════════════════════════════════"
echo "🚀 Quick Deploy to VPS"
echo "═══════════════════════════════════════════════════════"
echo ""

# สร้างโฟลเดอร์
sudo mkdir -p /var/www/cny.re-ya.com
sudo chown -R $USER:$USER /var/www/cny.re-ya.com

echo "✅ โฟลเดอร์ /var/www/cny.re-ya.com สร้างแล้ว"
echo ""

# โหลดโค้ดจาก GitHub
echo "📥 กำลังโหลดโค้ดจาก GitHub..."
cd /var/www/cny.re-ya.com

git clone https://github.com/reyatelehealth2026-crypto/odoo.git .

if [ $? -eq 0 ]; then
    echo "✅ โหลดโค้ดสำเร็จ"
else
    echo "❌ โหลดโค้ดไม่สำเร็จ ลองใช้ token หรือ SSH"
    echo "วิธีแก้:"
    echo "  1. สร้าง SSH key: ssh-keygen -t rsa -b 4096"
    echo "  2. เพิ่ม public key ใน GitHub"
fi

echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ Deploy เสร็จแล้ว (หรือเกือบเสร็จ)"
echo ""
echo "โฟลเดอร์โค้ด: /var/www/cny.re-ya.com"
echo ""
echo "ขั้นตอนต่อไป:"
echo "  1. ติดตั้ง Nginx, PHP, Redis, MySQL"
echo "  2. ตั้งค่า Database"
echo "  3. ทดสอบ Redis Cache"
echo "  4. ชี้ Domain มาที่ VPS"
echo "═══════════════════════════════════════════════════════"
