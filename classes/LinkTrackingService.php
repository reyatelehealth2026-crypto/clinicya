<?php
/**
 * LinkTrackingService - Stub class for link tracking functionality
 * TEMPORARY: This is a minimal stub to fix the missing file issue.
 * The full implementation should be restored from proper source.
 */

class LinkTrackingService {
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
            // Create tracked_links table
            $this->db->exec("CREATE TABLE IF NOT EXISTS tracked_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT DEFAULT NULL,
                original_url TEXT NOT NULL,
                short_code VARCHAR(50) UNIQUE,
                title VARCHAR(255),
                click_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_line_account (line_account_id),
                INDEX idx_short_code (short_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Create link_clicks table
            $this->db->exec("CREATE TABLE IF NOT EXISTS link_clicks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                link_id INT NOT NULL,
                user_id VARCHAR(100),
                ip_address VARCHAR(45),
                user_agent TEXT,
                clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_link (link_id),
                INDEX idx_clicked_at (clicked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {
            error_log('LinkTrackingService: Failed to create tables: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate a short code
     */
    private function generateShortCode() {
        return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 5)), 0, 8);
    }
    
    /**
     * Create a new tracked link
     */
    public function createLink($url, $title = '') {
        try {
            $shortCode = $this->generateShortCode();
            
            $stmt = $this->db->prepare("INSERT INTO tracked_links (line_account_id, original_url, short_code, title) VALUES (?, ?, ?, ?)");
            $stmt->execute([$this->botId, $url, $shortCode, $title]);
            
            $id = $this->db->lastInsertId();
            return [
                'id' => $id,
                'original_url' => $url,
                'short_code' => $shortCode,
                'title' => $title,
                'click_count' => 0
            ];
        } catch (Exception $e) {
            error_log('LinkTrackingService::createLink error: ' . $e->getMessage());
            throw new Exception('Failed to create link: ' . $e->getMessage());
        }
    }
    
    /**
     * Update an existing link
     */
    public function updateLink($linkId, $url, $title) {
        try {
            $sql = "UPDATE tracked_links SET original_url = ?, title = ? WHERE id = ?";
            $params = [$url, $title, $linkId];
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return ['id' => $linkId, 'original_url' => $url, 'title' => $title];
        } catch (Exception $e) {
            error_log('LinkTrackingService::updateLink error: ' . $e->getMessage());
            throw new Exception('Failed to update link: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete a single link
     */
    public function deleteLink($linkId) {
        try {
            // Delete clicks first
            $this->db->prepare("DELETE FROM link_clicks WHERE link_id = ?")->execute([$linkId]);
            
            $sql = "DELETE FROM tracked_links WHERE id = ?";
            $params = [$linkId];
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return true;
        } catch (Exception $e) {
            error_log('LinkTrackingService::deleteLink error: ' . $e->getMessage());
            throw new Exception('Failed to delete link: ' . $e->getMessage());
        }
    }
    
    /**
     * Bulk delete links
     */
    public function deleteLinks($ids) {
        try {
            if (empty($ids)) return 0;
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // Delete clicks first
            $this->db->prepare("DELETE FROM link_clicks WHERE link_id IN ($placeholders)")->execute($ids);
            
            $sql = "DELETE FROM tracked_links WHERE id IN ($placeholders)";
            $params = $ids;
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('LinkTrackingService::deleteLinks error: ' . $e->getMessage());
            throw new Exception('Failed to delete links: ' . $e->getMessage());
        }
    }
    
    /**
     * Get links with optional filters
     */
    public function getLinks($filters = []) {
        try {
            $sql = "SELECT * FROM tracked_links WHERE 1=1";
            $params = [];
            
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            if (!empty($filters['search'])) {
                $sql .= " AND (title LIKE ? OR original_url LIKE ?)";
                $params[] = '%' . $filters['search'] . '%';
                $params[] = '%' . $filters['search'] . '%';
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            if (!empty($filters['limit'])) {
                $sql .= " LIMIT ?";
                $params[] = (int)$filters['limit'];
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('LinkTrackingService::getLinks error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        try {
            $where = $this->botId ? "WHERE line_account_id = ?" : "";
            $params = $this->botId ? [$this->botId] : [];
            
            // Total links
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM tracked_links $where");
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // Total clicks
            $sql = "SELECT SUM(l.click_count) as total_clicks FROM tracked_links l";
            if ($this->botId) {
                $sql .= " WHERE l.line_account_id = ?";
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $clicks = $stmt->fetch(PDO::FETCH_ASSOC)['total_clicks'] ?? 0;
            
            return [
                'total_links' => (int)$total,
                'total_clicks' => (int)$clicks
            ];
        } catch (Exception $e) {
            error_log('LinkTrackingService::getStats error: ' . $e->getMessage());
            return ['total_links' => 0, 'total_clicks' => 0];
        }
    }
    
    /**
     * Get usage ratio
     */
    public function getUsageRatio() {
        try {
            $stats = $this->getStats();
            $totalLinks = $stats['total_links'];
            
            if ($totalLinks === 0) return 0;
            
            // Count links with at least 1 click
            $sql = "SELECT COUNT(*) as clicked FROM tracked_links WHERE click_count > 0";
            $params = [];
            if ($this->botId) {
                $sql .= " AND line_account_id = ?";
                $params[] = $this->botId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $clicked = $stmt->fetch(PDO::FETCH_ASSOC)['clicked'] ?? 0;
            
            return round(($clicked / $totalLinks) * 100, 2);
        } catch (Exception $e) {
            error_log('LinkTrackingService::getUsageRatio error: ' . $e->getMessage());
            return 0;
        }
    }
}
