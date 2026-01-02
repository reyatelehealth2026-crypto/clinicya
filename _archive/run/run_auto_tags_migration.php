<?php
/**
 * Run Auto Tags Migration
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🔧 Auto Tags Migration</h2>";

// อ่าน migration file
$migrationFile = __DIR__ . '/database/migration_auto_tags.sql';
if (!file_exists($migrationFile)) {
    echo "<p style='color:red'>❌ Migration file not found</p>";
    exit;
}

$sql = file_get_contents($migrationFile);

// แยก SQL statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = 0;
$errors = 0;

foreach ($statements as $statement) {
    if (empty($statement) || strpos($statement, '--') === 0) continue;
    
    try {
        $db->exec($statement);
        $success++;
        echo "<p style='color:green'>✅ " . substr($statement, 0, 80) . "...</p>";
    } catch (Exception $e) {
        $errors++;
        $errorMsg = $e->getMessage();
        // ไม่แสดง error ถ้าเป็น duplicate หรือ already exists
        if (strpos($errorMsg, 'Duplicate') === false && strpos($errorMsg, 'already exists') === false) {
            echo "<p style='color:orange'>⚠️ " . substr($statement, 0, 50) . "... - " . $errorMsg . "</p>";
        }
    }
}

echo "<h3>สรุป</h3>";
echo "<p>สำเร็จ: {$success} statements</p>";
echo "<p>Errors/Skipped: {$errors} statements</p>";

// ตรวจสอบตาราง
echo "<h3>ตรวจสอบตาราง</h3>";

$tables = ['user_tags', 'auto_tag_rules', 'auto_tag_logs'];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        echo "<p>✅ {$table}: {$count} rows</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ {$table}: " . $e->getMessage() . "</p>";
    }
}

echo "<p><a href='dashboard.php?tab=crm'>ไปหน้า CRM Dashboard</a></p>";
echo "<p><a href='auto-tag-rules.php'>ไปหน้า Auto Tag Rules</a></p>";
?>
