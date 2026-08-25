-- =============================================================================
-- Migration: Loyalty canonical ledger — idempotency, audit metadata, indexes
-- Date:      2026-08-26
-- Scope:     points_transactions (TENANT-SCOPED — apply to EACH reya_tenant_* DB)
-- Plan:      docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md — Phase 1 / Batch 1
-- Audit:     docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md
--
-- WHY (ทำไม)
--   `points_transactions` is being promoted to the canonical loyalty ledger: from
--   now on every point movement is exactly one immutable row here, and the
--   `users.total_points / available_points / used_points` columns are demoted to
--   derived caches recomputed from this table.
--
--   The Phase 0 audit found the table cannot carry that role yet:
--     1. NO UNIQUE constraint exists in ANY of its six on-disk definitions, so a
--        replayed LINE webhook, a re-delivered Odoo invoice or a double-clicked
--        "ให้แต้ม" button silently awards twice. There is no column to dedupe on.
--     2. There is nowhere to record WHY an award was the size it was, or WHO made
--        it — support cannot answer "ทำไมลูกค้าได้ 250 แต้ม?".
--     3. A freshly provisioned tenant gets PRIMARY KEY(id) and nothing else — the
--        template generator stripped every secondary index — so the balance query
--        `SUM(points) WHERE user_id = ?` full-scans on every award and every redeem.
--     4. The `type` ENUM differs per install; the tenant template lacks 'bonus',
--        and 'reverse'/'migration' exist nowhere, so the ledger cannot yet express
--        a reversal or a migrated opening balance.
--
-- WHAT
--   1. points_transactions.idempotency_key VARCHAR(191) NULL + UNIQUE
--   2. points_transactions.metadata        LONGTEXT NULL   (JSON rule breakdown)
--   3. points_transactions.created_by      VARCHAR(100) NULL
--   4. type ENUM widened to the union of every on-disk variant + reverse/migration
--   5. the secondary indexes a fresh tenant is missing
--
-- SAFETY
--   * Additive only. No column is dropped, renamed or retyped; no row is written.
--   * Re-run safe: every step is guarded against information_schema, so partial
--     deploys and repeat runs are no-ops.
--   * Portable: uses guarded procedures rather than MariaDB-only
--     `ADD COLUMN IF NOT EXISTS`, so it applies on MySQL 5.7+ and MariaDB alike.
--   * The UNIQUE on idempotency_key is safe to add to a populated table: the
--     column is brand new and therefore entirely NULL, and both MySQL and MariaDB
--     permit unlimited NULLs in a UNIQUE index.
--   * Callers that pass no idempotency key keep working exactly as before —
--     NULL never collides.
--
-- ROLLBACK
--   ALTER TABLE `points_transactions`
--     DROP INDEX  `uniq_points_tx_idempotency`,
--     DROP COLUMN `idempotency_key`,
--     DROP COLUMN `metadata`,
--     DROP COLUMN `created_by`;
--   (the widened ENUM and the added indexes are harmless and may be left in place)
--
-- Charset: utf8mb4_unicode_ci (Thai language). Timezone: Asia/Bangkok (+07:00).
-- =============================================================================

-- ---------------------------------------------------------------------
-- 1) points_transactions.idempotency_key
--    Dedupe key for every retryable award/debit. Format is caller-defined but
--    MUST embed the OA scope, e.g. 'la:3:order:1182:earn', 'la:3:redemption:9001:refund'.
--    191 chars keeps the index inside the 767-byte limit on utf8mb4 + older InnoDB.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_loyalty_add_idempotency_key;
DELIMITER //
CREATE PROCEDURE reya_loyalty_add_idempotency_key()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_transactions'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions'
          AND COLUMN_NAME = 'idempotency_key'
    ) THEN
        ALTER TABLE `points_transactions`
            ADD COLUMN `idempotency_key` VARCHAR(191) NULL DEFAULT NULL
            COMMENT 'กันการให้แต้มซ้ำ — dedupe key, e.g. la:3:order:1182:earn. NULL = not idempotent.';
    END IF;
END //
DELIMITER ;
CALL reya_loyalty_add_idempotency_key();
DROP PROCEDURE IF EXISTS reya_loyalty_add_idempotency_key;

-- ---------------------------------------------------------------------
-- 2) points_transactions.metadata
--    JSON blob holding the LoyaltyRuleEngine breakdown (base/campaign/category/
--    tier multipliers) so support can explain any award. LONGTEXT rather than
--    JSON for MySQL 5.6 tolerance; the column name matches the repo convention.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_loyalty_add_metadata;
DELIMITER //
CREATE PROCEDURE reya_loyalty_add_metadata()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_transactions'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions'
          AND COLUMN_NAME = 'metadata'
    ) THEN
        ALTER TABLE `points_transactions`
            ADD COLUMN `metadata` LONGTEXT NULL DEFAULT NULL
            COMMENT 'JSON — rule breakdown / audit context ที่มาของแต้ม';
    END IF;
END //
DELIMITER ;
CALL reya_loyalty_add_metadata();
DROP PROCEDURE IF EXISTS reya_loyalty_add_metadata;

-- ---------------------------------------------------------------------
-- 3) points_transactions.created_by
--    Who caused the movement: 'admin:12', 'system:webhook', 'cron:expire',
--    'pos:4'. Mirrors points_history.created_by varchar(100), which already exists.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_loyalty_add_created_by;
DELIMITER //
CREATE PROCEDURE reya_loyalty_add_created_by()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_transactions'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions'
          AND COLUMN_NAME = 'created_by'
    ) THEN
        ALTER TABLE `points_transactions`
            ADD COLUMN `created_by` VARCHAR(100) NULL DEFAULT NULL
            COMMENT 'ผู้ทำรายการ — admin:<id> | system:<source> | cron:<job> | pos:<id>';
    END IF;
END //
DELIMITER ;
CALL reya_loyalty_add_created_by();
DROP PROCEDURE IF EXISTS reya_loyalty_add_created_by;

-- ---------------------------------------------------------------------
-- 4) UNIQUE on idempotency_key
--    The DB-level backstop. LoyaltyLedgerService also checks in-transaction, but
--    the constraint is what makes a concurrent double-submit impossible rather
--    than merely unlikely.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_loyalty_add_idempotency_unique;
DELIMITER //
CREATE PROCEDURE reya_loyalty_add_idempotency_unique()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions'
          AND COLUMN_NAME = 'idempotency_key'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions'
          AND INDEX_NAME = 'uniq_points_tx_idempotency'
    ) THEN
        ALTER TABLE `points_transactions`
            ADD UNIQUE KEY `uniq_points_tx_idempotency` (`idempotency_key`);
    END IF;
END //
DELIMITER ;
CALL reya_loyalty_add_idempotency_unique();
DROP PROCEDURE IF EXISTS reya_loyalty_add_idempotency_unique;

-- ---------------------------------------------------------------------
-- 5) Widen the `type` ENUM to the union of every on-disk variant, plus the two
--    the canonical ledger needs.
--      earn      already everywhere
--      redeem    already everywhere
--      expire    already everywhere      — cron expiry writes an explicit row
--      adjust    already everywhere      — manual admin correction
--      refund    already everywhere      — reward cancellation credit
--      bonus     missing from the tenant template (api/member.php writes it to
--                points_history today; the ledger needs it for welcome bonuses)
--      reverse   NEW — undo of a specific prior transaction
--      migration NEW — opening balance carried in from a legacy store
--    Widening an ENUM is metadata-only in MariaDB/MySQL 8 when no value is
--    removed and the ordering of existing values is preserved, which it is here.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_loyalty_widen_type_enum;
DELIMITER //
CREATE PROCEDURE reya_loyalty_widen_type_enum()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions'
          AND COLUMN_NAME = 'type'
          AND COLUMN_TYPE NOT LIKE '%migration%'
    ) THEN
        ALTER TABLE `points_transactions`
            MODIFY COLUMN `type`
            ENUM('earn','redeem','expire','adjust','refund','bonus','reverse','migration')
            NOT NULL COMMENT 'ประเภทรายการแต้ม — ledger movement kind';
    END IF;
END //
DELIMITER ;
CALL reya_loyalty_widen_type_enum();
DROP PROCEDURE IF EXISTS reya_loyalty_widen_type_enum;

-- ---------------------------------------------------------------------
-- 6) The indexes a freshly provisioned tenant never received.
--    Every balance read is `WHERE user_id = ?`, so idx_user is the hot one;
--    without it each award and each redeem full-scans the ledger.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS reya_loyalty_add_indexes;
DELIMITER //
CREATE PROCEDURE reya_loyalty_add_indexes()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_transactions'
    ) THEN
        SELECT 'points_transactions absent — nothing to index' AS note;
    ELSE
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions' AND INDEX_NAME = 'idx_points_tx_user'
    ) THEN
        ALTER TABLE `points_transactions` ADD KEY `idx_points_tx_user` (`user_id`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions' AND INDEX_NAME = 'idx_points_tx_account'
    ) THEN
        ALTER TABLE `points_transactions` ADD KEY `idx_points_tx_account` (`line_account_id`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions' AND INDEX_NAME = 'idx_points_tx_expires'
    ) THEN
        ALTER TABLE `points_transactions` ADD KEY `idx_points_tx_expires` (`expires_at`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions' AND INDEX_NAME = 'idx_points_tx_reference'
    ) THEN
        ALTER TABLE `points_transactions`
            ADD KEY `idx_points_tx_reference` (`reference_type`, `reference_id`);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'points_transactions' AND INDEX_NAME = 'idx_points_tx_created'
    ) THEN
        ALTER TABLE `points_transactions` ADD KEY `idx_points_tx_created` (`created_at`);
    END IF;
    END IF;
END //
DELIMITER ;
CALL reya_loyalty_add_indexes();
DROP PROCEDURE IF EXISTS reya_loyalty_add_indexes;

-- =============================================================================
-- Verification (run manually after applying):
--
--   SHOW CREATE TABLE points_transactions\G
--   -- expect: idempotency_key, metadata, created_by columns;
--   --         UNIQUE KEY uniq_points_tx_idempotency;
--   --         type ENUM containing 'bonus','reverse','migration';
--   --         KEYs idx_points_tx_user / _account / _expires / _reference / _created
--
--   -- must return 0 rows — a populated idempotency_key with duplicates
--   SELECT idempotency_key, COUNT(*) c FROM points_transactions
--    WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING c > 1;
--
--   -- then run the read-only reconciliation report:
--   php scripts/loyalty-reconcile.php --all-tenants
-- =============================================================================
