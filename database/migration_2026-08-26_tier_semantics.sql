-- =============================================================================
-- Migration: Tier semantics — split the earn multiplier from the discount
-- Date:      2026-08-26
-- Scope:     tier_settings (TENANT-SCOPED — apply to EACH reya_tenant_* DB)
-- Plan:      docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §13 / Phase 3
-- Audit:     docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md §4.6
--
-- WHY (ทำไม)
--   `tier_settings.multiplier` carries the DB comment "Points earning multiplier
--   for this tier", and the admin UI labels it "ตัวคูณแต้ม" with the help text
--   "1.5x = ได้แต้มเพิ่ม 50%". But TierService reads it as:
--
--       SELECT ... multiplier AS discount_percent FROM tier_settings
--
--   so a Gold tier the pharmacy configured as "earn 1.5x points" was served to
--   the LINE mini app as "1.5% discount". One column, two incompatible business
--   meanings, and the wrong one reaching the customer.
--
-- WHAT
--   1. tier_settings.earn_multiplier  DECIMAL(5,2) NOT NULL DEFAULT 1.00
--   2. tier_settings.discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00
--   3. Backfill earn_multiplier from `multiplier` — the admin's intent, per the
--      column comment and the UI label. discount_percent starts at 0 because no
--      tenant has ever actually configured a discount; the number displayed as
--      one was always the multiplier wearing the wrong name.
--
-- SAFETY
--   * Additive. `multiplier` is KEPT and left populated so a rollback, or any
--     reader this migration has not reached, keeps working unchanged.
--   * Re-run safe: guarded against information_schema, so partial deploys and
--     repeat runs are no-ops. The backfill only touches rows where the new
--     column is still at its default, so it cannot overwrite a real edit.
--   * Portable: MySQL 5.7+ and MariaDB (no MariaDB-only IF NOT EXISTS).
--
-- ROLLBACK
--   ALTER TABLE `tier_settings`
--     DROP COLUMN `earn_multiplier`,
--     DROP COLUMN `discount_percent`;
--   (`multiplier` was never modified, so the old behaviour returns intact)
--
-- Charset: utf8mb4_unicode_ci (Thai language). Timezone: Asia/Bangkok (+07:00).
-- =============================================================================

-- ---------------------------------------------------------------------
-- 1) tier_settings.earn_multiplier — how fast this tier EARNS points
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_tier_add_earn_multiplier;
DELIMITER //
CREATE PROCEDURE reya_tier_add_earn_multiplier()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tier_settings'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tier_settings'
          AND COLUMN_NAME = 'earn_multiplier'
    ) THEN
        ALTER TABLE `tier_settings`
            ADD COLUMN `earn_multiplier` DECIMAL(5,2) NOT NULL DEFAULT 1.00
            COMMENT 'ตัวคูณแต้ม — points EARNING multiplier, e.g. 1.50 = ได้แต้มเพิ่ม 50%';

        -- Carry the admin's real intent across.
        UPDATE `tier_settings`
           SET `earn_multiplier` = COALESCE(`multiplier`, 1.00)
         WHERE `multiplier` IS NOT NULL;
    END IF;
END //
DELIMITER ;
CALL reya_tier_add_earn_multiplier();
DROP PROCEDURE IF EXISTS reya_tier_add_earn_multiplier;

-- ---------------------------------------------------------------------
-- 2) tier_settings.discount_percent — the price discount this tier grants
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_tier_add_discount_percent;
DELIMITER //
CREATE PROCEDURE reya_tier_add_discount_percent()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tier_settings'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tier_settings'
          AND COLUMN_NAME = 'discount_percent'
    ) THEN
        ALTER TABLE `tier_settings`
            ADD COLUMN `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00
            COMMENT 'ส่วนลด % — price discount granted by this tier. Separate from earn_multiplier.';
    END IF;
END //
DELIMITER ;
CALL reya_tier_add_discount_percent();
DROP PROCEDURE IF EXISTS reya_tier_add_discount_percent;

-- ---------------------------------------------------------------------
-- 3) Mark the old column deprecated in its own comment, so the next person
--    reading SHOW CREATE TABLE knows which one is load-bearing.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_tier_deprecate_multiplier;
DELIMITER //
CREATE PROCEDURE reya_tier_deprecate_multiplier()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tier_settings'
          AND COLUMN_NAME = 'multiplier'
          AND COLUMN_COMMENT NOT LIKE '%DEPRECATED%'
    ) THEN
        ALTER TABLE `tier_settings`
            MODIFY COLUMN `multiplier` DECIMAL(5,2) DEFAULT 1.00
            COMMENT 'DEPRECATED 2026-08-26 — superseded by earn_multiplier. Kept for rollback only.';
    END IF;
END //
DELIMITER ;
CALL reya_tier_deprecate_multiplier();
DROP PROCEDURE IF EXISTS reya_tier_deprecate_multiplier;

-- =============================================================================
-- Verification (run manually after applying):
--
--   SHOW CREATE TABLE tier_settings\G
--   SELECT name, min_points, multiplier, earn_multiplier, discount_percent
--     FROM tier_settings ORDER BY min_points;
--   -- expect earn_multiplier == the old multiplier, discount_percent == 0.00
-- =============================================================================
