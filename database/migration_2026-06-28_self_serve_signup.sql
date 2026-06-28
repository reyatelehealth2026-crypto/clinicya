-- ============================================================================
-- migration_2026-06-28_self_serve_signup.sql
-- Self-serve Google signup for shop owners (Phase 1 — platform schema).
--
-- Runs on the MASTER/platform DB (zrismpsz_reya_platform) only.
--
-- Adds platform-level identity columns so a Google-authenticated shop owner is
-- anchored in platform_users (one identity → one owned tenant), and lets that
-- table hold the new 'owner' role. Tenant-id allocation reuses
-- tenants.AUTO_INCREMENT (no extra counter table needed).
--
-- Idempotent-ish: re-running the ADD COLUMNs will error if already applied;
-- guard with the information_schema checks below or apply once.
-- ============================================================================

-- 1) platform_users: allow password-less (Google) accounts + identity columns
ALTER TABLE `platform_users`
  MODIFY `password_hash` VARCHAR(255) NULL COMMENT 'bcrypt — NULL when auth_provider = google';

ALTER TABLE `platform_users`
  ADD COLUMN `google_id` VARCHAR(64) NULL COMMENT 'Google sub (stable account id)' AFTER `email`,
  ADD COLUMN `auth_provider` ENUM('password','google') NOT NULL DEFAULT 'password' AFTER `google_id`,
  ADD COLUMN `tenant_id` INT NULL COMMENT 'shop this owner owns (NULL for platform staff)' AFTER `role`,
  ADD UNIQUE KEY `uq_platform_user_google` (`google_id`),
  ADD KEY `idx_platform_user_tenant` (`tenant_id`);

-- 2) add the 'owner' role (shop owners are NOT platform staff)
ALTER TABLE `platform_users`
  MODIFY `role` ENUM('super_admin','support','readonly','owner') NOT NULL DEFAULT 'support';

-- Notes:
-- * New self-serve shops are created with tenants.status = 'pending_setup'
--   (the table default), which resolve_subdomain.php already gates behind a
--   "waiting for approval" screen. A platform admin flips it to 'active' from
--   admin/tenant-approvals.php to unlock the shop.
-- * tenant_id is intentionally NOT a hard FK so a tenant row can be hard-deleted
--   during failed-provision cleanup without blocking on this reference.
