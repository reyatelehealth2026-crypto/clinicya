<?php
/**
 * Debug WMS Schema
 * Checks the transactions table status column definition
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Database Schema Check</h1>";

try {
    echo "<h2>Transactions Table Structure</h2>";
    $stmt = $db->query("DESCRIBE transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusCol = null;
    $wmsStatusCol = null;

    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";

    foreach ($columns as $col) {
        $bg = '';
        if ($col['Field'] === 'status') {
            $statusCol = $col;
            $bg = 'background: #ffeeba; font-weight: bold;';
        }
        if ($col['Field'] === 'wms_status') {
            $wmsStatusCol = $col;
            $bg = 'background: #d1e7dd;';
        }

        echo "<tr style='$bg'>";
        foreach ($col as $val) {
            echo "<td>" . htmlspecialchars($val) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    echo "<h3>Analysis:</h3>";
    if ($statusCol) {
        $type = $statusCol['Type'];
        echo "<p>Status Column Type: <strong>$type</strong></p>";

        if (strpos(strtoupper($type), 'ENUM') !== false) {
            echo "<div style='color: red; padding: 10px; border: 1px solid red; border-radius: 5px; background: #fff0f0;'>";
            echo "❌ <strong>ISSUE FOUND:</strong> The 'status' column is still an ENUM. It needs to be VARCHAR(50).<br>";
            echo "The migration did not run successfully.";
            echo "</div>";
        } elseif (strpos(strtoupper($type), 'VARCHAR') !== false) {
            echo "<div style='color: green; padding: 10px; border: 1px solid green; border-radius: 5px; background: #f0fff4;'>";
            echo "✅ <strong>OK:</strong> The 'status' column is a VARCHAR. The schema looks correct.";
            echo "</div>";
        }
    } else {
        echo "<p style='color: red;'>❌ Column 'status' not found!</p>";
    }

    // Check WMS Pick Items Schema as well just in case
    echo "<h2>WMS Pick Items Structure</h2>";
    try {
        $stmt = $db->query("DESCRIBE wms_pick_items");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            foreach ($col as $val) {
                echo "<td>" . htmlspecialchars($val) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p>Table wms_pick_items does not exist.</p>";
    }

} catch (Exception $e) {
    echo "<div style='color: red; padding: 20px; border: 1px solid red; border-radius: 5px;'>";
    echo "❌ Error: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
