<?php
/**
 * Dispense Drugs - หน้าจ่ายยาและกรอกรายละเอียด
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'จ่ายยา';

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) {
    header('Location: pharmacist-dashboard.php');
    exit;
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
    header('Location: pharmacist-dashboard.php');
    exit;
}

$triageData = json_decode($session['triage_data'] ?? '{}', true);
$symptoms = $triageData['symptoms'] ?? [];
$severity = $triageData['severity'] ?? null;
$redFlags = $triageData['red_flags'] ?? [];
$isEmergency = $session['current_state'] === 'emergency';

require_once __DIR__ . '/includes/header.php';
?>

<style>
.drug-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    max-height: 300px;
    overflow-y: auto;
    z-index: 50;
    display: none;
}
.drug-search-results.show { display: block; }
.drug-item { padding: 0.75rem 1rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; }
.drug-item:hover { background: #f0fdf4; }
.drug-item:last-child { border-bottom: none; }
.selected-drug-card { 
    background: white; 
    border: 1px solid #e5e7eb; 
    border-radius: 0.75rem; 
    padding: 1rem;
    margin-bottom: 1rem;
}
.timing-btn { 
    padding: 0.25rem 0.75rem; 
    border-radius: 9999px; 
    font-size: 0.75rem;
    border: 1px solid #d1d5db;
    background: white;
    cursor: pointer;
    transition: all 0.2s;
}
.timing-btn.active { background: #10b981; color: white; border-color: #10b981; }
</style>

<div class="container mx-auto px-4 py-6 max-w-5xl">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <a href="pharmacist-dashboard.php" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left text-xl"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">💊 จ่ายยา</h1>
                <p class="text-gray-500">Session #<?= $sessionId ?></p>
            </div>
        </div>
        <?php if ($isEmergency): ?>
        <span class="px-4 py-2 bg-red-500 text-white rounded-full font-medium">
            🚨 กรณีฉุกเฉิน
        </span>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: Patient Info -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm p-6 sticky top-4">
                <div class="flex items-center gap-4 mb-4">
                    <img src="<?= htmlspecialchars($session['picture_url'] ?? 'assets/images/default-avatar.png') ?>" 
                         class="w-16 h-16 rounded-full object-cover">
                    <div>
                        <h3 class="font-bold text-lg"><?= htmlspecialchars($session['display_name'] ?? 'ไม่ระบุชื่อ') ?></h3>
                        <p class="text-gray-500 text-sm"><?= htmlspecialchars($session['phone'] ?? '-') ?></p>
                    </div>
                </div>
                
                <div class="space-y-3 text-sm">
                    <?php if (!empty($symptoms)): ?>
                    <div>
                        <span class="text-gray-500">อาการ:</span>
                        <p class="font-medium"><?= htmlspecialchars(is_array($symptoms) ? implode(', ', $symptoms) : $symptoms) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($severity): ?>
                    <div>
                        <span class="text-gray-500">ความรุนแรง:</span>
                        <span class="font-medium <?= $severity >= 7 ? 'text-red-600' : ($severity >= 4 ? 'text-yellow-600' : 'text-green-600') ?>">
                            <?= $severity ?>/10
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($session['drug_allergies'])): ?>
                    <div class="p-2 bg-red-50 rounded-lg">
                        <span class="text-red-600 font-medium">⚠️ แพ้ยา:</span>
                        <p class="text-red-700"><?= htmlspecialchars($session['drug_allergies']) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($session['medical_conditions'])): ?>
                    <div>
                        <span class="text-gray-500">โรคประจำตัว:</span>
                        <p><?= htmlspecialchars($session['medical_conditions']) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($redFlags)): ?>
                    <div class="p-2 bg-red-50 rounded-lg">
                        <span class="text-red-600 font-medium">🚩 Red Flags:</span>
                        <?php foreach ($redFlags as $flag): ?>
                        <p class="text-red-700 text-xs">• <?= htmlspecialchars(is_array($flag) ? ($flag['message'] ?? '') : $flag) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Drug Selection -->
        <div class="lg:col-span-2">
            <!-- Drug Search -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <h3 class="font-bold text-lg mb-4"><i class="fas fa-search text-green-500 mr-2"></i>ค้นหายา</h3>
                <div class="relative">
                    <input type="text" id="drugSearch" placeholder="พิมพ์ชื่อยา..." 
                           class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-0 text-lg"
                           autocomplete="off">
                    <div id="searchResults" class="drug-search-results"></div>
                </div>
            </div>

            <!-- Selected Drugs -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <h3 class="font-bold text-lg mb-4"><i class="fas fa-pills text-green-500 mr-2"></i>ยาที่เลือก</h3>
                <div id="selectedDrugs">
                    <p class="text-gray-400 text-center py-8" id="noDrugsMessage">ยังไม่ได้เลือกยา</p>
                </div>
                
                <div id="totalSection" class="hidden border-t pt-4 mt-4">
                    <div class="flex justify-between items-center text-lg font-bold">
                        <span>รวมทั้งหมด</span>
                        <span class="text-green-600" id="totalPrice">฿0</span>
                    </div>
                </div>
            </div>

            <!-- Pharmacist Info -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <h3 class="font-bold text-lg mb-4"><i class="fas fa-user-md text-blue-500 mr-2"></i>ข้อมูลเภสัชกร</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-500 mb-1">ชื่อเภสัชกร</label>
                        <input type="text" id="pharmacistName" value="<?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? '') ?>" 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-500 mb-1">เลขใบอนุญาต</label>
                        <input type="text" id="pharmacistLicense" placeholder="ภ.XXXXX" 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-sm text-gray-500 mb-1">หมายเหตุถึงลูกค้า</label>
                    <textarea id="pharmacistNote" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" 
                              placeholder="คำแนะนำเพิ่มเติม..."></textarea>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-4">
                <button onclick="rejectCase()" class="flex-1 px-6 py-3 bg-red-100 text-red-700 rounded-xl font-medium hover:bg-red-200">
                    <i class="fas fa-times mr-2"></i>ปฏิเสธ
                </button>
                <button onclick="approveAndSend()" class="flex-1 px-6 py-3 bg-green-500 text-white rounded-xl font-medium hover:bg-green-600">
                    <i class="fas fa-check mr-2"></i>อนุมัติและส่งยา
                </button>
            </div>
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

// Load all drugs on page load
document.addEventListener('DOMContentLoaded', () => {
    loadAllDrugs();
});

function loadAllDrugs() {
    const searchInput = document.getElementById('drugSearch');
    searchInput.placeholder = 'กำลังโหลดรายการยา...';
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
            searchInput.placeholder = `พิมพ์ชื่อยา... (${allDrugs.length} รายการ)`;
            searchInput.disabled = false;
            console.log('Loaded', allDrugs.length, 'drugs');
        } else {
            searchInput.placeholder = 'เกิดข้อผิดพลาด: ' + (data.error || 'Unknown');
            console.error('Failed to load drugs:', data.error);
        }
    })
    .catch(err => {
        searchInput.placeholder = 'เกิดข้อผิดพลาดในการโหลด';
        console.error('Error loading drugs:', err);
    });
}

// Drug search with autocomplete
document.getElementById('drugSearch').addEventListener('input', function(e) {
    const query = e.target.value.trim().toLowerCase();
    
    clearTimeout(searchTimeout);
    
    if (query.length < 1) {
        document.getElementById('searchResults').classList.remove('show');
        return;
    }
    
    if (!drugsLoaded) {
        document.getElementById('searchResults').innerHTML = '<div class="p-4 text-gray-400 text-center">กำลังโหลดรายการยา...</div>';
        document.getElementById('searchResults').classList.add('show');
        return;
    }
    
    searchTimeout = setTimeout(() => {
        const results = allDrugs.filter(drug => 
            drug.name.toLowerCase().includes(query) || 
            (drug.generic_name && drug.generic_name.toLowerCase().includes(query))
        ).slice(0, 15);
        
        showSearchResults(results);
    }, 150);
});

function showSearchResults(drugs) {
    const container = document.getElementById('searchResults');
    
    if (drugs.length === 0) {
        container.innerHTML = '<div class="p-4 text-gray-400 text-center">ไม่พบยา</div>';
        container.classList.add('show');
        return;
    }
    
    container.innerHTML = drugs.map(drug => `
        <div class="drug-item" onclick="selectDrug(${drug.id}, '${escapeHtml(drug.name)}', ${drug.price || 0})">
            <div class="font-medium">${drug.name}</div>
            <div class="text-sm text-gray-500">
                ${drug.generic_name ? drug.generic_name + ' • ' : ''}฿${drug.price || 0}
            </div>
        </div>
    `).join('');
    
    container.classList.add('show');
}

function escapeHtml(text) {
    return text.replace(/'/g, "\\'").replace(/"/g, '\\"');
}

function selectDrug(id, name, price) {
    // Check if already selected
    if (selectedDrugs.find(d => d.id === id)) {
        alert('ยานี้ถูกเลือกแล้ว');
        return;
    }
    
    selectedDrugs.push({
        id, name, price,
        indication: '',
        dosage: '1',
        morning: false,
        noon: false,
        evening: false,
        bedtime: false,
        instructions: '',
        warning: ''
    });
    
    document.getElementById('drugSearch').value = '';
    document.getElementById('searchResults').classList.remove('show');
    
    renderSelectedDrugs();
}

function renderSelectedDrugs() {
    const container = document.getElementById('selectedDrugs');
    const noDrugsMsg = document.getElementById('noDrugsMessage');
    const totalSection = document.getElementById('totalSection');
    
    if (selectedDrugs.length === 0) {
        noDrugsMsg.style.display = 'block';
        totalSection.classList.add('hidden');
        container.innerHTML = '<p class="text-gray-400 text-center py-8" id="noDrugsMessage">ยังไม่ได้เลือกยา</p>';
        return;
    }
    
    totalSection.classList.remove('hidden');
    
    let total = 0;
    container.innerHTML = selectedDrugs.map((drug, idx) => {
        total += parseFloat(drug.price) || 0;
        return `
        <div class="selected-drug-card" data-drug-id="${drug.id}">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <span class="font-bold text-green-700">💊 ${drug.name}</span>
                    <span class="text-gray-500 ml-2">฿${drug.price}</span>
                </div>
                <button onclick="removeDrug(${drug.id})" class="text-red-400 hover:text-red-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                    <label class="text-gray-500">ข้อบ่งใช้</label>
                    <input type="text" placeholder="เช่น บรรเทาอาการปวด" 
                           value="${drug.indication || ''}"
                           onchange="updateDrug(${drug.id}, 'indication', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1">
                </div>
                <div>
                    <label class="text-gray-500">จำนวน (เม็ด/ครั้ง)</label>
                    <input type="number" min="0.5" step="0.5" value="${drug.dosage || 1}"
                           onchange="updateDrug(${drug.id}, 'dosage', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1">
                </div>
                <div class="md:col-span-2">
                    <label class="text-gray-500">เวลาทาน</label>
                    <div class="flex gap-2 mt-1">
                        <button type="button" class="timing-btn ${drug.morning ? 'active' : ''}" 
                                onclick="toggleTiming(${drug.id}, 'morning', this)">เช้า</button>
                        <button type="button" class="timing-btn ${drug.noon ? 'active' : ''}" 
                                onclick="toggleTiming(${drug.id}, 'noon', this)">กลางวัน</button>
                        <button type="button" class="timing-btn ${drug.evening ? 'active' : ''}" 
                                onclick="toggleTiming(${drug.id}, 'evening', this)">เย็น</button>
                        <button type="button" class="timing-btn ${drug.bedtime ? 'active' : ''}" 
                                onclick="toggleTiming(${drug.id}, 'bedtime', this)">ก่อนนอน</button>
                    </div>
                </div>
                <div>
                    <label class="text-gray-500">วิธีใช้</label>
                    <input type="text" placeholder="เช่น ทานหลังอาหาร" 
                           value="${drug.instructions || ''}"
                           onchange="updateDrug(${drug.id}, 'instructions', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1">
                </div>
                <div>
                    <label class="text-gray-500">คำเตือน</label>
                    <input type="text" placeholder="เช่น ห้ามใช้ในผู้แพ้ยา" 
                           value="${drug.warning || ''}"
                           onchange="updateDrug(${drug.id}, 'warning', this.value)"
                           class="w-full px-2 py-1.5 border rounded mt-1">
                </div>
            </div>
        </div>
        `;
    }).join('');
    
    document.getElementById('totalPrice').textContent = '฿' + total.toLocaleString();
}

function updateDrug(id, field, value) {
    const drug = selectedDrugs.find(d => d.id === id);
    if (drug) drug[field] = value;
}

function toggleTiming(id, timing, btn) {
    const drug = selectedDrugs.find(d => d.id === id);
    if (drug) {
        drug[timing] = !drug[timing];
        btn.classList.toggle('active');
    }
}

function removeDrug(id) {
    selectedDrugs = selectedDrugs.filter(d => d.id !== id);
    renderSelectedDrugs();
}

// Hide search results when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('#drugSearch') && !e.target.closest('#searchResults')) {
        document.getElementById('searchResults').classList.remove('show');
    }
});

function approveAndSend() {
    if (selectedDrugs.length === 0) {
        alert('กรุณาเลือกยาอย่างน้อย 1 รายการ');
        return;
    }
    
    const pharmacistName = document.getElementById('pharmacistName').value;
    if (!pharmacistName) {
        alert('กรุณากรอกชื่อเภสัชกร');
        return;
    }
    
    if (!confirm('ยืนยันอนุมัติและส่งยาให้ลูกค้า?')) return;
    
    const drugsWithDetails = selectedDrugs.map(drug => {
        const timing = [];
        if (drug.morning) timing.push('เช้า');
        if (drug.noon) timing.push('กลางวัน');
        if (drug.evening) timing.push('เย็น');
        if (drug.bedtime) timing.push('ก่อนนอน');
        
        return {
            id: drug.id,
            name: drug.name,
            price: drug.price,
            indication: drug.indication || '',
            dosage: drug.dosage || '1',
            timing: timing.join(', ') || 'ตามอาการ',
            instructions: drug.instructions || '',
            warning: drug.warning || ''
        };
    });
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'approve_drugs',
            session_id: sessionId,
            user_id: userId,
            drugs: drugsWithDetails,
            note: document.getElementById('pharmacistNote').value,
            pharmacist_name: pharmacistName,
            pharmacist_license: document.getElementById('pharmacistLicense').value
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ อนุมัติเรียบร้อย!');
            window.location.href = 'pharmacist-dashboard.php';
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
        }
    })
    .catch(e => {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    });
}

function rejectCase() {
    const reason = prompt('เหตุผลในการปฏิเสธ:', 'ไม่สามารถแนะนำยาได้ กรุณาพบแพทย์');
    if (reason === null) return;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reject',
            session_id: sessionId,
            user_id: userId,
            reason: reason
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('ส่งข้อความแจ้งลูกค้าแล้ว');
            window.location.href = 'pharmacist-dashboard.php';
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
