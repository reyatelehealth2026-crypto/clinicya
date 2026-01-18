# 📘 คู่มือ Setup ระบบ LINE Telepharmacy CRM - ฉบับสมบูรณ์

> เวอร์ชัน 2.5 | อัพเดทล่าสุด: มกราคม 2026

---

## 📑 สารบัญ

1. [ความต้องการของระบบ (Requirements)](#1-ความต้องการของระบบ)
2. [การติดตั้งระบบ (Installation)](#2-การติดตั้งระบบ)
3. [การตั้งค่า LINE Platform](#3-การตั้งค่า-line-platform)
4. [การตั้งค่า Database](#4-การตั้งค่า-database)
5. [การตั้งค่า WebSocket Server](#5-การตั้งค่า-websocket-server)
6. [การตั้งค่า Cron Jobs](#6-การตั้งค่า-cron-jobs)
7. [การตั้งค่า AI Services](#7-การตั้งค่า-ai-services)
8. [การตั้งค่า Admin Panel](#8-การตั้งค่า-admin-panel)
9. [การทดสอบระบบ](#9-การทดสอบระบบ)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. ความต้องการของระบบ

### 1.1 Server Requirements

#### ✅ ข้อกำหนดพื้นฐาน
- **PHP:** >= 7.4 (แนะนำ PHP 8.0+)
- **MySQL:** >= 5.7 หรือ MariaDB >= 10.2
- **Node.js:** >= 14.0 (สำหรับ WebSocket Server)
- **Web Server:** Apache 2.4+ หรือ Nginx 1.18+
- **SSL Certificate:** HTTPS (บังคับ - สำหรับ LINE Webhook และ LIFF)

#### ✅ PHP Extensions ที่จำเป็น
```bash
php -m | grep -E 'pdo|pdo_mysql|curl|json|mbstring|gd|zip|openssl'
```

Extensions ที่ต้องมี:
- ✓ PDO
- ✓ PDO_MySQL
- ✓ cURL
- ✓ JSON
- ✓ Mbstring
- ✓ GD (สำหรับจัดการรูปภาพ)
- ✓ Zip
- ✓ OpenSSL

#### ✅ PHP Configuration (php.ini)
```ini
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 300
max_input_time = 300
date.timezone = Asia/Bangkok
```

#### ✅ Server Resources (แนะนำ)
- **RAM:** ขั้นต่ำ 2GB (แนะนำ 4GB+)
- **Storage:** ขั้นต่ำ 10GB (สำหรับ database และไฟล์)
- **CPU:** 2 cores ขึ้นไป

---

## 2. การติดตั้งระบบ

### 2.1 วิธีที่ 1: ติดตั้งผ่าน Installation Wizard (แนะนำ)

#### ขั้นตอนที่ 1: อัพโหลดไฟล์
```bash
# 1. Upload ไฟล์ทั้งหมดไปยัง server
# ตัวอย่าง path: /var/www/html/v1/
# หรือ: /home/username/public_html/v1/
```

#### ขั้นตอนที่ 2: ตั้งค่า Permissions
```bash
cd /path/to/your/installation

# ตั้งค่า directory permissions
chmod 755 config/
chmod 755 uploads/
chmod -R 755 uploads/products/
chmod -R 755 uploads/slips/
chmod -R 755 uploads/prescriptions/
chmod 755 assets/

# ตั้งค่า file permissions
chmod 644 config/config.php
chmod 644 .htaccess

# สร้าง directories ที่จำเป็น
mkdir -p uploads/products uploads/slips uploads/prescriptions
mkdir -p uploads/profile_pictures uploads/documents
```

#### ขั้นตอนที่ 3: เปิด Installation Wizard
เปิดเบราว์เซอร์ไปที่:
```
https://yourdomain.com/v1/install/install_fresh.php
```

#### ขั้นตอนที่ 4: ทำตาม Wizard

**Screen 1: System Requirements Check**
- ตรวจสอบ PHP version
- ตรวจสอบ PHP extensions
- ตรวจสอบ permissions
- กด "Next" เมื่อทุกอย่างผ่าน

**Screen 2: Database Configuration**
```
Database Host: localhost
Database Name: telepharmacy
Database User: your_db_user
Database Password: your_db_password
Table Prefix: (ปล่อยว่างหรือใส่ prefix ถ้าต้องการ)
```

**Screen 3: Application Settings**
```
App Name: LINE Telepharmacy CRM
App URL: https://yourdomain.com
Base URL: https://yourdomain.com/v1
Timezone: Asia/Bangkok
```

**Screen 4: Admin Account**
```
Username: admin (หรือชื่อที่ต้องการ)
Email: admin@yourdomain.com
Password: (รหัสผ่านที่แข็งแรง)
Display Name: Administrator
```

**Screen 5: Installation**
- กด "Install Now"
- รอจนกว่าการติดตั้งเสร็จสมบูรณ์
- บันทึก Login credentials

#### ขั้นตอนที่ 5: ลบ Install Folder (สำคัญ!)
```bash
rm -rf /path/to/your/installation/install/
# หรือใช้ FTP ลบโฟลเดอร์ install
```

### 2.2 วิธีที่ 2: ติดตั้งแบบ Manual

#### ขั้นตอนที่ 1: สร้าง Database
```sql
-- เข้า MySQL
mysql -u root -p

-- สร้าง database
CREATE DATABASE telepharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- สร้าง user และให้สิทธิ์
CREATE USER 'telepharmacy_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON telepharmacy.* TO 'telepharmacy_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

#### ขั้นตอนที่ 2: Import Database Schema
```bash
cd /path/to/your/installation

# Import schema หลัก
mysql -u telepharmacy_user -p telepharmacy < database/schema_complete.sql

# Import migrations ที่จำเป็น (เรียงตามลำดับ)
mysql -u telepharmacy_user -p telepharmacy < database/migration_loyalty_points.sql
mysql -u telepharmacy_user -p telepharmacy < database/migration_inbox_chat.sql
mysql -u telepharmacy_user -p telepharmacy < database/migration_landing_page.sql
mysql -u telepharmacy_user -p telepharmacy < database/migration_health_articles.sql
mysql -u telepharmacy_user -p telepharmacy < database/migration_pos.sql
mysql -u telepharmacy_user -p telepharmacy < database/migration_multi_assignee.sql
mysql -u telepharmacy_user -p telepharmacy < database/migration_inbox_v2_performance.sql
```

#### ขั้นตอนที่ 3: แก้ไข config.php
```bash
cp config/config.sample.php config/config.php
nano config/config.php
```

แก้ไขค่าต่อไปนี้:
```php
<?php
// Database Credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'telepharmacy');
define('DB_USER', 'telepharmacy_user');
define('DB_PASS', 'strong_password_here');

// App Configuration
define('APP_NAME', 'LINE Telepharmacy CRM');
define('APP_URL', 'https://yourdomain.com');
define('BASE_URL', 'https://yourdomain.com/v1');
define('TIMEZONE', 'Asia/Bangkok');

// LINE API (จะตั้งค่าทีหลังผ่าน Admin Panel)
define('LINE_CHANNEL_ACCESS_TOKEN', '');
define('LINE_CHANNEL_SECRET', '');

// LIFF ID (จะตั้งค่าทีหลัง)
define('LIFF_SHARE_ID', '');

// OpenAI/Gemini AI (Optional)
define('OPENAI_API_KEY', '');
define('GEMINI_API_KEY', '');

// Telegram Notifications (Optional)
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID', '');
```

#### ขั้นตอนที่ 4: สร้าง Admin User
```sql
-- เข้า MySQL
mysql -u telepharmacy_user -p telepharmacy

-- สร้าง admin user (password: Admin@123)
INSERT INTO admin_users (username, email, password, display_name, role, status, created_at)
VALUES (
    'admin',
    'admin@yourdomain.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'System Administrator',
    'super_admin',
    'active',
    NOW()
);
```

---

## 3. การตั้งค่า LINE Platform

### 3.1 สร้าง LINE Official Account

1. **ไปที่ LINE Official Account Manager**
   - URL: https://manager.line.biz/
   - Login ด้วย LINE account

2. **สร้าง Official Account**
   - กด "Create Official Account"
   - เลือกประเภทบัญชี (Business หรือ Personal)
   - กรอกข้อมูลธุรกิจ
   - ยืนยันการสร้าง

3. **บันทึกข้อมูลสำคัญ**
   - Basic ID (เช่น @abc1234)
   - OA Manager URL

### 3.2 สร้าง LINE Developers Channel

1. **ไปที่ LINE Developers Console**
   - URL: https://developers.line.biz/console/
   - Login ด้วยบัญชีเดียวกับ OA Manager

2. **สร้าง Provider**
   - กด "Create a new provider"
   - ใส่ชื่อ Provider (เช่น "My Pharmacy")
   - กด "Create"

3. **สร้าง Messaging API Channel**
   - เลือก Provider ที่สร้างไว้
   - กด "Create a new channel"
   - เลือก "Messaging API"

4. **กรอกข้อมูล Channel**
   ```
   Channel type: Messaging API
   Provider: (เลือก Provider ที่สร้างไว้)
   Channel icon: (Upload รูป icon)
   Channel name: Telepharmacy Bot
   Channel description: ระบบร้านขายยา Telepharmacy
   Category: Medical
   Subcategory: Pharmacy
   Email address: admin@yourdomain.com
   ```

5. **เชื่อมโยงกับ LINE Official Account**
   - ใน Channel settings
   - ส่วน "Link a LINE Official Account"
   - เลือก OA ที่สร้างไว้

### 3.3 ตั้งค่า Messaging API

1. **เปิดหน้า Messaging API tab**

2. **ตั้งค่า Webhook**
   ```
   Webhook URL: https://yourdomain.com/v1/webhook.php?account=1
   Use webhook: Enabled
   Verify: กดปุ่ม "Verify" (ต้องได้ Success)
   ```

3. **ปิด Auto-reply**
   ```
   Auto-reply messages: Disabled
   Greeting messages: Disabled
   ```
   เหตุผล: เราจะใช้ระบบ Auto-reply ของ CRM แทน

4. **ดึง Channel Secret**
   - ใน "Basic settings" tab
   - คัดลอก "Channel secret"
   - เก็บไว้ในที่ปลอดภัย

5. **ดึง Channel Access Token**
   - ใน "Messaging API" tab
   - ส่วน "Channel access token (long-lived)"
   - กด "Issue" (ถ้ายังไม่มี)
   - คัดลอก Access Token
   - เก็บไว้ในที่ปลอดภัย

### 3.4 สร้าง LIFF App

#### 3.4.1 สร้าง LIFF หลัก (Main App)

1. **ใน Messaging API channel**
   - ไปที่ "LIFF" tab
   - กด "Add"

2. **ตั้งค่า LIFF**
   ```
   LIFF app name: Telepharmacy Main App
   Size: Full
   Endpoint URL: https://yourdomain.com/v1/liff/
   Scope: profile, openid, chat_message.write
   Bot link feature: On (Aggressive)
   Scan QR: On
   Module Mode: Off
   ```

3. **บันทึก LIFF ID**
   - คัดลอก LIFF ID (รูปแบบ: 1234567890-abcdefgh)

#### 3.4.2 สร้าง LIFF Share (สำหรับแชร์)

1. **สร้าง LIFF App ใหม่**
   ```
   LIFF app name: Telepharmacy Share
   Size: Compact
   Endpoint URL: https://yourdomain.com/v1/liff-share.php
   Scope: profile, openid
   Bot link feature: On
   ```

2. **บันทึก LIFF ID**

### 3.5 เพิ่ม LINE Account ใน Admin Panel

1. **Login เข้า Admin Panel**
   ```
   URL: https://yourdomain.com/v1/
   Username: admin
   Password: (ที่ตั้งไว้)
   ```

2. **ไปที่ LINE Accounts Management**
   - เมนู "Settings" > "LINE Accounts"
   - กด "Add New Account"

3. **กรอกข้อมูล**
   ```
   Account Name: Telepharmacy Bot
   Channel ID: (จาก LINE Developers)
   Channel Secret: (ที่คัดลอกไว้)
   Channel Access Token: (ที่คัดลอกไว้)
   LIFF ID (Main): (LIFF ID หลัก)
   LIFF ID (Share): (LIFF Share ID)
   Bot Basic ID: @abc1234
   Status: Active
   ```

4. **Test Connection**
   - กด "Test Connection"
   - ต้องได้ข้อความ "Connection successful"

---

## 4. การตั้งค่า Database

### 4.1 Database Structure Overview

ระบบใช้ MySQL/MariaDB สำหรับเก็บข้อมูล โครงสร้างหลัก:

```
📁 ตารางหลัก (Core Tables)
├── users                    # ผู้ใช้ LINE
├── members                  # สมาชิก (ข้อมูลเพิ่มเติม)
├── admin_users              # ผู้ดูแลระบบ
├── line_accounts            # บัญชี LINE OA
└── shop_settings            # ตั้งค่าร้านค้า

📁 CRM & Messaging
├── messages                 # ข้อความที่รับส่ง
├── broadcasts               # แคมเปญ Broadcast
├── auto_reply_rules         # กฎ Auto Reply
├── user_tags                # Tags สำหรับจัดกลุ่ม
└── drip_campaigns           # Drip Marketing

📁 E-commerce
├── products                 # สินค้า
├── orders                   # คำสั่งซื้อ
├── order_items              # รายการสินค้าในออเดอร์
├── cart_items               # ตะกร้าสินค้า
└── coupons                  # คูปองส่วนลด

📁 Loyalty & Points
├── points_transactions      # ประวัติแต้ม
├── points_rules             # กฎการให้แต้ม
├── rewards                  # รางวัล
└── redemptions              # การแลกรางวัล

📁 Health & Pharmacy
├── health_profiles          # ข้อมูลสุขภาพ
├── appointments             # การนัดหมาย
├── pharmacists              # เภสัชกร
├── medication_reminders     # เตือนทานยา
└── prescriptions            # ใบสั่งยา

📁 AI & Analytics
├── ai_chat_sessions         # Session แชท AI
├── ai_chat_messages         # ข้อความแชท AI
└── analytics_events         # Event tracking
```

### 4.2 Database Optimization

#### 4.2.1 สร้าง Indexes เพิ่มเติม (ถ้ายังไม่มี)

```sql
-- Performance indexes for inbox
ALTER TABLE messages
  ADD INDEX idx_unread_filter (line_account_id, is_from_user, is_read, created_at),
  ADD INDEX idx_search (line_account_id, message_text(100)),
  ADD INDEX idx_user_latest (line_user_id, created_at DESC);

-- Optimize users table
ALTER TABLE users
  ADD INDEX idx_account_status (line_account_id, is_blocked, last_interaction_at);

-- Optimize orders
ALTER TABLE orders
  ADD INDEX idx_status_date (status, created_at),
  ADD INDEX idx_user_orders (user_id, created_at DESC);
```

#### 4.2.2 Database Maintenance Script

สร้างไฟล์ `database/maintenance.sql`:
```sql
-- ทำความสะอาดข้อมูลเก่า (รันทุก 1 เดือน)

-- ลบข้อความเก่าเกิน 6 เดือน (ถ้าไม่จำเป็น)
DELETE FROM messages
WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
  AND is_important = 0;

-- ลบ session แชท AI เก่าเกิน 3 เดือน
DELETE FROM ai_chat_sessions
WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH);

-- ลบ analytics events เก่าเกิน 1 ปี
DELETE FROM analytics_events
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Optimize tables
OPTIMIZE TABLE messages;
OPTIMIZE TABLE users;
OPTIMIZE TABLE orders;
OPTIMIZE TABLE ai_chat_sessions;
```

### 4.3 Database Backup

#### 4.3.1 Manual Backup
```bash
# Backup ทั้ง database
mysqldump -u telepharmacy_user -p telepharmacy > backup_$(date +%Y%m%d).sql

# Backup แบบ gzip
mysqldump -u telepharmacy_user -p telepharmacy | gzip > backup_$(date +%Y%m%d).sql.gz

# Restore
mysql -u telepharmacy_user -p telepharmacy < backup_20260119.sql
```

#### 4.3.2 Auto Backup Script

สร้างไฟล์ `/path/to/scripts/db_backup.sh`:
```bash
#!/bin/bash

# Configuration
DB_USER="telepharmacy_user"
DB_PASS="your_password"
DB_NAME="telepharmacy"
BACKUP_DIR="/var/backups/mysql"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/backup_$DATE.sql.gz

# Keep only last 30 days
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +30 -delete

echo "Backup completed: backup_$DATE.sql.gz"
```

ตั้งค่า Cron:
```bash
chmod +x /path/to/scripts/db_backup.sh

# เพิ่มใน crontab (backup ทุกวันเวลา 02:00)
crontab -e
0 2 * * * /path/to/scripts/db_backup.sh >> /var/log/db_backup.log 2>&1
```

---

## 5. การตั้งค่า WebSocket Server

### 5.1 ติดตั้ง Node.js และ Dependencies

```bash
# ตรวจสอบ Node.js
node -v  # ควรได้ v14.0 ขึ้นไป
npm -v

# ถ้ายังไม่มี ติดตั้ง Node.js (Ubuntu/Debian)
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# ไปที่ project directory
cd /path/to/your/installation

# ติดตั้ง dependencies
npm install
```

### 5.2 ตั้งค่า Environment Variables

```bash
# คัดลอก .env.example
cp .env.example .env

# แก้ไข .env
nano .env
```

แก้ไขค่าต่อไปนี้:
```env
NODE_ENV=production
WEBSOCKET_PORT=3000
WEBSOCKET_HOST=0.0.0.0

ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com

DB_HOST=localhost
DB_USER=telepharmacy_user
DB_PASSWORD=your_db_password
DB_NAME=telepharmacy

REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=

SESSION_SECRET=your_random_secret_here_change_this
```

### 5.3 ติดตั้ง PM2 (Process Manager)

```bash
# ติดตั้ง PM2 globally
sudo npm install -g pm2

# เริ่ม WebSocket Server
pm2 start websocket-server.js --name telepharmacy-ws

# ตั้งให้รันตอน boot
pm2 startup
pm2 save

# ดู status
pm2 status

# ดู logs
pm2 logs telepharmacy-ws

# Restart
pm2 restart telepharmacy-ws
```

### 5.4 ตั้งค่า Nginx Reverse Proxy (ถ้าใช้ Nginx)

เพิ่มใน Nginx config:
```nginx
# WebSocket proxy
location /ws {
    proxy_pass http://localhost:3000;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # Timeout settings
    proxy_connect_timeout 7d;
    proxy_send_timeout 7d;
    proxy_read_timeout 7d;
}
```

Reload Nginx:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## 6. การตั้งค่า Cron Jobs

### 6.1 Cron Jobs ที่จำเป็น

สร้างไฟล์ `/path/to/scripts/setup_cron.sh`:
```bash
#!/bin/bash

# Cron jobs for LINE Telepharmacy CRM
PROJECT_PATH="/path/to/your/installation"

# เพิ่ม cron jobs
(crontab -l 2>/dev/null; echo "
# LINE Telepharmacy CRM Cron Jobs

# Medication Reminders (ทุก 15 นาที)
*/15 * * * * php $PROJECT_PATH/cron/medication_reminder.php >> /var/log/cron_medication.log 2>&1

# Medication Refill Reminders (ทุกวัน 09:00)
0 9 * * * php $PROJECT_PATH/cron/medication_refill_reminder.php >> /var/log/cron_refill.log 2>&1

# Appointment Reminders (ทุก 30 นาที)
*/30 * * * * php $PROJECT_PATH/cron/appointment_reminder.php >> /var/log/cron_appointment.log 2>&1

# Reward Expiry Reminders (ทุกวัน 10:00)
0 10 * * * php $PROJECT_PATH/cron/reward_expiry_reminder.php >> /var/log/cron_reward.log 2>&1

# Restock Notifications (ทุกวัน 08:00)
0 8 * * * php $PROJECT_PATH/cron/restock_notification.php >> /var/log/cron_restock.log 2>&1

# Wishlist Notifications (ทุกวัน 11:00)
0 11 * * * php $PROJECT_PATH/cron/wishlist_notification.php >> /var/log/cron_wishlist.log 2>&1

# Process Broadcast Queue (ทุก 5 นาที)
*/5 * * * * php $PROJECT_PATH/cron/process_broadcast_queue.php >> /var/log/cron_broadcast.log 2>&1

# Process Drip Campaigns (ทุก 10 นาที)
*/10 * * * * php $PROJECT_PATH/cron/process_drip_campaigns.php >> /var/log/cron_drip.log 2>&1

# Check Inactive Users (ทุกวัน 14:00)
0 14 * * * php $PROJECT_PATH/cron/check_inactive_users.php >> /var/log/cron_inactive.log 2>&1

# Scheduled Reports (ทุกวัน 07:00)
0 7 * * * php $PROJECT_PATH/cron/scheduled_reports.php >> /var/log/cron_reports.log 2>&1

# Sync Worker - CNY API (ทุก 1 นาที)
* * * * * php $PROJECT_PATH/cron/sync_worker.php >> /var/log/cron_sync.log 2>&1

# Database Backup (ทุกวัน 02:00)
0 2 * * * /path/to/scripts/db_backup.sh >> /var/log/db_backup.log 2>&1

# Database Maintenance (ทุกเดือน วันที่ 1 เวลา 03:00)
0 3 1 * * mysql -u telepharmacy_user -pyour_password telepharmacy < $PROJECT_PATH/database/maintenance.sql >> /var/log/db_maintenance.log 2>&1
") | crontab -

echo "Cron jobs installed successfully!"
```

รัน script:
```bash
chmod +x /path/to/scripts/setup_cron.sh
/path/to/scripts/setup_cron.sh
```

### 6.2 ตรวจสอบ Cron Jobs

```bash
# ดู cron jobs ทั้งหมด
crontab -l

# ดู logs
tail -f /var/log/cron_medication.log
tail -f /var/log/cron_broadcast.log
```

---

## 7. การตั้งค่า AI Services

### 7.1 Google Gemini AI

1. **สร้าง API Key**
   - ไปที่: https://makersuite.google.com/app/apikey
   - สร้าง API Key ใหม่
   - คัดลอก API Key

2. **เพิ่มใน config.php**
   ```php
   define('GEMINI_API_KEY', 'your_gemini_api_key_here');
   ```

3. **ตั้งค่าใน Admin Panel**
   - ไปที่ "Settings" > "AI Settings"
   - เลือก "Google Gemini"
   - วาง API Key
   - เลือก Model: gemini-2.0-flash-exp (แนะนำ)
   - Test Connection

### 7.2 OpenAI (Alternative)

1. **สร้าง API Key**
   - ไปที่: https://platform.openai.com/api-keys
   - สร้าง API Key
   - คัดลอก

2. **เพิ่มใน config.php**
   ```php
   define('OPENAI_API_KEY', 'sk-...');
   ```

3. **ตั้งค่าใน Admin Panel**
   - "Settings" > "AI Settings"
   - เลือก "OpenAI"
   - วาง API Key
   - เลือก Model: gpt-4-turbo หรือ gpt-3.5-turbo

### 7.3 ตั้งค่า AI Pharmacy System

1. **ไปที่ Admin Panel** > "AI Settings" > "Pharmacy AI"

2. **ตั้งค่า Safety Rules**
   ```
   ✓ Enable Drug Interaction Check
   ✓ Enable Red Flag Detection
   ✓ Check Drug Allergies
   ✓ Require Prescription for Controlled Drugs
   ```

3. **ตั้งค่า Red Flags (อาการอันตราย)**
   - อาการแพ้รุนแรง
   - เลือดออก
   - หายใจลำบาก
   - เจ็บหน้าอกรุนแรง
   - (เพิ่มเติมตามต้องการ)

4. **ตั้งค่า Notification**
   - แจ้งเภสัชกรผ่าน Telegram เมื่อเจอ Red Flag
   - แจ้งเตือนเมื่อมีการสั่งยาที่มี interaction

---

## 8. การตั้งค่า Admin Panel

### 8.1 Login และตั้งค่าพื้นฐาน

```
URL: https://yourdomain.com/v1/
Username: admin
Password: (ที่ตั้งไว้)
```

### 8.2 Shop Settings

1. **ไปที่ "Settings" > "Shop Settings"**

2. **กรอกข้อมูลร้าน**
   ```
   Shop Name: ร้านขายยา ABC
   Shop Logo: (Upload logo)
   Shop Description: ร้านขายยาครบวงจร บริการส่งถึงบ้าน
   Phone: 02-xxx-xxxx
   Email: contact@yourdomain.com
   Address: ที่อยู่ร้าน

   Business Hours:
   จันทร์-ศุกร์: 08:00-20:00
   เสาร์-อาทิตย์: 09:00-18:00
   ```

3. **ตั้งค่า Payment Methods**
   ```
   ✓ PromptPay (ระบุ QR Code)
   ✓ โอนเงิน (ระบุเลขบัญชี)
   ✓ เก็บเงินปลายทาง (COD)
   ```

4. **ตั้งค่า Shipping**
   ```
   ✓ จัดส่งฟรี เมื่อซื้อครบ 500 บาท
   ค่าจัดส่ง: 50 บาท
   ```

### 8.3 Rich Menu Setup

1. **ไปที่ "LINE" > "Rich Menu"**

2. **สร้าง Rich Menu**
   - ขนาด: 2500 x 1686 (แนะนำ)
   - แบ่งพื้นที่: 6 ช่อง (2x3)

3. **กำหนด Actions**
   ```
   ช่องที่ 1: หน้าหลัก -> LIFF URL
   ช่องที่ 2: ร้านค้า -> LIFF URL + /shop
   ช่องที่ 3: ตะกร้า -> LIFF URL + /cart
   ช่องที่ 4: ออเดอร์ -> LIFF URL + /orders
   ช่องที่ 5: AI ผู้ช่วย -> LIFF URL + /ai-assistant
   ช่องที่ 6: โปรไฟล์ -> LIFF URL + /profile
   ```

4. **Set as Default Rich Menu**

### 8.4 Welcome Message

1. **ไปที่ "CRM" > "Welcome Settings"**

2. **ตั้งค่าข้อความต้อนรับ**
   - ประเภท: Flex Message (แนะนำ)
   - ใส่ข้อความต้อนรับ
   - เพิ่มปุ่ม Quick Actions

3. **Auto Tag**
   - ✓ ติด Tag "new_follower" อัตโนมัติ

### 8.5 Auto Reply Rules

1. **ไปที่ "CRM" > "Auto Reply"**

2. **สร้างกฎพื้นฐาน**

**กฎที่ 1: ราคา**
```
Keywords: ราคา, เท่าไหร่, เท่าไร
Match Type: Contains
Response: กรุณาระบุชื่อสินค้าที่ต้องการสอบถาม หรือเปิดดูสินค้าได้ที่ร้านค้าเลยค่ะ
```

**กฎที่ 2: โปรโมชั่น**
```
Keywords: โปร, ลด, แถม
Match Type: Contains
Response: ดูโปรโมชั่นล่าสุดได้ที่เมนู "โปรโมชั่น" ค่ะ
```

**กฎที่ 3: ติดต่อ**
```
Keywords: ติดต่อ, โทร, เบอร์
Match Type: Contains
Response: ติดต่อเราได้ที่ 02-xxx-xxxx หรือแชทที่นี่ได้เลยค่ะ
```

---

## 9. การทดสอบระบบ

### 9.1 Test Checklist

#### ✅ ทดสอบ LINE Integration

1. **Add Friend**
   - เพิ่มเพื่อน LINE OA
   - ✓ ได้รับ Welcome Message
   - ✓ มี Rich Menu แสดง
   - ✓ ข้อมูล user บันทึกใน database

2. **Webhook Test**
   - ส่งข้อความ "สวัสดี"
   - ✓ ระบบตอบกลับ (ถ้าตั้ง Auto Reply)
   - ✓ ข้อความแสดงใน Admin > Messages

3. **LIFF Test**
   - กด Rich Menu เปิด LIFF
   - ✓ LIFF เปิดได้ไม่ error
   - ✓ แสดงข้อมูล profile ถูกต้อง
   - ✓ Navigation ทำงานได้

#### ✅ ทดสอบ E-commerce

1. **Product Display**
   - เปิดหน้าร้านค้า
   - ✓ สินค้าแสดงครบ
   - ✓ รูปภาพโหลดได้
   - ✓ ราคาถูกต้อง

2. **Add to Cart**
   - เพิ่มสินค้าลงตะกร้า
   - ✓ จำนวนอัพเดท
   - ✓ ราคารวมคำนวณถูกต้อง

3. **Checkout Flow**
   - กรอกข้อมูลจัดส่ง
   - เลือกวิธีชำระเงิน
   - ยืนยันคำสั่งซื้อ
   - ✓ สร้าง Order สำเร็จ
   - ✓ ได้รับ LINE notification
   - ✓ Order แสดงใน Admin Panel

#### ✅ ทดสอบ AI Chat

1. **AI Response**
   - ส่งข้อความ "@ai ปวดหัว"
   - ✓ AI ตอบกลับได้
   - ✓ มีการแนะนำสินค้า (ถ้าเปิดใช้)

2. **Red Flag Detection**
   - ส่ง "@ai เจ็บหน้าอกรุนแรง"
   - ✓ ระบบตรวจจับ Red Flag
   - ✓ แนะนำให้ไปพบแพทย์
   - ✓ แจ้งเตือนเภสัชกร (ถ้าตั้งค่า Telegram)

#### ✅ ทดสอบ Loyalty Points

1. **Register Member**
   - สมัครสมาชิกผ่าน LIFF
   - ✓ บันทึกข้อมูลสำเร็จ
   - ✓ ได้รับ Welcome Points

2. **Earn Points**
   - ทำการสั่งซื้อ
   - ✓ ได้รับแต้มตามกฎ
   - ✓ แสดงใน Points History

3. **Redeem Points**
   - แลกรางวัล
   - ✓ หักแต้มได้
   - ✓ บันทึก Redemption

### 9.2 Performance Testing

#### ตรวจสอบ Page Load Time
```bash
# ใช้ curl วัดเวลา
curl -o /dev/null -s -w 'Total: %{time_total}s\n' https://yourdomain.com/v1/liff/

# ควรได้ < 2 วินาที
```

#### ตรวจสอบ Database Queries
- เปิด Debug Mode ใน config.php
- ดูจำนวน queries ต่อ page
- ควรไม่เกิน 20-30 queries

#### ตรวจสอบ Memory Usage
```bash
# ดู PHP memory usage
ps aux | grep php-fpm

# ตรวจสอบ MySQL
mysqladmin -u root -p processlist
```

---

## 10. Troubleshooting

### 10.1 LINE Webhook Issues

**ปัญหา: Webhook ไม่ทำงาน**

```bash
# 1. ตรวจสอบ webhook.php error log
tail -f /var/log/nginx/error.log
# หรือ
tail -f /var/log/apache2/error.log

# 2. ตรวจสอบ SSL Certificate
curl -I https://yourdomain.com/v1/webhook.php

# 3. Test webhook manually
curl -X POST https://yourdomain.com/v1/webhook.php?account=1 \
  -H "Content-Type: application/json" \
  -d '{"events":[]}'
```

**ปัญหา: Signature Validation Failed**
- ตรวจสอบ Channel Secret ใน database ตรงกับใน LINE Developers
- ตรวจสอบไม่มีช่องว่างหรืออักขระพิเศษใน Secret

### 10.2 LIFF Issues

**ปัญหา: LIFF ไม่เปิด / Error 400**

1. ตรวจสอบ LIFF Endpoint URL
   - ต้องเป็น HTTPS
   - ต้องตรงกับที่ตั้งใน LINE Developers

2. ตรวจสอบ liff.init()
   ```javascript
   // ใน liff/assets/js/liff-app.js
   console.log('LIFF ID:', APP_CONFIG.liffId);
   ```

3. Clear LIFF Cache
   - ปิดแชท LINE
   - ล้าง cache LINE app
   - เปิดใหม่

**ปัญหา: liff.getProfile() ไม่ได้ข้อมูล**
- ตรวจสอบ Scope: ต้องมี "profile"
- ตรวจสอบ user login แล้ว: `liff.isLoggedIn()`

### 10.3 Database Issues

**ปัญหา: Connection Failed**

```bash
# ทดสอบ connection
mysql -u telepharmacy_user -p -h localhost telepharmacy

# ตรวจสอบ MySQL running
sudo systemctl status mysql

# ตรวจสอบ credentials ใน config.php
cat config/config.php | grep DB_
```

**ปัญหา: Table doesn't exist**
```bash
# Import schema อีกครั้ง
mysql -u telepharmacy_user -p telepharmacy < database/schema_complete.sql

# ตรวจสอบ tables
mysql -u telepharmacy_user -p telepharmacy -e "SHOW TABLES;"
```

### 10.4 Permission Issues

**ปัญหา: Cannot upload files**

```bash
# ตรวจสอบ permissions
ls -la uploads/

# แก้ไข permissions
chmod -R 755 uploads/
chown -R www-data:www-data uploads/  # Ubuntu/Debian
# หรือ
chown -R apache:apache uploads/      # CentOS/RHEL
```

**ปัญหา: Config file not writable**
```bash
chmod 644 config/config.php
chown www-data:www-data config/config.php
```

### 10.5 WebSocket Issues

**ปัญหา: WebSocket Connection Failed**

```bash
# ตรวจสอบ PM2 status
pm2 status

# ดู logs
pm2 logs telepharmacy-ws --lines 100

# Restart
pm2 restart telepharmacy-ws

# ตรวจสอบ port เปิดอยู่
netstat -tulpn | grep 3000
```

**ปัญหา: CORS Error**
- ตรวจสอบ ALLOWED_ORIGINS ใน .env
- ตรวจสอบ Nginx/Apache config

### 10.6 Cron Jobs Issues

**ปัญหา: Cron ไม่ทำงาน**

```bash
# ตรวจสอบ cron service
sudo systemctl status cron

# ดู cron logs
grep CRON /var/log/syslog

# ทดสอบ manual run
php /path/to/cron/medication_reminder.php

# ตรวจสอบ permissions
chmod +x /path/to/cron/*.php
```

### 10.7 AI Issues

**ปัญหา: AI ไม่ตอบ / Error**

```bash
# ตรวจสอบ API Key
# ใน Admin Panel > AI Settings > Test Connection

# ตรวจสอบ error log
tail -f /var/log/nginx/error.log | grep -i gemini

# ทดสอบ API directly
curl https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=YOUR_KEY
```

### 10.8 Common Errors

**Error: "Class 'PDO' not found"**
```bash
# ติดตั้ง PHP PDO extension
sudo apt-get install php-mysql php-pdo
sudo systemctl restart apache2
```

**Error: "Call to undefined function curl_init()"**
```bash
# ติดตั้ง cURL extension
sudo apt-get install php-curl
sudo systemctl restart apache2
```

**Error: "Maximum execution time exceeded"**
```bash
# แก้ไข php.ini
sudo nano /etc/php/8.0/apache2/php.ini
# เปลี่ยน: max_execution_time = 300

sudo systemctl restart apache2
```

---

## 📞 Support & Resources

### Documentation
- 📖 User Manual: `USER_MANUAL.md`
- 🏗️ Architecture: `PROJECT_FLOW_DOCUMENTATION.md`
- 🚀 Deployment: `install/DEPLOY_GUIDE.md`

### LINE Platform
- 📱 LINE Developers: https://developers.line.biz/
- 📚 LINE Messaging API Docs: https://developers.line.biz/en/docs/messaging-api/
- 📘 LIFF Docs: https://developers.line.biz/en/docs/liff/

### Community
- 💬 LINE Developers Community: https://www.line-community.me/

---

## ✅ Post-Installation Checklist

หลังติดตั้งเสร็จ ตรวจสอบรายการต่อไปนี้:

- [ ] Database ติดตั้งสำเร็จ ทุก tables ครบ
- [ ] Admin user login ได้
- [ ] LINE Webhook ทำงานได้ (Verify success)
- [ ] LIFF App เปิดได้ไม่ error
- [ ] Rich Menu แสดงใน LINE
- [ ] สามารถเพิ่ม Product ใน Admin ได้
- [ ] สามารถสั่งซื้อผ่าน LIFF ได้
- [ ] Loyalty Points ทำงานได้
- [ ] AI Chat ตอบได้
- [ ] WebSocket Server running
- [ ] Cron Jobs ตั้งค่าแล้ว
- [ ] SSL Certificate ใช้งานได้
- [ ] Backup script ทำงานได้
- [ ] ลบ /install folder แล้ว

---

## 🔒 Security Checklist

- [ ] เปลี่ยน default admin password
- [ ] ตั้งค่า strong password สำหรับ database
- [ ] เปิดใช้ HTTPS (SSL)
- [ ] ปิด directory listing
- [ ] ตั้งค่า file permissions ถูกต้อง
- [ ] เปิด error_log แต่ปิด display_errors
- [ ] Backup database อัตโนมัติ
- [ ] ติดตั้ง fail2ban (ป้องกัน brute force)
- [ ] Update PHP และ MySQL เป็น version ล่าสุด

---

**🎉 ขอให้ติดตั้งสำเร็จ!**

หากมีปัญหาหรือข้อสงสัย กรุณาตรวจสอบส่วน Troubleshooting หรืออ่าน documentation เพิ่มเติม
