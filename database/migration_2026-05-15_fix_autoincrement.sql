-- ---------------------------------------------------------------------------
-- Migration: 2026-05-15 fix AUTO_INCREMENT on line_accounts.id + activity_logs.id
-- ---------------------------------------------------------------------------
-- Production was throwing repeated PHP Fatal errors:
--   SQLSTATE[HY000]: 1364 Field 'id' doesn't have a default value
-- in classes/LineAccountManager.php:148 (INSERT INTO line_accounts)
-- and classes/ActivityLogger.php (INSERT INTO activity_logs).
--
-- Schema in database/install_complete_latest.sql declares both id columns as
-- AUTO_INCREMENT, but the production tables lost the AUTO_INCREMENT attribute
-- (likely from an older mysqldump restore). This migration restores it.
--
-- Safe to re-run: MODIFY is idempotent for the type definition.
-- ---------------------------------------------------------------------------

SET time_zone = '+07:00';

-- line_accounts.id -- INT NOT NULL AUTO_INCREMENT
ALTER TABLE `line_accounts`
    MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;

-- activity_logs.id -- BIGINT NOT NULL AUTO_INCREMENT
ALTER TABLE `activity_logs`
    MODIFY `id` BIGINT(20) NOT NULL AUTO_INCREMENT;

-- Optional: re-seed AUTO_INCREMENT to MAX(id)+1 so the next insert is safe.
-- Run these manually after the ALTERs above if the auto_increment counter
-- got out of sync with the data:
--   SELECT @next := IFNULL(MAX(id),0)+1 FROM line_accounts;
--   SET @sql := CONCAT('ALTER TABLE line_accounts AUTO_INCREMENT=', @next);
--   PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
--
--   SELECT @next := IFNULL(MAX(id),0)+1 FROM activity_logs;
--   SET @sql := CONCAT('ALTER TABLE activity_logs AUTO_INCREMENT=', @next);
--   PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
