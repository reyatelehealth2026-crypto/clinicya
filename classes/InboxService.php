<?php
/**
 * InboxService - จัดการ Inbox Chat Conversations
 * 
 * Requirements: 3.1, 3.3, 5.1, 5.2, 5.3, 5.4, 11.3
 */

class InboxService
{
    private $db;
    private $lineAccountId;

    public function __construct(PDO $db, ?int $lineAccountId = null)
    {
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
    public function getConversations(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit)); // Cap at 100
        $offset = ($page - 1) * $limit;

        // Build base query with subquery for last message and assignees
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
                au.username as assigned_admin_name,
                (SELECT GROUP_CONCAT(CONCAT(au2.username, ':', cma.admin_id) SEPARATOR '||')
                 FROM conversation_multi_assignees cma
                 LEFT JOIN admin_users au2 ON cma.admin_id = au2.id
                 WHERE cma.user_id = u.id AND cma.status = 'active') as assignees_list
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
            $params[] = (int) $filters['tag_id'];
            $countParams[] = (int) $filters['tag_id'];
        }

        // Assigned to filter (supports multi-assignee)
        if (!empty($filters['assigned_to'])) {
            $whereConditions[] = "EXISTS (
                SELECT 1 FROM conversation_multi_assignees cma_filter
                WHERE cma_filter.user_id = u.id 
                AND cma_filter.admin_id = ?
                AND cma_filter.status = 'active'
            )";
            $params[] = (int) $filters['assigned_to'];
            $countParams[] = (int) $filters['assigned_to'];
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
        $total = (int) $countStmt->fetchColumn();

        // Add ordering and pagination
        $sql .= " ORDER BY lm.last_message_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get tags and parse assignees for each conversation
        foreach ($conversations as &$conv) {
            $conv['tags'] = $this->getUserTags($conv['id']);

            // Parse assignees_list into array
            $conv['assignees'] = [];
            if (!empty($conv['assignees_list'])) {
                $assigneesParts = explode('||', $conv['assignees_list']);
                foreach ($assigneesParts as $part) {
                    if (strpos($part, ':') !== false) {
                        list($username, $adminId) = explode(':', $part, 2);
                        $conv['assignees'][] = [
                            'admin_id' => (int) $adminId,
                            'username' => $username
                        ];
                    }
                }
            }
            unset($conv['assignees_list']); // Remove raw data
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
     * Get conversations with delta updates (only since timestamp)
     * Uses cursor-based pagination for better performance
     * Requirements: 7.1, 7.2, 11.4
     *
     * @param int $accountId LINE account ID
     * @param int $since Unix timestamp for delta updates (0 for all)
     * @param string|null $cursor Pagination cursor (last_message_at timestamp)
     * @param int $limit Items per page (default 50)
     * @param string|null $search Search query for filtering conversations
     * @param array $filters Additional filters (status, chatStatus, tag, assignee)
     * @return array ['conversations' => [], 'next_cursor' => string|null, 'has_more' => bool]
     */
    public function getConversationsDelta(
        int $accountId,
        int $since = 0,
        ?string $cursor = null,
        int $limit = 50,
        ?string $search = null,
        array $filters = []
    ): array {
        $limit = max(1, min(100, $limit)); // Cap at 100

        // Build query with cursor-based pagination
        // Select only necessary fields (no full message content)
        // Use subquery for last_message_at to match the initial page load query
        $sql = "
            SELECT
                u.id,
                u.display_name,
                u.picture_url,
                u.chat_status,
                (SELECT created_at FROM messages m_last
                 WHERE m_last.user_id = u.id
                 ORDER BY m_last.created_at DESC LIMIT 1) as last_message_at,
                (SELECT COUNT(*) FROM messages m
                 WHERE m.user_id = u.id
                 AND m.direction = 'incoming'
                 AND m.is_read = 0) as unread_count,
                (SELECT SUBSTRING(content, 1, 100) FROM messages m2
                 WHERE m2.user_id = u.id
                 ORDER BY m2.created_at DESC LIMIT 1) as last_message_preview,
                (SELECT message_type FROM messages m3
                 WHERE m3.user_id = u.id
                 ORDER BY m3.created_at DESC LIMIT 1) as last_message_type,
                ca.assigned_to,
                ca.status as assignment_status
            FROM users u
            LEFT JOIN conversation_assignments ca ON ca.user_id = u.id
            WHERE u.line_account_id = ?
            AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)
        ";

        $params = [$accountId];

        // Search filter: search in display_name and last message
        if ($search !== null && trim($search) !== '') {
            $searchTerm = '%' . trim($search) . '%';
            $sql .= " AND (
                u.display_name LIKE ?
                OR EXISTS (
                    SELECT 1 FROM messages m_search
                    WHERE m_search.user_id = u.id
                    AND m_search.content LIKE ?
                    LIMIT 1
                )
            )";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Filter by chat status (work status)
        if (!empty($filters['chatStatus'])) {
            $sql .= " AND u.chat_status = ?";
            $params[] = $filters['chatStatus'];
        }

        // Filter by unread only
        if (!empty($filters['unreadOnly'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM messages m_unread
                WHERE m_unread.user_id = u.id
                AND m_unread.direction = 'incoming'
                AND m_unread.is_read = 0
            )";
        }

        // Filter by tag
        if (!empty($filters['tagId'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM user_tag_assignments uta
                WHERE uta.user_id = u.id
                AND uta.tag_id = ?
            )";
            $params[] = (int) $filters['tagId'];
        }

        // Filter by assignee
        if (!empty($filters['assigneeId'])) {
            if ($filters['assigneeId'] === 'unassigned') {
                $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM conversation_multi_assignees cma
                    WHERE cma.user_id = u.id
                    AND cma.status = 'active'
                )";
            } else {
                $sql .= " AND EXISTS (
                    SELECT 1 FROM conversation_multi_assignees cma
                    WHERE cma.user_id = u.id
                    AND cma.admin_id = ?
                    AND cma.status = 'active'
                )";
                $params[] = (int) $filters['assigneeId'];
            }
        }

        // Delta updates: only conversations updated since timestamp
        if ($since > 0) {
            $sql .= " AND (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) > FROM_UNIXTIME(?)";
            $params[] = $since;
        }

        // Cursor-based pagination: use last_message_at (from messages table) as cursor
        if ($cursor !== null && trim($cursor) !== '') {
            $sql .= " AND (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) < ?";
            $params[] = $cursor;
        }

        // Order by most recent message first and limit
        $sql .= " ORDER BY (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) DESC LIMIT ?";
        $params[] = $limit + 1; // Fetch one extra to check if there are more

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if there are more results
        $hasMore = count($conversations) > $limit;
        if ($hasMore) {
            array_pop($conversations); // Remove the extra item
        }

        // Get next cursor (last_message_at of last item)
        $nextCursor = null;
        if ($hasMore && !empty($conversations)) {
            $lastConv = end($conversations);
            $nextCursor = $lastConv['last_message_at'];
        }

        // Get tags for each conversation
        foreach ($conversations as &$conv) {
            $conv['tags'] = $this->getUserTags($conv['id']);

            // Get all assignees (multi-assignee support)
            $conv['assignees'] = $this->getAssignedAdminIds($conv['id']);
        }

        return [
            'conversations' => $conversations,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'count' => count($conversations)
        ];
    }

    /**
     * Get user tags
     * 
     * @param int $userId User ID
     * @return array Tags
     */
    private function getUserTags(int $userId): array
    {
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
    public function getMessages(int $userId, int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit)); // Cap at 100
        $offset = ($page - 1) * $limit;

        // Count total messages
        $countSql = "SELECT COUNT(*) FROM messages WHERE user_id = ? AND line_account_id = ?";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute([$userId, $this->lineAccountId]);
        $total = (int) $countStmt->fetchColumn();

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
     * Get messages with cursor-based pagination (more efficient than offset)
     * Uses message ID as cursor instead of OFFSET for better performance on large datasets
     * Requirements: 3.1, 3.2, 7.2
     * 
     * @param int $userId User ID
     * @param string|null $cursor Pagination cursor (message ID)
     * @param int $limit Messages per page (default 50)
     * @return array ['messages' => [], 'next_cursor' => string|null, 'has_more' => bool]
     */
    public function getMessagesCursor(
        int $userId,
        ?string $cursor = null,
        int $limit = 50
    ): array {
        $limit = max(1, min(100, $limit)); // Cap at 100

        // Build query with cursor-based pagination
        // Cursor is the message ID - fetch messages with ID less than cursor (older messages)
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
            WHERE user_id = ?
        ";

        $params = [$userId];

        // Add cursor condition if provided (for loading older messages)
        if ($cursor !== null) {
            $sql .= " AND id < ?";
            $params[] = (int) $cursor;
        }

        // Order by ID descending (newest first) and limit
        // Fetch one extra to check if there are more
        $sql .= " ORDER BY id DESC LIMIT ?";
        $params[] = $limit + 1;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if there are more results
        $hasMore = count($messages) > $limit;
        if ($hasMore) {
            array_pop($messages); // Remove the extra item
        }

        // Get next cursor (ID of last message)
        $nextCursor = null;
        if ($hasMore && !empty($messages)) {
            $lastMessage = end($messages);
            $nextCursor = (string) $lastMessage['id'];
        }

        // Reverse to show oldest first (chat order)
        $messages = array_reverse($messages);

        return [
            'messages' => $messages,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'count' => count($messages)
        ];
    }

    /**
     * Poll for new messages and conversation updates since timestamp
     * Efficient query to get only delta updates for real-time polling
     * Requirements: 4.3
     * 
     * @param int $accountId LINE account ID
     * @param int $since Unix timestamp (only fetch messages after this time)
     * @return array ['new_messages' => [], 'updated_conversations' => []]
     */
    public function pollUpdates(int $accountId, int $since): array
    {
        // Efficient query to get only new incoming messages since last check
        // Include user info for conversation bumping
        $sql = "
            SELECT 
                m.id,
                m.user_id,
                m.direction,
                m.message_type,
                m.content,
                m.is_read,
                m.created_at,
                u.display_name,
                u.picture_url,
                u.last_interaction
            FROM messages m
            JOIN users u ON u.id = m.user_id
            WHERE u.line_account_id = ?
            AND m.created_at > FROM_UNIXTIME(?)
            ORDER BY m.created_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$accountId, $since]);
        $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get updated conversations (conversations with new messages)
        // Group by user_id to get conversation-level updates
        $updatedConversations = [];
        $seenUsers = [];

        foreach ($newMessages as $message) {
            $userId = $message['user_id'];

            // Only add each conversation once
            if (!in_array($userId, $seenUsers)) {
                $seenUsers[] = $userId;

                // Get unread count for this conversation
                $unreadSql = "
                    SELECT COUNT(*) 
                    FROM messages 
                    WHERE user_id = ? 
                    AND direction = 'incoming' 
                    AND is_read = 0
                ";
                $unreadStmt = $this->db->prepare($unreadSql);
                $unreadStmt->execute([$userId]);
                $unreadCount = (int) $unreadStmt->fetchColumn();

                $updatedConversations[] = [
                    'user_id' => $userId,
                    'display_name' => $message['display_name'],
                    'picture_url' => $message['picture_url'],
                    'last_message_at' => $message['last_interaction'],
                    'last_message_preview' => substr($message['content'], 0, 100),
                    'unread_count' => $unreadCount
                ];
            }
        }

        return [
            'new_messages' => $newMessages,
            'updated_conversations' => $updatedConversations,
            'count' => count($newMessages)
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
    public function searchMessages(string $query, int $limit = 50): array
    {
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
     * Assign conversation to admin(s)
     * Requirements: 3.1 - Notify assigned admin, supports multiple assignees
     * 
     * @param int $userId Customer user ID
     * @param int|array $adminIds Admin user ID(s) - can be single int or array
     * @param int|null $assignedBy Admin who assigned (null for self-assign)
     * @return bool Success
     */
    public function assignConversation(int $userId, $adminIds, ?int $assignedBy = null): bool
    {
        // Convert single ID to array
        if (!is_array($adminIds)) {
            $adminIds = [$adminIds];
        }

        // Check if user exists
        $checkSql = "SELECT id FROM users WHERE id = ? AND line_account_id = ?";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([$userId, $this->lineAccountId]);
        if (!$checkStmt->fetch()) {
            return false;
        }

        // Validate all admin IDs
        foreach ($adminIds as $adminId) {
            $checkAdminSql = "SELECT id FROM admin_users WHERE id = ?";
            $checkAdminStmt = $this->db->prepare($checkAdminSql);
            $checkAdminStmt->execute([$adminId]);
            if (!$checkAdminStmt->fetch()) {
                return false;
            }
        }

        // Insert assignments (multi-assignee support)
        $sql = "
            INSERT INTO conversation_multi_assignees 
            (user_id, admin_id, assigned_by, assigned_at, status)
            VALUES (?, ?, ?, NOW(), 'active')
            ON DUPLICATE KEY UPDATE 
                assigned_by = VALUES(assigned_by),
                assigned_at = NOW(),
                status = 'active'
        ";

        $stmt = $this->db->prepare($sql);

        foreach ($adminIds as $adminId) {
            if (!$stmt->execute([$userId, $adminId, $assignedBy ?? $adminId])) {
                return false;
            }
        }

        // Also update old table for backward compatibility (use first admin)
        $legacySql = "
            INSERT INTO conversation_assignments 
            (user_id, assigned_to, assigned_by, assigned_at, status)
            VALUES (?, ?, ?, NOW(), 'active')
            ON DUPLICATE KEY UPDATE 
                assigned_to = VALUES(assigned_to),
                assigned_by = VALUES(assigned_by),
                assigned_at = NOW(),
                status = 'active'
        ";
        $legacyStmt = $this->db->prepare($legacySql);
        $legacyStmt->execute([$userId, $adminIds[0], $assignedBy ?? $adminIds[0]]);

        return true;
    }

    /**
     * Remove specific admin from conversation assignment
     * 
     * @param int $userId Customer user ID
     * @param int $adminId Admin user ID to remove
     * @return bool Success
     */
    public function removeAssignee(int $userId, int $adminId): bool
    {
        $sql = "DELETE FROM conversation_multi_assignees WHERE user_id = ? AND admin_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId, $adminId]);
    }

    /**
     * Unassign conversation (remove all assignees)
     * 
     * @param int $userId Customer user ID
     * @return bool Success
     */
    public function unassignConversation(int $userId): bool
    {
        // Remove from multi-assignees
        $sql = "DELETE FROM conversation_multi_assignees WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$userId]);

        // Also remove from legacy table
        $legacySql = "DELETE FROM conversation_assignments WHERE user_id = ?";
        $legacyStmt = $this->db->prepare($legacySql);
        $legacyStmt->execute([$userId]);

        return $result;
    }

    /**
     * Resolve conversation assignment
     * 
     * @param int $userId Customer user ID
     * @return bool Success
     */
    public function resolveConversation(int $userId): bool
    {
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
    public function getAssignedConversations(int $adminId, int $page = 1, int $limit = 50): array
    {
        return $this->getConversations(['assigned_to' => $adminId], $page, $limit);
    }

    /**
     * Get assignment info for a user (supports multiple assignees)
     * 
     * @param int $userId User ID
     * @return array Assignment info with assignees array
     */
    public function getAssignment(int $userId): array
    {
        // Get all assignees for this conversation
        $sql = "
            SELECT 
                cma.admin_id,
                cma.assigned_by,
                cma.assigned_at,
                cma.status,
                cma.resolved_at,
                au.username,
                au.display_name
            FROM conversation_multi_assignees cma
            LEFT JOIN admin_users au ON cma.admin_id = au.id
            WHERE cma.user_id = ?
            ORDER BY cma.assigned_at DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($assignees)) {
            return [
                'user_id' => $userId,
                'assignees' => [],
                'is_assigned' => false
            ];
        }

        return [
            'user_id' => $userId,
            'assignees' => $assignees,
            'is_assigned' => true,
            'status' => $assignees[0]['status'] ?? 'active',
            'assigned_at' => $assignees[0]['assigned_at'] ?? null
        ];
    }

    /**
     * Get all admin IDs assigned to a conversation
     * 
     * @param int $userId User ID
     * @return array Array of admin IDs
     */
    public function getAssignedAdminIds(int $userId): array
    {
        $sql = "SELECT admin_id FROM conversation_multi_assignees WHERE user_id = ? AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Mark messages as read
     * 
     * @param int $userId User ID
     * @return bool Success
     */
    public function markAsRead(int $userId): bool
    {
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
    public function getUnreadCount(): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM messages 
            WHERE line_account_id = ? 
            AND direction = 'incoming' 
            AND is_read = 0
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get conversation count by status
     * 
     * @return array ['total' => int, 'unread' => int, 'assigned' => int, 'resolved' => int]
     */
    public function getConversationCounts(): array
    {
        // Total conversations with messages
        $totalSql = "
            SELECT COUNT(DISTINCT user_id) 
            FROM messages 
            WHERE line_account_id = ?
        ";
        $totalStmt = $this->db->prepare($totalSql);
        $totalStmt->execute([$this->lineAccountId]);
        $total = (int) $totalStmt->fetchColumn();

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
        $unread = (int) $unreadStmt->fetchColumn();

        // Assigned conversations
        $assignedSql = "
            SELECT COUNT(*) 
            FROM conversation_assignments 
            WHERE status = 'active'
        ";
        $assignedStmt = $this->db->prepare($assignedSql);
        $assignedStmt->execute();
        $assigned = (int) $assignedStmt->fetchColumn();

        // Resolved conversations
        $resolvedSql = "
            SELECT COUNT(*) 
            FROM conversation_assignments 
            WHERE status = 'resolved'
        ";
        $resolvedStmt = $this->db->prepare($resolvedSql);
        $resolvedStmt->execute();
        $resolved = (int) $resolvedStmt->fetchColumn();

        return [
            'total' => $total,
            'unread' => $unread,
            'assigned' => $assigned,
            'resolved' => $resolved
        ];
    }

    /**
     * Get chat templates
     * 
     * @return array List of templates
     */
    public function getTemplates(): array
    {
        $sql = "SELECT id, name, content, category FROM chat_templates WHERE is_active = 1 ORDER BY category, name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
