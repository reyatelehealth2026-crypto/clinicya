-- =============================================================================
-- Migration: ai_rate_limits (Phase 4 security fix)
-- Date: 2026-05-24
-- Purpose: per-user / per-IP hourly rate limiting for AI endpoints
--          (Gemini Vision + summary) to bound token spend on abuse.
--
-- Idempotent: uses CREATE TABLE IF NOT EXISTS + safe indexes.
-- Rollback: DROP TABLE IF EXISTS ai_rate_limits;
-- =============================================================================

CREATE TABLE IF NOT EXISTS `ai_rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `endpoint` VARCHAR(64) NOT NULL COMMENT 'logical endpoint name e.g. vision, summary',
  `identifier` VARCHAR(128) NOT NULL COMMENT 'line_user_id or ip',
  `identifier_type` ENUM('user','ip') NOT NULL,
  `request_count` INT NOT NULL DEFAULT 0,
  `window_start` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_endpoint_id` (`endpoint`, `identifier_type`, `identifier`),
  INDEX `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Per-user / per-IP rate limit counters for AI endpoints (Phase 4 fix)';
