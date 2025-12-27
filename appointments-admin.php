<?php
/**
 * Appointments Admin - จัดการนัดหมาย
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'จัดการนัดหมาย';

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    if ($action === 'update_status' && $id) {
        $status = $_POST['status'];
        $notes = trim($_POST['notes'] ?? '');
        
        $stmt = $db->prepare("UPDATE appointments SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $notes, $id]);
        
        $message = 'อัพเดทสถานะสำเร็จ!';
        $messageType = 'success';
        
    } elseif ($action === 'cancel' && $id) {
        $reason = trim($_POST['reason'] ?? '');
        $stmt = $db->prepare("UPDATE appointments SET status = 'cancelled', cancelled_by = 'pharmacist', cancelled_reason = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $id]);
        
        $message = 'ยกเลิกนัดหมายสำเร็จ!';
        $messageType = 'success';
    }
}

// Filters
$status = $_GET['status'] ?? '';
$date = $_GET['date'] ?? '';
$pharmacistId = $_GET['pharmacist_id'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = "WHERE 1=1";
$params = [];

if ($status) {
    $where .= " AND a.status = ?";
    $params[] = $status;
}
if ($date) {
    $where .= " AND a.appointment_date = ?";
    $params[] = $date;
}
if ($pharmacistId) {
    $where .= " AND a.pharmacist_id = ?";
    $params[] = $pharmacistId;
}
if ($search) {
    $where .= " AND (a.appointment_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

// Get total
$stmt = $db->prepare("SELECT COUNT(*) FROM appointments a LEFT JOIN users u ON a.user_id = u.id {$where}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// Get appointments
$sql = "SELECT a.id, a.user_id, a.pharmacist_id, a.appointment_date, a.appointment_time, 
        a.status, a.notes, a.created_at, a.updated_at,
        COALESCE(a.appointment_id, CONCAT('APT', LPAD(a.id, 6, '0'))) as appointment_id,
        COALESCE(a.duration, 30) as duration,
        u.first_name, u.last_name, u.phone, u.display_name, u.picture_url,
        p.name as pharmacist_name, p.title as pharmacist_title
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN pharmacists p ON a.pharmacist_id = p.id
        {$where}
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pharmacists for filter
$pharmacists = $db->query("SELECT id, name, title FROM pharmacists WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$stats = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN appointment_date = CURDATE() AND status IN ('pending','confirmed') THEN 1 ELSE 0 END) as today
    FROM appointments")->fetch();

require_once 'includes/header.php';

?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-times-circle' ?> mr-2"></i><?= $message ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <p class="text-gray-500 text-sm">นัดหมายทั้งหมด</p>
        <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total'] ?? 0) ?></p>
    </div>
    <div class="bg-yellow-50 rounded-xl shadow p-4 border border-yellow-200">
        <p class="text-yellow-600 text-sm">รอยืนยัน</p>
        <p class="text-2xl font-bold text-yellow-600"><?= number_format($stats['pending'] ?? 0) ?></p>
    </div>
    <div class="bg-blue-50 rounded-xl shadow p-4 border border-blue-200">
        <p class="text-blue-600 text-sm">ยืนยันแล้ว</p>
        <p class="text-2xl font-bold text-blue-600"><?= number_format($stats['confirmed'] ?? 0) ?></p>
    </div>
    <div class="bg-green-50 rounded-xl shadow p-4 border border-green-200">
        <p class="text-green-600 text-sm">เสร็จสิ้น</p>
        <p class="text-2xl font-bold text-green-600"><?= number_format($stats['completed'] ?? 0) ?></p>
    </div>
    <div class="bg-purple-50 rounded-xl shadow p-4 border border-purple-200">
        <p class="text-purple-600 text-sm">วันนี้</p>
        <p class="text-2xl font-bold text-purple-600"><?= number_format($stats['today'] ?? 0) ?></p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหารหัส, ชื่อ, เบอร์โทร..." 
            class="flex-1 min-w-[200px] px-4 py-2 border rounded-lg">
        <input type="date" name="date" value="<?= $date ?>" class="px-4 py-2 border rounded-lg">
        <select name="pharmacist_id" class="px-4 py-2 border rounded-lg">
            <option value="">เภสัชกรทั้งหมด</option>
            <?php foreach ($pharmacists as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $pharmacistId == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['title'] . $p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="px-4 py-2 border rounded-lg">
            <option value="">ทุกสถานะ</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>รอยืนยัน</option>
            <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>ยืนยันแล้ว</option>
            <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>กำลังดำเนินการ</option>
            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
            <option value="no_show" <?= $status === 'no_show' ? 'selected' : '' ?>>ไม่มา</option>
        </select>
        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
            <i class="fas fa-search mr-2"></i>ค้นหา
        </button>
        <a href="appointments-admin.php" class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200">รีเซ็ต</a>
    </form>
</div>

<!-- Quick Filters -->
<div class="flex gap-2 mb-4 overflow-x-auto pb-2">
    <a href="?date=<?= date('Y-m-d') ?>" class="px-4 py-2 bg-purple-100 text-purple-600 rounded-full text-sm font-medium whitespace-nowrap hover:bg-purple-200">
        📅 วันนี้
    </a>
    <a href="?date=<?= date('Y-m-d', strtotime('+1 day')) ?>" class="px-4 py-2 bg-blue-100 text-blue-600 rounded-full text-sm font-medium whitespace-nowrap hover:bg-blue-200">
        📆 พรุ่งนี้
    </a>
    <a href="?status=pending" class="px-4 py-2 bg-yellow-100 text-yellow-600 rounded-full text-sm font-medium whitespace-nowrap hover:bg-yellow-200">
        ⏳ รอยืนยัน
    </a>
    <a href="?status=confirmed" class="px-4 py-2 bg-green-100 text-green-600 rounded-full text-sm font-medium whitespace-nowrap hover:bg-green-200">
        ✅ ยืนยันแล้ว
    </a>
</div>

<!-- Appointments Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">รหัส</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">ลูกค้า</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">เภสัชกร</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">วัน/เวลา</th>
                    <th class="px-4 py-3 text-center text-sm font-medium text-gray-600">สถานะ</th>
                    <th class="px-4 py-3 text-center text-sm font-medium text-gray-600">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($appointments as $apt): ?>
                <?php
                $statusColors = [
                    'pending' => 'bg-yellow-100 text-yellow-700',
                    'confirmed' => 'bg-blue-100 text-blue-700',
                    'in_progress' => 'bg-purple-100 text-purple-700',
                    'completed' => 'bg-green-100 text-green-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                    'no_show' => 'bg-gray-100 text-gray-700'
                ];
                $statusLabels = [
                    'pending' => 'รอยืนยัน',
                    'confirmed' => 'ยืนยันแล้ว',
                    'in_progress' => 'กำลังดำเนินการ',
                    'completed' => 'เสร็จสิ้น',
                    'cancelled' => 'ยกเลิก',
                    'no_show' => 'ไม่มา'
                ];
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <span class="font-mono text-sm font-medium text-purple-600"><?= $apt['appointment_id'] ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img src="<?= $apt['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full object-cover">
                            <div>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars(($apt['first_name'] ?? '') . ' ' . ($apt['last_name'] ?? '')) ?: $apt['display_name'] ?></p>
                                <p class="text-xs text-gray-500"><?= $apt['phone'] ?: '-' ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium"><?= htmlspecialchars($apt['pharmacist_title'] . $apt['pharmacist_name']) ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium"><?= date('d/m/Y', strtotime($apt['appointment_date'])) ?></p>
                        <p class="text-sm text-gray-500"><?= date('H:i', strtotime($apt['appointment_time'])) ?> น. (<?= $apt['duration'] ?> นาที)</p>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-3 py-1 rounded-full text-xs font-medium <?= $statusColors[$apt['status']] ?? '' ?>">
                            <?= $statusLabels[$apt['status']] ?? $apt['status'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($apt)) ?>)" 
                            class="px-3 py-1 bg-blue-100 text-blue-600 rounded-lg text-sm hover:bg-blue-200" title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php if (!in_array($apt['status'], ['completed', 'cancelled', 'no_show'])): ?>
                        <button onclick="openStatusModal(<?= $apt['id'] ?>, '<?= $apt['status'] ?>')" 
                            class="px-3 py-1 bg-green-100 text-green-600 rounded-lg text-sm hover:bg-green-200" title="อัพเดทสถานะ">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($appointments)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">ไม่พบนัดหมาย</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t flex justify-between items-center">
        <p class="text-sm text-gray-500">แสดง <?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> จาก <?= $total ?> รายการ</p>
        <div class="flex gap-1">
            <?php for ($i = 1; $i <= min($totalPages, 10); $i++): ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&date=<?= $date ?>&pharmacist_id=<?= $pharmacistId ?>" 
                class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- Detail Modal -->
<div id="detailModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold"><i class="fas fa-calendar-check text-purple-500 mr-2"></i>รายละเอียดนัดหมาย</h3>
            <button onclick="closeDetailModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div id="detailContent"></div>
    </div>
</div>

<!-- Status Modal -->
<div id="statusModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-bold mb-4"><i class="fas fa-edit text-green-500 mr-2"></i>อัพเดทสถานะ</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" id="status_apt_id">
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">สถานะ</label>
                <select name="status" id="status_select" class="w-full px-4 py-2 border rounded-lg">
                    <option value="pending">รอยืนยัน</option>
                    <option value="confirmed">ยืนยันแล้ว</option>
                    <option value="in_progress">กำลังดำเนินการ</option>
                    <option value="completed">เสร็จสิ้น</option>
                    <option value="no_show">ไม่มา</option>
                </select>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <textarea name="notes" rows="3" class="w-full px-4 py-2 border rounded-lg" placeholder="บันทึกเพิ่มเติม..."></textarea>
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="closeStatusModal()" class="flex-1 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
            </div>
        </form>
        
        <hr class="my-4">
        
        <form method="POST" onsubmit="return confirm('ยืนยันการยกเลิกนัดหมาย?')">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" id="cancel_apt_id">
            <div class="mb-3">
                <label class="block text-sm font-medium mb-1 text-red-600">ยกเลิกนัดหมาย</label>
                <input type="text" name="reason" class="w-full px-4 py-2 border border-red-200 rounded-lg" placeholder="เหตุผลในการยกเลิก">
            </div>
            <button type="submit" class="w-full py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">
                <i class="fas fa-times mr-2"></i>ยกเลิกนัดหมาย
            </button>
        </form>
    </div>
</div>

<script>
function openDetailModal(apt) {
    const statusLabels = {
        'pending': '<span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs">รอยืนยัน</span>',
        'confirmed': '<span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs">ยืนยันแล้ว</span>',
        'in_progress': '<span class="px-2 py-1 bg-purple-100 text-purple-700 rounded-full text-xs">กำลังดำเนินการ</span>',
        'completed': '<span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs">เสร็จสิ้น</span>',
        'cancelled': '<span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs">ยกเลิก</span>',
        'no_show': '<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded-full text-xs">ไม่มา</span>'
    };
    
    // Handle undefined/null values
    const duration = apt.duration || apt.consultation_duration || 15;
    const symptoms = apt.symptoms || apt.reason || '';
    
    const html = `
        <div class="space-y-4">
            <div class="flex justify-between items-center">
                <span class="font-mono text-purple-600 font-bold">${apt.appointment_id || '-'}</span>
                ${statusLabels[apt.status] || apt.status}
            </div>
            
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500 mb-1">ลูกค้า</p>
                <p class="font-medium">${apt.first_name || ''} ${apt.last_name || apt.display_name || '-'}</p>
                <p class="text-sm text-gray-500">${apt.phone || '-'}</p>
            </div>
            
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500 mb-1">เภสัชกร</p>
                <p class="font-medium">${apt.pharmacist_title || ''}${apt.pharmacist_name || '-'}</p>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div class="p-4 bg-purple-50 rounded-lg">
                    <p class="text-sm text-purple-600 mb-1">📅 วันที่</p>
                    <p class="font-medium">${new Date(apt.appointment_date).toLocaleDateString('th-TH', {day:'numeric',month:'short',year:'numeric'})}</p>
                </div>
                <div class="p-4 bg-blue-50 rounded-lg">
                    <p class="text-sm text-blue-600 mb-1">⏰ เวลา</p>
                    <p class="font-medium">${(apt.appointment_time || '').substring(0,5)} น. (${duration} นาที)</p>
                </div>
            </div>
            
            ${symptoms ? `
            <div class="p-4 bg-yellow-50 rounded-lg">
                <p class="text-sm text-yellow-600 mb-1">💊 อาการ/เหตุผล</p>
                <p>${symptoms}</p>
            </div>
            ` : ''}
            
            ${apt.notes ? `
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500 mb-1">📝 หมายเหตุ</p>
                <p>${apt.notes}</p>
            </div>
            ` : ''}
            
            ${apt.rating ? `
            <div class="p-4 bg-green-50 rounded-lg">
                <p class="text-sm text-green-600 mb-1">⭐ คะแนน</p>
                <p class="font-medium">${'⭐'.repeat(apt.rating)} (${apt.rating}/5)</p>
                ${apt.review ? `<p class="text-sm mt-1">${apt.review}</p>` : ''}
            </div>
            ` : ''}
            
            ${apt.cancelled_reason ? `
            <div class="p-4 bg-red-50 rounded-lg">
                <p class="text-sm text-red-600 mb-1">❌ เหตุผลยกเลิก</p>
                <p>${apt.cancelled_reason}</p>
                <p class="text-xs text-gray-500 mt-1">โดย: ${apt.cancelled_by === 'user' ? 'ลูกค้า' : 'เภสัชกร'}</p>
            </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('detailContent').innerHTML = html;
    document.getElementById('detailModal').classList.remove('hidden');
    document.getElementById('detailModal').classList.add('flex');
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.add('hidden');
    document.getElementById('detailModal').classList.remove('flex');
}

function openStatusModal(id, currentStatus) {
    document.getElementById('status_apt_id').value = id;
    document.getElementById('cancel_apt_id').value = id;
    document.getElementById('status_select').value = currentStatus;
    document.getElementById('statusModal').classList.remove('hidden');
    document.getElementById('statusModal').classList.add('flex');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
    document.getElementById('statusModal').classList.remove('flex');
}
</script>

<?php require_once 'includes/footer.php'; ?>
