/**
 * LIFF Application Main Controller
 * Handles LIFF initialization, authentication, and app lifecycle
 * 
 * Requirements: 1.2, 1.6
 * - Authenticate LINE_User and retrieve profile automatically
 * - Display user-friendly error message with retry option on network failure
 */

class LiffApp {
    constructor() {
        this.isInitialized = false;
        this.isLoggedIn = false;
        this.isInClient = false;
        this.profile = null;
        this.config = window.APP_CONFIG || {};
    }

    /**
     * Initialize the LIFF application
     */
    async init() {
        console.log('🚀 LiffApp initializing...');
        
        // Initialize store with config
        if (window.store) {
            window.store.init({
                baseUrl: this.config.BASE_URL,
                liffId: this.config.LIFF_ID,
                accountId: this.config.ACCOUNT_ID,
                shopName: this.config.SHOP_NAME,
                shopLogo: this.config.SHOP_LOGO,
                companyName: this.config.COMPANY_NAME
            });
        }

        try {
            // Initialize LIFF SDK่
            await this.initLiff();
            
            // Hide loading and show app FIRST (so elements are visible)
            this.showApp();
            
            // Initialize router (needs app-content to be visible)
            this.initRouter();
            
            // Setup event listeners
            this.setupEventListeners();
            
            console.log('✅ LiffApp initialized successfully');
            
        } catch (error) {
            console.error('❌ LiffApp initialization failed:', error);
            this.showError(error);
        }
    }

    /**
     * Initialize LIFF SDK
     */
    async initLiff() {
        const liffId = this.config.LIFF_ID;
        
        if (!liffId) {
            console.warn('⚠️ No LIFF ID configured, running in guest mode');
            this.handleGuestMode();
            return;
        }

        try {
            console.log('📱 Initializing LIFF with ID:', liffId);
            await liff.init({ liffId });
            
            this.isInitialized = true;
            this.isInClient = liff.isInClient();
            
            console.log('📱 LIFF initialized:', {
                isLoggedIn: liff.isLoggedIn(),
                isInClient: this.isInClient,
                os: liff.getOS()
            });

            // Update store
            if (window.store) {
                window.store.set('isInClient', this.isInClient);
            }

            // Handle authentication
            if (liff.isLoggedIn()) {
                await this.handleLoggedIn();
            } else {
                this.handleNotLoggedIn();
            }

        } catch (error) {
            console.error('❌ LIFF init error:', error);
            // Don't throw - continue in guest mode instead
            console.warn('⚠️ Continuing in guest mode due to LIFF error');
            this.handleGuestMode();
        }
    }

    /**
     * Handle logged in user
     */
    async handleLoggedIn() {
        try {
            // Get user profile
            this.profile = await liff.getProfile();
            this.isLoggedIn = true;
            
            console.log('👤 User profile:', this.profile.displayName);

            // Update store
            if (window.store) {
                window.store.setProfile(this.profile);
            }

            // Load member data
            await this.loadMemberData();

        } catch (error) {
            console.error('❌ Error getting profile:', error);
            // Continue without profile
            this.handleGuestMode();
        }
    }

    /**
     * Handle not logged in state
     */
    handleNotLoggedIn() {
        console.log('👤 User not logged in');
        
        // If in LINE app, auto login
        if (this.isInClient) {
            console.log('📱 In LINE app, triggering login...');
            liff.login();
            return;
        }

        // In external browser, show guest mode
        this.handleGuestMode();
    }

    /**
     * Handle guest mode (not logged in)
     */
    handleGuestMode() {
        console.log('👤 Running in guest mode');
        this.isLoggedIn = false;
        
        if (window.store) {
            window.store.set('isLoggedIn', false);
        }
    }

    /**
     * Load member data from API
     */
    async loadMemberData() {
        if (!this.profile?.userId) return;

        try {
            const url = `${this.config.BASE_URL}/api/member.php?action=get_card&line_user_id=${this.profile.userId}&line_account_id=${this.config.ACCOUNT_ID}`;
            
            const response = await this.fetchWithRetry(url);
            const data = await response.json();

            if (data.success && data.member) {
                console.log('💳 Member data loaded:', data.member.member_id);
                
                if (window.store) {
                    window.store.setMemberData(data);
                }
            } else {
                console.log('📝 User not registered as member');
            }

        } catch (error) {
            console.error('❌ Error loading member data:', error);
            // Continue without member data
        }
    }

    /**
     * Initialize router
     */
    initRouter() {
        if (!window.router) {
            console.error('Router not found');
            return;
        }

        const contentEl = document.getElementById('app-content');
        if (!contentEl) {
            console.error('Content element not found');
            return;
        }

        // Register page handlers
        this.registerPageHandlers();

        // Set route change callback
        window.router.onRouteChange = (route, params) => {
            this.onRouteChange(route, params);
        };

        // Initialize router
        window.router.init(contentEl);

        // Navigate to initial page if specified
        const initialPage = this.config.INITIAL_PAGE;
        if (initialPage && initialPage !== 'home') {
            window.router.navigate(`/${initialPage}`, {}, true);
        }
    }

    /**
     * Register page handlers for router
     */
    registerPageHandlers() {
        // Home page
        window.router.register('home', () => this.renderHomePage());
        
        // Shop page
        window.router.register('shop', () => this.renderShopPage());
        
        // Cart page
        window.router.register('cart', () => this.renderCartPage());
        
        // Checkout page
        window.router.register('checkout', () => this.renderCheckoutPage());
        
        // Orders page
        window.router.register('orders', () => this.renderOrdersPage());
        
        // Profile page
        window.router.register('profile', () => this.renderProfilePage());
        
        // Member card page
        window.router.register('member', () => this.renderMemberPage());
        
        // Order detail page - Requirements: 19.1, 19.2, 19.3, 19.4
        window.router.register('order-detail', (params) => this.renderOrderDetailPage(params));
        
        // Video call page - Requirements: 6.2, 6.3
        window.router.register('video-call', (params) => this.renderVideoCallPage(params));
        
        // Wishlist page - Requirements: 16.1, 16.2, 16.3, 16.4, 16.5
        window.router.register('wishlist', () => this.renderWishlistPage());
        
        // Notification settings page - Requirements: 14.1, 14.2, 14.3
        window.router.register('notifications', () => this.renderNotificationSettingsPage());
        
        // Medication reminders page - Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7
        window.router.register('medication-reminders', () => this.renderMedicationRemindersPage());
        
        // AI Assistant page - Requirements: 7.1, 7.2
        window.router.register('ai-assistant', (params) => this.renderAIAssistantPage(params));
        
        // Health Profile page - Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.10
        window.router.register('health-profile', () => this.renderHealthProfilePage());
        
        // Product Detail page
        window.router.register('product-detail', (params) => this.renderProductDetailPage(params));
        
        // Appointments page
        window.router.register('appointments', () => this.renderAppointmentsPage());
        
        // Redeem points page
        window.router.register('redeem', () => this.renderRedeemPage());
        
        // Other pages - placeholder for now
        const placeholderPages = [
            'points', 'coupons', 'symptom'
        ];
        
        placeholderPages.forEach(page => {
            window.router.register(page, (params) => this.renderPlaceholderPage(page, params));
        });
        
        // Register page - full implementation
        window.router.register('register', () => this.renderRegisterPage());
    }

    /**
     * Handle route change
     */
    onRouteChange(route, params) {
        console.log('📍 Route changed:', route.page, params);
        
        // Update cart badge
        this.updateCartBadge();
        
        // Update store
        if (window.store) {
            window.store.setCurrentPage(route.page);
        }
        
        // Hide/show bottom nav based on page
        const bottomNav = document.getElementById('bottom-nav');
        if (bottomNav) {
            // Hide bottom nav on checkout and video-call pages
            if (route.page === 'checkout' || route.page === 'video-call') {
                bottomNav.style.display = 'none';
            } else {
                bottomNav.style.display = '';
            }
        }

        // Load page-specific data
        if (route.page === 'home') {
            // Load pharmacists after a short delay to allow DOM to render
            setTimeout(() => this.loadPharmacists(), 100);
        }
        
        // Initialize video call page
        if (route.page === 'video-call') {
            setTimeout(() => this.initVideoCallPage(), 100);
        }
        
        // Load wishlist data
        if (route.page === 'wishlist') {
            setTimeout(() => this.loadWishlistData(), 100);
        }
        
        // Load notification settings
        if (route.page === 'notifications') {
            setTimeout(() => this.loadNotificationSettings(), 100);
        }
        
        // Load medication reminders
        if (route.page === 'medication-reminders') {
            setTimeout(() => this.loadMedicationRemindersData(), 100);
        }
        
        // Initialize AI assistant page
        if (route.page === 'ai-assistant') {
            setTimeout(() => this.initAIAssistantPage(params), 100);
        }
        
        // Initialize health profile page
        if (route.page === 'health-profile') {
            setTimeout(() => this.initHealthProfilePage(), 100);
        }
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Bottom navigation clicks
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const href = item.getAttribute('href');
                if (href) {
                    window.router.navigate(href.replace('#', ''));
                }
            });
        });

        // Cart updates
        if (window.store) {
            window.store.subscribe('cart', () => {
                this.updateCartBadge();
            });
        }

        // Online/offline events
        window.addEventListener('online', () => {
            this.showToast('กลับมาออนไลน์แล้ว', 'success');
        });

        window.addEventListener('offline', () => {
            this.showToast('ไม่มีการเชื่อมต่ออินเทอร์เน็ต', 'warning');
        });
    }

    /**
     * Show the app (hide loading)
     */
    showApp() {
        const loadingOverlay = document.getElementById('loading-overlay');
        const app = document.getElementById('app');
        
        if (loadingOverlay) {
            loadingOverlay.classList.add('hidden');
        }
        
        if (app) {
            app.classList.remove('hidden');
        }
    }

    /**
     * Show error state
     */
    showError(error) {
        const loadingOverlay = document.getElementById('loading-overlay');
        
        if (loadingOverlay) {
            loadingOverlay.innerHTML = `
                <div class="error-state">
                    <div class="error-icon error">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h2>เกิดข้อผิดพลาด</h2>
                    <p>${error.message || 'ไม่สามารถโหลดแอปพลิเคชันได้'}</p>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            `;
        }
    }

    /**
     * Update cart badge
     */
    updateCartBadge() {
        const badge = document.getElementById('cart-badge');
        if (!badge) return;

        const count = window.store?.getCartCount() || 0;
        
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info', duration = 3000) {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        
        container.appendChild(toast);

        // Auto remove
        setTimeout(() => {
            toast.style.animation = 'toast-out 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    /**
     * Fetch with retry logic
     */
    async fetchWithRetry(url, options = {}, retries = 3) {
        for (let i = 0; i < retries; i++) {
            try {
                const response = await fetch(url, {
                    ...options,
                    headers: {
                        'Content-Type': 'application/json',
                        ...options.headers
                    }
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                return response;
                
            } catch (error) {
                console.warn(`Fetch attempt ${i + 1} failed:`, error);
                
                if (i === retries - 1) {
                    throw error;
                }
                
                // Wait before retry (exponential backoff)
                await new Promise(r => setTimeout(r, Math.pow(2, i) * 1000));
            }
        }
    }

    /**
     * Send message via LIFF
     */
    async sendMessage(text) {
        if (!this.isInClient) {
            console.warn('sendMessage only works in LINE app');
            return false;
        }

        try {
            await liff.sendMessages([{ type: 'text', text }]);
            return true;
        } catch (error) {
            console.error('Send message error:', error);
            return false;
        }
    }

    /**
     * Login via LIFF
     */
    login() {
        if (this.isInitialized && !liff.isLoggedIn()) {
            liff.login();
        } else if (!this.isInitialized) {
            // Redirect to LIFF URL
            window.location.href = `https://liff.line.me/${this.config.LIFF_ID}`;
        }
    }

    /**
     * Logout
     */
    logout() {
        if (this.isInitialized && liff.isLoggedIn()) {
            liff.logout();
            window.location.reload();
        }
    }

    /**
     * Close LIFF window
     */
    closeWindow() {
        if (this.isInClient) {
            liff.closeWindow();
        } else {
            window.close();
        }
    }

    // ==================== Page Renderers ====================

    /**
     * Render home page - Telecare Style Dashboard
     * Requirements: 13.1, 13.4, 13.5, 13.6, 13.7, 13.8, 13.9
     */
    renderHomePage() {
        const profile = window.store?.get('profile');
        const member = window.store?.get('member');
        const tier = window.store?.get('tier');
        const shopName = this.config.SHOP_NAME || 'ร้านค้า';
        const companyName = this.config.COMPANY_NAME || shopName;
        const shopLogo = this.config.SHOP_LOGO || '';
        
        return `
            <div class="home-page">
                <!-- Header with Shop Logo and Notifications (Requirement 13.1) -->
                <div class="home-header">
                    <div class="home-header-content">
                        <div class="home-header-left">
                            ${shopLogo ? `
                                <img src="${shopLogo}" alt="${shopName}" class="home-logo" 
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="home-logo-fallback" style="display:none;">
                                    <i class="fas fa-clinic-medical"></i>
                                </div>
                            ` : `
                                <div class="home-logo-fallback">
                                    <i class="fas fa-clinic-medical"></i>
                                </div>
                            `}
                            <div class="home-header-text">
                                <h1 class="home-shop-name">${shopName}</h1>
                                <p class="home-shop-tagline">Health & Wellness</p>
                            </div>
                        </div>
                        <div class="home-header-actions">
                            <button class="home-header-btn" onclick="window.router.navigate('/notifications')">
                                <i class="far fa-bell"></i>
                                <span class="notification-dot"></span>
                            </button>
                            <button class="home-header-btn" onclick="window.router.navigate('/ai-assistant')">
                                <i class="far fa-comment-dots"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Member Card Section with Gradient Background (Requirement 13.2, 13.3) -->
                <div class="home-member-section">
                    ${this.renderMemberCardComponent(profile, member, tier, companyName)}
                </div>
                
                <!-- 3x2 Service Grid with Icons (Requirement 13.4, 13.5) -->
                <div class="home-services-section">
                    <div class="service-grid">
                        ${this.renderServiceGrid()}
                    </div>
                </div>
                
                <!-- AI Assistant Quick Actions Section (Requirement 13.6, 13.7) -->
                <div class="home-ai-section">
                    ${this.renderAIAssistantSection()}
                </div>
                
                <!-- Available Pharmacists Section (Requirement 13.8, 13.9) -->
                <div class="home-pharmacists-section">
                    ${this.renderPharmacistsSection()}
                </div>
            </div>
        `;
    }

    /**
     * Render Member Card Component
     * Requirements: 5.1, 5.2, 5.3, 5.4, 13.2, 13.3
     * - Display member name, ID, tier, points, expiry
     * - Add QR code generation for POS scanning
     * - Implement tier progress bar
     */
    renderMemberCardComponent(profile, member, tier, companyName) {
        if (!profile) {
            return `
                <div class="member-card member-card-guest">
                    <div class="member-card-decor"></div>
                    <div class="member-card-content">
                        <div class="member-card-guest-content">
                            <div class="member-card-guest-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <h3 class="member-card-guest-title">เข้าสู่ระบบเพื่อดูบัตรสมาชิก</h3>
                            <p class="member-card-guest-desc">รับสิทธิพิเศษและสะสมแต้มได้ทันที</p>
                            <button class="btn btn-white" onclick="window.liffApp.login()">
                                <i class="fab fa-line"></i> เข้าสู่ระบบ LINE
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        if (!member) {
            return `
                <div class="member-card member-card-guest">
                    <div class="member-card-decor"></div>
                    <div class="member-card-content">
                        <div class="member-card-guest-content">
                            <div class="member-card-guest-icon">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <h3 class="member-card-guest-title">ลงทะเบียนเป็นสมาชิก</h3>
                            <p class="member-card-guest-desc">รับสิทธิพิเศษและสะสมแต้มได้ทันที</p>
                            <button class="btn btn-white" onclick="window.router.navigate('/register')">
                                <i class="fas fa-user-plus"></i> ลงทะเบียนเลย
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Determine tier styling
        const tierName = tier?.name || member.tier || 'Silver';
        const tierClass = this.getTierClass(tierName);
        const points = member.points || 0;
        const nextTierPoints = tier?.next_tier_points || 2000;
        const currentTierPoints = tier?.current_tier_points || 0;
        const progressPercent = Math.min(100, Math.max(0, ((points - currentTierPoints) / (nextTierPoints - currentTierPoints)) * 100));
        const pointsToNext = Math.max(0, nextTierPoints - points);
        const nextTierName = tier?.next_tier_name || this.getNextTierName(tierName);
        const memberId = member.member_id || member.id || '-';
        const memberName = member.first_name || profile.displayName || 'สมาชิก';
        const expiryDate = member.expiry_date ? this.formatDate(member.expiry_date) : '-';

        return `
            <div class="member-card ${tierClass}" onclick="window.router.navigate('/member')">
                <div class="member-card-decor"></div>
                <div class="member-card-decor-2"></div>
                
                <div class="member-card-content">
                    <!-- Header Row -->
                    <div class="member-card-header">
                        <div class="member-card-brand">
                            <p class="member-card-company">${companyName} Member</p>
                            <h2 class="member-card-tier">${tierName} Tier</h2>
                        </div>
                        <div class="member-card-tier-icon">
                            ${this.getTierIcon(tierName)}
                        </div>
                    </div>
                    
                    <!-- Info Row -->
                    <div class="member-card-info">
                        <div class="member-card-points">
                            <p class="member-card-points-label">คะแนนสะสม</p>
                            <p class="member-card-points-value">${this.formatNumber(points)} <span class="member-card-points-unit">pt</span></p>
                        </div>
                        <div class="member-card-id">
                            <p class="member-card-id-label">หมายเลขสมาชิก</p>
                            <p class="member-card-id-value">${this.formatMemberId(memberId)}</p>
                        </div>
                    </div>
                    
                    <!-- Progress Bar (Requirement 5.4) -->
                    <div class="member-card-progress">
                        <div class="member-card-progress-bar">
                            <div class="member-card-progress-fill" style="width: ${progressPercent}%"></div>
                        </div>
                        <p class="member-card-progress-text">อีก ${this.formatNumber(pointsToNext)} คะแนน เพื่อเลื่อนเป็น ${nextTierName}</p>
                    </div>
                    
                    <!-- Flip Hint -->
                    <div class="member-card-flip-hint">
                        <i class="fas fa-qrcode"></i>
                        <span>แตะเพื่อดู QR Code</span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Get tier CSS class based on tier name
     */
    getTierClass(tierName) {
        const tierLower = (tierName || '').toLowerCase();
        if (tierLower.includes('platinum') || tierLower.includes('vip')) return 'member-card-platinum';
        if (tierLower.includes('gold')) return 'member-card-gold';
        if (tierLower.includes('bronze')) return 'member-card-bronze';
        return 'member-card-silver';
    }

    /**
     * Get tier icon based on tier name
     */
    getTierIcon(tierName) {
        const tierLower = (tierName || '').toLowerCase();
        if (tierLower.includes('platinum') || tierLower.includes('vip')) {
            return '<i class="fas fa-gem"></i>';
        }
        if (tierLower.includes('gold')) {
            return '<i class="fas fa-crown"></i>';
        }
        if (tierLower.includes('bronze')) {
            return '<i class="fas fa-medal"></i>';
        }
        return '<i class="fas fa-star"></i>';
    }

    /**
     * Get next tier name
     */
    getNextTierName(currentTier) {
        const tierLower = (currentTier || '').toLowerCase();
        if (tierLower.includes('silver')) return 'Gold';
        if (tierLower.includes('gold')) return 'Platinum';
        if (tierLower.includes('bronze')) return 'Silver';
        return 'Gold';
    }

    /**
     * Format member ID for display
     */
    formatMemberId(id) {
        if (!id || id === '-') return '-';
        const str = String(id).replace(/\D/g, '');
        if (str.length <= 4) return str;
        return str.match(/.{1,4}/g).join(' ');
    }

    /**
     * Format date for display
     */
    formatDate(dateStr) {
        if (!dateStr) return '-';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
        } catch (e) {
            return dateStr;
        }
    }

    /**
     * Render service grid - 3x2 layout
     * Requirements: 13.4, 13.5
     */
    renderServiceGrid() {
        const services = [
            { icon: 'fa-store', label: 'ร้านค้า', page: 'shop', bgColor: '#E6F7F6', iconColor: '#11B0A6' },
            { icon: 'fa-shopping-cart', label: 'ตะกร้า', page: 'cart', bgColor: '#FFF7ED', iconColor: '#F97316' },
            { icon: 'fa-box-open', label: 'ออเดอร์', page: 'orders', bgColor: '#EFF6FF', iconColor: '#3B82F6' },
            { icon: 'fa-robot', label: 'ผู้ช่วย AI', page: 'ai-assistant', bgColor: '#F3E8FF', iconColor: '#9333EA' },
            { icon: 'fa-calendar-check', label: 'นัดหมาย', page: 'appointments', bgColor: '#FFE4E6', iconColor: '#F43F5E' },
            { icon: 'fa-gift', label: 'แลกแต้ม', page: 'redeem', bgColor: '#FEF3C7', iconColor: '#F59E0B' }
        ];

        return services.map(s => `
            <div class="service-item" onclick="window.router.navigate('/${s.page}')">
                <div class="service-icon" style="background-color: ${s.bgColor}; color: ${s.iconColor};">
                    <i class="fas ${s.icon}"></i>
                </div>
                <span class="service-label">${s.label}</span>
            </div>
        `).join('');
    }

    /**
     * Render AI assistant section with gradient background
     * Requirements: 13.6, 13.7
     */
    renderAIAssistantSection() {
        const symptoms = [
            { icon: 'fa-head-side-virus', label: 'ปวดหัว', query: 'ปวดหัว' },
            { icon: 'fa-thermometer-half', label: 'ไข้หวัด', query: 'ไข้หวัด' },
            { icon: 'fa-stomach', label: 'ปวดท้อง', query: 'ปวดท้อง' },
            { icon: 'fa-allergies', label: 'แพ้อากาศ', query: 'แพ้อากาศ' }
        ];

        return `
            <div class="ai-assistant-card">
                <div class="ai-assistant-header">
                    <div class="ai-assistant-icon">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="ai-assistant-text">
                        <h3 class="ai-assistant-title">ผู้ช่วย AI ร้านยา</h3>
                        <p class="ai-assistant-desc">ถามเรื่องยาและสุขภาพได้เลย</p>
                    </div>
                    <button class="ai-assistant-expand" onclick="window.router.navigate('/ai-assistant')">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="ai-symptom-buttons">
                    ${symptoms.map(s => `
                        <button class="ai-symptom-btn" onclick="window.liffApp.startSymptomChat('${s.query}')">
                            <i class="fas ${s.icon}"></i>
                            <span>${s.label}</span>
                        </button>
                    `).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Start symptom chat with AI
     */
    startSymptomChat(symptom) {
        // Navigate to AI assistant with symptom query
        window.router.navigate('/ai-assistant', { symptom });
    }

    /**
     * Render available pharmacists section
     * Requirements: 13.8, 13.9
     */
    renderPharmacistsSection() {
        // This will be populated from API, showing skeleton initially
        return `
            <div class="pharmacists-section">
                <div class="section-header">
                    <h3 class="section-title">เภสัชกรพร้อมให้บริการ</h3>
                    <button class="section-more" onclick="window.router.navigate('/appointments')">
                        ดูทั้งหมด <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div id="pharmacists-list" class="pharmacists-list">
                    ${this.renderPharmacistsSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render pharmacists skeleton loading
     */
    renderPharmacistsSkeleton() {
        return window.Skeleton ? window.Skeleton.pharmacistCards(2) : `
            <div class="skeleton-pharmacist-card">
                <div class="skeleton skeleton-pharmacist-photo"></div>
                <div class="skeleton-pharmacist-info">
                    <div class="skeleton skeleton-text"></div>
                    <div class="skeleton skeleton-text short"></div>
                </div>
            </div>
        `;
    }

    /**
     * Load and render pharmacists from API
     */
    async loadPharmacists() {
        const container = document.getElementById('pharmacists-list');
        if (!container) return;

        try {
            const url = `${this.config.BASE_URL}/api/pharmacist.php?action=available&line_account_id=${this.config.ACCOUNT_ID}`;
            const response = await this.fetchWithRetry(url);
            const data = await response.json();

            if (data.success && data.pharmacists && data.pharmacists.length > 0) {
                container.innerHTML = this.renderPharmacistCards(data.pharmacists);
            } else {
                container.innerHTML = this.renderNoPharmacists();
            }
        } catch (error) {
            console.error('Error loading pharmacists:', error);
            container.innerHTML = this.renderNoPharmacists();
        }
    }

    /**
     * Render pharmacist cards
     * Requirements: 13.8, 13.9
     * - Display pharmacist cards with photo, name, specialty
     * - Add "Book" button for each pharmacist
     */
    renderPharmacistCards(pharmacists) {
        return pharmacists.slice(0, 3).map(p => `
            <div class="pharmacist-card">
                <div class="pharmacist-photo">
                    ${p.is_online ? '<span class="pharmacist-online-badge"></span>' : ''}
                    <img src="${p.photo_url || ''}" alt="${p.name}"
                         onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2250%22 fill=%22%2311B0A6%22/><text x=%2250%22 y=%2265%22 text-anchor=%22middle%22 fill=%22white%22 font-size=%2240%22>${(p.name || 'P').charAt(0)}</text></svg>'">
                </div>
                <div class="pharmacist-info">
                    <h4 class="pharmacist-name">${p.name || 'เภสัชกร'}</h4>
                    <p class="pharmacist-specialty">${p.specialty || 'เภสัชกรทั่วไป'}</p>
                    ${p.rating ? `
                        <div class="pharmacist-rating">
                            <i class="fas fa-star"></i>
                            <span>${p.rating}</span>
                            ${p.review_count ? `<span class="pharmacist-reviews">(${p.review_count})</span>` : ''}
                        </div>
                    ` : ''}
                    ${p.schedule ? `
                        <div class="pharmacist-schedule">
                            <i class="far fa-clock"></i>
                            <span>${p.schedule}</span>
                        </div>
                    ` : ''}
                </div>
                <button class="pharmacist-book-btn" onclick="window.liffApp.bookPharmacist(${p.id})">
                    <i class="fas fa-calendar-plus"></i>
                    นัดหมาย
                </button>
            </div>
        `).join('');
    }

    /**
     * Render no pharmacists available state
     */
    renderNoPharmacists() {
        return `
            <div class="no-pharmacists">
                <i class="fas fa-user-md"></i>
                <p>ไม่มีเภสัชกรพร้อมให้บริการในขณะนี้</p>
                <button class="btn btn-sm btn-outline" onclick="window.router.navigate('/appointments')">
                    ดูตารางนัดหมาย
                </button>
            </div>
        `;
    }

    /**
     * Book pharmacist appointment
     */
    bookPharmacist(pharmacistId) {
        window.router.navigate('/appointments', { pharmacist_id: pharmacistId });
    }

    /**
     * Render shop page - Product Catalog
     * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 11.1
     * - 2-column grid layout optimized for mobile
     * - Sticky search bar and category filter
     * - Product cards with image, name, price, add to cart
     * - AJAX add to cart without page reload
     * - Floating cart summary bar
     * - Infinite scroll pagination
     * - Real-time search filtering
     */
    renderShopPage() {
        // Initialize shop state
        this.shopState = {
            products: [],
            categories: [],
            currentPage: 1,
            totalPages: 1,
            hasMore: false,
            isLoading: false,
            isLoadingMore: false,
            searchQuery: '',
            selectedCategory: null,
            sortBy: 'newest',
            searchDebounceTimer: null
        };

        // Load initial data after render
        setTimeout(() => {
            this.loadCategories();
            this.loadProducts();
            this.setupShopEventListeners();
            this.updateCartSummaryBar();
        }, 100);

        return `
            <div class="shop-page">
                <!-- Sticky Header with Search and Categories (Requirement 2.2) -->
                <div class="shop-header">
                    <div class="shop-header-top">
                        <button class="shop-back-btn" onclick="window.router.navigate('/')">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="shop-search-container">
                            <i class="fas fa-search shop-search-icon"></i>
                            <input type="text" 
                                   id="shop-search-input"
                                   class="shop-search-input" 
                                   placeholder="ค้นหายา, วิตามิน, อุปกรณ์..."
                                   autocomplete="off">
                            <button id="shop-search-clear" class="shop-search-clear">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Category Filter Pills -->
                    <div id="shop-categories" class="shop-categories">
                        ${window.Skeleton ? window.Skeleton.categoryFilter(5) : this.renderCategorySkeleton()}
                    </div>
                </div>

                <!-- Sort & Filter Toolbar -->
                <div class="shop-toolbar">
                    <span id="shop-result-count" class="shop-result-count">กำลังโหลด...</span>
                    <button id="shop-sort-btn" class="shop-sort-btn" onclick="window.liffApp.showSortModal()">
                        <i class="fas fa-sort-amount-down"></i>
                        <span id="shop-sort-label">ล่าสุด</span>
                    </button>
                </div>

                <!-- Product Grid (Requirement 2.1) -->
                <div id="shop-product-grid" class="shop-product-grid">
                    ${window.Skeleton ? window.Skeleton.productCards(6) : this.renderProductSkeleton(6)}
                </div>

                <!-- Load More Indicator (Requirement 2.6) -->
                <div id="shop-load-more" class="shop-load-more hidden">
                    <div class="shop-load-more-spinner"></div>
                    <span class="shop-load-more-text">กำลังโหลดเพิ่มเติม...</span>
                </div>

                <!-- Floating Cart Summary Bar (Requirement 2.5) -->
                <div id="cart-summary-bar" class="cart-summary-bar">
                    <div class="cart-summary-info">
                        <div class="cart-summary-icon">
                            <i class="fas fa-shopping-cart"></i>
                            <span id="cart-summary-badge" class="cart-summary-badge">0</span>
                        </div>
                        <div class="cart-summary-text">
                            <span id="cart-summary-count" class="cart-summary-count">0 รายการ</span>
                            <span id="cart-summary-total" class="cart-summary-total">฿0</span>
                        </div>
                    </div>
                    <button class="cart-summary-btn" onclick="window.router.navigate('/cart')">
                        ดูตะกร้า
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Setup shop page event listeners
     */
    setupShopEventListeners() {
        // Search input with debounce (Requirement 2.7)
        const searchInput = document.getElementById('shop-search-input');
        const searchClear = document.getElementById('shop-search-clear');
        
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.trim();
                
                // Show/hide clear button
                if (searchClear) {
                    searchClear.classList.toggle('visible', query.length > 0);
                }
                
                // Debounce search
                if (this.shopState.searchDebounceTimer) {
                    clearTimeout(this.shopState.searchDebounceTimer);
                }
                
                this.shopState.searchDebounceTimer = setTimeout(() => {
                    this.shopState.searchQuery = query;
                    this.shopState.currentPage = 1;
                    this.loadProducts(true);
                }, 300);
            });
        }
        
        if (searchClear) {
            searchClear.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    searchClear.classList.remove('visible');
                    this.shopState.searchQuery = '';
                    this.shopState.currentPage = 1;
                    this.loadProducts(true);
                }
            });
        }

        // Infinite scroll (Requirement 2.6)
        this.setupInfiniteScroll();

        // Subscribe to cart changes
        if (window.store) {
            window.store.subscribe('cart', () => {
                this.updateCartSummaryBar();
            });
        }
    }

    /**
     * Setup infinite scroll for product loading
     * Requirement 2.6
     */
    setupInfiniteScroll() {
        const options = {
            root: null,
            rootMargin: '200px',
            threshold: 0
        };

        const loadMoreEl = document.getElementById('shop-load-more');
        if (!loadMoreEl) return;

        this.infiniteScrollObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && this.shopState.hasMore && !this.shopState.isLoadingMore) {
                    this.loadMoreProducts();
                }
            });
        }, options);

        this.infiniteScrollObserver.observe(loadMoreEl);
    }

    /**
     * Load categories from API
     */
    async loadCategories() {
        try {
            const url = `${this.config.BASE_URL}/api/shop-products.php?action=categories`;
            console.log('📂 Loading categories from:', url);
            const response = await this.fetchWithRetry(url);
            const data = await response.json();
            console.log('📂 Categories response:', data);

            if (data.success && data.categories) {
                this.shopState.categories = data.categories;
                this.renderCategories();
            } else {
                console.warn('⚠️ No categories found:', data);
                this.renderCategories(); // Render with empty categories
            }
        } catch (error) {
            console.error('❌ Error loading categories:', error);
            this.renderCategories(); // Render with empty categories
        }
    }

    /**
     * Render category filter pills
     */
    renderCategories() {
        const container = document.getElementById('shop-categories');
        if (!container) return;

        const categories = this.shopState.categories || [];
        const selectedId = this.shopState.selectedCategory;

        let html = `
            <button class="category-pill ${!selectedId ? 'active' : ''}" 
                    onclick="window.liffApp.selectCategory(null)">
                ทั้งหมด
            </button>
        `;

        categories.forEach(cat => {
            html += `
                <button class="category-pill ${selectedId == cat.id ? 'active' : ''}" 
                        onclick="window.liffApp.selectCategory(${cat.id})">
                    ${cat.name}
                </button>
            `;
        });

        container.innerHTML = html;
    }

    /**
     * Select a category
     */
    selectCategory(categoryId) {
        this.shopState.selectedCategory = categoryId;
        this.shopState.currentPage = 1;
        this.renderCategories();
        this.loadProducts(true);
    }

    /**
     * Load products from API
     */
    async loadProducts(reset = false) {
        if (this.shopState.isLoading) return;
        
        this.shopState.isLoading = true;
        
        const grid = document.getElementById('shop-product-grid');
        const loadMore = document.getElementById('shop-load-more');
        
        if (reset && grid) {
            grid.innerHTML = window.Skeleton ? window.Skeleton.productCards(6) : this.renderProductSkeleton(6);
        }

        try {
            const params = new URLSearchParams({
                page: this.shopState.currentPage,
                limit: 20,
                sort: this.shopState.sortBy,
                account: this.config.ACCOUNT_ID
            });

            if (this.shopState.searchQuery) {
                params.append('search', this.shopState.searchQuery);
            }

            if (this.shopState.selectedCategory) {
                params.append('category', this.shopState.selectedCategory);
            }

            const url = `${this.config.BASE_URL}/api/shop-products.php?${params.toString()}`;
            const response = await this.fetchWithRetry(url);
            const data = await response.json();

            if (data.success) {
                if (reset) {
                    this.shopState.products = data.products;
                } else {
                    this.shopState.products = [...this.shopState.products, ...data.products];
                }
                
                this.shopState.totalPages = data.pagination.total_pages;
                this.shopState.hasMore = data.pagination.has_more;
                
                this.renderProducts(reset);
                this.updateResultCount(data.pagination.total);
                
                // Show/hide load more indicator
                if (loadMore) {
                    loadMore.classList.toggle('hidden', !this.shopState.hasMore);
                }
            } else {
                this.renderNoProducts();
            }
        } catch (error) {
            console.error('Error loading products:', error);
            this.renderProductError();
        } finally {
            this.shopState.isLoading = false;
        }
    }

    /**
     * Load more products (infinite scroll)
     * Requirement 2.6
     */
    async loadMoreProducts() {
        if (this.shopState.isLoadingMore || !this.shopState.hasMore) return;
        
        this.shopState.isLoadingMore = true;
        this.shopState.currentPage++;
        
        const loadMore = document.getElementById('shop-load-more');
        if (loadMore) {
            loadMore.classList.remove('hidden');
        }

        try {
            const params = new URLSearchParams({
                page: this.shopState.currentPage,
                limit: 20,
                sort: this.shopState.sortBy,
                account: this.config.ACCOUNT_ID
            });

            if (this.shopState.searchQuery) {
                params.append('search', this.shopState.searchQuery);
            }

            if (this.shopState.selectedCategory) {
                params.append('category', this.shopState.selectedCategory);
            }

            const url = `${this.config.BASE_URL}/api/shop-products.php?${params.toString()}`;
            const response = await this.fetchWithRetry(url);
            const data = await response.json();

            if (data.success && data.products.length > 0) {
                this.shopState.products = [...this.shopState.products, ...data.products];
                this.shopState.hasMore = data.pagination.has_more;
                
                // Append new products to grid
                this.appendProducts(data.products);
            } else {
                this.shopState.hasMore = false;
            }
            
            // Hide load more if no more products
            if (loadMore && !this.shopState.hasMore) {
                loadMore.classList.add('hidden');
            }
        } catch (error) {
            console.error('Error loading more products:', error);
            this.shopState.currentPage--;
        } finally {
            this.shopState.isLoadingMore = false;
        }
    }

    /**
     * Render products in grid
     */
    renderProducts(reset = true) {
        const grid = document.getElementById('shop-product-grid');
        if (!grid) return;

        const products = this.shopState.products;

        if (products.length === 0) {
            this.renderNoProducts();
            return;
        }

        grid.innerHTML = products.map(p => this.renderProductCard(p)).join('');
    }

    /**
     * Append products to grid (for infinite scroll)
     */
    appendProducts(products) {
        const grid = document.getElementById('shop-product-grid');
        if (!grid) return;

        const html = products.map(p => this.renderProductCard(p)).join('');
        grid.insertAdjacentHTML('beforeend', html);
    }

    /**
     * Render a single product card
     * Requirements: 2.3, 11.1
     * - Display image, name, price, sale price, badges
     * - Add Rx badge for prescription products
     * - Add wishlist heart button
     * - Click opens modal instead of navigating
     */
    renderProductCard(product) {
        const isOutOfStock = product.stock <= 0;
        const hasSalePrice = product.sale_price && product.sale_price < product.price;
        const isPrescription = product.is_prescription || false;
        const isBestseller = product.is_bestseller || false;
        const isInWishlist = this.isProductInWishlist(product.id);
        
        // Calculate discount percentage
        let discountPercent = 0;
        if (hasSalePrice) {
            discountPercent = Math.round((1 - product.sale_price / product.price) * 100);
        }

        return `
            <div class="product-card ${isOutOfStock ? 'out-of-stock' : ''}" data-product-id="${product.id}">
                <div class="product-card-image-wrapper" onclick="window.liffApp.showProductDetailModal(${product.id})">
                    <!-- Badges -->
                    <div class="product-badges">
                        ${isPrescription ? '<span class="product-badge product-badge-rx">Rx</span>' : ''}
                        ${hasSalePrice ? `<span class="product-badge product-badge-sale">-${discountPercent}%</span>` : ''}
                        ${isBestseller ? '<span class="product-badge product-badge-bestseller">ขายดี</span>' : ''}
                    </div>
                    
                    <!-- Wishlist Button -->
                    <button class="product-wishlist-btn ${isInWishlist ? 'active' : ''}" 
                            onclick="event.stopPropagation(); window.liffApp.toggleWishlist(${product.id})">
                        <i class="${isInWishlist ? 'fas' : 'far'} fa-heart"></i>
                    </button>
                    
                    <!-- Product Image -->
                    <img src="${product.image_url || this.config.BASE_URL + '/assets/images/image-placeholder.svg'}" 
                         alt="${product.name}"
                         class="product-card-image"
                         loading="lazy"
                         onerror="this.src='${this.config.BASE_URL}/assets/images/image-placeholder.svg'">
                </div>
                
                <div class="product-card-info">
                    <h3 class="product-card-name" onclick="window.liffApp.showProductDetailModal(${product.id})">${product.name}</h3>
                    
                    <div class="product-card-price">
                        ${hasSalePrice ? `
                            <span class="product-price-current product-price-sale">฿${this.formatNumber(product.sale_price)}</span>
                            <span class="product-price-original">฿${this.formatNumber(product.price)}</span>
                        ` : `
                            <span class="product-price-current">฿${this.formatNumber(product.price)}</span>
                        `}
                    </div>
                    
                    <button class="product-add-btn" 
                            ${isOutOfStock ? 'disabled' : ''}
                            onclick="window.liffApp.addProductToCart(${product.id})"
                            data-product-id="${product.id}">
                        ${isOutOfStock ? 
                            '<span>สินค้าหมด</span>' : 
                            '<i class="fas fa-cart-plus"></i><span>เพิ่มลงตะกร้า</span>'
                        }
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Add product to cart with AJAX
     * Requirement 2.4, 12.1 - Check for drug interactions before adding
     */
    async addProductToCart(productId) {
        const btn = document.querySelector(`.product-add-btn[data-product-id="${productId}"]`);
        if (!btn || btn.classList.contains('adding')) return;

        // Show loading state
        btn.classList.add('adding');
        const originalContent = btn.innerHTML;
        btn.innerHTML = '<div class="btn-spinner"></div>';

        try {
            // Find product in state or fetch it
            let product = this.shopState.products.find(p => p.id === productId);
            
            if (!product) {
                // Fetch product details if not in state
                const url = `${this.config.BASE_URL}/api/shop-products.php?product_id=${productId}`;
                const response = await this.fetchWithRetry(url);
                const data = await response.json();
                
                if (!data.success || !data.product) {
                    throw new Error('Product not found');
                }
                product = data.product;
            }

            // Check if prescription product (Requirement 11.2)
            if (product.is_prescription && window.PrescriptionHandler) {
                // Show prescription info modal first
                window.PrescriptionHandler.showPrescriptionInfoModal(
                    product,
                    // onContinue - user acknowledged, proceed with interaction check
                    (prod) => {
                        this.proceedWithAddToCart(prod, btn, originalContent);
                    },
                    // onCancel - user cancelled
                    () => {
                        btn.classList.remove('adding');
                        btn.innerHTML = originalContent;
                    }
                );
            } else {
                // Not a prescription product, proceed with interaction check
                this.proceedWithAddToCart(product, btn, originalContent);
            }

        } catch (error) {
            console.error('Error adding to cart:', error);
            btn.classList.remove('adding');
            btn.innerHTML = originalContent;
            this.showToast('ไม่สามารถเพิ่มสินค้าได้', 'error');
        }
    }

    /**
     * Proceed with add to cart after prescription check
     * @param {Object} product - Product to add
     * @param {HTMLElement} btn - Add button element
     * @param {string} originalContent - Original button content
     */
    async proceedWithAddToCart(product, btn, originalContent) {
        // Check for drug interactions (Requirement 12.1)
        if (window.DrugInteractionChecker) {
            const cart = window.store?.get('cart') || { items: [] };
            
            // Use the drug interaction checker to handle the add
            await window.DrugInteractionChecker.handleAddToCart(
                product,
                // onSuccess callback - product can be added
                (prod, acknowledgedInteractions) => {
                    this.completeAddToCart(prod, btn, originalContent, acknowledgedInteractions);
                },
                // onBlock callback - product is blocked
                (prod, interactions) => {
                    btn.classList.remove('adding');
                    btn.innerHTML = originalContent;
                    this.showToast('ไม่สามารถเพิ่มสินค้าได้เนื่องจากปฏิกิริยายา', 'warning');
                }
            );
        } else {
            // No interaction checker available, add directly
            this.completeAddToCart(product, btn, originalContent);
        }
    }

    /**
     * Complete the add to cart action after interaction check
     * @param {Object} product - Product to add
     * @param {HTMLElement} btn - Add button element
     * @param {string} originalContent - Original button content
     * @param {Array} acknowledgedInteractions - Any acknowledged interactions
     */
    completeAddToCart(product, btn, originalContent, acknowledgedInteractions = []) {
        // Add to cart
        window.store?.addToCart(product, 1);

        // If there were acknowledged interactions, store them
        if (acknowledgedInteractions && acknowledgedInteractions.length > 0) {
            const cart = window.store?.get('cart');
            if (cart) {
                const item = cart.items.find(i => i.product_id === product.id);
                if (item) {
                    item.acknowledged_interactions = acknowledgedInteractions.map(i => ({
                        id: i.id,
                        drug1: i.drug1,
                        drug2: i.drug2,
                        severity: i.severity,
                        acknowledged_at: new Date().toISOString()
                    }));
                    window.store?.set('cart', cart);
                }
            }
        }

        // Show success state
        btn.classList.remove('adding');
        btn.classList.add('added');
        btn.innerHTML = '<i class="fas fa-check"></i><span>เพิ่มแล้ว</span>';
        
        // Show toast
        this.showToast('เพิ่มสินค้าลงตะกร้าแล้ว', 'success');

        // Reset button after delay
        setTimeout(() => {
            btn.classList.remove('added');
            btn.innerHTML = originalContent;
        }, 1500);
    }

    /**
     * Show product detail modal (like old liff-product-detail.php)
     * @param {number} productId - Product ID to show
     */
    async showProductDetailModal(productId) {
        console.log('🛍️ Opening product detail modal for ID:', productId);
        
        // Create modal if not exists
        let modal = document.getElementById('product-detail-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'product-detail-modal';
            modal.className = 'product-detail-modal';
            document.body.appendChild(modal);
        }

        // Show loading state
        modal.innerHTML = `
            <div class="product-modal-overlay" onclick="window.liffApp.closeProductDetailModal()"></div>
            <div class="product-modal-content">
                <div class="product-modal-header">
                    <button class="product-modal-close" onclick="window.liffApp.closeProductDetailModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="product-modal-body">
                    <div class="product-modal-loading">
                        <div class="skeleton" style="width: 100%; aspect-ratio: 1; border-radius: 12px;"></div>
                        <div class="skeleton" style="height: 24px; width: 80%; margin-top: 16px;"></div>
                        <div class="skeleton" style="height: 32px; width: 40%; margin-top: 8px;"></div>
                    </div>
                </div>
            </div>
        `;
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';

        try {
            // Fetch product details
            const url = `${this.config.BASE_URL}/api/shop-products.php?product_id=${productId}`;
            console.log('🛍️ Fetching product from:', url);
            const response = await this.fetchWithRetry(url);
            const text = await response.text();
            console.log('🛍️ Product response text:', text.substring(0, 200));
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                console.error('❌ JSON parse error:', parseError, 'Response:', text);
                this.showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
                this.closeProductDetailModal();
                return;
            }

            if (data.success && data.product) {
                this.renderProductDetailModal(data.product);
            } else {
                console.warn('⚠️ Product not found:', data);
                this.showToast('ไม่พบข้อมูลสินค้า', 'error');
                this.closeProductDetailModal();
            }
        } catch (error) {
            console.error('❌ Error loading product:', error);
            this.showToast('เกิดข้อผิดพลาด', 'error');
            this.closeProductDetailModal();
        }
    }

    /**
     * Render product detail modal content
     * @param {Object} product - Product data
     */
    renderProductDetailModal(product) {
        const modal = document.getElementById('product-detail-modal');
        if (!modal) return;

        const hasSalePrice = product.sale_price && product.sale_price < product.price;
        const discountPercent = hasSalePrice ? Math.round((1 - product.sale_price / product.price) * 100) : 0;
        const isOutOfStock = product.stock <= 0;
        const isLowStock = product.stock > 0 && product.stock <= 5;

        modal.innerHTML = `
            <div class="product-modal-overlay" onclick="window.liffApp.closeProductDetailModal()"></div>
            <div class="product-modal-content">
                <div class="product-modal-header">
                    <button class="product-modal-close" onclick="window.liffApp.closeProductDetailModal()">
                        <i class="fas fa-times"></i>
                    </button>
                    <button class="product-modal-wishlist" onclick="window.liffApp.toggleWishlist(${product.id})">
                        <i class="${this.isProductInWishlist(product.id) ? 'fas' : 'far'} fa-heart"></i>
                    </button>
                </div>
                <div class="product-modal-body">
                    <!-- Product Image -->
                    <div class="product-modal-image-wrapper">
                        <img src="${product.image_url || this.config.BASE_URL + '/assets/images/image-placeholder.svg'}" 
                             alt="${product.name}"
                             class="product-modal-image"
                             onerror="this.src='${this.config.BASE_URL}/assets/images/image-placeholder.svg'">
                        ${hasSalePrice ? `<span class="product-modal-badge sale">-${discountPercent}%</span>` : ''}
                    </div>

                    <!-- Product Info -->
                    <div class="product-modal-info">
                        <h2 class="product-modal-name">${product.name}</h2>
                        
                        ${product.generic_name ? `<p class="product-modal-generic">${product.generic_name}</p>` : ''}
                        
                        <!-- Price -->
                        <div class="product-modal-price">
                            ${hasSalePrice ? `
                                <span class="price-current sale">฿${this.formatNumber(product.sale_price)}</span>
                                <span class="price-original">฿${this.formatNumber(product.price)}</span>
                            ` : `
                                <span class="price-current">฿${this.formatNumber(product.price)}</span>
                            `}
                            ${product.unit ? `<span class="price-unit">/ ${product.unit}</span>` : ''}
                        </div>

                        <!-- Stock Status -->
                        <div class="product-modal-stock ${isOutOfStock ? 'out' : isLowStock ? 'low' : 'in'}">
                            ${isOutOfStock ? 
                                '<i class="fas fa-times-circle"></i> สินค้าหมด' : 
                                isLowStock ? 
                                    `<i class="fas fa-exclamation-circle"></i> เหลือ ${product.stock} ${product.unit || 'ชิ้น'}` :
                                    '<i class="fas fa-check-circle"></i> มีสินค้า'
                            }
                        </div>

                        ${product.manufacturer ? `
                            <div class="product-modal-manufacturer">
                                <i class="fas fa-industry"></i> ${product.manufacturer}
                            </div>
                        ` : ''}

                        ${product.usage_instructions ? `
                            <div class="product-modal-section">
                                <h3><i class="fas fa-prescription-bottle-alt"></i> วิธีใช้</h3>
                                <p>${product.usage_instructions}</p>
                            </div>
                        ` : ''}

                        ${product.description ? `
                            <div class="product-modal-section">
                                <h3><i class="fas fa-info-circle"></i> รายละเอียด</h3>
                                <p>${product.description}</p>
                            </div>
                        ` : ''}

                        ${product.sku ? `
                            <div class="product-modal-sku">
                                <span>SKU:</span> ${product.sku}
                            </div>
                        ` : ''}
                    </div>
                </div>

                <!-- Bottom Actions -->
                <div class="product-modal-actions">
                    <div class="product-modal-qty">
                        <button class="qty-btn" onclick="window.liffApp.changeModalQty(-1)">
                            <i class="fas fa-minus"></i>
                        </button>
                        <input type="number" id="modal-qty" value="1" min="1" max="${product.stock || 999}" readonly>
                        <button class="qty-btn" onclick="window.liffApp.changeModalQty(1)">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <button class="product-modal-add-btn" 
                            onclick="window.liffApp.addToCartFromModal(${product.id})"
                            ${isOutOfStock ? 'disabled' : ''}>
                        <i class="fas fa-cart-plus"></i>
                        ${isOutOfStock ? 'สินค้าหมด' : 'เพิ่มลงตะกร้า'}
                    </button>
                </div>
            </div>
        `;

        // Store current product for qty changes
        this.currentModalProduct = product;
    }

    /**
     * Close product detail modal
     */
    closeProductDetailModal() {
        const modal = document.getElementById('product-detail-modal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
            setTimeout(() => {
                modal.innerHTML = '';
            }, 300);
        }
        this.currentModalProduct = null;
    }

    /**
     * Change quantity in modal
     * @param {number} delta - Amount to change (+1 or -1)
     */
    changeModalQty(delta) {
        const input = document.getElementById('modal-qty');
        if (!input || !this.currentModalProduct) return;

        let val = parseInt(input.value) + delta;
        const max = this.currentModalProduct.stock || 999;
        
        if (val < 1) val = 1;
        if (val > max) val = max;
        
        input.value = val;
    }

    /**
     * Add to cart from modal
     * @param {number} productId - Product ID
     */
    async addToCartFromModal(productId) {
        const qtyInput = document.getElementById('modal-qty');
        const qty = qtyInput ? parseInt(qtyInput.value) : 1;
        const btn = document.querySelector('.product-modal-add-btn');
        
        if (!btn || btn.disabled) return;

        const originalContent = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<div class="btn-spinner"></div>';

        try {
            const product = this.currentModalProduct;
            if (!product) throw new Error('Product not found');

            // Add to cart
            for (let i = 0; i < qty; i++) {
                window.store?.addToCart(product, 1);
            }

            // Show success
            btn.innerHTML = '<i class="fas fa-check"></i> เพิ่มแล้ว';
            this.showToast(`เพิ่ม ${qty} รายการลงตะกร้าแล้ว`, 'success');

            // Close modal after delay
            setTimeout(() => {
                this.closeProductDetailModal();
            }, 800);

        } catch (error) {
            console.error('Add to cart error:', error);
            btn.disabled = false;
            btn.innerHTML = originalContent;
            this.showToast('เกิดข้อผิดพลาด', 'error');
        }
    }

    /**
     * Toggle product in wishlist
     * Requirements: 16.1, 16.2 - Add/remove products from wishlist
     */
    async toggleWishlist(productId) {
        const profile = window.store?.get('profile');
        
        // Check if logged in
        if (!profile?.userId) {
            this.showToast('กรุณาเข้าสู่ระบบเพื่อใช้งานรายการโปรด', 'warning');
            return;
        }

        const btn = document.querySelector(`.product-card[data-product-id="${productId}"] .product-wishlist-btn`);
        const isCurrentlyInWishlist = window.store?.isInWishlist(productId);
        
        // Optimistic UI update
        if (btn) {
            const isActive = btn.classList.toggle('active');
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = isActive ? 'fas fa-heart' : 'far fa-heart';
            }
        }

        try {
            // Call API to toggle wishlist
            const response = await fetch(`${this.config.BASE_URL}/api/wishlist.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'toggle',
                    line_user_id: profile.userId,
                    product_id: productId,
                    line_account_id: this.config.ACCOUNT_ID
                })
            });

            const data = await response.json();

            if (data.success) {
                // Update local store
                if (data.is_favorite) {
                    window.store?.addToWishlist(productId);
                    this.showToast('เพิ่มในรายการโปรดแล้ว', 'success');
                } else {
                    window.store?.removeFromWishlist(productId);
                    this.showToast('นำออกจากรายการโปรดแล้ว', 'success');
                }
            } else {
                // Revert UI on error
                this.revertWishlistButton(btn, isCurrentlyInWishlist);
                this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            }
        } catch (error) {
            console.error('Toggle wishlist error:', error);
            // Revert UI on error
            this.revertWishlistButton(btn, isCurrentlyInWishlist);
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        }
    }

    /**
     * Revert wishlist button state
     */
    revertWishlistButton(btn, wasInWishlist) {
        if (btn) {
            if (wasInWishlist) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = wasInWishlist ? 'fas fa-heart' : 'far fa-heart';
            }
        }
    }

    /**
     * Check if product is in wishlist
     * Requirements: 16.3 - Display filled heart icon for wishlist items
     */
    isProductInWishlist(productId) {
        return window.store?.isInWishlist(productId) || false;
    }

    /**
     * Load wishlist from API
     * Requirements: 16.4 - Display wishlist page
     */
    async loadWishlistFromApi() {
        const profile = window.store?.get('profile');
        if (!profile?.userId) return;

        try {
            const response = await fetch(
                `${this.config.BASE_URL}/api/wishlist.php?action=list&line_user_id=${profile.userId}`
            );
            const data = await response.json();

            if (data.success && data.items) {
                // Extract product IDs and update store
                const productIds = data.items.map(item => item.product_id);
                window.store?.setWishlistItems(productIds);
            }
        } catch (error) {
            console.error('Load wishlist error:', error);
        }
    }

    /**
     * Update cart summary bar visibility and content
     * Requirement 2.5
     */
    updateCartSummaryBar() {
        const bar = document.getElementById('cart-summary-bar');
        const badge = document.getElementById('cart-summary-badge');
        const countEl = document.getElementById('cart-summary-count');
        const totalEl = document.getElementById('cart-summary-total');
        
        if (!bar) return;

        const cart = window.store?.get('cart') || { items: [], total: 0 };
        const itemCount = cart.items.reduce((sum, item) => sum + item.quantity, 0);
        const total = cart.total || cart.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);

        if (itemCount > 0) {
            bar.classList.add('visible');
            if (badge) badge.textContent = itemCount > 99 ? '99+' : itemCount;
            if (countEl) countEl.textContent = `${itemCount} รายการ`;
            if (totalEl) totalEl.textContent = `฿${this.formatNumber(total)}`;
        } else {
            bar.classList.remove('visible');
        }
    }

    /**
     * Update result count display
     */
    updateResultCount(total) {
        const el = document.getElementById('shop-result-count');
        if (el) {
            el.textContent = `พบ ${this.formatNumber(total)} รายการ`;
        }
    }

    /**
     * Show sort modal
     */
    showSortModal() {
        const sortOptions = [
            { value: 'newest', label: 'ล่าสุด' },
            { value: 'price_asc', label: 'ราคา: ต่ำ → สูง' },
            { value: 'price_desc', label: 'ราคา: สูง → ต่ำ' },
            { value: 'name', label: 'ชื่อ: ก → ฮ' },
            { value: 'popular', label: 'ยอดนิยม' }
        ];

        const modalHtml = `
            <div class="modal">
                <div class="modal-header">
                    <h3 class="modal-title">เรียงลำดับ</h3>
                    <button class="modal-close" onclick="window.liffApp.hideModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="sort-modal-content">
                    ${sortOptions.map(opt => `
                        <div class="sort-option ${this.shopState.sortBy === opt.value ? 'active' : ''}" 
                             onclick="window.liffApp.selectSort('${opt.value}')">
                            <span class="sort-option-text">${opt.label}</span>
                            <i class="fas fa-check sort-option-check"></i>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        this.showModal(modalHtml);
    }

    /**
     * Select sort option
     */
    selectSort(sortValue) {
        this.shopState.sortBy = sortValue;
        this.shopState.currentPage = 1;
        
        // Update sort label
        const sortLabels = {
            'newest': 'ล่าสุด',
            'price_asc': 'ราคา: ต่ำ → สูง',
            'price_desc': 'ราคา: สูง → ต่ำ',
            'name': 'ชื่อ: ก → ฮ',
            'popular': 'ยอดนิยม'
        };
        
        const labelEl = document.getElementById('shop-sort-label');
        if (labelEl) {
            labelEl.textContent = sortLabels[sortValue] || 'ล่าสุด';
        }
        
        this.hideModal();
        this.loadProducts(true);
    }

    /**
     * Show modal
     */
    showModal(html) {
        const container = document.getElementById('modal-container');
        if (container) {
            container.innerHTML = html;
            container.classList.remove('hidden');
            container.addEventListener('click', (e) => {
                if (e.target === container) {
                    this.hideModal();
                }
            });
        }
    }

    /**
     * Hide modal
     */
    hideModal() {
        const container = document.getElementById('modal-container');
        if (container) {
            container.classList.add('hidden');
            container.innerHTML = '';
        }
    }

    /**
     * Render no products state
     */
    renderNoProducts() {
        const grid = document.getElementById('shop-product-grid');
        if (!grid) return;

        grid.innerHTML = `
            <div class="shop-no-results" style="grid-column: 1 / -1;">
                <div class="shop-no-results-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h3>ไม่พบสินค้า</h3>
                <p>ลองค้นหาด้วยคำอื่น หรือเลือกหมวดหมู่อื่น</p>
                <button class="btn btn-outline" onclick="window.liffApp.clearSearch()">
                    <i class="fas fa-redo"></i> ล้างการค้นหา
                </button>
            </div>
        `;
    }

    /**
     * Render product error state
     */
    renderProductError() {
        const grid = document.getElementById('shop-product-grid');
        if (!grid) return;

        grid.innerHTML = `
            <div class="shop-no-results" style="grid-column: 1 / -1;">
                <div class="shop-no-results-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>เกิดข้อผิดพลาด</h3>
                <p>ไม่สามารถโหลดสินค้าได้ กรุณาลองใหม่</p>
                <button class="btn btn-primary" onclick="window.liffApp.loadProducts(true)">
                    <i class="fas fa-redo"></i> ลองใหม่
                </button>
            </div>
        `;
    }

    /**
     * Clear search and filters
     */
    clearSearch() {
        const searchInput = document.getElementById('shop-search-input');
        const searchClear = document.getElementById('shop-search-clear');
        
        if (searchInput) searchInput.value = '';
        if (searchClear) searchClear.classList.remove('visible');
        
        this.shopState.searchQuery = '';
        this.shopState.selectedCategory = null;
        this.shopState.currentPage = 1;
        
        this.renderCategories();
        this.loadProducts(true);
    }

    /**
     * Render category skeleton
     */
    renderCategorySkeleton() {
        return Array(5).fill(null).map(() => 
            '<div class="skeleton skeleton-category-pill" style="width: 80px; height: 36px;"></div>'
        ).join('');
    }

    /**
     * Render product skeleton
     */
    renderProductSkeleton(count = 6) {
        return Array(count).fill(null).map(() => `
            <div class="skeleton-product-card">
                <div class="skeleton-product-image-wrapper">
                    <div class="skeleton skeleton-product-image" style="aspect-ratio: 1;"></div>
                </div>
                <div class="skeleton-product-info" style="padding: 12px;">
                    <div class="skeleton skeleton-text" style="height: 14px; margin-bottom: 8px;"></div>
                    <div class="skeleton skeleton-text" style="height: 14px; width: 70%; margin-bottom: 8px;"></div>
                    <div class="skeleton skeleton-text" style="height: 18px; width: 50%; margin-bottom: 12px;"></div>
                    <div class="skeleton skeleton-button" style="height: 36px;"></div>
                </div>
            </div>
        `).join('');
    }

    /**
     * Render cart page with item list
     * Requirements: 3.1 - Display cart items with quantity controls
     * - Show subtotal, discount, shipping, total
     */
    renderCartPage() {
        const cart = window.store?.get('cart') || { items: [], subtotal: 0, discount: 0, shipping: 0, total: 0 };
        
        // Initialize cart page after render
        setTimeout(() => {
            this.setupCartEventListeners();
            this.loadCartFromServer();
        }, 100);
        
        if (cart.items.length === 0) {
            return `
                <div class="cart-page">
                    <div class="cart-header">
                        <button class="back-btn" onclick="window.router.back()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h1 class="page-title">ตะกร้าสินค้า</h1>
                        <div class="header-spacer"></div>
                    </div>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <h2>ตะกร้าว่างเปล่า</h2>
                        <p class="text-secondary">เริ่มเลือกซื้อสินค้าได้เลย</p>
                        <button class="btn btn-primary" onclick="window.router.navigate('/shop')">
                            <i class="fas fa-store"></i> ไปร้านค้า
                        </button>
                    </div>
                </div>
            `;
        }

        // After render, move checkout bar to body for proper fixed positioning
        setTimeout(() => {
            const checkoutBar = document.querySelector('.cart-checkout-bar');
            if (checkoutBar && checkoutBar.parentElement?.classList.contains('cart-page')) {
                document.body.appendChild(checkoutBar);
                console.log('🛒 Moved checkout bar to body');
            }
        }, 50);

        return `
            <div class="cart-page">
                <!-- Header -->
                <div class="cart-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">ตะกร้าสินค้า</h1>
                    <button class="cart-clear-btn" onclick="window.liffApp.confirmClearCart()">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>

                <!-- Cart Items List -->
                <div id="cart-items-container" class="cart-items-container">
                    ${this.renderCartItems(cart.items)}
                </div>

                <!-- Cart Summary -->
                <div class="cart-summary-section">
                    <div class="cart-summary-card">
                        <h3 class="cart-summary-title">สรุปคำสั่งซื้อ</h3>
                        
                        <div class="cart-summary-row">
                            <span class="cart-summary-label">ยอดรวมสินค้า</span>
                            <span id="cart-subtotal" class="cart-summary-value">฿${this.formatNumber(cart.subtotal)}</span>
                        </div>
                        
                        <div class="cart-summary-row cart-discount-row ${cart.discount > 0 ? '' : 'hidden'}" id="cart-discount-row">
                            <span class="cart-summary-label">ส่วนลด</span>
                            <span id="cart-discount" class="cart-summary-value text-success">-฿${this.formatNumber(cart.discount)}</span>
                        </div>
                        
                        <div class="cart-summary-row">
                            <span class="cart-summary-label">ค่าจัดส่ง</span>
                            <span id="cart-shipping" class="cart-summary-value">${cart.shipping > 0 ? '฿' + this.formatNumber(cart.shipping) : 'ฟรี'}</span>
                        </div>
                        
                        <div class="cart-summary-divider"></div>
                        
                        <div class="cart-summary-row cart-total-row">
                            <span class="cart-summary-label">ยอดรวมทั้งหมด</span>
                            <span id="cart-total" class="cart-summary-total">฿${this.formatNumber(cart.total)}</span>
                        </div>
                        
                        <!-- Prescription Warning -->
                        ${cart.hasPrescription ? `
                            <div class="cart-rx-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>มียาที่ต้องปรึกษาเภสัชกรก่อนสั่งซื้อ</span>
                            </div>
                        ` : ''}
                        
                        <!-- Checkout Button inside summary card -->
                        <button class="btn btn-primary btn-block cart-checkout-btn-inline" onclick="window.router.navigate('/checkout')" style="margin-top: 16px;">
                            <span>ดำเนินการสั่งซื้อ</span>
                            <i class="fas fa-arrow-right" style="margin-left: 8px;"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render cart items list
     */
    renderCartItems(items) {
        if (!items || items.length === 0) {
            return '<div class="cart-empty-message">ไม่มีสินค้าในตะกร้า</div>';
        }

        return items.map(item => `
            <div class="cart-item" data-product-id="${item.product_id}">
                <div class="cart-item-image">
                    <img src="${item.image_url || this.config.BASE_URL + '/assets/images/image-placeholder.svg'}" 
                         alt="${item.name}"
                         onerror="this.src='${this.config.BASE_URL}/assets/images/image-placeholder.svg'">
                    ${item.is_prescription ? '<span class="cart-item-rx-badge">Rx</span>' : ''}
                </div>
                <div class="cart-item-info">
                    <h4 class="cart-item-name">${item.name}</h4>
                    <div class="cart-item-price">
                        <span class="cart-item-unit-price">฿${this.formatNumber(item.price)}</span>
                        ${item.original_price && item.original_price > item.price ? 
                            `<span class="cart-item-original-price">฿${this.formatNumber(item.original_price)}</span>` : ''}
                    </div>
                    <div class="cart-item-subtotal">
                        รวม: <strong>฿${this.formatNumber(item.price * item.quantity)}</strong>
                    </div>
                </div>
                <div class="cart-item-actions">
                    <button class="cart-item-remove" onclick="window.liffApp.removeCartItem(${item.product_id})">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <div class="cart-qty-control">
                        <button class="cart-qty-btn cart-qty-minus" 
                                onclick="window.liffApp.updateCartItemQty(${item.product_id}, ${item.quantity - 1})"
                                ${item.quantity <= 1 ? 'disabled' : ''}>
                            <i class="fas fa-minus"></i>
                        </button>
                        <span class="cart-qty-value">${item.quantity}</span>
                        <button class="cart-qty-btn cart-qty-plus" 
                                onclick="window.liffApp.updateCartItemQty(${item.product_id}, ${item.quantity + 1})">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    /**
     * Setup cart page event listeners
     */
    setupCartEventListeners() {
        // Prevent duplicate subscriptions
        if (this._cartListenersSetup) return;
        this._cartListenersSetup = true;
        
        // Subscribe to cart changes
        if (window.store) {
            window.store.subscribe('cart', () => {
                this.refreshCartDisplay();
            });
        }
    }

    /**
     * Load cart from server (sync with backend)
     */
    async loadCartFromServer() {
        // Prevent duplicate/rapid calls
        if (this._loadingCart) return;
        this._loadingCart = true;
        
        const profile = window.store?.get('profile');
        if (!profile?.userId) {
            this._loadingCart = false;
            // Use local cart if not logged in
            this.refreshCartDisplay();
            return;
        }

        try {
            const url = `${this.config.BASE_URL}/api/checkout.php?action=cart&line_user_id=${profile.userId}`;
            const response = await this.fetchWithRetry(url);
            const data = await response.json();

            if (data.success && data.items && data.items.length > 0) {
                // Update store with server cart data
                const cart = window.store.get('cart');
                cart.items = data.items.map(item => ({
                    product_id: item.product_id,
                    name: item.name,
                    price: parseFloat(item.sale_price || item.price),
                    original_price: parseFloat(item.price),
                    quantity: parseInt(item.quantity),
                    image_url: item.image_url,
                    is_prescription: item.is_prescription || false
                }));
                cart.subtotal = parseFloat(data.subtotal || 0);
                cart.shipping = parseFloat(data.shipping_fee || 0);
                cart.total = parseFloat(data.total || 0);
                cart.hasPrescription = cart.items.some(item => item.is_prescription);
                
                window.store.set('cart', cart);
            }
            // If server returns empty, keep local cart (don't overwrite)
            this.refreshCartDisplay();
        } catch (error) {
            console.error('Error loading cart from server:', error);
            // On error, just display local cart
            this.refreshCartDisplay();
        } finally {
            this._loadingCart = false;
        }
    }

    /**
     * Refresh cart display
     */
    refreshCartDisplay() {
        // Prevent infinite loop
        if (this._refreshingCart) return;
        
        const cart = window.store?.get('cart') || { items: [], subtotal: 0, discount: 0, shipping: 0, total: 0 };
        
        console.log('🛒 refreshCartDisplay:', { itemCount: cart.items?.length, subtotal: cart.subtotal });
        
        // Check if we're on cart page and have the container
        const itemsContainer = document.getElementById('cart-items-container');
        const cartPage = document.querySelector('.cart-page');
        const currentPage = window.store?.get('currentPage');
        
        console.log('🛒 Elements found:', { 
            hasItemsContainer: !!itemsContainer, 
            cartPageExists: !!cartPage,
            currentPage: currentPage
        });
        
        // Only re-render if we're on cart page but missing container
        if (currentPage === 'cart' && cart.items?.length > 0 && !itemsContainer && !cartPage) {
            console.log('🛒 Cart has items but no container, re-rendering page');
            this._refreshingCart = true;
            const contentEl = document.getElementById('app-content');
            if (contentEl) {
                contentEl.innerHTML = this.renderCartPage();
                setTimeout(() => {
                    this.setupCartEventListeners();
                    this._refreshingCart = false;
                }, 100);
            } else {
                this._refreshingCart = false;
            }
            return;
        }
        
        // Update items container
        if (itemsContainer) {
            itemsContainer.innerHTML = this.renderCartItems(cart.items);
        }

        // Update summary values
        const subtotalEl = document.getElementById('cart-subtotal');
        const discountEl = document.getElementById('cart-discount');
        const discountRow = document.getElementById('cart-discount-row');
        const shippingEl = document.getElementById('cart-shipping');
        const totalEl = document.getElementById('cart-total');

        if (subtotalEl) subtotalEl.textContent = `฿${this.formatNumber(cart.subtotal)}`;
        if (discountEl) discountEl.textContent = `-฿${this.formatNumber(cart.discount)}`;
        if (discountRow) discountRow.classList.toggle('hidden', cart.discount <= 0);
        if (shippingEl) shippingEl.textContent = cart.shipping > 0 ? `฿${this.formatNumber(cart.shipping)}` : 'ฟรี';
        if (totalEl) totalEl.textContent = `฿${this.formatNumber(cart.total)}`;

        // Update cart badge
        this.updateCartBadge();
    }

    /**
     * Update cart item quantity
     */
    async updateCartItemQty(productId, newQuantity) {
        if (newQuantity < 1) {
            this.removeCartItem(productId);
            return;
        }

        // Update local store
        window.store?.updateCartQuantity(productId, newQuantity);

        // Sync with server
        const profile = window.store?.get('profile');
        if (profile?.userId) {
            try {
                await this.fetchWithRetry(`${this.config.BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'update_cart',
                        line_user_id: profile.userId,
                        product_id: productId,
                        quantity: newQuantity
                    })
                });
            } catch (error) {
                console.error('Error updating cart:', error);
            }
        }

        this.refreshCartDisplay();
    }

    /**
     * Remove item from cart
     */
    async removeCartItem(productId) {
        // Update local store
        window.store?.removeFromCart(productId);

        // Sync with server
        const profile = window.store?.get('profile');
        if (profile?.userId) {
            try {
                await this.fetchWithRetry(`${this.config.BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'remove_from_cart',
                        line_user_id: profile.userId,
                        product_id: productId
                    })
                });
            } catch (error) {
                console.error('Error removing from cart:', error);
            }
        }

        this.showToast('นำสินค้าออกจากตะกร้าแล้ว', 'success');
        this.refreshCartDisplay();
    }

    /**
     * Confirm clear cart
     */
    confirmClearCart() {
        const modalHtml = `
            <div class="modal">
                <div class="modal-header">
                    <h3 class="modal-title">ล้างตะกร้า</h3>
                    <button class="modal-close" onclick="window.liffApp.hideModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="text-center">คุณต้องการล้างสินค้าทั้งหมดในตะกร้าหรือไม่?</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" onclick="window.liffApp.hideModal()">ยกเลิก</button>
                    <button class="btn btn-danger" onclick="window.liffApp.clearCartConfirmed()">ล้างตะกร้า</button>
                </div>
            </div>
        `;
        this.showModal(modalHtml);
    }

    /**
     * Clear cart confirmed
     */
    async clearCartConfirmed() {
        this.hideModal();

        // Clear local store
        window.store?.clearCart();

        // Sync with server
        const profile = window.store?.get('profile');
        if (profile?.userId) {
            try {
                await this.fetchWithRetry(`${this.config.BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'clear_cart',
                        line_user_id: profile.userId
                    })
                });
            } catch (error) {
                console.error('Error clearing cart:', error);
            }
        }

        this.showToast('ล้างตะกร้าเรียบร้อยแล้ว', 'success');
        window.router.navigate('/cart', {}, true);
    }

    /**
     * Render checkout page - One Page Flow
     * Requirements: 3.1, 3.2, 3.3, 3.4
     * - Display all checkout steps in One_Page_Checkout format
     * - Auto-fill customer name and phone from LINE Profile
     * - Address input with saved address option
     * - Payment method selection (Transfer, Card, PromptPay)
     */
    renderCheckoutPage() {
        const cart = window.store?.get('cart') || { items: [], total: 0 };
        const profile = window.store?.get('profile');
        const member = window.store?.get('member');
        
        console.log('🛒 renderCheckoutPage cart:', { itemCount: cart.items?.length, total: cart.total });
        
        // If cart is empty, show message instead of redirect loop
        if (!cart.items || cart.items.length === 0) {
            return `
                <div class="checkout-page">
                    <div class="checkout-header">
                        <button class="back-btn" onclick="window.router.navigate('/cart')">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h1 class="page-title">ชำระเงิน</h1>
                        <div class="header-spacer"></div>
                    </div>
                    <div class="empty-state" style="padding: 40px 20px; text-align: center;">
                        <div class="empty-state-icon" style="font-size: 48px; color: #ccc; margin-bottom: 16px;">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <h2 style="margin-bottom: 8px;">ตะกร้าว่างเปล่า</h2>
                        <p style="color: #666; margin-bottom: 20px;">กรุณาเพิ่มสินค้าก่อนชำระเงิน</p>
                        <button class="btn btn-primary" onclick="window.router.navigate('/shop')">
                            <i class="fas fa-store"></i> ไปร้านค้า
                        </button>
                    </div>
                </div>
            `;
        }

        // Check for prescription items that need approval
        // Requirements: 11.3 - Block checkout for Rx items without approval
        if (cart.hasPrescription && !cart.prescriptionApprovalId) {
            // Check prescription approval asynchronously
            setTimeout(() => this.checkPrescriptionApprovalForCheckout(), 100);
        } else if (cart.hasPrescription && cart.prescriptionApprovalId) {
            // Verify existing approval is still valid
            setTimeout(() => this.verifyPrescriptionApproval(), 100);
        }

        // Initialize checkout state
        this.checkoutState = {
            isSubmitting: false,
            formValid: false,
            promoCode: '',
            promoDiscount: 0,
            promoError: '',
            promoLoading: false,
            selectedPayment: 'transfer',
            useSavedAddress: false,
            savedAddress: member?.address || null,
            prescriptionApproved: !cart.hasPrescription || !!cart.prescriptionApprovalId
        };

        // Setup event listeners after render
        setTimeout(() => {
            this.setupCheckoutEventListeners();
            this.autoFillFromProfile();
            this.validateCheckoutForm();
        }, 100);

        // Auto-fill values from profile (Requirement 3.2)
        const displayName = profile?.displayName || member?.first_name || '';
        const phone = member?.phone || '';
        const savedAddr = member?.address || {};

        return `
            <div class="checkout-page">
                <!-- Header -->
                <div class="checkout-header">
                    <button class="back-btn" onclick="window.router.navigate('/cart')">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">ชำระเงิน</h1>
                    <div class="header-spacer"></div>
                </div>

                <!-- Checkout Form -->
                <form id="checkout-form" class="checkout-form" onsubmit="return false;">
                    
                    <!-- Section 1: Delivery Address (Requirement 3.3) -->
                    <div class="checkout-section">
                        <div class="checkout-section-header">
                            <div class="checkout-section-number">1</div>
                            <h2 class="checkout-section-title">ที่อยู่จัดส่ง</h2>
                        </div>
                        
                        ${savedAddr.address ? `
                            <div class="saved-address-option">
                                <label class="saved-address-checkbox">
                                    <input type="checkbox" id="use-saved-address" onchange="window.liffApp.toggleSavedAddress()">
                                    <span class="checkbox-custom"></span>
                                    <span class="checkbox-label">ใช้ที่อยู่ที่บันทึกไว้</span>
                                </label>
                                <div class="saved-address-preview" id="saved-address-preview">
                                    <p class="saved-address-name">${savedAddr.name || displayName}</p>
                                    <p class="saved-address-detail">${savedAddr.address || ''} ${savedAddr.subdistrict || ''} ${savedAddr.district || ''} ${savedAddr.province || ''} ${savedAddr.postcode || ''}</p>
                                    <p class="saved-address-phone">${savedAddr.phone || phone}</p>
                                </div>
                            </div>
                        ` : ''}
                        
                        <div id="address-form-fields" class="address-form-fields">
                            <div class="form-group">
                                <label class="form-label" for="checkout-name">ชื่อผู้รับ <span class="required">*</span></label>
                                <input type="text" 
                                       id="checkout-name" 
                                       class="form-input" 
                                       placeholder="ชื่อ-นามสกุล"
                                       value="${displayName}"
                                       required>
                                <span class="form-error" id="error-name"></span>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="checkout-phone">เบอร์โทรศัพท์ <span class="required">*</span></label>
                                <input type="tel" 
                                       id="checkout-phone" 
                                       class="form-input" 
                                       placeholder="0812345678"
                                       value="${phone}"
                                       maxlength="10"
                                       pattern="[0-9]{10}"
                                       required>
                                <span class="form-error" id="error-phone"></span>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="checkout-address">ที่อยู่ <span class="required">*</span></label>
                                <textarea id="checkout-address" 
                                          class="form-input form-textarea" 
                                          placeholder="บ้านเลขที่ ซอย ถนน"
                                          rows="2"
                                          required>${savedAddr.address || ''}</textarea>
                                <span class="form-error" id="error-address"></span>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group form-group-half">
                                    <label class="form-label" for="checkout-subdistrict">แขวง/ตำบล</label>
                                    <input type="text" 
                                           id="checkout-subdistrict" 
                                           class="form-input" 
                                           placeholder="แขวง/ตำบล"
                                           value="${savedAddr.subdistrict || ''}">
                                </div>
                                <div class="form-group form-group-half">
                                    <label class="form-label" for="checkout-district">เขต/อำเภอ</label>
                                    <input type="text" 
                                           id="checkout-district" 
                                           class="form-input" 
                                           placeholder="เขต/อำเภอ"
                                           value="${savedAddr.district || ''}">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group form-group-half">
                                    <label class="form-label" for="checkout-province">จังหวัด <span class="required">*</span></label>
                                    <input type="text" 
                                           id="checkout-province" 
                                           class="form-input" 
                                           placeholder="จังหวัด"
                                           value="${savedAddr.province || ''}"
                                           required>
                                    <span class="form-error" id="error-province"></span>
                                </div>
                                <div class="form-group form-group-half">
                                    <label class="form-label" for="checkout-postcode">รหัสไปรษณีย์ <span class="required">*</span></label>
                                    <input type="text" 
                                           id="checkout-postcode" 
                                           class="form-input" 
                                           placeholder="10xxx"
                                           maxlength="5"
                                           pattern="[0-9]{5}"
                                           value="${savedAddr.postcode || ''}"
                                           required>
                                    <span class="form-error" id="error-postcode"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Payment Method (Requirement 3.4) -->
                    <div class="checkout-section">
                        <div class="checkout-section-header">
                            <div class="checkout-section-number">2</div>
                            <h2 class="checkout-section-title">วิธีชำระเงิน</h2>
                        </div>
                        
                        <div class="payment-methods">
                            <label class="payment-method-option active" data-method="transfer">
                                <input type="radio" name="payment_method" value="transfer" checked>
                                <div class="payment-method-content">
                                    <div class="payment-method-icon">
                                        <i class="fas fa-university"></i>
                                    </div>
                                    <div class="payment-method-info">
                                        <span class="payment-method-name">โอนเงิน</span>
                                        <span class="payment-method-desc">โอนผ่านธนาคาร</span>
                                    </div>
                                    <div class="payment-method-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="payment-method-option" data-method="promptpay">
                                <input type="radio" name="payment_method" value="promptpay">
                                <div class="payment-method-content">
                                    <div class="payment-method-icon promptpay-icon">
                                        <i class="fas fa-qrcode"></i>
                                    </div>
                                    <div class="payment-method-info">
                                        <span class="payment-method-name">PromptPay QR</span>
                                        <span class="payment-method-desc">สแกน QR Code</span>
                                    </div>
                                    <div class="payment-method-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="payment-method-option" data-method="cod">
                                <input type="radio" name="payment_method" value="cod">
                                <div class="payment-method-content">
                                    <div class="payment-method-icon cod-icon">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </div>
                                    <div class="payment-method-info">
                                        <span class="payment-method-name">เก็บเงินปลายทาง</span>
                                        <span class="payment-method-desc">ชำระเมื่อรับสินค้า</span>
                                    </div>
                                    <div class="payment-method-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Section 3: Promo Code -->
                    <div class="checkout-section">
                        <div class="checkout-section-header">
                            <div class="checkout-section-number">3</div>
                            <h2 class="checkout-section-title">โค้ดส่วนลด</h2>
                        </div>
                        
                        <div class="promo-code-input">
                            <input type="text" 
                                   id="promo-code" 
                                   class="form-input promo-input" 
                                   placeholder="กรอกโค้ดส่วนลด">
                            <button type="button" 
                                    id="apply-promo-btn" 
                                    class="btn btn-outline promo-btn"
                                    onclick="window.liffApp.applyPromoCode()">
                                ใช้โค้ด
                            </button>
                        </div>
                        <div id="promo-result" class="promo-result"></div>
                    </div>

                    <!-- Section 4: Order Summary -->
                    <div class="checkout-section">
                        <div class="checkout-section-header">
                            <div class="checkout-section-number">4</div>
                            <h2 class="checkout-section-title">สรุปคำสั่งซื้อ</h2>
                        </div>
                        
                        <div class="checkout-items-summary">
                            ${cart.items.map(item => `
                                <div class="checkout-item">
                                    <div class="checkout-item-image">
                                        <img src="${item.image_url || this.config.BASE_URL + '/assets/images/image-placeholder.svg'}" 
                                             alt="${item.name}">
                                        ${item.is_prescription ? '<span class="checkout-item-rx">Rx</span>' : ''}
                                    </div>
                                    <div class="checkout-item-info">
                                        <span class="checkout-item-name">${item.name}</span>
                                        <span class="checkout-item-qty">x${item.quantity}</span>
                                    </div>
                                    <span class="checkout-item-price">฿${this.formatNumber(item.price * item.quantity)}</span>
                                </div>
                            `).join('')}
                        </div>
                        
                        <div class="checkout-totals">
                            <div class="checkout-total-row">
                                <span>ยอดรวมสินค้า (${cart.items.reduce((sum, i) => sum + i.quantity, 0)} ชิ้น)</span>
                                <span id="checkout-subtotal">฿${this.formatNumber(cart.subtotal)}</span>
                            </div>
                            <div class="checkout-total-row promo-discount-row hidden" id="checkout-promo-row">
                                <span>ส่วนลดโค้ด</span>
                                <span id="checkout-promo-discount" class="text-success">-฿0</span>
                            </div>
                            <div class="checkout-total-row">
                                <span>ค่าจัดส่ง</span>
                                <span id="checkout-shipping">${cart.shipping > 0 ? '฿' + this.formatNumber(cart.shipping) : 'ฟรี'}</span>
                            </div>
                            <div class="checkout-total-row checkout-grand-total">
                                <span>ยอดรวมทั้งหมด</span>
                                <span id="checkout-grand-total">฿${this.formatNumber(cart.total)}</span>
                            </div>
                        </div>
                        
                        ${cart.hasPrescription ? `
                            <div class="checkout-rx-notice">
                                <i class="fas fa-info-circle"></i>
                                <span>มียาที่ต้องได้รับการอนุมัติจากเภสัชกร ทางร้านจะติดต่อกลับเพื่อยืนยันก่อนจัดส่ง</span>
                            </div>
                        ` : ''}
                    </div>
                </form>

                <!-- Place Order Button -->
                <div class="checkout-submit-bar">
                    <button type="button" 
                            id="place-order-btn" 
                            class="btn btn-primary btn-block checkout-submit-btn"
                            onclick="window.liffApp.placeOrder()"
                            disabled>
                        <span id="place-order-text">สั่งซื้อ</span>
                        <span id="place-order-loading" class="hidden">
                            <div class="btn-spinner"></div>
                            กำลังดำเนินการ...
                        </span>
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Setup checkout event listeners
     */
    setupCheckoutEventListeners() {
        // Form input validation on change
        const inputs = document.querySelectorAll('#checkout-form .form-input');
        inputs.forEach(input => {
            input.addEventListener('input', () => this.validateCheckoutForm());
            input.addEventListener('blur', () => this.validateField(input));
        });

        // Payment method selection
        const paymentOptions = document.querySelectorAll('.payment-method-option');
        paymentOptions.forEach(option => {
            option.addEventListener('click', () => {
                paymentOptions.forEach(o => o.classList.remove('active'));
                option.classList.add('active');
                option.querySelector('input').checked = true;
                this.checkoutState.selectedPayment = option.dataset.method;
            });
        });
    }

    /**
     * Auto-fill form from LINE profile
     * Requirement 3.2
     */
    autoFillFromProfile() {
        const profile = window.store?.get('profile');
        const member = window.store?.get('member');
        
        if (profile?.displayName) {
            const nameInput = document.getElementById('checkout-name');
            if (nameInput && !nameInput.value) {
                nameInput.value = profile.displayName;
            }
        }
        
        if (member?.phone) {
            const phoneInput = document.getElementById('checkout-phone');
            if (phoneInput && !phoneInput.value) {
                phoneInput.value = member.phone;
            }
        }
    }

    /**
     * Toggle saved address
     */
    toggleSavedAddress() {
        const checkbox = document.getElementById('use-saved-address');
        const formFields = document.getElementById('address-form-fields');
        const member = window.store?.get('member');
        
        this.checkoutState.useSavedAddress = checkbox?.checked || false;
        
        if (this.checkoutState.useSavedAddress && member?.address) {
            formFields?.classList.add('hidden');
            // Fill form with saved address
            const addr = member.address;
            document.getElementById('checkout-name').value = addr.name || member.first_name || '';
            document.getElementById('checkout-phone').value = addr.phone || member.phone || '';
            document.getElementById('checkout-address').value = addr.address || '';
            document.getElementById('checkout-subdistrict').value = addr.subdistrict || '';
            document.getElementById('checkout-district').value = addr.district || '';
            document.getElementById('checkout-province').value = addr.province || '';
            document.getElementById('checkout-postcode').value = addr.postcode || '';
        } else {
            formFields?.classList.remove('hidden');
        }
        
        this.validateCheckoutForm();
    }

    /**
     * Validate checkout form in real-time
     * Requirements: 3.5, 3.6, 3.7
     * - Validate inputs as user types
     * - Show inline error messages
     * - Enable/disable Place Order button
     */
    validateCheckoutForm() {
        const fields = {
            name: document.getElementById('checkout-name'),
            phone: document.getElementById('checkout-phone'),
            address: document.getElementById('checkout-address'),
            province: document.getElementById('checkout-province'),
            postcode: document.getElementById('checkout-postcode')
        };

        let isValid = true;

        // Validate each required field
        for (const [fieldName, input] of Object.entries(fields)) {
            if (!input) continue;
            
            const value = input.value.trim();
            const errorEl = document.getElementById(`error-${fieldName}`);
            let fieldValid = true;
            let errorMessage = '';

            switch (fieldName) {
                case 'name':
                    if (!value) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกชื่อผู้รับ';
                    } else if (value.length < 2) {
                        fieldValid = false;
                        errorMessage = 'ชื่อต้องมีอย่างน้อย 2 ตัวอักษร';
                    }
                    break;
                    
                case 'phone':
                    if (!value) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกเบอร์โทรศัพท์';
                    } else if (!/^0[0-9]{9}$/.test(value)) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกเบอร์โทรศัพท์ 10 หลัก';
                    }
                    break;
                    
                case 'address':
                    if (!value) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกที่อยู่';
                    } else if (value.length < 10) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกที่อยู่ให้ครบถ้วน';
                    }
                    break;
                    
                case 'province':
                    if (!value) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกจังหวัด';
                    }
                    break;
                    
                case 'postcode':
                    if (!value) {
                        fieldValid = false;
                        errorMessage = 'กรุณากรอกรหัสไปรษณีย์';
                    } else if (!/^[0-9]{5}$/.test(value)) {
                        fieldValid = false;
                        errorMessage = 'รหัสไปรษณีย์ต้องเป็นตัวเลข 5 หลัก';
                    }
                    break;
            }

            // Update field state
            if (fieldValid) {
                input.classList.remove('error');
                if (errorEl) errorEl.textContent = '';
            } else {
                isValid = false;
            }
        }

        // Update form valid state
        this.checkoutState.formValid = isValid;
        
        // Enable/disable submit button (Requirement 3.6, 3.7)
        const submitBtn = document.getElementById('place-order-btn');
        if (submitBtn) {
            submitBtn.disabled = !isValid;
        }

        return isValid;
    }

    /**
     * Validate single field on blur
     * @param {HTMLInputElement} input - Input element to validate
     */
    validateField(input) {
        if (!input) return;
        
        const fieldName = input.id.replace('checkout-', '');
        const value = input.value.trim();
        const errorEl = document.getElementById(`error-${fieldName}`);
        let errorMessage = '';

        switch (fieldName) {
            case 'name':
                if (!value) {
                    errorMessage = 'กรุณากรอกชื่อผู้รับ';
                } else if (value.length < 2) {
                    errorMessage = 'ชื่อต้องมีอย่างน้อย 2 ตัวอักษร';
                }
                break;
                
            case 'phone':
                if (!value) {
                    errorMessage = 'กรุณากรอกเบอร์โทรศัพท์';
                } else if (!/^0[0-9]{9}$/.test(value)) {
                    errorMessage = 'กรุณากรอกเบอร์โทรศัพท์ 10 หลัก';
                }
                break;
                
            case 'address':
                if (!value) {
                    errorMessage = 'กรุณากรอกที่อยู่';
                } else if (value.length < 10) {
                    errorMessage = 'กรุณากรอกที่อยู่ให้ครบถ้วน';
                }
                break;
                
            case 'province':
                if (!value) {
                    errorMessage = 'กรุณากรอกจังหวัด';
                }
                break;
                
            case 'postcode':
                if (!value) {
                    errorMessage = 'กรุณากรอกรหัสไปรษณีย์';
                } else if (!/^[0-9]{5}$/.test(value)) {
                    errorMessage = 'รหัสไปรษณีย์ต้องเป็นตัวเลข 5 หลัก';
                }
                break;
        }

        // Show/hide error
        if (errorMessage) {
            input.classList.add('error');
            if (errorEl) errorEl.textContent = errorMessage;
        } else {
            input.classList.remove('error');
            if (errorEl) errorEl.textContent = '';
        }

        // Re-validate entire form
        this.validateCheckoutForm();
    }

    /**
     * Get form data for order submission
     * @returns {Object} - Form data object
     */
    getCheckoutFormData() {
        return {
            name: document.getElementById('checkout-name')?.value.trim() || '',
            phone: document.getElementById('checkout-phone')?.value.trim() || '',
            address: document.getElementById('checkout-address')?.value.trim() || '',
            subdistrict: document.getElementById('checkout-subdistrict')?.value.trim() || '',
            district: document.getElementById('checkout-district')?.value.trim() || '',
            province: document.getElementById('checkout-province')?.value.trim() || '',
            postcode: document.getElementById('checkout-postcode')?.value.trim() || ''
        };
    }

    /**
     * Place order with loading state
     * Requirements: 3.8, 3.9, 11.3
     * - Show loading state on submit
     * - Prevent duplicate submissions
     * - Create order via API
     * - Block if prescription items without approval
     */
    async placeOrder() {
        // Prevent duplicate submissions (Requirement 3.9)
        if (this.checkoutState.isSubmitting) {
            console.log('Order submission already in progress');
            return;
        }

        // Validate form first
        if (!this.validateCheckoutForm()) {
            this.showToast('กรุณากรอกข้อมูลให้ครบถ้วน', 'error');
            return;
        }

        // Get cart and validate
        const cart = window.store?.get('cart');
        console.log('🛒 placeOrder cart:', cart);
        
        if (!cart?.items || cart.items.length === 0) {
            this.showToast('ตะกร้าว่างเปล่า กรุณาเพิ่มสินค้า', 'error');
            return;
        }

        // Check prescription approval (Requirement 11.3)
        if (cart?.hasPrescription) {
            if (window.PrescriptionHandler) {
                const checkResult = await window.PrescriptionHandler.canProceedToCheckout(cart);
                if (!checkResult.canCheckout) {
                    this.showToast(checkResult.reason || 'ต้องได้รับการอนุมัติจากเภสัชกรก่อน', 'warning');
                    window.PrescriptionHandler.showCheckoutBlockedModal(
                        cart,
                        checkResult,
                        (items) => this.requestPrescriptionConsultation(items),
                        () => {}
                    );
                    return;
                }
            }
        }

        // Set submitting state
        this.checkoutState.isSubmitting = true;

        // Show loading state (Requirement 3.8)
        const submitBtn = document.getElementById('place-order-btn');
        const submitText = document.getElementById('place-order-text');
        const submitLoading = document.getElementById('place-order-loading');
        
        if (submitBtn) submitBtn.disabled = true;
        if (submitText) submitText.classList.add('hidden');
        if (submitLoading) submitLoading.classList.remove('hidden');

        try {
            const profile = window.store?.get('profile');
            const formData = this.getCheckoutFormData();
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'transfer';

            // Prepare cart items for API
            const cartItems = cart.items.map(item => ({
                product_id: item.product_id,
                name: item.name,
                price: item.price,
                quantity: item.quantity
            }));

            // Prepare order data - include cart items directly
            const orderData = {
                action: 'create_order',
                line_user_id: profile?.userId,
                line_account_id: this.config.ACCOUNT_ID,
                display_name: profile?.displayName || formData.name,
                address: formData,
                payment_method: paymentMethod,
                coupon_code: this.checkoutState.promoCode || null,
                prescription_approval_id: cart?.prescriptionApprovalId || null,
                cart_items: cartItems,
                subtotal: cart.subtotal,
                shipping: cart.shipping,
                total: cart.total
            };

            console.log('🛒 Sending order:', orderData);

            // Create order via API
            const response = await this.fetchWithRetry(`${this.config.BASE_URL}/api/checkout.php`, {
                method: 'POST',
                body: JSON.stringify(orderData)
            });

            const result = await response.json();

            if (result.success) {
                // Clear cart
                window.store?.clearCart();

                // Send LIFF message via LiffMessageBridge (with API fallback)
                if (window.liffMessageBridge) {
                    try {
                        await window.liffMessageBridge.sendOrderPlaced(result.order_number, {
                            total: cart.total,
                            items: cart.items.length
                        });
                    } catch (e) {
                        console.warn('Failed to send order message:', e);
                    }
                }

                // Show success and navigate to order confirmation
                this.showOrderConfirmation(result);
            } else {
                throw new Error(result.message || 'ไม่สามารถสร้างคำสั่งซื้อได้');
            }

        } catch (error) {
            console.error('Order placement error:', error);
            this.showToast(error.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            
            // Reset button state
            if (submitBtn) submitBtn.disabled = false;
            if (submitText) submitText.classList.remove('hidden');
            if (submitLoading) submitLoading.classList.add('hidden');
        } finally {
            this.checkoutState.isSubmitting = false;
        }
    }

    /**
     * Show order confirmation modal/page
     * @param {Object} orderResult - Order creation result from API
     */
    showOrderConfirmation(orderResult) {
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value || 'transfer';
        
        // For transfer/promptpay, show payment instructions
        if (paymentMethod === 'transfer' || paymentMethod === 'promptpay') {
            this.showPaymentInstructions(orderResult, paymentMethod);
        } else {
            // For COD, show success message
            this.showOrderSuccess(orderResult);
        }
    }

    /**
     * Show payment instructions modal
     */
    showPaymentInstructions(orderResult, paymentMethod) {
        const modalHtml = `
            <div class="modal order-success-modal">
                <div class="modal-header">
                    <h3 class="modal-title">สั่งซื้อสำเร็จ!</h3>
                    <button class="modal-close" onclick="window.liffApp.hideModal(); window.router.navigate('/orders');">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="order-success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <p class="order-success-number">หมายเลขคำสั่งซื้อ: <strong>#${orderResult.order_number}</strong></p>
                    <p class="order-success-total">ยอดชำระ: <strong>฿${this.formatNumber(orderResult.total)}</strong></p>
                    
                    <div class="payment-instructions">
                        <h4>ขั้นตอนการชำระเงิน</h4>
                        ${paymentMethod === 'transfer' ? `
                            <div class="bank-info">
                                <p><strong>ธนาคารกสิกรไทย</strong></p>
                                <p>ชื่อบัญชี: บริษัท ร้านยา จำกัด</p>
                                <p>เลขบัญชี: <span class="bank-account">xxx-x-xxxxx-x</span></p>
                            </div>
                        ` : `
                            <div class="promptpay-info">
                                <p>สแกน QR Code ด้านล่างเพื่อชำระเงิน</p>
                                <div class="promptpay-qr">
                                    <img src="${this.config.BASE_URL}/api/checkout.php?action=promptpay_qr&amount=${orderResult.total}" 
                                         alt="PromptPay QR"
                                         onerror="this.style.display='none'">
                                </div>
                            </div>
                        `}
                        <p class="payment-note">หลังโอนเงินแล้ว กรุณาอัพโหลดสลิปในหน้าออเดอร์</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary btn-block" onclick="window.liffApp.hideModal(); window.router.navigate('/orders');">
                        ดูคำสั่งซื้อ
                    </button>
                </div>
            </div>
        `;
        
        this.showModal(modalHtml);
    }

    /**
     * Show order success for COD
     */
    showOrderSuccess(orderResult) {
        const modalHtml = `
            <div class="modal order-success-modal">
                <div class="modal-header">
                    <h3 class="modal-title">สั่งซื้อสำเร็จ!</h3>
                    <button class="modal-close" onclick="window.liffApp.hideModal(); window.router.navigate('/orders');">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="order-success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <p class="order-success-number">หมายเลขคำสั่งซื้อ: <strong>#${orderResult.order_number}</strong></p>
                    <p class="order-success-total">ยอดชำระ: <strong>฿${this.formatNumber(orderResult.total)}</strong></p>
                    <p class="order-success-method">ชำระเงินปลายทาง (COD)</p>
                    <p class="order-success-note">ทางร้านจะติดต่อกลับเพื่อยืนยันคำสั่งซื้อ</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary btn-block" onclick="window.liffApp.hideModal(); window.router.navigate('/orders');">
                        ดูคำสั่งซื้อ
                    </button>
                </div>
            </div>
        `;
        
        this.showModal(modalHtml);
    }

    /**
     * Apply promo code
     * Requirements: 17.4, 17.5, 17.6, 17.7
     * - Add promo code input field
     * - Validate code via API
     * - Apply discount or show error
     */
    async applyPromoCode() {
        const promoInput = document.getElementById('promo-code');
        const promoBtn = document.getElementById('apply-promo-btn');
        const promoResult = document.getElementById('promo-result');
        
        const code = promoInput?.value.trim().toUpperCase();
        
        if (!code) {
            this.showPromoResult('กรุณากรอกโค้ดส่วนลด', 'error');
            return;
        }

        // Prevent duplicate requests
        if (this.checkoutState.promoLoading) return;
        this.checkoutState.promoLoading = true;

        // Show loading state
        if (promoBtn) {
            promoBtn.disabled = true;
            promoBtn.innerHTML = '<div class="btn-spinner-sm"></div>';
        }

        try {
            const profile = window.store?.get('profile');
            const cart = window.store?.get('cart');
            
            // Validate promo code via API (Requirement 17.5)
            const response = await this.fetchWithRetry(`${this.config.BASE_URL}/api/checkout.php`, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'validate_promo',
                    code: code,
                    line_user_id: profile?.userId,
                    line_account_id: this.config.ACCOUNT_ID,
                    subtotal: cart.subtotal
                })
            });

            const result = await response.json();

            if (result.success && result.valid) {
                // Apply discount (Requirement 17.6)
                this.checkoutState.promoCode = code;
                this.checkoutState.promoDiscount = parseFloat(result.discount) || 0;
                this.checkoutState.promoError = '';
                
                // Update cart discount
                const cartObj = window.store.get('cart');
                cartObj.discount = this.checkoutState.promoDiscount;
                cartObj.couponCode = code;
                window.store.recalculateCart(cartObj);
                window.store.set('cart', cartObj);
                
                // Update UI
                this.updateCheckoutTotals();
                this.showPromoResult(`ใช้โค้ดสำเร็จ! ลด ฿${this.formatNumber(this.checkoutState.promoDiscount)}`, 'success');
                
                // Disable input after successful apply
                if (promoInput) promoInput.disabled = true;
                if (promoBtn) {
                    promoBtn.textContent = 'ยกเลิก';
                    promoBtn.onclick = () => this.removePromoCode();
                }
            } else {
                // Show error (Requirement 17.7)
                this.checkoutState.promoError = result.message || 'โค้ดไม่ถูกต้องหรือหมดอายุ';
                this.showPromoResult(this.checkoutState.promoError, 'error');
            }

        } catch (error) {
            console.error('Promo code validation error:', error);
            this.showPromoResult('ไม่สามารถตรวจสอบโค้ดได้ กรุณาลองใหม่', 'error');
        } finally {
            this.checkoutState.promoLoading = false;
            if (promoBtn && !this.checkoutState.promoCode) {
                promoBtn.disabled = false;
                promoBtn.innerHTML = 'ใช้โค้ด';
            }
        }
    }

    /**
     * Remove applied promo code
     */
    removePromoCode() {
        const promoInput = document.getElementById('promo-code');
        const promoBtn = document.getElementById('apply-promo-btn');
        
        // Clear promo state
        this.checkoutState.promoCode = '';
        this.checkoutState.promoDiscount = 0;
        this.checkoutState.promoError = '';
        
        // Update cart
        const cart = window.store.get('cart');
        cart.discount = 0;
        cart.couponCode = null;
        window.store.recalculateCart(cart);
        window.store.set('cart', cart);
        
        // Reset UI
        if (promoInput) {
            promoInput.value = '';
            promoInput.disabled = false;
        }
        if (promoBtn) {
            promoBtn.textContent = 'ใช้โค้ด';
            promoBtn.onclick = () => this.applyPromoCode();
        }
        
        this.updateCheckoutTotals();
        this.showPromoResult('', '');
    }

    /**
     * Show promo code result message
     * @param {string} message - Message to display
     * @param {string} type - 'success' or 'error'
     */
    showPromoResult(message, type) {
        const promoResult = document.getElementById('promo-result');
        if (promoResult) {
            promoResult.textContent = message;
            promoResult.className = `promo-result ${type}`;
        }
    }

    /**
     * Update checkout totals display
     */
    updateCheckoutTotals() {
        const cart = window.store?.get('cart') || { subtotal: 0, discount: 0, shipping: 0, total: 0 };
        
        const subtotalEl = document.getElementById('checkout-subtotal');
        const promoRow = document.getElementById('checkout-promo-row');
        const promoDiscountEl = document.getElementById('checkout-promo-discount');
        const shippingEl = document.getElementById('checkout-shipping');
        const grandTotalEl = document.getElementById('checkout-grand-total');
        
        if (subtotalEl) subtotalEl.textContent = `฿${this.formatNumber(cart.subtotal)}`;
        
        if (promoRow && promoDiscountEl) {
            if (cart.discount > 0) {
                promoRow.classList.remove('hidden');
                promoDiscountEl.textContent = `-฿${this.formatNumber(cart.discount)}`;
            } else {
                promoRow.classList.add('hidden');
            }
        }
        
        if (shippingEl) {
            shippingEl.textContent = cart.shipping > 0 ? `฿${this.formatNumber(cart.shipping)}` : 'ฟรี';
        }
        
        if (grandTotalEl) {
            grandTotalEl.textContent = `฿${this.formatNumber(cart.total)}`;
        }
    }

    /**
     * Render orders page
     * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 19.1, 19.2, 19.3, 19.4
     * - Display orders in timeline/card view sorted by date descending
     * - Show status badge with color coding
     * - Expandable order details
     * - Re-order functionality
     * - Delivery tracking
     */
    renderOrdersPage() {
        // Show skeleton loading initially
        setTimeout(() => this.loadOrders(), 100);
        
        return `
            <div class="orders-page">
                <!-- Header -->
                <div class="orders-page-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">ออเดอร์ของฉัน</h1>
                    <div class="header-spacer"></div>
                </div>
                
                <!-- Filter Tabs -->
                <div class="orders-filter-tabs">
                    <button class="orders-filter-tab active" data-status="all" onclick="window.liffApp.filterOrders('all')">
                        ทั้งหมด
                    </button>
                    <button class="orders-filter-tab" data-status="pending" onclick="window.liffApp.filterOrders('pending')">
                        รอดำเนินการ
                    </button>
                    <button class="orders-filter-tab" data-status="shipping" onclick="window.liffApp.filterOrders('shipping')">
                        กำลังจัดส่ง
                    </button>
                    <button class="orders-filter-tab" data-status="completed" onclick="window.liffApp.filterOrders('completed')">
                        สำเร็จ
                    </button>
                </div>
                
                <!-- Orders List -->
                <div id="orders-list" class="orders-list">
                    ${window.Skeleton ? window.Skeleton.orderCards(3) : this.renderOrdersSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render orders skeleton loading
     */
    renderOrdersSkeleton() {
        return `
            <div class="skeleton-order-card">
                <div class="skeleton-order-header">
                    <div class="skeleton skeleton-text skeleton-order-id"></div>
                    <div class="skeleton skeleton-badge skeleton-order-status"></div>
                </div>
                <div class="skeleton-order-date">
                    <div class="skeleton skeleton-text skeleton-date"></div>
                </div>
                <div class="skeleton-order-items">
                    <div class="skeleton-order-item">
                        <div class="skeleton skeleton-item-image"></div>
                        <div class="skeleton-item-info">
                            <div class="skeleton skeleton-text skeleton-item-name"></div>
                            <div class="skeleton skeleton-text skeleton-item-qty"></div>
                        </div>
                    </div>
                </div>
                <div class="skeleton-order-footer">
                    <div class="skeleton skeleton-text skeleton-order-total"></div>
                    <div class="skeleton skeleton-button skeleton-order-action"></div>
                </div>
            </div>
        `.repeat(3);
    }

    /**
     * Load orders from API
     * Requirement 4.1 - Sort by date descending
     */
    async loadOrders(status = 'all') {
        const container = document.getElementById('orders-list');
        if (!container) return;

        const profile = window.store?.get('profile');
        
        if (!profile) {
            container.innerHTML = this.renderOrdersLoginRequired();
            return;
        }

        try {
            const url = `${this.config.BASE_URL}/api/orders.php?action=my_orders&line_user_id=${profile.userId}&line_account_id=${this.config.ACCOUNT_ID}${status !== 'all' ? `&status=${status}` : ''}`;
            
            const response = await this.fetchWithRetry(url);
            const data = await response.json();

            if (data.success && data.orders && data.orders.length > 0) {
                // Sort by date descending (Requirement 4.1)
                const sortedOrders = data.orders.sort((a, b) => {
                    return new Date(b.created_at) - new Date(a.created_at);
                });
                
                // Store orders for filtering
                this.currentOrders = sortedOrders;
                
                container.innerHTML = this.renderOrdersList(sortedOrders);
            } else {
                container.innerHTML = this.renderOrdersEmptyState();
            }
        } catch (error) {
            console.error('Error loading orders:', error);
            container.innerHTML = this.renderOrdersError();
        }
    }

    /**
     * Filter orders by status
     */
    filterOrders(status) {
        // Update active tab
        document.querySelectorAll('.orders-filter-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.status === status);
        });

        // Reload orders with filter
        this.loadOrders(status);
    }

    /**
     * Render orders list
     */
    renderOrdersList(orders) {
        return orders.map(order => this.renderOrderCard(order)).join('');
    }

    /**
     * Render single order card
     * Requirements: 4.2, 4.3, 4.6
     */
    renderOrderCard(order) {
        const orderId = order.order_number || order.order_id || order.id;
        const status = this.normalizeOrderStatus(order.status);
        const statusBadge = this.getOrderStatusBadge(status);
        const date = this.formatOrderDate(order.created_at);
        const items = order.items || [];
        const total = parseFloat(order.grand_total || order.total_amount || 0);
        const itemCount = items.reduce((sum, item) => sum + (parseInt(item.quantity) || 1), 0);
        
        // Get preview images (max 3)
        const previewImages = items.slice(0, 3).map(item => item.image || item.image_url || '');
        const moreCount = items.length > 3 ? items.length - 3 : 0;

        return `
            <div class="order-card" id="order-${order.id}" data-order-id="${order.id}">
                <!-- Header with Order Number and Status -->
                <div class="order-card-header" onclick="window.liffApp.toggleOrderDetails(${order.id})">
                    <div class="order-card-info">
                        <div class="order-card-number">#${orderId}</div>
                        <div class="order-card-date">${date}</div>
                    </div>
                    ${statusBadge}
                </div>
                
                <!-- Preview Section -->
                <div class="order-card-preview" onclick="window.liffApp.toggleOrderDetails(${order.id})">
                    <div class="order-preview-images">
                        ${previewImages.map(img => `
                            <img src="${img || 'assets/images/image-placeholder.svg'}" 
                                 class="order-preview-image" 
                                 alt="Product"
                                 onerror="this.src='assets/images/image-placeholder.svg'">
                        `).join('')}
                        ${moreCount > 0 ? `<div class="order-preview-more">+${moreCount}</div>` : ''}
                    </div>
                    <div class="order-preview-info">
                        <div class="order-preview-count">${itemCount} รายการ</div>
                        <div class="order-preview-total">฿${this.formatNumber(total)}</div>
                    </div>
                    <i class="fas fa-chevron-down order-card-expand"></i>
                </div>
                
                <!-- Expandable Details (Requirement 4.3) -->
                <div class="order-card-details">
                    <div class="order-details-content">
                        ${this.renderOrderItems(items)}
                        ${this.renderOrderSummary(order)}
                        ${this.renderOrderAddress(order)}
                        ${this.renderOrderTracking(order)}
                        ${this.renderOrderActions(order, status)}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Toggle order details expansion
     * Requirement 4.3
     */
    toggleOrderDetails(orderId) {
        const card = document.getElementById(`order-${orderId}`);
        if (card) {
            card.classList.toggle('expanded');
        }
    }

    /**
     * Normalize order status to standard values
     */
    normalizeOrderStatus(status) {
        const statusMap = {
            'pending': 'pending',
            'รอดำเนินการ': 'pending',
            'confirmed': 'confirmed',
            'ยืนยันแล้ว': 'confirmed',
            'packing': 'packing',
            'กำลังแพ็ค': 'packing',
            'shipping': 'shipping',
            'shipped': 'shipping',
            'กำลังจัดส่ง': 'shipping',
            'delivered': 'delivered',
            'completed': 'completed',
            'สำเร็จ': 'completed',
            'cancelled': 'cancelled',
            'ยกเลิก': 'cancelled'
        };
        return statusMap[status?.toLowerCase()] || 'pending';
    }

    /**
     * Get order status badge HTML
     * Requirement 4.2, 4.6 - Status badge with color coding
     */
    getOrderStatusBadge(status) {
        const statusConfig = this.getStatusConfig();
        const config = statusConfig[status] || statusConfig['pending'];
        
        return `
            <span class="order-status-badge ${config.class}">
                <i class="fas ${config.icon}"></i>
                ${config.label}
            </span>
        `;
    }

    /**
     * Get status configuration object
     * Centralized status config for badge display and updates
     * Requirement 4.2, 4.6 - Status colors: Yellow=Pending, Blue=Packing, Purple=Shipping, Green=Completed
     */
    getStatusConfig() {
        return {
            'pending': { label: 'รอดำเนินการ', icon: 'fa-clock', class: 'pending' },
            'confirmed': { label: 'ยืนยันแล้ว', icon: 'fa-check', class: 'confirmed' },
            'packing': { label: 'กำลังแพ็ค', icon: 'fa-box', class: 'packing' },
            'shipping': { label: 'กำลังจัดส่ง', icon: 'fa-truck', class: 'shipping' },
            'delivered': { label: 'จัดส่งแล้ว', icon: 'fa-check-circle', class: 'delivered' },
            'completed': { label: 'สำเร็จ', icon: 'fa-check-circle', class: 'completed' },
            'cancelled': { label: 'ยกเลิก', icon: 'fa-times-circle', class: 'cancelled' }
        };
    }

    /**
     * Update order status badge dynamically
     * Requirement 4.6 - Update badge on status change
     * @param {number|string} orderId - The order ID
     * @param {string} newStatus - The new status value
     */
    updateOrderStatusBadge(orderId, newStatus) {
        const card = document.getElementById(`order-${orderId}`);
        if (!card) return false;

        const normalizedStatus = this.normalizeOrderStatus(newStatus);
        const badge = card.querySelector('.order-status-badge');
        
        if (badge) {
            // Remove all status classes
            const statusClasses = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'completed', 'cancelled'];
            statusClasses.forEach(cls => badge.classList.remove(cls));
            
            // Add new status class
            const statusConfig = this.getStatusConfig();
            const config = statusConfig[normalizedStatus] || statusConfig['pending'];
            
            badge.classList.add(config.class);
            badge.innerHTML = `<i class="fas ${config.icon}"></i> ${config.label}`;
            
            // Add animation for visual feedback
            badge.classList.add('status-updated');
            setTimeout(() => badge.classList.remove('status-updated'), 500);
            
            // Update the delivery timeline if expanded
            this.updateOrderTimeline(orderId, normalizedStatus);
            
            return true;
        }
        return false;
    }

    /**
     * Update order timeline when status changes
     * @param {number|string} orderId - The order ID
     * @param {string} status - The normalized status
     */
    updateOrderTimeline(orderId, status) {
        const card = document.getElementById(`order-${orderId}`);
        if (!card) return;

        const timeline = card.querySelector('.delivery-timeline');
        if (!timeline) return;

        const statusOrder = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'completed'];
        const currentIndex = statusOrder.indexOf(status);
        
        const timelineItems = timeline.querySelectorAll('.timeline-item');
        timelineItems.forEach((item, index) => {
            const isCompleted = currentIndex >= index || (status === 'completed' && index <= 4);
            const isCurrent = currentIndex === index;
            
            item.classList.toggle('completed', isCompleted);
            item.classList.toggle('current', isCurrent);
        });
    }

    /**
     * Render order items list
     */
    renderOrderItems(items) {
        if (!items || items.length === 0) return '';
        
        return `
            <div class="order-items-section">
                <div class="order-items-title">รายการสินค้า</div>
                ${items.map(item => `
                    <div class="order-item">
                        <img src="${item.image || item.image_url || 'assets/images/image-placeholder.svg'}" 
                             class="order-item-image" 
                             alt="${item.name || item.product_name || 'Product'}"
                             onerror="this.src='assets/images/image-placeholder.svg'">
                        <div class="order-item-info">
                            <div class="order-item-name">${item.name || item.product_name || 'สินค้า'}</div>
                            <div class="order-item-meta">
                                <span class="order-item-qty">x${item.quantity || 1}</span>
                            </div>
                        </div>
                        <div class="order-item-price">฿${this.formatNumber(parseFloat(item.price || item.product_price || 0) * (item.quantity || 1))}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Render order summary
     */
    renderOrderSummary(order) {
        const subtotal = parseFloat(order.subtotal || order.total_amount || 0);
        const discount = parseFloat(order.discount || 0);
        const shipping = parseFloat(order.shipping_fee || order.shipping || 0);
        const total = parseFloat(order.grand_total || order.total_amount || 0);
        
        return `
            <div class="order-summary-section">
                <div class="order-summary-row">
                    <span class="order-summary-label">ยอดรวมสินค้า</span>
                    <span class="order-summary-value">฿${this.formatNumber(subtotal)}</span>
                </div>
                ${discount > 0 ? `
                    <div class="order-summary-row">
                        <span class="order-summary-label">ส่วนลด</span>
                        <span class="order-summary-value text-danger">-฿${this.formatNumber(discount)}</span>
                    </div>
                ` : ''}
                <div class="order-summary-row">
                    <span class="order-summary-label">ค่าจัดส่ง</span>
                    <span class="order-summary-value">${shipping > 0 ? `฿${this.formatNumber(shipping)}` : 'ฟรี'}</span>
                </div>
                <div class="order-summary-row total">
                    <span class="order-summary-label">ยอดรวมทั้งหมด</span>
                    <span class="order-summary-value">฿${this.formatNumber(total)}</span>
                </div>
            </div>
        `;
    }

    /**
     * Render order delivery address
     */
    renderOrderAddress(order) {
        const deliveryInfo = order.delivery_info || {};
        const address = deliveryInfo.address || order.shipping_address || order.address || '';
        const name = deliveryInfo.name || order.customer_name || '';
        const phone = deliveryInfo.phone || order.customer_phone || '';
        
        if (!address && !name) return '';
        
        return `
            <div class="order-address-section">
                <div class="order-items-title">ที่อยู่จัดส่ง</div>
                <div class="order-address-card">
                    <div class="order-address-header">
                        <div class="order-address-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="order-address-title">${name || 'ผู้รับ'}</div>
                    </div>
                    <div class="order-address-text">
                        ${phone ? `<div>${phone}</div>` : ''}
                        ${address}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render order tracking section
     * Requirements: 19.1, 19.2, 19.3, 19.4
     */
    renderOrderTracking(order) {
        const status = this.normalizeOrderStatus(order.status);
        const trackingNumber = order.tracking_number || order.delivery_info?.tracking_number;
        const carrier = order.carrier || order.delivery_info?.carrier || 'ขนส่ง';
        
        // Define timeline stages
        const stages = [
            { key: 'pending', label: 'สั่งซื้อสำเร็จ', icon: 'fa-shopping-cart' },
            { key: 'confirmed', label: 'ยืนยันออเดอร์', icon: 'fa-check' },
            { key: 'packing', label: 'กำลังเตรียมสินค้า', icon: 'fa-box' },
            { key: 'shipping', label: 'กำลังจัดส่ง', icon: 'fa-truck' },
            { key: 'delivered', label: 'จัดส่งสำเร็จ', icon: 'fa-check-circle' }
        ];
        
        const statusOrder = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'completed'];
        const currentIndex = statusOrder.indexOf(status);
        
        return `
            <div class="order-tracking-section">
                <div class="order-items-title">สถานะการจัดส่ง</div>
                <div class="order-tracking-card">
                    ${trackingNumber ? `
                        <div class="order-tracking-header">
                            <div class="order-tracking-info">
                                <div class="order-tracking-icon">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <div>
                                    <div class="order-tracking-title">${carrier}</div>
                                    <div class="order-tracking-number">${trackingNumber}</div>
                                </div>
                            </div>
                            <button class="order-tracking-link" onclick="window.liffApp.openTrackingPage('${trackingNumber}', '${carrier}')">
                                <i class="fas fa-external-link-alt"></i>
                                ติดตาม
                            </button>
                        </div>
                    ` : ''}
                    
                    <!-- Delivery Timeline (Requirement 19.2) -->
                    <div class="delivery-timeline">
                        ${stages.map((stage, index) => {
                            const isCompleted = currentIndex >= index || (status === 'completed' && index <= 4);
                            const isCurrent = currentIndex === index;
                            const stageTime = this.getStageTime(order, stage.key);
                            
                            return `
                                <div class="timeline-item ${isCompleted ? 'completed' : ''} ${isCurrent ? 'current' : ''}">
                                    <div class="timeline-dot"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">${stage.label}</div>
                                        ${stageTime ? `<div class="timeline-time">${stageTime}</div>` : ''}
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Get stage timestamp
     */
    getStageTime(order, stage) {
        const timeMap = {
            'pending': order.created_at,
            'confirmed': order.confirmed_at,
            'packing': order.packing_at,
            'shipping': order.shipped_at,
            'delivered': order.delivered_at
        };
        
        const time = timeMap[stage];
        return time ? this.formatOrderDateTime(time) : '';
    }

    /**
     * Open tracking page
     * Requirement 19.4 - Link to carrier tracking page
     */
    openTrackingPage(trackingNumber, carrier) {
        const carrierUrls = {
            'kerry': `https://th.kerryexpress.com/th/track/?track=${trackingNumber}`,
            'flash': `https://www.flashexpress.co.th/tracking/?se=${trackingNumber}`,
            'j&t': `https://www.jtexpress.co.th/index/query/gzquery.html?billcode=${trackingNumber}`,
            'thailand post': `https://track.thailandpost.co.th/?trackNumber=${trackingNumber}`,
            'ems': `https://track.thailandpost.co.th/?trackNumber=${trackingNumber}`,
            'ninja van': `https://www.ninjavan.co/th-th/tracking?id=${trackingNumber}`,
            'best express': `https://www.best-inc.co.th/track?bills=${trackingNumber}`,
            'scg express': `https://www.scgexpress.co.th/tracking/detail/${trackingNumber}`
        };
        
        const carrierLower = carrier.toLowerCase();
        let url = carrierUrls[carrierLower];
        
        // Default to Google search if carrier not found
        if (!url) {
            url = `https://www.google.com/search?q=${encodeURIComponent(carrier + ' tracking ' + trackingNumber)}`;
        }
        
        window.open(url, '_blank');
    }

    /**
     * Render order actions
     * Requirements: 4.4, 4.5 - Re-order functionality
     */
    renderOrderActions(order, status) {
        const canReorder = ['delivered', 'completed'].includes(status);
        const orderId = order.id || order.order_id;
        
        return `
            <div class="order-actions">
                ${canReorder ? `
                    <button class="order-action-btn reorder" onclick="window.liffApp.reorderItems('${orderId}')">
                        <i class="fas fa-redo"></i>
                        สั่งซื้ออีกครั้ง
                    </button>
                ` : ''}
                <button class="order-action-btn secondary" onclick="window.liffApp.viewOrderDetail('${orderId}')">
                    <i class="fas fa-eye"></i>
                    ดูรายละเอียด
                </button>
            </div>
        `;
    }

    /**
     * Re-order items from a previous order
     * Requirements: 4.4, 4.5 - Add all items to cart and navigate to checkout
     * 
     * This function:
     * 1. Finds the order from currentOrders or fetches from API
     * 2. Adds all items from the order to the cart
     * 3. Navigates to checkout page
     */
    async reorderItems(orderId) {
        // Try to find order in currentOrders (supports both id and order_id)
        let order = this.currentOrders?.find(o => 
            o.id === orderId || 
            o.order_id === orderId || 
            String(o.id) === String(orderId)
        );
        
        // If not found in currentOrders, try to fetch from API
        if (!order || !order.items || order.items.length === 0) {
            try {
                const profile = window.store?.get('profile');
                if (profile?.userId) {
                    const url = `${this.config.BASE_URL}/api/orders.php?action=detail&order_id=${orderId}&line_user_id=${profile.userId}`;
                    const response = await this.fetchWithRetry(url);
                    const data = await response.json();
                    if (data.success && data.order) {
                        order = data.order;
                    }
                }
            } catch (error) {
                console.error('Error fetching order for reorder:', error);
            }
        }
        
        // Validate order has items
        if (!order || !order.items || order.items.length === 0) {
            this.showToast('ไม่พบข้อมูลออเดอร์หรือไม่มีสินค้าในออเดอร์', 'error');
            return;
        }

        // Show loading toast
        this.showToast('กำลังเพิ่มสินค้าลงตะกร้า...', 'info');

        try {
            let addedCount = 0;
            let skippedCount = 0;
            
            // Add each item to cart (Requirement 4.4)
            for (const item of order.items) {
                // Validate item has required data
                const productId = item.product_id || item.id;
                if (!productId) {
                    skippedCount++;
                    continue;
                }
                
                const product = {
                    id: productId,
                    name: item.name || item.product_name || 'สินค้า',
                    price: parseFloat(item.price || item.product_price || 0),
                    image_url: item.image || item.image_url || '',
                    is_prescription: Boolean(item.is_prescription)
                };
                
                const quantity = parseInt(item.quantity) || 1;
                window.store?.addToCart(product, quantity);
                addedCount++;
            }

            // Show success message with count
            if (addedCount > 0) {
                this.showToast(`เพิ่ม ${addedCount} รายการลงตะกร้าแล้ว`, 'success');
                
                // Navigate to checkout (Requirement 4.5)
                setTimeout(() => {
                    window.router.navigate('/checkout');
                }, 500);
            } else {
                this.showToast('ไม่สามารถเพิ่มสินค้าได้', 'error');
            }

        } catch (error) {
            console.error('Error re-ordering:', error);
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        }
    }

    /**
     * View order detail page
     */
    viewOrderDetail(orderId) {
        window.router.navigate(`/order/${orderId}`);
    }

    /**
     * Render empty orders state
     * Requirement 4.7
     */
    renderOrdersEmptyState() {
        return `
            <div class="orders-empty-state">
                <div class="orders-empty-icon">
                    <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="60" cy="60" r="50" fill="#F1F5F9"/>
                        <path d="M40 45h40v35H40z" fill="#E2E8F0"/>
                        <path d="M45 50h30v5H45zM45 60h20v3H45zM45 68h25v3H45z" fill="#CBD5E1"/>
                        <circle cx="85" cy="75" r="15" fill="#11B0A6"/>
                        <path d="M80 75h10M85 70v10" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 class="orders-empty-title">ยังไม่มีออเดอร์</h2>
                <p class="orders-empty-desc">เริ่มช้อปปิ้งเพื่อดูประวัติการสั่งซื้อของคุณที่นี่</p>
                <button class="btn btn-primary" onclick="window.router.navigate('/shop')">
                    <i class="fas fa-shopping-bag"></i>
                    เริ่มช้อปปิ้ง
                </button>
            </div>
        `;
    }

    /**
     * Render login required state
     */
    renderOrdersLoginRequired() {
        return `
            <div class="orders-empty-state">
                <div class="orders-empty-icon">
                    <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="60" cy="60" r="50" fill="#F1F5F9"/>
                        <circle cx="60" cy="45" r="15" fill="#E2E8F0"/>
                        <path d="M35 85c0-14 11-25 25-25s25 11 25 25" fill="#E2E8F0"/>
                    </svg>
                </div>
                <h2 class="orders-empty-title">กรุณาเข้าสู่ระบบ</h2>
                <p class="orders-empty-desc">เข้าสู่ระบบเพื่อดูประวัติการสั่งซื้อของคุณ</p>
                <button class="btn btn-primary" onclick="window.liffApp.login()">
                    <i class="fab fa-line"></i>
                    เข้าสู่ระบบ LINE
                </button>
            </div>
        `;
    }

    // ==================== Order Detail Page ====================
    // Requirements: 19.1, 19.2, 19.3, 19.4 - Delivery Tracking

    /**
     * Render order detail page with delivery tracking
     * Requirements: 19.1, 19.2, 19.3, 19.4
     * - Display delivery timeline (19.2)
     * - Show tracking number and carrier (19.1, 19.3)
     * - Link to carrier tracking page (19.4)
     */
    renderOrderDetailPage(params) {
        const orderId = params?.orderId || params?.id;
        
        if (!orderId) {
            return this.renderOrderDetailError('ไม่พบหมายเลขออเดอร์');
        }
        
        // Load order detail after render
        setTimeout(() => this.loadOrderDetail(orderId), 100);
        
        return `
            <div class="order-detail-page">
                <!-- Header -->
                <div class="order-detail-header">
                    <button class="back-btn" onclick="window.router.navigate('/orders')">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">รายละเอียดออเดอร์</h1>
                    <div class="header-spacer"></div>
                </div>
                
                <!-- Content -->
                <div id="order-detail-content" class="order-detail-content">
                    ${this.renderOrderDetailSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Load order detail from API
     */
    async loadOrderDetail(orderId) {
        const contentEl = document.getElementById('order-detail-content');
        if (!contentEl) return;
        
        try {
            const profile = window.store?.get('profile');
            const lineUserId = profile?.userId || '';
            const lineAccountId = this.config.ACCOUNT_ID || 1;
            
            const response = await fetch(
                `${this.config.BASE_URL}/api/checkout.php?action=order&order_id=${orderId}&line_user_id=${lineUserId}&line_account_id=${lineAccountId}`
            );
            
            const data = await response.json();
            
            if (data.success && data.order) {
                contentEl.innerHTML = this.renderOrderDetailContent(data.order);
            } else {
                contentEl.innerHTML = this.renderOrderDetailError(data.message || 'ไม่พบข้อมูลออเดอร์');
            }
        } catch (error) {
            console.error('Error loading order detail:', error);
            contentEl.innerHTML = this.renderOrderDetailError('ไม่สามารถโหลดข้อมูลได้');
        }
    }

    /**
     * Render order detail content
     */
    renderOrderDetailContent(order) {
        const status = this.normalizeOrderStatus(order.status);
        const paymentStatus = order.payment_status || 'pending';
        const statusConfig = this.getStatusConfig(status);
        const orderNumber = order.order_number || order.order_id || order.id;
        const items = order.items || [];
        const paymentMethod = order.payment_method || 'transfer';
        
        // Check if needs slip upload (transfer/promptpay and not paid)
        const needsSlipUpload = ['transfer', 'promptpay'].includes(paymentMethod) && paymentStatus === 'pending';
        
        return `
            <!-- Order Header Card -->
            <div class="order-detail-card">
                <div class="order-detail-order-header">
                    <div class="order-detail-order-info">
                        <div class="order-detail-order-number">#${orderNumber}</div>
                        <div class="order-detail-order-date">${this.formatOrderDate(order.created_at)}</div>
                    </div>
                    <span class="order-status-badge ${status}" style="background: ${statusConfig.bgColor}; color: ${statusConfig.textColor}">
                        ${statusConfig.label}
                    </span>
                </div>
            </div>
            
            <!-- Payment Slip Upload Section -->
            ${needsSlipUpload ? this.renderSlipUploadSection(order) : ''}
            
            <!-- Delivery Tracking Section - Requirements 19.1, 19.2, 19.3, 19.4 -->
            ${this.renderDeliveryTrackingSection(order)}
            
            <!-- Order Items -->
            <div class="order-detail-card">
                <div class="order-detail-section-title">รายการสินค้า</div>
                ${this.renderOrderDetailItems(items)}
            </div>
            
            <!-- Order Summary -->
            <div class="order-detail-card">
                <div class="order-detail-section-title">สรุปยอดชำระ</div>
                ${this.renderOrderDetailSummary(order)}
            </div>
            
            <!-- Shipping Address -->
            ${this.renderOrderDetailAddress(order)}
            
            <!-- Actions -->
            <div class="order-detail-actions">
                ${['delivered', 'completed'].includes(status) ? `
                    <button class="btn btn-primary btn-block" onclick="window.liffApp.reorderItems('${order.id || order.order_id}')">
                        <i class="fas fa-redo"></i>
                        สั่งซื้ออีกครั้ง
                    </button>
                ` : ''}
                <button class="btn btn-secondary btn-block" onclick="window.liffApp.contactSupport('${orderNumber}')">
                    <i class="fas fa-headset"></i>
                    ติดต่อเรา
                </button>
            </div>
        `;
    }

    /**
     * Render slip upload section
     */
    renderSlipUploadSection(order) {
        const orderNumber = order.order_number || order.order_id || order.id;
        const orderId = order.id || order.order_id;
        const total = parseFloat(order.grand_total || order.total_amount || 0);
        const paymentMethod = order.payment_method || 'transfer';
        
        return `
            <div class="order-detail-card slip-upload-card">
                <div class="order-detail-section-title">
                    <i class="fas fa-receipt"></i>
                    แจ้งชำระเงิน
                </div>
                
                <div class="payment-info-box">
                    <div class="payment-amount">
                        <span class="payment-amount-label">ยอดที่ต้องชำระ</span>
                        <span class="payment-amount-value">฿${this.formatNumber(total)}</span>
                    </div>
                    
                    ${paymentMethod === 'transfer' ? `
                        <div class="bank-transfer-info">
                            <div class="bank-info-title">โอนเงินมาที่</div>
                            <div class="bank-info-row">
                                <span class="bank-name">ธนาคารกสิกรไทย</span>
                            </div>
                            <div class="bank-info-row">
                                <span class="bank-label">ชื่อบัญชี:</span>
                                <span class="bank-value">บริษัท ร้านยา จำกัด</span>
                            </div>
                            <div class="bank-info-row">
                                <span class="bank-label">เลขบัญชี:</span>
                                <span class="bank-value bank-account-number">xxx-x-xxxxx-x</span>
                                <button class="copy-btn" onclick="window.liffApp.copyToClipboard('xxx-x-xxxxx-x')">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    ` : `
                        <div class="promptpay-info">
                            <div class="promptpay-qr-container">
                                <img src="${this.config.BASE_URL}/api/checkout.php?action=promptpay_qr&amount=${total}" 
                                     alt="PromptPay QR" class="promptpay-qr-image"
                                     onerror="this.style.display='none'">
                            </div>
                            <p class="promptpay-hint">สแกน QR Code เพื่อชำระเงิน</p>
                        </div>
                    `}
                </div>
                
                <div class="slip-upload-section">
                    <div class="slip-upload-title">อัพโหลดหลักฐานการชำระเงิน</div>
                    
                    <div class="slip-upload-area" id="slip-upload-area" onclick="document.getElementById('slip-file-input').click()">
                        <input type="file" id="slip-file-input" accept="image/*" style="display: none;" 
                               onchange="window.liffApp.handleSlipFileSelect(event, '${orderId}')">
                        <div class="slip-upload-placeholder" id="slip-upload-placeholder">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span>แตะเพื่อเลือกรูปสลิป</span>
                            <span class="slip-upload-hint">รองรับ JPG, PNG ขนาดไม่เกิน 5MB</span>
                        </div>
                        <div class="slip-preview" id="slip-preview" style="display: none;">
                            <img id="slip-preview-image" src="" alt="Preview">
                            <button class="slip-remove-btn" onclick="event.stopPropagation(); window.liffApp.removeSlipPreview()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button class="btn btn-primary btn-block slip-submit-btn" id="slip-submit-btn" 
                            onclick="window.liffApp.submitSlip('${orderId}')" disabled>
                        <i class="fas fa-paper-plane"></i>
                        ส่งหลักฐานการชำระเงิน
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Handle slip file selection
     */
    handleSlipFileSelect(event, orderId) {
        const file = event.target.files[0];
        if (!file) return;
        
        // Validate file type
        if (!file.type.startsWith('image/')) {
            this.showToast('กรุณาเลือกไฟล์รูปภาพ', 'error');
            return;
        }
        
        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            this.showToast('ไฟล์ใหญ่เกินไป (สูงสุด 5MB)', 'error');
            return;
        }
        
        // Store file for later upload
        this._slipFile = file;
        this._slipOrderId = orderId;
        
        // Show preview
        const reader = new FileReader();
        reader.onload = (e) => {
            const placeholder = document.getElementById('slip-upload-placeholder');
            const preview = document.getElementById('slip-preview');
            const previewImage = document.getElementById('slip-preview-image');
            const submitBtn = document.getElementById('slip-submit-btn');
            
            if (placeholder) placeholder.style.display = 'none';
            if (preview) preview.style.display = 'block';
            if (previewImage) previewImage.src = e.target.result;
            if (submitBtn) submitBtn.disabled = false;
        };
        reader.readAsDataURL(file);
    }

    /**
     * Remove slip preview
     */
    removeSlipPreview() {
        this._slipFile = null;
        
        const placeholder = document.getElementById('slip-upload-placeholder');
        const preview = document.getElementById('slip-preview');
        const fileInput = document.getElementById('slip-file-input');
        const submitBtn = document.getElementById('slip-submit-btn');
        
        if (placeholder) placeholder.style.display = 'flex';
        if (preview) preview.style.display = 'none';
        if (fileInput) fileInput.value = '';
        if (submitBtn) submitBtn.disabled = true;
    }

    /**
     * Submit slip upload
     */
    async submitSlip(orderId) {
        if (!this._slipFile) {
            this.showToast('กรุณาเลือกรูปสลิป', 'error');
            return;
        }
        
        const submitBtn = document.getElementById('slip-submit-btn');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="btn-spinner"></div> กำลังอัพโหลด...';
        }
        
        try {
            const formData = new FormData();
            formData.append('slip', this._slipFile);
            formData.append('order_id', orderId);
            formData.append('action', 'upload_slip');
            
            const profile = window.store?.get('profile');
            if (profile?.userId) {
                formData.append('line_user_id', profile.userId);
            }
            
            const response = await fetch(`${this.config.BASE_URL}/api/checkout.php`, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showToast('ส่งหลักฐานการชำระเงินสำเร็จ', 'success');
                
                // Reload order detail
                setTimeout(() => this.loadOrderDetail(orderId), 1000);
            } else {
                throw new Error(result.message || 'ไม่สามารถอัพโหลดได้');
            }
        } catch (error) {
            console.error('Slip upload error:', error);
            this.showToast(error.message || 'เกิดข้อผิดพลาด', 'error');
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> ส่งหลักฐานการชำระเงิน';
            }
        }
    }

    /**
     * Copy text to clipboard
     */
    copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            this.showToast('คัดลอกแล้ว', 'success');
        }).catch(() => {
            this.showToast('ไม่สามารถคัดลอกได้', 'error');
        });
    }

    /**
     * Render delivery tracking section
     * Requirements: 19.1, 19.2, 19.3, 19.4
     */
    renderDeliveryTrackingSection(order) {
        const status = this.normalizeOrderStatus(order.status);
        const trackingNumber = order.tracking_number || order.delivery_info?.tracking_number;
        const carrier = order.carrier || order.delivery_info?.carrier || 'ขนส่ง';
        const estimatedDelivery = order.estimated_delivery || order.delivery_info?.estimated_delivery;
        
        // Define timeline stages with icons
        const stages = [
            { key: 'pending', label: 'สั่งซื้อสำเร็จ', icon: 'fa-shopping-cart', description: 'ออเดอร์ของคุณได้รับการยืนยันแล้ว' },
            { key: 'confirmed', label: 'ยืนยันออเดอร์', icon: 'fa-check', description: 'ร้านค้ายืนยันออเดอร์แล้ว' },
            { key: 'packing', label: 'กำลังเตรียมสินค้า', icon: 'fa-box', description: 'กำลังจัดเตรียมสินค้าของคุณ' },
            { key: 'shipping', label: 'กำลังจัดส่ง', icon: 'fa-truck', description: 'สินค้าออกจากคลังแล้ว' },
            { key: 'delivered', label: 'จัดส่งสำเร็จ', icon: 'fa-check-circle', description: 'สินค้าถึงมือคุณแล้ว' }
        ];
        
        const statusOrder = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'completed'];
        const currentIndex = statusOrder.indexOf(status);
        
        return `
            <div class="order-detail-card delivery-tracking-card">
                <div class="order-detail-section-title">
                    <i class="fas fa-shipping-fast"></i>
                    สถานะการจัดส่ง
                </div>
                
                <!-- Tracking Info Header - Requirement 19.1, 19.3 -->
                ${trackingNumber ? `
                    <div class="tracking-info-header">
                        <div class="tracking-carrier-info">
                            <div class="tracking-carrier-icon">
                                <i class="fas fa-truck"></i>
                            </div>
                            <div class="tracking-carrier-details">
                                <div class="tracking-carrier-name">${this.escapeHtml(carrier)}</div>
                                <div class="tracking-number">${this.escapeHtml(trackingNumber)}</div>
                            </div>
                        </div>
                        <!-- Requirement 19.4 - Link to carrier tracking page -->
                        <button class="tracking-link-btn" onclick="window.liffApp.openTrackingPage('${this.escapeHtml(trackingNumber)}', '${this.escapeHtml(carrier)}')">
                            <i class="fas fa-external-link-alt"></i>
                            ติดตามพัสดุ
                        </button>
                    </div>
                ` : status === 'shipping' ? `
                    <div class="tracking-info-pending">
                        <i class="fas fa-clock"></i>
                        <span>กำลังรอข้อมูลการจัดส่ง</span>
                    </div>
                ` : ''}
                
                <!-- Estimated Delivery -->
                ${estimatedDelivery && ['shipping', 'packing'].includes(status) ? `
                    <div class="estimated-delivery">
                        <i class="fas fa-calendar-alt"></i>
                        <span>คาดว่าจะได้รับสินค้า: <strong>${this.formatOrderDate(estimatedDelivery)}</strong></span>
                    </div>
                ` : ''}
                
                <!-- Delivery Timeline - Requirement 19.2 -->
                <div class="delivery-timeline-detailed">
                    ${stages.map((stage, index) => {
                        const isCompleted = currentIndex >= index || (status === 'completed' && index <= 4);
                        const isCurrent = currentIndex === index;
                        const stageTime = this.getStageTime(order, stage.key);
                        
                        return `
                            <div class="timeline-stage ${isCompleted ? 'completed' : ''} ${isCurrent ? 'current' : ''}">
                                <div class="timeline-stage-indicator">
                                    <div class="timeline-stage-dot">
                                        ${isCompleted ? '<i class="fas fa-check"></i>' : `<i class="fas ${stage.icon}"></i>`}
                                    </div>
                                    ${index < stages.length - 1 ? '<div class="timeline-stage-line"></div>' : ''}
                                </div>
                                <div class="timeline-stage-content">
                                    <div class="timeline-stage-title">${stage.label}</div>
                                    ${stageTime ? `<div class="timeline-stage-time">${stageTime}</div>` : ''}
                                    ${isCurrent ? `<div class="timeline-stage-desc">${stage.description}</div>` : ''}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Render order detail items
     */
    renderOrderDetailItems(items) {
        if (!items || items.length === 0) {
            return '<div class="no-items">ไม่มีรายการสินค้า</div>';
        }
        
        return `
            <div class="order-detail-items">
                ${items.map(item => `
                    <div class="order-detail-item">
                        <img src="${item.image || item.image_url || 'assets/images/image-placeholder.svg'}" 
                             class="order-detail-item-image" 
                             alt="${item.name || item.product_name || 'Product'}"
                             onerror="this.src='assets/images/image-placeholder.svg'">
                        <div class="order-detail-item-info">
                            <div class="order-detail-item-name">${item.name || item.product_name || 'สินค้า'}</div>
                            <div class="order-detail-item-qty">จำนวน: ${item.quantity || 1}</div>
                        </div>
                        <div class="order-detail-item-price">฿${this.formatNumber(parseFloat(item.price || item.product_price || 0) * (item.quantity || 1))}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Render order detail summary
     */
    renderOrderDetailSummary(order) {
        const subtotal = parseFloat(order.subtotal || order.total_amount || 0);
        const discount = parseFloat(order.discount || 0);
        const shipping = parseFloat(order.shipping_fee || order.shipping || 0);
        const total = parseFloat(order.grand_total || order.total_amount || 0);
        
        return `
            <div class="order-detail-summary">
                <div class="summary-row">
                    <span class="summary-label">ยอดรวมสินค้า</span>
                    <span class="summary-value">฿${this.formatNumber(subtotal)}</span>
                </div>
                ${discount > 0 ? `
                    <div class="summary-row discount">
                        <span class="summary-label">ส่วนลด</span>
                        <span class="summary-value">-฿${this.formatNumber(discount)}</span>
                    </div>
                ` : ''}
                <div class="summary-row">
                    <span class="summary-label">ค่าจัดส่ง</span>
                    <span class="summary-value">${shipping > 0 ? `฿${this.formatNumber(shipping)}` : 'ฟรี'}</span>
                </div>
                <div class="summary-row total">
                    <span class="summary-label">ยอดชำระทั้งหมด</span>
                    <span class="summary-value">฿${this.formatNumber(total)}</span>
                </div>
            </div>
        `;
    }

    /**
     * Render order detail address
     */
    renderOrderDetailAddress(order) {
        const deliveryInfo = order.delivery_info || {};
        const name = deliveryInfo.name || order.customer_name || '';
        const phone = deliveryInfo.phone || order.customer_phone || '';
        const address = deliveryInfo.address || order.shipping_address || '';
        
        if (!name && !address) return '';
        
        return `
            <div class="order-detail-card">
                <div class="order-detail-section-title">
                    <i class="fas fa-map-marker-alt"></i>
                    ที่อยู่จัดส่ง
                </div>
                <div class="order-detail-address">
                    ${name ? `<div class="address-name">${this.escapeHtml(name)}</div>` : ''}
                    ${phone ? `<div class="address-phone"><i class="fas fa-phone"></i> ${this.escapeHtml(phone)}</div>` : ''}
                    ${address ? `<div class="address-text">${this.escapeHtml(address)}</div>` : ''}
                </div>
            </div>
        `;
    }

    /**
     * Render order detail skeleton
     */
    renderOrderDetailSkeleton() {
        return `
            <div class="order-detail-card">
                <div class="skeleton-order-detail-header">
                    <div class="skeleton skeleton-text" style="width: 120px; height: 20px;"></div>
                    <div class="skeleton skeleton-badge" style="width: 80px; height: 24px;"></div>
                </div>
            </div>
            
            <div class="order-detail-card">
                <div class="skeleton skeleton-text" style="width: 100px; height: 16px; margin-bottom: 16px;"></div>
                <div class="skeleton-timeline">
                    ${[1,2,3,4,5].map(() => `
                        <div class="skeleton-timeline-item">
                            <div class="skeleton skeleton-circle" style="width: 24px; height: 24px;"></div>
                            <div class="skeleton skeleton-text" style="width: 80px; height: 14px;"></div>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="order-detail-card">
                <div class="skeleton skeleton-text" style="width: 100px; height: 16px; margin-bottom: 16px;"></div>
                ${[1,2].map(() => `
                    <div class="skeleton-item-row">
                        <div class="skeleton skeleton-image" style="width: 60px; height: 60px;"></div>
                        <div style="flex: 1;">
                            <div class="skeleton skeleton-text" style="width: 80%; height: 14px; margin-bottom: 8px;"></div>
                            <div class="skeleton skeleton-text" style="width: 40%; height: 12px;"></div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Render order detail error
     */
    renderOrderDetailError(message) {
        return `
            <div class="order-detail-error">
                <div class="error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h3>เกิดข้อผิดพลาด</h3>
                <p>${message}</p>
                <button class="btn btn-primary" onclick="window.router.navigate('/orders')">
                    <i class="fas fa-arrow-left"></i>
                    กลับไปหน้าออเดอร์
                </button>
            </div>
        `;
    }

    /**
     * Contact support with order number
     */
    contactSupport(orderNumber) {
        // Try to open LINE chat with the shop
        if (typeof liff !== 'undefined' && liff.isInClient()) {
            // Send message to LINE OA
            liff.sendMessages([{
                type: 'text',
                text: `สอบถามเกี่ยวกับออเดอร์ #${orderNumber}`
            }]).then(() => {
                liff.closeWindow();
            }).catch(err => {
                console.error('Error sending message:', err);
                this.showToast('ไม่สามารถส่งข้อความได้', 'error');
            });
        } else {
            // Fallback - show contact info
            this.showToast('กรุณาติดต่อผ่าน LINE Official Account', 'info');
        }
    }

    /**
     * Render orders error state
     */
    renderOrdersError() {
        return `
            <div class="orders-empty-state">
                <div class="orders-empty-icon">
                    <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="60" cy="60" r="50" fill="#FEE2E2"/>
                        <path d="M60 40v25M60 75v5" stroke="#EF4444" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <h2 class="orders-empty-title">เกิดข้อผิดพลาด</h2>
                <p class="orders-empty-desc">ไม่สามารถโหลดข้อมูลออเดอร์ได้ กรุณาลองใหม่</p>
                <button class="btn btn-primary" onclick="window.liffApp.loadOrders()">
                    <i class="fas fa-redo"></i>
                    ลองใหม่
                </button>
            </div>
        `;
    }

    /**
     * Format order date
     */
    formatOrderDate(dateStr) {
        if (!dateStr) return '-';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', { 
                day: 'numeric', 
                month: 'short', 
                year: 'numeric'
            });
        } catch (e) {
            return dateStr;
        }
    }

    /**
     * Format order date and time
     */
    formatOrderDateTime(dateStr) {
        if (!dateStr) return '';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', { 
                day: 'numeric', 
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return '';
        }
    }

    /**
     * Render profile page (placeholder)
     */
    renderProfilePage() {
        const profile = window.store?.get('profile');
        
        if (!profile) {
            return `
                <div class="profile-page p-4">
                    <div class="text-center py-8">
                        <p class="text-secondary mb-4">กรุณาเข้าสู่ระบบ</p>
                        <button class="btn btn-primary" onclick="window.liffApp.login()">
                            <i class="fab fa-line"></i> เข้าสู่ระบบ LINE
                        </button>
                    </div>
                </div>
            `;
        }

        return `
            <div class="profile-page">
                <div class="profile-header">
                    <img src="${profile.pictureUrl || ''}" 
                         class="profile-avatar"
                         onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2250%22 fill=%22%23ccc%22/></svg>'">
                    <h2 class="profile-name">${profile.displayName}</h2>
                </div>
                
                <div class="profile-menu">
                    <div class="profile-menu-item" onclick="window.router.navigate('/member')">
                        <i class="fas fa-id-card text-primary"></i>
                        <span>บัตรสมาชิก</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                    <div class="profile-menu-item" onclick="window.router.navigate('/health-profile')">
                        <i class="fas fa-heartbeat text-danger"></i>
                        <span>ข้อมูลสุขภาพ</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                    <div class="profile-menu-item" onclick="window.router.navigate('/notifications')">
                        <i class="fas fa-bell text-warning"></i>
                        <span>การแจ้งเตือน</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                    <div class="profile-menu-item" onclick="window.liffApp.logout()">
                        <i class="fas fa-sign-out-alt text-muted"></i>
                        <span>ออกจากระบบ</span>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render member page - Full Member Card with QR Code
     * Requirements: 5.1, 5.2, 5.3, 5.4, 13.2, 13.3
     */
    renderMemberPage() {
        const profile = window.store?.get('profile');
        const member = window.store?.get('member');
        const tier = window.store?.get('tier');
        const companyName = this.config.COMPANY_NAME || this.config.SHOP_NAME || 'ร้านค้า';

        if (!profile) {
            return `
                <div class="member-page">
                    <div class="member-page-header">
                        <button class="back-btn" onclick="window.router.back()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h1 class="page-title">บัตรสมาชิก</h1>
                        <div class="header-spacer"></div>
                    </div>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <h2>กรุณาเข้าสู่ระบบ</h2>
                        <p class="text-secondary">เข้าสู่ระบบเพื่อดูบัตรสมาชิกของคุณ</p>
                        <button class="btn btn-primary" onclick="window.liffApp.login()">
                            <i class="fab fa-line"></i> เข้าสู่ระบบ LINE
                        </button>
                    </div>
                </div>
            `;
        }

        if (!member) {
            return `
                <div class="member-page">
                    <div class="member-page-header">
                        <button class="back-btn" onclick="window.router.back()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h1 class="page-title">บัตรสมาชิก</h1>
                        <div class="header-spacer"></div>
                    </div>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h2>ยังไม่ได้เป็นสมาชิก</h2>
                        <p class="text-secondary">ลงทะเบียนเพื่อรับสิทธิพิเศษและสะสมแต้ม</p>
                        <button class="btn btn-primary" onclick="window.router.navigate('/register')">
                            <i class="fas fa-user-plus"></i> ลงทะเบียนเลย
                        </button>
                    </div>
                </div>
            `;
        }

        // Member data
        const tierName = tier?.name || member.tier || 'Silver';
        const tierClass = this.getTierClass(tierName);
        const points = member.points || 0;
        const nextTierPoints = tier?.next_tier_points || 2000;
        const currentTierPoints = tier?.current_tier_points || 0;
        const progressPercent = Math.min(100, Math.max(0, ((points - currentTierPoints) / (nextTierPoints - currentTierPoints)) * 100));
        const pointsToNext = Math.max(0, nextTierPoints - points);
        const nextTierName = tier?.next_tier_name || this.getNextTierName(tierName);
        const memberId = member.member_id || member.id || '-';
        const memberName = member.first_name || profile.displayName || 'สมาชิก';
        const expiryDate = member.expiry_date ? this.formatDate(member.expiry_date) : '-';
        
        // QR Code data (Requirement 5.3)
        const qrData = `MEMBER-${memberId}`;
        const qrCodeUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrData)}`;

        return `
            <div class="member-page">
                <!-- Header -->
                <div class="member-page-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">บัตรสมาชิก</h1>
                    <div class="header-spacer"></div>
                </div>

                <!-- Welcome Message -->
                <div class="member-welcome">
                    <p class="member-welcome-text">ยินดีต้อนรับกลับ, <strong>${memberName}</strong></p>
                </div>

                <!-- 3D Flip Card -->
                <div class="member-card-container" onclick="window.liffApp.toggleMemberCard()">
                    <div id="member-card-flip" class="member-card-flip">
                        <!-- Front Side (Info) -->
                        <div class="member-card-face member-card-front ${tierClass}">
                            <div class="member-card-decor"></div>
                            <div class="member-card-decor-2"></div>
                            
                            <div class="member-card-content">
                                <!-- Header Row -->
                                <div class="member-card-header">
                                    <div class="member-card-brand">
                                        <p class="member-card-company">${companyName} Member</p>
                                        <h2 class="member-card-tier">${tierName} Tier</h2>
                                    </div>
                                    <div class="member-card-tier-icon">
                                        ${this.getTierIcon(tierName)}
                                    </div>
                                </div>
                                
                                <!-- Info Row -->
                                <div class="member-card-info">
                                    <div class="member-card-points">
                                        <p class="member-card-points-label">คะแนนสะสม</p>
                                        <p class="member-card-points-value">${this.formatNumber(points)} <span class="member-card-points-unit">pt</span></p>
                                    </div>
                                    <div class="member-card-id">
                                        <p class="member-card-id-label">หมายเลขสมาชิก</p>
                                        <p class="member-card-id-value">${this.formatMemberId(memberId)}</p>
                                    </div>
                                </div>
                                
                                <!-- Progress Bar (Requirement 5.4) -->
                                <div class="member-card-progress">
                                    <div class="member-card-progress-bar">
                                        <div class="member-card-progress-fill" style="width: ${progressPercent}%"></div>
                                    </div>
                                    <p class="member-card-progress-text">อีก ${this.formatNumber(pointsToNext)} คะแนน เพื่อเลื่อนเป็น ${nextTierName}</p>
                                </div>
                                
                                <!-- Flip Hint -->
                                <div class="member-card-flip-hint">
                                    <i class="fas fa-sync-alt"></i>
                                    <span>แตะเพื่อดู QR Code</span>
                                </div>
                            </div>
                        </div>

                        <!-- Back Side (QR Code) - Requirement 5.3 -->
                        <div class="member-card-face member-card-back">
                            <div class="member-card-qr-content">
                                <h3 class="member-card-qr-title">QR Code สะสมแต้ม</h3>
                                <div class="member-card-qr-wrapper">
                                    <img src="${qrCodeUrl}" alt="QR Code" class="member-card-qr-image" 
                                         id="member-qr-code" data-member-id="${memberId}">
                                </div>
                                <p class="member-card-qr-hint">สแกนที่เคาน์เตอร์เพื่อสะสมแต้ม</p>
                                <p class="member-card-qr-id">ID: ${this.formatMemberId(memberId)}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card Shadow -->
                    <div class="member-card-shadow"></div>
                </div>

                <!-- Action Buttons -->
                <div class="member-actions">
                    <button class="member-action-btn member-action-redeem" onclick="window.router.navigate('/redeem')">
                        <i class="fas fa-gift"></i>
                        <span>แลกของรางวัล</span>
                    </button>
                    <button class="member-action-btn member-action-qr" onclick="window.liffApp.toggleMemberCard()">
                        <i class="fas fa-qrcode"></i>
                        <span>QR Code สะสมแต้ม</span>
                    </button>
                </div>

                <!-- Points History Link -->
                <div class="member-history-link">
                    <button class="member-history-btn" onclick="window.router.navigate('/points')">
                        <div class="member-history-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="member-history-text">
                            <span class="member-history-title">ประวัติคะแนน</span>
                            <span class="member-history-desc">ดูรายการสะสมและใช้แต้ม</span>
                        </div>
                        <i class="fas fa-chevron-right member-history-arrow"></i>
                    </button>
                </div>

                <!-- Tier Benefits -->
                <div class="member-benefits">
                    <h3 class="member-benefits-title">สิทธิประโยชน์ ${tierName}</h3>
                    <div class="member-benefits-list">
                        ${this.renderTierBenefits(tierName)}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Toggle member card flip
     */
    toggleMemberCard() {
        const card = document.getElementById('member-card-flip');
        if (card) {
            card.classList.toggle('flipped');
        }
    }

    /**
     * Render tier benefits
     */
    renderTierBenefits(tierName) {
        const tierLower = (tierName || '').toLowerCase();
        let benefits = [];

        if (tierLower.includes('platinum') || tierLower.includes('vip')) {
            benefits = [
                { icon: 'fa-percent', text: 'ส่วนลด 15% ทุกการซื้อ' },
                { icon: 'fa-truck', text: 'จัดส่งฟรีทุกออเดอร์' },
                { icon: 'fa-star', text: 'แต้ม x3 ทุกการซื้อ' },
                { icon: 'fa-gift', text: 'ของขวัญวันเกิดพิเศษ' },
                { icon: 'fa-headset', text: 'สายด่วนเภสัชกร 24 ชม.' }
            ];
        } else if (tierLower.includes('gold')) {
            benefits = [
                { icon: 'fa-percent', text: 'ส่วนลด 10% ทุกการซื้อ' },
                { icon: 'fa-truck', text: 'จัดส่งฟรีเมื่อซื้อครบ 300 บาท' },
                { icon: 'fa-star', text: 'แต้ม x2 ทุกการซื้อ' },
                { icon: 'fa-gift', text: 'ของขวัญวันเกิด' }
            ];
        } else if (tierLower.includes('bronze')) {
            benefits = [
                { icon: 'fa-percent', text: 'ส่วนลด 3% ทุกการซื้อ' },
                { icon: 'fa-star', text: 'สะสมแต้มทุกการซื้อ' }
            ];
        } else {
            benefits = [
                { icon: 'fa-percent', text: 'ส่วนลด 5% ทุกการซื้อ' },
                { icon: 'fa-truck', text: 'จัดส่งฟรีเมื่อซื้อครบ 500 บาท' },
                { icon: 'fa-star', text: 'สะสมแต้มทุกการซื้อ' }
            ];
        }

        return benefits.map(b => `
            <div class="member-benefit-item">
                <div class="member-benefit-icon">
                    <i class="fas ${b.icon}"></i>
                </div>
                <span class="member-benefit-text">${b.text}</span>
            </div>
        `).join('');
    }

    // ==================== Wishlist Page ====================
    // Requirements: 16.1, 16.2, 16.3, 16.4, 16.5

    /**
     * Render wishlist page
     * Requirements: 16.1, 16.2, 16.3, 16.4, 16.5
     * - Display heart icon button to add/remove from wishlist (16.1)
     * - Toggle wishlist status with animation (16.2)
     * - Display filled heart icon for wishlist items (16.3)
     * - Display all saved products in grid layout (16.4)
     * - Show product image, name, price, and "Add to Cart" button (16.5)
     */
    renderWishlistPage() {
        // Load wishlist data after render
        setTimeout(() => this.loadWishlistData(), 100);
        
        return `
            <div class="wishlist-page">
                <!-- Header -->
                <div class="wishlist-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">❤️ รายการโปรด</h1>
                    <span id="wishlist-count" class="wishlist-count">0 รายการ</span>
                </div>
                
                <!-- Info Banner -->
                <div class="wishlist-info-banner">
                    <i class="fas fa-bell"></i>
                    <span>เราจะแจ้งเตือนคุณเมื่อสินค้าในรายการโปรดลดราคา!</span>
                </div>
                
                <!-- Wishlist Items -->
                <div id="wishlist-container" class="wishlist-container">
                    ${this.renderWishlistSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render wishlist skeleton loading
     */
    renderWishlistSkeleton() {
        return `
            <div class="wishlist-skeleton">
                ${Array(3).fill().map(() => `
                    <div class="wishlist-item-skeleton">
                        <div class="skeleton skeleton-wishlist-image"></div>
                        <div class="wishlist-item-skeleton-info">
                            <div class="skeleton skeleton-text"></div>
                            <div class="skeleton skeleton-text short"></div>
                            <div class="skeleton skeleton-button"></div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Load wishlist data from API
     * Requirements: 16.4 - Display wishlist page
     */
    async loadWishlistData() {
        const container = document.getElementById('wishlist-container');
        const countEl = document.getElementById('wishlist-count');
        if (!container) return;

        const profile = window.store?.get('profile');
        
        if (!profile?.userId) {
            container.innerHTML = this.renderWishlistLoginRequired();
            return;
        }

        try {
            const response = await fetch(
                `${this.config.BASE_URL}/api/wishlist.php?action=list&line_user_id=${profile.userId}`
            );
            const data = await response.json();

            if (data.success && data.items && data.items.length > 0) {
                // Update store with wishlist items
                const productIds = data.items.map(item => item.product_id);
                window.store?.setWishlistItems(productIds);
                
                // Render items
                container.innerHTML = this.renderWishlistItems(data.items);
                if (countEl) countEl.textContent = `${data.count || data.items.length} รายการ`;
            } else {
                container.innerHTML = this.renderWishlistEmptyState();
                if (countEl) countEl.textContent = '0 รายการ';
            }
        } catch (error) {
            console.error('Error loading wishlist:', error);
            container.innerHTML = this.renderWishlistError();
        }
    }

    /**
     * Render wishlist items
     * Requirements: 16.5 - Show product image, name, price, and "Add to Cart" button
     */
    renderWishlistItems(items) {
        return `
            <div class="wishlist-items">
                ${items.map(item => this.renderWishlistItem(item)).join('')}
            </div>
        `;
    }

    /**
     * Render single wishlist item
     */
    renderWishlistItem(item) {
        const price = parseFloat(item.sale_price || item.price || 0);
        const originalPrice = parseFloat(item.price_when_added || item.price || 0);
        const isOnSale = item.is_on_sale == 1 || (item.sale_price && item.sale_price < originalPrice);
        const discount = item.discount_percent || (isOnSale ? Math.round((1 - price / originalPrice) * 100) : 0);
        const isOutOfStock = parseInt(item.stock || 0) <= 0;

        return `
            <div class="wishlist-item" data-product-id="${item.product_id}">
                <div class="wishlist-item-image" onclick="window.liffApp.viewProduct(${item.product_id})">
                    ${item.image_url 
                        ? `<img src="${item.image_url}" alt="${item.name}" loading="lazy" 
                               onerror="this.src='assets/images/image-placeholder.svg'">`
                        : `<div class="wishlist-item-placeholder"><i class="fas fa-image"></i></div>`
                    }
                </div>
                <div class="wishlist-item-info">
                    <div class="wishlist-item-header">
                        <h3 class="wishlist-item-name" onclick="window.liffApp.viewProduct(${item.product_id})">${item.name}</h3>
                        <button class="wishlist-remove-btn" onclick="window.liffApp.removeFromWishlistPage(${item.product_id})" title="ลบออกจากรายการโปรด">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="wishlist-item-price">
                        <span class="wishlist-price ${isOnSale ? 'sale' : ''}">฿${this.formatNumber(price)}</span>
                        ${isOnSale ? `
                            <span class="wishlist-original-price">฿${this.formatNumber(originalPrice)}</span>
                            <span class="wishlist-discount-badge">-${discount}%</span>
                        ` : ''}
                    </div>
                    <button class="wishlist-add-cart-btn ${isOutOfStock ? 'disabled' : ''}" 
                            onclick="window.liffApp.addWishlistItemToCart(${item.product_id}, '${item.name.replace(/'/g, "\\'")}', ${price}, '${item.image_url || ''}')"
                            ${isOutOfStock ? 'disabled' : ''}>
                        <i class="fas fa-cart-plus"></i>
                        ${isOutOfStock ? 'สินค้าหมด' : 'เพิ่มลงตะกร้า'}
                    </button>
                </div>
                ${isOnSale ? `
                    <div class="wishlist-sale-banner">
                        <i class="fas fa-fire"></i>
                        ลดราคาจากที่คุณเพิ่มไว้ ${discount}%!
                    </div>
                ` : ''}
            </div>
        `;
    }

    /**
     * View product detail - show modal instead of navigating
     */
    viewProduct(productId) {
        this.showProductDetailModal(productId);
    }

    /**
     * Remove item from wishlist on wishlist page
     * Requirements: 16.8 - Show undo option for 5 seconds
     */
    async removeFromWishlistPage(productId) {
        const profile = window.store?.get('profile');
        if (!profile?.userId) return;

        const itemEl = document.querySelector(`.wishlist-item[data-product-id="${productId}"]`);
        
        // Optimistic UI - hide item
        if (itemEl) {
            itemEl.style.opacity = '0.5';
            itemEl.style.pointerEvents = 'none';
        }

        try {
            const response = await fetch(`${this.config.BASE_URL}/api/wishlist.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'remove',
                    line_user_id: profile.userId,
                    product_id: productId
                })
            });

            const data = await response.json();

            if (data.success) {
                // Remove from store
                window.store?.removeFromWishlist(productId);
                
                // Remove from DOM with animation
                if (itemEl) {
                    itemEl.style.transition = 'all 0.3s ease';
                    itemEl.style.transform = 'translateX(100%)';
                    itemEl.style.opacity = '0';
                    
                    setTimeout(() => {
                        itemEl.remove();
                        
                        // Check if empty
                        const remaining = document.querySelectorAll('.wishlist-item').length;
                        const countEl = document.getElementById('wishlist-count');
                        
                        if (remaining === 0) {
                            const container = document.getElementById('wishlist-container');
                            if (container) {
                                container.innerHTML = this.renderWishlistEmptyState();
                            }
                        }
                        
                        if (countEl) countEl.textContent = `${remaining} รายการ`;
                    }, 300);
                }
                
                this.showToast('ลบออกจากรายการโปรดแล้ว', 'success');
            } else {
                // Revert UI
                if (itemEl) {
                    itemEl.style.opacity = '1';
                    itemEl.style.pointerEvents = 'auto';
                }
                this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            }
        } catch (error) {
            console.error('Remove from wishlist error:', error);
            // Revert UI
            if (itemEl) {
                itemEl.style.opacity = '1';
                itemEl.style.pointerEvents = 'auto';
            }
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        }
    }

    /**
     * Add wishlist item to cart
     */
    addWishlistItemToCart(productId, name, price, imageUrl) {
        const product = {
            id: productId,
            name: name,
            price: price,
            image_url: imageUrl,
            is_prescription: false
        };
        
        window.store?.addToCart(product, 1);
        this.showToast('เพิ่มลงตะกร้าแล้ว', 'success');
        this.updateCartBadge();
    }

    /**
     * Render wishlist empty state
     * Requirements: 16.9 - Display empty state with "Browse Products" button
     */
    renderWishlistEmptyState() {
        return `
            <div class="wishlist-empty-state">
                <div class="wishlist-empty-icon">
                    <i class="far fa-heart"></i>
                </div>
                <h2 class="wishlist-empty-title">ยังไม่มีรายการโปรด</h2>
                <p class="wishlist-empty-desc">กดปุ่ม ❤️ ที่สินค้าเพื่อเพิ่มรายการโปรด</p>
                <button class="btn btn-primary" onclick="window.router.navigate('/shop')">
                    <i class="fas fa-shopping-bag"></i>
                    ไปช้อปปิ้ง
                </button>
            </div>
        `;
    }

    /**
     * Render wishlist login required state
     */
    renderWishlistLoginRequired() {
        return `
            <div class="wishlist-empty-state">
                <div class="wishlist-empty-icon">
                    <i class="fas fa-user-lock"></i>
                </div>
                <h2 class="wishlist-empty-title">กรุณาเข้าสู่ระบบ</h2>
                <p class="wishlist-empty-desc">เข้าสู่ระบบเพื่อดูรายการโปรดของคุณ</p>
                <button class="btn btn-primary" onclick="window.liffApp.login()">
                    <i class="fab fa-line"></i>
                    เข้าสู่ระบบ LINE
                </button>
            </div>
        `;
    }

    /**
     * Render wishlist error state
     */
    renderWishlistError() {
        return `
            <div class="wishlist-empty-state">
                <div class="wishlist-empty-icon error">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2 class="wishlist-empty-title">เกิดข้อผิดพลาด</h2>
                <p class="wishlist-empty-desc">ไม่สามารถโหลดรายการโปรดได้ กรุณาลองใหม่</p>
                <button class="btn btn-primary" onclick="window.liffApp.loadWishlistData()">
                    <i class="fas fa-redo"></i>
                    ลองใหม่
                </button>
            </div>
        `;
    }

    // ==================== Notification Settings Page ====================
    // Requirements: 14.1, 14.2, 14.3

    /**
     * Render notification settings page
     * Requirements: 14.1, 14.2, 14.3
     * - Display categorized notification toggles (14.1)
     * - Show Order Updates, Promotions, Appointment Reminders, Drug Reminders, Health Tips (14.2)
     * - Save preferences immediately via API (14.3)
     */
    renderNotificationSettingsPage() {
        // Load settings after render
        setTimeout(() => this.loadNotificationSettings(), 100);
        
        return `
            <div class="notification-settings-page">
                <!-- Header -->
                <div class="notification-settings-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">การแจ้งเตือน</h1>
                    <div class="header-spacer"></div>
                </div>
                
                <!-- Info Banner -->
                <div class="notification-info-banner">
                    <i class="fas fa-info-circle"></i>
                    <span>เลือกประเภทการแจ้งเตือนที่คุณต้องการรับผ่าน LINE</span>
                </div>
                
                <!-- Notification Categories -->
                <div id="notification-settings-container" class="notification-settings-container">
                    ${this.renderNotificationSettingsSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render notification settings skeleton
     */
    renderNotificationSettingsSkeleton() {
        return `
            <div class="notification-settings-skeleton">
                ${Array(5).fill().map(() => `
                    <div class="notification-setting-skeleton">
                        <div class="skeleton skeleton-icon"></div>
                        <div class="notification-setting-skeleton-info">
                            <div class="skeleton skeleton-text"></div>
                            <div class="skeleton skeleton-text short"></div>
                        </div>
                        <div class="skeleton skeleton-toggle"></div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Load notification settings from API
     * Requirements: 14.1 - Display categorized notification toggles
     */
    async loadNotificationSettings() {
        const container = document.getElementById('notification-settings-container');
        if (!container) return;

        const profile = window.store?.get('profile');
        
        if (!profile?.userId) {
            container.innerHTML = this.renderNotificationSettingsLoginRequired();
            return;
        }

        try {
            const response = await fetch(
                `${this.config.BASE_URL}/api/user-notifications.php?action=get&line_user_id=${profile.userId}`
            );
            const data = await response.json();

            if (data.success) {
                // Update store
                window.store?.setNotificationSettings(data.preferences);
                
                // Render settings
                container.innerHTML = this.renderNotificationSettingsContent(data.preferences);
            } else {
                container.innerHTML = this.renderNotificationSettingsError();
            }
        } catch (error) {
            console.error('Error loading notification settings:', error);
            container.innerHTML = this.renderNotificationSettingsError();
        }
    }

    /**
     * Render notification settings content
     * Requirements: 14.2 - Show Order Updates, Promotions, Appointment Reminders, Drug Reminders, Health Tips
     */
    renderNotificationSettingsContent(preferences) {
        const categories = [
            {
                key: 'order_updates',
                icon: 'fa-box',
                iconColor: '#3B82F6',
                title: 'อัพเดทออเดอร์',
                description: 'แจ้งเตือนยืนยันออเดอร์ การจัดส่ง และการรับสินค้า'
            },
            {
                key: 'promotions',
                icon: 'fa-tags',
                iconColor: '#F59E0B',
                title: 'โปรโมชั่น',
                description: 'ข่าวสารโปรโมชั่นและส่วนลดพิเศษ'
            },
            {
                key: 'appointment_reminders',
                icon: 'fa-calendar-check',
                iconColor: '#10B981',
                title: 'เตือนนัดหมาย',
                description: 'แจ้งเตือน 24 ชม. และ 30 นาทีก่อนนัดหมาย'
            },
            {
                key: 'drug_reminders',
                icon: 'fa-pills',
                iconColor: '#EC4899',
                title: 'เตือนทานยา',
                description: 'แจ้งเตือนเวลาทานยาตามที่ตั้งไว้'
            },
            {
                key: 'health_tips',
                icon: 'fa-heart-pulse',
                iconColor: '#EF4444',
                title: 'เคล็ดลับสุขภาพ',
                description: 'บทความและเคล็ดลับดูแลสุขภาพ'
            },
            {
                key: 'price_alerts',
                icon: 'fa-bell',
                iconColor: '#8B5CF6',
                title: 'แจ้งเตือนราคา',
                description: 'แจ้งเมื่อสินค้าในรายการโปรดลดราคา'
            },
            {
                key: 'restock_alerts',
                icon: 'fa-warehouse',
                iconColor: '#06B6D4',
                title: 'แจ้งสินค้าเข้า',
                description: 'แจ้งเมื่อสินค้าที่หมดกลับมามีสต็อก'
            }
        ];

        return `
            <div class="notification-settings-list">
                ${categories.map(cat => `
                    <div class="notification-setting-item" data-category="${cat.key}">
                        <div class="notification-setting-icon" style="background-color: ${cat.iconColor}20; color: ${cat.iconColor};">
                            <i class="fas ${cat.icon}"></i>
                        </div>
                        <div class="notification-setting-info">
                            <div class="notification-setting-title">${cat.title}</div>
                            <div class="notification-setting-desc">${cat.description}</div>
                        </div>
                        <label class="notification-toggle">
                            <input type="checkbox" 
                                   ${preferences[cat.key] ? 'checked' : ''} 
                                   onchange="window.liffApp.toggleNotificationSetting('${cat.key}', this.checked)">
                            <span class="notification-toggle-slider"></span>
                        </label>
                    </div>
                `).join('')}
            </div>
            
            <!-- Disable All Warning -->
            <div class="notification-disable-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>การปิดการแจ้งเตือนทั้งหมดอาจทำให้คุณพลาดข้อมูลสำคัญ</span>
            </div>
        `;
    }

    /**
     * Toggle notification setting
     * Requirements: 14.3 - Save preferences immediately via API
     */
    async toggleNotificationSetting(category, enabled) {
        const profile = window.store?.get('profile');
        if (!profile?.userId) return;

        // Update store immediately (optimistic)
        window.store?.setNotificationSetting(category, enabled);

        try {
            const response = await fetch(`${this.config.BASE_URL}/api/user-notifications.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'toggle',
                    line_user_id: profile.userId,
                    line_account_id: this.config.ACCOUNT_ID,
                    category: category,
                    enabled: enabled
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast(data.message, 'success');
            } else {
                // Revert on error
                window.store?.setNotificationSetting(category, !enabled);
                const checkbox = document.querySelector(`[data-category="${category}"] input`);
                if (checkbox) checkbox.checked = !enabled;
                this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            }
        } catch (error) {
            console.error('Toggle notification error:', error);
            // Revert on error
            window.store?.setNotificationSetting(category, !enabled);
            const checkbox = document.querySelector(`[data-category="${category}"] input`);
            if (checkbox) checkbox.checked = !enabled;
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        }
    }

    /**
     * Render notification settings login required
     */
    renderNotificationSettingsLoginRequired() {
        return `
            <div class="notification-settings-empty">
                <div class="notification-settings-empty-icon">
                    <i class="fas fa-user-lock"></i>
                </div>
                <h2 class="notification-settings-empty-title">กรุณาเข้าสู่ระบบ</h2>
                <p class="notification-settings-empty-desc">เข้าสู่ระบบเพื่อตั้งค่าการแจ้งเตือน</p>
                <button class="btn btn-primary" onclick="window.liffApp.login()">
                    <i class="fab fa-line"></i>
                    เข้าสู่ระบบ LINE
                </button>
            </div>
        `;
    }

    /**
     * Render notification settings error
     */
    renderNotificationSettingsError() {
        return `
            <div class="notification-settings-empty">
                <div class="notification-settings-empty-icon error">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2 class="notification-settings-empty-title">เกิดข้อผิดพลาด</h2>
                <p class="notification-settings-empty-desc">ไม่สามารถโหลดการตั้งค่าได้ กรุณาลองใหม่</p>
                <button class="btn btn-primary" onclick="window.liffApp.loadNotificationSettings()">
                    <i class="fas fa-redo"></i>
                    ลองใหม่
                </button>
            </div>
        `;
    }

    // ==================== Medication Reminders Page ====================
    // Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7

    /**
     * Render medication reminders page
     * Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7
     * - Display list of active medication schedules (15.1)
     * - Allow selection from order history or manual entry (15.2)
     * - Capture medication name, dosage, frequency, and reminder times (15.3)
     */
    renderMedicationRemindersPage() {
        // Load reminders after render
        setTimeout(() => this.loadMedicationRemindersData(), 100);
        
        return `
            <div class="medication-reminders-page">
                <!-- Header -->
                <div class="medication-reminders-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">เตือนทานยา</h1>
                    <button class="add-reminder-btn" onclick="window.liffApp.showAddReminderModal()">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                
                <!-- Info Banner -->
                <div class="medication-info-banner">
                    <i class="fas fa-bell"></i>
                    <span>ตั้งเวลาเตือนทานยาเพื่อไม่พลาดทุกมื้อ</span>
                </div>
                
                <!-- Reminders List -->
                <div id="medication-reminders-container" class="medication-reminders-container">
                    ${this.renderMedicationRemindersSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render medication reminders skeleton
     */
    renderMedicationRemindersSkeleton() {
        return `
            <div class="medication-reminders-skeleton">
                ${Array(3).fill().map(() => `
                    <div class="medication-reminder-skeleton">
                        <div class="skeleton skeleton-pill-icon"></div>
                        <div class="medication-reminder-skeleton-info">
                            <div class="skeleton skeleton-text"></div>
                            <div class="skeleton skeleton-text short"></div>
                        </div>
                        <div class="skeleton skeleton-toggle"></div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    /**
     * Load medication reminders from API
     * Requirements: 15.1 - Display list of active medication schedules
     */
    async loadMedicationRemindersData() {
        const container = document.getElementById('medication-reminders-container');
        if (!container) return;

        const profile = window.store?.get('profile');
        
        if (!profile?.userId) {
            container.innerHTML = this.renderMedicationRemindersLoginRequired();
            return;
        }

        try {
            const response = await fetch(
                `${this.config.BASE_URL}/api/medication-reminders.php?action=list&line_user_id=${profile.userId}`
            );
            const data = await response.json();

            if (data.success) {
                if (data.reminders && data.reminders.length > 0) {
                    container.innerHTML = this.renderMedicationRemindersList(data.reminders);
                } else {
                    container.innerHTML = this.renderMedicationRemindersEmptyState();
                }
            } else {
                container.innerHTML = this.renderMedicationRemindersError();
            }
        } catch (error) {
            console.error('Error loading medication reminders:', error);
            container.innerHTML = this.renderMedicationRemindersError();
        }
    }

    /**
     * Render medication reminders list
     * Requirements: 15.7 - Display adherence percentage and missed doses
     */
    renderMedicationRemindersList(reminders) {
        return `
            <div class="medication-reminders-list">
                ${reminders.map(reminder => this.renderMedicationReminderCard(reminder)).join('')}
            </div>
            
            <!-- Adherence Summary -->
            <div class="medication-adherence-summary">
                <div class="adherence-summary-header">
                    <i class="fas fa-chart-line"></i>
                    <span>สรุปการทานยา 7 วันที่ผ่านมา</span>
                </div>
                <div class="adherence-summary-stats">
                    ${this.renderAdherenceSummary(reminders)}
                </div>
            </div>
        `;
    }

    /**
     * Render single medication reminder card
     */
    renderMedicationReminderCard(reminder) {
        const times = reminder.reminder_times || [];
        const adherence = reminder.adherence_percent || 100;
        const adherenceColor = adherence >= 80 ? 'success' : adherence >= 50 ? 'warning' : 'danger';
        
        return `
            <div class="medication-reminder-card" data-reminder-id="${reminder.id}">
                <div class="medication-reminder-main">
                    <div class="medication-pill-icon">
                        <i class="fas fa-pills"></i>
                    </div>
                    <div class="medication-reminder-info">
                        <div class="medication-reminder-name">${reminder.medication_name}</div>
                        <div class="medication-reminder-dosage">${reminder.dosage || 'ไม่ระบุขนาด'}</div>
                        <div class="medication-reminder-times">
                            <i class="far fa-clock"></i>
                            ${times.map(t => `<span class="reminder-time">${t}</span>`).join('')}
                        </div>
                    </div>
                    <div class="medication-reminder-actions">
                        <button class="medication-edit-btn" onclick="window.liffApp.editReminder(${reminder.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="medication-delete-btn" onclick="window.liffApp.deleteReminder(${reminder.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Adherence Bar -->
                <div class="medication-adherence">
                    <div class="adherence-label">
                        <span>การทานยา</span>
                        <span class="adherence-percent ${adherenceColor}">${adherence}%</span>
                    </div>
                    <div class="adherence-bar">
                        <div class="adherence-fill ${adherenceColor}" style="width: ${adherence}%"></div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="medication-quick-actions">
                    <button class="mark-taken-btn" onclick="window.liffApp.markMedicationTaken(${reminder.id})">
                        <i class="fas fa-check"></i>
                        ทานแล้ว
                    </button>
                    <button class="skip-btn" onclick="window.liffApp.skipMedication(${reminder.id})">
                        <i class="fas fa-forward"></i>
                        ข้าม
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Render adherence summary
     */
    renderAdherenceSummary(reminders) {
        const totalTaken = reminders.reduce((sum, r) => sum + (r.taken_count_7d || 0), 0);
        const totalMissed = reminders.reduce((sum, r) => sum + (r.missed_count_7d || 0), 0);
        const total = totalTaken + totalMissed;
        const overallAdherence = total > 0 ? Math.round((totalTaken / total) * 100) : 100;
        
        return `
            <div class="adherence-stat">
                <div class="adherence-stat-value success">${totalTaken}</div>
                <div class="adherence-stat-label">ทานแล้ว</div>
            </div>
            <div class="adherence-stat">
                <div class="adherence-stat-value danger">${totalMissed}</div>
                <div class="adherence-stat-label">พลาด</div>
            </div>
            <div class="adherence-stat">
                <div class="adherence-stat-value ${overallAdherence >= 80 ? 'success' : 'warning'}">${overallAdherence}%</div>
                <div class="adherence-stat-label">โดยรวม</div>
            </div>
        `;
    }

    /**
     * Show add reminder modal
     * Requirements: 15.2, 15.3 - Allow selection from order history or manual entry
     */
    showAddReminderModal() {
        const modalHtml = `
            <div class="modal medication-modal">
                <div class="modal-header">
                    <h3 class="modal-title">เพิ่มการเตือนทานยา</h3>
                    <button class="modal-close" onclick="window.liffApp.hideModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="add-reminder-form" onsubmit="window.liffApp.submitAddReminder(event)">
                        <!-- Medication Name -->
                        <div class="form-group">
                            <label class="form-label">ชื่อยา <span class="required">*</span></label>
                            <input type="text" name="medication_name" class="form-input" 
                                   placeholder="เช่น พาราเซตามอล" required>
                        </div>
                        
                        <!-- Dosage -->
                        <div class="form-group">
                            <label class="form-label">ขนาดยา</label>
                            <input type="text" name="dosage" class="form-input" 
                                   placeholder="เช่น 1 เม็ด, 5 มล.">
                        </div>
                        
                        <!-- Frequency -->
                        <div class="form-group">
                            <label class="form-label">ความถี่</label>
                            <select name="frequency" class="form-select" onchange="window.liffApp.updateReminderTimes(this.value)">
                                <option value="daily">วันละ 1 ครั้ง</option>
                                <option value="twice_daily">วันละ 2 ครั้ง</option>
                                <option value="three_times">วันละ 3 ครั้ง</option>
                                <option value="custom">กำหนดเอง</option>
                            </select>
                        </div>
                        
                        <!-- Reminder Times -->
                        <div class="form-group">
                            <label class="form-label">เวลาเตือน</label>
                            <div id="reminder-times-container" class="reminder-times-inputs">
                                <input type="time" name="reminder_times[]" class="form-input time-input" value="08:00">
                            </div>
                            <button type="button" class="add-time-btn" onclick="window.liffApp.addReminderTimeInput()">
                                <i class="fas fa-plus"></i> เพิ่มเวลา
                            </button>
                        </div>
                        
                        <!-- Notes -->
                        <div class="form-group">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea name="notes" class="form-textarea" rows="2" 
                                      placeholder="เช่น ทานหลังอาหาร"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-plus"></i> เพิ่มการเตือน
                        </button>
                    </form>
                </div>
            </div>
        `;
        
        this.showModal(modalHtml);
    }

    /**
     * Update reminder times based on frequency
     */
    updateReminderTimes(frequency) {
        const container = document.getElementById('reminder-times-container');
        if (!container) return;
        
        const timesByFrequency = {
            'daily': ['08:00'],
            'twice_daily': ['08:00', '20:00'],
            'three_times': ['08:00', '12:00', '20:00'],
            'custom': ['08:00']
        };
        
        const times = timesByFrequency[frequency] || ['08:00'];
        
        container.innerHTML = times.map(time => `
            <input type="time" name="reminder_times[]" class="form-input time-input" value="${time}">
        `).join('');
    }

    /**
     * Add reminder time input
     */
    addReminderTimeInput() {
        const container = document.getElementById('reminder-times-container');
        if (!container) return;
        
        const input = document.createElement('input');
        input.type = 'time';
        input.name = 'reminder_times[]';
        input.className = 'form-input time-input';
        input.value = '12:00';
        container.appendChild(input);
    }

    /**
     * Submit add reminder form
     */
    async submitAddReminder(event) {
        event.preventDefault();
        
        const profile = window.store?.get('profile');
        if (!profile?.userId) {
            this.showToast('กรุณาเข้าสู่ระบบ', 'error');
            return;
        }
        
        const form = event.target;
        const formData = new FormData(form);
        
        // Get reminder times
        const reminderTimes = [];
        form.querySelectorAll('input[name="reminder_times[]"]').forEach(input => {
            if (input.value) reminderTimes.push(input.value);
        });
        
        const data = {
            action: 'add',
            line_user_id: profile.userId,
            line_account_id: this.config.ACCOUNT_ID,
            medication_name: formData.get('medication_name'),
            dosage: formData.get('dosage'),
            frequency: formData.get('frequency'),
            reminder_times: reminderTimes,
            notes: formData.get('notes')
        };
        
        try {
            const response = await fetch(`${this.config.BASE_URL}/api/medication-reminders.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.hideModal();
                this.showToast(result.message, 'success');
                this.loadMedicationRemindersData();
            } else {
                this.showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
            }
        } catch (error) {
            console.error('Add reminder error:', error);
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        }
    }

    /**
     * Mark medication as taken
     * Requirements: 15.5, 15.6 - Mark as Taken action and record timestamp
     */
    async markMedicationTaken(reminderId) {
        const profile = window.store?.get('profile');
        if (!profile?.userId) return;
        
        try {
            const response = await fetch(`${this.config.BASE_URL}/api/medication-reminders.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'mark_taken',
                    line_user_id: profile.userId,
                    reminder_id: reminderId,
                    status: 'taken'
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast('บันทึกการทานยาแล้ว ✓', 'success');
                this.loadMedicationRemindersData();
            } else {
                this.showToast('เกิดข้อผิดพลาด', 'error');
            }
        } catch (error) {
            console.error('Mark taken error:', error);
            this.showToast('เกิดข้อผิดพลาด', 'error');
        }
    }

    /**
     * Skip medication
     */
    async skipMedication(reminderId) {
        const profile = window.store?.get('profile');
        if (!profile?.userId) return;
        
        try {
            const response = await fetch(`${this.config.BASE_URL}/api/medication-reminders.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'mark_taken',
                    line_user_id: profile.userId,
                    reminder_id: reminderId,
                    status: 'skipped'
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast('ข้ามการทานยาครั้งนี้', 'info');
                this.loadMedicationRemindersData();
            }
        } catch (error) {
            console.error('Skip medication error:', error);
        }
    }

    /**
     * Delete reminder
     */
    async deleteReminder(reminderId) {
        if (!confirm('ต้องการลบการเตือนนี้?')) return;
        
        const profile = window.store?.get('profile');
        if (!profile?.userId) return;
        
        try {
            const response = await fetch(`${this.config.BASE_URL}/api/medication-reminders.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    line_user_id: profile.userId,
                    reminder_id: reminderId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast('ลบการเตือนแล้ว', 'success');
                this.loadMedicationRemindersData();
            }
        } catch (error) {
            console.error('Delete reminder error:', error);
            this.showToast('เกิดข้อผิดพลาด', 'error');
        }
    }

    /**
     * Edit reminder (placeholder)
     */
    editReminder(reminderId) {
        this.showToast('ฟีเจอร์แก้ไขจะเปิดให้ใช้งานเร็วๆ นี้', 'info');
    }

    /**
     * Render medication reminders empty state
     */
    renderMedicationRemindersEmptyState() {
        return `
            <div class="medication-reminders-empty">
                <div class="medication-reminders-empty-icon">
                    <i class="fas fa-pills"></i>
                </div>
                <h2 class="medication-reminders-empty-title">ยังไม่มีการเตือนทานยา</h2>
                <p class="medication-reminders-empty-desc">เพิ่มการเตือนเพื่อไม่พลาดเวลาทานยา</p>
                <button class="btn btn-primary" onclick="window.liffApp.showAddReminderModal()">
                    <i class="fas fa-plus"></i>
                    เพิ่มการเตือน
                </button>
            </div>
        `;
    }

    /**
     * Render medication reminders login required
     */
    renderMedicationRemindersLoginRequired() {
        return `
            <div class="medication-reminders-empty">
                <div class="medication-reminders-empty-icon">
                    <i class="fas fa-user-lock"></i>
                </div>
                <h2 class="medication-reminders-empty-title">กรุณาเข้าสู่ระบบ</h2>
                <p class="medication-reminders-empty-desc">เข้าสู่ระบบเพื่อตั้งการเตือนทานยา</p>
                <button class="btn btn-primary" onclick="window.liffApp.login()">
                    <i class="fab fa-line"></i>
                    เข้าสู่ระบบ LINE
                </button>
            </div>
        `;
    }

    /**
     * Render medication reminders error
     */
    renderMedicationRemindersError() {
        return `
            <div class="medication-reminders-empty">
                <div class="medication-reminders-empty-icon error">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2 class="medication-reminders-empty-title">เกิดข้อผิดพลาด</h2>
                <p class="medication-reminders-empty-desc">ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่</p>
                <button class="btn btn-primary" onclick="window.liffApp.loadMedicationRemindersData()">
                    <i class="fas fa-redo"></i>
                    ลองใหม่
                </button>
            </div>
        `;
    }

    // ==================== AI Assistant Page Methods ====================
    // Requirements: 7.1, 7.2

    /**
     * Render AI Assistant page
     * Requirements: 7.1 - Display chat interface with quick symptom selection buttons
     * @param {Object} params - Route parameters (may include symptom)
     */
    renderAIAssistantPage(params) {
        // Return a container that will be populated by the AIChat component
        return `<div id="ai-assistant-container"></div>`;
    }

    /**
     * Initialize AI Assistant page
     * Requirements: 7.1, 7.2
     * @param {Object} params - Route parameters
     */
    initAIAssistantPage(params) {
        if (window.debugLog) window.debugLog('initAIAssistantPage called', 'info');
        console.log('🤖 Initializing AI Assistant page...');
        
        const container = document.getElementById('ai-assistant-container');
        if (!container) {
            if (window.debugLog) window.debugLog('Container not found!', 'error');
            console.error('❌ AI Assistant container not found');
            return;
        }
        if (window.debugLog) window.debugLog('Container found', 'success');

        // Check if AIChat class exists
        if (typeof AIChat === 'undefined') {
            if (window.debugLog) window.debugLog('AIChat class NOT loaded!', 'error');
            console.error('❌ AIChat class not loaded');
            container.innerHTML = `
                <div style="padding: 20px; text-align: center;">
                    <p style="color: red;">ไม่สามารถโหลด AI Chat ได้</p>
                    <p style="font-size: 12px; color: #666;">AIChat class is undefined</p>
                    <button onclick="location.reload()" style="padding: 10px 20px; margin-top: 10px;">ลองใหม่</button>
                </div>
            `;
            return;
        }
        if (window.debugLog) window.debugLog('AIChat class loaded', 'success');

        try {
            // Get user ID from profile
            const profile = window.store?.get('profile');
            const userId = profile?.userId || null;
            if (window.debugLog) window.debugLog('User ID: ' + (userId || 'null'), 'info');

            // Create AI Chat instance
            if (window.debugLog) window.debugLog('Creating AIChat instance...', 'info');
            window.aiChat = new AIChat({
                userId: userId,
                onSendMessage: (message) => {
                    console.log('Message sent:', message);
                },
                onSymptomSelect: (symptom) => {
                    console.log('Symptom selected:', symptom);
                }
            });

            // Initialize the chat interface
            if (window.debugLog) window.debugLog('Calling aiChat.init()...', 'info');
            window.aiChat.init(container);
            if (window.debugLog) window.debugLog('AI Chat initialized!', 'success');
            console.log('✅ AI Chat initialized');

            // If a symptom was passed from home page, start with it
            if (params && params.symptom) {
                window.aiChat.initWithSymptom(params.symptom);
            }
        } catch (error) {
            if (window.debugLog) window.debugLog('Error: ' + error.message, 'error');
            console.error('❌ Error initializing AI Chat:', error);
            container.innerHTML = `
                <div style="padding: 20px; text-align: center;">
                    <p style="color: red;">เกิดข้อผิดพลาด: ${error.message}</p>
                    <button onclick="location.reload()" style="padding: 10px 20px; margin-top: 10px;">ลองใหม่</button>
                </div>
            `;
        }
    }

    /**
     * Render Health Profile page
     * Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.10, 18.12
     */
    renderHealthProfilePage() {
        return `
            <div class="page-with-header">
                <div class="page-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">ข้อมูลสุขภาพ</h1>
                    <div class="header-spacer"></div>
                </div>
                <div id="health-profile-container" class="page-content">
                    <!-- Health profile content will be loaded here -->
                </div>
            </div>
        `;
    }

    /**
     * Initialize Health Profile page
     * Requirements: 18.1, 18.10
     */
    initHealthProfilePage() {
        if (window.healthProfile) {
            window.healthProfile.init();
        }
    }

    /**
     * Render Product Detail page - redirect to modal
     */
    renderProductDetailPage(params) {
        const productId = params?.id;
        
        if (productId) {
            // Show modal instead of page, then go back
            setTimeout(() => {
                this.showProductDetailModal(productId);
                // Go back to previous page after modal is shown
                window.router.back();
            }, 100);
        }

        // Return empty content since we're showing modal
        return `
            <div class="product-detail-page">
                <div class="product-detail-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">รายละเอียดสินค้า</h1>
                </div>
                <div class="empty-state">
                    <div class="skeleton" style="width: 100%; aspect-ratio: 1;"></div>
                </div>
            </div>
        `;
    }

    /**
     * Load product detail from API (legacy - kept for compatibility)
     */
    async loadProductDetail(productId) {
        try {
            const response = await fetch(`${this.config.BASE_URL}/api/shop-products.php?product_id=${productId}`);
            const data = await response.json();
            
            const container = document.getElementById('product-detail-content');
            if (!container) return;

            if (data.success && data.product) {
                const product = data.product;
                const priceHtml = product.sale_price && product.sale_price < product.price 
                    ? '<span style="text-decoration: line-through; color: var(--text-muted); font-size: 1rem; margin-left: 8px;">฿' + this.formatNumber(product.price) + '</span>' 
                    : '';
                container.innerHTML = `
                    <img src="${product.image_url || 'assets/images/placeholder.png'}" 
                         class="product-detail-image"
                         onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/></svg>'">
                    <div class="product-detail-info">
                        <h1 class="product-detail-name">${product.name}</h1>
                        <div class="product-detail-price">
                            ฿${this.formatNumber(product.sale_price || product.price)}
                            ${priceHtml}
                        </div>
                        <p class="product-detail-description">${product.description || 'ไม่มีรายละเอียดสินค้า'}</p>
                    </div>
                `;
            } else {
                container.innerHTML = '<div class="empty-state"><p>ไม่พบข้อมูลสินค้า</p></div>';
            }
        } catch (error) {
            console.error('Error loading product:', error);
        }
    }

    /**
     * Buy now - add to cart and go to checkout
     */
    buyNow(productId) {
        this.addToCart(productId);
        setTimeout(() => window.router.navigate('/checkout'), 500);
    }

    /**
     * Render Appointments page
     * เหมือนระบบเก่า liff-appointment.php - นัดเภสัชกรก่อน แล้วค่อย video call ตามเวลานัด
     */
    renderAppointmentsPage() {
        // Initialize appointment state first
        this.initAppointmentState();
        
        // Load pharmacists and appointments
        setTimeout(() => {
            this.loadPharmacistsForAppointment();
            this.loadMyAppointments();
        }, 100);

        return `
            <div class="appointments-page">
                <div class="appointments-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title" style="flex: 1; margin-left: 12px;">นัดหมายพบเภสัชกร</h1>
                    <button class="btn btn-icon" onclick="window.liffApp.showMyAppointments()">
                        <i class="fas fa-calendar-check"></i>
                    </button>
                </div>

                <!-- Step Indicator -->
                <div id="appointment-steps" class="appointment-steps">
                    <div class="step active" data-step="1">
                        <span class="step-number">1</span>
                        <span class="step-label">เลือกเภสัชกร</span>
                    </div>
                    <div class="step-line"></div>
                    <div class="step" data-step="2">
                        <span class="step-number">2</span>
                        <span class="step-label">เลือกเวลา</span>
                    </div>
                    <div class="step-line"></div>
                    <div class="step" data-step="3">
                        <span class="step-number">3</span>
                        <span class="step-label">ยืนยัน</span>
                    </div>
                </div>

                <!-- Step 1: Select Pharmacist -->
                <div id="appointment-step-1" class="appointment-step-content">
                    <div id="pharmacist-list" class="pharmacist-list">
                        <!-- Loading skeleton -->
                        <div class="pharmacist-card skeleton-card">
                            <div class="skeleton" style="width: 80px; height: 80px; border-radius: 12px;"></div>
                            <div style="flex: 1;">
                                <div class="skeleton" style="height: 20px; width: 60%; margin-bottom: 8px;"></div>
                                <div class="skeleton" style="height: 14px; width: 40%; margin-bottom: 8px;"></div>
                                <div class="skeleton" style="height: 14px; width: 80%;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Select Date & Time -->
                <div id="appointment-step-2" class="appointment-step-content hidden">
                    <div id="selected-pharmacist-info" class="selected-pharmacist-info"></div>
                    
                    <div class="date-selection">
                        <h3>เลือกวันที่</h3>
                        <div id="date-list" class="date-list"></div>
                    </div>
                    
                    <div class="time-selection">
                        <h3>เลือกเวลา</h3>
                        <div id="time-slots" class="time-slots"></div>
                        <p id="no-slots-msg" class="no-slots-msg hidden">ไม่มีช่วงเวลาว่างในวันนี้</p>
                    </div>
                    
                    <button id="step2-next-btn" class="btn btn-primary btn-block" onclick="window.liffApp.goToAppointmentStep(3)" disabled>
                        ถัดไป
                    </button>
                </div>

                <!-- Step 3: Confirm -->
                <div id="appointment-step-3" class="appointment-step-content hidden">
                    <div class="confirm-card">
                        <h3>ยืนยันการนัดหมาย</h3>
                        <div id="confirm-details" class="confirm-details"></div>
                    </div>
                    
                    <div class="symptoms-card">
                        <h3>อาการ/เหตุผลที่มา (ถ้ามี)</h3>
                        <textarea id="appointment-symptoms" rows="3" placeholder="เช่น ปวดหัว มีไข้ ต้องการปรึกษาเรื่องยา..."></textarea>
                    </div>
                    
                    <button id="confirm-booking-btn" class="btn btn-primary btn-block" onclick="window.liffApp.confirmBookAppointment()">
                        ยืนยันนัดหมาย
                    </button>
                </div>

                <!-- My Appointments Modal -->
                <div id="my-appointments-modal" class="appointments-modal hidden">
                    <div class="appointments-modal-header">
                        <button class="back-btn" onclick="window.liffApp.hideMyAppointments()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <h2>นัดหมายของฉัน</h2>
                    </div>
                    <div id="my-appointments-list" class="my-appointments-list"></div>
                </div>
            </div>
        `;
    }

    /**
     * Initialize appointment state
     */
    initAppointmentState() {
        this.appointmentState = {
            currentStep: 1,
            pharmacists: [],
            selectedPharmacist: null,
            selectedDate: null,
            selectedTime: null,
            myAppointments: []
        };
    }

    /**
     * Switch appointment tab
     */
    switchAppointmentTab(tab) {
        if (!this.appointmentState) this.initAppointmentState();
        this.appointmentState.currentTab = tab;
        
        // Update tab UI
        document.querySelectorAll('.appointment-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tab);
        });
        
        // Show/hide step indicator
        const steps = document.getElementById('appointment-steps');
        if (steps) {
            steps.classList.toggle('hidden', tab === 'instant');
        }
    }

    /**
     * Load pharmacists for appointment
     */
    async loadPharmacistsForAppointment() {
        if (!this.appointmentState) this.initAppointmentState();
        
        const container = document.getElementById('pharmacist-list');
        if (!container) return;

        try {
            const response = await fetch(`${this.config.BASE_URL}/api/appointments.php?action=pharmacists&line_account_id=${this.config.ACCOUNT_ID}`);
            const data = await response.json();

            if (data.success && data.pharmacists?.length > 0) {
                this.appointmentState.pharmacists = data.pharmacists;
                this.renderPharmacistList(data.pharmacists);
            } else {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-user-md"></i>
                        <p>ยังไม่มีเภสัชกรให้บริการ</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading pharmacists:', error);
            container.innerHTML = `
                <div class="empty-state error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>ไม่สามารถโหลดข้อมูลได้</p>
                    <button class="btn btn-sm btn-outline" onclick="window.liffApp.loadPharmacistsForAppointment()">ลองใหม่</button>
                </div>
            `;
        }
    }

    /**
     * Render pharmacist list
     */
    renderPharmacistList(pharmacists) {
        const container = document.getElementById('pharmacist-list');
        if (!container) return;

        if (!pharmacists || pharmacists.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-user-md"></i>
                    <p>ยังไม่มีเภสัชกรให้บริการ</p>
                </div>
            `;
            return;
        }

        container.innerHTML = pharmacists.map(p => {
            const fee = p.consultation_fee > 0 ? `฿${this.formatNumber(p.consultation_fee)}` : 'ฟรี';
            const duration = p.consultation_duration || 15;
            
            return `
                <div class="pharmacist-card" onclick="window.liffApp.selectPharmacistForAppointment(${p.id})">
                    <div class="pharmacist-avatar">
                        <img src="${p.image_url || this.config.BASE_URL + '/assets/images/avatar-placeholder.png'}" 
                             alt="${p.name}"
                             onerror="this.src='${this.config.BASE_URL}/assets/images/avatar-placeholder.png'">
                        <span class="pharmacist-rating">
                            <i class="fas fa-star"></i> ${p.rating || '5.0'}
                        </span>
                    </div>
                    <div class="pharmacist-info">
                        <h3 class="pharmacist-name">${p.name}</h3>
                        <p class="pharmacist-specialty">${p.specialty || 'เภสัชกรทั่วไป'}</p>
                        <p class="pharmacist-license"><i class="fas fa-id-card"></i> ${p.license_no || '-'}</p>
                        <div class="pharmacist-meta">
                            <span class="pharmacist-fee">${fee}</span>
                            <span class="pharmacist-duration">${duration} นาที</span>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm pharmacist-book-btn">
                        นัดหมาย
                    </button>
                </div>
            `;
        }).join('');
    }

    /**
     * Select pharmacist for appointment
     */
    selectPharmacistForAppointment(pharmacistId) {
        if (!this.appointmentState) this.initAppointmentState();
        
        const pharmacist = this.appointmentState.pharmacists.find(p => p.id === pharmacistId);
        if (!pharmacist) return;

        this.appointmentState.selectedPharmacist = pharmacist;
        
        // Go to step 2 - select date/time
        this.goToAppointmentStep(2);
        this.renderSelectedPharmacistInfo();
        this.renderDateSelection();
    }

    /**
     * Go to appointment step
     */
    goToAppointmentStep(step) {
        if (!this.appointmentState) this.initAppointmentState();
        this.appointmentState.currentStep = step;

        // Hide all steps
        document.querySelectorAll('.appointment-step-content').forEach(el => el.classList.add('hidden'));
        
        // Show current step
        const stepEl = document.getElementById(`appointment-step-${step}`);
        if (stepEl) stepEl.classList.remove('hidden');

        // Update step indicators
        document.querySelectorAll('.appointment-steps .step').forEach(el => {
            const s = parseInt(el.dataset.step);
            el.classList.toggle('active', s <= step);
            el.classList.toggle('completed', s < step);
        });

        // Show step indicator
        const stepsEl = document.getElementById('appointment-steps');
        if (stepsEl) stepsEl.classList.remove('hidden');

        // Render confirmation details when going to step 3
        if (step === 3) {
            this.renderConfirmationDetails();
        }
    }

    /**
     * Render selected pharmacist info
     */
    renderSelectedPharmacistInfo() {
        const container = document.getElementById('selected-pharmacist-info');
        const p = this.appointmentState?.selectedPharmacist;
        if (!container || !p) return;

        container.innerHTML = `
            <div class="selected-pharmacist">
                <img src="${p.image_url || this.config.BASE_URL + '/assets/images/avatar-placeholder.png'}" alt="${p.name}">
                <div>
                    <h4>${p.name}</h4>
                    <p>${p.specialty || 'เภสัชกรทั่วไป'}</p>
                </div>
                <button class="btn btn-icon btn-sm" onclick="window.liffApp.goToAppointmentStep(1)">
                    <i class="fas fa-edit"></i>
                </button>
            </div>
        `;
    }

    /**
     * Render date selection
     */
    renderDateSelection() {
        const container = document.getElementById('date-list');
        if (!container) return;

        const today = new Date();
        let html = '';

        for (let i = 0; i < 14; i++) {
            const date = new Date(today);
            date.setDate(today.getDate() + i);
            const dateStr = date.toISOString().split('T')[0];
            const dayName = date.toLocaleDateString('th-TH', { weekday: 'short' });
            const dayNum = date.getDate();
            const monthName = date.toLocaleDateString('th-TH', { month: 'short' });
            const isSelected = this.appointmentState?.selectedDate === dateStr;

            html += `
                <button class="date-btn ${isSelected ? 'selected' : ''}" 
                        data-date="${dateStr}"
                        onclick="window.liffApp.selectAppointmentDate('${dateStr}')">
                    <span class="date-day">${dayName}</span>
                    <span class="date-num">${dayNum}</span>
                    <span class="date-month">${monthName}</span>
                </button>
            `;
        }

        container.innerHTML = html;

        // Auto select first date
        if (!this.appointmentState?.selectedDate) {
            this.selectAppointmentDate(today.toISOString().split('T')[0]);
        }
    }

    /**
     * Select appointment date
     */
    async selectAppointmentDate(dateStr) {
        if (!this.appointmentState) this.initAppointmentState();
        this.appointmentState.selectedDate = dateStr;
        this.appointmentState.selectedTime = null;

        // Update UI
        document.querySelectorAll('.date-btn').forEach(btn => {
            btn.classList.toggle('selected', btn.dataset.date === dateStr);
        });

        // Disable next button
        const nextBtn = document.getElementById('step2-next-btn');
        if (nextBtn) nextBtn.disabled = true;

        // Load time slots
        await this.loadTimeSlots();
    }

    /**
     * Load available time slots
     */
    async loadTimeSlots() {
        const container = document.getElementById('time-slots');
        const noSlotsMsg = document.getElementById('no-slots-msg');
        if (!container) return;

        container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>';
        if (noSlotsMsg) noSlotsMsg.classList.add('hidden');

        try {
            const pharmacistId = this.appointmentState?.selectedPharmacist?.id;
            const date = this.appointmentState?.selectedDate;
            
            console.log('📅 Loading time slots for:', { pharmacistId, date });
            
            const response = await fetch(`${this.config.BASE_URL}/api/appointments.php?action=available_slots&pharmacist_id=${pharmacistId}&date=${date}`);
            const data = await response.json();
            
            console.log('📅 Time slots response:', data);

            if (data.success && data.slots?.length > 0) {
                container.innerHTML = data.slots.map(slot => `
                    <button class="time-slot-btn ${slot.available ? '' : 'disabled'}" 
                            data-time="${slot.time}"
                            onclick="window.liffApp.selectAppointmentTime('${slot.time}')"
                            ${!slot.available ? 'disabled' : ''}>
                        ${slot.time}
                    </button>
                `).join('');
            } else {
                container.innerHTML = '';
                if (noSlotsMsg) {
                    noSlotsMsg.classList.remove('hidden');
                    // Show message from API if available
                    if (data.message) {
                        noSlotsMsg.textContent = data.message;
                    } else {
                        noSlotsMsg.textContent = 'ไม่มีช่วงเวลาว่างในวันนี้';
                    }
                }
            }
        } catch (error) {
            console.error('Error loading time slots:', error);
            container.innerHTML = '<p class="error-text">ไม่สามารถโหลดข้อมูลได้</p>';
        }
    }

    /**
     * Select appointment time
     */
    selectAppointmentTime(time) {
        if (!this.appointmentState) this.initAppointmentState();
        this.appointmentState.selectedTime = time;

        // Update UI
        document.querySelectorAll('.time-slot-btn').forEach(btn => {
            btn.classList.toggle('selected', btn.dataset.time === time);
        });

        // Enable next button
        const nextBtn = document.getElementById('step2-next-btn');
        if (nextBtn) nextBtn.disabled = false;
    }

    /**
     * Render confirmation details
     */
    renderConfirmationDetails() {
        const container = document.getElementById('confirm-details');
        if (!container || !this.appointmentState) return;

        const p = this.appointmentState.selectedPharmacist;
        const date = new Date(this.appointmentState.selectedDate);
        const dateFormatted = date.toLocaleDateString('th-TH', { 
            weekday: 'long', 
            day: 'numeric', 
            month: 'long', 
            year: 'numeric' 
        });

        container.innerHTML = `
            <div class="confirm-item">
                <i class="fas fa-user-md"></i>
                <div>
                    <label>เภสัชกร</label>
                    <span>${p?.name || '-'}</span>
                </div>
            </div>
            <div class="confirm-item">
                <i class="fas fa-calendar"></i>
                <div>
                    <label>วันที่</label>
                    <span>${dateFormatted}</span>
                </div>
            </div>
            <div class="confirm-item">
                <i class="fas fa-clock"></i>
                <div>
                    <label>เวลา</label>
                    <span>${this.appointmentState.selectedTime || '-'}</span>
                </div>
            </div>
            <div class="confirm-item">
                <i class="fas fa-video"></i>
                <div>
                    <label>รูปแบบ</label>
                    <span>Video Call</span>
                </div>
            </div>
            <div class="confirm-item">
                <i class="fas fa-money-bill"></i>
                <div>
                    <label>ค่าบริการ</label>
                    <span>${p?.consultation_fee > 0 ? '฿' + this.formatNumber(p.consultation_fee) : 'ฟรี'}</span>
                </div>
            </div>
        `;
    }

    /**
     * Confirm book appointment
     */
    async confirmBookAppointment() {
        const profile = window.store?.get('profile');
        if (!profile?.userId) {
            this.showToast('กรุณาเข้าสู่ระบบก่อน', 'warning');
            return;
        }

        const btn = document.getElementById('confirm-booking-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังจอง...';
        }

        try {
            const symptoms = document.getElementById('appointment-symptoms')?.value || '';
            
            const response = await fetch(`${this.config.BASE_URL}/api/appointments.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'book',
                    line_user_id: profile.userId,
                    line_account_id: this.config.ACCOUNT_ID,
                    pharmacist_id: this.appointmentState?.selectedPharmacist?.id,
                    date: this.appointmentState?.selectedDate,
                    time: this.appointmentState?.selectedTime,
                    symptoms: symptoms
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('จองนัดหมายสำเร็จ!', 'success');
                
                // Send LIFF message via LiffMessageBridge
                if (window.liffMessageBridge) {
                    try {
                        await window.liffMessageBridge.sendAppointmentBooked(
                            this.appointmentState?.selectedDate,
                            this.appointmentState?.selectedTime,
                            {
                                pharmacist: this.appointmentState?.selectedPharmacist?.name,
                                appointmentId: data.appointment_id
                            }
                        );
                    } catch (e) {
                        console.warn('Failed to send appointment message:', e);
                    }
                }
                
                // Reset state and go back to step 1
                this.initAppointmentState();
                this.goToAppointmentStep(1);
                this.loadPharmacistsForAppointment();
            } else {
                this.showToast(data.message || 'ไม่สามารถจองได้', 'error');
            }
        } catch (error) {
            console.error('Error booking appointment:', error);
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'ยืนยันนัดหมาย';
            }
        }
    }

    /**
     * Show my appointments modal
     */
    showMyAppointments() {
        const modal = document.getElementById('my-appointments-modal');
        if (modal) {
            modal.classList.remove('hidden');
            this.loadMyAppointments();
        }
    }

    /**
     * Hide my appointments modal
     */
    hideMyAppointments() {
        const modal = document.getElementById('my-appointments-modal');
        if (modal) modal.classList.add('hidden');
    }

    /**
     * Load my appointments
     */
    async loadMyAppointments() {
        const profile = window.store?.get('profile');
        const container = document.getElementById('my-appointments-list');
        if (!container) return;

        if (!profile?.userId) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-user-lock"></i>
                    <p>กรุณาเข้าสู่ระบบเพื่อดูนัดหมาย</p>
                </div>
            `;
            return;
        }

        container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></div>';

        try {
            const response = await fetch(`${this.config.BASE_URL}/api/appointments.php?action=my_appointments&line_user_id=${profile.userId}`);
            const data = await response.json();

            if (data.success && (data.upcoming?.length > 0 || data.past?.length > 0)) {
                let html = '';
                
                // Upcoming appointments
                if (data.upcoming?.length > 0) {
                    html += '<h3 class="appointments-section-title">นัดหมายที่กำลังจะมาถึง</h3>';
                    html += data.upcoming.map(apt => this.renderMyAppointmentCard(apt, true)).join('');
                }
                
                // Past appointments
                if (data.past?.length > 0) {
                    html += '<h3 class="appointments-section-title">นัดหมายที่ผ่านมา</h3>';
                    html += data.past.map(apt => this.renderMyAppointmentCard(apt, false)).join('');
                }
                
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="far fa-calendar-alt"></i>
                        <p>ยังไม่มีนัดหมาย</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading appointments:', error);
            container.innerHTML = `
                <div class="empty-state error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>ไม่สามารถโหลดข้อมูลได้</p>
                </div>
            `;
        }
    }

    /**
     * Render my appointment card
     */
    renderMyAppointmentCard(apt, isUpcoming) {
        const canJoin = isUpcoming && apt.status === 'confirmed' && this.isAppointmentTimeNow(apt.appointment_date, apt.appointment_time);
        const dateFormatted = new Date(apt.appointment_date).toLocaleDateString('th-TH', { 
            day: 'numeric', 
            month: 'short', 
            year: '2-digit' 
        });
        
        return `
            <div class="my-appointment-card ${apt.status}">
                <div class="appointment-datetime">
                    <i class="far fa-calendar"></i>
                    ${dateFormatted} เวลา ${apt.appointment_time}
                </div>
                <div class="appointment-pharmacist">
                    <img src="${apt.pharmacist_image || this.config.BASE_URL + '/assets/images/avatar-placeholder.png'}" 
                         alt="${apt.pharmacist_name}"
                         onerror="this.src='${this.config.BASE_URL}/assets/images/avatar-placeholder.png'">
                    <span>${apt.pharmacist_name || 'เภสัชกร'}</span>
                </div>
                <div class="appointment-status-row">
                    <span class="appointment-status ${apt.status}">${this.getAppointmentStatusText(apt.status)}</span>
                    ${canJoin ? `
                        <button class="btn btn-primary btn-sm" onclick="window.router.navigate('/video-call', { appointment_id: '${apt.appointment_id}' })">
                            <i class="fas fa-video"></i> เข้าร่วม
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }

    /**
     * Check if appointment time is now (within 15 minutes)
     */
    isAppointmentTimeNow(dateStr, timeStr) {
        const appointmentDate = new Date(`${dateStr}T${timeStr}`);
        const now = new Date();
        const diffMinutes = (appointmentDate - now) / (1000 * 60);
        return diffMinutes >= -15 && diffMinutes <= 15;
    }

    getAppointmentStatusText(status) {
        const statusMap = {
            'pending': 'รอยืนยัน',
            'confirmed': 'ยืนยันแล้ว',
            'completed': 'เสร็จสิ้น',
            'cancelled': 'ยกเลิก'
        };
        return statusMap[status] || status;
    }

    /**
     * Render Redeem Points page
     */
    renderRedeemPage() {
        const member = window.store?.get('member');
        const points = member?.points || 0;

        // Load rewards
        setTimeout(() => this.loadRewards(), 100);

        return `
            <div class="redeem-page">
                <div class="redeem-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title" style="flex: 1; margin-left: 12px;">แลกแต้ม</h1>
                </div>
                
                <div class="points-balance-card">
                    <div class="points-balance-label">แต้มสะสมของคุณ</div>
                    <div class="points-balance-value">${this.formatNumber(points)}</div>
                </div>

                <h3 style="margin-bottom: 12px; font-weight: 600;">รางวัลที่แลกได้</h3>
                <div id="rewards-list">
                    <div class="reward-card">
                        <div class="skeleton" style="width: 80px; height: 80px; border-radius: 8px;"></div>
                        <div class="reward-info">
                            <div class="skeleton skeleton-text" style="height: 18px; margin-bottom: 8px;"></div>
                            <div class="skeleton skeleton-text" style="height: 14px; width: 50%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Load rewards from API
     */
    async loadRewards() {
        const container = document.getElementById('rewards-list');
        if (!container) return;

        try {
            const response = await fetch(`${this.config.BASE_URL}/api/points.php?action=rewards&line_account_id=${this.config.ACCOUNT_ID}`);
            const data = await response.json();

            if (data.success && data.rewards?.length > 0) {
                const member = window.store?.get('member');
                const userPoints = member?.points || 0;
                
                let html = '';
                data.rewards.forEach(reward => {
                    const canRedeem = userPoints >= reward.points_required;
                    html += `
                        <div class="reward-card ${!canRedeem ? 'disabled' : ''}" onclick="${canRedeem ? `window.liffApp.showRewardDetail(${reward.id})` : ''}">
                            <img src="${reward.image_url || this.config.BASE_URL + '/assets/images/image-placeholder.svg'}" 
                                 class="reward-image"
                                 onerror="this.src='${this.config.BASE_URL}/assets/images/image-placeholder.svg'">
                            <div class="reward-info">
                                <div class="reward-name">${reward.name}</div>
                                <div class="reward-points">${this.formatNumber(reward.points_required)} แต้ม</div>
                                ${!canRedeem ? '<div class="reward-insufficient">แต้มไม่พอ</div>' : ''}
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="empty-state" style="padding: 40px 20px; text-align: center;">
                        <i class="fas fa-gift" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                        <h3 style="margin-bottom: 8px;">ยังไม่มีรางวัล</h3>
                        <p style="color: var(--text-secondary);">รางวัลจะเพิ่มเข้ามาเร็วๆ นี้</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading rewards:', error);
            container.innerHTML = '<div class="empty-state"><p>ไม่สามารถโหลดข้อมูลได้</p></div>';
        }
    }

    showRewardDetail(rewardId) {
        // TODO: Show reward detail modal
        this.showToast('เร็วๆ นี้', 'info');
    }

    /**
     * Render Register/Member Registration page
     */
    renderRegisterPage() {
        const profile = window.store?.get('profile');
        
        // Check if already registered
        const member = window.store?.get('member');
        if (member?.is_registered) {
            setTimeout(() => {
                this.showToast('คุณเป็นสมาชิกอยู่แล้ว', 'info');
                window.router.navigate('/member');
            }, 100);
            return '<div class="loading-page"><div class="loading-spinner"></div></div>';
        }

        return `
            <div class="register-page">
                <div class="register-header">
                    <button class="back-btn" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1 class="page-title">สมัครสมาชิก</h1>
                    <div class="header-spacer"></div>
                </div>

                <form id="register-form" class="register-form" onsubmit="window.liffApp.submitRegistration(event)">
                    <!-- Profile Preview -->
                    <div class="register-profile-preview">
                        <img src="${profile?.pictureUrl || this.config.BASE_URL + '/assets/images/avatar-placeholder.png'}" 
                             alt="Profile" class="register-avatar"
                             onerror="this.src='${this.config.BASE_URL}/assets/images/avatar-placeholder.png'">
                        <div class="register-profile-info">
                            <p class="register-profile-name">${profile?.displayName || 'ผู้ใช้'}</p>
                            <p class="register-profile-hint">ข้อมูลจาก LINE</p>
                        </div>
                    </div>

                    <!-- Required Fields -->
                    <div class="form-section">
                        <h3 class="form-section-title">ข้อมูลส่วนตัว <span class="required">*</span></h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">ชื่อ <span class="required">*</span></label>
                                <input type="text" id="first_name" name="first_name" required placeholder="ชื่อจริง">
                            </div>
                            <div class="form-group">
                                <label for="last_name">นามสกุล</label>
                                <input type="text" id="last_name" name="last_name" placeholder="นามสกุล">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="birthday">วันเกิด <span class="required">*</span></label>
                                <input type="date" id="birthday" name="birthday" required>
                            </div>
                            <div class="form-group">
                                <label for="gender">เพศ <span class="required">*</span></label>
                                <select id="gender" name="gender" required>
                                    <option value="">เลือก</option>
                                    <option value="male">ชาย</option>
                                    <option value="female">หญิง</option>
                                    <option value="other">อื่นๆ</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phone">เบอร์โทรศัพท์</label>
                            <input type="tel" id="phone" name="phone" placeholder="0812345678" pattern="[0-9]{9,10}">
                        </div>
                    </div>

                    <!-- Health Info -->
                    <div class="form-section">
                        <h3 class="form-section-title">ข้อมูลสุขภาพ (ถ้ามี)</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="weight">น้ำหนัก (กก.)</label>
                                <input type="number" id="weight" name="weight" placeholder="60" min="1" max="300" step="0.1">
                            </div>
                            <div class="form-group">
                                <label for="height">ส่วนสูง (ซม.)</label>
                                <input type="number" id="height" name="height" placeholder="170" min="50" max="250">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="drug_allergies">แพ้ยา</label>
                            <textarea id="drug_allergies" name="drug_allergies" rows="2" placeholder="ระบุยาที่แพ้ (ถ้ามี)"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="medical_conditions">โรคประจำตัว</label>
                            <textarea id="medical_conditions" name="medical_conditions" rows="2" placeholder="ระบุโรคประจำตัว (ถ้ามี)"></textarea>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="form-section">
                        <h3 class="form-section-title">ที่อยู่จัดส่ง (ถ้ามี)</h3>
                        
                        <div class="form-group">
                            <label for="address">ที่อยู่</label>
                            <textarea id="address" name="address" rows="2" placeholder="บ้านเลขที่ ซอย ถนน"></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="district">เขต/อำเภอ</label>
                                <input type="text" id="district" name="district" placeholder="เขต/อำเภอ">
                            </div>
                            <div class="form-group">
                                <label for="province">จังหวัด</label>
                                <input type="text" id="province" name="province" placeholder="จังหวัด">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="postal_code">รหัสไปรษณีย์</label>
                            <input type="text" id="postal_code" name="postal_code" placeholder="10xxx" pattern="[0-9]{5}">
                        </div>
                    </div>

                    <!-- Consent -->
                    <div class="form-section">
                        <label class="checkbox-label">
                            <input type="checkbox" id="consent" name="consent" required>
                            <span>ยอมรับ <a href="#" onclick="window.liffApp.showTerms(); return false;">ข้อกำหนดและเงื่อนไข</a> และ <a href="#" onclick="window.liffApp.showPrivacy(); return false;">นโยบายความเป็นส่วนตัว</a></span>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" id="register-submit-btn" class="btn btn-primary btn-block btn-lg">
                        <i class="fas fa-user-plus"></i> สมัครสมาชิก
                    </button>
                </form>
            </div>
        `;
    }

    /**
     * Submit registration form
     */
    async submitRegistration(event) {
        event.preventDefault();
        
        const profile = window.store?.get('profile');
        if (!profile?.userId) {
            this.showToast('กรุณาเข้าสู่ระบบผ่าน LINE ก่อน', 'warning');
            return;
        }

        const form = document.getElementById('register-form');
        const btn = document.getElementById('register-submit-btn');
        
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        // Disable button
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังสมัคร...';

        try {
            const formData = new FormData(form);
            const data = {
                action: 'register',
                line_user_id: profile.userId,
                line_account_id: this.config.ACCOUNT_ID,
                first_name: formData.get('first_name'),
                last_name: formData.get('last_name'),
                birthday: formData.get('birthday'),
                gender: formData.get('gender'),
                phone: formData.get('phone'),
                weight: formData.get('weight'),
                height: formData.get('height'),
                drug_allergies: formData.get('drug_allergies'),
                medical_conditions: formData.get('medical_conditions'),
                address: formData.get('address'),
                district: formData.get('district'),
                province: formData.get('province'),
                postal_code: formData.get('postal_code')
            };

            const response = await fetch(`${this.config.BASE_URL}/api/member.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                this.showToast('สมัครสมาชิกสำเร็จ!', 'success');
                
                // Reload member data
                await this.loadMemberData();
                
                // Navigate to member card
                setTimeout(() => {
                    window.router.navigate('/member');
                }, 1000);
            } else {
                this.showToast(result.message || 'ไม่สามารถสมัครได้', 'error');
            }
        } catch (error) {
            console.error('Registration error:', error);
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-user-plus"></i> สมัครสมาชิก';
        }
    }

    /**
     * Show terms of service
     */
    showTerms() {
        window.open(`${this.config.BASE_URL}/terms-of-service.php`, '_blank');
    }

    /**
     * Show privacy policy
     */
    showPrivacy() {
        window.open(`${this.config.BASE_URL}/privacy-policy.php`, '_blank');
    }

    /**
     * Render placeholder page
     */
    renderPlaceholderPage(page, params) {
        const paramsStr = params && Object.keys(params).length > 0 
            ? '<p class="text-xs text-muted mt-2">Params: ' + JSON.stringify(params) + '</p>' 
            : '';
        return `
            <div class="placeholder-page p-4">
                <h2 class="text-xl font-bold mb-4">${page}</h2>
                <p class="text-secondary">หน้านี้จะถูกพัฒนาในขั้นตอนถัดไป</p>
                ${paramsStr}
            </div>
        `;
    }

    // ==================== Prescription Drug Flow Methods ====================
    // Requirements: 11.1, 11.2, 11.3, 11.4, 11.7, 11.9, 11.10

    /**
     * Check prescription approval for checkout
     * Requirements: 11.3 - Block checkout for Rx items without approval
     */
    async checkPrescriptionApprovalForCheckout() {
        const cart = window.store?.get('cart');
        
        if (!cart || !cart.hasPrescription) return;
        
        // Use PrescriptionHandler if available
        if (window.PrescriptionHandler) {
            const checkResult = await window.PrescriptionHandler.canProceedToCheckout(cart);
            
            if (!checkResult.canCheckout) {
                // Show blocked modal
                window.PrescriptionHandler.showCheckoutBlockedModal(
                    cart,
                    checkResult,
                    // onConsult callback
                    (prescriptionItems) => {
                        this.requestPrescriptionConsultation(prescriptionItems);
                    },
                    // onClose callback
                    () => {
                        // Stay on checkout page but disable submit
                        this.updateCheckoutPrescriptionState(false);
                    }
                );
            } else {
                this.updateCheckoutPrescriptionState(true);
            }
        }
    }

    /**
     * Verify existing prescription approval is still valid
     * Requirements: 11.10 - Require re-consultation if expired
     */
    async verifyPrescriptionApproval() {
        const cart = window.store?.get('cart');
        
        if (!cart || !cart.prescriptionApprovalId) return;
        
        if (window.PrescriptionHandler) {
            const status = await window.PrescriptionHandler.checkApprovalStatus(cart.prescriptionApprovalId);
            
            if (!status.valid) {
                // Approval expired or invalid
                cart.prescriptionApprovalId = null;
                window.store?.set('cart', cart);
                
                // Show expired modal
                window.PrescriptionHandler.showCheckoutBlockedModal(
                    cart,
                    { canCheckout: false, reason: status.reason, expired: status.expired, needsConsultation: true },
                    (prescriptionItems) => {
                        this.requestPrescriptionConsultation(prescriptionItems);
                    },
                    () => {
                        this.updateCheckoutPrescriptionState(false);
                    }
                );
            } else {
                // Show approval timer
                this.showApprovalTimer(status);
                this.updateCheckoutPrescriptionState(true);
            }
        }
    }

    /**
     * Update checkout state based on prescription approval
     */
    updateCheckoutPrescriptionState(approved) {
        if (this.checkoutState) {
            this.checkoutState.prescriptionApproved = approved;
        }
        
        // Update submit button state
        const submitBtn = document.getElementById('place-order-btn');
        if (submitBtn && !approved) {
            submitBtn.disabled = true;
        }
        
        // Show/hide prescription warning
        this.updateCheckoutPrescriptionUI(approved);
    }

    /**
     * Update checkout UI for prescription status
     */
    updateCheckoutPrescriptionUI(approved) {
        const cart = window.store?.get('cart');
        if (!cart?.hasPrescription) return;
        
        // Find or create prescription notice container
        let noticeContainer = document.getElementById('checkout-rx-status');
        
        if (!noticeContainer) {
            const checkoutForm = document.getElementById('checkout-form');
            if (checkoutForm) {
                noticeContainer = document.createElement('div');
                noticeContainer.id = 'checkout-rx-status';
                checkoutForm.insertBefore(noticeContainer, checkoutForm.firstChild);
            }
        }
        
        if (noticeContainer) {
            if (approved) {
                noticeContainer.innerHTML = `
                    <div class="checkout-rx-approved" style="padding: 16px; background: linear-gradient(135deg, #D1FAE5, #A7F3D0); border-radius: 16px; margin-bottom: 16px; border: 1px solid #10B981;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; background: #10B981; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-check" style="color: white; font-size: 18px;"></i>
                            </div>
                            <div>
                                <p style="font-size: 14px; font-weight: 700; color: #065F46; margin-bottom: 2px;">ยาได้รับการอนุมัติแล้ว</p>
                                <p style="font-size: 12px; color: #047857;">คุณสามารถดำเนินการสั่งซื้อได้</p>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                const prescriptionItems = window.PrescriptionHandler?.getPrescriptionItems(cart) || [];
                noticeContainer.innerHTML = `
                    <div class="checkout-rx-blocked" style="padding: 16px; background: linear-gradient(135deg, #FEE2E2, #FECACA); border-radius: 16px; margin-bottom: 16px; border: 1px solid #EF4444;">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                            <div style="width: 40px; height: 40px; background: #EF4444; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-prescription" style="color: white; font-size: 18px;"></i>
                            </div>
                            <div>
                                <p style="font-size: 14px; font-weight: 700; color: #991B1B; margin-bottom: 2px;">ต้องปรึกษาเภสัชกรก่อน</p>
                                <p style="font-size: 12px; color: #B91C1C;">มียา ${prescriptionItems.length} รายการที่ต้องได้รับการอนุมัติ</p>
                            </div>
                        </div>
                        <button onclick="window.liffApp.requestPrescriptionConsultation()" class="btn-consult-pharmacist" style="width: 100%; min-height: 48px; background: linear-gradient(135deg, #11B0A6, #0D8A82); color: white; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
                            <i class="fas fa-video"></i> ปรึกษาเภสัชกร
                        </button>
                    </div>
                `;
            }
        }
    }

    /**
     * Show approval timer in checkout
     */
    showApprovalTimer(approvalStatus) {
        if (!approvalStatus.expiresAt) return;
        
        const timeRemaining = window.PrescriptionHandler?.getTimeUntilExpiry(approvalStatus.expiresAt);
        if (!timeRemaining || timeRemaining.expired) return;
        
        const isExpiringSoon = timeRemaining.hours < 2;
        
        let timerContainer = document.getElementById('approval-timer-container');
        if (!timerContainer) {
            const checkoutForm = document.getElementById('checkout-form');
            if (checkoutForm) {
                timerContainer = document.createElement('div');
                timerContainer.id = 'approval-timer-container';
                checkoutForm.insertBefore(timerContainer, checkoutForm.firstChild);
            }
        }
        
        if (timerContainer) {
            timerContainer.innerHTML = `
                <div class="approval-timer ${isExpiringSoon ? 'expiring-soon' : ''}" style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; background: ${isExpiringSoon ? '#FEF3C7' : '#D1FAE5'}; border-radius: 12px; margin-bottom: 16px;">
                    <i class="fas fa-clock" style="font-size: 16px; color: ${isExpiringSoon ? '#D97706' : '#059669'};"></i>
                    <span style="font-size: 13px; color: ${isExpiringSoon ? '#92400E' : '#065F46'};">
                        การอนุมัติยาจะหมดอายุใน 
                        <strong style="color: ${isExpiringSoon ? '#D97706' : '#059669'};">${timeRemaining.hours} ชม. ${timeRemaining.minutes} นาที</strong>
                    </span>
                </div>
            `;
        }
    }

    /**
     * Request prescription consultation
     * Requirements: 11.4 - Display "Consult Pharmacist" button
     */
    requestPrescriptionConsultation(prescriptionItems) {
        const cart = window.store?.get('cart');
        const items = prescriptionItems || window.PrescriptionHandler?.getPrescriptionItems(cart) || [];
        
        // Navigate to video call with prescription context
        window.router?.navigate('/video-call', {
            reason: 'prescription_approval',
            items: items.map(item => ({
                product_id: item.product_id,
                name: item.name,
                quantity: item.quantity
            }))
        });
    }

    /**
     * Handle prescription product being added to cart
     * Requirements: 11.2 - Display modal explaining approval process
     */
    handlePrescriptionProductAdd(product, onSuccess, onCancel) {
        if (!window.PrescriptionHandler?.isPrescriptionProduct(product)) {
            // Not a prescription product, proceed normally
            if (onSuccess) onSuccess(product);
            return;
        }
        
        // Show prescription info modal
        window.PrescriptionHandler.showPrescriptionInfoModal(
            product,
            (prod) => {
                // User acknowledged, proceed with add
                if (onSuccess) onSuccess(prod);
            },
            () => {
                // User cancelled
                if (onCancel) onCancel();
            }
        );
    }

    // ==================== Video Call Page ====================
    // Requirements: 6.2, 6.3

    /**
     * Render video call page
     * Requirements: 6.2 - Establish WebRTC connection using peer-to-peer signaling
     * Requirements: 6.3, 6.4, 6.5, 6.8 - Display large video area with remote video prominent, call controls, iOS handling
     * @param {Object} params - Route parameters
     */
    renderVideoCallPage(params = {}) {
        // Get appointment_id from params
        const appointmentId = params.appointment_id || params.appointmentId || null;
        
        // Initialize video call manager if not already done
        if (window.videoCallManager) {
            window.videoCallManager.init({
                baseUrl: this.config.BASE_URL,
                accountId: this.config.ACCOUNT_ID,
                appointmentId: appointmentId,
                onStateChange: (state, oldState, data) => this.handleVideoCallStateChange(state, oldState, data),
                onRemoteStream: (stream) => this.handleRemoteStream(stream),
                onCallEnded: (data) => this.handleCallEnded(data),
                onError: (error) => this.handleVideoCallError(error),
                onControlsUpdate: (state) => this.handleControlsUpdate(state)
            });
        }

        const reason = params.reason || '';
        const pharmacistId = params.pharmacist_id || '';
        
        // Check for iOS limitations
        const iosLimitations = window.videoCallManager?.checkIOSLimitations() || { hasLimitations: false };
        
        return `
            <div class="video-call-page" id="video-call-page">
                <!-- iOS Limitation Warning (Requirement 6.8) -->
                ${iosLimitations.hasLimitations ? `
                    <div id="vc-ios-warning" class="video-call-ios-warning">
                        <div class="video-call-ios-warning-content">
                            <div class="video-call-ios-warning-icon">
                                <i class="fab fa-apple"></i>
                            </div>
                            <h3 class="video-call-ios-warning-title">ข้อจำกัดบน iOS</h3>
                            <p class="video-call-ios-warning-text">${iosLimitations.message}</p>
                            <p class="video-call-ios-warning-suggestion">${iosLimitations.suggestion}</p>
                            <div class="video-call-ios-warning-actions">
                                <button class="btn btn-primary" onclick="window.liffApp.dismissIOSWarning()">
                                    <i class="fas fa-play"></i> ดำเนินการต่อ
                                </button>
                                <button class="btn btn-secondary" onclick="window.videoCallManager.openInExternalBrowser()">
                                    <i class="fas fa-external-link-alt"></i> เปิดใน Safari
                                </button>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                <!-- Loading State -->
                <div id="vc-loading" class="video-call-loading ${iosLimitations.hasLimitations ? 'hidden' : ''}">
                    <div class="video-call-loading-content">
                        <div class="video-call-loading-icon">
                            <i class="fas fa-video"></i>
                        </div>
                        <p class="video-call-loading-text">กำลังเตรียมพร้อม...</p>
                        <p class="video-call-loading-status" id="vc-loading-status">เข้าถึงกล้อง</p>
                    </div>
                </div>

                <!-- Pre-Call Screen -->
                <div id="vc-pre-call" class="video-call-pre-call hidden">
                    <!-- Preview Video -->
                    <video id="vc-preview-video" class="video-call-preview" autoplay playsinline muted></video>
                    
                    <!-- Gradient Overlay -->
                    <div class="video-call-overlay"></div>
                    
                    <!-- Context Banner (if from prescription or drug interaction) -->
                    ${reason ? `
                        <div class="video-call-context-banner">
                            <div class="video-call-context-icon">
                                <i class="fas ${reason === 'prescription_approval' ? 'fa-prescription' : 'fa-exclamation-triangle'}"></i>
                            </div>
                            <div class="video-call-context-text">
                                <p class="video-call-context-title">
                                    ${reason === 'prescription_approval' ? 'ปรึกษาเรื่องยาตามใบสั่งแพทย์' : 'ปรึกษาเรื่องปฏิกิริยาระหว่างยา'}
                                </p>
                                <p class="video-call-context-desc">เภสัชกรจะช่วยตรวจสอบและให้คำแนะนำ</p>
                            </div>
                        </div>
                    ` : ''}
                    
                    <!-- Pre-Call Controls -->
                    <div class="video-call-pre-controls">
                        <h1 class="video-call-title">
                            <i class="fas fa-video"></i> Video Call
                        </h1>
                        <p class="video-call-subtitle">โทรหาเภสัชกรของเรา</p>
                        
                        <button class="video-call-start-btn" onclick="window.liffApp.startVideoCall(false)">
                            <i class="fas fa-video"></i>
                            <span>Video Call</span>
                        </button>
                        
                        <button class="video-call-audio-btn" onclick="window.liffApp.startVideoCall(true)">
                            <i class="fas fa-phone"></i>
                            <span>Audio Only</span>
                        </button>
                    </div>
                    
                    <!-- Camera Switch Button -->
                    <button class="video-call-switch-camera" onclick="window.liffApp.switchVideoCamera()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>

                <!-- In-Call Screen -->
                <div id="vc-in-call" class="video-call-in-call hidden">
                    <!-- Remote Video (Large) - Requirement 6.3 -->
                    <video id="vc-remote-video" class="video-call-remote" autoplay playsinline></video>
                    
                    <!-- Local Video (PIP) -->
                    <div class="video-call-pip" id="vc-local-pip">
                        <video id="vc-local-video" class="video-call-local" autoplay playsinline muted></video>
                    </div>
                    
                    <!-- Status Bar -->
                    <div class="video-call-status-bar">
                        <div class="video-call-status" id="vc-status">
                            <span class="video-call-status-dot"></span>
                            <span class="video-call-status-text" id="vc-status-text">กำลังเชื่อมต่อ...</span>
                        </div>
                        <div class="video-call-timer hidden" id="vc-timer">
                            <span class="video-call-timer-dot">●</span>
                            <span class="video-call-timer-text" id="vc-timer-text">00:00</span>
                        </div>
                    </div>
                    
                    <!-- Call Controls - Requirements: 6.4, 6.5 -->
                    <div class="video-call-controls">
                        <button class="video-call-ctrl-btn" id="vc-btn-mute" onclick="window.liffApp.toggleVideoMute()" title="ปิด/เปิดไมค์">
                            <i class="fas fa-microphone"></i>
                            <span class="video-call-ctrl-label">ไมค์</span>
                        </button>
                        <button class="video-call-ctrl-btn video-call-end-btn" id="vc-btn-end" onclick="window.liffApp.showEndCallConfirm()" title="วางสาย">
                            <i class="fas fa-phone-slash"></i>
                            <span class="video-call-ctrl-label">วางสาย</span>
                        </button>
                        <button class="video-call-ctrl-btn" id="vc-btn-video" onclick="window.liffApp.toggleVideoCamera()" title="ปิด/เปิดกล้อง">
                            <i class="fas fa-video"></i>
                            <span class="video-call-ctrl-label">กล้อง</span>
                        </button>
                        <button class="video-call-ctrl-btn" id="vc-btn-switch" onclick="window.liffApp.switchVideoCamera()" title="สลับกล้อง">
                            <i class="fas fa-sync-alt"></i>
                            <span class="video-call-ctrl-label">สลับ</span>
                        </button>
                    </div>
                    
                    <!-- End Call Confirmation - Requirement 6.5 -->
                    <div id="vc-end-confirm" class="video-call-end-confirm hidden">
                        <div class="video-call-end-confirm-content">
                            <p class="video-call-end-confirm-text">ต้องการวางสายหรือไม่?</p>
                            <div class="video-call-end-confirm-actions">
                                <button class="video-call-end-confirm-cancel" onclick="window.liffApp.hideEndCallConfirm()">
                                    ยกเลิก
                                </button>
                                <button class="video-call-end-confirm-btn" onclick="window.liffApp.confirmEndCall()">
                                    <i class="fas fa-phone-slash"></i> วางสาย
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- End Screen -->
                <div id="vc-end-screen" class="video-call-end-screen hidden">
                    <div class="video-call-end-content">
                        <div class="video-call-end-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2 class="video-call-end-title" id="vc-end-title">สิ้นสุดการโทร</h2>
                        <p class="video-call-end-duration-label">ระยะเวลา</p>
                        <p class="video-call-end-duration" id="vc-end-duration">00:00</p>
                        
                        <button class="video-call-restart-btn" onclick="window.liffApp.resetVideoCall()">
                            <i class="fas fa-redo"></i>
                            <span>โทรอีกครั้ง</span>
                        </button>
                        <button class="video-call-close-btn" onclick="window.router.navigate('/')">
                            <span>กลับหน้าหลัก</span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Initialize video call page after render
     * Requirements: 6.1, 6.6 - Pre-call permission check UI
     */
    async initVideoCallPage() {
        if (!window.videoCallManager) {
            console.error('VideoCallManager not available');
            return;
        }

        // Initialize permission checker
        if (window.permissionChecker) {
            window.permissionChecker.init({
                onPermissionsGranted: (stream) => this.handlePermissionsGranted(stream),
                onPermissionsDenied: (result) => this.handlePermissionsDenied(result),
                onPermissionChange: (type, state) => this.handlePermissionChange(type, state)
            });
        }

        // Check WebRTC support first
        if (window.permissionChecker && !window.permissionChecker.isWebRTCSupported()) {
            this.showVideoCallError('เบราว์เซอร์นี้ไม่รองรับวิดีโอคอล กรุณาใช้เบราว์เซอร์อื่น');
            return;
        }

        try {
            this.updateVideoCallLoadingStatus('ตรวจสอบสิทธิ์การเข้าถึง...');
            
            // Check current permission status
            let permissionStatus = { camera: 'prompt', microphone: 'prompt' };
            if (window.permissionChecker) {
                permissionStatus = await window.permissionChecker.checkPermissionStatus();
            }
            
            // If permissions already denied, show permission check UI
            if (permissionStatus.camera === 'denied' || permissionStatus.microphone === 'denied') {
                this.showPermissionCheckUI(permissionStatus);
                return;
            }
            
            this.updateVideoCallLoadingStatus('เข้าถึงกล้อง...');
            
            // Request permissions and get local stream
            if (window.permissionChecker) {
                const result = await window.permissionChecker.requestPermissions();
                if (!result.success) {
                    // If device not found (no camera), still allow to proceed for testing
                    if (result.errorType === 'not_found') {
                        console.warn('📹 No camera found, proceeding without preview');
                        this.handlePermissionsGranted(null);
                        return;
                    }
                    this.showPermissionCheckUI(result);
                    return;
                }
                // Stream is handled by onPermissionsGranted callback
            } else {
                // Fallback to direct request
                try {
                    const stream = await window.videoCallManager.getLocalStream();
                    this.handlePermissionsGranted(stream);
                } catch (e) {
                    console.warn('📹 Could not get stream, proceeding anyway:', e);
                    this.handlePermissionsGranted(null);
                }
            }
            
        } catch (error) {
            console.error('Video call init error:', error);
            // Allow to proceed even with errors for testing
            console.warn('📹 Proceeding to pre-call despite error');
            this.handlePermissionsGranted(null);
        }
    }

    /**
     * Handle permissions granted
     * @param {MediaStream} stream - The media stream
     */
    handlePermissionsGranted(stream) {
        // Store stream in video call manager
        if (window.videoCallManager && !window.videoCallManager.localStream) {
            window.videoCallManager.localStream = stream;
        }
        
        // Show preview
        const previewVideo = document.getElementById('vc-preview-video');
        if (previewVideo && stream) {
            previewVideo.srcObject = stream;
        }
        
        // Hide loading/permission UI, show pre-call
        document.getElementById('vc-loading')?.classList.add('hidden');
        document.getElementById('vc-permission-check')?.classList.add('hidden');
        document.getElementById('vc-pre-call')?.classList.remove('hidden');
    }

    /**
     * Handle permissions denied
     * Requirements: 6.6 - Display instructions to enable permissions in device settings
     * @param {Object} result - The error result
     */
    handlePermissionsDenied(result) {
        const loadingEl = document.getElementById('vc-loading');
        if (loadingEl && window.permissionChecker) {
            loadingEl.innerHTML = window.permissionChecker.renderPermissionDeniedUI(result);
            loadingEl.classList.remove('hidden');
        }
    }

    /**
     * Handle permission change
     * @param {string} type - Permission type (camera/microphone)
     * @param {string} state - New state
     */
    handlePermissionChange(type, state) {
        console.log(`Permission ${type} changed to ${state}`);
        // Could update UI here if needed
    }

    /**
     * Show permission check UI
     * Requirements: 6.1 - Display pre-call check UI for camera and microphone permissions
     * @param {Object} status - Permission status
     */
    showPermissionCheckUI(status) {
        const loadingEl = document.getElementById('vc-loading');
        if (loadingEl && window.permissionChecker) {
            loadingEl.innerHTML = window.permissionChecker.renderPermissionCheckUI(status);
            loadingEl.classList.remove('hidden');
        }
        document.getElementById('vc-pre-call')?.classList.add('hidden');
    }

    /**
     * Update loading status text
     */
    updateVideoCallLoadingStatus(text) {
        const el = document.getElementById('vc-loading-status');
        if (el) el.textContent = text;
    }

    /**
     * Start video call
     * @param {boolean} audioOnly - Start with audio only
     */
    async startVideoCall(audioOnly = false) {
        if (!window.videoCallManager) return;

        try {
            // Hide pre-call, show in-call
            document.getElementById('vc-pre-call')?.classList.add('hidden');
            document.getElementById('vc-in-call')?.classList.remove('hidden');
            
            // Set local video
            const localVideo = document.getElementById('vc-local-video');
            if (localVideo && window.videoCallManager.localStream) {
                localVideo.srcObject = window.videoCallManager.localStream;
            }
            
            // Start the call
            await window.videoCallManager.startCall(audioOnly);
            
        } catch (error) {
            console.error('Start call error:', error);
            this.showVideoCallError(error.message || 'ไม่สามารถเริ่มการโทรได้');
        }
    }

    /**
     * Handle video call state change
     */
    handleVideoCallStateChange(state, oldState, data) {
        console.log('Video call state:', state, data);
        
        const statusText = document.getElementById('vc-status-text');
        const timer = document.getElementById('vc-timer');
        const statusDot = document.querySelector('.video-call-status-dot');
        
        switch (state) {
            case 'connecting':
                if (statusText) statusText.textContent = 'กำลังเชื่อมต่อ...';
                break;
            case 'ringing':
                if (statusText) statusText.textContent = 'กำลังโทร...';
                break;
            case 'active':
                if (statusText) statusText.textContent = 'เชื่อมต่อแล้ว';
                if (statusDot) statusDot.classList.add('active');
                if (timer) timer.classList.remove('hidden');
                this.startVideoCallTimer();
                break;
            case 'reconnecting':
                if (statusText) statusText.textContent = 'กำลังเชื่อมต่อใหม่...';
                break;
            case 'ended':
                this.showVideoCallEndScreen(data);
                break;
            case 'error':
                this.showVideoCallError(data?.error?.message || 'เกิดข้อผิดพลาด');
                break;
        }
    }

    /**
     * Handle remote stream received
     * Requirements: 6.3 - Display remote video prominent
     */
    handleRemoteStream(stream) {
        const remoteVideo = document.getElementById('vc-remote-video');
        if (remoteVideo) {
            remoteVideo.srcObject = stream;
        }
    }

    /**
     * Handle call ended
     */
    handleCallEnded(data) {
        this.showVideoCallEndScreen(data);
    }

    /**
     * Handle video call error
     */
    handleVideoCallError(error) {
        this.showVideoCallError(error.message || 'เกิดข้อผิดพลาด');
    }

    /**
     * Show video call error
     */
    showVideoCallError(message) {
        // Hide all screens
        document.getElementById('vc-loading')?.classList.add('hidden');
        document.getElementById('vc-pre-call')?.classList.add('hidden');
        document.getElementById('vc-in-call')?.classList.add('hidden');
        
        // Show end screen with error
        const endScreen = document.getElementById('vc-end-screen');
        const endTitle = document.getElementById('vc-end-title');
        const endIcon = document.querySelector('.video-call-end-icon i');
        
        if (endScreen) endScreen.classList.remove('hidden');
        if (endTitle) endTitle.textContent = message;
        if (endIcon) {
            endIcon.className = 'fas fa-exclamation-circle';
            endIcon.parentElement.style.color = 'var(--danger)';
        }
    }

    /**
     * Show video call end screen
     * Requirements: 6.7 - Display consultation summary
     */
    showVideoCallEndScreen(data = {}) {
        // Stop timer
        this.stopVideoCallTimer();
        
        // Hide in-call, show end screen
        document.getElementById('vc-in-call')?.classList.add('hidden');
        const endScreen = document.getElementById('vc-end-screen');
        if (endScreen) endScreen.classList.remove('hidden');
        
        // Update duration
        const duration = data.duration || window.videoCallManager?.callDuration || 0;
        const durationEl = document.getElementById('vc-end-duration');
        if (durationEl) {
            durationEl.textContent = window.videoCallManager?.formatDuration(duration) || '00:00';
        }
        
        // Update title
        const titleEl = document.getElementById('vc-end-title');
        if (titleEl) {
            titleEl.textContent = data.reason || 'สิ้นสุดการโทร';
        }
        
        // Store call ID for summary retrieval
        this.lastCallId = data.callId;
        
        // Show summary section if call was completed
        if (duration > 0) {
            this.showCallSummary(data.callId, duration);
        }
    }

    /**
     * Show call summary with duration and notes option
     * Requirements: 6.7 - Record session duration and save consultation notes
     */
    async showCallSummary(callId, duration) {
        const endContent = document.querySelector('.video-call-end-content');
        if (!endContent) return;
        
        // Add summary section if not exists
        let summarySection = document.getElementById('vc-summary-section');
        if (!summarySection) {
            summarySection = document.createElement('div');
            summarySection.id = 'vc-summary-section';
            summarySection.className = 'video-call-summary-section';
            
            // Insert before buttons
            const restartBtn = endContent.querySelector('.video-call-restart-btn');
            if (restartBtn) {
                endContent.insertBefore(summarySection, restartBtn);
            } else {
                endContent.appendChild(summarySection);
            }
        }
        
        // Format duration for display
        const mins = Math.floor(duration / 60);
        const secs = duration % 60;
        const durationText = mins > 0 ? `${mins} นาที ${secs} วินาที` : `${secs} วินาที`;
        
        summarySection.innerHTML = `
            <div class="video-call-summary">
                <div class="video-call-summary-item">
                    <i class="fas fa-clock"></i>
                    <span>ระยะเวลาการโทร: ${durationText}</span>
                </div>
                <div class="video-call-summary-item">
                    <i class="fas fa-calendar"></i>
                    <span>วันที่: ${new Date().toLocaleDateString('th-TH', { 
                        day: 'numeric', 
                        month: 'short', 
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    })}</span>
                </div>
            </div>
        `;
    }

    /**
     * Toggle mute
     */
    toggleVideoMute() {
        if (!window.videoCallManager) return;
        
        const isMuted = window.videoCallManager.toggleMute();
        const btn = document.getElementById('vc-btn-mute');
        
        if (btn) {
            btn.innerHTML = isMuted ? '<i class="fas fa-microphone-slash"></i><span class="video-call-ctrl-label">ไมค์</span>' : '<i class="fas fa-microphone"></i><span class="video-call-ctrl-label">ไมค์</span>';
            btn.classList.toggle('active', isMuted);
        }
    }

    /**
     * Toggle video
     */
    toggleVideoCamera() {
        if (!window.videoCallManager) return;
        
        const isOff = window.videoCallManager.toggleVideo();
        const btn = document.getElementById('vc-btn-video');
        
        if (btn) {
            btn.innerHTML = isOff ? '<i class="fas fa-video-slash"></i><span class="video-call-ctrl-label">กล้อง</span>' : '<i class="fas fa-video"></i><span class="video-call-ctrl-label">กล้อง</span>';
            btn.classList.toggle('active', isOff);
        }
    }

    /**
     * Switch camera
     */
    async switchVideoCamera() {
        if (!window.videoCallManager) return;
        
        try {
            const stream = await window.videoCallManager.switchCamera();
            
            // Update preview video
            const previewVideo = document.getElementById('vc-preview-video');
            if (previewVideo) previewVideo.srcObject = stream;
            
            // Update local video
            const localVideo = document.getElementById('vc-local-video');
            if (localVideo) localVideo.srcObject = stream;
            
        } catch (error) {
            console.error('Switch camera error:', error);
            this.showToast('ไม่สามารถสลับกล้องได้', 'error');
        }
    }

    /**
     * End video call
     */
    async endVideoCall() {
        if (!window.videoCallManager) return;
        await window.videoCallManager.endCall();
    }

    /**
     * Show end call confirmation
     * Requirements: 6.5 - Display red confirmation button for End Call
     */
    showEndCallConfirm() {
        const confirmEl = document.getElementById('vc-end-confirm');
        if (confirmEl) {
            confirmEl.classList.remove('hidden');
        }
    }

    /**
     * Hide end call confirmation
     */
    hideEndCallConfirm() {
        const confirmEl = document.getElementById('vc-end-confirm');
        if (confirmEl) {
            confirmEl.classList.add('hidden');
        }
    }

    /**
     * Confirm and end call
     * Requirements: 6.5 - Display red confirmation button for End Call
     */
    async confirmEndCall() {
        this.hideEndCallConfirm();
        await this.endVideoCall();
    }

    /**
     * Dismiss iOS warning and continue
     * Requirements: 6.8 - Handle iOS WebRTC limitations
     */
    dismissIOSWarning() {
        const warningEl = document.getElementById('vc-ios-warning');
        const loadingEl = document.getElementById('vc-loading');
        
        if (warningEl) {
            warningEl.classList.add('hidden');
        }
        if (loadingEl) {
            loadingEl.classList.remove('hidden');
        }
        
        // Continue with initialization
        this.initVideoCallPage();
    }

    /**
     * Handle controls update from video call manager
     */
    handleControlsUpdate(state) {
        // Update mute button
        const muteBtn = document.getElementById('vc-btn-mute');
        if (muteBtn) {
            muteBtn.innerHTML = state.isMuted 
                ? '<i class="fas fa-microphone-slash"></i><span class="video-call-ctrl-label">ไมค์</span>' 
                : '<i class="fas fa-microphone"></i><span class="video-call-ctrl-label">ไมค์</span>';
            muteBtn.classList.toggle('active', state.isMuted);
        }
        
        // Update video button
        const videoBtn = document.getElementById('vc-btn-video');
        if (videoBtn) {
            videoBtn.innerHTML = state.isVideoOff 
                ? '<i class="fas fa-video-slash"></i><span class="video-call-ctrl-label">กล้อง</span>' 
                : '<i class="fas fa-video"></i><span class="video-call-ctrl-label">กล้อง</span>';
            videoBtn.classList.toggle('active', state.isVideoOff);
        }
    }

    /**
     * Reset video call (start over)
     */
    async resetVideoCall() {
        // Cleanup
        if (window.videoCallManager) {
            window.videoCallManager.cleanup();
        }
        
        // Reset UI
        document.getElementById('vc-end-screen')?.classList.add('hidden');
        document.getElementById('vc-loading')?.classList.remove('hidden');
        
        // Reset icon
        const endIcon = document.querySelector('.video-call-end-icon i');
        if (endIcon) {
            endIcon.className = 'fas fa-check-circle';
            endIcon.parentElement.style.color = '';
        }
        
        // Re-initialize
        await this.initVideoCallPage();
    }

    /**
     * Start video call timer display
     */
    startVideoCallTimer() {
        if (this.videoCallTimerInterval) return;
        
        this.videoCallTimerInterval = setInterval(() => {
            const duration = window.videoCallManager?.callDuration || 0;
            const timerText = document.getElementById('vc-timer-text');
            if (timerText) {
                timerText.textContent = window.videoCallManager?.formatDuration(duration) || '00:00';
            }
        }, 1000);
    }

    /**
     * Stop video call timer display
     */
    stopVideoCallTimer() {
        if (this.videoCallTimerInterval) {
            clearInterval(this.videoCallTimerInterval);
            this.videoCallTimerInterval = null;
        }
    }

    // ==================== Utility Methods ====================

    /**
     * Format number with commas
     */
    formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Format currency
     */
    formatCurrency(amount) {
        return `฿${this.formatNumber(amount)}`;
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Create global instance
window.liffApp = new LiffApp();

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.liffApp.init();
});
