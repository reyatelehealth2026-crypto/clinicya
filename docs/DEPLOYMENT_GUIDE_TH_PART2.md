# คู่มือการ Deploy ระบบ (ส่วนที่ 2) — Migration & Rollback

**Last Updated**: May 17, 2026

## 7. การ Upgrade จากเวอร์ชันเก่า

### 7.1 ตรวจสอบความเข้ากันได้

```bash
# ตรวจสอบ PHP version
php -v  # ต้องเป็น >= 8.0

# ตรวจสอบ MySQL version
mysql -V  # ต้องเป็น >= 5.7

# ตรวจสอบ extension ที่จำเป็น
php -m | grep -E "pdo|curl|json|mbstring"
```

### 7.2 ขั้นตอน Upgrade

**ขั้นตอนที่ 1: Backup ข้อมูลทั้งหมด**

```bash
# Backup MySQL database
mysqldump -u root -p telepharmacy > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup files
tar -czf uploads_backup_$(date +%Y%m%d_%H%M%S).tar.gz uploads/

# Verify backup
ls -lh backup_*.sql uploads_backup_*.tar.gz
```

**ขั้นตอนที่ 2: Pull changes จาก repository**

```bash
# ดูเวอร์ชันปัจจุบัน
git log --oneline -1

# Update code
git fetch origin
git pull origin main

# ตรวจสอบ migration files
ls database/migration_*.sql | tail -5
```

**ขั้นตอนที่ 3: รัน migrations**

```bash
# ตรวจสอบ migration ที่ต้องรัน
mysql -u root -p telepharmacy < database/migration_2026-05-17_dispense_refill_tracking.sql

# Verify tables ถูกสร้าง
mysql -u root -p telepharmacy -e "SHOW TABLES LIKE 'dispensing%';"
```

**ขั้นตอนที่ 4: Composer dependencies**

```bash
# Update PHP dependencies
composer install --no-dev

# Verify installation
composer show | head -20
```

**ขั้นตอนที่ 5: Restart services**

```bash
# Docker
docker-compose restart

# หรือ Apache/Nginx
sudo systemctl restart apache2
# หรือ
sudo systemctl restart nginx
```

### 7.3 ตรวจสอบหลังจาก Upgrade

```bash
# ตรวจสอบ admin login
curl -I https://yourdomain.com/admin/

# ตรวจสอบ webhook
curl https://yourdomain.com/webhook.php

# ดูข้อมูลผลิตภัณฑ์ใหม่
SELECT COUNT(*) FROM business_items;
```

---

## 8. Monitoring & Health Checks

### 8.1 Health Check Endpoints

```bash
# Admin panel
curl https://yourdomain.com/admin/dashboard.php -I

# API health
curl https://yourdomain.com/api/health.php

# Database connection
curl https://yourdomain.com/api/test-db.php

# Webhook receiver
curl -X POST https://yourdomain.com/webhook.php \
  -H "X-Line-Signature: test" \
  -d '{"test": true}'
```

### 8.2 เก็บ Logs

```bash
# ดูล่าสุด 100 lines
tail -100 /var/log/apache2/access.log

# ดูข้อผิดพลาด
tail -50 /var/log/apache2/error.log

# ดู PHP errors
tail -100 storage/logs/php-errors.log
```

### 8.3 Performance Monitoring

```bash
# Database query time
mysql -u root -p telepharmacy -e "
SET SESSION sql_mode='';
SELECT * FROM performance_schema.events_statements_summary_by_digest 
LIMIT 10;
"

# Memory usage
free -h

# Disk usage
df -h /home/zrismpsz/public_html
```

---

## 9. Troubleshooting Common Issues

### Issue: Webhook not receiving messages

**สาเหตุ**:
- URL ไม่ใช่ HTTPS
- Channel Secret ไม่ถูกต้อง
- IP firewall blocked

**วิธีแก**:
```bash
# ตรวจสอบ HTTPS
curl -I https://yourdomain.com/webhook.php

# ตรวจสอบ config
grep -i "channel_secret" config/config.php

# ตรวจสอบ firewall
sudo iptables -L | grep webhook
```

### Issue: Database connection error

**สาเหตุ**:
- MySQL not running
- Wrong password
- Database doesn't exist

**วิธีแก**:
```bash
# ตรวจสอบ MySQL status
sudo systemctl status mysql

# ตรวจสอบ connection
mysql -u root -p -e "SELECT 1"

# ตรวจสอบ config
cat config/config.php | grep DB_
```

### Issue: Cannot send LINE messages

**สาเหตุ**:
- Access token expired
- Reply quota exceeded
- Invalid user ID

**วิธีแก**:
```php
// Check in admin > LINE Accounts
// Verify Channel Access Token
// Check reply_token exists for user
// Use pushMessage instead if reply_token missing
```

---

## 10. Performance Optimization

### 10.1 Database Optimization

```sql
-- Check slow queries
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;

-- Rebuild indexes
OPTIMIZE TABLE messages;
OPTIMIZE TABLE orders;
OPTIMIZE TABLE users;

-- Check table statistics
ANALYZE TABLE business_items;
ANALYZE TABLE dispensing_records;
```

### 10.2 Caching Strategy

```bash
# Redis cache
redis-cli PING  # should return PONG

# Check cache hit rate
redis-cli INFO stats | grep hits

# Clear specific keys if needed
redis-cli DEL "cache:product:*"
```

### 10.3 File Upload Optimization

```bash
# Check upload directory size
du -sh uploads/

# Archive old files (older than 90 days)
find uploads/ -type f -mtime +90 -exec tar -czf archive_$(date +%Y%m%d).tar.gz {} \;

# Cleanup temp files
find /tmp -name "*php*" -mtime +7 -delete
```
    listen 443 ssl;
    server_name dashboard.yourdomain.com;

    location / {
        proxy_pass http://$backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

**ขั้นตอนที่ 3: ตั้งค่า Data Synchronization**

```bash
# Start data sync service
docker compose -f docker/docker-compose.migration.yml up -d data-sync

# Service นี้จะ sync ข้อมูลระหว่างระบบเดิมและใหม่
# - Orders
# - Customers
# - Payments
# - Webhooks
```

### 7.4 Gradual Traffic Migration

**สัปดาห์ที่ 3: เริ่มที่ 10% traffic**

```bash
# อัพเดท feature flag
mysql -uodoo_user -p telepharmacy -e "
UPDATE feature_flags 
SET flag_value = '10', enabled = 1 
WHERE flag_key = 'traffic_percentage';
"

# Monitor metrics
docker compose -f docker/monitoring/docker-compose.monitoring.yml logs -f
```

**สัปดาห์ที่ 4: เพิ่มเป็น 25%**

```bash
# เพิ่ม traffic
mysql -uodoo_user -p telepharmacy -e "
UPDATE feature_flags 
SET flag_value = '25' 
WHERE flag_key = 'traffic_percentage';
"

# ตรวจสอบ error rate และ performance
# ถ้าทุกอย่างปกติ ให้เพิ่มต่อ
```

**สัปดาห์ที่ 5: เพิ่มเป็น 50%**

```bash
mysql -uodoo_user -p telepharmacy -e "
UPDATE feature_flags 
SET flag_value = '50' 
WHERE flag_key = 'traffic_percentage';
"
```

**สัปดาห์ที่ 6: เพิ่มเป็น 75%**

```bash
mysql -uodoo_user -p telepharmacy -e "
UPDATE feature_flags 
SET flag_value = '75' 
WHERE flag_key = 'traffic_percentage';
"
```

**สัปดาห์ที่ 7: เปลี่ยนเป็น 100%**

```bash
# เปลี่ยน traffic ทั้งหมด
mysql -uodoo_user -p telepharmacy -e "
UPDATE feature_flags 
SET flag_value = '100' 
WHERE flag_key = 'traffic_percentage';
"

# รอ 24-48 ชั่วโมง เพื่อตรวจสอบความเสถียร
```

### 7.5 Complete Migration

**ขั้นตอนที่ 1: ยืนยันว่าระบบใหม่ทำงานปกติ**

```bash
# ตรวจสอบ metrics
# - Error rate < 3%
# - Response time < 300ms
# - No critical issues

# รัน comprehensive tests
cd backend
npm run test:system
```

**ขั้นตอนที่ 2: Stop Data Sync Service**

```bash
# หยุด sync service
docker compose -f docker/docker-compose.migration.yml stop data-sync

# ตรวจสอบว่าไม่มี pending sync jobs
docker compose -f docker/docker-compose.migration.yml exec data-sync npm run check-queue
```

**ขั้นตอนที่ 3: Decommission ระบบเดิม**

```bash
# Backup ระบบเดิมครั้งสุดท้าย
tar -czf legacy_system_final_backup_$(date +%Y%m%d).tar.gz /path/to/legacy/system

# Stop ระบบเดิม
# (ขึ้นอยู่กับวิธีการ deploy ของระบบเดิม)
sudo systemctl stop apache2
# หรือ
docker compose -f legacy-docker-compose.yml down
```

**ขั้นตอนที่ 4: Update DNS และ Nginx**

```bash
# อัพเดท Nginx config ให้ชี้ไปที่ระบบใหม่เท่านั้น
sudo nano /etc/nginx/sites-available/dashboard

# เปลี่ยนจาก traffic routing เป็น direct proxy
upstream backend {
    server localhost:3000;  # ระบบใหม่
}

# Reload nginx
sudo nginx -t
sudo systemctl reload nginx
```

**ขั้นตอนที่ 5: Cleanup**

```bash
# ลบ migration containers
docker compose -f docker/docker-compose.migration.yml down

# ลบ feature flags ที่ไม่ใช้แล้ว
mysql -uodoo_user -p telepharmacy -e "
DELETE FROM feature_flags 
WHERE flag_key IN ('traffic_percentage', 'use_legacy_system');
"

# Archive logs
tar -czf migration_logs_$(date +%Y%m%d).tar.gz logs/migration/
```

---

## 8. การตรวจสอบและ Monitoring

### 8.1 Health Checks

**ตรวจสอบ Health ของแต่ละ Service:**

```bash
# Frontend health
curl https://dashboard.yourdomain.com/health

# Backend API health
curl https://dashboard.yourdomain.com/api/v1/health

# WebSocket health
curl https://dashboard.yourdomain.com/ws/health

# Database health
docker compose exec mysql mysqladmin ping -uroot -p

# Redis health
docker compose exec redis redis-cli ping
```

**Automated Health Check Script:**

```bash
#!/bin/bash
# health-check.sh

echo "=== Health Check Report ==="
echo "Date: $(date)"
echo ""

# Frontend
echo "Frontend:"
curl -s https://dashboard.yourdomain.com/health | jq .
echo ""

# Backend
echo "Backend:"
curl -s https://dashboard.yourdomain.com/api/v1/health | jq .
echo ""

# Database
echo "Database:"
docker compose exec -T mysql mysqladmin ping -uroot -p${DB_ROOT_PASSWORD} 2>/dev/null && echo "OK" || echo "FAIL"
echo ""

# Redis
echo "Redis:"
docker compose exec -T redis redis-cli ping
echo ""

echo "=== End of Report ==="
```

### 8.2 Monitoring Dashboard

**เข้าถึง Grafana:**

```
URL: https://dashboard.yourdomain.com:3000
Username: admin
Password: (ตามที่ตั้งใน .env)
```

**Dashboard ที่สำคัญ:**

1. **System Overview**
   - CPU, Memory, Disk usage
   - Network traffic
   - Container status

2. **Application Metrics**
   - Request rate
   - Response time (p50, p95, p99)
   - Error rate
   - Active users

3. **Database Metrics**
   - Query performance
   - Connection pool usage
   - Slow queries
   - Table sizes

4. **Cache Metrics**
   - Hit rate
   - Miss rate
   - Memory usage
   - Eviction rate

### 8.3 Log Management

**ดู Logs แบบ Real-time:**

```bash
# ทุก services
docker compose logs -f

# Service เดียว
docker compose logs -f backend

# Filter by time
docker compose logs --since 1h backend

# Last 100 lines
docker compose logs --tail 100 frontend
```

**Export Logs:**

```bash
# Export logs ไปยังไฟล์
docker compose logs --no-color > logs_$(date +%Y%m%d_%H%M%S).txt

# Export แยกแต่ละ service
docker compose logs --no-color backend > backend_logs.txt
docker compose logs --no-color frontend > frontend_logs.txt
```

**Log Rotation:**

```bash
# ตั้งค่า log rotation ใน /etc/logrotate.d/docker-compose

/var/lib/docker/containers/*/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

### 8.4 Performance Monitoring

**ตรวจสอบ Response Time:**

```bash
# ใช้ curl วัด response time
curl -w "@curl-format.txt" -o /dev/null -s https://dashboard.yourdomain.com/api/v1/dashboard/overview

# curl-format.txt:
time_namelookup:  %{time_namelookup}\n
time_connect:  %{time_connect}\n
time_appconnect:  %{time_appconnect}\n
time_pretransfer:  %{time_pretransfer}\n
time_redirect:  %{time_redirect}\n
time_starttransfer:  %{time_starttransfer}\n
----------\n
time_total:  %{time_total}\n
```

**Load Testing:**

```bash
# ใช้ Apache Bench
ab -n 1000 -c 100 https://dashboard.yourdomain.com/api/v1/dashboard/overview

# ใช้ wrk
wrk -t12 -c400 -d30s https://dashboard.yourdomain.com/api/v1/dashboard/overview
```

### 8.5 Alert Configuration

**ตั้งค่า Alerts ใน Prometheus:**

```yaml
# docker/monitoring/alert_rules.yml

groups:
  - name: odoo_dashboard_alerts
    interval: 30s
    rules:
      # High Error Rate
      - alert: HighErrorRate
        expr: rate(http_requests_total{status=~"5.."}[5m]) > 0.03
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Error rate สูงเกิน 3%"
          description: "Error rate: {{ $value | humanizePercentage }}"

      # Slow Response Time
      - alert: SlowResponseTime
        expr: histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m])) > 0.5
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Response time ช้าเกิน 500ms (p95)"
          description: "Response time: {{ $value }}s"

      # Low Cache Hit Rate
      - alert: LowCacheHitRate
        expr: rate(cache_hits_total[5m]) / rate(cache_requests_total[5m]) < 0.85
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "Cache hit rate ต่ำกว่า 85%"
          description: "Hit rate: {{ $value | humanizePercentage }}"

      # Database Connection Pool Exhausted
      - alert: DatabasePoolExhausted
        expr: mysql_connection_pool_active / mysql_connection_pool_max > 0.9
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Database connection pool ใกล้เต็ม"
          description: "Usage: {{ $value | humanizePercentage }}"
```

**ตั้งค่า Notification Channels:**

```yaml
# docker/monitoring/alertmanager.yml

route:
  receiver: 'default'
  group_by: ['alertname', 'severity']
  group_wait: 10s
  group_interval: 10s
  repeat_interval: 12h
  routes:
    - match:
        severity: critical
      receiver: 'critical-alerts'
    - match:
        severity: warning
      receiver: 'warning-alerts'

receivers:
  - name: 'default'
    webhook_configs:
      - url: 'http://webhook-receiver:5000/alerts'

  - name: 'critical-alerts'
    email_configs:
      - to: 'ops-team@yourdomain.com'
        from: 'alerts@yourdomain.com'
        smarthost: 'smtp.gmail.com:587'
        auth_username: 'alerts@yourdomain.com'
        auth_password: 'your_email_password'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK'
        channel: '#critical-alerts'
        title: '🚨 Critical Alert'

  - name: 'warning-alerts'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK'
        channel: '#warnings'
        title: '⚠️ Warning Alert'
```

---

## 9. การแก้ไขปัญหา

### 9.1 ปัญหาที่พบบ่อย

**ปัญหา: Container ไม่ start**

```bash
# ตรวจสอบ logs
docker compose logs service_name

# ตรวจสอบ resource usage
docker stats

# ตรวจสอบ disk space
df -h

# แก้ไข: เพิ่ม memory limit
# แก้ไขใน docker-compose.yml
services:
  backend:
    mem_limit: 2g
    mem_reservation: 1g
```

**ปัญหา: Database connection failed**

```bash
# ตรวจสอบว่า MySQL running
docker compose ps mysql

# ตรวจสอบ connection
docker compose exec backend npm run db:test

# ตรวจสอบ credentials ใน .env
cat .env | grep DB_

# แก้ไข: Reset password
docker compose exec mysql mysql -uroot -p
> ALTER USER 'odoo_user'@'%' IDENTIFIED BY 'new_password';
> FLUSH PRIVILEGES;
```

**ปัญหา: Redis connection timeout**

```bash
# ตรวจสอบ Redis
docker compose exec redis redis-cli ping

# ตรวจสอบ memory
docker compose exec redis redis-cli INFO memory

# แก้ไข: เพิ่ม maxmemory
docker compose exec redis redis-cli CONFIG SET maxmemory 2gb
docker compose exec redis redis-cli CONFIG SET maxmemory-policy allkeys-lru
```

**ปัญหา: High CPU usage**

```bash
# ตรวจสอบ process
docker compose exec backend top

# ตรวจสอบ slow queries
docker compose exec mysql mysql -uroot -p -e "SHOW FULL PROCESSLIST;"

# แก้ไข: เพิ่ม indexes
# รัน migration script
docker compose exec backend npm run prisma:migrate
```

**ปัญหา: Disk space full**

```bash
# ตรวจสอบ disk usage
df -h

# ลบ unused Docker resources
docker system prune -a --volumes

# ลบ old logs
find /var/log -name "*.log" -mtime +30 -delete

# ลบ old backups
find /backups -name "*.sql" -mtime +30 -delete
```

### 9.2 Debug Mode

**เปิด Debug Mode:**

```bash
# แก้ไข .env
NODE_ENV=development
DEBUG=*

# Restart services
docker compose restart backend frontend
```

**ดู Detailed Logs:**

```bash
# Backend debug logs
docker compose exec backend npm run dev

# Frontend debug logs
docker compose exec frontend npm run dev

# Database query logs
docker compose exec mysql mysql -uroot -p -e "SET GLOBAL general_log = 'ON';"
docker compose exec mysql tail -f /var/log/mysql/general.log
```

### 9.3 Performance Troubleshooting

**ตรวจสอบ Slow Endpoints:**

```bash
# ดู response time จาก logs
docker compose logs backend | grep "Response time"

# ใช้ APM tools
# เข้า Grafana → Dashboard → API Performance
```

**ตรวจสอบ Database Performance:**

```bash
# Slow query log
docker compose exec mysql mysql -uroot -p -e "
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;
"

# ดู slow queries
docker compose exec mysql tail -f /var/log/mysql/slow.log

# Analyze queries
docker compose exec mysql mysql -uroot -p -e "
EXPLAIN SELECT * FROM orders WHERE status = 'pending';
"
```

**ตรวจสอบ Cache Performance:**

```bash
# Redis stats
docker compose exec redis redis-cli INFO stats

# Cache hit rate
docker compose exec redis redis-cli INFO stats | grep hit

# ดู keys
docker compose exec redis redis-cli KEYS "*"

# ดู memory usage
docker compose exec redis redis-cli INFO memory
```

---

