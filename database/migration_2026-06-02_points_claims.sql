-- =============================================================================
-- Migration: Loyalty "give points via QR claim" — points_claims
-- Date:      2026-06-02
-- Scope:     points_claims (TENANT-SCOPED — apply to EACH reya_tenant_* DB)
--
-- Offline counter-sale loyalty flow:
--   1. Pharmacist generates a one-time claim token + QR (no customer lookup).
--   2. Customer scans QR → Mini App LIFF → POST api/points-claim.php?action=claim.
--   3. Backend resolves the LINE user, credits points (LoyaltyPoints::addPoints),
--      marks the token used (single-use), and pushes a Flex receipt.
--
-- Single-use + expiry are enforced in PHP via a guarded UPDATE
-- (status='pending' AND expires_at > NOW()); the UNIQUE token + status column
-- here back-stop that guarantee.
--
-- Re-run safe: CREATE uses IF NOT EXISTS; idempotent on partial deploys.
-- Charset: utf8mb4_unicode_ci (Thai language).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `points_claims` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `line_account_id` INT NOT NULL COMMENT 'tenant LINE OA scope — FK line_accounts.id',
  `token` VARCHAR(64) NOT NULL COMMENT 'one-time claim token (URL-safe random) — encoded in the QR',
  `voucher_no` VARCHAR(30) NOT NULL COMMENT 'human-facing voucher number, e.g. WI20260602-001',

  -- Award details (captured by the pharmacist at generation time)
  `points` INT NOT NULL DEFAULT 0 COMMENT 'แต้มที่จะให้ — points to credit on claim',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ยอดเงิน — sale amount in THB (0 when points entered directly)',
  `payment_method` VARCHAR(20) DEFAULT NULL COMMENT 'cash|transfer|card|qr — informational only',

  -- Lifecycle
  `status` ENUM('pending','claimed','expired','cancelled') NOT NULL DEFAULT 'pending'
    COMMENT 'pending=awaiting scan, claimed=points credited, expired=past expires_at, cancelled=voided',
  `claimed_by_user_id` INT NULL COMMENT 'FK users.id — the customer who claimed (set on claim)',
  `claimed_line_user_id` VARCHAR(64) NULL COMMENT 'LINE userId snapshot of the claimer',
  `points_transaction_id` INT NULL COMMENT 'FK points_transactions.id — the credit row created on claim',
  `claimed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'when the token was claimed',
  `expires_at` TIMESTAMP NOT NULL COMMENT 'token validity cut-off (default +30 min)',

  -- Audit
  `created_by` INT NULL COMMENT 'FK admin_users.id — pharmacist who generated the token',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_account_status` (`line_account_id`, `status`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_claimed_user` (`claimed_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One-time loyalty point claim tokens (give-points-via-QR). Tenant-scoped.';
