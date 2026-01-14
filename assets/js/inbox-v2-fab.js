/**
 * Inbox V2 - Floating Action Button & HUD Mode Switcher
 * LINE-style UI improvements
 */

// ============================================
// FLOATING ACTION BUTTON (FAB)
// ============================================

const FAB = {
    isOpen: false,
    
    init() {
        // Close FAB when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.fab-container') && this.isOpen) {
                this.close();
            }
        });
    },
    
    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    },
    
    open() {
        const btn = document.getElementById('fabMainBtn');
        const menu = document.getElementById('fabMenu');
        
        if (btn && menu) {
            btn.classList.add('active');
            menu.classList.add('show');
            this.isOpen = true;
        }
    },
    
    close() {
        const btn = document.getElementById('fabMainBtn');
        const menu = document.getElementById('fabMenu');
        
        if (btn && menu) {
            btn.classList.remove('active');
            menu.classList.remove('show');
            this.isOpen = false;
        }
    },
    
    // Action handlers
    action(type) {
        this.close();
        
        switch(type) {
            case 'order':
                if (typeof openCreateOrderModal === 'function') {
                    openCreateOrderModal();
                }
                break;
            case 'payment':
                if (typeof sendPaymentLink === 'function') {
                    sendPaymentLink();
                }
                break;
            case 'delivery':
                if (typeof openScheduleDeliveryModal === 'function') {
                    openScheduleDeliveryModal();
                }
                break;
            case 'points':
                if (typeof openUsePointsModal === 'function') {
                    openUsePointsModal();
                }
                break;
            case 'menu':
                if (typeof sendRichMenu === 'function') {
                    sendRichMenu();
                }
                break;
            case 'image':
                if (typeof toggleImageAnalysisMenu === 'function') {
                    toggleImageAnalysisMenu();
                }
                break;
        }
    }
};

// ============================================
// HUD MODE SWITCHER
// ============================================

const HUDMode = {
    currentMode: 'ai', // 'ai' or 'crm'
    
    init() {
        // Load saved mode from localStorage
        const savedMode = localStorage.getItem('hudMode') || 'ai';
        this.switchMode(savedMode, false);
    },
    
    switchMode(mode, animate = true) {
        this.currentMode = mode;
        localStorage.setItem('hudMode', mode);
        
        // Update buttons
        document.querySelectorAll('.hud-mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        
        // Show/hide panels
        const aiPanel = document.getElementById('hudAIPanel');
        const crmPanel = document.getElementById('hudCRMPanel');
        
        if (aiPanel && crmPanel) {
            if (mode === 'ai') {
                aiPanel.style.display = 'block';
                crmPanel.style.display = 'none';
            } else {
                aiPanel.style.display = 'none';
                crmPanel.style.display = 'block';
                // Load CRM data if not loaded
                this.loadCRMData();
            }
            
            if (animate) {
                const activePanel = mode === 'ai' ? aiPanel : crmPanel;
                activePanel.style.animation = 'fadeIn 0.3s ease';
            }
        }
    },
    
    async loadCRMData() {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const response = await fetch(`api/inbox-v2.php?action=customer_crm&user_id=${userId}&line_account_id=${window.currentBotId || 1}`);
            const result = await response.json();
            
            if (result.success && result.data) {
                this.renderCRMData(result.data);
            }
        } catch (error) {
            console.error('Load CRM data error:', error);
        }
    },
    
    renderCRMData(data) {
        // Update member card
        const pointsDisplay = document.getElementById('crmPointsDisplay');
        if (pointsDisplay) {
            pointsDisplay.textContent = (data.points?.available_points || 0).toLocaleString();
        }
        
        // Update tier
        const tierBadge = document.getElementById('crmTierBadge');
        if (tierBadge && data.tier) {
            tierBadge.innerHTML = `${data.tier.icon} ${data.tier.name}`;
        }
        
        // Update stats
        if (data.stats) {
            const orderCount = document.getElementById('crmOrderCount');
            const totalSpent = document.getElementById('crmTotalSpent');
            const msgCount = document.getElementById('crmMsgCount');
            
            if (orderCount) orderCount.textContent = (data.stats.order_count || 0).toLocaleString();
            if (totalSpent) totalSpent.textContent = '฿' + (data.stats.total_spent || 0).toLocaleString();
            if (msgCount) msgCount.textContent = (data.stats.message_count || 0).toLocaleString();
        }
        
        // Update customer info
        if (data.user) {
            const fields = ['phone', 'email', 'birthday', 'address'];
            fields.forEach(field => {
                const el = document.getElementById(`crm_${field}`);
                if (el) {
                    el.textContent = data.user[field] || '-';
                }
            });
        }
        
        // Update tags
        this.renderTags(data.tags || []);
        
        // Update notes
        this.renderNotes(data.notes || []);
        
        // Update transactions
        this.renderTransactions(data.transactions || []);
    },
    
    renderTags(tags) {
        const container = document.getElementById('crmTagsContainer');
        if (!container) return;
        
        let html = tags.map(tag => `
            <span class="tag-badge" style="background-color: ${tag.color || '#6B7280'}">
                ${escapeHtml(tag.name)}
                <span class="remove-tag" onclick="HUDMode.removeTag(${tag.id})">&times;</span>
            </span>
        `).join('');
        
        html += `<button class="add-tag-btn" onclick="HUDMode.showAddTagModal()">+ เพิ่ม Tag</button>`;
        
        container.innerHTML = html;
    },
    
    renderNotes(notes) {
        const container = document.getElementById('crmNotesList');
        if (!container) return;
        
        if (notes.length === 0) {
            container.innerHTML = '<p class="text-gray-400 text-xs text-center py-2">ยังไม่มีโน้ต</p>';
            return;
        }
        
        container.innerHTML = notes.slice(0, 5).map(note => `
            <div class="note-item">
                <div>${escapeHtml(note.content)}</div>
                <div class="note-meta">${note.created_by || 'Admin'} • ${formatDate(note.created_at)}</div>
            </div>
        `).join('');
    },
    
    renderTransactions(transactions) {
        const container = document.getElementById('crmTransactionsList');
        if (!container) return;
        
        if (transactions.length === 0) {
            container.innerHTML = '<p class="text-gray-400 text-xs text-center py-2">ยังไม่มีรายการ</p>';
            return;
        }
        
        container.innerHTML = transactions.slice(0, 5).map(tx => `
            <div class="transaction-mini-item">
                <div class="tx-info">
                    <span class="tx-id">#${tx.id}</span>
                    <span class="tx-date">${formatDate(tx.created_at)}</span>
                </div>
                <span class="tx-amount">฿${(tx.grand_total || 0).toLocaleString()}</span>
            </div>
        `).join('');
    },
    
    async addNote() {
        const textarea = document.getElementById('crmNoteInput');
        const content = textarea?.value?.trim();
        
        if (!content) return;
        
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'add_customer_note');
            formData.append('user_id', userId);
            formData.append('content', content);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                textarea.value = '';
                this.loadCRMData(); // Refresh
                if (typeof showNotification === 'function') {
                    showNotification('✓ เพิ่มโน้ตสำเร็จ', 'success');
                }
            }
        } catch (error) {
            console.error('Add note error:', error);
        }
    },
    
    async removeTag(tagId) {
        const userId = window.ghostDraftState?.userId;
        if (!userId || !confirm('ต้องการลบ Tag นี้?')) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'remove_customer_tag');
            formData.append('user_id', userId);
            formData.append('tag_id', tagId);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.loadCRMData(); // Refresh
            }
        } catch (error) {
            console.error('Remove tag error:', error);
        }
    },
    
    showAddTagModal() {
        // Simple prompt for now - can be enhanced with a proper modal
        const tagName = prompt('ชื่อ Tag ใหม่:');
        if (tagName) {
            this.addTag(tagName);
        }
    },
    
    async addTag(tagName) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'add_customer_tag');
            formData.append('user_id', userId);
            formData.append('tag_name', tagName);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.loadCRMData(); // Refresh
                if (typeof showNotification === 'function') {
                    showNotification('✓ เพิ่ม Tag สำเร็จ', 'success');
                }
            }
        } catch (error) {
            console.error('Add tag error:', error);
        }
    },
    
    openUserDetail() {
        const userId = window.ghostDraftState?.userId;
        if (userId) {
            window.open(`user-detail.php?id=${userId}`, '_blank');
        }
    }
};

// Helper functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    FAB.init();
    HUDMode.init();
});
