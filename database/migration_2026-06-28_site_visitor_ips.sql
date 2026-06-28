-- migration_2026-06-28_site_visitor_ips.sql
-- Platform (master) DB: zrismpsz_reya_platform
--
-- Tracks unique public-landing visitor IPs so SiteNotifier can fire a
-- one-time Telegram alert the first time a given IP hits re-ya.com.
-- Idempotent.

CREATE TABLE IF NOT EXISTS `site_visitor_ips` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`           VARCHAR(45)  NOT NULL              COMMENT 'Real visitor IP (Cloudflare CF-Connecting-IP)',
  `user_agent`   VARCHAR(255) NULL,
  `referer`      VARCHAR(255) NULL,
  `country`      VARCHAR(8)   NULL                  COMMENT 'CF-IPCountry code',
  `hits`         INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL              COMMENT 'First seen',
  `last_seen_at` DATETIME     NULL                  COMMENT 'Most recent visit',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip` (`ip`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unique public-landing visitor IPs for new-visitor alerts';
