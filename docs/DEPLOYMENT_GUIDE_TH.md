# คู่มือการ Deploy ระบบ Odoo Dashboard สมัยใหม่

## สารบัญ

1. [ภาพรวมการ Deploy](#1-ภาพรวมการ-deploy)
2. [ความต้องการของระบบ](#2-ความต้องการของระบบ)
3. [การเตรียมสภาพแวดล้อม](#3-การเตรียมสภาพแวดล้อม)
4. [การติดตั้งและตั้งค่า](#4-การติดตั้งและตั้งค่า)
5. [การ Deploy แบบ Development](#5-การ-deploy-แบบ-development)
6. [การ Deploy แบบ Production](#6-การ-deploy-แบบ-production)
7. [การ Migration จากระบบเดิม](#7-การ-migration-จากระบบเดิม)
8. [การตรวจสอบและ Monitoring](#8-การตรวจสอบและ-monitoring)
9. [การแก้ไขปัญหา](#9-การแก้ไขปัญหา)
10. [การ Rollback](#10-การ-rollback)

---

## 1. ภาพรวมการ Deploy

### 1.1 สถาปัตยกรรมระบบ

ระบบ LINE Telepharmacy CRM ประกอบด้วย:

```
┌─────────────────────────────────────────────────────────┐
│                    Load Balancer (Nginx)                 │
└─────────────────────────────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
┌───────▼────────┐  ┌──────▼───────┐  ┌───────▼────────┐
│   Frontend     │  │   Backend    │  │   WebSocket    │
│   (Next.js)    │  │   (Node.js)  │  │   Server       │
│   Port: 3000   │  │   Port: 4000 │  │   Port: 3001   │
└────────────────┘  └──────────────┘  └────────────────┘
        │                   │                   │
        └───────────────────┼───────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
┌───────▼────────┐  ┌──────▼───────┐  ┌───────▼────────┐
│     MySQL      │  │     Redis    │  │   File Storage │
│   Port: 3306   │  │   Port: 6379 │  │   (uploads/)   │
└────────────────┘  └──────────────┘  └────────────────┘
```

### 1.2 กลยุทธ์การ Deploy

ระบบใช้กลยุทธ์ **Blue-Green Deployment** เพื่อ:
- Deploy โดยไม่มี downtime
- สามารถ rollback ได้ทันที
- ทดสอบระบบใหม่ก่อนเปลี่ยน traffic

---

## 2. ความต้องการของระบบ

### 2.1 Hardware Requirements

**สำหรับ Development:**
- CPU: 2 cores ขึ้นไป
- RAM: 4 GB ขึ้นไป
- Disk: 20 GB ว่าง
- Network: 10 Mbps

**สำหรับ Production:**
- CPU: 4 cores ขึ้นไป (แนะนำ 8 cores)
- RAM: 8 GB ขึ้นไป (แนะนำ 16 GB)
- Disk: 100 GB ว่าง (SSD แนะนำ)
- Network: 100 Mbps ขึ้นไป

### 2.2 Software Requirements

**ระบบปฏิบัติการ:**
- Ubuntu 20.04 LTS หรือใหม่กว่า
- CentOS 8 หรือใหม่กว่า
- Debian 11 หรือใหม่กว่า

**Software ที่ต้องติดตั้ง:**
- Docker 20.10+ และ Docker Compose 2.0+
- Node.js 18+ (สำหรับ development)
- MySQL 8.0+ หรือ MariaDB 10.6+
- Redis 7.0+
- Nginx 1.20+
- Git 2.30+

---

## 3. การเตรียมสภาพแวดล้อม

### 3.1 ติดตั้ง Docker และ Docker Compose

**Ubuntu/Debian:**
```bash
# อัพเดท package list
sudo apt update

# ติดตั้ง dependencies
sudo apt install -y apt-transport-https ca-certificates curl software-properties-common

# เพิ่ม Docker GPG key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg

# เพิ่ม Docker repository
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# ติดตั้ง Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# เพิ่ม user เข้า docker group
sudo usermod -aG docker $USER

# ตรวจสอบการติดตั้ง
docker --version
docker compose version
```

### 3.2 Clone Repository

```bash
# Clone โปรเจค
git clone https://github.com/your-org/telepharmacy-crm.git
cd telepharmacy-crm

# สร้าง branch สำหรับ production
git checkout -b production
```

### 3.3 ตั้งค่า Environment Variables

**สร้างไฟล์ `.env` สำหรับ Production:**

```bash
# คัดลอกจาก template
cp .env.prod.example .env

# แก้ไขค่าต่างๆ
nano .env
```

**ตัวอย่างไฟล์ `.env`:**

```bash
# ===== General Settings =====
NODE_ENV=production
APP_NAME="LINE Telepharmacy CRM"
APP_URL=https://yourdomain.com
TIMEZONE=Asia/Bangkok

# ===== Database Configuration =====
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=telepharmacy
DB_USERNAME=pharmacy_user
DB_PASSWORD=your_secure_password_here
DB_ROOT_PASSWORD=your_root_password_here

# ===== Redis Configuration =====
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=your_redis_password_here

# ===== LINE Integration =====
LINE_CHANNEL_ACCESS_TOKEN=your_line_channel_access_token
LINE_CHANNEL_SECRET=your_line_channel_secret

# ===== AI Services =====
GEMINI_API_KEY=your_gemini_api_key
OPENAI_API_KEY=your_openai_api_key

# ===== File Upload =====
UPLOAD_MAX_SIZE=10485760
UPLOAD_ALLOWED_TYPES=image/jpeg,image/png
UPLOAD_PATH=/app/uploads

# ===== SSL/TLS =====
SSL_CERT_PATH=/etc/nginx/ssl/cert.pem
SSL_KEY_PATH=/etc/nginx/ssl/key.pem
```

### 3.4 สร้าง SSL Certificate

**ใช้ Let's Encrypt (แนะนำ):**

```bash
# ติดตั้ง certbot
sudo apt install -y certbot python3-certbot-nginx

# สร้าง certificate
sudo certbot certonly --nginx -d dashboard.yourdomain.com

# Certificate จะถูกสร้างที่:
# /etc/letsencrypt/live/dashboard.yourdomain.com/fullchain.pem
# /etc/letsencrypt/live/dashboard.yourdomain.com/privkey.pem
```

**หรือใช้ Self-Signed Certificate (สำหรับ testing):**

```bash
# สร้าง directory สำหรับ SSL
mkdir -p docker/nginx/ssl

# สร้าง self-signed certificate
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout docker/nginx/ssl/key.pem \
  -out docker/nginx/ssl/cert.pem \
  -subj "/C=TH/ST=Bangkok/L=Bangkok/O=YourCompany/CN=dashboard.yourdomain.com"
```

---

## 4. การติดตั้งและตั้งค่า

### 4.1 Build Docker Images

```bash
# Build ทุก services
docker compose -f docker-compose.prod.yml build

# หรือ build แยกแต่ละ service
docker compose -f docker-compose.prod.yml build frontend
docker compose -f docker-compose.prod.yml build backend
docker compose -f docker-compose.prod.yml build websocket
```

### 4.2 ตั้งค่า Database

**สร้าง Database และ User:**

```bash
# Start MySQL container
docker compose -f docker-compose.prod.yml up -d mysql

# รอให้ MySQL พร้อม
sleep 30

# เข้าสู่ MySQL container
docker compose -f docker-compose.prod.yml exec mysql mysql -uroot -p

# รัน SQL commands
CREATE DATABASE IF NOT EXISTS telepharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'odoo_user'@'%' IDENTIFIED BY 'your_secure_password_here';
GRANT ALL PRIVILEGES ON telepharmacy.* TO 'odoo_user'@'%';
FLUSH PRIVILEGES;
EXIT;
```

**รัน Database Migrations:**

```bash
# เข้าสู่ backend container
docker compose -f docker-compose.prod.yml run --rm backend sh

# รัน Prisma migrations
npm run prisma:migrate

# Seed ข้อมูลเริ่มต้น (ถ้าต้องการ)
npm run prisma:seed

# ออกจาก container
exit
```

**Import ข้อมูลจากระบบเดิม (ถ้ามี):**

```bash
# คัดลอกไฟล์ backup เข้า container
docker cp backup.sql mysql_container:/tmp/

# Import ข้อมูล
docker compose -f docker-compose.prod.yml exec mysql \
  mysql -uodoo_user -p telepharmacy < /tmp/backup.sql
```

### 4.3 ตั้งค่า Redis

```bash
# Start Redis container
docker compose -f docker-compose.prod.yml up -d redis

# ทดสอบการเชื่อมต่อ
docker compose -f docker-compose.prod.yml exec redis redis-cli ping
# ควรได้ PONG

# ตั้งค่า password (ถ้ายังไม่ได้ตั้ง)
docker compose -f docker-compose.prod.yml exec redis redis-cli
> CONFIG SET requirepass "your_redis_password_here"
> AUTH your_redis_password_here
> CONFIG REWRITE
> EXIT
```

---

## 5. การ Deploy แบบ Development

### 5.1 Start Development Environment

```bash
# Start ทุก services
docker compose up -d

# ดู logs
docker compose logs -f

# ตรวจสอบ status
docker compose ps
```

### 5.2 เข้าถึงระบบ

- **Frontend**: http://localhost:3000
- **Backend API**: http://localhost:4000
- **WebSocket**: ws://localhost:3001
- **MySQL**: localhost:3306
- **Redis**: localhost:6379

### 5.3 Development Commands

```bash
# Restart service เดียว
docker compose restart backend

# ดู logs ของ service เดียว
docker compose logs -f frontend

# เข้าสู่ container
docker compose exec backend sh

# Stop ทุก services
docker compose down

# Stop และลบ volumes
docker compose down -v
```

---

## 6. การ Deploy แบบ Production

### 6.1 Pre-Deployment Checklist

ก่อน deploy ให้ตรวจสอบ:

- ✅ ไฟล์ `.env` ตั้งค่าครบถ้วน
- ✅ SSL Certificate พร้อมใช้งาน
- ✅ Database backup ล่าสุด
- ✅ DNS ชี้ไปที่ server ถูกต้อง
- ✅ Firewall เปิด ports ที่จำเป็น (80, 443, 3306, 6379)
- ✅ รัน tests ผ่านหมด
- ✅ ทีมพร้อมสำหรับ deployment

### 6.2 การ Deploy ครั้งแรก

**ขั้นตอนที่ 1: เตรียม Infrastructure**

```bash
# สร้าง network
docker network create odoo-dashboard-network

# สร้าง volumes
docker volume create mysql_data
docker volume create redis_data
docker volume create uploads_data
```

**ขั้นตอนที่ 2: Deploy Database และ Cache**

```bash
# Start MySQL และ Redis
docker compose -f docker-compose.prod.yml up -d mysql redis

# รอให้พร้อม
sleep 30

# ตรวจสอบ health
docker compose -f docker-compose.prod.yml ps
```

**ขั้นตอนที่ 3: รัน Database Migrations**

```bash
# รัน migrations
docker compose -f docker-compose.prod.yml run --rm backend npm run prisma:migrate

# Validate schema
docker compose -f docker-compose.prod.yml run --rm backend npm run prisma:validate
```

**ขั้นตอนที่ 4: Deploy Application Services**

```bash
# Start backend, frontend, websocket
docker compose -f docker-compose.prod.yml up -d backend frontend websocket

# ตรวจสอบ logs
docker compose -f docker-compose.prod.yml logs -f
```

**ขั้นตอนที่ 5: Deploy Nginx Load Balancer**

```bash
# Start nginx
docker compose -f docker-compose.prod.yml up -d nginx

# ทดสอบ configuration
docker compose -f docker-compose.prod.yml exec nginx nginx -t

# Reload nginx
docker compose -f docker-compose.prod.yml exec nginx nginx -s reload
```

**ขั้นตอนที่ 6: ตั้งค่า Monitoring**

```bash
# Start monitoring stack
docker compose -f docker/monitoring/docker-compose.monitoring.yml up -d

# เข้าถึง Grafana
# URL: https://dashboard.yourdomain.com:3000
# Username: admin
# Password: ตามที่ตั้งใน .env
```

### 6.3 การ Deploy แบบ Blue-Green

**ขั้นตอนที่ 1: Deploy Green Environment**

```bash
# Deploy green environment
bash docker/scripts/blue-green-deploy.sh green

# ระบบจะ:
# 1. Build images ใหม่
# 2. Start green containers
# 3. รัน health checks
# 4. รอการยืนยัน
```

**ขั้นตอนที่ 2: ทดสอบ Green Environment**

```bash
# ทดสอบผ่าน internal port
curl http://localhost:8080/health

# ทดสอบ API endpoints
bash docker/scripts/smoke-tests.sh http://localhost:8080
```

**ขั้นตอนที่ 3: Switch Traffic**

```bash
# Switch traffic จาก blue ไป green
bash docker/scripts/blue-green-deploy.sh switch

# Nginx จะเปลี่ยน upstream ไป green environment
```

**ขั้นตอนที่ 4: Monitor และ Verify**

```bash
# ตรวจสอบ logs
docker compose -f docker-compose.prod.yml logs -f

# ตรวจสอบ metrics ใน Grafana
# ดู error rate, response time, throughput
```

**ขั้นตอนที่ 5: Cleanup Blue Environment**

```bash
# หลังจากยืนยันว่า green ทำงานปกติ
# Stop blue environment
docker compose -f docker-compose.blue.yml down
```

### 6.4 การ Deploy แบบ Rolling Update

สำหรับ update เล็กน้อยที่ไม่ต้องการ blue-green:

```bash
# Update แต่ละ service ทีละตัว
docker compose -f docker-compose.prod.yml up -d --no-deps --build backend
docker compose -f docker-compose.prod.yml up -d --no-deps --build frontend
docker compose -f docker-compose.prod.yml up -d --no-deps --build websocket
```

---

