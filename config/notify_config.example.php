<?php
/**
 * notify_config.example.php — template for site-level alert secrets.
 *
 * Copy to config/notify_config.php ON THE SERVER ONLY (gitignored, chmod 600)
 * and fill in real values. Consumed by classes/SiteNotifier.php.
 *
 *   - NOTIFY_SIGNUP_EMAIL      : address that receives "new shop signup" emails
 *   - NOTIFY_TELEGRAM_BOT_TOKEN: Telegram bot token (from @BotFather) for alerts
 *   - NOTIFY_TELEGRAM_CHAT_ID  : Telegram chat / group / channel id to post to
 *
 * Leaving a value blank disables that channel (no error).
 */
declare(strict_types=1);

define('NOTIFY_SIGNUP_EMAIL', '');        // e.g. owner@example.com (comma-separated for many)
define('NOTIFY_TELEGRAM_BOT_TOKEN', '');  // e.g. 1234567890:AAEx...
define('NOTIFY_TELEGRAM_CHAT_ID', '');    // e.g. -1001234567890

// Platform-owner allowlist. A Google login whose email is in this list is sent
// to /admin/platform-dashboard.php (Platform Owner console) instead of a shop.
define('REYA_OWNER_EMAILS', '');          // e.g. a@example.com,b@example.com
