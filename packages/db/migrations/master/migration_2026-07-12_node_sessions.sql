-- =============================================================================
-- Migration: node_sessions (Next.js kernel stateful sessions — @reya/auth)
-- Date:      2026-07-12
-- Plan:      docs/plans/2026-07-12-nextjs-full-migration-plan.md §1.4, §4.1
-- Scope:     Creates `node_sessions` in the master DB (zrismpsz_reya_platform).
--            Backs @reya/auth's two-realm session kernel (tenant `reya_sid` /
--            platform `reya_platform_sid`) — the Node-side counterpart of PHP
--            $_SESSION, chosen over JWT specifically because impersonation
--            and admin_bot_access ACL changes must revoke access immediately
--            (§1.4: "impersonation/ACL ต้อง revoke ทันที").
--
-- Purpose:   ตารางเซสชันฝั่ง Node (master DB) สำหรับระบบ Auth สอง realm —
--            tenant admin (`reya_sid`) และ platform owner (`reya_platform_sid`)
--            แทน PHP $_SESSION แบบ per-process — เก็บใน DB กลางเพื่อให้
--            revoke สิทธิ์ / ยกเลิก impersonation ได้ทันทีทุก session ของ
--            user คนเดียวกัน (ไม่ต้องรอ JWT หมดอายุ)
--
-- IMPORTANT — NOT auto-applied by packages/db's migrate-all runner in this
-- batch: `packages/db/src/migrateAll.ts` / `src/bin/migrate-all.ts` only walk
-- `migrations/tenant/` against each tenant DB. Wiring an equivalent runner
-- for `migrations/master/` is a separate, not-yet-built piece of the
-- schema-governance workstream (plan §4.1) — flagged as a follow-up, not
-- built here. This file is a committed, checksum-able artifact only; apply
-- it manually (or via the future master-runner) against
-- `zrismpsz_reya_platform` once that tooling exists.
--
-- Re-run safe: CREATE TABLE IF NOT EXISTS.
-- Charset:   utf8mb4_unicode_ci (Thai language)
-- Engine:    InnoDB
-- Timezone:  Asia/Bangkok (+07:00) — enforced by app connection layer
-- Target:    MariaDB 10.3+ / Node 20+ (packages/auth)
--
-- Notes:
--   * No cross-database FK to tenant DBs (impossible across DB boundaries in
--     MariaDB — same rule already followed by super_admin_audit.tenant_id in
--     migration_2026-05-25_platform_master.sql). `tenant_id` here is a plain
--     INT soft reference to master.tenants.id.
--   * `payload` is a native JSON column (not longtext + json_valid()). This
--     is a brand-new Wave-3/Node-kernel table, not a port of an existing PHP
--     longtext-JSON column, so the "never convert an EXISTING longtext-JSON
--     column's type during migration" rule (plan §4.1 / repo CLAUDE.md) does
--     not apply here — this follows the JSON-column precedent already set by
--     `super_admin_audit.metadata` in migration_2026-05-25_platform_master.sql,
--     the most recent prior addition to this same master DB.
--   * No single generic `user_id` column: identity is realm-dependent
--     (`admin_user_id` for realm='tenant', `platform_user_id` for
--     realm='platform') — see idx_node_sessions_realm_admin_user /
--     idx_node_sessions_realm_platform_user below, which together deliver
--     the "invalidate ALL of one user's sessions in one query" property the
--     plan calls for (used by login()'s single-active-session enforcement
--     and available for future ACL-revocation call sites), without a
--     denormalised generic identity column that would sit NULL half the time.
--   * No triggers/stored procedures — repo-wide rule ("ไม่มี trigger/stored
--     procedure ใน DB เลย").
--   * No DELIMITER changes — every statement is a single plain `;`-terminated
--     CREATE TABLE, safe for packages/db's splitSqlStatements() (which does
--     a naive `;`-split because this repo never needs DELIMITER).
-- =============================================================================

USE `zrismpsz_reya_platform`;

CREATE TABLE IF NOT EXISTS `node_sessions` (
  `sid` VARCHAR(128) NOT NULL COMMENT 'opaque session id (crypto-random hex) — the reya_sid / reya_platform_sid cookie value',
  `realm` ENUM('tenant','platform') NOT NULL COMMENT 'tenant=admin_users session (reya_sid), platform=platform_users session (reya_platform_sid)',

  -- Identity — exactly one of these two is populated, per `realm`.
  `admin_user_id` INT NULL COMMENT 'soft ref -> tenant_db.admin_users.id (per-tenant DB, no cross-DB FK possible) — set when realm=tenant',
  `platform_user_id` INT NULL COMMENT 'FK-by-convention master.platform_users.id — set when realm=platform',

  -- Mirrors of the exact $_SESSION keys read by includes/auth_check.php,
  -- classes/AdminAuth.php, admin/platform-login.php, admin/switch-tenant.php.
  `tenant_id` INT NULL COMMENT 'mirrors $_SESSION[active_tenant_id] — realm=tenant only, soft ref -> master.tenants.id',
  `current_bot_id` INT NULL COMMENT 'mirrors $_SESSION[current_bot_id] — realm=tenant only, soft ref -> tenant_db.line_accounts.id',
  `platform_role` VARCHAR(20) NULL COMMENT 'mirrors $_SESSION[platform_user_role] (super_admin|support|readonly) — realm=platform only',
  `impersonated_tenant_id` INT NULL COMMENT 'mirrors $_SESSION[admin_switched_to_tenant_id] — realm=platform only, soft ref -> master.tenants.id',

  `payload` JSON NOT NULL COMMENT 'full serialized Session object (TenantSession | PlatformSession, @reya/auth) — source of truth for getSession(), the columns above are query-optimised mirrors for WHERE/index use, not a second source of truth',

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'bumped by getSession() on every successful read (mirrors a PHP session file mtime touch)',
  `expires_at` TIMESTAMP NOT NULL COMMENT 'absolute expiry — no DEFAULT, always set explicitly at creation/rotation from NODE_SESSION_TTL_SECONDS',

  PRIMARY KEY (`sid`),
  KEY `idx_node_sessions_expires_at` (`expires_at`) COMMENT 'expiry-sweep GC scan',
  KEY `idx_node_sessions_realm_admin_user` (`realm`, `admin_user_id`) COMMENT 'revoke every session of one tenant admin in a single query (ACL/role change, single-active-session-on-login enforcement)',
  KEY `idx_node_sessions_realm_platform_user` (`realm`, `platform_user_id`) COMMENT 'revoke every session of one platform user in a single query (impersonation/ACL revoke, single-active-session-on-login enforcement)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Node-side stateful sessions (@reya/auth), two realms, backs the reya_sid / reya_platform_sid cookies';
