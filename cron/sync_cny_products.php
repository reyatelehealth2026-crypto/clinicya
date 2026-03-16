<?php
/**
 * Background script to sync CNY Pharmacy products to database
 * Run this via cron or manually to update product cache
 */
ini_set('memory_limit', '512M'); // Increase memory limit first
set_time_limit(300); // 5 minutes

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CnyPharmacyAPI.php';

$db = Database::getInstance()->getConnection();

echo "Starting CNY product sync (grouped endpoint)...\n";

// Ensure table exists before processing
$db->exec("
    CREATE TABLE IF NOT EXISTS cny_products (
        id INT PRIMARY KEY AUTO_INCREMENT,
        sku VARCHAR(100) UNIQUE NOT NULL,
        barcode VARCHAR(100),
        name TEXT,
        name_en TEXT,
        spec_name TEXT,
        description TEXT,
        properties_other TEXT,
        how_to_use TEXT,
        photo_path TEXT,
        qty DECIMAL(10,2) DEFAULT 0,
        qty_incoming DECIMAL(10,2) DEFAULT 0,
        enable CHAR(1) DEFAULT '1',
        product_price JSON,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sku (sku),
        INDEX idx_enable (enable),
        INDEX idx_name (name(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "Table ready, fetching pages...\n";

$cnyApi = new CnyPharmacyAPI($db);
$page = 1;
$limit = 50;
$maxPages = 200;
$inserted = 0;
$updated = 0;
$skipped = 0;
$totalFetched = 0;

$insertStmt = $db->prepare("
    INSERT INTO cny_products (
        sku, barcode, name, name_en, spec_name, description,
        properties_other, how_to_use, photo_path, qty, qty_incoming,
        enable, product_price
    ) VALUES (
        :sku, :barcode, :name, :name_en, :spec_name, :description,
        :properties_other, :how_to_use, :photo_path, :qty, :qty_incoming,
        :enable, :product_price
    )
    ON DUPLICATE KEY UPDATE
        barcode = VALUES(barcode),
        name = VALUES(name),
        name_en = VALUES(name_en),
        spec_name = VALUES(spec_name),
        description = VALUES(description),
        properties_other = VALUES(properties_other),
        how_to_use = VALUES(how_to_use),
        photo_path = VALUES(photo_path),
        qty = VALUES(qty),
        qty_incoming = VALUES(qty_incoming),
        enable = VALUES(enable),
        product_price = VALUES(product_price)
");

while ($page <= $maxPages) {
    echo "Fetching page {$page}...\n";
    $result = $cnyApi->fetchGroupedProductsPage($page, $limit);
    if (!$result['success']) {
        $error = $result['error'] ?? 'Unknown API error';
        die("API Error on page {$page}: {$error}\n");
    }

    $products = $result['products'] ?? [];
    $count = count($products);
    if ($count === 0) {
        echo "No data returned for page {$page}, stopping.\n";
        break;
    }

    $totalFetched += $count;

    foreach ($products as $product) {
        $sku = trim($product['sku'] ?? '');
        if ($sku === '') {
            $skipped++;
            continue;
        }

        $insertStmt->execute([
            ':sku' => $sku,
            ':barcode' => $product['barcode'] ?? '',
            ':name' => $product['name'] ?? '',
            ':name_en' => $product['name_en'] ?? '',
            ':spec_name' => $product['spec_name'] ?? '',
            ':description' => $product['description'] ?? '',
            ':properties_other' => $product['properties_other'] ?? '',
            ':how_to_use' => $product['how_to_use'] ?? '',
            ':photo_path' => $product['photo_path'] ?? '',
            ':qty' => $product['qty'] ?? 0,
            ':qty_incoming' => $product['qty_incoming'] ?? 0,
            ':enable' => $product['enable'] ?? '1',
            ':product_price' => json_encode($product['product_price'] ?? [])
        ]);

        if ($insertStmt->rowCount() === 1) {
            $inserted++;
        } else {
            $updated++;
        }
    }

    if ($totalFetched % 500 === 0) {
        echo "Processed {$totalFetched} products so far...\n";
    }

    if (!$result['has_more']) {
        echo "Reached last page ({$page}).\n";
        break;
    }

    $page++;
}

echo "Downloaded {$totalFetched} products from {$page} page(s).\n";

echo "\nSync complete!\n";
echo "Inserted: $inserted\n";
echo "Updated: $updated\n";
echo "Skipped (missing SKU): $skipped\n";
echo "Total processed: " . ($inserted + $updated) . "\n";
