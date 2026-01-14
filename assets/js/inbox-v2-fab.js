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
    currentMode: 'crm',
    allTags: [],
    userTags: [],
    collapsedSections: {}, // Store collapsed state
    
    init() {
        // Default to 'crm' for first time users
        const savedMode = localStorage.getItem('hudMode') || 'crm';
        this.switchMode(savedMode, false);
        
        // Load collapsed sections from localStorage
        this.loadCollapsedSections();
    },
    
    // Load collapsed sections state from localStorage
    loadCollapsedSections() {
        try {
            const saved = localStorage.getItem('hudCollapsedSections');
            if (saved) {
                this.collapsedSections = JSON.parse(saved);
                // Apply saved state to sections
                Object.keys(this.collapsedSections).forEach(sectionId => {
                    const section = document.getElementById(sectionId);
                    if (section && this.collapsedSections[sectionId]) {
                        section.classList.add('collapsed');
                    }
                });
            }
        } catch (e) {
            console.warn('Failed to load collapsed sections:', e);
        }
    },
    
    // Save collapsed sections state to localStorage
    saveCollapsedSections() {
        try {
            localStorage.setItem('hudCollapsedSections', JSON.stringify(this.collapsedSections));
        } catch (e) {
            console.warn('Failed to save collapsed sections:', e);
        }
    },
    
    switchMode(mode) {
        this.currentMode = mode;
        localStorage.setItem('hudMode', mode);
        
        document.querySelectorAll('.hud-mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
        
        const crmPanel = document.getElementById('hudCRMPanel');
        const aiPanel = document.getElementById('hudAIPanel');
        const templatesPanel = document.getElementById('hudTemplatesPanel');
        
        // Hide all panels
        if (crmPanel) crmPanel.style.display = 'none';
        if (aiPanel) aiPanel.style.display = 'none';
        if (templatesPanel) templatesPanel.style.display = 'none';
        
        // Show selected panel
        switch (mode) {
            case 'crm':
                if (crmPanel) crmPanel.style.display = 'block';
                this.loadCRMData();
                break;
            case 'ai':
                if (aiPanel) aiPanel.style.display = 'block';
                break;
            case 'templates':
                if (templatesPanel) templatesPanel.style.display = 'block';
                this.loadTemplates();
                break;
        }
    },
    
    // Templates functions (combined Quick Reply + Templates)
    templates: [],
    filteredTemplates: [],
    templatesLoaded: false,
    
    async loadTemplates() {
        if (this.templatesLoaded && this.templates.length > 0) {
            this.renderTemplates();
            return;
        }
        
        const listContainer = document.getElementById('templateList');
        if (!listContainer) return;
        
        listContainer.innerHTML = '<div class="text-center text-gray-400 text-sm py-4"><i class="fas fa-spinner fa-spin"></i> กำลังโหลด...</div>';
        
        try {
            const response = await fetch('api/inbox.php?action=get_templates');
            const data = await response.json();
            
            if (data.success && data.data) {
                this.templates = data.data;
                this.filteredTemplates = [...this.templates];
                this.templatesLoaded = true;
                this.renderTemplates();
            } else {
                listContainer.innerHTML = '<div class="text-center text-gray-400 text-sm py-4">ไม่พบเทมเพลต</div>';
            }
        } catch (error) {
            console.error('Error loading templates:', error);
            listContainer.innerHTML = '<div class="text-center text-red-400 text-sm py-4">เกิดข้อผิดพลาด</div>';
        }
    },
    
    searchTemplates(query) {
        const q = query.toLowerCase().trim();
        if (!q) {
            this.filteredTemplates = [...this.templates];
        } else {
            this.filteredTemplates = this.templates.filter(t => 
                t.name.toLowerCase().includes(q) || 
                t.content.toLowerCase().includes(q) ||
                (t.category && t.category.toLowerCase().includes(q)) ||
                (t.shortcut && t.shortcut.toLowerCase().includes(q))
            );
        }
        this.renderTemplates();
    },
    
    renderTemplates() {
        const listContainer = document.getElementById('templateList');
        if (!listContainer) return;
        
        if (this.filteredTemplates.length === 0) {
            listContainer.innerHTML = '<div class="text-center text-gray-400 text-sm py-4">ไม่พบเทมเพลต</div>';
            return;
        }
        
        // Group by category
        const grouped = {};
        this.filteredTemplates.forEach(t => {
            const cat = t.category || 'ทั่วไป';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(t);
        });
        
        let html = '';
        for (const [category, items] of Object.entries(grouped)) {
            html += `<div class="mb-3">
                <div class="text-xs font-medium text-gray-500 mb-2 px-1">${escapeHtml(category)}</div>
                ${items.map(t => `
                    <div class="template-item bg-white border rounded-lg p-3 mb-2 cursor-pointer hover:bg-gray-50 hover:border-teal-300 transition" onclick="HUDMode.useTemplate(${t.id})">
                        <div class="flex items-center gap-2 mb-1">
                            ${t.shortcut ? `<span class="text-xs px-2 py-0.5 bg-teal-100 text-teal-700 rounded font-mono">${escapeHtml(t.shortcut)}</span>` : ''}
                            <span class="text-sm font-medium text-gray-800 truncate">${escapeHtml(t.name)}</span>
                        </div>
                        <p class="text-xs text-gray-500 line-clamp-2">${escapeHtml(t.content.substring(0, 100))}${t.content.length > 100 ? '...' : ''}</p>
                    </div>
                `).join('')}
            </div>`;
        }
        
        listContainer.innerHTML = html;
    },
    
    useTemplate(id) {
        const template = this.templates.find(t => t.id === id);
        if (!template) return;
        
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            // Replace placeholders
            let content = template.content;
            const userName = window.ghostDraftState?.userName || 'ลูกค้า';
            content = content.replace(/\{name\}/g, userName);
            content = content.replace(/\{ชื่อ\}/g, userName);
            
            messageInput.value = content;
            messageInput.focus();
            messageInput.dispatchEvent(new Event('input'));
            
            // Show notification
            if (typeof showNotification === 'function') {
                showNotification('✓ ใส่เทมเพลตแล้ว', 'success');
            }
        }
    },
    
    openTemplateManager() {
        window.open('inbox.php?tab=templates', '_blank');
    },
    
    toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        if (section) {
            section.classList.toggle('collapsed');
            // Save state to localStorage
            this.collapsedSections[sectionId] = section.classList.contains('collapsed');
            this.saveCollapsedSections();
        }
    },
    
    async loadCRMData() {
        const userId = window.ghostDraftState?.userId;
        console.log('[HUDMode] loadCRMData called, userId:', userId, 'currentBotId:', window.currentBotId);
        if (!userId) {
            console.warn('[HUDMode] No userId available');
            return;
        }
        
        try {
            const url = `api/inbox-v2.php?action=customer_crm&user_id=${userId}&line_account_id=${window.currentBotId || 1}`;
            console.log('[HUDMode] Fetching CRM data from:', url);
            const response = await fetch(url);
            const result = await response.json();
            console.log('[HUDMode] CRM data result:', result);
            
            if (result.success && result.data) {
                this.renderCRMData(result.data);
            } else {
                console.error('[HUDMode] CRM data error:', result.error);
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
            // Update chat status dropdown
            const chatStatusSelect = document.getElementById('crmChatStatus');
            if (chatStatusSelect) {
                chatStatusSelect.value = data.user.chat_status || '';
            }
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
            const container = document.getElementById(`${f.id}_container`);
            if (container && !container.classList.contains('editing')) {
                const value = user[f.field] || '-';
                const rawValue = user[f.field] || '';
                container.innerHTML = `
                    <div class="info-left">
                        <div class="label">${f.label}</div>
                        <div class="value" id="${f.id}">${escapeHtml(value)}</div>
                    </div>
                    <button class="edit-btn" onclick="HUDMode.editField('${f.field}', '${escapeHtml(rawValue).replace(/'/g, "\\'")}')">
                        <i class="fas fa-pen"></i>
                    </button>
                `;
            }
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
        console.log('[HUDMode] saveField called:', { field, value, userId });
        
        if (!userId) {
            console.error('[HUDMode] saveField: No userId available');
            showNotification && showNotification('❌ ไม่พบข้อมูลลูกค้า', 'error');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'update_customer_info');
            formData.append('user_id', userId);
            formData.append('field', field);
            formData.append('value', value);
            formData.append('line_account_id', window.currentBotId || 1);
            
            console.log('[HUDMode] saveField sending:', { action: 'update_customer_info', user_id: userId, field, value });
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            console.log('[HUDMode] saveField result:', result);
            
            if (result.success) {
                this.loadCRMData();
                showNotification && showNotification('✓ บันทึกสำเร็จ', 'success');
            } else {
                showNotification && showNotification('❌ ' + (result.error || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (error) {
            console.error('Save field error:', error);
            showNotification && showNotification('❌ เกิดข้อผิดพลาด', 'error');
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
        console.log('[HUDMode] showTagSelector called, container:', container, 'allTags:', this.allTags, 'userTags:', this.userTags);
        if (!container) {
            console.error('[HUDMode] tagSelectorContainer not found');
            return;
        }
        
        // Filter out already assigned tags
        const userTagIds = this.userTags.map(t => t.id);
        const availableTags = this.allTags.filter(t => !userTagIds.includes(t.id));
        console.log('[HUDMode] availableTags:', availableTags);
        
        let html = `<div class="tag-selector">`;
        
        if (availableTags.length > 0) {
            html += `<div class="tag-selector-title">เลือก Tag ที่มีอยู่</div>`;
            html += `<div class="tag-selector-list">`;
            availableTags.forEach(tag => {
                html += `<span class="tag-selector-item" style="background-color: ${tag.color || '#6B7280'}; color: white;" onclick="HUDMode.addExistingTag(${tag.id})">${escapeHtml(tag.name)}</span>`;
            });
            html += `</div>`;
        } else {
            html += `<div class="tag-selector-title">ไม่มี Tag ที่สามารถเพิ่มได้</div>`;
        }
        
        html += `
            <div class="tag-selector-new">
                <input type="text" id="newTagInput" placeholder="หรือสร้าง Tag ใหม่..." onkeypress="if(event.key==='Enter')HUDMode.addNewTag()">
                <button onclick="HUDMode.addNewTag()">เพิ่ม</button>
            </div>
        </div>`;
        
        container.innerHTML = html;
        console.log('[HUDMode] Tag selector rendered');
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
    },
    
    async updateChatStatus(status) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) {
            showNotification && showNotification('❌ ไม่พบข้อมูลลูกค้า', 'error');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'update_chat_status');
            formData.append('user_id', userId);
            formData.append('status', status);
            formData.append('line_account_id', window.currentBotId || 1);
            
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success) {
                showNotification && showNotification('✓ อัปเดตสถานะสำเร็จ', 'success');
                // Update the user-item data attribute in sidebar
                const userItem = document.querySelector(`a[data-user-id="${userId}"]`);
                if (userItem) {
                    userItem.dataset.chatStatus = status;
                }
            } else {
                showNotification && showNotification('❌ ' + (result.error || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (error) {
            console.error('Update chat status error:', error);
            showNotification && showNotification('❌ เกิดข้อผิดพลาด', 'error');
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

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    FAB.init();
    HUDMode.init();
});
