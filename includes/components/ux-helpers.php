<?php
/**
 * UX helpers — toast notifications + button lock + auto submit-button lock + global fetch indicator.
 *
 * Usage:
 *   require_once 'includes/components/ux-helpers.php';
 *   echo getUxHelpersScript();
 *
 * Exposes window globals:
 *   - rxToast(msg, type='success'|'error'|'info')
 *   - rxLockBtn(btn, asyncFn, loadingText='กำลังบันทึก...') → awaits fn, disables+spins btn, prevents double-click
 *
 * Auto-applies (no opt-in needed):
 *   - <form method=POST> → on submit, disables submit button + replaces text with spinner
 *     (skip via <form data-rx-no-lock="1">)
 *   - All fetch() calls → shows a thin emerald progress bar at the top of the viewport
 *
 * Idempotent — safe to include on a page that already has it (re-runs guard).
 */
if (!function_exists('getUxHelpersScript')) {
    function getUxHelpersScript(): string {
        return <<<'HTML'
<script>
(function () {
    if (window.rxToast) return; // already injected on this page

    // ─── Toast ──────────────────────────────────────────────────────────────
    window.rxToast = function (msg, type) {
        type = type || 'success';
        var bgMap = { success: '#10b981', error: '#dc2626', info: '#475569', warn: '#d97706' };
        var bg = bgMap[type] || bgMap.info;
        var t = document.createElement('div');
        t.style.cssText =
            'position:fixed;top:16px;right:16px;z-index:99999;padding:10px 16px;' +
            'border-radius:8px;color:#fff;font-size:13px;font-weight:500;' +
            'box-shadow:0 4px 14px rgba(0,0,0,.15);transition:all .25s;' +
            'opacity:0;transform:translateY(-8px);max-width:340px;background:' + bg;
        t.textContent = String(msg || '');
        document.body.appendChild(t);
        requestAnimationFrame(function () {
            t.style.opacity = '1';
            t.style.transform = 'translateY(0)';
        });
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transform = 'translateY(-8px)';
            setTimeout(function () { t.remove(); }, 300);
        }, type === 'error' ? 4000 : 2600);
    };

    // ─── Button lock ────────────────────────────────────────────────────────
    window.rxLockBtn = async function (btn, fn, loadingText) {
        if (!btn) { return await fn(); }
        if (btn.dataset.rxLocked === '1') return; // ignore rapid clicks
        btn.dataset.rxLocked = '1';
        btn.disabled = true;
        var original = btn.innerHTML;
        btn.innerHTML =
            '<i class="fas fa-spinner fa-spin" style="margin-right:6px"></i>' +
            (loadingText || 'กำลังบันทึก...');
        try { return await fn(); }
        finally {
            btn.disabled = false;
            btn.innerHTML = original;
            delete btn.dataset.rxLocked;
        }
    };

    // ─── Auto-lock submit buttons on POST form submission ───────────────────
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.dataset.rxNoLock === '1') return;
        var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        btns.forEach(function (b) {
            if (b.disabled) return;
            setTimeout(function () {
                b.disabled = true;
                if (b.tagName === 'BUTTON') {
                    if (!b.dataset.rxOrig) b.dataset.rxOrig = b.innerHTML;
                    b.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px"></i>กำลังบันทึก...';
                } else {
                    if (!b.dataset.rxOrig) b.dataset.rxOrig = b.value;
                    b.value = '⏳ กำลังบันทึก...';
                }
            }, 0);
        });
        // Fallback: if page doesn't reload within 12s, restore (avoid permanent lock on error)
        setTimeout(function () {
            btns.forEach(function (b) {
                if (b.dataset.rxOrig) {
                    b.disabled = false;
                    if (b.tagName === 'BUTTON') b.innerHTML = b.dataset.rxOrig;
                    else b.value = b.dataset.rxOrig;
                    delete b.dataset.rxOrig;
                }
            });
        }, 12000);
    }, true);

    // ─── Top progress bar for fetch() ───────────────────────────────────────
    var bar = document.createElement('div');
    bar.style.cssText =
        'position:fixed;top:0;left:0;height:2px;width:0;z-index:99998;' +
        'background:linear-gradient(90deg,#10b981 0%,#34d399 100%);' +
        'box-shadow:0 0 8px rgba(16,185,129,.6);transition:width .25s,opacity .35s;opacity:0;';
    function whenReady(fn) {
        if (document.body) fn();
        else document.addEventListener('DOMContentLoaded', fn, { once: true });
    }
    whenReady(function () { document.body.appendChild(bar); });

    var pending = 0;
    function nudge(p) { bar.style.opacity = '1'; bar.style.width = p + '%'; }
    function done()   {
        bar.style.width = '100%';
        setTimeout(function () { bar.style.opacity = '0'; bar.style.width = '0'; }, 250);
    }

    var origFetch = window.fetch;
    window.fetch = function () {
        pending++;
        nudge(Math.min(80, 30 + pending * 10));
        return origFetch.apply(this, arguments).finally(function () {
            pending = Math.max(0, pending - 1);
            if (pending === 0) done();
            else nudge(Math.min(85, 30 + pending * 10));
        });
    };
})();
</script>
HTML;
    }
}
