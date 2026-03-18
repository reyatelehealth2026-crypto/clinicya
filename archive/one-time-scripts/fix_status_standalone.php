<?php
/**
 * Fix reward_redemptions status ENUM - Standalone version
 */

// Database credentials
$host = 'localhost';
$dbname = 'zrismpsz_cny';
$username = 'zrismpsz_cny';
$password = 'zrismpsz_cny';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== Fixing reward_redemptions.status ENUM ===\n\n";

    // Check current structure
    echo "1. Checking current structure...\n";
    $stmt = $db->query("SHOW COLUMNS FROM reward_redemptions WHERE Field = 'status'");
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Current Type: " . $current['Type'] . "\n\n";

    // Alter the column
    echo "2. Altering column to add 'expired'...\n";
    $sql = "ALTER TABLE reward_redemptions
            MODIFY COLUMN `status` ENUM('pending', 'approved', 'delivered', 'cancelled', 'expired')
            DEFAULT 'pending'";

    $db->exec($sql);
    echo "   ✓ ALTER TABLE executed\n\n";

    // Verify
    echo "3. Verifying change...\n";
    $stmt = $db->query("SHOW COLUMNS FROM reward_redemptions WHERE Field = 'status'");
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Updated Type: " . $updated['Type'] . "\n\n";

    echo "=== ✓ SUCCESS! ===\n";
    echo "The 'expired' value has been added to the status ENUM.\n";

} catch (PDOException $e) {
    echo "=== ✗ ERROR ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    exit(1);
}
