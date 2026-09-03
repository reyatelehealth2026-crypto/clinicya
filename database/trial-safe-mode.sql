-- database/trial-safe-mode.sql
--
-- Run this on a TENANT database on the VPS trial stack, immediately after
-- importing a production dump and BEFORE anyone opens the admin UI.
--
-- WHY: an imported dump carries live, working outbound credentials. LINE
-- channel access tokens live per-row in `line_accounts` (classes/LineAPI.php
-- takes the token via its constructor, not from a config constant), and the
-- same is true of Telegram bot tokens, SMTP passwords, Facebook page tokens
-- and TikTok Shop tokens. Clicking dispense or broadcast on the trial stack
-- would reach real customers.
--
-- WHAT THIS DOES: severs every customer-reaching outbound channel while
-- leaving all data intact, so you can still browse, search, and exercise
-- read paths against realistic data.
--
-- WHAT IT DOES NOT DO: it does not touch customer PII. If the trial will be
-- seen by anyone who should not see real patient data, scrub separately.
--
-- ============================================================================
-- SAFETY CHECK — run this FIRST and read the output. Do not proceed unless
-- the host is the trial container.
-- ============================================================================
SELECT @@hostname AS host, DATABASE() AS db, NOW() AS server_time;

-- ============================================================================
-- 1. LINE
-- ============================================================================
-- NOTE `channel_secret` is NOT NULL and carries UNIQUE KEY
-- `unique_channel_secret`, so blanking every row to '' fails with a duplicate
-- key error the moment there is more than one account. Per-row placeholder
-- values keep the constraint satisfied.
UPDATE `line_accounts`
   SET `channel_access_token` = '',
       `channel_secret`       = CONCAT('trial-disabled-', `id`),
       `webhook_url`          = NULL,
       `is_active`            = 0;

-- ============================================================================
-- 2. Telegram
-- ============================================================================
UPDATE `telegram_settings`
   SET `is_enabled` = 0,
       `bot_token`  = '',
       `chat_id`    = '';

-- ============================================================================
-- 3. Email / SMTP
-- ============================================================================
UPDATE `email_settings`
   SET `smtp_host` = '',
       `smtp_user` = '',
       `smtp_pass` = '';

-- ============================================================================
-- 4. Facebook
-- ============================================================================
UPDATE `facebook_accounts`
   SET `is_active`          = 0,
       `page_access_token`  = '',
       `app_secret`         = '',
       `verify_token`       = '',
       `webhook_url`        = NULL;

-- ============================================================================
-- 5. TikTok Shop
-- ============================================================================
UPDATE `tiktok_shop_accounts`
   SET `is_active`     = 0,
       `access_token`  = '',
       `refresh_token` = '',
       `app_secret`    = '',
       `webhook_url`   = NULL;

-- ============================================================================
-- 6. AI keys — OPTIONAL, commented out on purpose.
-- ============================================================================
-- These do not reach customers, but they DO reach a paid API and bill the real
-- account. Leave them live if testing the AI consultation flow is the point of
-- the trial; uncomment to sever them if it is not.
--
-- UPDATE `ai_settings` SET `gemini_api_key` = '', `openai_api_key` = '', `is_enabled` = 0;

-- ============================================================================
-- Verify — every row below should show a blanked credential.
-- ============================================================================
SELECT 'line'     AS channel, id, is_active, LENGTH(channel_access_token) AS token_len FROM line_accounts
UNION ALL SELECT 'telegram', id, is_enabled, LENGTH(bot_token)         FROM telegram_settings
UNION ALL SELECT 'facebook', id, is_active,  LENGTH(page_access_token) FROM facebook_accounts
UNION ALL SELECT 'tiktok',   id, is_active,  LENGTH(access_token)      FROM tiktok_shop_accounts;
