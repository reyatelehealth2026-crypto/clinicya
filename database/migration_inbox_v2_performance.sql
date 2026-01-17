-- Inbox v2 Performance Upgrade Migration
-- Version: 1.0
-- Date: 2026-01-10
-- Description: Adds performance indexes for AJAX conversation switching, cursor-based pagination,
--              and efficient polling. Creates performance metrics table for monitoring.

-- =====================================================
-- Add columns to users table if they don't exist
-- =====================================================

-- Add last_message_at column if it doesn't exist
SET @exist := (SELECT COUNT(*) FROM information_schema.columns 
               WHERE table_schema = DATABASE() 
               AND table_name = 'users' 
               AND column_name = 'last_message_at');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE users ADD COLUMN last_message_at DATETIME NULL AFTER last_interaction', 
    'SELECT ''Column last_message_at already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unread_count column if it doesn't exist
SET @exist := (SELECT COUNT(*) FROM information_schema.columns 
               WHERE table_schema = DATABASE() 
               AND table_name = 'users' 
               AND column_name = 'unread_count');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE users ADD COLUMN unread_count INT DEFAULT 0 AFTER last_message_at', 
    'SELECT ''Column unread_count already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- Performance Indexes for Users Table (Conversation List)
-- =====================================================

-- Covering index for conversation list query
-- This index includes all fields needed for the conversation list, avoiding table lookups
-- Supports: ORDER BY last_message_at DESC with filtering by line_account_id
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'users' 
               AND index_name = 'idx_account_last_msg_cover');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE users ADD INDEX idx_account_last_msg_cover (
        line_account_id, 
        last_message_at DESC, 
        id, 
        display_name(100), 
        unread_count
    )', 
    'SELECT ''Index idx_account_last_msg_cover already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- Performance Indexes for Messages Table
-- =====================================================

-- Cursor-based pagination index for messages
-- Supports: WHERE user_id = ? AND id < ? ORDER BY id DESC LIMIT ?
-- This is much faster than OFFSET-based pagination for large datasets
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'messages' 
               AND index_name = 'idx_user_id_cursor');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE messages ADD INDEX idx_user_id_cursor (user_id, id DESC)', 
    'SELECT ''Index idx_user_id_cursor already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Polling query index for delta updates
-- Supports: WHERE line_account_id = ? AND created_at > ? AND direction = 'incoming'
-- Used for efficient polling to get only new messages since last check
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'messages' 
               AND index_name = 'idx_account_created_direction');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE messages ADD INDEX idx_account_created_direction (
        line_account_id, 
        created_at DESC, 
        direction
    )', 
    'SELECT ''Index idx_account_created_direction already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unread count index for efficient counting
-- Supports: WHERE user_id = ? AND is_read = 0 AND direction = 'incoming'
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'messages' 
               AND index_name = 'idx_user_unread');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE messages ADD INDEX idx_user_unread (user_id, is_read, direction)', 
    'SELECT ''Index idx_user_unread already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- Performance Metrics Table
-- =====================================================

CREATE TABLE IF NOT EXISTS performance_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NULL COMMENT 'LINE account for multi-tenant tracking',
    metric_type ENUM(
        'page_load', 
        'conversation_switch', 
        'message_render', 
        'api_call',
        'scroll_performance',
        'cache_hit',
        'cache_miss'
    ) NOT NULL,
    duration_ms INT NOT NULL COMMENT 'Duration in milliseconds',
    operation_details JSON NULL COMMENT 'Additional context about the operation',
    user_agent VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_created (metric_type, created_at),
    INDEX idx_account_type (line_account_id, metric_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Initialize last_message_at for existing users
-- =====================================================

-- Update last_message_at from most recent message for each user
UPDATE users u
LEFT JOIN (
    SELECT user_id, MAX(created_at) as last_msg
    FROM messages
    GROUP BY user_id
) m ON u.id = m.user_id
SET u.last_message_at = m.last_msg
WHERE u.last_message_at IS NULL AND m.last_msg IS NOT NULL;

-- =====================================================
-- Initialize unread_count for existing users
-- =====================================================

-- Update unread_count from messages table
UPDATE users u
LEFT JOIN (
    SELECT user_id, COUNT(*) as unread
    FROM messages
    WHERE direction = 'incoming' AND is_read = 0
    GROUP BY user_id
) m ON u.id = m.user_id
SET u.unread_count = COALESCE(m.unread, 0);

-- =====================================================
-- Success Message
-- =====================================================

SELECT 'Inbox v2 Performance Migration completed successfully!' as status;
SELECT 'Added covering indexes for conversation list, cursor pagination, and polling queries' as details;
SELECT 'Created performance_metrics table for monitoring' as monitoring;
SELECT 'Initialized last_message_at and unread_count for existing users' as data_init;
