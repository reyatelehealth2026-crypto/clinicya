<?php
/**
 * Appointments Admin - จัดการนัดหมาย
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/toolbar.php';
require_once __DIR__ . '/includes/components/data-table.php';
require_once __DIR__ . '/includes/components/empty-state.php';
require_once __DIR__ . '/includes/components/pagination.php';
require_once __DIR__ . '/includes/components/modal.php';
require_once __DIR__ . '/includes/components/toast.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'จัดการนัดหมาย';

// Check if appointments table exists
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'appointments'")->fetch();
    if (!$tableCheck) {
        // Create appointments table if not exists
        $db->exec("CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id VARCHAR(50),
            user_id INT,
            pharmacist_id INT,
            appointment_date DATE,
            appointment_time TIME,
            duration INT DEFAULT 30,
            status ENUM('pending','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'pending',
            notes TEXT,
            symptoms TEXT,
            cancelled_by VARCHAR(50),
            cancelled_reason TEXT,
            rating INT,
            review TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
} catch (Exception $e) {
    // Table check failed, continue anyway
}

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    if ($action === 'update_status' && $id) {
        // Whitelist status so an unexpected value can never truncate the ENUM (was
        // fataling with 1265 "Data truncated"); wrap in try/catch as defence-in-depth.
        $allowedStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
        $status = in_array($_POST['status'] ?? '', $allowedStatuses, true) ? $_POST['status'] : 'pending';
        $notes = trim($_POST['notes'] ?? '');

        try {
            $stmt = $db->prepare("UPDATE appointments SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $notes, $id]);
            $message = 'อัพเดทสถานะสำเร็จ!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'ไม่สามารถอัพเดทสถานะได้: ' . $e->getMessage();
            $messageType = 'error';
        }

    } elseif ($action === 'cancel' && $id) {
        $reason = trim($_POST['reason'] ?? '');
        // Check if cancelled_by column exists
        try {
            $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'cancelled_by'");
            if ($stmt->rowCount() > 0) {
                $stmt = $db->prepare("UPDATE appointments SET status = 'cancelled', cancelled_by = 'pharmacist', cancelled_reason = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reason, $id]);
            } else {
                // Fallback: just update status and notes
                $stmt = $db->prepare("UPDATE appointments SET status = 'cancelled', notes = CONCAT(IFNULL(notes, ''), '\nยกเลิกโดยเภสัชกร: ', ?), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reason, $id]);
            }
        } catch (Exception $e) {
            $stmt = $db->prepare("UPDATE appointments SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
        }
        
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

// Get appointments - use only columns that exist
$sql = "SELECT a.id, a.user_id, a.pharmacist_id, a.appointment_date, a.appointment_time, 
        a.status, a.notes, a.created_at, a.updated_at,
        CONCAT('APT', LPAD(a.id, 6, '0')) as appointment_id,
        30 as duration,
        u.first_name, u.last_name, u.phone, u.display_name, u.picture_url,
        p.name as pharmacist_name, p.title as pharmacist_title
        FROM appointments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN pharmacists p ON a.pharmacist_id = p.id
        {$where}
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT {$perPage} OFFSET {$offset}";
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $appointments = [];
}

// Get pharmacists for filter
try {
    $pharmacists = $db->query("SELECT id, name, title FROM pharmacists WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pharmacists = [];
}

// Get stats
try {
    $stats = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN appointment_date = CURDATE() AND status IN ('pending','confirmed') THEN 1 ELSE 0 END) as today
        FROM appointments")->fetch();
} catch (Exception $e) {
    $stats = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'today' => 0];
}

require_once 'includes/header.php';

echo getPageHeaderStyles();
echo getToolbarStyles();
echo getDataTableStyles();
echo getEmptyStateStyles();
echo getPaginationStyles();
echo getModalStyles();
echo getToastStyles();
?>

<?= renderToastContainer() ?>

<?php if ($message): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    fireToast(<?= json_encode($message) ?>, '<?= $messageType === 'success' ? 'success' : 'error' ?>');
});
</script>
<?php endif; ?>

<?= renderPageHeader(
    'จัดการนัดหมาย',
    'ดูและจัดการนัดหมายทั้งหมด',
    null,
    [['label' => 'หน้าหลัก', 'href' => '/'], ['label' => 'จัดการนัดหมาย', 'href' => null]]
) ?>

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

<?php
// Build pharmacist select options
$pharmacistOptions = [];
foreach ($pharmacists as $p) {
    $pharmacistOptions[] = ['value' => $p['id'], 'label' => $p['title'] . $p['name']];
}

echo renderToolbar([
    'search' => [
        'name'        => 'search',
        'value'       => $search,
        'placeholder' => 'ค้นหารหัส, ชื่อ, เบอร์โทร...',
    ],
    'hiddenFields' => ['date' => $date],
    'selects' => [
        [
            'name'        => 'pharmacist_id',
            'value'       => $pharmacistId,
            'placeholder' => 'เภสัชกรทั้งหมด',
            'options'     => $pharmacistOptions,
        ],
        [
            'name'        => 'status',
            'value'       => $status,
            'placeholder' => 'ทุกสถานะ',
            'options'     => [
                ['value' => 'pending',     'label' => 'รอยืนยัน'],
                ['value' => 'confirmed',   'label' => 'ยืนยันแล้ว'],
                ['value' => 'in_progress', 'label' => 'กำลังดำเนินการ'],
                ['value' => 'completed',   'label' => 'เสร็จสิ้น'],
                ['value' => 'cancelled',   'label' => 'ยกเลิก'],
                ['value' => 'no_show',     'label' => 'ไม่มา'],
            ],
        ],
    ],
    'chips' => [
        ['href' => '?date=' . date('Y-m-d'),                      'icon' => 'fas fa-calendar-day',  'label' => 'วันนี้',   'tone' => 'primary', 'active' => ($date === date('Y-m-d'))],
        ['href' => '?date=' . date('Y-m-d', strtotime('+1 day')), 'icon' => 'fas fa-calendar-plus', 'label' => 'พรุ่งนี้', 'tone' => 'primary', 'active' => ($date === date('Y-m-d', strtotime('+1 day')))],
        ['href' => '?status=pending',   'icon' => 'fas fa-hourglass-half', 'label' => 'รอยืนยัน',   'tone' => 'warning', 'active' => ($status === 'pending')],
        ['href' => '?status=confirmed', 'icon' => 'fas fa-check',          'label' => 'ยืนยันแล้ว', 'tone' => 'success', 'active' => ($status === 'confirmed')],
    ],
    'resetHref' => 'appointments-admin.php',
    'meta'      => number_format($total) . ' รายการ',
]);
?>

<?php
$statusColors = [
    'pending'     => 'bg-yellow-100 text-yellow-700',
    'confirmed'   => 'bg-blue-100 text-blue-700',
    'in_progress' => 'bg-purple-100 text-purple-700',
    'completed'   => 'bg-green-100 text-green-700',
    'cancelled'   => 'bg-red-100 text-red-700',
    'no_show'     => 'bg-gray-100 text-gray-700',
];
$statusLabels = [
    'pending'     => 'รอยืนยัน',
    'confirmed'   => 'ยืนยันแล้ว',
    'in_progress' => 'กำลังดำเนินการ',
    'completed'   => 'เสร็จสิ้น',
    'cancelled'   => 'ยกเลิก',
    'no_show'     => 'ไม่มา',
];

$columns = [
    [
        'key'    => 'appointment_id',
        'label'  => 'รหัส',
        'render' => function($row) {
            return '<span class="font-mono text-sm font-medium text-purple-600">' . htmlspecialchars($row['appointment_id']) . '</span>';
        },
    ],
    [
        'key'    => 'customer',
        'label'  => 'ลูกค้า',
        'render' => function($row) {
            $name  = htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['display_name'] ?? '-'));
            $phone = htmlspecialchars($row['phone'] ?: '-');
            $pic   = htmlspecialchars($row['picture_url'] ?: 'https://via.placeholder.com/40');
            return '<div class="flex items-center gap-3">'
                 . '<img src="' . $pic . '" class="w-10 h-10 rounded-full object-cover">'
                 . '<div><p class="font-medium text-gray-800">' . $name . '</p>'
                 . '<p class="text-xs text-gray-500">' . $phone . '</p></div></div>';
        },
    ],
    [
        'key'    => 'pharmacist',
        'label'  => 'เภสัชกร',
        'render' => function($row) {
            return '<p class="font-medium">' . htmlspecialchars(($row['pharmacist_title'] ?? '') . ($row['pharmacist_name'] ?? '-')) . '</p>';
        },
    ],
    [
        'key'    => 'datetime',
        'label'  => 'วัน/เวลา',
        'render' => function($row) {
            $d   = date('d/m/Y', strtotime($row['appointment_date']));
            $t   = date('H:i', strtotime($row['appointment_time']));
            $dur = $row['duration'];
            return '<p class="font-medium">' . $d . '</p>'
                 . '<p class="text-sm text-gray-500">' . $t . ' น. (' . $dur . ' นาที)</p>';
        },
    ],
    [
        'key'    => 'status',
        'label'  => 'สถานะ',
        'align'  => 'center',
        'render' => function($row) use ($statusColors, $statusLabels) {
            $cls   = $statusColors[$row['status']] ?? '';
            $label = $statusLabels[$row['status']] ?? $row['status'];
            return '<span class="px-3 py-1 rounded-full text-xs font-medium ' . $cls . '">' . htmlspecialchars($label) . '</span>';
        },
    ],
    [
        'key'    => 'actions',
        'label'  => 'จัดการ',
        'align'  => 'center',
        'render' => function($row) {
            $editBtn = '';
            if (!in_array($row['status'], ['completed', 'cancelled', 'no_show'])) {
                $editBtn = '<button onclick="openStatusModal(' . (int)$row['id'] . ', \'' . htmlspecialchars($row['status'], ENT_QUOTES) . '\')" '
                         . 'class="data-table-row-action" title="อัพเดทสถานะ"><i class="fas fa-edit"></i></button>';
            }
            return '<div class="data-table-row-actions">'
                 . '<button onclick="openDetailModal(' . htmlspecialchars(json_encode($row), ENT_QUOTES) . ')" '
                 . 'class="data-table-row-action" title="ดูรายละเอียด"><i class="fas fa-eye"></i></button>'
                 . $editBtn
                 . '</div>';
        },
    ],
];

$emptyHtml = renderEmptyState('fas fa-calendar-times', 'ไม่พบนัดหมาย', 'ลองปรับตัวกรองหรือค้นหาใหม่อีกครั้ง');
echo renderDataTable($columns, $appointments, ['emptyContent' => $emptyHtml]);
?>

<?php if ($totalPages > 1): ?>
<div class="mt-4">
<?= renderPagination(
    $page,
    $totalPages,
    $perPage,
    '?search=' . urlencode($search) . '&status=' . urlencode($status) . '&date=' . urlencode($date) . '&pharmacist_id=' . urlencode($pharmacistId) . '&',
    ['total' => $total, 'offset' => $offset]
) ?>
</div>
<?php endif; ?>

<?php
// Detail modal — body is JS-populated
$detailModalBody = '<div id="detailContent"></div>';

// Status modal body
$statusModalBody = '
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
        <button type="button" onclick="closeModalShell(\'statusModal\')" class="flex-1 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
        <button type="submit" class="flex-1 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
    </div>
</form>
<hr class="my-4">
<form method="POST" onsubmit="return confirm(\'ยืนยันการยกเลิกนัดหมาย?\')">
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="id" id="cancel_apt_id">
    <div class="mb-3">
        <label class="block text-sm font-medium mb-1 text-red-600">ยกเลิกนัดหมาย</label>
        <input type="text" name="reason" class="w-full px-4 py-2 border border-red-200 rounded-lg" placeholder="เหตุผลในการยกเลิก">
    </div>
    <button type="submit" class="w-full py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">
        <i class="fas fa-times mr-2"></i>ยกเลิกนัดหมาย
    </button>
</form>';

echo renderModal('detailModal', 'รายละเอียดนัดหมาย', $detailModalBody, null, ['size' => 'md']);
echo renderModal('statusModal', 'อัพเดทสถานะ', $statusModalBody, null, ['size' => 'sm']);
?>

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
    openModalShell('detailModal');
}

function openStatusModal(id, currentStatus) {
    document.getElementById('status_apt_id').value = id;
    document.getElementById('cancel_apt_id').value = id;
    document.getElementById('status_select').value = currentStatus;
    openModalShell('statusModal');
}
</script>

<?php require_once 'includes/footer.php'; ?>
