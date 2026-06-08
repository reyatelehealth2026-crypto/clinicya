-- ─────────────────────────────────────────────────────────────────────────────
-- migration_2026-06-02_route_liff_id.sql
--
-- Add liff_id to the platform routing index so the shared LINE Mini App can
-- resolve liff_id → line_account_id in ONE indexed query (api/resolve-line-account.php).
--
-- The Mini App is a single static export shared by all tenants. When a deep link
-- does not carry ?la={lineAccountId}, the app reads its LIFF id and asks the
-- resolver endpoint which tenant/line_account it belongs to. Without this column
-- the resolver must scan every tenant DB; with it, lookups are O(1).
--
-- Backfill: api/resolve-line-account.php opportunistically writes this column the
-- first time it resolves a liff_id via the scan fallback. Provisioning
-- (classes/TenantProvisioning.php) should also set it when a tenant finishes LIFF
-- setup. Idempotent: safe to re-run (guarded by INFORMATION_SCHEMA check).
--
-- Run against: master DB (zrismpsz_reya_platform)
-- 2026-06-02
-- ─────────────────────────────────────────────────────────────────────────────

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tenant_line_account_routes'
       AND COLUMN_NAME  = 'liff_id'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `tenant_line_account_routes`
        ADD COLUMN `liff_id` VARCHAR(64) DEFAULT NULL
            COMMENT ''LIFF id of the Mini App for this OA — used by api/resolve-line-account.php''
            AFTER `channel_id`,
        ADD KEY `idx_tlar_liff_id` (`liff_id`)',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
