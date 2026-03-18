<?php
/**
 * Run Status Migration (Direct SQL Version)
 * Updates the transactions table status column to VARCHAR(50)
 * Eliminates file path issues by embedding SQL directly.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Status Column Migration</h1>";

try {
    // Direct SQL execution to avoid file path issues
    $sql = "ALTER TABLE `transactions` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'pending'";

    echo "<p>Executing: <code>$sql</code></p>";

    $db->exec($sql);

    echo "<div style='color: green; padding: 20px; border: 1px solid green; border-radius: 5px; background-color: #f0fff4;'>";
    echo "✅ <strong>SUCCESS:</strong> Transactions table 'status' column has been updated to VARCHAR(50).";
    echo "</div>";

    echo "<p>You should no longer see 'Data truncated' errors.</p>";
    echo "<p><a href='/debug_wms_error.php' style='display:inline-block; padding:10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:5px;'>Verify with Debug Script</a></p>";
    echo "<p><a href='/inventory/?tab=wms&wms_tab=pack'>Go back to WMS Pack</a></p>";

} catch (Exception $e) {
    echo "<div style='color: red; padding: 20px; border: 1px solid red; border-radius: 5px; background-color: #fff0f0;'>";
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
