<?php
/**
 * Tab 6: พื้นที่เก็บ (Storage Locations)
 */
require_once __DIR__ . '/_lookup_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'storage_locations'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) throw new Exception('Invalid CSRF token');
        if (!$lineAccountId) throw new Exception('ยังไม่ได้เลือก LINE account');
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $code  = trim((string)($_POST['code'] ?? ''));
            $name  = trim((string)($_POST['name'] ?? ''));
            $temp  = trim((string)($_POST['temperature_range'] ?? ''));
            $hum   = trim((string)($_POST['humidity_range'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $act   = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') throw new Exception('กรุณาระบุชื่อพื้นที่');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE storage_locations SET code=?, name=?, temperature_range=?, humidity_range=?, notes=?, is_active=? WHERE id=? AND line_account_id=?');
                $stmt->execute([$code, $name, $temp, $hum, $notes, $act, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare('INSERT INTO storage_locations (line_account_id, code, name, temperature_range, humidity_range, notes, is_active) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$lineAccountId, $code, $name, $temp, $hum, $notes, $act]);
                $id = (int)$db->lastInsertId();
            }
            $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved storage location #{$id}", ['entity_type' => 'storage_location', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM storage_locations WHERE id=? AND line_account_id=?');
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
        'SELECT s.*, COUNT(bi.id) AS product_count
           FROM storage_locations s
           LEFT JOIN business_items bi
             ON bi.storage_location_id = s.id AND bi.line_account_id = s.line_account_id
          WHERE s.line_account_id = ?
          GROUP BY s.id
          ORDER BY s.code ASC, s.name ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดพื้นที่เก็บไม่สำเร็จ: ' . $e->getMessage();
}
$csrf = reya_csrf_token();
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">พื้นที่เก็บทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> พื้นที่</p>
    <button onclick="openSlModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มพื้นที่เก็บ
    </button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left">รหัส</th>
                <th class="px-3 py-2 text-left">ชื่อ</th>
                <th class="px-3 py-2 text-left">อุณหภูมิ</th>
                <th class="px-3 py-2 text-left">ความชื้น</th>
                <th class="px-3 py-2 text-right">สินค้า</th>
                <th class="px-3 py-2 text-center">สถานะ</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">ยังไม่มีพื้นที่เก็บ</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100">
                <td class="px-3 py-2 font-mono text-xs"><?= reya_h($r['code']) ?></td>
                <td class="px-3 py-2 font-medium"><?= reya_h($r['name']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['temperature_range']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['humidity_range']) ?></td>
                <td class="px-3 py-2 text-right tabular-nums"><?= (int)$r['product_count'] ?></td>
                <td class="px-3 py-2 text-center"><?= reya_status_pill((bool)$r['is_active']) ?></td>
                <td class="px-3 py-2 text-right">
                    <button class="text-indigo-600 hover:underline mr-2" onclick='editSl(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                    <button class="text-rose-600 hover:underline" onclick="deleteSl(<?= (int)$r['id'] ?>)">ลบ</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="slModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="slForm" class="bg-white rounded-xl w-full max-w-md p-6 space-y-3" onsubmit="return saveSl(event)">
        <h3 class="text-lg font-semibold" id="slModalTitle">เพิ่มพื้นที่เก็บ</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="storage_locations">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="slId" value="">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">รหัส</label>
                <input name="code" id="slCode" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <label class="flex items-end gap-2 text-sm pb-2">
                <input type="checkbox" name="is_active" id="slActive" checked> ใช้งาน
            </label>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อพื้นที่ <span class="text-rose-500">*</span></label>
            <input name="name" id="slName" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ช่วงอุณหภูมิ</label>
                <input name="temperature_range" id="slTemp" placeholder="2-8°C" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ความชื้น</label>
                <input name="humidity_range" id="slHum" placeholder="<60%" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">หมายเหตุ</label>
            <textarea name="notes" id="slNotes" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeSlModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
function openSlModal(){ const m=document.getElementById('slModal'); document.getElementById('slForm').reset(); document.getElementById('slId').value=''; document.getElementById('slActive').checked=true; document.getElementById('slModalTitle').textContent='เพิ่มพื้นที่เก็บ'; m.classList.remove('hidden'); m.classList.add('flex'); }
function closeSlModal(){ const m=document.getElementById('slModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function editSl(r){ document.getElementById('slId').value=r.id; document.getElementById('slCode').value=r.code||''; document.getElementById('slName').value=r.name||''; document.getElementById('slTemp').value=r.temperature_range||''; document.getElementById('slHum').value=r.humidity_range||''; document.getElementById('slNotes').value=r.notes||''; document.getElementById('slActive').checked=!!Number(r.is_active); document.getElementById('slModalTitle').textContent='แก้ไขพื้นที่เก็บ'; const m=document.getElementById('slModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
async function saveSl(ev){ ev.preventDefault(); const data=new FormData(ev.target); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=storage-locations&success='+(data.get('id')?'updated':'created'); } else if(typeof fireToast==='function'){ fireToast(j.error||'บันทึกไม่สำเร็จ','error'); } return false; }
async function deleteSl(id){ if(!confirm('ลบพื้นที่นี้?')) return; const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','storage_locations'); data.set('action','delete'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=storage-locations&success=deleted'; } else if(typeof fireToast==='function'){ fireToast(j.error||'ลบไม่สำเร็จ','error'); } }
</script>
