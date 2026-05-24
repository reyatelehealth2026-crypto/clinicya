<?php
/**
 * Tab 3: กลุ่มยา (Drug Groups)
 * CRUD for drug_groups per tenant.
 */
require_once __DIR__ . '/_lookup_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'drug_groups'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) throw new Exception('Invalid CSRF token');
        if (!$lineAccountId) throw new Exception('ยังไม่ได้เลือก LINE account');
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id      = (int)($_POST['id'] ?? 0);
            $code    = trim((string)($_POST['code'] ?? ''));
            $name_th = trim((string)($_POST['name_th'] ?? ''));
            $name_en = trim((string)($_POST['name_en'] ?? ''));
            $desc    = trim((string)($_POST['description'] ?? ''));
            $act     = isset($_POST['is_active']) ? 1 : 0;
            if ($name_th === '') throw new Exception('กรุณาระบุชื่อกลุ่ม (ไทย)');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE drug_groups SET code=?, name_th=?, name_en=?, description=?, is_active=? WHERE id=? AND line_account_id=?');
                $stmt->execute([$code, $name_th, $name_en, $desc, $act, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare('INSERT INTO drug_groups (line_account_id, code, name_th, name_en, description, is_active) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$lineAccountId, $code, $name_th, $name_en, $desc, $act]);
                $id = (int)$db->lastInsertId();
            }
            $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved drug group #{$id}", ['entity_type' => 'drug_group', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM drug_groups WHERE id=? AND line_account_id=?');
            $stmt->execute([$id, $lineAccountId]);
            $activityLogger->logAdmin(ActivityLogger::ACTION_DELETE, "Deleted drug group #{$id}", ['entity_type' => 'drug_group', 'entity_id' => $id]);
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
        'SELECT g.*, COUNT(bi.id) AS product_count
           FROM drug_groups g
           LEFT JOIN business_items bi
             ON bi.drug_group_id = g.id AND bi.line_account_id = g.line_account_id
          WHERE g.line_account_id = ?
          GROUP BY g.id
          ORDER BY g.name_th ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดกลุ่มยาไม่สำเร็จ: ' . $e->getMessage();
}
$csrf = reya_csrf_token();
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">กลุ่มยาทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> กลุ่ม</p>
    <button onclick="openDgModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มกลุ่มยา
    </button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left">รหัส</th>
                <th class="px-3 py-2 text-left">ชื่อ (TH)</th>
                <th class="px-3 py-2 text-left">ชื่อ (EN)</th>
                <th class="px-3 py-2 text-left">คำอธิบาย</th>
                <th class="px-3 py-2 text-right">จำนวนสินค้า</th>
                <th class="px-3 py-2 text-center">สถานะ</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">ยังไม่มีกลุ่มยา</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100">
                <td class="px-3 py-2 font-mono text-xs"><?= reya_h($r['code']) ?></td>
                <td class="px-3 py-2"><?= reya_h($r['name_th']) ?></td>
                <td class="px-3 py-2 text-slate-500"><?= reya_h($r['name_en']) ?></td>
                <td class="px-3 py-2 text-slate-500 max-w-md truncate" title="<?= reya_h($r['description']) ?>"><?= reya_h($r['description']) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= (int)$r['product_count'] ?></td>
                <td class="px-3 py-2 text-center"><?= reya_status_pill((bool)$r['is_active']) ?></td>
                <td class="px-3 py-2 text-right">
                    <button class="text-indigo-600 hover:underline mr-2" onclick='editDg(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                    <button class="text-rose-600 hover:underline" onclick="deleteDg(<?= (int)$r['id'] ?>)">ลบ</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="dgModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="dgForm" class="bg-white rounded-xl w-full max-w-md p-6 space-y-3" onsubmit="return saveDg(event)">
        <h3 class="text-lg font-semibold" id="dgModalTitle">เพิ่มกลุ่มยา</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="drug_groups">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="dgId" value="">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">รหัสกลุ่ม</label>
                <input name="code" id="dgCode" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <label class="flex items-end gap-2 text-sm pb-2">
                <input type="checkbox" name="is_active" id="dgActive" checked> ใช้งาน
            </label>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อกลุ่ม (TH) <span class="text-rose-500">*</span></label>
            <input name="name_th" id="dgNameTh" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อกลุ่ม (EN)</label>
            <input name="name_en" id="dgNameEn" class="w-full border border-slate-300 rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">คำอธิบาย</label>
            <textarea name="description" id="dgDesc" rows="3" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeDgModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
function openDgModal(){ const m=document.getElementById('dgModal'); document.getElementById('dgForm').reset(); document.getElementById('dgId').value=''; document.getElementById('dgActive').checked=true; document.getElementById('dgModalTitle').textContent='เพิ่มกลุ่มยา'; m.classList.remove('hidden'); m.classList.add('flex'); }
function closeDgModal(){ const m=document.getElementById('dgModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function editDg(r){ document.getElementById('dgId').value=r.id; document.getElementById('dgCode').value=r.code||''; document.getElementById('dgNameTh').value=r.name_th||''; document.getElementById('dgNameEn').value=r.name_en||''; document.getElementById('dgDesc').value=r.description||''; document.getElementById('dgActive').checked=!!Number(r.is_active); document.getElementById('dgModalTitle').textContent='แก้ไขกลุ่มยา'; const m=document.getElementById('dgModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
async function saveDg(ev){ ev.preventDefault(); const data=new FormData(ev.target); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=drug-groups&success='+(data.get('id')?'updated':'created'); } else if(typeof fireToast==='function'){ fireToast(j.error||'บันทึกไม่สำเร็จ','error'); } return false; }
async function deleteDg(id){ if(!confirm('ลบกลุ่มยานี้?')) return; const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','drug_groups'); data.set('action','delete'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=drug-groups&success=deleted'; } else if(typeof fireToast==='function'){ fireToast(j.error||'ลบไม่สำเร็จ','error'); } }
</script>
