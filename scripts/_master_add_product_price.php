<?php
/** Phase 1: add product_price JSON column to platform master_products (idempotent). */
declare(strict_types=1);
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = Database::platform()->getConnection();
$has = (bool) $pdo->query("SHOW COLUMNS FROM master_products LIKE 'product_price'")->fetch();
if ($has) {
    echo "ALREADY_EXISTS\n";
} else {
    $pdo->exec("ALTER TABLE master_products
                ADD COLUMN product_price JSON NULL
                COMMENT 'CNY multi-unit + price array [{unit,unit_num,price,customer_group}]' AFTER pack_size");
    echo "ADDED\n";
}
$cols = $pdo->query("SHOW COLUMNS FROM master_products LIKE 'product_price'")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($cols, JSON_UNESCAPED_UNICODE) . "\n";
echo "PHASE1_DONE\n";
