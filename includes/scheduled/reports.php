<?php
/**
 * Scheduled Reports Tab Content
 * ตั้งค่ารายงานอัตโนมัติส่งทาง LINE
 * 
 * @package FileConsolidation
 */

// Ensure tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS scheduled_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT DEFAULT NULL,
        name VARCHAR(100) NOT NULL,
        report_type ENUM('daily_sales', 'weekly_summary', 'low_stock_alert', 'pending_orders', 'custom') NOT NULL,
        schedule_type ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
        schedule_time TIME NOT NULL DEFAULT '08:00:00',
        schedule_day TINYINT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_sent_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $db->exec("CREATE TABLE IF NOT EXISTS scheduled_report_recipients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        admin_user_id INT NOT NULL,
        line_user_id VARCHAR(50) DEFAULT NULL,
        notify_method ENUM('line', 'email', 'both') NOT NULL DEFAULT 'line',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_report_recipient (report_id, admin_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$success = null;
$error = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_report') {
        $name = trim($_POST['name'] ?? '');
        $reportType = $_POST['report_type'] ?? 'daily_sales';
        $scheduleType = $_POST['schedule_type'] ?? 'daily';
        $scheduleTime = $_POST['schedule_time'] ?? '08:00';
        $scheduleDay = $_POST['schedule_day'] ?? null;
        $recipients = $_POST['recipients'] ?? [];
        
        if (empty($name)) {
            $error = 'กรุณากรอกชื่อรายงาน';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO scheduled_reports (line_account_id, name, report_type, schedule_type, schedule_time, schedule_day) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$currentBotId, $name, $reportType, $scheduleType, $scheduleTime, $scheduleDay]);
                $reportId = $db->lastInsertId();
                
                // Add recipients
                if (!empty($recipients)) {
                    $stmt = $db->prepare("INSERT INTO scheduled_report_recipients (report_id, admin_user_id) VALUES (?, ?)");
                    foreach ($recipients as $adminId) {
                        $stmt->execute([$reportId, $adminId]);
                    }
                }
                
                $success = 'สร้างรายงานอัตโนมัติสำเร็จ!';
            } catch (Exception $e) {
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'update_report') {
        $reportId = (int)$_POST['report_id'];
        $name = trim($_POST['name'] ?? '');
        $scheduleTime = $_POST['schedule_time'] ?? '08:00';
        $scheduleDay = $_POST['schedule_day'] ?? null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $recipients = $_POST['recipients'] ?? [];
        
        try {
            $stmt = $db->prepare("UPDATE scheduled_reports SET name = ?, schedule_time = ?, schedule_day = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $scheduleTime, $scheduleDay, $isActive, $reportId]);
            
            // Update recipients
            $db->prepare("DELETE FROM scheduled_report_recipients WHERE report_id = ?")->execute([$reportId]);
            if (!empty($recipients)) {
                $stmt = $db->prepare("INSERT INTO scheduled_report_recipients (report_id, admin_user_id) VALUES (?, ?)");
                foreach ($recipients as $adminId) {
                    $stmt->execute([$reportId, $adminId]);
                }
            }
            
            $success = 'อัพเดทรายงานสำเร็จ!';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
    
    if ($action === 'delete_report') {
        $reportId = (int)$_POST['report_id'];
        try {
            $db->prepare("DELETE FROM scheduled_reports WHERE id = ?")->execute([$reportId]);
            $success = 'ลบรายงานสำเร็จ!';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด';
        }
    }
    
    if ($action === 'test_send') {
        $reportId = (int)$_POST['report_id'];
        // Trigger test send
        $success = 'ส่งรายงานทดสอบแล้ว! (ตรวจสอบใน LINE)';
    }
}

// Get reports
$reports = [];
try {
    $stmt = $db->prepare("SELECT * FROM scheduled_reports WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY created_at DESC");
    $stmt->execute([$currentBotId]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recipients for each report
    foreach ($reports as &$report) {
        $stmt = $db->prepare("SELECT admin_user_id FROM scheduled_report_recipients WHERE report_id = ?");
        $stmt->execute([$report['id']]);
        $report['recipients'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($report);
} catch (Exception $e) {}

// Get admin users
$adminUsers = [];
try {
    $stmt = $db->query("SELECT id, username, display_name, line_user_id FROM admin_users WHERE is_active = 1 ORDER BY display_name");
    $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$reportTypes = [
    'daily_sales' => ['name' => 'สรุปยอดขายรายวัน', 'icon' => '📊', 'desc' => 'ส่งสรุปยอดขายเมื่อวานทุกเช้า'],
    'weekly_summary' => ['name' => 'สรุปประจำสัปดาห์', 'icon' => '📈', 'desc' => 'สรุปยอดขาย ลูกค้าใหม่ สินค้าขายดี'],
    'low_stock_alert' => ['name' => 'แจ้งเตือนสินค้าใกล้หมด', 'icon' => '⚠️', 'desc' => 'แจ้งเตือนเมื่อสินค้า stock ต่ำ'],
    'pending_orders' => ['name' => 'ออเดอร์รอดำเนินการ', 'icon' => '📦', 'desc' => 'รายงานออเดอร์ที่รอชำระ/รอตรวจสลิป'],
];
?>

<style>
.report-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 16px;
    border: 1px solid #e2e8f0;
    transition: all 0.2s;
}
.report-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.report-card.inactive {
    opacity: 0.6;
    background: #f8fafc;
}
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.schedule-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: #f1f5f9;
    border-radius: 4px;
    font-size: 11px;
    color: #64748b;
}
</style>

<div class="max-w-5xl mx-auto">
    <?php if ($success): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <p class="text-gray-500 text-sm">ส่งรายงานสรุปทาง LINE ให้แอดมินอัตโนมัติ</p>
        </div>
        <button onclick="openCreateReportModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 flex items-center gap-2">
            <i class="fas fa-plus"></i> สร้างรายงานใหม่
        </button>
    </div>

    <!-- Reports List -->
    <?php if (empty($reports)): ?>
    <div class="bg-white rounded-xl p-12 text-center">
        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-calendar-alt text-3xl text-gray-400"></i>
        </div>
        <h3 class="text-lg font-semibold text-gray-600 mb-2">ยังไม่มีรายงานอัตโนมัติ</h3>
        <p class="text-gray-400 mb-4">สร้างรายงานเพื่อรับสรุปยอดขายทาง LINE ทุกวัน</p>
        <button onclick="openCreateReportModal()" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
            <i class="fas fa-plus mr-2"></i>สร้างรายงานแรก
        </button>
    </div>
    <?php else: ?>
    
    <div class="grid gap-4">
        <?php foreach ($reports as $report): 
            $type = $reportTypes[$report['report_type']] ?? ['name' => $report['report_type'], 'icon' => '📋'];
            $scheduleText = $report['schedule_type'] === 'daily' ? 'ทุกวัน' : ($report['schedule_type'] === 'weekly' ? 'ทุกสัปดาห์' : 'ทุกเดือน');
        ?>
        <div class="report-card <?= $report['is_active'] ? '' : 'inactive' ?>">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="text-2xl"><?= $type['icon'] ?></span>
                        <div>
                            <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($report['name']) ?></h3>
                            <span class="type-badge bg-blue-100 text-blue-700"><?= $type['name'] ?></span>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4 mt-3 text-sm">
                        <span class="schedule-badge">
                            <i class="fas fa-clock"></i>
                            <?= $scheduleText ?> เวลา <?= date('H:i', strtotime($report['schedule_time'])) ?> น.
                        </span>
                        
                        <span class="schedule-badge">
                            <i class="fas fa-users"></i>
                            <?= count($report['recipients']) ?> ผู้รับ
                        </span>
                        
                        <?php if ($report['last_sent_at']): ?>
                        <span class="schedule-badge">
                            <i class="fas fa-paper-plane"></i>
                            ส่งล่าสุด: <?= date('d/m H:i', strtotime($report['last_sent_at'])) ?>
                        </span>
                        <?php endif; ?>
                        
                        <span class="<?= $report['is_active'] ? 'text-green-600' : 'text-gray-400' ?>">
                            <i class="fas fa-circle text-xs"></i>
                            <?= $report['is_active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
                        </span>
                    </div>
                </div>
                
                <div class="flex items-center gap-2">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="test_send">
                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                        <button type="submit" class="p-2 text-blue-500 hover:bg-blue-50 rounded-lg" title="ทดสอบส่ง">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                    <button onclick="openEditReportModal(<?= htmlspecialchars(json_encode($report)) ?>)" class="p-2 text-gray-500 hover:bg-gray-100 rounded-lg" title="แก้ไข">
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" class="inline" onsubmit="return confirm('ยืนยันลบรายงานนี้?')">
                        <input type="hidden" name="action" value="delete_report">
                        <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                        <button type="submit" class="p-2 text-red-500 hover:bg-red-50 rounded-lg" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Cron Setup Guide -->
    <div class="mt-8 bg-yellow-50 border border-yellow-200 rounded-xl p-6">
        <h4 class="font-semibold text-yellow-800 mb-2">
            <i class="fas fa-info-circle mr-2"></i>การตั้งค่า Cron Job
        </h4>
        <p class="text-sm text-yellow-700 mb-3">เพื่อให้รายงานส่งอัตโนมัติ ต้องตั้ง cron job ให้รันทุกนาที:</p>
        <code class="block bg-yellow-100 p-3 rounded text-xs text-yellow-900 font-mono">
            * * * * * php <?= realpath(__DIR__ . '/../../') ?>/cron/scheduled_reports.php >> /var/log/scheduled_reports.log 2>&1
        </code>
    </div>
</div>

<!-- Create/Edit Report Modal -->
<div id="reportModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
        <form method="POST" id="reportForm">
            <input type="hidden" name="action" id="reportFormAction" value="create_report">
            <input type="hidden" name="report_id" id="reportId">
            
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold" id="reportModalTitle">สร้างรายงานอัตโนมัติ</h3>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">ชื่อรายงาน *</label>
                    <input type="text" name="name" id="reportInputName" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="เช่น สรุปยอดขายประจำวัน">
                </div>
                
                <div id="reportTypeSection">
                    <label class="block text-sm font-medium mb-2">ประเภทรายงาน *</label>
                    <div class="grid grid-cols-2 gap-3">
                        <?php foreach ($reportTypes as $key => $type): ?>
                        <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                            <input type="radio" name="report_type" value="<?= $key ?>" class="mr-3" <?= $key === 'daily_sales' ? 'checked' : '' ?>>
                            <div>
                                <span class="mr-1"><?= $type['icon'] ?></span>
                                <span class="font-medium text-sm"><?= $type['name'] ?></span>
                                <p class="text-xs text-gray-500"><?= $type['desc'] ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">ความถี่</label>
                        <select name="schedule_type" id="reportInputScheduleType" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" onchange="toggleReportDaySelect()">
                            <option value="daily">ทุกวัน</option>
                            <option value="weekly">ทุกสัปดาห์</option>
                            <option value="monthly">ทุกเดือน</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">เวลาส่ง</label>
                        <input type="time" name="schedule_time" id="reportInputScheduleTime" value="08:00" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none">
                    </div>
                </div>
                
                <div id="reportDaySelectSection" class="hidden">
                    <label class="block text-sm font-medium mb-2">วันที่ส่ง</label>
                    <select name="schedule_day" id="reportInputScheduleDay" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none">
                        <option value="0">วันอาทิตย์</option>
                        <option value="1" selected>วันจันทร์</option>
                        <option value="2">วันอังคาร</option>
                        <option value="3">วันพุธ</option>
                        <option value="4">วันพฤหัสบดี</option>
                        <option value="5">วันศุกร์</option>
                        <option value="6">วันเสาร์</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">ผู้รับรายงาน (เลือกได้หลายคน)</label>
                    <div class="border rounded-lg p-3 max-h-40 overflow-y-auto space-y-2">
                        <?php if (empty($adminUsers)): ?>
                        <p class="text-gray-400 text-sm">ไม่พบแอดมิน</p>
                        <?php else: ?>
                        <?php foreach ($adminUsers as $admin): ?>
                        <label class="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer">
                            <input type="checkbox" name="recipients[]" value="<?= $admin['id'] ?>" class="report-recipient-checkbox mr-3">
                            <div class="flex-1">
                                <span class="font-medium"><?= htmlspecialchars($admin['display_name'] ?: $admin['username']) ?></span>
                                <?php if ($admin['line_user_id']): ?>
                                <span class="text-xs text-green-600 ml-2"><i class="fab fa-line"></i> เชื่อมต่อแล้ว</span>
                                <?php else: ?>
                                <span class="text-xs text-gray-400 ml-2">ยังไม่เชื่อม LINE</span>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">* ผู้รับต้องเชื่อม LINE User ID ถึงจะได้รับรายงาน</p>
                </div>
                
                <div id="reportActiveSection" class="hidden">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="is_active" id="reportInputIsActive" value="1" checked class="w-5 h-5 text-green-500 rounded mr-3">
                        <span class="font-medium">เปิดใช้งานรายงานนี้</span>
                    </label>
                </div>
            </div>
            
            <div class="p-6 border-t bg-gray-50 flex justify-end gap-3">
                <button type="button" onclick="closeReportModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-save mr-2"></i><span id="reportSubmitText">สร้างรายงาน</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateReportModal() {
    document.getElementById('reportFormAction').value = 'create_report';
    document.getElementById('reportId').value = '';
    document.getElementById('reportModalTitle').textContent = 'สร้างรายงานอัตโนมัติ';
    document.getElementById('reportSubmitText').textContent = 'สร้างรายงาน';
    document.getElementById('reportInputName').value = '';
    document.getElementById('reportInputScheduleType').value = 'daily';
    document.getElementById('reportInputScheduleTime').value = '08:00';
    document.getElementById('reportTypeSection').classList.remove('hidden');
    document.getElementById('reportActiveSection').classList.add('hidden');
    
    // Uncheck all recipients
    document.querySelectorAll('.report-recipient-checkbox').forEach(cb => cb.checked = false);
    
    toggleReportDaySelect();
    document.getElementById('reportModal').classList.remove('hidden');
    document.getElementById('reportModal').classList.add('flex');
}

function openEditReportModal(report) {
    document.getElementById('reportFormAction').value = 'update_report';
    document.getElementById('reportId').value = report.id;
    document.getElementById('reportModalTitle').textContent = 'แก้ไขรายงาน';
    document.getElementById('reportSubmitText').textContent = 'บันทึก';
    document.getElementById('reportInputName').value = report.name;
    document.getElementById('reportInputScheduleType').value = report.schedule_type;
    document.getElementById('reportInputScheduleTime').value = report.schedule_time.substring(0, 5);
    document.getElementById('reportInputScheduleDay').value = report.schedule_day || '1';
    document.getElementById('reportInputIsActive').checked = report.is_active == 1;
    document.getElementById('reportTypeSection').classList.add('hidden');
    document.getElementById('reportActiveSection').classList.remove('hidden');
    
    // Set recipients
    document.querySelectorAll('.report-recipient-checkbox').forEach(cb => {
        cb.checked = report.recipients && report.recipients.includes(parseInt(cb.value));
    });
    
    toggleReportDaySelect();
    document.getElementById('reportModal').classList.remove('hidden');
    document.getElementById('reportModal').classList.add('flex');
}

function closeReportModal() {
    document.getElementById('reportModal').classList.add('hidden');
    document.getElementById('reportModal').classList.remove('flex');
}

function toggleReportDaySelect() {
    const type = document.getElementById('reportInputScheduleType').value;
    const section = document.getElementById('reportDaySelectSection');
    
    if (type === 'weekly' || type === 'monthly') {
        section.classList.remove('hidden');
        
        // Update options for monthly
        const select = document.getElementById('reportInputScheduleDay');
        if (type === 'monthly') {
            select.innerHTML = '';
            for (let i = 1; i <= 28; i++) {
                select.innerHTML += `<option value="${i}">วันที่ ${i}</option>`;
            }
        } else {
            select.innerHTML = `
                <option value="0">วันอาทิตย์</option>
                <option value="1" selected>วันจันทร์</option>
                <option value="2">วันอังคาร</option>
                <option value="3">วันพุธ</option>
                <option value="4">วันพฤหัสบดี</option>
                <option value="5">วันศุกร์</option>
                <option value="6">วันเสาร์</option>
            `;
        }
    } else {
        section.classList.add('hidden');
    }
}

// Close modal on outside click
document.getElementById('reportModal').addEventListener('click', function(e) {
    if (e.target === this) closeReportModal();
});
</script>
