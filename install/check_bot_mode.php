<?php
/**
 * Check bot_mode for all LINE accounts
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Bot Mode Check</h1>";

$stmt = $db->query("SELECT id, name, bot_mode FROM line_accounts");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Bot Mode</th></tr>";
foreach ($accounts as $acc) {
    echo "<tr>";
    echo "<td>{$acc['id']}</td>";
    echo "<td>" . htmlspecialchars($acc['name'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($acc['bot_mode'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check ai_settings
echo "<h2>AI Settings</h2>";
try {
    $stmt = $db->query("SELECT line_account_id, is_enabled, ai_mode FROM ai_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Line Account ID</th><th>Is Enabled</th><th>AI Mode</th></tr>";
    foreach ($settings as $s) {
        echo "<tr>";
        echo "<td>{$s['line_account_id']}</td>";
        echo "<td>{$s['is_enabled']}</td>";
        echo "<td>{$s['ai_mode']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
