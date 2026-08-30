/**
 * REYA Admin V4 — Footer & Global Utilities
 * Shared functions, AI Widget, and global helpers
 * @version 4.0.0
 */

(function(global) {
  'use strict';

  // Ensure ReyaAdmin namespace exists (created by admin-v4.js)
  if (!global.ReyaAdmin) {
    global.ReyaAdmin = {};
  }

  /* ============================================================
     Global Utilities
     ============================================================ */

  /**
   * Shows a toast notification (enhanced version with stacking)
   * @param {string} message
   * @param {string} type - success | error | warning | info
   * @param {object} options
   */
  global.showToast = function(message, type = 'success', options = {}) {
    const { duration = 3000, title = '' } = options;

    // Use ReyaAdmin toast if available (V4)
    if (global.ReyaAdmin.toast) {
      global.ReyaAdmin.toast.show(message, { type, duration, title });
      return;
    }

    // Fallback for pages without V4 admin.js
    const colors = {
      success: 'bg-green-500',
      error: 'bg-red-500',
      warning: 'bg-yellow-500',
      info: 'bg-blue-500'
    };
    const icons = {
      success: 'fa-check-circle',
      error: 'fa-times-circle',
      warning: 'fa-exclamation-triangle',
      info: 'fa-info-circle'
    };

    const toast = document.createElement('div');
    toast.className = `fixed top-20 right-4 px-5 py-3 rounded-xl text-white ${colors[type] || colors.success} shadow-xl z-50 flex items-center gap-3 animate-slide-up`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `<i class="fas ${icons[type] || icons.success}"></i><span>${message}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-20px)';
      toast.style.transition = 'all 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  };

  /**
   * Shows a confirmation dialog
   * @param {string} message
   * @returns {boolean}
   */
  global.confirmDelete = function(message = 'คุณแน่ใจหรือไม่ที่จะลบ?') {
    return confirm(message);
  };

  /**
   * Formats a number with thousand separators
   * @param {number|string} num
   * @returns {string}
   */
  global.formatNumber = function(num) {
    const n = parseFloat(num);
    if (isNaN(n)) return '0';
    return n.toLocaleString('th-TH');
  };

  /**
   * Formats a number as currency
   * @param {number|string} amount
   * @param {string} symbol
   * @returns {string}
   */
  global.formatCurrency = function(amount, symbol = '฿') {
    return symbol + formatNumber(parseFloat(amount || 0).toFixed(0));
  };

  /**
   * Copies text to clipboard with toast feedback
   * @param {string} text
   * @param {string} successMsg
   */
  global.copyToClipboard = function(text, successMsg = 'คัดลอกแล้ว!') {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text)
        .then(() => showToast(successMsg, 'success'))
        .catch(() => fallbackCopy(text, successMsg));
    } else {
      fallbackCopy(text, successMsg);
    }
  };

  function fallbackCopy(text, successMsg) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand('copy');
      showToast(successMsg, 'success');
    } catch {
      showToast('ไม่สามารถคัดลอกได้', 'error');
    }

    document.body.removeChild(textarea);
  }

  /**
   * Shows a loading overlay
   * @param {string} message
   */
  global.showLoading = function(message = 'กำลังโหลด...') {
    // Use V4 loading overlay if available
    let overlay = document.getElementById('loadingOverlay');

    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'loadingOverlay';
      overlay.className = 'loading-overlay';
      overlay.innerHTML = `
        <div class="loading-overlay__card">
          <div class="loading-overlay__spinner"></div>
          <span class="loading-overlay__text">${message}</span>
        </div>
      `;
      document.body.appendChild(overlay);
    } else {
      const textEl = overlay.querySelector('.loading-overlay__text');
      if (textEl) textEl.textContent = message;
    }

    // Force reflow
    void overlay.offsetHeight;
    overlay.classList.add('is-active');
  };

  /**
   * Hides the loading overlay
   */
  global.hideLoading = function() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
      overlay.classList.remove('is-active');
      // Remove after transition
      setTimeout(() => {
        if (!overlay.classList.contains('is-active')) {
          overlay.remove();
        }
      }, 300);
    }
  };

  /**
   * Debounce utility
   * @param {Function} func
   * @param {number} wait
   * @returns {Function}
   */
  global.debounce = function(func, wait = 200) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func.apply(this, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  };

  /**
   * Throttle utility
   * @param {Function} func
   * @param {number} limit
   * @returns {Function}
   */
  global.throttle = function(func, limit = 100) {
    let inThrottle;
    return function(...args) {
      if (!inThrottle) {
        func.apply(this, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  };

  /* ============================================================
     AI Chat Widget (V4 Enhanced)
     ============================================================ */

  class AIChatWidget {
    constructor() {
      this.widget = document.getElementById('ai-chat-widget');
      if (!this.widget) return;

      this.toggleBtn = document.getElementById('ai-chat-toggle');
      this.chatWindow = document.getElementById('ai-chat-widget');
      this.closeBtn = document.getElementById('ai-chat-close');
      this.helpBtn = document.getElementById('ai-chat-help');
      this.form = document.getElementById('ai-chat-form');
      this.input = document.getElementById('ai-chat-input');
      this.messages = document.getElementById('ai-chat-messages');
      this.quickBtns = document.querySelectorAll('.ai-quick-btn');
      this.baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';

      this.isOpen = false;
      this.isTyping = false;
      this.messageHistory = [];

      this.init();
    }

    init() {
      this.bindEvents();
      this.loadHistory();
    }

    bindEvents() {
      this.toggleBtn?.addEventListener('click', () => this.toggle());
      this.closeBtn?.addEventListener('click', () => this.close());
      this.helpBtn?.addEventListener('click', () => this.showHelp());
      this.form?.addEventListener('submit', (e) => this.handleSubmit(e));

      this.quickBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          const msg = btn.dataset.msg;
          if (msg) this.send(msg);
        });
      });

      // Close on Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.isOpen) {
          this.close();
        }
      });

      // Close when clicking outside
      document.addEventListener('click', (e) => {
        if (this.isOpen && !this.widget.contains(e.target)) {
          this.close();
        }
      });
    }

    toggle() {
      this.isOpen ? this.close() : this.open();
    }

    open() {
      this.chatWindow?.classList.add('is-open');
      this.isOpen = true;
      this.input?.focus();
      this.scrollToBottom();
    }

    close() {
      this.chatWindow?.classList.remove('is-open');
      this.isOpen = false;
    }

    handleSubmit(e) {
      e.preventDefault();
      const msg = this.input?.value.trim();
      if (!msg || this.isTyping) return;
      this.send(msg);
      if (this.input) this.input.value = '';
    }

    send(message) {
      this.addMessage(message, 'user');
      this.showTyping();
      this.isTyping = true;

      fetch(this.baseUrl + 'api/ai-admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message })
      })
      .then(r => r.json())
      .then(data => {
        this.hideTyping();
        this.isTyping = false;
        if (data.success) {
          this.addMessage(data.response, 'ai');
        } else {
          this.addMessage('❌ ' + (data.error || 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'), 'ai');
        }
      })
      .catch(err => {
        this.hideTyping();
        this.isTyping = false;
        this.addMessage('❌ ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่ออินเทอร์เน็ต', 'ai');
        console.error('AI Chat error:', err);
      });
    }

    addMessage(text, type) {
      const msg = document.createElement('div');
      msg.className = `ai-message ai-message--${type}`;

      // Convert markdown-like formatting
      const html = this.formatMessage(text);

      const avatar = type === 'ai'
        ? '<div class="ai-message__avatar ai-message__avatar--ai"><i class="fas fa-robot"></i></div>'
        : '<div class="ai-message__avatar ai-message__avatar--user"><i class="fas fa-user"></i></div>';

      msg.innerHTML = `
        ${avatar}
        <div class="ai-message__bubble ai-message__bubble--${type}">${html}</div>
      `;

      this.messages?.appendChild(msg);
      this.scrollToBottom();
      this.saveToHistory(text, type);
    }

    formatMessage(text) {
      return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`(.+?)`/g, '<code style="background:rgba(0,0,0,0.05);padding:2px 4px;border-radius:4px;font-family:monospace;">$1</code>')
        .replace(/\n/g, '<br>');
    }

    showTyping() {
      const typing = document.createElement('div');
      typing.id = 'ai-typing-indicator';
      typing.className = 'ai-message';
      typing.innerHTML = `
        <div class="ai-message__avatar ai-message__avatar--ai"><i class="fas fa-robot"></i></div>
        <div class="ai-message__typing">
          <div class="typing-dots">
            <span></span><span></span><span></span>
          </div>
        </div>
      `;
      this.messages?.appendChild(typing);
      this.scrollToBottom();
    }

    hideTyping() {
      document.getElementById('ai-typing-indicator')?.remove();
    }

    scrollToBottom() {
      if (this.messages) {
        this.messages.scrollTop = this.messages.scrollHeight;
      }
    }

    showHelp() {
      const helpText = `📖 **คู่มือการใช้งาน AI Assistant**

━━━━━━━━━━━━━━━━━━━━━━

📊 **ดูข้อมูล/รายงาน:**
• "สรุปวันนี้" - ภาพรวมทั้งหมด
• "ยอดขายวันนี้/สัปดาห์/เดือน"
• "ออเดอร์รอดำเนินการ"
• "สินค้าหมด" / "สินค้าใกล้หมด"
• "ลูกค้าใหม่วันนี้"
• "สลิปรอตรวจ"

🔍 **ค้นหา:**
• "หาลูกค้า [ชื่อ]"
• "หาออเดอร์ #[เลข]"
• "หาสินค้า [ชื่อ]"

🚀 **Actions:**
• "ยืนยันออเดอร์ #TXN123"
• "อนุมัติสลิป #TXN123"
• "ปฏิเสธสลิป #TXN123"
• "ยกเลิกออเดอร์ #TXN123"

🚨 **แจ้งเตือน:**
• "แจ้งเตือน" - ดูปัญหาทั้งหมด

🏆 **อันดับ:**
• "top ลูกค้า"
• "สินค้าขายดี"

🖥️ **ระบบ:**
• "สถานะระบบ"
• "เปรียบเทียบสัปดาห์"`;

      this.addMessage(helpText, 'ai');
    }

    saveToHistory(text, type) {
      this.messageHistory.push({ text, type, time: Date.now() });
      // Keep last 50 messages
      if (this.messageHistory.length > 50) {
        this.messageHistory = this.messageHistory.slice(-50);
      }
      try {
        const key = 'reya_ai_chat_history_' + (document.body.dataset.userId || 'guest');
        localStorage.setItem(key, JSON.stringify(this.messageHistory));
      } catch (e) {
        // Storage full or private mode
      }
    }

    loadHistory() {
      try {
        const key = 'reya_ai_chat_history_' + (document.body.dataset.userId || 'guest');
        const saved = localStorage.getItem(key);
        if (saved) {
          this.messageHistory = JSON.parse(saved);
          // Render last 10 messages
          this.messageHistory.slice(-10).forEach(m => {
            if (m.type === 'user' || m.type === 'ai') {
              this.addMessage(m.text, m.type);
            }
          });
        }
      } catch (e) {
        // Ignore
      }
    }
  }

  /* ============================================================
     Floating Help Button
     ============================================================ */

  class FloatingHelpButton {
    constructor() {
      this.btn = document.getElementById('floating-help-btn');
      if (!this.btn) return;

      this.init();
    }

    init() {
      // Add entrance animation
      this.btn.style.opacity = '0';
      this.btn.style.transform = 'translateY(20px)';

      setTimeout(() => {
        this.btn.style.transition = 'all 0.4s cubic-bezier(0.16, 1, 0.3, 1)';
        this.btn.style.opacity = '1';
        this.btn.style.transform = 'translateY(0)';
      }, 1000);

      // Pulse animation on first visit
      if (!localStorage.getItem('reya_help_seen')) {
        this.btn.classList.add('fab--pulse');
        setTimeout(() => {
          this.btn.classList.remove('fab--pulse');
          localStorage.setItem('reya_help_seen', '1');
        }, 5000);
      }
    }
  }

  /* ============================================================
     Initialize on DOM Ready
     ============================================================ */

  function initFooter() {
    global.ReyaAdmin.aiChat = new AIChatWidget();
    global.ReyaAdmin.helpBtn = new FloatingHelpButton();

    console.log('[REYA Admin V4] Footer initialized');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFooter);
  } else {
    initFooter();
  }

})(window);
