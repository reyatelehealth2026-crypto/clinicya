-- =============================================================================
-- Migration: REYA Platform Master Database
-- Date:      2026-05-25
-- ADR:       docs/adr/0001-database-per-tenant-isolation.md
-- Scope:     Creates `zrismpsz_reya_platform` master DB and 7 core tables:
--              tenants, platform_users, plans, entitlements,
--              super_admin_audit, tenant_provisioning_log, tenant_migrations
--
-- Purpose:   ฐานข้อมูลกลาง (master) สำหรับ REYA — เก็บทะเบียน Tenant,
--            บัญชี Platform Owner, แพ็กเกจ, สิทธิ์การใช้งาน (entitlement),
--            และ audit trail สำหรับการเข้าถึงข้ามร้านโดย super_admin.
--
-- Re-run safe: every CREATE uses IF NOT EXISTS; INSERTs are IGNORE-keyed.
-- Charset:   utf8mb4_unicode_ci (Thai language)
-- Engine:    InnoDB (required for FK + transactional DDL)
-- Timezone:  Asia/Bangkok (+07:00) — enforced by app connection layer
-- Target:    MariaDB 10.3+ / PHP 8.0+ (clinicya.re-ya.com)
--
-- Notes:
--   * Cross-database FKs are NOT used (impossible across DB boundaries
--     in MariaDB). All FKs in this file reference tables in this same
--     `zrismpsz_reya_platform` DB. Soft references to per-tenant tables (e.g.
--     `default_branch_id`, `default_channel_id`) are plain INTs.
--   * `key` is a reserved word — entitlement column is `entitlement_key`.
--   * `tax_id` UNIQUE: MariaDB/MySQL treats NULLs as distinct, so the
--     index permits many tenants without a tax_id but enforces uniqueness
--     when one is supplied.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0) Database — created BEFORE this script via:
--      uapi Mysql create_database name='zrismpsz_reya_platform'
--      uapi Mysql set_privileges_on_database user='zrismpsz_demo' \
--           database='zrismpsz_reya_platform' privileges='ALL PRIVILEGES'
--    (CREATE DATABASE blocked by cPanel shared-hosting. See ADR-001.)
-- -----------------------------------------------------------------------------
USE `zrismpsz_reya_platform`;

-- -----------------------------------------------------------------------------
-- 1) plans — Subscription tiers (Starter / Pro / Enterprise / Custom)
--    Created BEFORE tenants because tenants.plan_id FKs this table.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(40) NOT NULL COMMENT 'machine name — starter / pro / enterprise / custom',
  `display_name` VARCHAR(100) NOT NULL COMMENT 'ชื่อแสดงผล เช่น "เริ่มต้น", "มืออาชีพ"',
  `description` TEXT DEFAULT NULL COMMENT 'รายละเอียดแพ็กเกจ',
  `price_monthly_thb` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ราคาต่อเดือน (บาท)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = retired plan (ห้ามรับสมัครใหม่)',
  `is_visible_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = ซ่อนจากหน้า public pricing',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_slug` (`slug`),
  KEY `idx_plan_active_visible` (`is_active`, `is_visible_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='แพ็กเกจสมาชิก (Subscription tiers)';

-- -----------------------------------------------------------------------------
-- 2) platform_users — Super admin / dev / support accounts
--    Tiny team (~5 people). Has 2FA support via TOTP secret.
--    Created BEFORE tenants because tenants.created_by FKs this table.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `platform_users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) NOT NULL COMMENT 'login + contact email',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'bcrypt — never plaintext',
  `name` VARCHAR(100) NOT NULL COMMENT 'ชื่อแสดงผลในระบบ',
  `role` ENUM('super_admin','support','readonly') NOT NULL DEFAULT 'support'
    COMMENT 'super_admin=full cross-tenant access; support=ticket handling; readonly=audit/reporting only',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = disabled (cannot login)',
  `two_factor_secret` VARCHAR(64) DEFAULT NULL COMMENT 'TOTP shared secret (NULL = 2FA off)',
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_platform_user_email` (`email`),
  KEY `idx_platform_user_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='บัญชีทีม dev/support (Platform Owner) — ~5 คน';

-- -----------------------------------------------------------------------------
-- 3) tenants — Tenant registry (THE central routing table)
--    Every web request resolves session → platform_users → tenant_id →
--    db_name, then PDO connects to that DB. See ADR-001 §"Connection routing".
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT NOT NULL AUTO_INCREMENT COMMENT 'used as tenant_id everywhere in code',
  `slug` VARCHAR(60) NOT NULL COMMENT 'URL-safe identifier (lowercase, hyphens) e.g. "reya-demo"',
  `display_name` VARCHAR(150) NOT NULL COMMENT 'ชื่อร้านที่แสดงในระบบ (Thai OK)',
  `legal_name` VARCHAR(255) DEFAULT NULL COMMENT 'ชื่อนิติบุคคล (สำหรับเอกสารภาษี)',
  `tax_id` VARCHAR(20) DEFAULT NULL COMMENT 'เลขประจำตัวผู้เสียภาษี 13 หลัก (optional)',

  -- DB routing (the heart of database-per-tenant)
  `db_name` VARCHAR(64) NOT NULL COMMENT 'actual MariaDB DB e.g. reya_tenant_0001',
  `db_host` VARCHAR(120) NOT NULL DEFAULT 'localhost'
    COMMENT 'for future multi-host scaling; default = same instance as platform',

  -- Plan + status
  `plan_id` INT NOT NULL COMMENT 'FK plans.id — current subscription tier',
  `status` ENUM('active','suspended','pending_setup','terminated') NOT NULL DEFAULT 'pending_setup'
    COMMENT 'pending_setup=DB being provisioned; suspended=billing/policy hold; terminated=archived',

  -- Default routing within tenant DB (soft refs — cannot FK across DBs)
  `default_branch_id` INT NULL COMMENT 'soft ref → tenant_db.branches.id (NULL until branch seeded)',
  `default_channel_id` INT NULL COMMENT 'soft ref → tenant_db.line_accounts.id',

  -- Owner contact (frozen at signup — separate from Tenant Owner admin_users record)
  `owner_name` VARCHAR(150) DEFAULT NULL COMMENT 'ชื่อเจ้าของร้าน (contact person)',
  `owner_email` VARCHAR(190) DEFAULT NULL COMMENT 'อีเมลติดต่อหลัก',
  `owner_phone` VARCHAR(40) DEFAULT NULL COMMENT 'เบอร์ติดต่อ',

  -- Audit / lifecycle
  `created_by` INT NULL COMMENT 'FK platform_users.id (NULL = bootstrap/seed)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `suspended_at` TIMESTAMP NULL DEFAULT NULL,
  `terminated_at` TIMESTAMP NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_slug` (`slug`),
  UNIQUE KEY `uq_tenant_db_name` (`db_name`),
  UNIQUE KEY `uq_tenant_tax_id` (`tax_id`) COMMENT 'NULLs are distinct in MariaDB; uniqueness only enforced when supplied',
  KEY `idx_tenant_status` (`status`),
  KEY `idx_tenant_plan` (`plan_id`),
  CONSTRAINT `fk_tenants_plan` FOREIGN KEY (`plan_id`)
    REFERENCES `plans` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_tenants_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `platform_users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ทะเบียน Tenant (ร้าน) + การ routing ไปยัง DB ของร้าน';

-- -----------------------------------------------------------------------------
-- 4) entitlements — Per-tenant feature flags (Platform Owner-controlled)
--    Overrides plan defaults. Examples:
--      max_branches, max_channels, max_admin_users,
--      allow_documents, allow_ai_chat, allow_telepharmacy,
--      storage_quota_mb
--    Only ONE value-column should be populated per row; the rest stay NULL.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `entitlements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id',
  `entitlement_key` VARCHAR(80) NOT NULL
    COMMENT 'feature flag name (avoid SQL reserved word `key`)',
  `value_int` INT NULL COMMENT 'numeric quota e.g. max_branches=3',
  `value_text` VARCHAR(255) NULL COMMENT 'string value e.g. allowed region code',
  `value_bool` TINYINT(1) NULL COMMENT '0/1 toggle e.g. allow_telepharmacy=1',
  `note` VARCHAR(255) DEFAULT NULL COMMENT 'หมายเหตุของ Platform Owner — ทำไมถึงเปิด/ปิด',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entitlement_tenant_key` (`tenant_id`, `entitlement_key`),
  KEY `idx_entitlement_key` (`entitlement_key`),
  CONSTRAINT `fk_entitlements_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='สิทธิ์การใช้งานต่อ Tenant (Platform Owner เป็นผู้กำหนด)';

-- -----------------------------------------------------------------------------
-- 5) super_admin_audit — Audit trail for cross-tenant access
--    EVERY super_admin action that touches or crosses a tenant boundary
--    MUST insert a row here. Append-only by convention.
--    Examples of `action`: switch_tenant, view_tenant_data, modify_entitlement,
--    suspend_tenant, run_query, export_data, login, impersonate_user.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admin_audit` (
  `id` BIGINT NOT NULL AUTO_INCREMENT COMMENT 'BIGINT — audit volume grows quickly',
  `platform_user_id` INT NOT NULL COMMENT 'FK platform_users.id — who did it',
  `tenant_id` INT NULL COMMENT 'FK tenants.id (NULL = platform-wide action)',
  `action` VARCHAR(100) NOT NULL COMMENT 'verb-noun e.g. switch_tenant, modify_entitlement',
  `target_type` VARCHAR(80) DEFAULT NULL COMMENT 'entity class e.g. user, order, dispense',
  `target_id` VARCHAR(80) DEFAULT NULL COMMENT 'entity PK as string (varies by table)',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IPv4 or IPv6 of admin',
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `request_method` VARCHAR(10) DEFAULT NULL COMMENT 'GET/POST/PUT/DELETE',
  `request_uri` VARCHAR(500) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL COMMENT 'free-form extra context (request body excerpt, before/after diff, etc.)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_saa_created_at` (`created_at`),
  KEY `idx_saa_user_time` (`platform_user_id`, `created_at`),
  KEY `idx_saa_tenant_time` (`tenant_id`, `created_at`),
  KEY `idx_saa_action` (`action`),
  CONSTRAINT `fk_saa_platform_user` FOREIGN KEY (`platform_user_id`)
    REFERENCES `platform_users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_saa_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log สำหรับการเข้าถึงข้าม Tenant โดย super_admin (append-only)';

-- -----------------------------------------------------------------------------
-- 6) tenant_provisioning_log — Lifecycle event log for tenant DB operations
--    Captures the timeline of creating / suspending / migrating each tenant DB.
--    Distinct from super_admin_audit: this is about the BACKEND operation
--    (CREATE DATABASE, apply schema, mysqldump), not user-facing UI actions.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_provisioning_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id',
  `event` ENUM(
    'create',
    'schema_apply',
    'seed',
    'suspend',
    'resume',
    'terminate',
    'db_backup',
    'db_restore',
    'migrate_apply'
  ) NOT NULL COMMENT 'lifecycle event being recorded',
  `migration_file` VARCHAR(255) DEFAULT NULL COMMENT 'filename when event=schema_apply or migrate_apply',
  `status` ENUM('started','succeeded','failed','rolled_back') NOT NULL DEFAULT 'started',
  `error_message` TEXT DEFAULT NULL COMMENT 'populated when status=failed/rolled_back',
  `triggered_by` INT NULL COMMENT 'FK platform_users.id (NULL = cron/system)',
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tpl_tenant_time` (`tenant_id`, `started_at`),
  KEY `idx_tpl_event_status` (`event`, `status`),
  KEY `idx_tpl_triggered_by` (`triggered_by`),
  CONSTRAINT `fk_tpl_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_tpl_triggered_by` FOREIGN KEY (`triggered_by`)
    REFERENCES `platform_users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='timeline ของการ provision / migrate / backup tenant DB';

-- -----------------------------------------------------------------------------
-- 7) tenant_migrations — Per-tenant applied migrations registry
--    The migration orchestrator (Phase 2 tool) reads/writes this table to
--    know which `database/migration_*.sql` files have been applied to which
--    tenant DB. checksum detects drift (file edited after deploy).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_migrations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id',
  `migration_file` VARCHAR(255) NOT NULL COMMENT 'e.g. migration_2026-05-24_documents.sql',
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applied_by` INT NULL COMMENT 'FK platform_users.id (NULL = cron/system)',
  `checksum` CHAR(64) DEFAULT NULL COMMENT 'sha256 hex of file content — detect drift after deploy',
  `execution_ms` INT DEFAULT NULL COMMENT 'wall-clock duration of applying the file (ms)',
  `status` ENUM('applied','failed','skipped') NOT NULL DEFAULT 'applied',
  `error_message` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tm_tenant_file` (`tenant_id`, `migration_file`),
  KEY `idx_tm_file` (`migration_file`),
  KEY `idx_tm_applied_at` (`applied_at`),
  KEY `idx_tm_applied_by` (`applied_by`),
  CONSTRAINT `fk_tm_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_tm_applied_by` FOREIGN KEY (`applied_by`)
    REFERENCES `platform_users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ทะเบียน migration ที่ apply แล้วต่อ tenant DB';

-- =============================================================================
-- Seed data — default plans (idempotent via INSERT IGNORE on unique slug)
-- ราคาเป็นค่าเริ่มต้น — Platform Owner ปรับได้ภายหลังผ่าน UI/UPDATE.
-- =============================================================================
INSERT IGNORE INTO `plans` (`slug`, `display_name`, `description`, `price_monthly_thb`, `is_active`, `is_visible_public`)
VALUES
  ('starter',
   'เริ่มต้น (Starter)',
   'แพ็กเกจเริ่มต้นสำหรับร้านขนาดเล็ก: 1 สาขา, 1 ช่องทาง LINE OA, ฟังก์ชันพื้นฐาน',
   990.00, 1, 1),
  ('pro',
   'มืออาชีพ (Pro)',
   'สำหรับร้านที่ต้องการระบบเภสัชกรรมเต็มรูปแบบ: หลายช่องทาง, AI Chat, จ่ายยา, เอกสารภาษี',
   2990.00, 1, 1),
  ('enterprise',
   'องค์กร (Enterprise)',
   'สำหรับเครือร้านขนาดใหญ่: หลายสาขา, telepharmacy, integration Odoo, SLA',
   9990.00, 1, 1);

-- =============================================================================
-- End of migration_2026-05-25_platform_master.sql
-- Next steps (NOT in this file):
--   * Create reya_tenant_0001 + reya_tenant_0002 DBs from existing DDL
--   * Backfill `tenants` rows for line_accounts.id IN (1, 4)
--   * Seed `entitlements` per tenant
--   * Wire Database::forTenant($id) factory in app/
-- =============================================================================
