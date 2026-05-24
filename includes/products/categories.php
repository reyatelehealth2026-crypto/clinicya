<?php
/**
 * Tab 2: หมวดสินค้า (Categories)
 * Manage item_categories per tenant.
 *
 * Expects from products.php: $db (PDO), $lineAccountId, $activityLogger,
 *                            and writes to $success / $error if POST handled here.
 */
require_once __DIR__ . '/_lookup_helpers.php';

// --- POST handler (same-page AJAX, gated on X-Requested-With) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'categories'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) {
            throw new Exception('Invalid CSRF token');
        }
        $action = $_POST['action'] ?? '';
        if (!$lineAccountId) {
            throw new Exception('ยังไม่ได้เลือก LINE account');
        }
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $code  = trim((string)($_POST['cny_code'] ?? ''));
            $order = (int)($_POST['display_order'] ?? 0);
            $act   = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') throw new Exception('กรุณาระบุชื่อหมวด');
            if ($id > 0) {
                $stmt = $db->prepare(
                    'UPDATE item_categories
                        SET name = ?, cny_code = ?, display_order = ?, is_active = ?
                      WHERE id = ? AND line_account_id = ?'
                );
                $stmt->execute([$name, $code, $order, $act, $id, $lineAccountId]);
                $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, "Updated category #{$id}",
                    ['entity_type' => 'item_category', 'entity_id' => $id]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO item_categories (line_account_id, name, cny_code, display_order, is_active)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$lineAccountId, $name, $code, $order, $act]);
                $id = (int)$db->lastInsertId();
                $activityLogger->logAdmin(ActivityLogger::ACTION_CREATE, "Created category {$name}",
                    ['entity_type' => 'item_category', 'entity_id' => $id]);
            }
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM item_categories WHERE id = ? AND line_account_id = ?');
            $stmt->execute([$id, $lineAccountId]);
            $activityLogger->logAdmin(ActivityLogger::ACTION_DELETE, "Deleted category #{$id}",
                ['entity_type' => 'item_category', 'entity_id' => $id]);
            echo json_encode(['success' => true]);
            exit;
        }
        if ($action === 'reorder') {
            $order = json_decode((string)($_POST['order'] ?? '[]'), true) ?: [];
            $stmt = $db->prepare(
                'UPDATE item_categories SET display_order = ? WHERE id = ? AND line_account_id = ?'
            );
            foreach ($order as $i => $catId) {
                $stmt->execute([(int)$i, (int)$catId, $lineAccountId]);
            }
            echo json_encode(['success' => true]);
            exit;
        }
        throw new Exception('Unknown action');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// --- Render: list categories + product counts ---
$rows = [];
try {
    $stmt = $db->prepare(
        'SELECT c.id, c.name, c.cny_code, c.display_order, c.is_active,
                COUNT(bi.id) AS product_count
           FROM item_categories c
           LEFT JOIN business_items bi
             ON bi.category_id = c.id AND bi.line_account_id = c.line_account_id
          WHERE c.line_account_id = ?
          GROUP BY c.id
          ORDER BY c.display_order ASC, c.name ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดรายการหมวดสินค้าไม่สำเร็จ: ' . $e->getMessage();
}

$csrf = reya_csrf_token();
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">หมวดสินค้าทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> หมวด</p>
    <button onclick="openCatModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มหมวด
    </button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left w-12">ลำดับ</th>
                <th class="px-3 py-2 text-left">รหัส</th>
                <th class="px-3 py-2 text-left">ชื่อหมวด</th>
                <th class="px-3 py-2 text-right">จำนวนสินค้า</th>
                <th class="px-3 py-2 text-center">สถานะ</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody id="catTbody">
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="px-3 py-6 text-center text-slate-400">ยังไม่มีหมวดสินค้า — เริ่มเพิ่มหมวดแรกเลย</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100" data-id="<?= (int)$r['id'] ?>">
                <td class="px-3 py-2 text-slate-500"><?= (int)$r['display_order'] ?></td>
                <td class="px-3 py-2 font-mono text-xs"><?= reya_h($r['cny_code']) ?></td>
                <td class="px-3 py-2"><?= reya_h($r['name']) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= (int)$r['product_count'] ?></td>
                <td class="px-3 py-2 text-center"><?= reya_status_pill((bool)$r['is_active']) ?></td>
                <td class="px-3 py-2 text-right">
                    <button class="text-indigo-600 hover:underline mr-2" onclick='editCat(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                    <button class="text-rose-600 hover:underline" onclick="deleteCat(<?= (int)$r['id'] ?>)">ลบ</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="catModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="catForm" class="bg-white rounded-xl w-full max-w-md p-6 space-y-3" onsubmit="return saveCat(event)">
        <h3 class="text-lg font-semibold" id="catModalTitle">เพิ่มหมวดสินค้า</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="categories">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="catId" value="">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อหมวด <span class="text-rose-500">*</span></label>
            <input name="name" id="catName" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">รหัสหมวด</label>
            <input name="cny_code" id="catCode" class="w-full border border-slate-300 rounded-lg px-3 py-2">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ลำดับ</label>
                <input type="number" name="display_order" id="catOrder" value="0" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <label class="flex items-end gap-2 text-sm pb-2">
                <input type="checkbox" name="is_active" id="catActive" checked> ใช้งาน
            </label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeCatModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
function openCatModal(){
    document.getElementById('catModalTitle').textContent = 'เพิ่มหมวดสินค้า';
    document.getElementById('catForm').reset();
    document.getElementById('catId').value = '';
    document.getElementById('catActive').checked = true;
    document.getElementById('catModal').classList.remove('hidden');
    document.getElementById('catModal').classList.add('flex');
}
function closeCatModal(){
    document.getElementById('catModal').classList.add('hidden');
    document.getElementById('catModal').classList.remove('flex');
}
function editCat(r){
    document.getElementById('catModalTitle').textContent = 'แก้ไขหมวดสินค้า';
    document.getElementById('catId').value = r.id;
    document.getElementById('catName').value = r.name || '';
    document.getElementById('catCode').value = r.cny_code || '';
    document.getElementById('catOrder').value = r.display_order || 0;
    document.getElementById('catActive').checked = !!Number(r.is_active);
    document.getElementById('catModal').classList.remove('hidden');
    document.getElementById('catModal').classList.add('flex');
}
async function saveCat(ev){
    ev.preventDefault();
    const form = ev.target;
    const data = new FormData(form);
    const r = await fetch(location.href, {method:'POST', headers:{'X-Requested-With':'fetch'}, body:data});
    const j = await r.json();
    if (j.success){ location.href = location.pathname + '?tab=categories&success=' + (data.get('id') ? 'updated' : 'created'); }
    else if (typeof fireToast === 'function'){ fireToast(j.error || 'บันทึกไม่สำเร็จ','error'); }
    return false;
}
async function deleteCat(id){
    if (!confirm('ลบหมวดนี้?')) return;
    const data = new FormData();
    data.set('_csrf', <?= json_encode($csrf) ?>);
    data.set('_form', 'categories');
    data.set('action', 'delete');
    data.set('id', id);
    const r = await fetch(location.href, {method:'POST', headers:{'X-Requested-With':'fetch'}, body:data});
    const j = await r.json();
    if (j.success){ location.href = location.pathname + '?tab=categories&success=deleted'; }
    else if (typeof fireToast === 'function'){ fireToast(j.error || 'ลบไม่สำเร็จ','error'); }
}
</script>
