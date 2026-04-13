<?php
/**
 * Migration: add `surface` columns to mini app merchandising tables.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>Migration: Mini App Shop Surface</h2>";

try {
    $db = Database::getInstance()->getConnection();
    $targets = [
        'miniapp_banners' => "ALTER TABLE miniapp_banners ADD COLUMN surface ENUM('home','shop') NOT NULL DEFAULT 'home' AFTER link_label",
        'miniapp_home_sections' => "ALTER TABLE miniapp_home_sections ADD COLUMN surface ENUM('home','shop') NOT NULL DEFAULT 'home' AFTER countdown_ends_at",
    ];

    foreach ($targets as $table => $sql) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'surface'"
        );
        $stmt->execute([$table]);
        $exists = (int) $stmt->fetchColumn() > 0;

        if ($exists) {
            echo "<p>✓ {$table}.surface already exists</p>";
            continue;
        }

        $db->exec($sql);
        echo "<p>✓ Added {$table}.surface</p>";
    }

    echo "<p><strong>Done.</strong></p>";
} catch (Throwable $e) {
    echo "<p style='color:red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit(1);
}
