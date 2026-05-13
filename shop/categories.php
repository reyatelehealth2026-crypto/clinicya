<?php
/**
 * Shop Categories V4.0 - Compact & Simple
 * ไม่มีรูปภาพ, ใช้งานง่าย, กระทัดรัด
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
if (file_exists(__DIR__ . '/../classes/UnifiedShop.php')) {
    require_once __DIR__ . '/../classes/UnifiedShop.php';
}
require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/components/toolbar.php';
require_once __DIR__ . '/../includes/components/data-table.php';
require_once __DIR__ . '/../includes/components/empty-state.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_once __DIR__ . '/../includes/components/toast.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'หมวดหมู่สินค้า';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Initialize UnifiedShop
$shop = new UnifiedShop($db, null, $currentBotId);
$categoriesTable = $shop->getCategoriesTable() ?? 'product_categories';
$productsTable = $shop->getItemsTable() ?? 'products';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $stmt = $db->prepare("INSERT INTO {$categoriesTable} (name, sort_order, is_active) VALUES (?, ?, 1)");
        $stmt->execute([trim($_POST['name']), intval($_POST['sort_order'] ?? 0)]);
    } elseif ($action === 'update') {
        $stmt = $db->prepare("UPDATE {$categoriesTable} SET name = ?, sort_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([trim($_POST['name']), intval($_POST['sort_order']), isset($_POST['is_active']) ? 1 : 0, $_POST['id']]);
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM {$categoriesTable} WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        // Clear category from products
        $stmt = $db->prepare("UPDATE {$productsTable} SET category_id = NULL WHERE category_id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($action === 'toggle') {
        $stmt = $db->prepare("UPDATE {$categoriesTable} SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($action === 'reorder') {
        $orders = json_decode($_POST['orders'], true);
        foreach ($orders as $id => $order) {
            $stmt = $db->prepare("UPDATE {$categoriesTable} SET sort_order = ? WHERE id = ?");
            $stmt->execute([$order, $id]);
        }
    }

    header('Location: categories.php');
    exit;
}

// Get categories with product count (shared across all LINE accounts)
$categories = [];
try {
    $stmt = $db->query("
        SELECT c.*, COALESCE(COUNT(p.id), 0) as product_count
        FROM {$categoriesTable} c
        LEFT JOIN {$productsTable} p ON c.id = p.category_id AND p.is_active = 1
        GROUP BY c.id
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$totalProducts = array_sum(array_column($categories, 'product_count'));
$activeCount = count(array_filter($categories, fn($c) => $c['is_active']));

require_once '../includes/header.php';
?>

<?= getPageHeaderStyles() ?>
<?= getToolbarStyles() ?>
<?= getDataTableStyles() ?>
<?= getEmptyStateStyles() ?>
<?= getModalStyles() ?>
<?= getToastStyles() ?>

<style>
.cat-row.inactive { opacity: 0.5; }
.drag-handle { cursor: grab; color: var(--color-slate-300); }
.drag-handle:active { cursor: grabbing; }
</style>

<?php
echo renderPageHeader(
    'หมวดหมู่สินค้า',
    count($categories) . ' หมวดหมู่ · ' . $activeCount . ' เปิดใช้ · ' . number_format($totalProducts) . ' สินค้า',
    ['label' => 'เพิ่มหมวดหมู่', 'icon' => 'fas fa-plus', 'onclick' => 'openAddModal()', 'variant' => 'success'],
    [['label' => 'ร้านค้า', 'href' => null], ['label' => 'หมวดหมู่สินค้า', 'href' => null]]
);

echo renderToolbar([
    'chips' => [
        ['href' => '../sync_categories_from_manufacturer.php', 'icon' => 'fas fa-magic', 'label' => 'สร้างจากผู้ผลิต', 'tone' => 'primary'],
    ],
]);

// Build table columns
$columns = [
    [
        'key'    => 'drag',
        'label'  => '',
        'width'  => '40px',
        'align'  => 'center',
        'render' => fn($row) => '<span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>',
    ],
    [
        'key'    => 'name',
        'label'  => 'ชื่อหมวดหมู่',
        'align'  => 'left',
        'render' => function ($cat) {
            $initial  = mb_substr($cat['name'], 0, 1);
            $nameEsc  = htmlspecialchars($cat['name']);
            $codeHtml = !empty($cat['manufacturer_code'])
                ? '<div style="font-size:var(--text-xs);color:var(--color-dark-500);">รหัส: ' . htmlspecialchars($cat['manufacturer_code']) . '</div>'
                : '';
            return '<div style="display:flex;align-items:center;gap:12px;">
                <div style="width:32px;height:32px;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--color-emerald-400),var(--color-emerald-600));display:flex;align-items:center;justify-content:center;color:#fff;font-size:var(--text-sm);font-weight:700;flex-shrink:0;">' . $initial . '</div>
                <div>
                    <div style="font-weight:500;color:var(--color-dark-800);">' . $nameEsc . '</div>
                    ' . $codeHtml . '
                </div>
            </div>';
        },
    ],
    [
        'key'    => 'product_count',
        'label'  => 'สินค้า',
        'align'  => 'center',
        'render' => fn($cat) => '<a href="products.php?category=' . (int)$cat['id'] . '" class="toolbar-chip toolbar-chip-primary" style="display:inline-flex;">' . number_format($cat['product_count']) . '</a>',
    ],
    [
        'key'    => 'sort_order',
        'label'  => 'ลำดับ',
        'align'  => 'center',
        'render' => fn($cat) => '<span style="color:var(--color-dark-500);font-size:var(--text-sm);">' . (int)$cat['sort_order'] . '</span>',
    ],
    [
        'key'    => 'is_active',
        'label'  => 'สถานะ',
        'align'  => 'center',
        'render' => function ($cat) {
            $tone  = $cat['is_active'] ? 'toolbar-chip-success' : 'toolbar-chip-neutral';
            $label = $cat['is_active'] ? 'เปิด' : 'ปิด';
            return '<form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="' . (int)$cat['id'] . '">
                <button type="submit" class="toolbar-chip ' . $tone . '" style="border:none;cursor:pointer;">' . $label . '</button>
            </form>';
        },
    ],
    [
        'key'    => 'actions',
        'label'  => 'จัดการ',
        'align'  => 'center',
        'render' => function ($cat) {
            $catJson = htmlspecialchars(json_encode($cat, JSON_HEX_APOS | JSON_HEX_QUOT));
            $nameEsc = htmlspecialchars(addslashes($cat['name']));
            return '<div class="data-table-row-actions">
                <button onclick=\'openEditModal(' . $catJson . ')\' class="data-table-row-action" title="แก้ไข"><i class="fas fa-edit"></i></button>
                <button onclick="deleteCategory(' . (int)$cat['id'] . ', \'' . $nameEsc . '\')" class="data-table-row-action data-table-row-action-danger" title="ลบ"><i class="fas fa-trash"></i></button>
            </div>';
        },
    ],
];

$emptyHtml = renderEmptyState(
    'fas fa-folder-open',
    'ยังไม่มีหมวดหมู่',
    'เพิ่มหมวดหมู่แรกเพื่อจัดกลุ่มสินค้า',
    ['label' => 'เพิ่มหมวดหมู่แรก', 'icon' => 'fas fa-plus', 'onclick' => 'openAddModal()']
);

echo renderDataTable($columns, $categories, [
    'emptyContent' => $emptyHtml,
    'rowKey'       => 'id',
    'rowClass'     => fn($row) => 'cat-row' . (!$row['is_active'] ? ' inactive' : ''),
]);
?>

<?php
// ── Add / Edit Modal ──────────────────────────────────────────────
$modalBody = '
<input type="hidden" name="action" id="formAction" value="create">
<input type="hidden" name="id" id="catId" value="">
<div style="display:flex;flex-direction:column;gap:var(--space-4);">
    <div>
        <label style="display:block;font-size:var(--text-sm);font-weight:500;margin-bottom:var(--space-1);color:var(--color-dark-800);">ชื่อหมวดหมู่ *</label>
        <input type="text" name="name" id="catName" required
               style="width:100%;height:40px;padding:0 12px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md);background:var(--color-slate-50);font-size:var(--text-sm);color:var(--color-dark-800);box-sizing:border-box;">
    </div>
    <div>
        <label style="display:block;font-size:var(--text-sm);font-weight:500;margin-bottom:var(--space-1);color:var(--color-dark-800);">ลำดับ</label>
        <input type="number" name="sort_order" id="catSort" value="0" min="0"
               style="width:100%;height:40px;padding:0 12px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md);background:var(--color-slate-50);font-size:var(--text-sm);color:var(--color-dark-800);box-sizing:border-box;">
    </div>
    <div id="activeField" style="display:none;">
        <label style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--color-slate-50);border-radius:var(--radius-md);cursor:pointer;">
            <input type="checkbox" name="is_active" id="catActive" checked
                   style="width:16px;height:16px;accent-color:var(--color-emerald-500);">
            <span style="font-size:var(--text-sm);color:var(--color-dark-800);">เปิดใช้งาน</span>
        </label>
    </div>
</div>';

$modalFooter = '
<button type="button" data-modal-close="catModal"
        style="padding:10px var(--space-4);border:1px solid var(--color-slate-200);border-radius:var(--radius-md);background:#fff;color:var(--color-dark-800);font-size:var(--text-sm);font-weight:600;cursor:pointer;">ยกเลิก</button>
<button type="submit"
        style="padding:10px var(--space-4);border:none;border-radius:var(--radius-md);background:var(--color-emerald-500);color:#fff;font-size:var(--text-sm);font-weight:600;cursor:pointer;">บันทึก</button>';

echo renderModal('catModal', '<span id="modalTitle">เพิ่มหมวดหมู่</span>', $modalBody, $modalFooter, [
    'size'      => 'sm',
    'formOpen'  => '<form method="POST">',
    'formClose' => '</form>',
]);

// ── Delete Confirm Modal ──────────────────────────────────────────
$deleteBody = '
<div style="text-align:center;padding:var(--space-2) 0;">
    <div style="width:56px;height:56px;border-radius:var(--radius-full);background:var(--color-rose-50);display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-3);">
        <i class="fas fa-trash" style="font-size:22px;color:var(--color-rose-600);"></i>
    </div>
    <p id="deleteText" style="color:var(--color-dark-500);font-size:var(--text-sm);margin:0;"></p>
</div>';

$deleteFooter = '
<form method="POST" style="display:flex;gap:var(--space-2);width:100%;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
    <button type="button" data-modal-close="deleteModal"
            style="flex:1;padding:10px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md);background:#fff;font-size:var(--text-sm);font-weight:600;cursor:pointer;">ยกเลิก</button>
    <button type="submit"
            style="flex:1;padding:10px;border:none;border-radius:var(--radius-md);background:var(--color-rose-600);color:#fff;font-size:var(--text-sm);font-weight:600;cursor:pointer;">ลบ</button>
</form>';

echo renderModal('deleteModal', 'ยืนยันการลบ', $deleteBody, $deleteFooter, ['size' => 'sm']);

echo renderToastContainer();
?>

<script>
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'เพิ่มหมวดหมู่';
    document.getElementById('formAction').value = 'create';
    document.getElementById('catId').value = '';
    document.getElementById('catName').value = '';
    document.getElementById('catSort').value = '0';
    document.getElementById('activeField').style.display = 'none';
    openModalShell('catModal');
}

function openEditModal(cat) {
    document.getElementById('modalTitle').textContent = 'แก้ไขหมวดหมู่';
    document.getElementById('formAction').value = 'update';
    document.getElementById('catId').value = cat.id;
    document.getElementById('catName').value = cat.name;
    document.getElementById('catSort').value = cat.sort_order || 0;
    document.getElementById('catActive').checked = cat.is_active == 1;
    document.getElementById('activeField').style.display = 'block';
    openModalShell('catModal');
}

function deleteCategory(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteText').textContent = 'ลบ "' + name + '" ?';
    openModalShell('deleteModal');
}
</script>

<?php require_once '../includes/footer.php'; ?>
