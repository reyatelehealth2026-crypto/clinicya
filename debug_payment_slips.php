<?php
/**
 * Debug Payment Slips
 * ตรวจสอบปัญหาสลิปไม่แสดง
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

$orderId = $_GET['order_id'] ?? null;

echo "<h1>🧾 Debug Payment Slips</h1>";

// 1. Check payment_slips table structure
echo "<h2>1. โครงสร้างตาราง payment_slips</h2>";
try {
    $stmt = $db->query("DESCRIBE payment_slips");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 2. Show all slips
echo "<h2>2. สลิปทั้งหมดในระบบ</h2>";
try {
    $stmt = $db->query("SELECT * FROM payment_slips ORDER BY id DESC LIMIT 20");
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($slips) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr>";
        foreach (array_keys($slips[0]) as $key) {
            echo "<th>{$key}</th>";
        }
        echo "</tr>";
        foreach ($slips as $slip) {
            echo "<tr>";
            foreach ($slip as $key => $val) {
                if ($key === 'image_url' && $val) {
                    echo "<td><a href='{$val}' target='_blank'>View</a></td>";
                } else {
                    echo "<td>" . htmlspecialchars($val ?? 'NULL') . "</td>";
                }
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:orange'>ไม่มีสลิปในระบบ</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 3. Check specific order
if ($orderId) {
    echo "<h2>3. ตรวจสอบ Order ID: {$orderId}</h2>";
    
    // Get order
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        echo "<p style='color:green'>✅ พบ Order</p>";
        echo "<ul>";
        echo "<li>Order Number: {$order['order_number']}</li>";
        echo "<li>User ID: {$order['user_id']}</li>";
        echo "<li>Status: {$order['status']}</li>";
        echo "<li>Payment Status: " . ($order['payment_status'] ?? 'N/A') . "</li>";
        echo "</ul>";
        
        // Find slips by transaction_id
        echo "<h3>สลิปที่ผูกกับ transaction_id = {$orderId}</h3>";
        $stmt = $db->prepare("SELECT * FROM payment_slips WHERE transaction_id = ?");
        $stmt->execute([$orderId]);
        $slipsByTxn = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>พบ " . count($slipsByTxn) . " รายการ</p>";
        
        // Find slips by user_id
        echo "<h3>สลิปที่ผูกกับ user_id = {$order['user_id']}</h3>";
        $stmt = $db->prepare("SELECT * FROM payment_slips WHERE user_id = ?");
        $stmt->execute([$order['user_id']]);
        $slipsByUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>พบ " . count($slipsByUser) . " รายการ</p>";
        
        if ($slipsByUser) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>ID</th><th>Transaction ID</th><th>User ID</th><th>Image</th><th>Status</th><th>Created</th></tr>";
            foreach ($slipsByUser as $s) {
                echo "<tr>";
                echo "<td>{$s['id']}</td>";
                echo "<td>" . ($s['transaction_id'] ?? 'NULL') . "</td>";
                echo "<td>" . ($s['user_id'] ?? 'NULL') . "</td>";
                echo "<td><a href='{$s['image_url']}' target='_blank'>View</a></td>";
                echo "<td>{$s['status']}</td>";
                echo "<td>{$s['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Find slips by order_number
        echo "<h3>สลิปที่ผูกกับ order_number = {$order['order_number']}</h3>";
        try {
            $stmt = $db->prepare("SELECT * FROM payment_slips WHERE order_number = ?");
            $stmt->execute([$order['order_number']]);
            $slipsByOrderNum = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<p>พบ " . count($slipsByOrderNum) . " รายการ</p>";
        } catch (Exception $e) {
            echo "<p>ไม่มี column order_number</p>";
        }
        
    } else {
        echo "<p style='color:red'>❌ ไม่พบ Order</p>";
    }
}

// 4. Recent transactions
echo "<h2>4. Transactions ล่าสุด</h2>";
$stmt = $db->query("SELECT id, order_number, user_id, status, payment_status, created_at FROM transactions ORDER BY id DESC LIMIT 10");
$txns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Order Number</th><th>User ID</th><th>Status</th><th>Payment</th><th>Created</th><th>Action</th></tr>";
foreach ($txns as $t) {
    echo "<tr>";
    echo "<td>{$t['id']}</td>";
    echo "<td>{$t['order_number']}</td>";
    echo "<td>{$t['user_id']}</td>";
    echo "<td>{$t['status']}</td>";
    echo "<td>" . ($t['payment_status'] ?? 'N/A') . "</td>";
    echo "<td>{$t['created_at']}</td>";
    echo "<td><a href='?order_id={$t['id']}'>Debug</a></td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<p><a href='debug_payment_slips.php'>🔄 Refresh</a></p>";
