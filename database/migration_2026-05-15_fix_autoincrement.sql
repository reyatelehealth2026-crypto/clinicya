-- ---------------------------------------------------------------------------
-- Migration: 2026-05-15 restore keys + AUTO_INCREMENT on line_accounts & activity_logs
-- ---------------------------------------------------------------------------
-- Production audit on 2026-05-15 found that on the `zrismpsz_demo` database the
-- tables `line_accounts` and `activity_logs` had been imported WITHOUT their
-- PRIMARY KEY, UNIQUE KEY, secondary indexes, or AUTO_INCREMENT attribute.
-- As a result:
--   * LineAccountManager->createAccount() (settings.php → create LINE account)
--     threw repeated PHP Fatal: 1364 Field 'id' doesn't have a default value
--   * ActivityLogger->log() threw the same error on every admin action.
--
-- Both tables were empty in production at the time of this fix, so no data
-- migration / dedupe is needed.
--
-- This migration restores the schema defined in
-- database/install_complete_latest.sql for these two tables.
-- ---------------------------------------------------------------------------

SET time_zone = '+07:00';

-- =========================================================================
-- line_accounts: restore PRIMARY KEY + UNIQUE KEY + AUTO_INCREMENT
-- =========================================================================
ALTER TABLE `line_accounts`
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `unique_channel_secret` (`channel_secret`);

ALTER TABLE `line_accounts`
    MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;

-- =========================================================================
-- activity_logs: restore PRIMARY KEY + secondary indexes + AUTO_INCREMENT
-- =========================================================================
ALTER TABLE `activity_logs`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_log_type` (`log_type`),
    ADD KEY `idx_action` (`action`),
    ADD KEY `idx_user_id` (`user_id`),
    ADD KEY `idx_admin_id` (`admin_id`),
    ADD KEY `idx_entity` (`entity_type`,`entity_id`),
    ADD KEY `idx_created_at` (`created_at`),
    ADD KEY `idx_line_account` (`line_account_id`);

ALTER TABLE `activity_logs`
    MODIFY `id` BIGINT(20) NOT NULL AUTO_INCREMENT;
