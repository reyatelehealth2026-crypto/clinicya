/**
 * ConversationListManager - Manages the conversation list UI
 * 
 * Handles conversation array management, search, filtering, virtual scrolling,
 * and auto-bumping of conversations when new messages arrive.
 * 
 * Features:
 * - Virtual scrolling for lists with 100+ items (Requirement 5.1)
 * - Auto-bumping conversations to top on new messages (Requirement 2.1)
 * - Debounced search (300ms) to reduce API calls (Requirement 5.4)
 * - Smooth animations for conversation movements (Requirement 2.3)
 * - Pinned conversation support (Requirement 2.5)
 * - Efficient sorting and filtering
 * 
 * @class ConversationListManager
 * @version 1.0.0
 */
class ConversationListManager {
    /**
     * Create a ConversationListManager
     * @param {Object} options - Configuration options
     * @param {HTMLElement} options.container - Container element for conversation list
     * @param {number} options.itemHeight - Height of each conversation item in pixels (default: 80)
     * @param {number} options.bufferSize - Number of buffer items for virtual scrolling (default: 10)
     * @param {number} options.searchDebounceMs - Debounce delay for search in milliseconds (default: 300)
     */
    constructor(options = {}) {
        // Validate required options
        if (!options.container) {
            throw new Error('Container element is required');
        }
        
        // Core properties
        this.container = options.container;
        this.conversations = [];
        this.filteredConversations = [];
        this.sortOrder = 'recent'; // 'recent', 'unread', 'assigned'
        
        // Virtual scrolling properties
        this.itemHeight = options.itemHeight || 80;
        this.bufferSize = options.bufferSize || 10;
        this.virtualScroller = null;
        this.visibleRange = { start: 0, end: 0 };
        
        // Search and filter properties
        this.searchDebounceMs = options.searchDebounceMs || 300;
        this.searchDebouncer = null;
        this.pendingSearchRequest = null; // AbortController for cancelling requests
        this.currentSearchQuery = '';
        this.currentFilters = {};
        
        // State tracking
        this.isInitialized = false;
        this.isVirtualScrollEnabled = false;
        
        // Callbacks
        this.onConversationClick = options.onConversationClick || null;
        this.onLoadMore = options.onLoadMore || null;
        this.onSearch = options.onSearch || null; // Server-side search callback
    }
    
    /**
     * Initialize the conversation list manager
     * Sets up event listeners and initial rendering
     */
    initialize() {
        if (this.isInitialized) {
            console.warn('ConversationListManager already initialized');
            return;
        }
        
        // Set up container
        this.container.style.position = 'relative';
        this.container.style.overflow = 'auto';
        
        this.isInitialized = true;
        console.log('ConversationListManager initialized');
    }
    
    /**
     * Set the conversations array
     * @param {Array<Object>} conversations - Array of conversation objects
     * @param {boolean} append - Whether to append to existing conversations (default: false)
     */
    setConversations(conversations, append = false) {
        if (!Array.isArray(conversations)) {
            throw new Error('Conversations must be an array');
        }
        
        if (append) {
            this.conversations = [...this.conversations, ...conversations];
        } else {
            this.conversations = [...conversations];
        }
        
        // Apply current filters and search
        this.applyFiltersAndSearch();
        
        // Check if virtual scrolling should be enabled (Requirement 5.1)
        this.checkVirtualScrolling();
        
        // Render the list
        this.render();
    }
    
    /**
     * Get all conversations
     * @returns {Array<Object>} Array of conversation objects
     */
    getConversations() {
        return [...this.conversations];
    }
    
    /**
     * Get filtered conversations
     * @returns {Array<Object>} Array of filtered conversation objects
     */
    getFilteredConversations() {
        return [...this.filteredConversations];
    }
    
    /**
     * Bump conversation to top when new message arrives
     * Implements smooth animation and handles pinned conversations
     * 
     * @param {string} userId - User ID of conversation to bump
     * @param {Object} message - New message object
     * @returns {boolean} True if conversation was bumped, false if not found
     * 
     * Validates: Requirements 2.1, 2.2, 2.3, 2.5
     */
    bumpConversation(userId, message) {
        if (!userId) {
            console.error('userId is required for bumpConversation');
            return false;
        }
        
        // Find the conversation
        const index = this.conversations.findIndex(conv => conv.id === userId || conv.user_id === userId);
        
        if (index === -1) {
            console.warn(`Conversation not found for userId: ${userId}`);
            return false;
        }
        
        const conversation = this.conversations[index];
        
        // Check if conversation is pinned (Requirement 2.5)
        if (conversation.is_pinned) {
            // Update conversation data but don't move it
            this.updateConversationData(userId, {
                last_message: message.content || message.text || '',
                last_message_at: message.created_at || new Date().toISOString(),
                unread_count: (conversation.unread_count || 0) + 1
            });
            return true;
        }
        
        // Find the correct position (after pinned conversations)
        let insertIndex = 0;
        while (insertIndex < this.conversations.length && this.conversations[insertIndex].is_pinned) {
            insertIndex++;
        }
        
        // Check if conversation is already at the top position (Requirement 2.3)
        // Don't animate if it's already where it should be
        if (index === insertIndex) {
            // Just update the data without animation
            this.updateConversationData(userId, {
                last_message: message.content || message.text || '',
                last_message_at: message.created_at || new Date().toISOString(),
                unread_count: (conversation.unread_count || 0) + 1
            });
            return true;
        }
        
        // Get the DOM element before moving (for animation)
        const conversationElement = this.getConversationElement(userId);
        
        // Remove from current position
        this.conversations.splice(index, 1);
        
        // Update conversation with new message data
        conversation.last_message = message.content || message.text || '';
        conversation.last_message_at = message.created_at || new Date().toISOString();
        conversation.unread_count = (conversation.unread_count || 0) + 1;
        
        // Insert at the top of non-pinned conversations (Requirement 2.1)
        this.conversations.splice(insertIndex, 0, conversation);
        
        // Re-apply filters and search
        this.applyFiltersAndSearch();
        
        // Render with animation (Requirement 2.3)
        this.renderWithBumpAnimation(userId, conversationElement);
        
        return true;
    }
    
    /**
     * Get the DOM element for a conversation
     * @param {string} userId - User ID of conversation
     * @returns {HTMLElement|null} The conversation element or null if not found
     * @private
     */
    getConversationElement(userId) {
        if (!this.container) return null;
        
        // Find the conversation element by data-user-id attribute
        return this.container.querySelector(`[data-user-id="${userId}"]`);
    }
    
    /**
     * Render conversation list with bump animation
     * Animates the conversation sliding to the top smoothly
     * 
     * @param {string} bumpedUserId - User ID of conversation being bumped
     * @param {HTMLElement|null} oldElement - The old DOM element (before bump)
     * @private
     * 
     * Validates: Requirement 2.3 (smooth animation)
     */
    renderWithBumpAnimation(bumpedUserId, oldElement) {
        if (!this.isInitialized) {
            console.warn('ConversationListManager not initialized');
            return;
        }
        
        // If no old element, just render normally
        if (!oldElement) {
            this.render(false);
            return;
        }
        
        // Get the old position before re-rendering
        const oldRect = oldElement.getBoundingClientRect();
        const oldScrollTop = this.container.scrollTop;
        
        // Render the list (conversation is now in new position)
        this.render(false);
        
        // Get the new element and its position
        const newElement = this.getConversationElement(bumpedUserId);
        
        if (!newElement) {
            console.warn('Could not find conversation element after render');
            return;
        }
        
        const newRect = newElement.getBoundingClientRect();
        
        // Calculate the distance to animate
        const deltaY = oldRect.top - newRect.top;
        
        // Don't animate if the distance is too small (already at top)
        if (Math.abs(deltaY) < 5) {
            return;
        }
        
        // Use FLIP animation technique for smooth performance
        // First: Set initial position (where it was)
        newElement.style.transform = `translateY(${deltaY}px)`;
        newElement.style.transition = 'none';
        
        // Add highlight class for visual feedback
        newElement.classList.add('conversation-bumping');
        
        // Force reflow to ensure the transform is applied
        newElement.offsetHeight;
        
        // Last: Animate to final position (where it should be)
        newElement.style.transition = 'transform 0.4s cubic-bezier(0.4, 0.0, 0.2, 1), background-color 0.4s ease';
        newElement.style.transform = 'translateY(0)';
        
        // Clean up after animation completes
        const cleanup = () => {
            newElement.style.transform = '';
            newElement.style.transition = '';
            newElement.classList.remove('conversation-bumping');
            newElement.removeEventListener('transitionend', cleanup);
        };
        
        newElement.addEventListener('transitionend', cleanup);
        
        // Fallback cleanup in case transitionend doesn't fire
        setTimeout(cleanup, 500);
    }
    
    /**
     * Update conversation data without changing position
     * @param {string} userId - User ID of conversation
     * @param {Object} updates - Object with fields to update
     * @returns {boolean} True if conversation was updated, false if not found
     */
    updateConversationData(userId, updates) {
        const conversation = this.conversations.find(conv => conv.id === userId || conv.user_id === userId);
        
        if (!conversation) {
            return false;
        }
        
        // Apply updates
        Object.assign(conversation, updates);
        
        // Re-render
        this.render();
        
        return true;
    }
    
    /**
     * Search conversations with debouncing
     * Searches across name, message content, and tags
     * Cancels pending requests when new search is initiated
     * 
     * @param {string} query - Search query
     * @param {number} delay - Debounce delay in ms (default: uses constructor value)
     * 
     * Validates: Requirement 5.4 (300ms debounce)
     * Validates: Requirement 11.7 (cancel pending requests)
     */
    searchConversations(query, delay = null) {
        // Use provided delay or default from constructor
        const debounceDelay = delay !== null ? delay : this.searchDebounceMs;
        
        // Clear existing debouncer (Requirement 11.7: cancel pending)
        if (this.searchDebouncer) {
            clearTimeout(this.searchDebouncer);
            this.searchDebouncer = null;
        }
        
        // Cancel any pending API requests if they exist
        if (this.pendingSearchRequest) {
            this.pendingSearchRequest.abort();
            this.pendingSearchRequest = null;
        }
        
        // Store query
        this.currentSearchQuery = query;
        
        // Debounce the search (Requirement 5.4: 300ms debounce)
        this.searchDebouncer = setTimeout(() => {
            this.applyFiltersAndSearch();
            this.render();
            
            // If there's a callback for server-side search, call it
            if (this.onSearch) {
                this.performServerSearch(query);
            }
        }, debounceDelay);
    }
    
    /**
     * Perform server-side search with AbortController
     * Allows cancellation of pending requests
     * @param {string} query - Search query
     * @private
     * 
     * Validates: Requirement 11.7 (cancel pending requests)
     */
    async performServerSearch(query) {
        // Create AbortController for this request
        const controller = new AbortController();
        this.pendingSearchRequest = controller;
        
        try {
            // Call the search callback with abort signal
            if (this.onSearch) {
                await this.onSearch(query, controller.signal);
            }
        } catch (error) {
            // Ignore abort errors (expected when cancelling)
            if (error.name !== 'AbortError') {
                console.error('Search error:', error);
            }
        } finally {
            // Clear pending request if it's still this one
            if (this.pendingSearchRequest === controller) {
                this.pendingSearchRequest = null;
            }
        }
    }
    
    /**
     * Apply filters to conversations
     * @param {Object} filters - Filter object
     * @param {string} filters.status - Filter by status ('all', 'unread', 'assigned')
     * @param {string} filters.assignedTo - Filter by assigned admin ID
     * @param {Array<string>} filters.tags - Filter by tags
     */
    applyFilters(filters) {
        this.currentFilters = { ...filters };
        this.applyFiltersAndSearch();
        this.render();
    }
    
    /**
     * Clear all filters and search
     */
    clearFilters() {
        this.currentFilters = {};
        this.currentSearchQuery = '';
        this.applyFiltersAndSearch();
        this.render();
    }
    
    /**
     * Apply current filters and search query to conversations
     * Updates filteredConversations array
     * @private
     */
    applyFiltersAndSearch() {
        let filtered = [...this.conversations];
        
        // Apply search query
        if (this.currentSearchQuery && this.currentSearchQuery.trim() !== '') {
            const query = this.currentSearchQuery.toLowerCase().trim();
            filtered = filtered.filter(conv => {
                // Search in display name
                if (conv.display_name && conv.display_name.toLowerCase().includes(query)) {
                    return true;
                }
                
                // Search in last message
                if (conv.last_message && conv.last_message.toLowerCase().includes(query)) {
                    return true;
                }
                
                // Search in tags
                if (conv.tags && Array.isArray(conv.tags)) {
                    return conv.tags.some(tag => tag.toLowerCase().includes(query));
                }
                
                return false;
            });
        }
        
        // Apply status filter
        if (this.currentFilters.status) {
            switch (this.currentFilters.status) {
                case 'unread':
                    filtered = filtered.filter(conv => conv.unread_count > 0);
                    break;
                case 'assigned':
                    filtered = filtered.filter(conv => conv.assigned_to);
                    break;
                // 'all' or default: no filtering
            }
        }
        
        // Apply assigned_to filter
        if (this.currentFilters.assignedTo) {
            filtered = filtered.filter(conv => conv.assigned_to === this.currentFilters.assignedTo);
        }
        
        // Apply tags filter
        if (this.currentFilters.tags && Array.isArray(this.currentFilters.tags) && this.currentFilters.tags.length > 0) {
            filtered = filtered.filter(conv => {
                if (!conv.tags || !Array.isArray(conv.tags)) {
                    return false;
                }
                // Check if conversation has any of the filter tags
                return this.currentFilters.tags.some(filterTag => conv.tags.includes(filterTag));
            });
        }
        
        // Sort filtered conversations (Requirement 2.2)
        this.sortConversations(filtered);
        
        this.filteredConversations = filtered;
    }
    
    /**
     * Sort conversations by current sort order
     * Pinned conversations always stay at top
     * Uses efficient stable sort to minimize DOM changes
     * 
     * @param {Array<Object>} conversations - Array to sort (modified in place)
     * @private
     * 
     * Validates: Requirement 2.2 (sort by timestamp)
     * Validates: Requirement 5.5 (efficient sorting)
     */
    sortConversations(conversations) {
        // Use stable sort to maintain relative order when values are equal
        // This reduces unnecessary DOM updates
        conversations.sort((a, b) => {
            // Pinned conversations always at top (Requirement 2.5)
            if (a.is_pinned && !b.is_pinned) return -1;
            if (!a.is_pinned && b.is_pinned) return 1;
            
            // Sort by current sort order
            switch (this.sortOrder) {
                case 'recent':
                    // Sort by last_message_at descending (most recent first)
                    const timeA = new Date(a.last_message_at || 0).getTime();
                    const timeB = new Date(b.last_message_at || 0).getTime();
                    if (timeB !== timeA) {
                        return timeB - timeA;
                    }
                    // Fallback to ID for stable sort
                    return (a.id || a.user_id || '').localeCompare(b.id || b.user_id || '');
                    
                case 'unread':
                    // Sort by unread_count descending
                    const unreadDiff = (b.unread_count || 0) - (a.unread_count || 0);
                    if (unreadDiff !== 0) {
                        return unreadDiff;
                    }
                    // Fallback to recent for stable sort
                    const timeA2 = new Date(a.last_message_at || 0).getTime();
                    const timeB2 = new Date(b.last_message_at || 0).getTime();
                    return timeB2 - timeA2;
                    
                case 'assigned':
                    // Sort by assigned_to (assigned first, then by recent)
                    if (a.assigned_to && !b.assigned_to) return -1;
                    if (!a.assigned_to && b.assigned_to) return 1;
                    // If both assigned or both unassigned, sort by recent
                    const timeA3 = new Date(a.last_message_at || 0).getTime();
                    const timeB3 = new Date(b.last_message_at || 0).getTime();
                    if (timeB3 !== timeA3) {
                        return timeB3 - timeA3;
                    }
                    // Fallback to ID for stable sort
                    return (a.id || a.user_id || '').localeCompare(b.id || b.user_id || '');
                    
                default:
                    return 0;
            }
        });
    }
    
    /**
     * Set sort order and re-sort conversations
     * @param {string} order - Sort order ('recent', 'unread', 'assigned')
     */
    setSortOrder(order) {
        const validOrders = ['recent', 'unread', 'assigned'];
        if (!validOrders.includes(order)) {
            console.error(`Invalid sort order: ${order}. Must be one of: ${validOrders.join(', ')}`);
            return;
        }
        
        this.sortOrder = order;
        this.applyFiltersAndSearch();
        this.render();
    }
    
    /**
     * Check if virtual scrolling should be enabled
     * Enables virtual scrolling when conversation count exceeds 100 (Requirement 5.1)
     * @private
     */
    checkVirtualScrolling() {
        const shouldEnable = this.filteredConversations.length > 100;
        
        if (shouldEnable !== this.isVirtualScrollEnabled) {
            this.isVirtualScrollEnabled = shouldEnable;
            console.log(`Virtual scrolling ${shouldEnable ? 'enabled' : 'disabled'} (${this.filteredConversations.length} conversations)`);
        }
    }
    
    /**
     * Initialize virtual scrolling for conversation list
     * Uses Intersection Observer for efficient rendering
     * 
     * @param {HTMLElement} container - Container element (optional, uses this.container if not provided)
     * @param {number} itemHeight - Height of each conversation item (optional, uses this.itemHeight if not provided)
     * 
     * Validates: Requirement 5.1 (virtual scrolling for 100+ items)
     * Validates: Requirement 5.3 (render only visible items + 10 buffer)
     * Validates: Requirement 11.2 (infinite scroll)
     */
    initVirtualScroll(container = null, itemHeight = null) {
        const scrollContainer = container || this.container;
        const height = itemHeight || this.itemHeight;
        
        // Store for later use
        if (container) this.container = container;
        if (itemHeight) this.itemHeight = itemHeight;
        
        // Clean up existing virtual scroller if any
        if (this.virtualScroller) {
            this.destroyVirtualScroll();
        }
        
        // Create virtual scroller object
        this.virtualScroller = {
            container: scrollContainer,
            itemHeight: height,
            visibleStart: 0,
            visibleEnd: 0,
            renderedItems: new Map(), // Map of userId -> DOM element
            intersectionObserver: null,
            scrollListener: null,
            loadMoreObserver: null,
            lastCursor: null
        };
        
        // Calculate visible range based on container height
        this.updateVisibleRange();
        
        // Set up scroll listener for updating visible range
        this.virtualScroller.scrollListener = this.throttle(() => {
            this.updateVisibleRange();
            this.renderVisibleItems();
        }, 16); // ~60fps
        
        // Use tracked event listener (Requirement 8.4)
        this.addTrackedEventListener(scrollContainer, 'scroll', this.virtualScroller.scrollListener);
        
        // Set up Intersection Observer for infinite scroll (load more)
        this.setupInfiniteScroll();
        
        console.log('Virtual scrolling initialized', {
            container: scrollContainer,
            itemHeight: height,
            bufferSize: this.bufferSize,
            visibleRange: this.visibleRange
        });
    }
    
    /**
     * Destroy virtual scrolling
     * Cleans up observers and listeners
     * @private
     */
    destroyVirtualScroll() {
        if (!this.virtualScroller) return;
        
        // Remove scroll listener
        if (this.virtualScroller.scrollListener) {
            this.container.removeEventListener('scroll', this.virtualScroller.scrollListener);
        }
        
        // Disconnect Intersection Observer
        if (this.virtualScroller.intersectionObserver) {
            this.virtualScroller.intersectionObserver.disconnect();
        }
        
        // Disconnect load more observer
        if (this.virtualScroller.loadMoreObserver) {
            this.virtualScroller.loadMoreObserver.disconnect();
        }
        
        // Clear rendered items
        this.virtualScroller.renderedItems.clear();
        
        this.virtualScroller = null;
    }
    
    /**
     * Set up Intersection Observer for infinite scroll
     * Detects when user scrolls near the bottom and loads more conversations
     * @private
     * 
     * Validates: Requirement 11.2 (infinite scroll)
     */
    setupInfiniteScroll() {
        if (!this.virtualScroller) return;
        
        // Create a sentinel element at the bottom
        const sentinel = document.createElement('div');
        sentinel.className = 'conversation-list-sentinel';
        sentinel.style.height = '1px';
        sentinel.style.visibility = 'hidden';
        
        // Append sentinel to container
        this.container.appendChild(sentinel);
        
        // Create Intersection Observer for the sentinel
        this.virtualScroller.loadMoreObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && this.onLoadMore) {
                        // User scrolled near bottom, load more conversations
                        console.log('Loading more conversations (infinite scroll)');
                        this.loadMore(this.virtualScroller.lastCursor);
                    }
                });
            },
            {
                root: this.container,
                rootMargin: '200px', // Trigger 200px before reaching bottom
                threshold: 0
            }
        );
        
        this.virtualScroller.loadMoreObserver.observe(sentinel);
    }
    
    /**
     * Update visible range based on scroll position
     * Calculates which items should be visible + buffer
     * @private
     * 
     * Validates: Requirement 5.3 (visible items + 10 buffer)
     */
    updateVisibleRange() {
        if (!this.virtualScroller || !this.isVirtualScrollEnabled) return;
        
        const scrollTop = this.container.scrollTop;
        const containerHeight = this.container.clientHeight;
        
        // Calculate visible range
        const startIndex = Math.floor(scrollTop / this.itemHeight);
        const endIndex = Math.ceil((scrollTop + containerHeight) / this.itemHeight);
        
        // Add buffer (Requirement 5.3: 10 buffer items)
        const bufferedStart = Math.max(0, startIndex - this.bufferSize);
        const bufferedEnd = Math.min(
            this.filteredConversations.length,
            endIndex + this.bufferSize
        );
        
        // Update visible range
        this.visibleRange = {
            start: bufferedStart,
            end: bufferedEnd
        };
    }
    
    /**
     * Render only visible items (virtual scrolling)
     * Only renders items within visible range + buffer
     * Uses incremental updates - only modifies changed items
     * @private
     * 
     * Validates: Requirement 5.3 (render only visible items + buffer)
     * Validates: Requirement 5.5 (incremental DOM updates)
     */
    renderVisibleItems() {
        if (!this.virtualScroller || !this.isVirtualScrollEnabled) {
            // Fall back to rendering all items
            this.renderAllItems();
            return;
        }
        
        const { start, end } = this.visibleRange;
        const visibleConversations = this.filteredConversations.slice(start, end);
        
        // Get currently rendered user IDs
        const currentlyRendered = new Set(this.virtualScroller.renderedItems.keys());
        const shouldBeRendered = new Set(
            visibleConversations.map(conv => conv.id || conv.user_id)
        );
        
        // Remove items that are no longer visible (Requirement 5.5: incremental updates)
        currentlyRendered.forEach(userId => {
            if (!shouldBeRendered.has(userId)) {
                const element = this.virtualScroller.renderedItems.get(userId);
                if (element && element.parentNode) {
                    element.parentNode.removeChild(element);
                }
                this.virtualScroller.renderedItems.delete(userId);
            }
        });
        
        // Render visible items (incremental update - only create/update what's needed)
        visibleConversations.forEach((conversation, index) => {
            const userId = conversation.id || conversation.user_id;
            const absoluteIndex = start + index;
            
            // Check if already rendered
            if (this.virtualScroller.renderedItems.has(userId)) {
                // Update existing element if needed (Requirement 5.5: don't re-render unchanged)
                const element = this.virtualScroller.renderedItems.get(userId);
                
                // Only update if data has changed
                if (this.hasConversationChanged(element, conversation)) {
                    this.updateConversationElement(userId, conversation);
                }
                
                // Update position if index changed
                const currentIndex = parseInt(element.dataset.index);
                if (currentIndex !== absoluteIndex) {
                    element.dataset.index = absoluteIndex;
                    element.style.top = `${absoluteIndex * this.itemHeight}px`;
                }
            } else {
                // Create new element
                const element = this.createConversationElement(conversation, absoluteIndex);
                this.virtualScroller.renderedItems.set(userId, element);
                
                // Insert into container at correct position
                this.insertConversationElement(element, absoluteIndex);
            }
        });
        
        console.log(`Rendered ${visibleConversations.length} items (range: ${start}-${end}, total rendered: ${this.virtualScroller.renderedItems.size})`);
    }
    
    /**
     * Check if conversation data has changed
     * Compares current DOM state with new data to avoid unnecessary updates
     * @param {HTMLElement} element - Current DOM element
     * @param {Object} conversation - New conversation data
     * @returns {boolean} True if data has changed
     * @private
     * 
     * Validates: Requirement 5.5 (incremental updates - detect changes)
     */
    hasConversationChanged(element, conversation) {
        // Check unread count
        const unreadBadge = element.querySelector('.unread-count');
        const currentUnread = unreadBadge ? parseInt(unreadBadge.textContent) || 0 : 0;
        const newUnread = conversation.unread_count || 0;
        if (currentUnread !== newUnread) return true;
        
        // Check last message
        const lastMessage = element.querySelector('.last-message');
        const currentMessage = lastMessage ? lastMessage.textContent : '';
        const newMessage = conversation.last_message || '';
        if (currentMessage !== newMessage) return true;
        
        // Check timestamp
        const timestamp = element.querySelector('.timestamp');
        const currentTimestamp = timestamp ? timestamp.textContent : '';
        const newTimestamp = this.formatTimestamp(conversation.last_message_at);
        if (currentTimestamp !== newTimestamp) return true;
        
        // Check pinned status
        const isPinned = element.querySelector('.pin-icon') !== null;
        const shouldBePinned = conversation.is_pinned || false;
        if (isPinned !== shouldBePinned) return true;
        
        // No changes detected
        return false;
    }
    
    /**
     * Throttle function to limit execution rate
     * @param {Function} func - Function to throttle
     * @param {number} limit - Time limit in milliseconds
     * @returns {Function} Throttled function
     * @private
     */
    throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
    
    /**
     * Create a conversation element
     * @param {Object} conversation - Conversation object
     * @param {number} index - Index in the list
     * @returns {HTMLElement} The created element
     * @private
     */
    createConversationElement(conversation, index) {
        const element = document.createElement('div');
        element.className = 'conversation-item';
        element.dataset.userId = conversation.id || conversation.user_id;
        element.dataset.index = index;
        
        // Set position for virtual scrolling
        element.style.position = 'absolute';
        element.style.top = `${index * this.itemHeight}px`;
        element.style.height = `${this.itemHeight}px`;
        element.style.width = '100%';
        
        // Populate content (this will be customized based on actual UI)
        element.innerHTML = this.getConversationHTML(conversation);
        
        // Add click handler
        if (this.onConversationClick) {
            element.addEventListener('click', () => {
                this.onConversationClick(conversation);
            });
        }
        
        return element;
    }
    
    /**
     * Update an existing conversation element
     * @param {string} userId - User ID
     * @param {Object} conversation - Updated conversation data
     * @private
     */
    updateConversationElement(userId, conversation) {
        const element = this.virtualScroller.renderedItems.get(userId);
        if (!element) return;
        
        // Update content without full re-render (incremental update)
        // Only update changed fields
        const unreadBadge = element.querySelector('.unread-count');
        if (unreadBadge) {
            const unreadCount = conversation.unread_count || 0;
            unreadBadge.textContent = unreadCount;
            unreadBadge.style.display = unreadCount > 0 ? 'block' : 'none';
        }
        
        const lastMessage = element.querySelector('.last-message');
        if (lastMessage && conversation.last_message) {
            lastMessage.textContent = conversation.last_message;
        }
        
        const timestamp = element.querySelector('.timestamp');
        if (timestamp && conversation.last_message_at) {
            timestamp.textContent = this.formatTimestamp(conversation.last_message_at);
        }
    }
    
    /**
     * Insert conversation element at correct position in container
     * @param {HTMLElement} element - Element to insert
     * @param {number} index - Index position
     * @private
     */
    insertConversationElement(element, index) {
        // For virtual scrolling, we append to container
        // Position is controlled by CSS (absolute positioning)
        this.container.appendChild(element);
    }
    
    /**
     * Get HTML for a conversation item
     * @param {Object} conversation - Conversation object
     * @returns {string} HTML string
     * @private
     */
    getConversationHTML(conversation) {
        const unreadCount = conversation.unread_count || 0;
        const displayName = conversation.display_name || 'Unknown';
        const lastMessage = conversation.last_message || '';
        const pictureUrl = conversation.picture_url || '/assets/images/default-avatar.png';
        const timestamp = this.formatTimestamp(conversation.last_message_at);
        const isPinned = conversation.is_pinned ? 'pinned' : '';
        
        return `
            <div class="conversation-avatar">
                <img src="${pictureUrl}" alt="${displayName}">
            </div>
            <div class="conversation-content">
                <div class="conversation-header">
                    <span class="conversation-name">${displayName}</span>
                    ${isPinned ? '<i class="fas fa-thumbtack pin-icon"></i>' : ''}
                    <span class="timestamp">${timestamp}</span>
                </div>
                <div class="conversation-preview">
                    <span class="last-message">${lastMessage}</span>
                    ${unreadCount > 0 ? `<span class="unread-count">${unreadCount}</span>` : ''}
                </div>
            </div>
        `;
    }
    
    /**
     * Format timestamp for display
     * @param {string} timestamp - ISO timestamp
     * @returns {string} Formatted timestamp
     * @private
     */
    formatTimestamp(timestamp) {
        if (!timestamp) return '';
        
        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);
        
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        
        return date.toLocaleDateString();
    }
    
    /**
     * Render all items (non-virtual scrolling fallback)
     * @private
     */
    renderAllItems() {
        // Clear container
        this.container.innerHTML = '';
        
        // Render all filtered conversations
        this.filteredConversations.forEach((conversation, index) => {
            const element = this.createConversationElement(conversation, index);
            // For non-virtual scrolling, use relative positioning
            element.style.position = 'relative';
            element.style.top = 'auto';
            this.container.appendChild(element);
        });
    }
    
    /**
     * Load more conversations (infinite scroll)
     * @param {string} cursor - Pagination cursor
     * @returns {Promise<void>}
     */
    async loadMore(cursor) {
        if (!this.onLoadMore) {
            console.warn('onLoadMore callback not set');
            return;
        }
        
        try {
            const newConversations = await this.onLoadMore(cursor);
            
            if (newConversations && Array.isArray(newConversations)) {
                this.setConversations(newConversations, true); // Append
            }
        } catch (error) {
            console.error('Error loading more conversations:', error);
        }
    }
    
    /**
     * Render the conversation list
     * @param {boolean} animate - Whether to animate changes (default: false)
     * @private
     * 
     * Validates: Requirement 5.5 (incremental DOM updates)
     */
    render(animate = false) {
        if (!this.isInitialized) {
            console.warn('ConversationListManager not initialized');
            return;
        }
        
        // Check if virtual scrolling should be enabled
        this.checkVirtualScrolling();
        
        // Set container height for virtual scrolling
        if (this.isVirtualScrollEnabled) {
            const totalHeight = this.filteredConversations.length * this.itemHeight;
            this.container.style.height = `${totalHeight}px`;
            this.container.style.position = 'relative';
            
            // Initialize virtual scroll if not already done
            if (!this.virtualScroller) {
                this.initVirtualScroll();
            }
            
            // Render only visible items
            this.renderVisibleItems();
            
            // Enforce DOM node limit after rendering (Requirement 8.3)
            this.enforceDOMNodeLimit();
        } else {
            // Render all items (for small lists)
            this.renderAllItems();
        }
        
        console.log('Rendered conversation list', {
            total: this.conversations.length,
            filtered: this.filteredConversations.length,
            virtualScrollEnabled: this.isVirtualScrollEnabled,
            visibleRange: this.visibleRange,
            animate: animate
        });
    }
    
    /**
     * Count total DOM nodes in container
     * Recursively counts all nodes including children
     * 
     * @returns {number} Total DOM node count
     * @private
     * 
     * Validates: Requirement 8.3 (DOM node counting)
     */
    countDOMNodes() {
        if (!this.container) {
            return 0;
        }
        
        let count = 0;
        
        const countNodes = (node) => {
            count++;
            if (node.childNodes) {
                node.childNodes.forEach(child => countNodes(child));
            }
        };
        
        countNodes(this.container);
        return count;
    }
    
    /**
     * Enforce DOM node limit by removing off-screen nodes
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
        
        console.warn(`DOM node count (${nodeCount}) exceeds limit (${DOM_NODE_LIMIT}), cleaning up off-screen nodes`);
        
        // Only clean up if virtual scrolling is enabled
        if (!this.isVirtualScrollEnabled || !this.virtualScroller) {
            console.warn('Virtual scrolling not enabled, cannot clean up off-screen nodes');
            return;
        }
        
        // Get visible range
        const { start, end } = this.visibleRange;
        
        // Remove nodes outside visible range + buffer
        const renderedItems = Array.from(this.virtualScroller.renderedItems.entries());
        let removedCount = 0;
        
        renderedItems.forEach(([userId, element]) => {
            const index = parseInt(element.dataset.index);
            
            // Check if outside visible range (with buffer already applied)
            if (index < start || index >= end) {
                // Remove from DOM
                if (element.parentNode) {
                    element.parentNode.removeChild(element);
                    removedCount++;
                }
                
                // Remove from rendered items map
                this.virtualScroller.renderedItems.delete(userId);
            }
        });
        
        console.log(`Removed ${removedCount} off-screen DOM nodes (new count: ${this.countDOMNodes()})`);
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
     * Destroy the conversation list manager
     * Cleans up event listeners and timers
     * 
     * Validates: Requirement 8.4 (event listener cleanup)
     * Validates: Requirement 11.7 (cancel pending requests)
     */
    destroy() {
        // Clear debouncer
        if (this.searchDebouncer) {
            clearTimeout(this.searchDebouncer);
            this.searchDebouncer = null;
        }
        
        // Cancel pending search request (Requirement 11.7)
        if (this.pendingSearchRequest) {
            this.pendingSearchRequest.abort();
            this.pendingSearchRequest = null;
        }
        
        // Remove all tracked event listeners (Requirement 8.4)
        this.removeAllEventListeners();
        
        // Clear virtual scroller
        if (this.virtualScroller) {
            this.destroyVirtualScroll();
        }
        
        // Clear data
        this.conversations = [];
        this.filteredConversations = [];
        
        this.isInitialized = false;
        
        console.log('ConversationListManager destroyed');
    }
}

// Export for use in other modules (if using ES6 modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ConversationListManager;
}
