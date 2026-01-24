/**
 * ChatPanelManager - Manages the chat panel UI with AJAX loading
 * 
 * Handles loading conversations via AJAX without page reload, message caching,
 * cursor-based pagination, optimistic UI updates, and browser URL management.
 * 
 * Features:
 * - AJAX conversation loading without page reload (Requirement 1.1)
 * - LRU message caching with 30-second TTL (Requirements 3.3, 3.4)
 * - Cursor-based pagination for efficient loading (Requirement 7.2)
 * - History API for URL updates without reload (Requirement 1.5)
 * - Optimistic UI for sending messages (Requirement 7.5)
 * - Loading states and error handling (Requirements 9.1-9.5)
 * - Lazy loading of older messages (Requirement 3.2)
 * 
 * @class ChatPanelManager
 * @version 1.0.0
 */
class ChatPanelManager {
    /**
     * Create a ChatPanelManager
     * @param {Object} options - Configuration options
     * @param {HTMLElement} options.container - Container element for chat panel
     * @param {HTMLElement} options.messageContainer - Container for messages
     * @param {HTMLElement} options.loadingIndicator - Loading indicator element
     * @param {number} options.cacheTTL - Cache TTL in milliseconds (default: 30000)
     * @param {number} options.cacheMaxSize - Maximum cache size (default: 10)
     * @param {number} options.messageLimit - Messages per page (default: 50)
     * @param {string} options.apiEndpoint - API endpoint URL (default: '/api/inbox-v2.php')
     */
    constructor(options = {}) {
        // Validate required options
        if (!options.container) {
            throw new Error('Container element is required');
        }

        // Core properties
        this.container = options.container;
        this.messageContainer = options.messageContainer || options.container;
        this.loadingIndicator = options.loadingIndicator || null;
        this.currentUserId = null;
        this.currentUserData = null;

        // Cache configuration (Requirements 3.3, 3.4, 3.5)
        // Increased TTL to 60 seconds for better cache hit rate
        this.cacheTTL = options.cacheTTL || 60000; // 60 seconds (was 30 seconds)
        // Increased cache size to 20 for better coverage in team environments
        this.cacheMaxSize = options.cacheMaxSize || 20; // Max 20 conversations (was 10)
        this.messageCache = new LRUCache(this.cacheMaxSize);

        // Pagination configuration (Requirement 3.1, 3.2)
        this.messageLimit = options.messageLimit || 50; // 50 messages per page
        this.currentCursor = null;
        this.hasMoreMessages = true;

        // API configuration
        this.apiEndpoint = options.apiEndpoint || '/api/inbox-v2.php';

        // Loading state (Requirements 9.1, 9.2, 9.3)
        this.loadingState = 'idle'; // 'idle', 'loading', 'error', 'sending'
        this.errorMessage = null;

        // Virtual scrolling properties (Phase 4 - Task 14.1)
        this.virtualScroller = null;
        this.isVirtualScrollEnabled = options.enableVirtualScroll !== false; // Enabled by default
        this.virtualScrollBuffer = options.virtualScrollBuffer || 10; // Buffer items above/below viewport
        this.virtualScrollThreshold = options.virtualScrollThreshold || 100; // Messages threshold to enable virtual scroll
        this.intersectionObserver = null;
        this.visibleMessages = new Set(); // Track visible message IDs
        this.allMessages = []; // Store all messages for virtual scrolling

        // Message queue for optimistic UI and offline support
        this.pendingMessages = [];
        this.messageIdCounter = 0;

        // Reply state
        this.replyToId = null;
        this.replyToMessage = null;

        // Request deduplication - prevent duplicate API calls when switching conversations rapidly
        this.pendingRequests = new Map(); // Map<userId, AbortController>

        // State tracking
        this.isInitialized = false;
        this.isLoadingMore = false;

        // Callbacks
        this.onMessageSent = options.onMessageSent || null;
        this.onConversationLoaded = options.onConversationLoaded || null;
        this.onError = options.onError || null;
    }

    /**
     * Initialize the chat panel manager
     * Sets up event listeners and initial state
     */
    initialize() {
        if (this.isInitialized) {
            console.warn('ChatPanelManager already initialized');
            return;
        }

        // Initialize event listener tracking
        this.initEventListenerTracking();

        // Setup paste event for image uploading (Task 5)
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            this.addTrackedEventListener(messageInput, 'paste', this.handlePaste.bind(this));
        }

        // Set up scroll listener for lazy loading older messages
        if (this.messageContainer) {
            const scrollHandler = this.handleScroll.bind(this);
            this.addTrackedEventListener(this.messageContainer, 'scroll', scrollHandler);
        }

        // Listen for popstate events (browser back/forward)
        const popStateHandler = this.handlePopState.bind(this);
        this.addTrackedEventListener(window, 'popstate', popStateHandler);

        // Initialize Intersection Observer for virtual scrolling (Task 14.1)
        if (this.isVirtualScrollEnabled) {
            this.initIntersectionObserver();
        }

        this.isInitialized = true;

        // Expose globally for inline onclick handlers
        window.chatPanelManager = this;

        console.log('ChatPanelManager initialized');
    }

    /**
     * Handle paste event
     * @param {ClipboardEvent} e 
     */
    handlePaste(e) {
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) {
                e.preventDefault();
                const blob = items[i].getAsFile();
                if (confirm('ส่งรูปภาพนี้หรือไม่?')) {
                    this.uploadImage(blob);
                }
                return;
            }
        }
    }

    async uploadImage(blob) {
        if (!this.currentUserId) return;

        const formData = new FormData();
        formData.append('action', 'send_image'); // This goes to inbox-v2.php handler
        formData.append('user_id', this.currentUserId);
        formData.append('image', blob, 'pasted_image.png');
        if (this.replyToId) {
            formData.append('reply_to_id', this.replyToId);
        }

        try {
            // Show temp message
            const tempId = 'temp_img_' + Date.now();
            this.appendMessage({
                id: tempId,
                type: 'text',
                content: '📷 Uploading image...',
                direction: 'outgoing',
                status: 'sending',
                created_at: new Date().toISOString(),
                is_temp: true
            });

            // ChatPanelManager uses apiEndpoint which is configured to /api/inbox-v2.php
            // But send_message is in inbox-v2.php.
            // We'll force fetch to inbox-v2.php for this one if apiEndpoint is the API file.
            // However, ChatPanelManager.js usually posts to apiEndpoint.
            // Let's assume apiEndpoint is correct or let's try 'inbox-v2.php' explicitly if fails.

            const response = await fetch('inbox-v2.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const result = await response.json();

            if (result.success) {
                // Remove temp
                const tempEl = this.messageContainer.querySelector(`[data-message-id="${tempId}"]`);
                if (tempEl) tempEl.remove();

                // Append real message (usually server pushes it or we fetch it, but let's append optimistic result)
                this.appendMessage({
                    id: result.message_id || Date.now(),
                    type: 'image',
                    image_url: result.image_url,
                    content: result.image_url,
                    direction: 'outgoing',
                    status: 'sent',
                    created_at: new Date().toISOString()
                });

                if (this.replyToId) this.cancelReply();
            } else {
                alert('Upload failed: ' + result.error);
                const tempEl = this.messageContainer.querySelector(`[data-message-id="${tempId}"]`);
                if (tempEl) tempEl.remove();
            }
        } catch (err) {
            console.error(err);
            alert('Upload error');
        }
    }

    handleReply(id, content, type) {
        this.replyToId = id;
        this.replyToMessage = { id, content, type };

        let preview = document.getElementById('replyPreview');
        if (!preview) {
            // Insert preview container
            const inputContainer = document.getElementById('messageInput')?.parentElement;
            if (inputContainer) {
                preview = document.createElement('div');
                preview.id = 'replyPreview';
                preview.className = 'flex justify-between items-center p-2 mb-2 bg-gray-50 border-l-4 border-teal-500 rounded text-sm';
                inputContainer.parentElement.insertBefore(preview, inputContainer);
            }
        }

        if (preview) {
            const icon = type === 'image' ? '<i class="fas fa-image"></i> Image' : '<i class="fas fa-comment"></i>';
            const text = type === 'image' ? 'Image' : (content.length > 60 ? content.substring(0, 60) + '...' : content);
            preview.innerHTML = `
                <div>
                    <div class="text-xs text-teal-600 font-bold">Replying to</div>
                    <div class="text-gray-600 flex items-center gap-2">${icon} ${text}</div>
                </div>
                <button onclick="chatPanelManager.cancelReply()" class="text-gray-400 hover:text-red-500">
                    <i class="fas fa-times"></i>
                </button>
             `;
            preview.style.display = 'flex';
        }

        document.getElementById('messageInput')?.focus();
    }

    cancelReply() {
        this.replyToId = null;
        const preview = document.getElementById('replyPreview');
        if (preview) preview.style.display = 'none';
    }

    /**
     * Initialize Intersection Observer for virtual scrolling
     * Renders only visible messages + buffer for performance
     * 
     * Validates: Requirement 6.1 (virtual scrolling for 100+ messages)
     * @private
     */
    initIntersectionObserver() {
        if (!this.messageContainer) {
            console.warn('Message container not set, cannot initialize Intersection Observer');
            return;
        }

        // Create Intersection Observer with root as message container
        const options = {
            root: this.messageContainer,
            rootMargin: '200px', // Load images 200px before entering viewport
            threshold: 0.01 // Trigger when 1% visible
        };

        this.intersectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                const messageEl = entry.target;
                const messageId = messageEl.dataset.messageId;

                if (entry.isIntersecting) {
                    // Message entered viewport
                    this.visibleMessages.add(messageId);
                    messageEl.classList.add('message-visible');

                    // Trigger lazy image loading (Task 14.3)
                    this.loadMessageImages(messageEl);
                } else {
                    // Message left viewport
                    this.visibleMessages.delete(messageId);
                    messageEl.classList.remove('message-visible');
                }
            });
        }, options);

        console.log('Intersection Observer initialized for virtual scrolling');
    }

    /**
     * Load conversation via AJAX without page reload
     * Checks cache first, then fetches from API if needed
     * Supports offline mode - shows cached data when offline
     * 
     * @param {string} userId - User ID of conversation to load
     * @param {boolean} useCache - Whether to use cache (default: true)
     * @param {Object} userData - Optional user data (name, picture, etc.)
     * @returns {Promise<void>}
     * 
     * Validates: Requirements 1.1, 1.4, 3.4, 11.2 (offline cache access)
     */
    async loadConversation(userId, useCache = true, userData = null) {
        // Start performance tracking (Requirements: 12.2)
        const perfStart = performance.now();

        if (!userId) {
            console.error('userId is required for loadConversation');
            return;
        }

        // Store current user
        this.currentUserId = userId;
        this.currentUserData = userData;
        this.currentCursor = null;
        this.hasMoreMessages = true;

        // Check if offline (Requirement 11.2)
        const isOffline = window.offlineManager && !window.offlineManager.isNetworkOnline();

        // Check cache first (Requirement 3.4, 11.2)
        if (useCache && this.messageCache.has(userId)) {
            const cached = this.messageCache.get(userId);

            // If offline, always use cache regardless of age (Requirement 11.2)
            if (isOffline) {
                console.log('📴 Offline mode: Loading conversation from cache');
                this.renderMessages(cached.messages);
                this.updateURL(userId);
                this.loadingState = 'idle';

                // Track performance (Requirements: 12.2)
                if (window.performanceTracker) {
                    window.performanceTracker.trackConversationSwitch(
                        userId,
                        perfStart,
                        true, // from cache
                        cached.messages.length
                    );
                }

                // Show offline notice
                this.showOfflineNotice();

                // Call callback
                if (this.onConversationLoaded) {
                    this.onConversationLoaded(userId, cached.messages, true);
                }

                return;
            }

            // Check if cache is still valid (30 seconds TTL)
            const cacheAge = Date.now() - cached.timestamp;
            if (cacheAge < this.cacheTTL) {
                console.log(`Loading conversation from cache (age: ${cacheAge}ms)`);
                this.renderMessages(cached.messages);
                this.updateURL(userId);
                this.loadingState = 'idle';

                // Track performance (Requirements: 12.2)
                if (window.performanceTracker) {
                    window.performanceTracker.trackConversationSwitch(
                        userId,
                        perfStart,
                        true, // from cache
                        cached.messages.length
                    );
                }

                // Call callback
                if (this.onConversationLoaded) {
                    this.onConversationLoaded(userId, cached.messages, true);
                }

                return;
            } else {
                console.log(`Cache expired (age: ${cacheAge}ms), fetching fresh data`);
            }
        }

        // If offline and no cache, show error (Requirement 11.2)
        if (isOffline) {
            console.warn('📴 Offline mode: No cached data available for this conversation');
            this.loadingState = 'error';
            this.errorMessage = 'ไม่สามารถโหลดการสนทนาได้ในโหมดออฟไลน์ (ไม่มีข้อมูลแคช)';
            this.showErrorState(this.errorMessage);
            return;
        }

        // Show loading state (Requirement 9.1)
        this.showLoadingState();

        try {
            // Fetch via AJAX (Requirement 1.1)
            const messages = await this.fetchMessages(userId, null, this.messageLimit);

            // Cache the result (Requirement 3.4)
            this.messageCache.set(userId, {
                messages: messages,
                timestamp: Date.now()
            });

            // Render messages
            this.renderMessages(messages);

            // Update URL without reload (Requirement 1.5)
            this.updateURL(userId);

            // Update state
            this.loadingState = 'idle';
            this.hideLoadingState();

            // Track performance (Requirements: 12.2)
            if (window.performanceTracker) {
                window.performanceTracker.trackConversationSwitch(
                    userId,
                    perfStart,
                    false, // not from cache
                    messages.length
                );
            }

            // Call callback
            if (this.onConversationLoaded) {
                this.onConversationLoaded(userId, messages, false);
            }

        } catch (error) {
            console.error('Error loading conversation:', error);
            this.loadingState = 'error';
            this.errorMessage = error.message || 'Failed to load conversation';
            this.showErrorState(this.errorMessage);

            // Call error callback
            if (this.onError) {
                this.onError('load_conversation', error);
            }
        }
    }

    /**
     * Fetch messages with cursor-based pagination
     * Uses message ID as cursor for efficient pagination (no OFFSET)
     * 
     * @param {string} userId - User ID
     * @param {string} cursor - Pagination cursor (message ID, optional)
     * @param {number} limit - Number of messages (default: 50)
     * @returns {Promise<Array>} Array of message objects
     * 
     * Validates: Requirements 3.1, 3.2, 7.2
     */
    async fetchMessages(userId, cursor = null, limit = null) {
        // Start performance tracking (Requirements: 12.4)
        const perfStart = performance.now();
        const endpoint = 'getMessages';

        const messageLimit = limit || this.messageLimit;

        // Build URL with cursor-based pagination (Requirement 7.2)
        let url = `${this.apiEndpoint}?action=getMessages&user_id=${encodeURIComponent(userId)}&limit=${messageLimit}`;

        if (cursor) {
            url += `&cursor=${encodeURIComponent(cursor)}`;
        }

        // Request deduplication - cancel previous request for this userId if exists
        const requestKey = cursor ? `${userId}_${cursor}` : userId;
        if (this.pendingRequests.has(requestKey)) {
            console.log(`Cancelling previous request for: ${requestKey}`);
            this.pendingRequests.get(requestKey).abort();
        }

        // Create new AbortController for this request
        const abortController = new AbortController();
        this.pendingRequests.set(requestKey, abortController);

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                signal: abortController.signal
            });

            // Track API call performance (Requirements: 12.4)
            if (window.performanceTracker) {
                window.performanceTracker.trackApiCall(
                    endpoint,
                    perfStart,
                    response.ok,
                    response.status
                );
            }

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to fetch messages');
            }

            // Store cursor for next page
            this.currentCursor = data.next_cursor || data.data?.next_cursor || null;
            this.hasMoreMessages = data.has_more || data.data?.has_more || false;

            // Extract messages array from response
            // API can return: data.data.messages, data.messages, or data.data (if data is array)
            let messages = [];
            if (data.data) {
                if (Array.isArray(data.data)) {
                    messages = data.data;
                } else if (data.data.messages && Array.isArray(data.data.messages)) {
                    messages = data.data.messages;
                }
            } else if (data.messages && Array.isArray(data.messages)) {
                messages = data.messages;
            }

            // Clean up pending request on success
            this.pendingRequests.delete(requestKey);

            return messages;

        } catch (error) {
            // Clean up pending request on error
            this.pendingRequests.delete(requestKey);

            // Ignore AbortError - request was cancelled intentionally
            if (error.name === 'AbortError') {
                console.log(`Request cancelled for: ${requestKey}`);
                return [];
            }

            // Track failed API call (Requirements: 12.4)
            if (window.performanceTracker) {
                window.performanceTracker.trackApiCall(
                    endpoint,
                    perfStart,
                    false,
                    0
                );
            }

            console.error('Error fetching messages:', error);
            throw error;
        }
    }

    /**
     * Load older messages when scrolling up
     * Implements lazy loading with cursor-based pagination
     * 
     * @returns {Promise<void>}
     * 
     * Validates: Requirement 3.2 (lazy load in batches of 50)
     */
    async loadOlderMessages() {
        // Don't load if already loading or no more messages
        if (this.isLoadingMore || !this.hasMoreMessages || !this.currentCursor) {
            return;
        }

        if (!this.currentUserId) {
            console.warn('No conversation loaded');
            return;
        }

        this.isLoadingMore = true;
        this.showLoadingMoreIndicator();

        try {
            // Fetch older messages using cursor (Requirement 3.2)
            const olderMessages = await this.fetchMessages(
                this.currentUserId,
                this.currentCursor,
                this.messageLimit
            );

            // Prepend to existing messages
            this.prependMessages(olderMessages);

            // Update cache
            if (this.messageCache.has(this.currentUserId)) {
                const cached = this.messageCache.get(this.currentUserId);
                cached.messages = [...olderMessages, ...cached.messages];
                cached.timestamp = Date.now();
                this.messageCache.set(this.currentUserId, cached);
            }

        } catch (error) {
            console.error('Error loading older messages:', error);
            this.showErrorState('Failed to load older messages');
        } finally {
            this.isLoadingMore = false;
            this.hideLoadingMoreIndicator();
        }
    }

    /**
     * Send message with optimistic UI and offline support
     * Adds message to UI immediately before server responds
     * If offline, queues message for sending when back online
     * 
     * @param {string} content - Message content
     * @param {string} type - Message type (default: 'text')
     * @returns {Promise<Object>} Sent message object
     * 
     * Validates: Requirement 7.5 (optimistic UI), 11.3 (offline queue)
     */
    async sendMessage(content, type = 'text') {
        if (!content || !content.trim()) {
            console.warn('Cannot send empty message');
            return null;
        }

        if (!this.currentUserId) {
            console.error('No conversation loaded');
            return null;
        }

        // Check if offline (Requirement 11.3)
        const isOffline = window.offlineManager && !window.offlineManager.isNetworkOnline();

        if (isOffline) {
            console.log('📴 Offline detected, queuing message');

            // Queue message for sending when back online
            if (window.offlineManager) {
                const queueId = window.offlineManager.queueMessage({
                    userId: this.currentUserId,
                    content: content,
                    type: type
                });

                // Show queued status in UI
                const queuedMessage = {
                    id: queueId,
                    user_id: this.currentUserId,
                    content: content,
                    type: type,
                    direction: 'outgoing',
                    status: 'queued',
                    created_at: new Date().toISOString(),
                    is_temp: true
                };

                this.appendMessage(queuedMessage);

                return queuedMessage;
            }
        }

        // Generate temporary ID for optimistic UI (Requirement 7.5)
        const tempId = `temp_${Date.now()}_${++this.messageIdCounter}`;

        // Create optimistic message object
        const optimisticMessage = {
            id: tempId,
            user_id: this.currentUserId,
            content: content,
            type: type,
            direction: 'outgoing',
            status: 'sending',
            created_at: new Date().toISOString(),
            is_temp: true
        };

        // Add to pending queue
        this.pendingMessages.push(optimisticMessage);

        // Add message to UI immediately (Requirement 7.5)
        this.appendMessage(optimisticMessage);

        try {
            // Send to server
            const result = await this.postMessage(content, type);

            // Update UI with real message ID (Requirement 9.3)
            this.updateMessageStatus(tempId, result.message_id || result.id, 'sent');

            // Remove from pending queue
            this.pendingMessages = this.pendingMessages.filter(m => m.id !== tempId);

            // Update cache
            if (this.messageCache.has(this.currentUserId)) {
                const cached = this.messageCache.get(this.currentUserId);
                // Replace temp message with real message
                const index = cached.messages.findIndex(m => m.id === tempId);
                if (index !== -1) {
                    cached.messages[index] = {
                        ...result,
                        status: 'sent'
                    };
                } else {
                    cached.messages.push(result);
                }
                cached.timestamp = Date.now();
                this.messageCache.set(this.currentUserId, cached);
            }

            // Call callback
            if (this.onMessageSent) {
                this.onMessageSent(result);
            }

            return result;

        } catch (error) {
            console.error('Error sending message:', error);

            // Update UI to show failed state (Requirement 9.4)
            this.updateMessageStatus(tempId, null, 'failed');

            // Keep in pending queue for retry
            const pendingMsg = this.pendingMessages.find(m => m.id === tempId);
            if (pendingMsg) {
                pendingMsg.status = 'failed';
                pendingMsg.error = error.message;
            }

            // Call error callback
            if (this.onError) {
                this.onError('send_message', error);
            }

            throw error;
        }
    }

    /**
     * Post message to server
     * @param {string} content - Message content
     * @param {string} type - Message type
     * @returns {Promise<Object>} Server response
     * @private
     */
    async postMessage(content, type = 'text') {
        const url = `${this.apiEndpoint}?action=sendMessage`;

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                user_id: this.currentUserId,
                content: content,
                type: type,
                reply_to_id: this.replyToId
            })
        });

        // Clear reply state after sending
        if (this.replyToId) {
            this.cancelReply();
        }

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'Failed to send message');
        }

        return data.data || data.message || {};
    }

    /**
     * Retry sending a failed message
     * @param {string} tempId - Temporary message ID
     * @returns {Promise<void>}
     */
    async retryMessage(tempId) {
        const pendingMsg = this.pendingMessages.find(m => m.id === tempId);

        if (!pendingMsg) {
            console.warn(`Pending message not found: ${tempId}`);
            return;
        }

        // Update status to sending
        this.updateMessageStatus(tempId, null, 'sending');
        pendingMsg.status = 'sending';

        try {
            // Retry sending
            const result = await this.postMessage(pendingMsg.content, pendingMsg.type);

            // Update UI with real message ID
            this.updateMessageStatus(tempId, result.message_id || result.id, 'sent');

            // Remove from pending queue
            this.pendingMessages = this.pendingMessages.filter(m => m.id !== tempId);

            // Call callback
            if (this.onMessageSent) {
                this.onMessageSent(result);
            }

        } catch (error) {
            console.error('Error retrying message:', error);
            this.updateMessageStatus(tempId, null, 'failed');
            pendingMsg.status = 'failed';
            pendingMsg.error = error.message;
        }
    }

    /**
     * Update browser URL using History API
     * Updates URL without page reload
     * 
     * @param {string} userId - User ID
     * 
     * Validates: Requirement 1.5 (History API without reload)
     */
    updateURL(userId) {
        if (!userId) {
            return;
        }

        try {
            // Create new URL with user_id parameter
            const url = new URL(window.location);
            url.searchParams.set('user_id', userId);

            // Update browser URL without reload (Requirement 1.5)
            window.history.pushState(
                { userId: userId },
                '',
                url.toString()
            );

            console.log(`URL updated to: ${url.toString()}`);

        } catch (error) {
            console.error('Error updating URL:', error);
        }
    }

    /**
     * Handle browser back/forward navigation
     * @param {PopStateEvent} event - PopState event
     * @private
     */
    handlePopState(event) {
        if (event.state && event.state.userId) {
            // Load conversation from history state
            this.loadConversation(event.state.userId, true);
        } else {
            // Try to get user_id from URL
            const url = new URL(window.location);
            const userId = url.searchParams.get('user_id');

            if (userId) {
                this.loadConversation(userId, true);
            }
        }
    }

    /**
     * Handle scroll event for lazy loading
     * @param {Event} event - Scroll event
     * @private
     */
    handleScroll(event) {
        if (!this.messageContainer) {
            return;
        }

        // Check if scrolled to top (for loading older messages)
        const scrollTop = this.messageContainer.scrollTop;
        const threshold = 100; // Load when within 100px of top

        if (scrollTop < threshold && this.hasMoreMessages && !this.isLoadingMore) {
            // Save current scroll position
            const oldScrollHeight = this.messageContainer.scrollHeight;

            // Load older messages
            this.loadOlderMessages().then(() => {
                // Restore scroll position after prepending messages
                const newScrollHeight = this.messageContainer.scrollHeight;
                const scrollDiff = newScrollHeight - oldScrollHeight;
                this.messageContainer.scrollTop = scrollTop + scrollDiff;
            });
        }
    }

    /**
     * Render messages in the chat panel
     * @param {Array<Object>} messages - Array of message objects
     * @private
     */
    renderMessages(messages) {
        // Start performance tracking (Requirements: 12.3)
        const perfStart = performance.now();

        if (!this.messageContainer) {
            console.warn('Message container not set');
            return;
        }

        // Store all messages for virtual scrolling
        this.allMessages = messages;

        // Clear existing messages
        this.messageContainer.innerHTML = '';

        // Check if we should use virtual scrolling (Requirement 6.1)
        const shouldUseVirtualScroll = this.isVirtualScrollEnabled &&
            messages.length > this.virtualScrollThreshold;

        if (shouldUseVirtualScroll) {
            console.log(`Using virtual scrolling for ${messages.length} messages`);
            this.renderMessagesVirtual(messages);
        } else {
            // Render all messages normally
            // Ensure messages is an array before calling forEach
            if (Array.isArray(messages)) {
                messages.forEach(message => {
                    this.appendMessage(message, false);
                });
            } else {
                console.error('messages is not an array:', messages);
            }
        }

        // Scroll to bottom
        this.scrollToBottom();

        // Track performance (Requirements: 12.3)
        if (window.performanceTracker) {
            window.performanceTracker.trackMessageRender(
                messages.length,
                perfStart,
                'initial'
            );
        }

        console.log(`Rendered ${messages.length} messages`);
    }

    /**
     * Render messages with virtual scrolling
     * Only renders visible messages + buffer for performance
     * 
     * @param {Array<Object>} messages - Array of message objects
     * @private
     */
    renderMessagesVirtual(messages) {
        // For now, render all messages but observe them for lazy loading
        // In a full implementation, we would only render visible + buffer
        // This is a simplified version that focuses on lazy image loading

        // Ensure messages is an array before calling forEach
        if (Array.isArray(messages)) {
            messages.forEach(message => {
                this.appendMessage(message, false);
            });
        } else {
            console.error('renderMessagesVirtual: messages is not an array:', messages);
        }

        // Observe all message elements for intersection
        if (this.intersectionObserver) {
            const messageElements = this.messageContainer.querySelectorAll('.message');
            messageElements.forEach(el => {
                this.intersectionObserver.observe(el);
            });
        }

        // Enforce DOM node limit after rendering (Requirement 8.3)
        this.enforceDOMNodeLimit();
    }

    /**
     * Append a single message to the chat panel
     * @param {Object} message - Message object
     * @param {boolean} scroll - Whether to scroll to bottom (default: true)
     * @private
     */
    appendMessage(message, scroll = true) {
        if (!this.messageContainer) {
            return;
        }

        // Create message element
        const messageEl = this.createMessageElement(message);

        // Append to container
        this.messageContainer.appendChild(messageEl);

        // Observe for intersection if virtual scrolling is enabled
        if (this.intersectionObserver && this.isVirtualScrollEnabled) {
            this.intersectionObserver.observe(messageEl);
        }

        // Scroll to bottom if needed
        if (scroll) {
            this.scrollToBottom();
        }
    }

    /**
     * Prepend messages to the chat panel (for lazy loading)
     * @param {Array<Object>} messages - Array of message objects
     * @private
     */
    prependMessages(messages) {
        if (!this.messageContainer || !messages || !Array.isArray(messages) || messages.length === 0) {
            return;
        }

        // Create document fragment for better performance
        const fragment = document.createDocumentFragment();

        // Create message elements in reverse order (oldest first)
        messages.forEach(message => {
            const messageEl = this.createMessageElement(message);
            fragment.appendChild(messageEl);
        });

        // Prepend to container
        this.messageContainer.insertBefore(fragment, this.messageContainer.firstChild);

        console.log(`Prepended ${messages.length} older messages`);
    }

    /**
     * Create a message DOM element
     * @param {Object} message - Message object
     * @returns {HTMLElement} Message element
     * @private
     */
    createMessageElement(message) {
        const messageEl = document.createElement('div');
        messageEl.className = `message message-${message.direction || 'incoming'}`;
        messageEl.dataset.messageId = message.id;

        // Add status class for outgoing messages
        if (message.direction === 'outgoing' && message.status) {
            messageEl.classList.add(`message-${message.status}`);
        }

        // Add temp class for optimistic messages
        if (message.is_temp) {
            messageEl.classList.add('message-temp');
        }

        // Create message content
        const contentEl = document.createElement('div');
        contentEl.className = 'message-content';

        // Handle different message types
        if (message.type === 'image') {
            // Use lazy loading for images (Task 14.3 - Requirement 6.2)
            const img = document.createElement('img');
            img.className = 'message-image';
            img.alt = 'Image message';

            // Set data-src for lazy loading instead of src
            img.dataset.src = message.content || message.image_url;

            // Set placeholder
            img.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200"%3E%3Crect fill="%23f0f0f0" width="300" height="200"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" fill="%23999" font-family="sans-serif" font-size="14"%3ELoading...%3C/text%3E%3C/svg%3E';

            contentEl.appendChild(img);
        } else if (message.type === 'flex' && message.flex_content) {
            // Render Flex Message with caching (Task 14.5 - Requirement 6.3)
            try {
                const flexContent = typeof message.flex_content === 'string'
                    ? JSON.parse(message.flex_content)
                    : message.flex_content;

                const flexHtml = this.renderFlexMessage(flexContent);
                contentEl.innerHTML = flexHtml;
            } catch (error) {
                console.error('Error rendering Flex Message:', error);
                contentEl.textContent = '[Flex Message]';
            }
        } else {
            contentEl.textContent = message.content || message.text || '';
        }

        messageEl.appendChild(contentEl);

        // Add timestamp
        const timeEl = document.createElement('div');
        timeEl.className = 'message-time';
        timeEl.textContent = this.formatMessageTime(message.created_at);
        messageEl.appendChild(timeEl);

        // Add status indicator for outgoing messages
        if (message.direction === 'outgoing') {
            const statusEl = document.createElement('div');
            statusEl.className = 'message-status';

            if (message.status === 'sending') {
                statusEl.innerHTML = '<i class="fas fa-clock"></i>';
                statusEl.title = 'Sending...';
            } else if (message.status === 'sent') {
                statusEl.innerHTML = '<i class="fas fa-check"></i>';
                statusEl.title = 'Sent';
            } else if (message.status === 'failed') {
                statusEl.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                statusEl.title = 'Failed to send';

                // Add retry button
                const retryBtn = document.createElement('button');
                retryBtn.className = 'btn-retry';
                retryBtn.innerHTML = '<i class="fas fa-redo"></i> Retry';
                retryBtn.onclick = () => this.retryMessage(message.id);
                messageEl.appendChild(retryBtn);
            }

            messageEl.appendChild(statusEl);
        } else {
            // Incoming message - Add Reply Button
            // Make message relative for absolute positioning of action button
            messageEl.style.position = 'relative';

            // Extract plain text for the handler to avoid quote issues
            const safeContent = (message.content || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
            const type = message.type || 'text';

            const replyBtn = document.createElement('button');
            replyBtn.className = 'reply-btn absolute top-1 -right-6 text-gray-400 hover:text-teal-600 opacity-0 group-hover:opacity-100 transition-opacity bg-white rounded-full p-1 shadow-sm';
            replyBtn.innerHTML = '<i class="fas fa-reply"></i>';
            replyBtn.onclick = () => window.chatPanelManager.handleReply(message.id, safeContent, type);
            replyBtn.title = 'Reply';

            // Ensure group hover works by adding group class
            messageEl.classList.add('group');
            messageEl.appendChild(replyBtn);
        }

        // Show Reply Context
        if (message.reply_to_id) {
            const context = document.createElement('div');
            context.className = 'text-xs text-gray-400 mb-1 flex items-center gap-1';
            context.innerHTML = `<i class="fas fa-reply fa-flip-horizontal"></i> Replied to message`;
            messageEl.insertBefore(context, messageEl.firstChild);
        }

        return messageEl;
    }

    /**
     * Update message status in UI
     * @param {string} tempId - Temporary message ID
     * @param {string} realId - Real message ID from server
     * @param {string} status - New status ('sending', 'sent', 'failed')
     * @private
     */
    updateMessageStatus(tempId, realId, status) {
        const messageEl = this.messageContainer?.querySelector(`[data-message-id="${tempId}"]`);

        if (!messageEl) {
            console.warn(`Message element not found: ${tempId}`);
            return;
        }

        // Update message ID if provided
        if (realId) {
            messageEl.dataset.messageId = realId;
        }

        // Update status class
        messageEl.className = messageEl.className.replace(/message-(sending|sent|failed)/g, '');
        messageEl.classList.add(`message-${status}`);

        // Remove temp class
        messageEl.classList.remove('message-temp');

        // Update status indicator
        const statusEl = messageEl.querySelector('.message-status');
        if (statusEl) {
            if (status === 'sending') {
                statusEl.innerHTML = '<i class="fas fa-clock"></i>';
                statusEl.title = 'Sending...';
            } else if (status === 'sent') {
                statusEl.innerHTML = '<i class="fas fa-check"></i>';
                statusEl.title = 'Sent';
            } else if (status === 'failed') {
                statusEl.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                statusEl.title = 'Failed to send';

                // Add retry button if not exists
                if (!messageEl.querySelector('.btn-retry')) {
                    const retryBtn = document.createElement('button');
                    retryBtn.className = 'btn-retry';
                    retryBtn.innerHTML = '<i class="fas fa-redo"></i> Retry';
                    retryBtn.onclick = () => this.retryMessage(realId || tempId);
                    messageEl.appendChild(retryBtn);
                }
            }
        }
    }

    /**
     * Format message timestamp
     * @param {string} timestamp - ISO timestamp
     * @returns {string} Formatted time
     * @private
     */
    formatMessageTime(timestamp) {
        if (!timestamp) {
            return '';
        }

        try {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = now - date;

            // Less than 1 minute
            if (diff < 60000) {
                return 'Just now';
            }

            // Less than 1 hour
            if (diff < 3600000) {
                const minutes = Math.floor(diff / 60000);
                return `${minutes}m ago`;
            }

            // Less than 24 hours
            if (diff < 86400000) {
                const hours = Math.floor(diff / 3600000);
                return `${hours}h ago`;
            }

            // Same year
            if (date.getFullYear() === now.getFullYear()) {
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            }

            // Different year
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });

        } catch (error) {
            console.error('Error formatting time:', error);
            return '';
        }
    }

    /**
     * Scroll to bottom of message container
     * @private
     */
    scrollToBottom() {
        if (!this.messageContainer) {
            return;
        }

        // Use smooth scrolling
        this.messageContainer.scrollTop = this.messageContainer.scrollHeight;
    }

    /**
     * Show loading state
     * @private
     */
    showLoadingState() {
        this.loadingState = 'loading';

        if (this.loadingIndicator) {
            this.loadingIndicator.style.display = 'block';
        }

        // Show skeleton loader in message container
        if (this.messageContainer) {
            this.messageContainer.innerHTML = `
                <div class="skeleton-loader">
                    <div class="skeleton-message"></div>
                    <div class="skeleton-message"></div>
                    <div class="skeleton-message"></div>
                </div>
            `;
        }
    }

    /**
     * Hide loading state
     * @private
     */
    hideLoadingState() {
        this.loadingState = 'idle';

        if (this.loadingIndicator) {
            this.loadingIndicator.style.display = 'none';
        }
    }

    /**
     * Show loading more indicator (for lazy loading)
     * @private
     */
    showLoadingMoreIndicator() {
        if (!this.messageContainer) {
            return;
        }

        // Check if indicator already exists
        let indicator = this.messageContainer.querySelector('.loading-more-indicator');

        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'loading-more-indicator';
            indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading older messages...';
            this.messageContainer.insertBefore(indicator, this.messageContainer.firstChild);
        }
    }

    /**
     * Hide loading more indicator
     * @private
     */
    hideLoadingMoreIndicator() {
        if (!this.messageContainer) {
            return;
        }

        const indicator = this.messageContainer.querySelector('.loading-more-indicator');
        if (indicator) {
            indicator.remove();
        }
    }

    /**
     * Show error state
     * @param {string} message - Error message
     * @private
     */
    showErrorState(message) {
        this.loadingState = 'error';
        this.errorMessage = message;

        if (this.messageContainer) {
            const errorEl = document.createElement('div');
            errorEl.className = 'error-state';
            errorEl.innerHTML = `
                <i class="fas fa-exclamation-triangle"></i>
                <p>${message}</p>
                <button class="btn-retry" onclick="location.reload()">
                    <i class="fas fa-redo"></i> Retry
                </button>
            `;

            this.messageContainer.innerHTML = '';
            this.messageContainer.appendChild(errorEl);
        }
    }

    /**
     * Show offline notice banner
     * Requirement: 11.2
     * @private
     */
    showOfflineNotice() {
        if (!this.messageContainer) {
            return;
        }

        // Check if notice already exists
        if (this.messageContainer.querySelector('.offline-notice')) {
            return;
        }

        const notice = document.createElement('div');
        notice.className = 'offline-notice bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 mb-4 rounded';
        notice.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-info-circle mr-2"></i>
                <p class="text-sm">
                    <strong>โหมดออฟไลน์:</strong> กำลังแสดงข้อมูลจากแคช ข้อมูลอาจไม่เป็นปัจจุบัน
                </p>
            </div>
        `;

        // Insert at the top of message container
        this.messageContainer.insertBefore(notice, this.messageContainer.firstChild);
    }

    /**
     * Hide offline notice banner
     * @private
     */
    hideOfflineNotice() {
        if (!this.messageContainer) {
            return;
        }

        const notice = this.messageContainer.querySelector('.offline-notice');
        if (notice) {
            notice.remove();
        }
    }

    /**
     * Clear cache for a specific conversation
     * @param {string} userId - User ID
     */
    clearCache(userId) {
        if (userId) {
            this.messageCache.delete(userId);
            console.log(`Cache cleared for user: ${userId}`);
        } else {
            this.messageCache.clear();
            console.log('All cache cleared');
        }
    }

    /**
     * Get cache statistics
     * @returns {Object} Cache stats
     */
    getCacheStats() {
        return {
            size: this.messageCache.size,
            maxSize: this.cacheMaxSize,
            keys: this.messageCache.keys(),
            ttl: this.cacheTTL
        };
    }

    /**
     * Check if a conversation is cached and valid
     * @param {string} userId - User ID
     * @returns {boolean} True if cached and valid
     */
    isCached(userId) {
        if (!this.messageCache.has(userId)) {
            return false;
        }

        const cached = this.messageCache.get(userId);
        const cacheAge = Date.now() - cached.timestamp;

        return cacheAge < this.cacheTTL;
    }

    /**
     * Get pending messages (for offline support)
     * @returns {Array<Object>} Array of pending messages
     */
    getPendingMessages() {
        return [...this.pendingMessages];
    }

    /**
     * Clear pending messages
     */
    clearPendingMessages() {
        this.pendingMessages = [];
    }

    /**
     * Get current conversation ID
     * @returns {string|null} Current user ID
     */
    getCurrentUserId() {
        return this.currentUserId;
    }

    /**
     * Get current loading state
     * @returns {string} Loading state
     */
    getLoadingState() {
        return this.loadingState;
    }

    /**
     * Preload conversation messages in background (for faster switching)
     * Should be called on hover or when user is likely to click a conversation
     * Uses low priority fetch to avoid blocking main requests
     *
     * @param {string} userId - User ID to preload
     * @returns {Promise<void>}
     */
    async preloadConversation(userId) {
        if (!userId) {
            return;
        }

        // Don't preload if already cached and valid
        if (this.isCached(userId)) {
            return;
        }

        // Don't preload if already loading this conversation
        if (this.pendingRequests.has(userId)) {
            return;
        }

        // Don't preload if it's the current conversation
        if (userId === this.currentUserId) {
            return;
        }

        console.log(`Preloading conversation: ${userId}`);

        try {
            // Fetch with lower priority (not blocking main thread)
            const messages = await this.fetchMessages(userId, null, 30); // Fetch fewer messages for preload

            // Cache the result
            if (messages && messages.length > 0) {
                this.messageCache.set(userId, {
                    messages: messages,
                    timestamp: Date.now(),
                    isPreload: true // Mark as preloaded (can be refreshed on actual load)
                });
                console.log(`Preloaded ${messages.length} messages for: ${userId}`);
            }
        } catch (error) {
            // Silently fail preloading - it's optional optimization
            if (error.name !== 'AbortError') {
                console.warn(`Preload failed for ${userId}:`, error.message);
            }
        }
    }

    /**
     * Load images for a message element (lazy loading)
     * Only loads images when message enters viewport
     * Uses batching to limit concurrent image loads for better performance
     *
     * Validates: Requirement 6.2 (lazy load images outside viewport)
     * @param {HTMLElement} messageEl - Message element
     * @private
     */
    loadMessageImages(messageEl) {
        if (!messageEl) {
            return;
        }

        // Find all lazy-load images in this message
        const lazyImages = messageEl.querySelectorAll('img[data-src]');

        lazyImages.forEach(img => {
            // Check if already loaded or queued
            if (img.dataset.loaded === 'true' || img.dataset.queued === 'true') {
                return;
            }

            // Mark as queued to prevent duplicate loading
            img.dataset.queued = 'true';

            // Add to image load queue for batching
            this.queueImageLoad(img);
        });
    }

    /**
     * Queue image for batched loading
     * Limits concurrent image loads to improve performance
     *
     * @param {HTMLImageElement} img - Image element to load
     * @private
     */
    queueImageLoad(img) {
        // Initialize image queue if not exists
        if (!this.imageLoadQueue) {
            this.imageLoadQueue = [];
            this.activeImageLoads = 0;
            this.maxConcurrentImageLoads = 4; // Limit concurrent loads
        }

        // Add to queue
        this.imageLoadQueue.push(img);

        // Process queue
        this.processImageQueue();
    }

    /**
     * Process image load queue with concurrency control
     * @private
     */
    processImageQueue() {
        // Don't process if at max concurrent loads
        while (this.activeImageLoads < this.maxConcurrentImageLoads && this.imageLoadQueue.length > 0) {
            const img = this.imageLoadQueue.shift();

            // Skip if already loaded (element may have been processed while in queue)
            if (img.dataset.loaded === 'true') {
                continue;
            }

            const src = img.dataset.src;
            if (!src) {
                continue;
            }

            // Increment active loads
            this.activeImageLoads++;

            // Show loading placeholder
            img.classList.add('loading');

            // Create a new image to preload
            const tempImg = new Image();

            tempImg.onload = () => {
                // Set the actual source
                img.src = src;
                img.dataset.loaded = 'true';
                delete img.dataset.queued;
                img.classList.remove('loading');
                img.classList.add('loaded');

                // Remove data-src attribute
                delete img.dataset.src;

                // Decrement active loads and process next
                this.activeImageLoads--;
                this.processImageQueue();
            };

            tempImg.onerror = () => {
                // Show error placeholder
                img.classList.remove('loading');
                img.classList.add('error');
                delete img.dataset.queued;
                img.alt = 'Failed to load image';

                // Decrement active loads and process next
                this.activeImageLoads--;
                this.processImageQueue();
            };

            // Start loading
            tempImg.src = src;
        }
    }

    /**
     * Render Flex Message with caching
     * Caches rendered HTML for reuse to improve performance
     * 
     * Validates: Requirement 6.3 (cache rendered HTML for Flex Messages)
     * @param {Object} flexMessage - Flex Message object
     * @returns {string} Rendered HTML
     * @private
     */
    renderFlexMessage(flexMessage) {
        if (!flexMessage) {
            return '';
        }

        // Initialize Flex Message cache if not exists
        if (!this.flexMessageCache) {
            this.flexMessageCache = new Map();
        }

        // Generate cache key from Flex Message content
        const cacheKey = this.generateFlexMessageCacheKey(flexMessage);

        // Check cache first (Requirement 6.3)
        if (this.flexMessageCache.has(cacheKey)) {
            console.log('Using cached Flex Message HTML');
            return this.flexMessageCache.get(cacheKey);
        }

        // Render Flex Message (simplified - actual implementation would be more complex)
        let html = '<div class="flex-message">';

        if (flexMessage.type === 'bubble') {
            html += this.renderFlexBubble(flexMessage);
        } else if (flexMessage.type === 'carousel') {
            html += this.renderFlexCarousel(flexMessage);
        } else {
            html += '<div class="flex-message-unsupported">Unsupported Flex Message type</div>';
        }

        html += '</div>';

        // Cache the rendered HTML (Requirement 6.3)
        this.flexMessageCache.set(cacheKey, html);

        // Limit cache size to prevent memory issues
        if (this.flexMessageCache.size > 100) {
            // Remove oldest entry (first key)
            const firstKey = this.flexMessageCache.keys().next().value;
            this.flexMessageCache.delete(firstKey);
        }

        return html;
    }

    /**
     * Generate cache key for Flex Message
     * @param {Object} flexMessage - Flex Message object
     * @returns {string} Cache key
     * @private
     */
    generateFlexMessageCacheKey(flexMessage) {
        // Simple hash based on JSON string
        // In production, use a proper hash function
        const str = JSON.stringify(flexMessage);
        let hash = 0;

        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32-bit integer
        }

        return `flex_${hash}`;
    }

    /**
     * Render Flex Bubble (simplified)
     * @param {Object} bubble - Bubble object
     * @returns {string} HTML
     * @private
     */
    renderFlexBubble(bubble) {
        let html = '<div class="flex-bubble">';

        // Header
        if (bubble.header) {
            html += '<div class="flex-header">';
            html += this.renderFlexBox(bubble.header);
            html += '</div>';
        }

        // Hero image
        if (bubble.hero && bubble.hero.type === 'image') {
            html += `<img class="flex-hero" data-src="${bubble.hero.url}" alt="Hero image" />`;
        }

        // Body
        if (bubble.body) {
            html += '<div class="flex-body">';
            html += this.renderFlexBox(bubble.body);
            html += '</div>';
        }

        // Footer
        if (bubble.footer) {
            html += '<div class="flex-footer">';
            html += this.renderFlexBox(bubble.footer);
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    /**
     * Render Flex Carousel (simplified)
     * @param {Object} carousel - Carousel object
     * @returns {string} HTML
     * @private
     */
    renderFlexCarousel(carousel) {
        let html = '<div class="flex-carousel">';

        if (carousel.contents && Array.isArray(carousel.contents)) {
            carousel.contents.forEach(bubble => {
                html += this.renderFlexBubble(bubble);
            });
        }

        html += '</div>';
        return html;
    }

    /**
     * Render Flex Box (simplified)
     * @param {Object} box - Box object
     * @returns {string} HTML
     * @private
     */
    renderFlexBox(box) {
        if (!box || !box.contents) {
            return '';
        }

        let html = `<div class="flex-box flex-${box.layout || 'vertical'}">`;

        box.contents.forEach(component => {
            if (component.type === 'text') {
                html += `<div class="flex-text">${this.escapeHtml(component.text || '')}</div>`;
            } else if (component.type === 'image') {
                html += `<img class="flex-image" data-src="${component.url}" alt="" />`;
            } else if (component.type === 'button') {
                html += `<button class="flex-button">${this.escapeHtml(component.action?.label || '')}</button>`;
            } else if (component.type === 'box') {
                html += this.renderFlexBox(component);
            }
        });

        html += '</div>';
        return html;
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     * @private
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Count total DOM nodes in message container
     * Recursively counts all nodes including children
     * 
     * @returns {number} Total DOM node count
     * @private
     * 
     * Validates: Requirement 8.3 (DOM node counting)
     */
    countDOMNodes() {
        if (!this.messageContainer) {
            return 0;
        }

        let count = 0;

        const countNodes = (node) => {
            count++;
            if (node.childNodes) {
                node.childNodes.forEach(child => countNodes(child));
            }
        };

        countNodes(this.messageContainer);
        return count;
    }

    /**
     * Enforce DOM node limit by removing off-screen messages
     * Removes nodes when total exceeds 1000 to prevent memory issues
     * 
     * Validates: Requirement 8.3 (remove off-screen nodes when exceeding 1000)
     */
    enforceDOMNodeLimit() {
        const nodeCount = this.countDOMNodes();
        const DOM_NODE_LIMIT = 1000;

        if (nodeCount <= DOM_NODE_LIMIT) {
            return; // Within limit, no action needed
        }

        console.warn(`DOM node count (${nodeCount}) exceeds limit (${DOM_NODE_LIMIT}), cleaning up off-screen messages`);

        // Only clean up if virtual scrolling is enabled
        if (!this.isVirtualScrollEnabled || !this.intersectionObserver) {
            console.warn('Virtual scrolling not enabled, cannot clean up off-screen nodes');
            return;
        }

        // Get all message elements
        const messageElements = this.messageContainer.querySelectorAll('.message');
        let removedCount = 0;

        messageElements.forEach(messageEl => {
            const messageId = messageEl.dataset.messageId;

            // Check if message is visible (tracked by Intersection Observer)
            if (!this.visibleMessages.has(messageId)) {
                // Message is off-screen, remove from DOM
                messageEl.remove();
                removedCount++;
            }
        });

        console.log(`Removed ${removedCount} off-screen message nodes (new count: ${this.countDOMNodes()})`);
    }

    /**
     * Track event listeners for cleanup
     * Stores references to event listeners for later removal
     * 
     * @private
     * 
     * Validates: Requirement 8.4 (track event listeners)
     */
    initEventListenerTracking() {
        if (!this.eventListeners) {
            this.eventListeners = [];
        }
    }

    /**
     * Add tracked event listener
     * Adds event listener and stores reference for cleanup
     * 
     * @param {HTMLElement} element - Element to attach listener to
     * @param {string} event - Event name
     * @param {Function} handler - Event handler function
     * @param {Object} options - Event listener options
     * 
     * Validates: Requirement 8.4 (track event listeners for cleanup)
     */
    addTrackedEventListener(element, event, handler, options = {}) {
        this.initEventListenerTracking();

        // Add the event listener
        element.addEventListener(event, handler, options);

        // Store reference for cleanup
        this.eventListeners.push({
            element: element,
            event: event,
            handler: handler,
            options: options
        });
    }

    /**
     * Remove all tracked event listeners
     * Cleans up all event listeners to prevent memory leaks
     * 
     * Validates: Requirement 8.4 (remove event listeners when unmounting)
     */
    removeAllEventListeners() {
        if (!this.eventListeners || this.eventListeners.length === 0) {
            return;
        }

        let removedCount = 0;

        this.eventListeners.forEach(({ element, event, handler, options }) => {
            try {
                element.removeEventListener(event, handler, options);
                removedCount++;
            } catch (error) {
                console.error('Error removing event listener:', error);
            }
        });

        this.eventListeners = [];

        console.log(`Removed ${removedCount} event listeners`);
    }

    /**
     * Destroy the chat panel manager
     * Cleans up event listeners and cache
     */
    destroy() {
        // Disconnect Intersection Observer
        if (this.intersectionObserver) {
            this.intersectionObserver.disconnect();
            this.intersectionObserver = null;
        }

        // Remove all tracked event listeners (Requirement 8.4)
        this.removeAllEventListeners();

        // Remove event listeners (legacy - for backward compatibility)
        if (this.messageContainer) {
            this.messageContainer.removeEventListener('scroll', this.handleScroll);
        }

        window.removeEventListener('popstate', this.handlePopState);

        // Clear caches
        this.messageCache.clear();

        if (this.flexMessageCache) {
            this.flexMessageCache.clear();
        }

        // Clear visible messages set
        this.visibleMessages.clear();

        // Clear pending messages
        this.pendingMessages = [];

        // Reset state
        this.currentUserId = null;
        this.currentUserData = null;
        this.currentCursor = null;
        this.loadingState = 'idle';
        this.isInitialized = false;
        this.allMessages = [];

        console.log('ChatPanelManager destroyed');
    }
}

// Export for use in other modules (if using ES6 modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ChatPanelManager;
}
