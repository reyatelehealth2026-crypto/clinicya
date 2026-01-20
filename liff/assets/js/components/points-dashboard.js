/**
 * Points Dashboard Component
 * Loyalty Points Dashboard for LIFF Telepharmacy
 * 
 * Requirements: 21.1, 21.2, 21.3, 21.4, 21.5, 21.6, 21.7, 21.8
 * - Display available points balance with animated counter
 * - Show summary card with total earned, used, and expired points
 * - Display tier status with progress bar to next tier
 * - Show pending points with expected confirmation date
 * - Display last 5 transactions with link to full history
 * - Show points expiry warning if points expire within 30 days
 * - Display motivational message with "Start Shopping" CTA when balance is zero
 */

class PointsDashboard {
    constructor(config = {}) {
        this.config = {
            baseUrl: config.baseUrl || window.APP_CONFIG?.BASE_URL || '',
            accountId: config.accountId || window.APP_CONFIG?.ACCOUNT_ID || 1,
            ...config
        };
        this.pointsData = null;
        this.isLoading = false;
    }

    /**
     * Load points data from API
     * Requirements: 21.9, 21.10 - JSON serialization/deserialization
     * @param {string} lineUserId - LINE user ID
     * @returns {Promise<Object>} Points data
     */
    async loadPointsData(lineUserId) {
        if (!lineUserId) {
            console.warn('PointsDashboard: No lineUserId provided');
            return null;
        }

        this.isLoading = true;

        try {
            const url = `${this.config.baseUrl}/api/points-history.php?action=dashboard&line_user_id=${lineUserId}&line_account_id=${this.config.accountId}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                // Deserialize and reconstruct points object (Requirement 21.10)
                this.pointsData = this.deserializePointsData(data);
                return this.pointsData;
            } else {
                console.error('PointsDashboard: API error', data.error);
                return null;
            }
        } catch (error) {
            console.error('PointsDashboard: Failed to load points data', error);
            return null;
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Deserialize points data from API response
     * Requirements: 21.10 - Decode JSON and reconstruct points object
     * @param {Object} data - Raw API response
     * @returns {Object} Reconstructed points object
     */
    deserializePointsData(data) {
        return {
            available_points: parseInt(data.user?.available_points || data.available_points || 0),
            total_earned: parseInt(data.user?.total_points || data.total_earned || 0),
            total_used: parseInt(data.user?.used_points || data.total_used || 0),
            total_expired: parseInt(data.total_expired || 0),
            pending_points: parseInt(data.pending_points || 0),
            pending_confirmation_date: data.pending_confirmation_date || null,
            tier: data.tier?.name || data.tier || 'Silver',
            tier_color: data.tier?.color || data.tier_color || '#9CA3AF',
            tier_icon: data.tier?.icon || data.tier_icon || '',
            tier_code: data.tier?.code || data.tier_code || 'silver',
            tier_points: parseInt(data.tier_points || 0),
            points_to_next_tier: parseInt(data.points_to_next_tier || 2000),
            next_tier_name: data.next_tier_name || 'Gold',
            nearest_expiry_date: data.nearest_expiry_date || null,
            expiring_points: parseInt(data.expiring_points || 0),
            recent_transactions: Array.isArray(data.history) ? data.history.slice(0, 5) : [],
            user_name: data.user?.name || data.user?.display_name || 'สมาชิก'
        };
    }

    /**
     * Serialize points data to JSON for storage
     * Requirements: 21.9 - Encode using JSON format
     * @param {Object} pointsData - Points data object
     * @returns {string} JSON string
     */
    serializePointsData(pointsData) {
        return JSON.stringify({
            available_points: pointsData.available_points,
            total_earned: pointsData.total_earned,
            total_used: pointsData.total_used,
            total_expired: pointsData.total_expired,
            pending_points: pointsData.pending_points,
            pending_confirmation_date: pointsData.pending_confirmation_date,
            tier: pointsData.tier,
            tier_points: pointsData.tier_points,
            points_to_next_tier: pointsData.points_to_next_tier,
            next_tier_name: pointsData.next_tier_name,
            nearest_expiry_date: pointsData.nearest_expiry_date,
            expiring_points: pointsData.expiring_points,
            recent_transactions: pointsData.recent_transactions,
            serialized_at: new Date().toISOString()
        });
    }

    /**
     * Render the complete points dashboard
     * @param {Object} pointsData - Points data (optional, uses cached if not provided)
     * @returns {string} HTML string
     */
    render(pointsData = null) {
        const data = pointsData || this.pointsData;

        if (!data) {
            return this.renderSkeleton();
        }

        // Check for zero balance state (Requirement 21.7)
        if (data.available_points === 0 && data.total_earned === 0) {
            return this.renderZeroBalanceState();
        }

        return `
            <div class="points-dashboard">
                ${this.renderHeader()}
                ${this.renderPointsBalanceCard(data)}
                ${this.renderSummaryCard(data)}
                ${this.renderTierProgress(data)}
                ${this.renderExpiryWarning(data)}
                ${this.renderPendingPoints(data)}
                ${this.renderRecentTransactions(data)}
                ${this.renderActions()}
            </div>
        `;
    }

    /**
     * Render page header with back button
     */
    renderHeader() {
        return `
            <div class="points-dashboard-header">
                <button class="back-btn" onclick="window.router.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="page-title">คะแนนสะสม</h1>
                <button class="header-action-btn" onclick="window.router.navigate('/points-history')">
                    <i class="fas fa-history"></i>
                </button>
            </div>
        `;
    }

    /**
     * Render points balance card with animated counter
     * Requirements: 21.1 - Display available points balance with animated counter
     * @param {Object} data - Points data
     * @returns {string} HTML string
     */
    renderPointsBalanceCard(data) {
        return `
            <div class="points-balance-card-large">
                <div class="points-balance-bg"></div>
                <div class="points-balance-content">
                    <div class="points-balance-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="points-balance-label">คะแนนสะสมของคุณ</div>
                    <div class="points-balance-value" data-target="${data.available_points}" id="points-counter">
                        ${this.formatNumber(data.available_points)}
                    </div>
                    <div class="points-balance-unit">คะแนน</div>
                </div>
            </div>
        `;
    }

    /**
     * Render summary card with total earned, used, and expired points
     * Requirements: 21.2 - Show summary card with total earned, used, and expired points
     * @param {Object} data - Points data
     * @returns {string} HTML string
     */
    renderSummaryCard(data) {
        return `
            <div class="points-summary-card">
                <div class="points-summary-item">
                    <div class="points-summary-icon earned">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="points-summary-info">
                        <span class="points-summary-label">ได้รับทั้งหมด</span>
                        <span class="points-summary-value earned">+${this.formatNumber(data.total_earned)}</span>
                    </div>
                </div>
                <div class="points-summary-divider"></div>
                <div class="points-summary-item">
                    <div class="points-summary-icon used">
                        <i class="fas fa-minus-circle"></i>
                    </div>
                    <div class="points-summary-info">
                        <span class="points-summary-label">ใช้ไปแล้ว</span>
                        <span class="points-summary-value used">-${this.formatNumber(data.total_used)}</span>
                    </div>
                </div>
                <div class="points-summary-divider"></div>
                <div class="points-summary-item">
                    <div class="points-summary-icon expired">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="points-summary-info">
                        <span class="points-summary-label">หมดอายุ</span>
                        <span class="points-summary-value expired">-${this.formatNumber(data.total_expired)}</span>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render tier progress section
     * Requirements: 21.3, 21.4 - Display tier status with progress bar to next tier
     * @param {Object} data - Points data
     * @returns {string} HTML string
     */
    renderTierProgress(data) {
        const tierColor = data.tier_color || '#9CA3AF';
        const tierIcon = data.tier_icon || this.getTierIcon(data.tier);

        // Calculate progress percentage (bounded 0-100)
        const totalForNextTier = data.tier_points + data.points_to_next_tier;
        const progress = totalForNextTier > 0
            ? Math.min(100, Math.max(0, (data.tier_points / totalForNextTier) * 100))
            : 0;

        return `
            <div class="tier-progress-card">
                <div class="tier-progress-header">
                    <div class="tier-badge" style="background: ${tierColor}; color: #fff; border: 1px solid rgba(255,255,255,0.2);">
                        ${tierIcon}
                        <span style="margin-left: 4px;">${data.tier}</span>
                    </div>
                    <div class="tier-next">
                        <span class="tier-next-label">ระดับถัดไป</span>
                        <span class="tier-next-name">${data.next_tier_name || 'Gold'}</span>
                    </div>
                </div>
                <div class="tier-progress-bar-container">
                    <div class="tier-progress-bar">
                        <div class="tier-progress-fill" style="width: ${progress}%; background-color: ${tierColor};"></div>
                    </div>
                    <div class="tier-progress-labels">
                        <span>${this.formatNumber(data.tier_points)} pt</span>
                        <span>${this.formatNumber(data.tier_points + data.points_to_next_tier)} pt</span>
                    </div>
                </div>
                <div class="tier-progress-info">
                    <i class="fas fa-info-circle"></i>
                    <span>อีก <strong>${this.formatNumber(data.points_to_next_tier)}</strong> คะแนน เพื่อเลื่อนเป็น ${data.next_tier_name}</span>
                </div>
            </div>
        `;
    }

    /**
     * Render points expiry warning
     * Requirements: 21.8 - Show warning if points expire within 30 days
     * @param {Object} data - Points data
     * @returns {string} HTML string
     */
    renderExpiryWarning(data) {
        if (!data.expiring_points || data.expiring_points === 0) {
            return '';
        }

        const expiryDate = data.nearest_expiry_date
            ? this.formatDate(data.nearest_expiry_date)
            : 'เร็วๆ นี้';

        return `
            <div class="points-expiry-warning">
                <div class="expiry-warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="expiry-warning-content">
                    <span class="expiry-warning-title">คะแนนใกล้หมดอายุ!</span>
                    <span class="expiry-warning-text">
                        <strong>${this.formatNumber(data.expiring_points)}</strong> คะแนนจะหมดอายุ ${expiryDate}
                    </span>
                </div>
                <button class="expiry-warning-action" onclick="window.router.navigate('/redeem')">
                    ใช้เลย
                </button>
            </div>
        `;
    }

    /**
     * Render pending points section
     * Requirements: 21.5 - Show pending points with expected confirmation date
     * @param {Object} data - Points data
     * @returns {string} HTML string
     */
    renderPendingPoints(data) {
        if (!data.pending_points || data.pending_points === 0) {
            return '';
        }

        const confirmDate = data.pending_confirmation_date
            ? this.formatDate(data.pending_confirmation_date)
            : 'รอยืนยัน';

        return `
            <div class="pending-points-card">
                <div class="pending-points-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="pending-points-content">
                    <span class="pending-points-label">คะแนนรอยืนยัน</span>
                    <span class="pending-points-value">+${this.formatNumber(data.pending_points)}</span>
                </div>
                <div class="pending-points-date">
                    <i class="far fa-calendar"></i>
                    <span>ยืนยัน ${confirmDate}</span>
                </div>
            </div>
        `;
    }

    /**
     * Render recent transactions section
     * Requirements: 21.6 - Display last 5 transactions with link to full history
     * @param {Object} data - Points data
     * @returns {string} HTML string
     */
    renderRecentTransactions(data) {
        const transactions = data.recent_transactions || [];

        // Limit to 5 transactions (Requirement 21.6)
        const recentFive = transactions.slice(0, 5);

        if (recentFive.length === 0) {
            return `
                <div class="recent-transactions-section">
                    <div class="section-header">
                        <h3>รายการล่าสุด</h3>
                    </div>
                    <div class="empty-transactions">
                        <i class="fas fa-receipt"></i>
                        <p>ยังไม่มีรายการ</p>
                    </div>
                </div>
            `;
        }

        const transactionsHtml = recentFive.map(tx => this.renderTransaction(tx)).join('');

        return `
            <div class="recent-transactions-section">
                <div class="section-header">
                    <h3>รายการล่าสุด</h3>
                    <a href="#/points-history" class="view-all-link" onclick="window.router.navigate('/points-history'); return false;">
                        ดูทั้งหมด <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <div class="transactions-list">
                    ${transactionsHtml}
                </div>
            </div>
        `;
    }

    /**
     * Render a single transaction item
     * @param {Object} tx - Transaction object
     * @returns {string} HTML string
     */
    renderTransaction(tx) {
        const typeConfig = {
            'earned': { icon: 'fa-plus-circle', color: 'earned', prefix: '+' },
            'earn': { icon: 'fa-plus-circle', color: 'earned', prefix: '+' },
            'redeemed': { icon: 'fa-minus-circle', color: 'used', prefix: '-' },
            'redeem': { icon: 'fa-minus-circle', color: 'used', prefix: '-' },
            'expired': { icon: 'fa-clock', color: 'expired', prefix: '-' },
            'expire': { icon: 'fa-clock', color: 'expired', prefix: '-' }
        };

        const type = (tx.type || 'earned').toLowerCase();
        const config = typeConfig[type] || typeConfig['earned'];
        const points = Math.abs(parseInt(tx.points) || 0);
        const isPositive = type === 'earned' || type === 'earn' || parseInt(tx.points) > 0;

        return `
            <div class="transaction-item">
                <div class="transaction-icon ${config.color}">
                    <i class="fas ${config.icon}"></i>
                </div>
                <div class="transaction-details">
                    <div class="transaction-description">${tx.description || 'รายการคะแนน'}</div>
                    <div class="transaction-meta">
                        ${tx.reference_type ? `<span class="transaction-ref">${tx.reference_type}</span>` : ''}
                        <span class="transaction-date">${this.formatDateTime(tx.created_at)}</span>
                    </div>
                </div>
                <div class="transaction-points ${config.color}">
                    ${isPositive ? '+' : '-'}${this.formatNumber(points)}
                </div>
            </div>
        `;
    }

    /**
     * Render action buttons
     */
    renderActions() {
        return `
            <div class="points-actions">
                <button class="btn btn-primary btn-block" onclick="window.router.navigate('/redeem')">
                    <i class="fas fa-gift"></i> แลกของรางวัล
                </button>
                <button class="btn btn-outline btn-block" onclick="window.router.navigate('/shop')">
                    <i class="fas fa-shopping-cart"></i> ช้อปเพื่อสะสมคะแนน
                </button>
            </div>
        `;
    }

    /**
     * Render zero balance state
     * Requirements: 21.7 - Display motivational message with "Start Shopping" CTA
     * @returns {string} HTML string
     */
    renderZeroBalanceState() {
        return `
            <div class="points-dashboard">
                ${this.renderHeader()}
                <div class="zero-balance-state">
                    <div class="zero-balance-illustration">
                        <i class="fas fa-coins"></i>
                    </div>
                    <h2 class="zero-balance-title">เริ่มสะสมคะแนนกันเลย!</h2>
                    <p class="zero-balance-message">
                        ช้อปสินค้าเพื่อรับคะแนนสะสม<br>
                        และแลกของรางวัลสุดพิเศษ
                    </p>
                    <div class="zero-balance-benefits">
                        <div class="benefit-item">
                            <i class="fas fa-shopping-bag"></i>
                            <span>ทุกการซื้อได้คะแนน</span>
                        </div>
                        <div class="benefit-item">
                            <i class="fas fa-gift"></i>
                            <span>แลกของรางวัลมากมาย</span>
                        </div>
                        <div class="benefit-item">
                            <i class="fas fa-crown"></i>
                            <span>เลื่อนระดับสมาชิก</span>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" onclick="window.router.navigate('/shop')">
                        <i class="fas fa-store"></i> เริ่มช้อปเลย
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Render skeleton loading state
     * @returns {string} HTML string
     */
    renderSkeleton() {
        return `
            <div class="points-dashboard">
                ${this.renderHeader()}
                <div class="points-balance-card-large skeleton-card">
                    <div class="skeleton" style="width: 60px; height: 60px; border-radius: 50%; margin: 0 auto 16px;"></div>
                    <div class="skeleton skeleton-text" style="width: 120px; margin: 0 auto 8px;"></div>
                    <div class="skeleton" style="width: 150px; height: 40px; margin: 0 auto 8px;"></div>
                    <div class="skeleton skeleton-text" style="width: 60px; margin: 0 auto;"></div>
                </div>
                <div class="points-summary-card">
                    <div class="skeleton" style="height: 60px;"></div>
                </div>
                <div class="tier-progress-card">
                    <div class="skeleton" style="height: 100px;"></div>
                </div>
                <div class="recent-transactions-section">
                    <div class="skeleton skeleton-text" style="width: 100px; margin-bottom: 16px;"></div>
                    ${[1, 2, 3].map(() => `
                        <div class="transaction-item">
                            <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%;"></div>
                            <div style="flex: 1; margin-left: 12px;">
                                <div class="skeleton skeleton-text" style="width: 80%;"></div>
                                <div class="skeleton skeleton-text short" style="width: 50%;"></div>
                            </div>
                            <div class="skeleton" style="width: 60px; height: 20px;"></div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
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
     * Format number with thousand separators
     */
    formatNumber(num) {
        return (parseInt(num) || 0).toLocaleString('th-TH');
    }

    /**
     * Format date for display
     */
    formatDate(dateStr) {
        if (!dateStr) return '-';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', {
                day: 'numeric',
                month: 'short',
                year: '2-digit'
            });
        } catch (e) {
            return dateStr;
        }
    }

    /**
     * Format date and time for display
     */
    formatDateTime(dateStr) {
        if (!dateStr) return '-';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return dateStr;
        }
    }

    /**
     * Animate points counter
     * Requirements: 21.1 - Animated counter
     */
    animateCounter() {
        const counter = document.getElementById('points-counter');
        if (!counter) return;

        const target = parseInt(counter.dataset.target) || 0;
        const duration = 1000; // 1 second
        const startTime = performance.now();
        const startValue = 0;

        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);

            // Easing function (ease-out)
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const currentValue = Math.floor(startValue + (target - startValue) * easeOut);

            counter.textContent = this.formatNumber(currentValue);

            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };

        requestAnimationFrame(animate);
    }
}

// Export for global use
window.PointsDashboard = PointsDashboard;
