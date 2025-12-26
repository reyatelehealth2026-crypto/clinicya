<?php
/**
 * Pharmacists Management - จัดการเภสัชกร
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'จัดการเภสัชกร';

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name']);
        $title = trim($_POST['title'] ?? 'ภก.');
        $specialty = trim($_POST['specialty'] ?? '');
        $licenseNo = trim($_POST['license_no'] ?? '');
        $hospital = trim($_POST['hospital'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');
        $consultationFee = (float)($_POST['consultation_fee'] ?? 0);
        $consultationDuration = (int)($_POST['consultation_duration'] ?? 15);
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id) {
            $stmt = $db->prepare("UPDATE pharmacists SET 
                name=?, title=?, specialty=?, license_no=?, hospital=?, bio=?, image_url=?,
                consultation_fee=?, consultation_duration=?, is_available=?, is_active=?
                WHERE id=?");
            $stmt->execute([$name, $title, $specialty, $licenseNo, $hospital, $bio, $imageUrl, 
                $consultationFee, $consultationDuration, $isAvailable, $isActive, $id]);
            $message = 'อัพเดทข้อมูลเภสัชกรสำเร็จ!';
        } else {
            $stmt = $db->prepare("INSERT INTO pharmacists 
                (name, title, specialty, license_no, hospital, bio, image_url, consultation_fee, consultation_duration, is_available, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $title, $specialty, $licenseNo, $hospital, $bio, $imageUrl, 
                $consultationFee, $consultationDuration, $isAvailable, $isActive]);
            $id = $db->lastInsertId();
            $message = 'เพิ่มเภสัชกรสำเร็จ!';
        }
        $messageType = 'success';
        
        // Save schedules
        if (isset($_POST['schedules'])) {
            $db->prepare("DELETE FROM pharmacist_schedules WHERE pharmacist_id = ?")->execute([$id]);
            $scheduleStmt = $db->prepare("INSERT INTO pharmacist_schedules (pharmacist_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, 1)");
            
            foreach ($_POST['schedules'] as $day => $times) {
                if (!empty($times['start']) && !empty($times['end'])) {
                    $scheduleStmt->execute([$id, $day, $times['start'], $times['end']]);
                }
            }
        }
        
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Check if has appointments
        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE pharmacist_id = ? AND status NOT IN ('completed','cancelled')");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $message = 'ไม่สามารถลบได้ เนื่องจากมีนัดหมายที่ยังไม่เสร็จสิ้น';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare("DELETE FROM pharmacists WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'ลบเภสัชกรสำเร็จ!';
            $messageType = 'success';
        }
        
    } elseif ($action === 'add_holiday') {
        $pharmacistId = (int)$_POST['pharmacist_id'];
        $holidayDate = $_POST['holiday_date'];
        $reason = trim($_POST['reason'] ?? '');
        
        $stmt = $db->prepare("INSERT INTO pharmacist_holidays (pharmacist_id, holiday_date, reason) VALUES (?, ?, ?)");
        $stmt->execute([$pharmacistId, $holidayDate, $reason]);
        $message = 'เพิ่มวันหยุดสำเร็จ!';
        $messageType = 'success';
        
    } elseif ($action === 'delete_holiday') {
        $id = (int)$_POST['holiday_id'];
        $db->prepare("DELETE FROM pharmacist_holidays WHERE id = ?")->execute([$id]);
        $message = 'ลบวันหยุดสำเร็จ!';
        $messageType = 'success';
    }
}

// Get pharmacists - check if sort_order column exists
$pharmacists = [];
try {
    $cols = $db->query("SHOW COLUMNS FROM pharmacists")->fetchAll(PDO::FETCH_COLUMN);
    $hasSortOrder = in_array('sort_order', $cols);
    $orderBy = $hasSortOrder ? "ORDER BY sort_order ASC, name ASC" : "ORDER BY name ASC";
    
    $pharmacists = $db->query("SELECT p.*, 
        (SELECT COUNT(*) FROM appointments WHERE pharmacist_id = p.id AND status = 'completed') as completed_count,
        (SELECT COUNT(*) FROM appointments WHERE pharmacist_id = p.id AND status IN ('pending','confirmed') AND appointment_date >= CURDATE()) as upcoming_count
        FROM pharmacists p {$orderBy}")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pharmacists = [];
}

// Get schedules for each pharmacist
foreach ($pharmacists as &$p) {
    $stmt = $db->prepare("SELECT * FROM pharmacist_schedules WHERE pharmacist_id = ?");
    $stmt->execute([$p['id']]);
    $p['schedules'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming holidays
    $stmt = $db->prepare("SELECT * FROM pharmacist_holidays WHERE pharmacist_id = ? AND holiday_date >= CURDATE() ORDER BY holiday_date LIMIT 5");
    $stmt->execute([$p['id']]);
    $p['holidays'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$dayNames = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-times-circle' ?> mr-2"></i><?= $message ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="flex justify-between items-center mb-6">
    <div>
        <p class="text-gray-500">จัดการข้อมูลเภสัชกรและตารางเวลา</p>
    </div>
    <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
        <i class="fas fa-plus mr-2"></i>เพิ่มเภสัชกร
    </button>
</div>

<!-- Pharmacists Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($pharmacists as $p): ?>
    <div class="bg-white rounded-xl shadow overflow-hidden <?= !$p['is_active'] ? 'opacity-60' : '' ?>">
        <div class="p-6">
            <div class="flex items-start gap-4 mb-4">
                <img src="<?= $p['image_url'] ?: 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($p['name']) ?>" 
                    class="w-16 h-16 rounded-full object-cover border-2 border-green-200">
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($p['title'] . $p['name']) ?></h3>
                        <?php if ($p['is_available']): ?>
                        <span class="w-2 h-2 bg-green-500 rounded-full" title="พร้อมให้บริการ"></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($p['specialty'] ?: 'เภสัชกรทั่วไป') ?></p>
                    <?php if ($p['license_no']): ?>
                    <p class="text-xs text-gray-400">ใบอนุญาต: <?= $p['license_no'] ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex items-center gap-4 mb-4 text-sm">
                <div class="flex items-center gap-1 text-yellow-500">
                    <i class="fas fa-star"></i>
                    <span><?= number_format($p['rating'], 1) ?></span>
                    <span class="text-gray-400">(<?= $p['review_count'] ?>)</span>
                </div>
                <div class="text-gray-500">
                    <i class="fas fa-clock mr-1"></i><?= $p['consultation_duration'] ?> นาที
                </div>
                <?php if ($p['consultation_fee'] > 0): ?>
                <div class="text-green-600 font-medium">
                    ฿<?= number_format($p['consultation_fee']) ?>
                </div>
                <?php else: ?>
                <div class="text-green-600 font-medium">ฟรี</div>
                <?php endif; ?>
            </div>
            
            <!-- Stats -->
            <div class="grid grid-cols-2 gap-2 mb-4">
                <div class="p-2 bg-blue-50 rounded-lg text-center">
                    <p class="text-lg font-bold text-blue-600"><?= $p['upcoming_count'] ?></p>
                    <p class="text-xs text-blue-500">นัดหมายรอ</p>
                </div>
                <div class="p-2 bg-green-50 rounded-lg text-center">
                    <p class="text-lg font-bold text-green-600"><?= $p['completed_count'] ?></p>
                    <p class="text-xs text-green-500">เสร็จสิ้น</p>
                </div>
            </div>
            
            <!-- Schedule Preview -->
            <div class="mb-4">
                <p class="text-xs font-medium text-gray-500 mb-2">ตารางเวลา:</p>
                <div class="flex flex-wrap gap-1">
                    <?php 
                    $scheduleDays = array_column($p['schedules'], 'day_of_week');
                    foreach ($dayNames as $i => $day): 
                    ?>
                    <span class="px-2 py-0.5 rounded text-xs <?= in_array($i, $scheduleDays) ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' ?>">
                        <?= mb_substr($day, 0, 1) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex gap-2">
                <button onclick="editPharmacist(<?= htmlspecialchars(json_encode($p)) ?>)" 
                    class="flex-1 py-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 text-sm">
                    <i class="fas fa-edit mr-1"></i>แก้ไข
                </button>
                <button onclick="openHolidayModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>')" 
                    class="flex-1 py-2 bg-yellow-100 text-yellow-600 rounded-lg hover:bg-yellow-200 text-sm">
                    <i class="fas fa-calendar-times mr-1"></i>วันหยุด
                </button>
                <form method="POST" class="inline" onsubmit="return confirm('ยืนยันการลบ?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="py-2 px-3 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 text-sm">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($pharmacists)): ?>
    <div class="col-span-full text-center py-12 text-gray-500">
        <i class="fas fa-user-md text-5xl mb-4 text-gray-300"></i>
        <p>ยังไม่มีเภสัชกร</p>
        <button onclick="openModal()" class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg">เพิ่มเภสัชกรคนแรก</button>
    </div>
    <?php endif; ?>
</div>


<!-- Add/Edit Modal -->
<div id="pharmacistModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-bold mb-4" id="modalTitle"><i class="fas fa-user-md text-green-500 mr-2"></i>เพิ่มเภสัชกร</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="pharm_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">คำนำหน้า</label>
                    <select name="title" id="pharm_title" class="w-full px-4 py-2 border rounded-lg">
                        <option value="ภก.">ภก. (ชาย)</option>
                        <option value="ภญ.">ภญ. (หญิง)</option>
                        <option value="ดร.">ดร.</option>
                        <option value="">ไม่ระบุ</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อ-นามสกุล *</label>
                    <input type="text" name="name" id="pharm_name" required class="w-full px-4 py-2 border rounded-lg">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ความเชี่ยวชาญ</label>
                    <input type="text" name="specialty" id="pharm_specialty" class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น เภสัชกรคลินิก">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เลขใบอนุญาต</label>
                    <input type="text" name="license_no" id="pharm_license" class="w-full px-4 py-2 border rounded-lg">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">สถานที่ทำงาน</label>
                <input type="text" name="hospital" id="pharm_hospital" class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น โรงพยาบาล, คลินิก, ร้านยา">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">ประวัติย่อ</label>
                <textarea name="bio" id="pharm_bio" rows="2" class="w-full px-4 py-2 border rounded-lg" placeholder="ประสบการณ์ ความเชี่ยวชาญ..."></textarea>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">URL รูปภาพ</label>
                <input type="url" name="image_url" id="pharm_image" class="w-full px-4 py-2 border rounded-lg" placeholder="https://...">
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ค่าปรึกษา (บาท)</label>
                    <input type="number" name="consultation_fee" id="pharm_fee" min="0" class="w-full px-4 py-2 border rounded-lg" value="0">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ระยะเวลาต่อครั้ง (นาที)</label>
                    <input type="number" name="consultation_duration" id="pharm_duration" min="5" class="w-full px-4 py-2 border rounded-lg" value="15">
                </div>
            </div>
            
            <div class="flex gap-4 mb-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_available" id="pharm_available" checked class="w-4 h-4">
                    <span>พร้อมให้บริการ</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="pharm_active" checked class="w-4 h-4">
                    <span>เปิดใช้งาน</span>
                </label>
            </div>
            
            <!-- Schedule -->
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">ตารางเวลาทำงาน</label>
                <div class="space-y-2" id="scheduleInputs">
                    <?php foreach ($dayNames as $i => $day): ?>
                    <div class="flex items-center gap-2">
                        <span class="w-20 text-sm"><?= $day ?></span>
                        <input type="time" name="schedules[<?= $i ?>][start]" id="sched_<?= $i ?>_start" class="px-3 py-1 border rounded text-sm">
                        <span>-</span>
                        <input type="time" name="schedules[<?= $i ?>][end]" id="sched_<?= $i ?>_end" class="px-3 py-1 border rounded text-sm">
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 mt-1">เว้นว่างถ้าไม่ทำงานวันนั้น</p>
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="closeModal()" class="flex-1 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Holiday Modal -->
<div id="holidayModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-bold mb-4"><i class="fas fa-calendar-times text-yellow-500 mr-2"></i>จัดการวันหยุด</h3>
        <p class="text-gray-600 mb-4" id="holidayPharmName"></p>
        
        <form method="POST" class="mb-4">
            <input type="hidden" name="action" value="add_holiday">
            <input type="hidden" name="pharmacist_id" id="holiday_pharm_id">
            
            <div class="flex gap-2 mb-2">
                <input type="date" name="holiday_date" required class="flex-1 px-4 py-2 border rounded-lg" min="<?= date('Y-m-d') ?>">
                <input type="text" name="reason" class="flex-1 px-4 py-2 border rounded-lg" placeholder="เหตุผล (ไม่บังคับ)">
            </div>
            <button type="submit" class="w-full py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600">
                <i class="fas fa-plus mr-2"></i>เพิ่มวันหยุด
            </button>
        </form>
        
        <div id="holidayList" class="space-y-2 max-h-40 overflow-y-auto"></div>
        
        <button type="button" onclick="closeHolidayModal()" class="w-full mt-4 py-2 border rounded-lg hover:bg-gray-50">ปิด</button>
    </div>
</div>

<script>
const dayNames = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

function openModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-md text-green-500 mr-2"></i>เพิ่มเภสัชกร';
    document.getElementById('pharm_id').value = '';
    document.getElementById('pharm_title').value = 'ภก.';
    document.getElementById('pharm_name').value = '';
    document.getElementById('pharm_specialty').value = '';
    document.getElementById('pharm_license').value = '';
    document.getElementById('pharm_hospital').value = '';
    document.getElementById('pharm_bio').value = '';
    document.getElementById('pharm_image').value = '';
    document.getElementById('pharm_fee').value = '0';
    document.getElementById('pharm_duration').value = '15';
    document.getElementById('pharm_available').checked = true;
    document.getElementById('pharm_active').checked = true;
    
    // Clear schedules
    for (let i = 0; i < 7; i++) {
        document.getElementById('sched_' + i + '_start').value = '';
        document.getElementById('sched_' + i + '_end').value = '';
    }
    
    document.getElementById('pharmacistModal').classList.remove('hidden');
    document.getElementById('pharmacistModal').classList.add('flex');
}

function editPharmacist(p) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-blue-500 mr-2"></i>แก้ไขเภสัชกร';
    document.getElementById('pharm_id').value = p.id;
    document.getElementById('pharm_title').value = p.title || 'ภก.';
    document.getElementById('pharm_name').value = p.name;
    document.getElementById('pharm_specialty').value = p.specialty || '';
    document.getElementById('pharm_license').value = p.license_no || '';
    document.getElementById('pharm_hospital').value = p.hospital || '';
    document.getElementById('pharm_bio').value = p.bio || '';
    document.getElementById('pharm_image').value = p.image_url || '';
    document.getElementById('pharm_fee').value = p.consultation_fee || 0;
    document.getElementById('pharm_duration').value = p.consultation_duration || 15;
    document.getElementById('pharm_available').checked = p.is_available == 1;
    document.getElementById('pharm_active').checked = p.is_active == 1;
    
    // Clear schedules first
    for (let i = 0; i < 7; i++) {
        document.getElementById('sched_' + i + '_start').value = '';
        document.getElementById('sched_' + i + '_end').value = '';
    }
    
    // Fill schedules
    if (p.schedules) {
        p.schedules.forEach(s => {
            document.getElementById('sched_' + s.day_of_week + '_start').value = s.start_time.substring(0, 5);
            document.getElementById('sched_' + s.day_of_week + '_end').value = s.end_time.substring(0, 5);
        });
    }
    
    document.getElementById('pharmacistModal').classList.remove('hidden');
    document.getElementById('pharmacistModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('pharmacistModal').classList.add('hidden');
    document.getElementById('pharmacistModal').classList.remove('flex');
}

function openHolidayModal(id, name) {
    document.getElementById('holiday_pharm_id').value = id;
    document.getElementById('holidayPharmName').textContent = 'เภสัชกร: ' + name;
    
    // Load holidays
    const pharmacists = <?= json_encode($pharmacists) ?>;
    const pharm = pharmacists.find(p => p.id == id);
    let html = '';
    if (pharm && pharm.holidays && pharm.holidays.length > 0) {
        pharm.holidays.forEach(h => {
            const date = new Date(h.holiday_date);
            html += `<div class="flex items-center justify-between p-2 bg-gray-50 rounded">
                <div>
                    <span class="font-medium">${date.toLocaleDateString('th-TH', {day:'numeric',month:'short',year:'numeric'})}</span>
                    ${h.reason ? `<span class="text-sm text-gray-500 ml-2">(${h.reason})</span>` : ''}
                </div>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="delete_holiday">
                    <input type="hidden" name="holiday_id" value="${h.id}">
                    <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i></button>
                </form>
            </div>`;
        });
    } else {
        html = '<p class="text-gray-500 text-center py-4">ไม่มีวันหยุดที่กำหนด</p>';
    }
    document.getElementById('holidayList').innerHTML = html;
    
    document.getElementById('holidayModal').classList.remove('hidden');
    document.getElementById('holidayModal').classList.add('flex');
}

function closeHolidayModal() {
    document.getElementById('holidayModal').classList.add('hidden');
    document.getElementById('holidayModal').classList.remove('flex');
}
</script>

<?php require_once 'includes/footer.php'; ?>
