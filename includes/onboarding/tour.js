/**
 * AdminTour — Shepherd.js-style vanilla interactive tour
 * Storage key: admin_tour_v1_done = "1"
 * Trigger: window.AdminTour.start({ force?: bool })
 *
 * Step shape (via window.__ADMIN_TOUR_CONFIG):
 * { selector, fallbacks?, title, content, position: 'top'|'bottom'|'left'|'right' }
 */
(function (global) {
    'use strict';

    const STORAGE_KEY = 'admin_tour_v1_done';
    const STORAGE_VAL = '1';

    function $(sel) { try { return document.querySelector(sel); } catch (e) { return null; } }
    function el(tag, cls, html) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    }

    class AdminTour {
        constructor(steps) {
            this.steps = Array.isArray(steps) ? steps : [];
            this.index = 0;
            this.active = false;
            this._nodes = {};
            this._onResize = this._reposition.bind(this);
            this._onKey = this._handleKey.bind(this);
        }

        start(opts) {
            opts = opts || {};
            if (this.active) return;
            if (!this.steps.length) return;
            if (!opts.force && this.isDone()) return;
            this.index = 0;
            this.active = true;
            this._build();
            this._render();
            window.addEventListener('resize', this._onResize);
            window.addEventListener('scroll', this._onResize, true);
            document.addEventListener('keydown', this._onKey);
        }

        stop(markDone) {
            if (!this.active) return;
            this.active = false;
            window.removeEventListener('resize', this._onResize);
            window.removeEventListener('scroll', this._onResize, true);
            document.removeEventListener('keydown', this._onKey);
            Object.values(this._nodes).forEach(n => n && n.parentNode && n.parentNode.removeChild(n));
            this._nodes = {};
            if (markDone) this.markDone();
        }

        isDone() {
            try { return localStorage.getItem(STORAGE_KEY) === STORAGE_VAL; }
            catch (e) { return false; }
        }
        markDone() {
            try { localStorage.setItem(STORAGE_KEY, STORAGE_VAL); } catch (e) {}
        }
        reset() {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        }

        next() {
            if (this.index >= this.steps.length - 1) { this.stop(true); return; }
            this.index++;
            this._render();
        }
        prev() {
            if (this.index <= 0) return;
            this.index--;
            this._render();
        }
        skip() { this.stop(true); }

        _handleKey(e) {
            if (e.key === 'ArrowRight') this.next();
            else if (e.key === 'ArrowLeft') this.prev();
            else if (e.key === 'Escape') this._warnLock();
        }

        _build() {
            this._nodes.backdrop = el('div', 'adm-tour-backdrop');
            this._nodes.backdrop.addEventListener('click', () => this._warnLock());
            this._nodes.ring = el('div', 'adm-tour-ring');
            this._nodes.tooltip = el('div', 'adm-tour-tooltip');
            this._nodes.toast = el('div', 'adm-tour-toast', 'กดปุ่ม "ข้ามทัวร์" เพื่อปิดทัวร์');
            document.body.appendChild(this._nodes.backdrop);
            document.body.appendChild(this._nodes.ring);
            document.body.appendChild(this._nodes.tooltip);
            document.body.appendChild(this._nodes.toast);
            requestAnimationFrame(() => {
                this._nodes.backdrop.classList.add('is-visible');
            });
        }

        _warnLock() {
            const t = this._nodes.toast;
            if (!t) return;
            t.classList.add('is-visible');
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => t.classList.remove('is-visible'), 1800);
        }

        _findTarget(step) {
            const tried = [step.selector].concat(step.fallbacks || []);
            for (const sel of tried) {
                if (!sel) continue;
                const found = $(sel);
                if (found) return found;
            }
            return null;
        }

        _render() {
            const step = this.steps[this.index];
            if (!step) { this.stop(true); return; }
            const target = this._findTarget(step);
            const tip = this._nodes.tooltip;
            const total = this.steps.length;
            const num = this.index + 1;

            const dots = this.steps.map((_, i) => {
                const state = i === this.index ? 'is-active' : (i < this.index ? 'is-done' : '');
                return `<span class="adm-tour-tooltip__dot ${state}"></span>`;
            }).join('');

            const isFirst = this.index === 0;
            const isLast = this.index === this.steps.length - 1;
            tip.innerHTML = `
                <div class="adm-tour-tooltip__arrow"></div>
                <div class="adm-tour-tooltip__header">
                    <div class="adm-tour-tooltip__title">${step.title || ''}</div>
                    <div class="adm-tour-tooltip__counter">${num}/${total}</div>
                </div>
                <div class="adm-tour-tooltip__body">${step.content || ''}</div>
                <div class="adm-tour-tooltip__dots">${dots}</div>
                <div class="adm-tour-tooltip__footer">
                    <button class="adm-tour-btn adm-tour-btn--skip" data-act="skip">ข้ามทัวร์</button>
                    <div class="adm-tour-tooltip__nav">
                        ${isFirst ? '' : '<button class="adm-tour-btn adm-tour-btn--ghost" data-act="prev">ย้อน</button>'}
                        <button class="adm-tour-btn adm-tour-btn--primary" data-act="next">${isLast ? 'เสร็จ' : 'ถัดไป'}</button>
                    </div>
                </div>
            `;
            tip.querySelectorAll('[data-act]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const act = e.currentTarget.getAttribute('data-act');
                    if (act === 'skip') this.skip();
                    else if (act === 'prev') this.prev();
                    else if (act === 'next') this.next();
                });
            });

            if (!target) {
                this._nodes.ring.style.display = 'none';
                tip.style.top = '50%';
                tip.style.left = '50%';
                tip.style.transform = 'translate(-50%, -50%)';
                tip.setAttribute('data-pos', 'center');
            } else {
                this._nodes.ring.style.display = '';
                this._positionFor(target, step.position || 'right');
                try { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
            }
            requestAnimationFrame(() => tip.classList.add('is-visible'));
        }

        _reposition() {
            if (!this.active) return;
            const step = this.steps[this.index];
            if (!step) return;
            const target = this._findTarget(step);
            if (target) this._positionFor(target, step.position || 'right');
        }

        _positionFor(target, position) {
            const rect = target.getBoundingClientRect();
            const pad = 6;
            const top = rect.top + window.scrollY - pad;
            const left = rect.left + window.scrollX - pad;
            const w = rect.width + pad * 2;
            const h = rect.height + pad * 2;

            const ring = this._nodes.ring;
            ring.style.top = top + 'px';
            ring.style.left = left + 'px';
            ring.style.width = w + 'px';
            ring.style.height = h + 'px';

            const tip = this._nodes.tooltip;
            tip.style.transform = '';
            tip.setAttribute('data-pos', position);
            const tw = tip.offsetWidth || 320;
            const th = tip.offsetHeight || 160;
            const gap = 16;
            let tTop, tLeft;

            switch (position) {
                case 'top':    tTop = top - th - gap;  tLeft = left;            break;
                case 'bottom': tTop = top + h + gap;   tLeft = left;            break;
                case 'left':   tTop = top;             tLeft = left - tw - gap; break;
                case 'right':
                default:       tTop = top;             tLeft = left + w + gap;  break;
            }
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const minLeft = 12 + window.scrollX;
            const maxLeft = window.scrollX + vw - tw - 12;
            const minTop = 12 + window.scrollY;
            const maxTop = window.scrollY + vh - th - 12;
            tLeft = Math.max(minLeft, Math.min(tLeft, maxLeft));
            tTop  = Math.max(minTop,  Math.min(tTop,  maxTop));

            tip.style.top = tTop + 'px';
            tip.style.left = tLeft + 'px';
        }
    }

    function boot() {
        const cfg = global.__ADMIN_TOUR_CONFIG || {};
        const tour = new AdminTour(cfg.steps || []);
        global.AdminTour = tour;

        document.querySelectorAll('[data-admin-tour-launch]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                tour.reset();
                tour.start({ force: true });
                const m = document.getElementById('userMenu');
                if (m) m.classList.add('hidden');
            });
        });

        const url = new URL(window.location.href);
        const after = url.searchParams.get('after');
        const tourParam = url.searchParams.get('tour');
        if ((after === 'wizard' || tourParam === '1') && !tour.isDone()) {
            setTimeout(() => tour.start({ force: true }), 400);
        } else if (cfg.autoLaunch && !tour.isDone()) {
            setTimeout(() => tour.start(), 400);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
