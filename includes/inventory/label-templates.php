<?php
/**
 * Tab 7: ฉลากยา (Drug Label Templates)
 * Supports placeholders: {shop_name}, {patient_name}, {drug_name},
 * {dose}, {usage}, {date}, {pharmacist}.
 */
require_once __DIR__ . '/_lookup_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'label_templates'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) throw new Exception('Invalid CSRF token');
        if (!$lineAccountId) throw new Exception('ยังไม่ได้เลือก LINE account');
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id        = (int)($_POST['id'] ?? 0);
            $name      = trim((string)($_POST['name'] ?? ''));
            $text      = trim((string)($_POST['template_text'] ?? ''));
            $lang      = trim((string)($_POST['language'] ?? 'th'));
            $generic   = $_POST['applies_to_generic_id'] !== '' ? (int)$_POST['applies_to_generic_id'] : null;
            $usagePat  = trim((string)($_POST['applies_to_usage_pattern'] ?? ''));
            $groupDef  = $_POST['default_for_drug_group_id'] !== '' ? (int)$_POST['default_for_drug_group_id'] : null;
            $act       = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '' || $text === '') throw new Exception('กรุณาระบุชื่อและเนื้อหาเทมเพลต');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE drug_label_templates SET name=?, template_text=?, language=?, applies_to_generic_id=?, applies_to_usage_pattern=?, default_for_drug_group_id=?, is_active=? WHERE id=? AND line_account_id=?');
                $stmt->execute([$name, $text, $lang, $generic, $usagePat ?: null, $groupDef, $act, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare('INSERT INTO drug_label_templates (line_account_id, name, template_text, language, applies_to_generic_id, applies_to_usage_pattern, default_for_drug_group_id, is_active) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$lineAccountId, $name, $text, $lang, $generic, $usagePat ?: null, $groupDef, $act]);
                $id = (int)$db->lastInsertId();
            }
            $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, "Saved label template #{$id}", ['entity_type' => 'drug_label_template', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM drug_label_templates WHERE id=? AND line_account_id=?');
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

$generics = [];
$groups   = [];
$rows     = [];
try {
    $stmt = $db->prepare('SELECT id, generic_name FROM generic_names WHERE line_account_id=? ORDER BY generic_name');
    $stmt->execute([$lineAccountId]);
    $generics = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare('SELECT id, name_th FROM drug_groups WHERE line_account_id=? ORDER BY name_th');
    $stmt->execute([$lineAccountId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $db->prepare(
        'SELECT t.*, g.generic_name, dg.name_th AS drug_group_name
           FROM drug_label_templates t
           LEFT JOIN generic_names g ON g.id = t.applies_to_generic_id
           LEFT JOIN drug_groups  dg ON dg.id = t.default_for_drug_group_id
          WHERE t.line_account_id = ?
          ORDER BY t.name ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดเทมเพลตฉลากยาไม่สำเร็จ: ' . $e->getMessage();
}
$csrf = reya_csrf_token();
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">เทมเพลตฉลากยาทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> แบบ
        — รองรับ placeholder: <code class="text-xs">{shop_name} {patient_name} {drug_name} {dose} {usage} {date} {pharmacist}</code>
    </p>
    <button onclick="openLtModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มเทมเพลต
    </button>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left">ชื่อเทมเพลต</th>
                <th class="px-3 py-2 text-left">ภาษา</th>
                <th class="px-3 py-2 text-left">ใช้กับ Generic</th>
                <th class="px-3 py-2 text-left">ใช้กับวิธีใช้</th>
                <th class="px-3 py-2 text-left">Default กลุ่มยา</th>
                <th class="px-3 py-2 text-center">สถานะ</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">ยังไม่มีเทมเพลตฉลากยา</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100">
                <td class="px-3 py-2 font-medium"><?= reya_h($r['name']) ?></td>
                <td class="px-3 py-2 uppercase"><?= reya_h($r['language']) ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['generic_name'] ?? '—') ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['applies_to_usage_pattern'] ?? '—') ?></td>
                <td class="px-3 py-2 text-slate-600"><?= reya_h($r['drug_group_name'] ?? '—') ?></td>
                <td class="px-3 py-2 text-center"><?= reya_status_pill((bool)$r['is_active']) ?></td>
                <td class="px-3 py-2 text-right">
                    <button class="text-indigo-600 hover:underline mr-2" onclick='editLt(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                    <button class="text-rose-600 hover:underline" onclick="deleteLt(<?= (int)$r['id'] ?>)">ลบ</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="ltModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="ltForm" class="bg-white rounded-xl w-full max-w-3xl p-6 space-y-3" onsubmit="return saveLt(event)">
        <h3 class="text-lg font-semibold" id="ltModalTitle">เพิ่มเทมเพลตฉลากยา</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="label_templates">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="ltId" value="">

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อเทมเพลต <span class="text-rose-500">*</span></label>
                <input name="name" id="ltName" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ภาษา</label>
                <select name="language" id="ltLang" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                    <option value="th">ไทย (TH)</option>
                    <option value="en">English (EN)</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">เนื้อหาฉลาก <span class="text-rose-500">*</span></label>
                <textarea name="template_text" id="ltText" rows="10" class="w-full border border-slate-300 rounded-lg px-3 py-2 font-mono text-sm" required oninput="updateLtPreview()"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ตัวอย่าง</label>
                <pre id="ltPreview" class="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 h-[260px] overflow-auto whitespace-pre-wrap text-sm"></pre>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ผูกกับ Generic</label>
                <select name="applies_to_generic_id" id="ltGeneric" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                    <option value="">— ไม่ผูก —</option>
                    <?php foreach ($generics as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= reya_h($g['generic_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ผูกกับวิธีใช้</label>
                <input name="applies_to_usage_pattern" id="ltUsage" placeholder="oral, topical, ..." class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Default สำหรับกลุ่มยา</label>
                <select name="default_for_drug_group_id" id="ltGroup" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                    <option value="">— ไม่ตั้ง default —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= (int)$g['id'] ?>"><?= reya_h($g['name_th']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" id="ltActive" checked> เปิดใช้งาน
        </label>

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeLtModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
const LT_SAMPLE = { shop_name:'ร้านยาตัวอย่าง', patient_name:'คุณสมชาย', drug_name:'Paracetamol 500mg', dose:'1 เม็ด', usage:'รับประทานทุก 6 ชั่วโมง', date:'24 พ.ค. 2569', pharmacist:'ภญ.มาลี' };
function updateLtPreview(){
    const txt = document.getElementById('ltText').value || '';
    let out = txt;
    for (const k in LT_SAMPLE){ out = out.split('{'+k+'}').join(LT_SAMPLE[k]); }
    document.getElementById('ltPreview').textContent = out;
}
function openLtModal(){ const m=document.getElementById('ltModal'); document.getElementById('ltForm').reset(); document.getElementById('ltId').value=''; document.getElementById('ltActive').checked=true; document.getElementById('ltModalTitle').textContent='เพิ่มเทมเพลตฉลากยา'; updateLtPreview(); m.classList.remove('hidden'); m.classList.add('flex'); }
function closeLtModal(){ const m=document.getElementById('ltModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function editLt(r){ document.getElementById('ltId').value=r.id; document.getElementById('ltName').value=r.name||''; document.getElementById('ltLang').value=r.language||'th'; document.getElementById('ltText').value=r.template_text||''; document.getElementById('ltGeneric').value=r.applies_to_generic_id||''; document.getElementById('ltUsage').value=r.applies_to_usage_pattern||''; document.getElementById('ltGroup').value=r.default_for_drug_group_id||''; document.getElementById('ltActive').checked=!!Number(r.is_active); document.getElementById('ltModalTitle').textContent='แก้ไขเทมเพลตฉลากยา'; updateLtPreview(); const m=document.getElementById('ltModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
async function saveLt(ev){ ev.preventDefault(); const data=new FormData(ev.target); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=label-templates&success='+(data.get('id')?'updated':'created'); } else if(typeof fireToast==='function'){ fireToast(j.error||'บันทึกไม่สำเร็จ','error'); } return false; }
async function deleteLt(id){ if(!confirm('ลบเทมเพลตนี้?')) return; const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','label_templates'); data.set('action','delete'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=label-templates&success=deleted'; } else if(typeof fireToast==='function'){ fireToast(j.error||'ลบไม่สำเร็จ','error'); } }
</script>
