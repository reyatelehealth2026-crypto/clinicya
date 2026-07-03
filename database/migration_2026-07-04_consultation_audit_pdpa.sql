-- =====================================================================
-- migration_2026-07-04_consultation_audit_pdpa.sql
-- =====================================================================
-- Telepharmacy compliance: immutable consultation audit trail (issue #15).
--
-- Purpose:
--   อย. requires the tele-consultation process to be traceable end-to-end
--   ("ตรวจสอบได้ตลอดกระบวนการ"): every AI turn, safety trigger, escalation,
--   consent decision, and pharmacist review must be recorded and provable.
--   This table is APPEND-ONLY and tamper-evident via a per-session SHA-256
--   hash chain (each row hashes the previous row's content_hash), so any
--   later edit/deletion of a row breaks the chain and is detectable.
--
-- Scope:
--   Per-tenant table — apply to every reya_tenant_* DB (and re-run across
--   all tenants for existing installs). IDEMPOTENT (CREATE TABLE IF NOT
--   EXISTS). The ConsultationAudit service also lazily creates this table
--   on first use as a resilience fallback, matching the repo's existing
--   auto-create pattern (dispensing_records, user_notes, ...).
--
-- Immutability note:
--   Enforced by convention at the application layer: the app only ever
--   INSERTs. Do NOT add UPDATE/DELETE code paths against this table. For
--   hard DB-level immutability, revoke UPDATE/DELETE from the app DB user
--   on this table in production (out of scope for this migration).
-- =====================================================================

CREATE TABLE IF NOT EXISTS consultation_audit (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    line_account_id INT NULL COMMENT 'บัญชี LINE OA ที่ให้บริการ (account/tenant scope)',
    session_id      BIGINT UNSIGNED NULL COMMENT 'triage_sessions.id (NULL = ก่อนเริ่ม session เช่น red-flag แรก)',
    user_id         BIGINT NULL COMMENT 'users.id หรือ synthetic id ของผู้ใช้ LINE',
    actor_type      ENUM('customer','ai','pharmacist','system') NOT NULL COMMENT 'ผู้ก่อเหตุการณ์',
    actor_id        INT NULL COMMENT 'users.id ของเภสัชกร เมื่อ actor_type=pharmacist',
    event_type      VARCHAR(40) NOT NULL COMMENT 'triage_question | ai_recommendation | red_flag | escalation | pharmacist_approve | pharmacist_edit | consent_granted | consent_missing | consent_withdrawn',
    payload         JSON NULL COMMENT 'รายละเอียดเชิงโครงสร้าง (AI แนะอะไร / เภสัชกรแก้อะไร)',
    content_hash    CHAR(64) NOT NULL COMMENT 'sha256(prev_hash | event_type | actor | canonical(payload) | ts)',
    prev_hash       CHAR(64) NULL COMMENT 'content_hash ของแถวก่อนหน้าใน session เดียวกัน (hash chain)',
    created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_session (session_id, id),
    KEY idx_account_created (line_account_id, created_at),
    KEY idx_user (user_id),
    KEY idx_event (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only, hash-chained audit trail for AI tele-pharmacy consultations (PDPA / อย. compliance)';
