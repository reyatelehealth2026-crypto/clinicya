<?php
require_once __DIR__ . '/config.php';

try {
    // Show table structure
    $stmt = $db->query("SHOW CREATE TABLE reward_redemptions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "=== REWARD_REDEMPTIONS TABLE STRUCTURE ===\n\n";
    echo $result['Create Table'];
    echo "\n\n";

    // Show columns
    echo "=== COLUMNS ===\n\n";
    $stmt = $db->query("SHOW COLUMNS FROM reward_redemptions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
