<?php
/**
 * Quick Access Settings Tab Content
 * Part of consolidated settings.php
 */
?>

<style>
.qa-menu-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s;
    user-select: none;
}
.qa-menu-card:hover {
    border-color: #10b981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
}
.qa-menu-card.selected {
    border-color: #10b981;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
}
.qa-menu-card.selected .qa-menu-card-icon {
    transform: scale(1.1);
}
.qa-menu-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    margin: 0 auto 8px;
    transition: transform 0.2s;
}
.qa-menu-card-check {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 24px;
    height: 24px;
    background: #10b981;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
}
.qa-menu-card.selected .qa-menu-card-check {
    display: flex;
}
.qa-sortable-ghost {
    opacity: 0.4;
}
.qa-drag-handle {
    cursor: grab;
}
.qa-drag-handle:active {
    cursor: grabbing;
}
</style>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">⚡ ตั้งค่า Quick Access</h2>
            <p class="text-gray-500 mt-1">เลือกเมนูที่ใช้บ่อยเพื่อเข้าถึงได้รวดเร็ว (สูงสุด 8 รายการ)</p>
        </div>
        <div class="flex gap-2">
            <button onclick="resetQAToDefault()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                <i class="fas fa-undo mr-1"></i> รีเซ็ต
            </button>
            <button onclick="saveQAQuickAccess()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-save mr-1"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<!-- Selected Items (Sortable) -->
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-gray-800">
            <i class="fas fa-star text-yellow-500 mr-2"></i>เมนูที่เลือก
            <span id="qaSelectedCount" class="text-sm font-normal text-gray-500">(0/8)</span>
        </h3>
        <p class="text-sm text-gray-500">ลากเพื่อเรียงลำดับ</p>
    </div>
    
    <div id="qaSelectedMenus" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 min-h-[100px] p-4 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
        <div class="col-span-full text-center text-gray-400 py-8" id="qaEmptyMessage">
            <i class="fas fa-hand-pointer text-4xl mb-2"></i>
            <p>คลิกเลือกเมนูด้านล่าง</p>
        </div>
    </div>
</div>

<!-- Available Menus -->
<div class="bg-white rounded-xl shadow p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-4">
        <i class="fas fa-th-large text-blue-500 mr-2"></i>เมนูทั้งหมด
    </h3>
    
    <div id="qaAvailableMenus" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <!-- Will be populated by JS -->
    </div>
</div>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
const QA_BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';
let qaSelectedMenus = [];
let qaAvailableMenus = [];

const qaColorClasses = {
    'green': 'bg-green-500', 'orange': 'bg-orange-500', 'blue': 'bg-blue-500',
    'purple': 'bg-purple-500', 'cyan': 'bg-cyan-500', 'pink': 'bg-pink-500',
    'indigo': 'bg-indigo-500', 'teal': 'bg-teal-500', 'amber': 'bg-amber-500',
    'emerald': 'bg-emerald-500', 'sky': 'bg-sky-500', 'violet': 'bg-violet-500',
    'rose': 'bg-rose-500', 'fuchsia': 'bg-fuchsia-500', 'lime': 'bg-lime-500', 'slate': 'bg-slate-500',
};

document.addEventListener('DOMContentLoaded', async () => {
    await loadQAAvailableMenus();
    await loadQAUserMenus();
    initQASortable();
});

async function loadQAAvailableMenus() {
    try {
        const res = await fetch(`${QA_BASE_URL}/api/quick-access.php?action=get_available`);
        const data = await res.json();
        if (data.success) {
            qaAvailableMenus = data.data;
            renderQAAvailableMenus();
        }
    } catch (e) { console.error('Load available menus error:', e); }
}

async function loadQAUserMenus() {
    try {
        const res = await fetch(`${QA_BASE_URL}/api/quick-access.php?action=get`);
        const data = await res.json();
        if (data.success) {
            qaSelectedMenus = data.data.map(m => m.key);
            renderQASelectedMenus();
            updateQAAvailableMenusState();
        }
    } catch (e) { console.error('Load user menus error:', e); }
}

function renderQAAvailableMenus() {
    const container = document.getElementById('qaAvailableMenus');
    container.innerHTML = qaAvailableMenus.map(menu => `
        <div class="qa-menu-card relative" data-key="${menu.key}" onclick="toggleQAMenu('${menu.key}')">
            <div class="qa-menu-card-check"><i class="fas fa-check"></i></div>
            <div class="qa-menu-card-icon ${qaColorClasses[menu.color] || 'bg-gray-500'}">
                <i class="fas ${menu.icon}"></i>
            </div>
            <p class="text-center font-medium text-gray-700 text-sm">${menu.label}</p>
        </div>
    `).join('');
}

function renderQASelectedMenus() {
    const container = document.getElementById('qaSelectedMenus');
    if (qaSelectedMenus.length === 0) {
        container.innerHTML = `
            <div class="col-span-full text-center text-gray-400 py-8" id="qaEmptyMessage">
                <i class="fas fa-hand-pointer text-4xl mb-2"></i>
                <p>คลิกเลือกเมนูด้านล่าง</p>
            </div>
        `;
        document.getElementById('qaSelectedCount').textContent = '(0/8)';
        return;
    }
    
    container.innerHTML = qaSelectedMenus.map(key => {
        const menu = qaAvailableMenus.find(m => m.key === key);
        if (!menu) return '';
        return `
            <div class="qa-menu-card selected qa-drag-handle" data-key="${key}">
                <div class="qa-menu-card-icon ${qaColorClasses[menu.color] || 'bg-gray-500'}">
                    <i class="fas ${menu.icon}"></i>
                </div>
                <p class="text-center font-medium text-gray-700 text-xs">${menu.label}</p>
                <button onclick="event.stopPropagation(); removeQAMenu('${key}')" 
                        class="absolute top-1 right-1 w-5 h-5 bg-red-500 text-white rounded-full text-xs hover:bg-red-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
    }).join('');
    
    document.getElementById('qaSelectedCount').textContent = `(${qaSelectedMenus.length}/8)`;
}

function updateQAAvailableMenusState() {
    document.querySelectorAll('#qaAvailableMenus .qa-menu-card').forEach(card => {
        const key = card.dataset.key;
        if (qaSelectedMenus.includes(key)) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
    });
}

function toggleQAMenu(key) {
    const index = qaSelectedMenus.indexOf(key);
    if (index > -1) {
        qaSelectedMenus.splice(index, 1);
    } else {
        if (qaSelectedMenus.length >= 8) {
            alert('เลือกได้สูงสุด 8 รายการ');
            return;
        }
        qaSelectedMenus.push(key);
    }
    renderQASelectedMenus();
    updateQAAvailableMenusState();
    initQASortable();
}

function removeQAMenu(key) {
    const index = qaSelectedMenus.indexOf(key);
    if (index > -1) {
        qaSelectedMenus.splice(index, 1);
        renderQASelectedMenus();
        updateQAAvailableMenusState();
        initQASortable();
    }
}

function initQASortable() {
    const container = document.getElementById('qaSelectedMenus');
    if (container._sortable) {
        container._sortable.destroy();
    }
    
    container._sortable = new Sortable(container, {
        animation: 150,
        ghostClass: 'qa-sortable-ghost',
        handle: '.qa-drag-handle',
        onEnd: function(evt) {
            const newOrder = [];
            container.querySelectorAll('.qa-menu-card').forEach(card => {
                if (card.dataset.key) {
                    newOrder.push(card.dataset.key);
                }
            });
            qaSelectedMenus = newOrder;
        }
    });
}

async function saveQAQuickAccess() {
    try {
        const res = await fetch(`${QA_BASE_URL}/api/quick-access.php?action=save`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ menus: qaSelectedMenus })
        });
        const data = await res.json();
        if (data.success) {
            alert('บันทึกสำเร็จ! กรุณารีเฟรชหน้าเพื่อดูการเปลี่ยนแปลง');
            location.reload();
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}

async function resetQAToDefault() {
    if (!confirm('รีเซ็ตเป็นค่าเริ่มต้น?')) return;
    
    try {
        const res = await fetch(`${QA_BASE_URL}/api/quick-access.php?action=reset`);
        const data = await res.json();
        if (data.success) {
            qaSelectedMenus = ['messages', 'orders', 'products', 'broadcast'];
            renderQASelectedMenus();
            updateQAAvailableMenusState();
            initQASortable();
            alert('รีเซ็ตสำเร็จ!');
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}
</script>
