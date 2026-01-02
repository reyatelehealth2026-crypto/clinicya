<?php
/**
 * User Tags Management V3.0 - ระบบจัดการ Tags ลูกค้า
 * Modal + AJAX ทั้งหมด
 */
require_once 'config/config.php';
require_once 'config/database.php';

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

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-tags text-blue-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Total Tags</p>
                <p class="text-2xl font-bold"><?= count($tags) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-users text-green-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Tagged Users</p>
                <p class="text-2xl font-bold"><?= number_format(array_sum(array_column($tags, 'user_count'))) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-magic text-purple-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Auto Tags</p>
                <p class="text-2xl font-bold"><?= count(array_filter($tags, fn($t) => !empty($t['auto_assign_rules']))) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4 cursor-pointer hover:shadow-lg transition" onclick="openCreateModal()">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-plus text-white text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Quick Action</p>
                <p class="text-lg font-bold text-green-600">สร้าง Tag ใหม่</p>
            </div>
        </div>
    </div>
</div>

<!-- Tags Grid -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center">
        <h3 class="font-semibold">🏷️ Tags ทั้งหมด</h3>
        <button onclick="openCreateModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
            <i class="fas fa-plus mr-2"></i>สร้าง Tag
        </button>
    </div>
    
    <div id="tagsContainer" class="p-4">
        <?php if (empty($tags)): ?>
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-tags text-5xl mb-4"></i>
            <p class="text-lg">ยังไม่มี Tags</p>
            <button onclick="openCreateModal()" class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-plus mr-2"></i>สร้าง Tag แรก
            </button>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($tags as $tag): ?>
            <div id="tag-<?= $tag['id'] ?>" class="tag-card border rounded-xl p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <div class="w-5 h-5 rounded-full shadow-sm" style="background-color: <?= htmlspecialchars($tag['color']) ?>"></div>
                        <h4 class="font-semibold"><?= htmlspecialchars($tag['name']) ?></h4>
                    </div>
                    <div class="flex items-center gap-1">
                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($tag)) ?>)" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteTag(<?= $tag['id'] ?>, '<?= htmlspecialchars(addslashes($tag['name'])) ?>')" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                <?php if ($tag['description']): ?>
                <p class="text-gray-500 text-sm mb-3 line-clamp-2"><?= htmlspecialchars($tag['description']) ?></p>
                <?php endif; ?>
                
                <div class="flex items-center justify-between">
                    <a href="users.php?tag=<?= $tag['id'] ?>" class="text-sm text-blue-500 hover:text-blue-600">
                        <i class="fas fa-users mr-1"></i><?= number_format($tag['user_count']) ?> คน
                    </a>
                    <?php if (!empty($tag['auto_assign_rules'])): ?>
                    <span class="px-2 py-1 bg-purple-100 text-purple-700 rounded-full text-xs">
                        <i class="fas fa-magic mr-1"></i>Auto
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="tagModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 id="modalTitle" class="text-lg font-semibold">🏷️ สร้าง Tag ใหม่</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="tagForm" onsubmit="return saveTag(event)">
            <input type="hidden" id="tagId" value="">
            
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">ชื่อ Tag *</label>
                    <input type="text" id="tagName" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="เช่น VIP, New Customer">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">สี</label>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($colors as $c): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="tagColor" value="<?= $c ?>" class="hidden peer" <?= $c === '#3B82F6' ? 'checked' : '' ?>>
                            <div class="w-8 h-8 rounded-full peer-checked:ring-2 peer-checked:ring-offset-2 peer-checked:ring-gray-400 hover:scale-110 transition" style="background-color: <?= $c ?>"></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">คำอธิบาย</label>
                    <textarea id="tagDescription" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="อธิบายว่า Tag นี้ใช้สำหรับอะไร"></textarea>
                </div>
                
                <div id="errorMessage" class="hidden p-3 bg-red-100 text-red-700 rounded-lg text-sm"></div>
            </div>
            
            <div class="p-4 border-t flex gap-3">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" id="saveBtn" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <span id="saveBtnText">บันทึก</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4">
        <div class="p-6 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-trash text-red-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold mb-2">ลบ Tag?</h3>
            <p class="text-gray-500 mb-4">คุณต้องการลบ Tag "<span id="deleteTagName" class="font-medium"></span>" หรือไม่?</p>
            <p class="text-sm text-red-500 mb-4">การลบจะยกเลิก Tag จากผู้ใช้ทั้งหมด</p>
            
            <input type="hidden" id="deleteTagId">
            
            <div class="flex gap-3">
                <button onclick="closeDeleteModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button onclick="executeDeleteTag()" id="deleteBtn" class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">
                    <span id="deleteBtnText">ลบ</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="fixed bottom-4 right-4 z-50 hidden transform transition-transform duration-300">
    <div class="bg-gray-800 text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3">
        <i id="toastIcon" class="fas fa-check-circle text-green-400"></i>
        <span id="toastMessage"></span>
    </div>
</div>

<script>
const API_URL = 'api/ajax_handler.php';

// Modal functions
function openCreateModal() {
    document.getElementById('modalTitle').textContent = '🏷️ สร้าง Tag ใหม่';
    document.getElementById('tagId').value = '';
    document.getElementById('tagForm').reset();
    document.querySelector('input[name="tagColor"][value="#3B82F6"]').checked = true;
    hideError();
    openModal();
}

function openEditModal(tag) {
    document.getElementById('modalTitle').textContent = '✏️ แก้ไข Tag';
    document.getElementById('tagId').value = tag.id;
    document.getElementById('tagName').value = tag.name;
    document.getElementById('tagDescription').value = tag.description || '';
    
    const colorInput = document.querySelector(`input[name="tagColor"][value="${tag.color}"]`);
    if (colorInput) colorInput.checked = true;
    
    hideError();
    openModal();
}

function openModal() {
    document.getElementById('tagModal').classList.remove('hidden');
    document.getElementById('tagModal').classList.add('flex');
    document.getElementById('tagName').focus();
}

function closeModal() {
    document.getElementById('tagModal').classList.add('hidden');
    document.getElementById('tagModal').classList.remove('flex');
}

function deleteTag(id, name) {
    document.getElementById('deleteTagId').value = id;
    document.getElementById('deleteTagName').textContent = name;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
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

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    const msg = document.getElementById('toastMessage');
    
    msg.textContent = message;
    icon.className = type === 'success' 
        ? 'fas fa-check-circle text-green-400' 
        : 'fas fa-exclamation-circle text-red-400';
    
    toast.classList.remove('hidden');
    toast.classList.add('translate-y-0');
    
    setTimeout(() => {
        toast.classList.add('hidden');
    }, 3000);
}

// Keyboard shortcuts
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// Close on backdrop click
document.getElementById('tagModal').addEventListener('click', e => {
    if (e.target === document.getElementById('tagModal')) closeModal();
});

document.getElementById('deleteModal').addEventListener('click', e => {
    if (e.target === document.getElementById('deleteModal')) closeDeleteModal();
});
</script>

<style>
.tag-card { transition: all 0.3s ease; }
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<?php require_once 'includes/footer.php'; ?>
