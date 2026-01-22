-- =============================================
-- LINE Telepharmacy CRM - Database Schema Export
-- Exported from: zrismpsz_cny
-- Export date: 2026-01-23 01:08:32
-- Total tables: 228
-- =============================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ---------------------------------------------
-- Table: `account_daily_stats`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_date` (`line_account_id`,`stat_date`),
  KEY `idx_stats_account` (`line_account_id`),
  KEY `idx_stats_date` (`stat_date`)
) ENGINE=InnoDB AUTO_INCREMENT=12066 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `account_events`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_account_events_account` (`line_account_id`),
  KEY `idx_account_events_user` (`line_user_id`),
  KEY `idx_account_events_type` (`event_type`),
  KEY `idx_account_events_created` (`created_at`),
  KEY `idx_account_events_webhook` (`webhook_event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6593 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `account_followers`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_follower` (`line_account_id`,`line_user_id`),
  KEY `idx_followers_account` (`line_account_id`),
  KEY `idx_followers_user` (`line_user_id`),
  KEY `idx_followers_following` (`is_following`),
  KEY `idx_followers_date` (`followed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=879 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `account_payables`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `ap_number` (`ap_number`),
  KEY `idx_ap_supplier` (`supplier_id`),
  KEY `idx_ap_status` (`status`),
  KEY `idx_ap_due_date` (`due_date`),
  KEY `idx_ap_po` (`po_id`),
  KEY `idx_ap_gr` (`gr_id`),
  KEY `idx_ap_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `account_receivables`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `ar_number` (`ar_number`),
  KEY `idx_ar_user` (`user_id`),
  KEY `idx_ar_status` (`status`),
  KEY `idx_ar_due_date` (`due_date`),
  KEY `idx_ar_transaction` (`transaction_id`),
  KEY `idx_ar_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `activity_logs`
-- ---------------------------------------------
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
) ENGINE=InnoDB AUTO_INCREMENT=604 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `admin_activity_log`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=341 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `admin_bot_access`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_bot_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `line_account_id` int(11) NOT NULL,
  `can_view` tinyint(1) DEFAULT 1,
  `can_edit` tinyint(1) DEFAULT 1,
  `can_broadcast` tinyint(1) DEFAULT 1,
  `can_manage_users` tinyint(1) DEFAULT 1,
  `can_manage_shop` tinyint(1) DEFAULT 1,
  `can_view_analytics` tinyint(1) DEFAULT 1,
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_admin_bot` (`admin_id`,`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `admin_quick_access`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_quick_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(11) NOT NULL,
  `menu_key` varchar(50) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_menu` (`admin_user_id`,`menu_key`),
  KEY `idx_admin_user` (`admin_user_id`),
  CONSTRAINT `admin_quick_access_ibfk_1` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `admin_users`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'admin',
  `line_account_id` int(11) DEFAULT NULL COMMENT 'สำหรับ role=user ใช้ได้แค่ 1 บัญชี',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `login_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `line_user_id` varchar(50) DEFAULT NULL,
  `notification_enabled` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_admin_users_role` (`role`),
  KEY `idx_admin_users_line_account` (`line_account_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_role_active` (`role`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_chat_logs`
-- ---------------------------------------------
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
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_chat_settings`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_conversation_history`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_conversation_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `session_id` varchar(50) DEFAULT NULL,
  `role` enum('user','assistant') NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_session_id` (`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=283 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_conversations`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `content` text NOT NULL,
  `tokens_used` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_conv_user` (`user_id`),
  KEY `idx_ai_conv_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_pharmacy_settings`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 0,
  `system_prompt` text DEFAULT NULL,
  `model` varchar(50) DEFAULT 'gpt-3.5-turbo',
  `max_tokens` int(11) DEFAULT 500,
  `temperature` decimal(2,1) DEFAULT 0.7,
  `gemini_api_key` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ai_mode` enum('pharmacist','sales','support') DEFAULT 'sales',
  `business_info` text DEFAULT NULL,
  `product_knowledge` text DEFAULT NULL,
  `sales_prompt` text DEFAULT NULL,
  `auto_load_products` tinyint(1) DEFAULT 1,
  `product_load_limit` int(11) DEFAULT 50,
  `sender_name` varchar(100) DEFAULT NULL,
  `sender_icon` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_triage_assessments`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_severity` (`severity_level`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_user_mode`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_user_mode` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ai_mode` varchar(50) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=130 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ai_user_pause`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_user_pause` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pause_until` datetime NOT NULL,
  `reason` varchar(255) DEFAULT 'human_request',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  KEY `idx_pause_until` (`pause_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `analytics`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`event_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_analytics_line_account` (`line_account_id`),
  KEY `idx_analytics_created` (`created_at`),
  KEY `idx_analytics_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `appointments`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_appt_user` (`user_id`),
  KEY `idx_appt_date` (`appointment_date`),
  KEY `idx_appt_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `auto_replies`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_reply_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `auto_reply_rules`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_keyword` (`keyword`),
  KEY `idx_active` (`is_active`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กฎการตอบกลับอัตโนมัติ';

-- ---------------------------------------------
-- Table: `auto_tag_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `auto_tag_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `rule_id` int(11) DEFAULT NULL,
  `action` enum('assign','remove') NOT NULL,
  `trigger_type` varchar(50) DEFAULT NULL,
  `trigger_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trigger_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_tag` (`tag_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=170 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `auto_tag_rules`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `auto_tag_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `tag_id` int(11) NOT NULL,
  `rule_name` varchar(100) NOT NULL,
  `trigger_type` enum('follow','message','purchase','inactivity','birthday','order_count','total_spent','custom') NOT NULL,
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`conditions`)),
  `is_active` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tag_id` (`tag_id`),
  KEY `idx_trigger` (`trigger_type`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `auto_tag_rules_ibfk_1` FOREIGN KEY (`tag_id`) REFERENCES `user_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `birthday_campaigns`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------
-- Table: `broadcast_campaigns`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_account` (`line_account_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `broadcast_clicks`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcast_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `tag_assigned` tinyint(1) DEFAULT 0,
  `clicked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `item_id` (`item_id`),
  KEY `idx_broadcast` (`broadcast_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_clicked` (`clicked_at`),
  CONSTRAINT `broadcast_clicks_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcast_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `broadcast_clicks_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `broadcast_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `broadcast_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcast_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  KEY `idx_broadcast` (`broadcast_id`),
  KEY `idx_postback` (`postback_data`),
  CONSTRAINT `broadcast_items_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcast_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `broadcast_messages`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_broadcast_line_account` (`line_account_id`),
  KEY `idx_broadcast_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `broadcast_queue`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcast_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_broadcast` (`broadcast_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `broadcasts`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `broadcasts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text NOT NULL,
  `target_type` varchar(20) DEFAULT 'all' COMMENT 'database, all, limit, narrowcast, group, segment, tag, select, single',
  `target_group_id` int(11) DEFAULT NULL,
  `sent_count` int(11) DEFAULT 0,
  `status` enum('draft','scheduled','sending','sent','failed') DEFAULT 'draft',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_broadcast_line_account` (`line_account_id`),
  KEY `idx_broadcast_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `business_categories`
-- ---------------------------------------------
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
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `business_items`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_item_type` (`item_type`),
  KEY `idx_category` (`category_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_barcode` (`barcode`),
  KEY `idx_featured` (`is_featured`),
  KEY `idx_business_items_barcode` (`barcode`),
  KEY `idx_business_items_cny_id` (`cny_id`),
  KEY `idx_business_items_enable` (`enable`),
  KEY `idx_movement_class` (`movement_class`),
  KEY `idx_storage_zone_type` (`storage_zone_type`),
  KEY `idx_default_location` (`default_location_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4700 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `business_items_to_products_map`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `business_items_to_products_map` (
  `old_business_item_id` int(11) NOT NULL,
  `new_product_id` int(11) NOT NULL,
  `migrated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`old_business_item_id`),
  KEY `idx_new_product` (`new_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `business_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `business_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `cart`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cart_item` (`user_id`,`product_id`),
  KEY `idx_cart_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `cart_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product` (`line_user_id`,`product_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `cart_items_backup`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cart_items_backup` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product` (`line_user_id`,`product_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `category_points_bonus`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `category_points_bonus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `multiplier` decimal(3,2) DEFAULT 1.00 COMMENT 'Points multiplier for this category',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_category` (`line_account_id`,`category_id`),
  KEY `idx_category_bonus_account` (`line_account_id`),
  KEY `idx_category_bonus_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `chat_status_history`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT current_timestamp(),
  `note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`line_account_id`),
  KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `cleanup_actions`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cleanup_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action_type` enum('scan','deprecate','backup','delete','restore') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `backup_id` varchar(100) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `executed_by` varchar(100) DEFAULT NULL,
  `executed_at` datetime DEFAULT current_timestamp(),
  `status` enum('pending','success','failed') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_executed_at` (`executed_at`),
  KEY `idx_backup_id` (`backup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `cleanup_backups`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cleanup_backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_id` varchar(100) NOT NULL,
  `backup_path` varchar(500) NOT NULL,
  `original_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `md5_checksum` varchar(32) NOT NULL,
  `backed_up_at` datetime DEFAULT current_timestamp(),
  `restored_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `status` enum('active','restored','archived') DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `backup_id` (`backup_id`),
  KEY `idx_backup_id` (`backup_id`),
  KEY `idx_original_path` (`original_path`),
  KEY `idx_backed_up_at` (`backed_up_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `cleanup_inventory`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cleanup_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(50) NOT NULL,
  `category` varchar(100) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `created_date` datetime DEFAULT NULL,
  `modified_date` datetime DEFAULT NULL,
  `last_accessed_date` datetime DEFAULT NULL,
  `md5_checksum` varchar(32) DEFAULT NULL,
  `status` enum('active','deprecated','archived','deleted') DEFAULT 'active',
  `recommendation` varchar(50) DEFAULT NULL,
  `scan_date` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_file_path` (`file_path`),
  KEY `idx_category` (`category`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `cleanup_reports`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cleanup_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` varchar(100) NOT NULL,
  `report_type` enum('scan','cleanup','rollback') NOT NULL,
  `files_scanned` int(11) DEFAULT 0,
  `files_deleted` int(11) DEFAULT 0,
  `files_archived` int(11) DEFAULT 0,
  `space_freed` bigint(20) DEFAULT 0,
  `warnings_count` int(11) DEFAULT 0,
  `errors_count` int(11) DEFAULT 0,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_data`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `report_id` (`report_id`),
  KEY `idx_report_type` (`report_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `cny_products`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `cny_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(100) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `name` text DEFAULT NULL,
  `name_en` text DEFAULT NULL,
  `spec_name` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `properties_other` text DEFAULT NULL,
  `how_to_use` text DEFAULT NULL,
  `photo_path` text DEFAULT NULL,
  `qty` decimal(10,2) DEFAULT 0.00,
  `qty_incoming` decimal(10,2) DEFAULT 0.00,
  `enable` char(1) DEFAULT '1',
  `product_price` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`product_price`)),
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `idx_sku` (`sku`),
  KEY `idx_enable` (`enable`),
  KEY `idx_name` (`name`(100))
) ENGINE=InnoDB AUTO_INCREMENT=9609 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `consent_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `consent_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `consent_type` varchar(50) NOT NULL,
  `action` enum('accept','withdraw','update') NOT NULL,
  `consent_version` varchar(20) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_consent` (`user_id`,`consent_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `consultation_analytics`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `consultation_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_pharmacist` (`pharmacist_id`),
  KEY `idx_purchase` (`resulted_in_purchase`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3265 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `consultation_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `consultation_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `log_type` enum('start','end','note','prescription') DEFAULT 'note',
  `content` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `appointment_id` (`appointment_id`),
  CONSTRAINT `consultation_logs_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `consultation_stages`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `consultation_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `stage` enum('symptom_assessment','drug_recommendation','purchase','follow_up') NOT NULL,
  `confidence` decimal(3,2) DEFAULT 0.00,
  `signals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detected signals' CHECK (json_valid(`signals`)),
  `has_urgent_symptoms` tinyint(1) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  KEY `idx_stage` (`stage`),
  KEY `idx_urgent` (`has_urgent_symptoms`)
) ENGINE=InnoDB AUTO_INCREMENT=2943 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `conversation_assignments`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `conversation_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Customer user ID',
  `line_account_id` int(11) NOT NULL DEFAULT 1,
  `assigned_to` int(11) NOT NULL COMMENT 'Admin user ID',
  `assigned_by` int(11) DEFAULT NULL COMMENT 'Who assigned',
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','resolved','transferred') DEFAULT 'active',
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  UNIQUE KEY `unique_user_account` (`user_id`,`line_account_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=492 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `conversation_multi_assignees`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `conversation_multi_assignees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'Customer user ID',
  `admin_id` int(11) NOT NULL COMMENT 'Admin user ID assigned',
  `assigned_by` int(11) DEFAULT NULL COMMENT 'Who assigned this admin',
  `assigned_at` datetime DEFAULT current_timestamp(),
  `status` enum('active','resolved') DEFAULT 'active',
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_admin` (`user_id`,`admin_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_at` (`assigned_at`)
) ENGINE=InnoDB AUTO_INCREMENT=508 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Supports multiple admins assigned to one conversation';

-- ---------------------------------------------
-- Table: `conversation_states`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `conversation_states` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `current_state` varchar(50) NOT NULL,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`state_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `conversation_states_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `coupon_usage`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `coupon_usage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coupon_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `used_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_coupon` (`coupon_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `coupons`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_code` (`code`),
  KEY `idx_account` (`line_account_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `customer_health_profiles`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_health_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `communication_type` enum('A','B','C') DEFAULT NULL COMMENT 'A=Direct, B=Concerned, C=Detailed',
  `confidence` decimal(3,2) DEFAULT 0.00,
  `chronic_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'List of chronic conditions' CHECK (json_valid(`chronic_conditions`)),
  `communication_tips` text DEFAULT NULL,
  `last_analyzed_at` datetime DEFAULT NULL,
  `message_count_analyzed` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_type` (`communication_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2947 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `customer_notes`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `customer_segments`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `data_access_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `data_access_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `resource_type` varchar(50) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_user` (`admin_user_id`),
  KEY `idx_target_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `dev_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `dev_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `log_type` enum('error','warning','info','debug','webhook') DEFAULT 'info',
  `source` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `user_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_type` (`log_type`),
  KEY `idx_created` (`created_at`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `dispensing_records`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drip_campaign_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drip_campaign_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `step_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `status` enum('sent','failed','skipped') NOT NULL,
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_campaign` (`campaign_id`),
  KEY `idx_log_user` (`user_id`),
  KEY `idx_log_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drip_campaign_progress`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drip_campaign_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `current_step` int(11) DEFAULT 0,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `next_send_at` timestamp NULL DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_campaign_user` (`campaign_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_next_send` (`next_send_at`,`status`),
  CONSTRAINT `drip_campaign_progress_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `drip_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `drip_campaign_progress_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drip_campaign_queue`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drip_campaign_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `step_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','sent','failed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scheduled` (`scheduled_at`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `drip_campaign_steps`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drip_campaign_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `delay_minutes` int(11) DEFAULT 0,
  `message_type` enum('text','flex','image') DEFAULT 'text',
  `message_content` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaign_step` (`campaign_id`,`step_order`),
  CONSTRAINT `drip_campaign_steps_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `drip_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drip_campaigns`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_drip_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drug_disposal_records`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_disposal_date` (`disposal_date`),
  CONSTRAINT `drug_disposal_records_ibfk_1` FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `drug_disposal_records_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกการทำลายยา (เก็บไว้ 3 ปีตามกฎหมาย)';

-- ---------------------------------------------
-- Table: `drug_interaction_acknowledgments`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drug_interaction_acknowledgments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `drug1_id` int(11) NOT NULL,
  `drug2_id` int(11) NOT NULL,
  `drug1_name` varchar(255) DEFAULT NULL,
  `drug2_name` varchar(255) DEFAULT NULL,
  `severity` enum('mild','moderate','severe') DEFAULT 'moderate',
  `acknowledged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `order_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_drugs` (`drug1_id`,`drug2_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drug_interactions`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drug_interactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `drug1_name` varchar(100) NOT NULL,
  `drug1_generic` varchar(100) DEFAULT NULL,
  `drug2_name` varchar(100) NOT NULL,
  `drug2_generic` varchar(100) DEFAULT NULL,
  `severity` enum('mild','moderate','severe','contraindicated') DEFAULT 'moderate',
  `description` text DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_drug1` (`drug1_name`),
  KEY `idx_drug2` (`drug2_name`),
  KEY `idx_severity` (`severity`)
) ENGINE=InnoDB AUTO_INCREMENT=196 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drug_pricing_rules`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drug_pricing_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  KEY `idx_type` (`rule_type`),
  KEY `idx_category` (`category_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `drug_recognition_cache`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `drug_recognition_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `image_hash` varchar(64) NOT NULL,
  `image_url` text DEFAULT NULL,
  `drug_name` varchar(255) DEFAULT NULL,
  `generic_name` varchar(255) DEFAULT NULL,
  `matched_product_id` int(11) DEFAULT NULL,
  `recognition_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recognition_result`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `image_hash` (`image_hash`),
  KEY `idx_hash` (`image_hash`),
  KEY `idx_product` (`matched_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `email_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `email_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT 587,
  `smtp_user` varchar(255) DEFAULT NULL,
  `smtp_pass` varchar(255) DEFAULT NULL,
  `smtp_secure` enum('tls','ssl','none') DEFAULT 'tls',
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT 'Notification',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `emergency_alerts`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `expense_categories`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_exp_cat_active` (`is_active`),
  KEY `idx_exp_cat_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `expenses`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `expense_number` (`expense_number`),
  KEY `idx_exp_category` (`category_id`),
  KEY `idx_exp_date` (`expense_date`),
  KEY `idx_exp_status` (`payment_status`),
  KEY `idx_exp_line_account` (`line_account_id`),
  CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `flex_templates`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_flex_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `ghost_draft_learning`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_pharmacist` (`pharmacist_id`),
  KEY `idx_accepted` (`was_accepted`),
  KEY `idx_stage` (`context_stage`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `goods_receive_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `goods_receive_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  KEY `idx_gri_gr` (`gr_id`),
  KEY `idx_gri_po_item` (`po_item_id`),
  KEY `idx_gri_product` (`product_id`),
  KEY `idx_gri_batch_number` (`batch_number`),
  KEY `idx_gri_expiry_date` (`expiry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `goods_receives`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `gr_number` (`gr_number`),
  KEY `idx_gr_number` (`gr_number`),
  KEY `idx_gr_po` (`po_id`),
  KEY `idx_gr_line_account` (`line_account_id`),
  KEY `idx_gr_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `groups`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#3B82F6',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_group_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `health_article_categories`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_category_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `health_articles`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_article_published` (`is_published`,`published_at`),
  KEY `idx_article_featured` (`is_featured`,`is_published`),
  KEY `idx_article_category` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `inventory_batches`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_batch_number` (`batch_number`),
  KEY `idx_expiry` (`expiry_date`),
  KEY `idx_status` (`status`),
  KEY `idx_location` (`location_id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_received_at` (`received_at`),
  CONSTRAINT `fk_batch_location` FOREIGN KEY (`location_id`) REFERENCES `warehouse_locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `item_categories`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_cat_line_account` (`line_account_id`),
  KEY `idx_cat_parent` (`parent_id`),
  KEY `idx_cat_cny_code` (`cny_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `item_images`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `item_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `landing_banners`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_banner_account` (`line_account_id`),
  KEY `idx_banner_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `landing_faqs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `landing_faqs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `question` varchar(500) NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_faq_account` (`line_account_id`),
  KEY `idx_faq_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `landing_featured_products`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `landing_featured_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `product_source` varchar(50) DEFAULT 'products',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_featured_product` (`line_account_id`,`product_id`),
  KEY `idx_featured_account` (`line_account_id`),
  KEY `idx_featured_product` (`product_id`),
  KEY `idx_featured_active` (`is_active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `landing_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `landing_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_landing_setting` (`line_account_id`,`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `landing_testimonials`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_testimonial_account` (`line_account_id`),
  KEY `idx_testimonial_status` (`status`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `liff_apps`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_liff_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `liff_message_logs`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user` (`line_user_id`),
  KEY `idx_action` (`action_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `liff_shop_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `liff_shop_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_setting` (`line_account_id`,`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `line_accounts`
-- ---------------------------------------------
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `line_group_members`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `line_group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_member` (`group_id`,`line_user_id`),
  KEY `idx_members_group` (`group_id`),
  KEY `idx_members_user` (`line_user_id`),
  KEY `idx_members_active` (`is_active`),
  CONSTRAINT `line_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `line_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `line_group_messages`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `line_group_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text DEFAULT NULL,
  `message_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gmsg_group` (`group_id`),
  KEY `idx_gmsg_user` (`line_user_id`),
  KEY `idx_gmsg_created` (`created_at`),
  CONSTRAINT `line_group_messages_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `line_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=320 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `line_groups`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_group` (`line_account_id`,`group_id`),
  KEY `idx_groups_account` (`line_account_id`),
  KEY `idx_groups_active` (`is_active`),
  KEY `idx_groups_type` (`group_type`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `link_clicks`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `link_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `link_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `referer` text DEFAULT NULL,
  `clicked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_link` (`link_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `link_clicks_ibfk_1` FOREIGN KEY (`link_id`) REFERENCES `tracked_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `location_movements`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_from_location` (`from_location_id`),
  KEY `idx_to_location` (`to_location_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_movement_type` (`movement_type`),
  CONSTRAINT `fk_movement_batch` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_movement_from_location` FOREIGN KEY (`from_location_id`) REFERENCES `warehouse_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_movement_to_location` FOREIGN KEY (`to_location_id`) REFERENCES `warehouse_locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `loyalty_points`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `loyalty_points` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `line_account_id` int(11) DEFAULT NULL,
  `points` int(11) DEFAULT 0,
  `lifetime_points` int(11) DEFAULT 0,
  `tier` varchar(50) DEFAULT 'bronze',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `loyalty_points_history`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `loyalty_points_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(100) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `type` enum('earn','redeem','adjust','expire') DEFAULT 'earn',
  `description` varchar(255) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------
-- Table: `medical_history`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `medical_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `triage_session_id` int(11) DEFAULT NULL,
  `symptoms` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`symptoms`)),
  `diagnosis` text DEFAULT NULL,
  `medications_prescribed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`medications_prescribed`)),
  `pharmacist_notes` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `triage_session_id` (`triage_session_id`),
  CONSTRAINT `medical_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `medical_history_ibfk_2` FOREIGN KEY (`triage_session_id`) REFERENCES `triage_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `medication_reminders`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `medication_taken_history`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `medication_taken_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reminder_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `taken_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('taken','skipped','missed') DEFAULT 'taken',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reminder` (`reminder_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`taken_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `message_analytics`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `message_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `response_time_seconds` int(11) DEFAULT NULL COMMENT 'Time to respond in seconds',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_message` (`message_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `messages`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  KEY `idx_msg_line_account` (`line_account_id`),
  KEY `idx_msg_user` (`user_id`),
  KEY `idx_msg_created` (`created_at`),
  KEY `idx_is_read` (`is_read`,`direction`),
  KEY `idx_user_direction` (`user_id`,`direction`),
  KEY `idx_account_created` (`line_account_id`,`created_at` DESC),
  KEY `idx_mark_as_read_token` (`mark_as_read_token`),
  KEY `idx_messages_user_created` (`user_id`,`line_account_id`,`created_at` DESC),
  KEY `idx_messages_unread` (`user_id`,`line_account_id`,`direction`,`is_read`),
  KEY `idx_messages_id_user` (`id`,`user_id`,`line_account_id`),
  KEY `idx_user_id_cursor` (`user_id`,`id` DESC),
  KEY `idx_account_created_direction` (`line_account_id`,`created_at` DESC,`direction`),
  KEY `idx_user_unread` (`user_id`,`is_read`,`direction`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6924 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `mims_conversation_state`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `mims_conversation_state` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`state_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `notification_settings`
-- ---------------------------------------------
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
  `notify_admin_users` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `onboarding_sessions`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_admin_user` (`admin_user_id`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `order_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `orders`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `user_id` (`user_id`),
  KEY `idx_order_line_account` (`line_account_id`),
  KEY `idx_order_status` (`status`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `payment_proofs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_proofs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `payment_slips`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_slips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `line_user_id` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `image_url` varchar(500) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `idx_transaction` (`transaction_id`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `payment_vouchers`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_number` (`voucher_number`),
  KEY `idx_pv_type` (`voucher_type`),
  KEY `idx_pv_ref` (`reference_id`),
  KEY `idx_pv_date` (`payment_date`),
  KEY `idx_pv_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `performance_metrics`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `performance_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'LINE account for multi-tenant tracking',
  `metric_type` enum('page_load','conversation_switch','message_render','api_call','scroll_performance','cache_hit','cache_miss') NOT NULL,
  `duration_ms` int(11) NOT NULL COMMENT 'Duration in milliseconds',
  `operation_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional context about the operation' CHECK (json_valid(`operation_details`)),
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_type_created` (`metric_type`,`created_at`),
  KEY `idx_account_type` (`line_account_id`,`metric_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2311 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacist_consultations`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_consult_user` (`user_id`),
  KEY `idx_consult_pharmacist` (`pharmacist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacist_holidays`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pharmacist_holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pharmacist_id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_holiday` (`pharmacist_id`,`holiday_date`),
  CONSTRAINT `pharmacist_holidays_ibfk_1` FOREIGN KEY (`pharmacist_id`) REFERENCES `pharmacists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacist_notifications`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `user_id` (`user_id`),
  KEY `triage_session_id` (`triage_session_id`),
  CONSTRAINT `pharmacist_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pharmacist_notifications_ibfk_2` FOREIGN KEY (`triage_session_id`) REFERENCES `triage_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacist_schedules`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pharmacist_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pharmacist_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '0=Sunday, 6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pharmacist_day` (`pharmacist_id`,`day_of_week`),
  CONSTRAINT `pharmacist_schedules_ibfk_1` FOREIGN KEY (`pharmacist_id`) REFERENCES `pharmacists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacists`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_number` (`license_number`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacy_context_keywords`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pharmacy_context_keywords` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) NOT NULL,
  `keyword_type` enum('symptom','drug','condition','action') NOT NULL,
  `widget_type` enum('drug_info','interaction','symptom','allergy','pricing','pregnancy') NOT NULL,
  `related_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Related drug IDs, condition info, etc.' CHECK (json_valid(`related_data`)),
  `priority` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_keyword` (`keyword`),
  KEY `idx_widget` (`widget_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=235 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pharmacy_ghost_learning`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pharmacy_ghost_learning` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `customer_message` text NOT NULL,
  `ai_draft` text NOT NULL,
  `pharmacist_final` text NOT NULL,
  `edit_distance` int(11) DEFAULT NULL COMMENT 'Levenshtein distance',
  `was_accepted` tinyint(1) DEFAULT 0,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Stage, health profile, symptoms, etc.' CHECK (json_valid(`context`)),
  `mentioned_drugs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Drugs mentioned in conversation' CHECK (json_valid(`mentioned_drugs`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_accepted` (`was_accepted`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `point_rewards`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_reward_active` (`is_active`),
  KEY `idx_reward_points` (`points_required`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `points_campaigns`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_campaign_account` (`line_account_id`),
  KEY `idx_campaign_dates` (`start_date`,`end_date`),
  KEY `idx_campaign_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `points_history`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `points_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `points` int(11) NOT NULL COMMENT 'บวก=ได้รับ, ลบ=ใช้',
  `type` enum('earn','redeem','expire','adjust','bonus') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'order, reward, manual',
  `reference_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `balance_after` int(11) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_points_user` (`user_id`),
  KEY `idx_points_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `points_rules`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_type` (`rule_type`),
  KEY `idx_active` (`is_active`),
  KEY `idx_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `points_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `points_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `points_per_baht` decimal(10,6) DEFAULT 0.001000 COMMENT 'แต้มต่อบาท (รองรับถึง 0.000001)',
  `min_order_for_points` decimal(12,2) DEFAULT 0.00 COMMENT 'ยอดสั่งซื้อขั้นต่ำเพื่อรับแต้ม',
  `points_expiry_days` int(11) DEFAULT 365 COMMENT 'แต้มหมดอายุกี่วัน (0 = ไม่หมดอายุ)',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `points_tiers`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_account` (`line_account_id`),
  KEY `idx_points` (`min_points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `points_transactions`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=273 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_cash_movements`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pos_cash_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `movement_type` enum('in','out') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift` (`shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_daily_summary`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date_account` (`summary_date`,`line_account_id`),
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_payments`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pos_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `payment_method` enum('cash','transfer','card','points','credit') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `cash_received` decimal(12,2) DEFAULT NULL,
  `change_amount` decimal(12,2) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `points_used` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `idx_method` (`payment_method`),
  CONSTRAINT `pos_payments_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `pos_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_return_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pos_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `original_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `return_id` (`return_id`),
  KEY `original_item_id` (`original_item_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `pos_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `pos_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pos_return_items_ibfk_2` FOREIGN KEY (`original_item_id`) REFERENCES `pos_transaction_items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_returns`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `idx_original` (`original_transaction_id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `pos_returns_ibfk_1` FOREIGN KEY (`original_transaction_id`) REFERENCES `pos_transactions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_shifts`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `shift_number` (`shift_number`),
  KEY `idx_cashier` (`cashier_id`),
  KEY `idx_status` (`status`),
  KEY `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_transaction_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `pos_transaction_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_batch` (`batch_id`),
  CONSTRAINT `pos_transaction_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `pos_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `pos_transactions`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_number` (`transaction_number`),
  KEY `idx_shift` (`shift_id`),
  KEY `idx_cashier` (`cashier_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`created_at`),
  KEY `idx_line_account` (`line_account_id`),
  CONSTRAINT `pos_transactions_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `pos_shifts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `prescription_approvals`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_line_account_id` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `prescription_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `prescription_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prescription_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `drug_name` varchar(255) NOT NULL,
  `strength` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `directions` text NOT NULL COMMENT 'วิธีใช้ยา',
  `dispensed_quantity` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prescription` (`prescription_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `prescription_items_ibfk_1` FOREIGN KEY (`prescription_id`) REFERENCES `prescription_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prescription_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `prescription_ocr_results`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `prescription_ocr_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `image_hash` varchar(64) NOT NULL,
  `image_url` text DEFAULT NULL,
  `extracted_drugs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'List of drugs from prescription' CHECK (json_valid(`extracted_drugs`)),
  `doctor_name` varchar(255) DEFAULT NULL,
  `hospital_name` varchar(255) DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `ocr_confidence` decimal(3,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_hash` (`image_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `prescription_records`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `prescription_number` (`prescription_number`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_prescription_number` (`prescription_number`),
  KEY `idx_status` (`status`),
  KEY `idx_prescription_date` (`prescription_date`),
  CONSTRAINT `prescription_records_ibfk_1` FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกใบสั่งแพทย์ (เก็บไว้ 5 ปีตามกฎหมาย)';

-- ---------------------------------------------
-- Table: `product_categories`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_cat_line_account` (`line_account_id`),
  KEY `idx_cny_code` (`cny_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `product_images`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `product_units`
-- ---------------------------------------------
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
  UNIQUE KEY `unique_product_unit` (`product_id`,`unit_name`),
  KEY `idx_product` (`product_id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `products`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `idx_product_line_account` (`line_account_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_barcode` (`barcode`),
  KEY `idx_sort_order` (`sort_order`),
  KEY `idx_is_featured` (`is_featured`),
  KEY `idx_products_featured` (`is_featured`),
  KEY `idx_products_bestseller` (`is_bestseller`),
  KEY `idx_drug_type` (`drug_type`),
  KEY `idx_requires_prescription` (`requires_prescription`),
  KEY `idx_requires_pharmacist` (`requires_pharmacist`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `promotion_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `promotion_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_setting` (`line_account_id`,`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=369 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `purchase_order_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  KEY `idx_poi_po` (`po_id`),
  KEY `idx_poi_product` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `purchase_orders`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `idx_po_number` (`po_number`),
  KEY `idx_po_status` (`status`),
  KEY `idx_po_supplier` (`supplier_id`),
  KEY `idx_po_line_account` (`line_account_id`),
  KEY `idx_po_order_date` (`order_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `quick_reply_templates`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `quick_reply_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `category` varchar(50) DEFAULT '',
  `quick_reply` text DEFAULT NULL COMMENT 'JSON array of LINE Quick Reply items',
  `usage_count` int(11) DEFAULT 0,
  `last_used_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account` (`line_account_id`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `receipt_vouchers`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_number` (`voucher_number`),
  KEY `idx_rv_ar` (`ar_id`),
  KEY `idx_rv_date` (`receipt_date`),
  KEY `idx_rv_line_account` (`line_account_id`),
  CONSTRAINT `fk_rv_ar` FOREIGN KEY (`ar_id`) REFERENCES `account_receivables` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `red_flag_symptoms`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `symptom_code` (`symptom_code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='อาการที่ต้องส่งต่อแพทย์ (Red Flags)';

-- ---------------------------------------------
-- Table: `restock_notifications`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `restock_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wishlist_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `notification_type` varchar(50) DEFAULT 'restock',
  `old_stock` int(11) DEFAULT 0,
  `new_stock` int(11) DEFAULT 0,
  `message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `reward_redemptions`
-- ---------------------------------------------
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
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `rewards`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `rewards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `points_required` int(11) NOT NULL,
  `reward_type` enum('discount','product','voucher','shipping') DEFAULT 'discount',
  `reward_value` decimal(10,2) DEFAULT NULL,
  `stock` int(11) DEFAULT -1 COMMENT '-1 = unlimited',
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `max_per_user` int(11) DEFAULT 0 COMMENT '0 = unlimited',
  `terms` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_reward_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `rich_menu_aliases`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `rich_menu_aliases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `alias_id` varchar(100) NOT NULL COMMENT 'LINE Rich Menu Alias ID',
  `alias_name` varchar(50) NOT NULL COMMENT 'ชื่อ Alias (เช่น member, guest)',
  `rich_menu_id` int(11) NOT NULL,
  `line_rich_menu_id` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_alias` (`line_account_id`,`alias_name`),
  KEY `idx_alias_id` (`alias_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `rich_menu_rules`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `rich_menu_switch_log`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `rich_menu_switch_pages`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `rich_menu_switch_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `switch_set_id` int(11) NOT NULL,
  `page_number` int(11) NOT NULL DEFAULT 1,
  `page_name` varchar(50) NOT NULL,
  `rich_menu_id` int(11) NOT NULL,
  `line_rich_menu_id` varchar(100) DEFAULT NULL,
  `alias_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_page` (`switch_set_id`,`page_number`),
  KEY `idx_set` (`switch_set_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `rich_menu_switch_sets`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `rich_menu_switch_sets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `rich_menus`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_richmenu_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `scheduled_messages`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_scheduled_line_account` (`line_account_id`),
  KEY `idx_scheduled_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `scheduled_report_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_report_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `sent_at` datetime NOT NULL,
  `recipients_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('success','partial','failed') NOT NULL,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`report_data`)),
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_sent` (`report_id`,`sent_at`),
  CONSTRAINT `scheduled_report_logs_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `scheduled_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `scheduled_report_recipients`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_report_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `line_user_id` varchar(50) DEFAULT NULL COMMENT 'LINE User ID for push message',
  `notify_method` enum('line','email','both') NOT NULL DEFAULT 'line',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_report_recipient` (`report_id`,`admin_user_id`),
  CONSTRAINT `scheduled_report_recipients_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `scheduled_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `scheduled_reports`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_report_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `segment_members`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `segment_members` (
  `segment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `score` decimal(10,2) DEFAULT 0.00,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`segment_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `segment_members_ibfk_1` FOREIGN KEY (`segment_id`) REFERENCES `customer_segments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `segment_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(20) DEFAULT 'string',
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `setup_progress`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `setup_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) NOT NULL,
  `item_key` varchar(50) NOT NULL,
  `status` enum('pending','in_progress','completed','skipped') DEFAULT 'pending',
  `completed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_progress` (`line_account_id`,`item_key`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `shared_flex_messages`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_code` (`share_code`),
  KEY `idx_share_code` (`share_code`),
  KEY `idx_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `shop_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `shop_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `shop_name` varchar(255) DEFAULT 'LINE Shop',
  `shop_logo` varchar(500) DEFAULT NULL,
  `welcome_message` text DEFAULT NULL,
  `shipping_fee` decimal(10,2) DEFAULT 50.00,
  `free_shipping_min` decimal(10,2) DEFAULT 500.00,
  `bank_accounts` text DEFAULT NULL,
  `promptpay_number` varchar(20) DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shop_line_account` (`line_account_id`),
  KEY `idx_shop_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `stock_adjustments`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `adjustment_number` (`adjustment_number`),
  KEY `idx_adj_number` (`adjustment_number`),
  KEY `idx_adj_product` (`product_id`),
  KEY `idx_adj_line_account` (`line_account_id`),
  KEY `idx_adj_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `stock_movements`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_sm_product` (`product_id`),
  KEY `idx_sm_type` (`movement_type`),
  KEY `idx_sm_reference` (`reference_type`,`reference_id`),
  KEY `idx_sm_created` (`created_at`),
  KEY `idx_sm_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `suppliers`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_supplier_code` (`code`),
  KEY `idx_supplier_active` (`is_active`),
  KEY `idx_supplier_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `symptom_analysis_cache`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `symptom_analysis_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `image_hash` varchar(64) NOT NULL,
  `image_url` text DEFAULT NULL,
  `analysis_result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Condition, severity, recommendations' CHECK (json_valid(`analysis_result`)),
  `is_urgent` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `image_hash` (`image_hash`),
  KEY `idx_hash` (`image_hash`),
  KEY `idx_urgent` (`is_urgent`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `symptom_assessment_followups`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `symptom_assessment_followups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assessment_id` int(11) NOT NULL,
  `followup_date` date NOT NULL,
  `status` enum('pending','improved','same','worse','consulted_doctor') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_assessment_id` (`assessment_id`),
  KEY `idx_followup_date` (`followup_date`),
  CONSTRAINT `symptom_assessment_followups_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `symptom_assessments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `symptom_assessments`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_assessment_user` (`user_id`),
  KEY `idx_assessment_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `sync_batches`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_name` varchar(255) NOT NULL,
  `total_jobs` int(11) DEFAULT 0,
  `completed_jobs` int(11) DEFAULT 0,
  `failed_jobs` int(11) DEFAULT 0,
  `skipped_jobs` int(11) DEFAULT 0,
  `status` enum('pending','running','completed','failed') DEFAULT 'pending',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `sync_config`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_config` (
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `sync_logs`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `sync_queue`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sku` (`sku`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `tags`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT 'gray',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `telegram_settings`
-- ---------------------------------------------
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `temperature_logs`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_location` (`location_id`),
  KEY `idx_recorded_date` (`recorded_at`),
  CONSTRAINT `temperature_logs_ibfk_1` FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกอุณหภูมิการเก็บรักษา (เก็บไว้ 1 ปี)';

-- ---------------------------------------------
-- Table: `templates`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `testing_results`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `testing_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `test_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`test_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------
-- Table: `tier_settings`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_tier_account` (`line_account_id`),
  KEY `idx_tier_points` (`min_points`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `tracked_links`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_code` (`short_code`),
  KEY `idx_short_code` (`short_code`),
  KEY `idx_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `transaction_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `transaction_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_sku` varchar(100) DEFAULT NULL,
  `product_price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_transaction` (`transaction_id`),
  KEY `idx_item_product` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `transactions`
-- ---------------------------------------------
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
  `status` enum('pending','confirmed','paid','preparing','shipping','delivered','cancelled','refunded') DEFAULT 'pending',
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_order_line_account` (`line_account_id`),
  KEY `idx_order_user` (`user_id`),
  KEY `idx_order_status` (`status`),
  KEY `idx_order_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `triage_analytics`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_date_account` (`date`,`line_account_id`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `triage_sessions`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `triage_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `current_state` varchar(50) DEFAULT 'greeting',
  `triage_data` longtext DEFAULT NULL,
  `status` enum('active','completed','escalated','expired') DEFAULT 'active',
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
  PRIMARY KEY (`id`),
  KEY `idx_triage_user` (`user_id`),
  KEY `idx_triage_level` (`triage_level`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_behaviors`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_user_behavior` (`user_id`,`behavior_type`),
  KEY `idx_account_behavior` (`line_account_id`,`behavior_type`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `user_behaviors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_consents`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_consents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_consent` (`user_id`,`consent_type`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_current_medications`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_custom_field_values`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_custom_field_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `field_id` int(11) NOT NULL,
  `field_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_field` (`user_id`,`field_id`),
  KEY `idx_field_values_user` (`user_id`),
  KEY `idx_field_values_field` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_custom_fields`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_field_key` (`line_account_id`,`field_key`),
  KEY `idx_custom_fields_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_drug_allergies`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_drug` (`drug_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_groups`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_groups` (
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`group_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `user_groups_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_health_profiles`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_health_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_user_id` varchar(50) NOT NULL,
  `line_account_id` int(11) DEFAULT 0,
  `age` int(11) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `blood_type` enum('A','B','AB','O','unknown') DEFAULT 'unknown',
  `medical_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`medical_conditions`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`line_user_id`,`line_account_id`),
  KEY `idx_line_user` (`line_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_notes`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=723 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `user_notification_preferences`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  KEY `idx_line_user` (`line_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_notification_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notification_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_updates` tinyint(1) DEFAULT 1,
  `promotions` tinyint(1) DEFAULT 1,
  `appointment_reminders` tinyint(1) DEFAULT 1,
  `drug_reminders` tinyint(1) DEFAULT 1,
  `health_tips` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_points`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_account` (`user_id`,`line_account_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------
-- Table: `user_profiles_extended`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_profiles_extended` (
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
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_profiles_extended_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ---------------------------------------------
-- Table: `user_rich_menus`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`line_account_id`,`user_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_rich_menu` (`rich_menu_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_states`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_states` (
  `user_id` int(11) NOT NULL,
  `state` varchar(50) NOT NULL,
  `state_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`state_data`)),
  `expires_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_tag_assignments`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `user_tag_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `assigned_by` varchar(50) DEFAULT 'manual' COMMENT 'manual, auto, system, campaign',
  `assigned_reason` text DEFAULT NULL,
  `score` int(11) DEFAULT 0 COMMENT 'คะแนนความสนใจ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Tag หมดอายุเมื่อไหร่',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_tag` (`user_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`),
  CONSTRAINT `user_tag_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_tag_assignments_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `user_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2378 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_tags`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tag_name` (`line_account_id`,`name`),
  KEY `idx_account_tag` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `user_wishlist`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  KEY `idx_line_user` (`line_user_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_notify` (`notify_on_sale`,`notified_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `users`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `line_user_id` varchar(50) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_line_user` (`line_account_id`,`line_user_id`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_email` (`email`),
  KEY `idx_birthday` (`birthday`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_account_last_msg` (`line_account_id`,`last_message_at` DESC),
  KEY `idx_users_chat_status` (`chat_status`),
  KEY `idx_users_line_account` (`line_account_id`),
  KEY `idx_account_last_msg_cover` (`line_account_id`,`last_message_at` DESC,`id`,`display_name`(100),`unread_count`)
) ENGINE=InnoDB AUTO_INCREMENT=892 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `v_batch_progress`
-- ---------------------------------------------
CREATE ALGORITHM=UNDEFINED DEFINER=`zrismpsz`@`localhost` SQL SECURITY DEFINER VIEW `v_batch_progress` AS select `b`.`id` AS `id`,`b`.`batch_name` AS `batch_name`,`b`.`total_jobs` AS `total_jobs`,`b`.`completed_jobs` AS `completed_jobs`,`b`.`failed_jobs` AS `failed_jobs`,`b`.`skipped_jobs` AS `skipped_jobs`,`b`.`status` AS `status`,`b`.`started_at` AS `started_at`,`b`.`completed_at` AS `completed_at`,`b`.`created_at` AS `created_at`,round((`b`.`completed_jobs` + `b`.`failed_jobs` + `b`.`skipped_jobs`) / nullif(`b`.`total_jobs`,0) * 100,2) AS `progress_percent`,timestampdiff(SECOND,`b`.`started_at`,coalesce(`b`.`completed_at`,current_timestamp())) AS `duration_seconds` from `sync_batches` `b`;

-- ---------------------------------------------
-- Table: `v_business_items`
-- ---------------------------------------------
CREATE ALGORITHM=UNDEFINED DEFINER=`zrismpsz`@`localhost` SQL SECURITY DEFINER VIEW `v_business_items` AS select `products`.`id` AS `id`,`products`.`line_account_id` AS `line_account_id`,`products`.`item_type` AS `item_type`,`products`.`category_id` AS `category_id`,`products`.`name` AS `name`,`products`.`description` AS `description`,`products`.`price` AS `price`,`products`.`sale_price` AS `sale_price`,`products`.`image_url` AS `image_url`,`products`.`action_data` AS `action_data`,`products`.`delivery_method` AS `delivery_method`,`products`.`validity_days` AS `validity_days`,`products`.`max_quantity` AS `max_quantity`,`products`.`stock` AS `stock`,`products`.`sku` AS `sku`,`products`.`is_active` AS `is_active`,`products`.`sort_order` AS `sort_order`,`products`.`created_at` AS `created_at`,`products`.`updated_at` AS `updated_at`,`products`.`barcode` AS `barcode`,`products`.`manufacturer` AS `manufacturer`,`products`.`generic_name` AS `generic_name`,`products`.`usage_instructions` AS `usage_instructions`,`products`.`unit` AS `unit`,`products`.`extra_data` AS `extra_data` from `products`;

-- ---------------------------------------------
-- Table: `v_queue_summary`
-- ---------------------------------------------
CREATE ALGORITHM=UNDEFINED DEFINER=`zrismpsz`@`localhost` SQL SECURITY DEFINER VIEW `v_queue_summary` AS select `sync_queue`.`status` AS `status`,count(0) AS `count`,avg(`sync_queue`.`attempts`) AS `avg_attempts` from `sync_queue` group by `sync_queue`.`status`;

-- ---------------------------------------------
-- Table: `v_user_tags_with_count`
-- ---------------------------------------------
CREATE ALGORITHM=UNDEFINED DEFINER=`zrismpsz`@`localhost` SQL SECURITY DEFINER VIEW `v_user_tags_with_count` AS select `t`.`id` AS `id`,`t`.`line_account_id` AS `line_account_id`,`t`.`name` AS `name`,`t`.`color` AS `color`,`t`.`description` AS `description`,`t`.`tag_type` AS `tag_type`,`t`.`auto_assign_rules` AS `auto_assign_rules`,`t`.`auto_remove_rules` AS `auto_remove_rules`,`t`.`source_type` AS `source_type`,`t`.`source_id` AS `source_id`,`t`.`priority` AS `priority`,`t`.`created_at` AS `created_at`,coalesce(count(distinct `uta`.`user_id`),0) AS `user_count` from (`user_tags` `t` left join `user_tag_assignments` `uta` on(`t`.`id` = `uta`.`tag_id`)) group by `t`.`id`;

-- ---------------------------------------------
-- Table: `v_users_with_tags`
-- ---------------------------------------------
CREATE ALGORITHM=UNDEFINED DEFINER=`zrismpsz`@`localhost` SQL SECURITY DEFINER VIEW `v_users_with_tags` AS select `u`.`id` AS `id`,`u`.`display_name` AS `display_name`,`u`.`line_user_id` AS `line_user_id`,`u`.`line_account_id` AS `line_account_id`,group_concat(`t`.`name` separator ', ') AS `tags`,group_concat(`t`.`color` separator ', ') AS `tag_colors` from ((`users` `u` left join `user_tag_assignments` `uta` on(`u`.`id` = `uta`.`user_id`)) left join `user_tags` `t` on(`uta`.`tag_id` = `t`.`id`)) group by `u`.`id`;

-- ---------------------------------------------
-- Table: `vibe_selling_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `vibe_selling_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vibe_setting` (`line_account_id`,`setting_key`),
  KEY `idx_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `video_call_settings`
-- ---------------------------------------------
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`line_account_id`),
  CONSTRAINT `video_call_settings_ibfk_1` FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `video_call_signals`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `video_call_signals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `call_id` int(11) NOT NULL,
  `signal_type` varchar(50) NOT NULL COMMENT 'offer, answer, ice-candidate',
  `signal_data` longtext NOT NULL,
  `from_who` varchar(20) DEFAULT 'customer',
  `processed` tinyint(1) DEFAULT 0,
  `sender_type` enum('admin','customer') DEFAULT 'customer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_call` (`call_id`),
  KEY `idx_signal_poll` (`call_id`,`from_who`,`processed`),
  CONSTRAINT `video_call_signals_ibfk_1` FOREIGN KEY (`call_id`) REFERENCES `video_calls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `video_calls`
-- ---------------------------------------------
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
  `status` enum('waiting','active','ended','missed') DEFAULT 'waiting',
  `duration` int(11) DEFAULT 0 COMMENT 'Duration in seconds',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_id` (`room_id`),
  KEY `idx_video_user` (`user_id`),
  KEY `idx_video_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `warehouse_locations`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `warehouse_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT 1,
  `location_code` varchar(20) NOT NULL,
  `zone` varchar(10) NOT NULL,
  `shelf` int(11) NOT NULL,
  `bin` int(11) NOT NULL,
  `zone_type` varchar(50) NOT NULL DEFAULT 'general',
  `ergonomic_level` enum('golden','upper','lower') DEFAULT 'golden',
  `capacity` int(11) DEFAULT 100,
  `current_qty` int(11) DEFAULT 0,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_location_code` (`location_code`,`line_account_id`),
  KEY `idx_zone` (`zone`),
  KEY `idx_zone_type` (`zone_type`),
  KEY `idx_location_code` (`location_code`),
  KEY `idx_line_account` (`line_account_id`),
  KEY `idx_ergonomic` (`ergonomic_level`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `webhook_events`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id` (`event_id`),
  KEY `idx_webhook_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6289 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `welcome_settings`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `welcome_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `message_type` enum('text','flex') DEFAULT 'text',
  `text_content` text DEFAULT NULL,
  `flex_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`flex_content`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `enabled` tinyint(1) DEFAULT 1,
  `text_message` text DEFAULT NULL,
  `flex_json` longtext DEFAULT NULL,
  `delay_seconds` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_welcome_line_account` (`line_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `wishlist`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `wishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_wishlist` (`user_id`,`product_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `wishlist_notifications`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `wishlist_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wishlist_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `notification_type` enum('price_drop','promotion','restock') NOT NULL,
  `old_price` decimal(10,2) DEFAULT NULL,
  `new_price` decimal(10,2) DEFAULT NULL,
  `discount_percent` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wishlist` (`wishlist_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `wms_activity_logs`
-- ---------------------------------------------
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
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `wms_batch_pick_orders`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `wms_batch_pick_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `pick_status` enum('pending','picked') DEFAULT 'pending',
  `picked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_order` (`batch_id`,`order_id`),
  KEY `idx_batch_pick_order` (`order_id`),
  CONSTRAINT `wms_batch_pick_orders_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `wms_batch_picks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `wms_batch_picks`
-- ---------------------------------------------
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

-- ---------------------------------------------
-- Table: `wms_pick_items`
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS `wms_pick_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_item` (`order_id`,`transaction_item_id`),
  KEY `idx_pick_item_order` (`order_id`),
  KEY `idx_pick_item_product` (`product_id`),
  KEY `idx_pick_item_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- Table: `zone_types`
-- ---------------------------------------------
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_zone_type_code` (`line_account_id`,`code`),
  KEY `idx_zone_type_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
