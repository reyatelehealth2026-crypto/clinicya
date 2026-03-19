<?php
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Check Members</title></head><body>";
echo "<h1>Checking Database...</h1>";

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>✓ Database Connected</h2>";
    
    // Count members
    $stmt = $db->query("SELECT COUNT(*) as count FROM line_group_members");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $memberCount = $result['count'];
    
    echo "<p><strong>Total Members in Database:</strong> $memberCount</p>";
    
    if ($memberCount == 0) {
        echo "<p style='color: red;'><strong>❌ NO MEMBERS FOUND!</strong></p>";
        echo "<p>This is why the UI shows 'ยังไม่มีข้อมูลสมาชิก'</p>";
        echo "<h3>Solution: Add test data</h3>";
        echo "<pre>";
        echo "INSERT INTO line_group_members (group_id, line_user_id, display_name, is_active, joined_at, created_at, updated_at)\n";
        echo "VALUES (1, 'U1234567890', 'Test User 1', 1, NOW(), NOW(), NOW());\n";
        echo "</pre>";
    } else {
        echo "<p style='color: green;'><strong>✓ Found $memberCount members</strong></p>";
        
        // Show sample
        $stmt = $db->query("SELECT * FROM line_group_members LIMIT 5");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Sample Members:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Group ID</th><th>User ID</th><th>Name</th></tr>";
        foreach ($members as $m) {
            echo "<tr>";
            echo "<td>{$m['id']}</td>";
            echo "<td>{$m['group_id']}</td>";
            echo "<td>" . substr($m['line_user_id'], 0, 15) . "...</td>";
            echo "<td>" . htmlspecialchars($m['display_name'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>
