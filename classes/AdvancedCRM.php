<?php
/**
 * Advanced CRM Manager - Data Collection, Segmentation, Analytics
 * @version 3.0
 */

class AdvancedCRM
{
    private $db;
    private $lineAccountId;
    private $line;

    public function __construct($db, $lineAccountId = null, $line = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->line = $line;
    }

    // =============================================
    // TAG MANAGEMENT
    // =============================================

    public function createTag($name, $color = '#3B82F6', $description = '', $type = 'manual', $autoRules = null)
    {
        $stmt = $this->db->prepare("INSERT INTO user_tags 
            (line_account_id, name, color, description, tag_type, auto_assign_rules) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE color = ?, description = ?, auto_assign_rules = ?");
        $stmt->execute([
            $this->lineAccountId,
            $name,
            $color,
            $description,
            $type,
            $autoRules ? json_encode($autoRules) : null,
            $color,
            $description,
            $autoRules ? json_encode($autoRules) : null
        ]);
        return $this->db->lastInsertId();
    }

    public function assignTag($userId, $tagId, $assignedBy = 'manual', $reason = null)
    {
        $stmt = $this->db->prepare("INSERT INTO user_tag_assignments 
            (user_id, tag_id, assigned_by, assigned_reason) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE assigned_by = ?, assigned_reason = ?");
        $stmt->execute([$userId, $tagId, $assignedBy, $reason, $assignedBy, $reason]);
        return true;
    }

    public function removeTag($userId, $tagId)
    {
        $stmt = $this->db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
        $stmt->execute([$userId, $tagId]);
        return $stmt->rowCount() > 0;
    }

    public function getUserTags($userId)
    {
        $stmt = $this->db->prepare("SELECT t.*, a.assigned_by, a.assigned_reason, a.created_at as assigned_at
            FROM user_tags t
            JOIN user_tag_assignments a ON t.id = a.tag_id
            WHERE a.user_id = ?
            ORDER BY a.created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUsersByTag($tagId, $limit = 1000)
    {
        $stmt = $this->db->prepare("SELECT u.*, a.created_at as assigned_at
            FROM users u
            JOIN user_tag_assignments a ON u.id = a.user_id
            WHERE a.tag_id = ?
            ORDER BY a.created_at DESC
            LIMIT ?");
        $stmt->execute([$tagId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =============================================
    // SEGMENTATION
    // =============================================

    public function createSegment($name, $conditions, $description = '', $type = 'dynamic')
    {
        $stmt = $this->db->prepare("INSERT INTO customer_segments 
            (line_account_id, name, description, segment_type, conditions) 
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $this->lineAccountId,
            $name,
            $description,
            $type,
            json_encode($conditions)
        ]);

        $segmentId = $this->db->lastInsertId();
        $this->calculateSegmentMembers($segmentId);

        return $segmentId;
    }

    public function calculateSegmentMembers($segmentId)
    {
        $stmt = $this->db->prepare("SELECT * FROM customer_segments WHERE id = ?");
        $stmt->execute([$segmentId]);
        $segment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$segment)
            return 0;

        $conditions = json_decode($segment['conditions'], true);
        if (!$conditions)
            return 0;

        $query = $this->buildSegmentQuery($conditions);

        $stmt = $this->db->prepare("DELETE FROM segment_members WHERE segment_id = ?");
        $stmt->execute([$segmentId]);

        $insertQuery = "INSERT INTO segment_members (segment_id, user_id, score)
            SELECT ?, u.id, 1 FROM users u
            LEFT JOIN loyalty_points lp ON u.id = lp.user_id
            WHERE (u.line_account_id = ? OR u.line_account_id IS NULL) AND u.is_blocked = 0
            {$query['where']}";

        $stmt = $this->db->prepare($insertQuery);
        $params = array_merge([$segmentId, $this->lineAccountId], $query['params']);
        $stmt->execute($params);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM segment_members WHERE segment_id = ?");
        $stmt->execute([$segmentId]);
        $count = $stmt->fetchColumn();

        $stmt = $this->db->prepare("UPDATE customer_segments SET user_count = ?, last_calculated_at = NOW() WHERE id = ?");
        $stmt->execute([$count, $segmentId]);

        return $count;
    }

    private function buildSegmentQuery($conditions)
    {
        $where = [];
        $params = [];

        foreach ($conditions as $field => $condition) {
            if (is_array($condition)) {
                foreach ($condition as $op => $value) {
                    switch ($field) {
                        case 'last_activity_days':
                            // Inactive for X days (Recency)
                            $where[] = "DATEDIFF(NOW(), COALESCE(u.last_message_at, u.last_order_at, u.created_at)) {$op} ?";
                            $params[] = $value;
                            break;

                        case 'created_days':
                            $where[] = "DATEDIFF(NOW(), u.created_at) {$op} ?";
                            $params[] = $value;
                            break;

                        case 'total_spent':
                            // Monetary
                            $where[] = "COALESCE(u.total_spent, 0) {$op} ?";
                            $params[] = $value;
                            break;

                        case 'order_count':
                            // Frequency
                            $where[] = "COALESCE(u.total_orders, 0) {$op} ?";
                            $params[] = $value;
                            break;

                        case 'tier':
                            $where[] = "lp.tier = ?";
                            $params[] = $value;
                            break;

                        case 'has_tag':
                            // Subquery for tag check
                            $where[] = "EXISTS (SELECT 1 FROM user_tag_assignments uta WHERE uta.user_id = u.id AND uta.tag_id = ?)";
                            $params[] = $value;
                            break;

                        case 'province':
                            $where[] = "u.province = ?";
                            $params[] = $value;
                            break;
                    }
                }
            }
        }

        return [
            'where' => $where ? 'AND ' . implode(' AND ', $where) : '',
            'params' => $params
        ];
    }

    public function getSegmentMembers($segmentId, $limit = 1000)
    {
        $stmt = $this->db->prepare("SELECT u.*, sm.score, sm.added_at
            FROM users u
            JOIN segment_members sm ON u.id = sm.user_id
            WHERE sm.segment_id = ?
            ORDER BY sm.score DESC
            LIMIT ?");
        $stmt->execute([$segmentId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSegments()
    {
        $stmt = $this->db->prepare("SELECT * FROM customer_segments 
            WHERE line_account_id = ? OR line_account_id IS NULL 
            ORDER BY user_count DESC");
        $stmt->execute([$this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =============================================
    // ANALYTICS
    // =============================================

    public function getUserAnalytics($days = 30)
    {
        $stats = [];

        // Active users
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT user_id) FROM user_behaviors 
            WHERE (line_account_id = ? OR line_account_id IS NULL) 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$this->lineAccountId, $days]);
        $stats['active_users'] = $stmt->fetchColumn();

        // New users
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users 
            WHERE (line_account_id = ? OR line_account_id IS NULL) 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$this->lineAccountId, $days]);
        $stats['new_users'] = $stmt->fetchColumn();

        // Top tags
        $stmt = $this->db->prepare("SELECT t.name, t.color, COUNT(a.user_id) as count
            FROM user_tags t
            LEFT JOIN user_tag_assignments a ON t.id = a.tag_id
            WHERE (t.line_account_id = ? OR t.line_account_id IS NULL)
            GROUP BY t.id ORDER BY count DESC LIMIT 10");
        $stmt->execute([$this->lineAccountId]);
        $stats['top_tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }
}
