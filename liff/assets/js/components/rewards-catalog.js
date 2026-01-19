/**
 * Rewards Catalog Component
 * Rewards Catalog & Redemption for LIFF Telepharmacy
 * 
 * Requirements: 23.1, 23.2, 23.3, 23.4, 23.5, 23.6, 23.7, 23.8, 23.9, 23.10, 23.11, 23.12, 23.13
 * - Display available rewards in grid layout with images
 * - Show reward image, name, points required, stock availability
 * - Display remaining quantity with "เหลือ X ชิ้น" label for limited stock
 * - Display "หมดแล้ว" badge and disable redemption for out-of-stock
 * - Gray out unredeemable rewards and show points needed for insufficient points
 * - Display reward detail modal with full description and terms
 * - Deduct points and generate unique redemption code on redemption
 * - Display success modal with redemption code and confetti animation
 * - Send LINE notification with redemption details
 * - Show redeemed rewards with status (Pending/Approved/Delivered/Cancelled)
 * - Display expiry countdown and send reminder 3 days before
 * - JSON serialization/deserialization for redemption data
 */

class RewardsCatalog {
    constructor(config = {}) {
        this.config = {
            baseUrl: config.baseUrl || window.APP_CONFIG?.BASE_URL || '',
            accountId: config.accountId || window.APP_CONFIG?.ACCOUNT_ID || 1,
            ...config
        };
        this.rewards = [];
        this.myRedemptions = [];
        this.userPoints = 0;
        this.isLoading = false;
        this.currentTab = 'rewards';
        this.selectedReward = null;
    }

    /**
     * Load rewards data from API
     * Requirements: 23.12, 23.13 - JSON serialization/deserialization
     * @param {string} lineUserId - LINE user ID
     * @returns {Promise<Object>} Rewards data
     */
    async loadRewardsData(lineUserId) {
        if (!lineUserId) {
            console.warn('RewardsCatalog: No lineUserId provided');
            return null;
        }

        this.isLoading = true;

        try {
            // Use rewards.php API for consistency with backend logic
            const url = `${this.config.baseUrl}/api/rewards.php?action=list&line_account_id=${this.config.accountId}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                // Deserialize and reconstruct rewards data (Requirement 23.13)
                this.rewards = this.deserializeRewards(data.rewards || []);

                // Load user redemptions separately
                await this.loadUserRedemptions(lineUserId);

                // Load user points from member info
                await this.loadUserPoints(lineUserId);

                return {
                    rewards: this.rewards,
                    myRedemptions: this.myRedemptions,
                    userPoints: this.userPoints
                };
            } else {
                console.error('RewardsCatalog: API error', data.message || data.error);
                return null;
            }
        } catch (error) {
            console.error('RewardsCatalog: Failed to load rewards data', error);
            return null;
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Load user redemptions from API
     * @param {string} lineUserId - LINE user ID
     */
    async loadUserRedemptions(lineUserId) {
        try {
            const url = `${this.config.baseUrl}/api/rewards.php?action=my_redemptions&line_user_id=${lineUserId}&line_account_id=${this.config.accountId}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                this.myRedemptions = this.deserializeRedemptions(data.redemptions || []);
            }
        } catch (error) {
            console.error('RewardsCatalog: Failed to load redemptions', error);
            this.myRedemptions = [];
        }
    }

    /**
     * Load user points from member info
     * @param {string} lineUserId - LINE user ID
     */
    async loadUserPoints(lineUserId) {
        try {
            const url = `${this.config.baseUrl}/api/points-history.php?action=dashboard&line_user_id=${lineUserId}&line_account_id=${this.config.accountId}`;
            console.log('RewardsCatalog: Loading user points from:', url);
            const response = await fetch(url);
            const data = await response.json();
            console.log('RewardsCatalog: User points response:', data);

            if (data.success && data.user) {
                this.userPoints = parseInt(data.user.available_points) || 0;
                console.log('RewardsCatalog: User points loaded:', this.userPoints);
            } else {
                console.warn('RewardsCatalog: Failed to load user points:', data.message);
                this.userPoints = 0;
            }
        } catch (error) {
            console.error('RewardsCatalog: Failed to load user points', error);
            this.userPoints = 0;
        }
    }

    /**
     * Deserialize rewards from API response
     * Requirements: 23.13 - Decode JSON and reconstruct reward objects
     * @param {Array} rewards - Raw rewards array
     * @returns {Array} Reconstructed rewards array
     */
    deserializeRewards(rewards) {
        return rewards.map(reward => ({
            id: parseInt(reward.id),
            name: reward.name || '',
            description: reward.description || '',
            image_url: reward.image_url || null,
            points_required: parseInt(reward.points_required) || 0,
            reward_type: reward.reward_type || 'gift',
            reward_value: reward.reward_value || null,
            stock_quantity: parseInt(reward.stock) ?? -1,
            is_active: reward.is_active !== false && reward.is_active !== 0,
            valid_from: reward.valid_from || null,
            valid_until: reward.valid_until || null,
            terms: reward.terms || null,
            created_at: reward.created_at || null
        }));
    }

    /**
     * Deserialize redemptions from API response
     * Requirements: 23.13 - Decode JSON and reconstruct redemption objects
     * @param {Array} redemptions - Raw redemptions array
     * @returns {Array} Reconstructed redemptions array
     */
    deserializeRedemptions(redemptions) {
        return redemptions.map(r => ({
            id: parseInt(r.id),
            reward_id: parseInt(r.reward_id),
            reward_name: r.reward_name || r.name || '',
            image_url: r.image_url || r.reward_image || r.image || null,
            points_used: parseInt(r.points_used) || parseInt(r.points) || 0,
            redemption_code: r.redemption_code || r.code || '',
            status: r.status || 'pending',
            notes: r.notes || null,
            approved_at: r.approved_at || null,
            delivered_at: r.delivered_at || null,
            expires_at: r.expires_at || null,
            created_at: r.created_at || null,
            formatted_date: r.formatted_date || this.formatDate(r.created_at)
        }));
    }

    /**
     * Serialize redemption data to JSON for storage
     * Requirements: 23.12 - Encode using JSON format
     * @param {Object} redemption - Redemption data object
     * @returns {string} JSON string
     */
    serializeRedemption(redemption) {
        return JSON.stringify({
            id: redemption.id,
            reward_id: redemption.reward_id,
            reward_name: redemption.reward_name,
            image_url: redemption.image_url,
            points_used: redemption.points_used,
            redemption_code: redemption.redemption_code,
            status: redemption.status,
            notes: redemption.notes,
            approved_at: redemption.approved_at,
            delivered_at: redemption.delivered_at,
            expires_at: redemption.expires_at,
            created_at: redemption.created_at,
            serialized_at: new Date().toISOString()
        });
    }

    /**
     * Render the complete rewards catalog page
     * @returns {string} HTML string
     */
    render() {
        if (this.isLoading) {
            return this.renderSkeleton();
        }

        return `
            <div class="rewards-catalog-page">
                ${this.renderHeader()}
                ${this.renderPointsSummary()}
                ${this.renderTabs()}
                <div class="rewards-content">
                    ${this.currentTab === 'rewards' 
                        ? this.renderRewardsGrid() 
                        : this.renderMyRewards()}
                </div>
            </div>
        `;
    }

    /**
     * Render page header
     */
    renderHeader() {
        return `
            <div class="rewards-header">
                <button class="back-btn" onclick="window.router?.back() || window.history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="page-title">แลกของรางวัล</h1>
                <button class="header-action-btn" onclick="window.router?.navigate('/points-history')">
                    <i class="fas fa-history"></i>
                </button>
            </div>
        `;
    }

    /**
     * Render points summary card
     */
    renderPointsSummary() {
        return `
            <div class="rewards-points-card">
                <div class="rewards-points-bg"></div>
                <div class="rewards-points-content">
                    <div class="rewards-points-info">
                        <span class="rewards-points-label">แต้มที่ใช้ได้</span>
                        <span class="rewards-points-value">${this.formatNumber(this.userPoints)}</span>
                    </div>
                    <div class="rewards-points-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render tab navigation
     */
    renderTabs() {
        return `
            <div class="rewards-tabs">
                <button class="rewards-tab ${this.currentTab === 'rewards' ? 'active' : ''}" 
                        onclick="window.rewardsCatalog?.switchTab('rewards')">
                    <i class="fas fa-gift"></i>
                    <span>ของรางวัล</span>
                </button>
                <button class="rewards-tab ${this.currentTab === 'my-rewards' ? 'active' : ''}" 
                        onclick="window.rewardsCatalog?.switchTab('my-rewards')">
                    <i class="fas fa-ticket-alt"></i>
                    <span>รางวัลของฉัน</span>
                </button>
            </div>
        `;
    }

    /**
     * Render rewards grid
     * Requirements: 23.1, 23.2 - Display rewards in grid layout with images
     * @returns {string} HTML string
     */
    renderRewardsGrid() {
        if (this.rewards.length === 0) {
            return this.renderEmptyRewards();
        }

        const rewardsHtml = this.rewards.map(reward => this.renderRewardCard(reward)).join('');

        return `
            <div class="rewards-grid">
                ${rewardsHtml}
            </div>
        `;
    }

    /**
     * Render a single reward card
     * Requirements: 23.2, 23.3, 23.4, 23.5 - Show reward details and availability
     * @param {Object} reward - Reward object
     * @returns {string} HTML string
     */
    renderRewardCard(reward) {
        const isOutOfStock = reward.stock_quantity === 0;
        const isInsufficientPoints = this.userPoints < reward.points_required;
        const isDisabled = isOutOfStock || isInsufficientPoints;
        const hasLimitedStock = reward.stock_quantity > 0 && reward.stock_quantity <= 10;

        // Determine card state classes
        let cardClasses = 'reward-card';
        if (isDisabled) cardClasses += ' disabled';
        if (isOutOfStock) cardClasses += ' out-of-stock';
        if (isInsufficientPoints && !isOutOfStock) cardClasses += ' insufficient-points';

        return `
            <div class="${cardClasses}"
                 data-reward-id="${reward.id}"
                 onclick="window.rewardsCatalog?.showRewardDetail(${reward.id})"
                 style="cursor: pointer;">
                <div class="reward-card-image">
                    ${reward.image_url 
                        ? `<img src="${this.escapeHtml(reward.image_url)}" alt="${this.escapeHtml(reward.name)}" loading="lazy">`
                        : `<div class="reward-card-placeholder">
                            <i class="fas ${this.getRewardIcon(reward.reward_type)}"></i>
                           </div>`
                    }
                    ${isOutOfStock 
                        ? `<div class="reward-badge out-of-stock-badge">หมดแล้ว</div>` 
                        : ''}
                    ${hasLimitedStock && !isOutOfStock
                        ? `<div class="reward-badge limited-stock-badge">เหลือ ${reward.stock_quantity} ชิ้น</div>`
                        : ''}
                    <div class="reward-points-badge">
                        <i class="fas fa-star"></i>
                        ${this.formatNumber(reward.points_required)}
                    </div>
                </div>
                <div class="reward-card-info">
                    <h3 class="reward-card-name">${this.escapeHtml(reward.name)}</h3>
                    <p class="reward-card-desc">${this.escapeHtml(reward.description || '')}</p>
                    ${isInsufficientPoints && !isOutOfStock 
                        ? `<div class="reward-points-needed">
                            ต้องการอีก ${this.formatNumber(reward.points_required - this.userPoints)} คะแนน
                           </div>`
                        : ''}
                </div>
            </div>
        `;
    }

    /**
     * Render reward detail modal
     * Requirements: 23.6 - Display full description and terms on tap
     * @param {Object} reward - Reward object
     * @returns {string} HTML string
     */
    renderRewardDetailModal(reward) {
        const canRedeem = this.userPoints >= reward.points_required && 
                         (reward.stock_quantity < 0 || reward.stock_quantity > 0);
        
        let stockText = '';
        if (reward.stock_quantity < 0) {
            stockText = '<i class="fas fa-infinity"></i> ไม่จำกัดจำนวน';
        } else if (reward.stock_quantity === 0) {
            stockText = '<i class="fas fa-times-circle"></i> หมดแล้ว';
        } else {
            stockText = `<i class="fas fa-box"></i> เหลือ ${reward.stock_quantity} ชิ้น`;
        }

        // Calculate expiry countdown if applicable (Requirement 23.11)
        let expiryHtml = '';
        if (reward.valid_until) {
            const expiryDate = new Date(reward.valid_until);
            const now = new Date();
            const daysLeft = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
            
            if (daysLeft > 0 && daysLeft <= 7) {
                expiryHtml = `
                    <div class="reward-expiry-warning">
                        <i class="fas fa-clock"></i>
                        เหลืออีก ${daysLeft} วัน
                    </div>
                `;
            }
        }

        return `
            <div class="reward-detail-modal" id="rewardDetailModal">
                <div class="reward-detail-overlay" onclick="window.rewardsCatalog?.closeRewardDetail()"></div>
                <div class="reward-detail-content">
                    <button class="reward-detail-close" onclick="window.rewardsCatalog?.closeRewardDetail()">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="reward-detail-image">
                        ${reward.image_url 
                            ? `<img src="${this.escapeHtml(reward.image_url)}" alt="${this.escapeHtml(reward.name)}">`
                            : `<div class="reward-detail-placeholder">
                                <i class="fas ${this.getRewardIcon(reward.reward_type)}"></i>
                               </div>`
                        }
                    </div>
                    <div class="reward-detail-body">
                        <h2 class="reward-detail-name">${this.escapeHtml(reward.name)}</h2>
                        <p class="reward-detail-desc">${this.escapeHtml(reward.description || 'ไม่มีรายละเอียด')}</p>
                        
                        <div class="reward-detail-points">
                            <span class="reward-detail-points-label">แต้มที่ใช้</span>
                            <span class="reward-detail-points-value">
                                <i class="fas fa-star"></i>
                                ${this.formatNumber(reward.points_required)} คะแนน
                            </span>
                        </div>
                        
                        <div class="reward-detail-stock">${stockText}</div>
                        
                        ${expiryHtml}
                        
                        ${reward.terms ? `
                            <div class="reward-detail-terms">
                                <h4>เงื่อนไขการใช้</h4>
                                <p>${this.escapeHtml(reward.terms)}</p>
                            </div>
                        ` : ''}
                        
                        <div class="reward-detail-actions">
                            <button class="btn btn-outline" onclick="window.rewardsCatalog?.closeRewardDetail()">
                                ยกเลิก
                            </button>
                            <button class="btn btn-primary ${!canRedeem ? 'disabled' : ''}" 
                                    onclick="${canRedeem ? `window.rewardsCatalog?.confirmRedemption(${reward.id})` : ''}"
                                    ${!canRedeem ? 'disabled' : ''}>
                                ${!canRedeem 
                                    ? '<i class="fas fa-lock"></i> แต้มไม่พอ'
                                    : '<i class="fas fa-exchange-alt"></i> แลกเลย'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render redemption success modal
     * Requirements: 23.8 - Display success with redemption code and confetti animation
     * @param {Object} redemption - Redemption result
     * @returns {string} HTML string
     */
    renderSuccessModal(redemption) {
        return `
            <div class="redemption-success-modal" id="redemptionSuccessModal">
                <div class="redemption-success-overlay"></div>
                <div class="redemption-success-content">
                    <div class="confetti-container" id="confettiContainer"></div>
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="success-title">แลกสำเร็จ!</h2>
                    <p class="success-subtitle">รหัสรับรางวัลของคุณ</p>
                    <div class="redemption-code-box">
                        <code class="redemption-code">${this.escapeHtml(redemption.redemption_code)}</code>
                        <button class="copy-code-btn" onclick="window.rewardsCatalog?.copyRedemptionCode('${redemption.redemption_code}')">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <p class="success-note">กรุณาแสดงรหัสนี้เพื่อรับรางวัล</p>
                    <button class="btn btn-primary btn-block" onclick="window.rewardsCatalog?.closeSuccessModal()">
                        เข้าใจแล้ว
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Render My Rewards tab
     * Requirements: 23.10 - Show redeemed rewards with status
     * @returns {string} HTML string
     */
    renderMyRewards() {
        if (this.myRedemptions.length === 0) {
            return this.renderEmptyMyRewards();
        }

        const redemptionsHtml = this.myRedemptions.map(r => this.renderRedemptionCard(r)).join('');

        return `
            <div class="my-rewards-list">
                ${redemptionsHtml}
            </div>
        `;
    }

    /**
     * Render a single redemption card
     * Requirements: 23.10, 23.11 - Show status and expiry countdown
     * @param {Object} redemption - Redemption object
     * @returns {string} HTML string
     */
    renderRedemptionCard(redemption) {
        const statusConfig = this.getStatusConfig(redemption.status);
        
        // Calculate expiry countdown (Requirement 23.11)
        let expiryHtml = '';
        if (redemption.expires_at && redemption.status === 'approved') {
            const expiryDate = new Date(redemption.expires_at);
            const now = new Date();
            const daysLeft = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
            
            if (daysLeft > 0 && daysLeft <= 3) {
                expiryHtml = `
                    <div class="redemption-expiry-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        หมดอายุใน ${daysLeft} วัน
                    </div>
                `;
            } else if (daysLeft <= 0) {
                expiryHtml = `
                    <div class="redemption-expired">
                        <i class="fas fa-times-circle"></i>
                        หมดอายุแล้ว
                    </div>
                `;
            }
        }

        return `
            <div class="redemption-card">
                <div class="redemption-card-image">
                    ${redemption.image_url 
                        ? `<img src="${this.escapeHtml(redemption.image_url)}" alt="${this.escapeHtml(redemption.reward_name)}">`
                        : `<div class="redemption-card-placeholder">
                            <i class="fas fa-gift"></i>
                           </div>`
                    }
                </div>
                <div class="redemption-card-info">
                    <h4 class="redemption-card-name">${this.escapeHtml(redemption.reward_name)}</h4>
                    <p class="redemption-card-date">${redemption.formatted_date || ''}</p>
                    <div class="redemption-card-footer">
                        <span class="redemption-status ${statusConfig.class}">${statusConfig.label}</span>
                        <code class="redemption-card-code">${this.escapeHtml(redemption.redemption_code)}</code>
                    </div>
                    ${expiryHtml}
                </div>
            </div>
        `;
    }

    /**
     * Render empty rewards state
     */
    renderEmptyRewards() {
        return `
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-gift"></i>
                </div>
                <h3 class="empty-state-title">ยังไม่มีของรางวัล</h3>
                <p class="empty-state-text">รอติดตามของรางวัลใหม่ๆ เร็วๆ นี้</p>
            </div>
        `;
    }

    /**
     * Render empty my rewards state
     */
    renderEmptyMyRewards() {
        return `
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <h3 class="empty-state-title">ยังไม่มีรางวัลที่แลก</h3>
                <p class="empty-state-text">แลกของรางวัลเพื่อรับสิทธิพิเศษ</p>
                <button class="btn btn-primary" onclick="window.rewardsCatalog?.switchTab('rewards')">
                    <i class="fas fa-gift"></i> ไปแลกรางวัล
                </button>
            </div>
        `;
    }

    /**
     * Render skeleton loading state
     */
    renderSkeleton() {
        return `
            <div class="rewards-catalog-page">
                ${this.renderHeader()}
                <div class="rewards-points-card skeleton-card">
                    <div class="skeleton" style="height: 80px;"></div>
                </div>
                <div class="rewards-tabs">
                    <div class="skeleton" style="height: 44px; width: 100%;"></div>
                </div>
                <div class="rewards-grid">
                    ${[1, 2, 3, 4].map(() => `
                        <div class="reward-card skeleton-card">
                            <div class="skeleton" style="height: 120px;"></div>
                            <div style="padding: 12px;">
                                <div class="skeleton skeleton-text" style="width: 80%;"></div>
                                <div class="skeleton skeleton-text short" style="width: 60%;"></div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Switch between tabs
     * @param {string} tab - Tab name ('rewards' or 'my-rewards')
     */
    switchTab(tab) {
        this.currentTab = tab;
        this.updateView();
    }

    /**
     * Show reward detail modal
     * Requirements: 23.6 - Display reward detail modal
     * @param {number} rewardId - Reward ID
     */
    showRewardDetail(rewardId) {
        const reward = this.rewards.find(r => r.id === rewardId);
        if (!reward) return;

        this.selectedReward = reward;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('rewardDetailModal');
        if (existingModal) existingModal.remove();

        // Add modal to DOM
        document.body.insertAdjacentHTML('beforeend', this.renderRewardDetailModal(reward));
        
        // Animate in
        setTimeout(() => {
            const modal = document.getElementById('rewardDetailModal');
            if (modal) modal.classList.add('active');
        }, 10);
    }

    /**
     * Close reward detail modal
     */
    closeRewardDetail() {
        const modal = document.getElementById('rewardDetailModal');
        if (modal) {
            modal.classList.remove('active');
            setTimeout(() => modal.remove(), 300);
        }
        this.selectedReward = null;
    }

    /**
     * Confirm and process redemption
     * Requirements: 23.7 - Deduct points and generate unique redemption code
     * @param {number} rewardId - Reward ID
     */
    async confirmRedemption(rewardId) {
        const reward = this.rewards.find(r => r.id === rewardId);
        if (!reward) return;

        // Check if user has enough points
        if (this.userPoints < reward.points_required) {
            this.showToast('แต้มไม่เพียงพอ', 'error');
            return;
        }

        // Show loading state
        const btn = document.querySelector('.reward-detail-actions .btn-primary');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังแลก...';
        }

        try {
            // Use rewards.php API for redemption
            const formData = new FormData();
            formData.append('action', 'redeem');
            formData.append('line_user_id', window.APP_CONFIG?.LINE_USER_ID || '');
            formData.append('line_account_id', this.config.accountId);
            formData.append('reward_id', rewardId);

            const response = await fetch(`${this.config.baseUrl}/api/rewards.php`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                // Close detail modal
                this.closeRewardDetail();

                // Update user points from response (Requirement 23.7 - deduct points)
                if (data.new_balance !== undefined) {
                    this.userPoints = parseInt(data.new_balance) || 0;
                } else {
                    this.userPoints -= reward.points_required;
                }

                // Update reward stock
                if (reward.stock_quantity > 0) {
                    reward.stock_quantity--;
                }

                // Show success modal (Requirement 23.8)
                this.showSuccessModal({
                    redemption_code: data.redemption_code,
                    reward_name: data.reward?.name || reward.name
                });

                // Send LINE notification (Requirement 23.9)
                this.sendLineNotification(data.redemption_code, reward);

                // Refresh data
                await this.refreshData();
            } else {
                this.showToast(data.message || data.error || 'ไม่สามารถแลกรางวัลได้', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-exchange-alt"></i> แลกเลย';
                }
            }
        } catch (error) {
            console.error('RewardsCatalog: Redemption failed', error);
            this.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-exchange-alt"></i> แลกเลย';
            }
        }
    }

    /**
     * Show success modal with confetti
     * Requirements: 23.8 - Display success with confetti animation
     * @param {Object} redemption - Redemption result
     */
    showSuccessModal(redemption) {
        // Remove existing modal if any
        const existingModal = document.getElementById('redemptionSuccessModal');
        if (existingModal) existingModal.remove();

        // Add modal to DOM
        document.body.insertAdjacentHTML('beforeend', this.renderSuccessModal(redemption));

        // Animate in
        setTimeout(() => {
            const modal = document.getElementById('redemptionSuccessModal');
            if (modal) modal.classList.add('active');
            
            // Trigger confetti animation
            this.createConfetti();
        }, 10);
    }

    /**
     * Close success modal
     */
    closeSuccessModal() {
        const modal = document.getElementById('redemptionSuccessModal');
        if (modal) {
            modal.classList.remove('active');
            setTimeout(() => modal.remove(), 300);
        }
        
        // Update view
        this.updateView();
    }

    /**
     * Create confetti animation
     * Requirements: 23.8 - Confetti animation
     */
    createConfetti() {
        const container = document.getElementById('confettiContainer');
        if (!container) return;

        const colors = ['#667eea', '#764ba2', '#f59e0b', '#10b981', '#ef4444', '#3b82f6'];
        
        for (let i = 0; i < 50; i++) {
            const confetti = document.createElement('div');
            confetti.className = 'confetti-piece';
            confetti.style.left = Math.random() * 100 + '%';
            confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            confetti.style.animationDelay = Math.random() * 2 + 's';
            confetti.style.animationDuration = (2 + Math.random() * 2) + 's';
            container.appendChild(confetti);

            // Remove after animation
            setTimeout(() => confetti.remove(), 4000);
        }
    }

    /**
     * Send LINE notification for redemption
     * Requirements: 23.9 - Send LINE notification with redemption details
     * @param {string} code - Redemption code
     * @param {Object} reward - Reward object
     */
    async sendLineNotification(code, reward) {
        try {
            // Use LIFF message bridge if available
            if (window.LiffMessageBridge) {
                const bridge = new window.LiffMessageBridge(this.config);
                await bridge.sendActionMessage('points_redeemed', {
                    redemption_code: code,
                    reward_name: reward.name,
                    points_used: reward.points_required
                });
            } else if (typeof liff !== 'undefined' && liff.isInClient()) {
                // Fallback to direct LIFF message
                await liff.sendMessages([{
                    type: 'text',
                    text: `แลกแต้มสำเร็จ ${reward.points_required} แต้ม\nรหัส: ${code}`
                }]);
            }
        } catch (error) {
            console.warn('RewardsCatalog: Failed to send LINE notification', error);
            // Non-critical error, don't show to user
        }
    }

    /**
     * Copy redemption code to clipboard
     * @param {string} code - Redemption code
     */
    async copyRedemptionCode(code) {
        try {
            await navigator.clipboard.writeText(code);
            this.showToast('คัดลอกรหัสแล้ว', 'success');
        } catch (error) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = code;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showToast('คัดลอกรหัสแล้ว', 'success');
        }
    }

    /**
     * Refresh rewards data
     */
    async refreshData() {
        const lineUserId = window.APP_CONFIG?.LINE_USER_ID;
        if (lineUserId) {
            await this.loadRewardsData(lineUserId);
        }
    }

    /**
     * Update the view
     */
    updateView() {
        const container = document.querySelector('.rewards-content');
        if (container) {
            container.innerHTML = this.currentTab === 'rewards' 
                ? this.renderRewardsGrid() 
                : this.renderMyRewards();
        }

        // Update tab buttons
        document.querySelectorAll('.rewards-tab').forEach(tab => {
            const tabName = tab.textContent.includes('ของรางวัล') ? 'rewards' : 'my-rewards';
            tab.classList.toggle('active', tabName === this.currentTab);
        });

        // Update points display
        const pointsValue = document.querySelector('.rewards-points-value');
        if (pointsValue) {
            pointsValue.textContent = this.formatNumber(this.userPoints);
        }
    }

    /**
     * Show toast notification
     * @param {string} message - Toast message
     * @param {string} type - Toast type ('success', 'error', 'info')
     */
    showToast(message, type = 'info') {
        // Remove existing toast
        const existingToast = document.querySelector('.rewards-toast');
        if (existingToast) existingToast.remove();

        const toast = document.createElement('div');
        toast.className = `rewards-toast ${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        `;
        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('active'), 10);

        // Remove after delay
        setTimeout(() => {
            toast.classList.remove('active');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Get status configuration
     * Requirements: 23.10 - Status display (Pending/Approved/Delivered/Cancelled)
     * @param {string} status - Status string
     * @returns {Object} Status configuration
     */
    getStatusConfig(status) {
        const configs = {
            'pending': { label: 'รอดำเนินการ', class: 'status-pending' },
            'approved': { label: 'อนุมัติแล้ว', class: 'status-approved' },
            'delivered': { label: 'รับแล้ว', class: 'status-delivered' },
            'cancelled': { label: 'ยกเลิก', class: 'status-cancelled' }
        };
        return configs[status] || { label: status, class: 'status-default' };
    }

    /**
     * Get reward icon based on type
     * @param {string} type - Reward type
     * @returns {string} Font Awesome icon class
     */
    getRewardIcon(type) {
        const icons = {
            'discount': 'fa-percent',
            'discount_coupon': 'fa-percent',
            'shipping': 'fa-truck',
            'free_shipping': 'fa-truck',
            'gift': 'fa-gift',
            'physical_gift': 'fa-gift',
            'product': 'fa-box',
            'product_voucher': 'fa-box',
            'coupon': 'fa-ticket-alt'
        };
        return icons[type] || 'fa-gift';
    }

    /**
     * Format number with thousand separators
     * @param {number} num - Number to format
     * @returns {string} Formatted number
     */
    formatNumber(num) {
        return (parseInt(num) || 0).toLocaleString('th-TH');
    }

    /**
     * Format date for display
     * @param {string} dateStr - Date string
     * @returns {string} Formatted date
     */
    formatDate(dateStr) {
        if (!dateStr) return '-';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', {
                day: 'numeric',
                month: 'short',
                year: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return dateStr;
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} str - String to escape
     * @returns {string} Escaped string
     */
    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}

// Export for global use
window.RewardsCatalog = RewardsCatalog;
