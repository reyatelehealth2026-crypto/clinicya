-- Migration: Add Performance Feature Flags
-- Description: Add settings for gradual rollout of inbox v2 performance features
-- Requirements: Task 25.1 - Feature flag for gradual rollout

-- Add performance feature flag settings to vibe_selling_settings table
INSERT INTO vibe_selling_settings (line_account_id, setting_key, setting_value, created_at, updated_at)
VALUES 
    -- Performance upgrade master switch (default: disabled)
    (NULL, 'performance_upgrade_enabled', '0', NOW(), NOW()),
    
    -- WebSocket real-time updates (default: disabled)
    (NULL, 'websocket_enabled', '0', NOW(), NOW()),
    
    -- Rollout percentage for A/B testing (default: 10%)
    (NULL, 'performance_rollout_percentage', '10', NOW(), NOW()),
    
    -- Internal team user IDs (comma-separated, always enabled)
    (NULL, 'performance_internal_users', '', NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    updated_at = NOW();

-- Add comments for documentation
ALTER TABLE vibe_selling_settings 
MODIFY COLUMN setting_key VARCHAR(100) COMMENT 'Setting key (e.g., v2_enabled, performance_upgrade_enabled)';

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_setting_key ON vibe_selling_settings(setting_key);

