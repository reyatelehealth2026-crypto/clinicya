-- ─────────────────────────────────────────────────────────────────────────────
-- tenant_line_account_routes — Platform-wide routing index for LINE OAs.
--
-- Maps every (line_account_id, tenant_id) pair so that root-domain requests
-- (webhook.php?account=N, api/checkout.php?line_account_id=N, etc.) can resolve
-- the correct tenant DB WITHOUT depending on subdomain or session.
--
-- Why this exists:
--   - LINE webhook URLs are configured per-channel in the LINE Developers console
--     and CANNOT use tenant subdomains (LINE will not change them per tenant).
--   - LIFF apps load from a single base URL (line-mini-app/), so requests come
--     from re-ya.com (root) — no subdomain → no TenantContext.
--   - We must route by the `account` / `line_account_id` parameter the client
--     already sends, mapping it back to the owning tenant.
--
-- IDs are NOT globally unique across tenant DBs (each tenant DB has its own
-- auto-increment), so the (line_account_id, tenant_id) pair is the composite
-- identity. We assume that in practice the LINE OA channel ID + channel secret
-- belong to exactly ONE tenant — guarded by the unique on `channel_id`.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `tenant_line_account_routes` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `line_account_id`  INT(11)      NOT NULL COMMENT 'PK of line_accounts row INSIDE the tenant DB',
    `tenant_id`        INT(11)      NOT NULL COMMENT 'FK → tenants.id',
    `tenant_db_name`   VARCHAR(64)  NOT NULL COMMENT 'Denormalised: tenants.db_name — saves a JOIN per request',
    `oa_name`          VARCHAR(150) DEFAULT NULL COMMENT 'Friendly name for ops debugging',
    `channel_id`       VARCHAR(50)  DEFAULT NULL COMMENT 'LINE Messaging API channel_id — globally unique per OA',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `last_seen_at`     TIMESTAMP    NULL DEFAULT NULL COMMENT 'Optional: last time a request hit this route',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_line_account_tenant` (`line_account_id`, `tenant_id`),
    UNIQUE KEY `uk_channel_id`          (`channel_id`),
    KEY `idx_line_account` (`line_account_id`),
    KEY `idx_tenant`       (`tenant_id`),
    CONSTRAINT `fk_tlar_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='LINE account → tenant routing index (used by webhook + LIFF APIs that come in via root domain)';
