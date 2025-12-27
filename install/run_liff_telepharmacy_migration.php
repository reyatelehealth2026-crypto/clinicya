<?php
/**
 * LIFF Telepharmacy Migration Runner
 * Run this script to set up all required tables for LIFF Telepharmacy
 */

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🏥 LIFF Telepharmacy Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Set PDO to throw exceptions
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Database connection successful\n";
    echo "Database: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
    
    echo "Creating tables directly...\n\n";
    
    $tablesCreated = 0;
    $errors = [];
    
    // 1. Prescription Approvals Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS prescription_approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            pharmacist_id INT NULL,
            approved_items JSON NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'expired', 'used') DEFAULT 'pending',
            video_call_id INT NULL,
            notes TEXT NULL,
            line_account_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_expires_at (expires_at),
            INDEX idx_line_account_id (line_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: prescription_approvals\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ prescription_approvals: " . $e->getMessage() . "\n";
        $errors[] = "prescription_approvals: " . $e->getMessage();
    }

    // 2. User Health Profiles Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_health_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(50) NOT NULL,
            line_account_id INT DEFAULT 0,
            age INT DEFAULT NULL,
            gender ENUM('male', 'female', 'other') DEFAULT NULL,
            weight DECIMAL(5,2) DEFAULT NULL,
            height DECIMAL(5,2) DEFAULT NULL,
            blood_type ENUM('A', 'B', 'AB', 'O', 'unknown') DEFAULT 'unknown',
            medical_conditions JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (line_user_id, line_account_id),
            INDEX idx_line_user (line_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: user_health_profiles\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ user_health_profiles: " . $e->getMessage() . "\n";
        $errors[] = "user_health_profiles: " . $e->getMessage();
    }

    // 3. User Drug Allergies Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_drug_allergies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(50) NOT NULL,
            line_account_id INT DEFAULT 0,
            drug_name VARCHAR(255) NOT NULL,
            drug_id INT DEFAULT NULL,
            reaction_type ENUM('rash', 'breathing', 'swelling', 'other') DEFAULT 'other',
            reaction_notes TEXT DEFAULT NULL,
            severity ENUM('mild', 'moderate', 'severe') DEFAULT 'moderate',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_line_user (line_user_id),
            INDEX idx_drug (drug_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: user_drug_allergies\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ user_drug_allergies: " . $e->getMessage() . "\n";
        $errors[] = "user_drug_allergies: " . $e->getMessage();
    }

    // 4. User Current Medications Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_current_medications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(50) NOT NULL,
            line_account_id INT DEFAULT 0,
            medication_name VARCHAR(255) NOT NULL,
            product_id INT DEFAULT NULL,
            dosage VARCHAR(100) DEFAULT NULL,
            frequency VARCHAR(100) DEFAULT NULL,
            start_date DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_line_user (line_user_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: user_current_medications\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ user_current_medications: " . $e->getMessage() . "\n";
        $errors[] = "user_current_medications: " . $e->getMessage();
    }

    // 5. Medication Reminders Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS medication_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            line_user_id VARCHAR(50),
            line_account_id INT,
            medication_name VARCHAR(255) NOT NULL,
            dosage VARCHAR(100),
            frequency VARCHAR(50),
            reminder_times JSON,
            start_date DATE,
            end_date DATE,
            notes TEXT,
            is_active TINYINT(1) DEFAULT 1,
            product_id INT,
            order_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_line_user (line_user_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: medication_reminders\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ medication_reminders: " . $e->getMessage() . "\n";
        $errors[] = "medication_reminders: " . $e->getMessage();
    }

    // 6. Medication Taken History Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS medication_taken_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reminder_id INT NOT NULL,
            user_id INT NOT NULL,
            scheduled_time TIME,
            taken_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('taken', 'skipped', 'missed') DEFAULT 'taken',
            notes TEXT,
            INDEX idx_reminder (reminder_id),
            INDEX idx_user (user_id),
            INDEX idx_date (taken_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: medication_taken_history\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ medication_taken_history: " . $e->getMessage() . "\n";
        $errors[] = "medication_taken_history: " . $e->getMessage();
    }

    // 7. User Notification Preferences Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_notification_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            line_user_id VARCHAR(50),
            line_account_id INT,
            order_updates TINYINT(1) DEFAULT 1,
            promotions TINYINT(1) DEFAULT 1,
            appointment_reminders TINYINT(1) DEFAULT 1,
            drug_reminders TINYINT(1) DEFAULT 1,
            health_tips TINYINT(1) DEFAULT 0,
            price_alerts TINYINT(1) DEFAULT 1,
            restock_alerts TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (user_id),
            INDEX idx_line_user (line_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: user_notification_preferences\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ user_notification_preferences: " . $e->getMessage() . "\n";
        $errors[] = "user_notification_preferences: " . $e->getMessage();
    }

    // 8. Drug Interaction Acknowledgments Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS drug_interaction_acknowledgments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            line_user_id VARCHAR(50),
            drug1_id INT NOT NULL,
            drug2_id INT NOT NULL,
            drug1_name VARCHAR(255),
            drug2_name VARCHAR(255),
            severity ENUM('mild', 'moderate', 'severe') DEFAULT 'moderate',
            acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            order_id INT NULL,
            INDEX idx_user (user_id),
            INDEX idx_drugs (drug1_id, drug2_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: drug_interaction_acknowledgments\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ drug_interaction_acknowledgments: " . $e->getMessage() . "\n";
        $errors[] = "drug_interaction_acknowledgments: " . $e->getMessage();
    }

    // 9. LIFF Message Logs Table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS liff_message_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(50) NOT NULL,
            line_account_id INT,
            action_type VARCHAR(50) NOT NULL,
            message_data JSON,
            sent_via ENUM('liff', 'api') DEFAULT 'liff',
            status ENUM('sent', 'failed', 'pending') DEFAULT 'sent',
            error_message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (line_user_id),
            INDEX idx_action (action_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ Created table: liff_message_logs\n";
        $tablesCreated++;
    } catch (PDOException $e) {
        echo "⚠️ liff_message_logs: " . $e->getMessage() . "\n";
        $errors[] = "liff_message_logs: " . $e->getMessage();
    }

    echo "\n========================================\n";
    echo "Tables created: {$tablesCreated}/9\n";
    if (!empty($errors)) {
        echo "Errors: " . count($errors) . "\n";
    }
    echo "========================================\n";
    
    // Check LIFF configuration
    echo "\n📱 Checking LIFF Configuration...\n\n";
    
    // First, ensure cart_items table exists (required for checkout)
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_cart_item (user_id, product_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ cart_items table ready\n\n";
    } catch (PDOException $e) {
        echo "⚠️ cart_items: " . $e->getMessage() . "\n\n";
    }
    
    $stmt = $db->query("SELECT id, name, liff_id, is_default, is_active FROM line_accounts ORDER BY is_default DESC, id ASC");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "⚠️ No LINE accounts found. Please add a LINE account first.\n";
    } else {
        echo "LINE Accounts:\n";
        foreach ($accounts as $acc) {
            $default = $acc['is_default'] ? ' (DEFAULT)' : '';
            $active = $acc['is_active'] ? '✅' : '❌';
            $liffStatus = !empty($acc['liff_id']) ? '✅' : '❌ Missing';
            echo "  {$active} ID: {$acc['id']} - {$acc['name']}{$default}\n";
            echo "     LIFF ID: {$liffStatus} {$acc['liff_id']}\n";
        }
    }
    
    // Check required tables
    echo "\n📋 Checking Required Tables...\n\n";
    
    $requiredTables = [
        'prescription_approvals',
        'user_health_profiles',
        'user_drug_allergies',
        'user_current_medications',
        'medication_reminders',
        'medication_taken_history',
        'user_notification_preferences',
        'drug_interaction_acknowledgments',
        'liff_message_logs',
        'cart_items',
        'video_calls',
        'video_call_signals',
        'video_call_settings'
    ];
    
    $allTablesExist = true;
    
    foreach ($requiredTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        $status = $exists ? '✅' : '❌';
        echo "  {$status} {$table}\n";
        if (!$exists) $allTablesExist = false;
    }
    
    echo "\n";
    echo "========================================\n";
    if ($allTablesExist) {
        echo "🎉 All tables ready!\n";
    } else {
        echo "⚠️ Some tables are missing. Please check errors above.\n";
    }
    echo "========================================\n";
    echo "\n";
    echo "Next Steps:\n";
    echo "1. Ensure LIFF ID is configured in LINE Developers Console\n";
    echo "2. Set LIFF Endpoint URL to: " . BASE_URL . "liff/index.php\n";
    echo "3. Access LIFF app via: https://liff.line.me/{YOUR_LIFF_ID}\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
