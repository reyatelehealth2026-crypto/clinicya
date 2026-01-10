-- Inbox Chat Upgrade - Index Migration
-- Version: 1.0
-- Date: 2026-01-10
-- Description: Adds performance indexes to existing messages and users tables

-- =====================================================
-- Add indexes to messages table for performance
-- =====================================================

-- Index for filtering by user and direction (incoming/outgoing)
-- Check if index exists before creating
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'messages' 
               AND index_name = 'idx_user_direction');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE messages ADD INDEX idx_user_direction (user_id, direction)', 
    'SELECT ''Index idx_user_direction already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for sorting by account and created date
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'messages' 
               AND index_name = 'idx_account_created');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE messages ADD INDEX idx_account_created (line_account_id, created_at DESC)', 
    'SELECT ''Index idx_account_created already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for filtering unread messages
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'messages' 
               AND index_name = 'idx_is_read');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE messages ADD INDEX idx_is_read (is_read, direction)', 
    'SELECT ''Index idx_is_read already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- Add indexes to users table for performance
-- =====================================================

-- Index for sorting conversations by last message
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
               AND table_name = 'users' 
               AND index_name = 'idx_account_last_msg');
SET @sqlstmt := IF(@exist = 0, 
    'ALTER TABLE users ADD INDEX idx_account_last_msg (line_account_id, last_message_at DESC)', 
    'SELECT ''Index idx_account_last_msg already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
