<?php
/**
 * Dynamic Rich Menu Manager
 * จัดการ Rich Menu แบบ Dynamic ตามเงื่อนไขผู้ใช้
 */

class DynamicRichMenu {
    private $db;
    private $line;
    private $lineAccountId;
    
    public function __construct($db, $line, $lineAccountId) {
        $this->db = $db;
        $this->line = $line;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * กำหนด Rich Menu ให้ผู้ใช้ตามกฎที่ตั้งไว้
     */
    public function assignRichMenuByRules($userId, $lineUserId) {
        try {
            // ดึงข้อมูลผู้ใช้
            $userData = $this->getUserData($userId);
            if (!$userData) return ['success' => false, 'error' => 'User not found'];
            
            // ดึงกฎทั้งหมดเรียงตาม priority
            $rules = $this->getActiveRules();
            
            // หากฎที่ตรงเงื่อนไข
            foreach ($rules as $rule) {
                if ($this->evaluateConditions($rule['conditions'], $userData)) {
                    return $this->assignRichMenu($userId, $lineUserId, $rule['rich_menu_id'], 'rule', $rule['id'], $rule['name']);
                }
            }
            
            // ไม่มีกฎตรง - ใช้ default
            return $this->assignDefaultRichMenu($userId, $lineUserId);
            
        } catch (Exception $e) {
            error_log("DynamicRichMenu::assignRichMenuByRules error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * กำหนด Rich Menu โดยตรง (manual)
     */
    public function assignRichMenu($userId, $lineUserId, $richMenuId, $triggerType = 'manual', $ruleId = null, $reason = null) {
        try {
            // ดึง LINE Rich Menu ID
            $stmt = $this->db->prepare("SELECT id, line_rich_menu_id, name FROM rich_menus WHERE id = ?");
            $stmt->execute([$richMenuId]);
            $richMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$richMenu || !$richMenu['line_rich_menu_id']) {
                return ['success' => false, 'error' => 'Rich Menu not found'];
            }
            
            // ดึง Rich Menu ปัจจุบันของผู้ใช้
            $currentMenu = $this->getUserCurrentRichMenu($userId);
            
            // เรียก LINE API
            $result = $this->line->linkRichMenuToUser($lineUserId, $richMenu['line_rich_menu_id']);
            
            if ($result['code'] !== 200) {
                return ['success' => false, 'error' => 'LINE API error: ' . ($result['body']['message'] ?? 'Unknown')];
            }
            
            // บันทึกการกำหนด
            $stmt = $this->db->prepare("
                INSERT INTO user_rich_menus (line_account_id, user_id, line_user_id, rich_menu_id, line_rich_menu_id, rule_id, assigned_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    rich_menu_id = VALUES(rich_menu_id),
                    line_rich_menu_id = VALUES(line_rich_menu_id),
                    rule_id = VALUES(rule_id),
                    assigned_reason = VALUES(assigned_reason),
                    assigned_at = NOW()
            ");
            $stmt->execute([
                $this->lineAccountId, $userId, $lineUserId, 
                $richMenuId, $richMenu['line_rich_menu_id'], 
                $ruleId, $reason ?? $richMenu['name']
            ]);
            
            // บันทึก log
            $this->logSwitch($userId, $lineUserId, $currentMenu['rich_menu_id'] ?? null, $richMenuId, $triggerType, $reason);
            
            return ['success' => true, 'rich_menu' => $richMenu['name']];
            
        } catch (Exception $e) {
            error_log("DynamicRichMenu::assignRichMenu error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    
    /**
     * กำหนด Default Rich Menu
     */
    public function assignDefaultRichMenu($userId, $lineUserId) {
        $stmt = $this->db->prepare("
            SELECT id FROM rich_menus 
            WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_default = 1 
            LIMIT 1
        ");
        $stmt->execute([$this->lineAccountId]);
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($menu) {
            return $this->assignRichMenu($userId, $lineUserId, $menu['id'], 'rule', null, 'Default Menu');
        }
        
        return ['success' => false, 'error' => 'No default Rich Menu'];
    }
    
    /**
     * ยกเลิก Rich Menu ของผู้ใช้
     */
    public function unlinkRichMenu($userId, $lineUserId) {
        try {
            $result = $this->line->unlinkRichMenuFromUser($lineUserId);
            
            if ($result['code'] === 200) {
                $stmt = $this->db->prepare("DELETE FROM user_rich_menus WHERE line_account_id = ? AND user_id = ?");
                $stmt->execute([$this->lineAccountId, $userId]);
                return ['success' => true];
            }
            
            return ['success' => false, 'error' => 'LINE API error'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * ดึงข้อมูลผู้ใช้สำหรับประเมินเงื่อนไข
     */
    private function getUserData($userId) {
        $stmt = $this->db->prepare("
            SELECT u.*,
                   COALESCE(u.available_points, 0) as points,
                   DATEDIFF(NOW(), u.created_at) as days_since_follow,
                   DATEDIFF(NOW(), u.last_interaction) as days_inactive
            FROM users u
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        // Tier is derived from the balance, not stored on the user row.
        $user['tier'] = 'bronze';
        try {
            require_once __DIR__ . '/TierService.php';
            $tierService = new TierService($this->db, $this->lineAccountId);
            $user['tier'] = $tierService->calculateTier((int) $user['points'])['tier_code'];
        } catch (Exception $e) {
            error_log('DynamicRichMenu: tier calculation failed - ' . $e->getMessage());
        }

        // ดึง tags
        $stmt = $this->db->prepare("
            SELECT t.name FROM user_tags t
            JOIN user_tag_assignments uta ON t.id = uta.tag_id
            WHERE uta.user_id = ?
        ");
        $stmt->execute([$userId]);
        $user['tags'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // ดึงจำนวน orders
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$userId]);
        $user['completed_orders'] = (int)$stmt->fetchColumn();
        
        return $user;
    }
    
    /**
     * ดึงกฎที่ active เรียงตาม priority
     */
    private function getActiveRules() {
        $stmt = $this->db->prepare("
            SELECT * FROM rich_menu_rules 
            WHERE line_account_id = ? AND is_active = 1 
            ORDER BY priority DESC
        ");
        $stmt->execute([$this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * ประเมินเงื่อนไข
     */
    private function evaluateConditions($conditionsJson, $userData) {
        $conditions = json_decode($conditionsJson, true);
        if (!$conditions) return false;
        
        $operator = $conditions['operator'] ?? 'all'; // all = AND, any = OR
        $results = [];
        
        // ตรวจสอบ tags
        if (isset($conditions['tags'])) {
            $userTags = $userData['tags'] ?? [];
            $requiredTags = $conditions['tags'];
            $tagMatch = !empty(array_intersect($requiredTags, $userTags));
            $results[] = $tagMatch;
        }
        
        // ตรวจสอบ is_registered
        if (isset($conditions['is_registered'])) {
            $results[] = (bool)$userData['is_registered'] === $conditions['is_registered'];
        }
        
        // ตรวจสอบ points
        if (isset($conditions['points_min'])) {
            $results[] = ($userData['points'] ?? 0) >= $conditions['points_min'];
        }
        if (isset($conditions['points_max'])) {
            $results[] = ($userData['points'] ?? 0) <= $conditions['points_max'];
        }
        
        // ตรวจสอบ tier
        if (isset($conditions['tier'])) {
            $tiers = is_array($conditions['tier']) ? $conditions['tier'] : [$conditions['tier']];
            $results[] = in_array($userData['tier'] ?? 'bronze', $tiers);
        }
        
        // ตรวจสอบ days_since_follow
        if (isset($conditions['days_since_follow'])) {
            $days = $userData['days_since_follow'] ?? 0;
            if (isset($conditions['days_since_follow']['min'])) {
                $results[] = $days >= $conditions['days_since_follow']['min'];
            }
            if (isset($conditions['days_since_follow']['max'])) {
                $results[] = $days <= $conditions['days_since_follow']['max'];
            }
        }
        
        // ตรวจสอบ days_inactive
        if (isset($conditions['days_inactive'])) {
            $days = $userData['days_inactive'] ?? 0;
            if (isset($conditions['days_inactive']['min'])) {
                $results[] = $days >= $conditions['days_inactive']['min'];
            }
        }
        
        // ตรวจสอบ completed_orders
        if (isset($conditions['completed_orders_min'])) {
            $results[] = ($userData['completed_orders'] ?? 0) >= $conditions['completed_orders_min'];
        }
        
        // ไม่มีเงื่อนไข = ไม่ตรง
        if (empty($results)) return false;
        
        // ประเมินผล
        if ($operator === 'any') {
            return in_array(true, $results);
        }
        return !in_array(false, $results); // all
    }

    
    /**
     * ดึง Rich Menu ปัจจุบันของผู้ใช้
     */
    public function getUserCurrentRichMenu($userId) {
        $stmt = $this->db->prepare("
            SELECT urm.*, rm.name as menu_name 
            FROM user_rich_menus urm
            LEFT JOIN rich_menus rm ON urm.rich_menu_id = rm.id
            WHERE urm.line_account_id = ? AND urm.user_id = ?
        ");
        $stmt->execute([$this->lineAccountId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * บันทึก log การเปลี่ยน Rich Menu
     */
    private function logSwitch($userId, $lineUserId, $fromMenuId, $toMenuId, $triggerType, $detail) {
        $stmt = $this->db->prepare("
            INSERT INTO rich_menu_switch_log (line_account_id, user_id, line_user_id, from_rich_menu_id, to_rich_menu_id, trigger_type, trigger_detail)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$this->lineAccountId, $userId, $lineUserId, $fromMenuId, $toMenuId, $triggerType, $detail]);
    }
    
    // ==================== RULES MANAGEMENT ====================
    
    /**
     * สร้างกฎใหม่
     */
    public function createRule($name, $richMenuId, $conditions, $priority = 0, $description = '') {
        $stmt = $this->db->prepare("
            INSERT INTO rich_menu_rules (line_account_id, name, description, rich_menu_id, priority, conditions)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId, $name, $description, $richMenuId, $priority, 
            is_array($conditions) ? json_encode($conditions) : $conditions
        ]);
        return $this->db->lastInsertId();
    }
    
    /**
     * อัพเดทกฎ
     */
    public function updateRule($ruleId, $data) {
        $fields = [];
        $values = [];
        
        foreach (['name', 'description', 'rich_menu_id', 'priority', 'is_active'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (isset($data['conditions'])) {
            $fields[] = "conditions = ?";
            $values[] = is_array($data['conditions']) ? json_encode($data['conditions']) : $data['conditions'];
        }
        
        if (empty($fields)) return false;
        
        $values[] = $ruleId;
        $values[] = $this->lineAccountId;
        
        $stmt = $this->db->prepare("UPDATE rich_menu_rules SET " . implode(', ', $fields) . " WHERE id = ? AND line_account_id = ?");
        return $stmt->execute($values);
    }
    
    /**
     * ลบกฎ
     */
    public function deleteRule($ruleId) {
        $stmt = $this->db->prepare("DELETE FROM rich_menu_rules WHERE id = ? AND line_account_id = ?");
        return $stmt->execute([$ruleId, $this->lineAccountId]);
    }
    
    /**
     * ดึงกฎทั้งหมด
     */
    public function getRules() {
        $stmt = $this->db->prepare("
            SELECT r.*, rm.name as rich_menu_name 
            FROM rich_menu_rules r
            LEFT JOIN rich_menus rm ON r.rich_menu_id = rm.id
            WHERE r.line_account_id = ?
            ORDER BY r.priority DESC
        ");
        $stmt->execute([$this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ==================== BULK OPERATIONS ====================
    
    /**
     * Re-evaluate และ assign Rich Menu ให้ผู้ใช้ทั้งหมด
     */
    public function reEvaluateAllUsers($limit = 100) {
        $stmt = $this->db->prepare("
            SELECT id, line_user_id FROM users 
            WHERE line_account_id = ? AND line_user_id IS NOT NULL
            LIMIT ?
        ");
        $stmt->execute([$this->lineAccountId, $limit]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($users as $user) {
            $result = $this->assignRichMenuByRules($user['id'], $user['line_user_id']);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "User {$user['id']}: " . ($result['error'] ?? 'Unknown');
            }
        }
        
        return $results;
    }
    
    /**
     * Assign Rich Menu ให้ผู้ใช้ตาม tag
     */
    public function assignByTag($tagName, $richMenuId) {
        $stmt = $this->db->prepare("
            SELECT u.id, u.line_user_id 
            FROM users u
            JOIN user_tag_assignments uta ON u.id = uta.user_id
            JOIN user_tags t ON uta.tag_id = t.id
            WHERE t.name = ? AND u.line_account_id = ? AND u.line_user_id IS NOT NULL
        ");
        $stmt->execute([$tagName, $this->lineAccountId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = ['success' => 0, 'failed' => 0];
        
        foreach ($users as $user) {
            $result = $this->assignRichMenu($user['id'], $user['line_user_id'], $richMenuId, 'manual', null, "Tag: $tagName");
            $result['success'] ? $results['success']++ : $results['failed']++;
        }
        
        return $results;
    }
    
    // ==================== STATISTICS ====================
    
    /**
     * ดึงสถิติการใช้ Rich Menu
     */
    public function getStatistics() {
        // จำนวนผู้ใช้แต่ละ Rich Menu
        $stmt = $this->db->prepare("
            SELECT rm.id, rm.name, COUNT(urm.id) as user_count
            FROM rich_menus rm
            LEFT JOIN user_rich_menus urm ON rm.id = urm.rich_menu_id AND urm.line_account_id = ?
            WHERE rm.line_account_id = ? OR rm.line_account_id IS NULL
            GROUP BY rm.id
        ");
        $stmt->execute([$this->lineAccountId, $this->lineAccountId]);
        $menuStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // จำนวนการ switch วันนี้
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM rich_menu_switch_log 
            WHERE line_account_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$this->lineAccountId]);
        $switchesToday = $stmt->fetchColumn();
        
        // Switch by trigger type
        $stmt = $this->db->prepare("
            SELECT trigger_type, COUNT(*) as count 
            FROM rich_menu_switch_log 
            WHERE line_account_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY trigger_type
        ");
        $stmt->execute([$this->lineAccountId]);
        $switchByType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        return [
            'menu_stats' => $menuStats,
            'switches_today' => $switchesToday,
            'switch_by_type' => $switchByType
        ];
    }
}
