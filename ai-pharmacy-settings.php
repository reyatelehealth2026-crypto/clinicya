<?php
/**
 * AI Pharmacy Settings - ตั้งค่า AI เภสัชออนไลน์
 * Version 2.0 - Professional Pharmacy AI Configuration
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/form-section.php';
require_once __DIR__ . '/includes/components/field.php';
require_once __DIR__ . '/includes/components/toggle.php';
require_once __DIR__ . '/includes/components/sticky-save-bar.php';
require_once __DIR__ . '/includes/components/toast.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่า AI เภสัชออนไลน์';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Ensure tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ai_pharmacy_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT DEFAULT NULL,
        triage_enabled TINYINT(1) DEFAULT 1,
        red_flag_enabled TINYINT(1) DEFAULT 1,
        auto_recommend TINYINT(1) DEFAULT 1,
        require_pharmacist_approval TINYINT(1) DEFAULT 1,
        video_call_enabled TINYINT(1) DEFAULT 1,
        notification_line_token VARCHAR(255) DEFAULT NULL,
        notification_email VARCHAR(255) DEFAULT NULL,
        working_hours_start TIME DEFAULT '09:00:00',
        working_hours_end TIME DEFAULT '21:00:00',
        emergency_contact VARCHAR(100) DEFAULT NULL,
        pharmacy_name VARCHAR(200) DEFAULT NULL,
        pharmacy_license VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Get current settings
$settings = [];
try {
    if ($currentBotId) {
        $stmt = $db->prepare("SELECT * FROM ai_pharmacy_settings WHERE line_account_id = ?");
        $stmt->execute([$currentBotId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {}

// Handle POST
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        try {
            $data = [
                'line_account_id' => $currentBotId,
                'triage_enabled' => isset($_POST['triage_enabled']) ? 1 : 0,
                'red_flag_enabled' => isset($_POST['red_flag_enabled']) ? 1 : 0,
                'auto_recommend' => isset($_POST['auto_recommend']) ? 1 : 0,
                'require_pharmacist_approval' => isset($_POST['require_pharmacist_approval']) ? 1 : 0,
                'video_call_enabled' => isset($_POST['video_call_enabled']) ? 1 : 0,
                'notification_email' => trim($_POST['notification_email'] ?? ''),
                'working_hours_start' => $_POST['working_hours_start'] ?? '09:00',
                'working_hours_end' => $_POST['working_hours_end'] ?? '21:00',
                'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
                'pharmacy_name' => trim($_POST['pharmacy_name'] ?? ''),
                'pharmacy_license' => trim($_POST['pharmacy_license'] ?? ''),
            ];
            
            $stmt = $db->prepare("INSERT INTO ai_pharmacy_settings 
                (line_account_id, triage_enabled, red_flag_enabled, auto_recommend, require_pharmacist_approval, 
                 video_call_enabled, notification_email, working_hours_start, working_hours_end, 
                 emergency_contact, pharmacy_name, pharmacy_license)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                triage_enabled = VALUES(triage_enabled), red_flag_enabled = VALUES(red_flag_enabled),
                auto_recommend = VALUES(auto_recommend), require_pharmacist_approval = VALUES(require_pharmacist_approval),
                video_call_enabled = VALUES(video_call_enabled), notification_email = VALUES(notification_email),
                working_hours_start = VALUES(working_hours_start), working_hours_end = VALUES(working_hours_end),
                emergency_contact = VALUES(emergency_contact), pharmacy_name = VALUES(pharmacy_name),
                pharmacy_license = VALUES(pharmacy_license)");
            
            $stmt->execute(array_values($data));
            $success = 'บันทึกการตั้งค่าสำเร็จ';
            
            // Reload settings
            $stmt = $db->prepare("SELECT * FROM ai_pharmacy_settings WHERE line_account_id = ?");
            $stmt->execute([$currentBotId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}

// Default values
$triageEnabled = $settings['triage_enabled'] ?? 1;
$redFlagEnabled = $settings['red_flag_enabled'] ?? 1;
$autoRecommend = $settings['auto_recommend'] ?? 1;
$requireApproval = $settings['require_pharmacist_approval'] ?? 1;
$videoCallEnabled = $settings['video_call_enabled'] ?? 1;
$notificationEmail = $settings['notification_email'] ?? '';
$workingStart = $settings['working_hours_start'] ?? '09:00';
$workingEnd = $settings['working_hours_end'] ?? '21:00';
$emergencyContact = $settings['emergency_contact'] ?? '';
$pharmacyName = $settings['pharmacy_name'] ?? '';
$pharmacyLicense = $settings['pharmacy_license'] ?? '';

require_once __DIR__ . '/includes/header.php';
echo getPageHeaderStyles();
echo getFormSectionStyles();
echo getFieldStyles();
echo getToggleStyles();
echo getStickySaveBarStyles();
echo getToastStyles();
?>

<?= renderToastContainer() ?>

<?php if ($success): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ fireToast(<?= json_encode($success) ?>,'success'); });</script>
<?php endif; ?>
<?php if ($error): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ fireToast(<?= json_encode($error) ?>,'error'); });</script>
<?php endif; ?>

<?= renderPageHeader(
    'ตั้งค่า AI เภสัชออนไลน์',
    'กำหนดค่าระบบ Triage และการแนะนำยาอัตโนมัติ',
    ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'type' => 'link', 'href' => 'pharmacist-dashboard.php', 'variant' => 'success'],
    [['label' => 'หน้าหลัก', 'href' => '/'], ['label' => 'ตั้งค่า AI เภสัชออนไลน์', 'href' => null]]
) ?>

<div class="max-w-5xl mx-auto">
    <form method="POST" id="aiPharmacyForm">
        <input type="hidden" name="action" value="save_settings">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Settings -->
            <div class="lg:col-span-2">
                <?php
                // --- Section: Pharmacy Info ---
                $pharmacyBody  = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
                $pharmacyBody .= renderField('pharmacy_name', 'ชื่อร้านยา', 'text', $pharmacyName, ['placeholder' => 'เช่น ร้านยา ABC']);
                $pharmacyBody .= renderField('pharmacy_license', 'เลขที่ใบอนุญาต', 'text', $pharmacyLicense, ['placeholder' => 'เช่น ข.ย. 12345']);
                $pharmacyBody .= '</div>';
                echo renderFormSection('ข้อมูลร้านยา', 'fas fa-store-alt', '', $pharmacyBody);

                // --- Section: Triage ---
                $triageBody  = renderToggle('triage_enabled', 'เปิดใช้งานระบบซักประวัติ', (bool)$triageEnabled, 'AI จะซักประวัติอาการเป็นขั้นตอนก่อนแนะนำยา', ['color' => 'emerald']);
                $triageBody .= renderToggle('red_flag_enabled', 'ตรวจจับอาการฉุกเฉิน (Red Flag)', (bool)$redFlagEnabled, 'แจ้งเตือนเมื่อพบอาการที่ต้องพบแพทย์ทันที', ['color' => 'emerald']);
                $triageBody .= renderToggle('auto_recommend', 'แนะนำยาอัตโนมัติ', (bool)$autoRecommend, 'AI จะแนะนำยาจากคลังสินค้าตามอาการ', ['color' => 'emerald']);
                $triageBody .= renderToggle('require_pharmacist_approval', 'ต้องให้เภสัชกรอนุมัติ', (bool)$requireApproval, 'ลูกค้าต้องรอเภสัชกรยืนยันก่อนสั่งซื้อยา', ['color' => 'emerald']);
                echo renderFormSection('ระบบซักประวัติ (Triage)', 'fas fa-stethoscope', '', $triageBody);

                // --- Section: Video Call ---
                $videoBody  = renderToggle('video_call_enabled', 'เปิดใช้งาน Video Call', (bool)$videoCallEnabled, 'ลูกค้าสามารถ Video Call ปรึกษาเภสัชกรได้', ['color' => 'indigo']);
                $videoBody .= '<div class="grid grid-cols-2 gap-4 mt-4">';
                $videoBody .= renderField('working_hours_start', 'เวลาเปิดให้บริการ', 'text', $workingStart, ['class' => 'field-time']);
                $videoBody .= renderField('working_hours_end', 'เวลาปิดให้บริการ', 'text', $workingEnd, ['class' => 'field-time']);
                $videoBody .= '</div>';
                echo renderFormSection('Video Call / Telecare', 'fas fa-video', '', $videoBody);

                // --- Section: Notifications ---
                $notifBody  = renderField('notification_email', 'Email แจ้งเตือน', 'email', $notificationEmail, [
                    'placeholder' => 'pharmacist@example.com',
                    'help'        => 'รับแจ้งเตือนเมื่อมีคำขอปรึกษาใหม่',
                ]);
                $notifBody .= renderField('emergency_contact', 'เบอร์ฉุกเฉิน', 'text', $emergencyContact, [
                    'placeholder' => 'เช่น 02-xxx-xxxx',
                    'help'        => 'แสดงให้ลูกค้าเมื่อพบอาการฉุกเฉิน',
                ]);
                echo renderFormSection('การแจ้งเตือน', 'fas fa-bell', '', $notifBody);
                ?>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Quick Links -->
                <div class="bg-white rounded-xl shadow p-6">
                    <h4 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-link text-gray-400 mr-2"></i>ลิงก์ด่วน
                    </h4>
                    <div class="space-y-2">
                        <a href="ai-chat-settings.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-robot text-blue-500"></i>
                            <span class="text-sm">ตั้งค่า AI Chat</span>
                        </a>
                        <a href="pharmacist-dashboard.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-tachometer-alt text-green-500"></i>
                            <span class="text-sm">Pharmacist Dashboard</span>
                        </a>
                        <a href="broadcast-catalog.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-pills text-purple-500"></i>
                            <span class="text-sm">จัดการสินค้า/ยา</span>
                        </a>
                        <a href="run_triage_migration.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-database text-orange-500"></i>
                            <span class="text-sm">Run Migration</span>
                        </a>
                    </div>
                </div>

                <!-- Features Info -->
                <div class="bg-white rounded-xl shadow p-6">
                    <h4 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-info-circle text-blue-400 mr-2"></i>ฟีเจอร์ระบบ
                    </h4>
                    <ul class="space-y-3 text-sm text-gray-600">
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>ซักประวัติอาการอัตโนมัติ</span></li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>ตรวจจับอาการฉุกเฉิน (Red Flag)</span></li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>ตรวจสอบยาตีกัน</span></li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>ตรวจสอบการแพ้ยา</span></li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>แนะนำยาจากคลังสินค้า</span></li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>Video Call กับเภสัชกร</span></li>
                        <li class="flex items-start gap-2"><i class="fas fa-check-circle text-emerald-500 mt-0.5"></i><span>บันทึกประวัติการรักษา</span></li>
                    </ul>
                </div>

                <!-- Flow Diagram -->
                <div class="bg-white rounded-xl shadow p-6">
                    <h4 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-project-diagram text-purple-400 mr-2"></i>Flow การทำงาน
                    </h4>
                    <div class="space-y-2 text-xs">
                        <div class="flex items-center gap-2"><span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center font-bold">1</span><span class="text-gray-600">ลูกค้าบอกอาการ</span></div>
                        <div class="w-0.5 h-4 bg-gray-200 ml-3"></div>
                        <div class="flex items-center gap-2"><span class="w-6 h-6 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center font-bold">2</span><span class="text-gray-600">AI ซักประวัติเพิ่มเติม</span></div>
                        <div class="w-0.5 h-4 bg-gray-200 ml-3"></div>
                        <div class="flex items-center gap-2"><span class="w-6 h-6 bg-red-100 text-red-600 rounded-full flex items-center justify-center font-bold">3</span><span class="text-gray-600">ตรวจ Red Flag / แพ้ยา</span></div>
                        <div class="w-0.5 h-4 bg-gray-200 ml-3"></div>
                        <div class="flex items-center gap-2"><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center font-bold">4</span><span class="text-gray-600">แนะนำยา + รอเภสัชยืนยัน</span></div>
                        <div class="w-0.5 h-4 bg-gray-200 ml-3"></div>
                        <div class="flex items-center gap-2"><span class="w-6 h-6 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center font-bold">5</span><span class="text-gray-600">สั่งซื้อ / Video Call</span></div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <?= renderStickySaveBar('aiPharmacyForm', 'บันทึกการตั้งค่า') ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
