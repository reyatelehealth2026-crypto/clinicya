-- =====================================================================
-- Migration: AI Chat persistence + safety columns
-- Date: 2026-05-24
-- Purpose: Phase 1 of AI Chat Option D — ensure ai_conversation_history
--          has columns + index needed for Mini-App resume + per-session
--          safety context (allergies / chronic / current meds).
--
-- Idempotent: uses IF NOT EXISTS where possible and stored procedures
--             to guard ADD COLUMN / CREATE INDEX on older MySQL (5.7+).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) ai_conversation_history.line_account_id INT NULL
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS migrate_ach_add_line_account_id;
DELIMITER //
CREATE PROCEDURE migrate_ach_add_line_account_id()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversation_history'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_conversation_history'
          AND COLUMN_NAME = 'line_account_id'
    ) THEN
        ALTER TABLE `ai_conversation_history`
            ADD COLUMN `line_account_id` INT NULL AFTER `user_id`;
    END IF;
END //
DELIMITER ;
CALL migrate_ach_add_line_account_id();
DROP PROCEDURE IF EXISTS migrate_ach_add_line_account_id;

-- ---------------------------------------------------------------------
-- 2) ai_conversation_history.session_id VARCHAR(64) NULL
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS migrate_ach_add_session_id;
DELIMITER //
CREATE PROCEDURE migrate_ach_add_session_id()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversation_history'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_conversation_history'
          AND COLUMN_NAME = 'session_id'
    ) THEN
        ALTER TABLE `ai_conversation_history`
            ADD COLUMN `session_id` VARCHAR(64) NULL AFTER `line_account_id`;
    END IF;
END //
DELIMITER ;
CALL migrate_ach_add_session_id();
DROP PROCEDURE IF EXISTS migrate_ach_add_session_id;

-- ---------------------------------------------------------------------
-- 3) Composite index for fast per-user history fetches
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS migrate_ach_add_user_created_idx;
DELIMITER //
CREATE PROCEDURE migrate_ach_add_user_created_idx()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversation_history'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_conversation_history'
          AND INDEX_NAME = 'idx_ach_user_created'
    ) THEN
        ALTER TABLE `ai_conversation_history`
            ADD INDEX `idx_ach_user_created` (`user_id`, `created_at`);
    END IF;
END //
DELIMITER ;
CALL migrate_ach_add_user_created_idx();
DROP PROCEDURE IF EXISTS migrate_ach_add_user_created_idx;

-- ---------------------------------------------------------------------
-- 4) Helper index on session_id for session-scoped queries
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS migrate_ach_add_session_idx;
DELIMITER //
CREATE PROCEDURE migrate_ach_add_session_idx()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversation_history'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_conversation_history'
          AND INDEX_NAME = 'idx_ach_session'
    ) THEN
        ALTER TABLE `ai_conversation_history`
            ADD INDEX `idx_ach_session` (`session_id`);
    END IF;
END //
DELIMITER ;
CALL migrate_ach_add_session_idx();
DROP PROCEDURE IF EXISTS migrate_ach_add_session_idx;

-- ---------------------------------------------------------------------
-- 5) pharmacist_notifications table — used by the AI chat triage flow
--    to surface escalations on the pharmacist dashboard. Previously this
--    DDL ran inline inside includes/ai-chat-context.php on every request,
--    which is wasteful and made schema drift impossible to audit.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pharmacist_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NULL,
    `type` VARCHAR(50) DEFAULT 'triage_alert',
    `title` VARCHAR(255),
    `message` TEXT,
    `notification_data` JSON,
    `reference_id` INT,
    `reference_type` VARCHAR(50),
    `user_id` INT,
    `triage_session_id` INT NULL,
    `priority` ENUM('normal','urgent') DEFAULT 'normal',
    `status` ENUM('pending','handled','dismissed') DEFAULT 'pending',
    `is_read` TINYINT(1) DEFAULT 0,
    `handled_by` INT NULL,
    `handled_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_line_account` (`line_account_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_triage_session` (`triage_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
