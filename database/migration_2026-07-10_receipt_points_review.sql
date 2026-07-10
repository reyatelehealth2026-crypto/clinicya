-- Receipt Points Review: adds diagnostic + review-tracking columns to
-- receipt_point_claims so a new admin page can show WHY OCR didn't
-- auto-award, and who manually finished a pending claim.
-- Safe to re-run. See docs/adr/0007-receipt-points-review.md.

-- Base table may not exist yet on tenants that have never processed a
-- receipt claim (it's created lazily by webhook.php's
-- ensureReceiptPointClaimsTable()). Create it here with the FULL final
-- column set so a first-time tenant gets everything in one shot.
CREATE TABLE IF NOT EXISTS `receipt_point_claims` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `line_account_id` INT           DEFAULT NULL,
  `user_id`         INT           NOT NULL,
  `claim_key`       VARCHAR(255)  NOT NULL,
  `receipt_number`  VARCHAR(100)  DEFAULT NULL,
  `shop_name`       VARCHAR(255)  DEFAULT NULL,
  `total_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `points_awarded`  INT           NOT NULL DEFAULT 0,
  `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `status`          VARCHAR(30)   DEFAULT 'approved',
  `image_hash`      CHAR(64)      DEFAULT NULL,
  `image_path`      VARCHAR(255)  DEFAULT NULL,
  `ocr_amount`      DECIMAL(10,2) DEFAULT NULL COMMENT 'OCR-read total even when unverified/low-confidence',
  `confidence`      VARCHAR(20)   DEFAULT NULL COMMENT 'high|low|unverified|none',
  `fail_reason`     VARCHAR(50)   DEFAULT NULL COMMENT 'no_ocr_result|zero_amount|low_confidence; NULL for approved claims',
  `reviewed_by`     INT           DEFAULT NULL COMMENT 'admin_users.id of whoever manually awarded this',
  `reviewed_at`     DATETIME      DEFAULT NULL,
  UNIQUE KEY `uk_claim` (`line_account_id`, `claim_key`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_account` (`line_account_id`),
  KEY `idx_status`  (`line_account_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auto receipt-scan loyalty point claims';

-- Existing tenants already have the base 8 columns (+ maybe status/
-- image_hash/image_path from the runtime auto-migrate). Guard every
-- column individually — MySQL/MariaDB portable (no ADD COLUMN IF NOT
-- EXISTS, which is MariaDB-only).
SET @has_status := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'status');
SET @sql := IF(@has_status = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `status` VARCHAR(30) DEFAULT 'approved'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_image_hash := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'image_hash');
SET @sql := IF(@has_image_hash = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `image_hash` CHAR(64) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_image_path := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'image_path');
SET @sql := IF(@has_image_path = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_ocr_amount := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'ocr_amount');
SET @sql := IF(@has_ocr_amount = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `ocr_amount` DECIMAL(10,2) DEFAULT NULL COMMENT 'OCR-read total even when unverified/low-confidence'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_confidence := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'confidence');
SET @sql := IF(@has_confidence = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `confidence` VARCHAR(20) DEFAULT NULL COMMENT 'high|low|unverified|none'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fail_reason := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'fail_reason');
SET @sql := IF(@has_fail_reason = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `fail_reason` VARCHAR(50) DEFAULT NULL COMMENT 'no_ocr_result|zero_amount|low_confidence'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_reviewed_by := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'reviewed_by');
SET @sql := IF(@has_reviewed_by = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `reviewed_by` INT DEFAULT NULL COMMENT 'admin_users.id of whoever manually awarded this'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_reviewed_at := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND COLUMN_NAME = 'reviewed_at');
SET @sql := IF(@has_reviewed_at = 0, "ALTER TABLE `receipt_point_claims` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_status := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_point_claims' AND INDEX_NAME = 'idx_status');
SET @sql := IF(@has_idx_status = 0, "ALTER TABLE `receipt_point_claims` ADD KEY `idx_status` (`line_account_id`, `status`, `created_at`)", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
