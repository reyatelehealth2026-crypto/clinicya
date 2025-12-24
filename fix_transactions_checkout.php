<?php
/**
 * Fix transactions table for checkout
 * เพิ่ม columns ที่ขาดหายไป
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔧 Fix Transactions Table</h1>";

// Check current columns
echo "<h2>1. ตรวจสอบ columns ปัจจุบัน</h2>";
$stmt = $db->query("DESCRIBE transactions");
$columns = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}
echo "<p>Columns: " . implode(', ', $columns) . "</p>";

// Required columns for checkout
$requiredColumns = [
    'transaction_type' => "VARCHAR(50) DEFAULT 'purchase'",
    'order_number' => "VARCHAR(50)",
    'shipping_fee' => "DECIMAL(10,2) DEFAULT 0",
    'grand_total' => "DECIMAL(10,2) DEFAULT 0",
    'delivery_info' => "TEXT",
    'payment_method' => "VARCHAR(50) DEFAULT 'transfer'",
    'payment_status' => "VARCHAR(50) DEFAULT 'pending'"
];

echo "<h2>2. เพิ่ม columns ที่ขาด</h2>";
$added = [];
$skipped = [];

foreach ($requiredColumns as $col => $definition) {
    if (!in_array($col, $columns)) {
        try {
            $sql = "ALTER TABLE transactions ADD COLUMN {$col} {$definition}";
            $db->exec($sql);
            $added[] = $col;
            echo "<p style='color:green'>✅ เพิ่ม {$col}</p>";
        } catch (Exception $e) {
            echo "<p style='color:red'>❌ Error adding {$col}: " . $e->getMessage() . "</p>";
        }
    } else {
        $skipped[] = $col;
    }
}

if ($skipped) {
    echo "<p style='color:gray'>⏭️ มีอยู่แล้ว: " . implode(', ', $skipped) . "</p>";
}

// Check cart_items table
echo "<h2>3. ตรวจสอบ cart_items table</h2>";
try {
    $stmt = $db->query("DESCRIBE cart_items");
    $cartColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cartColumns[] = $row['Field'];
    }
    echo "<p style='color:green'>✅ cart_items มี columns: " . implode(', ', $cartColumns) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠️ cart_items ไม่มี - กำลังสร้าง...</p>";
    
    $sql = "CREATE TABLE IF NOT EXISTS cart_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_product (user_id, product_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    try {
        $db->exec($sql);
        echo "<p style='color:green'>✅ สร้าง cart_items สำเร็จ</p>";
    } catch (Exception $e2) {
        echo "<p style='color:red'>❌ Error: " . $e2->getMessage() . "</p>";
    }
}

// Check transaction_items table
echo "<h2>4. ตรวจสอบ transaction_items table</h2>";
try {
    $stmt = $db->query("DESCRIBE transaction_items");
    $tiColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tiColumns[] = $row['Field'];
    }
    echo "<p style='color:green'>✅ transaction_items มี columns: " . implode(', ', $tiColumns) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠️ transaction_items ไม่มี - กำลังสร้าง...</p>";
    
    $sql = "CREATE TABLE IF NOT EXISTS transaction_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        product_id INT,
        product_name VARCHAR(255),
        product_price DECIMAL(10,2),
        quantity INT DEFAULT 1,
        subtotal DECIMAL(10,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_transaction (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    try {
        $db->exec($sql);
        echo "<p style='color:green'>✅ สร้าง transaction_items สำเร็จ</p>";
    } catch (Exception $e2) {
        echo "<p style='color:red'>❌ Error: " . $e2->getMessage() . "</p>";
    }
}

echo "<hr>";
echo "<h2>✅ เสร็จสิ้น</h2>";
echo "<p><a href='test_add_cart.php'>🛒 ทดสอบเพิ่มตะกร้า</a></p>";
echo "<p><a href='debug_cart.php'>📋 ดูตะกร้า</a></p>";
