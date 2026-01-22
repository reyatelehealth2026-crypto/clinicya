/**
 * Dashboard Notification Module
 * 
 * Provides real-time message notifications across all pages:
 * - Sound notification when new messages arrive
 * - Browser notification (desktop)
 * - Badge update in sidebar menu
 * - Toast notification
 * 
 * Usage: Auto-initializes on page load
 */

const DashboardNotification = (function () {
    const config = {
        pollInterval: 5000,
        apiEndpoint: '/api/inbox-realtime.php',
        enableSound: true,
        enableBrowserNotification: true,
        soundVolume: 0.5
    };

    let state = {
        isRunning: false,
        pollTimer: null,
        lastUnreadCount: 0,
        lineAccountId: null,
        audioContext: null,
        hasUserInteracted: false
    };

    /**
     * Initialize notification system
     */
    function init(options = {}) {
        // Get line_account_id from page or session
        state.lineAccountId = options.lineAccountId ||
            document.querySelector('meta[name="line-account-id"]')?.content ||
            null;

        // Request notification permission
        if (config.enableBrowserNotification && 'Notification' in window) {
            if (Notification.permission === 'default') {
                Notification.requestPermission();
            }
        }

        // Track user interaction for audio
        document.addEventListener('click', () => {
            state.hasUserInteracted = true;
            initAudioContext();
        }, { once: true });

        // Start polling
        start();
    }

    /**
     * Initialize audio context (requires user interaction)
     */
    function initAudioContext() {
        if (state.audioContext) return;

        try {
            state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) {
            console.error('[DashboardNotification] Audio init failed:', e);
        }
    }

    /**
     * Play notification sound
     */
    function playSound() {
        if (!config.enableSound || !state.audioContext || !state.hasUserInteracted) return;

        try {
            const oscillator = state.audioContext.createOscillator();
            const gainNode = state.audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(state.audioContext.destination);

            // Two-tone notification sound
            oscillator.frequency.setValueAtTime(880, state.audioContext.currentTime);
            oscillator.frequency.setValueAtTime(1100, state.audioContext.currentTime + 0.1);
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(config.soundVolume, state.audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, state.audioContext.currentTime + 0.3);

            oscillator.start(state.audioContext.currentTime);
            oscillator.stop(state.audioContext.currentTime + 0.3);
        } catch (e) {
            console.error('[DashboardNotification] Sound play failed:', e);
        }
    }

    /**
     * Show browser notification
     */
    function showBrowserNotification(count, senderName) {
        if (!config.enableBrowserNotification) return;
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        if (!document.hidden) return; // Only show when tab is not active

        const title = senderName ? `ข้อความจาก ${senderName}` : 'ข้อความใหม่';
        const body = count > 1 ? `คุณมี ${count} ข้อความใหม่` : 'คุณมีข้อความใหม่ 1 ข้อความ';

        const notification = new Notification(title, {
            body: body,
            icon: '/icon.svg',
            tag: 'dashboard-message',
            requireInteraction: false
        });

        notification.onclick = function () {
            window.focus();
            window.location.href = 'inbox-v2.php';
            notification.close();
        };

        // Auto close after 5 seconds
        setTimeout(() => notification.close(), 5000);
    }

    /**
     * Update sidebar badge
     */
    function updateSidebarBadge(count) {
        // Find inbox menu item in sidebar
        const inboxLinks = document.querySelectorAll('a[href*="inbox"]');

        inboxLinks.forEach(link => {
            let badge = link.querySelector('.inbox-badge');

            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'inbox-badge ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full font-bold';
                    link.appendChild(badge);
                }
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-block';
            } else if (badge) {
                badge.style.display = 'none';
            }
        });

        // Update page title if on dashboard
        if (window.location.pathname.includes('dashboard')) {
            const baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
            document.title = count > 0 ? `(${count}) ${baseTitle}` : baseTitle;
        }
    }

    /**
     * Show toast notification
     */
    function showToast(message) {
        if (typeof showToast === 'function' && window.showToast !== showToast) {
            window.showToast(message, 'info');
            return;
        }

        // Fallback toast
        const toast = document.createElement('div');
        toast.className = 'fixed top-20 right-4 px-5 py-3 rounded-xl text-white bg-blue-500 shadow-xl z-50 flex items-center gap-3';
        toast.style.animation = 'slideIn 0.3s ease-out';
        toast.innerHTML = `<i class="fas fa-envelope"></i><span>${message}</span>`;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-20px)';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    /**
     * Start polling
     */
    function start() {
        if (state.isRunning) return;
        state.isRunning = true;
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
     * Main polling function
     */
    async function poll() {
        if (!state.isRunning) return;

        try {
            const params = new URLSearchParams({
                action: 'unread_count'
            });

            if (state.lineAccountId) {
                params.append('line_account_id', state.lineAccountId);
            }

            const response = await fetch(`${config.apiEndpoint}?${params}`);
            const data = await response.json();

            if (data.success) {
                const newCount = data.unread_count || 0;

                // Check if there are new messages
                if (newCount > state.lastUnreadCount && state.lastUnreadCount >= 0) {
                    const diff = newCount - state.lastUnreadCount;

                    // Play sound
                    playSound();

                    // Show browser notification
                    showBrowserNotification(diff);

                    // Show toast (only if not on inbox page)
                    if (!window.location.pathname.includes('inbox')) {
                        showToast(`📬 มี ${diff} ข้อความใหม่`);
                    }
                }

                // Update badge
                updateSidebarBadge(newCount);

                // Save count
                state.lastUnreadCount = newCount;
            }
        } catch (e) {
            console.error('[DashboardNotification] Poll error:', e);
        }

        // Schedule next poll
        if (state.isRunning) {
            state.pollTimer = setTimeout(poll, config.pollInterval);
        }
    }

    // Public API
    return {
        init,
        start,
        stop,
        playSound,
        getUnreadCount: () => state.lastUnreadCount
    };
})();

// Auto-initialize when DOM is ready (skip on inbox pages)
document.addEventListener('DOMContentLoaded', function () {
    // Skip on inbox pages (they have their own realtime)
    if (window.location.pathname.includes('inbox')) return;

    // Skip on LIFF pages
    if (window.location.pathname.includes('liff')) return;

    // Initialize
    DashboardNotification.init();
});

// Add CSS for toast animation
(function () {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(100px); }
            to { opacity: 1; transform: translateX(0); }
        }
    `;
    document.head.appendChild(style);
})();
