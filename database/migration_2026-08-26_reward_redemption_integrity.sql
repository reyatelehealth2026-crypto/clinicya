-- =============================================================================
-- Migration: Reward redemption integrity — idempotency + the missing indexes
-- Date:      2026-08-26
-- Scope:     reward_redemptions (TENANT-SCOPED — apply to EACH reya_tenant_* DB)
-- Plan:      docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §15 / Phase 5
-- Audit:     docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md §4.4
--
-- WHY (ทำไม)
--   RewardRedemptionService is now the single redemption path, and it can make a
--   double-submitted redeem return the ORIGINAL redemption instead of charging
--   twice — but only if there is a column to dedupe on and a UNIQUE index to
--   enforce it under concurrency.
--
--   The table is also missing everything a redemption screen queries by. The
--   prod dump and the tenant template both declare PRIMARY KEY(id) as the ONLY
--   key: `redemption_code` is not unique (uniqueness was enforced in PHP alone,
--   by retrying a random code), and there is no index on user_id, reward_id or
--   status, so "this member's redemptions" and "pending redemptions" full-scan.
--
-- WHAT
--   1. reward_redemptions.idempotency_key VARCHAR(191) NULL + UNIQUE
--   2. UNIQUE on redemption_code — the code is handed to a customer as proof;
--      PHP-only uniqueness is not a guarantee
--   3. The missing secondary indexes (user, reward, status, created_at)
--
-- SAFETY
--   * Additive. No column is dropped, renamed or retyped; no row is written.
--   * Re-run safe: guarded against information_schema.
--   * The UNIQUE on idempotency_key is safe on a populated table: the column is
--     brand new and therefore entirely NULL, and both MySQL and MariaDB permit
--     unlimited NULLs in a UNIQUE index.
--   * The UNIQUE on redemption_code is added ONLY IF the existing data has no
--     duplicates — see the guard. On a table that does have duplicates the step
--     is skipped with a notice rather than failing the migration; resolve those
--     rows by hand and re-run.
--
-- ROLLBACK
--   ALTER TABLE `reward_redemptions`
--     DROP INDEX  `uniq_redemption_idempotency`,
--     DROP COLUMN `idempotency_key`;
--   (the added indexes are harmless and may be left in place)
--
-- Charset: utf8mb4_unicode_ci (Thai language). Timezone: Asia/Bangkok (+07:00).
-- =============================================================================

-- ---------------------------------------------------------------------
-- 1) reward_redemptions.idempotency_key
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_redemption_add_idempotency;
DELIMITER //
CREATE PROCEDURE reya_redemption_add_idempotency()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reward_redemptions'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions'
          AND COLUMN_NAME = 'idempotency_key'
    ) THEN
        ALTER TABLE `reward_redemptions`
            ADD COLUMN `idempotency_key` VARCHAR(191) NULL DEFAULT NULL
            COMMENT 'กันการแลกซ้ำ — dedupe key, e.g. redeem:la3:u881:r12:1756200000';

        ALTER TABLE `reward_redemptions`
            ADD UNIQUE KEY `uniq_redemption_idempotency` (`idempotency_key`);
    END IF;
END //
DELIMITER ;
CALL reya_redemption_add_idempotency();
DROP PROCEDURE IF EXISTS reya_redemption_add_idempotency;

-- ---------------------------------------------------------------------
-- 2) UNIQUE on redemption_code — only when the data already allows it
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_redemption_unique_code;
DELIMITER //
CREATE PROCEDURE reya_redemption_unique_code()
BEGIN
    DECLARE dup_count INT DEFAULT 0;

    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions'
          AND COLUMN_NAME = 'redemption_code'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions'
          AND INDEX_NAME = 'uniq_redemption_code'
    ) THEN
        SELECT COUNT(*) INTO dup_count FROM (
            SELECT `redemption_code`
              FROM `reward_redemptions`
             WHERE `redemption_code` IS NOT NULL
             GROUP BY `redemption_code`
            HAVING COUNT(*) > 1
        ) AS duplicates;

        IF dup_count = 0 THEN
            ALTER TABLE `reward_redemptions`
                ADD UNIQUE KEY `uniq_redemption_code` (`redemption_code`);
        ELSE
            SELECT CONCAT(
                'SKIPPED uniq_redemption_code: ', dup_count,
                ' duplicate redemption_code value(s) exist. Resolve them, then re-run.'
            ) AS warning;
        END IF;
    END IF;
END //
DELIMITER ;
CALL reya_redemption_unique_code();
DROP PROCEDURE IF EXISTS reya_redemption_unique_code;

-- ---------------------------------------------------------------------
-- 3) The secondary indexes every redemption screen needs
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_redemption_add_indexes;
DELIMITER //
CREATE PROCEDURE reya_redemption_add_indexes()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reward_redemptions'
    ) THEN
        SELECT 'reward_redemptions absent — nothing to index' AS note;
    ELSE
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions' AND INDEX_NAME = 'idx_redemption_user'
    ) THEN
        -- max_per_user counts by (user_id, reward_id); this is the hot one.
        ALTER TABLE `reward_redemptions` ADD KEY `idx_redemption_user` (`user_id`, `reward_id`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions' AND INDEX_NAME = 'idx_redemption_reward'
    ) THEN
        ALTER TABLE `reward_redemptions` ADD KEY `idx_redemption_reward` (`reward_id`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions' AND INDEX_NAME = 'idx_redemption_status'
    ) THEN
        ALTER TABLE `reward_redemptions` ADD KEY `idx_redemption_status` (`status`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reward_redemptions' AND INDEX_NAME = 'idx_redemption_created'
    ) THEN
        ALTER TABLE `reward_redemptions` ADD KEY `idx_redemption_created` (`created_at`);
    END IF;
    END IF;
END //
DELIMITER ;
CALL reya_redemption_add_indexes();
DROP PROCEDURE IF EXISTS reya_redemption_add_indexes;

-- =============================================================================
-- Verification (run manually after applying):
--
--   SHOW CREATE TABLE reward_redemptions\G
--   -- expect: idempotency_key column; UNIQUE uniq_redemption_idempotency;
--   --         UNIQUE uniq_redemption_code (unless skipped with a warning);
--   --         KEYs idx_redemption_user / _reward / _status / _created
--
--   -- must return 0 rows
--   SELECT redemption_code, COUNT(*) c FROM reward_redemptions
--    WHERE redemption_code IS NOT NULL GROUP BY redemption_code HAVING c > 1;
-- =============================================================================
