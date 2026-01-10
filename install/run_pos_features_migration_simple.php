<?php
/**
 * Simple POS Features Migration
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

echo "POS Features Migration\n";
echo "======================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. Add hold_note column
    try {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN hold_note VARCHAR(255) NULL");
        echo "✓ Added hold_note column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ hold_note already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 2. Add hold_at column
    try {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN hold_at DATETIME NULL");
        echo "✓ Added hold_at column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ hold_at already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 3. Add original_price column
    try {
        $db->exec("ALTER TABLE pos_transaction_items ADD COLUMN original_price DECIMAL(12,2) NULL");
        echo "✓ Added original_price column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ original_price already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 4. Add price_override_reason column
    try {
        $db->exec("ALTER TABLE pos_transaction_items ADD COLUMN price_override_reason VARCHAR(255) NULL");
        echo "✓ Added price_override_reason column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ price_override_reason already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 5. Add price_override_by column
    try {
        $db->exec("ALTER TABLE pos_transaction_items ADD COLUMN price_override_by INT NULL");
        echo "✓ Added price_override_by column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ price_override_by already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 6. Add price_override_at column
    try {
        $db->exec("ALTER TABLE pos_transaction_items ADD COLUMN price_override_at DATETIME NULL");
        echo "✓ Added price_override_at column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ price_override_at already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 7. Add reprint_count column
    try {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN reprint_count INT DEFAULT 0");
        echo "✓ Added reprint_count column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ reprint_count already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 8. Add last_reprint_at column
    try {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN last_reprint_at DATETIME NULL");
        echo "✓ Added last_reprint_at column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ last_reprint_at already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 9. Add last_reprint_by column
    try {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN last_reprint_by INT NULL");
        echo "✓ Added last_reprint_by column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ last_reprint_by already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 10. Add cash_adjustments column
    try {
        $db->exec("ALTER TABLE pos_shifts ADD COLUMN cash_adjustments DECIMAL(12,2) DEFAULT 0");
        echo "✓ Added cash_adjustments column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ cash_adjustments already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // 11. Create pos_cash_movements table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS pos_cash_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT NOT NULL,
                shift_id INT NOT NULL,
                movement_type ENUM('in', 'out') NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_by INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_shift (shift_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✓ Created pos_cash_movements table\n";
    } catch (PDOException $e) {
        echo "⚠ pos_cash_movements: " . $e->getMessage() . "\n";
    }
    
    // 12. Update status enum
    try {
        $db->exec("ALTER TABLE pos_transactions MODIFY COLUMN status ENUM('draft', 'hold', 'pending', 'completed', 'voided', 'refunded') DEFAULT 'draft'");
        echo "✓ Updated status enum\n";
    } catch (PDOException $e) {
        echo "⚠ status enum: " . $e->getMessage() . "\n";
    }
    
    // 13. Add returned_quantity column
    try {
        $db->exec("ALTER TABLE pos_transaction_items ADD COLUMN returned_quantity INT DEFAULT 0");
        echo "✓ Added returned_quantity column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠ returned_quantity already exists\n";
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n======================\n";
    echo "Migration Complete!\n";
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}
