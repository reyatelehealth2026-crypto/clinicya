-- Inbox Chat Upgrade Migration
-- Version: 1.0
-- Date: 2026-01-10
-- Description: Creates tables for quick reply templates, conversation assignments, 
--              customer notes, and message analytics

-- =====================================================
-- Quick Reply Templates Table
-- =====================================================
CREATE TABLE IF NOT EXISTS quick_reply_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(50) DEFAULT '',
    usage_count INT DEFAULT 0,
    last_used_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account (line_account_id),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Conversation Assignments Table
-- =====================================================
CREATE TABLE IF NOT EXISTS conversation_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Customer user ID',
    assigned_to INT NOT NULL COMMENT 'Admin user ID',
    assigned_by INT NULL COMMENT 'Who assigned',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'resolved', 'transferred') DEFAULT 'active',
    resolved_at DATETIME NULL,
    UNIQUE KEY uk_user (user_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Customer Notes Table
-- =====================================================
CREATE TABLE IF NOT EXISTS customer_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NOT NULL,
    note TEXT NOT NULL,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Message Analytics Table
-- =====================================================
CREATE TABLE IF NOT EXISTS message_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    admin_id INT NULL,
    response_time_seconds INT NULL COMMENT 'Time to respond in seconds',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
