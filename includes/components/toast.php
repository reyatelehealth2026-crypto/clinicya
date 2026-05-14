<?php
/**
 * Toast Component - Top-right success / error / info notifications
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * Renders an always-on container; messages are pushed via window.fireToast(...).
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render the toast container (place once near the end of <body>).
 *
 * @return string HTML output
 */
function renderToastContainer() {
    return '<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="false"></div>';
}

/**
 * Toast CSS + the JS firing hook.
 *
 * Exposes `window.fireToast(message, type, opts)` where:
 *   message: string
 *   type:    'success' | 'error' | 'info' | 'warning'  (default 'info')
 *   opts:    { duration?: number (ms, default 4000), title?: string }
 *
 * @return string <style>…</style><script>…</script>
 */
function getToastStyles() {
    return <<<CSS
<style>
/* Toast — uses design-tokens.css custom properties. */
.toast-container {
    position: fixed;
    top: var(--space-4, 16px);
    right: var(--space-4, 16px);
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: var(--space-2, 8px);
    width: min(360px, calc(100vw - var(--space-8, 32px)));
    pointer-events: none;
}

.toast-item {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3, 12px);
    padding: var(--space-3, 12px) var(--space-4, 16px);
    border-radius: var(--radius-md, 12px);
    background: #ffffff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15), 0 0 0 1px var(--color-slate-200);
    pointer-events: auto;
    transform: translateX(110%);
    opacity: 0;
    transition: transform var(--transition-base, 250ms ease), opacity var(--transition-base, 250ms ease);
}

.toast-item.show {
    transform: translateX(0);
    opacity: 1;
}

.toast-item-icon {
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    border-radius: var(--radius-full, 9999px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.toast-item-body {
    flex: 1 1 auto;
    min-width: 0;
    font-size: var(--text-sm, 14px);
    line-height: 1.45;
    color: var(--color-dark-800);
}

.toast-item-title {
    font-weight: 600;
    margin-bottom: 2px;
}

.toast-item-close {
    flex: 0 0 auto;
    width: 24px;
    height: 24px;
    border: none;
    background: transparent;
    color: var(--color-dark-500);
    cursor: pointer;
    border-radius: var(--radius-sm, 8px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast, 150ms ease);
}

.toast-item-close:hover {
    background: var(--color-slate-100);
    color: var(--color-dark-800);
}

/* Variants */
.toast-item-success .toast-item-icon {
    background: var(--color-emerald-100);
    color: var(--color-emerald-600);
}

.toast-item-error .toast-item-icon {
    background: var(--color-rose-100);
    color: var(--color-rose-600);
}

.toast-item-warning .toast-item-icon {
    background: var(--color-amber-100);
    color: var(--color-amber-600);
}

.toast-item-info .toast-item-icon {
    background: var(--color-primary-100);
    color: var(--color-primary-600);
}

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .toast-item {
    background: var(--color-dark-800);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4), 0 0 0 1px var(--color-dark-700);
}

.dark .toast-item-body {
    color: var(--color-slate-100);
}

.dark .toast-item-close {
    color: var(--color-slate-400);
}

.dark .toast-item-close:hover {
    background: var(--color-dark-700);
    color: var(--color-slate-100);
}

.dark .toast-item-success .toast-item-icon {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-emerald-300);
}

.dark .toast-item-error .toast-item-icon {
    background: rgba(244, 63, 94, 0.15);
    color: var(--color-rose-300);
}

.dark .toast-item-warning .toast-item-icon {
    background: rgba(245, 158, 11, 0.15);
    color: var(--color-amber-300);
}

.dark .toast-item-info .toast-item-icon {
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-primary-300);
}
</style>
<script>
/* Toast firing hook — exposes window.fireToast(message, type, opts).
   Auto-dismisses after opts.duration ms (default 4000). */
(function () {
    if (window.__toastInit) return;
    window.__toastInit = true;

    var ICONS = {
        success: 'fa-check',
        error:   'fa-times',
        warning: 'fa-exclamation',
        info:    'fa-info'
    };

    function getContainer() {
        var c = document.getElementById('toastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toastContainer';
            c.className = 'toast-container';
            c.setAttribute('aria-live', 'polite');
            document.body.appendChild(c);
        }
        return c;
    }

    window.fireToast = function (message, type, opts) {
        opts = opts || {};
        type = type || 'info';
        var duration = typeof opts.duration === 'number' ? opts.duration : 4000;
        var title = opts.title || '';

        var item = document.createElement('div');
        item.className = 'toast-item toast-item-' + type;

        var icon = document.createElement('div');
        icon.className = 'toast-item-icon';
        icon.innerHTML = '<i class="fas ' + (ICONS[type] || ICONS.info) + '"></i>';

        var body = document.createElement('div');
        body.className = 'toast-item-body';
        if (title) {
            var t = document.createElement('div');
            t.className = 'toast-item-title';
            t.textContent = title;
            body.appendChild(t);
        }
        var msg = document.createElement('div');
        msg.textContent = message || '';
        body.appendChild(msg);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-item-close';
        close.innerHTML = '<i class="fas fa-times"></i>';
        close.addEventListener('click', function () { dismiss(item); });

        item.appendChild(icon);
        item.appendChild(body);
        item.appendChild(close);

        var container = getContainer();
        container.appendChild(item);
        // trigger transition next frame
        requestAnimationFrame(function () { item.classList.add('show'); });

        if (duration > 0) {
            setTimeout(function () { dismiss(item); }, duration);
        }
    };

    function dismiss(item) {
        if (!item || !item.parentNode) return;
        item.classList.remove('show');
        setTimeout(function () {
            if (item.parentNode) item.parentNode.removeChild(item);
        }, 250);
    }
})();
</script>
CSS;
}
