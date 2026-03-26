#!/bin/bash
# AAPanel Server Setup Script
# รันบน VPS ที่ติดตั้ง aapanel แล้ว

echo "═══════════════════════════════════════════════════════"
echo "🚀 AAPanel Server Setup"
echo "═══════════════════════════════════════════════════════"
echo ""

# 1. ไปที่โฟลเดอร์เว็บ
cd /www/wwwroot

# 2. สร้างโฟลเดอร์สำหรับเว็บ
mkdir -p cny.re-ya.com
cd cny.re-ya.com

# 3. Clone โค้ดจาก GitHub
echo "📥 กำลังดาวน์โหลดโค้ด..."
git clone https://github.com/reyatelehealth2026-crypto/odoo.git .

# 4. ตั้งค่า permissions
echo "🔧 ตั้งค่า permissions..."
chown -R www:www /www/wwwroot/cny.re-ya.com
chmod -R 755 /www/wwwroot/cny.re-ya.com
chmod -R 777 uploads cache logs

# 5. สร้างไฟล์ทดสอบ PHP
echo "📝 สร้างไฟล์ทดสอบ..."
cat > info.php << 'EOF'
<?php
phpinfo();
?&gt;
EOF

# 6. แสดงผลลัพธ์
echo ""
echo "═══════════════════════════════════════════════════════"
echo "✅ Setup เสร็จแล้ว!"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "📂 โฟลเดอร์: /www/wwwroot/cny.re-ya.com"
echo "🌐 ทดสอบ: http://your-ip/cny.re-ya.com/info.php"
echo ""
echo "ขั้นตอนต่อไป:"
echo "  1. เข้า aapanel > Website > Add Site"
echo "  2. ใส่ Domain: cny.re-ya.com"
echo "  3. Path: /www/wwwroot/cny.re-ya.com"
echo "  4. PHP: 8.3"
echo "  5. สร้าง Database ผ่าน aapanel"
echo "  6. แก้ไข config/config.php"
echo "═══════════════════════════════════════════════════════"
