<?php
/**
 * Run Admin User Fields Migration
 * เพิ่ม field เลขบัตรประชาชน, วันเกิด, เงินเดือน และตาราง payroll_records
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔄 Running Admin User Fields Migration...</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Add id_card column
    try {
        $db->exec("ALTER TABLE admin_users ADD COLUMN id_card VARCHAR(13) NULL COMMENT 'เลขบัตรประชาชน 13 หลัก' AFTER line_user_id");
        echo "✅ Added id_card column<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⏭️ id_card column already exists<br>";
        } else {
            throw $e;
        }
    }
    
    // Add birth_date column
    try {
        $db->exec("ALTER TABLE admin_users ADD COLUMN birth_date DATE NULL COMMENT 'วันเดือนปีเกิด' AFTER id_card");
        echo "✅ Added birth_date column<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⏭️ birth_date column already exists<br>";
        } else {
            throw $e;
        }
    }
    
    // Add salary column
    try {
        $db->exec("ALTER TABLE admin_users ADD COLUMN salary DECIMAL(12,2) NULL DEFAULT 0 COMMENT 'เงินเดือน' AFTER birth_date");
        echo "✅ Added salary column<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "⏭️ salary column already exists<br>";
        } else {
            throw $e;
        }
    }
    
    // Create payroll_records table
    $db->exec("
        CREATE TABLE IF NOT EXISTS payroll_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            pay_date DATE NOT NULL COMMENT 'วันที่จ่ายเงินเดือน',
            pay_period_start DATE NOT NULL COMMENT 'งวดเริ่มต้น',
            pay_period_end DATE NOT NULL COMMENT 'งวดสิ้นสุด',
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'เงินเดือนพื้นฐาน',
            overtime DECIMAL(12,2) DEFAULT 0 COMMENT 'ค่าล่วงเวลา',
            bonus DECIMAL(12,2) DEFAULT 0 COMMENT 'โบนัส',
            deductions DECIMAL(12,2) DEFAULT 0 COMMENT 'หักเงิน',
            social_security DECIMAL(12,2) DEFAULT 0 COMMENT 'ประกันสังคม',
            tax DECIMAL(12,2) DEFAULT 0 COMMENT 'ภาษี',
            net_salary DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'เงินเดือนสุทธิ',
            notes TEXT NULL COMMENT 'หมายเหตุ',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_admin_id (admin_id),
            INDEX idx_pay_date (pay_date),
            INDEX idx_pay_period (pay_period_start, pay_period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created payroll_records table<br>";
    
    echo "<br><h3>✅ Migration completed successfully!</h3>";
    echo "<p><a href='../admin-users.php'>← กลับไปหน้าจัดการพนักงาน</a></p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Migration failed:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
