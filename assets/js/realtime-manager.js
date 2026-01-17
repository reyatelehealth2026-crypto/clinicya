/**
 * RealtimeManager - Real-time Updates for Inbox v2
 * 
 * Manages real-time communication using WebSocket (Socket.IO) with automatic
 * fallback to polling. Handles connection management, typing indicators,
 * tab visibility changes, and exponential backoff on failures.
 * 
 * Requirements: 4.1, 4.2, 4.4, 4.6, 4.7, 4.1.1, 4.1.2
 * 
 * @class RealtimeManager
 * @version 1.0.0
 */

class RealtimeManager {
    /**
     * Constructor
     * @param {Object} options Configuration options
     * @param {string} options.websocketUrl WebSocket server URL
     * @param {string} options.authToken Authentication token
     * @param {number} options.lineAccountId LINE account ID
     * @param {Function} options.onNewMessage Callback for new messages
     * @param {Function} options.onConversationUpdate Callback for conversation updates
     * @param {Function} options.onTyping Callback for typing indicators
     * @param {Function} options.onConnectionChange Callback for connection status changes
     */
    constructor(options = {}) {
        // Configuration
        this.websocketUrl = options.websocketUrl || 'http://localhost:3000';
        this.authToken = options.authToken || null;
        this.lineAccountId = options.lineAccountId || null;
        
        // Callbacks
        this.onNewMessage = options.onNewMessage || (() => {});
        this.onConversationUpdate = options.onConversationUpdate || (() => {});
        this.onTyping = options.onTyping || (() => {});
        this.onConnectionChange = options.onConnectionChange || (() => {});
        
        // WebSocket state
        this.socket = null;
        this.useWebSocket = true;
        this.isConnected = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectTimer = null;
        
        // Polling state
        this.pollingInterval = 3000; // 3 seconds when active
        this.pollingIntervalInactive = 10000; // 10 seconds when inactive
        this.pollingTimer = null;
        this.lastCheck = Date.now();
        this.isPolling = false;
        
        // Tab visibility state
        this.isTabActive = !document.hidden;
        
        // Typing indicator state
        this.typingTimer = null;
        this.typingTimeout = 3000; // 3 seconds
        this.currentConversationId = null;
        
        // Performance tracking
        this.connectionStartTime = null;
        this.messageLatencies = [];
        
        // Bind methods
        this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
        this.handleOnline = this.handleOnline.bind(this);
        this.handleOffline = this.handleOffline.bind(this);
        
        // Initialize
        this._setupEventListeners();
    }
    
    /**
     * Start real-time updates
     * Tries WebSocket first, falls back to polling if needed
     */
    start() {
        console.log('[RealtimeManager] Starting real-time updates...');
        
        if (this.useWebSocket && typeof io !== 'undefined') {
            this.startWebSocket();
        } else {
            console.log('[RealtimeManager] WebSocket not available, using polling');
            this.useWebSocket = false;
            this.startPolling();
        }
    }
    
    /**
     * Stop real-time updates
     * Cleans up connections and timers
     */
    stop() {
        console.log('[RealtimeManager] Stopping real-time updates...');
        
        if (this.socket) {
            this.socket.disconnect();
            this.socket = null;
        }
        
        if (this.pollingTimer) {
            clearTimeout(this.pollingTimer);
            this.pollingTimer = null;
        }
        
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        
        if (this.typingTimer) {
            clearTimeout(this.typingTimer);
            this.typingTimer = null;
        }
        
        this.isConnected = false;
        this.isPolling = false;
    }
    
    /**
     * Start WebSocket connection using Socket.IO
     * Requirements: 4.1, 4.2, 4.4
     */
    startWebSocket() {
        if (!this.authToken) {
            console.error('[RealtimeManager] No auth token provided');
            this.fallbackToPolling();
            return;
        }
        
        try {
            console.log('[RealtimeManager] Connecting to WebSocket:', this.websocketUrl);
            this.connectionStartTime = Date.now();
            
            // Create Socket.IO connection
            this.socket = io(this.websocketUrl, {
                auth: {
                    token: this.authToken
                },
                transports: ['websocket', 'polling'],
                reconnection: false, // We handle reconnection manually
                timeout: 10000,
                path: '/socket.io/'
            });
            
            // Connection opened
            this.socket.on('connect', () => {
                const latency = Date.now() - this.connectionStartTime;
                console.log(`[RealtimeManager] WebSocket connected (${latency}ms)`);
                
                this.isConnected = true;
                this.reconnectAttempts = 0;
                this.onConnectionChange('connected', 'websocket');
                
                // Join room for this LINE account
                this.socket.emit('join', {
                    line_account_id: this.lineAccountId
                });
            });
            
            // Connection confirmation
            this.socket.on('connected', (data) => {
                console.log('[RealtimeManager] Connection confirmed:', data);
            });
            
            // Listen for new messages
            this.socket.on('new_message', (message) => {
                const receiveTime = Date.now();
                const messageTime = new Date(message.created_at).getTime();
                const latency = receiveTime - messageTime;
                
                this.messageLatencies.push(latency);
                if (this.messageLatencies.length > 100) {
                    this.messageLatencies.shift();
                }
                
                console.log(`[RealtimeManager] New message received (latency: ${latency}ms):`, message);
                this.onNewMessage(message);
            });
            
            // Listen for conversation updates
            this.socket.on('conversation_update', (data) => {
                console.log('[RealtimeManager] Conversation update:', data);
                this.onConversationUpdate(data);
            });
            
            // Listen for typing indicators
            this.socket.on('typing', (data) => {
                console.log('[RealtimeManager] Typing indicator:', data);
                this.onTyping(data);
            });
            
            // Listen for server shutdown
            this.socket.on('server_shutdown', (data) => {
                console.warn('[RealtimeManager] Server shutting down:', data);
                this.fallbackToPolling();
            });
            
            // Handle connection errors
            this.socket.on('connect_error', (error) => {
                console.error('[RealtimeManager] WebSocket connection error:', error);
                this.handleConnectionError();
            });
            
            // Handle disconnection
            this.socket.on('disconnect', (reason) => {
                console.log('[RealtimeManager] WebSocket disconnected:', reason);
                this.isConnected = false;
                this.onConnectionChange('disconnected', 'websocket');
                
                // Attempt to reconnect or fallback
                if (reason === 'io server disconnect') {
                    // Server disconnected us, try to reconnect
                    this.attemptReconnect();
                } else if (reason === 'transport close' || reason === 'transport error') {
                    // Connection lost, try to reconnect
                    this.attemptReconnect();
                }
            });
            
            // Handle errors
            this.socket.on('error', (error) => {
                console.error('[RealtimeManager] WebSocket error:', error);
            });
            
            // Handle pong (heartbeat response)
            this.socket.on('pong', (data) => {
                // Connection is alive
            });
            
            // Handle sync response
            this.socket.on('sync_response', (updates) => {
                console.log('[RealtimeManager] Sync response:', updates);
                
                if (updates.new_messages && updates.new_messages.length > 0) {
                    updates.new_messages.forEach(msg => this.onNewMessage(msg));
                }
                
                this.lastCheck = updates.timestamp || Date.now();
            });
            
        } catch (error) {
            console.error('[RealtimeManager] Failed to initialize WebSocket:', error);
            this.fallbackToPolling();
        }
    }
    
    /**
     * Handle connection error with exponential backoff
     * Requirements: 4.4
     */
    handleConnectionError() {
        this.isConnected = false;
        this.reconnectAttempts++;
        
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.log('[RealtimeManager] Max reconnect attempts reached, falling back to polling');
            this.fallbackToPolling();
        } else {
            this.attemptReconnect();
        }
    }
    
    /**
     * Attempt to reconnect with exponential backoff
     * Delay formula: min(3 * 2^N, 30) seconds
     * Requirements: 4.4
     */
    attemptReconnect() {
        if (this.reconnectTimer) {
            return; // Already scheduled
        }
        
        // Calculate delay with exponential backoff: min(3 * 2^N, 30) seconds
        const baseDelay = 3000; // 3 seconds
        const delay = Math.min(
            baseDelay * Math.pow(2, this.reconnectAttempts),
            30000 // Max 30 seconds
        );
        
        console.log(`[RealtimeManager] Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts + 1}/${this.maxReconnectAttempts})`);
        
        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;
            
            if (this.socket) {
                this.socket.disconnect();
                this.socket = null;
            }
            
            this.startWebSocket();
        }, delay);
    }
    
    /**
     * Fallback to polling when WebSocket fails
     * Requirements: 4.2
     */
    fallbackToPolling() {
        console.log('[RealtimeManager] Falling back to polling');
        
        this.useWebSocket = false;
        
        if (this.socket) {
            this.socket.disconnect();
            this.socket = null;
        }
        
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        
        this.onConnectionChange('connected', 'polling');
        this.startPolling();
    }
    
    /**
     * Start polling for updates
     * Requirements: 4.6, 4.7
     */
    startPolling() {
        if (this.isPolling) {
            return; // Already polling
        }
        
        console.log('[RealtimeManager] Starting polling');
        this.isPolling = true;
        this.poll();
    }
    
    /**
     * Poll for new messages with adaptive intervals
     * Requirements: 4.6, 4.7
     */
    async poll() {
        if (!this.isPolling) {
            return;
        }
        
        try {
            const updates = await this.fetchUpdates();
            
            if (updates.success && updates.data) {
                // Process new messages
                if (updates.data.new_messages && updates.data.new_messages.length > 0) {
                    updates.data.new_messages.forEach(msg => this.onNewMessage(msg));
                }
                
                // Process conversation updates
                if (updates.data.conversation_updates && updates.data.conversation_updates.length > 0) {
                    updates.data.conversation_updates.forEach(update => this.onConversationUpdate(update));
                }
            }
            
            // Update last check timestamp
            this.lastCheck = Date.now();
            
        } catch (error) {
            console.error('[RealtimeManager] Polling error:', error);
        }
        
        // Schedule next poll with adaptive interval
        const interval = this.isTabActive ? this.pollingInterval : this.pollingIntervalInactive;
        
        this.pollingTimer = setTimeout(() => {
            this.poll();
        }, interval);
    }
    
    /**
     * Fetch updates from server (delta updates only)
     * Requirements: 4.3
     */
    async fetchUpdates() {
        const url = `/api/inbox-v2.php?action=poll&since=${Math.floor(this.lastCheck / 1000)}`;
        
        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return await response.json();
            
        } catch (error) {
            console.error('[RealtimeManager] Fetch updates error:', error);
            throw error;
        }
    }
    
    /**
     * Send typing indicator
     * Requirements: 4.1.1, 4.1.2
     * @param {string} userId User ID of the conversation
     * @param {boolean} isTyping Whether user is typing
     */
    sendTypingIndicator(userId, isTyping) {
        if (!userId) {
            return;
        }
        
        this.currentConversationId = userId;
        
        // Only send via WebSocket (typing indicators not supported in polling)
        if (this.socket && this.isConnected) {
            this.socket.emit('typing', {
                user_id: userId,
                is_typing: isTyping
            });
            
            // Auto-clear typing indicator after timeout
            if (isTyping) {
                if (this.typingTimer) {
                    clearTimeout(this.typingTimer);
                }
                
                this.typingTimer = setTimeout(() => {
                    this.sendTypingIndicator(userId, false);
                }, this.typingTimeout);
            }
        }
    }
    
    /**
     * Handle typing in message input
     * Automatically sends typing indicator and clears after inactivity
     * Requirements: 4.1.1, 4.1.2
     * @param {string} userId User ID of the conversation
     */
    handleTyping(userId) {
        if (!userId || userId !== this.currentConversationId) {
            this.currentConversationId = userId;
        }
        
        // Send typing indicator
        this.sendTypingIndicator(userId, true);
    }
    
    /**
     * Stop typing indicator for current conversation
     * Requirements: 4.1.2
     */
    stopTyping() {
        if (this.currentConversationId) {
            this.sendTypingIndicator(this.currentConversationId, false);
        }
        
        if (this.typingTimer) {
            clearTimeout(this.typingTimer);
            this.typingTimer = null;
        }
    }
    
    /**
     * Handle tab visibility changes
     * Requirements: 4.7
     */
    handleVisibilityChange() {
        const wasActive = this.isTabActive;
        this.isTabActive = !document.hidden;
        
        console.log(`[RealtimeManager] Tab ${this.isTabActive ? 'active' : 'inactive'}`);
        
        if (this.isTabActive && !wasActive) {
            // Tab became active
            this.onTabActive();
        } else if (!this.isTabActive && wasActive) {
            // Tab became inactive
            this.onTabInactive();
        }
    }
    
    /**
     * Handle tab becoming active
     * Requirements: 4.7
     */
    onTabActive() {
        console.log('[RealtimeManager] Tab became active, syncing...');
        
        if (this.socket && this.isConnected) {
            // Request sync via WebSocket
            this.socket.emit('sync', {
                last_check: this.lastCheck
            });
        } else if (this.isPolling) {
            // Speed up polling
            if (this.pollingTimer) {
                clearTimeout(this.pollingTimer);
                this.pollingTimer = null;
            }
            this.poll(); // Poll immediately
        }
    }
    
    /**
     * Handle tab becoming inactive
     * Requirements: 4.7
     */
    onTabInactive() {
        console.log('[RealtimeManager] Tab became inactive');
        
        // WebSocket stays connected, server will handle it
        // Polling will automatically slow down on next iteration
    }
    
    /**
     * Handle online event
     */
    handleOnline() {
        console.log('[RealtimeManager] Network online');
        
        if (this.useWebSocket && !this.isConnected) {
            // Try to reconnect WebSocket
            this.reconnectAttempts = 0;
            this.startWebSocket();
        } else if (this.isPolling) {
            // Resume polling immediately
            if (this.pollingTimer) {
                clearTimeout(this.pollingTimer);
                this.pollingTimer = null;
            }
            this.poll();
        }
    }
    
    /**
     * Handle offline event
     */
    handleOffline() {
        console.log('[RealtimeManager] Network offline');
        this.onConnectionChange('offline', this.useWebSocket ? 'websocket' : 'polling');
    }
    
    /**
     * Setup event listeners for visibility and network changes
     * @private
     */
    _setupEventListeners() {
        // Tab visibility
        document.addEventListener('visibilitychange', this.handleVisibilityChange);
        
        // Network status
        window.addEventListener('online', this.handleOnline);
        window.addEventListener('offline', this.handleOffline);
    }
    
    /**
     * Remove event listeners
     * @private
     */
    _removeEventListeners() {
        document.removeEventListener('visibilitychange', this.handleVisibilityChange);
        window.removeEventListener('online', this.handleOnline);
        window.removeEventListener('offline', this.handleOffline);
    }
    
    /**
     * Get connection status
     * @returns {Object} Status object with connection info
     */
    getStatus() {
        return {
            isConnected: this.isConnected,
            connectionType: this.useWebSocket ? 'websocket' : 'polling',
            reconnectAttempts: this.reconnectAttempts,
            isTabActive: this.isTabActive,
            averageLatency: this.messageLatencies.length > 0
                ? Math.round(this.messageLatencies.reduce((a, b) => a + b, 0) / this.messageLatencies.length)
                : null
        };
    }
    
    /**
     * Destroy the manager and clean up resources
     */
    destroy() {
        console.log('[RealtimeManager] Destroying...');
        
        this.stop();
        this._removeEventListeners();
        
        // Clear callbacks
        this.onNewMessage = () => {};
        this.onConversationUpdate = () => {};
        this.onTyping = () => {};
        this.onConnectionChange = () => {};
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RealtimeManager;
}
