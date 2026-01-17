/**
 * OfflineManager - Manages offline support and message queuing
 * 
 * Handles offline detection, message queuing when offline, localStorage persistence,
 * and automatic sending when back online.
 * 
 * Features:
 * - Offline/online detection (Requirement 11.1)
 * - Message queue with localStorage persistence (Requirement 11.3)
 * - Auto-send queued messages on reconnection (Requirement 11.4)
 * - Access to cached data when offline (Requirement 11.2)
 * 
 * @class OfflineManager
 * @version 1.0.0
 */
class OfflineManager {
    /**
     * Create an OfflineManager
     * @param {Object} options - Configuration options
     * @param {Function} options.onOnline - Callback when coming back online
     * @param {Function} options.onOffline - Callback when going offline
     * @param {Function} options.onMessageQueued - Callback when message is queued
     * @param {Function} options.onMessageSent - Callback when queued message is sent
     * @param {string} options.storageKey - LocalStorage key for queue (default: 'inbox_offline_queue')
     */
    constructor(options = {}) {
        // Callbacks
        this.onOnline = options.onOnline || null;
        this.onOffline = options.onOffline || null;
        this.onMessageQueued = options.onMessageQueued || null;
        this.onMessageSent = options.onMessageSent || null;
        
        // Storage configuration
        this.storageKey = options.storageKey || 'inbox_offline_queue';
        
        // State
        this.isOnline = navigator.onLine;
        this.messageQueue = [];
        this.isSending = false;
        
        // Load queue from localStorage
        this.loadQueue();
        
        // Initialize event listeners
        this.initialize();
    }
    
    /**
     * Initialize offline detection and event listeners
     * Requirement: 11.1
     */
    initialize() {
        // Listen for offline event
        window.addEventListener('offline', () => {
            console.log('🔴 Network offline detected');
            this.isOnline = false;
            
            if (this.onOffline) {
                this.onOffline();
            }
        });
        
        // Listen for online event
        window.addEventListener('online', () => {
            console.log('🟢 Network online detected');
            this.isOnline = true;
            
            if (this.onOnline) {
                this.onOnline();
            }
            
            // Auto-send queued messages (Requirement 11.4)
            this.sendQueuedMessages();
        });
        
        // Check initial state
        this.isOnline = navigator.onLine;
        console.log(`📡 Initial network state: ${this.isOnline ? 'online' : 'offline'}`);
    }
    
    /**
     * Check if currently online
     * @returns {boolean} True if online, false if offline
     */
    isNetworkOnline() {
        return this.isOnline && navigator.onLine;
    }
    
    /**
     * Queue a message for sending when back online
     * Requirement: 11.3
     * 
     * @param {Object} message - Message to queue
     * @param {string} message.userId - User ID to send to
     * @param {string} message.content - Message content
     * @param {string} message.type - Message type (default: 'text')
     * @returns {string} Queue ID for tracking
     */
    queueMessage(message) {
        if (!message || !message.userId || !message.content) {
            throw new Error('Invalid message: userId and content are required');
        }
        
        // Create queue item
        const queueItem = {
            id: `queue_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
            userId: message.userId,
            content: message.content,
            type: message.type || 'text',
            queuedAt: Date.now(),
            attempts: 0,
            status: 'queued' // 'queued', 'sending', 'sent', 'failed'
        };
        
        // Add to queue
        this.messageQueue.push(queueItem);
        
        // Save to localStorage
        this.saveQueue();
        
        console.log(`📥 Message queued (offline): ${queueItem.id}`, queueItem);
        
        // Call callback
        if (this.onMessageQueued) {
            this.onMessageQueued(queueItem);
        }
        
        return queueItem.id;
    }
    
    /**
     * Send all queued messages
     * Requirement: 11.4
     * 
     * @returns {Promise<Object>} Results of sending attempts
     */
    async sendQueuedMessages() {
        if (!this.isNetworkOnline()) {
            console.log('⏸️ Cannot send queued messages: offline');
            return { sent: 0, failed: 0, remaining: this.messageQueue.length };
        }
        
        if (this.isSending) {
            console.log('⏸️ Already sending queued messages');
            return { sent: 0, failed: 0, remaining: this.messageQueue.length };
        }
        
        if (this.messageQueue.length === 0) {
            console.log('✅ No queued messages to send');
            return { sent: 0, failed: 0, remaining: 0 };
        }
        
        this.isSending = true;
        console.log(`📤 Sending ${this.messageQueue.length} queued messages...`);
        
        let sent = 0;
        let failed = 0;
        
        // Process queue (make a copy to avoid modification during iteration)
        const queueCopy = [...this.messageQueue];
        
        for (const queueItem of queueCopy) {
            if (queueItem.status === 'sent') {
                // Already sent, remove from queue
                this.removeFromQueue(queueItem.id);
                continue;
            }
            
            try {
                // Update status
                queueItem.status = 'sending';
                queueItem.attempts++;
                this.saveQueue();
                
                // Send message via ChatPanelManager
                if (window.chatPanelManager) {
                    await window.chatPanelManager.postMessage(queueItem.content, queueItem.type);
                    
                    // Success
                    queueItem.status = 'sent';
                    queueItem.sentAt = Date.now();
                    sent++;
                    
                    console.log(`✅ Queued message sent: ${queueItem.id}`);
                    
                    // Call callback
                    if (this.onMessageSent) {
                        this.onMessageSent(queueItem);
                    }
                    
                    // Remove from queue
                    this.removeFromQueue(queueItem.id);
                } else {
                    throw new Error('ChatPanelManager not available');
                }
                
            } catch (error) {
                console.error(`❌ Failed to send queued message ${queueItem.id}:`, error);
                
                // Update status
                queueItem.status = 'failed';
                queueItem.lastError = error.message;
                queueItem.lastAttemptAt = Date.now();
                failed++;
                
                // Keep in queue for retry (max 3 attempts)
                if (queueItem.attempts >= 3) {
                    console.warn(`⚠️ Max attempts reached for ${queueItem.id}, removing from queue`);
                    this.removeFromQueue(queueItem.id);
                }
                
                this.saveQueue();
            }
        }
        
        this.isSending = false;
        
        const result = {
            sent,
            failed,
            remaining: this.messageQueue.length
        };
        
        console.log(`📊 Queue processing complete:`, result);
        
        return result;
    }
    
    /**
     * Remove a message from the queue
     * @param {string} queueId - Queue item ID
     */
    removeFromQueue(queueId) {
        const index = this.messageQueue.findIndex(item => item.id === queueId);
        if (index !== -1) {
            this.messageQueue.splice(index, 1);
            this.saveQueue();
            console.log(`🗑️ Removed from queue: ${queueId}`);
        }
    }
    
    /**
     * Get all queued messages
     * @returns {Array<Object>} Array of queued messages
     */
    getQueuedMessages() {
        return [...this.messageQueue];
    }
    
    /**
     * Get queue size
     * @returns {number} Number of messages in queue
     */
    getQueueSize() {
        return this.messageQueue.length;
    }
    
    /**
     * Clear all queued messages
     */
    clearQueue() {
        this.messageQueue = [];
        this.saveQueue();
        console.log('🗑️ Queue cleared');
    }
    
    /**
     * Save queue to localStorage
     * Requirement: 11.3 (persistence)
     * @private
     */
    saveQueue() {
        try {
            const data = JSON.stringify(this.messageQueue);
            localStorage.setItem(this.storageKey, data);
            console.log(`💾 Queue saved to localStorage (${this.messageQueue.length} items)`);
        } catch (error) {
            console.error('❌ Failed to save queue to localStorage:', error);
            
            // If quota exceeded, try to clear old items
            if (error.name === 'QuotaExceededError') {
                console.warn('⚠️ LocalStorage quota exceeded, clearing old items');
                this.clearOldQueueItems();
                
                // Try again
                try {
                    const data = JSON.stringify(this.messageQueue);
                    localStorage.setItem(this.storageKey, data);
                } catch (retryError) {
                    console.error('❌ Failed to save queue even after clearing:', retryError);
                }
            }
        }
    }
    
    /**
     * Load queue from localStorage
     * Requirement: 11.3 (persistence)
     * @private
     */
    loadQueue() {
        try {
            const data = localStorage.getItem(this.storageKey);
            if (data) {
                this.messageQueue = JSON.parse(data);
                console.log(`📂 Queue loaded from localStorage (${this.messageQueue.length} items)`);
                
                // Clean up old items (older than 24 hours)
                this.clearOldQueueItems();
            } else {
                this.messageQueue = [];
                console.log('📂 No queue found in localStorage');
            }
        } catch (error) {
            console.error('❌ Failed to load queue from localStorage:', error);
            this.messageQueue = [];
        }
    }
    
    /**
     * Clear queue items older than 24 hours
     * @private
     */
    clearOldQueueItems() {
        const oneDayAgo = Date.now() - (24 * 60 * 60 * 1000);
        const originalLength = this.messageQueue.length;
        
        this.messageQueue = this.messageQueue.filter(item => {
            return item.queuedAt > oneDayAgo;
        });
        
        const removed = originalLength - this.messageQueue.length;
        if (removed > 0) {
            console.log(`🗑️ Removed ${removed} old queue items (>24h)`);
            this.saveQueue();
        }
    }
    
    /**
     * Check if there are queued messages
     * @returns {boolean} True if queue has messages
     */
    hasQueuedMessages() {
        return this.messageQueue.length > 0;
    }
    
    /**
     * Get queue statistics
     * @returns {Object} Queue statistics
     */
    getQueueStats() {
        const stats = {
            total: this.messageQueue.length,
            queued: 0,
            sending: 0,
            failed: 0,
            oldestQueuedAt: null,
            newestQueuedAt: null
        };
        
        this.messageQueue.forEach(item => {
            if (item.status === 'queued') stats.queued++;
            if (item.status === 'sending') stats.sending++;
            if (item.status === 'failed') stats.failed++;
            
            if (!stats.oldestQueuedAt || item.queuedAt < stats.oldestQueuedAt) {
                stats.oldestQueuedAt = item.queuedAt;
            }
            if (!stats.newestQueuedAt || item.queuedAt > stats.newestQueuedAt) {
                stats.newestQueuedAt = item.queuedAt;
            }
        });
        
        return stats;
    }
    
    /**
     * Destroy the offline manager and clean up
     */
    destroy() {
        // Event listeners are automatically cleaned up when page unloads
        console.log('🔌 OfflineManager destroyed');
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = OfflineManager;
}
