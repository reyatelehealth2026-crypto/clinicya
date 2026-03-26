<?php
/**
 * DripCampaignService - Stub class for drip campaign functionality
 * TEMPORARY: This is a minimal stub to fix the missing file issue.
 * The full implementation should be restored from proper source.
 */

class DripCampaignService {
    private $db;
    private $botId;
    
    public function __construct($db, $botId = null) {
        $this->db = $db;
        $this->botId = $botId;
        $this->ensureTablesExist();
    }
    
    /**
     * Ensure required database tables exist
     */
    private function ensureTablesExist() {
        try {
            // Create drip_campaigns table
            $this->db->exec("CREATE TABLE IF NOT EXISTS drip_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                trigger_type VARCHAR(50) DEFAULT 'follow',
                trigger_config JSON NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_line_account (line_account_id),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Create drip_steps table
            $this->db->exec("CREATE TABLE IF NOT EXISTS drip_steps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                step_order INT DEFAULT 0,
                delay_minutes INT DEFAULT 0,
                message_type VARCHAR(50) DEFAULT 'text',
                message_content TEXT,
                template_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_campaign (campaign_id),
                INDEX idx_order (step_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Create drip_queue table
            $this->db->exec("CREATE TABLE IF NOT EXISTS drip_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                campaign_id INT NOT NULL,
                user_id VARCHAR(100) NOT NULL,
                current_step INT DEFAULT 0,
                status VARCHAR(50) DEFAULT 'pending',
                scheduled_at TIMESTAMP NULL,
                sent_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_campaign_user (campaign_id, user_id),
                INDEX idx_status (status),
                INDEX idx_scheduled (scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {
            error_log('DripCampaignService: Failed to create tables: ' . $e->getMessage());
        }
    }
    
    /**
     * List all campaigns with stats
     */
    public function listCampaignsWithStats() {
        try {
            $sql = "SELECT c.*, 
                    (SELECT COUNT(*) FROM drip_steps WHERE campaign_id = c.id) as step_count,
                    (SELECT COUNT(*) FROM drip_queue WHERE campaign_id = c.id) as total_recipients,
                    (SELECT COUNT(*) FROM drip_queue WHERE campaign_id = c.id AND status = 'completed') as completed_count
                    FROM drip_campaigns c";
            
            if ($this->botId) {
                $sql .= " WHERE c.line_account_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$this->botId]);
            } else {
                $stmt = $this->db->query($sql);
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('DripCampaignService::listCampaignsWithStats error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get queue summary
     */
    public function getQueueSummary() {
        try {
            $where = $this->botId ? "WHERE c.line_account_id = ?" : "";
            $params = $this->botId ? [$this->botId] : [];
            
            $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN q.status = 'completed' THEN 1 ELSE 0 END) as completed
                    FROM drip_queue q
                    JOIN drip_campaigns c ON q.campaign_id = c.id
                    $where";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total' => (int)($result['total'] ?? 0),
                'pending' => (int)($result['pending'] ?? 0),
                'sent' => (int)($result['sent'] ?? 0),
                'completed' => (int)($result['completed'] ?? 0)
            ];
        } catch (Exception $e) {
            error_log('DripCampaignService::getQueueSummary error: ' . $e->getMessage());
            return ['total' => 0, 'pending' => 0, 'sent' => 0, 'completed' => 0];
        }
    }
    
    /**
     * Create a new campaign
     */
    public function createCampaign($name, $triggerType = 'follow', $triggerConfig = null) {
        try {
            $stmt = $this->db->prepare("INSERT INTO drip_campaigns (line_account_id, name, trigger_type, trigger_config) VALUES (?, ?, ?, ?)");
            $stmt->execute([$this->botId, $name, $triggerType, $triggerConfig ? json_encode($triggerConfig) : null]);
            $id = $this->db->lastInsertId();
            return ['id' => $id, 'name' => $name, 'trigger_type' => $triggerType];
        } catch (Exception $e) {
            error_log('DripCampaignService::createCampaign error: ' . $e->getMessage());
            throw new Exception('Failed to create campaign: ' . $e->getMessage());
        }
    }
    
    /**
     * Update a campaign
     */
    public function updateCampaign($campaignId, $payload) {
        try {
            $fields = [];
            $values = [];
            
            if (isset($payload['name'])) {
                $fields[] = "name = ?";
                $values[] = $payload['name'];
            }
            if (isset($payload['trigger_type'])) {
                $fields[] = "trigger_type = ?";
                $values[] = $payload['trigger_type'];
            }
            if (isset($payload['trigger_config'])) {
                $fields[] = "trigger_config = ?";
                $values[] = json_encode($payload['trigger_config']);
            }
            
            if (empty($fields)) {
                return $this->getCampaign($campaignId);
            }
            
            $values[] = $campaignId;
            $sql = "UPDATE drip_campaigns SET " . implode(', ', $fields) . " WHERE id = ?";
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $values[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            return $this->getCampaign($campaignId);
        } catch (Exception $e) {
            error_log('DripCampaignService::updateCampaign error: ' . $e->getMessage());
            throw new Exception('Failed to update campaign: ' . $e->getMessage());
        }
    }
    
    /**
     * Toggle campaign active status
     */
    public function toggleCampaign($campaignId) {
        try {
            $sql = "UPDATE drip_campaigns SET is_active = NOT is_active WHERE id = ?";
            $params = [$campaignId];
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $this->getCampaign($campaignId);
        } catch (Exception $e) {
            error_log('DripCampaignService::toggleCampaign error: ' . $e->getMessage());
            throw new Exception('Failed to toggle campaign: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete a campaign
     */
    public function deleteCampaign($campaignId) {
        try {
            // Delete related records first
            $this->db->prepare("DELETE FROM drip_steps WHERE campaign_id = ?")->execute([$campaignId]);
            $this->db->prepare("DELETE FROM drip_queue WHERE campaign_id = ?")->execute([$campaignId]);
            
            $sql = "DELETE FROM drip_campaigns WHERE id = ?";
            $params = [$campaignId];
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return true;
        } catch (Exception $e) {
            error_log('DripCampaignService::deleteCampaign error: ' . $e->getMessage());
            throw new Exception('Failed to delete campaign: ' . $e->getMessage());
        }
    }
    
    /**
     * Add a step to a campaign
     */
    public function addStep($campaignId, $payload) {
        try {
            $delayMinutes = isset($payload['delay_minutes']) ? (int)$payload['delay_minutes'] : 0;
            $messageType = $payload['message_type'] ?? 'text';
            $messageContent = $payload['message_content'] ?? '';
            $templateId = $payload['template_id'] ?? null;
            
            // Get next step order
            $stmt = $this->db->prepare("SELECT MAX(step_order) as max_order FROM drip_steps WHERE campaign_id = ?");
            $stmt->execute([$campaignId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stepOrder = (int)($result['max_order'] ?? 0) + 1;
            
            $stmt = $this->db->prepare("INSERT INTO drip_steps (campaign_id, step_order, delay_minutes, message_type, message_content, template_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$campaignId, $stepOrder, $delayMinutes, $messageType, $messageContent, $templateId]);
            
            $id = $this->db->lastInsertId();
            return [
                'id' => $id,
                'campaign_id' => $campaignId,
                'step_order' => $stepOrder,
                'delay_minutes' => $delayMinutes,
                'message_type' => $messageType
            ];
        } catch (Exception $e) {
            error_log('DripCampaignService::addStep error: ' . $e->getMessage());
            throw new Exception('Failed to add step: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete a step from a campaign
     */
    public function deleteStep($campaignId, $stepId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM drip_steps WHERE id = ? AND campaign_id = ?");
            $stmt->execute([$stepId, $campaignId]);
            return true;
        } catch (Exception $e) {
            error_log('DripCampaignService::deleteStep error: ' . $e->getMessage());
            throw new Exception('Failed to delete step: ' . $e->getMessage());
        }
    }
    
    /**
     * Get a single campaign by ID
     */
    public function getCampaign($campaignId) {
        try {
            $sql = "SELECT * FROM drip_campaigns WHERE id = ?";
            $params = [$campaignId];
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                throw new Exception('Campaign not found');
            }
            
            return $campaign;
        } catch (Exception $e) {
            error_log('DripCampaignService::getCampaign error: ' . $e->getMessage());
            throw new Exception('Failed to get campaign: ' . $e->getMessage());
        }
    }
    
    /**
     * Get all steps for a campaign
     */
    public function getCampaignSteps($campaignId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM drip_steps WHERE campaign_id = ? ORDER BY step_order ASC");
            $stmt->execute([$campaignId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('DripCampaignService::getCampaignSteps error: ' . $e->getMessage());
            return [];
        }
    }
}
