-- migration_2026-06-28_site_visitor_ips_geo.sql
-- Platform (master) DB: zrismpsz_reya_platform
--
-- Enrich the new-visitor table with geolocation + device/bot classification,
-- populated by classes/SiteNotifier.php (ip-api.com lookup + UA parsing).
-- Re-run safe (ADD COLUMN IF NOT EXISTS — MariaDB 10.3+).

ALTER TABLE `site_visitor_ips`
  ADD COLUMN IF NOT EXISTS `city`       VARCHAR(100)  NULL AFTER `country`,
  ADD COLUMN IF NOT EXISTS `region`     VARCHAR(100)  NULL AFTER `city`,
  ADD COLUMN IF NOT EXISTS `lat`        DECIMAL(10,6) NULL AFTER `region`,
  ADD COLUMN IF NOT EXISTS `lon`        DECIMAL(10,6) NULL AFTER `lat`,
  ADD COLUMN IF NOT EXISTS `isp`        VARCHAR(150)  NULL AFTER `lon`,
  ADD COLUMN IF NOT EXISTS `asn`        VARCHAR(120)  NULL AFTER `isp`,
  ADD COLUMN IF NOT EXISTS `is_bot`     TINYINT(1)    NOT NULL DEFAULT 0 AFTER `asn`,
  ADD COLUMN IF NOT EXISTS `bot_name`   VARCHAR(60)   NULL AFTER `is_bot`,
  ADD COLUMN IF NOT EXISTS `device`     VARCHAR(40)   NULL AFTER `bot_name`,
  ADD COLUMN IF NOT EXISTS `os`         VARCHAR(60)   NULL AFTER `device`,
  ADD COLUMN IF NOT EXISTS `browser`    VARCHAR(60)   NULL AFTER `os`,
  ADD COLUMN IF NOT EXISTS `is_proxy`   TINYINT(1)    NOT NULL DEFAULT 0 AFTER `browser`,
  ADD COLUMN IF NOT EXISTS `is_hosting` TINYINT(1)    NOT NULL DEFAULT 0 AFTER `is_proxy`,
  ADD COLUMN IF NOT EXISTS `is_mobile`  TINYINT(1)    NOT NULL DEFAULT 0 AFTER `is_hosting`;
