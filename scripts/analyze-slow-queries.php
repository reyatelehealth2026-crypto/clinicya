<?php
/**
 * Analyze dashboard query performance
 * ตรวจสอบ table stats และ query times ก่อน/หลัง optimization
 *
 * Run: php scripts/analyze-slow-queries.php
 * Run (JSON output): php scripts/analyze-slow-queries.php --json
 *
 * ตารางที่ตรวจสอบ: ทุกตารางที่ใช้จริงใน odoo-dashboard-api.php
 * (ไม่รวม odoo_customer_projection ซึ่งใช้น้อยกว่าที่แผนเดิมประเมินไว้)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$jsonMode = in_array('--json', $argv ?? []);
$db = Database::getInstance()->getConnection();

// ── 1. Table Stats ─────────────────────────────────────────────────────────
$tables = [
    // Critical (301, 38, 66 refs ใน codebase)
    'odoo_webhooks_log',
    'odoo_notification_log',
    'odoo_line_users',
    // High
    'odoo_slip_uploads',
    'odoo_bdos',
    'odoo_bdo_context',
    'odoo_webhook_dlq',
    // Medium
    'odoo_orders',
    'odoo_invoices',
    'odoo_bdo_orders',
    // Cache tables (ควรใช้เพิ่มขึ้น)
    'odoo_orders_summary',
    'odoo_customers_cache',
    'odoo_invoices_cache',
    // Low usage แต่มีใน API
    'odoo_order_notes',
    'odoo_manual_overrides',
    'odoo_customer_projection',
];

$report = [];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SHOW TABLE STATUS LIKE '{$table}'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $report[$table] = ['error' => 'table not found'];
            continue;
        }

        $idxStmt = $db->query("SHOW INDEX FROM `{$table}`");
        $indexes = array_unique(array_column($idxStmt->fetchAll(PDO::FETCH_ASSOC), 'Key_name'));

        $report[$table] = [
            'rows'          => (int) $row['Rows'],
            'data_mb'       => round($row['Data_length'] / 1024 / 1024, 2),
            'index_mb'      => round($row['Index_length'] / 1024 / 1024, 2),
            'total_mb'      => round(($row['Data_length'] + $row['Index_length']) / 1024 / 1024, 2),
            'avg_row_bytes' => (int) $row['Avg_row_length'],
            'index_count'   => count($indexes),
            'indexes'       => $indexes,
        ];
    } catch (PDOException $e) {
        $report[$table] = ['error' => $e->getMessage()];
    }
}

// ── 2. Critical Query Timing ───────────────────────────────────────────────
$queries = [
    'webhooks_today_range'   => "SELECT COUNT(*) FROM odoo_webhooks_log WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY",
    'notification_today_range' => "SELECT COUNT(*) FROM odoo_notification_log WHERE sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY",
    'bdo_context_group_by'   => "SELECT bdo_id, MAX(id) as max_id FROM odoo_bdo_context GROUP BY bdo_id LIMIT 10",
    'slips_pending'          => "SELECT COUNT(*) FROM odoo_slip_uploads WHERE status IN ('new','pending')",
    'bdos_unpaid'            => "SELECT COUNT(*) FROM odoo_bdos WHERE payment_state NOT IN ('paid','reversed','in_payment') AND state != 'cancel'",
    'orders_today'           => "SELECT COUNT(*) FROM odoo_orders WHERE date_order >= CURDATE()",
    'line_users_lookup'      => "SELECT COUNT(*) FROM odoo_line_users WHERE odoo_partner_id IS NOT NULL",
    'customer_projection'    => "SELECT COUNT(*) FROM odoo_customer_projection WHERE overdue_amount > 0",
];

$queryTimes = [];
foreach ($queries as $name => $sql) {
    $start = microtime(true);
    try {
        $db->query($sql)->fetchAll();
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $queryTimes[$name] = [
            'ms'     => $elapsed,
            'status' => $elapsed < 100 ? 'ok' : ($elapsed < 500 ? 'slow' : 'critical'),
        ];
    } catch (PDOException $e) {
        $queryTimes[$name] = ['ms' => null, 'status' => 'error', 'error' => $e->getMessage()];
    }
}

// ── 3. Output ──────────────────────────────────────────────────────────────
$output = [
    'generated_at' => date('c'),
    'tables'       => $report,
    'query_times'  => $queryTimes,
];

if ($jsonMode) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

// Human-readable output
echo "\n=== Odoo Dashboard Query Analysis ===\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";

echo str_pad("Table", 35) . str_pad("Rows", 12) . str_pad("Size MB", 10) . str_pad("Indexes", 10) . "\n";
echo str_repeat("-", 70) . "\n";

foreach ($report as $table => $info) {
    if (isset($info['error'])) {
        echo str_pad($table, 35) . "ERROR: " . $info['error'] . "\n";
        continue;
    }
    $warning = $info['rows'] > 1_000_000 ? ' ⚠️ >1M' : ($info['rows'] > 500_000 ? ' ⚠️ >500K' : '');
    echo str_pad($table, 35)
       . str_pad(number_format($info['rows']), 12)
       . str_pad($info['total_mb'], 10)
       . str_pad($info['index_count'], 10)
       . $warning . "\n";
}

echo "\n=== Query Performance ===\n";
echo str_pad("Query", 35) . str_pad("Time (ms)", 12) . "Status\n";
echo str_repeat("-", 60) . "\n";

foreach ($queryTimes as $name => $result) {
    $ms = isset($result['ms']) ? $result['ms'] . "ms" : "ERROR";
    $icon = match($result['status']) {
        'ok'       => '✅',
        'slow'     => '⚠️ ',
        'critical' => '🔴',
        default    => '❌',
    };
    echo str_pad($name, 35) . str_pad($ms, 12) . $icon . "\n";
}

echo "\nLegend: ✅ <100ms  ⚠️  100-500ms  🔴 >500ms\n\n";

// Recommendations
$criticals = array_filter($queryTimes, fn($r) => $r['status'] === 'critical');
if ($criticals) {
    echo "=== Recommendations ===\n";
    foreach (array_keys($criticals) as $name) {
        echo "  • Run migration_missing_indexes.sql to fix: {$name}\n";
    }
    echo "\n";
}
