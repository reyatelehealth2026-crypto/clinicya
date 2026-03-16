/**
 * LIFF SPA Router
 * Client-side routing without full page reloads
 * 
 * Requirements: 1.3 - Client-side routing without full page reloads
 */

class Router {
    constructor() {
        this.routes = new Map();
        this.currentRoute = null;
        this.previousRoute = null;
        this.contentEl = null;
        this.onRouteChange = null;

        // Default routes configuration
        this.routeConfig = {
            '/': { page: 'home', title: 'หน้าหลัก' },
            '/home': { page: 'home', title: 'หน้าหลัก' },
            '/shop': { page: 'shop', title: 'ร้านค้า' },
            '/cart': { page: 'cart', title: 'ตะกร้า' },
            '/checkout': { page: 'checkout', title: 'ชำระเงิน' },
            '/orders': { page: 'orders', title: 'ออเดอร์ของฉัน' },
            '/order/:id': { page: 'order-detail', title: 'รายละเอียดออเดอร์' },
            '/member': { page: 'member', title: 'บัตรสมาชิก' },
            '/points': { page: 'points', title: 'ประวัติแต้ม' },
            '/redeem': { page: 'redeem', title: 'แลกแต้ม' },
            '/appointments': { page: 'appointments', title: 'นัดหมาย' },
            '/video-call': { page: 'video-call', title: 'ปรึกษาเภสัชกร' },
            '/video-call/:id': { page: 'video-call', title: 'ปรึกษาเภสัชกร' },
            '/profile': { page: 'profile', title: 'โปรไฟล์' },
            '/wishlist': { page: 'wishlist', title: 'รายการโปรด' },
            '/coupons': { page: 'coupons', title: 'คูปอง' },
            '/health-profile': { page: 'health-profile', title: 'ข้อมูลสุขภาพ' },
            '/notifications': { page: 'notifications', title: 'การแจ้งเตือน' },
            '/medication-reminders': { page: 'medication-reminders', title: 'เตือนทานยา' },
            '/product/:id': { page: 'product-detail', title: 'รายละเอียดสินค้า' },
            '/ai-assistant': { page: 'ai-assistant', title: 'ผู้ช่วย AI' },
            '/symptom': { page: 'symptom', title: 'ประเมินอาการ' },
            '/register': { page: 'register', title: 'สมัครสมาชิก' },
            '/settings': { page: 'settings', title: 'ตั้งค่าบัญชี' },
            // Odoo Integration Routes
            '/odoo-link': { page: 'odoo-link', title: 'เชื่อมต่อบัญชี Odoo' },
            '/odoo-orders': { page: 'odoo-orders', title: 'ออเดอร์ Odoo' },
            '/odoo-order/:id': { page: 'odoo-order-detail', title: 'รายละเอียดออเดอร์ Odoo' },
            '/odoo-order-tracking/:id': { page: 'odoo-order-tracking', title: 'ติดตามออเดอร์ Odoo' },
            '/odoo-invoices': { page: 'odoo-invoices', title: 'ใบแจ้งหนี้ Odoo' },
            '/odoo-credit-status': { page: 'odoo-credit-status', title: 'สถานะวงเงินเครดิต' },
            // Retail B2C Routes
            '/retail-shop': { page: 'retail-shop', title: 'ร้านค้าปลีก' },
            '/retail-product/:id': { page: 'retail-product', title: 'รายละเอียดสินค้า' },
            '/retail-cart': { page: 'retail-cart', title: 'ตะกร้าสินค้า' },
            '/retail-checkout': { page: 'retail-checkout', title: 'ชำระเงิน' },
            '/retail-orders': { page: 'retail-orders', title: 'คำสั่งซื้อ' },
            '/retail-order/:id': { page: 'retail-order', title: 'รายละเอียดคำสั่งซื้อ' }
        };
    }

    /**
     * Initialize the router
     * @param {HTMLElement} contentEl - The content container element
     */
    init(contentEl) {
        this.contentEl = contentEl;

        // Listen for hash changes
        window.addEventListener('hashchange', () => this.handleRouteChange());

        // Listen for popstate (back/forward)
        window.addEventListener('popstate', () => this.handleRouteChange());

        // Handle initial route
        this.handleRouteChange();
    }

    /**
     * Register a route handler
     * @param {string} path - Route path (e.g., '/shop', '/product/:id')
     * @param {Function} handler - Handler function that returns HTML or Promise<HTML>
     */
    register(path, handler) {
        this.routes.set(path, handler);
    }

    /**
     * Navigate to a path
     * @param {string} path - The path to navigate to
     * @param {Object} params - Optional parameters
     * @param {boolean} replace - Replace current history entry
     */
    navigate(path, params = {}, replace = false) {
        const hash = path.startsWith('#') ? path : `#${path}`;

        // Store params in state
        const state = { path, params, timestamp: Date.now() };

        if (replace) {
            history.replaceState(state, '', hash);
        } else {
            history.pushState(state, '', hash);
        }

        this.handleRouteChange();
    }

    /**
     * Go back in history
     */
    back() {
        history.back();
    }

    /**
     * Handle route change
     */
    async handleRouteChange() {
        const hash = window.location.hash || '#/';
        const path = hash.slice(1) || '/'; // Remove # prefix

        // Parse path and extract params from URL
        const { route, params: urlParams } = this.matchRoute(path);

        // Merge with params from history.state (passed via navigate())
        const stateParams = history.state?.params || {};
        const params = { ...urlParams, ...stateParams };

        console.log('🔀 Route change:', { path, urlParams, stateParams, mergedParams: params });

        if (!route) {
            console.warn(`Route not found: ${path}`);
            this.navigate('/', {}, true);
            return;
        }

        // Store previous route
        this.previousRoute = this.currentRoute;
        this.currentRoute = { path, route, params };

        // Update document title
        if (route.title) {
            document.title = `${route.title} - ${window.APP_CONFIG?.SHOP_NAME || 'LIFF App'}`;
        }

        // Update active nav item
        this.updateNavigation(route.page);

        // Trigger route change callback
        if (this.onRouteChange) {
            this.onRouteChange(route, params);
        }

        // Render the page
        await this.renderPage(route, params);
    }

    /**
     * Match a path to a route configuration
     * @param {string} path - The path to match
     * @returns {Object} - { route, params }
     */
    matchRoute(path) {
        // Remove query string
        const [pathWithoutQuery] = path.split('?');
        const pathParts = pathWithoutQuery.split('/').filter(Boolean);

        // Try exact match first
        if (this.routeConfig[pathWithoutQuery]) {
            return { route: this.routeConfig[pathWithoutQuery], params: {} };
        }

        // Try pattern matching for dynamic routes
        for (const [pattern, config] of Object.entries(this.routeConfig)) {
            const patternParts = pattern.split('/').filter(Boolean);

            if (patternParts.length !== pathParts.length) continue;

            const params = {};
            let match = true;

            for (let i = 0; i < patternParts.length; i++) {
                if (patternParts[i].startsWith(':')) {
                    // Dynamic segment
                    params[patternParts[i].slice(1)] = pathParts[i];
                } else if (patternParts[i] !== pathParts[i]) {
                    match = false;
                    break;
                }
            }

            if (match) {
                return { route: config, params };
            }
        }

        // Default to home
        return { route: this.routeConfig['/'], params: {} };
    }

    /**
     * Render a page
     * Requirements: 9.5 - smooth page transitions (slide or fade)
     * @param {Object} route - Route configuration
     * @param {Object} params - Route parameters
     */
    async renderPage(route, params) {
        if (!this.contentEl) return;

        // Get the handler for this route
        const handler = this.routes.get(route.page);

        if (!handler) {
            console.warn(`No handler registered for page: ${route.page}`);
            this.contentEl.innerHTML = this.renderNotFound();
            return;
        }

        // Check for reduced motion preference
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        try {
            if (!prefersReducedMotion) {
                // Add page exit transition class
                this.contentEl.classList.add('page-exit');

                // Wait for exit animation
                await this.wait(150);
            }

            // Render the page
            const html = await handler(params);
            this.contentEl.innerHTML = html;

            if (!prefersReducedMotion) {
                // Remove exit class and add enter class
                this.contentEl.classList.remove('page-exit');
                this.contentEl.classList.add('page-enter');

                // Wait for enter animation to complete
                await this.wait(250);
                this.contentEl.classList.remove('page-enter');
            }

            // Scroll to top smoothly
            window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });

        } catch (error) {
            console.error('Error rendering page:', error);
            this.contentEl.classList.remove('page-exit', 'page-enter');
            this.contentEl.innerHTML = this.renderError(error);
        }
    }

    /**
     * Update navigation active state
     * @param {string} page - Current page name
     */
    updateNavigation(page) {
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            const itemPage = item.dataset.page;
            if (itemPage === page || (page === 'home' && itemPage === 'home')) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
    }

    /**
     * Render 404 page
     */
    renderNotFound() {
        return `
            <div class="error-state">
                <div class="error-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h2>ไม่พบหน้าที่ต้องการ</h2>
                <p>หน้าที่คุณกำลังค้นหาอาจถูกย้ายหรือไม่มีอยู่</p>
                <button class="btn btn-primary" onclick="window.router.navigate('/')">
                    <i class="fas fa-home"></i> กลับหน้าหลัก
                </button>
            </div>
        `;
    }

    /**
     * Render error page
     * @param {Error} error - The error object
     */
    renderError(error) {
        return `
            <div class="error-state">
                <div class="error-icon error">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2>เกิดข้อผิดพลาด</h2>
                <p>ไม่สามารถโหลดหน้านี้ได้ กรุณาลองใหม่อีกครั้ง</p>
                <button class="btn btn-primary" onclick="location.reload()">
                    <i class="fas fa-redo"></i> ลองใหม่
                </button>
            </div>
        `;
    }

    /**
     * Helper: Wait for specified milliseconds
     * @param {number} ms - Milliseconds to wait
     */
    wait(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Get current route info
     */
    getCurrentRoute() {
        return this.currentRoute;
    }

    /**
     * Get previous route info
     */
    getPreviousRoute() {
        return this.previousRoute;
    }

    /**
     * Check if current route matches a page
     * @param {string} page - Page name to check
     */
    isCurrentPage(page) {
        return this.currentRoute?.route?.page === page;
    }
}

// Create global router instance
window.router = new Router();
