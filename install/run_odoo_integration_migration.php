<?php
/**
 * Run Odoo ERP Integration Migration
 * สร้างตารางสำหรับการเชื่อมต่อกับ Odoo ERP
 * 
 * Tables created:
 * - odoo_line_users: เชื่อมต่อ LINE users กับ Odoo partners
 * - odoo_webhooks_log: บันทึก webhooks จาก Odoo
 * - odoo_slip_uploads: ติดตามการอัพโหลดสลิปการชำระเงิน
 * - odoo_api_logs: บันทึก API calls (optional)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔗 Odoo ERP Integration Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();

    // Define Odoo integration tables to check
    $odooTables = [
        'odoo_line_users',
        'odoo_webhooks_log',
        'odoo_slip_uploads',
        'odoo_api_logs'
    ];

    // Check if tables already exist
    echo "📋 Checking existing Odoo integration tables:\n";
    foreach ($odooTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "⚠️ Table '$table' already exists\n";
        } else {
            echo "➡️ Table '$table' will be created\n";
        }
    }
    echo "\n";

    $success = 0;
    $skipped = 0;
    $errors = 0;

    echo "🔄 Running Odoo integration migration...\n\n";

    // SQL Content directly embedded to avoid file path issues
    $sql = <<<'SQL'
-- ============================================================================
-- Odoo ERP Integration - Database Migration
-- ============================================================================

CREATE TABLE IF NOT EXISTS odoo_line_users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT NOT NULL COMMENT 'Reference to line_accounts table',
  line_user_id VARCHAR(100) NOT NULL COMMENT 'LINE user ID (U...)',
  odoo_partner_id INT NOT NULL COMMENT 'Odoo partner ID',
  odoo_partner_name VARCHAR(255) COMMENT 'Partner name from Odoo',
  odoo_customer_code VARCHAR(100) COMMENT 'Customer code from Odoo',
  linked_via ENUM('phone', 'email', 'customer_code') NOT NULL COMMENT 'Method used to link account',
  line_notification_enabled TINYINT(1) DEFAULT 1 COMMENT 'Enable/disable LINE notifications',
  linked_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When account was linked',
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  
  UNIQUE KEY unique_line_user (line_user_id),
  INDEX idx_odoo_partner (odoo_partner_id),
  INDEX idx_line_account (line_account_id),
  INDEX idx_notification_enabled (line_notification_enabled),
  
  FOREIGN KEY fk_line_account (line_account_id) 
    REFERENCES line_accounts(id) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Links LINE users with Odoo partner accounts';

CREATE TABLE IF NOT EXISTS odoo_webhooks_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT COMMENT 'Reference to line_accounts table (nullable for shared mode)',
  delivery_id VARCHAR(100) NOT NULL COMMENT 'X-Odoo-Delivery-Id header for idempotency',
  event_type VARCHAR(100) NOT NULL COMMENT 'Webhook event type (e.g., order.validated)',
  payload JSON NOT NULL COMMENT 'Full webhook payload',
  signature VARCHAR(255) COMMENT 'X-Odoo-Signature header',
  processed_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When webhook was processed',
  status ENUM('success', 'failed') DEFAULT 'success' COMMENT 'Processing status',
  error_message TEXT COMMENT 'Error details if failed',
  line_user_id VARCHAR(100) COMMENT 'LINE user ID that received notification',
  order_id INT COMMENT 'Odoo order ID (if applicable)',
  
  UNIQUE KEY unique_delivery_id (delivery_id),
  INDEX idx_event_type (event_type),
  INDEX idx_processed_at (processed_at),
  INDEX idx_line_user (line_user_id),
  INDEX idx_status (status),
  INDEX idx_order_id (order_id),
  
  FOREIGN KEY fk_webhook_line_account (line_account_id) 
    REFERENCES line_accounts(id) 
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Logs all incoming webhooks from Odoo for audit trail';

CREATE TABLE IF NOT EXISTS odoo_slip_uploads (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT NOT NULL COMMENT 'Reference to line_accounts table',
  line_user_id VARCHAR(100) NOT NULL COMMENT 'LINE user ID who uploaded slip',
  odoo_slip_id INT COMMENT 'Slip ID from Odoo',
  odoo_partner_id INT COMMENT 'Odoo partner ID',
  bdo_id INT COMMENT 'Bank Deposit Order ID (if matched)',
  invoice_id INT COMMENT 'Invoice ID (if matched)',
  order_id INT COMMENT 'Order ID (if matched)',
  amount DECIMAL(10,2) COMMENT 'Payment amount from slip',
  transfer_date DATE COMMENT 'Transfer date from slip',
  status ENUM('new', 'pending', 'matched', 'payment_created', 'posted', 'done', 'failed') DEFAULT 'new' COMMENT 'Matching status',
  match_reason TEXT COMMENT 'Reason for match/fail',
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When slip was uploaded',
  matched_at DATETIME COMMENT 'When slip was matched',
  
  INDEX idx_line_user (line_user_id),
  INDEX idx_status (status),
  INDEX idx_uploaded_at (uploaded_at),
  INDEX idx_odoo_slip (odoo_slip_id),
  INDEX idx_bdo (bdo_id),
  INDEX idx_invoice (invoice_id),
  
  FOREIGN KEY fk_slip_line_account (line_account_id) 
    REFERENCES line_accounts(id) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks payment slip uploads and auto-matching results';

CREATE TABLE IF NOT EXISTS odoo_api_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT NOT NULL COMMENT 'Reference to line_accounts table',
  endpoint VARCHAR(255) NOT NULL COMMENT 'API endpoint called',
  method VARCHAR(10) DEFAULT 'POST' COMMENT 'HTTP method',
  request_params JSON COMMENT 'Request parameters',
  response_data JSON COMMENT 'Response data',
  status_code INT COMMENT 'HTTP status code',
  error_message TEXT COMMENT 'Error message if failed',
  duration_ms INT COMMENT 'Request duration in milliseconds',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When API call was made',
  
  INDEX idx_endpoint (endpoint),
  INDEX idx_created_at (created_at),
  INDEX idx_status_code (status_code),
  INDEX idx_line_account (line_account_id),
  
  FOREIGN KEY fk_api_log_line_account (line_account_id) 
    REFERENCES line_accounts(id) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Optional: Logs all API calls to Odoo for debugging';
SQL;

    // Extract CREATE TABLE statements
    preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/s', $sql, $matches);

    echo "📝 Creating Odoo integration tables:\n";
    foreach ($matches[0] as $createStmt) {
        try {
            $db->exec($createStmt);
            // Extract table name for display
            preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
            $tableName = $tableMatch[1] ?? 'unknown';
            echo "✅ Created table: $tableName\n";
            $success++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
                $tableName = $tableMatch[1] ?? 'unknown';
                echo "⚠️ Skipped (already exists): $tableName\n";
                $skipped++;
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }

    echo "\n";
    echo "========================================\n";
    echo "✅ Success: $success operations\n";
    if ($skipped > 0) {
        echo "⚠️ Skipped: $skipped operations\n";
    }
    if ($errors > 0) {
        echo "❌ Errors: $errors operations\n";
    }
    echo "========================================\n";

    // Verify Odoo integration tables
    echo "\n📋 Verifying Odoo integration tables:\n";
    foreach ($odooTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $countStmt = $db->query("SELECT COUNT(*) FROM `$table`");
            $count = $countStmt->fetchColumn();
            echo "✅ Table '$table' exists ($count rows)\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }

    // Show linked_via enum values
    echo "\n📋 User Linking Methods (linked_via):\n";
    $stmt = $db->query("SHOW COLUMNS FROM odoo_line_users LIKE 'linked_via'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }

    // Show webhook status enum values
    echo "\n📋 Webhook Status values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'status'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }

    // Show slip upload status enum values
    echo "\n📋 Slip Upload Status values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM odoo_slip_uploads LIKE 'status'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }

    // Show table structure details
    echo "\n📋 Table Structure Details:\n";

    echo "\n🔹 odoo_line_users:\n";
    echo "   Purpose: เชื่อมต่อ LINE users กับ Odoo partner accounts\n";
    echo "   Key fields: line_user_id, odoo_partner_id, linked_via\n";

    echo "\n🔹 odoo_webhooks_log:\n";
    echo "   Purpose: บันทึก webhooks จาก Odoo (audit trail)\n";
    echo "   Key fields: delivery_id (idempotency), event_type, payload\n";

    echo "\n🔹 odoo_slip_uploads:\n";
    echo "   Purpose: ติดตามการอัพโหลดสลิปและ auto-matching\n";
    echo "   Key fields: line_user_id, odoo_slip_id, status, match_reason\n";

    echo "\n🔹 odoo_api_logs:\n";
    echo "   Purpose: บันทึก API calls (optional, for debugging)\n";
    echo "   Key fields: endpoint, request_params, response_data, duration_ms\n";

    echo "\n🎉 Odoo Integration Migration completed!\n";
    echo "\n📝 Next Steps:\n";
    echo "   1. Configure Odoo API credentials in config/config.php:\n";
    echo "      - ODOO_API_BASE_URL\n";
    echo "      - ODOO_API_KEY\n";
    echo "      - ODOO_WEBHOOK_SECRET\n";
    echo "   2. Test connection with: php install/test_odoo_api_client.php\n";
    echo "   3. Implement OdooAPIClient class\n";
    echo "   4. Implement OdooWebhookHandler class\n";
    echo "   5. Create webhook endpoint: /api/webhook/odoo.php\n";

} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "\n📝 Troubleshooting:\n";
    echo "   - Check database connection in config/config.php\n";
    echo "   - Verify migration file exists: database/migration_odoo_integration.sql\n";
    echo "   - Check database user has CREATE TABLE permissions\n";
}

echo "</pre>";
?>