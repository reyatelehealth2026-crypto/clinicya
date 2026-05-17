# คู่มือการ Deploy ระบบ Odoo Dashboard (ส่วนที่ 3)

## 10. Disaster Recovery & Rollback

### 10.1 เมื่อไหร่ควร Rollback

ควร rollback เมื่อ:
- Admin page ไม่สามารถเข้าถึงได้
- Webhook ไม่ทำงาน
- Database corruption
- Critical bugs ที่เกี่ยวกับลูกค้า
- Data loss หรือ inconsistency

### 10.2 Rollback แบบเร็ว (Quick Rollback)

**ขั้นตอนที่ 1: Revert code changes**

```bash
# ดูเวอร์ชันก่อนหน้า
git log --oneline | head -5

# Revert to previous commit
git revert <commit_hash>
# หรือ
git reset --hard <previous_commit>
```

**ขั้นตอนที่ 2: Restart services**

```bash
# Docker
docker-compose restart

# Apache/Nginx
sudo systemctl restart apache2
sudo systemctl restart nginx
```

**ขั้นตอนที่ 3: Verify services**

```bash
# Check admin panel
curl -I https://yourdomain.com/admin/

# Check webhook
curl https://yourdomain.com/webhook.php

# Check database
mysql -u root -p telepharmacy -e "SELECT COUNT(*) FROM users;"
```

### 10.3 Restore from Database Backup

**ถ้ามี database corruption:**

```bash
# ตรวจสอบ backup files
ls -lh backup_*.sql

# Restore specific backup
mysql -u root -p telepharmacy < backup_20260517_120000.sql

# Verify restore
mysql -u root -p telepharmacy -e "SELECT COUNT(*) FROM users;"
```

**ตรวจสอบ Data Integrity หลังจาก Restore:**

```bash
# ตรวจสอบจำนวน rows
mysql -u root -p telepharmacy -e "
SELECT 
  'users' as table_name, COUNT(*) as row_count FROM users
UNION ALL
SELECT 'orders', COUNT(*) FROM orders
UNION ALL
SELECT 'messages', COUNT(*) FROM messages
UNION ALL
SELECT 'business_items', COUNT(*) FROM business_items;
"

# ตรวจสอบ referential integrity
mysql -u root -p telepharmacy -e "
SELECT COUNT(*) FROM orders o
LEFT JOIN users u ON o.user_id = u.id
WHERE u.id IS NULL;
" # ควรคืน 0
```

### 10.4 Recovery Checklist

- [ ] ตรวจสอบ code version (`git log -1`)
- [ ] ตรวจสอบ database integrity
- [ ] ตรวจสอบ webhook receiver
- [ ] ตรวจสอบ LINE channel connection
- [ ] ตรวจสอบ admin login
- [ ] ตรวจสอบ customer data
- [ ] ตรวจสอบ logs ไม่มี errors
- [ ] ประกาศให้ทีม admin ทราบ

---

## 11. Data Backup Strategy

### 11.1 Backup Schedule

```bash
# Daily backup at 2:00 AM
0 2 * * * mysqldump -u root -p telepharmacy > /backups/daily_$(date +\%Y\%m\%d).sql

# Weekly full backup on Sunday
0 2 * * 0 tar -czf /backups/weekly_$(date +\%Y\%m\%d).tar.gz /home/zrismpsz/public_html/uploads/

# Monthly archive
0 2 1 * * tar -czf /backups/monthly_$(date +\%Y\%m).tar.gz /backups/daily_*.sql
```

### 11.2 Verify Backups

```bash
# ตรวจสอบขนาด backup
ls -lh /backups/

# ตรวจสอบ database backup สามารถ restore ได้
mysqldump -u root -p telepharmacy | mysql -u root -p telepharmacy_test

# ตรวจสอบ file integrity
md5sum /backups/daily_*.sql
```

### 11.3 Store Backups Offsite

```bash
# Copy to external storage
scp -r /backups/ remote_server:/backup-storage/

# หรือ upload to cloud
aws s3 sync /backups/ s3://your-bucket/backups/
gsutil -m cp /backups/* gs://your-bucket/backups/
```

---

## 12. Emergency Contacts & Escalation

### 12.1 Crisis Response Team

| Role | Name | Phone | Email |
|------|------|-------|-------|
| Technical Lead | [Name] | [Phone] | [Email] |
| Database Admin | [Name] | [Phone] | [Email] |
| DevOps Engineer | [Name] | [Phone] | [Email] |
| Server Admin | [Name] | [Phone] | [Email] |

### 12.2 Incident Report Template

```
[INCIDENT REPORT]
Time: YYYY-MM-DD HH:MM:SS
Severity: CRITICAL / HIGH / MEDIUM
Component: [Admin/API/Database/Webhook/etc]
Description: [What happened]
Impact: [How many users affected]
Actions Taken: [What was done to fix]
Root Cause: [Why it happened]
Prevention: [How to prevent next time]
```

---

## 13. Maintenance Windows

### 13.1 Schedule Maintenance

```bash
# Create maintenance mode
touch /home/zrismpsz/public_html/.maintenance

# Display maintenance page
# (Configure in config/config.php or .htaccess)

# Do maintenance work
# ... deploy code, migrate database, etc ...

# Resume service
rm /home/zrismpsz/public_html/.maintenance
```

### 13.2 Announce Downtime

- Email: Notify all customers 24 hours before
- LINE: Send broadcast message
- Website: Show maintenance banner
- Slack: Notify team

# ขั้นตอนที่ 6: Verify
bash docker/scripts/smoke-tests.sh
```

### 10.5 Emergency Rollback Procedure

**สำหรับสถานการณ์ฉุกเฉิน (ระบบ down):**

```bash
#!/bin/bash
# emergency-rollback.sh

echo "🚨 EMERGENCY ROLLBACK INITIATED"
echo "================================"

# 1. Switch to blue immediately
echo "Switching to blue environment..."
docker compose exec nginx sed -i 's/green/blue/g' /etc/nginx/conf.d/upstream.conf
docker compose exec nginx nginx -s reload

# 2. Stop green
echo "Stopping green environment..."
docker compose -f docker-compose.green.yml down

# 3. Verify blue is working
echo "Verifying blue environment..."
for i in {1..5}; do
  if curl -f https://dashboard.yourdomain.com/health; then
    echo "✅ Blue environment is healthy"
    break
  fi
  echo "Attempt $i failed, retrying..."
  sleep 2
done

# 4. Send notification
echo "Sending notification..."
curl -X POST https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK \
  -H 'Content-Type: application/json' \
  -d '{
    "text": "🚨 Emergency rollback completed",
    "attachments": [{
      "color": "danger",
      "fields": [{
        "title": "Status",
        "value": "Rolled back to blue environment",
        "short": false
      }]
    }]
  }'

echo "================================"
echo "✅ EMERGENCY ROLLBACK COMPLETED"
```

---

## 11. Backup และ Recovery

### 11.1 Backup Strategy

**ประเภทของ Backup:**

1. **Full Backup** - ทุกวันเวลา 02:00 น.
2. **Incremental Backup** - ทุก 6 ชั่วโมง
3. **Transaction Log Backup** - ทุก 1 ชั่วโมง

**Retention Policy:**
- Daily backups: เก็บ 7 วัน
- Weekly backups: เก็บ 4 สัปดาห์
- Monthly backups: เก็บ 12 เดือน

### 11.2 Automated Backup Script

```bash
#!/bin/bash
# backup.sh

BACKUP_DIR="/backups"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=7

# สร้าง backup directory
mkdir -p $BACKUP_DIR/{daily,weekly,monthly}

# Database backup
echo "Backing up database..."
docker compose exec -T mysql mysqldump \
  -uroot -p${DB_ROOT_PASSWORD} \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  telepharmacy | gzip > $BACKUP_DIR/daily/db_$DATE.sql.gz

# Files backup
echo "Backing up files..."
tar -czf $BACKUP_DIR/daily/uploads_$DATE.tar.gz uploads/

# Redis backup
echo "Backing up Redis..."
docker compose exec -T redis redis-cli --rdb /data/dump.rdb
docker cp redis_container:/data/dump.rdb $BACKUP_DIR/daily/redis_$DATE.rdb

# Configuration backup
echo "Backing up configuration..."
tar -czf $BACKUP_DIR/daily/config_$DATE.tar.gz \
  .env \
  docker-compose.prod.yml \
  docker/nginx/ \
  docker/monitoring/

# Upload to cloud storage (optional)
echo "Uploading to S3..."
aws s3 sync $BACKUP_DIR/daily/ s3://your-bucket/backups/daily/

# Cleanup old backups
echo "Cleaning up old backups..."
find $BACKUP_DIR/daily -name "*.gz" -mtime +$RETENTION_DAYS -delete
find $BACKUP_DIR/daily -name "*.rdb" -mtime +$RETENTION_DAYS -delete

# Weekly backup (ทุกวันอาทิตย์)
if [ $(date +%u) -eq 7 ]; then
  cp $BACKUP_DIR/daily/db_$DATE.sql.gz $BACKUP_DIR/weekly/
  cp $BACKUP_DIR/daily/uploads_$DATE.tar.gz $BACKUP_DIR/weekly/
fi

# Monthly backup (วันที่ 1 ของเดือน)
if [ $(date +%d) -eq 01 ]; then
  cp $BACKUP_DIR/daily/db_$DATE.sql.gz $BACKUP_DIR/monthly/
  cp $BACKUP_DIR/daily/uploads_$DATE.tar.gz $BACKUP_DIR/monthly/
fi

echo "✅ Backup completed: $DATE"
```

**ตั้งค่า Cron Job:**

```bash
# เพิ่มใน crontab
crontab -e

# Full backup ทุกวันเวลา 02:00
0 2 * * * /path/to/backup.sh >> /var/log/backup.log 2>&1

# Incremental backup ทุก 6 ชั่วโมง
0 */6 * * * /path/to/incremental-backup.sh >> /var/log/backup.log 2>&1
```

### 11.3 Recovery Procedures

**Restore Database:**

```bash
# ขั้นตอนที่ 1: Stop application
docker compose stop backend frontend websocket

# ขั้นตอนที่ 2: Restore database
gunzip < /backups/daily/db_20260317_020000.sql.gz | \
  docker compose exec -T mysql mysql -uroot -p${DB_ROOT_PASSWORD} telepharmacy

# ขั้นตอนที่ 3: Verify data
docker compose exec mysql mysql -uroot -p -e "
USE telepharmacy;
SELECT COUNT(*) FROM orders;
SELECT COUNT(*) FROM users;
"

# ขั้นตอนที่ 4: Start application
docker compose start backend frontend websocket
```

**Restore Files:**

```bash
# Restore uploads
tar -xzf /backups/daily/uploads_20260317_020000.tar.gz -C /

# Verify files
ls -lh uploads/
```

**Restore Redis:**

```bash
# Stop Redis
docker compose stop redis

# Copy backup file
docker cp /backups/daily/redis_20260317_020000.rdb redis_container:/data/dump.rdb

# Start Redis
docker compose start redis

# Verify
docker compose exec redis redis-cli DBSIZE
```

### 11.4 Point-in-Time Recovery

**ใช้ Binary Logs สำหรับ PITR:**

```bash
# ขั้นตอนที่ 1: Restore full backup
gunzip < /backups/daily/db_20260317_020000.sql.gz | \
  docker compose exec -T mysql mysql -uroot -p${DB_ROOT_PASSWORD} telepharmacy

# ขั้นตอนที่ 2: Apply binary logs จนถึงเวลาที่ต้องการ
docker compose exec mysql mysqlbinlog \
  --start-datetime="2026-03-17 02:00:00" \
  --stop-datetime="2026-03-17 14:30:00" \
  /var/log/mysql/mysql-bin.000001 | \
  docker compose exec -T mysql mysql -uroot -p${DB_ROOT_PASSWORD} telepharmacy

# ขั้นตอนที่ 3: Verify
docker compose exec mysql mysql -uroot -p -e "
SELECT MAX(created_at) FROM orders;
"
```

---

## 12. Security Best Practices

### 12.1 SSL/TLS Configuration

**ใช้ Strong Cipher Suites:**

```nginx
# /etc/nginx/conf.d/ssl.conf

ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384';
ssl_prefer_server_ciphers off;

ssl_session_cache shared:SSL:10m;
ssl_session_timeout 10m;
ssl_session_tickets off;

ssl_stapling on;
ssl_stapling_verify on;

add_header Strict-Transport-Security "max-age=63072000" always;
```

**Auto-renew Let's Encrypt Certificate:**

```bash
# ตั้งค่า cron job
crontab -e

# Renew ทุกวันเวลา 03:00
0 3 * * * certbot renew --quiet --post-hook "docker compose exec nginx nginx -s reload"
```

### 12.2 Firewall Configuration

```bash
# ติดตั้ง UFW
sudo apt install ufw

# Default policies
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Allow SSH
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Allow MySQL (เฉพาะจาก internal network)
sudo ufw allow from 10.0.0.0/8 to any port 3306

# Allow Redis (เฉพาะจาก internal network)
sudo ufw allow from 10.0.0.0/8 to any port 6379

# Enable firewall
sudo ufw enable

# ตรวจสอบ status
sudo ufw status verbose
```

### 12.3 Secrets Management

**ใช้ Docker Secrets:**

```bash
# สร้าง secrets
echo "your_db_password" | docker secret create db_password -
echo "your_jwt_secret" | docker secret create jwt_secret -

# ใช้ใน docker-compose.yml
services:
  backend:
    secrets:
      - db_password
      - jwt_secret
    environment:
      DB_PASSWORD_FILE: /run/secrets/db_password
      JWT_SECRET_FILE: /run/secrets/jwt_secret

secrets:
  db_password:
    external: true
  jwt_secret:
    external: true
```

**หรือใช้ Environment Variables แบบปลอดภัย:**

```bash
# เก็บ secrets ใน encrypted file
ansible-vault create secrets.yml

# Decrypt เมื่อ deploy
ansible-vault decrypt secrets.yml --output .env
docker compose up -d
ansible-vault encrypt .env
```

### 12.4 Security Scanning

**Scan Docker Images:**

```bash
# ใช้ Trivy
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock \
  aquasec/trivy image odoo-dashboard-backend:latest

# ใช้ Snyk
snyk container test odoo-dashboard-backend:latest
```

**Scan Dependencies:**

```bash
# Backend
cd backend
npm audit
npm audit fix

# Frontend
cd frontend
npm audit
npm audit fix
```

---

## 13. Performance Tuning

### 13.1 Database Optimization

**MySQL Configuration:**

```ini
# /etc/mysql/my.cnf

[mysqld]
# Connection settings
max_connections = 200
max_connect_errors = 100

# Buffer pool
innodb_buffer_pool_size = 4G
innodb_buffer_pool_instances = 4

# Log settings
innodb_log_file_size = 512M
innodb_log_buffer_size = 16M

# Query cache (MySQL 5.7)
query_cache_type = 1
query_cache_size = 256M

# Slow query log
slow_query_log = 1
long_query_time = 1
slow_query_log_file = /var/log/mysql/slow.log
```

**Create Indexes:**

```sql
-- Orders table
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_created_at ON orders(created_at);
CREATE INDEX idx_orders_customer ON orders(customer_ref);

-- Users table
CREATE INDEX idx_users_line_account ON users(line_account_id);
CREATE INDEX idx_users_email ON users(email);

-- Webhooks table
CREATE INDEX idx_webhooks_status ON odoo_webhooks_log(status);
CREATE INDEX idx_webhooks_created ON odoo_webhooks_log(created_at);
```

### 13.2 Redis Optimization

```bash
# Redis configuration
docker compose exec redis redis-cli CONFIG SET maxmemory 2gb
docker compose exec redis redis-cli CONFIG SET maxmemory-policy allkeys-lru
docker compose exec redis redis-cli CONFIG SET save "900 1 300 10 60 10000"

# Enable persistence
docker compose exec redis redis-cli CONFIG SET appendonly yes
docker compose exec redis redis-cli CONFIG SET appendfsync everysec
```

### 13.3 Node.js Optimization

**PM2 Configuration:**

```javascript
// ecosystem.config.js

module.exports = {
  apps: [{
    name: 'backend',
    script: './dist/server.js',
    instances: 'max',  // ใช้ทุก CPU cores
    exec_mode: 'cluster',
    max_memory_restart: '1G',
    env: {
      NODE_ENV: 'production'
    },
    error_file: './logs/err.log',
    out_file: './logs/out.log',
    log_date_format: 'YYYY-MM-DD HH:mm:ss Z'
  }]
};
```

**Start with PM2:**

```bash
# Install PM2
npm install -g pm2

# Start application
pm2 start ecosystem.config.js

# Save configuration
pm2 save

# Setup startup script
pm2 startup
```

### 13.4 Nginx Optimization

```nginx
# /etc/nginx/nginx.conf

worker_processes auto;
worker_rlimit_nofile 65535;

events {
    worker_connections 4096;
    use epoll;
    multi_accept on;
}

http {
    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript 
               application/json application/javascript application/xml+rss;

    # Caching
    proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=my_cache:10m 
                     max_size=1g inactive=60m use_temp_path=off;

    # Connection pooling
    upstream backend {
        least_conn;
        server backend1:4000 max_fails=3 fail_timeout=30s;
        server backend2:4000 max_fails=3 fail_timeout=30s;
        keepalive 32;
    }

    server {
        listen 443 ssl http2;
        
        # Enable caching
        location /api/ {
            proxy_cache my_cache;
            proxy_cache_valid 200 5m;
            proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
            proxy_cache_background_update on;
            proxy_cache_lock on;
            
            proxy_pass http://backend;
        }
    }
}
```

---

## 14. Maintenance Tasks

### 14.1 Daily Tasks

```bash
#!/bin/bash
# daily-maintenance.sh

echo "=== Daily Maintenance $(date) ==="

# 1. ตรวจสอบ disk space
df -h | grep -E '(Filesystem|/$|/var|/home)'

# 2. ตรวจสอบ logs
tail -100 /var/log/syslog | grep -i error

# 3. ตรวจสอบ Docker containers
docker compose ps

# 4. ตรวจสอบ resource usage
docker stats --no-stream

# 5. Backup
/path/to/backup.sh

echo "=== Maintenance Complete ==="
```

### 14.2 Weekly Tasks

```bash
#!/bin/bash
# weekly-maintenance.sh

echo "=== Weekly Maintenance $(date) ==="

# 1. Update packages
sudo apt update
sudo apt upgrade -y

# 2. Clean Docker
docker system prune -f

# 3. Optimize database
docker compose exec mysql mysqlcheck -uroot -p --optimize --all-databases

# 4. Rotate logs
logrotate -f /etc/logrotate.conf

# 5. Security scan
trivy image --severity HIGH,CRITICAL odoo-dashboard-backend:latest

echo "=== Maintenance Complete ==="
```

### 14.3 Monthly Tasks

```bash
#!/bin/bash
# monthly-maintenance.sh

echo "=== Monthly Maintenance $(date) ==="

# 1. Review and update dependencies
cd backend && npm audit && npm update
cd ../frontend && npm audit && npm update

# 2. Review logs for patterns
zgrep -i error /var/log/nginx/*.gz | wc -l

# 3. Database maintenance
docker compose exec mysql mysql -uroot -p -e "
ANALYZE TABLE orders, users, odoo_webhooks_log;
OPTIMIZE TABLE orders, users, odoo_webhooks_log;
"

# 4. Review monitoring dashboards
# Manual task: Check Grafana for trends

# 5. Update documentation
# Manual task: Update runbooks if needed

echo "=== Maintenance Complete ==="
```

---

## 15. ภาคผนวก

### 15.1 คำสั่งที่ใช้บ่อย

```bash
# Docker Compose
docker compose up -d                    # Start ทุก services
docker compose down                     # Stop ทุก services
docker compose restart service_name     # Restart service เดียว
docker compose logs -f service_name     # ดู logs
docker compose ps                       # ดู status
docker compose exec service_name sh     # เข้าสู่ container

# Database
docker compose exec mysql mysql -uroot -p                    # เข้า MySQL
docker compose exec mysql mysqldump -uroot -p db > backup.sql  # Backup
docker compose exec mysql mysql -uroot -p db < backup.sql    # Restore

# Redis
docker compose exec redis redis-cli     # เข้า Redis CLI
docker compose exec redis redis-cli FLUSHALL  # ลบข้อมูลทั้งหมด

# Logs
docker compose logs --tail 100 -f       # ดู logs 100 บรรทัดล่าสุด
docker compose logs --since 1h          # ดู logs 1 ชั่วโมงล่าสุด

# Health checks
curl https://dashboard.yourdomain.com/health
curl https://dashboard.yourdomain.com/api/v1/health
```

### 15.2 Port Reference

| Service | Port | Protocol | Description |
|---------|------|----------|-------------|
| Frontend | 3000 | HTTP | Next.js application |
| Backend API | 4000 | HTTP | Node.js API server |
| WebSocket | 3001 | WS | Real-time updates |
| MySQL | 3306 | TCP | Database |
| Redis | 6379 | TCP | Cache |
| Nginx | 80 | HTTP | Load balancer |
| Nginx | 443 | HTTPS | Load balancer (SSL) |
| Grafana | 3000 | HTTP | Monitoring dashboard |
| Prometheus | 9090 | HTTP | Metrics collection |

### 15.3 Environment Variables Reference

```bash
# Application
NODE_ENV=production|development
APP_URL=https://dashboard.yourdomain.com

# Database
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=telepharmacy
DB_USERNAME=odoo_user
DB_PASSWORD=<secure_password>

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<secure_password>

# JWT
JWT_SECRET=<minimum_32_characters>
JWT_ACCESS_EXPIRY=15m
JWT_REFRESH_EXPIRY=7d

# API
API_PREFIX=/api/v1
API_PORT=4000

# Frontend
NEXT_PUBLIC_API_URL=https://dashboard.yourdomain.com/api/v1
NEXT_PUBLIC_WS_URL=wss://dashboard.yourdomain.com/ws
```

### 15.4 ติดต่อและ Support

**Technical Support:**
- Email: support@yourdomain.com
- Slack: #odoo-dashboard-support
- On-call: +66-XX-XXX-XXXX

**Documentation:**
- Technical Docs: https://docs.yourdomain.com
- API Reference: https://api-docs.yourdomain.com
- Runbooks: https://runbooks.yourdomain.com

**Emergency Contacts:**
- DevOps Lead: devops-lead@yourdomain.com
- Database Admin: dba@yourdomain.com
- Security Team: security@yourdomain.com

---

## สรุป

คู่มือนี้ครอบคลุมทุกขั้นตอนของการ deploy ระบบ Odoo Dashboard สมัยใหม่ ตั้งแต่การเตรียมสภาพแวดล้อม การติดตั้ง การ deploy แบบต่างๆ การ migration จากระบบเดิม การตรวจสอบและ monitoring การแก้ไขปัญหา และการ rollback

**สิ่งสำคัญที่ต้องจำ:**
1. ✅ Backup ก่อน deploy ทุกครั้ง
2. ✅ ทดสอบใน staging ก่อน production
3. ✅ ใช้ Blue-Green deployment เพื่อ zero-downtime
4. ✅ Monitor metrics อย่างใกล้ชิดหลัง deploy
5. ✅ เตรียม rollback plan ไว้เสมอ

**ขั้นตอนต่อไป:**
1. ทบทวนคู่มือทั้งหมด
2. เตรียม environment ตาม checklist
3. ทดสอบ deployment ใน staging
4. วางแผน deployment window
5. Execute deployment plan
6. Monitor และ optimize

ขอให้การ deploy สำเร็จลุล่วงด้วยดี! 🚀
