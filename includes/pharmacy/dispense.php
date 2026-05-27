<?php
/**
 * Dispense Drugs Tab Content
 * หน้าจ่ายยาและกรอกรายละเอียด
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

$sessionId = (int)($_GET['session_id'] ?? 0);

// Quick-access banner: ติดตามการจ่ายยา (วันคงเหลือ + แจ้งเตือนแมนนวล)
?>
<div class="bg-gradient-to-r from-teal-50 to-emerald-50 border border-teal-200 rounded-xl p-4 mb-4 flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-teal-100 text-teal-700 flex items-center justify-center text-xl">🔔</div>
        <div>
            <div class="font-semibold text-gray-900">ติดตามการจ่ายยา</div>
            <div class="text-xs text-gray-600">ดูยาคงเหลือของลูกค้าที่จ่ายไปแล้ว · ส่งแจ้งเตือนแบบแมนนวล</div>
        </div>
    </div>
    <a href="/dispense-tracking" class="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm hover:bg-teal-700 transition">
        <i class="fas fa-prescription-bottle-alt mr-1"></i> เปิดหน้าติดตาม
    </a>
</div>
<?php

// If no session_id, show session list
if (!$sessionId) {
    // Get pending sessions
    $pendingSessions = [];
    try {
        $stmt = $db->query("
            SELECT ts.id, ts.user_id, ts.current_state, ts.triage_data, ts.status,
                   ts.created_at, u.display_name, u.picture_url
            FROM triage_sessions ts
            LEFT JOIN users u ON ts.user_id = u.id
            WHERE ts.status IS NULL OR ts.status = 'active' OR ts.status = ''
            ORDER BY ts.created_at DESC
            LIMIT 50
        ");
        $pendingSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    ?>
    <div class="text-center py-8">
        <i class="fas fa-pills text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-600 mb-2">เลือก Session เพื่อจ่ายยา</h3>
        <p class="text-gray-500 mb-6">กรุณาเลือก session จากรายการด้านล่าง หรือจากแท็บ Dashboard</p>
        
        <?php if (!empty($pendingSessions)): ?>
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-xl shadow divide-y">
                <?php foreach ($pendingSessions as $sess): ?>
                <?php $triageData = json_decode($sess['triage_data'] ?? '{}', true); ?>
                <a href="pharmacy.php?tab=dispense&session_id=<?= $sess['id'] ?>" 
                   class="flex items-center gap-4 p-4 hover:bg-gray-50 transition">
                    <img src="<?= htmlspecialchars($sess['picture_url'] ?? 'assets/images/default-avatar.png') ?>" 
                         class="w-12 h-12 rounded-full object-cover">
                    <div class="flex-1 text-left">
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($sess['display_name'] ?? 'ไม่ระบุชื่อ') ?></p>
                        <p class="text-sm text-gray-500">
                            <?php if (!empty($triageData['symptoms'])): ?>
                            อาการ: <?= htmlspecialchars(is_array($triageData['symptoms']) ? implode(', ', array_slice($triageData['symptoms'], 0, 3)) : $triageData['symptoms']) ?>
                            <?php else: ?>
                            Session #<?= $sess['id'] ?>
                            <?php endif; ?>
                        </p>
                        <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($sess['created_at'])) ?></p>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <p class="text-gray-400">ไม่มี session ที่รอจ่ายยา</p>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// Get session data
$stmt = $db->prepare("
    SELECT ts.*, u.display_name, u.picture_url, u.phone, u.drug_allergies, u.medical_conditions, u.line_account_id
    FROM triage_sessions ts
    LEFT JOIN users u ON ts.user_id = u.id
    WHERE ts.id = ?
");
$stmt->execute([$sessionId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    echo '<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-4xl mb-4"></i><p>ไม่พบ Session ที่ระบุ</p></div>';
    return;
}

$triageData = json_decode($session['triage_data'] ?? '{}', true);
$symptoms = $triageData['symptoms'] ?? [];
$severity = $triageData['severity'] ?? null;
$redFlags = $triageData['red_flags'] ?? [];
$isEmergency = $session['current_state'] === 'emergency';

// ── NEW: Load AI conversation history for this session (Option D) ────────────
$aiChat = [];
try {
    $stmt = $db->prepare("SELECT role, content, created_at FROM ai_conversation_history WHERE session_id = ? ORDER BY created_at ASC LIMIT 60");
    $stmt->execute([(string)$sessionId]);
    $aiChat = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Fallback: try by user_id when session_id wasn't populated yet
    if (empty($aiChat) && !empty($session['user_id'])) {
        $stmt = $db->prepare("SELECT role, content, created_at FROM ai_conversation_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 60");
        $stmt->execute([(int)$session['user_id']]);
        $aiChat = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
} catch (\Throwable $e) {
    error_log('dispense: load ai_conversation_history failed: ' . $e->getMessage());
}
$chiefComplaint = trim((string)($session['chief_complaint'] ?? ''));
?>

<style>
.drug-search-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: white; border: 1px solid #e5e7eb; border-radius: 0.5rem;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15); max-height: 300px;
    overflow-y: auto; z-index: 50; display: none;
}
.drug-search-results.show { display: block; }
.drug-item { padding: 0.75rem 1rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; }
.drug-item:hover { background: #f8fafc; }
.drug-item:last-child { border-bottom: none; }
.selected-drug-card { 
    background: #fafafa; border: 1px solid #e5e7eb; 
    border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem;
}
.timing-btn { 
    padding: 0.25rem 0.75rem; border-radius: 0.25rem; font-size: 0.75rem;
    border: 1px solid #d1d5db; background: white; cursor: pointer;
}
.timing-btn.active { background: #0d9488; color: white; border-color: #0d9488; }
.unit-btn {
    padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem;
    border: 1px solid #d1d5db; background: white; cursor: pointer;
}
.unit-btn.active { background: #0d9488; color: white; border-color: #0d9488; }
.generic-name { color: #0891b2; font-size: 0.875rem; }
.non-drug-section { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 0.5rem; padding: 0.75rem; margin-top: 0.5rem; }
</style>

<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-4">
        <a href="pharmacy.php?tab=dashboard" class="text-gray-500 hover:text-gray-700">
            <i class="fas fa-arrow-left text-xl"></i>
        </a>
        <div>
            <h2 class="text-xl font-bold text-gray-800">จ่ายยา/สินค้า</h2>
            <p class="text-gray-500">Session #<?= $sessionId ?></p>
        </div>
    </div>
    <?php if ($isEmergency): ?>
    <span class="px-4 py-2 bg-red-500 text-white rounded-full font-medium">กรณีฉุกเฉิน</span>
    <?php endif; ?>
</div>

<!-- ── NEW: AI Chat transcript + AI summary (Option D) ───────────────────── -->
<?php if (!empty($aiChat) || $chiefComplaint !== ''): ?>
<div class="bg-white rounded-xl shadow p-4 mb-6 border-l-4 border-purple-400">
    <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold text-gray-800 flex items-center gap-2">
            <i class="fas fa-robot text-purple-500"></i>
            บทสนทนา AI กับลูกค้า
            <span class="text-xs text-gray-500 font-normal">(<?= count($aiChat) ?> ข้อความ)</span>
        </h3>
        <button type="button" onclick="document.getElementById('aiChatBody').classList.toggle('hidden')" class="text-xs text-purple-600 hover:text-purple-700">
            <i class="fas fa-chevron-up"></i> ย่อ/ขยาย
        </button>
    </div>
    <?php if ($chiefComplaint !== ''): ?>
    <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 mb-3">
        <p class="text-xs font-semibold text-purple-800 mb-1">📋 สรุปจาก AI (chief complaint):</p>
        <p class="text-sm text-gray-800 leading-relaxed"><?= nl2br(htmlspecialchars($chiefComplaint)) ?></p>
    </div>
    <?php endif; ?>
    <div id="aiChatBody" class="space-y-2 max-h-96 overflow-y-auto bg-gray-50 rounded-lg p-3">
        <?php if (empty($aiChat)): ?>
        <p class="text-xs text-gray-500 italic text-center py-4">ไม่มีบทสนทนา AI สำหรับ session นี้</p>
        <?php else: ?>
        <?php foreach ($aiChat as $msg): ?>
        <div class="flex gap-2 <?= $msg['role'] === 'user' ? 'flex-row-reverse' : '' ?>">
            <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-xs <?= $msg['role'] === 'user' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' ?>">
                <i class="fas <?= $msg['role'] === 'user' ? 'fa-user' : 'fa-robot' ?>"></i>
            </div>
            <div class="max-w-[80%] px-3 py-2 rounded-xl text-sm <?= $msg['role'] === 'user' ? 'bg-blue-600 text-white' : 'bg-white text-gray-800 border border-gray-200' ?>">
                <p class="whitespace-pre-line leading-relaxed"><?= nl2br(htmlspecialchars($msg['content'])) ?></p>
                <p class="text-[10px] mt-1 opacity-60"><?= date('d/m H:i', strtotime($msg['created_at'])) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm p-6 sticky top-4">
            <div class="flex items-center gap-4 mb-4">
                <img src="<?= htmlspecialchars($session['picture_url'] ?? 'assets/images/default-avatar.png') ?>" 
                     class="w-16 h-16 rounded-full object-cover border-2 border-gray-200">
                <div>
                    <h3 class="font-bold text-lg"><?= htmlspecialchars($session['display_name'] ?? 'ไม่ระบุชื่อ') ?></h3>
                    <p class="text-gray-500 text-sm"><?= htmlspecialchars($session['phone'] ?? '-') ?></p>
                </div>
            </div>
            <div class="space-y-3 text-sm">
                <?php if (!empty($symptoms)): ?>
                <div><span class="text-gray-500">อาการ:</span>
                    <p class="font-medium"><?= htmlspecialchars(is_array($symptoms) ? implode(', ', $symptoms) : $symptoms) ?></p>
                </div>
                <?php endif; ?>
                <?php if ($severity): ?>
                <div><span class="text-gray-500">ความรุนแรง:</span>
                    <span class="font-medium <?= $severity >= 7 ? 'text-red-600' : ($severity >= 4 ? 'text-yellow-600' : 'text-green-600') ?>"><?= $severity ?>/10</span>
                </div>
                <?php endif; ?>
                <?php if (!empty($session['drug_allergies'])): ?>
                <div class="p-2 bg-red-50 rounded-lg border border-red-200">
                    <span class="text-red-600 font-medium">แพ้ยา:</span>
                    <p class="text-red-700"><?= htmlspecialchars($session['drug_allergies']) ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($session['medical_conditions'])): ?>
                <div><span class="text-gray-500">โรคประจำตัว:</span>
                    <p><?= htmlspecialchars($session['medical_conditions']) ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($redFlags)): ?>
                <div class="p-2 bg-red-50 rounded-lg border border-red-200">
                    <span class="text-red-600 font-medium">Red Flags:</span>
                    <?php foreach ($redFlags as $flag): ?>
                    <p class="text-red-700 text-xs">- <?= htmlspecialchars(is_array($flag) ? ($flag['message'] ?? '') : $flag) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-lg mb-4">ค้นหายา/สินค้า</h3>
            <div class="relative">
                <input type="text" id="drugSearch" placeholder="พิมพ์ชื่อยาหรือสินค้า..." 
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-teal-500 focus:ring-1 focus:ring-teal-500"
                       autocomplete="off">
                <div id="searchResults" class="drug-search-results"></div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-lg mb-4">รายการที่เลือก</h3>
            <div id="selectedDrugs">
                <p class="text-gray-400 text-center py-8" id="noDrugsMessage">ยังไม่ได้เลือกรายการ</p>
            </div>
            <div id="totalSection" class="hidden border-t pt-4 mt-4">
                <div class="flex justify-between items-center text-lg font-bold">
                    <span>รวมทั้งหมด</span>
                    <span class="text-teal-600" id="totalPrice">฿0</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-lg mb-4">ข้อมูลเภสัชกร</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">ชื่อเภสัชกร</label>
                    <input type="text" id="pharmacistName" value="<?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? '') ?>" 
                           class="w-full px-3 py-2 border rounded-lg focus:ring-1 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">เลขใบอนุญาต</label>
                    <input type="text" id="pharmacistLicense" placeholder="ภ.XXXXX" 
                           class="w-full px-3 py-2 border rounded-lg focus:ring-1 focus:ring-teal-500">
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm text-gray-600 mb-1">หมายเหตุถึงลูกค้า</label>
                <textarea id="pharmacistNote" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-1 focus:ring-teal-500" 
                          placeholder="คำแนะนำเพิ่มเติม..."></textarea>
            </div>
        </div>

        <div class="flex gap-4">
            <button onclick="rejectCase()" class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-medium hover:bg-gray-200 border">
                ปฏิเสธ
            </button>
            <button onclick="approveAndSend()" class="flex-1 px-6 py-3 bg-teal-600 text-white rounded-lg font-medium hover:bg-teal-700">
                อนุมัติและเพิ่มลงตะกร้า
            </button>
        </div>
    </div>
</div>

<script>
const sessionId = <?= $sessionId ?>;
const userId = <?= $session['user_id'] ?>;
let allDrugs = [];
let selectedDrugs = [];
let searchTimeout = null;
let drugsLoaded = false;

document.addEventListener('DOMContentLoaded', () => { loadAllDrugs(); });

function loadAllDrugs() {
    const searchInput = document.getElementById('drugSearch');
    searchInput.placeholder = 'กำลังโหลดรายการ...';
    searchInput.disabled = true;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_drugs' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            allDrugs = data.drugs || [];
            drugsLoaded = true;
            searchInput.placeholder = `พิมพ์ชื่อยาหรือสินค้า... (${allDrugs.length} รายการ)`;
            searchInput.disabled = false;
        } else {
            searchInput.placeholder = 'เกิดข้อผิดพลาด';
        }
    })
    .catch(err => { searchInput.placeholder = 'เกิดข้อผิดพลาดในการโหลด'; });
}

document.getElementById('drugSearch').addEventListener('input', function(e) {
    const query = e.target.value.trim().toLowerCase();
    clearTimeout(searchTimeout);
    if (query.length < 1) { document.getElementById('searchResults').classList.remove('show'); return; }
    if (!drugsLoaded) { return; }
    
    searchTimeout = setTimeout(() => {
        const results = allDrugs.filter(drug => {
            const name = (drug.name || '').trim().toLowerCase();
            const genericName = (drug.generic_name || '').trim().toLowerCase();
            const sku = (drug.sku || '').trim().toLowerCase();
            return name.includes(query) || genericName.includes(query) || sku.includes(query);
        }).slice(0, 15);
        showSearchResults(results);
    }, 150);
});

function showSearchResults(drugs) {
    const container = document.getElementById('searchResults');
    if (drugs.length === 0) {
        container.innerHTML = '<div class="p-4 text-gray-400 text-center">ไม่พบรายการ</div>';
        container.classList.add('show');
        return;
    }
    container.innerHTML = drugs.map(drug => {
        const name = (drug.name || '').trim();
        const genericName = (drug.generic_name || '').trim();
        const requiresPrescription = !!parseInt(drug.requires_prescription || 0, 10);
        const rxFlag = requiresPrescription
            ? `<span class="ml-2 px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-800 text-xs">⚕️ Rx</span>`
            : '';
        const payload = JSON.stringify({
            id: drug.id, name: name, genericName: genericName,
            price: drug.price || 0, requiresPrescription: requiresPrescription
        });
        return `
        <div class="drug-item" onclick='selectDrug(${payload})'>
            <div class="font-medium">${name}${rxFlag}</div>
            ${genericName ? `<div class="generic-name">${genericName}</div>` : ''}
            <div class="text-sm text-gray-500">฿${drug.price || 0}</div>
        </div>`;
    }).join('');
    container.classList.add('show');
}

function selectDrug(drug) {
    if (selectedDrugs.find(d => d.id === drug.id)) { alert('รายการนี้ถูกเลือกแล้ว'); return; }
    selectedDrugs.push({
        id: drug.id, name: drug.name, genericName: drug.genericName, price: drug.price,
        requiresPrescription: !!drug.requiresPrescription, // Phase 2C
        isNonDrug: false, indication: '', dosage: '1', unit: 'เม็ด',
        morning: false, noon: false, evening: false, bedtime: false,
        instructions: '', warning: '', quantity: 1,
        // Phase 2B: batch tracking (loaded async after selection)
        batches: null, batchId: null, batchTrackingDisabled: false
    });
    document.getElementById('drugSearch').value = '';
    document.getElementById('searchResults').classList.remove('show');
    renderSelectedDrugs();
    loadBatchesForDrug(drug.id);
    // Phase 2C: lazy-load Rx pool the first time an Rx-flagged drug is added.
    if (drug.requiresPrescription) { loadPendingRxOnce(); }
}

// ── Phase 2C: prescription approvals ────────────────────────────────────
let pendingRx = null;          // null = not yet loaded; [] = loaded empty
let selectedRxApprovalId = null;
let rxLoadInflight = false;

function loadPendingRxOnce() {
    if (pendingRx !== null || rxLoadInflight) return;
    rxLoadInflight = true;
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_pending_rx', user_id: userId })
    })
    .then(r => r.json())
    .then(data => {
        pendingRx = data.success ? (data.approvals || []) : [];
        rxLoadInflight = false;
        renderSelectedDrugs();
    })
    .catch(() => { pendingRx = []; rxLoadInflight = false; renderSelectedDrugs(); });
}

function selectRxApproval(approvalId) {
    selectedRxApprovalId = approvalId ? parseInt(approvalId, 10) : null;
}

function rxPickerHtml() {
    const anyRx = selectedDrugs.some(d => d.requiresPrescription && !d.isNonDrug);
    if (!anyRx) return '';
    if (pendingRx === null) {
        return `<div class="bg-yellow-50 border border-yellow-300 rounded-lg p-3 mt-3 text-sm">กำลังโหลดใบสั่งยา...</div>`;
    }
    if (pendingRx.length === 0) {
        return `
        <div class="bg-red-50 border border-red-300 rounded-lg p-3 mt-3 text-sm">
            <div class="font-semibold text-red-700">⚠️ ไม่พบใบสั่งยาที่อนุมัติแล้วสำหรับลูกค้านี้</div>
            <div class="text-red-600 mt-1">รายการที่เลือกมียาที่ต้องสั่งโดยแพทย์ ต้องมีการอนุมัติใบสั่งยาก่อนจ่าย</div>
        </div>`;
    }
    const options = pendingRx.map(rx => {
        const sel = rx.id === selectedRxApprovalId ? 'selected' : '';
        const exp = rx.expires_at ? ` — หมดอายุ ${rx.expires_at}` : '';
        return `<option value="${rx.id}" ${sel}>ใบสั่งยา #${rx.id} (${rx.created_at})${exp}</option>`;
    }).join('');
    return `
        <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-3 mt-3 text-sm">
            <div class="font-semibold text-yellow-800 mb-2">📋 ต้องแนบใบสั่งยา</div>
            <select onchange="selectRxApproval(this.value)" class="w-full px-2 py-1.5 border rounded">
                <option value="">— เลือกใบสั่งยา —</option>
                ${options}
            </select>
        </div>`;
}

function loadBatchesForDrug(drugId) {
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_batches_for_drug', drug_id: drugId })
    })
    .then(r => r.json())
    .then(data => {
        const idx = selectedDrugs.findIndex(d => d.id === drugId);
        if (idx === -1) return;
        if (data.success) {
            selectedDrugs[idx].batches = data.batches || [];
            selectedDrugs[idx].batchTrackingDisabled = !!data.tracking_disabled;
            // Auto-pick the FEFO batch (first one, since API orders by expiry ASC) if any
            if (selectedDrugs[idx].batches.length > 0) {
                selectedDrugs[idx].batchId = selectedDrugs[idx].batches[0].id;
            }
        } else {
            selectedDrugs[idx].batches = [];
            selectedDrugs[idx].batchTrackingDisabled = true;
        }
        renderSelectedDrugs();
    })
    .catch(() => {
        const idx = selectedDrugs.findIndex(d => d.id === drugId);
        if (idx !== -1) {
            selectedDrugs[idx].batches = [];
            selectedDrugs[idx].batchTrackingDisabled = true;
            renderSelectedDrugs();
        }
    });
}

function selectBatch(idx, batchId) {
    selectedDrugs[idx].batchId = batchId ? parseInt(batchId, 10) : null;
}

function batchDropdownHtml(drug, idx) {
    if (drug.batches === null) {
        return `<div class="text-xs text-gray-400 mt-2">กำลังโหลด batch...</div>`;
    }
    if (drug.batchTrackingDisabled || drug.batches.length === 0) {
        return `<div class="text-xs text-gray-400 mt-2">ไม่มี batch ลงทะเบียน — จะจ่ายโดยไม่ตัดสต๊อก</div>`;
    }
    const today = new Date(); today.setHours(0,0,0,0);
    const options = drug.batches.map(b => {
        const exp = new Date(b.expiry_date);
        const days = Math.floor((exp - today) / 86400000);
        const expWarn = days < 90;
        const label = `${b.batch_number} — หมดอายุ ${b.expiry_date} (คงเหลือ ${b.quantity_on_hand})`;
        const sel = b.id === drug.batchId ? 'selected' : '';
        return `<option value="${b.id}" ${sel} data-warn="${expWarn ? '1' : '0'}">${label}</option>`;
    }).join('');
    const selectedBatch = drug.batches.find(b => b.id === drug.batchId);
    let warnHtml = '';
    if (selectedBatch) {
        const exp = new Date(selectedBatch.expiry_date);
        const days = Math.floor((exp - today) / 86400000);
        if (days < 90) {
            warnHtml = `<div class="text-xs text-red-600 mt-1">⚠️ หมดอายุใน ${days} วัน</div>`;
        }
    }
    return `
        <div class="mt-2">
            <label class="text-xs text-gray-600">Batch (FEFO)</label>
            <select onchange="selectBatch(${idx}, this.value)" class="w-full px-2 py-1.5 border rounded text-sm">
                ${options}
            </select>
            ${warnHtml}
        </div>`;
}

function renderSelectedDrugs() {
    const container = document.getElementById('selectedDrugs');
    const totalSection = document.getElementById('totalSection');
    
    if (selectedDrugs.length === 0) {
        container.innerHTML = '<p class="text-gray-400 text-center py-8">ยังไม่ได้เลือกรายการ</p>';
        totalSection.classList.add('hidden');
        return;
    }
    
    totalSection.classList.remove('hidden');
    let total = 0;
    
    container.innerHTML = selectedDrugs.map((drug, idx) => {
        total += (parseFloat(drug.price) || 0) * (parseInt(drug.quantity) || 1);
        const isNonDrug = drug.isNonDrug;

        const rxRibbon = drug.requiresPrescription && !isNonDrug
            ? `<span class="ml-2 px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 text-xs font-semibold">⚕️ ต้องมีใบสั่งยา</span>`
            : '';

        return `
        <div class="selected-drug-card" data-idx="${idx}">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <div class="font-bold text-gray-800">${drug.name}${rxRibbon}</div>
                    ${drug.genericName ? `<div class="generic-name">${drug.genericName}</div>` : ''}
                    <div class="text-gray-500 text-sm">฿${drug.price} x ${drug.quantity} = ฿${(drug.price * drug.quantity).toLocaleString()}</div>
                </div>
                <button onclick="removeDrug(${idx})" class="text-red-400 hover:text-red-600 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="mb-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" ${isNonDrug ? 'checked' : ''} onchange="toggleNonDrug(${idx}, this.checked)" class="rounded">
                    <span class="text-sm text-gray-600">ไม่ใช่ยา (สินค้าทั่วไป)</span>
                </label>
            </div>
            
            ${isNonDrug ? `
            <div class="non-drug-section">
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <label class="text-gray-600">จำนวน</label>
                        <input type="number" min="1" value="${drug.quantity}" onchange="updateDrug(${idx}, 'quantity', this.value)"
                               class="w-full px-2 py-1.5 border rounded mt-1">
                    </div>
                    <div>
                        <label class="text-gray-600">ข้อบ่งใช้/รายละเอียด</label>
                        <input type="text" value="${drug.indication || ''}" onchange="updateDrug(${idx}, 'indication', this.value)"
                               class="w-full px-2 py-1.5 border rounded mt-1" placeholder="เช่น บำรุงร่างกาย">
                    </div>
                </div>
            </div>
            ` : `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                    <label class="text-gray-600">ข้อบ่งใช้</label>
                    <input type="text" value="${drug.indication || ''}" onchange="updateDrug(${idx}, 'indication', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1" placeholder="เช่น บรรเทาอาการปวด">
                </div>
                <div>
                    <label class="text-gray-600">จำนวน/ครั้ง</label>
                    <div class="flex gap-2 mt-1">
                        <input type="number" min="0.5" step="0.5" value="${drug.dosage}" onchange="updateDrug(${idx}, 'dosage', this.value)"
                               class="w-20 px-2 py-1.5 border rounded">
                        <div class="flex gap-1">
                            <button type="button" class="unit-btn ${drug.unit === 'เม็ด' ? 'active' : ''}" onclick="setUnit(${idx}, 'เม็ด', this)">เม็ด</button>
                            <button type="button" class="unit-btn ${drug.unit === 'ช้อน' ? 'active' : ''}" onclick="setUnit(${idx}, 'ช้อน', this)">ช้อน</button>
                            <button type="button" class="unit-btn ${drug.unit === 'มล.' ? 'active' : ''}" onclick="setUnit(${idx}, 'มล.', this)">มล.</button>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="text-gray-600">เวลารับประทาน</label>
                    <div class="flex gap-2 mt-1 flex-wrap">
                        <button type="button" class="timing-btn ${drug.morning ? 'active' : ''}" onclick="toggleTiming(${idx}, 'morning', this)">เช้า</button>
                        <button type="button" class="timing-btn ${drug.noon ? 'active' : ''}" onclick="toggleTiming(${idx}, 'noon', this)">กลางวัน</button>
                        <button type="button" class="timing-btn ${drug.evening ? 'active' : ''}" onclick="toggleTiming(${idx}, 'evening', this)">เย็น</button>
                        <button type="button" class="timing-btn ${drug.bedtime ? 'active' : ''}" onclick="toggleTiming(${idx}, 'bedtime', this)">ก่อนนอน</button>
                    </div>
                </div>
                <div>
                    <label class="text-gray-600">วิธีใช้</label>
                    <input type="text" value="${drug.instructions || ''}" onchange="updateDrug(${idx}, 'instructions', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1" placeholder="เช่น รับประทานหลังอาหาร">
                </div>
                <div>
                    <label class="text-gray-600">คำเตือน</label>
                    <input type="text" value="${drug.warning || ''}" onchange="updateDrug(${idx}, 'warning', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1" placeholder="เช่น ห้ามใช้ในผู้แพ้ยา">
                </div>
                <div>
                    <label class="text-gray-600">จำนวนที่จ่าย</label>
                    <input type="number" min="1" value="${drug.quantity}" onchange="updateDrug(${idx}, 'quantity', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1">
                </div>
                <div class="md:col-span-2">${batchDropdownHtml(drug, idx)}</div>
            </div>
            `}
        </div>`;
    }).join('');
    
    document.getElementById('totalPrice').textContent = '฿' + total.toLocaleString();

    // Phase 2C: render Rx picker beneath the drugs list when any Rx-flagged drug is present.
    let rxContainer = document.getElementById('rxPickerContainer');
    if (!rxContainer) {
        rxContainer = document.createElement('div');
        rxContainer.id = 'rxPickerContainer';
        container.parentElement.appendChild(rxContainer);
    }
    rxContainer.innerHTML = rxPickerHtml();
}

function updateDrug(idx, field, value) { selectedDrugs[idx][field] = value; if (field === 'quantity') renderSelectedDrugs(); }
function toggleNonDrug(idx, checked) { selectedDrugs[idx].isNonDrug = checked; renderSelectedDrugs(); }
function setUnit(idx, unit, btn) {
    selectedDrugs[idx].unit = unit;
    btn.parentElement.querySelectorAll('.unit-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
function toggleTiming(idx, timing, btn) { selectedDrugs[idx][timing] = !selectedDrugs[idx][timing]; btn.classList.toggle('active'); }
function removeDrug(idx) { selectedDrugs.splice(idx, 1); renderSelectedDrugs(); }

document.addEventListener('click', function(e) {
    if (!e.target.closest('#drugSearch') && !e.target.closest('#searchResults')) {
        document.getElementById('searchResults').classList.remove('show');
    }
});

function approveAndSend() {
    if (selectedDrugs.length === 0) { alert('กรุณาเลือกรายการอย่างน้อย 1 รายการ'); return; }
    const pharmacistName = document.getElementById('pharmacistName').value;
    if (!pharmacistName) { alert('กรุณากรอกชื่อเภสัชกร'); return; }

    const drugsWithDetails = selectedDrugs.map(drug => {
        const timing = [];
        if (drug.morning) timing.push('เช้า');
        if (drug.noon) timing.push('กลางวัน');
        if (drug.evening) timing.push('เย็น');
        if (drug.bedtime) timing.push('ก่อนนอน');
        return {
            id: drug.id, name: drug.name, genericName: drug.genericName || '',
            price: drug.price, quantity: drug.quantity || 1,
            isNonDrug: drug.isNonDrug || false,
            indication: drug.indication || '',
            dosage: drug.dosage || '1', unit: drug.unit || 'เม็ด',
            timing: timing.join(', ') || 'ตามอาการ',
            instructions: drug.instructions || '', warning: drug.warning || '',
            batch_id: drug.batchId || null
        };
    });

    // Phase 2A: pre-flight safety check before approval.
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'check_interactions', user_id: userId, drugs: drugsWithDetails })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('ไม่สามารถตรวจสอบยาตีกันได้: ' + (data.error || 'Unknown') + '\nกรุณาลองใหม่');
            return;
        }
        const findings = collectFindings(data.report || {});
        if (findings.length === 0) {
            if (!confirm('ยืนยันอนุมัติและเพิ่มลงตะกร้าลูกค้า?')) return;
            submitApprove(drugsWithDetails, pharmacistName, []);
            return;
        }
        showInteractionModal(findings, (acknowledgedKeys) => {
            submitApprove(drugsWithDetails, pharmacistName, acknowledgedKeys);
        });
    })
    .catch(e => { alert('เกิดข้อผิดพลาด: ' + e.message); });
}

function submitApprove(drugsWithDetails, pharmacistName, acknowledgedInteractions) {
    // Phase 2C client-side pre-check: if any Rx drug is selected, require an attached approval.
    const anyRx = selectedDrugs.some(d => d.requiresPrescription && !d.isNonDrug);
    if (anyRx && !selectedRxApprovalId) {
        alert('โปรดเลือกใบสั่งยาที่อนุมัติแล้วก่อนจ่ายยา');
        return;
    }
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'approve_drugs',
            session_id: sessionId, user_id: userId,
            drugs: drugsWithDetails,
            note: document.getElementById('pharmacistNote').value,
            pharmacist_name: pharmacistName,
            pharmacist_license: document.getElementById('pharmacistLicense').value,
            add_to_cart: true,
            acknowledged_interactions: acknowledgedInteractions,
            prescription_approval_id: selectedRxApprovalId
        })
    })
    .then(r => r.json().then(j => ({ status: r.status, body: j })))
    .then(({ status, body }) => {
        if (body.success) {
            alert('อนุมัติเรียบร้อย สินค้าถูกเพิ่มลงตะกร้าลูกค้าแล้ว');
            window.location.href = 'pharmacy.php?tab=dashboard';
        } else if (status === 422 && body.error === 'interaction_ack_required') {
            // Server-side caught a finding the client missed — re-open modal with server's findings.
            const findings = collectFindings(body.report || {});
            showInteractionModal(findings, (acknowledgedKeys) => {
                submitApprove(drugsWithDetails, pharmacistName, acknowledgedKeys);
            });
        } else {
            alert('เกิดข้อผิดพลาด: ' + (body.error || body.message || 'Unknown'));
        }
    })
    .catch(e => { alert('เกิดข้อผิดพลาด: ' + e.message); });
}

function collectFindings(report) {
    const findings = [];
    (report.interactions || []).forEach(i => {
        findings.push({
            kind: 'interaction',
            severity: i.severity || 'moderate',
            drug1: i.drug1 || '', drug2: i.drug2 || '',
            effect: i.effect || i.description || '',
            recommendation: i.recommendation || '',
            key: ((i.drug1 || '') + '|' + (i.drug2 || '')).toLowerCase()
        });
    });
    (report.allergies || []).forEach(a => {
        findings.push({
            kind: 'allergy',
            severity: a.severity || 'severe',
            drug1: a.drug || '', drug2: 'แพ้ ' + (a.allergy || ''),
            effect: a.message || '',
            recommendation: '',
            key: ((a.drug || '') + '|allergy|' + (a.allergy || '')).toLowerCase()
        });
    });
    (report.contraindications || []).forEach(c => {
        findings.push({
            kind: 'contraindication',
            severity: c.severity || 'warning',
            drug1: c.drug || '', drug2: c.condition || '',
            effect: c.reason || '',
            recommendation: '',
            key: ((c.drug || '') + '|contra|' + (c.condition || '')).toLowerCase()
        });
    });
    return findings;
}

function severityBadge(sev) {
    const map = {
        contraindicated: { c: 'bg-red-600 text-white',    label: 'ห้ามใช้' },
        severe:          { c: 'bg-red-500 text-white',    label: 'รุนแรง' },
        moderate:        { c: 'bg-yellow-500 text-white', label: 'ปานกลาง' },
        mild:            { c: 'bg-blue-500 text-white',   label: 'เล็กน้อย' },
        warning:         { c: 'bg-orange-500 text-white', label: 'ระวัง' }
    };
    const m = map[sev] || map.moderate;
    return `<span class="px-2 py-0.5 rounded-full text-xs font-medium ${m.c}">${m.label}</span>`;
}

function showInteractionModal(findings, onConfirm) {
    const modalId = 'interactionModal_' + Date.now();
    const blocking = findings.filter(f => ['severe', 'contraindicated'].includes(f.severity));
    const advisory = findings.filter(f => !['severe', 'contraindicated'].includes(f.severity));

    const rowHtml = (f) => `
        <div class="border rounded-lg p-3 mb-2" data-key="${f.key}">
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1">
                    <div class="font-semibold text-gray-800">${f.drug1} ⇄ ${f.drug2}</div>
                    <div class="text-sm text-gray-600 mt-1">${f.effect || ''}</div>
                    ${f.recommendation ? `<div class="text-xs text-gray-500 mt-1">คำแนะนำ: ${f.recommendation}</div>` : ''}
                </div>
                ${severityBadge(f.severity)}
            </div>
            <label class="flex items-center gap-2 mt-2 text-sm">
                <input type="checkbox" class="ack-cb rounded" data-key="${f.key}">
                <span>ฉันรับทราบและยืนยันการจ่ายยา</span>
            </label>
        </div>`;

    const html = `
        <div id="${modalId}" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col">
                <div class="p-5 border-b">
                    <h3 class="text-lg font-bold text-red-600">⚠️ พบปัญหาความปลอดภัย</h3>
                    <p class="text-sm text-gray-600 mt-1">โปรดทบทวนทุกรายการก่อนจ่ายยา</p>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    ${blocking.length > 0 ? `<div class="mb-4"><h4 class="font-semibold text-red-700 mb-2">ต้องรับทราบเพื่อจ่ายต่อ (${blocking.length})</h4>${blocking.map(rowHtml).join('')}</div>` : ''}
                    ${advisory.length > 0 ? `<div><h4 class="font-semibold text-yellow-700 mb-2">ข้อมูลเพื่อทราบ (${advisory.length})</h4>${advisory.map(rowHtml).join('')}</div>` : ''}
                </div>
                <div class="p-5 border-t flex gap-3 justify-end">
                    <button id="${modalId}_cancel" class="px-4 py-2 rounded bg-gray-100 text-gray-700 hover:bg-gray-200">ยกเลิก</button>
                    <button id="${modalId}_confirm" class="px-4 py-2 rounded bg-teal-600 text-white hover:bg-teal-700 disabled:bg-gray-300" disabled>ยืนยันและจ่ายยา</button>
                </div>
            </div>
        </div>`;

    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    document.body.appendChild(wrapper);

    const modal = document.getElementById(modalId);
    const confirmBtn = document.getElementById(modalId + '_confirm');
    const cancelBtn = document.getElementById(modalId + '_cancel');

    function recompute() {
        const required = blocking.map(f => f.key);
        const ticked = Array.from(modal.querySelectorAll('.ack-cb:checked')).map(cb => cb.dataset.key);
        const allRequiredAcked = required.every(k => ticked.includes(k));
        confirmBtn.disabled = !allRequiredAcked;
    }
    modal.querySelectorAll('.ack-cb').forEach(cb => cb.addEventListener('change', recompute));
    recompute();

    cancelBtn.addEventListener('click', () => { wrapper.remove(); });
    confirmBtn.addEventListener('click', () => {
        const ticked = Array.from(modal.querySelectorAll('.ack-cb:checked')).map(cb => cb.dataset.key);
        wrapper.remove();
        onConfirm(ticked);
    });
}

function rejectCase() {
    const reason = prompt('เหตุผลในการปฏิเสธ:', 'ไม่สามารถแนะนำยาได้ กรุณาพบแพทย์');
    if (reason === null) return;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reject', session_id: sessionId, user_id: userId, reason: reason })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { alert('ส่งข้อความแจ้งลูกค้าแล้ว'); window.location.href = 'pharmacy.php?tab=dashboard'; }
    });
}
</script>
