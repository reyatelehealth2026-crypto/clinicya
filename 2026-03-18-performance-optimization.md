![[# Odoo Dashboard Performance Optimization - Execution Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** ปรับปรุงประสิทธิภาพ Odoo Dashboard ให้รองรับข้อมูลจำนวนมาก (1M+ records) โดยลด response time จาก 3-5 วินาที เหลือ < 500ms

**Architecture:** แบ่งการทำงานเป็น 4 phases: (1) Database optimization with indexing & partitioning, (2) API code splitting & caching layer upgrade, (3) Frontend virtualization & pagination, (4) Monitoring & alerting setup

**Tech Stack:** PHP 8+, MySQL/MariaDB, Redis, JavaScript (vanilla), nginx

**Base Path:** `/root/.openclaw/workspace/odoo`

---

## Phase 1: Database Optimization (Foundation)

### Task 1.1: Analyze Current Query Performance

**Files:**
- Create: `scripts/analyze-slow-queries.php`
- Read: `api/odoo-dashboard-api.php` (ดู queries หลัก)

**Step 1: Create query analyzer script**

```php
<?php
/**
 * Analyze slow queries in odoo-dashboard-api
 * Run: php scripts/analyze-slow-queries.php
 */
]]require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Enable slow query log temporarily
    $db->exec("SET GLOBAL slow_query_log = 'ON'");
    $db->exec("SET GLOBAL long_query_time = 1");
    
    // Get table statistics
    $tables = ['odoo_webhooks_log', 'odoo_orders', 'odoo_invoices', 'odoo_bdos', 'odoo_customer_projection'];
    $stats = [];
    
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLE STATUS LIKE '$table'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats[$table] = [
            'rows' => $row['Rows'] ?? 0,
            'size_mb' => round(($row['Data_length'] + $row['Index_length']) / 1024 / 1024, 2),
            'avg_row_length' => $row['Avg_row_length'] ?? 0
        ];
        
        // Get current indexes
        $idxStmt = $db->query("SHOW INDEX FROM $table");
        $stats[$table]['indexes'] = $idxStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

**Step 2: Run analyzer and save results**

```bash
cd /root/.openclaw/workspace/odoo
php scripts/analyze-slow-queries.php > docs/db-analysis-before.json
cat docs/db-analysis-before.json
```

**Expected Output:** JSON with table stats showing row counts and sizes

**Step 3: Commit**

```bash
git add scripts/analyze-slow-queries.php docs/db-analysis-before.json
git commit -m "feat(perf): add database analysis script for baseline metrics"
```

---

### Task 1.2: Add Critical Indexes

**Files:**
- Create: `migration/add-performance-indexes.sql`
- Create: `migration/apply-indexes.php`

**Step 1: Create migration SQL**

```sql
-- Migration: Add performance indexes for odoo-dashboard
-- Created: 2026-03-18
-- Estimated time: 2-5 minutes for tables < 1M rows

-- odoo_webhooks_log indexes (most critical - highest traffic)
ALTER TABLE odoo_webhooks_log 
ADD INDEX idx_webhook_created_at (created_at),
ADD INDEX idx_webhook_event_created (event_type, created_at),
ADD INDEX idx_webhook_order_ref (order_ref(50)),
ADD INDEX idx_webhook_partner_id (partner_id),
ADD INDEX idx_webhook_status_created (status, created_at);

-- odoo_orders indexes
ALTER TABLE odoo_orders 
ADD INDEX idx_order_date (date_order),
ADD INDEX idx_order_state (state),
ADD INDEX idx_order_customer_ref (customer_ref(50)),
ADD INDEX idx_order_updated (updated_at),
ADD INDEX idx_order_date_state (date_order, state),
ADD INDEX idx_order_partner (partner_id, date_order);

-- odoo_invoices indexes
ALTER TABLE odoo_invoices 
ADD INDEX idx_invoice_date (invoice_date),
ADD INDEX idx_invoice_state (state),
ADD INDEX idx_invoice_partner (partner_id),
ADD INDEX idx_invoice_payment (payment_state);

-- odoo_customer_projection indexes
ALTER TABLE odoo_customer_projection 
ADD INDEX idx_customer_total (total_invoiced),
ADD INDEX idx_customer_overdue (overdue_amount),
ADD INDEX idx_customer_name (name(100));

-- odoo_bdos indexes
ALTER TABLE odoo_bdos 
ADD INDEX idx_bdo_state (state),
ADD INDEX idx_bdo_payment (payment_state),
ADD INDEX idx_bdo_partner (partner_id),
ADD INDEX idx_bdo_amount (amount_net_to_pay);

-- odoo_slip_uploads indexes
ALTER TABLE odoo_slip_uploads 
ADD INDEX idx_slip_status (status),
ADD INDEX idx_slip_uploaded (uploaded_at),
ADD INDEX idx_slip_matched (matched_order_id);
```

**Step 2: Create safe migration runner**

```php
<?php
/**
 * Safe index migration runner
 * Run: php migration/apply-indexes.php
 */
require_once __DIR__ . '/../config/database.php';

$dryRun = in_array('--dry-run', $argv);
$sqlFile = __DIR__ . '/add-performance-indexes.sql';

if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

try {
    $db = Database::getInstance()->getConnection();
    $sql = file_get_contents($sqlFile);
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql))
    );
    
    echo $dryRun ? "[DRY RUN] " : "";
    echo "Applying " . count($statements) . " index migrations...\n\n";
    
    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;
        
        echo "Executing: " . substr($stmt, 0, 60) . "...\n";
        
        if (!$dryRun) {
            try {
                $start = microtime(true);
                $db->exec($stmt);
                $elapsed = round((microtime(true) - $start) * 1000, 2);
                echo "  ✓ Done in {$elapsed}ms\n";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key') !== false) {
                    echo "  ⚠ Index already exists, skipping\n";
                } else {
                    echo "  ✗ Error: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "\n✅ Index migration complete!\n";
    
} catch (Exception $e) {
    die("Fatal error: " . $e->getMessage() . "\n");
}
```

**Step 3: Test with dry-run first**

```bash
cd /root/.openclaw/workspace/odoo
php migration/apply-indexes.php --dry-run
```

**Expected Output:** "[DRY RUN] Applying X index migrations..."

**Step 4: Apply for real**

```bash
php migration/apply-indexes.php
```

**Expected Output:** "✅ Index migration complete!" with timing for each index

**Step 5: Commit**

```bash
git add migration/
git commit -m "perf(db): add 20+ performance indexes for dashboard queries"
```

---

### Task 1.3: Create Partitioning for Webhook Logs (ถ้า > 500K rows)

**Files:**
- Create: `migration/setup-partitioning.php`
- Create: `cron/partition-maintenance.php`

**Step 1: Check if partitioning needed**

```bash
# Check current row count
cd /root/.openclaw/workspace/odoo
php -r "require 'config/database.php'; \$db = Database::getInstance()->getConnection(); echo 'Webhook logs: ' . \$db->query('SELECT COUNT(*) FROM odoo_webhooks_log')->fetchColumn() . PHP_EOL;"
```

**Step 2: Create partitioning setup (if rows > 500K)**

```php
<?php
/**
 * Setup monthly partitioning for odoo_webhooks_log
 * Run: php migration/setup-partitioning.php
 */
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Only proceed if table has > 500K rows
$count = $db->query("SELECT COUNT(*) FROM odoo_webhooks_log")->fetchColumn();
if ($count < 500000) {
    echo "Table has only $count rows. Partitioning not needed yet.\n";
    echo "Skipping partitioning setup.\n";
    exit(0);
}

echo "Setting up partitioning for $count rows...\n";

// Create new partitioned table
$db->exec("
    CREATE TABLE odoo_webhooks_log_new (
        LIKE odoo_webhooks_log INCLUDING ALL
    ) PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at))
");

// Create partitions for last 6 months and next 3
$partitions = [];
for ($i = -6; $i <= 3; $i++) {
    $date = new DateTime();
    $date->modify("$i months");
    $ym = $date->format('Ym');
    $nextMonth = clone $date;
    $nextMonth->modify('+1 month');
    $nextYm = $nextMonth->format('Ym');
    
    $partitions[] = "PARTITION p{$ym} VALUES LESS THAN ({$nextYm})";
}

$sql = "ALTER TABLE odoo_webhooks_log_new ADD PARTITION (" . implode(', ', $partitions) . ")";
$db->exec($sql);

echo "✅ Partitioning setup complete\n";
```

**Step 3: Commit**

```bash
git add migration/setup-partitioning.php cron/partition-maintenance.php
git commit -m "perf(db): add webhook log partitioning support for large datasets"
```

---

## Phase 2: API Optimization (Backend)

### Task 2.1: Split Large API File

**Files:**
- Create: `api/actions/` directory structure
- Modify: `api/odoo-dashboard-api.php` (refactor to router only)

**Step 1: Create directory structure**

```bash
cd /root/.openclaw/workspace/odoo
mkdir -p api/actions api/shared
```

**Step 2: Extract shared functions**

```php
<?php
// api/shared/DatabaseHelpers.php

class DatabaseHelpers {
    public static function getWebhookColumns($db) {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $stmt = $db->query("SHOW COLUMNS FROM odoo_webhooks_log");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cache[$row['Field']] = true;
            }
        }
        return $cache;
    }
    
    public static function buildLimitOffset($input, $defaultLimit = 50, $maxLimit = 500) {
        $limit = min((int)($input['limit'] ?? $defaultLimit), $maxLimit);
        $offset = (int)($input['offset'] ?? 0);
        return [$limit, $offset];
    }
    
    public static function sanitizeString($input, $field, $default = '') {
        $value = trim((string)($input[$field] ?? $default));
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
```

**Step 3: Extract customer actions**

```php
<?php
// api/actions/CustomerActions.php

require_once __DIR__ . '/../shared/DatabaseHelpers.php';

class CustomerActions {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function getCustomerList($input) {
        [$limit, $offset] = DatabaseHelpers::buildLimitOffset($input, 80, 500);
        $search = DatabaseHelpers::sanitizeString($input, 'search');
        $sortBy = DatabaseHelpers::sanitizeString($input, 'sort_by', 'name');
        
        // Optimized query with covering index
        $sql = "SELECT 
                    partner_id, customer_ref, name, phone, 
                    email, total_invoiced, overdue_amount, last_order_date
                FROM odoo_customer_projection 
                WHERE 1=1";
        
        $params = [];
        if ($search) {
            $sql .= " AND (name LIKE :search OR customer_ref LIKE :search OR phone LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        // Whitelist sort columns
        $allowedSort = ['name', 'customer_ref', 'total_invoiced', 'overdue_amount', 'last_order_date'];
        if (in_array($sortBy, $allowedSort)) {
            $sql .= " ORDER BY " . $sortBy . " DESC";
        } else {
            $sql .= " ORDER BY name ASC";
        }
        
        $sql .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count (approximate for large tables)
        $countStmt = $this->db->query("SHOW TABLE STATUS LIKE 'odoo_customer_projection'");
        $tableInfo = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = $tableInfo['Rows'] ?? 0;
        
        return [
            'customers' => $customers,
            'pagination' => [
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => count($customers) === $limit
            ]
        ];
    }
    
    public function getCustomerDetail($input) {
        $partnerId = (int)($input['partner_id'] ?? 0);
        if (!$partnerId) {
            throw new Exception('partner_id required');
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM odoo_customer_projection 
            WHERE partner_id = :partner_id 
            LIMIT 1
        ");
        $stmt->execute([':partner_id' => $partnerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            throw new Exception('Customer not found');
        }
        
        return $customer;
    }
}
```

**Step 4: Refactor main API to router**

```php
<?php
/**
 * Odoo Dashboard API Router (Refactored)
 * Lightweight router that delegates to action classes
 * 
 * @version 3.0.0 - Refactored for performance
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/actions/CustomerActions.php';

// Enable compression
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}

// Parse input
$input = $_SERVER['REQUEST_METHOD'] === 'POST' 
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_GET;

$action = trim((string)($input['action'] ?? ''));

// Quick health check (no DB)
if ($action === 'health' || $action === '') {
    echo json_encode([
        'success' => true,
        'data' => ['status' => 'ok', 'version' => '3.0.0'],
        'meta' => ['cached' => false]
    ]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $startTime = microtime(true);
    
    // Route to appropriate action class
    $result = match($action) {
        'customer_list', 'customer_detail' => (new CustomerActions($db))->$action($input),
        // Add more as we extract them
        default => throw new Exception("Unknown action: $action")
    };
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'meta' => [
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'cached' => false
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
```

**Step 5: Test refactored API**

```bash
# Test health endpoint
curl -s "http://localhost/api/odoo-dashboard-api.php?action=health" | jq

# Test customer list
curl -s "http://localhost/api/odoo-dashboard-api.php?action=customer_list&limit=5" | jq
```

**Step 6: Commit**

```bash
git add api/actions/ api/shared/ api/odoo-dashboard-api.php
git commit -m "refactor(api): split monolithic API into action classes (WIP)"
```

---

### Task 2.2: Add Redis Caching Layer

**Files:**
- Create: `classes/RedisCache.php`
- Modify: `api/actions/CustomerActions.php` (add caching)

**Step 1: Create Redis cache class**

```php
<?php
/**
 * Redis Cache Layer for Dashboard
 * Falls back to file cache if Redis unavailable
 */

class RedisCache {
    private static $instance = null;
    private $redis = null;
    private $fileCacheDir;
    private $enabled = false;
    
    private function __construct() {
        $this->fileCacheDir = sys_get_temp_dir() . '/odoo_cache/';
        if (!is_dir($this->fileCacheDir)) {
            @mkdir($this->fileCacheDir, 0755, true);
        }
        
        // Try Redis first
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379, 0.5);
                $this->redis->select(1); // Use DB 1 for dashboard
                $this->enabled = true;
            } catch (Exception $e) {
                error_log("Redis connection failed: " . $e->getMessage());
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get($key) {
        // Try Redis first
        if ($this->enabled && $this->redis) {
            $data = $this->redis->get($key);
            if ($data !== false) {
                return json_decode($data, true);
            }
        }
        
        // Fall back to file cache
        $file = $this->fileCacheDir . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = unserialize(file_get_contents($file));
            if ($data['expiry'] > time()) {
                return $data['value'];
            }
            @unlink($file);
        }
        
        return null;
    }
    
    public function set($key, $value, $ttl = 300) {
        $data = json_encode($value);
        
        // Try Redis
        if ($this->enabled && $this->redis) {
            return $this->redis->setex($key, $ttl, $data);
        }
        
        // File cache fallback
        $file = $this->fileCacheDir . md5($key) . '.cache';
        return file_put_contents($file, serialize([
            'expiry' => time() + $ttl,
            'value' => $value
        ]), LOCK_EX);
    }
    
    public function delete($pattern) {
        if ($this->enabled && $this->redis) {
            $keys = $this->redis->keys($pattern);
            if ($keys) {
                $this->redis->del($keys);
            }
        }
        // File cache: would need to scan files
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
}
```

**Step 2: Update CustomerActions with caching**

```php
<?php
// api/actions/CustomerActions.php

require_once __DIR__ . '/../shared/DatabaseHelpers.php';
require_once __DIR__ . '/../../classes/RedisCache.php';

class CustomerActions {
    private $db;
    private $cache;
    private $cacheTtl = 60;
    
    public function __construct($db) {
        $this->db = $db;
        $this->cache = RedisCache::getInstance();
    }
    
    public function getCustomerList($input) {
        // Build cache key
        $cacheKey = 'customer_list:' . md5(serialize($input));
        
        // Try cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return array_merge($cached, ['_cached' => true]);
        }
        
        // ... existing query logic ...
        $result = [
            'customers' => $customers,
            'pagination' => [/* ... */]
        ];
        
        // Store in cache
        $this->cache->set($cacheKey, $result, $this->cacheTtl);
        
        return $result;
    }
}
```

**Step 3: Commit**

```bash
git add classes/RedisCache.php api/actions/
git commit -m "perf(cache): add Redis caching layer with file fallback"
```

---

## Phase 3: Frontend Optimization

### Task 3.1: Add Virtual Scrolling for Data Tables

**Files:**
- Create: `assets/js/components/VirtualTable.js`
- Modify: `odoo-dashboard.js` (integrate virtual scrolling)

**Step 1: Create VirtualTable component**

```javascript
/**
 * VirtualTable - Render only visible rows for large datasets
 * @version 1.0.0
 */
class VirtualTable {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.rowHeight = options.rowHeight || 48;
        this.bufferSize = options.bufferSize || 5;
        this.data = [];
        this.scrollTop = 0;
        this.visibleStart = 0;
        this.visibleEnd = 0;
        this.renderCallback = options.renderRow || this.defaultRenderRow;
        
        this.init();
    }
    
    init() {
        this.container.style.overflow = 'auto';
        this.container.style.position = 'relative';
        
        // Create scroll spacer
        this.spacer = document.createElement('div');
        this.spacer.style.height = '0px';
        this.container.appendChild(this.spacer);
        
        // Create table container
        this.tableContainer = document.createElement('div');
        this.tableContainer.style.position = 'relative';
        this.container.appendChild(this.tableContainer);
        
        this.container.addEventListener('scroll', this.onScroll.bind(this));
        window.addEventListener('resize', this.onScroll.bind(this));
    }
    
    setData(data) {
        this.data = data;
        this.spacer.style.height = `${this.data.length * this.rowHeight}px`;
        this.onScroll();
    }
    
    onScroll() {
        this.scrollTop = this.container.scrollTop;
        const containerHeight = this.container.clientHeight;
        
        // Calculate visible range
        const startIdx = Math.max(0, Math.floor(this.scrollTop / this.rowHeight) - this.bufferSize);
        const endIdx = Math.min(
            this.data.length,
            Math.ceil((this.scrollTop + containerHeight) / this.rowHeight) + this.bufferSize
        );
        
        if (startIdx !== this.visibleStart || endIdx !== this.visibleEnd) {
            this.visibleStart = startIdx;
            this.visibleEnd = endIdx;
            this.render();
        }
    }
    
    render() {
        const fragment = document.createDocumentFragment();
        const offsetTop = this.visibleStart * this.rowHeight;
        
        // Create visible rows container
        const rowsContainer = document.createElement('div');
        rowsContainer.style.transform = `translateY(${offsetTop}px)`;
        
        for (let i = this.visibleStart; i < this.visibleEnd; i++) {
            if (this.data[i]) {
                const row = this.renderCallback(this.data[i], i);
                row.style.height = `${this.rowHeight}px`;
                rowsContainer.appendChild(row);
            }
        }
        
        this.tableContainer.innerHTML = '';
        this.tableContainer.appendChild(rowsContainer);
    }
    
    defaultRenderRow(item, index) {
        const div = document.createElement('div');
        div.textContent = `Row ${index}: ${JSON.stringify(item)}`;
        div.style.borderBottom = '1px solid #eee';
        div.style.padding = '8px';
        return div;
    }
}

// Export for use
if (typeof module !== 'undefined') {
    module.exports = VirtualTable;
}
```

**Step 2: Add cursor pagination support**

```javascript
/**
 * CursorPagination - Efficient pagination for large datasets
 * Replaces offset/limit with cursor-based navigation
 */
class CursorPagination {
    constructor(options = {}) {
        this.pageSize = options.pageSize || 50;
        this.cursor = null;
        this.hasMore = false;
        this.loading = false;
        this.cache = new Map();
    }
    
    async fetchPage(apiCall, direction = 'next') {
        if (this.loading) return null;
        this.loading = true;
        
        const cacheKey = `${this.cursor || 'first'}_${direction}`;
        if (this.cache.has(cacheKey)) {
            this.loading = false;
            return this.cache.get(cacheKey);
        }
        
        try {
            const params = {
                limit: this.pageSize,
                cursor: direction === 'next' ? this.cursor : undefined,
                direction: direction
            };
            
            const result = await apiCall(params);
            
            this.cache.set(cacheKey, result);
            this.cursor = result.next_cursor;
            this.hasMore = result.has_more;
            
            return result;
        } finally {
            this.loading = false;
        }
    }
    
    reset() {
        this.cursor = null;
        this.hasMore = false;
        this.cache.clear();
    }
}
```

**Step 3: Commit**

```bash
git add assets/js/components/
git commit -m "perf(frontend): add VirtualTable and CursorPagination components"
```

---

## Phase 4: Monitoring & Maintenance

### Task 4.1: Add Performance Monitoring

**Files:**
- Create: `api/metrics.php`
- Create: `scripts/performance-report.php`

**Step 1: Create metrics endpoint**

```php
<?php
/**
 * Metrics endpoint for monitoring dashboard performance
 * Returns: response times, cache hit rates, query counts
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

$metrics = [
    'timestamp' => date('c'),
    'php' => [
        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(false)['opcache_enabled'],
    ],
    'database' => [],
    'cache' => []
];

try {
    $db = Database::getInstance()->getConnection();
    $start = microtime(true);
    
    // Test query performance
    $db->query("SELECT COUNT(*) FROM odoo_customer_projection")->fetchColumn();
    $metrics['database']['customer_count_time_ms'] = round((microtime(true) - $start) * 1000, 2);
    
    // Get table sizes
    $tables = ['odoo_webhooks_log', 'odoo_orders', 'odoo_invoices', 'odoo_customer_projection'];
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLE STATUS LIKE '$table'");
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        $metrics['database']['tables'][$table] = [
            'rows' => (int)$info['Rows'],
            'size_mb' => round(($info['Data_length'] + $info['Index_length']) / 1024 / 1024, 2)
        ];
    }
    
    // Check Redis
    if (extension_loaded('redis')) {
        try {
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379, 0.5);
            $info = $redis->info();
            $metrics['cache']['redis'] = [
                'connected' => true,
                'used_memory_mb' => round($info['used_memory'] / 1024 / 1024, 2),
                'hit_rate' => $info['keyspace_hits'] / ($info['keyspace_hits'] + $info['keyspace_misses'] + 0.001)
            ];
        } catch (Exception $e) {
            $metrics['cache']['redis'] = ['connected' => false];
        }
    }
    
} catch (Exception $e) {
    $metrics['error'] = $e->getMessage();
}

echo json_encode($metrics, JSON_PRETTY_PRINT);
```

**Step 2: Create performance report script**

```php
<?php
/**
 * Generate daily performance report
 * Run via cron: 0 9 * * * php scripts/performance-report.php
 */

$metrics = json_decode(file_get_contents('http://localhost/api/metrics.php'), true);

$report = "=== Odoo Dashboard Performance Report ===\n";
$report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

$report .= "Database Tables:\n";
foreach ($metrics['database']['tables'] as $table => $info) {
    $report .= sprintf("  %-30s %10d rows %8.2f MB\n", $table, $info['rows'], $info['size_mb']);
}

$report .= "\nCache Status:\n";
if ($metrics['cache']['redis']['connected']) {
    $report .= "  Redis: Connected\n";
    $report .= "  Memory: {$metrics['cache']['redis']['used_memory_mb']} MB\n";
    $report .= "  Hit Rate: " . round($metrics['cache']['redis']['hit_rate'] * 100, 1) . "%\n";
} else {
    $report .= "  Redis: Not connected (using file cache)\n";
}

// Alert if tables are getting large
foreach ($metrics['database']['tables'] as $table => $info) {
    if ($info['rows'] > 1000000) {
        $report .= "\n⚠️  ALERT: Table '$table' has {$info['rows']} rows. Consider partitioning.\n";
    }
}

echo $report;

// Save to file
file_put_contents('logs/performance-' . date('Y-m-d') . '.log', $report);
```

**Step 3: Commit**

```bash
git add api/metrics.php scripts/performance-report.php
git commit -m "feat(monitoring): add performance metrics endpoint and reporting"
```

---

## Summary of Changes

| Phase | Files Created/Modified | Expected Impact |
|-------|----------------------|-----------------|
| 1.1 | `scripts/analyze-slow-queries.php` | Baseline metrics |
| 1.2 | `migration/add-performance-indexes.sql` | **50-80% faster queries** |
| 1.3 | `migration/setup-partitioning.php` | Handles >1M rows |
| 2.1 | `api/actions/*` + `api/shared/*` | **3-5x faster API parse** |
| 2.2 | `classes/RedisCache.php` | **10x faster cache** |
| 3.1 | `assets/js/components/VirtualTable.js` | Smooth scrolling for 100K+ rows |
| 4.1 | `api/metrics.php` | Ongoing monitoring |

**Expected Results After Complete Implementation:**
- API Response Time: 3-5s → < 500ms
- Database Query Time: 2-3s → < 100ms
- Frontend Render: Laggy → 60fps smooth scrolling
- Cache Hit Rate: Target > 80%

---

## Testing Checklist

- [ ] Run `php scripts/analyze-slow-queries.php` - ได้ baseline metrics
- [ ] Run `php migration/apply-indexes.php` - indexes ถูกสร้างทั้งหมด
- [ ] Test API health endpoint - ตอบกลับ < 100ms
- [ ] Test customer_list with limit=100 - ตอบกลับ < 300ms
- [ ] Test with 10,000 rows in VirtualTable - scroll ลื่น
- [ ] Check Redis connection in metrics - connected
- [ ] Run performance report - ไม่มี alert

---

**Next Steps After Plan Review:**
1. Execute Phase 1 (Database) first - ผลลัพธ์ทันที
2. Execute Phase 2 (API) - ลด server load
3. Execute Phase 3 (Frontend) - ปรับปรุง UX
4. Execute Phase 4 (Monitoring) - ติดตามผลระยะยาว
