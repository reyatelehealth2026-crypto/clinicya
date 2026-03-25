<?php
/**
 * Odoo Dashboard — Shared Utility Functions
 *
 * Common helpers used by both odoo-dashboard-api.php and odoo-webhooks-dashboard.php.
 * Extracted to eliminate ~200 lines of code duplication between the two API files.
 *
 * Functions included:
 * - hasWebhookColumn()
 * - resolveWebhookTimeColumn()
 * - webhookRecentWindowWhere()
 * - webhookCustomerSortExpr()
 * - tableExists()
 * - dashboardApiShouldCache() / dashboardApiBuildCacheKey() / etc.
 *
 * @version 2.0.0
 * @created 2026-03-16
 * @updated 2026-03-16 — APCu caching layer, batch schema detection, optimized
 *          file cache with atomic writes.
 */

if (defined('_ODOO_DASHBOARD_FUNCTIONS_LOADED')) {
    return;
}
define('_ODOO_DASHBOARD_FUNCTIONS_LOADED', true);

/**
 * Check if a column exists in odoo_webhooks_log table.
 * Uses a static cache per-request and APCu across requests.
 */
if (!function_exists('hasWebhookColumn')) {
    function hasWebhookColumn($db, $column)
    {
        static $cache = null;

        $column = (string) $column;
        if ($column === '') {
            return false;
        }

        // Lazy-load all webhook columns in one query (batch detection)
        if ($cache === null) {
            $cache = _loadWebhookColumns($db);
        }

        return isset($cache[$column]);
    }
}

/**
 * Batch-load all column names from odoo_webhooks_log in a single query.
 * Caches the result in file (5min TTL) and APCu (if available) across requests.
 * Optimized for shared hosting without APCu/OPcache.
 */
if (!function_exists('_loadWebhookColumns')) {
    function _loadWebhookColumns($db)
    {
        // Use file-based cache for shared hosting without APCu
        $dbName = defined('DB_NAME') ? DB_NAME : 'default';
        $cacheFile = sys_get_temp_dir() . '/odoo_wh_cols_' . md5($dbName) . '.cache';
        $cacheTtl = 300; // 5 minutes

        // Check file cache first
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = @json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        // Try APCu if available (faster than file)
        $apcuKey = 'odoo_wh_cols_' . crc32($dbName);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($cached) && !empty($cached)) {
                // Sync to file cache for next request
                @file_put_contents($cacheFile, json_encode($cached), LOCK_EX);
                return $cached;
            }
        }

        $columns = [];

        // Optimized: Use SHOW COLUMNS first (faster than information_schema on shared hosting)
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `odoo_webhooks_log`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[$row['Field']] = true;
            }
        } catch (Exception $e) {
            // Fallback to information_schema
            try {
                $stmt = $db->prepare("
                    SELECT COLUMN_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'odoo_webhooks_log'
                ");
                $stmt->execute();
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $columns[$row[0]] = true;
                }
            } catch (Exception $e2) {
                // Table might not exist - return hardcoded defaults
                $columns = [
                    'id' => true, 'event_type' => true, 'payload' => true,
                    'status' => true, 'created_at' => true, 'processed_at' => true
                ];
            }
        }

        // Save to both caches
        if (!empty($columns)) {
            @file_put_contents($cacheFile, json_encode($columns), LOCK_EX);
            if (function_exists('apcu_store')) {
                apcu_store($apcuKey, $columns, 30);
            }
        }

        return $columns;
    }
}

/**
 * Resolve the best available webhook timestamp column expression.
 * Cached result for the lifetime of the request.
 */
if (!function_exists('resolveWebhookTimeColumn')) {
    function resolveWebhookTimeColumn($db)
    {
        static $resolved = false;
        static $result = null;

        if ($resolved) {
            return $result;
        }

        foreach (['processed_at', 'created_at', 'received_at', 'updated_at'] as $column) {
            if (hasWebhookColumn($db, $column)) {
                $result = "`{$column}`";
                $resolved = true;
                return $result;
            }
        }

        $resolved = true;
        return null;
    }
}

/**
 * Build WHERE clause to limit webhook queries to a recent window.
 */
if (!function_exists('webhookRecentWindowWhere')) {
    function webhookRecentWindowWhere($db, $processedAtColumn, $days = 180, $maxRows = 80000)
    {
        $days = max(1, (int) $days);
        $maxRows = max(1000, (int) $maxRows);

        if ($processedAtColumn) {
            return "{$processedAtColumn} >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
        }

        return "id >= GREATEST((SELECT MAX(id) - {$maxRows} FROM odoo_webhooks_log), 0)";
    }
}

/**
 * Get ORDER BY expression for webhook fallback customer list sorting.
 */
if (!function_exists('webhookCustomerSortExpr')) {
    function webhookCustomerSortExpr($sortBy)
    {
        $map = [
            'activity_desc' => 'latest_order_at DESC',
            'spend_desc'  => 'spend_30d DESC, latest_order_at DESC',
            'spend_asc'   => 'spend_30d ASC, latest_order_at DESC',
            'orders_desc' => 'orders_total DESC, latest_order_at DESC',
            'orders_asc'  => 'orders_total ASC, latest_order_at DESC',
            'due_desc'    => 'total_due DESC, latest_order_at DESC',
            'name_asc'    => 'customer_name ASC',
            'name_desc'   => 'customer_name DESC',
        ];
        return $map[$sortBy] ?? 'latest_order_at DESC';
    }
}

/**
 * Check if a MySQL table exists (with in-request caching + file-based caching).
 * Optimized for shared hosting without APCu.
 */
if (!function_exists('tableExists')) {
    function tableExists($db, $table)
    {
        static $cache = [];

        $table = (string) $table;
        if ($table === '') {
            return false;
        }

        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        // Try file-based cache (5 min TTL)
        $dbName = defined('DB_NAME') ? DB_NAME : 'default';
        $cacheFile = sys_get_temp_dir() . '/tbl_exists_' . md5($dbName . $table) . '.cache';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false) {
                $cache[$table] = (bool) $cached;
                return $cache[$table];
            }
        }

        // Try APCu if available
        $apcuKey = 'tbl_exists_' . crc32($dbName . $table);
        if (function_exists('apcu_fetch')) {
            $val = apcu_fetch($apcuKey, $hit);
            if ($hit) {
                $cache[$table] = (bool) $val;
                @file_put_contents($cacheFile, $cache[$table] ? '1' : '', LOCK_EX);
                return $cache[$table];
            }
        }

        // Query database - use SHOW TABLES (faster than information_schema)
        try {
            $quoted = $db->quote($table);
            $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
            $exists = $stmt ? ($stmt->rowCount() > 0) : false;
            
            if (!$exists) {
                // Fallback to information_schema
                $stmt = $db->prepare("
                    SELECT 1
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                    LIMIT 1
                ");
                $stmt->execute([$table]);
                $exists = (bool) $stmt->fetchColumn();
            }
            $cache[$table] = $exists;
        } catch (Exception $e) {
            $cache[$table] = false;
        }

        // Save to both caches
        @file_put_contents($cacheFile, $cache[$table] ? '1' : '', LOCK_EX);
        if (function_exists('apcu_store')) {
            apcu_store($apcuKey, $cache[$table], 60);
        }

        return $cache[$table];
    }
}

// =====================================================================
// Dashboard API Cache Helpers (Redis L0 + APCu L1 + File L2 — triple layer)
// Redis ลด latency จาก ~50ms (file) → <1ms, shared across PHP-FPM workers
// =====================================================================

// Load Redis adapter if available
if (!class_exists('RedisCache') && file_exists(__DIR__ . '/../classes/RedisCache.php')) {
    require_once __DIR__ . '/../classes/RedisCache.php';
}

if (!function_exists('dashboardApiShouldCache')) {
    function dashboardApiShouldCache($action, $input, $result)
    {
        if (!is_array($result)) {
            return false;
        }

        if (!empty($result['error'])) {
            return false;
        }

        if ($action === 'customer_list' && trim((string) ($input['search'] ?? '')) !== '') {
            return false;
        }

        if ($action === 'customer_full_detail') {
            $pid = trim((string) ($input['partner_id'] ?? ''));
            $ref = trim((string) ($input['customer_ref'] ?? ''));
            if ($pid === '' && $ref === '') {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('dashboardApiBuildCacheKey')) {
    function dashboardApiBuildCacheKey($action, $input)
    {
        if (is_array($input)) {
            unset($input['_t']);
            dashboardApiNormalizeCacheInput($input);
        }

        return $action . '_' . sha1(json_encode($input, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('dashboardApiNormalizeCacheInput')) {
    function dashboardApiNormalizeCacheInput(&$value)
    {
        if (!is_array($value)) {
            return;
        }

        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                dashboardApiNormalizeCacheInput($item);
            }
        }
        unset($item);
    }
}

if (!function_exists('dashboardApiCacheDir')) {
    function dashboardApiCacheDir()
    {
        static $dir = null;
        if ($dir !== null) {
            return $dir;
        }

        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cny_odoo_dashboard_cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}

if (!function_exists('dashboardApiCachePath')) {
    function dashboardApiCachePath($key)
    {
        return dashboardApiCacheDir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
    }
}

/**
 * Cache read — tries Redis (L0) → APCu (L1) → File (L2).
 * Promotes data upward on miss for faster subsequent reads.
 */
if (!function_exists('dashboardApiCacheGet')) {
    function dashboardApiCacheGet($key, $ttl)
    {
        // L0: Redis (shared across all PHP-FPM workers, <1ms)
        if (class_exists('RedisCache')) {
            $redis = RedisCache::getInstance();
            if ($redis->isConnected()) {
                $data = $redis->get($key);
                if ($data !== null && is_array($data)) {
                    return $data;
                }
            }
        }

        // L1: APCu (per-worker, ~0.1ms)
        if (function_exists('apcu_fetch')) {
            $apcuKey = 'dash_' . $key;
            $data = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($data)) {
                // Promote to Redis
                if (class_exists('RedisCache')) {
                    $redis = RedisCache::getInstance();
                    if ($redis->isConnected()) {
                        $redis->set($key, $data, $ttl);
                    }
                }
                return $data;
            }
        }

        // L2: File-based (disk I/O, ~5-50ms)
        $path = dashboardApiCachePath($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['t'])) {
            @unlink($path);
            return null;
        }

        if ((time() - (int) $payload['t']) > $ttl) {
            @unlink($path);
            return null;
        }

        $data = $payload['d'] ?? null;

        // Promote to L0 + L1 for faster subsequent reads
        if ($data !== null) {
            if (function_exists('apcu_store')) {
                apcu_store('dash_' . $key, $data, $ttl);
            }
            if (class_exists('RedisCache')) {
                $redis = RedisCache::getInstance();
                if ($redis->isConnected()) {
                    $redis->set($key, $data, $ttl);
                }
            }
        }

        return $data;
    }
}

/**
 * Cache write — writes to Redis (L0) + APCu (L1) + File (L2) atomically.
 */
if (!function_exists('dashboardApiCacheSet')) {
    function dashboardApiCacheSet($key, $data, $ttl = 60)
    {
        // L0: Redis (primary, shared)
        if (class_exists('RedisCache')) {
            $redis = RedisCache::getInstance();
            if ($redis->isConnected()) {
                $redis->set($key, $data, $ttl);
            }
        }

        // L1: APCu (per-worker)
        if (function_exists('apcu_store')) {
            apcu_store('dash_' . $key, $data, $ttl);
        }

        // L2: File (atomic write via rename — fallback if Redis dies)
        $path = dashboardApiCachePath($key);
        $tmpPath = $path . '.' . getmypid() . '.tmp';
        $payload = json_encode([
            't' => time(),
            'd' => $data,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload !== false) {
            if (@file_put_contents($tmpPath, $payload, LOCK_EX) !== false) {
                @rename($tmpPath, $path);
            }
            @unlink($tmpPath); // cleanup if rename failed
        }
    }
}

/**
 * Purge expired cache entries (call from cron, not every request).
 */
if (!function_exists('dashboardApiCachePurge')) {
    function dashboardApiCachePurge($maxAge = 300)
    {
        $dir = dashboardApiCacheDir();
        $cutoff = time() - $maxAge;
        $count = 0;

        foreach (glob($dir . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }
}

/**
 * Get BDO records from odoo_bdos sync table (full columns).
 * Falls back to webhook log JSON extraction if table unavailable.
 *
 * @param array $input Optional keys: partner_id, line_user_id, customer_ref, limit, offset,
 *                     payment_filter = 'unpaid' (only BDOs with no completed payment in sync table)
 */
if (!function_exists('getOdooBdos')) {
    function getOdooBdos($db, $input)
    {
        $partnerId   = trim((string) ($input['partner_id']   ?? ''));
        $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
        $customerRef = trim((string) ($input['customer_ref'] ?? ''));
        $limit       = min((int) ($input['limit']  ?? 100), 500);
        $offset      = max((int) ($input['offset'] ?? 0), 0);
        $paymentFilterUnpaid = (trim((string) ($input['payment_filter'] ?? '')) === 'unpaid');

        // Resolve line_user_id from partner_id if not provided
        if ($lineUserId === '' && $partnerId !== '' && $partnerId !== '-') {
            try {
                $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
                $stmt->execute([(int) $partnerId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $lineUserId = $row['line_user_id'];
                }
            } catch (Exception $e) { /* ignore */
            }
        }

        // Try dedicated sync table first
        try {
            $hasPaymentStateCol = false;
            $hasAmountNetCol    = false;
            try {
                $chk = $db->query("SHOW COLUMNS FROM odoo_bdos LIKE 'payment_state'");
                $hasPaymentStateCol = $chk && $chk->rowCount() > 0;
                $chk2 = $db->query("SHOW COLUMNS FROM odoo_bdos LIKE 'amount_net_to_pay'");
                $hasAmountNetCol = $chk2 && $chk2->rowCount() > 0;
            } catch (Exception $e) { /* ignore */
            }

            $where = [];
            $params = [];

            if ($partnerId !== '' && $partnerId !== '-') {
                $where[] = 'partner_id = ?';
                $params[] = (int) $partnerId;
            } elseif ($lineUserId !== '') {
                $where[] = 'line_user_id = ?';
                $params[] = $lineUserId;
            } elseif ($customerRef !== '') {
                $where[] = 'customer_ref = ?';
                $params[] = $customerRef;
            }

            if ($paymentFilterUnpaid) {
                if ($hasPaymentStateCol) {
                    $where[] = '(payment_state IS NULL OR LOWER(TRIM(payment_state)) NOT IN (\'paid\',\'done\',\'invoiced\'))';
                }
                $where[] = '(state IS NULL OR LOWER(TRIM(state)) NOT IN (\'cancel\',\'done\'))';
            }

            $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $totalStmt = $db->prepare("SELECT COUNT(*) FROM odoo_bdos {$whereClause}");
            $totalStmt->execute($params);
            $total = (int) $totalStmt->fetchColumn();

            if ($total > 0 || $whereClause !== '') {
                $extraCols = '';
                if ($hasPaymentStateCol) {
                    $extraCols .= ', payment_state';
                }
                if ($hasAmountNetCol) {
                    $extraCols .= ', amount_net_to_pay';
                }
                $sql = "
                SELECT
                    id, bdo_id, bdo_name,
                    order_id, order_name,
                    partner_id, customer_ref, line_user_id,
                    salesperson_id, salesperson_name,
                    state, amount_total, currency,
                    bdo_date, expected_delivery{$extraCols},
                    latest_event, synced_at, updated_at
                FROM odoo_bdos
                {$whereClause}
                ORDER BY updated_at DESC
                LIMIT ? OFFSET ?
            ";
                $params[] = $limit;
                $params[] = $offset;
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $bdos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($bdos as &$b) {
                    $b['id']            = (int) $b['id'];
                    $b['bdo_id']        = (int) $b['bdo_id'];
                    $b['partner_id']    = $b['partner_id']    !== null ? (int) $b['partner_id']    : null;
                    $b['order_id']      = $b['order_id']      !== null ? (int) $b['order_id']      : null;
                    $b['salesperson_id'] = $b['salesperson_id'] !== null ? (int) $b['salesperson_id'] : null;
                    $b['amount_total']  = $b['amount_total']  !== null ? (float) $b['amount_total']  : null;
                    if ($hasAmountNetCol) {
                        $b['amount_net_to_pay'] = isset($b['amount_net_to_pay']) && $b['amount_net_to_pay'] !== null ? (float) $b['amount_net_to_pay'] : null;
                    }
                }
                unset($b);

                // Backfill NULL bdo_date from webhook log
                $nullBdos = array_filter($bdos, function ($b) {
                    return !$b['bdo_date'] && $b['bdo_name'];
                });
                if (!empty($nullBdos)) {
                    try {
                        $names = array_map(function ($b) {
                            return $b['bdo_name'];
                        }, $nullBdos);
                        $placeholders = implode(',', array_fill(0, count($names), '?'));
                        $bdoNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.bdo_name')),'')";
                        $dateExpr    = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.bdo_date')),'')";
                        $wbStmt = $db->prepare("
                        SELECT {$bdoNameExpr} AS bdo_name,
                               MAX({$dateExpr}) AS bdo_date,
                               MAX(processed_at) AS processed_at
                        FROM odoo_webhooks_log
                        WHERE event_type LIKE 'bdo.%'
                          AND {$bdoNameExpr} IN ({$placeholders})
                        GROUP BY {$bdoNameExpr}
                    ");
                        $wbStmt->execute($names);
                        $wbMap = [];
                        foreach ($wbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $wbMap[$row['bdo_name']] = $row;
                        }
                        foreach ($bdos as &$b) {
                            if (!$b['bdo_date'] && isset($wbMap[$b['bdo_name']])) {
                                $wb = $wbMap[$b['bdo_name']];
                                $b['bdo_date'] = $wb['bdo_date'] ?: $wb['processed_at'] ?: null;
                            }
                        }
                        unset($b);
                    } catch (Exception $e) { /* ignore */
                    }
                }

                return ['bdos' => $bdos, 'total' => $total, 'source' => 'sync_table', 'limit' => $limit, 'offset' => $offset];
            }
        } catch (Exception $e) {
            // column missing or other — fall through to webhook log
        }

        // Fallback: query from webhook log with JSON extraction
        $pidExpr       = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
        $refExpr       = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
        $bdoIdExpr     = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_id')), '')";
        $bdoNameExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_name')), '')";
        $amountExpr    = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '')";
        $dateExpr      = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_date')), '')";
        $stateExpr     = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), '')";
        $orderNameExpr = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.sale_orders[0].name'))";

        $fbWhere  = ["event_type LIKE 'bdo.%'"];
        $fbParams = [];
        if ($partnerId !== '' && $partnerId !== '-') {
            $fbWhere[] = "{$pidExpr} = ?";
            $fbParams[] = $partnerId;
        } elseif ($customerRef !== '') {
            $fbWhere[] = "{$refExpr} = ?";
            $fbParams[] = $customerRef;
        }
        $fbWhereClause = 'WHERE ' . implode(' AND ', $fbWhere);

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM odoo_webhooks_log {$fbWhereClause}");
            $stmt->execute($fbParams);
            $total = (int) $stmt->fetchColumn();

            $fbParams2 = $fbParams;
            $stmt = $db->prepare("
            SELECT id, event_type,
                {$bdoIdExpr} as bdo_id,
                {$bdoNameExpr} as bdo_name,
                {$orderNameExpr} as order_name,
                {$amountExpr} as amount_total,
                {$dateExpr} as bdo_date,
                {$stateExpr} as state,
                processed_at
            FROM odoo_webhooks_log {$fbWhereClause}
            ORDER BY processed_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
            $stmt->execute($fbParams2);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $bdos = [];
            foreach ($rows as $row) {
                $bdos[] = [
                    'id'           => (int) $row['id'],
                    'bdo_id'       => $row['bdo_id'] ? (int) $row['bdo_id'] : null,
                    'bdo_name'     => $row['bdo_name'] ?: null,
                    'order_name'   => $row['order_name'] ?: null,
                    'amount_total' => $row['amount_total'] ? (float) $row['amount_total'] : null,
                    'bdo_date'     => $row['bdo_date'] ?: $row['processed_at'],
                    'state'        => $row['state'] ?: 'confirmed',
                    'event_type'   => $row['event_type'],
                ];
            }
            if ($paymentFilterUnpaid) {
                $bdos = array_values(array_filter($bdos, function ($b) {
                    $st = strtolower((string) ($b['state'] ?? ''));

                    return !in_array($st, ['cancel', 'done', 'validated'], true);
                }));
            }

            return ['bdos' => $bdos, 'total' => $total, 'source' => 'webhook_log', 'limit' => $limit, 'offset' => $offset];
        } catch (Exception $e) {
            return ['bdos' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Primary dashboard entry for odoo_bdo_list_api (odoo-dashboard-api.php).
 */
if (!function_exists('getBdoListLive')) {
    function getBdoListLive($db, $input)
    {
        return getOdooBdos($db, $input);
    }
}
