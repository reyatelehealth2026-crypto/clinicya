-- =====================================================================
-- migration_2026-07-04_pdpa_data_rights.sql
-- =====================================================================
-- PDPA data-subject self-service rights (พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล).
--
-- Purpose:
--   Let customers exercise their PDPA rights themselves from the LINE
--   Mini App instead of the manual email-only flow: withdraw consent,
--   request account/data deletion, and export their own data.
--
--   This migration adds the storage for the DELETION-REQUEST right:
--     * users.deletion_status / users.deletion_requested_at — a SOFT flag
--       on the user row (NO hard delete — Thai tax/audit-retention rules
--       and the append-only consultation_audit trail require us to keep
--       records; actual erasure is handled by staff within 30 days per the
--       published data-deletion.php policy).
--     * data_deletion_requests — one row per request, with a confirmation
--       code the customer can quote when following up.
--
-- Scope:
--   Per-tenant table/columns — apply to every reya_tenant_* DB (and re-run
--   across all tenants for existing installs). IDEMPOTENT: the column adds
--   are guarded by INFORMATION_SCHEMA checks and the table uses
--   CREATE TABLE IF NOT EXISTS. DataRightsService also lazily creates these
--   on first use as a resilience fallback (repo auto-create pattern).
-- =====================================================================

-- ── Soft deletion flag on users (idempotent) ────────────────────────
DROP PROCEDURE IF EXISTS p_pdpa_add_user_deletion_cols;
DELIMITER //
CREATE PROCEDURE p_pdpa_add_user_deletion_cols()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'users'
                     AND COLUMN_NAME = 'deletion_status') THEN
        ALTER TABLE `users`
            ADD COLUMN `deletion_status` ENUM('none','requested','processing','completed') NOT NULL DEFAULT 'none'
                COMMENT 'สถานะคำขอลบข้อมูลตาม PDPA (soft flag — ไม่ลบแถวจริง)';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'users'
                     AND COLUMN_NAME = 'deletion_requested_at') THEN
        ALTER TABLE `users`
            ADD COLUMN `deletion_requested_at` DATETIME NULL
                COMMENT 'เวลาที่ผู้ใช้ขอลบข้อมูล (PDPA)';
    END IF;
END//
DELIMITER ;

CALL p_pdpa_add_user_deletion_cols();
DROP PROCEDURE p_pdpa_add_user_deletion_cols;

-- ── Deletion-request ledger (idempotent) ────────────────────────────
CREATE TABLE IF NOT EXISTS `data_deletion_requests` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `line_account_id`   INT NULL COMMENT 'บัญชี LINE OA / tenant scope',
    `user_id`           INT NOT NULL COMMENT 'users.id ที่ขอลบข้อมูล',
    `line_user_id`      VARCHAR(50) NOT NULL COMMENT 'LINE user id ตอนยื่นคำขอ (สำเนาไว้ตรวจสอบ)',
    `confirmation_code` VARCHAR(20) NOT NULL COMMENT 'รหัสยืนยันคำขอ (ลูกค้าใช้อ้างอิงเวลาติดตาม)',
    `status`            ENUM('requested','processing','completed','cancelled') NOT NULL DEFAULT 'requested',
    `reason`            TEXT NULL COMMENT 'เหตุผลของผู้ใช้ (ถ้ามี)',
    `ip_address`        VARCHAR(45) NULL,
    `user_agent`        TEXT NULL,
    `requested_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at`      DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_confirmation_code` (`confirmation_code`),
    KEY `idx_ddr_user` (`user_id`),
    KEY `idx_ddr_account` (`line_account_id`),
    KEY `idx_ddr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='PDPA data-deletion requests — soft-flag ledger, no hard delete';
