# Design Document: Inbox v2 Performance Upgrade

## Overview

ปรับปรุง inbox-v2.php ให้มี performance สูงสุดด้วยการใช้ AJAX สำหรับ conversation switching, automatic conversation bumping, และเทคนิค optimization ต่างๆ เช่น virtual scrolling, intelligent caching, และ smart polling เพื่อประสบการณ์การใช้งานที่ลื่นไหลและรวดเร็ว

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Frontend (inbox-v2.php)                           │
├─────────────────────────────────────────────────────────────────────┤
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐   │
│  │ Conversation     │  │   Chat Panel     │  │   HUD Widgets    │   │
│  │ List Manager     │  │   Manager        │  │   Panel          │   │
│  │                  │  │                  │  │                  │   │
│  │ - Virtual Scroll │  │ - AJAX Loading   │  │ - Drug Info      │   │
│  │ - Auto Bump      │  │ - Message Cache  │  │ - Pricing        │   │
│  │ - Search/Filter  │  │ - Virtual Scroll │  │ - Ghost Draft    │   │
│  │ - LRU Cache      │  │ - Lazy Images    │  │                  │   │
│  └──────────────────┘  └──────────────────┘  └──────────────────┘   │
│                              │                                        │
│  ┌──────────────────────────┴────────────────────────────────────┐   │
│  │              State Management Layer                           │   │
│  │  - ConversationCache (LRU, max 10)                           │   │
│  │  - MessageCache (LRU, max 500 messages)                      │   │
│  │  - PendingMessageQueue (for offline support)                 │   │
│  │  - PollingManager (adaptive intervals)                       │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    API Layer (api/inbox-v2.php)                      │
├─────────────────────────────────────────────────────────────────────┤
│  GET  /conversations?since={timestamp}  - Delta updates only        │
│  GET  /messages/:userId?cursor={cursor} - Cursor-based pagination   │
│  POST /messages                         - Send with optimistic UI   │
│  GET  /poll?last_check={timestamp}      - Efficient polling         │
└─────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    Database Layer (Optimized)                        │
├─────────────────────────────────────────────────────────────────────┤
│  - Indexed queries for fast retrieval                               │
│  - Cursor-based pagination (no OFFSET)                              │
│  - Covering indexes for common queries                              │
│  - Query result caching (Redis optional)                            │
└─────────────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. ConversationListManager (Frontend)

```javascript
class ConversationListManager {
    constructor() {
        this.conversations = [];
        this.virtualScroller = null;
        this.searchDebouncer = null;
        this.sortOrder = 'recent'; // 'recent', 'unread', 'assigned'
    }
    
    /**
     * Initialize virtual scrolling for conversation list
     * @param {HTMLElement} container - Container element
     * @param {number} itemHeight - Height of each conversation item
     */
    initVirtualScroll(container, itemHeight = 80) {
        // Use Intersection Observer for efficient rendering
    }
    
    /**
     * Bump conversation to top when new message arrives
     * @param {string} userId - User ID of conversation
     * @param {Object} message - New message object
     */
    bumpConversation(userId, message) {
        // Move conversation to top with smooth animation
        // Update last_message_at timestamp
        // Re-sort if needed
    }
    
    /**
     * Search conversations with debouncing
     * @param {string} query - Search query
     * @param {number} delay - Debounce delay in ms (default 300)
     */
    searchConversations(query, delay = 300) {
        // Debounce to reduce API calls
        // Search across name, message content, tags
    }
    
    /**
     * Load more conversations (infinite scroll)
     * @param {string} cursor - Pagination cursor
     */
    async loadMore(cursor) {
        // Fetch next batch using cursor-based pagination
    }
}
```

### 2. ChatPanelManager (Frontend)

```javascript
class ChatPanelManager {
    constructor() {
        this.currentUserId = null;
        this.messageCache = new LRUCache(500);
        this.virtualScroller = null;
        this.loadingState = 'idle'; // 'idle', 'loading', 'error'
    }
    
    /**
     * Load conversation via AJAX without page reload
     * @param {string} userId - User ID
     * @param {boolean} useCache - Whether to use cache (default true)
     */
    async loadConversation(userId, useCache = true) {
        // Check cache first
        if (useCache && this.messageCache.has(userId)) {
            const cached = this.messageCache.get(userId);
            if (Date.now() - cached.timestamp < 30000) { // 30 seconds
                this.renderMessages(cached.messages);
                this.updateURL(userId);
                return;
            }
        }
        
        // Show loading state
        this.showLoadingState();
        
        // Fetch via AJAX
        const messages = await this.fetchMessages(userId);
        
        // Cache the result
        this.messageCache.set(userId, {
            messages: messages,
            timestamp: Date.now()
        });
        
        // Render messages
        this.renderMessages(messages);
        
        // Update URL without reload
        this.updateURL(userId);
    }
    
    /**
     * Fetch messages with cursor-based pagination
     * @param {string} userId - User ID
     * @param {string} cursor - Pagination cursor (optional)
     * @param {number} limit - Number of messages (default 50)
     */
    async fetchMessages(userId, cursor = null, limit = 50) {
        const url = `/api/inbox-v2.php?action=getMessages&user_id=${userId}&limit=${limit}`;
        if (cursor) url += `&cursor=${cursor}`;
        
        const response = await fetch(url);
        return await response.json();
    }
    
    /**
     * Load older messages when scrolling up
     * @param {string} cursor - Pagination cursor
     */
    async loadOlderMessages(cursor) {
        // Lazy load older messages in batches
    }
    
    /**
     * Send message with optimistic UI
     * @param {string} content - Message content
     */
    async sendMessage(content) {
        // Add message to UI immediately (optimistic)
        const tempId = `temp_${Date.now()}`;
        this.addMessageToUI({
            id: tempId,
            content: content,
            direction: 'outgoing',
            status: 'sending'
        });
        
        try {
            // Send to server
            const result = await this.postMessage(content);
            
            // Update UI with real message ID
            this.updateMessageStatus(tempId, result.message_id, 'sent');
        } catch (error) {
            // Show retry button
            this.updateMessageStatus(tempId, null, 'failed');
        }
    }
    
    /**
     * Update browser URL using History API
     * @param {string} userId - User ID
     */
    updateURL(userId) {
        const url = new URL(window.location);
        url.searchParams.set('user_id', userId);
        window.history.pushState({userId}, '', url);
    }
}
```

### 3. RealtimeManager (Frontend) - Server-Sent Events + Polling Fallback

```javascript
class RealtimeManager {
    constructor() {
        this.eventSource = null;
        this.useSSE = true;
        this.pollingInterval = 3000;
        this.pollingTimer = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 3;
        this.isActive = true;
        this.lastCheck = Date.now();
    }
    
    /**
     * Start real-time updates (try SSE first, fallback to polling)
     */
    start() {
        if (this.useSSE && typeof EventSource !== 'undefined') {
            this.startSSE();
        } else {
            this.startPolling();
        }
        
        // Adjust behavior based on tab visibility
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.onTabInactive();
            } else {
                this.onTabActive();
            }
        });
    }
    
    /**
     * Start Server-Sent Events connection
     */
    startSSE() {
        try {
            // Connect to SSE endpoint
            const url = `/api/inbox-v2.php?action=stream&line_account_id=${this.getLineAccountId()}`;
            this.eventSource = new EventSource(url);
            
            // Connection opened
            this.eventSource.onopen = () => {
                console.log('SSE connected');
                this.reconnectAttempts = 0;
            };
            
            // Listen for new messages
            this.eventSource.addEventListener('new_message', (event) => {
                const message = JSON.parse(event.data);
                this.handleNewMessage(message);
            });
            
            // Listen for conversation updates
            this.eventSource.addEventListener('conversation_update', (event) => {
                const data = JSON.parse(event.data);
                this.handleConversationUpdate(data);
            });
            
            // Listen for heartbeat (keep connection alive)
            this.eventSource.addEventListener('heartbeat', (event) => {
                console.log('SSE heartbeat received');
            });
            
            // Handle errors
            this.eventSource.onerror = (error) => {
                console.error('SSE error:', error);
                this.reconnectAttempts++;
                
                // Close and try to reconnect
                this.eventSource.close();
                
                // Fallback to polling after max attempts
                if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                    console.log('Falling back to polling');
                    this.useSSE = false;
                    this.startPolling();
                } else {
                    // Try to reconnect with delay
                    const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), 10000);
                    setTimeout(() => this.startSSE(), delay);
                }
            };
            
        } catch (error) {
            console.error('SSE initialization failed:', error);
            this.useSSE = false;
            this.startPolling();
        }
    }
    
    /**
     * Start polling as fallback
     */
    startPolling() {
        this.poll();
    }
    
    /**
     * Poll for new messages with exponential backoff on failure
     */
    async poll() {
        if (!this.isActive) return;
        
        try {
            const updates = await this.fetchUpdates();
            
            if (updates.new_messages && updates.new_messages.length > 0) {
                updates.new_messages.forEach(msg => this.handleNewMessage(msg));
            }
            
            // Reset to normal interval on success
            this.pollingInterval = 3000;
            
        } catch (error) {
            console.error('Polling error:', error);
            // Exponential backoff on failure
            this.pollingInterval = Math.min(this.pollingInterval * 2, 30000);
        }
        
        // Schedule next poll
        this.pollingTimer = setTimeout(() => this.poll(), this.pollingInterval);
    }
    
    /**
     * Fetch only delta updates since last check
     */
    async fetchUpdates() {
        const url = `/api/inbox-v2.php?action=poll&since=${this.lastCheck}`;
        const response = await fetch(url);
        const data = await response.json();
        
        this.lastCheck = Date.now();
        return data;
    }
    
    /**
     * Handle new message (from SSE or polling)
     * @param {Object} message - New message object
     */
    handleNewMessage(message) {
        // Bump conversation to top
        conversationListManager.bumpConversation(message.user_id, message);
        
        // If viewing this conversation, append message
        if (chatPanelManager.currentUserId === message.user_id) {
            chatPanelManager.appendMessage(message);
        }
        
        // Show notification
        this.showNotification(message);
        
        // Play sound
        this.playNotificationSound();
    }
    
    /**
     * Handle conversation update
     * @param {Object} data - Update data
     */
    handleConversationUpdate(data) {
        conversationListManager.updateConversation(data);
    }
    
    /**
     * Handle tab becoming inactive
     */
    onTabInactive() {
        if (this.eventSource) {
            // Keep SSE connected (server will send less frequent updates)
            console.log('Tab inactive, maintaining SSE');
        } else {
            // Slow down polling
            this.pollingInterval = 10000;
        }
    }
    
    /**
     * Handle tab becoming active
     */
    onTabActive() {
        if (this.eventSource) {
            // SSE will automatically catch up
            console.log('Tab active, SSE will sync');
        } else {
            // Speed up polling
            this.pollingInterval = 3000;
            this.poll(); // Poll immediately
        }
    }
    
    /**
     * Get LINE account ID
     */
    getLineAccountId() {
        return window.LINE_ACCOUNT_ID || '';
    }
    
    /**
     * Show desktop notification
     */
    showNotification(message) {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('New message', {
                body: message.content,
                icon: message.user_picture_url
            });
        }
    }
    
    /**
     * Play notification sound
     */
    playNotificationSound() {
        const audio = new Audio('/assets/sounds/notification.mp3');
        audio.play().catch(e => console.log('Could not play sound:', e));
    }
    
    /**
     * Clean up connections
     */
    destroy() {
        this.isActive = false;
        
        if (this.eventSource) {
            this.eventSource.close();
        }
        
        if (this.pollingTimer) {
            clearTimeout(this.pollingTimer);
        }
    }
}
```
```

### 4. LRUCache (Frontend)

```javascript
class LRUCache {
    constructor(maxSize) {
        this.maxSize = maxSize;
        this.cache = new Map();
    }
    
    /**
     * Get item from cache
     * @param {string} key - Cache key
     * @returns {any} Cached value or null
     */
    get(key) {
        if (!this.cache.has(key)) return null;
        
        // Move to end (most recently used)
        const value = this.cache.get(key);
        this.cache.delete(key);
        this.cache.set(key, value);
        
        return value;
    }
    
    /**
     * Set item in cache
     * @param {string} key - Cache key
     * @param {any} value - Value to cache
     */
    set(key, value) {
        // Remove if exists
        if (this.cache.has(key)) {
            this.cache.delete(key);
        }
        
        // Add to end
        this.cache.set(key, value);
        
        // Evict oldest if over limit
        if (this.cache.size > this.maxSize) {
            const firstKey = this.cache.keys().next().value;
            this.cache.delete(firstKey);
        }
    }
    
    /**
     * Check if key exists in cache
     * @param {string} key - Cache key
     * @returns {boolean}
     */
    has(key) {
        return this.cache.has(key);
    }
    
    /**
     * Clear all cache
     */
    clear() {
        this.cache.clear();
    }
}
```

### 5. Enhanced InboxService (Backend)

```php
class InboxService {
    /**
     * Get conversations with delta updates
     * @param int $accountId LINE account ID
     * @param int $since Unix timestamp for delta updates
     * @param string $cursor Pagination cursor
     * @param int $limit Items per page
     * @return array Conversations with cursor for next page
     */
    public function getConversationsDelta(
        int $accountId, 
        int $since = 0, 
        string $cursor = null, 
        int $limit = 50
    ): array {
        // Use cursor-based pagination instead of OFFSET
        // Only return conversations updated since $since timestamp
        // Include only necessary fields (no full message content)
        
        $query = "
            SELECT 
                u.id, u.display_name, u.picture_url,
                u.last_message_at, u.unread_count,
                m.content as last_message_preview,
                ca.assigned_to
            FROM users u
            LEFT JOIN messages m ON m.id = (
                SELECT id FROM messages 
                WHERE user_id = u.id 
                ORDER BY created_at DESC LIMIT 1
            )
            LEFT JOIN conversation_assignments ca ON ca.user_id = u.id
            WHERE u.line_account_id = ?
            AND u.last_message_at > FROM_UNIXTIME(?)
        ";
        
        if ($cursor) {
            $query .= " AND u.last_message_at < ?";
        }
        
        $query .= " ORDER BY u.last_message_at DESC LIMIT ?";
        
        // Execute query and return with next cursor
    }
    
    /**
     * Get messages with cursor-based pagination
     * @param int $userId User ID
     * @param string $cursor Pagination cursor (message ID)
     * @param int $limit Messages per page
     * @return array Messages with cursor for next page
     */
    public function getMessagesCursor(
        int $userId, 
        string $cursor = null, 
        int $limit = 50
    ): array {
        // Use cursor-based pagination (message ID) instead of OFFSET
        // This is much faster for large datasets
        
        $query = "
            SELECT id, user_id, content, direction, type, 
                   created_at, is_read
            FROM messages
            WHERE user_id = ?
        ";
        
        if ($cursor) {
            $query .= " AND id < ?";
        }
        
        $query .= " ORDER BY id DESC LIMIT ?";
        
        // Execute and return with next cursor
    }
    
    /**
     * Poll for new messages since timestamp
     * @param int $accountId LINE account ID
     * @param int $since Unix timestamp
     * @return array New messages and updated conversations
     */
    public function pollUpdates(int $accountId, int $since): array {
        // Efficient query to get only new messages
        $query = "
            SELECT m.*, u.display_name, u.picture_url
            FROM messages m
            JOIN users u ON u.id = m.user_id
            WHERE u.line_account_id = ?
            AND m.created_at > FROM_UNIXTIME(?)
            AND m.direction = 'incoming'
            ORDER BY m.created_at ASC
        ";
        
        // Return new messages for bumping conversations
    }
}
```

### 6. WebSocketServer (Backend - Node.js)

```javascript
// websocket-server.js
const express = require('express');
const http = require('http');
const socketIO = require('socket.io');
const mysql = require('mysql2/promise');
const redis = require('redis');

const app = express();
const server = http.createServer(app);
const io = socketIO(server, {
    cors: {
        origin: process.env.ALLOWED_ORIGINS?.split(',') || '*',
        credentials: true
    },
    path: '/socket.io/'
});

// Redis client for pub/sub
const redisClient = redis.createClient({
    host: process.env.REDIS_HOST || 'localhost',
    port: process.env.REDIS_PORT || 6379
});

const redisSubscriber = redisClient.duplicate();

// MySQL connection pool
const pool = mysql.createPool({
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
    waitForConnections: true,
    connectionLimit: 10
});

// Store active connections by LINE account ID
const connections = new Map();

// Socket.IO connection handler
io.on('connection', async (socket) => {
    console.log('Client connected:', socket.id);
    
    // Authenticate socket
    const token = socket.handshake.auth.token;
    const user = await authenticateToken(token);
    
    if (!user) {
        socket.disconnect();
        return;
    }
    
    socket.userId = user.id;
    socket.lineAccountId = user.line_account_id;
    
    // Join room for this LINE account
    socket.on('join', async (data) => {
        const room = `account_${data.line_account_id}`;
        socket.join(room);
        
        // Track connection
        if (!connections.has(data.line_account_id)) {
            connections.set(data.line_account_id, new Set());
        }
        connections.get(data.line_account_id).add(socket.id);
        
        console.log(`Socket ${socket.id} joined room ${room}`);
    });
    
    // Handle typing indicator
    socket.on('typing', (data) => {
        const room = `account_${socket.lineAccountId}`;
        socket.to(room).emit('typing', {
            user_id: data.user_id,
            is_typing: data.is_typing,
            admin_id: socket.userId
        });
    });
    
    // Handle sync request (when tab becomes active)
    socket.on('sync', async (data) => {
        try {
            const updates = await getUpdatesSince(socket.lineAccountId, data.last_check);
            socket.emit('sync_response', updates);
        } catch (error) {
            console.error('Sync error:', error);
            socket.emit('error', { message: 'Sync failed' });
        }
    });
    
    // Handle disconnection
    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);
        
        // Remove from connections
        if (connections.has(socket.lineAccountId)) {
            connections.get(socket.lineAccountId).delete(socket.id);
            
            if (connections.get(socket.lineAccountId).size === 0) {
                connections.delete(socket.lineAccountId);
            }
        }
    });
});

// Subscribe to Redis channel for new messages from PHP
redisSubscriber.subscribe('inbox_updates');

redisSubscriber.on('message', (channel, message) => {
    if (channel === 'inbox_updates') {
        const data = JSON.parse(message);
        
        // Broadcast to all admins in this LINE account
        const room = `account_${data.line_account_id}`;
        io.to(room).emit('new_message', data.message);
        
        // Also emit conversation update
        io.to(room).emit('conversation_update', {
            user_id: data.message.user_id,
            last_message_at: data.message.created_at,
            unread_count: data.unread_count
        });
    }
});

/**
 * Authenticate socket token
 */
async function authenticateToken(token) {
    try {
        // Verify token with PHP session or JWT
        const [rows] = await pool.query(
            'SELECT id, line_account_id FROM admin_users WHERE session_token = ?',
            [token]
        );
        
        return rows[0] || null;
    } catch (error) {
        console.error('Auth error:', error);
        return null;
    }
}

/**
 * Get updates since timestamp
 */
async function getUpdatesSince(lineAccountId, since) {
    try {
        const [messages] = await pool.query(`
            SELECT m.*, u.display_name, u.picture_url
            FROM messages m
            JOIN users u ON u.id = m.user_id
            WHERE u.line_account_id = ?
            AND m.created_at > FROM_UNIXTIME(?)
            AND m.direction = 'incoming'
            ORDER BY m.created_at ASC
        `, [lineAccountId, since / 1000]);
        
        return { new_messages: messages };
    } catch (error) {
        console.error('Get updates error:', error);
        throw error;
    }
}

// Start server
const PORT = process.env.WEBSOCKET_PORT || 3000;
server.listen(PORT, () => {
    console.log(`WebSocket server running on port ${PORT}`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('SIGTERM received, closing server...');
    server.close(() => {
        console.log('Server closed');
        pool.end();
        redisClient.quit();
        redisSubscriber.quit();
        process.exit(0);
    });
});
```

### 7. PHP Integration with WebSocket

```php
// In webhook.php or wherever messages are received
class WebSocketNotifier {
    private $redis;
    
    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('localhost', 6379);
    }
    
    /**
     * Notify WebSocket server of new message
     * @param array $message Message data
     * @param int $lineAccountId LINE account ID
     */
    public function notifyNewMessage(array $message, int $lineAccountId): void {
        // Get unread count
        $unreadCount = $this->getUnreadCount($message['user_id']);
        
        // Publish to Redis channel
        $this->redis->publish('inbox_updates', json_encode([
            'line_account_id' => $lineAccountId,
            'message' => $message,
            'unread_count' => $unreadCount
        ]));
    }
    
    private function getUnreadCount(int $userId): int {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM messages 
            WHERE user_id = ? 
            AND direction = 'incoming' 
            AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch()['count'];
    }
}

// Usage in webhook
$notifier = new WebSocketNotifier();
$notifier->notifyNewMessage($messageData, $lineAccountId);
```

## Data Models

### Enhanced Indexes for Performance

```sql
-- Add covering index for conversation list query
ALTER TABLE users 
    ADD INDEX idx_account_last_msg_cover (
        line_account_id, 
        last_message_at DESC, 
        id, 
        display_name, 
        picture_url, 
        unread_count
    );

-- Add index for cursor-based message pagination
ALTER TABLE messages
    ADD INDEX idx_user_id_cursor (user_id, id DESC);

-- Add index for polling query
ALTER TABLE messages
    ADD INDEX idx_account_created_incoming (
        line_account_id, 
        created_at, 
        direction
    ) WHERE direction = 'incoming';

-- Add index for unread count
ALTER TABLE messages
    ADD INDEX idx_user_unread (user_id, is_read, direction);
```

### New Table for Performance Monitoring

```sql
CREATE TABLE performance_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric_type ENUM('page_load', 'conversation_switch', 'message_render', 'api_call') NOT NULL,
    duration_ms INT NOT NULL,
    user_agent VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_created (metric_type, created_at)
);
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property Reflection

After analyzing all acceptance criteria, I identified several redundant properties:
- Property 8.2 (conversation cache eviction) is identical to 3.5 - will consolidate
- Properties 5.2 and 6.4 (60fps scrolling) test the same concept - will combine
- Properties 12.1-12.4 (performance logging) can be combined into one comprehensive property

### Core Properties

**Property 1: Conversation Bumping to Top**
*For any* conversation list and any new message, when the message arrives for a conversation, that conversation should move to index 0 (top position) in the list.
**Validates: Requirements 2.1**

**Property 2: Conversation Sorting by Timestamp**
*For any* set of conversations with different last_message_at timestamps, the conversations should be sorted in descending order by timestamp (most recent first).
**Validates: Requirements 2.2**

**Property 3: Pinned Conversation Stability**
*For any* pinned conversation and any set of new messages to other conversations, the pinned conversation should remain at the top position.
**Validates: Requirements 2.5**

**Property 4: Initial Message Fetch Limit**
*For any* conversation, the initial message fetch should return at most 50 messages.
**Validates: Requirements 3.1**

**Property 5: Lazy Load Batch Size**
*For any* lazy load request for older messages, the response should contain at most 50 messages.
**Validates: Requirements 3.2**

**Property 6: Cache Retrieval Performance**
*For any* cached conversation or message set, retrieval time should be less than 50 milliseconds.
**Validates: Requirements 3.3**

**Property 7: Cache TTL Validation**
*For any* conversation cached less than 30 seconds ago, requesting it again should serve from cache without making an API call.
**Validates: Requirements 3.4**

**Property 8: LRU Cache Eviction**
*For any* cache (conversation or message) exceeding its size limit, the least recently used item should be evicted first.
**Validates: Requirements 3.5, 8.1, 8.2**

**Property 9: Active Polling Interval**
*For any* sequence of polls when the inbox is active (tab visible), the time between consecutive polls should be approximately 3 seconds (±500ms).
**Validates: Requirements 4.1**

**Property 10: Inactive Polling Interval**
*For any* sequence of polls when the inbox is inactive (tab hidden), the time between consecutive polls should be approximately 10 seconds (±1s).
**Validates: Requirements 4.2**

**Property 11: Delta Update Efficiency**
*For any* poll request, the query should include a timestamp filter to fetch only messages created after the last check timestamp.
**Validates: Requirements 4.3**

**Property 12: Exponential Backoff on Failure**
*For any* sequence of N consecutive polling failures, the interval before the Nth retry should be min(3 * 2^(N-1), 30) seconds.
**Validates: Requirements 4.5**

**Property 13: Virtual Scrolling Buffer Size**
*For any* virtualized list, the number of rendered DOM items should equal visible items plus at most 10 buffer items.
**Validates: Requirements 5.3**

**Property 14: Debounce Timing**
*For any* sequence of search inputs within 300ms, only the last input should trigger an API call.
**Validates: Requirements 5.4**

**Property 15: Incremental DOM Updates**
*For any* list update operation, DOM nodes for unchanged items should not be modified or re-rendered.
**Validates: Requirements 5.5, 6.5**

**Property 16: Lazy Image Loading**
*For any* image element outside the viewport, the image source should not be loaded until the element enters the viewport.
**Validates: Requirements 6.2**

**Property 17: Flex Message Caching**
*For any* Flex Message rendered multiple times, the second and subsequent renders should use cached HTML without re-computation.
**Validates: Requirements 6.3**

**Property 18: Scrolling Performance**
*For any* scrolling operation in conversation list or message list, the frame rate should maintain at least 60fps (frame time ≤ 16.67ms).
**Validates: Requirements 5.2, 6.4**

**Property 19: Minimal Field Selection**
*For any* conversation list query, the SELECT statement should include only necessary fields and exclude full message content.
**Validates: Requirements 7.1**

**Property 20: Cursor-Based Pagination**
*For any* message pagination query, the WHERE clause should use cursor (message ID comparison) instead of OFFSET.
**Validates: Requirements 7.2**

**Property 21: Request Batching**
*For any* set of N independent API operations that can be batched, they should result in at most 1 HTTP request.
**Validates: Requirements 7.3**

**Property 22: HTTP Cache Headers**
*For any* API response for cacheable data, the response should include ETag or Last-Modified headers.
**Validates: Requirements 7.4**

**Property 23: Optimistic UI Updates**
*For any* outgoing message, it should appear in the UI immediately before the server responds, with a "sending" status indicator.
**Validates: Requirements 7.5**

**Property 24: DOM Node Cleanup**
*For any* virtualized list with more than 1000 total items, the number of DOM nodes should not exceed visible items + buffer items.
**Validates: Requirements 8.3**

**Property 25: Event Listener Cleanup**
*For any* component that is unmounted or destroyed, all event listeners attached by that component should be removed.
**Validates: Requirements 8.4**

**Property 26: Keyboard Navigation**
*For any* conversation list with N conversations, pressing Down arrow N-1 times from the first conversation should focus the last conversation.
**Validates: Requirements 10.1**

**Property 27: Offline Cache Access**
*For any* cached conversation or message, it should be accessible and displayable when the network is offline.
**Validates: Requirements 11.2**

**Property 28: Message Queue Persistence**
*For any* message sent while offline, it should be added to a queue and automatically sent when the network reconnects.
**Validates: Requirements 11.3, 11.4**

**Property 29: Performance Metric Logging**
*For any* performance-critical operation (page load, conversation switch, message render, API call), the duration should be measured and logged.
**Validates: Requirements 12.1, 12.2, 12.3, 12.4**

**Property 30: Performance Degradation Warnings**
*For any* operation exceeding its performance threshold (e.g., conversation load > 1s), a warning should be logged with operation details.
**Validates: Requirements 12.5**



## Error Handling

### Frontend Error Handling

1. **Network Errors**
   - Show offline indicator when network is unavailable
   - Queue messages for retry when back online
   - Implement exponential backoff for failed requests
   - Show "slow connection" warning when requests take > 5 seconds

2. **API Errors**
   - 404: Show "Conversation not found" message
   - 403: Show "Access denied" message
   - 429: Implement rate limiting backoff
   - 500: Show "Server error, please try again" with retry button
   - Timeout: Show "Request timed out" with retry option

3. **Cache Errors**
   - If cache is corrupted, clear and rebuild
   - If cache quota exceeded, evict oldest items
   - Log cache errors for debugging

4. **Rendering Errors**
   - Catch and log React/DOM rendering errors
   - Show fallback UI for failed components
   - Prevent entire page crash from component errors

### Backend Error Handling

1. **Database Errors**
   - Connection errors: Return 503 with retry-after header
   - Query timeout: Log slow query and return 504
   - Deadlock: Retry transaction up to 3 times
   - Constraint violation: Return 400 with specific error message

2. **LINE API Errors**
   - Rate limit: Queue message and retry with backoff
   - Invalid token: Log error and notify admin
   - Message too large: Return 413 with size limit info
   - Timeout: Retry up to 3 times with exponential backoff

3. **Cache Errors**
   - Redis connection error: Fall back to database
   - Cache miss: Fetch from database and populate cache
   - Serialization error: Log and skip caching

## Testing Strategy

### Unit Tests

**Frontend Unit Tests (Jest + Testing Library)**
- Test LRUCache.get() and set() with various sizes
- Test PollingManager interval calculations
- Test ConversationListManager.bumpConversation() logic
- Test ChatPanelManager.loadConversation() with mocked fetch
- Test debounce function timing
- Test exponential backoff calculations

**Backend Unit Tests (PHPUnit)**
- Test InboxService.getConversationsDelta() with various timestamps
- Test InboxService.getMessagesCursor() pagination
- Test InboxService.pollUpdates() delta logic
- Test cursor generation and parsing
- Test query optimization (verify no OFFSET used)

### Property-Based Tests

**Property Testing Library**: PHPUnit with custom generators for frontend, PHPUnit for backend

**Test Configuration**: Each property test should run minimum 100 iterations

**Frontend Properties** (using fast-check or similar):
- Property 1: Conversation bumping (generate random conversation lists and messages)
- Property 2: Conversation sorting (generate random timestamps)
- Property 8: LRU eviction (generate random cache operations)
- Property 12: Exponential backoff (generate failure sequences)
- Property 14: Debounce timing (generate input sequences)
- Property 26: Keyboard navigation (generate random list sizes)

**Backend Properties** (using PHPUnit):
- Property 4: Message fetch limit (generate conversations with varying message counts)
- Property 7: Cache TTL (generate timestamps within and outside TTL)
- Property 11: Delta updates (generate message sets with timestamps)
- Property 19: Field selection (verify query structure)
- Property 20: Cursor pagination (generate large message sets)

### Integration Tests

**End-to-End Scenarios**:
1. Load inbox → select conversation → send message → verify delivery
2. Receive new message → verify conversation bumps to top → verify notification
3. Switch conversations rapidly → verify caching works → verify no memory leaks
4. Go offline → send message → come online → verify message sends
5. Load conversation with 1000+ messages → verify virtual scrolling → verify performance

**Performance Tests**:
- Load test: 1000 conversations, measure list render time (should be < 1s)
- Scroll test: Scroll through 1000 conversations, measure frame rate (should be 60fps)
- Switch test: Switch between 10 conversations rapidly, measure average time (should be < 200ms)
- Memory test: Keep inbox open for 1 hour, measure memory growth (should be < 100MB)
- Polling test: Monitor polling for 10 minutes, verify intervals are correct

### Browser Compatibility Testing

Test on:
- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Mobile Safari (iOS)
- Mobile Chrome (Android)

### Accessibility Testing

- Keyboard navigation works without mouse
- Screen reader announces conversation changes
- Focus indicators are visible
- ARIA labels are present and correct

## Performance Benchmarks

### Target Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Initial page load (TTI) | < 2s | Performance API |
| Conversation switch (cached) | < 50ms | Performance API |
| Conversation switch (uncached) | < 500ms | Performance API |
| Message render (50 messages) | < 200ms | Performance API |
| Scroll frame rate | 60fps | requestAnimationFrame |
| Memory usage (4 hours) | < 200MB | Chrome DevTools |
| API response time (p95) | < 300ms | Server logs |
| Polling overhead | < 5% CPU | Chrome DevTools |

### Monitoring

- Log all performance metrics to `performance_metrics` table
- Set up alerts for metrics exceeding thresholds
- Create dashboard showing:
  - Average conversation switch time
  - API response time percentiles (p50, p95, p99)
  - Error rates by type
  - Cache hit rates
  - Memory usage over time

## Implementation Notes

### Phase 1: Core AJAX Infrastructure
- Implement ChatPanelManager with AJAX loading
- Implement History API for URL updates
- Add loading states and error handling

### Phase 2: Caching Layer
- Implement LRUCache class
- Add conversation and message caching
- Implement cache invalidation logic

### Phase 3: Conversation Bumping
- Implement ConversationListManager.bumpConversation()
- Add smooth animations for bumping
- Handle edge cases (pinned conversations, current conversation)

### Phase 4: Performance Optimization
- Implement virtual scrolling for both lists
- Add lazy loading for images
- Optimize DOM updates (incremental rendering)

### Phase 5: Smart Polling
- Implement PollingManager with adaptive intervals
- Add exponential backoff on failures
- Implement delta updates

### Phase 6: Offline Support
- Implement message queue for offline messages
- Add offline indicator
- Handle reconnection logic

### Phase 7: Monitoring & Testing
- Add performance metric logging
- Implement property-based tests
- Run performance benchmarks

## Migration Plan

### Backward Compatibility

- Keep existing inbox.php working during migration
- Add feature flag to enable new inbox-v2 performance features
- Allow gradual rollout to users

### Database Migration

```sql
-- Add indexes for performance (run during low-traffic period)
ALTER TABLE users 
    ADD INDEX idx_account_last_msg_cover (
        line_account_id, 
        last_message_at DESC, 
        id, 
        display_name, 
        picture_url, 
        unread_count
    );

ALTER TABLE messages
    ADD INDEX idx_user_id_cursor (user_id, id DESC);

-- Create performance metrics table
CREATE TABLE performance_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric_type ENUM('page_load', 'conversation_switch', 'message_render', 'api_call') NOT NULL,
    duration_ms INT NOT NULL,
    user_agent VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_created (metric_type, created_at)
);
```

### Rollout Strategy

1. **Week 1**: Deploy to internal team for testing
2. **Week 2**: Deploy to 10% of users (A/B test)
3. **Week 3**: Monitor metrics, fix issues
4. **Week 4**: Deploy to 50% of users
5. **Week 5**: Deploy to 100% of users
6. **Week 6**: Remove old code and feature flags

## Security Considerations

1. **XSS Prevention**: Sanitize all user-generated content before rendering
2. **CSRF Protection**: Include CSRF tokens in all POST requests
3. **Rate Limiting**: Implement rate limiting on API endpoints
4. **Authentication**: Verify session on every API call
5. **Authorization**: Check user permissions before returning data
6. **Input Validation**: Validate all inputs on both client and server
7. **SQL Injection**: Use prepared statements for all queries
8. **Cache Poisoning**: Validate cache keys and data integrity

## Future Enhancements

1. **WebSocket Support**: Replace polling with WebSocket for true real-time updates
2. **Service Worker**: Add service worker for better offline support and push notifications
3. **IndexedDB**: Use IndexedDB for larger client-side cache
4. **Compression**: Implement response compression (gzip/brotli)
5. **CDN**: Serve static assets from CDN
6. **Code Splitting**: Split JavaScript bundles for faster initial load
7. **Prefetching**: Prefetch likely next conversations
8. **AI Predictions**: Use ML to predict which conversations user will open next
