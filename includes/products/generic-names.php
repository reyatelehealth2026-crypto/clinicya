<?php
/**
 * Tab 4: ชื่อทางการ (Generic Names)
 */
require_once __DIR__ . '/_lookup_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'generic_names'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) throw new Exception('Invalid CSRF token');
        if (!$lineAccountId) throw new Exception('ยังไม่ได้เลือก LINE account');
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['generic_name'] ?? ''));
            $atc  = trim((string)($_POST['atc_code'] ?? ''));
            $form = trim((string)($_POST['default_dosage_form'] ?? ''));
            $unit = trim((string)($_POST['default_unit'] ?? ''));
            $warn = trim((string)($_POST['default_warnings'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            if ($name === '') throw new Exception('กรุณาระบุชื่อทางการ');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE generic_names SET generic_name=?, atc_code=?, default_dosage_form=?, default_unit=?, default_warnings=?, description=? WHERE id=? AND line_account_id=?');
                $stmt->execute([$name, $atc, $form, $unit, $warn, $desc, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare('INSERT INTO generic_names (line_account_id, generic_name, atc_code, default_dosage_form, default_unit, default_warnings, description) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$lineAccountId, $name, $atc, $form, $unit, $warn, $desc]);
                $id = (int)$db->lastInsertId();
            }
            $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved generic name #{$id}", ['entity_type' => 'generic_name', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM generic_names WHERE id=? AND line_account_id=?');
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
        'SELECT g.*, COUNT(bi.id) AS product_count
           FROM generic_names g
           LEFT JOIN business_items bi
             ON bi.generic_name_id = g.id AND bi.line_account_id = g.line_account_id
          WHERE g.line_account_id = ?
          GROUP BY g.id
          ORDER BY g.generic_name ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดชื่อทางการไม่สำเร็จ: ' . $e->getMessage();
}
$csrf = reya_csrf_token();
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">ชื่อทางการทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> รายการ</p>
    <button onclick="openGnModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มชื่อทางการ
    </button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left">ชื่อทางการ (Generic)</th>
                <th class="px-3 py-2 text-left">ATC</th>
                <th class="px-3 py-2 text-left">รูปแบบยา</th>
                <th class="px-3 py-2 text-left">หน่วย</th>
                <th class="px-3 py-2 text-right">สินค้าที่ใช้</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="px-3 py-6 text-center text-slate-400">ยังไม่มีชื่อทางการ</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100">
                <td class="px-3 py-2 font-medium"><?= reya_h($r['generic_name']) ?></td>
                <td class="px-3 py-2 font-mono text-xs"><?= reya_h($r['atc_code']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['default_dosage_form']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['default_unit']) ?></td>
                <td class="px-3 py-2 text-right tabular-nums">
                    <?php if ((int)$r['product_count'] > 0): ?>
                        <a class="text-indigo-600 hover:underline" href="?tab=list&generic_id=<?= (int)$r['id'] ?>"><?= (int)$r['product_count'] ?></a>
                    <?php else: ?>
                        0
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right">
                    <button class="text-indigo-600 hover:underline mr-2" onclick='editGn(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                    <button class="text-rose-600 hover:underline" onclick="deleteGn(<?= (int)$r['id'] ?>)">ลบ</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="gnModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="gnForm" class="bg-white rounded-xl w-full max-w-lg p-6 space-y-3" onsubmit="return saveGn(event)">
        <h3 class="text-lg font-semibold" id="gnModalTitle">เพิ่มชื่อทางการ</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="generic_names">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="gnId" value="">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อทางการ (Generic name) <span class="text-rose-500">*</span></label>
            <input name="generic_name" id="gnName" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">รหัส ATC</label>
                <input name="atc_code" id="gnAtc" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">รูปแบบยาเริ่มต้น</label>
                <input name="default_dosage_form" id="gnDosage" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">หน่วยเริ่มต้น</label>
            <input name="default_unit" id="gnUnit" class="w-full border border-slate-300 rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">คำเตือนเริ่มต้น</label>
            <textarea name="default_warnings" id="gnWarn" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">รายละเอียด</label>
            <textarea name="description" id="gnDesc" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeGnModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
function openGnModal(){ const m=document.getElementById('gnModal'); document.getElementById('gnForm').reset(); document.getElementById('gnId').value=''; document.getElementById('gnModalTitle').textContent='เพิ่มชื่อทางการ'; m.classList.remove('hidden'); m.classList.add('flex'); }
function closeGnModal(){ const m=document.getElementById('gnModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function editGn(r){ document.getElementById('gnId').value=r.id; document.getElementById('gnName').value=r.generic_name||''; document.getElementById('gnAtc').value=r.atc_code||''; document.getElementById('gnDosage').value=r.default_dosage_form||''; document.getElementById('gnUnit').value=r.default_unit||''; document.getElementById('gnWarn').value=r.default_warnings||''; document.getElementById('gnDesc').value=r.description||''; document.getElementById('gnModalTitle').textContent='แก้ไขชื่อทางการ'; const m=document.getElementById('gnModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
async function saveGn(ev){ ev.preventDefault(); const data=new FormData(ev.target); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=generic-names&success='+(data.get('id')?'updated':'created'); } else if(typeof fireToast==='function'){ fireToast(j.error||'บันทึกไม่สำเร็จ','error'); } return false; }
async function deleteGn(id){ if(!confirm('ลบรายการนี้?')) return; const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','generic_names'); data.set('action','delete'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=generic-names&success=deleted'; } else if(typeof fireToast==='function'){ fireToast(j.error||'ลบไม่สำเร็จ','error'); } }
</script>
