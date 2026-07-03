-- =====================================================================
-- migration_2026-05-25_tenant_template.sql
-- =====================================================================
-- Canonical schema template for a NEW REYA tenant database.
--
-- Purpose:
--   Apply this script verbatim to every freshly-provisioned
--   `reya_tenant_NNNN` database to install the full per-tenant table
--   set. The script is IDEMPOTENT - every statement uses
--   CREATE TABLE IF NOT EXISTS so re-running on an existing tenant DB
--   adds only missing tables without touching existing ones.
--
-- Scope:
--   - Includes only tables that belong INSIDE a tenant DB.
--   - Platform-level tables (admin_users, dev_logs, etc.) live in
--     `reya_platform` and are defined by a separate migration.
--   - Dead/legacy/backup tables on production are EXCLUDED.
--   - Orphan tables that contained tenant data but lacked
--     `line_account_id` have a NOT NULL `line_account_id` column added
--     immediately after `id`, plus a covering index. Default is 1 to
--     keep INSERTs from existing code paths safe until the app is
--     fully tenant-aware.
--
-- Conventions:
--   - All tables: ENGINE=InnoDB, CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci.
--   - All FOREIGN KEYs reference tables INSIDE the same tenant DB.
--   - `line_account_id` is the channel id (LINE OA), which inside a
--     single-tenant DB doubles as the channel discriminator. Cross-tenant
--     isolation is enforced by the database boundary itself, not by this
--     column. See docs/adr/0001-database-per-tenant-isolation.md.
--   - All AUTO_INCREMENT counters reset to default (1) on creation.
--
-- Generated:    2026-05-25T02:55:56.308260Z
-- Source DB:    zrismpsz_demo (322 base tables on prod, 7.81 MB total)
-- Source dump:  .codegraph/reya_schema_dump.sql
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------
-- SECTION: CORE ACCOUNT AND USERS  (17 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `account_daily_stats` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `stat_date` date NOT NULL,
  `new_followers` int(11) DEFAULT 0,
  `unfollowers` int(11) DEFAULT 0,
  `total_messages` int(11) DEFAULT 0,
  `incoming_messages` int(11) DEFAULT 0,
  `outgoing_messages` int(11) DEFAULT 0,
  `unique_users` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_events` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`event_data`)),
  `webhook_event_id` varchar(100) DEFAULT NULL,
  `source_type` varchar(20) DEFAULT 'user',
  `source_id` varchar(50) DEFAULT NULL,
  `reply_token` varchar(255) DEFAULT NULL,
  `timestamp` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_followers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `picture_url` text DEFAULT NULL,
  `status_message` text DEFAULT NULL,
  `is_following` tinyint(1) DEFAULT 1,
  `followed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unfollowed_at` timestamp NULL DEFAULT NULL,
  `follow_count` int(11) DEFAULT 1,
  `last_interaction_at` timestamp NULL DEFAULT NULL,
  `total_messages` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_af_line_following` (`line_account_id`, `is_following`, `user_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flex_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `flex_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`flex_json`)),
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `use_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#3B82F6',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_accounts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'ชื่อบัญชี LINE OA',
  `channel_id` varchar(100) DEFAULT NULL COMMENT 'Channel ID',
  `channel_secret` varchar(100) NOT NULL COMMENT 'Channel Secret',
  `liff_id` varchar(50) DEFAULT NULL,
  `unified_liff_id` varchar(50) DEFAULT NULL,
  `channel_access_token` text NOT NULL COMMENT 'Channel Access Token',
  `webhook_url` varchar(500) DEFAULT NULL COMMENT 'Webhook URL',
  `basic_id` varchar(50) DEFAULT NULL COMMENT 'LINE Basic ID (@xxx)',
  `picture_url` varchar(500) DEFAULT NULL COMMENT 'รูปโปรไฟล์',
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0 COMMENT 'บัญชีหลัก',
  `bot_mode` enum('shop','general','auto_reply_only') DEFAULT 'shop',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ตั้งค่าเพิ่มเติม' CHECK (json_valid(`settings`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `welcome_message` text DEFAULT NULL,
  `auto_reply_enabled` tinyint(1) DEFAULT 1,
  `shop_enabled` tinyint(1) DEFAULT 1,
  `rich_menu_id` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_channel_secret` (`channel_secret`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_groups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `group_id` varchar(50) NOT NULL,
  `group_type` enum('group','room') DEFAULT 'group',
  `group_name` varchar(255) DEFAULT NULL,
  `picture_url` text DEFAULT NULL,
  `member_count` int(11) DEFAULT 0,
  `invited_by` varchar(50) DEFAULT NULL,
  `invited_by_name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `total_messages` int(11) DEFAULT 0,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quick_reply_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `category` varchar(50) DEFAULT '',
  `shortcuts` text DEFAULT NULL,
  `variables` text DEFAULT NULL,
  `quick_reply` text DEFAULT NULL COMMENT 'JSON array of LINE Quick Reply items',
  `usage_count` int(11) DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shared_flex_messages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `share_code` varchar(20) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `flex_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`flex_content`)),
  `view_count` int(11) DEFAULT 0,
  `share_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_addresses` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `line_account_id` int(11) DEFAULT 0,
  `label` varchar(20) NOT NULL COMMENT 'primary | secondary_1 | secondary_2 | secondary_3',
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL COMMENT 'à¸šà¹‰à¸²à¸™à¹€à¸¥à¸‚à¸—à¸µà¹ˆ + à¸–à¸™à¸™',
  `subdistrict` varchar(100) DEFAULT NULL COMMENT 'à¸•à¸³à¸šà¸¥/à¹à¸‚à¸§à¸‡',
  `district` varchar(100) DEFAULT NULL COMMENT 'à¸­à¸³à¹€à¸ à¸­/à¹€à¸‚à¸•',
  `province` varchar(100) DEFAULT NULL,
  `postcode` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_label` (`line_user_id`,`line_account_id`,`label`),
  KEY `idx_line_user` (`line_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_behaviors` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `behavior_type` varchar(50) NOT NULL,
  `behavior_category` varchar(100) DEFAULT NULL,
  `behavior_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`behavior_data`)),
  `source` varchar(50) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_custom_fields` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `field_name` varchar(100) NOT NULL COMMENT 'ชื่อฟิลด์',
  `field_key` varchar(50) NOT NULL COMMENT 'key สำหรับใช้ในโค้ด',
  `field_type` enum('text','number','date','select','checkbox','textarea') DEFAULT 'text',
  `field_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ตัวเลือก (สำหรับ select)' CHECK (json_valid(`field_options`)),
  `is_required` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_points` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `total_points` int(11) DEFAULT 0,
  `available_points` int(11) DEFAULT 0,
  `used_points` int(11) DEFAULT 0,
  `tier` varchar(20) DEFAULT 'member',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_rich_menus` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `rich_menu_id` int(11) NOT NULL,
  `line_rich_menu_id` varchar(100) NOT NULL,
  `rule_id` int(11) DEFAULT NULL COMMENT 'กฎที่ใช้กำหนด (NULL = manual)',
  `assigned_reason` varchar(255) DEFAULT NULL COMMENT 'เหตุผลที่กำหนด',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_wishlist` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `price_when_added` decimal(10,2) DEFAULT 0.00 COMMENT 'ราคาตอนที่เพิ่ม',
  `notify_on_sale` tinyint(1) DEFAULT 1 COMMENT 'แจ้งเตือนเมื่อลดราคา',
  `notify_on_restock` tinyint(1) DEFAULT 0 COMMENT 'แจ้งเตือนเมื่อมีสินค้า',
  `notified_at` timestamp NULL DEFAULT NULL COMMENT 'แจ้งเตือนล่าสุดเมื่อ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `facebook_account_id` int(11) DEFAULT NULL,
  `tiktok_account_id` int(11) DEFAULT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'line',
  `platform_user_id` varchar(100) DEFAULT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `custom_display_name` varchar(255) DEFAULT NULL COMMENT 'Custom name set by admin (overrides LINE display_name)',
  `picture_url` text DEFAULT NULL,
  `status_message` text DEFAULT NULL,
  `real_name` varchar(255) DEFAULT NULL COMMENT 'ชื่อจริง',
  `phone` varchar(20) DEFAULT NULL COMMENT 'เบอร์โทร',
  `email` varchar(255) DEFAULT NULL COMMENT 'อีเมล',
  `birthday` date DEFAULT NULL COMMENT 'วันเกิด',
  `address` text DEFAULT NULL COMMENT 'ที่อยู่',
  `province` varchar(100) DEFAULT NULL COMMENT 'จังหวัด',
  `postal_code` varchar(10) DEFAULT NULL COMMENT 'รหัสไปรษณีย์',
  `note` text DEFAULT NULL COMMENT 'หมายเหตุ',
  `total_orders` int(11) DEFAULT 0 COMMENT 'จำนวนออเดอร์ทั้งหมด',
  `total_spent` decimal(12,2) DEFAULT 0.00 COMMENT 'ยอดซื้อรวม',
  `last_order_at` timestamp NULL DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `unread_count` int(11) DEFAULT 0,
  `customer_score` int(11) DEFAULT 0 COMMENT 'คะแนนลูกค้า 0-100',
  `is_blocked` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `order_count` int(11) DEFAULT 0,
  `total_points` int(11) DEFAULT 0 COMMENT 'แต้มสะสมทั้งหมด',
  `available_points` int(11) DEFAULT 0 COMMENT 'แต้มที่ใช้ได้',
  `used_points` int(11) DEFAULT 0 COMMENT 'แต้มที่ใช้ไปแล้ว',
  `medical_conditions` text DEFAULT NULL COMMENT 'โรคประจำตัว',
  `drug_allergies` text DEFAULT NULL COMMENT 'แพ้ยา',
  `current_medications` text DEFAULT NULL COMMENT 'ยาที่ใช้อยู่',
  `emergency_contact` varchar(100) DEFAULT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `reply_token` varchar(255) DEFAULT NULL,
  `reply_token_expires` datetime DEFAULT NULL,
  `is_registered` tinyint(1) DEFAULT 0,
  `loyalty_points` int(11) DEFAULT 0,
  `consent_privacy` tinyint(1) DEFAULT 0,
  `consent_terms` tinyint(1) DEFAULT 0,
  `consent_health_data` tinyint(1) DEFAULT 0,
  `consent_date` datetime DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL COMMENT 'ชื่อ',
  `last_name` varchar(100) DEFAULT NULL COMMENT 'นามสกุล',
  `gender` enum('male','female','other') DEFAULT NULL COMMENT 'เพศ',
  `weight` decimal(5,2) DEFAULT NULL COMMENT 'น้ำหนัก (กก.)',
  `height` decimal(5,2) DEFAULT NULL COMMENT 'ส่วนสูง (ซม.)',
  `district` varchar(100) DEFAULT NULL COMMENT 'เขต/อำเภอ',
  `member_id` varchar(20) DEFAULT NULL COMMENT 'รหัสสมาชิก',
  `tier_id` int(11) DEFAULT NULL COMMENT 'ระดับสมาชิก',
  `registered_at` datetime DEFAULT NULL COMMENT 'วันที่สมัคร',
  `account_id` int(11) DEFAULT NULL,
  `membership_level` enum('bronze','silver','gold','platinum') DEFAULT 'bronze',
  `notes` text DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL,
  `last_interaction` datetime DEFAULT NULL,
  `points` int(11) DEFAULT 0,
  `tier` varchar(50) DEFAULT 'Silver',
  `tier_updated_at` timestamp NULL DEFAULT NULL,
  `chat_status` varchar(50) DEFAULT NULL,
  `order_days` varchar(255) DEFAULT NULL,
  KEY `idx_users_line_account` (`line_account_id`),
  KEY `idx_users_line_user` (`line_user_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: ADMIN UI  (1 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_quick_access` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `admin_user_id` int(11) NOT NULL,
  `menu_key` varchar(50) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_admin_quick_access_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: MESSAGING  (30 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `auto_replies` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `keyword` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL COMMENT 'Rule description',
  `tags` varchar(255) DEFAULT NULL COMMENT 'Tags for categorization',
  `match_type` enum('exact','contains','starts_with','regex') DEFAULT 'contains',
  `reply_type` varchar(50) DEFAULT 'text',
  `reply_content` text NOT NULL,
  `alt_text` varchar(400) DEFAULT NULL COMMENT 'Alt text for Flex Message',
  `sender_name` varchar(100) DEFAULT NULL COMMENT 'Custom sender name',
  `sender_icon` varchar(500) DEFAULT NULL COMMENT 'Custom sender icon URL',
  `quick_reply` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Quick reply buttons JSON' CHECK (json_valid(`quick_reply`)),
  `is_active` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 0,
  `use_count` int(11) DEFAULT 0 COMMENT 'Number of times used',
  `last_used_at` timestamp NULL DEFAULT NULL COMMENT 'Last time this rule was triggered',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `enable_share` tinyint(1) DEFAULT 0,
  `share_button_label` varchar(50) DEFAULT '? แชร์ให้เพื่อน',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auto_reply_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `keyword` varchar(255) NOT NULL COMMENT 'คำสำคัญที่ต้องการตรวจจับ',
  `match_type` enum('exact','contains','starts_with','ends_with','regex') DEFAULT 'contains' COMMENT 'ประเภทการจับคู่',
  `response_type` enum('text','flex','image','video','audio') DEFAULT 'text' COMMENT 'ประเภทการตอบกลับ',
  `response_content` text NOT NULL COMMENT 'เนื้อหาการตอบกลับ (text หรือ JSON สำหรับ flex)',
  `priority` int(11) DEFAULT 0 COMMENT 'ลำดับความสำคัญ (เลขมากทำก่อน)',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'เปิดใช้งาน',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กฎการตอบกลับอัตโนมัติ';

CREATE TABLE IF NOT EXISTS `auto_tag_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `rule_id` int(11) DEFAULT NULL,
  `action` enum('assign','remove') NOT NULL,
  `trigger_type` varchar(50) DEFAULT NULL,
  `trigger_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trigger_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_auto_tag_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auto_tag_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `tag_id` int(11) NOT NULL,
  `rule_name` varchar(100) NOT NULL,
  `trigger_type` varchar(50) NOT NULL,
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`conditions`)),
  `is_active` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_campaigns` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `message_type` enum('text','flex','image','product_carousel') DEFAULT 'text',
  `content` longtext DEFAULT NULL,
  `auto_tag_enabled` tinyint(1) DEFAULT 0,
  `tag_prefix` varchar(50) DEFAULT NULL,
  `sent_count` int(11) DEFAULT 0,
  `click_count` int(11) DEFAULT 0,
  `status` enum('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_clicks` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `broadcast_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `tag_assigned` tinyint(1) DEFAULT 0,
  `clicked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_broadcast_clicks_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `broadcast_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_image` varchar(500) DEFAULT NULL,
  `item_price` decimal(10,2) DEFAULT 0.00,
  `postback_data` varchar(255) NOT NULL,
  `tag_id` int(11) DEFAULT NULL,
  `click_count` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_broadcast_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_messages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text NOT NULL,
  `flex_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`flex_json`)),
  `target_type` enum('all','tag','segment') DEFAULT 'all',
  `target_tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_tags`)),
  `target_segment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_segment`)),
  `sent_count` int(11) DEFAULT 0,
  `success_count` int(11) DEFAULT 0,
  `fail_count` int(11) DEFAULT 0,
  `status` enum('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_messages_v2` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `target_segment_id` int(11) DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `total_recipients` int(11) DEFAULT 0,
  `delivered_count` int(11) DEFAULT 0,
  `read_count` int(11) DEFAULT 0,
  `status` enum('draft','scheduled','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_queue` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `broadcast_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_broadcast_queue_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcasts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text NOT NULL,
  `target_type` varchar(20) DEFAULT 'all' COMMENT 'database, all, limit, narrowcast, group, segment, tag, select, single',
  `target_group_id` varchar(255) DEFAULT NULL,
  `sent_count` int(11) DEFAULT 0,
  `status` enum('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_status_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp(),
  `note` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_assignees` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `conversation_id` int(11) NOT NULL,
  `assignee_id` int(11) NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL,
  KEY `idx_conversation_assignees_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_assignments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Customer user ID',
  `line_account_id` int(11) NOT NULL DEFAULT 1,
  `assigned_to` int(11) NOT NULL COMMENT 'Admin user ID',
  `assigned_by` int(11) DEFAULT NULL COMMENT 'Who assigned',
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','resolved','transferred') DEFAULT 'active',
  `resolved_at` datetime DEFAULT NULL,
  KEY `idx_ca_user` (`user_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_multi_assignees` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL COMMENT 'Customer user ID',
  `admin_id` int(11) NOT NULL COMMENT 'Admin user ID assigned',
  `assigned_by` int(11) DEFAULT NULL COMMENT 'Who assigned this admin',
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','resolved') DEFAULT 'active',
  `resolved_at` datetime DEFAULT NULL,
  KEY `idx_conversation_multi_assignees_la` (`line_account_id`),
  KEY `idx_cma_user_status` (`user_id`, `status`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Supports multiple admins assigned to one conversation';

CREATE TABLE IF NOT EXISTS `conversation_states` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `current_state` varchar(50) NOT NULL,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`state_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_conversation_states_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emergency_alerts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL COMMENT 'Original message that triggered the alert',
  `red_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detected red flags array' CHECK (json_valid(`red_flags`)),
  `severity` enum('warning','high','critical') DEFAULT 'warning',
  `emergency_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional emergency information' CHECK (json_valid(`emergency_info`)),
  `status` enum('pending','reviewed','handled','dismissed') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL COMMENT 'Admin user who reviewed',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Pharmacist notes',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_notification_preferences` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(64) NOT NULL,
  `line_account_id` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `order_updates` tinyint(1) NOT NULL DEFAULT 1,
  `promotions` tinyint(1) NOT NULL DEFAULT 1,
  `appointment_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `med_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `health_tips` tinyint(1) NOT NULL DEFAULT 0,
  `price_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `restock_alerts` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_account` (`line_user_id`,`line_account_id`),
  KEY `idx_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_analytics` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `response_time_seconds` int(11) DEFAULT NULL COMMENT 'Time to respond in seconds',
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_message_analytics_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'line',
  `user_id` int(11) DEFAULT NULL,
  `direction` enum('incoming','outgoing') NOT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text DEFAULT NULL,
  `reply_token` varchar(255) DEFAULT NULL,
  `mark_as_read_token` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_read_on_line` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_by` varchar(100) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `media_url` text DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `reply_to_id` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_msg_user` (`user_id`),
  KEY `idx_msg_user_created` (`user_id`, `created_at`),
  KEY `idx_msg_user_dir_read` (`user_id`, `direction`, `is_read`),
  KEY `idx_msg_line_created` (`line_account_id`, `created_at`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mims_conversation_state` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`state_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_mims_conversation_state_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 0,
  `line_notify_enabled` tinyint(1) DEFAULT 1,
  `line_notify_new_order` tinyint(1) DEFAULT 1,
  `line_notify_payment` tinyint(1) DEFAULT 1,
  `line_notify_urgent` tinyint(1) DEFAULT 1,
  `line_notify_appointment` tinyint(1) DEFAULT 1,
  `line_notify_low_stock` tinyint(1) DEFAULT 0,
  `email_enabled` tinyint(1) DEFAULT 0,
  `email_addresses` text DEFAULT NULL,
  `email_notify_urgent` tinyint(1) DEFAULT 1,
  `email_notify_daily_report` tinyint(1) DEFAULT 0,
  `email_notify_low_stock` tinyint(1) DEFAULT 0,
  `telegram_enabled` tinyint(1) DEFAULT 0,
  `odoo_liff_notify_enabled` tinyint(1) DEFAULT 1,
  `odoo_liff_notify_events` text DEFAULT NULL,
  `notify_admin_users` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacist_notifications` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `triage_session_id` int(11) DEFAULT NULL,
  `priority` enum('normal','urgent') DEFAULT 'normal',
  `notification_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`notification_data`)),
  `status` enum('pending','read','handled','dismissed') DEFAULT 'pending',
  `handled_by` int(11) DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` varchar(50) DEFAULT 'triage_alert',
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'ID of related record',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'Type of related record',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scheduled_messages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text NOT NULL,
  `target_type` enum('all','group','user') DEFAULT 'all',
  `target_id` int(11) DEFAULT NULL,
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `repeat_type` enum('none','daily','weekly','monthly') DEFAULT 'none',
  `status` enum('pending','sent','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sla_tracking` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `conversation_id` int(11) NOT NULL,
  `sla_threshold_minutes` int(11) NOT NULL,
  `started_at` timestamp NOT NULL,
  `deadline_at` timestamp NOT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `is_breached` tinyint(1) DEFAULT 0,
  KEY `idx_sla_tracking_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `name` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT 'gray',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_tags_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notification_preferences` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `order_updates` tinyint(1) DEFAULT 1,
  `promotions` tinyint(1) DEFAULT 1,
  `appointment_reminders` tinyint(1) DEFAULT 1,
  `drug_reminders` tinyint(1) DEFAULT 1,
  `health_tips` tinyint(1) DEFAULT 0,
  `price_alerts` tinyint(1) DEFAULT 1,
  `restock_alerts` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notification_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `order_updates` tinyint(1) DEFAULT 1,
  `promotions` tinyint(1) DEFAULT 1,
  `appointment_reminders` tinyint(1) DEFAULT 1,
  `drug_reminders` tinyint(1) DEFAULT 1,
  `health_tips` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_user_notification_settings_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tag_assignments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `assigned_by` varchar(50) DEFAULT 'manual' COMMENT 'manual, auto, system, campaign',
  `assigned_reason` text DEFAULT NULL,
  `score` int(11) DEFAULT 0 COMMENT 'คะแนนความสนใจ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Tag หมดอายุเมื่อไหร่',
  KEY `idx_user_tag_assignments_la` (`line_account_id`),
  KEY `idx_uta_user` (`user_id`, `tag_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tags` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `color` varchar(7) DEFAULT '#3B82F6',
  `description` text DEFAULT NULL,
  `tag_type` enum('manual','auto','system','broadcast') DEFAULT 'manual',
  `auto_assign_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'เงื่อนไขการติด Tag อัตโนมัติ' CHECK (json_valid(`auto_assign_rules`)),
  `auto_remove_rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'เงื่อนไขการถอด Tag อัตโนมัติ' CHECK (json_valid(`auto_remove_rules`)),
  `source_type` enum('manual','auto','broadcast','system') DEFAULT 'manual',
  `source_id` int(11) DEFAULT NULL,
  `priority` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: INVENTORY AND PRODUCTS  (38 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `business_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cat_name_account` (`line_account_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `item_type` enum('physical','digital','service','booking','content') DEFAULT 'physical',
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `name_en` varchar(500) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `image_gallery` longtext DEFAULT NULL COMMENT 'JSON array of image URLs for the mini-app product detail gallery',
  `action_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ข้อมูลเฉพาะประเภท: game_code, download_url, etc.' CHECK (json_valid(`action_data`)),
  `delivery_method` enum('shipping','email','line','download','onsite') DEFAULT 'shipping',
  `validity_days` int(11) DEFAULT NULL COMMENT 'อายุการใช้งาน (สำหรับ digital/service)',
  `max_quantity` int(11) DEFAULT NULL COMMENT 'จำนวนสูงสุดต่อออเดอร์',
  `stock` int(11) DEFAULT 0,
  `sku` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `is_flash_sale` tinyint(1) DEFAULT 0,
  `is_choice` tinyint(1) DEFAULT 0,
  `flash_sale_end` datetime DEFAULT NULL,
  `is_promotion` tinyint(1) DEFAULT 0,
  `promotion_start` datetime DEFAULT NULL,
  `promotion_end` datetime DEFAULT NULL,
  `featured_order` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `barcode` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `active_ingredient` text DEFAULT NULL COMMENT 'ตัวยาสำคัญ',
  `generic_name` varchar(255) DEFAULT NULL,
  `usage_instructions` longtext DEFAULT NULL,
  `unit` varchar(50) DEFAULT 'ชิ้น',
  `extra_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ข้อมูลเพิ่มเติมจาก API (JSON)' CHECK (json_valid(`extra_data`)),
  `dosage_form` varchar(100) DEFAULT NULL,
  `drug_category` varchar(50) DEFAULT NULL COMMENT 'ประเภทยา: otc, dangerous, controlled',
  `strength` varchar(100) DEFAULT NULL,
  `warnings` text DEFAULT NULL,
  `contraindications` text DEFAULT NULL,
  `dosage` varchar(255) DEFAULT NULL COMMENT 'ขนาดยา',
  `side_effects` text DEFAULT NULL,
  `storage_conditions` varchar(200) DEFAULT NULL,
  `requires_prescription` tinyint(1) DEFAULT 0,
  `is_bestseller` tinyint(1) DEFAULT 0,
  `min_stock` int(11) DEFAULT 5,
  `reorder_point` int(11) DEFAULT 5,
  `supplier_id` int(11) DEFAULT NULL,
  `storage_condition` varchar(255) DEFAULT NULL COMMENT 'สภาพการจัดเก็บ/ตำแหน่งจัดเก็บ',
  `movement_class` enum('A','B','C') DEFAULT 'C' COMMENT 'ABC classification',
  `storage_zone_type` varchar(50) DEFAULT 'general',
  `default_location_id` int(11) DEFAULT NULL COMMENT 'Default storage location',
  `requires_batch_tracking` tinyint(1) DEFAULT 0 COMMENT 'Requires batch/lot tracking',
  `requires_expiry_tracking` tinyint(1) DEFAULT 0 COMMENT 'Requires expiry date tracking',
  `base_unit` varchar(50) DEFAULT NULL COMMENT 'หน่วยนับ เช่น ขวด, กล่อง, แผง',
  `product_price` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ราคาตามกลุ่มลูกค้า JSON array' CHECK (json_valid(`product_price`)),
  `properties_other` longtext DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL COMMENT 'URL รูปภาพจาก CNY',
  `cny_id` int(11) DEFAULT NULL COMMENT 'ID จาก CNY API',
  `cny_category` varchar(100) DEFAULT NULL COMMENT 'หมวดหมู่จาก CNY',
  `hashtag` varchar(500) DEFAULT NULL COMMENT 'Hashtag สำหรับค้นหา',
  `qty_incoming` int(11) DEFAULT 0 COMMENT 'จำนวนที่กำลังเข้า',
  `enable` tinyint(1) DEFAULT 1 COMMENT 'เปิด/ปิดขาย',
  `last_synced_at` timestamp NULL DEFAULT NULL COMMENT 'เวลา sync ล่าสุด',
  `ai_recommendable` tinyint(1) NOT NULL DEFAULT 1,
  `dispensing_fee` decimal(10,2) DEFAULT 0.00 COMMENT 'à¸„à¹ˆà¸²à¸«à¸¢à¸´à¸šà¸¢à¸² per unit / dispensing fee',
  `storage_location_id` int(11) DEFAULT NULL COMMENT 'FK storage_locations.id',
  `drug_group_id` int(11) DEFAULT NULL COMMENT 'FK drug_groups.id',
  `generic_name_id` int(11) DEFAULT NULL COMMENT 'FK generic_names.id',
  `unit_id` int(11) DEFAULT NULL COMMENT 'FK product_units.id (sell unit)',
  `label_template_id` int(11) DEFAULT NULL COMMENT 'FK drug_label_templates.id',
  `usage_method` varchar(100) DEFAULT NULL COMMENT 'à¸§à¸´à¸˜à¸µà¸à¸²à¸£à¹ƒà¸Šà¹‰ (oral, topical, injection ...)',
  `label_language` varchar(5) DEFAULT 'th' COMMENT 'TH / EN',
  `default_usage_text` text DEFAULT NULL COMMENT 'à¸§à¸´à¸˜à¸µà¹ƒà¸Šà¹‰à¹€à¸£à¸´à¹ˆà¸¡à¸•à¹‰à¸™à¸ªà¸³à¸«à¸£à¸±à¸šà¸‰à¸¥à¸²à¸',
  `default_warning_text` text DEFAULT NULL COMMENT 'à¸„à¸³à¹€à¸•à¸·à¸­à¸™à¹€à¸£à¸´à¹ˆà¸¡à¸•à¹‰à¸™à¸ªà¸³à¸«à¸£à¸±à¸šà¸‰à¸¥à¸²à¸',
  PRIMARY KEY (`id`),
  KEY `idx_bi_tenant_active` (`line_account_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drug_disposal_records` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `reason` enum('expired','damaged','recalled','other') NOT NULL,
  `disposal_method` varchar(255) DEFAULT NULL COMMENT 'วิธีการทำลาย',
  `disposed_by` int(11) NOT NULL COMMENT 'ผู้ทำลาย',
  `witness_by` int(11) DEFAULT NULL COMMENT 'พยาน',
  `disposal_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `photo_evidence` text DEFAULT NULL COMMENT 'รูปถ่ายหลักฐาน',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกการทำลายยา (เก็บไว้ 3 ปีตามกฎหมาย)';

CREATE TABLE IF NOT EXISTS `drug_groups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `code` varchar(50) DEFAULT NULL COMMENT 'à¸£à¸«à¸±à¸ªà¸à¸¥à¸¸à¹ˆà¸¡ / group code',
  `name_th` varchar(255) NOT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸ à¸²à¸©à¸²à¹„à¸—à¸¢',
  `name_en` varchar(255) DEFAULT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸ à¸²à¸©à¸²à¸­à¸±à¸‡à¸à¸¤à¸©',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dg_tenant` (`line_account_id`),
  KEY `idx_dg_tenant_code` (`line_account_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸à¸¥à¸¸à¹ˆà¸¡à¸¢à¸² / drug groups (e.g. NSAIDs, antibiotics)';

CREATE TABLE IF NOT EXISTS `drug_interaction_acknowledgments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `drug1_id` int(11) NOT NULL,
  `drug2_id` int(11) NOT NULL,
  `drug1_name` varchar(255) DEFAULT NULL,
  `drug2_name` varchar(255) DEFAULT NULL,
  `severity` enum('mild','moderate','severe') DEFAULT 'moderate',
  `acknowledged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `order_id` int(11) DEFAULT NULL,
  KEY `idx_drug_interaction_acknowledgments_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drug_interactions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'FK line_accounts.id (NULL = global)',
  `drug1_name` varchar(100) NOT NULL,
  `drug1_generic` varchar(100) DEFAULT NULL,
  `drug2_name` varchar(100) NOT NULL,
  `drug2_generic` varchar(100) DEFAULT NULL,
  `severity` enum('mild','moderate','severe','contraindicated') DEFAULT 'moderate',
  `description` text DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `mechanism` text DEFAULT NULL COMMENT 'à¸à¸¥à¹„à¸ / mechanism of interaction',
  `interaction_text` text DEFAULT NULL COMMENT 'à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸œà¸¥à¸—à¸µà¹ˆà¹€à¸à¸´à¸” (alias / extended description)',
  PRIMARY KEY (`id`),
  KEY `idx_di_tenant` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drug_label_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¹€à¸—à¸¡à¹€à¸žà¸¥à¸•',
  `template_text` text NOT NULL COMMENT 'placeholders: {shop_name}, {patient_name}, {drug_name}, {dose}, {usage}, {date}, {pharmacist}',
  `language` varchar(5) DEFAULT 'th',
  `applies_to_generic_id` int(11) DEFAULT NULL COMMENT 'FK generic_names.id (auto-apply)',
  `applies_to_usage_pattern` varchar(100) DEFAULT NULL COMMENT 'usage_method match for bulk apply',
  `default_for_drug_group_id` int(11) DEFAULT NULL COMMENT 'FK drug_groups.id (default for whole group)',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dlt_tenant` (`line_account_id`),
  KEY `idx_dlt_generic` (`applies_to_generic_id`),
  KEY `idx_dlt_drug_group` (`default_for_drug_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¹€à¸—à¸¡à¹€à¸žà¸¥à¸•à¸‰à¸¥à¸²à¸à¸¢à¸² / drug label templates';

CREATE TABLE IF NOT EXISTS `drug_pricing_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `rule_name` varchar(100) NOT NULL,
  `rule_type` enum('category','brand','generic','margin','promotion') NOT NULL DEFAULT 'margin',
  `category_id` int(11) DEFAULT NULL COMMENT 'Product category ID if applicable',
  `brand_name` varchar(255) DEFAULT NULL COMMENT 'Brand name if applicable',
  `min_margin` decimal(5,2) DEFAULT 15.00 COMMENT 'Minimum margin percentage',
  `max_margin` decimal(5,2) DEFAULT 40.00 COMMENT 'Maximum margin percentage',
  `target_margin` decimal(5,2) DEFAULT 25.00 COMMENT 'Target margin percentage',
  `price_rounding` enum('none','nearest_5','nearest_10','up_5','up_10') DEFAULT 'nearest_5',
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional conditions for rule application' CHECK (json_valid(`conditions`)),
  `priority` int(11) DEFAULT 0 COMMENT 'Higher priority rules applied first',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_drug_pricing_rules_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drug_recognition_cache` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `image_hash` varchar(64) NOT NULL,
  `image_url` text DEFAULT NULL,
  `drug_name` varchar(255) DEFAULT NULL,
  `generic_name` varchar(255) DEFAULT NULL,
  `matched_product_id` int(11) DEFAULT NULL,
  `recognition_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recognition_result`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  KEY `idx_drug_recognition_cache_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drug_type_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'NULL = ใช้ทุกบัญชี, มีค่า = เฉพาะบัญชีนั้น',
  `match_type` enum('category','name_contains','sku_prefix') NOT NULL,
  `match_value` varchar(128) NOT NULL,
  `drug_type` varchar(64) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 100 COMMENT 'ตัวเลขน้อย = match ก่อน',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_match` (`match_type`,`match_value`),
  KEY `idx_line_priority` (`line_account_id`,`priority`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กฎการ map drug_type จาก category/name/sku ของ Odoo';

CREATE TABLE IF NOT EXISTS `expense_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `name_en` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `expense_type` enum('operating','administrative','financial','other') DEFAULT 'operating',
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `expense_number` varchar(50) NOT NULL,
  `category_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `expense_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `payment_status` enum('unpaid','paid') DEFAULT 'unpaid',
  `payment_voucher_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `generic_names` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `generic_name` varchar(255) NOT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸—à¸²à¸‡à¸à¸²à¸£ / generic name',
  `atc_code` varchar(20) DEFAULT NULL COMMENT 'WHO ATC classification',
  `default_dosage_form` varchar(100) DEFAULT NULL COMMENT 'à¸£à¸¹à¸›à¹à¸šà¸šà¸¢à¸²à¹€à¸£à¸´à¹ˆà¸¡à¸•à¹‰à¸™',
  `default_unit` varchar(50) DEFAULT NULL COMMENT 'à¸«à¸™à¹ˆà¸§à¸¢à¹€à¸£à¸´à¹ˆà¸¡à¸•à¹‰à¸™',
  `default_warnings` text DEFAULT NULL COMMENT 'à¸„à¸³à¹€à¸•à¸·à¸­à¸™à¹€à¸£à¸´à¹ˆà¸¡à¸•à¹‰à¸™',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gn_tenant` (`line_account_id`),
  KEY `idx_gn_tenant_name` (`line_account_id`,`generic_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸Šà¸·à¹ˆà¸­à¸—à¸²à¸‡à¸à¸²à¸£à¸‚à¸­à¸‡à¸¢à¸² / generic drug names';

CREATE TABLE IF NOT EXISTS `goods_receive_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `gr_id` int(11) NOT NULL,
  `po_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'FK to business_items.id',
  `unit_id` int(11) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `unit_factor` decimal(10,4) DEFAULT 1.0000,
  `quantity` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `batch_number` varchar(50) DEFAULT NULL COMMENT 'Batch number from supplier',
  `lot_number` varchar(50) DEFAULT NULL COMMENT 'Lot number from supplier',
  `expiry_date` date DEFAULT NULL COMMENT 'Product expiry date',
  `manufacture_date` date DEFAULT NULL COMMENT 'Product manufacture date',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  KEY `idx_goods_receive_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goods_receives` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `gr_number` varchar(30) NOT NULL,
  `po_id` int(11) NOT NULL,
  `status` enum('draft','confirmed','cancelled') DEFAULT 'draft',
  `receive_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `image_analysis_results` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `message_id` int(11) NOT NULL,
  `analysis_type` enum('symptom','drug','prescription') NOT NULL,
  `results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`results`)),
  `confidence` decimal(3,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_image_analysis_results_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_batches` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT 1,
  `product_id` int(11) NOT NULL,
  `batch_number` varchar(50) NOT NULL,
  `lot_number` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `quantity_available` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `manufacture_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `status` enum('active','quarantine','expired','disposed') DEFAULT 'active',
  `disposal_date` datetime DEFAULT NULL,
  `disposal_by` int(11) DEFAULT NULL,
  `disposal_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `manufacturer_code` varchar(100) DEFAULT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `cny_code` varchar(50) DEFAULT NULL COMMENT 'CNY Category Code',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `display_order` int(11) DEFAULT 0 COMMENT 'à¸¥à¸³à¸”à¸±à¸šà¸à¸²à¸£à¹à¸ªà¸”à¸‡',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_images` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `item_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  KEY `idx_item_images_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `location_movements` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT 1,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `from_location_id` int(11) DEFAULT NULL,
  `to_location_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `movement_type` enum('put_away','pick','transfer','adjustment','disposal') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `manufacturer_code` varchar(100) DEFAULT NULL,
  `cny_code` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `product_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  KEY `idx_product_images_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_symptom_map` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `symptom_code` varchar(64) NOT NULL,
  `symptom_label_th` varchar(255) DEFAULT NULL,
  `weight` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `is_first_line` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_product_symptom` (`line_account_id`,`product_id`,`symptom_code`),
  KEY `idx_psm_symptom` (`symptom_code`),
  KEY `idx_psm_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_units` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `unit_name` varchar(50) NOT NULL COMMENT 'ชื่อหน่วย เช่น ขวด, โหล, กล่อง',
  `unit_code` varchar(20) DEFAULT NULL COMMENT 'รหัสหน่วย เช่น BTL, DOZ, BOX',
  `factor` decimal(10,4) NOT NULL DEFAULT 1.0000 COMMENT 'ตัวคูณเทียบกับหน่วยหลัก เช่น โหล=12',
  `cost_price` decimal(10,2) DEFAULT NULL COMMENT 'ราคาทุนต่อหน่วยนี้',
  `sale_price` decimal(10,2) DEFAULT NULL COMMENT 'ราคาขายต่อหน่วยนี้',
  `barcode` varchar(50) DEFAULT NULL COMMENT 'บาร์โค้ดของหน่วยนี้',
  `is_base_unit` tinyint(1) DEFAULT 0 COMMENT 'เป็นหน่วยหลักหรือไม่',
  `is_purchase_unit` tinyint(1) DEFAULT 1 COMMENT 'ใช้สำหรับสั่งซื้อ',
  `is_sale_unit` tinyint(1) DEFAULT 1 COMMENT 'ใช้สำหรับขาย',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_unit` (`product_id`,`unit_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `previous_price` decimal(10,2) DEFAULT NULL,
  `price_changed_at` timestamp NULL DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `item_type` enum('physical','digital','service','booking','content') DEFAULT 'physical',
  `delivery_method` enum('shipping','email','line','download','onsite') DEFAULT 'shipping',
  `action_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`action_data`)),
  `stock` int(11) DEFAULT 0,
  `max_quantity` int(11) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_featured` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `validity_days` int(11) DEFAULT NULL COMMENT 'อายุการใช้งาน (วัน) สำหรับ digital/service',
  `old_business_item_id` int(11) DEFAULT NULL COMMENT 'ID เดิมจาก business_items (สำหรับ migration)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `barcode` varchar(100) DEFAULT NULL COMMENT 'บาร์โค้ด',
  `manufacturer` varchar(255) DEFAULT NULL COMMENT 'ผู้ผลิต/บริษัท',
  `generic_name` varchar(255) DEFAULT NULL COMMENT 'ชื่อสามัญยา',
  `usage_instructions` text DEFAULT NULL COMMENT 'วิธีใช้/ขนาดรับประทาน',
  `unit` varchar(50) DEFAULT 'ชิ้น' COMMENT 'หน่วยนับ',
  `extra_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ข้อมูลเพิ่มเติมจาก API' CHECK (json_valid(`extra_data`)),
  `is_bestseller` tinyint(1) DEFAULT 0,
  `drug_type` enum('controlled','dangerous','household','traditional') DEFAULT 'household' COMMENT 'ประเภทยา: controlled=ยาควบคุมพิเศษ, dangerous=ยาอันตราย, household=ยาสามัญประจำบ้าน, traditional=ยาแผนโบราณ',
  `requires_prescription` tinyint(1) DEFAULT 0 COMMENT 'ต้องมีใบสั่งแพทย์หรือไม่',
  `requires_pharmacist` tinyint(1) DEFAULT 0 COMMENT 'ต้องมีเภสัชกรจ่ายหรือไม่',
  `drug_schedule` varchar(50) DEFAULT NULL COMMENT 'บัญชียา (Schedule 1, 2, 3)',
  `active_ingredient` text DEFAULT NULL COMMENT 'ตัวยาสำคัญ',
  `strength` varchar(100) DEFAULT NULL COMMENT 'ความแรงของยา (e.g., 500mg)',
  `dosage_form` varchar(100) DEFAULT NULL COMMENT 'รูปแบบยา (tablet, capsule, syrup, etc.)',
  `fda_registration` varchar(100) DEFAULT NULL COMMENT 'เลขทะเบียน อย.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `po_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'FK to business_items.id',
  `unit_id` int(11) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `unit_factor` decimal(10,4) DEFAULT 1.0000,
  `quantity` int(11) NOT NULL,
  `received_quantity` int(11) DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL,
  `subtotal` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  KEY `idx_purchase_order_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `po_number` varchar(30) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `status` enum('draft','submitted','partial','completed','cancelled') DEFAULT 'draft',
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restock_notifications` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `wishlist_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `notification_type` varchar(50) DEFAULT 'restock',
  `old_stock` int(11) DEFAULT 0,
  `new_stock` int(11) DEFAULT 0,
  `message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_restock_notifications_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_products` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `product_id` varchar(64) DEFAULT NULL,
  `product_code` varchar(64) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `generic_name` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `category` varchar(150) DEFAULT NULL,
  `drug_type` varchar(64) DEFAULT NULL COMMENT 'ชนิดยา: OTC / Rx / Controlled / Supplement / Cosmetic / Other (derive จาก drug_type_rules)',
  `list_price` decimal(12,2) DEFAULT 0.00,
  `online_price` decimal(12,2) DEFAULT 0.00,
  `saleable_qty` decimal(12,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `storefront_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=แสดงบนหน้าร้านจริง, 0=sync แล้วแต่ซ่อน (default 0 — admin ต้องเปิดเอง)',
  `featured_order` int(11) DEFAULT NULL COMMENT 'ลำดับ pin บนหน้าแรก (NULL=ไม่ pin, ค่าน้อย=อยู่บน)',
  `admin_overrides` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'admin override per-field เช่น {"list_price":100,"name":"ชื่อใหม่"} — sync ไม่แตะ (effective value = COALESCE(override, sync))' CHECK (json_valid(`admin_overrides`)),
  `last_synced_at` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image_url` varchar(500) DEFAULT NULL,
  `image_gallery` longtext DEFAULT NULL,
  `description` text DEFAULT NULL,
  `usage_instructions` text DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `base_unit` varchar(50) DEFAULT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `is_local` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_storefront` (`line_account_id`,`storefront_enabled`,`is_active`),
  KEY `idx_drug_type` (`line_account_id`,`drug_type`),
  KEY `idx_featured_order` (`line_account_id`,`featured_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_adjustments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `adjustment_number` varchar(30) NOT NULL,
  `adjustment_type` enum('increase','decrease') NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'FK to business_items.id',
  `quantity` int(11) NOT NULL,
  `reason` enum('physical_count','damaged','expired','lost','found','correction','other') NOT NULL,
  `reason_detail` text DEFAULT NULL,
  `stock_before` int(11) NOT NULL,
  `stock_after` int(11) NOT NULL,
  `status` enum('draft','confirmed','cancelled') DEFAULT 'draft',
  `created_by` int(11) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_count_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `session_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `expected_qty` int(11) NOT NULL DEFAULT 0,
  `counted_qty` int(11) DEFAULT NULL,
  `delta` int(11) DEFAULT NULL COMMENT 'counted - expected',
  `note` text DEFAULT NULL,
  `counted_at` timestamp NULL DEFAULT NULL,
  KEY `idx_stock_count_items_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sci_session_product` (`session_id`,`product_id`),
  KEY `idx_sci_session` (`session_id`),
  KEY `idx_sci_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸£à¸²à¸¢à¸à¸²à¸£à¸ªà¸´à¸™à¸„à¹‰à¸²à¸—à¸µà¹ˆà¸™à¸±à¸šà¹ƒà¸™à¹à¸•à¹ˆà¸¥à¸°à¸£à¸­à¸š';

CREATE TABLE IF NOT EXISTS `stock_count_sessions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `code` varchar(50) DEFAULT NULL COMMENT 'à¸£à¸«à¸±à¸ªà¸£à¸­à¸šà¸™à¸±à¸š',
  `name` varchar(255) DEFAULT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸£à¸­à¸šà¸™à¸±à¸š (à¹€à¸Šà¹ˆà¸™ à¸ªà¸´à¹‰à¸™à¹€à¸”à¸·à¸­à¸™ 05/2569)',
  `status` enum('draft','counting','submitted','adjusted','cancelled') DEFAULT 'draft',
  `scope` enum('all','category','location','custom') DEFAULT 'all',
  `scope_ref_id` int(11) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `started_by` int(11) DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT current_timestamp(),
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scs_tenant` (`line_account_id`),
  KEY `idx_scs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸‡à¸²à¸™à¸™à¸±à¸šà¸ªà¸´à¸™à¸„à¹‰à¸² (stock count sessions)';

CREATE TABLE IF NOT EXISTS `stock_movements` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL COMMENT 'FK to business_items.id',
  `unit_id` int(11) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `unit_factor` decimal(10,4) DEFAULT 1.0000,
  `movement_type` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL COMMENT 'บวก=เข้า, ลบ=ออก',
  `stock_before` int(11) NOT NULL,
  `stock_after` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'goods_receive, order, adjustment',
  `reference_id` int(11) DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unit_cost` decimal(12,2) DEFAULT 0.00,
  `value_change` decimal(12,2) DEFAULT 0.00,
  `lot_no` varchar(50) DEFAULT NULL COMMENT 'Lot number',
  `expiry_date` date DEFAULT NULL COMMENT 'Expiry date for this movement',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `storage_locations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `code` varchar(50) DEFAULT NULL COMMENT 'à¸£à¸«à¸±à¸ªà¸žà¸·à¹‰à¸™à¸—à¸µà¹ˆ (A1, B2 ...)',
  `name` varchar(255) NOT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸žà¸·à¹‰à¸™à¸—à¸µà¹ˆà¹€à¸à¹‡à¸š',
  `temperature_range` varchar(50) DEFAULT NULL COMMENT 'à¹€à¸Šà¹ˆà¸™ 2-8Â°C',
  `humidity_range` varchar(50) DEFAULT NULL COMMENT 'à¹€à¸Šà¹ˆà¸™ <60%',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sl_tenant` (`line_account_id`),
  KEY `idx_sl_tenant_code` (`line_account_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸žà¸·à¹‰à¸™à¸—à¸µà¹ˆà¹€à¸à¹‡à¸šà¸¢à¸² / storage locations';

CREATE TABLE IF NOT EXISTS `suppliers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `code` varchar(20) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_id` varchar(20) DEFAULT NULL,
  `payment_terms` int(11) DEFAULT 30 COMMENT 'วันครบกำหนดชำระ',
  `total_purchase_amount` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `temperature_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `location_id` int(11) DEFAULT NULL COMMENT 'ตำแหน่งที่เก็บ (ตู้เย็น, ห้องเย็น, etc.)',
  `temperature` decimal(5,2) NOT NULL COMMENT 'อุณหภูมิ (°C)',
  `humidity` decimal(5,2) DEFAULT NULL COMMENT 'ความชื้น (%)',
  `recorded_by` int(11) NOT NULL COMMENT 'ผู้บันทึก',
  `recorded_at` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  `alert_triggered` tinyint(1) DEFAULT 0 COMMENT 'แจ้งเตือนเมื่ออุณหภูมิผิดปกติ',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกอุณหภูมิการเก็บรักษา (เก็บไว้ 1 ปี)';

CREATE TABLE IF NOT EXISTS `warehouse_locations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT 1,
  `location_code` varchar(20) NOT NULL,
  `zone` varchar(10) NOT NULL,
  `shelf` int(11) NOT NULL,
  `bin` int(11) NOT NULL,
  `zone_type` varchar(50) DEFAULT 'general',
  `ergonomic_level` enum('golden','upper','lower') DEFAULT 'golden',
  `capacity` int(11) DEFAULT 100,
  `current_qty` int(11) DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_location_code` (`location_code`,`line_account_id`),
  KEY `idx_zone` (`zone`),
  KEY `idx_zone_type` (`zone_type`),
  KEY `idx_location_code` (`location_code`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_ergonomic` (`ergonomic_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zone_types` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT 1,
  `code` varchar(50) NOT NULL COMMENT 'Unique code for zone type',
  `label` varchar(100) NOT NULL COMMENT 'Display label',
  `color` varchar(20) DEFAULT 'gray' COMMENT 'Tailwind color name',
  `icon` varchar(50) DEFAULT 'fa-box' COMMENT 'FontAwesome icon class',
  `description` text DEFAULT NULL COMMENT 'Description of zone type',
  `storage_requirements` text DEFAULT NULL COMMENT 'Special storage requirements',
  `is_default` tinyint(1) DEFAULT 0 COMMENT 'Is system default type',
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_zone_type_code` (`line_account_id`,`code`),
  KEY `idx_zone_type_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: ORDERS AND TRANSACTIONS  (13 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `account_payables` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `ap_number` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `gr_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL,
  `status` enum('open','partial','paid','cancelled') DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_receivables` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `ar_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `received_amount` decimal(12,2) DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL,
  `status` enum('open','partial','paid','cancelled') DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_cart_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cart_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `product_id` int(11) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `product_source` varchar(32) NOT NULL DEFAULT 'business_items' COMMENT 'business_items|odoo_products_cache',
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_cart_items_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product_source` (`user_id`,`product_id`,`product_source`),
  UNIQUE KEY `unique_user_product_unit` (`line_user_id`,`product_id`,`unit_id`),
  KEY `idx_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  KEY `idx_order_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `shipping_fee` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `grand_total` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','paid','shipping','delivered','cancelled') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `shipping_name` varchar(255) DEFAULT NULL,
  `shipping_phone` varchar(20) DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `shipping_tracking` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_proofs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `transaction_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_payment_proofs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_slips` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_id` int(11) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `image_url` varchar(500) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_payment_slips_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_vouchers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `voucher_number` varchar(50) NOT NULL,
  `voucher_type` enum('ap','expense') NOT NULL,
  `reference_id` int(11) NOT NULL COMMENT 'AP ID or Expense ID',
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','transfer','cheque','credit_card') NOT NULL,
  `bank_account` varchar(100) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `cheque_number` varchar(50) DEFAULT NULL,
  `cheque_date` date DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_vouchers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `voucher_number` varchar(50) NOT NULL,
  `ar_id` int(11) NOT NULL,
  `receipt_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','transfer','cheque','credit_card') NOT NULL,
  `bank_account` varchar(100) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `slip_id` int(11) DEFAULT NULL COMMENT 'Link to payment_slips table',
  `attachment_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `slip_verifications` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `message_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `image_url` text NOT NULL,
  `expected_amount` decimal(10,2) DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `status` enum('pending','valid','suspicious','review','error') NOT NULL DEFAULT 'pending',
  `score` int(11) DEFAULT 0,
  `summary` text DEFAULT NULL,
  `flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`flags`)),
  `checks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checks`)),
  `parsed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parsed`)),
  `ocr_provider` varchar(50) DEFAULT NULL,
  `ocr_confidence` double DEFAULT NULL,
  `ocr_raw_text` longtext DEFAULT NULL,
  `sha256` varchar(64) DEFAULT NULL,
  `image_hash` varchar(64) DEFAULT NULL,
  `width` int(11) DEFAULT NULL,
  `height` int(11) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `verified_by` varchar(255) DEFAULT NULL,
  `verified_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` datetime(3) NOT NULL,
  KEY `idx_slip_verifications_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transaction_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_sku` varchar(100) DEFAULT NULL,
  `product_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  KEY `idx_transaction_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `shipping_fee` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `points_used` int(11) DEFAULT 0,
  `points_discount` decimal(10,2) DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `shipping_name` varchar(255) DEFAULT NULL,
  `shipping_phone` varchar(20) DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `shipping_tracking` varchar(100) DEFAULT NULL,
  `shipping_provider` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `transaction_type` varchar(50) DEFAULT 'purchase',
  `delivery_info` text DEFAULT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `wms_status` enum('pending_pick','picking','picked','packing','packed','ready_to_ship','shipped','on_hold') DEFAULT NULL,
  `picker_id` int(11) DEFAULT NULL,
  `packer_id` int(11) DEFAULT NULL,
  `pick_started_at` datetime DEFAULT NULL,
  `pick_completed_at` datetime DEFAULT NULL,
  `pack_started_at` datetime DEFAULT NULL,
  `pack_completed_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `carrier` varchar(50) DEFAULT NULL,
  `package_weight` decimal(10,2) DEFAULT NULL,
  `package_dimensions` varchar(50) DEFAULT NULL,
  `wms_exception` varchar(255) DEFAULT NULL,
  `wms_exception_resolved_at` datetime DEFAULT NULL,
  `wms_exception_resolved_by` int(11) DEFAULT NULL,
  `label_printed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: DOCUMENTS AND FINANCE  (7 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `business_document_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `document_id` int(11) NOT NULL COMMENT 'FK business_documents.id',
  `line_no` int(11) NOT NULL DEFAULT 1 COMMENT 'à¸¥à¸³à¸”à¸±à¸šà¸šà¸£à¸£à¸—à¸±à¸” (1..N)',
  `product_id` int(11) DEFAULT NULL COMMENT 'FK business_items.id (nullable for free-text services)',
  `product_sku` varchar(100) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸ªà¸´à¸™à¸„à¹‰à¸²/à¸šà¸£à¸´à¸à¸²à¸£',
  `description` text DEFAULT NULL COMMENT 'à¸£à¸²à¸¢à¸¥à¸°à¹€à¸­à¸µà¸¢à¸”à¹€à¸žà¸´à¹ˆà¸¡à¹€à¸•à¸´à¸¡',
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(50) DEFAULT NULL COMMENT 'à¸«à¸™à¹ˆà¸§à¸¢ à¹€à¸Šà¹ˆà¸™ à¸à¸¥à¹ˆà¸­à¸‡, à¸‚à¸§à¸”, à¹€à¸¡à¹‡à¸”',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '(qty * unit_price) - discount_amount',
  KEY `idx_business_document_items_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  KEY `idx_di_document` (`document_id`,`line_no`),
  KEY `idx_di_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸£à¸²à¸¢à¸à¸²à¸£à¸ªà¸´à¸™à¸„à¹‰à¸²à¹ƒà¸™à¹€à¸­à¸à¸ªà¸²à¸£';

CREATE TABLE IF NOT EXISTS `business_documents` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL COMMENT 'tenant scope â€” FK line_accounts.id',
  `doc_type` enum('QT','BL','INV','RE','TAX','DN','CN','PO','GR','DNP','CNP') NOT NULL COMMENT 'QT=quotation, BL=billing-note, INV=invoice, RE=receipt, TAX=tax-invoice, DN=debit-note, CN=credit-note, PO=purchase-order, GR=goods-receipt, DNP=debit-note-purchase, CNP=credit-note-purchase',
  `doc_number` varchar(30) NOT NULL COMMENT 'human-facing number, e.g. QT-2605-0001',
  `ref_transaction_id` int(11) DEFAULT NULL COMMENT 'FK transactions.id when bound to an order',
  `ref_doc_id` int(11) DEFAULT NULL COMMENT 'FK business_documents.id (e.g. INV references BL)',
  `customer_user_id` int(11) DEFAULT NULL COMMENT 'FK users.id when LINE customer',
  `customer_name` varchar(255) DEFAULT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸¥à¸¹à¸à¸„à¹‰à¸²',
  `customer_tax_id` varchar(20) DEFAULT NULL COMMENT 'à¹€à¸¥à¸‚à¸›à¸£à¸°à¸ˆà¸³à¸•à¸±à¸§à¸œà¸¹à¹‰à¹€à¸ªà¸µà¸¢à¸ à¸²à¸©à¸µ 13 à¸«à¸¥à¸±à¸',
  `customer_branch_code` varchar(20) DEFAULT NULL COMMENT 'à¸£à¸«à¸±à¸ªà¸ªà¸²à¸‚à¸²à¸¥à¸¹à¸à¸„à¹‰à¸² â€” 00000 = à¸ªà¸³à¸™à¸±à¸à¸‡à¸²à¸™à¹ƒà¸«à¸à¹ˆ',
  `customer_address` text DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `issue_date` date NOT NULL COMMENT 'à¸§à¸±à¸™à¸—à¸µà¹ˆà¸­à¸­à¸à¹€à¸­à¸à¸ªà¸²à¸£',
  `due_date` date DEFAULT NULL COMMENT 'à¸§à¸±à¸™à¸—à¸µà¹ˆà¸„à¸£à¸šà¸à¸³à¸«à¸™à¸” (BL/INV)',
  `valid_until` date DEFAULT NULL COMMENT 'à¹ƒà¸Šà¹‰à¹„à¸”à¹‰à¸–à¸¶à¸‡à¸§à¸±à¸™à¸—à¸µà¹ˆ (QT)',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'à¸£à¸§à¸¡à¸à¹ˆà¸­à¸™à¸ªà¹ˆà¸§à¸™à¸¥à¸”/à¸ à¸²à¸©à¸µ',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'à¸ªà¹ˆà¸§à¸™à¸¥à¸”à¸£à¸§à¸¡',
  `vat_rate` decimal(4,2) NOT NULL DEFAULT 7.00 COMMENT 'à¸­à¸±à¸•à¸£à¸² VAT %',
  `vat_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'à¸¢à¸­à¸” VAT',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'à¸¢à¸­à¸”à¸ªà¸¸à¸—à¸˜à¸´à¸£à¸§à¸¡ VAT',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'cash / transfer / credit_card / qr / cheque',
  `payment_ref` varchar(100) DEFAULT NULL COMMENT 'à¹€à¸¥à¸‚à¸­à¹‰à¸²à¸‡à¸­à¸´à¸‡à¸à¸²à¸£à¸Šà¸³à¸£à¸°',
  `status` enum('pending_approval','approved','cancelled') NOT NULL DEFAULT 'pending_approval' COMMENT 'à¸£à¸­à¸­à¸™à¸¸à¸¡à¸±à¸•à¸´ / à¸­à¸™à¸¸à¸¡à¸±à¸•à¸´ / à¸¢à¸à¹€à¸¥à¸´à¸',
  `note` text DEFAULT NULL COMMENT 'à¸«à¸¡à¸²à¸¢à¹€à¸«à¸•à¸¸ (à¸žà¸´à¸¡à¸žà¹Œà¸šà¸™à¹€à¸­à¸à¸ªà¸²à¸£)',
  `internal_note` text DEFAULT NULL COMMENT 'à¸«à¸¡à¸²à¸¢à¹€à¸«à¸•à¸¸à¸ à¸²à¸¢à¹ƒà¸™ (à¹„à¸¡à¹ˆà¸žà¸´à¸¡à¸žà¹Œ)',
  `created_by` int(11) DEFAULT NULL COMMENT 'admin_users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL COMMENT 'cached generated PDF/HTML path (optional)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_number_account` (`line_account_id`,`doc_number`),
  KEY `idx_doc_line_account_type` (`line_account_id`,`doc_type`,`issue_date`),
  KEY `idx_doc_status` (`status`),
  KEY `idx_doc_customer` (`customer_user_id`),
  KEY `idx_doc_ref_transaction` (`ref_transaction_id`),
  KEY `idx_doc_ref_doc` (`ref_doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¹€à¸­à¸à¸ªà¸²à¸£à¸šà¸±à¸à¸Šà¸µ (à¹ƒà¸šà¹€à¸ªà¸™à¸­à¸£à¸²à¸„à¸²/à¹ƒà¸šà¸à¸³à¸à¸±à¸šà¸ à¸²à¸©à¸µ/à¹ƒà¸šà¹€à¸ªà¸£à¹‡à¸ˆ à¸¯à¸¥à¸¯)';

CREATE TABLE IF NOT EXISTS `business_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `business_type` enum('retail','digital','service','hybrid') DEFAULT 'hybrid',
  `shop_name` varchar(255) DEFAULT 'LINE Business',
  `shop_logo` varchar(500) DEFAULT NULL,
  `welcome_message` text DEFAULT NULL,
  `shipping_fee` decimal(10,2) DEFAULT 50.00,
  `free_shipping_min` decimal(10,2) DEFAULT 500.00,
  `bank_accounts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bank_accounts`)),
  `promptpay_number` varchar(20) DEFAULT NULL,
  `digital_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`digital_settings`)),
  `service_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`service_settings`)),
  `contact_phone` varchar(20) DEFAULT NULL,
  `is_open` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_business_settings_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_sequences` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `doc_type` varchar(10) NOT NULL,
  `year_month` char(4) NOT NULL COMMENT 'YYMM in Buddhist year tail e.g. 2605 = à¸ž.à¸„. 2569',
  `last_seq` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seq_tenant_type_month` (`line_account_id`,`doc_type`,`year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸•à¸±à¸§à¹€à¸¥à¸‚à¸¥à¸³à¸”à¸±à¸šà¹€à¸­à¸à¸ªà¸²à¸£ (atomic counter)';

CREATE TABLE IF NOT EXISTS `settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(20) DEFAULT 'string',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_settings_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `shop_name` varchar(255) DEFAULT 'LINE Shop',
  `address` text DEFAULT NULL,
  `shop_logo` varchar(500) DEFAULT NULL,
  `welcome_message` text DEFAULT NULL,
  `shipping_fee` decimal(10,2) DEFAULT 50.00,
  `free_shipping_min` decimal(10,2) DEFAULT 500.00,
  `bank_accounts` text DEFAULT NULL,
  `promptpay_number` varchar(20) DEFAULT NULL,
  `promptpay_name` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `is_open` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `pharmacy_license` varchar(100) DEFAULT NULL COMMENT 'เลขที่ใบอนุญาตร้านยา',
  `pharmacist_name` varchar(255) DEFAULT NULL COMMENT 'ชื่อเภสัชกรผู้มีหน้าที่ปฏิบัติการ',
  `pharmacist_license` varchar(100) DEFAULT NULL COMMENT 'เลขที่ใบอนุญาตเภสัชกร',
  `shop_email` varchar(255) DEFAULT NULL COMMENT 'อีเมลร้าน',
  `privacy_policy_version` varchar(20) DEFAULT '1.0',
  `terms_version` varchar(20) DEFAULT '1.0',
  `cod_enabled` tinyint(1) DEFAULT 0,
  `cod_fee` decimal(10,2) DEFAULT 0.00,
  `auto_confirm_payment` tinyint(1) DEFAULT 0,
  `shop_address` text DEFAULT NULL,
  `line_id` varchar(100) DEFAULT NULL,
  `facebook_url` varchar(500) DEFAULT NULL,
  `instagram_url` varchar(500) DEFAULT NULL,
  `order_data_source` varchar(20) DEFAULT 'shop',
  `home_theme` varchar(32) NOT NULL DEFAULT 'modern' COMMENT 'modern | healthcare â€” controls mini-app home visual theme',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_la` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_tax_info` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `business_name` varchar(255) DEFAULT NULL COMMENT 'à¸Šà¸·à¹ˆà¸­à¸à¸´à¸ˆà¸à¸²à¸£',
  `business_name_en` varchar(255) DEFAULT NULL,
  `tax_id` varchar(20) DEFAULT NULL COMMENT 'à¹€à¸¥à¸‚à¸›à¸£à¸°à¸ˆà¸³à¸•à¸±à¸§à¸œà¸¹à¹‰à¹€à¸ªà¸µà¸¢à¸ à¸²à¸©à¸µ 13 à¸«à¸¥à¸±à¸',
  `branch_code` varchar(20) NOT NULL DEFAULT '00000' COMMENT '00000 = à¸ªà¸³à¸™à¸±à¸à¸‡à¸²à¸™à¹ƒà¸«à¸à¹ˆ',
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `authorized_signer` varchar(255) DEFAULT NULL COMMENT 'à¸œà¸¹à¹‰à¸¡à¸µà¸­à¸³à¸™à¸²à¸ˆà¸¥à¸‡à¸™à¸²à¸¡',
  `signer_position` varchar(100) DEFAULT NULL COMMENT 'à¸•à¸³à¹à¸«à¸™à¹ˆà¸‡',
  `is_vat_registered` tinyint(1) NOT NULL DEFAULT 0,
  `default_vat_rate` decimal(4,2) NOT NULL DEFAULT 7.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_tax_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¸˜à¸¸à¸£à¸à¸´à¸ˆà¸ªà¸³à¸«à¸£à¸±à¸šà¹€à¸­à¸à¸ªà¸²à¸£à¸—à¸²à¸‡à¸ à¸²à¸©à¸µ (à¸•à¹ˆà¸­ tenant)';


-- ---------------------------------------------------------------------
-- SECTION: PHARMACY CLINICAL  (35 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `appointments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `appointment_type` enum('consultation','video_call','pickup','delivery') DEFAULT 'consultation',
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `duration_minutes` int(11) DEFAULT 30,
  `status` enum('pending','confirmed','completed','cancelled','no_show') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reminder_10min_sent` tinyint(1) DEFAULT 0,
  `reminder_now_sent` tinyint(1) DEFAULT 0,
  `cancelled_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consultation_analytics` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `communication_type` enum('A','B','C') DEFAULT NULL,
  `stage_at_close` varchar(50) DEFAULT NULL,
  `response_time_avg` int(11) DEFAULT NULL COMMENT 'Average response time in seconds',
  `message_count` int(11) DEFAULT NULL,
  `ai_suggestions_shown` int(11) DEFAULT 0,
  `ai_suggestions_accepted` int(11) DEFAULT 0,
  `resulted_in_purchase` tinyint(1) DEFAULT 0,
  `purchase_amount` decimal(12,2) DEFAULT NULL,
  `symptom_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Categories of symptoms discussed' CHECK (json_valid(`symptom_categories`)),
  `drugs_recommended` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Drugs recommended in consultation' CHECK (json_valid(`drugs_recommended`)),
  `successful_patterns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Patterns that led to purchase' CHECK (json_valid(`successful_patterns`)),
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_consultation_analytics_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consultation_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `appointment_id` int(11) NOT NULL,
  `log_type` enum('start','end','note','prescription') DEFAULT 'note',
  `content` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_consultation_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consultation_stages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `stage` enum('symptom_assessment','drug_recommendation','purchase','follow_up') NOT NULL,
  `confidence` decimal(3,2) DEFAULT 0.00,
  `signals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detected signals' CHECK (json_valid(`signals`)),
  `has_urgent_symptoms` tinyint(1) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_consultation_stages_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_health_profiles` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `communication_type` enum('A','B','C') DEFAULT NULL COMMENT 'A=Direct, B=Concerned, C=Detailed',
  `confidence` decimal(3,2) DEFAULT 0.00,
  `chronic_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'List of chronic conditions' CHECK (json_valid(`chronic_conditions`)),
  `communication_tips` text DEFAULT NULL,
  `last_analyzed_at` datetime DEFAULT NULL,
  `message_count_analyzed` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_customer_health_profiles_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dispensing_records` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `order_number` varchar(50) NOT NULL,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items`)),
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'cash',
  `payment_status` varchar(20) DEFAULT 'paid',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `health_article_categories` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'fas fa-folder',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `health_articles` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content` longtext NOT NULL,
  `featured_image` varchar(500) DEFAULT NULL,
  `author_name` varchar(100) DEFAULT NULL,
  `author_title` varchar(100) DEFAULT NULL,
  `author_image` varchar(500) DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `meta_keywords` varchar(500) DEFAULT NULL,
  `view_count` int(11) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `is_published` tinyint(1) DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `triage_session_id` int(11) DEFAULT NULL,
  `symptoms` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`symptoms`)),
  `diagnosis` text DEFAULT NULL,
  `medications_prescribed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`medications_prescribed`)),
  `pharmacist_notes` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_medical_history_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medication_refill_tracking` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity_purchased` int(11) DEFAULT 0,
  `daily_dosage` int(11) DEFAULT 1 COMMENT 'à¸ˆà¸³à¸™à¸§à¸™à¸—à¸µà¹ˆà¸—à¸²à¸™à¸•à¹ˆà¸­à¸§à¸±à¸™ (à¸£à¸§à¸¡à¸—à¸¸à¸à¸¡à¸·à¹‰à¸­)',
  `purchase_date` date DEFAULT NULL,
  `estimated_end_date` date DEFAULT NULL,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL COMMENT 'dispense | order | manual',
  `source_ref_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_end_date` (`estimated_end_date`),
  KEY `idx_product` (`product_id`),
  KEY `idx_user_product` (`user_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medication_reminders` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `medication_name` varchar(255) NOT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `frequency` varchar(50) DEFAULT NULL,
  `reminder_times` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reminder_times`)),
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `product_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medication_taken_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `reminder_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `taken_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('taken','skipped','missed') DEFAULT 'taken',
  `notes` text DEFAULT NULL,
  KEY `idx_medication_taken_history_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacist_consultations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `assessment_id` int(11) DEFAULT NULL,
  `consultation_type` enum('chat','video','phone') DEFAULT 'chat',
  `status` enum('waiting','in_progress','completed','cancelled') DEFAULT 'waiting',
  `notes` text DEFAULT NULL,
  `recommendations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recommendations`)),
  `prescribed_products` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`prescribed_products`)),
  `follow_up_required` tinyint(1) DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacist_holidays` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `pharmacist_id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_pharmacist_holidays_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacist_schedules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `pharmacist_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '0=Sunday, 6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_pharmacist_schedules_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacists` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(50) DEFAULT '',
  `specialty` varchar(255) DEFAULT 'เภสัชกร',
  `sub_specialty` varchar(255) DEFAULT NULL,
  `hospital` varchar(255) DEFAULT NULL,
  `license_no` varchar(100) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `consulting_areas` text DEFAULT NULL,
  `work_experience` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `rating` decimal(2,1) DEFAULT 5.0,
  `review_count` int(11) DEFAULT 0,
  `consultation_fee` decimal(10,2) DEFAULT 0.00,
  `consultation_duration` int(11) DEFAULT 15,
  `is_available` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `license_number` varchar(50) DEFAULT NULL COMMENT 'เลขที่ใบอนุญาตประกอบวิชาชีพเภสัชกรรม',
  `license_expiry` date DEFAULT NULL COMMENT 'วันหมดอายุใบอนุญาต (ต่ออายุทุก 5 ปี)',
  `pharmacy_council_id` varchar(50) DEFAULT NULL COMMENT 'เลขทะเบียนสภาเภสัชกรรม',
  `cpe_credits` int(11) DEFAULT 0 COMMENT 'หน่วยกิตการศึกษาต่อเนื่อง (CPE)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacy_context_keywords` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) NOT NULL,
  `keyword_type` enum('symptom','drug','condition','action') NOT NULL,
  `widget_type` enum('drug_info','interaction','symptom','allergy','pricing','pregnancy') NOT NULL,
  `related_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Related drug IDs, condition info, etc.' CHECK (json_valid(`related_data`)),
  `priority` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescription_approvals` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `approved_items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`approved_items`)),
  `status` enum('pending','approved','rejected','expired','used') DEFAULT 'pending',
  `video_call_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescription_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `prescription_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `drug_name` varchar(255) NOT NULL,
  `strength` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `directions` text NOT NULL COMMENT 'วิธีใช้ยา',
  `dispensed_quantity` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_prescription_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescription_ocr_results` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `image_hash` varchar(64) NOT NULL,
  `image_url` text DEFAULT NULL,
  `extracted_drugs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'List of drugs from prescription' CHECK (json_valid(`extracted_drugs`)),
  `doctor_name` varchar(255) DEFAULT NULL,
  `hospital_name` varchar(255) DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `ocr_confidence` decimal(3,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_prescription_ocr_results_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescription_records` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `prescription_number` varchar(100) NOT NULL,
  `patient_name` varchar(255) NOT NULL,
  `patient_id_card` varchar(20) DEFAULT NULL,
  `doctor_name` varchar(255) NOT NULL,
  `doctor_license` varchar(50) NOT NULL,
  `doctor_signature` text DEFAULT NULL COMMENT 'ลายเซ็นแพทย์ (base64)',
  `prescription_date` date NOT NULL,
  `prescription_image` text DEFAULT NULL COMMENT 'รูปใบสั่งแพทย์ (base64 or URL)',
  `status` enum('pending','verified','dispensed','cancelled') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL COMMENT 'เภสัชกรผู้ตรวจสอบ',
  `verified_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกใบสั่งแพทย์ (เก็บไว้ 5 ปีตามกฎหมาย)';

CREATE TABLE IF NOT EXISTS `red_flag_symptoms` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `symptom_code` varchar(50) NOT NULL,
  `symptom_name_th` varchar(255) NOT NULL COMMENT 'ชื่ออาการภาษาไทย',
  `symptom_name_en` varchar(255) DEFAULT NULL COMMENT 'ชื่ออาการภาษาอังกฤษ',
  `description` text DEFAULT NULL,
  `severity` enum('critical','urgent','warning') DEFAULT 'warning',
  `action_required` text DEFAULT NULL COMMENT 'การดำเนินการที่ต้องทำ',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='อาการที่ต้องส่งต่อแพทย์ (Red Flags)';

CREATE TABLE IF NOT EXISTS `symptom_analysis_cache` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `image_hash` varchar(64) NOT NULL,
  `image_url` text DEFAULT NULL,
  `analysis_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Condition, severity, recommendations' CHECK (json_valid(`analysis_result`)),
  `is_urgent` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  KEY `idx_symptom_analysis_cache_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `symptom_assessment_followups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `assessment_id` int(11) NOT NULL,
  `followup_date` date NOT NULL,
  `status` enum('pending','improved','same','worse','consulted_doctor') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_symptom_assessment_followups_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `symptom_assessments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `symptoms` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`symptoms`)),
  `duration` varchar(100) DEFAULT NULL,
  `severity` int(11) DEFAULT NULL COMMENT '1-10',
  `medical_history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`medical_history`)),
  `allergies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allergies`)),
  `current_medications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`current_medications`)),
  `ai_assessment` text DEFAULT NULL,
  `ai_recommendations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_recommendations`)),
  `triage_level` enum('green','yellow','orange','red') DEFAULT 'green',
  `red_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`red_flags`)),
  `status` enum('in_progress','completed','referred') DEFAULT 'in_progress',
  `pharmacist_id` int(11) DEFAULT NULL,
  `pharmacist_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `triage_analytics` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `total_sessions` int(11) DEFAULT 0,
  `completed_sessions` int(11) DEFAULT 0,
  `escalated_sessions` int(11) DEFAULT 0,
  `urgent_sessions` int(11) DEFAULT 0,
  `avg_completion_time_minutes` decimal(10,2) DEFAULT 0.00,
  `top_symptoms` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`top_symptoms`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `triage_question_responses` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `triage_session_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_value` varchar(255) NOT NULL,
  `answer_text` text DEFAULT NULL,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_triage_question_responses_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  KEY `idx_tqr_session` (`triage_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `triage_questions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `condition_code` varchar(64) NOT NULL,
  `parent_question_id` int(11) DEFAULT NULL,
  `question_th` text NOT NULL,
  `question_en` text DEFAULT NULL,
  `answer_type` enum('yes_no','scale_1_10','multi_choice') NOT NULL DEFAULT 'yes_no',
  `options_json` longtext DEFAULT NULL,
  `next_if_yes` int(11) DEFAULT NULL,
  `next_if_no` int(11) DEFAULT NULL,
  `red_flag_if_yes` tinyint(1) NOT NULL DEFAULT 0,
  `recommend_symptom_codes` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tq_cond_qth` (`condition_code`,`question_th`(191),`line_account_id`),
  KEY `idx_tq_condition` (`condition_code`),
  KEY `idx_tq_active_order` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `triage_sessions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `current_state` varchar(50) DEFAULT 'greeting',
  `triage_data` longtext DEFAULT NULL,
  `status` enum('active','completed','escalated','expired','pending_approval','cancelled') DEFAULT 'active',
  `assessment_id` int(11) DEFAULT NULL,
  `triage_level` enum('green','yellow','orange','red') NOT NULL,
  `chief_complaint` text DEFAULT NULL,
  `vital_signs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`vital_signs`)),
  `red_flags_detected` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`red_flags_detected`)),
  `ai_recommendation` text DEFAULT NULL,
  `pharmacist_action` text DEFAULT NULL,
  `outcome` enum('self_care','otc_recommended','refer_doctor','emergency') DEFAULT 'self_care',
  `follow_up_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `pharmacist_note` text DEFAULT NULL,
  `reject_reason_code` varchar(40) DEFAULT NULL COMMENT 'à¸£à¸«à¸±à¸ªà¹€à¸«à¸•à¸¸à¸œà¸¥à¸›à¸à¸´à¹€à¸ªà¸˜ (unsuitable_med, expired, customer_cancel, invalid_rx, other)',
  `reject_reason_detail` text DEFAULT NULL COMMENT 'à¸£à¸²à¸¢à¸¥à¸°à¹€à¸­à¸µà¸¢à¸”à¹€à¸«à¸•à¸¸à¸œà¸¥à¸›à¸à¸´à¹€à¸ªà¸˜ (free-text à¸ˆà¸²à¸à¹€à¸ à¸ªà¸±à¸Šà¸à¸£)',
  `reject_attachment_url` varchar(500) DEFAULT NULL COMMENT 'URL à¸£à¸¹à¸›à¹à¸™à¸š (à¸£à¸¹à¸›à¸¢à¸²à¹€à¸ªà¸µà¸¢, à¹ƒà¸šà¸ªà¸±à¹ˆà¸‡à¸¢à¸²à¸—à¸µà¹ˆà¸ªà¸‡à¸ªà¸±à¸¢)',
  `rejected_at` timestamp NULL DEFAULT NULL COMMENT 'à¹€à¸§à¸¥à¸²à¸—à¸µà¹ˆà¸›à¸à¸´à¹€à¸ªà¸˜',
  `rejected_by` int(11) DEFAULT NULL COMMENT 'admin_users.id à¸‚à¸­à¸‡à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸—à¸µà¹ˆà¸›à¸à¸´à¹€à¸ªà¸˜',
  PRIMARY KEY (`id`),
  KEY `idx_reject_reason` (`reject_reason_code`,`rejected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_current_medications` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `line_account_id` int(11) DEFAULT 0,
  `medication_name` varchar(255) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `frequency` varchar(100) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_drug_allergies` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `line_account_id` int(11) DEFAULT 0,
  `drug_name` varchar(255) NOT NULL,
  `drug_id` int(11) DEFAULT NULL,
  `reaction_type` enum('rash','breathing','swelling','other') DEFAULT 'other',
  `reaction_notes` text DEFAULT NULL,
  `severity` enum('mild','moderate','severe') DEFAULT 'moderate',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_health_profiles` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `line_account_id` int(11) DEFAULT 0,
  `name` varchar(255) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `blood_type` enum('A','B','AB','O','unknown') DEFAULT 'unknown',
  `medical_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`medical_conditions`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`line_user_id`,`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `video_call_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `auto_answer` tinyint(1) DEFAULT 0,
  `max_duration` int(11) DEFAULT 3600 COMMENT 'Max call duration in seconds',
  `working_hours_start` time DEFAULT '09:00:00',
  `working_hours_end` time DEFAULT '18:00:00',
  `offline_message` text DEFAULT 'ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลาทำการ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `video_call_signals` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `call_id` int(11) NOT NULL,
  `signal_type` varchar(50) NOT NULL COMMENT 'offer, answer, ice-candidate',
  `signal_data` longtext NOT NULL,
  `from_who` varchar(20) DEFAULT 'customer',
  `processed` tinyint(1) DEFAULT 0,
  `sender_type` enum('admin','customer') DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_video_call_signals_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `video_calls` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `picture_url` varchar(500) DEFAULT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `room_id` varchar(100) DEFAULT NULL,
  `status` enum('pending','ringing','active','reconnecting','completed','ended','rejected','error','timeout') DEFAULT 'pending',
  `duration` int(11) DEFAULT 0 COMMENT 'Duration in seconds',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: ODOO INTEGRATION  (38 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `odoo_activity_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) DEFAULT NULL COMMENT 'User who performed the action',
  `action` varchar(100) NOT NULL COMMENT 'Action type (view, update, retry, etc.)',
  `entity_type` varchar(50) DEFAULT NULL COMMENT 'Type of entity (order, customer, webhook)',
  `entity_id` varchar(100) DEFAULT NULL COMMENT 'Entity identifier',
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional action details' CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'User IP address',
  `user_agent` text DEFAULT NULL COMMENT 'User browser/client',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'When action occurred',
  KEY `idx_odoo_activity_log_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all dashboard activities';

CREATE TABLE IF NOT EXISTS `odoo_api_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL COMMENT 'Reference to line_accounts table',
  `endpoint` varchar(255) NOT NULL COMMENT 'API endpoint called',
  `method` varchar(10) DEFAULT 'POST' COMMENT 'HTTP method',
  `request_params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Request parameters' CHECK (json_valid(`request_params`)),
  `response_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Response data' CHECK (json_valid(`response_data`)),
  `status_code` int(11) DEFAULT NULL COMMENT 'HTTP status code',
  `error_message` text DEFAULT NULL COMMENT 'Error message if failed',
  `duration_ms` int(11) DEFAULT NULL COMMENT 'Request duration in milliseconds',
  `created_at` datetime DEFAULT current_timestamp() COMMENT 'When API call was made',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Optional: Logs all API calls to Odoo for debugging';

CREATE TABLE IF NOT EXISTS `odoo_bdo_context` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `line_user_id` varchar(64) NOT NULL,
  `bdo_id` int(11) NOT NULL,
  `bdo_name` varchar(64) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `delivery_type` varchar(20) DEFAULT NULL COMMENT 'company or private',
  `state` varchar(20) DEFAULT 'waiting',
  `qr_payload` text DEFAULT NULL COMMENT 'PromptPay QR raw payload',
  `statement_pdf_path` varchar(255) DEFAULT NULL,
  `webhook_delivery_id` varchar(128) DEFAULT NULL COMMENT 'delivery_id of bdo.confirmed webhook',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `financial_summary_json` mediumtext DEFAULT NULL COMMENT 'Full financial breakdown JSON from bdo.confirmed',
  `selected_invoices_json` text DEFAULT NULL COMMENT 'selected_invoices array from financial_summary',
  `selected_credit_notes_json` text DEFAULT NULL COMMENT 'selected_credit_notes array from financial_summary',
  KEY `idx_odoo_bdo_context_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_bdo_orders` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `bdo_id` int(11) NOT NULL COMMENT 'Odoo BDO ID',
  `bdo_name` varchar(64) DEFAULT NULL COMMENT 'BDO number e.g. BDO2603-00439',
  `order_id` int(11) NOT NULL COMMENT 'Odoo Sale Order ID',
  `order_name` varchar(64) DEFAULT NULL COMMENT 'SO number e.g. SO2603-06523',
  `amount_total` decimal(14,2) DEFAULT NULL COMMENT 'BDO total amount',
  `payment_reference` varchar(128) DEFAULT NULL COMMENT 'ref for slip matching (= bdo_name)',
  `partner_id` int(11) DEFAULT NULL COMMENT 'Odoo partner ID',
  `customer_name` varchar(255) DEFAULT NULL COMMENT 'Customer display name',
  `line_user_id` varchar(64) DEFAULT NULL COMMENT 'LINE user ID',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'promptpay / bank_transfer',
  `payment_status` enum('pending','slip_uploaded','matched','paid') DEFAULT 'pending' COMMENT 'Payment workflow status',
  `slip_upload_id` int(11) DEFAULT NULL COMMENT 'FK to odoo_slip_uploads.id',
  `webhook_delivery_id` varchar(128) DEFAULT NULL COMMENT 'delivery_id of source webhook',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_bdo_orders_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links BDO to Sale Orders (many-to-many) with payment tracking';

CREATE TABLE IF NOT EXISTS `odoo_bdos` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `bdo_id` int(10) unsigned NOT NULL,
  `bdo_name` varchar(100) NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `order_name` varchar(100) DEFAULT NULL,
  `partner_id` int(10) unsigned DEFAULT NULL,
  `customer_ref` varchar(50) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `salesperson_id` int(10) unsigned DEFAULT NULL,
  `salesperson_name` varchar(200) DEFAULT NULL,
  `state` varchar(50) DEFAULT 'confirmed',
  `amount_total` decimal(14,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'THB',
  `bdo_date` date DEFAULT NULL,
  `expected_delivery` date DEFAULT NULL,
  `latest_event` varchar(100) DEFAULT NULL,
  `webhook_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `synced_at` timestamp NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'promptpay / bank_transfer',
  `payment_reference` varchar(128) DEFAULT NULL COMMENT 'ref for slip matching (= bdo_name)',
  `payment_status` enum('pending','slip_uploaded','matched','paid') DEFAULT 'pending' COMMENT 'Payment workflow status',
  `qr_data` text DEFAULT NULL COMMENT 'PromptPay QR raw payload',
  `payment_state` varchar(64) DEFAULT NULL,
  `amount_net_to_pay` decimal(14,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  KEY `idx_odoo_bdos_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_circuit_breaker_state` (

  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `service_name` varchar(64) NOT NULL,
  `status` enum('closed','open','half_open') NOT NULL DEFAULT 'closed',
  `consecutive_failures` smallint(5) unsigned NOT NULL DEFAULT 0,
  `opened_at` int(10) unsigned DEFAULT NULL COMMENT 'unix timestamp ที่ circuit เปิด',
  `half_open_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_failure_at` int(10) unsigned DEFAULT NULL,
  `last_success_at` int(10) unsigned DEFAULT NULL,
  `last_error` varchar(200) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_circuit_breaker_state_la` (`line_account_id`),
  PRIMARY KEY (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shared Odoo circuit breaker state — PHP + Node.js stacks';

CREATE TABLE IF NOT EXISTS `odoo_customer_product_stats` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `line_user_id` varchar(100) NOT NULL,
  `odoo_partner_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `qty_30d` decimal(14,2) NOT NULL DEFAULT 0.00,
  `qty_90d` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_30d` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_90d` decimal(14,2) NOT NULL DEFAULT 0.00,
  `last_purchased_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_customer_product_stats_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_customer_projection` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `line_user_id` varchar(100) NOT NULL,
  `odoo_partner_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_ref` varchar(100) DEFAULT NULL,
  `credit_limit` decimal(14,2) DEFAULT NULL,
  `credit_used` decimal(14,2) DEFAULT NULL,
  `credit_remaining` decimal(14,2) DEFAULT NULL,
  `total_due` decimal(14,2) DEFAULT NULL,
  `overdue_amount` decimal(14,2) DEFAULT NULL,
  `latest_order_id` int(11) DEFAULT NULL,
  `latest_order_name` varchar(120) DEFAULT NULL,
  `latest_order_at` datetime DEFAULT NULL,
  `orders_count_30d` int(10) unsigned NOT NULL DEFAULT 0,
  `orders_count_90d` int(10) unsigned NOT NULL DEFAULT 0,
  `spend_30d` decimal(14,2) NOT NULL DEFAULT 0.00,
  `spend_90d` decimal(14,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_customer_projection_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_customers_cache` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` varchar(50) DEFAULT NULL COMMENT 'Odoo customer ID',
  `partner_id` varchar(50) DEFAULT NULL COMMENT 'Odoo partner ID',
  `customer_name` varchar(255) NOT NULL,
  `customer_ref` varchar(100) DEFAULT NULL COMMENT 'Customer code/reference',
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `street` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `line_display_name` varchar(255) DEFAULT NULL,
  `salesperson_id` varchar(50) DEFAULT NULL,
  `salesperson_name` varchar(255) DEFAULT NULL,
  `credit_limit` decimal(14,2) DEFAULT 0.00,
  `total_due` decimal(14,2) DEFAULT 0.00,
  `overdue_amount` decimal(14,2) DEFAULT 0.00,
  `trust_level` varchar(20) DEFAULT 'normal',
  `orders_count_total` int(10) unsigned DEFAULT 0,
  `orders_count_30d` int(10) unsigned DEFAULT 0,
  `spend_total` decimal(14,2) DEFAULT 0.00,
  `spend_30d` decimal(14,2) DEFAULT 0.00,
  `first_order_at` timestamp NULL DEFAULT NULL,
  `latest_order_at` timestamp NULL DEFAULT NULL,
  `last_invoice_at` timestamp NULL DEFAULT NULL,
  `last_payment_at` timestamp NULL DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `line_account_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Denormalized customer data for fast dashboard queries';

CREATE TABLE IF NOT EXISTS `odoo_daily_summary` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `summary_date` date NOT NULL,
  `total_orders` int(11) DEFAULT 0,
  `total_order_amount` decimal(15,2) DEFAULT 0.00,
  `total_invoices` int(11) DEFAULT 0,
  `total_invoice_amount` decimal(15,2) DEFAULT 0.00,
  `total_outstanding` decimal(15,2) DEFAULT 0.00,
  `total_payments` int(11) DEFAULT 0,
  `total_payment_amount` decimal(15,2) DEFAULT 0.00,
  `cash_amount` decimal(15,2) DEFAULT 0.00,
  `bank_transfer_amount` decimal(15,2) DEFAULT 0.00,
  `promptpay_amount` decimal(15,2) DEFAULT 0.00,
  `credit_card_amount` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_daily_summary_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_daily_summary_auto_log` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `execution_date` date NOT NULL COMMENT 'Date this auto-send was executed',
  `execution_time` datetime NOT NULL COMMENT 'Actual execution timestamp',
  `scheduled_time` time NOT NULL COMMENT 'Configured scheduled time',
  `total_recipients` int(11) DEFAULT 0 COMMENT 'Total users eligible',
  `sent_count` int(11) DEFAULT 0 COMMENT 'Successfully sent',
  `failed_count` int(11) DEFAULT 0 COMMENT 'Failed to send',
  `skipped_count` int(11) DEFAULT 0 COMMENT 'Skipped (already sent today)',
  `execution_duration_ms` int(11) DEFAULT 0 COMMENT 'Execution time in milliseconds',
  `status` enum('success','partial','failed') DEFAULT 'success',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_daily_summary_auto_log_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Execution log for automated daily summary sends';

CREATE TABLE IF NOT EXISTS `odoo_daily_summary_settings` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `setting_key` varchar(100) NOT NULL COMMENT 'Setting identifier',
  `setting_value` text DEFAULT NULL COMMENT 'Setting value (JSON or plain text)',
  `enabled` tinyint(1) DEFAULT 1 COMMENT 'Whether this setting is active',
  `updated_by` varchar(100) DEFAULT NULL COMMENT 'Admin user who last updated',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_daily_summary_settings_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Settings for automated daily summary notifications';

CREATE TABLE IF NOT EXISTS `odoo_dashboard_cache_meta` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `cache_key` varchar(100) NOT NULL COMMENT 'e.g., overview_kpi, customer_list',
  `cache_type` varchar(50) NOT NULL COMMENT 'kpi/list/chart/summary',
  `data_checksum` varchar(64) DEFAULT NULL COMMENT 'SHA-256 for change detection',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_webhook_at` timestamp NULL DEFAULT NULL COMMENT 'Last webhook that triggered update',
  `record_count` int(10) unsigned DEFAULT 0,
  `sync_duration_ms` int(10) unsigned DEFAULT 0,
  `is_dirty` tinyint(1) DEFAULT 1 COMMENT '1 = needs refresh',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_dashboard_cache_meta_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Metadata for tracking cache freshness';

CREATE TABLE IF NOT EXISTS `odoo_invoices` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `invoice_id` int(10) unsigned NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `order_name` varchar(100) DEFAULT NULL,
  `partner_id` int(10) unsigned DEFAULT NULL,
  `customer_ref` varchar(50) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `salesperson_id` int(10) unsigned DEFAULT NULL,
  `salesperson_name` varchar(200) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `invoice_state` varchar(50) DEFAULT NULL,
  `payment_state` varchar(50) DEFAULT NULL,
  `amount_total` decimal(14,2) DEFAULT 0.00,
  `amount_tax` decimal(14,2) DEFAULT 0.00,
  `amount_untaxed` decimal(14,2) DEFAULT 0.00,
  `amount_residual` decimal(14,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'THB',
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `payment_term` varchar(100) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT 0,
  `is_overdue` tinyint(1) DEFAULT 0,
  `pdf_url` varchar(500) DEFAULT NULL,
  `latest_event` varchar(100) DEFAULT NULL,
  `webhook_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `synced_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_invoices_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_invoices_cache` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_id` varchar(50) DEFAULT NULL COMMENT 'Odoo invoice ID',
  `order_key` varchar(100) DEFAULT NULL,
  `order_id` varchar(50) DEFAULT NULL,
  `customer_id` varchar(50) DEFAULT NULL,
  `partner_id` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `amount_total` decimal(14,2) DEFAULT 0.00,
  `amount_residual` decimal(14,2) DEFAULT 0.00,
  `amount_paid` decimal(14,2) DEFAULT 0.00,
  `state` varchar(50) DEFAULT 'draft' COMMENT 'draft/open/posted/paid/overdue',
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `payment_state` varchar(50) DEFAULT NULL,
  `is_overdue` tinyint(1) DEFAULT 0,
  `days_overdue` int(11) DEFAULT 0,
  `line_user_id` varchar(100) DEFAULT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `line_account_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Denormalized invoice data for dashboard';

CREATE TABLE IF NOT EXISTS `odoo_line_users` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL COMMENT 'Reference to line_accounts table',
  `line_user_id` varchar(100) NOT NULL COMMENT 'LINE user ID (U...)',
  `odoo_partner_id` int(11) NOT NULL COMMENT 'Odoo partner ID',
  `odoo_partner_name` varchar(255) DEFAULT NULL COMMENT 'Partner name from Odoo',
  `odoo_customer_code` varchar(100) DEFAULT NULL COMMENT 'Customer code from Odoo',
  `odoo_phone` varchar(50) DEFAULT NULL,
  `odoo_email` varchar(255) DEFAULT NULL,
  `linked_via` enum('phone','email','customer_code') NOT NULL COMMENT 'Method used to link account',
  `line_notification_enabled` tinyint(1) DEFAULT 1 COMMENT 'Enable/disable LINE notifications',
  `linked_at` datetime DEFAULT current_timestamp() COMMENT 'When account was linked',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Last update timestamp',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links LINE users with Odoo partner accounts';

CREATE TABLE IF NOT EXISTS `odoo_manual_overrides` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `entity_type` enum('order','invoice') NOT NULL,
  `entity_ref` varchar(100) NOT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `reason` text NOT NULL,
  `admin_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_manual_overrides_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_notification_batch_groups` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `batch_group_id` varchar(100) NOT NULL COMMENT 'Unique batch group ID',
  `order_id` int(11) NOT NULL COMMENT 'Odoo order ID',
  `order_ref` varchar(120) DEFAULT NULL COMMENT 'Order reference',
  `line_user_id` varchar(100) NOT NULL COMMENT 'LINE user ID',
  `event_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of event types in this batch' CHECK (json_valid(`event_types`)),
  `event_count` int(11) DEFAULT 0 COMMENT 'Number of events collected',
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Full event data for all events' CHECK (json_valid(`event_data`)),
  `first_event_at` datetime NOT NULL COMMENT 'First event timestamp',
  `last_event_at` datetime NOT NULL COMMENT 'Last event timestamp',
  `status` enum('collecting','ready','sent','expired') DEFAULT 'collecting' COMMENT 'Batch status',
  `milestone_reached` tinyint(1) DEFAULT 0 COMMENT 'Has milestone been reached',
  `milestone_event` varchar(100) DEFAULT NULL COMMENT 'Milestone event that triggered send',
  `window_expires_at` datetime NOT NULL COMMENT 'When batch window expires',
  `sent_at` datetime DEFAULT NULL COMMENT 'When roadmap was sent',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_notification_batch_groups_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Batch groups for roadmap notifications';

CREATE TABLE IF NOT EXISTS `odoo_notification_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `delivery_id` varchar(100) NOT NULL COMMENT 'Webhook delivery ID',
  `event_type` varchar(100) NOT NULL COMMENT 'Event type',
  `queue_id` int(11) DEFAULT NULL COMMENT 'Reference to notification_queue.id',
  `recipient_type` enum('customer','salesperson') NOT NULL COMMENT 'Recipient type',
  `line_user_id` varchar(100) NOT NULL COMMENT 'LINE user ID',
  `notification_method` enum('text','flex','roadmap') NOT NULL COMMENT 'Notification format',
  `message_preview` text DEFAULT NULL COMMENT 'Message preview/summary',
  `status` enum('sent','failed','skipped') NOT NULL COMMENT 'Send status',
  `line_api_status` int(11) DEFAULT NULL COMMENT 'LINE API HTTP status code',
  `line_api_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'LINE API response' CHECK (json_valid(`line_api_response`)),
  `error_message` text DEFAULT NULL COMMENT 'Error message if failed',
  `skip_reason` varchar(255) DEFAULT NULL COMMENT 'Reason if skipped',
  `sent_at` datetime DEFAULT current_timestamp() COMMENT 'When notification was sent/attempted',
  `latency_ms` int(11) DEFAULT NULL COMMENT 'Processing latency in milliseconds',
  KEY `idx_odoo_notification_log_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for all notification attempts';

CREATE TABLE IF NOT EXISTS `odoo_notification_preferences` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `line_user_id` varchar(100) NOT NULL COMMENT 'LINE user ID',
  `event_type` varchar(100) NOT NULL COMMENT 'Event type (e.g., order.validated)',
  `enabled` tinyint(1) DEFAULT 1 COMMENT 'Enable/disable notification for this event',
  `notification_method` enum('text','flex','none') DEFAULT 'flex' COMMENT 'Notification format',
  `batch_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable batching for this event',
  `batch_window_seconds` int(11) DEFAULT 300 COMMENT 'Batch collection window in seconds',
  `batch_milestone_events` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Events that trigger batch send (JSON array)' CHECK (json_valid(`batch_milestone_events`)),
  `quiet_hours_enabled` tinyint(1) DEFAULT 0 COMMENT 'Enable quiet hours',
  `quiet_hours_start` time DEFAULT NULL COMMENT 'Quiet hours start time',
  `quiet_hours_end` time DEFAULT NULL COMMENT 'Quiet hours end time',
  `quiet_hours_action` enum('skip','queue','silent') DEFAULT 'queue' COMMENT 'Action during quiet hours',
  `priority` enum('high','medium','low') DEFAULT 'medium' COMMENT 'Notification priority',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_notification_preferences_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User notification preferences and settings';

CREATE TABLE IF NOT EXISTS `odoo_notification_queue` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_id` varchar(100) NOT NULL COMMENT 'Webhook delivery ID',
  `event_type` varchar(100) NOT NULL COMMENT 'Event type',
  `order_id` int(11) DEFAULT NULL COMMENT 'Odoo order ID',
  `order_ref` varchar(120) DEFAULT NULL COMMENT 'Order reference',
  `recipient_type` enum('customer','salesperson') NOT NULL COMMENT 'Recipient type',
  `line_user_id` varchar(100) NOT NULL COMMENT 'LINE user ID',
  `line_account_id` int(11) DEFAULT NULL COMMENT 'LINE account ID',
  `message_type` enum('text','flex','roadmap') NOT NULL COMMENT 'Message format',
  `message_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Message content (JSON)' CHECK (json_valid(`message_payload`)),
  `alt_text` varchar(400) DEFAULT NULL COMMENT 'Alternative text for notification',
  `batch_group_id` varchar(100) DEFAULT NULL COMMENT 'Batch group ID for roadmap',
  `is_batched` tinyint(1) DEFAULT 0 COMMENT 'Is part of batch',
  `priority` tinyint(4) DEFAULT 5 COMMENT 'Priority (1=highest, 10=lowest)',
  `scheduled_at` datetime DEFAULT current_timestamp() COMMENT 'When to send',
  `expires_at` datetime DEFAULT NULL COMMENT 'Expiration time',
  `status` enum('pending','processing','sent','failed','expired','cancelled') DEFAULT 'pending' COMMENT 'Queue status',
  `retry_count` int(11) DEFAULT 0 COMMENT 'Number of retry attempts',
  `max_retries` int(11) DEFAULT 3 COMMENT 'Maximum retry attempts',
  `sent_at` datetime DEFAULT NULL COMMENT 'When notification was sent',
  `line_api_status` int(11) DEFAULT NULL COMMENT 'LINE API HTTP status code',
  `line_api_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'LINE API response' CHECK (json_valid(`line_api_response`)),
  `error_message` text DEFAULT NULL COMMENT 'Error message if failed',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notification queue for async processing';

CREATE TABLE IF NOT EXISTS `odoo_notification_templates` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `template_code` varchar(100) NOT NULL COMMENT 'Template code (e.g., roadmap_timeline)',
  `event_type` varchar(100) NOT NULL COMMENT 'Event type',
  `recipient_type` enum('customer','salesperson','both') NOT NULL COMMENT 'Recipient type',
  `language` varchar(10) DEFAULT 'th' COMMENT 'Language code',
  `template_type` enum('text','flex','roadmap') NOT NULL COMMENT 'Template format',
  `template_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Template structure (JSON)' CHECK (json_valid(`template_content`)),
  `alt_text_template` varchar(400) DEFAULT NULL COMMENT 'Alternative text template',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Is template active',
  `version` int(11) DEFAULT 1 COMMENT 'Template version',
  `description` text DEFAULT NULL COMMENT 'Template description',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_notification_templates_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notification message templates';

CREATE TABLE IF NOT EXISTS `odoo_order_events` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_key` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL COMMENT 'sale.order.confirmed, etc.',
  `event_category` varchar(50) NOT NULL COMMENT 'order/delivery/invoice/payment',
  `status` varchar(50) DEFAULT 'success' COMMENT 'Webhook processing status',
  `old_state` varchar(50) DEFAULT NULL,
  `new_state` varchar(50) DEFAULT NULL,
  `payload_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Key payload fields only' CHECK (json_valid(`payload_summary`)),
  `webhook_log_id` int(10) unsigned DEFAULT NULL COMMENT 'Reference to odoo_webhooks_log',
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_order_events_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Order event timeline for fast retrieval';

CREATE TABLE IF NOT EXISTS `odoo_order_lines` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `odoo_order_id` int(11) NOT NULL,
  `odoo_line_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `product_code` varchar(50) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `uom_name` varchar(50) DEFAULT NULL,
  `price_unit` decimal(15,2) DEFAULT 0.00,
  `discount` decimal(5,2) DEFAULT 0.00,
  `price_subtotal` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_order_lines_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_order_notes` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `entity_type` enum('order','invoice') NOT NULL,
  `entity_ref` varchar(100) NOT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `note` text NOT NULL,
  `admin_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_order_notes_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_order_projection` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_id` int(11) NOT NULL,
  `order_name` varchar(120) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `odoo_partner_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_ref` varchar(100) DEFAULT NULL,
  `latest_event_type` varchar(100) DEFAULT NULL,
  `latest_state` varchar(100) DEFAULT NULL,
  `latest_state_display` varchar(150) DEFAULT NULL,
  `amount_total` decimal(14,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'THB',
  `source_delivery_id` varchar(100) DEFAULT NULL,
  `source_status` varchar(50) DEFAULT NULL,
  `last_webhook_at` datetime DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_order_projection_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_order_states` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `odoo_order_id` int(11) NOT NULL,
  `state` varchar(50) NOT NULL,
  `state_display` varchar(100) DEFAULT NULL,
  `state_type` varchar(50) DEFAULT NULL,
  `assignee_id` int(11) DEFAULT NULL,
  `assignee_name` varchar(255) DEFAULT NULL,
  `assignee_type` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_order_states_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_order_status_overrides` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_id` varchar(100) NOT NULL COMMENT 'Odoo order reference/name',
  `user_id` int(11) NOT NULL COMMENT 'User who made the override',
  `old_status` varchar(50) DEFAULT NULL COMMENT 'Previous status value',
  `new_status` varchar(50) NOT NULL COMMENT 'New status value',
  `reason` text DEFAULT NULL COMMENT 'Reason for status override',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'When override was made',
  KEY `idx_odoo_order_status_overrides_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks manual status overrides for orders';

CREATE TABLE IF NOT EXISTS `odoo_orders` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_id` int(10) unsigned NOT NULL COMMENT 'Odoo sale.order ID',
  `order_name` varchar(100) NOT NULL COMMENT 'SO number',
  `partner_id` int(10) unsigned DEFAULT NULL COMMENT 'Odoo partner ID',
  `customer_ref` varchar(50) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `salesperson_id` int(10) unsigned DEFAULT NULL,
  `salesperson_name` varchar(200) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `state_display` varchar(100) DEFAULT NULL,
  `amount_total` decimal(14,2) DEFAULT 0.00,
  `amount_tax` decimal(14,2) DEFAULT 0.00,
  `amount_untaxed` decimal(14,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'THB',
  `date_order` datetime DEFAULT NULL,
  `expected_delivery` date DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `payment_status` varchar(50) DEFAULT NULL,
  `delivery_status` varchar(50) DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT 0,
  `is_delivered` tinyint(1) DEFAULT 0,
  `items_count` int(11) DEFAULT 0,
  `latest_event` varchar(100) DEFAULT NULL,
  `webhook_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `synced_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_orders_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_orders_summary` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_key` varchar(100) NOT NULL COMMENT 'Order name/number from Odoo',
  `order_id` int(10) unsigned DEFAULT NULL COMMENT 'Internal order_id from webhook',
  `odoo_order_id` varchar(50) DEFAULT NULL COMMENT 'Odoo order ID',
  `customer_id` varchar(50) DEFAULT NULL COMMENT 'Customer ID from Odoo',
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_ref` varchar(100) DEFAULT NULL COMMENT 'Customer reference code',
  `partner_id` varchar(50) DEFAULT NULL COMMENT 'Odoo partner ID',
  `salesperson_id` varchar(50) DEFAULT NULL,
  `salesperson_name` varchar(255) DEFAULT NULL,
  `amount_total` decimal(14,2) DEFAULT 0.00,
  `amount_tax` decimal(14,2) DEFAULT 0.00,
  `amount_untaxed` decimal(14,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'THB',
  `state` varchar(50) DEFAULT 'draft' COMMENT 'Order state from Odoo',
  `state_display` varchar(100) DEFAULT NULL COMMENT 'Human readable state',
  `delivery_type` varchar(20) DEFAULT NULL COMMENT 'company/private',
  `invoice_status` varchar(50) DEFAULT NULL,
  `payment_status` varchar(50) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL COMMENT 'LINE user for notifications',
  `first_event_at` timestamp NULL DEFAULT NULL COMMENT 'First webhook event time',
  `last_event_at` timestamp NULL DEFAULT NULL COMMENT 'Latest webhook event time',
  `created_at_odoo` timestamp NULL DEFAULT NULL COMMENT 'Order creation time in Odoo',
  `date_order` date DEFAULT NULL COMMENT 'Order date',
  `expected_delivery_date` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `sync_status` varchar(20) DEFAULT 'pending' COMMENT 'pending/synced/error',
  `synced_at` timestamp NULL DEFAULT NULL,
  `line_account_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Denormalized order data from webhooks for fast dashboard queries';

CREATE TABLE IF NOT EXISTS `odoo_payments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `odoo_payment_id` int(11) NOT NULL,
  `payment_name` varchar(50) DEFAULT NULL,
  `odoo_invoice_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `odoo_order_id` int(11) DEFAULT NULL,
  `order_name` varchar(50) DEFAULT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `partner_name` varchar(255) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT 'draft',
  `state_display` varchar(50) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'THB',
  `method` varchar(50) DEFAULT NULL,
  `method_display` varchar(50) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `slip_image_url` text DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(100) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_odoo_payments_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_products_sync_state` (

  `line_account_id` int(11) NOT NULL,
  `next_offset` int(11) NOT NULL DEFAULT 1,
  `last_incremental_sync_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_slip_uploads` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL COMMENT 'Reference to line_accounts table',
  `line_user_id` varchar(100) NOT NULL COMMENT 'LINE user ID who uploaded slip',
  `odoo_slip_id` int(11) DEFAULT NULL COMMENT 'Slip ID from Odoo',
  `odoo_partner_id` int(11) DEFAULT NULL COMMENT 'Odoo partner ID',
  `bdo_id` int(11) DEFAULT NULL COMMENT 'Bank Deposit Order ID (if matched)',
  `invoice_id` int(11) DEFAULT NULL COMMENT 'Invoice ID (if matched)',
  `order_id` int(11) DEFAULT NULL COMMENT 'Order ID (if matched)',
  `amount` decimal(10,2) DEFAULT NULL COMMENT 'Payment amount from slip',
  `transfer_date` date DEFAULT NULL COMMENT 'Transfer date from slip',
  `image_path` varchar(500) DEFAULT NULL COMMENT 'Local file path to saved slip image',
  `image_url` varchar(500) DEFAULT NULL COMMENT 'Original image URL from LINE/inbox',
  `uploaded_by` varchar(100) DEFAULT NULL COMMENT 'Admin who uploaded the slip',
  `message_id` int(11) DEFAULT NULL COMMENT 'Reference to messages table',
  `slip_verified` tinyint(1) DEFAULT NULL COMMENT 'null=not checked, 1=verified, 0=failed',
  `slip_verify_ref` varchar(100) DEFAULT NULL COMMENT 'Transaction reference from SlipMate',
  `slip_verify_amount` decimal(12,2) DEFAULT NULL COMMENT 'Amount verified by SlipMate',
  `slip_verify_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Full SlipData payload from SlipMate' CHECK (json_valid(`slip_verify_data`)),
  `slip_verified_at` datetime DEFAULT NULL COMMENT 'When verification was performed',
  `status` enum('new','pending','matched','payment_created','posted','done','failed') DEFAULT 'new' COMMENT 'Matching status',
  `match_reason` text DEFAULT NULL COMMENT 'Reason for match/fail',
  `uploaded_at` datetime DEFAULT current_timestamp() COMMENT 'When slip was uploaded',
  `matched_at` datetime DEFAULT NULL COMMENT 'When slip was matched',
  `match_confidence` varchar(30) DEFAULT NULL COMMENT 'exact, partial, multi, bdo_prepayment, manual, unmatched',
  `bdo_name` varchar(64) DEFAULT NULL COMMENT 'BDO number e.g. BDO2511-01778',
  `delivery_type` varchar(20) DEFAULT NULL COMMENT 'company or private',
  `bdo_amount` decimal(14,2) DEFAULT NULL COMMENT 'Net to pay amount from BDO',
  `slip_inbox_id` int(11) DEFAULT NULL COMMENT 'Odoo Slip Inbox record ID',
  `slip_inbox_name` varchar(64) DEFAULT NULL COMMENT 'Slip number e.g. SLIP-2603-00111',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks payment slip uploads and auto-matching results';

CREATE TABLE IF NOT EXISTS `odoo_slips_cache` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slip_id` varchar(100) NOT NULL COMMENT 'Internal slip ID',
  `order_key` varchar(100) DEFAULT NULL,
  `bdo_id` varchar(50) DEFAULT NULL COMMENT 'BDO/outstanding ID',
  `customer_id` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT 0.00,
  `matched_amount` decimal(14,2) DEFAULT 0.00,
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `bank_ref` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending' COMMENT 'pending/matched/rejected',
  `confidence` varchar(50) DEFAULT 'unmatched' COMMENT 'exact/partial/manual/unmatched',
  `matched_at` timestamp NULL DEFAULT NULL,
  `matched_by` varchar(100) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `line_account_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment slips cache for dashboard';

CREATE TABLE IF NOT EXISTS `odoo_sync_log` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(50) NOT NULL COMMENT 'orders/customers/invoices/slips/full',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `records_processed` int(10) unsigned DEFAULT 0,
  `records_inserted` int(10) unsigned DEFAULT 0,
  `records_updated` int(10) unsigned DEFAULT 0,
  `execution_duration_ms` int(10) unsigned DEFAULT 0,
  `records_failed` int(10) unsigned DEFAULT 0,
  `status` varchar(20) DEFAULT 'running' COMMENT 'running/success/failed',
  `error_message` text DEFAULT NULL,
  `triggered_by` varchar(50) DEFAULT 'cron' COMMENT 'cron/webhook/manual',
  `line_account_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sync job execution log';

CREATE TABLE IF NOT EXISTS `odoo_webhook_dlq` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `delivery_id` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `error_code` varchar(64) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `retry_count` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `webhook_log_id` int(11) DEFAULT NULL,
  `last_retry_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_webhook_dlq_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_webhook_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `event_type` varchar(100) NOT NULL,
  `event_id` varchar(100) DEFAULT NULL,
  `odoo_order_id` int(11) DEFAULT NULL,
  `odoo_invoice_id` int(11) DEFAULT NULL,
  `odoo_payment_id` int(11) DEFAULT NULL,
  `odoo_bdo_id` int(11) DEFAULT NULL,
  `line_user_id` varchar(100) DEFAULT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `processed` tinyint(1) DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_odoo_webhook_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `odoo_webhooks_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'Reference to line_accounts table (nullable for shared mode)',
  `delivery_id` varchar(100) NOT NULL COMMENT 'X-Odoo-Delivery-Id header for idempotency',
  `event_type` varchar(100) NOT NULL COMMENT 'Webhook event type (e.g., order.validated)',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Full webhook payload' CHECK (json_valid(`payload`)),
  `payload_hash` char(64) DEFAULT NULL COMMENT 'SHA256 hash of payload for forensic checks',
  `signature` varchar(255) DEFAULT NULL COMMENT 'X-Odoo-Signature header',
  `received_at` datetime DEFAULT NULL COMMENT 'When webhook was initially received',
  `processing_started_at` datetime DEFAULT NULL COMMENT 'When processing started',
  `retry_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Retry attempts for this delivery',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Total receive attempts for this delivery_id',
  `process_latency_ms` int(10) unsigned DEFAULT NULL COMMENT 'End-to-end processing latency (ms)',
  `processed_at` datetime DEFAULT current_timestamp() COMMENT 'When webhook was processed',
  `status` enum('received','processing','success','failed','duplicate','retry','dead_letter') NOT NULL DEFAULT 'received' COMMENT 'Webhook lifecycle status',
  `error_message` text DEFAULT NULL COMMENT 'Error details if failed',
  `last_error_code` varchar(64) DEFAULT NULL COMMENT 'Stable internal error code',
  `line_user_id` varchar(100) DEFAULT NULL COMMENT 'LINE user ID that received notification',
  `source_ip` varchar(45) DEFAULT NULL COMMENT 'Source IP received at webhook endpoint',
  `webhook_timestamp` bigint(20) DEFAULT NULL COMMENT 'X-Odoo-Timestamp header value',
  `header_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Captured webhook headers snapshot' CHECK (json_valid(`header_json`)),
  `notified_targets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'List of notification targets that were sent' CHECK (json_valid(`notified_targets`)),
  `order_id` int(11) DEFAULT NULL COMMENT 'Odoo order ID (if applicable)',
  `synced_to_tables` tinyint(1) DEFAULT 0 COMMENT 'Whether this webhook has been synced to dedicated tables',
  `customer_id` int(11) GENERATED ALWAYS AS (cast(json_unquote(json_extract(`payload`,'$.partner_id')) as unsigned)) STORED COMMENT 'Extracted customer/partner ID from payload for fast filtering',
  `extracted_order_id` varchar(100) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.name'))) STORED COMMENT 'Extracted order reference/name from payload for fast filtering',
  `payload_customer_id` varchar(50) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.customer.id'))) VIRTUAL,
  `payload_order_name` varchar(100) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.order_name'))) VIRTUAL,
  `v_customer_id` varchar(50) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.customer.id'))) VIRTUAL,
  `v_customer_ref` varchar(100) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.customer.ref'))) VIRTUAL,
  `v_customer_name` varchar(200) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.customer.name'))) VIRTUAL,
  `v_order_name` varchar(100) GENERATED ALWAYS AS (json_unquote(json_extract(`payload`,'$.order_name'))) VIRTUAL,
  `v_amount_total` decimal(14,2) GENERATED ALWAYS AS (cast(coalesce(nullif(json_unquote(json_extract(`payload`,'$.amount_total')),''),'0') as decimal(14,2))) VIRTUAL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs all incoming webhooks from Odoo for audit trail';


-- ---------------------------------------------------------------------
-- SECTION: AI AND CONSULTATION  (14 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ai_chat_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_message` text DEFAULT NULL,
  `ai_response` text DEFAULT NULL,
  `response_time_ms` int(11) DEFAULT NULL,
  `model_used` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_chat_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 0,
  `gemini_api_key` varchar(255) DEFAULT NULL,
  `model` varchar(50) DEFAULT 'gemini-2.0-flash',
  `system_prompt` text DEFAULT NULL,
  `temperature` decimal(2,1) DEFAULT 0.7,
  `max_tokens` int(11) DEFAULT 500,
  `response_style` varchar(50) DEFAULT 'friendly',
  `language` varchar(10) DEFAULT 'th',
  `fallback_message` text DEFAULT NULL,
  `business_info` text DEFAULT NULL,
  `product_knowledge` text DEFAULT NULL,
  `sender_name` varchar(100) DEFAULT NULL,
  `sender_icon` varchar(500) DEFAULT NULL,
  `quick_reply_buttons` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversation_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `session_id` varchar(50) DEFAULT NULL,
  `role` enum('user','assistant') NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ach_user_created` (`user_id`,`created_at`),
  KEY `idx_ach_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversations` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `content` text NOT NULL,
  `tokens_used` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_base` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `source` varchar(150) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `heading_path` varchar(500) DEFAULT NULL,
  `content` mediumtext NOT NULL,
  `keywords` varchar(1000) DEFAULT NULL,
  `condition_codes` varchar(500) DEFAULT NULL,
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kb_account` (`line_account_id`),
  KEY `idx_kb_source` (`source`),
  KEY `idx_kb_active_priority` (`is_active`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_pharmacy_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `triage_enabled` tinyint(1) DEFAULT 1,
  `red_flag_enabled` tinyint(1) DEFAULT 1,
  `auto_recommend` tinyint(1) DEFAULT 1,
  `require_pharmacist_approval` tinyint(1) DEFAULT 1,
  `video_call_enabled` tinyint(1) DEFAULT 1,
  `notification_line_token` varchar(255) DEFAULT NULL,
  `notification_email` varchar(255) DEFAULT NULL,
  `working_hours_start` time DEFAULT '09:00:00',
  `working_hours_end` time DEFAULT '21:00:00',
  `emergency_contact` varchar(100) DEFAULT NULL,
  `pharmacy_name` varchar(200) DEFAULT NULL,
  `pharmacy_license` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_rate_limits` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `endpoint` varchar(64) NOT NULL COMMENT 'logical endpoint name e.g. vision, summary',
  `identifier` varchar(128) NOT NULL COMMENT 'line_user_id or ip',
  `identifier_type` enum('user','ip') NOT NULL,
  `request_count` int(11) NOT NULL DEFAULT 0,
  `window_start` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_ai_rate_limits_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_endpoint_id` (`endpoint`,`identifier_type`,`identifier`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-user / per-IP rate limit counters for AI endpoints (Phase 4 fix)';

CREATE TABLE IF NOT EXISTS `ai_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `ai_provider` varchar(50) NOT NULL DEFAULT 'gemini',
  `is_enabled` tinyint(1) DEFAULT 0,
  `system_prompt` text DEFAULT NULL,
  `model` varchar(50) DEFAULT 'gpt-3.5-turbo',
  `max_tokens` int(11) DEFAULT 500,
  `temperature` decimal(2,1) DEFAULT 0.7,
  `gemini_api_key` varchar(255) DEFAULT NULL,
  `openai_api_key` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ai_mode` enum('pharmacist','sales','support') DEFAULT 'sales',
  `business_info` text DEFAULT NULL,
  `product_knowledge` text DEFAULT NULL,
  `sales_prompt` text DEFAULT NULL,
  `auto_load_products` tinyint(1) DEFAULT 1,
  `product_load_limit` int(11) DEFAULT 50,
  `sender_name` varchar(100) DEFAULT NULL,
  `sender_icon` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_la` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_triage_assessments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `severity` int(11) DEFAULT NULL,
  `severity_level` enum('low','medium','high','critical') DEFAULT 'low',
  `associated_symptoms` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `medical_conditions` text DEFAULT NULL,
  `current_medications` text DEFAULT NULL,
  `ai_assessment` text DEFAULT NULL,
  `recommended_action` enum('self_care','consult_pharmacist','see_doctor','emergency') DEFAULT 'self_care',
  `pharmacist_notified` tinyint(1) DEFAULT 0,
  `pharmacist_response` text DEFAULT NULL,
  `status` enum('pending','reviewed','completed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_user_mode` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `ai_mode` varchar(50) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_ai_user_mode_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_user_pause` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `pause_until` datetime NOT NULL,
  `reason` varchar(255) DEFAULT 'human_request',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_ai_user_pause_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ghost_draft_learning` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Customer user ID',
  `pharmacist_id` int(11) DEFAULT NULL COMMENT 'Pharmacist who edited',
  `customer_message` text NOT NULL COMMENT 'Original customer message',
  `ai_draft` text NOT NULL COMMENT 'AI generated draft',
  `pharmacist_final` text NOT NULL COMMENT 'Final message sent by pharmacist',
  `edit_distance` int(11) DEFAULT NULL COMMENT 'Levenshtein distance between draft and final',
  `edit_ratio` decimal(5,4) DEFAULT NULL COMMENT 'Edit distance / original length ratio',
  `was_accepted` tinyint(1) DEFAULT 0 COMMENT '1 if draft was used with minimal edits',
  `context_stage` varchar(50) DEFAULT NULL COMMENT 'Consultation stage at time of draft',
  `context_symptoms` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detected symptoms in conversation' CHECK (json_valid(`context_symptoms`)),
  `context_drugs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Drugs mentioned in conversation' CHECK (json_valid(`context_drugs`)),
  `context_health_profile` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Customer health profile snapshot' CHECK (json_valid(`context_health_profile`)),
  `feedback_rating` tinyint(4) DEFAULT NULL COMMENT 'Pharmacist rating 1-5',
  `feedback_notes` text DEFAULT NULL COMMENT 'Pharmacist feedback notes',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ghost_drafts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `conversation_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `tone` varchar(50) DEFAULT NULL,
  `confidence` decimal(3,2) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `was_accepted` tinyint(1) DEFAULT 0,
  `was_edited` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_ghost_drafts_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pharmacy_ghost_learning` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `customer_message` text NOT NULL,
  `ai_draft` text NOT NULL,
  `pharmacist_final` text NOT NULL,
  `edit_distance` int(11) DEFAULT NULL COMMENT 'Levenshtein distance',
  `was_accepted` tinyint(1) DEFAULT 0,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Stage, health profile, symptoms, etc.' CHECK (json_valid(`context`)),
  `mentioned_drugs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Drugs mentioned in conversation' CHECK (json_valid(`mentioned_drugs`)),
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_pharmacy_ghost_learning_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: ANALYTICS AND LOGS  (12 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `activity_logs` (

  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `log_type` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `admin_name` varchar(255) DEFAULT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_value`)),
  `new_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_value`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_url` varchar(500) DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `extra_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_type` (`log_type`),
  KEY `idx_action` (`action`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `analytics` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`event_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consent_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `consent_type` varchar(50) NOT NULL,
  `action` enum('accept','withdraw','update') NOT NULL,
  `consent_version` varchar(20) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_consent_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `data_access_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `admin_user_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `resource_type` varchar(50) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_data_access_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `onboarding_sessions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `conversation_history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conversation_history`)),
  `current_topic` varchar(100) DEFAULT NULL,
  `business_type` varchar(50) DEFAULT NULL,
  `setup_progress` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`setup_progress`)),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `performance_metrics` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'LINE account for multi-tenant tracking',
  `metric_type` enum('page_load','conversation_switch','message_render','api_call','scroll_performance','cache_hit','cache_miss') NOT NULL,
  `duration_ms` int(11) NOT NULL COMMENT 'Duration in milliseconds',
  `operation_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional context about the operation' CHECK (json_valid(`operation_details`)),
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `setup_progress` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `item_key` varchar(50) NOT NULL,
  `status` enum('pending','in_progress','completed','skipped') DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_batches` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `batch_name` varchar(255) NOT NULL,
  `total_jobs` int(11) DEFAULT 0,
  `completed_jobs` int(11) DEFAULT 0,
  `failed_jobs` int(11) DEFAULT 0,
  `skipped_jobs` int(11) DEFAULT 0,
  `status` enum('pending','running','completed','failed') DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_sync_batches_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_config` (

  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_sync_config_la` (`line_account_id`),
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `queue_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_sync_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_queue` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `sku` varchar(50) NOT NULL,
  `status` enum('pending','processing','completed','failed','skipped') DEFAULT 'pending',
  `priority` tinyint(4) DEFAULT 5,
  `attempts` tinyint(4) DEFAULT 0,
  `max_attempts` tinyint(4) DEFAULT 3,
  `api_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`api_data`)),
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result`)),
  `error_message` text DEFAULT NULL,
  `processing_started_at` datetime DEFAULT NULL,
  `processing_completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_sync_queue_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_consents` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `consent_type` enum('privacy_policy','terms_of_service','marketing','health_data') NOT NULL,
  `consent_version` varchar(20) NOT NULL DEFAULT '1.0',
  `is_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `accepted_at` datetime DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_user_consents_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: WMS WAREHOUSE  (4 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `wms_activity_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `action` enum('pick_started','item_picked','pick_completed','pack_started','pack_completed','label_printed','shipped','item_short','item_damaged','on_hold','exception_resolved') NOT NULL,
  `item_id` int(11) DEFAULT NULL COMMENT 'Reference to transaction_items.id',
  `staff_id` int(11) DEFAULT NULL COMMENT 'Reference to admin_users.id',
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional data like quantity, reason, etc.' CHECK (json_valid(`metadata`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wms_log_order` (`order_id`),
  KEY `idx_wms_log_line_account` (`line_account_id`),
  KEY `idx_wms_log_action` (`action`),
  KEY `idx_wms_log_created` (`created_at`),
  KEY `idx_wms_log_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wms_batch_pick_orders` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `batch_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `pick_status` enum('pending','picked') DEFAULT 'pending',
  `picked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_wms_batch_pick_orders_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_order` (`batch_id`,`order_id`),
  KEY `idx_batch_pick_order` (`order_id`),
  CONSTRAINT `wms_batch_pick_orders_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `wms_batch_picks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wms_batch_picks` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `batch_number` varchar(20) NOT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `picker_id` int(11) DEFAULT NULL COMMENT 'Reference to admin_users.id',
  `total_orders` int(11) DEFAULT 0,
  `total_items` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_number` (`batch_number`),
  KEY `idx_batch_line_account` (`line_account_id`),
  KEY `idx_batch_status` (`status`),
  KEY `idx_batch_picker` (`picker_id`),
  KEY `idx_batch_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wms_pick_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `order_id` int(11) NOT NULL,
  `transaction_item_id` int(11) NOT NULL COMMENT 'Reference to transaction_items.id',
  `product_id` int(11) NOT NULL,
  `quantity_required` int(11) NOT NULL,
  `quantity_picked` int(11) DEFAULT 0,
  `status` enum('pending','picked','short','damaged') DEFAULT 'pending',
  `picked_by` int(11) DEFAULT NULL,
  `picked_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_wms_pick_items_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_item` (`order_id`,`transaction_item_id`),
  KEY `idx_pick_item_order` (`order_id`),
  KEY `idx_pick_item_product` (`product_id`),
  KEY `idx_pick_item_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: POS  (8 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `pos_cash_movements` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `movement_type` enum('in','out') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_daily_summary` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `summary_date` date NOT NULL,
  `total_sales` decimal(12,2) DEFAULT 0.00,
  `total_transactions` int(11) DEFAULT 0,
  `total_items_sold` int(11) DEFAULT 0,
  `cash_sales` decimal(12,2) DEFAULT 0.00,
  `transfer_sales` decimal(12,2) DEFAULT 0.00,
  `card_sales` decimal(12,2) DEFAULT 0.00,
  `points_sales` decimal(12,2) DEFAULT 0.00,
  `credit_sales` decimal(12,2) DEFAULT 0.00,
  `total_returns` decimal(12,2) DEFAULT 0.00,
  `return_count` int(11) DEFAULT 0,
  `total_vat` decimal(12,2) DEFAULT 0.00,
  `net_sales` decimal(12,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_payments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `transaction_id` int(11) NOT NULL,
  `payment_method` enum('cash','transfer','card','points','credit') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `cash_received` decimal(12,2) DEFAULT NULL,
  `change_amount` decimal(12,2) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `points_used` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  KEY `idx_pos_payments_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_return_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `return_id` int(11) NOT NULL,
  `original_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  KEY `idx_pos_return_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_returns` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `return_number` varchar(50) NOT NULL,
  `original_transaction_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `refund_amount` decimal(12,2) NOT NULL,
  `refund_method` enum('cash','original','credit') NOT NULL,
  `points_deducted` int(11) DEFAULT 0,
  `reason` varchar(255) NOT NULL,
  `processed_by` int(11) NOT NULL,
  `authorized_by` int(11) DEFAULT NULL,
  `status` enum('pending','completed','cancelled') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_shifts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `cashier_id` int(11) NOT NULL,
  `shift_number` varchar(50) NOT NULL,
  `opening_cash` decimal(12,2) NOT NULL,
  `closing_cash` decimal(12,2) DEFAULT NULL,
  `expected_cash` decimal(12,2) DEFAULT NULL,
  `variance` decimal(12,2) DEFAULT NULL,
  `total_sales` decimal(12,2) DEFAULT 0.00,
  `total_transactions` int(11) DEFAULT 0,
  `total_refunds` decimal(12,2) DEFAULT 0.00,
  `status` enum('open','closed') DEFAULT 'open',
  `opened_at` datetime DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  `cash_adjustments` decimal(12,2) DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_transaction_items` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `product_sku` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `returned_quantity` int(11) DEFAULT 0,
  `unit_price` decimal(12,2) NOT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `discount_type` enum('percent','fixed') DEFAULT NULL,
  `discount_value` decimal(12,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `original_price` decimal(12,2) DEFAULT NULL,
  `price_override_reason` varchar(255) DEFAULT NULL,
  `price_override_by` int(11) DEFAULT NULL,
  `price_override_at` datetime DEFAULT NULL,
  KEY `idx_pos_transaction_items_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_transactions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `transaction_number` varchar(50) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_type` enum('walk_in','member') DEFAULT 'walk_in',
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `discount_type` enum('percent','fixed') DEFAULT NULL,
  `discount_value` decimal(12,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `vat_amount` decimal(12,2) DEFAULT 0.00,
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `points_earned` int(11) DEFAULT 0,
  `points_redeemed` int(11) DEFAULT 0,
  `points_value` decimal(12,2) DEFAULT 0.00,
  `status` enum('draft','hold','pending','completed','voided','refunded') DEFAULT 'draft',
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `hold_note` varchar(255) DEFAULT NULL,
  `hold_at` datetime DEFAULT NULL,
  `reprint_count` int(11) DEFAULT 0,
  `last_reprint_at` datetime DEFAULT NULL,
  `last_reprint_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: INTEGRATIONS  (4 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `email_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_user` varchar(255) DEFAULT NULL,
  `smtp_pass` varchar(255) DEFAULT NULL,
  `smtp_secure` enum('tls','ssl','none') DEFAULT 'tls',
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT 'Notification',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_email_settings_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facebook_accounts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `name` varchar(255) NOT NULL,
  `page_id` varchar(100) NOT NULL,
  `app_id` varchar(100) NOT NULL,
  `app_secret` varchar(255) NOT NULL,
  `page_access_token` text NOT NULL,
  `verify_token` varchar(255) NOT NULL,
  `webhook_url` varchar(500) DEFAULT NULL,
  `picture_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_facebook_accounts_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 0,
  `bot_token` varchar(255) DEFAULT NULL,
  `chat_id` varchar(100) DEFAULT NULL,
  `notify_new_follower` tinyint(1) DEFAULT 1,
  `notify_new_message` tinyint(1) DEFAULT 1,
  `notify_unfollow` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notify_new_order` tinyint(1) DEFAULT 1,
  `notify_payment` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tiktok_shop_accounts` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `name` varchar(255) NOT NULL,
  `shop_id` varchar(100) NOT NULL,
  `app_key` varchar(100) NOT NULL,
  `app_secret` varchar(255) NOT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `shop_cipher` varchar(255) DEFAULT NULL,
  `webhook_url` varchar(500) DEFAULT NULL,
  `picture_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_tiktok_shop_accounts_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: LIFF AND MINIAPP  (11 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `landing_banners` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `image_url` varchar(500) NOT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `link_type` enum('none','internal','external') DEFAULT 'none',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `landing_faqs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `question` varchar(500) NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `landing_featured_products` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `product_source` varchar(50) DEFAULT 'products',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `landing_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_landing_setting` (`line_account_id`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `landing_testimonials` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_avatar` varchar(255) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT 5,
  `review_text` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `source` varchar(50) DEFAULT NULL COMMENT 'google, facebook, manual',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `liff_apps` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `liff_id` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `endpoint_url` varchar(500) DEFAULT NULL,
  `view_type` enum('full','tall','compact') DEFAULT 'full',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `liff_message_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `message_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`message_data`)),
  `sent_via` enum('liff','api') DEFAULT 'liff',
  `status` enum('sent','failed','pending') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `liff_shop_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `miniapp_banners` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) DEFAULT NULL,
  `subtitle` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(500) NOT NULL,
  `image_mobile_url` varchar(500) DEFAULT NULL,
  `link_type` enum('url','miniapp','liff','line_chat','deep_link','none') DEFAULT 'none',
  `link_value` varchar(500) DEFAULT NULL,
  `link_label` varchar(100) DEFAULT NULL,
  `surface` enum('home','shop') DEFAULT 'home',
  `position` enum('home_top','home_middle','home_bottom') DEFAULT 'home_top',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `bg_color` varchar(20) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_position` (`position`),
  KEY `idx_active` (`is_active`),
  KEY `idx_order` (`display_order`),
  KEY `idx_dates` (`start_date`,`end_date`),
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `miniapp_home_products` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `short_description` varchar(500) DEFAULT NULL,
  `image_url` varchar(500) NOT NULL,
  `image_gallery` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`image_gallery`)),
  `original_price` decimal(12,2) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) DEFAULT NULL,
  `price_unit` varchar(50) DEFAULT NULL,
  `promotion_tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`promotion_tags`)),
  `promotion_label` varchar(100) DEFAULT NULL,
  `badges` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`badges`)),
  `custom_label` varchar(200) DEFAULT NULL,
  `stock_qty` int(11) DEFAULT NULL,
  `limit_qty` int(11) DEFAULT NULL,
  `show_stock_badge` tinyint(1) DEFAULT 0,
  `delivery_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`delivery_options`)),
  `link_type` enum('url','miniapp','liff','line_chat','deep_link','none') DEFAULT 'none',
  `link_value` varchar(500) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_section` (`section_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_order` (`display_order`),
  KEY `idx_dates` (`start_date`,`end_date`),
  KEY `idx_line_account` (`line_account_id`),
  CONSTRAINT `fk_miniapp_product_section` FOREIGN KEY (`section_id`) REFERENCES `miniapp_home_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `miniapp_home_sections` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_key` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `subtitle` varchar(500) DEFAULT NULL,
  `section_style` enum('flash_sale','horizontal_scroll','grid','banner_list') DEFAULT 'horizontal_scroll',
  `bg_color` varchar(20) DEFAULT NULL,
  `text_color` varchar(20) DEFAULT NULL,
  `icon_url` varchar(500) DEFAULT NULL,
  `countdown_ends_at` datetime DEFAULT NULL,
  `surface` enum('home','shop') DEFAULT 'home',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`),
  KEY `idx_style` (`section_style`),
  KEY `idx_active` (`is_active`),
  KEY `idx_order` (`display_order`),
  KEY `idx_dates` (`start_date`,`end_date`),
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: RICH MENUS  (6 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rich_menu_aliases` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `alias_id` varchar(100) NOT NULL COMMENT 'LINE Rich Menu Alias ID',
  `alias_name` varchar(50) NOT NULL COMMENT 'ชื่อ Alias (เช่น member, guest)',
  `rich_menu_id` int(11) NOT NULL,
  `line_rich_menu_id` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rich_menu_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'ชื่อกฎ',
  `description` text DEFAULT NULL COMMENT 'คำอธิบาย',
  `rich_menu_id` int(11) NOT NULL COMMENT 'Rich Menu ที่จะใช้',
  `priority` int(11) DEFAULT 0 COMMENT 'ลำดับความสำคัญ (สูง = ใช้ก่อน)',
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'เงื่อนไขในรูปแบบ JSON' CHECK (json_valid(`conditions`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rich_menu_switch_log` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `from_rich_menu_id` int(11) DEFAULT NULL,
  `to_rich_menu_id` int(11) NOT NULL,
  `trigger_type` enum('rule','manual','event','api') DEFAULT 'rule',
  `trigger_detail` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rich_menu_switch_pages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `switch_set_id` int(11) NOT NULL,
  `page_number` int(11) NOT NULL DEFAULT 1,
  `page_name` varchar(50) NOT NULL,
  `rich_menu_id` int(11) NOT NULL,
  `line_rich_menu_id` varchar(100) DEFAULT NULL,
  `alias_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_rich_menu_switch_pages_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rich_menu_switch_sets` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rich_menus` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `line_rich_menu_id` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `menu_type` enum('default','member','guest','vip','custom') DEFAULT 'custom',
  `chat_bar_text` varchar(50) DEFAULT NULL,
  `size_width` int(11) DEFAULT 2500,
  `size_height` int(11) DEFAULT 1686,
  `areas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`areas`)),
  `image_path` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `target_audience` varchar(50) DEFAULT NULL COMMENT 'กลุ่มเป้าหมาย',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: LOYALTY AND POINTS  (30 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `category_points_bonus` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `multiplier` decimal(3,2) DEFAULT 1.00 COMMENT 'Points multiplier for this category',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_usage` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `coupon_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `used_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_coupon_usage_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupons` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('fixed','percent') DEFAULT 'fixed',
  `discount_value` decimal(10,2) NOT NULL,
  `min_purchase` decimal(10,2) DEFAULT 0.00,
  `max_discount` decimal(10,2) DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `usage_count` int(11) DEFAULT 0,
  `user_limit` int(11) DEFAULT 1,
  `start_date` timestamp NULL DEFAULT NULL,
  `end_date` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_notes` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `content` text NOT NULL,
  KEY `idx_customer_notes_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_segments` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `segment_type` enum('static','dynamic') DEFAULT 'dynamic',
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`conditions`)),
  `user_count` int(11) DEFAULT 0,
  `last_calculated_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_group_members` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `group_id` int(11) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `picture_url` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_at` timestamp NULL DEFAULT NULL,
  `total_messages` int(11) DEFAULT 0,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_line_group_members_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_group_messages` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `group_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text DEFAULT NULL,
  `message_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_line_group_messages_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `link_clicks` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `link_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `referer` text DEFAULT NULL,
  `clicked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_link_clicks_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loyalty_points` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `points` int(11) DEFAULT 0,
  `lifetime_points` int(11) DEFAULT 0,
  `tier` varchar(50) DEFAULT 'bronze',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loyalty_points_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` varchar(100) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `type` enum('earn','redeem','adjust','expire') DEFAULT 'earn',
  `description` varchar(255) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_loyalty_points_history_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_tiers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `tier_code` varchar(50) NOT NULL,
  `tier_name` varchar(100) NOT NULL,
  `min_points` int(11) NOT NULL DEFAULT 0,
  `color` varchar(20) DEFAULT '#6B7280',
  `icon` varchar(10) DEFAULT '?',
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `benefits` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tier` (`line_account_id`,`tier_code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_min_points` (`min_points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `point_rewards` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `points_required` int(11) NOT NULL DEFAULT 100,
  `type` enum('discount','shipping','gift','product','coupon') DEFAULT 'discount',
  `value` decimal(10,2) DEFAULT 0.00 COMMENT 'มูลค่า เช่น ส่วนลด 50 บาท',
  `image_url` varchar(500) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL COMMENT 'NULL = ไม่จำกัด',
  `redeemed_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `points_campaigns` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Campaign name',
  `description` text DEFAULT NULL,
  `multiplier` decimal(3,2) DEFAULT 2.00 COMMENT 'Points multiplier (e.g., 2.0 for double points)',
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `applicable_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of category IDs, null = all categories' CHECK (json_valid(`applicable_categories`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `points_history` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `points` int(11) NOT NULL COMMENT 'บวก=ได้รับ, ลบ=ใช้',
  `type` enum('earn','redeem','expire','adjust','bonus') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'order, reward, manual',
  `reference_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `balance_after` int(11) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_points_history_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `points_rules` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `rule_type` enum('base','campaign','category','tier') NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `value` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions`)),
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `points_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `points_per_baht` decimal(10,6) DEFAULT 0.001000 COMMENT 'แต้มต่อบาท (รองรับถึง 0.000001)',
  `min_order_for_points` decimal(12,2) DEFAULT 0.00 COMMENT 'ยอดสั่งซื้อขั้นต่ำเพื่อรับแต้ม',
  `points_expiry_days` int(11) DEFAULT 365 COMMENT 'แต้มหมดอายุกี่วัน (0 = ไม่หมดอายุ)',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `points_tiers` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `min_points` int(11) NOT NULL COMMENT 'แต้มขั้นต่ำ',
  `multiplier` decimal(3,2) DEFAULT 1.00,
  `points_multiplier` decimal(3,2) DEFAULT 1.00 COMMENT 'ตัวคูณแต้ม',
  `color` varchar(20) DEFAULT '#666666',
  `icon` varchar(50) DEFAULT 'fa-star',
  `sort_order` int(11) DEFAULT 0,
  `benefits` text DEFAULT NULL COMMENT 'สิทธิประโยชน์ (JSON)',
  `badge_color` varchar(20) DEFAULT '#6B7280',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `points_transactions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `type` enum('earn','redeem','expire','adjust','refund') NOT NULL,
  `points` int(11) NOT NULL COMMENT 'จำนวนแต้ม (บวก=ได้, ลบ=ใช้)',
  `balance_after` int(11) NOT NULL COMMENT 'แต้มคงเหลือหลังทำรายการ',
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'order, reward, manual, etc.',
  `reference_id` int(11) DEFAULT NULL COMMENT 'ID อ้างอิง',
  `description` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'วันหมดอายุของแต้ม',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promotion_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reward_redemptions` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `reward_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `points_used` int(11) NOT NULL,
  `status` enum('pending','approved','delivered','cancelled','expired') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `redemption_code` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `expiry_reminder_sent` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rewards` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `points_required` int(11) NOT NULL,
  `reward_type` enum('discount','product','voucher','shipping') DEFAULT 'discount',
  `reward_value` varchar(255) DEFAULT NULL COMMENT 'à¸¡à¸¹à¸¥à¸„à¹ˆà¸²/à¸£à¸«à¸±à¸ªà¸„à¸¹à¸›à¸­à¸‡/product_id',
  `stock` int(11) DEFAULT -1 COMMENT '-1 = unlimited',
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `max_per_user` int(11) DEFAULT 0 COMMENT '0 = unlimited',
  `terms` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `segment_members` (

  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `segment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `score` decimal(10,2) DEFAULT 0.00,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_segment_members_la` (`line_account_id`),
  PRIMARY KEY (`segment_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tier_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(50) NOT NULL COMMENT 'Tier name (Silver, Gold, Platinum)',
  `min_points` int(11) NOT NULL DEFAULT 0 COMMENT 'Minimum points to reach this tier',
  `multiplier` decimal(3,2) DEFAULT 1.00 COMMENT 'Points earning multiplier for this tier',
  `benefits` text DEFAULT NULL COMMENT 'JSON or text description of tier benefits',
  `badge_color` varchar(50) DEFAULT NULL COMMENT 'CSS color for tier badge',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tracked_links` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `short_code` varchar(20) NOT NULL,
  `original_url` text NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `auto_tag_id` int(11) DEFAULT NULL,
  `click_count` int(11) DEFAULT 0,
  `unique_clicks` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_custom_field_values` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `field_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_user_custom_field_values_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_groups` (

  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  KEY `idx_user_groups_la` (`line_account_id`),
  PRIMARY KEY (`user_id`,`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notes` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_user_notes_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_profiles_extended` (

  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `birthday` date DEFAULT NULL,
  `gender` enum('male','female','other','unknown') DEFAULT 'unknown',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `customer_type` enum('new','returning','vip','inactive') DEFAULT 'new',
  `lifetime_value` decimal(12,2) DEFAULT 0.00,
  `total_orders` int(11) DEFAULT 0,
  `average_order_value` decimal(10,2) DEFAULT 0.00,
  `last_purchase_at` timestamp NULL DEFAULT NULL,
  `first_purchase_at` timestamp NULL DEFAULT NULL,
  `preferred_categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferred_categories`)),
  `interests` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`interests`)),
  `custom_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_fields`)),
  `engagement_score` int(11) DEFAULT 0 COMMENT '0-100',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_user_profiles_extended_la` (`line_account_id`),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_states` (

  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `user_id` int(11) NOT NULL,
  `state` varchar(50) NOT NULL,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`state_data`)),
  `expires_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_user_states_la` (`line_account_id`),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vibe_selling_settings` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- SECTION: DRIPS AND CAMPAIGNS  (11 tables)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `birthday_campaigns` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `days_before` int(11) DEFAULT 0 COMMENT 'ส่งก่อนวันเกิดกี่วัน',
  `send_time` time DEFAULT '09:00:00',
  `message_type` enum('text','flex') DEFAULT 'flex',
  `message_content` text NOT NULL,
  `coupon_code` varchar(50) DEFAULT NULL,
  `discount_type` enum('percent','fixed') DEFAULT 'percent',
  `discount_value` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sent_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_campaign_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `campaign_id` int(11) NOT NULL,
  `step_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `status` enum('sent','failed','skipped') NOT NULL,
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_drip_campaign_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_campaign_progress` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `current_step` int(11) DEFAULT 0,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `next_send_at` timestamp NULL DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  KEY `idx_drip_campaign_progress_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_campaign_queue` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `campaign_id` int(11) NOT NULL,
  `step_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','sent','failed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_drip_campaign_queue_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_campaign_steps` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `campaign_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `delay_minutes` int(11) DEFAULT 0,
  `message_type` enum('text','flex','image') DEFAULT 'text',
  `message_content` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_drip_campaign_steps_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_campaigns` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `trigger_type` enum('follow','tag_added','purchase','manual') DEFAULT 'follow',
  `trigger_tag_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `total_enrolled` int(11) DEFAULT 0,
  `total_completed` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_queue` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `campaign_id` int(11) NOT NULL,
  `user_id` varchar(100) NOT NULL,
  `current_step` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'pending',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  KEY `idx_drip_queue_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  KEY `idx_campaign_user` (`campaign_id`,`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_scheduled` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `drip_steps` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `campaign_id` int(11) NOT NULL,
  `step_order` int(11) DEFAULT 0,
  `delay_minutes` int(11) DEFAULT 0,
  `message_type` varchar(50) DEFAULT 'text',
  `message_content` text DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `idx_drip_steps_la` (`line_account_id`),
  PRIMARY KEY (`id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_order` (`step_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scheduled_report_logs` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `report_id` int(11) NOT NULL,
  `sent_at` datetime NOT NULL,
  `recipients_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('success','partial','failed') NOT NULL,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_data`)),
  `error_message` text DEFAULT NULL,
  KEY `idx_scheduled_report_logs_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scheduled_report_recipients` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL DEFAULT 1 COMMENT 'tenant scope - FK line_accounts.id (added during DB-per-tenant migration)',
  `report_id` int(11) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL COMMENT 'LINE User ID for push message',
  `notify_method` enum('line','email','both') NOT NULL DEFAULT 'line',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_scheduled_report_recipients_la` (`line_account_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scheduled_reports` (

  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `report_type` varchar(50) NOT NULL,
  `schedule` varchar(50) NOT NULL COMMENT 'daily, weekly, monthly',
  `recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recipients`)),
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parameters`)),
  `last_run` timestamp NULL DEFAULT NULL,
  `next_run` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- END migration_2026-05-25_tenant_template.sql
-- Emitted: 279 tables  |  Skipped: 43 tables
-- Orphan tables given new `line_account_id` column: 117
-- =====================================================================