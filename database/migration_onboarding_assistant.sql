-- Migration: AI Onboarding Assistant
-- Version: 1.0.0
-- Date: 2025-12-25

-- Onboarding Sessions Table
CREATE TABLE IF NOT EXISTS onboarding_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    conversation_history JSON,
    current_topic VARCHAR(100) DEFAULT NULL,
    business_type VARCHAR(50) DEFAULT NULL,
    setup_progress JSON,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_line_account (line_account_id),
    INDEX idx_admin_user (admin_user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Setup Progress Table
CREATE TABLE IF NOT EXISTS setup_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'skipped') DEFAULT 'pending',
    completed_at TIMESTAMP NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_progress (line_account_id, item_key),
    INDEX idx_line_account (line_account_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
