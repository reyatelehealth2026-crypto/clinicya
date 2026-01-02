<?php
/**
 * Dispense Drugs Tab Content
 * หน้าจ่ายยาและกรอกรายละเอียด
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

$sessionId = (int)($_GET['session_id'] ?? 0);

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
        return `
        <div class="drug-item" onclick='selectDrug(${JSON.stringify({id: drug.id, name: name, genericName: genericName, price: drug.price || 0})})'>
            <div class="font-medium">${name}</div>
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
        isNonDrug: false, indication: '', dosage: '1', unit: 'เม็ด',
        morning: false, noon: false, evening: false, bedtime: false,
        instructions: '', warning: '', quantity: 1
    });
    document.getElementById('drugSearch').value = '';
    document.getElementById('searchResults').classList.remove('show');
    renderSelectedDrugs();
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
        
        return `
        <div class="selected-drug-card" data-idx="${idx}">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <div class="font-bold text-gray-800">${drug.name}</div>
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
            </div>
            `}
        </div>`;
    }).join('');
    
    document.getElementById('totalPrice').textContent = '฿' + total.toLocaleString();
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
    if (!confirm('ยืนยันอนุมัติและเพิ่มลงตะกร้าลูกค้า?')) return;
    
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
            instructions: drug.instructions || '', warning: drug.warning || ''
        };
    });
    
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
            add_to_cart: true
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('อนุมัติเรียบร้อย สินค้าถูกเพิ่มลงตะกร้าลูกค้าแล้ว');
            window.location.href = 'pharmacy.php?tab=dashboard';
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
        }
    })
    .catch(e => { alert('เกิดข้อผิดพลาด: ' + e.message); });
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
