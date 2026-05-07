-- ============================================================
-- Phase A: Unified Broadcast Schema
-- Date: 2026-05-04
-- ============================================================
-- 1. Extend broadcast_campaigns to cover quick-send + catalog use cases
-- 2. Add delivered/failed/unique-clicker counters for analytics
-- 3. Add tenant-scoped composite index
-- 4. Add broadcast_link_clicks table for text/flex URL tracking
-- 5. Add broadcast_drafts table for Catalog Builder save/resume
--
-- Idempotent. All ALTERs are guarded via INFORMATION_SCHEMA.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. broadcast_campaigns: extend ────────────────────────────
DROP PROCEDURE IF EXISTS p_phaseA_extend_campaigns;
DELIMITER //
CREATE PROCEDURE p_phaseA_extend_campaigns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_campaigns'
                     AND COLUMN_NAME = 'source') THEN
        ALTER TABLE `broadcast_campaigns`
            ADD COLUMN `source` ENUM('quick','catalog','products','drip') NOT NULL DEFAULT 'quick' AFTER `name`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_campaigns'
                     AND COLUMN_NAME = 'target_type') THEN
        ALTER TABLE `broadcast_campaigns`
            ADD COLUMN `target_type` VARCHAR(32) NOT NULL DEFAULT 'database' AFTER `tag_prefix`,
            ADD COLUMN `target_payload` JSON NULL AFTER `target_type`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_campaigns'
                     AND COLUMN_NAME = 'flex_payload') THEN
        ALTER TABLE `broadcast_campaigns`
            ADD COLUMN `flex_payload` LONGTEXT NULL AFTER `content`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_campaigns'
                     AND COLUMN_NAME = 'delivered_count') THEN
        ALTER TABLE `broadcast_campaigns`
            ADD COLUMN `delivered_count` INT NOT NULL DEFAULT 0 AFTER `sent_count`,
            ADD COLUMN `failed_count` INT NOT NULL DEFAULT 0 AFTER `delivered_count`,
            ADD COLUMN `unique_clickers` INT NOT NULL DEFAULT 0 AFTER `click_count`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_campaigns'
                     AND COLUMN_NAME = 'created_by') THEN
        ALTER TABLE `broadcast_campaigns`
            ADD COLUMN `created_by` INT NULL AFTER `unique_clickers`;
    END IF;

    -- Composite index for tenant-scoped queries (overview/list/stats)
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_campaigns'
                     AND INDEX_NAME = 'idx_account_status_created') THEN
        ALTER TABLE `broadcast_campaigns`
            ADD INDEX `idx_account_status_created` (`line_account_id`, `status`, `created_at`);
    END IF;
END//
DELIMITER ;
CALL p_phaseA_extend_campaigns();
DROP PROCEDURE p_phaseA_extend_campaigns;


-- ── 2. broadcast_clicks: tenant scoping helper column ────────
-- broadcast_id already FKs to broadcast_campaigns, but explicit
-- line_account_id avoids JOIN cost on hot analytics path.
DROP PROCEDURE IF EXISTS p_phaseA_clicks_scope;
DELIMITER //
CREATE PROCEDURE p_phaseA_clicks_scope()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'broadcast_clicks'
                     AND COLUMN_NAME = 'line_account_id') THEN
        ALTER TABLE `broadcast_clicks`
            ADD COLUMN `line_account_id` INT NULL AFTER `broadcast_id`,
            ADD INDEX `idx_account_clicked` (`line_account_id`, `clicked_at`);
    END IF;
END//
DELIMITER ;
CALL p_phaseA_clicks_scope();
DROP PROCEDURE p_phaseA_clicks_scope;

-- Backfill line_account_id on existing rows (safe to re-run)
UPDATE `broadcast_clicks` bc
JOIN `broadcast_campaigns` bcm ON bcm.id = bc.broadcast_id
SET bc.line_account_id = bcm.line_account_id
WHERE bc.line_account_id IS NULL;


-- ── 3. broadcast_links: token → URL mapping (created at send) ─
CREATE TABLE IF NOT EXISTS `broadcast_links` (
    `token`        VARCHAR(32)  NOT NULL,
    `campaign_id`  INT          NOT NULL,
    `original_url` VARCHAR(2048) NOT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `idx_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 4. broadcast_link_clicks: text/flex URL click events ─────
CREATE TABLE IF NOT EXISTS `broadcast_link_clicks` (
    `id`              BIGINT       NOT NULL AUTO_INCREMENT,
    `campaign_id`     INT          NOT NULL,
    `line_account_id` INT          NULL,
    `user_id`         INT          NULL,
    `line_user_id`    VARCHAR(50)  NULL,
    `link_token`      VARCHAR(32)  NOT NULL,
    `original_url`    VARCHAR(2048) NOT NULL,
    `clicked_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_agent`      VARCHAR(255) NULL,
    `ip`              VARCHAR(64)  NULL,
    PRIMARY KEY (`id`),
    KEY `idx_campaign_clicked` (`campaign_id`, `clicked_at`),
    KEY `idx_account_clicked` (`line_account_id`, `clicked_at`),
    KEY `idx_token` (`link_token`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 5. broadcast_drafts: Catalog Builder save/resume ─────────
CREATE TABLE IF NOT EXISTS `broadcast_drafts` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `line_account_id` INT          NULL,
    `created_by`      INT          NULL,
    `name`            VARCHAR(255) NOT NULL,
    `source`          VARCHAR(32)  NOT NULL DEFAULT 'catalog',
    `payload`         LONGTEXT     NOT NULL  COMMENT 'JSON: bubbles, layout, theme, settings',
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_account_updated` (`line_account_id`, `updated_at`),
    KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
