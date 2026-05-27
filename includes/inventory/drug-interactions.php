<?php
/**
 * Tab 8: Drug Interactions
 * Multi-tenant aware: tenant-specific rows (line_account_id = current) and
 * global rows (line_account_id IS NULL) are both shown to the admin so they
 * can promote/duplicate global → tenant.
 *
 * Column mapping (legacy table — preserves consumer API):
 *   drug1_name ↔ "drug A"   drug1_generic
 *   drug2_name ↔ "drug B"   drug2_generic
 *   severity     ENUM('mild','moderate','severe','contraindicated')
 *   description (= spec's interaction_text)   mechanism   recommendation
 */
require_once __DIR__ . '/_lookup_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && ($_POST['_form'] ?? '') === 'drug_interactions'
) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!reya_csrf_check()) throw new Exception('Invalid CSRF token');
        if (!$lineAccountId) throw new Exception('ยังไม่ได้เลือก LINE account');
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $d1   = trim((string)($_POST['drug1_name'] ?? ''));
            $d1g  = trim((string)($_POST['drug1_generic'] ?? ''));
            $d2   = trim((string)($_POST['drug2_name'] ?? ''));
            $d2g  = trim((string)($_POST['drug2_generic'] ?? ''));
            $sev  = $_POST['severity'] ?? 'moderate';
            $desc = trim((string)($_POST['description'] ?? ''));
            $mech = trim((string)($_POST['mechanism'] ?? ''));
            $rec  = trim((string)($_POST['recommendation'] ?? ''));
            if (!in_array($sev, ['mild','moderate','severe','contraindicated'], true)) {
                throw new Exception('ระดับความรุนแรงไม่ถูกต้อง');
            }
            if ($d1 === '' || $d2 === '') throw new Exception('กรุณาระบุชื่อยาทั้งสองตัว');
            if ($id > 0) {
                // Only edit rows owned by this tenant
                $stmt = $db->prepare(
                    'UPDATE drug_interactions
                        SET drug1_name=?, drug1_generic=?, drug2_name=?, drug2_generic=?,
                            severity=?, description=?, mechanism=?, recommendation=?
                      WHERE id=? AND line_account_id=?'
                );
                $stmt->execute([$d1, $d1g, $d2, $d2g, $sev, $desc, $mech, $rec, $id, $lineAccountId]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO drug_interactions
                        (line_account_id, drug1_name, drug1_generic, drug2_name, drug2_generic,
                         severity, description, mechanism, recommendation)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([$lineAccountId, $d1, $d1g, $d2, $d2g, $sev, $desc, $mech, $rec]);
                $id = (int)$db->lastInsertId();
            }
            $activityLogger->logPharmacy(ActivityLogger::ACTION_UPDATE,
                "Saved drug interaction #{$id}: {$d1} ↔ {$d2}",
                ['entity_type' => 'drug_interaction', 'entity_id' => $id]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // Only delete tenant-owned rows
            $stmt = $db->prepare('DELETE FROM drug_interactions WHERE id=? AND line_account_id=?');
            $stmt->execute([$id, $lineAccountId]);
            echo json_encode(['success' => true]);
            exit;
        }
        if ($action === 'duplicate_global') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM drug_interactions WHERE id=? AND line_account_id IS NULL');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Row not found');
            $stmt = $db->prepare(
                'INSERT INTO drug_interactions
                    (line_account_id, drug1_name, drug1_generic, drug2_name, drug2_generic,
                     severity, description, mechanism, recommendation)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $lineAccountId, $row['drug1_name'], $row['drug1_generic'],
                $row['drug2_name'], $row['drug2_generic'], $row['severity'],
                $row['description'], $row['mechanism'] ?? null, $row['recommendation']
            ]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
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
        'SELECT * FROM drug_interactions
          WHERE line_account_id = ? OR line_account_id IS NULL
          ORDER BY (line_account_id IS NULL) ASC,
                   FIELD(severity, "contraindicated","severe","moderate","mild"),
                   drug1_name ASC'
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $error = 'โหลดยาตีกันไม่สำเร็จ: ' . $e->getMessage();
}
$csrf = reya_csrf_token();

$sevCount = ['contraindicated'=>0,'severe'=>0,'moderate'=>0,'mild'=>0];
foreach ($rows as $r) { $sevCount[$r['severity']] = ($sevCount[$r['severity']] ?? 0) + 1; }
?>
<div class="flex items-center justify-between mb-4">
    <p class="text-slate-500 text-sm">
        ทั้งหมด <span class="font-semibold text-slate-800"><?= count($rows) ?></span> คู่
        — แสดงทั้งของร้านและฐานข้อมูลกลาง (กดคัดลอกเพื่อปรับเป็นของร้าน)
    </p>
    <button onclick="openDiModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
        <i class="fas fa-plus mr-2"></i>เพิ่มยาตีกัน
    </button>
</div>

<div class="grid grid-cols-4 gap-3 mb-4">
    <div class="rounded-lg bg-rose-50 border border-rose-100 p-3">
        <div class="text-2xl font-bold text-rose-700"><?= $sevCount['contraindicated'] ?></div>
        <div class="text-xs text-rose-600">ห้ามใช้ร่วมกัน</div>
    </div>
    <div class="rounded-lg bg-amber-50 border border-amber-100 p-3">
        <div class="text-2xl font-bold text-amber-700"><?= $sevCount['severe'] ?></div>
        <div class="text-xs text-amber-600">รุนแรง</div>
    </div>
    <div class="rounded-lg bg-blue-50 border border-blue-100 p-3">
        <div class="text-2xl font-bold text-blue-700"><?= $sevCount['moderate'] ?></div>
        <div class="text-xs text-blue-600">ปานกลาง</div>
    </div>
    <div class="rounded-lg bg-emerald-50 border border-emerald-100 p-3">
        <div class="text-2xl font-bold text-emerald-700"><?= $sevCount['mild'] ?></div>
        <div class="text-xs text-emerald-600">น้อย</div>
    </div>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-3 py-2 text-left">ยา A</th>
                <th class="px-3 py-2 text-left">ยา B</th>
                <th class="px-3 py-2 text-center">ระดับ</th>
                <th class="px-3 py-2 text-left">รายละเอียด</th>
                <th class="px-3 py-2 text-left">คำแนะนำ</th>
                <th class="px-3 py-2 text-center">ที่มา</th>
                <th class="px-3 py-2 text-right">จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">ยังไม่มีข้อมูลยาตีกัน</td></tr>
        <?php else: foreach ($rows as $r): $isGlobal = $r['line_account_id'] === null; ?>
            <tr class="border-t border-slate-100 <?= $isGlobal ? 'bg-slate-50/40' : '' ?>">
                <td class="px-3 py-2">
                    <div class="font-medium"><?= reya_h($r['drug1_name']) ?></div>
                    <?php if (!empty($r['drug1_generic'])): ?><div class="text-xs text-slate-500"><?= reya_h($r['drug1_generic']) ?></div><?php endif; ?>
                </td>
                <td class="px-3 py-2">
                    <div class="font-medium"><?= reya_h($r['drug2_name']) ?></div>
                    <?php if (!empty($r['drug2_generic'])): ?><div class="text-xs text-slate-500"><?= reya_h($r['drug2_generic']) ?></div><?php endif; ?>
                </td>
                <td class="px-3 py-2 text-center">
                    <?php
                    $sevColors = [
                        'contraindicated' => 'bg-rose-100 text-rose-700',
                        'severe'          => 'bg-amber-100 text-amber-700',
                        'moderate'        => 'bg-blue-100 text-blue-700',
                        'mild'            => 'bg-emerald-100 text-emerald-700',
                    ];
                    $sevLabels = [
                        'contraindicated' => 'ห้าม', 'severe' => 'รุนแรง',
                        'moderate' => 'ปานกลาง', 'mild' => 'น้อย',
                    ];
                    ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $sevColors[$r['severity']] ?? 'bg-slate-100' ?>"><?= reya_h($sevLabels[$r['severity']] ?? $r['severity']) ?></span>
                </td>
                <td class="px-3 py-2 text-slate-700 max-w-md"><?= reya_h($r['description']) ?></td>
                <td class="px-3 py-2 text-slate-700 max-w-md"><?= reya_h($r['recommendation']) ?></td>
                <td class="px-3 py-2 text-center">
                    <?php if ($isGlobal): ?>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">กลาง</span>
                    <?php else: ?>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">ของร้าน</span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right">
                    <?php if ($isGlobal): ?>
                        <button class="text-indigo-600 hover:underline" onclick="dupGlobalDi(<?= (int)$r['id'] ?>)">คัดลอกมาแก้</button>
                    <?php else: ?>
                        <button class="text-indigo-600 hover:underline mr-2" onclick='editDi(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>แก้ไข</button>
                        <button class="text-rose-600 hover:underline" onclick="deleteDi(<?= (int)$r['id'] ?>)">ลบ</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="diModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
    <form id="diForm" class="bg-white rounded-xl w-full max-w-2xl p-6 space-y-3" onsubmit="return saveDi(event)">
        <h3 class="text-lg font-semibold" id="diModalTitle">เพิ่มยาตีกัน</h3>
        <input type="hidden" name="_csrf" value="<?= reya_h($csrf) ?>">
        <input type="hidden" name="_form" value="drug_interactions">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="diId" value="">

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ยา A (ชื่อ) <span class="text-rose-500">*</span></label>
                <input name="drug1_name" id="diD1" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ยา A (Generic)</label>
                <input name="drug1_generic" id="diD1g" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ยา B (ชื่อ) <span class="text-rose-500">*</span></label>
                <input name="drug2_name" id="diD2" class="w-full border border-slate-300 rounded-lg px-3 py-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ยา B (Generic)</label>
                <input name="drug2_generic" id="diD2g" class="w-full border border-slate-300 rounded-lg px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ระดับความรุนแรง</label>
            <select name="severity" id="diSev" class="w-full border border-slate-300 rounded-lg px-3 py-2">
                <option value="contraindicated">ห้ามใช้ร่วมกัน (contraindicated)</option>
                <option value="severe">รุนแรง (severe)</option>
                <option value="moderate" selected>ปานกลาง (moderate)</option>
                <option value="mild">น้อย (mild)</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">รายละเอียดผลที่เกิด</label>
            <textarea name="description" id="diDesc" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">กลไก (mechanism)</label>
            <textarea name="mechanism" id="diMech" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">คำแนะนำ</label>
            <textarea name="recommendation" id="diRec" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="px-4 py-2 rounded-lg border" onclick="closeDiModal()">ยกเลิก</button>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg">บันทึก</button>
        </div>
    </form>
</div>

<script>
function openDiModal(){ const m=document.getElementById('diModal'); document.getElementById('diForm').reset(); document.getElementById('diId').value=''; document.getElementById('diSev').value='moderate'; document.getElementById('diModalTitle').textContent='เพิ่มยาตีกัน'; m.classList.remove('hidden'); m.classList.add('flex'); }
function closeDiModal(){ const m=document.getElementById('diModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function editDi(r){ document.getElementById('diId').value=r.id; document.getElementById('diD1').value=r.drug1_name||''; document.getElementById('diD1g').value=r.drug1_generic||''; document.getElementById('diD2').value=r.drug2_name||''; document.getElementById('diD2g').value=r.drug2_generic||''; document.getElementById('diSev').value=r.severity||'moderate'; document.getElementById('diDesc').value=r.description||''; document.getElementById('diMech').value=r.mechanism||''; document.getElementById('diRec').value=r.recommendation||''; document.getElementById('diModalTitle').textContent='แก้ไขยาตีกัน'; const m=document.getElementById('diModal'); m.classList.remove('hidden'); m.classList.add('flex'); }
async function saveDi(ev){ ev.preventDefault(); const data=new FormData(ev.target); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=drug-interactions&success='+(data.get('id')?'updated':'created'); } else if(typeof fireToast==='function'){ fireToast(j.error||'บันทึกไม่สำเร็จ','error'); } return false; }
async function deleteDi(id){ if(!confirm('ลบรายการนี้?')) return; const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','drug_interactions'); data.set('action','delete'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=drug-interactions&success=deleted'; } else if(typeof fireToast==='function'){ fireToast(j.error||'ลบไม่สำเร็จ','error'); } }
async function dupGlobalDi(id){ const data=new FormData(); data.set('_csrf',<?= json_encode($csrf) ?>); data.set('_form','drug_interactions'); data.set('action','duplicate_global'); data.set('id',id); const r=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:data}); const j=await r.json(); if(j.success){ location.href=location.pathname+'?tab=drug-interactions&success=created'; } else if(typeof fireToast==='function'){ fireToast(j.error||'คัดลอกไม่สำเร็จ','error'); } }
</script>
