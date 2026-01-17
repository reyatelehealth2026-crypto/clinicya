<?php
/**
 * WebSocket Notifier
 * 
 * Publishes real-time updates to WebSocket server via Redis pub/sub
 * Used to notify connected clients of new messages and conversation updates
 * 
 * @package LINE Telepharmacy Platform
 * @version 1.0.0
 */

class WebSocketNotifier {
    private $redis;
    private $isConnected = false;
    private $channelName = 'inbox_updates';
    
    /**
     * Constructor - Initialize Redis connection
     * 
     * @param string $host Redis host (default: localhost)
     * @param int $port Redis port (default: 6379)
     * @param string $password Redis password (optional)
     */
    public function __construct(
        string $host = 'localhost', 
        int $port = 6379, 
        string $password = null
    ) {
        // Check if Redis extension is available
        if (!class_exists('Redis')) {
            error_log('WebSocketNotifier: Redis extension not available');
            return;
        }
        
        try {
            $this->redis = new Redis();
            $this->isConnected = $this->redis->connect($host, $port, 2.0); // 2 second timeout
            
            if ($this->isConnected && $password) {
                $this->redis->auth($password);
            }
            
            if (!$this->isConnected) {
                error_log('WebSocketNotifier: Failed to connect to Redis');
            }
        } catch (Exception $e) {
            error_log('WebSocketNotifier: Redis connection error - ' . $e->getMessage());
            $this->isConnected = false;
        }
    }
    
    /**
     * Notify WebSocket server of new message
     * 
     * @param array $message Message data (id, user_id, content, direction, type, created_at)
     * @param int $lineAccountId LINE account ID
     * @param array $userData Optional user data (display_name, picture_url)
     * @return bool Success status
     */
    public function notifyNewMessage(
        array $message, 
        int $lineAccountId,
        array $userData = []
    ): bool {
        if (!$this->isConnected) {
            return false;
        }
        
        try {
            // Get unread count for this user
            $unreadCount = $this->getUnreadCount($message['user_id']);
            
            // Prepare notification payload
            $payload = [
                'type' => 'new_message',
                'line_account_id' => $lineAccountId,
                'message' => [
                    'id' => $message['id'] ?? null,
                    'user_id' => $message['user_id'],
                    'content' => $message['content'] ?? '',
                    'direction' => $message['direction'] ?? 'incoming',
                    'type' => $message['type'] ?? 'text',
                    'created_at' => $message['created_at'] ?? date('Y-m-d H:i:s'),
                    'is_read' => $message['is_read'] ?? 0
                ],
                'unread_count' => $unreadCount,
                'timestamp' => time()
            ];
            
            // Add user data if provided
            if (!empty($userData)) {
                $payload['message']['user_display_name'] = $userData['display_name'] ?? '';
                $payload['message']['user_picture_url'] = $userData['picture_url'] ?? '';
            }
            
            // Publish to Redis channel
            $result = $this->redis->publish(
                $this->channelName, 
                json_encode($payload)
            );
            
            return $result !== false;
            
        } catch (Exception $e) {
            error_log('WebSocketNotifier: Failed to publish message - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notify WebSocket server of conversation update
     * 
     * @param int $userId User ID
     * @param int $lineAccountId LINE account ID
     * @param array $updateData Update data (last_message_at, unread_count, etc.)
     * @return bool Success status
     */
    public function notifyConversationUpdate(
        int $userId,
        int $lineAccountId,
        array $updateData = []
    ): bool {
        if (!$this->isConnected) {
            return false;
        }
        
        try {
            // Prepare notification payload
            $payload = [
                'type' => 'conversation_update',
                'line_account_id' => $lineAccountId,
                'user_id' => $userId,
                'update_data' => $updateData,
                'timestamp' => time()
            ];
            
            // Publish to Redis channel
            $result = $this->redis->publish(
                $this->channelName, 
                json_encode($payload)
            );
            
            return $result !== false;
            
        } catch (Exception $e) {
            error_log('WebSocketNotifier: Failed to publish conversation update - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notify WebSocket server of typing indicator
     * 
     * @param int $userId User ID
     * @param int $lineAccountId LINE account ID
     * @param int $adminId Admin user ID who is typing
     * @param bool $isTyping Whether admin is typing or stopped
     * @return bool Success status
     */
    public function notifyTypingIndicator(
        int $userId,
        int $lineAccountId,
        int $adminId,
        bool $isTyping
    ): bool {
        if (!$this->isConnected) {
            return false;
        }
        
        try {
            // Prepare notification payload
            $payload = [
                'type' => 'typing_indicator',
                'line_account_id' => $lineAccountId,
                'user_id' => $userId,
                'admin_id' => $adminId,
                'is_typing' => $isTyping,
                'timestamp' => time()
            ];
            
            // Publish to Redis channel
            $result = $this->redis->publish(
                $this->channelName, 
                json_encode($payload)
            );
            
            return $result !== false;
            
        } catch (Exception $e) {
            error_log('WebSocketNotifier: Failed to publish typing indicator - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unread message count for a user
     * 
     * @param int $userId User ID
     * @return int Unread count
     */
    private function getUnreadCount(int $userId): int {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE user_id = ? 
                AND direction = 'incoming' 
                AND is_read = 0
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)($result['count'] ?? 0);
            
        } catch (Exception $e) {
            error_log('WebSocketNotifier: Failed to get unread count - ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check if Redis is connected
     * 
     * @return bool Connection status
     */
    public function isConnected(): bool {
        return $this->isConnected;
    }
    
    /**
     * Close Redis connection
     */
    public function close(): void {
        if ($this->isConnected && $this->redis) {
            try {
                $this->redis->close();
                $this->isConnected = false;
            } catch (Exception $e) {
                error_log('WebSocketNotifier: Error closing Redis connection - ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Destructor - Clean up Redis connection
     */
    public function __destruct() {
        $this->close();
    }
}
