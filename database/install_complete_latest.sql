-- =============================================
-- LINE Telepharmacy CRM - Complete Installation SQL
-- Version: 3.2 (Latest)
-- Generated: 2026-01-19
-- Description: รวมทุกตาราง + migrations ล่าสุดสำหรับติดตั้งระบบใหม่
-- =============================================

-- INSTRUCTIONS:
-- 1. สร้าง database ใหม่: CREATE DATABASE telepharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- 2. รัน script นี้: mysql -u user -p telepharmacy < install_complete_latest.sql
-- 3. ตรวจสอบว่า import สำเร็จ: SHOW TABLES;

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- Drop existing tables if re-installing (ระวัง: จะลบข้อมูล!)
-- DROP TABLE IF EXISTS ...

START TRANSACTION;

-- =============================================
-- SECTION 1: CORE TABLES
-- =============================================

-- LINE Accounts (Multi-bot support)
CREATE TABLE IF NOT EXISTS `line_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL COMMENT 'ชื่อบัญชี LINE OA',
    `channel_id` VARCHAR(100) COMMENT 'Channel ID',
    `channel_secret` VARCHAR(100) NOT NULL COMMENT 'Channel Secret',
    `channel_access_token` TEXT NOT NULL COMMENT 'Channel Access Token',
    `webhook_url` VARCHAR(500) COMMENT 'Webhook URL',
    `basic_id` VARCHAR(50) COMMENT 'LINE Basic ID (@xxx)',
    `picture_url` VARCHAR(500) COMMENT 'รูปโปรไฟล์',
    `liff_id` VARCHAR(100) COMMENT 'LIFF ID หลัก',
    `liff_share_id` VARCHAR(100) COMMENT 'LIFF Share ID',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0 COMMENT 'บัญชีหลัก',
    `settings` JSON COMMENT 'ตั้งค่าเพิ่มเติม',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_channel_secret` (`channel_secret`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บัญชี LINE OA';

-- Admin Users (System users)
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) UNIQUE NOT NULL,
    `email` VARCHAR(255) UNIQUE NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(255),
    `avatar_url` VARCHAR(500),
    `role` ENUM('super_admin', 'admin', 'pharmacist', 'staff', 'user') DEFAULT 'user',
    `line_account_id` INT DEFAULT NULL COMMENT 'บัญชี LINE ที่รับผิดชอบ (NULL = ทุกบัญชี)',
    `permissions` JSON COMMENT 'สิทธิ์เพิ่มเติม',
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_admin_role` (`role`),
    INDEX `idx_admin_line_account` (`line_account_id`),
    INDEX `idx_admin_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ผู้ดูแลระบบ';

-- LINE Users (Customers)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `line_user_id` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(255),
    `picture_url` TEXT,
    `status_message` TEXT,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `birth_date` DATE DEFAULT NULL,
    `gender` VARCHAR(10) DEFAULT NULL,
    `weight` DECIMAL(5,2) DEFAULT NULL COMMENT 'น้ำหนัก (กก.)',
    `height` DECIMAL(5,2) DEFAULT NULL COMMENT 'ส่วนสูง (ซม.)',
    `address` TEXT DEFAULT NULL,
    `district` VARCHAR(100) DEFAULT NULL,
    `province` VARCHAR(100) DEFAULT NULL,
    `postal_code` VARCHAR(10) DEFAULT NULL,
    `member_id` VARCHAR(20) DEFAULT NULL UNIQUE,
    `is_registered` TINYINT(1) DEFAULT 0,
    `registered_at` DATETIME DEFAULT NULL,
    `is_blocked` TINYINT(1) DEFAULT 0,
    `is_member` TINYINT(1) DEFAULT 0,
    `member_since` DATETIME DEFAULT NULL,
    `membership_level` VARCHAR(20) DEFAULT 'bronze',
    `tier` VARCHAR(20) DEFAULT 'bronze',
    `points` INT DEFAULT 0 COMMENT 'คะแนนปัจจุบัน',
    `total_points` INT DEFAULT 0 COMMENT 'คะแนนสะสมทั้งหมด',
    `available_points` INT DEFAULT 0 COMMENT 'คะแนนที่ใช้ได้',
    `used_points` INT DEFAULT 0 COMMENT 'คะแนนที่ใช้ไปแล้ว',
    `loyalty_points` INT DEFAULT 0,
    `tier_id` INT DEFAULT NULL,
    `tier_updated_at` TIMESTAMP NULL DEFAULT NULL,
    `total_spent` DECIMAL(12,2) DEFAULT 0 COMMENT 'ยอดซื้อสะสม',
    `order_count` INT DEFAULT 0 COMMENT 'จำนวนออเดอร์',
    `drug_allergies` TEXT DEFAULT NULL COMMENT 'ยาที่แพ้',
    `chronic_diseases` TEXT DEFAULT NULL COMMENT 'โรคประจำตัว',
    `current_medications` TEXT DEFAULT NULL COMMENT 'ยาที่ใช้ประจำ',
    `medical_conditions` TEXT DEFAULT NULL COMMENT 'ข้อมูลการแพทย์เพิ่มเติม',
    `reply_token` VARCHAR(255),
    `reply_token_expires` DATETIME,
    `last_interaction_at` TIMESTAMP NULL COMMENT 'ครั้งล่าสุดที่ติดต่อ',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_line_user` (`line_account_id`, `line_user_id`),
    INDEX `idx_user_phone` (`phone`),
    INDEX `idx_user_email` (`email`),
    INDEX `idx_user_member_id` (`member_id`),
    INDEX `idx_user_blocked` (`is_blocked`),
    INDEX `idx_user_registered` (`is_registered`),
    INDEX `idx_user_member` (`is_member`),
    INDEX `idx_user_tier` (`tier`),
    INDEX `idx_user_last_interaction` (`last_interaction_at`),
    INDEX `idx_account_status` (`line_account_id`, `is_blocked`, `last_interaction_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ผู้ใช้ LINE';

-- Members (Extended user info)
CREATE TABLE IF NOT EXISTS `members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `member_id` VARCHAR(20) UNIQUE,
    `full_name` VARCHAR(255),
    `birth_date` DATE,
    `gender` ENUM('male', 'female', 'other'),
    `phone` VARCHAR(20),
    `email` VARCHAR(255),
    `address` TEXT,
    `district` VARCHAR(100),
    `province` VARCHAR(100),
    `postal_code` VARCHAR(10),
    `tier` VARCHAR(20) DEFAULT 'bronze',
    `points` INT DEFAULT 0,
    `total_spent` DECIMAL(12,2) DEFAULT 0,
    `order_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_member_user` (`user_id`),
    INDEX `idx_member_tier` (`tier`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อมูลสมาชิก';

-- Health Profiles
CREATE TABLE IF NOT EXISTS `health_profiles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `weight` DECIMAL(5,2) COMMENT 'น้ำหนัก (กก.)',
    `height` DECIMAL(5,2) COMMENT 'ส่วนสูง (ซม.)',
    `bmi` DECIMAL(5,2) COMMENT 'BMI คำนวณอัตโนมัติ',
    `blood_group` VARCHAR(5) COMMENT 'หมู่เลือด',
    `allergies` JSON COMMENT 'ยาที่แพ้',
    `chronic_diseases` JSON COMMENT 'โรคประจำตัว',
    `current_medications` JSON COMMENT 'ยาที่ใช้ประจำ',
    `medical_history` TEXT COMMENT 'ประวัติการรักษา',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_health_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อมูลสุขภาพ';

-- =============================================
-- SECTION 2: MESSAGING TABLES
-- =============================================

-- Messages (Chat history)
CREATE TABLE IF NOT EXISTS `messages` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `line_user_id` VARCHAR(50) NOT NULL,
    `message_id` VARCHAR(100),
    `message_type` VARCHAR(20) DEFAULT 'text',
    `message_text` TEXT,
    `image_url` TEXT,
    `video_url` TEXT,
    `audio_url` TEXT,
    `file_url` TEXT,
    `sticker_package_id` VARCHAR(50),
    `sticker_id` VARCHAR(50),
    `latitude` DECIMAL(10, 8),
    `longitude` DECIMAL(11, 8),
    `address` TEXT,
    `is_from_user` TINYINT(1) DEFAULT 1,
    `is_read` TINYINT(1) DEFAULT 0,
    `is_important` TINYINT(1) DEFAULT 0,
    `sent_by_admin_id` INT DEFAULT NULL,
    `reply_token` VARCHAR(255),
    `raw_data` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_msg_line_account` (`line_account_id`),
    INDEX `idx_msg_line_user` (`line_user_id`),
    INDEX `idx_msg_is_from_user` (`is_from_user`),
    INDEX `idx_msg_is_read` (`is_read`),
    INDEX `idx_msg_created` (`created_at`),
    INDEX `idx_msg_user_latest` (`line_user_id`, `created_at` DESC),
    INDEX `idx_unread_filter` (`line_account_id`, `is_from_user`, `is_read`, `created_at`),
    INDEX `idx_search` (`line_account_id`, `message_text`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อความ';

-- Conversation Assignments (Multi-assignee support)
CREATE TABLE IF NOT EXISTS `conversation_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `line_user_id` VARCHAR(50) NOT NULL,
    `assigned_to_admin_id` INT NOT NULL,
    `assigned_by_admin_id` INT DEFAULT NULL,
    `is_primary` TINYINT(1) DEFAULT 0 COMMENT 'ผู้รับผิดชอบหลัก',
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `unassigned_at` TIMESTAMP NULL,
    INDEX `idx_assignment_user` (`line_user_id`),
    INDEX `idx_assignment_admin` (`assigned_to_admin_id`),
    INDEX `idx_assignment_primary` (`line_user_id`, `is_primary`),
    FOREIGN KEY (`assigned_to_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การมอบหมายแชท';

-- User Notes (Internal notes)
CREATE TABLE IF NOT EXISTS `user_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `admin_id` INT NOT NULL,
    `note` TEXT NOT NULL,
    `is_important` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_note_user` (`user_id`),
    INDEX `idx_note_admin` (`admin_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกภายใน';

-- =============================================
-- SECTION 3: CRM & MARKETING TABLES
-- =============================================

-- User Tags
CREATE TABLE IF NOT EXISTS `user_tags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `tag_name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tag_user` (`user_id`),
    INDEX `idx_tag_name` (`tag_name`),
    UNIQUE KEY `unique_user_tag` (`user_id`, `tag_name`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tags ของผู้ใช้';

-- Broadcasts (Campaigns)
CREATE TABLE IF NOT EXISTS `broadcasts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `message_type` VARCHAR(20) DEFAULT 'text',
    `message_content` TEXT,
    `flex_message` JSON,
    `target_type` ENUM('all', 'tags', 'segment', 'custom') DEFAULT 'all',
    `target_tags` JSON COMMENT 'Tags ที่เป้าหมาย',
    `target_segment` VARCHAR(100),
    `target_custom_query` TEXT,
    `scheduled_at` DATETIME,
    `status` ENUM('draft', 'pending', 'sending', 'completed', 'failed') DEFAULT 'draft',
    `total_recipients` INT DEFAULT 0,
    `total_sent` INT DEFAULT 0,
    `total_delivered` INT DEFAULT 0,
    `total_clicked` INT DEFAULT 0,
    `total_failed` INT DEFAULT 0,
    `created_by_admin_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_broadcast_account` (`line_account_id`),
    INDEX `idx_broadcast_status` (`status`),
    INDEX `idx_broadcast_scheduled` (`scheduled_at`),
    FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แคมเปญ Broadcast';

-- Broadcast Logs
CREATE TABLE IF NOT EXISTS `broadcast_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `broadcast_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `line_user_id` VARCHAR(50) NOT NULL,
    `status` ENUM('sent', 'delivered', 'failed') DEFAULT 'sent',
    `error_message` TEXT,
    `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_log_broadcast` (`broadcast_id`),
    INDEX `idx_log_user` (`user_id`),
    INDEX `idx_log_status` (`status`),
    FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการส่ง Broadcast';

-- Auto Reply Rules (Latest version with priority)
CREATE TABLE IF NOT EXISTS `auto_reply_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `rule_name` VARCHAR(255) NOT NULL,
    `keywords` TEXT NOT NULL COMMENT 'คำหลักที่ตรวจจับ (JSON array)',
    `match_type` ENUM('exact', 'contains', 'starts_with', 'ends_with', 'regex') DEFAULT 'contains',
    `reply_type` ENUM('text', 'image', 'flex', 'quick_reply') DEFAULT 'text',
    `reply_content` TEXT NOT NULL,
    `flex_message` JSON,
    `quick_reply` JSON COMMENT 'Quick reply items',
    `priority` INT DEFAULT 0 COMMENT 'ลำดับความสำคัญ (เลขสูง = ทำก่อน)',
    `is_active` TINYINT(1) DEFAULT 1,
    `trigger_count` INT DEFAULT 0 COMMENT 'จำนวนครั้งที่ถูก trigger',
    `created_by_admin_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_rule_account` (`line_account_id`),
    INDEX `idx_rule_active` (`is_active`),
    INDEX `idx_rule_priority` (`priority` DESC),
    FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กฎ Auto Reply';

-- Drip Campaigns
CREATE TABLE IF NOT EXISTS `drip_campaigns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `trigger_type` ENUM('user_follow', 'user_register', 'first_purchase', 'manual') DEFAULT 'manual',
    `messages` JSON COMMENT 'Array of messages with delays',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_drip_account` (`line_account_id`),
    INDEX `idx_drip_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Drip Marketing Campaigns';

-- Drip Subscribers
CREATE TABLE IF NOT EXISTS `drip_subscribers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `current_step` INT DEFAULT 0,
    `next_send_at` DATETIME,
    `status` ENUM('active', 'paused', 'completed', 'unsubscribed') DEFAULT 'active',
    `subscribed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_drip_sub_campaign` (`campaign_id`),
    INDEX `idx_drip_sub_user` (`user_id`),
    INDEX `idx_drip_sub_next_send` (`next_send_at`),
    FOREIGN KEY (`campaign_id`) REFERENCES `drip_campaigns`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ผู้ติดตาม Drip Campaign';

-- =============================================
-- SECTION 4: E-COMMERCE TABLES
-- =============================================

-- Shop Settings
CREATE TABLE IF NOT EXISTS `shop_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL UNIQUE,
    `shop_name` VARCHAR(255),
    `shop_logo` VARCHAR(500),
    `shop_description` TEXT,
    `contact_phone` VARCHAR(20),
    `contact_email` VARCHAR(255),
    `contact_line` VARCHAR(100),
    `address` TEXT,
    `business_hours` TEXT,
    `theme_color` VARCHAR(10) DEFAULT '#00B900',
    `currency` VARCHAR(5) DEFAULT 'THB',
    `tax_rate` DECIMAL(5,2) DEFAULT 0,
    `shipping_fee` DECIMAL(10,2) DEFAULT 0,
    `free_shipping_threshold` DECIMAL(10,2) DEFAULT 0,
    `payment_methods` JSON COMMENT 'วิธีชำระเงิน',
    `promptpay_qr` VARCHAR(500) COMMENT 'PromptPay QR Code',
    `bank_accounts` JSON COMMENT 'บัญชีธนาคาร',
    `policies` JSON COMMENT 'นโยบาย',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตั้งค่าร้านค้า';

-- Products
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `sku` VARCHAR(100),
    `barcode` VARCHAR(100),
    `name` VARCHAR(255) NOT NULL,
    `name_en` VARCHAR(255),
    `description` TEXT,
    `description_en` TEXT,
    `description_html` TEXT COMMENT 'HTML description',
    `category` VARCHAR(100),
    `category_en` VARCHAR(100),
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `sale_price` DECIMAL(10,2),
    `cost` DECIMAL(10,2),
    `stock` INT DEFAULT 0,
    `min_stock` INT DEFAULT 5,
    `unit` VARCHAR(50) DEFAULT 'piece',
    `weight` DECIMAL(10,2),
    `dimensions` VARCHAR(100),
    `image_url` TEXT,
    `images` JSON COMMENT 'Multiple images',
    `is_active` TINYINT(1) DEFAULT 1,
    `is_featured` TINYINT(1) DEFAULT 0,
    `is_prescription` TINYINT(1) DEFAULT 0 COMMENT 'ต้องใช้ใบสั่งแพทย์',
    `drug_class` VARCHAR(100) COMMENT 'หมวดยา',
    `drug_form` VARCHAR(100) COMMENT 'รูปแบบยา',
    `drug_dosage` VARCHAR(100) COMMENT 'ขนาดยา',
    `drug_ingredients` TEXT COMMENT 'ส่วนประกอบ',
    `drug_warnings` TEXT COMMENT 'คำเตือน',
    `drug_interactions` TEXT COMMENT 'ปฏิกิริยายา',
    `storage_condition` VARCHAR(100) COMMENT 'เงื่อนไขการเก็บ',
    `cny_product_id` VARCHAR(100) COMMENT 'CNY API Product ID',
    `meta_title` VARCHAR(255),
    `meta_description` TEXT,
    `meta_keywords` TEXT,
    `view_count` INT DEFAULT 0,
    `order_count` INT DEFAULT 0,
    `rating` DECIMAL(3,2) DEFAULT 0,
    `reviews_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_product_account` (`line_account_id`),
    INDEX `idx_product_sku` (`sku`),
    INDEX `idx_product_barcode` (`barcode`),
    INDEX `idx_product_category` (`category`),
    INDEX `idx_product_active` (`is_active`),
    INDEX `idx_product_featured` (`is_featured`),
    INDEX `idx_product_prescription` (`is_prescription`),
    INDEX `idx_product_stock` (`stock`),
    UNIQUE KEY `unique_product_sku` (`line_account_id`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='สินค้า';

-- Orders
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `status` ENUM('pending', 'pending_verification', 'paid', 'preparing', 'shipped', 'delivered', 'cancelled', 'refunded') DEFAULT 'pending',
    `payment_method` VARCHAR(50),
    `payment_status` ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    `payment_slip_url` VARCHAR(500),
    `shipping_method` VARCHAR(50),
    `shipping_address` TEXT,
    `shipping_name` VARCHAR(255),
    `shipping_phone` VARCHAR(20),
    `tracking_number` VARCHAR(100),
    `subtotal` DECIMAL(12,2) DEFAULT 0,
    `shipping_fee` DECIMAL(10,2) DEFAULT 0,
    `tax` DECIMAL(10,2) DEFAULT 0,
    `discount` DECIMAL(10,2) DEFAULT 0,
    `points_used` INT DEFAULT 0,
    `points_discount` DECIMAL(10,2) DEFAULT 0,
    `total` DECIMAL(12,2) NOT NULL,
    `notes` TEXT COMMENT 'บันทึกจากลูกค้า',
    `admin_notes` TEXT COMMENT 'บันทึกภายใน',
    `requires_prescription` TINYINT(1) DEFAULT 0,
    `prescription_url` VARCHAR(500),
    `prescription_verified` TINYINT(1) DEFAULT 0,
    `verified_by_admin_id` INT,
    `paid_at` DATETIME,
    `shipped_at` DATETIME,
    `delivered_at` DATETIME,
    `cancelled_at` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order_account` (`line_account_id`),
    INDEX `idx_order_user` (`user_id`),
    INDEX `idx_order_number` (`order_number`),
    INDEX `idx_order_status` (`status`),
    INDEX `idx_order_payment` (`payment_status`),
    INDEX `idx_status_date` (`status`, `created_at`),
    INDEX `idx_user_orders` (`user_id`, `created_at` DESC),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำสั่งซื้อ';

-- Order Items
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `product_sku` VARCHAR(100),
    `product_image` VARCHAR(500),
    `quantity` INT NOT NULL DEFAULT 1,
    `price` DECIMAL(10,2) NOT NULL,
    `subtotal` DECIMAL(12,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_item_order` (`order_id`),
    INDEX `idx_item_product` (`product_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รายการสินค้าในออเดอร์';

-- Cart Items
CREATE TABLE IF NOT EXISTS `cart_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cart_user` (`user_id`),
    INDEX `idx_cart_product` (`product_id`),
    UNIQUE KEY `unique_cart_item` (`user_id`, `product_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตะกร้าสินค้า';

-- =============================================
-- SECTION 5: LOYALTY PROGRAM TABLES
-- =============================================

-- Points Transactions
CREATE TABLE IF NOT EXISTS `points_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `points` INT NOT NULL COMMENT 'จำนวนแต้ม (+/-)' ,
    `type` ENUM('earn', 'redeem', 'expire', 'adjust') DEFAULT 'earn',
    `reason` VARCHAR(255),
    `reference_type` VARCHAR(50) COMMENT 'order, reward, manual',
    `reference_id` INT COMMENT 'ID ของ order หรือ reward',
    `balance_after` INT COMMENT 'คะแนนคงเหลือหลังทำรายการ',
    `expiry_date` DATE COMMENT 'วันหมดอายุของแต้ม',
    `is_expired` TINYINT(1) DEFAULT 0,
    `admin_id` INT COMMENT 'ถ้าเป็น manual adjustment',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_points_user` (`user_id`),
    INDEX `idx_points_type` (`type`),
    INDEX `idx_points_reference` (`reference_type`, `reference_id`),
    INDEX `idx_points_expiry` (`expiry_date`, `is_expired`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติแต้ม';

-- Points Rules (กฎการให้แต้ม)
CREATE TABLE IF NOT EXISTS `points_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `rule_name` VARCHAR(255) NOT NULL,
    `rule_type` ENUM('purchase', 'registration', 'birthday', 'referral', 'review', 'check-in', 'custom') DEFAULT 'purchase',
    `points_amount` INT NOT NULL,
    `condition_value` DECIMAL(10,2) COMMENT 'เช่น ยอดซื้อขั้นต่ำ',
    `points_per_baht` DECIMAL(10,4) COMMENT 'แต้มต่อ 1 บาท',
    `max_points_per_transaction` INT,
    `is_active` TINYINT(1) DEFAULT 1,
    `valid_from` DATE,
    `valid_until` DATE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rule_account` (`line_account_id`),
    INDEX `idx_rule_type` (`rule_type`),
    INDEX `idx_rule_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กฎการให้แต้ม';

-- Rewards (รางวัล)
CREATE TABLE IF NOT EXISTS `rewards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `image_url` VARCHAR(500),
    `points_required` INT NOT NULL,
    `reward_type` ENUM('discount', 'product', 'voucher', 'service') DEFAULT 'discount',
    `reward_value` DECIMAL(10,2) COMMENT 'มูลค่าส่วนลด',
    `stock` INT DEFAULT -1 COMMENT '-1 = unlimited',
    `is_active` TINYINT(1) DEFAULT 1,
    `valid_from` DATE,
    `valid_until` DATE,
    `terms_conditions` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reward_account` (`line_account_id`),
    INDEX `idx_reward_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รางวัล';

-- Redemptions (การแลกรางวัล)
CREATE TABLE IF NOT EXISTS `redemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `reward_id` INT NOT NULL,
    `points_used` INT NOT NULL,
    `redemption_code` VARCHAR(50) UNIQUE,
    `status` ENUM('pending', 'approved', 'used', 'expired', 'cancelled') DEFAULT 'pending',
    `used_at` DATETIME,
    `expired_at` DATE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_redemption_user` (`user_id`),
    INDEX `idx_redemption_reward` (`reward_id`),
    INDEX `idx_redemption_status` (`status`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reward_id`) REFERENCES `rewards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การแลกรางวัล';

-- =============================================
-- SECTION 6: HEALTH & PHARMACY TABLES
-- =============================================

-- Pharmacists
CREATE TABLE IF NOT EXISTS `pharmacists` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `license_number` VARCHAR(100) UNIQUE NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20),
    `email` VARCHAR(255),
    `avatar_url` VARCHAR(500),
    `specialization` VARCHAR(255),
    `bio` TEXT,
    `years_experience` INT,
    `languages` VARCHAR(255),
    `rating` DECIMAL(3,2) DEFAULT 0,
    `total_consultations` INT DEFAULT 0,
    `is_available` TINYINT(1) DEFAULT 1,
    `is_active` TINYINT(1) DEFAULT 1,
    `telegram_chat_id` VARCHAR(100) COMMENT 'Telegram ID สำหรับแจ้งเตือน',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pharm_account` (`line_account_id`),
    INDEX `idx_pharm_available` (`is_available`),
    INDEX `idx_pharm_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='เภสัชกร';

-- Appointments
CREATE TABLE IF NOT EXISTS `appointments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `pharmacist_id` INT,
    `appointment_date` DATE NOT NULL,
    `appointment_time` TIME NOT NULL,
    `appointment_datetime` DATETIME NOT NULL,
    `duration` INT DEFAULT 30 COMMENT 'นาที',
    `service_type` VARCHAR(100),
    `reason` TEXT,
    `notes` TEXT,
    `status` ENUM('pending', 'confirmed', 'in-progress', 'completed', 'cancelled', 'no-show') DEFAULT 'pending',
    `actual_start_time` DATETIME,
    `actual_end_time` DATETIME,
    `reminder_24h_sent` TINYINT(1) DEFAULT 0,
    `reminder_1h_sent` TINYINT(1) DEFAULT 0,
    `reminder_10min_sent` TINYINT(1) DEFAULT 0,
    `cancelled_at` DATETIME,
    `cancelled_reason` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_appt_user` (`user_id`),
    INDEX `idx_appt_pharm` (`pharmacist_id`),
    INDEX `idx_appt_datetime` (`appointment_datetime`),
    INDEX `idx_appt_status` (`status`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`pharmacist_id`) REFERENCES `pharmacists`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การนัดหมาย';

-- Video Call Records
CREATE TABLE IF NOT EXISTS `video_call_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `appointment_id` INT NOT NULL,
    `room_id` VARCHAR(255),
    `started_at` DATETIME,
    `ended_at` DATETIME,
    `duration` INT COMMENT 'วินาที',
    `recording_url` VARCHAR(500),
    `quality_rating` INT COMMENT '1-5 stars',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_video_appt` (`appointment_id`),
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึก Video Call';

-- Consultation Notes
CREATE TABLE IF NOT EXISTS `consultation_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `appointment_id` INT NOT NULL,
    `pharmacist_id` INT NOT NULL,
    `symptoms` TEXT,
    `diagnosis` TEXT,
    `recommendations` TEXT,
    `prescriptions` JSON,
    `follow_up_required` TINYINT(1) DEFAULT 0,
    `follow_up_date` DATE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_consult_appt` (`appointment_id`),
    INDEX `idx_consult_pharm` (`pharmacist_id`),
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`pharmacist_id`) REFERENCES `pharmacists`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกการปรึกษา';

-- Medication Reminders
CREATE TABLE IF NOT EXISTS `medication_reminders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `medication_name` VARCHAR(255) NOT NULL,
    `dosage` VARCHAR(100),
    `times_per_day` INT DEFAULT 1,
    `reminder_times` JSON COMMENT 'เวลาเตือน ["08:00", "13:00", "20:00"]',
    `start_date` DATE,
    `end_date` DATE,
    `notes` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `next_reminder` DATETIME,
    `last_sent` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reminder_user` (`user_id`),
    INDEX `idx_reminder_next` (`next_reminder`),
    INDEX `idx_reminder_active` (`is_active`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='เตือนทานยา';

-- Prescriptions
CREATE TABLE IF NOT EXISTS `prescriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `pharmacist_id` INT,
    `appointment_id` INT,
    `prescription_number` VARCHAR(50) UNIQUE,
    `prescription_date` DATE,
    `medications` JSON COMMENT 'รายการยา',
    `image_url` VARCHAR(500) COMMENT 'รูปใบสั่งแพทย์',
    `verified` TINYINT(1) DEFAULT 0,
    `verified_at` DATETIME,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_presc_user` (`user_id`),
    INDEX `idx_presc_pharm` (`pharmacist_id`),
    INDEX `idx_presc_appt` (`appointment_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`pharmacist_id`) REFERENCES `pharmacists`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ใบสั่งยา';

-- =============================================
-- SECTION 7: AI & ANALYTICS TABLES
-- =============================================

-- AI Chat Sessions
CREATE TABLE IF NOT EXISTS `ai_chat_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(100) UNIQUE NOT NULL,
    `user_id` INT NOT NULL,
    `model_used` VARCHAR(50) DEFAULT 'gemini-2.0-flash',
    `is_active` TINYINT(1) DEFAULT 1,
    `total_messages` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ai_session_user` (`user_id`),
    INDEX `idx_ai_session_active` (`is_active`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Session แชท AI';

-- AI Chat Messages
CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(100) NOT NULL,
    `role` ENUM('user', 'assistant', 'system') DEFAULT 'user',
    `content` TEXT NOT NULL,
    `metadata` JSON COMMENT 'ข้อมูลเพิ่มเติม (products, warnings, etc.)',
    `tokens_used` INT,
    `response_time_ms` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ai_msg_session` (`session_id`),
    INDEX `idx_ai_msg_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ข้อความแชท AI';

-- Red Flag Alerts (ระบบแจ้งเตือนอาการอันตราย)
CREATE TABLE IF NOT EXISTS `red_flag_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `session_id` VARCHAR(100),
    `symptom_text` TEXT NOT NULL,
    `detected_flags` JSON COMMENT 'Red flags ที่ตรวจพบ',
    `severity` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    `admin_notified` TINYINT(1) DEFAULT 0,
    `pharmacist_notified` TINYINT(1) DEFAULT 0,
    `follow_up_required` TINYINT(1) DEFAULT 1,
    `follow_up_completed` TINYINT(1) DEFAULT 0,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_alert_user` (`user_id`),
    INDEX `idx_alert_severity` (`severity`),
    INDEX `idx_alert_follow_up` (`follow_up_required`, `follow_up_completed`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แจ้งเตือนอาการอันตราย';

-- Analytics Events
CREATE TABLE IF NOT EXISTS `analytics_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT,
    `user_id` INT,
    `event_type` VARCHAR(100) NOT NULL,
    `event_category` VARCHAR(100),
    `event_label` VARCHAR(255),
    `event_value` DECIMAL(10,2),
    `metadata` JSON,
    `session_id` VARCHAR(100),
    `page_url` VARCHAR(500),
    `referrer_url` VARCHAR(500),
    `user_agent` VARCHAR(500),
    `ip_address` VARCHAR(45),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_analytics_account` (`line_account_id`),
    INDEX `idx_analytics_user` (`user_id`),
    INDEX `idx_analytics_event` (`event_type`),
    INDEX `idx_analytics_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Event Analytics';

-- =============================================
-- SECTION 8: CONTENT & LANDING PAGE TABLES
-- =============================================

-- Landing Page Settings
CREATE TABLE IF NOT EXISTS `landing_page_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL UNIQUE,
    `hero_title` VARCHAR(255),
    `hero_subtitle` TEXT,
    `hero_image` VARCHAR(500),
    `hero_cta_text` VARCHAR(100),
    `hero_cta_link` VARCHAR(500),
    `about_title` VARCHAR(255),
    `about_content` TEXT,
    `about_image` VARCHAR(500),
    `services` JSON COMMENT 'บริการที่นำเสนอ',
    `features` JSON COMMENT 'ฟีเจอร์เด่น',
    `testimonials` JSON COMMENT 'รีวิวลูกค้า',
    `contact_info` JSON,
    `social_links` JSON,
    `seo_title` VARCHAR(255),
    `seo_description` TEXT,
    `seo_keywords` TEXT,
    `google_analytics_id` VARCHAR(50),
    `facebook_pixel_id` VARCHAR(50),
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตั้งค่าหน้า Landing';

-- Landing Banners
CREATE TABLE IF NOT EXISTS `landing_banners` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `title` VARCHAR(255),
    `subtitle` TEXT,
    `image_url` VARCHAR(500) NOT NULL,
    `link_url` VARCHAR(500),
    `position` INT DEFAULT 0 COMMENT 'ลำดับการแสดง',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_banner_account` (`line_account_id`),
    INDEX `idx_banner_position` (`position`),
    INDEX `idx_banner_active` (`is_active`),
    FOREIGN KEY (`line_account_id`) REFERENCES `line_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แบนเนอร์หน้า Landing';

-- Health Articles
CREATE TABLE IF NOT EXISTS `health_articles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) UNIQUE,
    `excerpt` TEXT,
    `content` LONGTEXT NOT NULL,
    `featured_image` VARCHAR(500),
    `category` VARCHAR(100),
    `tags` JSON,
    `author_id` INT,
    `view_count` INT DEFAULT 0,
    `is_published` TINYINT(1) DEFAULT 0,
    `published_at` DATETIME,
    `meta_title` VARCHAR(255),
    `meta_description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_article_account` (`line_account_id`),
    INDEX `idx_article_slug` (`slug`),
    INDEX `idx_article_category` (`category`),
    INDEX `idx_article_published` (`is_published`),
    FOREIGN KEY (`author_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บทความสุขภาพ';

-- =============================================
-- SECTION 9: SYSTEM TABLES
-- =============================================

-- Rich Menus
CREATE TABLE IF NOT EXISTS `rich_menus` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `rich_menu_id` VARCHAR(100) UNIQUE COMMENT 'LINE Rich Menu ID',
    `name` VARCHAR(255) NOT NULL,
    `chat_bar_text` VARCHAR(20),
    `size_width` INT DEFAULT 2500,
    `size_height` INT DEFAULT 1686,
    `selected` TINYINT(1) DEFAULT 0,
    `areas` JSON COMMENT 'Areas definition',
    `image_url` VARCHAR(500),
    `is_default` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_richmenu_account` (`line_account_id`),
    INDEX `idx_richmenu_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rich Menus';

-- System Settings
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `setting_type` VARCHAR(20) DEFAULT 'string',
    `description` TEXT,
    `group` VARCHAR(50) DEFAULT 'general',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`),
    INDEX `idx_setting_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตั้งค่าระบบ';

-- Activity Logs
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT,
    `action` VARCHAR(100) NOT NULL,
    `target_type` VARCHAR(50),
    `target_id` INT,
    `description` TEXT,
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(500),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_log_admin` (`admin_id`),
    INDEX `idx_log_action` (`action`),
    INDEX `idx_log_target` (`target_type`, `target_id`),
    INDEX `idx_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บันทึกกิจกรรม';

-- Coupons
CREATE TABLE IF NOT EXISTS `coupons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `discount_type` ENUM('percentage', 'fixed_amount') DEFAULT 'percentage',
    `discount_value` DECIMAL(10,2) NOT NULL,
    `min_purchase` DECIMAL(10,2) DEFAULT 0,
    `max_discount` DECIMAL(10,2),
    `usage_limit` INT COMMENT 'จำนวนครั้งที่ใช้ได้ทั้งหมด',
    `usage_per_user` INT DEFAULT 1,
    `usage_count` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `valid_from` DATETIME,
    `valid_until` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coupon_account` (`line_account_id`),
    INDEX `idx_coupon_code` (`code`),
    INDEX `idx_coupon_active` (`is_active`),
    UNIQUE KEY `unique_coupon_code` (`line_account_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คูปองส่วนลด';

-- Coupon Usage
CREATE TABLE IF NOT EXISTS `coupon_usage` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `order_id` INT,
    `discount_amount` DECIMAL(10,2),
    `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_usage_coupon` (`coupon_id`),
    INDEX `idx_usage_user` (`user_id`),
    INDEX `idx_usage_order` (`order_id`),
    FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การใช้คูปอง';

-- Wishlists
CREATE TABLE IF NOT EXISTS `wishlists` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wishlist_user` (`user_id`),
    INDEX `idx_wishlist_product` (`product_id`),
    UNIQUE KEY `unique_wishlist` (`user_id`, `product_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รายการโปรด';

-- Product Reviews
CREATE TABLE IF NOT EXISTS `product_reviews` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `order_id` INT,
    `rating` INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    `review_text` TEXT,
    `images` JSON,
    `is_verified_purchase` TINYINT(1) DEFAULT 0,
    `is_approved` TINYINT(1) DEFAULT 0,
    `approved_by_admin_id` INT,
    `helpful_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_review_product` (`product_id`),
    INDEX `idx_review_user` (`user_id`),
    INDEX `idx_review_approved` (`is_approved`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='รีวิวสินค้า';

-- Notification Settings
CREATE TABLE IF NOT EXISTS `user_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `notification_type` VARCHAR(50) NOT NULL COMMENT 'order, promotion, reminder, etc.',
    `is_enabled` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_notif_user` (`user_id`),
    UNIQUE KEY `unique_user_notif` (`user_id`, `notification_type`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ตั้งค่าการแจ้งเตือน';

-- Performance Feature Flags (Latest)
CREATE TABLE IF NOT EXISTS `performance_feature_flags` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `flag_key` VARCHAR(100) UNIQUE NOT NULL,
    `flag_name` VARCHAR(255) NOT NULL,
    `is_enabled` TINYINT(1) DEFAULT 0,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_flag_key` (`flag_key`),
    INDEX `idx_flag_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Feature Flags สำหรับ Performance';

-- =============================================
-- INSERT DEFAULT DATA
-- =============================================

-- Insert default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `group`) VALUES
('app_name', 'LINE Telepharmacy CRM', 'string', 'ชื่อแอปพลิเคชัน', 'general'),
('app_version', '3.2', 'string', 'เวอร์ชัน', 'general'),
('timezone', 'Asia/Bangkok', 'string', 'Timezone', 'general'),
('default_language', 'th', 'string', 'ภาษาเริ่มต้น', 'general'),
('points_per_baht', '0.01', 'number', 'แต้มต่อ 1 บาท (1% = 0.01)', 'loyalty'),
('points_expiry_days', '365', 'number', 'แต้มหมดอายุกี่วัน', 'loyalty'),
('welcome_points', '100', 'number', 'แต้มต้อนรับสมาชิกใหม่', 'loyalty'),
('free_shipping_threshold', '500', 'number', 'ยอดซื้อขั้นต่ำสำหรับจัดส่งฟรี', 'shop'),
('default_shipping_fee', '50', 'number', 'ค่าจัดส่งปกติ', 'shop')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- Insert default performance flags
INSERT INTO `performance_feature_flags` (`flag_key`, `flag_name`, `is_enabled`, `description`) VALUES
('enable_websocket', 'Enable WebSocket', 1, 'เปิดใช้ WebSocket สำหรับ real-time updates'),
('enable_ai_chat', 'Enable AI Chat', 1, 'เปิดใช้ AI Chat Assistant'),
('enable_virtual_scrolling', 'Enable Virtual Scrolling', 1, 'เปิดใช้ Virtual Scrolling ใน Inbox'),
('enable_lazy_loading', 'Enable Lazy Loading', 1, 'เปิดใช้ Lazy Loading สำหรับรูปภาพ'),
('enable_caching', 'Enable Caching', 1, 'เปิดใช้ระบบ Cache')
ON DUPLICATE KEY UPDATE `flag_name`=VALUES(`flag_name`);

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- =============================================
-- VERIFICATION
-- =============================================

-- Show all tables
SELECT 'DATABASE INSTALLED SUCCESSFULLY!' as status;
SELECT COUNT(*) as total_tables FROM information_schema.tables
WHERE table_schema = DATABASE();

-- Show tables list
SHOW TABLES;

-- =============================================
-- NOTES
-- =============================================

/*
การติดตั้งเสร็จสมบูรณ์!

ขั้นตอนต่อไป:
1. ตรวจสอบว่า import สำเร็จ: SHOW TABLES;
2. สร้าง Admin user ใน admin_users table
3. เพิ่ม LINE Account ใน line_accounts table
4. ตั้งค่า config.php ให้ถูกต้อง
5. ทดสอบ webhook.php
6. ตั้งค่า Cron Jobs
7. ตั้งค่า WebSocket Server

สำหรับการ migrate จาก version เก่า:
- ใช้ไฟล์ migration_*.sql ที่เหมาะสม
- Backup database ก่อนทำ migration

สำหรับข้อมูลเพิ่มเติม:
- อ่าน SETUP_GUIDE_COMPLETE.md
- อ่าน CRM_WORKFLOW_COMPLETE.md
*/
