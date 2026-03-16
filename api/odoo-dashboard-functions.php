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
 * Caches the result in APCu (30s TTL) and per-request static.
 */
if (!function_exists('_loadWebhookColumns')) {
    function _loadWebhookColumns($db)
    {
        $apcuKey = 'odoo_wh_cols_' . crc32(defined('DB_NAME') ? DB_NAME : '');

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        $columns = [];

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
        } catch (Exception $e) {
            try {
                $stmt = $db->query("SHOW COLUMNS FROM `odoo_webhooks_log`");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns[$row['Field']] = true;
                }
            } catch (Exception $e2) {
                // Table might not exist
            }
        }

        if (function_exists('apcu_store') && !empty($columns)) {
            apcu_store($apcuKey, $columns, 30);
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
 * Check if a MySQL table exists (with in-request caching + APCu).
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

        // Try APCu for cross-request caching
        $apcuKey = 'tbl_exists_' . crc32((defined('DB_NAME') ? DB_NAME : '') . $table);
        if (function_exists('apcu_fetch')) {
            $val = apcu_fetch($apcuKey, $hit);
            if ($hit) {
                $cache[$table] = (bool) $val;
                return $cache[$table];
            }
        }

        try {
            $stmt = $db->prepare("
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                LIMIT 1
            ");
            $stmt->execute([$table]);
            $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            $quoted = $db->quote($table);
            $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
            $cache[$table] = $stmt ? ($stmt->rowCount() > 0) : false;
        }

        if (function_exists('apcu_store')) {
            apcu_store($apcuKey, $cache[$table], 60);
        }

        return $cache[$table];
    }
}

// =====================================================================
// Dashboard API Cache Helpers (File + APCu dual layer)
// =====================================================================

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
 * Cache read — tries APCu first, then falls back to file.
 */
if (!function_exists('dashboardApiCacheGet')) {
    function dashboardApiCacheGet($key, $ttl)
    {
        // L1: APCu (fastest)
        if (function_exists('apcu_fetch')) {
            $apcuKey = 'dash_' . $key;
            $data = apcu_fetch($apcuKey, $hit);
            if ($hit && is_array($data)) {
                return $data;
            }
        }

        // L2: File-based
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

        // Promote to APCu for faster subsequent reads
        if ($data !== null && function_exists('apcu_store')) {
            apcu_store('dash_' . $key, $data, $ttl);
        }

        return $data;
    }
}

/**
 * Cache write — writes to both APCu and file atomically.
 */
if (!function_exists('dashboardApiCacheSet')) {
    function dashboardApiCacheSet($key, $data, $ttl = 60)
    {
        // L1: APCu
        if (function_exists('apcu_store')) {
            apcu_store('dash_' . $key, $data, $ttl);
        }

        // L2: File (atomic write via rename)
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
