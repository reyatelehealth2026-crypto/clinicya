<?php
/**
 * Template Library - คลังเทมเพลตข้อความ
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/empty-state.php';
require_once __DIR__ . '/includes/components/modal.php';
require_once __DIR__ . '/includes/components/toast.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Template Library';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $data = [$_POST['name'], $_POST['category'], $_POST['message_type'], $_POST['content']];

        if ($action === 'create') {
            $stmt = $db->prepare("INSERT INTO templates (name, category, message_type, content) VALUES (?, ?, ?, ?)");
        } else {
            $data[] = $_POST['id'];
            $stmt = $db->prepare("UPDATE templates SET name=?, category=?, message_type=?, content=? WHERE id=?");
        }
        $stmt->execute($data);
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM templates WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    }
    header('Location: templates.php');
    exit;
}

// Get all templates
$stmt = $db->query("SELECT * FROM templates ORDER BY category, name");
$templates = $stmt->fetchAll();

// Get categories
$categories = array_unique(array_column($templates, 'category'));

require_once 'includes/header.php';
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>
<?= getModalStyles() ?>
<?= getToastStyles() ?>

<style>
.template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: var(--space-4, 16px);
    margin-top: var(--space-4, 16px);
}
.template-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-4, 16px);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    transition: box-shadow var(--transition-fast, 150ms ease);
}
.template-card:hover { box-shadow: 0 4px 12px rgba(15,23,42,0.10); }
.template-card-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: var(--space-2, 8px);
}
.template-card-name { font-weight: 600; font-size: var(--text-sm, 14px); color: var(--color-dark-800); }
.template-card-cat { font-size: var(--text-xs, 12px); color: var(--color-dark-500); margin-top: 2px; }
.template-type-badge {
    display: inline-flex; align-items: center;
    padding: 2px 8px; border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 12px); font-weight: 500; white-space: nowrap;
}
.template-type-text { background: var(--color-primary-50); color: var(--color-primary-700); }
.template-type-flex { background: rgba(124,58,237,0.08); color: var(--color-violet-600); }
.template-preview {
    padding: var(--space-3, 12px); background: var(--color-slate-50);
    border-radius: var(--radius-md, 12px); margin-bottom: var(--space-3, 12px);
    max-height: 120px; overflow-y: auto;
}
.template-preview pre {
    font-size: var(--text-xs, 12px); white-space: pre-wrap; word-break: break-word;
    color: var(--color-dark-700); margin: 0; font-family: inherit;
}
.template-actions { display: flex; gap: var(--space-2, 8px); }
.template-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px var(--space-3, 12px); border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px); font-size: var(--text-xs, 12px); font-weight: 500;
    background: #ffffff; color: var(--color-dark-700); cursor: pointer; text-decoration: none;
    transition: all var(--transition-fast, 150ms ease);
}
.template-btn:hover { background: var(--color-slate-50); border-color: var(--color-primary-300); color: var(--color-primary-600); }
.template-btn-icon { flex: none; width: 36px; padding: 8px; }
.template-btn-danger:hover { background: var(--color-rose-50); border-color: var(--color-rose-300); color: var(--color-rose-600); }
.category-filter-bar { display: flex; flex-wrap: wrap; gap: var(--space-2, 8px); margin-bottom: var(--space-2, 8px); }
.category-btn {
    display: inline-flex; align-items: center; padding: 7px 14px;
    border-radius: var(--radius-full, 9999px); font-size: var(--text-sm, 14px); font-weight: 500;
    border: 1px solid var(--color-slate-200); background: #ffffff; color: var(--color-dark-700);
    cursor: pointer; transition: all var(--transition-fast, 150ms ease);
}
.category-btn:hover, .category-btn.active {
    background: var(--color-primary-600); border-color: var(--color-primary-600); color: #ffffff;
}
.modal-field { margin-bottom: var(--space-4, 16px); }
.modal-field label { display: block; font-size: var(--text-sm, 14px); font-weight: 500; color: var(--color-dark-800); margin-bottom: 6px; }
.modal-input {
    width: 100%; padding: 10px var(--space-3, 12px); border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px); font-size: var(--text-sm, 14px); color: var(--color-dark-800);
    background: var(--color-slate-50); transition: all var(--transition-fast, 150ms ease); box-sizing: border-box;
}
.modal-input:focus { outline: none; background: #ffffff; border-color: var(--color-primary-400); box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }
.modal-footer-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px var(--space-4, 16px);
    border-radius: var(--radius-md, 12px); font-size: var(--text-sm, 14px); font-weight: 600;
    cursor: pointer; border: 1px solid var(--color-slate-200); background: #ffffff; color: var(--color-dark-700);
    transition: all var(--transition-fast, 150ms ease);
}
.modal-footer-btn:hover { background: var(--color-slate-50); }
.modal-footer-btn-primary { background: var(--color-emerald-500); border-color: var(--color-emerald-500); color: #ffffff; }
.modal-footer-btn-primary:hover { background: var(--color-emerald-600); border-color: var(--color-emerald-600); }
/* Dark mode */
.dark .template-card { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .template-card-name { color: var(--color-slate-100); }
.dark .template-card-cat { color: var(--color-slate-400); }
.dark .template-preview { background: var(--color-dark-900); }
.dark .template-preview pre { color: var(--color-slate-300); }
.dark .template-btn { background: var(--color-dark-700); border-color: var(--color-dark-600); color: var(--color-slate-300); }
.dark .template-btn:hover { background: var(--color-dark-600); color: var(--color-primary-300); border-color: var(--color-primary-500); }
.dark .category-btn { background: var(--color-dark-800); border-color: var(--color-dark-700); color: var(--color-slate-300); }
.dark .category-btn:hover, .dark .category-btn.active { background: var(--color-primary-600); border-color: var(--color-primary-600); color: #ffffff; }
.dark .modal-input { background: var(--color-dark-900); border-color: var(--color-dark-700); color: var(--color-slate-100); }
.dark .modal-input:focus { background: var(--color-dark-800); border-color: var(--color-primary-400); }
.dark .modal-footer-btn { background: var(--color-dark-700); border-color: var(--color-dark-600); color: var(--color-slate-300); }
</style>

<?= renderPageHeader(
    'Template Library',
    'คลังเทมเพลตข้อความสำหรับส่งหาลูกค้า',
    [
        'label'   => 'เพิ่มเทมเพลต',
        'icon'    => 'fas fa-plus',
        'onclick' => 'openModal()',
        'variant' => 'success',
    ],
    [
        ['label' => 'Marketing', 'href' => null],
        ['label' => 'Template Library', 'href' => null],
    ]
) ?>

<!-- Category filter bar -->
<div class="category-filter-bar">
    <button onclick="filterCategory('', this)" class="category-btn active">ทั้งหมด</button>
    <?php foreach ($categories as $cat): if ($cat): ?>
    <button onclick="filterCategory('<?= htmlspecialchars($cat) ?>', this)" class="category-btn"><?= htmlspecialchars($cat) ?></button>
    <?php endif; endforeach; ?>
</div>

<!-- Templates grid -->
<div class="template-grid" id="templatesGrid">
    <?php foreach ($templates as $template): ?>
    <div class="template-card" data-category="<?= htmlspecialchars($template['category']) ?>">
        <div class="template-card-head">
            <div>
                <div class="template-card-name"><?= htmlspecialchars($template['name']) ?></div>
                <div class="template-card-cat"><?= $template['category'] ?: 'ไม่มีหมวดหมู่' ?></div>
            </div>
            <span class="template-type-badge <?= $template['message_type'] === 'text' ? 'template-type-text' : 'template-type-flex' ?>">
                <?= htmlspecialchars($template['message_type']) ?>
            </span>
        </div>
        <div class="template-preview">
            <pre><?= htmlspecialchars($template['content']) ?></pre>
        </div>
        <div class="template-actions">
            <button onclick="copyTemplate('<?= htmlspecialchars(addslashes($template['content'])) ?>')" class="template-btn">
                <i class="fas fa-copy"></i>คัดลอก
            </button>
            <button onclick='editTemplate(<?= json_encode($template) ?>)' class="template-btn">
                <i class="fas fa-edit"></i>แก้ไข
            </button>
            <form method="POST" class="inline" onsubmit="return confirm('ลบเทมเพลตนี้?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $template['id'] ?>">
                <button type="submit" class="template-btn template-btn-icon template-btn-danger"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($templates)): ?>
    <div style="grid-column:1/-1;">
        <?= renderEmptyState(
            'fas fa-file-alt',
            'ยังไม่มีเทมเพลต',
            'สร้างเทมเพลตแรกเพื่อเริ่มส่งข้อความ',
            ['label' => 'เพิ่มเทมเพลต', 'icon' => 'fas fa-plus', 'onclick' => 'openModal()']
        ) ?>
    </div>
    <?php endif; ?>
</div>

<?php
$modalBody = '
<div class="modal-field">
    <label>ชื่อเทมเพลต</label>
    <input type="text" name="name" id="name" required class="modal-input" placeholder="ชื่อเทมเพลต">
</div>
<div class="modal-field">
    <label>หมวดหมู่</label>
    <input type="text" name="category" id="category" class="modal-input" placeholder="เช่น ทักทาย, โปรโมชั่น, FAQ">
</div>
<div class="modal-field">
    <label>ประเภท</label>
    <select name="message_type" id="message_type" class="modal-input">
        <option value="text">Text</option>
        <option value="flex">Flex Message (JSON)</option>
    </select>
</div>
<div class="modal-field" style="margin-bottom:0">
    <label>เนื้อหา</label>
    <textarea name="content" id="content" rows="6" required class="modal-input"></textarea>
</div>';

$modalFooter = '
<button type="button" onclick="closeModalShell(\'templateModal\')" class="modal-footer-btn">ยกเลิก</button>
<button type="submit" class="modal-footer-btn modal-footer-btn-primary"><i class="fas fa-save"></i>บันทึก</button>';

echo renderModal(
    'templateModal',
    'เพิ่มเทมเพลต',
    $modalBody,
    $modalFooter,
    [
        'size'      => 'md',
        'formOpen'  => '<form method="POST"><input type="hidden" name="action" id="formAction" value="create"><input type="hidden" name="id" id="formId">',
        'formClose' => '</form>',
    ]
);
?>

<?= renderToastContainer() ?>

<script>
function openModal() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.querySelector('#templateModal .modal-shell-title').textContent = 'เพิ่มเทมเพลต';
    document.querySelector('#templateModal form').reset();
    openModalShell('templateModal');
}

function editTemplate(template) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = template.id;
    document.querySelector('#templateModal .modal-shell-title').textContent = 'แก้ไขเทมเพลต';
    document.getElementById('name').value = template.name;
    document.getElementById('category').value = template.category || '';
    document.getElementById('message_type').value = template.message_type;
    document.getElementById('content').value = template.content;
    openModalShell('templateModal');
}

function copyTemplate(content) {
    navigator.clipboard.writeText(content).then(() => fireToast('คัดลอกแล้ว!', 'success'));
}

function filterCategory(category, btn) {
    document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    document.querySelectorAll('.template-card').forEach(card => {
        card.style.display = (!category || card.dataset.category === category) ? '' : 'none';
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
