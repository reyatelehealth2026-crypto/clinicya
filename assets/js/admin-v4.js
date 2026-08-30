/**
 * REYA Admin V4 — Client-Side Architecture
 * Modular, event-driven, accessibility-first
 * @version 4.0.0
 */

(function(global) {
  'use strict';

  /* ============================================================
     Utilities
     ============================================================ */
  const Utils = {
    debounce(fn, wait = 200) {
      let t;
      return (...args) => { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), wait); };
    },

    throttle(fn, limit = 100) {
      let inThrottle;
      return (...args) => {
        if (!inThrottle) { fn.apply(this, args); inThrottle = true; setTimeout(() => inThrottle = false, limit); }
      };
    },

    escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    },

    // Fuzzy search scoring (simplified fuse.js algorithm)
    fuzzyScore(query, text) {
      query = query.toLowerCase();
      text = text.toLowerCase();
      if (text.includes(query)) return 2.0;
      let score = 0, qi = 0;
      for (let i = 0; i < text.length && qi < query.length; i++) {
        if (text[i] === query[qi]) { score += 1; qi++; }
      }
      return qi === query.length ? score / text.length : 0;
    },

    getStorageKey(base) {
      const userId = document.body.dataset.userId || 'guest';
      return `reya_${base}_${userId}`;
    },

    prefersReducedMotion() {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    },

    isTouchDevice() {
      return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    }
  };

  /* ============================================================
     State Manager (localStorage wrapper with namespacing)
     ============================================================ */
  class StateManager {
    constructor() {
      this.prefix = Utils.getStorageKey('');
    }
    get(key, fallback = null) {
      try { return JSON.parse(localStorage.getItem(this.prefix + key)) ?? fallback; }
      catch { return fallback; }
    }
    set(key, value) {
      try { localStorage.setItem(this.prefix + key, JSON.stringify(value)); }
      catch(e) { console.warn('StateManager save failed:', e); }
    }
    remove(key) { localStorage.removeItem(this.prefix + key); }
  }

  /* ============================================================
     Theme Manager (system preference + manual toggle)
     ============================================================ */
  class ThemeManager {
    constructor() {
      this.state = new StateManager();
      this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
      this.init();
    }

    init() {
      const saved = this.state.get('theme');
      if (saved) {
        this.apply(saved);
      } else if (this.mediaQuery.matches) {
        this.apply('dark');
      }
      this.mediaQuery.addEventListener('change', (e) => {
        if (!this.state.get('theme')) this.apply(e.matches ? 'dark' : 'light');
      });
    }

    apply(mode) {
      document.documentElement.setAttribute('data-theme', mode);
      document.body.classList.toggle('dark', mode === 'dark');
    }

    toggle() {
      const current = document.documentElement.getAttribute('data-theme') || 'light';
      const next = current === 'dark' ? 'light' : 'dark';
      this.apply(next);
      this.state.set('theme', next);
      this.dispatchEvent(next);
    }

    dispatchEvent(mode) {
      window.dispatchEvent(new CustomEvent('reyathemechange', { detail: { mode } }));
    }
  }

  /* ============================================================
     Menu Manager (sidebar, collapsible, recent nav, scroll lock)
     ============================================================ */
  class MenuManager {
    constructor() {
      this.state = new StateManager();
      this.sidebar = document.getElementById('sidebar');
      this.overlay = document.getElementById('mobileOverlay');
      this.init();
    }

    init() {
      this.restoreMenuStates();
      this.bindEvents();
      this.renderRecentNav();
      this.setupTouchGestures();
    }

    bindEvents() {
      // Group toggles
      document.querySelectorAll('[data-menu-group]').forEach(btn => {
        btn.addEventListener('click', (e) => this.toggleGroup(e.currentTarget.dataset.menuGroup));
      });

      // Nested toggles
      document.querySelectorAll('[data-nested-menu]').forEach(btn => {
        btn.addEventListener('click', (e) => this.toggleNested(e.currentTarget.dataset.nestedMenu));
      });

      // Track navigation clicks
      document.querySelectorAll('[data-nav-track]').forEach(link => {
        link.addEventListener('click', (e) => this.recordNav(e.currentTarget));
      });

      // Mobile overlay
      this.overlay?.addEventListener('click', () => this.closeSidebar());

      // Close on resize to desktop
      window.addEventListener('resize', Utils.throttle(() => {
        if (window.innerWidth > 768) this.closeSidebar();
      }, 200));
    }

    toggleSidebar() {
      const isOpen = this.sidebar.classList.toggle('is-open');
      this.overlay?.classList.toggle('is-open', isOpen);
      this.toggleBodyScroll(isOpen);
    }

    closeSidebar() {
      this.sidebar?.classList.remove('is-open');
      this.overlay?.classList.remove('is-open');
      this.toggleBodyScroll(false);
    }

    toggleBodyScroll(lock) {
      if (window.innerWidth > 768) return;
      document.body.style.overflow = lock ? 'hidden' : '';
      document.body.style.position = lock ? 'fixed' : '';
      document.body.style.width = lock ? '100%' : '';
    }

    toggleGroup(id) {
      const group = document.querySelector(`[data-menu-group="${id}"]`);
      const body = document.getElementById(`group_body_${id}`);
      if (!group || !body) return;

      const isOpen = body.classList.toggle('is-open');
      group.classList.toggle('is-open', isOpen);
      group.setAttribute('aria-expanded', isOpen);

      // Close other groups on desktop (accordion behavior)
      if (isOpen && window.innerWidth > 768) {
        document.querySelectorAll('[data-menu-group]').forEach(other => {
          if (other.dataset.menuGroup !== id) {
            const otherBody = document.getElementById(`group_body_${other.dataset.menuGroup}`);
            if (otherBody?.classList.contains('is-open')) {
              otherBody.classList.remove('is-open');
              other.classList.remove('is-open');
              other.setAttribute('aria-expanded', 'false');
              this.saveMenuState(other.dataset.menuGroup, false);
            }
          }
        });
      }

      this.saveMenuState(id, isOpen);
    }

    toggleNested(id) {
      const nested = document.querySelector(`[data-nested-menu="${id}"]`);
      const body = document.getElementById(`nested_body_${id}`);
      if (!nested || !body) return;

      const isOpen = body.classList.toggle('is-open');
      nested.classList.toggle('is-open', isOpen);
      nested.setAttribute('aria-expanded', isOpen);
      this.saveNestedState(id, isOpen);
    }

    saveMenuState(id, isOpen) {
      const states = this.state.get('menuGroups', {});
      states[id] = isOpen;
      this.state.set('menuGroups', states);
    }

    saveNestedState(id, isOpen) {
      const states = this.state.get('nestedMenus', {});
      states[id] = isOpen;
      this.state.set('nestedMenus', states);
    }

    restoreMenuStates() {
      const menuStates = this.state.get('menuGroups', {});
      const nestedStates = this.state.get('nestedMenus', {});

      // Restore groups (but don't override active-item expansion)
      Object.entries(menuStates).forEach(([id, isOpen]) => {
        const group = document.querySelector(`[data-menu-group="${id}"]`);
        const body = document.getElementById(`group_body_${id}`);
        if (group && body && !body.querySelector('.is-active')) {
          group.classList.toggle('is-open', isOpen);
          body.classList.toggle('is-open', isOpen);
          group.setAttribute('aria-expanded', isOpen);
        }
      });

      // Auto-expand groups containing active items
      document.querySelectorAll('.menu-item.is-active, .nested-menu__item.is-active').forEach(item => {
        const group = item.closest('[data-menu-group]');
        if (group) {
          const gid = group.dataset.menuGroup;
          const body = document.getElementById(`group_body_${gid}`);
          if (body) {
            body.classList.add('is-open');
            group.classList.add('is-open');
            group.setAttribute('aria-expanded', 'true');
          }
        }
        const nested = item.closest('.nested-menu');
        if (nested) {
          const btn = nested.querySelector('[data-nested-menu]');
          const body = nested.querySelector('.nested-menu__body');
          if (btn && body) {
            body.classList.add('is-open');
            btn.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
          }
        }
      });

      // Restore nested states
      Object.entries(nestedStates).forEach(([id, isOpen]) => {
        const nested = document.querySelector(`[data-nested-menu="${id}"]`);
        const body = document.getElementById(`nested_body_${id}`);
        if (nested && body && !body.querySelector('.is-active')) {
          nested.classList.toggle('is-open', isOpen);
          body.classList.toggle('is-open', isOpen);
          nested.setAttribute('aria-expanded', isOpen);
        }
      });

      this.updateVisualStates();
    }

    updateVisualStates() {
      document.querySelectorAll('[data-menu-group]').forEach(group => {
        const body = document.getElementById(`group_body_${group.dataset.menuGroup}`);
        const hasActive = body?.querySelector('.is-active') !== null;
        group.closest('.menu-group')?.classList.toggle('has-active', hasActive);
      });

      document.querySelectorAll('.nested-menu').forEach(group => {
        const hasActive = group.querySelector('.nested-menu__item.is-active') !== null;
        group.classList.toggle('has-active', hasActive);
      });
    }

    recordNav(link) {
      if (!link?.href) return;
      const recent = this.state.get('recentNav', []);
      const entry = {
        title: link.dataset.navTitle || link.textContent.trim().slice(0, 40),
        url: link.href,
        group: link.dataset.navGroup || 'Navigation',
        parent: link.dataset.navParent || '',
        icon: link.dataset.navIcon || '•',
        timestamp: Date.now()
      };
      // Remove duplicates, keep max 6, sort by recency with time decay
      const filtered = recent.filter(r => r.url !== entry.url);
      const next = [entry, ...filtered].slice(0, 6);
      this.state.set('recentNav', next);
    }

    renderRecentNav() {
      const section = document.getElementById('recentNavSection');
      const list = document.getElementById('recentNavList');
      if (!section || !list) return;

      const items = this.state.get('recentNav', []);
      list.innerHTML = '';

      if (!items.length) {
        section.classList.add('is-hidden');
        return;
      }

      const getIcon = (icon) => icon?.startsWith('fa') ? `<i class="fas ${icon}"></i>` : (icon || '•');

      items.slice(0, 4).forEach(item => {
        const el = document.createElement('a');
        el.href = item.url;
        el.className = 'recent-nav__item';
        el.dataset.navTrack = '';
        el.dataset.navTitle = item.title;
        el.dataset.navGroup = item.group;
        el.dataset.navParent = item.parent;
        el.dataset.navIcon = item.icon;
        el.innerHTML = `
          <span class="recent-nav__icon">${getIcon(item.icon)}</span>
          <span class="recent-nav__copy">
            <span class="recent-nav__title">${Utils.escapeHtml(item.title)}</span>
            <span class="recent-nav__meta">${Utils.escapeHtml(item.group)}</span>
          </span>
        `;
        el.addEventListener('click', () => this.recordNav(el));
        list.appendChild(el);
      });

      section.classList.remove('is-hidden');
    }

    setupTouchGestures() {
      if (!Utils.isTouchDevice()) return;
      let startX = 0;

      document.addEventListener('touchstart', (e) => { startX = e.changedTouches[0].screenX; }, { passive: true });
      document.addEventListener('touchend', (e) => {
        const endX = e.changedTouches[0].screenX;
        const diff = startX - endX;
        const sidebarOpen = this.sidebar?.classList.contains('is-open');

        if (diff > 80 && sidebarOpen) this.closeSidebar();          // Swipe left to close
        if (diff < -80 && startX < 30 && !sidebarOpen) this.toggleSidebar(); // Swipe right from edge to open
      }, { passive: true });
    }
  }

  /* ============================================================
     Command Palette (Fuzzy search, keyboard navigation)
     ============================================================ */
  class CommandPalette {
    constructor() {
      this.el = document.getElementById('commandPalette');
      this.input = document.getElementById('commandPaletteInput');
      this.results = document.getElementById('commandPaletteResults');
      this.items = Array.from(document.querySelectorAll('[data-command-item]'));
      this.selectedIndex = 0;
      this.visibleItems = [];
      this.isOpen = false;
      this.init();
    }

    init() {
      this.input?.addEventListener('input', Utils.debounce(() => this.filter(), 80));

      document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
          e.preventDefault();
          this.toggle();
        }
        if (e.key === 'Escape' && this.isOpen) {
          this.close();
        }
        if (this.isOpen) {
          if (e.key === 'ArrowDown') { e.preventDefault(); this.moveSelection(1); }
          if (e.key === 'ArrowUp') { e.preventDefault(); this.moveSelection(-1); }
          if (e.key === 'Enter') { e.preventDefault(); this.activateSelected(); }
        }
      });

      // Click outside to close
      this.el?.querySelector('.command-palette__backdrop')?.addEventListener('click', () => this.close());
    }

    toggle() { this.isOpen ? this.close() : this.open(); }

    open() {
      if (!this.el) return;
      this.el.classList.add('is-open');
      this.el.setAttribute('aria-hidden', 'false');
      this.isOpen = true;
      this.input.value = '';
      this.filter();
      requestAnimationFrame(() => this.input.focus());
    }

    close() {
      if (!this.el) return;
      this.el.classList.remove('is-open');
      this.el.setAttribute('aria-hidden', 'true');
      this.isOpen = false;
    }

    filter() {
      const query = this.input.value.trim().toLowerCase();
      const emptyState = document.getElementById('commandPaletteEmpty');
      const countEl = document.getElementById('commandPaletteCount');

      this.visibleItems = [];
      this.selectedIndex = 0;

      this.items.forEach((item, index) => {
        const haystack = [
          item.dataset.navTitle,
          item.dataset.navGroup,
          item.dataset.navParent,
          item.textContent
        ].join(' ').toLowerCase();

        const score = query ? Utils.fuzzyScore(query, haystack) : 1;
        const visible = score > 0;

        item.classList.toggle('hidden', !visible);
        item.classList.toggle('is-selected', false);

        if (visible) {
          item.dataset.score = score;
          this.visibleItems.push(item);
        }
      });

      // Sort by score if searching
      if (query) {
        this.visibleItems.sort((a, b) => parseFloat(b.dataset.score) - parseFloat(a.dataset.score));
      }

      // Re-append in sorted order
      this.visibleItems.forEach(item => this.results.appendChild(item));

      if (this.visibleItems.length) {
        this.visibleItems[0].classList.add('is-selected');
      }

      if (emptyState) emptyState.classList.toggle('hidden', this.visibleItems.length > 0);
      if (countEl) countEl.textContent = `${this.visibleItems.length} items`;
    }

    moveSelection(delta) {
      if (!this.visibleItems.length) return;
      this.visibleItems[this.selectedIndex]?.classList.remove('is-selected');
      this.selectedIndex = (this.selectedIndex + delta + this.visibleItems.length) % this.visibleItems.length;
      this.visibleItems[this.selectedIndex].classList.add('is-selected');
      this.visibleItems[this.selectedIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    activateSelected() {
      this.visibleItems[this.selectedIndex]?.click();
    }
  }

  /* ============================================================
     Keyboard Help Overlay
     ============================================================ */
  class KeyboardHelp {
    constructor() {
      this.el = document.getElementById('keyboardHelp');
      this.init();
    }

    init() {
      document.addEventListener('keydown', (e) => {
        if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
          const tag = document.activeElement?.tagName;
          if (tag !== 'INPUT' && tag !== 'TEXTAREA') {
            e.preventDefault();
            this.toggle();
          }
        }
        if (e.key === 'Escape' && this.el?.classList.contains('is-open')) {
          this.close();
        }
      });

      this.el?.querySelector('.keyboard-help__backdrop')?.addEventListener('click', () => this.close());
      this.el?.querySelector('[data-close-help]')?.addEventListener('click', () => this.close());
    }

    toggle() { this.el?.classList.toggle('is-open'); }
    close() { this.el?.classList.remove('is-open'); }
  }

  /* ============================================================
     Toast Notification System
     ============================================================ */
  class ToastManager {
    constructor() {
      this.container = document.getElementById('toastContainer');
      this.toasts = [];
    }

    show(message, options = {}) {
      const { title = '', type = 'info', duration = 4000, action = null } = options;

      const toast = document.createElement('div');
      toast.className = 'toast';
      toast.setAttribute('role', 'alert');
      toast.setAttribute('aria-live', 'polite');

      const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };

      toast.innerHTML = `
        <span class="toast__icon toast__icon--${type}"><i class="fas ${icons[type]}"></i></span>
        <div class="toast__content">
          ${title ? `<div class="toast__title">${Utils.escapeHtml(title)}</div>` : ''}
          <div class="toast__message">${Utils.escapeHtml(message)}</div>
        </div>
        <button class="toast__close" aria-label="Close notification"><i class="fas fa-times"></i></button>
      `;

      toast.querySelector('.toast__close').addEventListener('click', () => this.dismiss(toast));

      this.container?.appendChild(toast);
      this.toasts.push(toast);

      if (duration > 0) {
        setTimeout(() => this.dismiss(toast), duration);
      }

      return toast;
    }

    dismiss(toast) {
      toast.classList.add('is-leaving');
      toast.addEventListener('animationend', () => {
        toast.remove();
        this.toasts = this.toasts.filter(t => t !== toast);
      });
    }
  }

  /* ============================================================
     Dropdown Manager (click-outside, escape, accessibility)
     ============================================================ */
  class DropdownManager {
    constructor() {
      this.activeDropdowns = new Set();
      this.init();
    }

    init() {
      document.addEventListener('click', (e) => this.handleClick(e));
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.closeAll();
      });
    }

    handleClick(e) {
      const trigger = e.target.closest('[data-dropdown-trigger]');

      if (trigger) {
        const id = trigger.dataset.dropdownTrigger;
        const dropdown = document.getElementById(id);
        if (dropdown) {
          const isOpen = dropdown.classList.contains('is-open');
          this.closeAll();
          if (!isOpen) {
            dropdown.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            this.activeDropdowns.add(dropdown);
          }
          e.stopPropagation();
        }
      } else if (!e.target.closest('.dropdown-panel') && !e.target.closest('.dropdown')) {
        this.closeAll();
      }
    }

    closeAll() {
      this.activeDropdowns.forEach(d => {
        d.classList.remove('is-open');
        const trigger = document.querySelector(`[data-dropdown-trigger="${d.id}"]`);
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      });
      this.activeDropdowns.clear();
    }
  }

  /* ============================================================
     Page Loader (progress bar on navigation)
     ============================================================ */
  class PageLoader {
    constructor() {
      this.el = document.getElementById('pageLoader');
    }
    start() { this.el?.classList.add('is-active'); }
    stop() { this.el?.classList.remove('is-active'); }
  }

  /* ============================================================
     Bot Switcher
     ============================================================ */
  class BotSwitcher {
    constructor() {
      this.dropdown = document.getElementById('botDropdown');
      this.init();
    }

    init() {
      document.querySelector('[data-bot-toggle]')?.addEventListener('click', (e) => {
        e.stopPropagation();
        this.dropdown?.classList.toggle('is-open');
      });
    }
  }

  /* ============================================================
     Main Initialization
     ============================================================ */
  function init() {
    // Set user ID for namespacing
    const userId = document.querySelector('meta[name="user-id"]')?.content;
    if (userId) document.body.dataset.userId = userId;

    // Initialize all modules
    global.ReyaAdmin = {
      theme: new ThemeManager(),
      menu: new MenuManager(),
      command: new CommandPalette(),
      keyboard: new KeyboardHelp(),
      toast: new ToastManager(),
      dropdowns: new DropdownManager(),
      loader: new PageLoader(),
      bot: new BotSwitcher(),
      utils: Utils
    };

    // Expose toggle functions for inline onclick handlers (backward compat)
    global.toggleSidebar = () => global.ReyaAdmin.menu.toggleSidebar();
    global.toggleTheme = () => global.ReyaAdmin.theme.toggle();
    global.openCommandPalette = () => global.ReyaAdmin.command.open();
    global.closeCommandPalette = () => global.ReyaAdmin.command.close();
    global.toggleUserMenu = () => {
      const menu = document.getElementById('userMenu');
      menu?.classList.toggle('is-open');
    };
    global.toggleBotDropdown = () => {
      document.getElementById('botDropdown')?.classList.toggle('is-open');
    };

    // Demo banner close
    document.querySelector('[data-demo-close]')?.addEventListener('click', (e) => {
      e.target.closest('.demo-banner')?.style.setProperty('display', 'none');
    });

    // Handle iOS 100vh
    const setVH = () => {
      document.documentElement.style.setProperty('--vh', `${window.innerHeight * 0.01}px`);
    };
    setVH();
    window.addEventListener('resize', Utils.throttle(setVH, 100));

    // Intersection Observer for lazy loading off-screen menu content
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
          }
        });
      }, { root: document.querySelector('.sidebar-nav'), rootMargin: '100px' });

      document.querySelectorAll('.menu-group__body').forEach(el => observer.observe(el));
    }

    console.log('[REYA Admin V4] Initialized');
  }

  // DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})(window);
