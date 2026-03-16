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

    // Orders today
    try {
        $row = $db->query("SELECT COUNT(*) as c, COALESCE(SUM(amount_total),0) as s FROM odoo_orders WHERE DATE(COALESCE(date_order,updated_at))=CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $r['orders_today'] = (int) ($row['c'] ?? 0);
        $r['sales_today'] = (float) ($row['s'] ?? 0);

        $stmt = $db->query("SELECT order_id, order_name, customer_ref, state, state_display, amount_total, date_order, updated_at, latest_event, salesperson_name, line_user_id FROM odoo_orders WHERE DATE(COALESCE(date_order,updated_at))=CURDATE() ORDER BY updated_at DESC LIMIT 5");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$o) { $o['amount_total'] = (float) ($o['amount_total'] ?? 0); }
        unset($o);
        $r['orders'] = $orders;
    } catch (Exception $e) { /* table may not exist */ }

    // Pending slips
    try {
        $r['slips_pending'] = (int) $db->query("SELECT COUNT(*) FROM odoo_slip_uploads WHERE status='pending'")->fetchColumn();
        $m = $db->query("SELECT COALESCE(SUM(amount),0) FROM odoo_slip_uploads WHERE status='matched' AND DATE(COALESCE(matched_at,uploaded_at))=CURDATE()")->fetchColumn();
        $r['payments_today'] = (float) $m;
    } catch (Exception $e) { /* table may not exist */ }

    // Pending BDOs
    try {
        $row = $db->query("SELECT COUNT(*) as c, COALESCE(SUM(amount_net_to_pay),0) as s FROM odoo_bdos WHERE payment_state NOT IN ('paid','reversed','in_payment') AND state!='cancel'")->fetch(PDO::FETCH_ASSOC);
        $r['bdos_pending'] = (int) ($row['c'] ?? 0);
        $r['bdos_pending_amount'] = (float) ($row['s'] ?? 0);
    } catch (Exception $e) { /* table may not exist */ }

    // Overdue customers
    try {
        $r['overdue_customers'] = (int) $db->query("SELECT COUNT(*) FROM odoo_customer_projection WHERE COALESCE(overdue_amount,0)>0")->fetchColumn();
    } catch (Exception $e) { /* table may not exist */ }

    // Lightweight webhook stats (today only, range scan)
    try {
        $col = null;
        foreach (['processed_at', 'created_at', 'received_at'] as $c) {
            $chk = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='odoo_webhooks_log' AND COLUMN_NAME='{$c}' LIMIT 1");
            if ($chk->fetchColumn()) { $col = $c; break; }
        }
        if ($col) {
            $row = $db->query("SELECT COUNT(*) as c, SUM(IF(status='success',1,0)) as ok, MAX(`{$col}`) as lw FROM odoo_webhooks_log WHERE `{$col}`>=CURDATE() AND `{$col}`<CURDATE()+INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC);
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

// ── Unknown action: redirect to heavy API ───────────────────────────────
// Return a specific error so the JS client knows to try the heavy endpoint
http_response_code(200);
echo json_encode([
    'success' => false,
    'error' => 'Action not supported by fast endpoint',
    'fallback' => true,
    'action' => $action,
]);
