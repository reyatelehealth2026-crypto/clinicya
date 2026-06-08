<?php
/**
 * READ-ONLY diagnostic for the slip-verification rollout.
 * Lists tenant databases, checks payment_slips.verify_ref presence, and locates
 * a specific order. Performs NO writes (SELECT / SHOW only).
 *
 *   php install/diag_tenant_dbs.php [ORDER_NUMBER]
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

$orderNumber = $argv[1] ?? 'TXN202606074484';

function pdo(string $db): PDO
{
    return new PDO('mysql:host=' . DB_HOST . ';dbname=' . $db . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

echo "=== Slip-verification rollout diagnostic (READ ONLY) ===\n";
echo "legacy DB_NAME = " . DB_NAME . "\n\n";

// All schemas visible to this DB user.
$all = [];
try {
    $info = pdo('information_schema');
    $all = array_map('strval', $info->query(
        "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME"
    )->fetchAll(PDO::FETCH_COLUMN));
} catch (\Throwable $e) {
    echo "! cannot list schemas: {$e->getMessage()}\n";
}

echo "Visible schemas (" . count($all) . "):\n";
foreach ($all as $s) {
    echo "  - {$s}\n";
}
echo "\n";

// Candidate tenant DBs by either naming convention + legacy.
$candidates = array_values(array_unique(array_filter($all, function ($s) {
    return $s === DB_NAME
        || strpos($s, 'reya_tenant') !== false
        || strpos($s, 'reya_t_') !== false
        || strpos($s, 'clinicya') !== false;
})));

echo "=== payment_slips.verify_ref status + order lookup ===\n";
foreach ($candidates as $db) {
    try {
        $p = pdo($db);
        $hasTable = (bool) $p->query("SHOW TABLES LIKE 'payment_slips'")->fetch();
        $hasCol = $hasTable && (bool) $p->query("SHOW COLUMNS FROM payment_slips LIKE 'verify_ref'")->fetch();
        $orderHit = '';
        try {
            $st = $p->prepare("SELECT id, payment_method, payment_status, grand_total FROM transactions WHERE order_number = ? LIMIT 1");
            $st->execute([$orderNumber]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $orderHit = " <== ORDER {$orderNumber} HERE (id={$row['id']}, pay={$row['payment_method']}/{$row['payment_status']}, total={$row['grand_total']})";
            }
        } catch (\Throwable $e) { /* no transactions table */ }
        printf("  [%-26s] payment_slips=%s verify_ref=%s%s\n",
            $db, $hasTable ? 'yes' : 'NO', $hasCol ? 'yes' : 'NO', $orderHit);
    } catch (\Throwable $e) {
        printf("  [%-26s] ERROR: %s\n", $db, $e->getMessage());
    }
}
echo "\n=== done ===\n";
