-- =============================================================================
-- Migration: Phone-keyed loyalty members + semi-auto merge flag
-- Date:      2026-06-20
-- Scope:     points_merge_candidates (TENANT-SCOPED — apply to EACH reya_tenant_* DB)
--
-- Counter-sale loyalty by PHONE NUMBER (external POS, LINE optional):
--   - Pharmacist enters a phone at the counter (api/points-claim.php?action=give_by_phone).
--   - Found  -> credit the existing customer (LINE-linked or phone-only).
--   - New    -> create a phone-only "offline ghost" user (users.line_user_id =
--               'offline:<phone>', satisfying the NOT NULL + unique_line_user
--               constraint without touching the schema) and credit it.
--   - When the same phone later links a LINE account (api/member.php register),
--     we DO NOT auto-merge: we flag a pending merge candidate here so a
--     pharmacist can confirm "this phone has X points — merge into the LINE
--     profile?" during a quiet moment. Confirming transfers the ghost's points
--     into the LINE user. This keeps the busy counter flow friction-free while
--     keeping the merge safe (a mistyped phone never silently moves points).
--
-- No change to `users`: `phone` + KEY `idx_phone` already exist, and the
-- synthetic `offline:<phone>` line_user_id avoids the NOT NULL / unique_line_user
-- constraint. Only this flag table is new.
--
-- Re-run safe: CREATE uses IF NOT EXISTS. Charset utf8mb4_unicode_ci (Thai).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `points_merge_candidates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `line_account_id` INT NOT NULL COMMENT 'tenant LINE OA scope — FK line_accounts.id',
  `phone` VARCHAR(20) NOT NULL COMMENT 'normalized phone shared by both records',

  `offline_user_id` INT NOT NULL COMMENT 'FK users.id — the phone-only ghost holding points to move',
  `line_user_id` INT NOT NULL COMMENT 'FK users.id — the LINE-linked target to merge INTO',
  `offline_points` INT NOT NULL DEFAULT 0 COMMENT 'snapshot of ghost available_points when flagged',

  `status` ENUM('pending','merged','dismissed') NOT NULL DEFAULT 'pending'
    COMMENT 'pending=awaiting pharmacist confirm, merged=points moved, dismissed=ignored',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'when confirmed/dismissed',
  `resolved_by` INT NULL COMMENT 'FK admin_users.id — pharmacist who resolved',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pair` (`line_account_id`, `offline_user_id`, `line_user_id`),
  KEY `idx_account_status` (`line_account_id`, `status`),
  KEY `idx_phone` (`line_account_id`, `phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pending phone->LINE loyalty merges awaiting pharmacist confirmation. Tenant-scoped.';
