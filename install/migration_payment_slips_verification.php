<?php
/**
 * Migration: Add GhostX slip-verification columns to payment_slips
 *
 * Stores the result of GhostX QR slip verification alongside the native
 * (non-Odoo) B2C payment-slip records created by api/checkout.php.
 *
 * Columns:
 *   verify_ref     — bank transactionRef (unique; prevents slip reuse)
 *   verify_amount  — amount confirmed by GhostX
 *   verify_data    — full GhostX response payload (JSON)
 *   verified_at    — when verification succeeded
 *
 * Run once on server:
 *   php install/migration_payment_slips_verification.php
 *
 * @spec ghostx-slip-verification
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    echo "=== Payment Slips Verification Migration ===\n\n";

    // Idempotent: skip if already applied.
    $cols = $db->query("SHOW COLUMNS FROM payment_slips LIKE 'verify_ref'")->fetchAll();
    if (count($cols) > 0) {
        echo "✓ Columns already exist — skipping migration.\n";
        exit(0);
    }

    $db->exec("
        ALTER TABLE payment_slips
        ADD COLUMN verify_ref VARCHAR(100) DEFAULT NULL COMMENT 'GhostX transactionRef (unique)' AFTER status,
        ADD COLUMN verify_amount DECIMAL(12,2) DEFAULT NULL COMMENT 'Amount confirmed by GhostX' AFTER verify_ref,
        ADD COLUMN verify_data JSON DEFAULT NULL COMMENT 'Full GhostX response payload' AFTER verify_amount,
        ADD COLUMN verified_at DATETIME DEFAULT NULL COMMENT 'When verification succeeded' AFTER verify_data
    ");
    echo "✓ Added columns: verify_ref, verify_amount, verify_data, verified_at\n";

    // Unique index on verify_ref guards against the same slip being reused.
    // MySQL allows multiple NULLs in a UNIQUE index, so unverified slips coexist.
    $db->exec("ALTER TABLE payment_slips ADD UNIQUE INDEX uniq_verify_ref (verify_ref)");
    echo "✓ Added unique index: uniq_verify_ref\n";

    echo "\n=== Migration complete ===\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
