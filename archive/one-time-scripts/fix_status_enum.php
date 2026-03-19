<?php
/**
 * Fix reward_redemptions status ENUM to include 'expired'
 * Current: ENUM('pending', 'approved', 'delivered', 'cancelled')
 * New: ENUM('pending', 'approved', 'delivered', 'cancelled', 'expired')
 */

require_once __DIR__ . '/config.php';

try {
    echo "=== Fixing reward_redemptions.status ENUM ===\n\n";

    // First, check current structure
    echo "Checking current structure...\n";
    $stmt = $db->query("SHOW COLUMNS FROM reward_redemptions WHERE Field = 'status'");
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Current Type: " . $current['Type'] . "\n\n";

    // Alter the column to add 'expired'
    echo "Altering column to add 'expired'...\n";
    $sql = "ALTER TABLE reward_redemptions
            MODIFY COLUMN `status` ENUM('pending', 'approved', 'delivered', 'cancelled', 'expired')
            DEFAULT 'pending'";

    $db->exec($sql);
    echo "✓ Column altered successfully!\n\n";

    // Verify the change
    echo "Verifying change...\n";
    $stmt = $db->query("SHOW COLUMNS FROM reward_redemptions WHERE Field = 'status'");
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Updated Type: " . $updated['Type'] . "\n\n";

    echo "=== SUCCESS! ===\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
