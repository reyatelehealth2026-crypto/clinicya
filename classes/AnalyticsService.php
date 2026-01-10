<?php
/**
 * AnalyticsService - จัดการ Message Analytics และ Response Time
 * 
 * Requirements: 6.1, 6.2, 6.4, 6.5
 */

class AnalyticsService {
    private $db;
    private $lineAccountId;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Calculate average response time
     * Requirements: 6.1 - Display average response time
     * 
     * @param string $period 'day', 'week', 'month'
     * @return float Average response time in seconds
     */
    public function getAverageResponseTime(string $period = 'day'): float {
        // Determine date range based on period
        $dateCondition = $this->getDateCondition($period);
        
        $sql = "
            SELECT AVG(response_time_seconds) as avg_response_time
            FROM message_analytics ma
            JOIN messages m ON ma.message_id = m.id
            WHERE m.line_account_id = ?
            AND ma.response_time_seconds IS NOT NULL
            AND ma.response_time_seconds > 0
            {$dateCondition}
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (float)($result['avg_response_time'] ?? 0);
    }
    
    /**
     * Get conversations exceeding SLA
     * Requirements: 6.2 - Highlight conversations exceeding SLA with warning indicator
     * 
     * @param int $slaSeconds SLA threshold in seconds
     * @return array Conversations exceeding SLA
     */
    public function getConversationsExceedingSLA(int $slaSeconds): array {
        // Get conversations where the last incoming message hasn't been responded to
        // or where response time exceeded SLA
        $sql = "
            SELECT DISTINCT
                u.id as user_id,
                u.display_name,
                u.picture_url,
                m.id as message_id,
                m.content as last_message,
                m.created_at as message_time,
                TIMESTAMPDIFF(SECOND, m.created_at, NOW()) as waiting_seconds
            FROM users u
            JOIN messages m ON u.id = m.user_id
            WHERE m.line_account_id = ?
            AND m.direction = 'incoming'
            AND m.id = (
                SELECT MAX(m2.id) 
                FROM messages m2 
                WHERE m2.user_id = u.id 
                AND m2.direction = 'incoming'
            )
            AND NOT EXISTS (
                SELECT 1 FROM messages m3 
                WHERE m3.user_id = u.id 
                AND m3.direction = 'outgoing'
                AND m3.created_at > m.created_at
            )
            AND TIMESTAMPDIFF(SECOND, m.created_at, NOW()) > ?
            ORDER BY waiting_seconds DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $slaSeconds]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    
    /**
     * Record response time for a message
     * Requirements: 6.4 - Record response time when admin responds
     * 
     * @param int $messageId The outgoing message ID (admin's response)
     * @param int $userId User ID
     * @param int|null $adminId Admin user ID who responded
     * @return bool Success
     */
    public function recordResponseTime(int $messageId, int $userId, ?int $adminId = null): bool {
        // Find the last incoming message from the customer before this response
        $sql = "
            SELECT m.id, m.created_at
            FROM messages m
            WHERE m.user_id = ?
            AND m.direction = 'incoming'
            AND m.created_at < (
                SELECT created_at FROM messages WHERE id = ?
            )
            ORDER BY m.created_at DESC
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $messageId]);
        $lastIncoming = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lastIncoming) {
            // No incoming message to respond to
            return false;
        }
        
        // Get the response message timestamp
        $responseSql = "SELECT created_at FROM messages WHERE id = ?";
        $responseStmt = $this->db->prepare($responseSql);
        $responseStmt->execute([$messageId]);
        $responseMsg = $responseStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$responseMsg) {
            return false;
        }
        
        // Calculate response time in seconds
        $incomingTime = new DateTime($lastIncoming['created_at']);
        $responseTime = new DateTime($responseMsg['created_at']);
        $responseTimeSeconds = $responseTime->getTimestamp() - $incomingTime->getTimestamp();
        
        // Ensure response time is positive
        if ($responseTimeSeconds < 0) {
            $responseTimeSeconds = 0;
        }
        
        // Insert analytics record
        $insertSql = "
            INSERT INTO message_analytics 
            (message_id, user_id, admin_id, response_time_seconds, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ";
        
        $insertStmt = $this->db->prepare($insertSql);
        return $insertStmt->execute([$messageId, $userId, $adminId, $responseTimeSeconds]);
    }
    
    /**
     * Get time since last customer message
     * Requirements: 6.5 - Show time since last customer message
     * 
     * @param int $userId User ID
     * @return int Seconds since last incoming message, or -1 if no messages
     */
    public function getTimeSinceLastMessage(int $userId): int {
        $sql = "
            SELECT created_at
            FROM messages
            WHERE user_id = ?
            AND direction = 'incoming'
            ORDER BY created_at DESC
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return -1; // No incoming messages found
        }
        
        $lastMessageTime = new DateTime($result['created_at']);
        $now = new DateTime();
        
        return $now->getTimestamp() - $lastMessageTime->getTimestamp();
    }
    
    /**
     * Get date condition SQL based on period
     * 
     * @param string $period 'day', 'week', 'month'
     * @return string SQL condition
     */
    private function getDateCondition(string $period): string {
        switch ($period) {
            case 'day':
                return "AND ma.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            case 'week':
                return "AND ma.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            case 'month':
                return "AND ma.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            default:
                return "AND ma.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        }
    }
    
    /**
     * Get response time statistics
     * 
     * @param string $period 'day', 'week', 'month'
     * @return array Statistics including avg, min, max, count
     */
    public function getResponseTimeStats(string $period = 'day'): array {
        $dateCondition = $this->getDateCondition($period);
        
        $sql = "
            SELECT 
                AVG(response_time_seconds) as avg_time,
                MIN(response_time_seconds) as min_time,
                MAX(response_time_seconds) as max_time,
                COUNT(*) as total_responses
            FROM message_analytics ma
            JOIN messages m ON ma.message_id = m.id
            WHERE m.line_account_id = ?
            AND ma.response_time_seconds IS NOT NULL
            AND ma.response_time_seconds > 0
            {$dateCondition}
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'average' => (float)($result['avg_time'] ?? 0),
            'minimum' => (int)($result['min_time'] ?? 0),
            'maximum' => (int)($result['max_time'] ?? 0),
            'total_responses' => (int)($result['total_responses'] ?? 0),
            'period' => $period
        ];
    }
    
    /**
     * Get response time trends (daily averages)
     * 
     * @param int $days Number of days to look back
     * @return array Daily averages
     */
    public function getResponseTimeTrends(int $days = 7): array {
        $sql = "
            SELECT 
                DATE(ma.created_at) as date,
                AVG(response_time_seconds) as avg_time,
                COUNT(*) as response_count
            FROM message_analytics ma
            JOIN messages m ON ma.message_id = m.id
            WHERE m.line_account_id = ?
            AND ma.response_time_seconds IS NOT NULL
            AND ma.response_time_seconds > 0
            AND ma.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(ma.created_at)
            ORDER BY date ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get admin performance statistics
     * 
     * @param string $period 'day', 'week', 'month'
     * @return array Admin performance data
     */
    public function getAdminPerformance(string $period = 'day'): array {
        $dateCondition = $this->getDateCondition($period);
        
        $sql = "
            SELECT 
                ma.admin_id,
                au.username as admin_name,
                AVG(ma.response_time_seconds) as avg_response_time,
                COUNT(*) as total_responses,
                MIN(ma.response_time_seconds) as fastest_response,
                MAX(ma.response_time_seconds) as slowest_response
            FROM message_analytics ma
            JOIN messages m ON ma.message_id = m.id
            LEFT JOIN admin_users au ON ma.admin_id = au.id
            WHERE m.line_account_id = ?
            AND ma.response_time_seconds IS NOT NULL
            AND ma.response_time_seconds > 0
            AND ma.admin_id IS NOT NULL
            {$dateCondition}
            GROUP BY ma.admin_id, au.username
            ORDER BY avg_response_time ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get SLA compliance rate
     * 
     * @param int $slaSeconds SLA threshold in seconds
     * @param string $period 'day', 'week', 'month'
     * @return array Compliance statistics
     */
    public function getSLAComplianceRate(int $slaSeconds, string $period = 'day'): array {
        $dateCondition = $this->getDateCondition($period);
        
        $sql = "
            SELECT 
                COUNT(*) as total_responses,
                SUM(CASE WHEN response_time_seconds <= ? THEN 1 ELSE 0 END) as within_sla,
                SUM(CASE WHEN response_time_seconds > ? THEN 1 ELSE 0 END) as exceeded_sla
            FROM message_analytics ma
            JOIN messages m ON ma.message_id = m.id
            WHERE m.line_account_id = ?
            AND ma.response_time_seconds IS NOT NULL
            AND ma.response_time_seconds > 0
            {$dateCondition}
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$slaSeconds, $slaSeconds, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total = (int)($result['total_responses'] ?? 0);
        $withinSla = (int)($result['within_sla'] ?? 0);
        $exceededSla = (int)($result['exceeded_sla'] ?? 0);
        
        return [
            'total_responses' => $total,
            'within_sla' => $withinSla,
            'exceeded_sla' => $exceededSla,
            'compliance_rate' => $total > 0 ? round(($withinSla / $total) * 100, 2) : 0,
            'sla_threshold_seconds' => $slaSeconds,
            'period' => $period
        ];
    }
    
    /**
     * Get messages per day statistics
     * Requirements: 6.3 - Show messages per day
     * 
     * @param int $days Number of days to look back
     * @return array Daily message counts
     */
    public function getMessagesPerDay(int $days = 7): array {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_messages,
                SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing
            FROM messages
            WHERE line_account_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->lineAccountId, $days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
