-- Migration: ai_pharmacy_settings phantom columns (issue #31)
--
-- TriageRouter::getMaxQuestionsPerSession() SELECTs `max_questions_per_session`
-- from `ai_pharmacy_settings`, but the column has never existed in the schema
-- (neither the tenant template nor ai-pharmacy-settings.php's CREATE TABLE) —
-- the query throws, is caught, and silently falls back to 7. The tenant-facing
-- setting has never had any effect.
--
-- `require_pharmacist_approval` already exists (tenant template +
-- ai-pharmacy-settings.php's CREATE TABLE IF NOT EXISTS both define it), so
-- this migration only guards it defensively for any DB that predates that
-- column (idempotent no-op everywhere else).
--
-- Apply to a single DB with:
--   mysql -u USER -p DBNAME < database/migration_2026-07-04_ai_pharmacy_settings_columns.sql
-- Apply to every tenant DB with:
--   php install/migrate_all_tenants_ai_pharmacy_settings.php

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ai_pharmacy_settings'
      AND COLUMN_NAME = 'max_questions_per_session'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE ai_pharmacy_settings ADD COLUMN max_questions_per_session INT DEFAULT 7 AFTER auto_recommend',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ai_pharmacy_settings'
      AND COLUMN_NAME = 'require_pharmacist_approval'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE ai_pharmacy_settings ADD COLUMN require_pharmacist_approval TINYINT(1) DEFAULT 0 AFTER auto_recommend',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
