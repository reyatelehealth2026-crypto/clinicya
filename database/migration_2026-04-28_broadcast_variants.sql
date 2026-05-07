-- ============================================================
-- Phase 1A: Broadcast A/B variants + per-variant analytics
-- Date: 2026-04-28
-- ============================================================
-- Adds broadcast_variants and stamps broadcast_queue / broadcast_clicks
-- with a variant_id so per-variant sent / delivered / click stats are queryable.
--
-- Idempotent. broadcast_queue and broadcast_clicks live on production but
-- have no canonical CREATE TABLE in this repo, so column additions are
-- guarded via INFORMATION_SCHEMA + dynamic SQL.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `broadcast_variants` (
    `id`              BIGINT       NOT NULL AUTO_INCREMENT,
    `broadcast_id`    BIGINT       NOT NULL,
    `variant_label`   VARCHAR(8)   NOT NULL  COMMENT 'A, B, C ...',
    `message_type`    ENUM('text','image','flex') NOT NULL,
    `content`         LONGTEXT     NOT NULL,
    `weight_pct`      TINYINT      NOT NULL DEFAULT 50  COMMENT 'Traffic split percent',
    `sent_count`      INT          NOT NULL DEFAULT 0,
    `delivered_count` INT          NOT NULL DEFAULT 0,
    `failed_count`    INT          NOT NULL DEFAULT 0,
    `click_count`     INT          NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_broadcast_label` (`broadcast_id`, `variant_label`),
    KEY `idx_broadcast` (`broadcast_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS p_phase1a_add_variant_cols;
DELIMITER //
CREATE PROCEDURE p_phase1a_add_variant_cols()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_queue'
                     AND COLUMN_NAME = 'variant_id') THEN
        ALTER TABLE `broadcast_queue`
            ADD COLUMN `variant_id` BIGINT NULL AFTER `broadcast_id`,
            ADD KEY `idx_variant` (`variant_id`);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_clicks'
                     AND COLUMN_NAME = 'variant_id') THEN
        ALTER TABLE `broadcast_clicks`
            ADD COLUMN `variant_id` BIGINT NULL AFTER `broadcast_id`;
    END IF;
END//
DELIMITER ;

CALL p_phase1a_add_variant_cols();
DROP PROCEDURE p_phase1a_add_variant_cols;
