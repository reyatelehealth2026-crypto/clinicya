-- infra/e2e/seed/00-master-db.sql
--
-- Creates the master/platform DB shell. The six committed master migrations
-- (see infra/e2e/run.mjs's MASTER_MIGRATIONS list) are piped in immediately
-- after this, each with a `USE \`zrismpsz_reya_platform\`;` line prepended
-- by run.mjs (most of those files don't carry their own USE statement — see
-- run.mjs's applySqlFile() for why that's necessary with docker compose exec
-- piping rather than docker-entrypoint-initdb.d's MYSQL_DATABASE-default
-- trick).
--
-- 'zrismpsz_reya_platform' is TenantContext::PLATFORM_DB_NAME /
-- packages/config's PLATFORM_DB_NAME — a fixed schema name hardcoded in both
-- runtimes (not a secret, not sourced from config/config.php), safe to write
-- literally here exactly as those two source files already do.
CREATE DATABASE IF NOT EXISTS `zrismpsz_reya_platform`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
