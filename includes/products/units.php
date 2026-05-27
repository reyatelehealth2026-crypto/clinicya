<?php
/**
 * Tab 5: หน่วยสินค้า (Product Units)
 * Self-referential conversion: e.g. กล่อง = 10 × แผง, แผง = 10 × เม็ด.
 */
require_once __DIR__ . '/_lookup_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'product_units'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) throw new Exception('Invalid CSRF token');
        if (!$lineAccountId) throw new Exception('ยังไม่ได้เลือก LINE account');
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id      = (int)($_POST['id'] ?? 0);
            $code    = trim((string)($_POST['code'] ?? ''));
            $name    = trim((string)($_POST['name'] ?? ''));
            $name_en = trim((string)($_POST['name_en'] ?? ''));
            $sub     = $_POST['sub_unit_id'] !== '' ? (int)$_POST['sub_unit_id'] : null;
            $ratio   = (float)($_POST['conversion_ratio'] ?? 1);
            $base    = isset($_POST['is_base_unit']) ? 1 : 0;
            $act     = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') throw new Exception('กรุณาระบุชื่อหน่วย');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE product_units SET code=?, name=?, name_en=?, sub_unit_id=?, conversion_ratio=?, is_base_unit=?, is_active=? WHERE id=? AND line_account_id=?');
                $stmt->execute([$code, $name, $name_en, $sub, $ratio, $base, $act, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare('INSERT INTO product_units (line_account_id, code, name, name_en, sub_unit_id, conversion_ratio, is_base_unit, is_active) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$lineAccountId, $code, $name, $name_en, $sub, $ratio, $base, $act]);
                $id = (int)$db->lastInsertId();
            }
            $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved product unit #{$id}", ['entity_type' => 'product_unit', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM product_units WHERE id=? AND line_account_id=?');
            $stmt->execute([$id, $lineAccountId]);
            echo json_encode(['success' => true]);
            exit;
        }
        throw new Exception('Unknown action');
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$rows = [];
try {
    $stmt = $db->prepare(
        'SELECT u.*, sub.name AS sub_name
           FROM product_units u
           LEFT JOIN product_units sub ON sub.id = u.sub_unit_id
          WHERE u.line_account_id = ?
          ORDER BY u.is_base_unit DESC, u.name ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดหน่วยสินค้าไม่สำเร็จ: ' . $e->getMessage();
}
$csrf = reya_csrf_token();
$unitOptions = $rows;
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">หน่วยสินค้าทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> หน่วย</p>
    <button onclick="openPuModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มหน่วย
    </button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left">รหัส</th>
                <th class="px-3 py-2 text-left">ชื่อหน่วย</th>
                <th class="px-3 py-2 text-left">EN</th>
                <th class="px-3 py-2 text-left">หน่วยย่อย</th>
                <th class="px-3 py-2 text-right">อัตราแปลง</th>
                <th class="px-3 py-2 text-center">หน่วยฐาน</th>
                <th class="px-3 py-2 text-center">สถานะ</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="px-3 py-6 text-center text-slate-400">ยังไม่มีหน่วยสินค้า</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100">
                <td class="px-3 py-2 font-mono text-xs"><?= reya_h($r['code']) ?></td>
                <td class="px-3 py-2 font-medium"><?= reya_h($r['name']) ?></td>
                <td class="px-3 py-2 text-slate-500"><?= reya_h($r['name_en']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['sub_name'] ?? '—') ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= rtrim(rtrim(number_format((float)$r['conversion_ratio'], 4), '0'), '.') ?></td>
                <td class="px-3 py-2 text-center"><?= $r['is_base_unit'] ? '<i class="fas fa-check text-emerald-600"></i>' : '' ?></td>
                <td class="px-3 py-2 text-center"><?= reya_status_pill((bool)$r['is_active']) ?></td>
                <td class="px-3 py-2 text-right">
                    <button class="text-indigo-600 hover:underline mr-2" onclick='editPu(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                    <button class="text-rose-600 hover:underline" onclick="deletePu(<?= (int)$r['id'] ?>)">ลบ</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="puModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="puForm" class="bg-white rounded-xl w-full max-w-md p-6 space-y-3" onsubmit="return savePu(event)">
        <h3 class="text-lg font-semibold" id="puModalTitle">เพิ่มหน่วยสินค้า</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="product_units">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="puId" value="">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">รหัส</label>
                <input name="code" id="puCode" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <label class="flex items-end gap-2 text-sm pb-2">
                <input type="checkbox" name="is_active" id="puActive" checked> ใช้งาน
            </label>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อ (TH) <span class="text-rose-500">*</span></label>
                <input name="name" id="puName" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อ (EN)</label>
                <input name="name_en" id="puNameEn" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">หน่วยย่อย</label>
                <select name="sub_unit_id" id="puSubUnit" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                    <option value="">— ไม่มี —</option>
                    <?php foreach ($unitOptions as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= reya_h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">อัตราแปลง (1 หน่วยนี้ = ?)</label>
                <input type="number" step="0.0001" name="conversion_ratio" id="puRatio" value="1" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_base_unit" id="puBase"> หน่วยฐาน (เล็กที่สุด)
        </label>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closePuModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
function openPuModal(){ const m=document.getElementById('puModal'); document.getElementById('puForm').reset(); document.getElementById('puId').value=''; document.getElementById('puActive').checked=true; document.getElementById('puRatio').value=1; document.getElementById('puModalTitle').textContent='เพิ่มหน่วยสินค้า'; m.classList.remove('hidden'); m.classList.add('flex'); }
function closePuModal(){ const m=document.getElementById('puModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function editPu(r){ document.getElementById('puId').value=r.id; document.getElementById('puCode').value=r.code||''; document.getElementById('puName').value=r.name||''; document.getElementById('puNameEn').value=r.name_en||''; document.getElementById('puSubUnit').value=r.sub_unit_id||''; document.getElementById('puRatio').value=r.conversion_ratio||1; document.getElementById('puBase').checked=!!Number(r.is_base_unit); document.getElementById('puActive').checked=!!Number(r.is_active); document.getElementById('puModalTitle').textContent='แก้ไขหน่วยสินค้า'; const m=document.getElementById('puModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
async function savePu(ev){ ev.preventDefault(); const data=new FormData(ev.target); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=units&success='+(data.get('id')?'updated':'created'); } else if(typeof fireToast==='function'){ fireToast(j.error||'บันทึกไม่สำเร็จ','error'); } return false; }
async function deletePu(id){ if(!confirm('ลบหน่วยนี้?')) return; const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','product_units'); data.set('action','delete'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=units&success=deleted'; } else if(typeof fireToast==='function'){ fireToast(j.error||'ลบไม่สำเร็จ','error'); } }
</script>
