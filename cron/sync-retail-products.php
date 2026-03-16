#!/usr/bin/env php
<?php
/**
 * Sync Retail Products from Odoo
 * รันทุก 15 นาทีผ่าน cron
 * 
 * 1. ดึงสินค้าจาก Odoo ที่มี retail_price > 0
 * 2. กรองเฉพาะ OTC (ใช้ mapping จาก retail_category_mapping)
 * 3. อัพเดท retail_products table
 * 4. อัพเดท stock ใน retail_product_stock
 */

// Set unlimited execution time for long sync
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/OdooAPIClient.php';

$startTime = microtime(true);
$stats = [
    'total' => 0,
    'synced' => 0,
    'skipped' => 0,
    'failed' => 0,
    'stock_updated' => 0
];

echo "🔄 Starting retail product sync from Odoo...\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // ============================================================
    // 1. GET OTC CATEGORY CODES
    // ============================================================
    $catStmt = $db->query("
        SELECT category_code 
        FROM retail_category_mapping 
        WHERE is_otc = TRUE AND is_active = TRUE
    ");
    $otcCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($otcCategories)) {
        throw new Exception('No OTC categories found in mapping table');
    }
    
    echo "✓ OTC categories: " . count($otcCategories) . "\n";
    
    // ============================================================
    // 2. CONNECT TO ODOO
    // ============================================================
    $odoo = new OdooAPIClient();
    
    if (!$odoo->authenticate()) {
        throw new Exception('Failed to authenticate with Odoo');
    }
    
    echo "✓ Connected to Odoo\n";
    
    // ============================================================
    // 3. FETCH PRODUCTS FROM ODOO
    // ============================================================
    // Search for products with retail_price > 0 and in OTC categories
    $domain = [
        ['active', '=', true],
        ['retail_price', '>', 0],
        ['categ_id.code', 'in', $otcCategories]
    ];
    
    $fields = [
        'id', 'default_code', 'barcode', 'name', 'description', 'description_sale',
        'categ_id', 'list_price', 'retail_price', 'wholesale_price', 'standard_price',
        'qty_available', 'virtual_available', 'uom_id', 'image_1920', 'website_published'
    ];
    
    echo "📦 Fetching products from Odoo...\n";
    
    $odooProducts = $odoo->searchRead('product.product', $domain, $fields, 0, 1000);
    
    if (empty($odooProducts)) {
        echo "⚠️ No products found in Odoo\n";
        exit(0);
    }
    
    $stats['total'] = count($odooProducts);
    echo "✓ Found {$stats['total']} products in Odoo\n\n";
    
    // ============================================================
    // 4. SYNC PRODUCTS
    // ============================================================
    $db->beginTransaction();
    
    $insertStmt = $db->prepare("
        INSERT INTO retail_products (
            odoo_id, odoo_template_id, sku, barcode, name, description,
            category_code, category_name, retail_price, wholesale_price, cost_price,
            unit_of_measure, is_active, last_sync_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, NOW())
        ON DUPLICATE KEY UPDATE
            sku = VALUES(sku),
            barcode = VALUES(barcode),
            name = VALUES(name),
            description = VALUES(description),
            category_code = VALUES(category_code),
            category_name = VALUES(category_name),
            retail_price = VALUES(retail_price),
            wholesale_price = VALUES(wholesale_price),
            cost_price = VALUES(cost_price),
            unit_of_measure = VALUES(unit_of_measure),
            is_active = TRUE,
            last_sync_at = NOW()
    ");
    
    $stockStmt = $db->prepare("
        INSERT INTO retail_product_stock (
            product_id, qty_available, odoo_qty, last_sync_at
        )
        SELECT id, ?, ?, NOW()
        FROM retail_products
        WHERE odoo_id = ?
        ON DUPLICATE KEY UPDATE
            qty_available = VALUES(qty_available),
            odoo_qty = VALUES(odoo_qty),
            last_sync_at = VALUES(last_sync_at)
    ");
    
    $categoryMap = [];
    
    foreach ($odooProducts as $product) {
        try {
            // Get category info
            $categoryId = is_array($product['categ_id']) ? $product['categ_id'][0] : $product['categ_id'];
            $categoryName = is_array($product['categ_id']) ? $product['categ_id'][1] : '';
            
            // Get category code from mapping or use default
            if (!isset($categoryMap[$categoryId])) {
                $catCodeStmt = $db->prepare("
                    SELECT category_code 
                    FROM retail_category_mapping 
                    WHERE category_name_th = ? OR category_name_en = ?
                    LIMIT 1
                ");
                $catCodeStmt->execute([$categoryName, $categoryName]);
                $categoryCode = $catCodeStmt->fetchColumn();
                
                if (!$categoryCode) {
                    // Try to extract from Odoo category code
                    $odooCatStmt = $db->prepare("
                        SELECT code FROM product_categories WHERE id = ?
                    ");
                    $odooCatStmt->execute([$categoryId]);
                    $categoryCode = $odooCatStmt->fetchColumn() ?: 'OTC-01';
                }
                
                $categoryMap[$categoryId] = $categoryCode;
            }
            
            $categoryCode = $categoryMap[$categoryId];
            
            // Skip if not OTC category
            if (!in_array($categoryCode, $otcCategories)) {
                $stats['skipped']++;
                continue;
            }
            
            // Insert/update product
            $insertStmt->execute([
                $product['id'],
                $product['product_tmpl_id'][0] ?? null,
                $product['default_code'],
                $product['barcode'],
                $product['name'],
                $product['description_sale'] ?: $product['description'],
                $categoryCode,
                $categoryName,
                $product['retail_price'] ?: $product['list_price'],
                $product['wholesale_price'] ?: 0,
                $product['standard_price'] ?: 0,
                is_array($product['uom_id']) ? $product['uom_id'][1] : 'Unit'
            ]);
            
            $stats['synced']++;
            
            // Update stock
            $qty = $product['qty_available'] ?? 0;
            $stockStmt->execute([$qty, $qty, $product['id']]);
            $stats['stock_updated']++;
            
        } catch (Exception $e) {
            $stats['failed']++;
            echo "❌ Failed to sync product {$product['id']}: {$e->getMessage()}\n";
        }
    }
    
    $db->commit();
    
    // ============================================================
    // 5. MARK PRODUCTS NOT IN ODOO AS INACTIVE
    // ============================================================
    $deactivateStmt = $db->prepare("
        UPDATE retail_products 
        SET is_active = FALSE 
        WHERE last_sync_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND is_active = TRUE
    ");
    $deactivateStmt->execute();
    $deactivated = $deactivateStmt->rowCount();
    
    if ($deactivated > 0) {
        echo "⚠️ Deactivated {$deactivated} products not found in Odoo\n";
    }
    
    // ============================================================
    // 6. LOG SYNC
    // ============================================================
    $logStmt = $db->prepare("
        INSERT INTO retail_sync_log (
            sync_type, status, items_total, items_synced, items_failed, items_skipped,
            started_at, completed_at, duration_seconds
        ) VALUES ('products', 'success', ?, ?, ?, ?, NOW(), NOW(), ?)
    ");
    $duration = round(microtime(true) - $startTime, 2);
    $logStmt->execute([
        $stats['total'],
        $stats['synced'],
        $stats['failed'],
        $stats['skipped'],
        $duration
    ]);
    
    // ============================================================
    // SUMMARY
    // ============================================================
    echo "\n✅ Sync completed!\n";
    echo "==================\n";
    echo "Total from Odoo:  {$stats['total']}\n";
    echo "Synced:          {$stats['synced']}\n";
    echo "Skipped (not OTC): {$stats['skipped']}\n";
    echo "Failed:          {$stats['failed']}\n";
    echo "Stock updated:   {$stats['stock_updated']}\n";
    echo "Duration:        {$duration}s\n";
    echo "Completed at:    " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    // Log error
    if (isset($db)) {
        $errorStmt = $db->prepare("
            INSERT INTO retail_sync_log (
                sync_type, status, error_message, started_at, completed_at, duration_seconds
            ) VALUES ('products', 'failed', ?, NOW(), NOW(), ?)
        ");
        $duration = round(microtime(true) - $startTime, 2);
        $errorStmt->execute([$e->getMessage(), $duration]);
    }
    
    echo "\n❌ Sync failed: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
