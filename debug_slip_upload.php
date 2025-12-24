<?php
/**
 * Debug Slip Upload
 * ตรวจสอบปัญหาการอัพโหลดสลิป
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🧾 Debug Slip Upload</h1>";

// 1. Check payment_slips table
echo "<h2>1. ตรวจสอบตาราง payment_slips</h2>";
try {
    $stmt = $db->query("DESCRIBE payment_slips");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }
    echo "<p style='color:green'>✅ payment_slips มี columns: " . implode(', ', $columns) . "</p>";
    
    // Check if line_user_id exists
    if (!in_array('line_user_id', $columns)) {
        echo "<p style='color:orange'>⚠️ ไม่มี line_user_id - กำลังเพิ่ม...</p>";
        $db->exec("ALTER TABLE payment_slips ADD COLUMN line_user_id VARCHAR(50) AFTER transaction_id");
        echo "<p style='color:green'>✅ เพิ่ม line_user_id แล้ว</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ payment_slips ไม่มี - กำลังสร้าง...</p>";
    $sql = "CREATE TABLE payment_slips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT,
        line_user_id VARCHAR(50),
        user_id INT,
        image_url VARCHAR(500),
        status VARCHAR(50) DEFAULT 'pending',
        verified_at DATETIME,
        verified_by INT,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_transaction (transaction_id),
        INDEX idx_line_user (line_user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    echo "<p style='color:green'>✅ สร้าง payment_slips แล้ว</p>";
}

// 2. Check transactions table
echo "<h2>2. ตรวจสอบตาราง transactions</h2>";
try {
    $stmt = $db->query("DESCRIBE transactions");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }
    echo "<p>Columns: " . implode(', ', $columns) . "</p>";
    
    // Add missing columns
    $required = [
        'line_user_id' => "VARCHAR(50)",
        'order_number' => "VARCHAR(50)",
        'shipping_fee' => "DECIMAL(10,2) DEFAULT 0",
        'grand_total' => "DECIMAL(10,2) DEFAULT 0",
        'delivery_info' => "TEXT",
        'payment_method' => "VARCHAR(50) DEFAULT 'transfer'"
    ];
    
    foreach ($required as $col => $def) {
        if (!in_array($col, $columns)) {
            try {
                $db->exec("ALTER TABLE transactions ADD COLUMN {$col} {$def}");
                echo "<p style='color:green'>✅ เพิ่ม {$col}</p>";
            } catch (Exception $e) {
                echo "<p style='color:orange'>⚠️ {$col}: " . $e->getMessage() . "</p>";
            }
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// 3. Check uploads directory
echo "<h2>3. ตรวจสอบ uploads directory</h2>";
$uploadDir = __DIR__ . '/uploads/slips/';
if (is_dir($uploadDir)) {
    echo "<p style='color:green'>✅ Directory exists: {$uploadDir}</p>";
    echo "<p>Writable: " . (is_writable($uploadDir) ? 'Yes' : 'No') . "</p>";
    
    // List recent files
    $files = glob($uploadDir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    echo "<p>Files: " . count($files) . "</p>";
    if ($files) {
        $recent = array_slice($files, -5);
        echo "<ul>";
        foreach ($recent as $f) {
            echo "<li>" . basename($f) . " - " . date('Y-m-d H:i:s', filemtime($f)) . "</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p style='color:orange'>⚠️ Directory ไม่มี - กำลังสร้าง...</p>";
    mkdir($uploadDir, 0755, true);
    echo "<p style='color:green'>✅ สร้างแล้ว</p>";
}

// 4. Recent slips in database
echo "<h2>4. สลิปล่าสุดใน database</h2>";
try {
    $stmt = $db->query("SELECT ps.*, t.order_number, t.status as order_status 
                        FROM payment_slips ps 
                        LEFT JOIN transactions t ON ps.transaction_id = t.id 
                        ORDER BY ps.id DESC LIMIT 10");
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($slips) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Order</th><th>Line User</th><th>Image</th><th>Status</th><th>Created</th></tr>";
        foreach ($slips as $s) {
            echo "<tr>";
            echo "<td>{$s['id']}</td>";
            echo "<td>{$s['order_number']}</td>";
            echo "<td>" . substr($s['line_user_id'] ?? '', 0, 10) . "...</td>";
            echo "<td><a href='{$s['image_url']}' target='_blank'>View</a></td>";
            echo "<td>{$s['status']}</td>";
            echo "<td>{$s['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>ไม่มีสลิปใน database</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 5. Recent orders
echo "<h2>5. Orders ล่าสุด</h2>";
try {
    $stmt = $db->query("SELECT id, order_number, line_user_id, total_amount, grand_total, status, created_at 
                        FROM transactions 
                        ORDER BY id DESC LIMIT 10");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($orders) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Order#</th><th>Line User</th><th>Total</th><th>Status</th><th>Created</th></tr>";
        foreach ($orders as $o) {
            echo "<tr>";
            echo "<td>{$o['id']}</td>";
            echo "<td>{$o['order_number']}</td>";
            echo "<td>" . substr($o['line_user_id'] ?? '', 0, 10) . "...</td>";
            echo "<td>฿" . number_format($o['grand_total'] ?? $o['total_amount'], 0) . "</td>";
            echo "<td>{$o['status']}</td>";
            echo "<td>{$o['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 6. Test upload form
echo "<h2>6. ทดสอบอัพโหลดสลิป</h2>";
$testOrderId = $_GET['order_id'] ?? '';
?>
<form action="api/checkout.php" method="POST" enctype="multipart/form-data" target="_blank">
    <input type="hidden" name="action" value="upload_slip">
    <p>
        <label>Order ID: <input type="text" name="order_id" value="<?= $testOrderId ?>" required></label>
    </p>
    <p>
        <label>Line User ID: <input type="text" name="line_user_id" placeholder="Uxxxxx"></label>
    </p>
    <p>
        <label>Slip Image: <input type="file" name="slip" accept="image/*" required></label>
    </p>
    <p>
        <button type="submit">Upload Test</button>
    </p>
</form>

<hr>
<p><a href="debug_slip_upload.php">🔄 Refresh</a></p>
<?php
