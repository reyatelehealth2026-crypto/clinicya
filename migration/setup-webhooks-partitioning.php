<?php
/**
 * Setup monthly partitioning for odoo_webhooks_log (MySQL/MariaDB)
 * Run: php migration/setup-webhooks-partitioning.php [--dry-run]
 *
 * Prerequisites:
 *  - ตรวจสอบ FK constraints ก่อน: SHOW CREATE TABLE odoo_webhooks_log;
 *  - ลบ FK constraints ก่อนรัน หากมี (InnoDB ไม่รองรับ partitioned tables with FK)
 *  - ตรวจสอบว่า created_at ไม่อนุญาต NULL (PARTITION BY RANGE ต้องการ NOT NULL)
 *
 * ข้อแก้ไขจากแผนเดิม:
 *  - ไม่ใช้ PostgreSQL syntax "CREATE TABLE ... INCLUDING ALL"
 *  - ใช้ ALTER TABLE ... PARTITION BY RANGE บน MySQL/MariaDB โดยตรง
 *  - ตรวจสอบ information_schema.PARTITIONS ก่อนเพื่อไม่รันซ้ำ
 */

require_once __DIR__ . '/../config/database.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$db = Database::getInstance()->getConnection();

// Only proceed if > 500K rows
$count = (int) $db->query("SELECT COUNT(*) FROM odoo_webhooks_log")->fetchColumn();
if ($count < 500000) {
    echo "Table has {$count} rows (< 500K). Partitioning not needed yet.\n";
    exit(0);
}

// Check if already partitioned
$stmt = $db->query(
    "SELECT PARTITION_NAME FROM information_schema.PARTITIONS
     WHERE TABLE_NAME = 'odoo_webhooks_log'
       AND TABLE_SCHEMA = DATABASE()
       AND PARTITION_NAME IS NOT NULL
     LIMIT 1"
);
if ($stmt->fetch()) {
    echo "Table is already partitioned. Skipping.\n";
    exit(0);
}

echo "Table has {$count} rows. Setting up monthly partitioning...\n";

// Build partition list: 12 months back + 3 months ahead + catch-all future
$partitions = [];
for ($i = -12; $i <= 3; $i++) {
    $date = new DateTime();
    $date->modify("{$i} months");
    $date->modify('first day of this month');

    $nextMonth = clone $date;
    $nextMonth->modify('+1 month');

    $partKey  = 'p' . $date->format('Ym');
    $lessVal  = (int) $nextMonth->format('Ym');
    $partitions[] = "    PARTITION {$partKey} VALUES LESS THAN ({$lessVal})";
}
$partitions[] = "    PARTITION p_future VALUES LESS THAN MAXVALUE";

$sql = "ALTER TABLE odoo_webhooks_log\n"
     . "PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at))\n"
     . "(\n"
     . implode(",\n", $partitions)
     . "\n)";

if ($dryRun) {
    echo "[DRY RUN] Would execute:\n{$sql}\n";
    exit(0);
}

echo "Executing ALTER TABLE (this may take several minutes for large tables)...\n";
try {
    $start = microtime(true);
    $db->exec($sql);
    $elapsed = round(microtime(true) - $start, 1);
    echo "✅ Partitioning complete in {$elapsed}s\n";
    echo "Partition count: " . count($partitions) . "\n";
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    if (str_contains($e->getMessage(), 'foreign key')) {
        echo "Tip: Remove foreign key constraints before partitioning:\n";
        echo "  ALTER TABLE odoo_webhooks_log DROP FOREIGN KEY <fk_name>;\n";
    }
    exit(1);
}
