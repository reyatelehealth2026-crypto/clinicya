-- =====================================================================
-- migration_2026-07-04_ai_usage_counters.sql
-- =====================================================================
-- Phase 3: per-tenant Gemini/AI usage metering (#19).
--
-- Purpose:
--   Now that each tenant resolves its own Gemini API key (ai_settings
--   scoped by line_account_id), we need a per-tenant, per-day counter so
--   usage/billing/quota can be attributed correctly instead of lumped
--   together across the whole platform.
--
-- Scope:
--   Per-tenant table — apply to every reya_tenant_* DB (and the legacy/main
--   DB). IDEMPOTENT (CREATE TABLE IF NOT EXISTS). AiUsageMeter also lazily
--   creates this table on first use as a resilience fallback, matching the
--   repo's existing auto-create pattern (dispensing_records, consultation_audit, ...).
--
-- Counting model:
--   One row per (line_account_id, day, provider, model); `calls` increments
--   on every request. INSERT ... ON DUPLICATE KEY UPDATE calls = calls + 1.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_usage_counters (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    line_account_id INT NULL COMMENT 'บัญชี LINE OA / tenant scope (NULL = ไม่ผูกกับ tenant ใดเป็นการเฉพาะ)',
    day             DATE NOT NULL COMMENT 'วันที่ตาม Asia/Bangkok',
    provider        VARCHAR(20) NOT NULL DEFAULT 'gemini' COMMENT 'gemini | openai | ...',
    model           VARCHAR(50) NOT NULL COMMENT 'เช่น gemini-flash-latest',
    calls           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนครั้งที่เรียก API สำเร็จ/พยายามเรียก',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_account_day_provider_model (line_account_id, day, provider, model),
    KEY idx_account_day (line_account_id, day),
    KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-tenant, per-day AI API usage counters (Phase 3 metering, #19)';
