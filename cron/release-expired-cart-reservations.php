#!/usr/bin/env php
<?php
/**
 * Release Expired Cart Reservations
 * รันทุก 5 นาทีผ่าน cron
 * 
 * ปล่อย stock ที่ถูกจองใน cart แต่หมดเวลา (30 นาที)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "🔄 Releasing expired cart reservations...\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Find expired reservations
    $expiredStmt = $db->prepare("
        SELECT c.id as cart_id, c.product_id, c.qty, c.line_user_id
        FROM retail_carts c
        WHERE c.is_reserved = TRUE
          AND c.reserved_until < NOW()
        LIMIT 100
    ");
    $expiredStmt->execute();
    $expiredItems = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($expiredItems)) {
        echo "✓ No expired reservations found\n";
        exit(0);
    }
    
    echo "Found " . count($expiredItems) . " expired reservations\n";
    
    $db->beginTransaction();
    
    $releasedCount = 0;
    $failedCount = 0;
    
    foreach ($expiredItems as $item) {
        try {
            // Release stock reservation
            $releaseStmt = $db->prepare("
                UPDATE retail_product_stock 
                SET qty_reserved = GREATEST(0, qty_reserved - ?)
                WHERE product_id = ?
            ");
            $releaseStmt->execute([$item['qty'], $item['product_id']]);
            
            // Mark cart item as not reserved
            $updateStmt = $db->prepare("
                UPDATE retail_carts 
                SET is_reserved = FALSE, reserved_until = NULL
                WHERE id = ?
            ");
            $updateStmt->execute([$item['cart_id']]);
            
            // Log the release
            $logStmt = $db->prepare("
                INSERT INTO retail_stock_movements (
                    product_id, movement_type, qty, before_qty, after_qty,
                    reference_type, reference_id, notes
                )
                SELECT 
                    ?,
                    'release',
                    ?,
                    qty_reserved + ?,
                    qty_reserved,
                    'cart',
                    ?,
                    'Released expired cart reservation'
                FROM retail_product_stock
                WHERE product_id = ?
            ");
            $logStmt->execute([
                $item['product_id'],
                $item['qty'],
                $item['qty'],
                $item['cart_id'],
                $item['product_id']
            ]);
            
            $releasedCount++;
            
        } catch (Exception $e) {
            echo "❌ Failed to release cart {$item['cart_id']}: {$e->getMessage()}\n";
            $failedCount++;
        }
    }
    
    $db->commit();
    
    // Clean up very old cart items (older than 7 days)
    $cleanupStmt = $db->prepare("
        DELETE c FROM retail_carts c
        LEFT JOIN retail_product_stock s ON c.product_id = s.product_id
        WHERE c.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $cleanupStmt->execute();
    $cleaned = $cleanupStmt->rowCount();
    
    echo "\n✅ Completed!\n";
    echo "Released: {$releasedCount}\n";
    echo "Failed: {$failedCount}\n";
    echo "Old carts cleaned: {$cleaned}\n";
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
