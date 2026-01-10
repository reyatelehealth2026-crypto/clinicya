<?php
/**
 * InboxService - จัดการ Inbox Chat Conversations
 * 
 * Requirements: 3.1, 3.3, 5.1, 5.2, 5.3, 5.4, 11.3
 */

class InboxService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get paginated conversations with filters
     * Requirements: 5.1, 5.2, 5.3, 5.4, 11.3
     * 
     * @param array $filters ['status', 'tag_id', 'assigned_to', 'search', 'date_from', 'date_to']
     * @param int $page Page number
     * @param int $limit Items per page (default 50)
     * @return array ['conversations' => [], 'total' => int, 'page' => int]
     */
    public function getConversations(array $filters = [], int $page = 1, int $limit = 50): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit)); // Cap at 100
        $offset = ($page - 1) * $limit;
        
        // Build base query with subquery for last message
        $sql = "
            SELECT 
                u.id,
                u.line_user_id,
                u.display_name,
                u.picture_url,
                u.phone,
                u.email,
                u.is_blocked,
                u.created_at,
                u.last_interaction,
                lm.last_message_content,
                lm.last_message_at,
                lm.last_message_direction,
                lm.unread_count,
                ca.assigned_to,
                ca.status as assignment_status,
                ca.assigned_at,
                au.username as assigned_admin_name
            FROM users u
            LEFT JOIN (
                SELECT 
                    user_id,
                    MAX(created_at) as last_message_at,
                    (SELECT content FROM messages m2 WHERE m2.user_id = m1.user_id ORDER BY created_at DESC LIMIT 1) as last_message_content,
                    (SELECT direction FROM messages m3 WHERE m3.user_id = m1.user_id ORDER BY created_at DESC LIMIT 1) as last_message_direction,
                    SUM(CASE WHEN is_read = 0 AND direction = 'incoming' THEN 1 ELSE 0 END) as unread_count
                FROM messages m1
                WHERE line_account_id = ?
                GROUP BY user_id
            ) lm ON u.id = lm.user_id
            LEFT JOIN conversation_assignments ca ON u.id = ca.user_id
            LEFT JOIN admin_users au ON ca.assigned_to = au.id
            WHERE u.line_account_id = ?
            AND lm.last_message_at IS NOT NULL
        ";
        
        $params = [$this->lineAccountId, $this->lineAccountId];
        $countParams = [$this->lineAccountId, $this->lineAccountId];
        
        // Apply filters
        $whereConditions = [];
        
        // Status filter (unread, assigned, resolved)
        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'unread':
                    $whereConditions[] = "lm.unread_count > 0";
                    break;
                case 'assigned':
                    $whereConditions[] = "ca.status = 'active'";
                    break;
                case 'resolved':
                    $whereConditions[] = "ca.status = 'resolved'";
                    break;
            }
        }
        
        // Tag filter
        if (!empty($filters['tag_id'])) {
            $whereConditions[] = "EXISTS (
                SELECT 1 FROM user_tag_assignments uta 
                WHERE uta.user_id = u.id AND uta.tag_id = ?
            )";
            $params[] = (int)$filters['tag_id'];
            $countParams[] = (int)$filters['tag_id'];
        }
        
        // Assigned to filter
        if (!empty($filters['assigned_to'])) {
            $whereConditions[] = "ca.assigned_to = ?";
            $params[] = (int)$filters['assigned_to'];
            $countParams[] = (int)$filters['assigned_to'];
        }
        
        // Date range filter
        if (!empty($filters['date_from'])) {
            $whereConditions[] = "lm.last_message_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
            $countParams[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $whereConditions[] = "lm.last_message_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
            $countParams[] = $filters['date_to'] . ' 23:59:59';
        }
        
        // Search filter (name, content)
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $whereConditions[] = "(
                u.display_name LIKE ? 
                OR lm.last_message_content LIKE ?
                OR EXISTS (
                    SELECT 1 FROM user_tag_assignments uta2
                    JOIN user_tags ut ON uta2.tag_id = ut.id
                    WHERE uta2.user_id = u.id AND ut.name LIKE ?
                )
            )";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
        }
        
        // Add where conditions to SQL
        if (!empty($whereConditions)) {
            $sql .= " AND " . implode(" AND ", $whereConditions);
        }
        
        // Count total
        $countSql = "
            SELECT COUNT(DISTINCT u.id)
            FROM users u
            LEFT JOIN (
                SELECT 
                    user_id,
                    MAX(created_at) as last_message_at,
                    (SELECT content FROM messages m2 WHERE m2.user_id = m1.user_id ORDER BY created_at DESC LIMIT 1) as last_message_content,
                    SUM(CASE WHEN is_read = 0 AND direction = 'incoming' THEN 1 ELSE 0 END) as unread_count
                FROM messages m1
                WHERE line_account_id = ?
                GROUP BY user_id
            ) lm ON u.id = lm.user_id
            LEFT JOIN conversation_assignments ca ON u.id = ca.user_id
            WHERE u.line_account_id = ?
            AND lm.last_message_at IS NOT NULL
        ";
        
        if (!empty($whereConditions)) {
            $countSql .= " AND " . implode(" AND ", $whereConditions);
        }
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();
        
        // Add ordering and pagination
        $sql .= " ORDER BY lm.last_message_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get tags for each conversation
        foreach ($conversations as &$conv) {
            $conv['tags'] = $this->getUserTags($conv['id']);
        }
        
        return [
            'conversations' => $conversations,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ];
    }

    
    /**
     * Get user tags
     * 
     * @param int $userId User ID
     * @return array Tags
     */
    private function getUserTags(int $userId): array {
        $sql = "
            SELECT ut.id, ut.name, ut.color
            FROM user_tags ut
            JOIN user_tag_assignments uta ON ut.id = uta.tag_id
            WHERE uta.user_id = ?
            ORDER BY ut.name
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get paginated messages for a conversation
     * Requirements: 11.3 - Load only 50 messages initially with pagination
     * 
     * @param int $userId User ID
     * @param int $page Page number
     * @param int $limit Messages per page (default 50)
     * @return array ['messages' => [], 'total' => int, 'has_more' => bool]
     */
    public function getMessages(int $userId, int $page = 1, int $limit = 50): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit)); // Cap at 100
        $offset = ($page - 1) * $limit;
        
        // Count total messages
        $countSql = "SELECT COUNT(*) FROM messages WHERE user_id = ? AND line_account_id = ?";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute([$userId, $this->lineAccountId]);
        $total = (int)$countStmt->fetchColumn();
        
        // Get messages with pagination (newest first for display, but we'll reverse for chat order)
        $sql = "
            SELECT 
                id,
                user_id,
                direction,
                message_type,
                content,
                is_read,
                sent_by,
                created_at
            FROM messages
            WHERE user_id = ? AND line_account_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $this->lineAccountId, $limit, $offset]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Reverse to show oldest first in the page (chat order)
        $messages = array_reverse($messages);
        
        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'has_more' => ($offset + $limit) < $total
        ];
    }
    
    /**
     * Search messages across all conversations
     * Requirements: 5.1 - Search across customer name, message content, and tags
     * 
     * @param string $query Search query
     * @param int $limit Max results (default 50)
     * @return array Matching conversations with highlighted results
     */
    public function searchMessages(string $query, int $limit = 50): array {
        if (empty(trim($query))) {
            return [];
        }
        
        $searchTerm = '%' . trim($query) . '%';
        $limit = max(1, min(100, $limit));
        
        // Search in messages content
        $sql = "
            SELECT DISTINCT
                u.id as user_id,
                u.display_name,
                u.picture_url,
                m.content as matched_content,
                m.created_at as matched_at,
                'message' as match_type
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE m.line_account_id = ?
            AND m.content LIKE ?
            ORDER BY m.created_at DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $searchTerm, $limit]);
        $messageResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Search in user names
        $sql = "
            SELECT DISTINCT
                u.id as user_id,
                u.display_name,
                u.picture_url,
                u.display_name as matched_content,
                u.last_interaction as matched_at,
                'name' as match_type
            FROM users u
            WHERE u.line_account_id = ?
            AND u.display_name LIKE ?
            AND EXISTS (SELECT 1 FROM messages m WHERE m.user_id = u.id)
            ORDER BY u.last_interaction DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $searchTerm, $limit]);
        $nameResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Search in tags
        $sql = "
            SELECT DISTINCT
                u.id as user_id,
                u.display_name,
                u.picture_url,
                ut.name as matched_content,
                u.last_interaction as matched_at,
                'tag' as match_type
            FROM users u
            JOIN user_tag_assignments uta ON u.id = uta.user_id
            JOIN user_tags ut ON uta.tag_id = ut.id
            WHERE u.line_account_id = ?
            AND ut.name LIKE ?
            AND EXISTS (SELECT 1 FROM messages m WHERE m.user_id = u.id)
            ORDER BY u.last_interaction DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $searchTerm, $limit]);
        $tagResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge and deduplicate results
        $allResults = array_merge($messageResults, $nameResults, $tagResults);
        
        // Group by user_id and keep the most relevant match
        $grouped = [];
        foreach ($allResults as $result) {
            $userId = $result['user_id'];
            if (!isset($grouped[$userId])) {
                $grouped[$userId] = $result;
                $grouped[$userId]['matches'] = [];
            }
            $grouped[$userId]['matches'][] = [
                'type' => $result['match_type'],
                'content' => $result['matched_content']
            ];
        }
        
        // Convert to array and limit
        $results = array_values($grouped);
        return array_slice($results, 0, $limit);
    }

    
    /**
     * Assign conversation to admin
     * Requirements: 3.1 - Notify assigned admin
     * 
     * @param int $userId Customer user ID
     * @param int $adminId Admin user ID
     * @param int|null $assignedBy Admin who assigned (null for self-assign)
     * @return bool Success
     */
    public function assignConversation(int $userId, int $adminId, ?int $assignedBy = null): bool {
        // Check if user exists
        $checkSql = "SELECT id FROM users WHERE id = ? AND line_account_id = ?";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([$userId, $this->lineAccountId]);
        if (!$checkStmt->fetch()) {
            return false;
        }
        
        // Check if admin exists
        $checkAdminSql = "SELECT id FROM admin_users WHERE id = ?";
        $checkAdminStmt = $this->db->prepare($checkAdminSql);
        $checkAdminStmt->execute([$adminId]);
        if (!$checkAdminStmt->fetch()) {
            return false;
        }
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE for upsert
        $sql = "
            INSERT INTO conversation_assignments 
            (user_id, assigned_to, assigned_by, assigned_at, status)
            VALUES (?, ?, ?, NOW(), 'active')
            ON DUPLICATE KEY UPDATE 
                assigned_to = VALUES(assigned_to),
                assigned_by = VALUES(assigned_by),
                assigned_at = NOW(),
                status = 'active'
        ";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId, $adminId, $assignedBy ?? $adminId]);
    }
    
    /**
     * Unassign conversation
     * 
     * @param int $userId Customer user ID
     * @return bool Success
     */
    public function unassignConversation(int $userId): bool {
        $sql = "DELETE FROM conversation_assignments WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }
    
    /**
     * Resolve conversation assignment
     * 
     * @param int $userId Customer user ID
     * @return bool Success
     */
    public function resolveConversation(int $userId): bool {
        $sql = "
            UPDATE conversation_assignments 
            SET status = 'resolved', resolved_at = NOW()
            WHERE user_id = ?
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }
    
    /**
     * Get conversations assigned to specific admin
     * Requirements: 3.3 - Filter to show only their assignments
     * 
     * @param int $adminId Admin user ID
     * @param int $page Page number
     * @param int $limit Items per page
     * @return array Assigned conversations
     */
    public function getAssignedConversations(int $adminId, int $page = 1, int $limit = 50): array {
        return $this->getConversations(['assigned_to' => $adminId], $page, $limit);
    }
    
    /**
     * Get assignment info for a user
     * 
     * @param int $userId User ID
     * @return array|null Assignment info or null if not assigned
     */
    public function getAssignment(int $userId): ?array {
        $sql = "
            SELECT 
                ca.*,
                au.username as assigned_admin_name
            FROM conversation_assignments ca
            LEFT JOIN admin_users au ON ca.assigned_to = au.id
            WHERE ca.user_id = ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Mark messages as read
     * 
     * @param int $userId User ID
     * @return bool Success
     */
    public function markAsRead(int $userId): bool {
        $sql = "
            UPDATE messages 
            SET is_read = 1 
            WHERE user_id = ? 
            AND line_account_id = ? 
            AND direction = 'incoming' 
            AND is_read = 0
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId, $this->lineAccountId]);
    }
    
    /**
     * Get unread count for account
     * 
     * @return int Unread message count
     */
    public function getUnreadCount(): int {
        $sql = "
            SELECT COUNT(*) 
            FROM messages 
            WHERE line_account_id = ? 
            AND direction = 'incoming' 
            AND is_read = 0
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get conversation count by status
     * 
     * @return array ['total' => int, 'unread' => int, 'assigned' => int, 'resolved' => int]
     */
    public function getConversationCounts(): array {
        // Total conversations with messages
        $totalSql = "
            SELECT COUNT(DISTINCT user_id) 
            FROM messages 
            WHERE line_account_id = ?
        ";
        $totalStmt = $this->db->prepare($totalSql);
        $totalStmt->execute([$this->lineAccountId]);
        $total = (int)$totalStmt->fetchColumn();
        
        // Unread conversations
        $unreadSql = "
            SELECT COUNT(DISTINCT user_id) 
            FROM messages 
            WHERE line_account_id = ? 
            AND direction = 'incoming' 
            AND is_read = 0
        ";
        $unreadStmt = $this->db->prepare($unreadSql);
        $unreadStmt->execute([$this->lineAccountId]);
        $unread = (int)$unreadStmt->fetchColumn();
        
        // Assigned conversations
        $assignedSql = "
            SELECT COUNT(*) 
            FROM conversation_assignments 
            WHERE status = 'active'
        ";
        $assignedStmt = $this->db->prepare($assignedSql);
        $assignedStmt->execute();
        $assigned = (int)$assignedStmt->fetchColumn();
        
        // Resolved conversations
        $resolvedSql = "
            SELECT COUNT(*) 
            FROM conversation_assignments 
            WHERE status = 'resolved'
        ";
        $resolvedStmt = $this->db->prepare($resolvedSql);
        $resolvedStmt->execute();
        $resolved = (int)$resolvedStmt->fetchColumn();
        
        return [
            'total' => $total,
            'unread' => $unread,
            'assigned' => $assigned,
            'resolved' => $resolved
        ];
    }
}
