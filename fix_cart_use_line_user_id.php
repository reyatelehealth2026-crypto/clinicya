<?php
/**
 * Fix cart_items to use line_user_id directly
 * เปลี่ยนจาก user_id เป็น line_user_id
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔧 Fix Cart to use line_user_id</h1>";

// 1. Check current cart_items structure
echo "<h2>1. ตรวจสอบโครงสร้างปัจจุบัน</h2>";
try {
    $stmt = $db->query("DESCRIBE cart_items");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = $row;
    }
    echo "<p>Columns: " . implode(', ', array_keys($columns)) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠️ cart_items ไม่มี - จะสร้างใหม่</p>";
    $columns = [];
}

// 2. Backup existing data
echo "<h2>2. Backup ข้อมูลเดิม</h2>";
$existingData = [];
try {
    $stmt = $db->query("
        SELECT c.*, u.line_user_id 
        FROM cart_items c 
        LEFT JOIN users u ON c.user_id = u.id
    ");
    $existingData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>พบข้อมูลเดิม: " . count($existingData) . " รายการ</p>";
} catch (Exception $e) {
    echo "<p>ไม่มีข้อมูลเดิม</p>";
}

// 3. Drop and recreate cart_items with line_user_id
echo "<h2>3. สร้างตาราง cart_items ใหม่</h2>";
try {
    // Drop old table
    $db->exec("DROP TABLE IF EXISTS cart_items_backup");
    $db->exec("RENAME TABLE cart_items TO cart_items_backup");
    echo "<p>✅ Backup ตารางเดิมเป็น cart_items_backup</p>";
} catch (Exception $e) {
    echo "<p>ไม่มีตารางเดิม</p>";
}

// Create new table with line_user_id
$sql = "CREATE TABLE cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_user_id VARCHAR(50) NOT NULL,
    product_id INT NOT NULL,
    quantity INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_product (line_user_id, product_id),
    INDEX idx_line_user (line_user_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $db->exec($sql);
    echo "<p style='color:green'>✅ สร้างตาราง cart_items ใหม่สำเร็จ</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
    exit;
}

// 4. Migrate old data
echo "<h2>4. Migrate ข้อมูลเดิม</h2>";
$migrated = 0;
foreach ($existingData as $item) {
    if (!empty($item['line_user_id'])) {
        try {
            $stmt = $db->prepare("
                INSERT INTO cart_items (line_user_id, product_id, quantity) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ");
            $stmt->execute([$item['line_user_id'], $item['product_id'], $item['quantity']]);
            $migrated++;
        } catch (Exception $e) {}
    }
}
echo "<p>Migrated: {$migrated} รายการ</p>";

// 5. Fix transactions table
echo "<h2>5. เพิ่ม columns ใน transactions</h2>";
$transColumns = [];
try {
    $stmt = $db->query("DESCRIBE transactions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $transColumns[] = $row['Field'];
    }
} catch (Exception $e) {}

$addColumns = [
    'line_user_id' => "VARCHAR(50) AFTER line_account_id",
    'transaction_type' => "VARCHAR(50) DEFAULT 'purchase'",
    'order_number' => "VARCHAR(50)",
    'shipping_fee' => "DECIMAL(10,2) DEFAULT 0",
    'grand_total' => "DECIMAL(10,2) DEFAULT 0",
    'delivery_info' => "TEXT",
    'payment_method' => "VARCHAR(50) DEFAULT 'transfer'",
    'payment_status' => "VARCHAR(50) DEFAULT 'pending'"
];

foreach ($addColumns as $col => $def) {
    if (!in_array($col, $transColumns)) {
        try {
            $db->exec("ALTER TABLE transactions ADD COLUMN {$col} {$def}");
            echo "<p style='color:green'>✅ เพิ่ม transactions.{$col}</p>";
        } catch (Exception $e) {
            echo "<p style='color:orange'>⚠️ {$col}: " . $e->getMessage() . "</p>";
        }
    }
}

// Update existing transactions with line_user_id
try {
    $db->exec("
        UPDATE transactions t 
        JOIN users u ON t.user_id = u.id 
        SET t.line_user_id = u.line_user_id 
        WHERE t.line_user_id IS NULL
    ");
    echo "<p style='color:green'>✅ Update line_user_id ใน transactions เดิม</p>";
} catch (Exception $e) {}

// 6. Create transaction_items if not exists
echo "<h2>6. ตรวจสอบ transaction_items</h2>";
try {
    $db->query("SELECT 1 FROM transaction_items LIMIT 1");
    echo "<p style='color:green'>✅ transaction_items มีอยู่แล้ว</p>";
} catch (Exception $e) {
    $sql = "CREATE TABLE transaction_items (
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
    $db->exec($sql);
    echo "<p style='color:green'>✅ สร้าง transaction_items</p>";
}

echo "<hr>";
echo "<h2>✅ เสร็จสิ้น!</h2>";
echo "<p>ตอนนี้ cart_items ใช้ line_user_id โดยตรงแล้ว</p>";
echo "<p><a href='test_add_cart.php'>🛒 ทดสอบเพิ่มตะกร้า</a></p>";
