-- migration_2026-06-28_tenant_activity_log.sql
-- Platform (master) DB: zrismpsz_reya_platform
--
-- Central feed of TENANT (shop) activity — login, points awarded, LINE connect,
-- etc. — written cross-context by classes/TenantActivity.php from inside tenant
-- requests. Powers the platform dashboard "recent activity" feed and per-tenant
-- activity, and drives Telegram alerts. No FK to tenants (keep history even if a
-- tenant is deleted; never block tenant deletion). Idempotent.

CREATE TABLE IF NOT EXISTS `tenant_activity_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`  INT          NOT NULL              COMMENT 'tenants.id (no FK on purpose)',
  `event_type` VARCHAR(40)  NOT NULL              COMMENT 'login | points_award | line_connect | ...',
  `actor`      VARCHAR(150) NULL                  COMMENT 'who did it (admin email/name or customer)',
  `detail`     VARCHAR(255) NULL                  COMMENT 'short human detail',
  `notified`   TINYINT(1)   NOT NULL DEFAULT 0    COMMENT '1 = a Telegram alert was sent',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tal_created` (`created_at`),
  KEY `idx_tal_tenant_event` (`tenant_id`, `event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Central tenant/shop activity feed (login, points, LINE connect)';
