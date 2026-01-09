<?php
/**
 * LandingBannerService - จัดการแบนเนอร์/โปสเตอร์สไลด์สำหรับ Landing Page
 */

class LandingBannerService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get active banners for display
     */
    public function getActiveBanners(int $limit = 10): array {
        try {
            $sql = "SELECT id, title, image_url, link_url, link_type, sort_order 
                    FROM landing_banners 
                    WHERE is_active = 1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            } else {
                $sql .= " AND line_account_id IS NULL";
            }
            
            $sql .= " ORDER BY sort_order ASC, id DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get all banners for admin
     */
    public function getAllForAdmin(): array {
        try {
            $sql = "SELECT * FROM landing_banners WHERE 1=1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY sort_order ASC, id DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get banner by ID
     */
    public function getById(int $id): ?array {
        $sql = "SELECT * FROM landing_banners WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Create new banner
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO landing_banners 
            (line_account_id, title, image_url, link_url, link_type, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            trim($data['title'] ?? ''),
            trim($data['image_url'] ?? ''),
            trim($data['link_url'] ?? ''),
            $data['link_type'] ?? 'none',
            (int)($data['sort_order'] ?? $this->getNextSortOrder()),
            isset($data['is_active']) ? (int)$data['is_active'] : 1
        ]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update banner
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];
        
        if (isset($data['title'])) {
            $fields[] = 'title = ?';
            $params[] = trim($data['title']);
        }
        if (isset($data['image_url'])) {
            $fields[] = 'image_url = ?';
            $params[] = trim($data['image_url']);
        }
        if (isset($data['link_url'])) {
            $fields[] = 'link_url = ?';
            $params[] = trim($data['link_url']);
        }
        if (isset($data['link_type'])) {
            $fields[] = 'link_type = ?';
            $params[] = $data['link_type'];
        }
        if (isset($data['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$data['sort_order'];
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }
        
        if (empty($fields)) return true;
        
        $params[] = $id;
        $sql = "UPDATE landing_banners SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Delete banner
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM landing_banners WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Reorder banners
     */
    public function reorder(array $ids): bool {
        $stmt = $this->db->prepare("UPDATE landing_banners SET sort_order = ? WHERE id = ?");
        foreach ($ids as $order => $id) {
            $stmt->execute([$order + 1, (int)$id]);
        }
        return true;
    }
    
    private function getNextSortOrder(): int {
        $stmt = $this->db->query("SELECT MAX(sort_order) FROM landing_banners");
        return ((int)$stmt->fetchColumn()) + 1;
    }
    
    public function getCount(): int {
        try {
            $sql = "SELECT COUNT(*) FROM landing_banners WHERE 1=1";
            $params = [];
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}
