# Odoo Dashboard Performance Optimization — Revised Execution Plan

> **Revision Date:** 2026-03-18 (แก้ไขจากแผนเดิมหลังตรวจสอบ codebase จริง)
>
> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** ปรับปรุงประสิทธิภาพ Odoo Dashboard ให้รองรับข้อมูลจำนวนมาก (1M+ records) โดยลด response time จาก 3-5 วินาที เหลือ < 500ms

**Tech Stack:** PHP 8+, MySQL/MariaDB, Redis (optional), JavaScript (vanilla), nginx

**Base Path:** `/home/user/odoo` (production: `/home/zrismpsz/public_html/cny.re-ya.com`)

---

## สิ่งที่ค้นพบจากการตรวจสอบ codebase จริง

### ตารางที่ใช้จริง (เรียงตามความถี่ใช้งานใน dashboard API)

| ตาราง | ครั้งใน dashboard | ครั้งรวมทั้งระบบ | ความสำคัญ |
|-------|-----------------|----------------|----------|
| `odoo_webhooks_log` | 40 | 241 | 🔴 Critical |
| `odoo_notification_log` | 11 | 38 | 🔴 Critical |
| `odoo_line_users` | 13 | 66 | 🔴 Critical |
| `odoo_slip_uploads` | 8 | 57 | 🟠 High |
| `odoo_bdos` | 8 | 41 | 🟠 High |
| `odoo_bdo_context` | 8 | 24 | 🟠 High (ไม่มีในแผนเดิม!) |
| `odoo_webhook_dlq` | 7 | 27 | 🟠 High |
| `odoo_orders` | 4 | 54 | 🟡 Medium |
| `odoo_invoices` | 3 | 24 | 🟡 Medium |
| `odoo_bdo_orders` | 3 | 15 | 🟡 Medium (ไม่มีในแผนเดิม!) |
| `odoo_order_notes` | 2 | 6 | 🟢 Low |
| `odoo_manual_overrides` | 2 | 6 | 🟢 Low |
| `odoo_customer_projection` | 2 | 13 | 🟢 Low (แผนเดิมให้ความสำคัญสูงเกินไป) |
| `odoo_orders_summary` | 0* | 15 | 🟡 Cache table - ควรใช้เพิ่มขึ้น |
| `odoo_customers_cache` | 0* | 8 | 🟡 Cache table - ควรใช้เพิ่มขึ้น |
| `odoo_invoices_cache` | 0* | 11 | 🟡 Cache table - ควรใช้เพิ่มขึ้น |

\* ถูกใช้ใน `odoo-dashboard-local.php` (1,148 บรรทัด) แต่ไม่ได้ใช้ใน main API

### สิ่งที่มีอยู่แล้ว (ไม่ต้องสร้างใหม่)

| สิ่ง | ไฟล์ | สถานะ |
|------|------|--------|
| Index migration | `database/migration_odoo_api_performance.sql` | ✅ มีอยู่แล้ว ดีกว่าแผนเดิมด้วย |
| Fast endpoint | `api/odoo-dashboard-fast.php` (176 บรรทัด) | ✅ ใช้งานอยู่ใน JS |
| Local cache API | `api/odoo-dashboard-local.php` (1,148 บรรทัด) | ✅ มีอยู่แล้ว |
| Cache tables | `odoo_orders_summary`, `odoo_customers_cache`, `odoo_invoices_cache` | ✅ มีอยู่ใน schema |
| Dashboard functions | `api/odoo-dashboard-functions.php` (439 บรรทัด) | ✅ มีอยู่แล้ว |

### ข้อผิดพลาดในแผนเดิมที่แก้ไขแล้ว

1. **Partitioning script** — ใช้ `INCLUDING ALL` ซึ่งเป็น PostgreSQL syntax ไม่ใช่ MySQL
2. **API Router method names** — `match()` เรียก `$action` ตรงๆ แต่ method จริงชื่อ `getCustomerList`
3. **Redis Cache file** — ใช้ `unserialize(file_get_contents())` แต่ set ด้วย `json_encode()` ไม่สอดคล้องกัน
4. **Index targets** — `odoo_customer_projection` มีคอลัมน์ต่างจากที่แผนระบุ (`total_invoiced` ไม่มี, ชื่อจริงคือ `spend_total`)
5. **Critical tables หายไป** — `odoo_bdo_context`, `odoo_bdo_orders`, `odoo_notification_log` ไม่มีใน index plan เดิม
6. **VirtualTable.js** — `innerHTML = ''` ทุก scroll event ทำให้ DOM thrashing แย่ลง

---

## Phase 1: Database — ตรวจสอบและเพิ่ม Index ที่ยังขาด

### Task 1.1: รัน Migration ที่มีอยู่แล้ว

ไฟล์ `database/migration_odoo_api_performance.sql` มีอยู่แล้วและดีมาก ให้รัน:

```bash
cd /home/user/odoo
# ตรวจสอบก่อนว่า index ไหนมีแล้ว
mysql -u $DB_USER -p$DB_PASS $DB_NAME < database/migration_odoo_api_performance.sql
```

ไฟล์นี้ครอบคลุม: `odoo_webhooks_log`, `odoo_orders`, `odoo_invoices`, `odoo_bdos`, `odoo_customer_projection`, `odoo_order_projection`, `odoo_api_logs` และยังมี **generated virtual columns** สำหรับ JSON fields

### Task 1.2: เพิ่ม Index สำหรับตารางที่ขาดหายในแผนเดิม

**Files:**
- Create: `database/migration_missing_indexes.sql`

```sql
-- Migration: Index สำหรับตารางที่ขาดในแผนเดิม
-- Created: 2026-03-18
-- ใช้ IF NOT EXISTS เพื่อ idempotent

-- ── odoo_notification_log (38 references, DATE() queries ทำ full scan) ──
ALTER TABLE odoo_notification_log
ADD INDEX IF NOT EXISTS idx_notif_sent_at_date (sent_at),
ADD INDEX IF NOT EXISTS idx_notif_status_sent (status, sent_at),
ADD INDEX IF NOT EXISTS idx_notif_line_user_sent (line_user_id, sent_at DESC),
ADD INDEX IF NOT EXISTS idx_notif_event_sent (event_type, sent_at DESC);

-- ── odoo_bdo_context (ใช้ GROUP BY bdo_id + MAX(id) บ่อยมาก) ──
ALTER TABLE odoo_bdo_context
ADD INDEX IF NOT EXISTS idx_bdo_ctx_bdo_id (bdo_id, id DESC),
ADD INDEX IF NOT EXISTS idx_bdo_ctx_id (id);

-- ── odoo_bdo_orders (JOIN กับ odoo_bdo_context บ่อย) ──
ALTER TABLE odoo_bdo_orders
ADD INDEX IF NOT EXISTS idx_bdo_orders_bdo_id (bdo_id),
ADD INDEX IF NOT EXISTS idx_bdo_orders_partner (partner_id, due_date),
ADD INDEX IF NOT EXISTS idx_bdo_orders_payment (payment_state, state);

-- ── odoo_webhook_dlq (retry queue) ──
ALTER TABLE odoo_webhook_dlq
ADD INDEX IF NOT EXISTS idx_dlq_status_next (status, next_retry_at),
ADD INDEX IF NOT EXISTS idx_dlq_created (created_at);

-- ── odoo_line_users (JOIN กับ webhooks หา line_user_id) ──
ALTER TABLE odoo_line_users
ADD INDEX IF NOT EXISTS idx_line_users_partner (odoo_partner_id, line_user_id),
ADD INDEX IF NOT EXISTS idx_line_users_customer_code (odoo_customer_code);

-- ── odoo_slip_uploads (upload tracking) ──
ALTER TABLE odoo_slip_uploads
ADD INDEX IF NOT EXISTS idx_slips_status_uploaded (status, uploaded_at DESC),
ADD INDEX IF NOT EXISTS idx_slips_line_user (line_user_id, uploaded_at DESC),
ADD INDEX IF NOT EXISTS idx_slips_matched_order (matched_order_id);

-- ── odoo_orders_summary (cache table — ยังไม่มี index ที่ดี) ──
ALTER TABLE odoo_orders_summary
ADD INDEX IF NOT EXISTS idx_orders_sum_date_state (date_order, state),
ADD INDEX IF NOT EXISTS idx_orders_sum_customer_ref (customer_ref(50)),
ADD INDEX IF NOT EXISTS idx_orders_sum_line_user (line_user_id, last_event_at DESC);

-- ── odoo_customers_cache (cache table) ──
ALTER TABLE odoo_customers_cache
ADD INDEX IF NOT EXISTS idx_cust_cache_name (customer_name(80)),
ADD INDEX IF NOT EXISTS idx_cust_cache_phone (phone(20));
```

**Apply:**
```bash
mysql -u $DB_USER -p$DB_PASS $DB_NAME < database/migration_missing_indexes.sql
```

**Commit:**
```bash
git add database/migration_missing_indexes.sql
git commit -m "perf(db): add missing indexes for bdo_context, notification_log, line_users"
```

---

### Task 1.3: แก้ไข DATE() Functions ใน notification_log Queries

**ปัญหา:** ใน `api/odoo-dashboard-api.php` บรรทัด 1959-1963 มี queries:
```php
"SELECT COUNT(*) FROM odoo_notification_log WHERE DATE(sent_at) = CURDATE()"
```
`DATE(sent_at)` ทำให้ใช้ index บน `sent_at` ไม่ได้ → full scan ทุกครั้ง

**Files:**
- Modify: `api/odoo-dashboard-api.php` (แก้ 5 queries)

เปลี่ยน pattern นี้:
```php
// ❌ ใช้ index ไม่ได้
"WHERE DATE(sent_at) = CURDATE()"

// ✅ ใช้ index ได้
"WHERE sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY"
```

แก้ไขทั้ง 5 occurrences ในบรรทัด 1959-1963 และ 1967

**Commit:**
```bash
git add api/odoo-dashboard-api.php
git commit -m "perf(db): replace DATE(sent_at) with range query to enable index usage"
```

---

### Task 1.4: Partitioning สำหรับ odoo_webhooks_log (เมื่อ > 500K rows)

> **ข้อแก้ไขจากแผนเดิม:** Script เดิมใช้ `INCLUDING ALL` ซึ่งเป็น PostgreSQL syntax

**Files:**
- Create: `migration/setup-webhooks-partitioning.php`

```php
<?php
/**
 * Setup monthly partitioning for odoo_webhooks_log (MySQL/MariaDB)
 * Run: php migration/setup-webhooks-partitioning.php [--dry-run]
 *
 * Prerequisite: ลบ FK constraints ก่อน (ถ้ามี)
 */
require_once __DIR__ . '/../config/database.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$db = Database::getInstance()->getConnection();

// Only proceed if > 500K rows
$count = $db->query("SELECT COUNT(*) FROM odoo_webhooks_log")->fetchColumn();
if ($count < 500000) {
    echo "Table has $count rows (< 500K). Partitioning not needed yet.\n";
    exit(0);
}

// Check if already partitioned
$stmt = $db->query("SELECT PARTITION_NAME FROM information_schema.PARTITIONS WHERE TABLE_NAME = 'odoo_webhooks_log' AND TABLE_SCHEMA = DATABASE() AND PARTITION_NAME IS NOT NULL LIMIT 1");
if ($stmt->fetch()) {
    echo "Table is already partitioned. Skipping.\n";
    exit(0);
}

echo "Table has $count rows. Setting up partitioning...\n";

// Build partition list: 12 months back + 3 months ahead
$partitions = [];
for ($i = -12; $i <= 3; $i++) {
    $date = new DateTime();
    $date->modify("$i months");
    $date->modify('first day of this month');
    $nextMonth = clone $date;
    $nextMonth->modify('+1 month');

    $partKey = 'p' . $date->format('Ym');
    $lessVal = (int)$nextMonth->format('Ym');
    $partitions[] = "PARTITION {$partKey} VALUES LESS THAN ({$lessVal})";
}
$partitions[] = "PARTITION p_future VALUES LESS THAN MAXVALUE";

$sql = "ALTER TABLE odoo_webhooks_log PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (\n"
     . implode(",\n", $partitions)
     . "\n)";

echo $dryRun ? "[DRY RUN] Would execute:\n$sql\n" : "Executing...\n";

if (!$dryRun) {
    try {
        $start = microtime(true);
        $db->exec($sql);
        $elapsed = round((microtime(true) - $start), 1);
        echo "✅ Partitioning complete in {$elapsed}s\n";
    } catch (PDOException $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        echo "Tip: Remove foreign key constraints first if any exist.\n";
    }
}
```

**Commit:**
```bash
git add migration/setup-webhooks-partitioning.php
git commit -m "perf(db): fix partitioning script to use MySQL RANGE syntax (not PostgreSQL)"
```

---

## Phase 2: API Optimization

### Task 2.1: ใช้ Cache Tables ที่มีอยู่แล้วใน main dashboard API

**สิ่งที่ค้นพบ:** มี `odoo_orders_summary`, `odoo_customers_cache`, `odoo_invoices_cache` อยู่แล้วใน schema และ `odoo-dashboard-local.php` ใช้ตารางเหล่านี้อยู่แล้ว แต่ main API (`odoo-dashboard-api.php`) ยังใช้ตารางดั้งเดิม

**Files:**
- Modify: `api/odoo-dashboard-fast.php` — เพิ่ม actions จาก local cache

ใน `odoo-dashboard-fast.php` เพิ่ม action ที่ดึงจาก cache tables:

```php
case 'orders_today_fast':
    // ดึงจาก odoo_orders_summary แทนการ query odoo_orders + webhook JOINs
    $stmt = $db->prepare("
        SELECT order_key, customer_name, customer_ref, amount_total,
               state, payment_status, date_order, last_event_at
        FROM odoo_orders_summary
        WHERE line_account_id = :account_id
          AND date_order >= CURDATE()
        ORDER BY last_event_at DESC
        LIMIT :limit
    ");
    $stmt->execute([':account_id' => $lineAccountId, ':limit' => min((int)($input['limit'] ?? 50), 200)]);
    $result = ['orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    break;

case 'customers_fast':
    // ดึงจาก odoo_customers_cache แทน odoo_customer_projection
    $stmt = $db->prepare("
        SELECT customer_id, customer_name, customer_ref, phone,
               total_due, overdue_amount, latest_order_at, orders_count_total
        FROM odoo_customers_cache
        WHERE line_account_id = :account_id
        ORDER BY latest_order_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->execute([
        ':account_id' => $lineAccountId,
        ':limit' => min((int)($input['limit'] ?? 50), 500),
        ':offset' => (int)($input['offset'] ?? 0)
    ]);
    $result = ['customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    break;
```

**Commit:**
```bash
git add api/odoo-dashboard-fast.php
git commit -m "perf(api): add cache-table backed actions to fast endpoint"
```

---

### Task 2.2: เพิ่ม OPcache สำหรับ odoo-dashboard-api.php

**ปัญหา:** `odoo-dashboard-api.php` มี 4,932 บรรทัด (~182KB) ใช้เวลา parse ~1.3s บนเซิร์ฟเวอร์ที่ไม่มี OPcache (`odoo-dashboard-fast.php` ระบุไว้ใน comment เอง)

**Files:**
- Create: `config/opcache.ini` (เพิ่มใน nginx/PHP-FPM config)

```ini
; PHP OPcache settings for dashboard performance
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
opcache.enable_cli=0
```

ตรวจสอบสถานะ:
```bash
php -r "var_dump(opcache_get_status()['opcache_enabled']);"
```

---

### Task 2.3: เพิ่ม Response Compression และ HTTP Caching

**Files:**
- Modify: `api/odoo-dashboard-fast.php` (เพิ่ม gzip + cache headers)

```php
// เพิ่มต้นไฟล์ หลัง header declarations
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}

// สำหรับ read-only actions เพิ่ม cache headers
if (in_array($action, ['health', 'orders_today_fast', 'customers_fast'])) {
    header('Cache-Control: private, max-age=30');
    header('Vary: Accept-Encoding');
}
```

---

## Phase 3: Frontend Optimization

### Task 3.1: แก้ไข VirtualTable.js (แก้ DOM Thrashing)

**ปัญหาในแผนเดิม:** `this.tableContainer.innerHTML = ''` ทุก scroll event ทำให้เกิด full DOM reflow

**Files:**
- Create: `assets/js/components/VirtualTable.js`

```javascript
/**
 * VirtualTable — Render only visible rows using node recycling
 * @version 1.1.0 (แก้ไข DOM thrashing จากแผนเดิม)
 */
class VirtualTable {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.rowHeight = options.rowHeight || 48;
        this.bufferSize = options.bufferSize || 3;
        this.data = [];
        this.columns = options.columns || [];
        this.renderRow = options.renderRow || this._defaultRenderRow.bind(this);
        this._visibleStart = -1;
        this._visibleEnd = -1;
        this._nodePool = [];  // recycled DOM nodes
        this._activeNodes = new Map(); // index → node
        this._init();
    }

    _init() {
        this.container.style.cssText = 'overflow:auto;position:relative;';
        // Spacer controls total scroll height
        this._spacer = document.createElement('div');
        this._spacer.style.cssText = 'position:absolute;top:0;left:0;width:1px;';
        // Rows container — positioned inside scroll area
        this._rows = document.createElement('div');
        this._rows.style.cssText = 'position:absolute;top:0;left:0;right:0;';
        this.container.appendChild(this._spacer);
        this.container.appendChild(this._rows);
        this.container.addEventListener('scroll', () => this._update(), { passive: true });
        window.addEventListener('resize', () => this._update(), { passive: true });
    }

    setData(data) {
        this.data = data;
        this._spacer.style.height = `${data.length * this.rowHeight}px`;
        this._visibleStart = -1;
        this._visibleEnd = -1;
        // Recycle all active nodes
        this._activeNodes.forEach(node => this._nodePool.push(node));
        this._activeNodes.clear();
        this._rows.innerHTML = '';
        this._update();
    }

    _update() {
        const scrollTop = this.container.scrollTop;
        const viewH = this.container.clientHeight;
        const start = Math.max(0, Math.floor(scrollTop / this.rowHeight) - this.bufferSize);
        const end = Math.min(this.data.length, Math.ceil((scrollTop + viewH) / this.rowHeight) + this.bufferSize);

        if (start === this._visibleStart && end === this._visibleEnd) return;

        // Remove rows no longer visible → recycle
        this._activeNodes.forEach((node, idx) => {
            if (idx < start || idx >= end) {
                this._rows.removeChild(node);
                this._nodePool.push(node);
                this._activeNodes.delete(idx);
            }
        });

        // Add newly visible rows — reuse recycled nodes
        for (let i = start; i < end; i++) {
            if (this._activeNodes.has(i)) continue;
            const node = this._nodePool.pop() || document.createElement('div');
            node.style.cssText = `position:absolute;top:${i * this.rowHeight}px;left:0;right:0;height:${this.rowHeight}px;`;
            this.renderRow(node, this.data[i], i);
            this._rows.appendChild(node);
            this._activeNodes.set(i, node);
        }

        this._visibleStart = start;
        this._visibleEnd = end;
    }

    _defaultRenderRow(node, item) {
        node.textContent = JSON.stringify(item);
        node.style.borderBottom = '1px solid #eee';
        node.style.padding = '8px';
    }
}

if (typeof module !== 'undefined') module.exports = VirtualTable;
```

**Commit:**
```bash
git add assets/js/components/VirtualTable.js
git commit -m "perf(frontend): fix VirtualTable to use node recycling instead of innerHTML reset"
```

---

### Task 3.2: เพิ่ม Cursor-based Pagination

(ใช้โค้ดเดิมจากแผน — ถูกต้องแล้ว ไม่มีการเปลี่ยนแปลง)

**Files:**
- Create: `assets/js/components/CursorPagination.js`

ใช้ implementation จากแผนเดิมได้เลย (CursorPagination class)

---

## Phase 4: Cleanup — ลบไฟล์ที่ไม่ได้ใช้

### Task 4.1: ลบ/Archive One-time Migration Scripts ที่รันไปแล้ว

ไฟล์เหล่านี้คือ scripts ที่สร้างขึ้นมาครั้งเดียวและรันไปแล้ว ควร archive หรือลบ:

**Root PHP files (one-time scripts):**
| ไฟล์ | เหตุผล |
|------|--------|
| `check_table.php` | Debug script ตรวจสอบ table structure |
| `status_fix.php` | One-time: แก้ transactions status column |
| `fix_status_enum.php` | One-time: แก้ reward_redemptions ENUM |
| `fix_status_standalone.php` | Duplicate ของ fix_status_enum |
| `update_liff_main.php` | One-time: update LIFF ID |
| `check-members-simple.php` | Debug: ตรวจสอบ members table |

**API debug/demo files:**
| ไฟล์ | เหตุผล |
|------|--------|
| `api/_debug_payload.php` | Debug only |
| `api/csv-import-debug.php` | Debug version ของ csv-import.php |
| `api/dashboard-cache-demo.php` | Demo file |
| `api/dashboard-realtime-demo.php` | Demo file |
| `api/debug-rewards.php` | Debug only |
| `api/error-handling-demo.php` | Demo file |
| `api/inbox-debug.php` | Debug only |
| `api/put-away-debug.php` | Debug version ของ put-away.php |
| `api/rich-menu-debug.php` | Debug only |
| `api/migrate_odoo_phone_email.php` | One-time migration script |
| `api/classes_OdooWebhookHandler.php` | ชื่อผิด format — test file |

**Script สำหรับ archive:**
```bash
cd /home/user/odoo
mkdir -p archive/one-time-scripts archive/debug-files

# Root one-time scripts
mv check_table.php status_fix.php fix_status_enum.php fix_status_standalone.php \
   update_liff_main.php check-members-simple.php archive/one-time-scripts/

# API debug/demo
mv api/_debug_payload.php api/csv-import-debug.php api/dashboard-cache-demo.php \
   api/dashboard-realtime-demo.php api/debug-rewards.php api/error-handling-demo.php \
   api/inbox-debug.php api/put-away-debug.php api/rich-menu-debug.php \
   api/migrate_odoo_phone_email.php api/classes_OdooWebhookHandler.php \
   archive/debug-files/

git add archive/
git rm check_table.php status_fix.php fix_status_enum.php fix_status_standalone.php \
        update_liff_main.php check-members-simple.php
git rm api/_debug_payload.php api/csv-import-debug.php api/dashboard-cache-demo.php \
       api/dashboard-realtime-demo.php api/debug-rewards.php api/error-handling-demo.php \
       api/inbox-debug.php api/put-away-debug.php api/rich-menu-debug.php \
       api/migrate_odoo_phone_email.php api/classes_OdooWebhookHandler.php

git commit -m "cleanup: archive one-time migration scripts and debug/demo files"
```

> ⚠️ **ตรวจสอบก่อนลบ:** ยืนยันกับทีมว่าไฟล์เหล่านี้รันไปแล้วจริงๆ อย่าลบโดยไม่ตรวจสอบ

---

### Task 4.2: ตรวจสอบ Duplicate Dashboard Files

ปัจจุบันมี dashboard API ซ้ำกัน 4 ตัว:
| ไฟล์ | บรรทัด | สถานะ |
|------|---------|--------|
| `api/odoo-dashboard-api.php` | 4,932 | Main API — ยังใช้อยู่ |
| `api/odoo-dashboard-local.php` | 1,148 | Local cache reads — ใช้อยู่บางส่วน |
| `api/odoo-dashboard-fast.php` | 176 | Fast endpoint — ใช้อยู่ใน JS |
| `api/odoo-dashboard-functions.php` | 439 | Helper functions — include ใน main API |

**แนะนำ:** ไม่ต้องลบตอนนี้ แต่ควรทำ long-term refactor โดยให้ `odoo-dashboard-fast.php` เป็น entry point หลัก และค่อยๆ migrate actions จาก main API ไปใน Phase ถัดไป

---

## Phase 5: Monitoring

### Task 5.1: Performance Baseline Script

**Files:**
- Create: `scripts/analyze-slow-queries.php`

```php
<?php
/**
 * Analyze dashboard query performance — ตรวจสอบก่อนและหลัง optimization
 * Run: php scripts/analyze-slow-queries.php
 */
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$tables = [
    'odoo_webhooks_log', 'odoo_orders', 'odoo_invoices', 'odoo_bdos',
    'odoo_bdo_context', 'odoo_notification_log', 'odoo_line_users',
    'odoo_slip_uploads', 'odoo_orders_summary', 'odoo_customers_cache'
];

$report = [];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SHOW TABLE STATUS LIKE '$table'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $report[$table] = ['error' => 'not found']; continue; }

        $idxStmt = $db->query("SHOW INDEX FROM `$table`");
        $indexes = array_column($idxStmt->fetchAll(PDO::FETCH_ASSOC), 'Key_name');

        $report[$table] = [
            'rows' => (int)$row['Rows'],
            'size_mb' => round(($row['Data_length'] + $row['Index_length']) / 1024 / 1024, 2),
            'index_size_mb' => round($row['Index_length'] / 1024 / 1024, 2),
            'indexes' => array_unique($indexes),
        ];
    } catch (PDOException $e) {
        $report[$table] = ['error' => $e->getMessage()];
    }
}

// Test critical queries
$queries = [
    'webhooks_today' => "SELECT COUNT(*) FROM odoo_webhooks_log WHERE created_at >= CURDATE()",
    'notif_today' => "SELECT COUNT(*) FROM odoo_notification_log WHERE sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY",
    'bdo_context_latest' => "SELECT bdo_id, MAX(id) as max_id FROM odoo_bdo_context GROUP BY bdo_id LIMIT 10",
];

$queryTimes = [];
foreach ($queries as $name => $sql) {
    $start = microtime(true);
    try {
        $db->query($sql)->fetchAll();
        $queryTimes[$name] = round((microtime(true) - $start) * 1000, 2) . 'ms';
    } catch (PDOException $e) {
        $queryTimes[$name] = 'ERROR: ' . $e->getMessage();
    }
}

$output = ['tables' => $report, 'query_times' => $queryTimes, 'generated_at' => date('c')];
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
```

```bash
git add scripts/analyze-slow-queries.php
git commit -m "feat(monitoring): add query analysis script with correct table list"
```

---

## สรุปลำดับการ Execute

| ลำดับ | Task | Impact | Risk | เวลาประมาณ |
|-------|------|--------|------|-----------|
| 1 | รัน `migration_odoo_api_performance.sql` | 🔴 สูง | ต่ำ | 5-10 นาที |
| 2 | Task 1.2: Index ตารางที่ขาด | 🔴 สูง | ต่ำ | 10-20 นาที |
| 3 | Task 1.3: แก้ DATE() queries | 🔴 สูง | ต่ำ | 30 นาที |
| 4 | Task 5.1: Baseline measurement | 🟡 Medium | ไม่มี | 15 นาที |
| 5 | Task 2.1: Fast endpoint + cache tables | 🟠 สูง | กลาง | 1-2 ชั่วโมง |
| 6 | Task 3.1: แก้ VirtualTable.js | 🟡 Medium | ต่ำ | 30 นาที |
| 7 | Task 4.1: Archive debug files | 🟢 Cleanup | กลาง (ตรวจสอบก่อน) | 30 นาที |
| 8 | Task 1.4: Partitioning (ถ้า >500K) | 🟡 Medium | สูง | ทำบน production เท่านั้น |

## Expected Results

| Metric | ก่อน | หลัง (ประมาณ) |
|--------|------|--------------|
| Dashboard initial load | 3-5s | < 800ms |
| notification_log queries | ~500ms (full scan) | < 30ms |
| bdo_context GROUP BY | ~300ms | < 20ms |
| webhooks_log count | ~200ms | < 10ms |
| Frontend scroll (10K rows) | laggy / crash | 60fps |

## Testing Checklist

- [ ] `php scripts/analyze-slow-queries.php` — ได้ baseline
- [ ] รัน migration_odoo_api_performance.sql — ไม่มี error
- [ ] รัน migration_missing_indexes.sql — ไม่มี error
- [ ] Test `action=health` บน fast endpoint < 50ms
- [ ] Test `action=orders_today_fast` < 200ms
- [ ] Verify DATE() queries เปลี่ยนเป็น range ครบ 5 จุด
- [ ] VirtualTable scroll test กับ 5,000 rows — ไม่มี layout thrashing
- [ ] ยืนยันกับทีมก่อน archive debug files
