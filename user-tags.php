<?php
/**
 * User Tags Management V3.0 - ระบบจัดการ Tags ลูกค้า
 * Modal + AJAX ทั้งหมด
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/empty-state.php';
require_once __DIR__ . '/includes/components/toast.php';
require_once __DIR__ . '/includes/components/modal.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'User Tags';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Ensure table exists
try {
    $db->query("SELECT 1 FROM user_tags LIMIT 1");
} catch (Exception $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS user_tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT DEFAULT NULL,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(7) DEFAULT '#3B82F6',
        description TEXT,
        auto_assign_rules JSON DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_line_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Get tags with user count
$tags = [];
try {
    $stmt = $db->prepare("
        SELECT t.*, 
               COALESCE(COUNT(DISTINCT uta.user_id), 0) as user_count
        FROM user_tags t
        LEFT JOIN user_tag_assignments uta ON t.id = uta.tag_id
        WHERE t.line_account_id = ? OR t.line_account_id IS NULL
        GROUP BY t.id
        ORDER BY t.name ASC
    ");
    $stmt->execute([$currentBotId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // user_tag_assignments might not exist
    $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
    $stmt->execute([$currentBotId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tags as &$tag) {
        $tag['user_count'] = 0;
    }
    unset($tag);
}

require_once 'includes/header.php';

$colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6B7280', '#06C755', '#14B8A6', '#F97316'];
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>
<?= getModalStyles() ?>
<?= getToastStyles() ?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: var(--space-4, 16px);
    margin-bottom: var(--space-6, 24px);
}
.stat-tile {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-4, 16px);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    display: flex;
    align-items: center;
    gap: var(--space-3, 12px);
    transition: box-shadow var(--transition-fast, 150ms ease);
}
.stat-tile:hover { box-shadow: 0 4px 12px rgba(15,23,42,0.10); }
.stat-tile-icon {
    width: 48px; height: 48px; border-radius: var(--radius-md, 12px);
    display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
}
.stat-tile-value { font-size: var(--text-2xl, 24px); font-weight: 700; color: var(--color-dark-800); line-height: 1; }
.stat-tile-label { font-size: var(--text-xs, 12px); color: var(--color-dark-500); margin-top: 4px; }
.tags-panel {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    overflow: hidden;
}
.tags-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: var(--space-4, 16px);
    border-bottom: 1px solid var(--color-slate-200);
}
.tags-panel-title { font-weight: 600; font-size: var(--text-base, 16px); color: var(--color-dark-800); }
.tags-grid-inner {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-4, 16px);
    padding: var(--space-4, 16px);
}
.tag-card {
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-4, 16px);
    transition: box-shadow var(--transition-fast, 150ms ease);
}
.tag-card:hover { box-shadow: 0 4px 12px rgba(15,23,42,0.10); }
.tag-card-head {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: var(--space-3, 12px);
}
.tag-color-dot {
    width: 20px; height: 20px; border-radius: var(--radius-full, 9999px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.15); flex-shrink: 0;
}
.tag-card-name { font-weight: 600; font-size: var(--text-sm, 14px); color: var(--color-dark-800); }
.tag-card-desc {
    font-size: var(--text-sm, 14px); color: var(--color-dark-500);
    margin-bottom: var(--space-3, 12px);
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.tag-card-footer { display: flex; align-items: center; justify-content: space-between; }
.tag-users-link { font-size: var(--text-sm, 14px); color: var(--color-primary-600); text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.tag-users-link:hover { color: var(--color-primary-700); }
.tag-auto-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 12px); font-weight: 500;
    background: rgba(124,58,237,0.08); color: var(--color-violet-600);
}
/* modal form */
.tf-field { margin-bottom: var(--space-4, 16px); }
.tf-field label { display: block; font-size: var(--text-sm, 14px); font-weight: 500; color: var(--color-dark-800); margin-bottom: 6px; }
.tf-input {
    width: 100%; padding: 10px var(--space-3, 12px); border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px); font-size: var(--text-sm, 14px); color: var(--color-dark-800);
    background: var(--color-slate-50); transition: all var(--transition-fast, 150ms ease); box-sizing: border-box;
}
.tf-input:focus { outline: none; background: #ffffff; border-color: var(--color-primary-400); box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }
.tf-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px var(--space-4, 16px); border-radius: var(--radius-md, 12px);
    font-size: var(--text-sm, 14px); font-weight: 600; cursor: pointer;
    border: 1px solid var(--color-slate-200); background: #ffffff; color: var(--color-dark-700);
    transition: all var(--transition-fast, 150ms ease);
}
.tf-btn:hover { background: var(--color-slate-50); }
.tf-btn-primary { background: var(--color-emerald-500); border-color: var(--color-emerald-500); color: #ffffff; }
.tf-btn-primary:hover { background: var(--color-emerald-600); }
.tf-btn-danger { background: var(--color-rose-500); border-color: var(--color-rose-500); color: #ffffff; }
.tf-btn-danger:hover { background: var(--color-rose-600); }
.color-swatches { display: flex; flex-wrap: wrap; gap: 8px; }
.color-swatch-label { cursor: pointer; }
.color-swatch-label input[type="radio"] { display: none; }
.color-swatch {
    width: 32px; height: 32px; border-radius: var(--radius-full, 9999px);
    transition: transform var(--transition-fast, 150ms ease);
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.color-swatch:hover { transform: scale(1.15); }
.color-swatch-label input:checked + .color-swatch {
    outline: 2px solid var(--color-dark-700); outline-offset: 2px;
}
.tf-error {
    padding: var(--space-3, 12px); background: var(--color-rose-50);
    border: 1px solid var(--color-rose-200); color: var(--color-rose-700);
    border-radius: var(--radius-md, 12px); font-size: var(--text-sm, 14px);
    margin-top: var(--space-3, 12px);
}
.delete-confirm-icon {
    width: 64px; height: 64px; border-radius: var(--radius-full, 9999px);
    background: var(--color-rose-100); display: flex; align-items: center; justify-content: center;
    margin: 0 auto var(--space-4, 16px);
    font-size: 24px; color: var(--color-rose-600);
}
/* Dark mode */
.dark .stat-tile { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .stat-tile-value { color: var(--color-slate-100); }
.dark .stat-tile-label { color: var(--color-slate-400); }
.dark .tags-panel { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .tags-panel-header { border-color: var(--color-dark-700); }
.dark .tags-panel-title { color: var(--color-slate-100); }
.dark .tag-card { border-color: var(--color-dark-700); }
.dark .tag-card-name { color: var(--color-slate-100); }
.dark .tag-card-desc { color: var(--color-slate-400); }
.dark .tf-input { background: var(--color-dark-900); border-color: var(--color-dark-700); color: var(--color-slate-100); }
.dark .tf-input:focus { background: var(--color-dark-800); border-color: var(--color-primary-400); }
.dark .tf-btn { background: var(--color-dark-700); border-color: var(--color-dark-600); color: var(--color-slate-300); }
</style>

<?= renderPageHeader(
    'User Tags',
    'จัดการ Tags สำหรับจัดกลุ่มลูกค้า',
    [
        'label'   => 'สร้าง Tag',
        'icon'    => 'fas fa-plus',
        'onclick' => 'openCreateModal()',
        'variant' => 'success',
    ],
    [
        ['label' => 'Customers', 'href' => null],
        ['label' => 'User Tags', 'href' => null],
    ]
) ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-tile">
        <div class="stat-tile-icon" style="background:var(--color-primary-50);color:var(--color-primary-600);">
            <i class="fas fa-tags"></i>
        </div>
        <div>
            <div class="stat-tile-value"><?= count($tags) ?></div>
            <div class="stat-tile-label">Total Tags</div>
        </div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon" style="background:var(--color-emerald-50);color:var(--color-emerald-600);">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="stat-tile-value"><?= number_format(array_sum(array_column($tags, 'user_count'))) ?></div>
            <div class="stat-tile-label">Tagged Users</div>
        </div>
    </div>
    <div class="stat-tile">
        <div class="stat-tile-icon" style="background:rgba(124,58,237,0.08);color:var(--color-violet-600);">
            <i class="fas fa-magic"></i>
        </div>
        <div>
            <div class="stat-tile-value"><?= count(array_filter($tags, fn($t) => !empty($t['auto_assign_rules']))) ?></div>
            <div class="stat-tile-label">Auto Tags</div>
        </div>
    </div>
    <div class="stat-tile" style="cursor:pointer;" onclick="openCreateModal()">
        <div class="stat-tile-icon" style="background:var(--color-emerald-500);color:#ffffff;">
            <i class="fas fa-plus"></i>
        </div>
        <div>
            <div class="stat-tile-label">Quick Action</div>
            <div style="font-size:var(--text-base,16px);font-weight:700;color:var(--color-emerald-600);margin-top:2px;">สร้าง Tag ใหม่</div>
        </div>
    </div>
</div>

<!-- Tags Panel -->
<div class="tags-panel">
    <div class="tags-panel-header">
        <span class="tags-panel-title"><i class="fas fa-tags" style="margin-right:8px;color:var(--color-primary-500);"></i>Tags ทั้งหมด</span>
        <button onclick="openCreateModal()" class="page-header-action page-header-action-success">
            <i class="fas fa-plus"></i><span>สร้าง Tag</span>
        </button>
    </div>

    <div id="tagsContainer">
        <?php if (empty($tags)): ?>
        <?= renderEmptyState(
            'fas fa-tags',
            'ยังไม่มี Tags',
            'สร้าง Tag แรกเพื่อเริ่มจัดกลุ่มลูกค้า',
            ['label' => 'สร้าง Tag แรก', 'icon' => 'fas fa-plus', 'onclick' => 'openCreateModal()']
        ) ?>
        <?php else: ?>
        <div class="tags-grid-inner">
            <?php foreach ($tags as $tag): ?>
            <div id="tag-<?= $tag['id'] ?>" class="tag-card">
                <div class="tag-card-head">
                    <div style="display:flex;align-items:center;gap:var(--space-3,12px);">
                        <div class="tag-color-dot" style="background-color:<?= htmlspecialchars($tag['color']) ?>"></div>
                        <span class="tag-card-name"><?= htmlspecialchars($tag['name']) ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($tag)) ?>)" class="data-table-row-action" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteTag(<?= $tag['id'] ?>, '<?= htmlspecialchars(addslashes($tag['name'])) ?>')" class="data-table-row-action data-table-row-action-danger" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php if ($tag['description']): ?>
                <div class="tag-card-desc"><?= htmlspecialchars($tag['description']) ?></div>
                <?php endif; ?>
                <div class="tag-card-footer">
                    <a href="users.php?tag=<?= $tag['id'] ?>" class="tag-users-link">
                        <i class="fas fa-users"></i><?= number_format($tag['user_count']) ?> คน
                    </a>
                    <?php if (!empty($tag['auto_assign_rules'])): ?>
                    <span class="tag-auto-badge"><i class="fas fa-magic"></i>Auto</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
/* ── Create/Edit Modal ── */
$colorSwatches = '';
foreach ($colors as $c) {
    $checked = $c === '#3B82F6' ? ' checked' : '';
    $colorSwatches .= '<label class="color-swatch-label">'
        . '<input type="radio" name="tagColor" value="' . htmlspecialchars($c) . '"' . $checked . '>'
        . '<div class="color-swatch" style="background-color:' . htmlspecialchars($c) . '"></div>'
        . '</label>';
}

$tagModalBody = '
<input type="hidden" id="tagId" value="">
<div class="tf-field">
    <label>ชื่อ Tag *</label>
    <input type="text" id="tagName" required class="tf-input" placeholder="เช่น VIP, New Customer">
</div>
<div class="tf-field">
    <label>สี</label>
    <div class="color-swatches">' . $colorSwatches . '</div>
</div>
<div class="tf-field" style="margin-bottom:0;">
    <label>คำอธิบาย</label>
    <textarea id="tagDescription" rows="2" class="tf-input" placeholder="อธิบายว่า Tag นี้ใช้สำหรับอะไร"></textarea>
</div>
<div id="errorMessage" class="tf-error" style="display:none;"></div>';

$tagModalFooter = '
<button type="button" onclick="closeModal()" class="tf-btn">ยกเลิก</button>
<button type="submit" id="saveBtn" class="tf-btn tf-btn-primary"><span id="saveBtnText">บันทึก</span></button>';

echo renderModal(
    'tagModal',
    'สร้าง Tag ใหม่',
    $tagModalBody,
    $tagModalFooter,
    ['size' => 'sm', 'formOpen' => '<form id="tagForm" onsubmit="return saveTag(event)">', 'formClose' => '</form>']
);

/* ── Delete Confirm Modal ── */
$deleteBody = '
<div class="delete-confirm-icon"><i class="fas fa-trash"></i></div>
<h3 style="font-size:var(--text-lg,18px);font-weight:600;color:var(--color-dark-800);text-align:center;margin:0 0 8px;">ลบ Tag?</h3>
<p style="font-size:var(--text-sm,14px);color:var(--color-dark-500);text-align:center;margin:0 0 8px;">คุณต้องการลบ Tag "<span id="deleteTagName" style="font-weight:600;"></span>" หรือไม่?</p>
<p style="font-size:var(--text-sm,14px);color:var(--color-rose-600);text-align:center;margin:0;">การลบจะยกเลิก Tag จากผู้ใช้ทั้งหมด</p>
<input type="hidden" id="deleteTagId">';

$deleteFooter = '
<button onclick="closeDeleteModal()" class="tf-btn">ยกเลิก</button>
<button onclick="executeDeleteTag()" id="deleteBtn" class="tf-btn tf-btn-danger"><span id="deleteBtnText">ลบ</span></button>';

echo renderModal('deleteModal', 'ยืนยันการลบ', $deleteBody, $deleteFooter, ['size' => 'sm']);
?>

<?= renderToastContainer() ?>

<script>
const API_URL = 'api/ajax_handler.php';

// Modal functions
function openCreateModal() {
    document.querySelector('#tagModal .modal-shell-title').textContent = 'สร้าง Tag ใหม่';
    document.getElementById('tagId').value = '';
    document.getElementById('tagForm').reset();
    var defaultColor = document.querySelector('input[name="tagColor"][value="#3B82F6"]');
    if (defaultColor) defaultColor.checked = true;
    hideError();
    openModal();
}

function openEditModal(tag) {
    document.querySelector('#tagModal .modal-shell-title').textContent = 'แก้ไข Tag';
    document.getElementById('tagId').value = tag.id;
    document.getElementById('tagName').value = tag.name;
    document.getElementById('tagDescription').value = tag.description || '';
    var colorInput = document.querySelector('input[name="tagColor"][value="' + tag.color + '"]');
    if (colorInput) colorInput.checked = true;
    hideError();
    openModal();
}

function openModal() {
    openModalShell('tagModal');
    setTimeout(function() {
        var n = document.getElementById('tagName');
        if (n) n.focus();
    }, 50);
}

function closeModal() {
    closeModalShell('tagModal');
}

function deleteTag(id, name) {
    document.getElementById('deleteTagId').value = id;
    document.getElementById('deleteTagName').textContent = name;
    openModalShell('deleteModal');
}

function closeDeleteModal() {
    closeModalShell('deleteModal');
}

// API functions
async function saveTag(e) {
    e.preventDefault();
    
    const id = document.getElementById('tagId').value;
    const name = document.getElementById('tagName').value.trim();
    const color = document.querySelector('input[name="tagColor"]:checked')?.value || '#3B82F6';
    const description = document.getElementById('tagDescription').value.trim();
    
    if (!name) {
        showError('กรุณาระบุชื่อ Tag');
        return false;
    }
    
    setLoading('saveBtn', 'saveBtnText', true);
    
    try {
        const formData = new FormData();
        formData.append('action', id ? 'update_tag' : 'create_tag');
        formData.append('name', name);
        formData.append('color', color);
        formData.append('description', description);
        if (id) formData.append('tag_id', id);
        
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast(id ? 'อัพเดท Tag สำเร็จ!' : 'สร้าง Tag สำเร็จ!', 'success');
            closeModal();
            setTimeout(() => location.reload(), 500);
        } else {
            showError(result.error || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาด: ' + error.message);
    } finally {
        setLoading('saveBtn', 'saveBtnText', false);
    }
    
    return false;
}

async function executeDeleteTag() {
    const id = document.getElementById('deleteTagId').value;
    
    setLoading('deleteBtn', 'deleteBtnText', true, 'กำลังลบ...');
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_tag');
        formData.append('tag_id', id);
        
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('ลบ Tag สำเร็จ!', 'success');
            closeDeleteModal();
            
            // Remove from DOM
            const tagCard = document.getElementById(`tag-${id}`);
            if (tagCard) {
                tagCard.style.opacity = '0';
                tagCard.style.transform = 'scale(0.9)';
                setTimeout(() => tagCard.remove(), 300);
            }
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        showToast('เกิดข้อผิดพลาด', 'error');
    } finally {
        setLoading('deleteBtn', 'deleteBtnText', false, 'ลบ');
    }
}

// Helper functions
function setLoading(btnId, textId, loading, loadingText = 'กำลังบันทึก...') {
    const btn = document.getElementById(btnId);
    const text = document.getElementById(textId);
    btn.disabled = loading;
    text.innerHTML = loading ? `<i class="fas fa-spinner fa-spin mr-1"></i>${loadingText}` : (btnId === 'deleteBtn' ? 'ลบ' : 'บันทึก');
}

function showError(message) {
    const el = document.getElementById('errorMessage');
    el.textContent = message;
    el.classList.remove('hidden');
}

function hideError() {
    document.getElementById('errorMessage').classList.add('hidden');
}

function showToast(message, type) {
    fireToast(message, type || 'success');
}
</script>

<?php require_once 'includes/footer.php'; ?>
