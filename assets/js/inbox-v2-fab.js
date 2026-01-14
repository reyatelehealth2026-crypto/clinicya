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
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.fab-container') && this.isOpen) {
                this.close();
            }
        });
    },
    
    toggle() {
        this.isOpen ? this.close() : this.open();
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
    
    action(type) {
        this.close();
        const actions = {
            'order': () => typeof openCreateOrderModal === 'function' && openCreateOrderModal(),
            'payment': () => typeof sendPaymentLink === 'function' && sendPaymentLink(),
            'delivery': () => typeof openScheduleDeliveryModal === 'function' && openScheduleDeliveryModal(),
            'points': () => typeof openUsePointsModal === 'function' && openUsePointsModal(),
            'menu': () => typeof sendRichMenu === 'function' && sendRichMenu(),
            'image': () => typeof toggleImageAnalysisMenu === 'function' && toggleImageAnalysisMenu()
        };
        actions[type] && actions[type]();
    }
};

// ============================================
// HUD MODE SWITCHER
// ============================================

const HUDMode = {
    currentMode: 'ai',
    allTags: [],
    userTags: [],
    
    init() {
        const savedMode = localStorage.getItem('hudMode') || 'ai';
        this.switchMode(savedMode, false);
    },
    
    switchMode(mode, animate = true) {
        this.currentMode = mode;
        localStorage.setItem('hudMode', mode);
        
        document.querySelectorAll('.hud-mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        
        const aiPanel = document.getElementById('hudAIPanel');
        const crmPanel = document.getElementById('hudCRMPanel');
        
        if (aiPanel && crmPanel) {
            if (mode === 'ai') {
                aiPanel.style.display = 'block';
                crmPanel.style.display = 'none';
            } else {
                aiPanel.style.display = 'none';
                crmPanel.style.display = 'block';
                this.loadCRMData();
            }
        }
    },
    
    toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        if (section) {
            section.classList.toggle('collapsed');
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
        // Points
        const pointsDisplay = document.getElementById('crmPointsDisplay');
        if (pointsDisplay) pointsDisplay.textContent = (data.points?.available_points || 0).toLocaleString();
        
        // Tier
        const tierBadge = document.getElementById('crmTierBadge');
        if (tierBadge && data.tier) tierBadge.innerHTML = `${data.tier.icon} ${data.tier.name}`;
        
        // Stats
        if (data.stats) {
            const el = (id, val) => { const e = document.getElementById(id); if(e) e.textContent = val; };
            el('crmOrderCount', (data.stats.order_count || 0).toLocaleString());
            el('crmTotalSpent', '฿' + (data.stats.total_spent || 0).toLocaleString());
            el('crmMsgCount', (data.stats.message_count || 0).toLocaleString());
        }
        
        // Customer info
        if (data.user) {
            this.renderCustomerInfo(data.user);
        }
        
        // Tags
        this.allTags = data.all_tags || [];
        this.userTags = data.tags || [];
        this.renderTags();
        
        // Notes
        this.renderNotes(data.notes || []);
        
        // Transactions
        this.renderTransactions(data.transactions || []);
    },
    
    renderCustomerInfo(user) {
        const fields = [
            { id: 'crm_display_name', field: 'display_name', label: 'ชื่อ' },
            { id: 'crm_phone', field: 'phone', label: 'เบอร์โทร' },
            { id: 'crm_address', field: 'address', label: 'ที่อยู่' }
        ];
        
        fields.forEach(f => {
            const el = document.getElementById(f.id);
            if (el) el.textContent = user[f.field] || '-';
        });
    },
    
    editField(field, currentValue) {
        const container = document.getElementById(`crm_${field}_container`);
        if (!container) return;
        
        const label = container.querySelector('.label').textContent;
        container.classList.add('editing');
        container.innerHTML = `
            <div class="label">${label}</div>
            <input type="text" class="edit-input" id="edit_${field}" value="${escapeHtml(currentValue || '')}" placeholder="กรอก${label}...">
            <div class="edit-actions">
                <button class="cancel-btn" onclick="HUDMode.cancelEdit('${field}', '${escapeHtml(currentValue || '-')}', '${label}')">ยกเลิก</button>
                <button class="save-btn" onclick="HUDMode.saveField('${field}')">บันทึก</button>
            </div>
        `;
        document.getElementById(`edit_${field}`).focus();
    },
    
    cancelEdit(field, value, label) {
        const container = document.getElementById(`crm_${field}_container`);
        if (!container) return;
        
        container.classList.remove('editing');
        container.innerHTML = `
            <div class="info-left">
                <div class="label">${label}</div>
                <div class="value" id="crm_${field}">${value}</div>
            </div>
            <button class="edit-btn" onclick="HUDMode.editField('${field}', '${escapeHtml(value === '-' ? '' : value)}')">
                <i class="fas fa-pen"></i>
            </button>
        `;
    },
    
    async saveField(field) {
        const input = document.getElementById(`edit_${field}`);
        const value = input?.value?.trim() || '';
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'update_customer_info');
            formData.append('user_id', userId);
            formData.append('field', field);
            formData.append('value', value);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                this.loadCRMData();
                showNotification && showNotification('✓ บันทึกสำเร็จ', 'success');
            } else {
                showNotification && showNotification('❌ ' + (result.error || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (error) {
            console.error('Save field error:', error);
        }
    },
    
    renderTags() {
        const container = document.getElementById('crmTagsContainer');
        if (!container) return;
        
        let html = this.userTags.map(tag => `
            <span class="tag-badge" style="background-color: ${tag.color || '#6B7280'}">
                ${escapeHtml(tag.name)}
                <span class="remove-tag" onclick="HUDMode.removeTag(${tag.id})">&times;</span>
            </span>
        `).join('');
        
        html += `<button class="add-tag-btn" onclick="HUDMode.showTagSelector()">+ เพิ่ม Tag</button>`;
        html += `<div id="tagSelectorContainer"></div>`;
        
        container.innerHTML = html;
    },
    
    showTagSelector() {
        const container = document.getElementById('tagSelectorContainer');
        if (!container) return;
        
        // Filter out already assigned tags
        const userTagIds = this.userTags.map(t => t.id);
        const availableTags = this.allTags.filter(t => !userTagIds.includes(t.id));
        
        let html = `<div class="tag-selector">`;
        
        if (availableTags.length > 0) {
            html += `<div class="tag-selector-title">เลือก Tag ที่มีอยู่</div>`;
            html += `<div class="tag-selector-list">`;
            availableTags.forEach(tag => {
                html += `<span class="tag-selector-item" style="background-color: ${tag.color || '#6B7280'}; color: white;" onclick="HUDMode.addExistingTag(${tag.id})">${escapeHtml(tag.name)}</span>`;
            });
            html += `</div>`;
        }
        
        html += `
            <div class="tag-selector-new">
                <input type="text" id="newTagInput" placeholder="หรือสร้าง Tag ใหม่..." onkeypress="if(event.key==='Enter')HUDMode.addNewTag()">
                <button onclick="HUDMode.addNewTag()">เพิ่ม</button>
            </div>
        </div>`;
        
        container.innerHTML = html;
    },
    
    hideTagSelector() {
        const container = document.getElementById('tagSelectorContainer');
        if (container) container.innerHTML = '';
    },
    
    async addExistingTag(tagId) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'assign_tag');
            formData.append('user_id', userId);
            formData.append('tag_id', tagId);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                this.hideTagSelector();
                this.loadCRMData();
                showNotification && showNotification('✓ เพิ่ม Tag สำเร็จ', 'success');
            }
        } catch (error) {
            console.error('Add tag error:', error);
        }
    },
    
    async addNewTag() {
        const input = document.getElementById('newTagInput');
        const tagName = input?.value?.trim();
        if (!tagName) return;
        
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'add_customer_tag');
            formData.append('user_id', userId);
            formData.append('tag_name', tagName);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                this.hideTagSelector();
                this.loadCRMData();
                showNotification && showNotification('✓ เพิ่ม Tag สำเร็จ', 'success');
            }
        } catch (error) {
            console.error('Add new tag error:', error);
        }
    },
    
    async removeTag(tagId) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'remove_customer_tag');
            formData.append('user_id', userId);
            formData.append('tag_id', tagId);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                this.loadCRMData();
            }
        } catch (error) {
            console.error('Remove tag error:', error);
        }
    },
    
    renderNotes(notes) {
        const container = document.getElementById('crmNotesList');
        if (!container) return;
        
        if (notes.length === 0) {
            container.innerHTML = '<div class="notes-empty">ยังไม่มีโน้ต</div>';
            return;
        }
        
        container.innerHTML = notes.slice(0, 10).map(note => `
            <div class="note-item">
                <div>${escapeHtml(note.content)}</div>
                <div class="note-meta">
                    <span>${note.created_by || 'Admin'} • ${formatDate(note.created_at)}</span>
                    <span class="delete-note" onclick="HUDMode.deleteNote(${note.id})"><i class="fas fa-trash"></i></span>
                </div>
            </div>
        `).join('');
    },
    
    async addNote() {
        const textarea = document.getElementById('crmNoteInput');
        const content = textarea?.value?.trim();
        if (!content) return;
        
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;
        
        const btn = document.querySelector('.add-note-btn');
        if (btn) btn.disabled = true;
        
        try {
            const formData = new FormData();
            formData.append('action', 'add_customer_note');
            formData.append('user_id', userId);
            formData.append('content', content);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                textarea.value = '';
                this.loadCRMData();
                showNotification && showNotification('✓ เพิ่มโน้ตสำเร็จ', 'success');
            }
        } catch (error) {
            console.error('Add note error:', error);
        } finally {
            if (btn) btn.disabled = false;
        }
    },
    
    async deleteNote(noteId) {
        if (!confirm('ต้องการลบโน้ตนี้?')) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'delete_customer_note');
            formData.append('note_id', noteId);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                this.loadCRMData();
            }
        } catch (error) {
            console.error('Delete note error:', error);
        }
    },
    
    renderTransactions(transactions) {
        const container = document.getElementById('crmTransactionsList');
        if (!container) return;
        
        if (transactions.length === 0) {
            container.innerHTML = '<div class="notes-empty">ยังไม่มีรายการ</div>';
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
    
    openUserDetail() {
        const userId = window.ghostDraftState?.userId;
        if (userId) window.open(`user-detail.php?id=${userId}`, '_blank');
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

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    FAB.init();
    HUDMode.init();
});
