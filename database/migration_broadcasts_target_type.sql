-- =============================================
-- Fix broadcasts.target_type column size
-- =============================================

-- Create broadcasts table if not exists
CREATE TABLE IF NOT EXISTS `broadcasts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message_type` ENUM('text', 'image', 'flex', 'video', 'audio') DEFAULT 'text',
    `content` TEXT,
    `target_type` VARCHAR(20) DEFAULT 'all' COMMENT 'database, all, limit, narrowcast, group, segment, tag, select, single',
    `target_group_id` VARCHAR(100) DEFAULT NULL,
    `sent_count` INT DEFAULT 0,
    `status` ENUM('draft', 'sent', 'failed') DEFAULT 'draft',
    `sent_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_line_account` (`line_account_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alter existing table if it exists
ALTER TABLE `broadcasts` 
MODIFY COLUMN `target_type` VARCHAR(20) DEFAULT 'all' COMMENT 'database, all, limit, narrowcast, group, segment, tag, select, single';
