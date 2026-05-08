-- Migration: Create conversation_multi_assignees table
-- For multi-assignee support in inbox conversations

CREATE TABLE IF NOT EXISTS `conversation_multi_assignees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'User/conversation ID',
  `admin_id` INT(11) NOT NULL COMMENT 'Admin user ID assigned to this conversation',
  `status` VARCHAR(20) DEFAULT 'active' COMMENT 'active, inactive',
  `assigned_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` INT(11) NULL COMMENT 'Admin ID who made the assignment',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_status` (`status`),
  UNIQUE KEY `unique_assignment` (`user_id`, `admin_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-assignee support for conversations';

-- Add index for faster lookups
CREATE INDEX IF NOT EXISTS `idx_user_status` ON `conversation_multi_assignees` (`user_id`, `status`);
