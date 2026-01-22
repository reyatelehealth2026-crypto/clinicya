/**
 * Inbox Real-time Module
 * 
 * Provides real-time message updates for inbox-v2:
 * - Auto-refresh conversation list when new messages arrive
 * - Move conversations with new messages to top
 * - Auto-load new messages in active chat
 * - Sound notification for new messages
 * 
 * Usage:
 *   InboxRealtime.init({ userId: 123 });
 *   InboxRealtime.start();
 *   InboxRealtime.stop();
 */

const InboxRealtime = (function () {
    // Configuration
    const config = {
        pollInterval: 5000,        // Poll every 5 seconds (optimized for performance)
        apiEndpoint: '/api/inbox-realtime.php',
        enableSound: true,
        enableDesktopNotification: true,
        maxRetries: 3
    };

    // State
    let state = {
        isRunning: false,
        pollTimer: null,
        lastCheck: null,
        currentUserId: null,
        lineAccountId: null,
        lastMessageId: null,
        retryCount: 0,
        onNewMessage: null,
        onConversationUpdate: null,
        onError: null
    };

    // Audio for notification
    let notificationSound = null;

    /**
     * Initialize the real-time module
     * @param {object} options - Configuration options
     */
    function init(options = {}) {
        state.currentUserId = options.userId || null;
        state.lineAccountId = options.lineAccountId || null;
        state.onNewMessage = options.onNewMessage || null;
        state.onConversationUpdate = options.onConversationUpdate || null;
        state.onError = options.onError || null;
        state.lastCheck = new Date().toISOString().slice(0, 19).replace('T', ' ');

        // Override config
        if (options.pollInterval) config.pollInterval = options.pollInterval;
        if (options.enableSound !== undefined) config.enableSound = options.enableSound;

        // Initialize notification sound
        if (config.enableSound) {
            initSound();
        }

        // Request notification permission
        if (config.enableDesktopNotification && 'Notification' in window) {
            Notification.requestPermission();
        }

        console.log('[InboxRealtime] Initialized', { userId: state.currentUserId, lineAccountId: state.lineAccountId });
    }

    /**
     * Initialize notification sound
     */
    function initSound() {
        try {
            // Create a simple beep sound using Web Audio API
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            notificationSound = {
                play: function () {
                    const oscillator = audioContext.createOscillator();
                    const gainNode = audioContext.createGain();

                    oscillator.connect(gainNode);
                    gainNode.connect(audioContext.destination);

                    oscillator.frequency.value = 800;
                    oscillator.type = 'sine';

                    gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);

                    oscillator.start(audioContext.currentTime);
                    oscillator.stop(audioContext.currentTime + 0.3);
                }
            };
        } catch (e) {
            console.warn('[InboxRealtime] Could not initialize sound:', e);
        }
    }

    /**
     * Start polling for updates
     */
    function start() {
        if (state.isRunning) return;

        state.isRunning = true;
        state.retryCount = 0;
        poll();
    }

    /**
     * Stop polling
     */
    function stop() {
        state.isRunning = false;
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
    }

    /**
     * Set current user (when switching conversations)
     * @param {number} userId
     */
    function setCurrentUser(userId) {
        state.currentUserId = userId;
    }

    /**
     * Main polling function
     */
    async function poll() {
        if (!state.isRunning) return;

        try {
            // Save lastCheck before updating (for loading new messages)
            const previousCheck = state.lastCheck;

            const params = new URLSearchParams({
                action: 'check_new',
                last_check: state.lastCheck,
                current_user: state.currentUserId || 0
            });

            // Add line_account_id if available
            if (state.lineAccountId) {
                params.append('line_account_id', state.lineAccountId);
            }

            const response = await fetch(`${config.apiEndpoint}?${params}`);
            const data = await response.json();

            if (data.success) {
                state.retryCount = 0;
                state.lastCheck = data.server_time;

                // Handle new messages
                if (data.has_new) {
                    handleNewMessages(data);
                }

                // Update conversation list
                if (data.conversations && state.onConversationUpdate) {
                    state.onConversationUpdate(data.conversations);
                }

                // Load new messages for current chat (use previousCheck as since)
                if (data.has_new_for_current && state.currentUserId) {
                    loadNewMessagesForCurrentUser(previousCheck);
                }
            }

        } catch (error) {
            console.error('[InboxRealtime] Poll error:', error);
            state.retryCount++;

            if (state.onError) {
                state.onError(error);
            }

            // Stop after max retries
            if (state.retryCount >= config.maxRetries) {
                console.error('[InboxRealtime] Max retries reached, stopping');
                stop();
                return;
            }
        }

        // Schedule next poll
        if (state.isRunning) {
            state.pollTimer = setTimeout(poll, config.pollInterval);
        }
    }

    /**
     * Handle new messages notification
     * @param {object} data
     */
    function handleNewMessages(data) {
        // Play sound
        if (config.enableSound && notificationSound) {
            try {
                notificationSound.play();
            } catch (e) { }
        }

        // Show desktop notification
        if (config.enableDesktopNotification &&
            'Notification' in window &&
            Notification.permission === 'granted' &&
            document.hidden) {

            new Notification('ข้อความใหม่', {
                body: `คุณมี ${data.new_count} ข้อความใหม่`,
                icon: '/icon.svg',
                tag: 'inbox-new-message'
            });
        }

        // Update page title
        updatePageTitle(data.new_count);

        // Callback
        if (state.onNewMessage) {
            state.onNewMessage(data);
        }
    }

    /**
     * Load new messages for current user
     * @param {string} sinceTime - Timestamp to get messages since
     */
    async function loadNewMessagesForCurrentUser(sinceTime) {
        if (!state.currentUserId) return;

        try {
            const params = new URLSearchParams({
                action: 'get_new_messages',
                user_id: state.currentUserId,
                since: sinceTime || state.lastCheck
            });

            const response = await fetch(`${config.apiEndpoint}?${params}`);
            const data = await response.json();

            if (data.success && data.messages.length > 0) {
                // Append messages to chat
                data.messages.forEach(msg => {
                    appendMessageToChat(msg);
                });

                // Scroll to bottom
                scrollChatToBottom();
            }

        } catch (error) {
            console.error('[InboxRealtime] Error loading new messages:', error);
        }
    }

    /**
     * Append a message to the chat container
     * @param {object} msg
     */
    function appendMessageToChat(msg) {
        const chatContainer = document.getElementById('chatBox') ||
            document.getElementById('chatMessages') ||
            document.querySelector('.chat-messages');
        if (!chatContainer) {
            console.warn('[InboxRealtime] Chat container not found');
            return;
        }

        // Check if message already exists (use data-msg-id to match inbox-v2.php)
        if (document.querySelector(`[data-msg-id="${msg.id}"]`)) {
            return;
        }

        const isIncoming = msg.direction === 'incoming';
        const messageHtml = createMessageHtml(msg, isIncoming);

        chatContainer.insertAdjacentHTML('beforeend', messageHtml);
    }

    /**
     * Create HTML for a message
     * @param {object} msg
     * @param {boolean} isIncoming
     * @returns {string}
     */
    function createMessageHtml(msg, isIncoming) {
        const alignClass = isIncoming ? 'justify-start' : 'justify-end';
        const bgClass = isIncoming ? 'bg-white' : 'bg-emerald-500 text-white';
        const roundedClass = isIncoming ? 'rounded-tl-none' : 'rounded-tr-none';

        let content = '';

        switch (msg.type) {
            case 'image':
                content = `<img src="${escapeHtml(msg.content)}" class="max-w-[200px] rounded-lg cursor-pointer" onclick="window.open('${escapeHtml(msg.content)}', '_blank')">`;
                break;
            case 'sticker':
                content = `<div class="text-4xl">😊</div>`;
                break;
            case 'file':
                try {
                    const fileData = JSON.parse(msg.content);
                    content = `<a href="${escapeHtml(fileData.url)}" target="_blank" class="flex items-center gap-2 ${isIncoming ? 'text-blue-600' : 'text-white underline'}">
                        <i class="fas fa-file"></i> ${escapeHtml(fileData.name || 'ไฟล์')}
                    </a>`;
                } catch {
                    content = `<a href="${escapeHtml(msg.content)}" target="_blank" class="${isIncoming ? 'text-blue-600' : 'text-white underline'}">📎 ไฟล์แนบ</a>`;
                }
                break;
            default:
                content = `<div class="whitespace-pre-wrap break-words">${escapeHtml(msg.content)}</div>`;
        }

        return `
            <div class="flex ${alignClass} mb-3 animate-fadeIn" data-msg-id="${msg.id}">
                <div class="max-w-[70%] ${bgClass} rounded-2xl ${roundedClass} px-4 py-2 shadow-sm">
                    ${content}
                    <div class="text-[10px] ${isIncoming ? 'text-gray-400' : 'text-emerald-100'} mt-1 text-right">
                        ${msg.time}
                        ${!isIncoming && msg.sent_by ? `<span class="ml-1">• ${escapeHtml(msg.sent_by.replace('admin:', ''))}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Scroll chat to bottom
     */
    function scrollChatToBottom() {
        const chatContainer = document.getElementById('chatBox') ||
            document.getElementById('chatMessages') ||
            document.querySelector('.chat-messages');
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    }

    /**
     * Update page title with unread count
     * @param {number} count
     */
    function updatePageTitle(count) {
        const baseTitle = 'Inbox V2 - Vibe Selling OS';
        if (count > 0) {
            document.title = `(${count}) ${baseTitle}`;
        } else {
            document.title = baseTitle;
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Update conversation list in UI
     * @param {array} conversations
     */
    function updateConversationList(conversations) {
        const container = document.getElementById('userList') ||
            document.getElementById('conversationList') ||
            document.querySelector('.conversation-list');
        if (!container) return;

        conversations.forEach((conv, index) => {
            const existingItem = container.querySelector(`[data-user-id="${conv.id}"]`);

            if (existingItem) {
                // Update existing item
                updateConversationItem(existingItem, conv);

                // Move to correct position if needed
                const currentIndex = Array.from(container.children).indexOf(existingItem);
                if (currentIndex !== index) {
                    if (index === 0) {
                        container.prepend(existingItem);
                        // Add highlight animation
                        existingItem.classList.add('highlight-new');
                        setTimeout(() => existingItem.classList.remove('highlight-new'), 2000);
                    }
                }
            }
        });
    }

    /**
     * Update a single conversation item
     * @param {HTMLElement} item
     * @param {object} conv
     */
    function updateConversationItem(item, conv) {
        // Update last message with direction prefix
        const lastMsgEl = item.querySelector('.last-msg');
        if (lastMsgEl) {
            const prefix = conv.last_direction === 'outgoing' ? 'คุณ: ' : '';
            lastMsgEl.textContent = prefix + conv.last_message;
        }

        // Update time
        const timeEl = item.querySelector('.last-time');
        if (timeEl) {
            timeEl.textContent = conv.last_time_formatted;
        }

        // Update unread badge
        const badgeEl = item.querySelector('.unread-badge');
        if (badgeEl) {
            if (conv.unread_count > 0) {
                badgeEl.textContent = conv.unread_count > 9 ? '9+' : conv.unread_count;
                badgeEl.style.display = 'flex';
            } else {
                badgeEl.style.display = 'none';
            }
        } else if (conv.unread_count > 0) {
            // Create badge if doesn't exist
            const avatarContainer = item.querySelector('.relative');
            if (avatarContainer) {
                const newBadge = document.createElement('div');
                newBadge.className = 'unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold';
                newBadge.textContent = conv.unread_count > 9 ? '9+' : conv.unread_count;
                avatarContainer.appendChild(newBadge);
            }
        }
    }

    // Public API
    return {
        init,
        start,
        stop,
        setCurrentUser,
        updateConversationList,
        getState: () => ({ ...state }),
        isRunning: () => state.isRunning
    };
})();

// CSS for animations (inject into page)
(function () {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeIn {
            animation: fadeIn 0.3s ease-out;
        }
        @keyframes highlightNew {
            0% { background-color: rgba(16, 185, 129, 0.2); }
            100% { background-color: transparent; }
        }
        .highlight-new {
            animation: highlightNew 2s ease-out;
        }
    `;
    document.head.appendChild(style);
})();
