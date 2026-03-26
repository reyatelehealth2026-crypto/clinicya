<?php
/**
 * Odoo Dashboard — Fast Endpoint
 *
 * Lightweight API (~100 lines) for time-critical dashboard actions.
 * Exists because the main odoo-dashboard-api.php is 4700+ lines / 182KB
 * and takes ~1.3s just to parse on servers without OPcache.
 *
 * Supported actions:
 *   health          — instant connectivity check (no DB)
 *   overview_fast   — KPI overview using indexed sync tables only (<500ms)
 *   circuit_breaker_status — monitor circuit breaker states
 *   circuit_breaker_reset  — manual reset
 *
 * For all other actions, the JS client falls back to odoo-dashboard-api.php.
 *
 * @version 1.0.0
 * @created 2026-03-16
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// Allow Nginx fastcgi_cache and browser cache for read-only actions
header('Cache-Control: private, max-age=15, stale-while-revalidate=30');
header('Vary: Accept-Encoding');

// Enable gzip compression for all responses
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$_startTime = microtime(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_GET;
}

$action = trim((string) ($input['action'] ?? ''));

// ── APCu-first cache (skip Redis — US-East RTT ~200ms, APCu ~0.05ms) ───
function _fastCacheGet($key, $ttl) {
    // L1: APCu (in-process, ~0.05ms)
    if (function_exists('apcu_fetch')) {
        $data = apcu_fetch('fast_' . $key, $hit);
        if ($hit && is_array($data)) return $data;
    }
    // L2: File fallback
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/cny_fast_cache/' . md5($key) . '.json';
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $p = json_decode($raw, true);
            if (is_array($p) && isset($p['t']) && (time() - (int)$p['t']) <= $ttl) {
                $data = $p['d'];
                if (function_exists('apcu_store')) apcu_store('fast_' . $key, $data, $ttl);
                return $data;
            }
        }
    }
    return null;
}
function _fastCacheSet($key, $data, $ttl) {
    if (function_exists('apcu_store')) apcu_store('fast_' . $key, $data, $ttl);
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/cny_fast_cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/' . md5($key) . '.json';
    @file_put_contents($path, json_encode(['t'=>time(),'d'=>$data], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ── health: instant, no DB ──────────────────────────────────────────────
if ($action === '' || $action === 'health') {
    // Include Redis status in health check
    $redisStatus = ['connected' => false];
    $redisCacheFile = __DIR__ . '/../classes/RedisCache.php';
    if (file_exists($redisCacheFile)) {
        require_once $redisCacheFile;
        try {
            $redis = RedisCache::getInstance();
            $redisStatus = $redis->getInfo();
        } catch (Exception $e) {
            $redisStatus = ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    // OPcache status
    $opcacheEnabled = function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);
    $opcacheStats = [];
    if ($opcacheEnabled) {
        $ocs = opcache_get_status(false);
        $opcacheStats = [
            'enabled' => true,
            'memory_used_mb' => round(($ocs['memory_usage']['used_memory'] ?? 0) / 1048576, 1),
            'memory_free_mb' => round(($ocs['memory_usage']['free_memory'] ?? 0) / 1048576, 1),
            'cached_scripts' => $ocs['opcache_statistics']['num_cached_scripts'] ?? 0,
            'hit_rate' => round($ocs['opcache_statistics']['opcache_hit_rate'] ?? 0, 1),
            'jit_enabled' => !empty($ocs['jit']['enabled']),
        ];
    } else {
        $opcacheStats = ['enabled' => false];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'status' => 'ok',
            'service' => 'odoo-dashboard-fast',
            'timestamp' => date('c'),
            'version' => '2.1.0',
            'php_version' => PHP_VERSION,
            'opcache' => $opcacheStats,
            'redis' => $redisStatus,
        ],
        '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => false, 'action' => 'health'],
    ]);
    exit;
}

// ── circuit_breaker_status / circuit_breaker_reset ───────────────────────
if ($action === 'circuit_breaker_status' || $action === 'circuit_breaker_reset') {
    require_once __DIR__ . '/../classes/OdooCircuitBreaker.php';

    if ($action === 'circuit_breaker_status') {
        $result = [
            'odoo_reya' => (new OdooCircuitBreaker('odoo_reya'))->getStatus(),
            'odoo_cny' => (new OdooCircuitBreaker('odoo_cny'))->getStatus(),
        ];
    } else {
        $service = trim((string) ($input['service'] ?? ''));
        if ($service === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing service parameter']);
            exit;
        }
        $cb = new OdooCircuitBreaker($service);
        $cb->reset();
        $result = ['reset' => true, 'status' => $cb->getStatus()];
    }

    echo json_encode([
        'success' => true,
        'data' => $result,
        '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => false, 'action' => $action],
    ]);
    exit;
}

// ── overview_fast: uses ONLY indexed sync tables ────────────────────────
if ($action === 'overview_fast') {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/odoo-dashboard-functions.php';

    // Cache: 30s (มี BDO/payment KPIs ต้อง fresh)
    $_cKey = 'overview_fast';
    $_cached = _fastCacheGet($_cKey, 30);
    if ($_cached !== null) {
        $_cached['_meta']['cached'] = true;
        $_cached['_meta']['duration_ms'] = round((microtime(true) - $_startTime) * 1000);
        echo json_encode($_cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    $r = [
        'orders_today' => 0,
        'sales_today' => 0.0,
        'orders' => [],
        'slips_pending' => 0,
        'bdos_pending' => 0,
        'bdos_pending_amount' => 0.0,
        'overdue_customers' => 0,
        'payments_today' => 0.0,
        'last_webhook' => null,
        'webhook_total_today' => 0,
        'webhook_success_rate' => 0,
    ];

    // Orders today — range predicates so MySQL can use index on date_order/updated_at
    try {
        $row = $db->query("SELECT COUNT(*) as c, COALESCE(SUM(amount_total),0) as s FROM odoo_orders WHERE COALESCE(date_order,updated_at) >= CURDATE() AND COALESCE(date_order,updated_at) < CURDATE()+INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC);
        $r['orders_today'] = (int) ($row['c'] ?? 0);
        $r['sales_today'] = (float) ($row['s'] ?? 0);

        $stmt = $db->query("SELECT order_id, order_name, customer_ref, state, state_display, amount_total, date_order, updated_at, latest_event, salesperson_name, line_user_id FROM odoo_orders WHERE COALESCE(date_order,updated_at) >= CURDATE() AND COALESCE(date_order,updated_at) < CURDATE()+INTERVAL 1 DAY ORDER BY updated_at DESC LIMIT 5");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$o) { $o['amount_total'] = (float) ($o['amount_total'] ?? 0); }
        unset($o);
        $r['orders'] = $orders;
    } catch (Exception $e) { /* table may not exist */ }

    // Pending slips
    try {
        $r['slips_pending'] = (int) $db->query("SELECT COUNT(*) FROM odoo_slip_uploads WHERE status IN ('new','pending')")->fetchColumn();
        // Range predicate instead of DATE() wrapper — allows index on matched_at/uploaded_at
        $m = $db->query("SELECT COALESCE(SUM(amount),0) FROM odoo_slip_uploads WHERE status='matched' AND COALESCE(matched_at,uploaded_at) >= CURDATE() AND COALESCE(matched_at,uploaded_at) < CURDATE()+INTERVAL 1 DAY")->fetchColumn();
        $r['payments_today'] = (float) $m;
    } catch (Exception $e) { /* table may not exist */ }

    // Pending BDOs
    try {
        $row = $db->query("SELECT COUNT(*) as c, COALESCE(SUM(amount_net_to_pay),0) as s FROM odoo_bdos WHERE payment_state NOT IN ('paid','reversed','in_payment') AND state!='cancel'")->fetch(PDO::FETCH_ASSOC);
        $r['bdos_pending'] = (int) ($row['c'] ?? 0);
        $r['bdos_pending_amount'] = (float) ($row['s'] ?? 0);
    } catch (Exception $e) { /* table may not exist */ }

    // Overdue customers — direct comparison avoids COALESCE function per row
    try {
        $r['overdue_customers'] = (int) $db->query("SELECT COUNT(*) FROM odoo_customer_projection WHERE overdue_amount > 0")->fetchColumn();
    } catch (Exception $e) { /* table may not exist */ }

    // Lightweight webhook stats — use cached resolveWebhookTimeColumn() (single SHOW COLUMNS,
    // result cached in file+APCu) instead of 3 information_schema.COLUMNS probes per request.
    try {
        $col = resolveWebhookTimeColumn($db);
        if ($col) {
            $row = $db->query("SELECT COUNT(*) as c, SUM(IF(status='success',1,0)) as ok, MAX({$col}) as lw FROM odoo_webhooks_log WHERE {$col}>=CURDATE() AND {$col}<CURDATE()+INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC);
            $r['webhook_total_today'] = (int) ($row['c'] ?? 0);
            $r['last_webhook'] = $row['lw'] ?? null;
            $cnt = (int) ($row['c'] ?? 0);
            $ok = (int) ($row['ok'] ?? 0);
            $r['webhook_success_rate'] = $cnt > 0 ? round(($ok / $cnt) * 100) : 0;
        }
    } catch (Exception $e) { /* table may not exist */ }

    $_result = [
        'success' => true,
        'data' => $r,
        '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => false, 'action' => 'overview_fast'],
    ];
    _fastCacheSet($_cKey, $_result, 30);
    echo json_encode($_result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── orders_today_fast: ดึงจาก odoo_orders_summary (cache table) ─────────
if ($action === 'orders_today_fast') {
    $limit = min((int) ($input['limit'] ?? 50), 200);
    $_cKey = 'orders_today_fast_' . $limit;
    $_cached = _fastCacheGet($_cKey, 120);
    if ($_cached !== null) {
        $_cached['_meta']['cached'] = true;
        $_cached['_meta']['duration_ms'] = round((microtime(true) - $_startTime) * 1000);
        echo json_encode($_cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    header('Cache-Control: private, max-age=120');

    try {
        $stmt = $db->prepare("
            SELECT order_key, customer_name, customer_ref, amount_total,
                   state, payment_status, date_order, last_event_at
            FROM odoo_orders_summary
            WHERE date_order >= CURDATE()
            ORDER BY last_event_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $_result = [
            'success' => true,
            'data' => ['orders' => $orders],
            '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => false, 'action' => 'orders_today_fast'],
        ];
        _fastCacheSet($_cKey, $_result, 120);
        echo json_encode($_result, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'fallback' => true]);
    }
    exit;
}

// ── customers_fast: ดึงจาก odoo_customers_cache (cache table) ───────────
if ($action === 'customers_fast') {
    $limit  = min((int) ($input['limit'] ?? 50), 500);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $_cKey = 'customers_fast_' . $limit . '_' . $offset;
    $_cached = _fastCacheGet($_cKey, 300);
    if ($_cached !== null) {
        $_cached['_meta']['cached'] = true;
        $_cached['_meta']['duration_ms'] = round((microtime(true) - $_startTime) * 1000);
        echo json_encode($_cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    header('Cache-Control: private, max-age=300');

    try {
        $stmt = $db->prepare("
            SELECT customer_id, customer_name, customer_ref, phone,
                   total_due, overdue_amount, latest_order_at, orders_count_total
            FROM odoo_customers_cache
            ORDER BY latest_order_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $_result = [
            'success' => true,
            'data' => [
                'customers'  => $customers,
                'pagination' => ['limit' => $limit, 'offset' => $offset, 'has_more' => count($customers) === $limit],
            ],
            '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => false, 'action' => 'customers_fast'],
        ];
        _fastCacheSet($_cKey, $_result, 300);
        echo json_encode($_result, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'fallback' => true]);
    }
    exit;
}

// ── customer_profile_fast: profile/credit from odoo_customer_projection + odoo_line_users ──
if ($action === 'customer_profile_fast') {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    header('Cache-Control: private, max-age=60');

    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));

    if ($partnerId === '' && $customerRef === '') {
        echo json_encode(['success' => false, 'error' => 'Missing partner_id or customer_ref']);
        exit;
    }

    // Cache: 300s (5 นาที — profile เปลี่ยนไม่บ่อย)
    $_cKey = 'cust_profile_' . md5($partnerId . '_' . $customerRef);
    $_cached = _fastCacheGet($_cKey, 300);
    if ($_cached !== null) {
        $_cached['_meta']['cached'] = true;
        $_cached['_meta']['duration_ms'] = round((microtime(true) - $_startTime) * 1000);
        echo json_encode($_cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $profile  = null;
    $credit   = [];
    $link     = null;

    // Query odoo_customer_projection for profile + credit fields
    try {
        $where  = [];
        $params = [];
        if ($partnerId !== '' && $partnerId !== '-') {
            $where[]  = 'COALESCE(odoo_partner_id, customer_id) = ?';
            $params[] = (int) $partnerId;
        }
        if ($customerRef !== '') {
            $where[]  = 'COALESCE(partner_code, customer_ref) = ?';
            $params[] = $customerRef;
        }
        $whereSql = $where ? ('WHERE (' . implode(' OR ', $where) . ')') : '';

        $stmt = $db->prepare("
            SELECT
                COALESCE(partner_name, customer_name, '') AS name,
                COALESCE(partner_code,  customer_ref, '')  AS ref,
                COALESCE(odoo_partner_id, customer_id)     AS partner_id,
                COALESCE(phone, '')          AS phone,
                COALESCE(email, '')          AS email,
                COALESCE(salesperson_name,'') AS salesperson_name,
                COALESCE(credit_limit,  0)  AS credit_limit,
                COALESCE(credit_used,   0)  AS credit_used,
                COALESCE(credit_remaining,0) AS credit_remaining,
                COALESCE(total_due,     0)  AS total_due,
                COALESCE(overdue_amount,0)  AS overdue_amount,
                line_user_id
            FROM odoo_customer_projection
            {$whereSql}
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $profile = [
                'name'             => $row['name'],
                'customer_name'    => $row['name'],
                'ref'              => $row['ref'],
                'customer_ref'     => $row['ref'],
                'partner_id'       => $row['partner_id'],
                'phone'            => $row['phone'],
                'email'            => $row['email'],
                'salesperson_name' => $row['salesperson_name'],
            ];
            $credit = [
                'credit_limit'     => (float) $row['credit_limit'],
                'credit_used'      => (float) $row['credit_used'],
                'credit_remaining' => (float) $row['credit_remaining'],
                'total_due'        => (float) $row['total_due'],
                'overdue_amount'   => (float) $row['overdue_amount'],
            ];
        }
    } catch (Exception $e) { /* table may not exist — silently continue */ }

    // Query odoo_line_users for LINE link data
    try {
        $luWhere  = [];
        $luParams = [];
        if ($partnerId !== '' && $partnerId !== '-') {
            $luWhere[]  = 'odoo_partner_id = ?';
            $luParams[] = (int) $partnerId;
        }
        if ($customerRef !== '') {
            $luWhere[]  = 'odoo_customer_code = ?';
            $luParams[] = $customerRef;
        }
        if ($luWhere) {
            $stmt = $db->prepare("
                SELECT line_user_id, line_account_id, odoo_partner_id, odoo_customer_code,
                       linked_at, created_at
                FROM odoo_line_users
                WHERE " . implode(' OR ', $luWhere) . "
                LIMIT 1
            ");
            $stmt->execute($luParams);
            $luRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($luRow) {
                $link = $luRow;
            }
        }
    } catch (Exception $e) { /* table may not exist */ }

    $_result = [
        'success' => true,
        'data' => [
            'profile'      => $profile,
            'credit'       => $credit,
            'link'         => $link,
            'points'       => null,
            'warnings'     => [],
            'partner_id'   => $partnerId,
            'customer_ref' => $customerRef,
        ],
        '_meta' => [
            'duration_ms' => round((microtime(true) - $_startTime) * 1000),
            'cached'      => false,
            'action'      => 'customer_profile_fast',
            'source'      => 'odoo_customer_projection',
        ],
    ];
    _fastCacheSet($_cKey, $_result, 300);
    echo json_encode($_result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── customer_slip_bdo_summary: aggregate counts per customer_ref ─────────
if ($action === 'customer_slip_bdo_summary') {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    // Cache: 20s (BDO/payment — ต้อง fresh)
    $_cKey = 'slip_bdo_summary';
    $_cached = _fastCacheGet($_cKey, 20);
    if ($_cached !== null) {
        $_cached['_meta']['cached'] = true;
        $_cached['_meta']['duration_ms'] = round((microtime(true) - $_startTime) * 1000);
        echo json_encode($_cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Cache-Control: private, max-age=20');

    $slipMap = [];
    $bdoMap  = [];

    // Pending slip counts grouped by customer_ref
    try {
        $rows = $db->query("
            SELECT customer_ref, COUNT(*) AS slip_count
            FROM odoo_slip_uploads
            WHERE status IN ('new','pending')
              AND customer_ref IS NOT NULL AND customer_ref <> ''
            GROUP BY customer_ref
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $slipMap[$r['customer_ref']] = (int) $r['slip_count'];
        }
    } catch (Exception $e) { /* table may not exist */ }

    // Outstanding BDO counts grouped by customer_ref
    try {
        $rows = $db->query("
            SELECT customer_ref, COUNT(*) AS bdo_count
            FROM odoo_bdos
            WHERE payment_state NOT IN ('paid','reversed','in_payment')
              AND state != 'cancel'
              AND customer_ref IS NOT NULL AND customer_ref <> ''
            GROUP BY customer_ref
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $bdoMap[$r['customer_ref']] = (int) $r['bdo_count'];
        }
    } catch (Exception $e) { /* table may not exist */ }

    $_result = [
        'success' => true,
        'data' => [
            'slips' => $slipMap,
            'bdos'  => $bdoMap,
        ],
        '_meta' => [
            'duration_ms' => round((microtime(true) - $_startTime) * 1000),
            'cached'      => false,
            'action'      => 'customer_slip_bdo_summary',
        ],
    ];
    _fastCacheSet($_cKey, $_result, 20);
    echo json_encode($_result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Unknown action: redirect to heavy API ───────────────────────────────
// Return a specific error so the JS client knows to try the heavy endpoint
http_response_code(200);
echo json_encode([
    'success' => false,
    'error' => 'Action not supported by fast endpoint',
    'fallback' => true,
    'action' => $action,
]);
