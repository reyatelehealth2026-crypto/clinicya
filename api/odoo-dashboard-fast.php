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

// ── health: instant, no DB ──────────────────────────────────────────────
if ($action === '' || $action === 'health') {
    echo json_encode([
        'success' => true,
        'data' => [
            'status' => 'ok',
            'service' => 'odoo-dashboard-fast',
            'timestamp' => date('c'),
            'version' => '2.0.0',
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

    echo json_encode([
        'success' => true,
        'data' => $r,
        '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => false, 'action' => 'overview_fast'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── orders_today_fast: ดึงจาก odoo_orders_summary (cache table) ─────────
if ($action === 'orders_today_fast') {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    header('Cache-Control: private, max-age=30');
    header('Vary: Accept-Encoding');

    try {
        $limit = min((int) ($input['limit'] ?? 50), 200);
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

        echo json_encode([
            'success' => true,
            'data' => ['orders' => $orders],
            '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => true, 'action' => 'orders_today_fast'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'fallback' => true]);
    }
    exit;
}

// ── customers_fast: ดึงจาก odoo_customers_cache (cache table) ───────────
if ($action === 'customers_fast') {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    header('Cache-Control: private, max-age=30');
    header('Vary: Accept-Encoding');

    try {
        $limit  = min((int) ($input['limit'] ?? 50), 500);
        $offset = max((int) ($input['offset'] ?? 0), 0);
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

        echo json_encode([
            'success' => true,
            'data' => [
                'customers'  => $customers,
                'pagination' => ['limit' => $limit, 'offset' => $offset, 'has_more' => count($customers) === $limit],
            ],
            '_meta' => ['duration_ms' => round((microtime(true) - $_startTime) * 1000), 'cached' => true, 'action' => 'customers_fast'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'fallback' => true]);
    }
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
