<?php
/**
 * Update LIFF ID in line_accounts table
 */
require_once 'config/config.php';
require_once 'config/database.php';

$newLiffId = '2008477880-wmRN2Aln';

echo "<h2>🔧 Update LIFF ID</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Show current accounts
    echo "<h3>Current LINE Accounts:</h3>";
    $stmt = $db->query("SELECT id, name, liff_id, is_default FROM line_accounts ORDER BY id");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>Name</th><th>LIFF ID</th><th>Default</th></tr>";
    foreach ($accounts as $acc) {
        echo "<tr>";
        echo "<td>{$acc['id']}</td>";
        echo "<td>{$acc['name']}</td>";
        echo "<td>" . ($acc['liff_id'] ?: '<em>ว่าง</em>') . "</td>";
        echo "<td>" . ($acc['is_default'] ? '✅' : '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Update all accounts with new LIFF ID
    if (isset($_GET['update'])) {
        $accountId = $_GET['account'] ?? null;
        
        if ($accountId) {
            $stmt = $db->prepare("UPDATE line_accounts SET liff_id = ? WHERE id = ?");
            $stmt->execute([$newLiffId, $accountId]);
        } else {
            // Update all accounts
            $stmt = $db->prepare("UPDATE line_accounts SET liff_id = ? WHERE liff_id IS NULL OR liff_id = ''");
            $stmt->execute([$newLiffId]);
        }
        
        echo "<p style='color:green;font-size:18px;'>✅ Updated LIFF ID to: <strong>{$newLiffId}</strong></p>";
        echo "<p><a href='update_liff_main.php'>Refresh to see changes</a></p>";
    } else {
        echo "<br><br>";
        echo "<p><strong>New LIFF ID:</strong> {$newLiffId}</p>";
        echo "<p><a href='?update=1' style='padding:10px 20px;background:#06C755;color:white;text-decoration:none;border-radius:8px;'>🔄 Update All Accounts</a></p>";
        
        foreach ($accounts as $acc) {
            echo "<p><a href='?update=1&account={$acc['id']}'>Update only: {$acc['name']}</a></p>";
        }
    }
    
    echo "<hr>";
    echo "<p><a href='liff-main.php?debug=1'>🔍 Test LIFF Main (Debug)</a></p>";
    echo "<p><a href='https://liff.line.me/{$newLiffId}' target='_blank'>🚀 Open LIFF URL</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}
