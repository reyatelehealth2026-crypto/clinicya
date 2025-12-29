<?php
/**
 * Pharmacist Dashboard - แดชบอร์ดสำหรับเภสัชกร
 * Version 2.0 - Professional Pharmacy Management
 * 
 * Features:
 * - ดูรายการรอตรวจสอบ
 * - ดูประวัติการซักประวัติ
 * - อนุมัติ/ปฏิเสธคำสั่งซื้อยา
 * - Video Call กับลูกค้า
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Pharmacist Dashboard';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Get statistics
$stats = [
    'pending' => 0,
    'urgent' => 0,
    'today_completed' => 0,
    'total_sessions' => 0,
];

try {
    // Pending notifications - no line_account_id filter
    $stmt = $db->query("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending'");
    $stats['pending'] = $stmt->fetchColumn();
    
    // Urgent notifications
    $stmt = $db->query("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending' AND priority = 'urgent'");
    $stats['urgent'] = $stmt->fetchColumn();
    
    // Today completed
    $stmt = $db->query("SELECT COUNT(*) FROM triage_sessions WHERE status = 'completed' AND DATE(completed_at) = CURDATE()");
    $stats['today_completed'] = $stmt->fetchColumn();
    
    // Total sessions
    $stmt = $db->query("SELECT COUNT(*) FROM triage_sessions");
    $stats['total_sessions'] = $stmt->fetchColumn();
    
    error_log("Pharmacist Dashboard Stats: pending={$stats['pending']}, urgent={$stats['urgent']}, total={$stats['total_sessions']}");
} catch (Exception $e) {
    error_log("Pharmacist Dashboard Stats Error: " . $e->getMessage());
}

// Get pending notifications
$notifications = [];
try {
    $stmt = $db->query("
        SELECT pn.*, u.display_name, u.picture_url, u.phone,
               ts.current_state, ts.triage_data
        FROM pharmacist_notifications pn
        LEFT JOIN users u ON pn.user_id = u.id
        LEFT JOIN triage_sessions ts ON pn.triage_session_id = ts.id
        WHERE pn.status = 'pending'
        ORDER BY 
            CASE WHEN pn.priority = 'urgent' THEN 0 ELSE 1 END,
            pn.created_at DESC
        LIMIT 20
    ");
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Pharmacist Dashboard Notifications: found " . count($notifications));
} catch (Exception $e) {
    error_log("Pharmacist Dashboard Notifications Error: " . $e->getMessage());
}

// Get recent sessions
$recentSessions = [];
try {
    $stmt = $db->query("
        SELECT ts.*, u.display_name, u.picture_url
        FROM triage_sessions ts
        LEFT JOIN users u ON ts.user_id = u.id
        ORDER BY ts.updated_at DESC
        LIMIT 10
    ");
    $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.stat-card { transition: all 0.3s; }
.stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
.notification-card { transition: all 0.2s; }
.notification-card:hover { background: #f8fafc; }
.urgent-badge { animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
.priority-urgent { border-left: 4px solid #ef4444; }
.priority-normal { border-left: 4px solid #3b82f6; }
.status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
.status-active { background: #dcfce7; color: #166534; }
.status-completed { background: #dbeafe; color: #1e40af; }
.status-escalated { background: #fef3c7; color: #92400e; }
.status-cancelled { background: #f3f4f6; color: #6b7280; }
</style>

<div class="container mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-user-md text-green-500 mr-2"></i>Pharmacist Dashboard
            </h1>
            <p class="text-gray-500 mt-1">จัดการคำขอปรึกษาและอนุมัติยา</p>
        </div>
        <div class="flex gap-3">
            <button onclick="refreshData()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                <i class="fas fa-sync-alt mr-2"></i>รีเฟรช
            </button>
            <a href="ai-chat-settings.php" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-cog mr-2"></i>ตั้งค่า AI
            </a>
            <a href="triage-analytics.php" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                <i class="fas fa-chart-pie mr-2"></i>Analytics
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card bg-white rounded-xl shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">รอตรวจสอบ</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['pending'] ?></p>
                </div>
                <div class="w-14 h-14 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-clock text-blue-500 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-6 <?= $stats['urgent'] > 0 ? 'ring-2 ring-red-500' : '' ?>">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">เร่งด่วน</p>
                    <p class="text-3xl font-bold <?= $stats['urgent'] > 0 ? 'text-red-500' : 'text-gray-800' ?>">
                        <?= $stats['urgent'] ?>
                    </p>
                </div>
                <div class="w-14 h-14 <?= $stats['urgent'] > 0 ? 'bg-red-100 urgent-badge' : 'bg-gray-100' ?> rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle <?= $stats['urgent'] > 0 ? 'text-red-500' : 'text-gray-400' ?> text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">เสร็จวันนี้</p>
                    <p class="text-3xl font-bold text-green-600"><?= $stats['today_completed'] ?></p>
                </div>
                <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm">ทั้งหมด</p>
                    <p class="text-3xl font-bold text-gray-800"><?= $stats['total_sessions'] ?></p>
                </div>
                <div class="w-14 h-14 bg-purple-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-clipboard-list text-purple-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Notifications -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800">
                        <i class="fas fa-bell text-yellow-500 mr-2"></i>รายการรอตรวจสอบ
                    </h3>
                    <span class="text-sm text-gray-500"><?= count($notifications) ?> รายการ</span>
                </div>
                
                <div class="divide-y max-h-[600px] overflow-y-auto">
                    <?php if (empty($notifications)): ?>
                    <div class="p-8 text-center text-gray-400">
                        <i class="fas fa-inbox text-4xl mb-3"></i>
                        <p>ไม่มีรายการรอตรวจสอบ</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                    <?php 
                        $triageData = json_decode($notif['triage_data'] ?? '{}', true);
                        $notifData = json_decode($notif['notification_data'] ?? '{}', true);
                        $isUrgent = $notif['priority'] === 'urgent';
                        
                        // Get symptoms from notification_data or triage_data
                        $symptoms = $notifData['symptoms'] ?? $triageData['symptoms'] ?? [];
                        if (is_string($symptoms)) $symptoms = [$symptoms];
                        
                        // Get severity from notification_data or triage_data
                        $severity = $notifData['severity'] ?? $triageData['severity'] ?? null;
                        $severityLevel = $notifData['severity_level'] ?? null;
                        
                        // Get red_flags from notification_data or triage_data
                        $redFlags = $notifData['red_flags'] ?? $triageData['red_flags'] ?? [];
                        
                        // Check if emergency state
                        $isEmergency = ($notif['current_state'] ?? '') === 'emergency';
                        if ($isEmergency && empty($redFlags)) {
                            $redFlags = [['message' => 'ผู้ป่วยอยู่ในสถานะฉุกเฉิน']];
                        }
                        
                        // Get notification type
                        $notifType = $notif['type'] ?? 'triage_alert';
                    ?>
                    <div class="notification-card p-4 <?= $isUrgent ? 'priority-urgent bg-red-50' : 'priority-normal' ?>" 
                         data-id="<?= $notif['id'] ?>">
                        <div class="flex items-start gap-4">
                            <img src="<?= htmlspecialchars($notif['picture_url'] ?? 'assets/images/default-avatar.png') ?>" 
                                 class="w-12 h-12 rounded-full object-cover" alt="">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-medium text-gray-800">
                                        <?= htmlspecialchars($notif['display_name'] ?? 'ไม่ระบุชื่อ') ?>
                                    </span>
                                    <?php if ($isUrgent): ?>
                                    <span class="px-2 py-0.5 bg-red-500 text-white text-xs rounded-full urgent-badge">
                                        🚨 เร่งด่วน
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($notifType === 'escalation'): ?>
                                    <span class="px-2 py-0.5 bg-blue-500 text-white text-xs rounded-full">
                                        👨‍⚕️ ขอปรึกษา
                                    </span>
                                    <?php elseif ($notifType === 'emergency_alert'): ?>
                                    <span class="px-2 py-0.5 bg-red-600 text-white text-xs rounded-full">
                                        🚨 ฉุกเฉิน
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($symptoms)): ?>
                                <p class="text-sm text-gray-600 mb-1">
                                    <i class="fas fa-stethoscope text-blue-500 mr-1"></i>
                                    อาการ: <?= htmlspecialchars(is_array($symptoms) ? implode(', ', $symptoms) : $symptoms) ?>
                                </p>
                                <?php endif; ?>
                                
                                <?php if ($severity !== null): ?>
                                <p class="text-sm mb-1">
                                    <i class="fas fa-chart-bar text-orange-500 mr-1"></i>
                                    ความรุนแรง: 
                                    <span class="font-medium <?= $severity >= 7 ? 'text-red-600' : ($severity >= 4 ? 'text-yellow-600' : 'text-green-600') ?>">
                                        <?= $severity ?>/10
                                    </span>
                                    <?php if ($severityLevel): ?>
                                    <span class="text-xs ml-1 px-1.5 py-0.5 rounded <?= $severityLevel === 'critical' ? 'bg-red-100 text-red-700' : ($severityLevel === 'high' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600') ?>">
                                        <?= $severityLevel ?>
                                    </span>
                                    <?php endif; ?>
                                </p>
                                <?php endif; ?>
                                
                                <?php if (!empty($redFlags)): ?>
                                <div class="text-sm text-red-600 mb-1">
                                    <?php foreach (array_slice($redFlags, 0, 2) as $flag): ?>
                                    <p><i class="fas fa-exclamation-triangle mr-1"></i><?= htmlspecialchars(is_array($flag) ? ($flag['message'] ?? '') : $flag) ?></p>
                                    <?php endforeach; ?>
                                    <?php if (count($redFlags) > 2): ?>
                                    <p class="text-xs text-red-400">+<?= count($redFlags) - 2 ?> รายการเพิ่มเติม</p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <p class="text-xs text-gray-400">
                                    <i class="far fa-clock mr-1"></i>
                                    <?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?>
                                </p>
                            </div>
                            
                            <div class="flex flex-col gap-2">
                                <button onclick="viewDetail(<?= $notif['id'] ?>)" 
                                        class="px-3 py-1.5 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600">
                                    <i class="fas fa-eye mr-1"></i>ดู
                                </button>
                                <button onclick="handleNotification(<?= $notif['id'] ?>, 'handled')" 
                                        class="px-3 py-1.5 bg-green-500 text-white text-sm rounded-lg hover:bg-green-600">
                                    <i class="fas fa-check mr-1"></i>จัดการแล้ว
                                </button>
                                <button onclick="startVideoCall(<?= $notif['user_id'] ?>)" 
                                        class="px-3 py-1.5 bg-purple-500 text-white text-sm rounded-lg hover:bg-purple-600">
                                    <i class="fas fa-video mr-1"></i>โทร
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Sessions -->
        <div>
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b">
                    <h3 class="font-semibold text-gray-800">
                        <i class="fas fa-history text-purple-500 mr-2"></i>Session ล่าสุด
                    </h3>
                </div>
                
                <div class="divide-y max-h-[600px] overflow-y-auto">
                    <?php if (empty($recentSessions)): ?>
                    <div class="p-6 text-center text-gray-400">
                        <p>ยังไม่มี session</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($recentSessions as $session): ?>
                    <?php 
                        $sessionData = json_decode($session['triage_data'] ?? '{}', true);
                        $statusClass = 'status-' . $session['status'];
                    ?>
                    <div class="p-4 hover:bg-gray-50 cursor-pointer" onclick="viewSession(<?= $session['id'] ?>)">
                        <div class="flex items-center gap-3 mb-2">
                            <img src="<?= htmlspecialchars($session['picture_url'] ?? 'assets/images/default-avatar.png') ?>" 
                                 class="w-8 h-8 rounded-full object-cover" alt="">
                            <span class="font-medium text-sm text-gray-800">
                                <?= htmlspecialchars($session['display_name'] ?? 'ไม่ระบุ') ?>
                            </span>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= ucfirst($session['status']) ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($sessionData['symptoms'])): ?>
                        <p class="text-xs text-gray-500 mb-1">
                            <?= htmlspecialchars(implode(', ', array_slice($sessionData['symptoms'], 0, 3))) ?>
                        </p>
                        <?php endif; ?>
                        
                        <p class="text-xs text-gray-400">
                            <?= date('d/m H:i', strtotime($session['updated_at'])) ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="bg-white rounded-xl shadow mt-6 p-4">
                <h3 class="font-semibold text-gray-800 mb-4">
                    <i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Actions
                </h3>
                <div class="space-y-2">
                    <a href="chat.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                        <i class="fas fa-comments text-blue-500"></i>
                        <span class="text-sm">เปิดแชท</span>
                    </a>
                    <a href="users.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                        <i class="fas fa-users text-green-500"></i>
                        <span class="text-sm">รายชื่อลูกค้า</span>
                    </a>
                    <a href="drug-interactions.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                        <i class="fas fa-pills text-red-500"></i>
                        <span class="text-sm">ยาตีกัน</span>
                    </a>
                    <a href="triage-analytics.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                        <i class="fas fa-chart-pie text-purple-500"></i>
                        <span class="text-sm">สถิติ Triage</span>
                    </a>
                    <a href="broadcast.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                        <i class="fas fa-bullhorn text-orange-500"></i>
                        <span class="text-sm">ส่ง Broadcast</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detail Modal -->
<div id="detailModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black bg-opacity-50" onclick="closeModal()"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden">
            <div class="bg-gradient-to-r from-green-500 to-teal-500 p-4 text-white">
                <div class="flex justify-between items-center">
                    <h3 class="font-bold text-lg"><i class="fas fa-clipboard-list mr-2"></i>รายละเอียดการซักประวัติ</h3>
                    <button onclick="closeModal()" class="hover:opacity-80"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div id="modalContent" class="p-6 overflow-y-auto max-h-[60vh]">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="p-4 border-t bg-gray-50">
                <!-- Drug Selection -->
                <div id="drugSelection" class="mb-4 hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">เลือกยาที่จะแนะนำ:</label>
                    <div id="drugList" class="grid grid-cols-2 gap-2 max-h-40 overflow-y-auto p-2 bg-gray-100 rounded-lg">
                        <!-- Drugs loaded via AJAX -->
                    </div>
                </div>
                
                <!-- Pharmacist Note -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">หมายเหตุถึงลูกค้า:</label>
                    <textarea id="pharmacistNote" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" 
                              placeholder="เช่น ทานหลังอาหาร, หลีกเลี่ยงแอลกอฮอล์..."></textarea>
                </div>
                
                <div class="flex justify-end gap-3">
                    <button onclick="rejectCase()" class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200">
                        <i class="fas fa-times mr-2"></i>ปฏิเสธ
                    </button>
                    <button onclick="sendMessage()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                        <i class="fas fa-comment mr-2"></i>ส่งข้อความ
                    </button>
                    <button onclick="approveAndSend()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        <i class="fas fa-check mr-2"></i>อนุมัติและส่งยา
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Send Message Modal -->
<div id="messageModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black bg-opacity-50" onclick="closeMessageModal()"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md">
            <div class="bg-gradient-to-r from-blue-500 to-indigo-500 p-4 text-white">
                <div class="flex justify-between items-center">
                    <h3 class="font-bold text-lg"><i class="fas fa-comment mr-2"></i>ส่งข้อความถึงลูกค้า</h3>
                    <button onclick="closeMessageModal()" class="hover:opacity-80"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="p-6">
                <textarea id="customMessage" rows="4" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" 
                          placeholder="พิมพ์ข้อความ..."></textarea>
                <div class="mt-4 flex gap-2 flex-wrap">
                    <button onclick="setQuickMessage('กรุณารอสักครู่ เภสัชกรกำลังตรวจสอบ')" class="px-3 py-1 bg-gray-100 rounded-full text-sm hover:bg-gray-200">
                        รอสักครู่
                    </button>
                    <button onclick="setQuickMessage('กรุณาโทรมาที่ร้านเพื่อสอบถามเพิ่มเติม')" class="px-3 py-1 bg-gray-100 rounded-full text-sm hover:bg-gray-200">
                        โทรมาที่ร้าน
                    </button>
                    <button onclick="setQuickMessage('แนะนำให้พบแพทย์เพื่อตรวจเพิ่มเติม')" class="px-3 py-1 bg-gray-100 rounded-full text-sm hover:bg-gray-200">
                        พบแพทย์
                    </button>
                </div>
            </div>
            <div class="p-4 border-t bg-gray-50 flex justify-end gap-3">
                <button onclick="closeMessageModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
                <button onclick="sendCustomMessage()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                    <i class="fas fa-paper-plane mr-2"></i>ส่ง
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentNotificationId = null;
let currentUserId = null;
let currentTriageData = null;
let availableDrugs = [];
let selectedDrugs = [];

function refreshData() {
    location.reload();
}

function viewDetail(id) {
    currentNotificationId = id;
    document.getElementById('detailModal').classList.remove('hidden');
    document.getElementById('modalContent').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i></div>';
    
    fetch(`api/pharmacist.php?action=get_detail&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentUserId = data.data.user_id;
                currentTriageData = data.data.triage_data || {};
                document.getElementById('modalContent').innerHTML = buildDetailHTML(data.data);
                loadAvailableDrugs();
            } else {
                document.getElementById('modalContent').innerHTML = '<p class="text-red-500">เกิดข้อผิดพลาด</p>';
            }
        })
        .catch(e => {
            document.getElementById('modalContent').innerHTML = '<p class="text-red-500">เกิดข้อผิดพลาด</p>';
        });
}

function loadAvailableDrugs() {
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_drugs', line_account_id: <?= $currentBotId ? $currentBotId : 'null' ?> })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.drugs) {
            availableDrugs = data.drugs;
            renderDrugList();
            document.getElementById('drugSelection').classList.remove('hidden');
        }
    });
}

function renderDrugList() {
    const container = document.getElementById('drugList');
    const recommendations = currentTriageData?.recommendations || [];
    const recommendedIds = recommendations.map(r => r.id);
    
    container.innerHTML = availableDrugs.map(drug => {
        const isRecommended = recommendedIds.includes(drug.id);
        const isSelected = selectedDrugs.some(d => d.id === drug.id);
        // Escape drug name for JavaScript string
        const escapedName = (drug.name || '').replace(/'/g, "\\'").replace(/"/g, '\\"');
        return `
            <label class="flex items-center gap-2 p-2 bg-white rounded cursor-pointer hover:bg-green-50 ${isRecommended ? 'ring-2 ring-green-500' : ''}">
                <input type="checkbox" value="${drug.id}" ${isSelected ? 'checked' : ''} 
                       onchange="toggleDrug(${drug.id}, '${escapedName}', ${drug.price || 0})"
                       class="w-4 h-4 text-green-500 rounded">
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium truncate">${drug.name}</div>
                    <div class="text-xs text-gray-500">฿${drug.price || 0}</div>
                </div>
                ${isRecommended ? '<span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">AI แนะนำ</span>' : ''}
            </label>
        `;
    }).join('');
    
    // Pre-select AI recommended drugs
    if (selectedDrugs.length === 0 && recommendations.length > 0) {
        recommendations.forEach(drug => {
            selectedDrugs.push({ id: drug.id, name: drug.name, price: drug.price || 0 });
        });
        renderDrugList();
    }
}

function toggleDrug(id, name, price) {
    const index = selectedDrugs.findIndex(d => d.id === id);
    if (index > -1) {
        selectedDrugs.splice(index, 1);
    } else {
        selectedDrugs.push({ id, name, price });
    }
    console.log('Selected drugs:', selectedDrugs);
}

function buildDetailHTML(data) {
    // Merge triage_data and notification_data for complete information
    const triage = data.triage_data || {};
    const notifData = data.notification_data || {};
    
    // Use notification_data as primary source, fallback to triage_data
    const symptoms = notifData.symptoms || triage.symptoms || [];
    const duration = notifData.duration || triage.duration || '';
    const severity = notifData.severity || triage.severity || null;
    const severityLevel = notifData.severity_level || '';
    const associatedSymptoms = notifData.associated_symptoms || triage.associated_symptoms || [];
    const allergies = notifData.allergies || triage.allergies || [];
    const medicalHistory = notifData.medical_history || triage.medical_history || [];
    const currentMedications = notifData.current_medications || triage.current_medications || [];
    const recommendations = notifData.recommendations || triage.recommendations || [];
    const interactions = notifData.interactions || triage.interactions || [];
    const redFlags = notifData.red_flags || triage.red_flags || [];
    const aiAssessment = notifData.ai_assessment || '';
    const recommendedAction = notifData.recommended_action || '';
    
    let html = `
        <div class="space-y-4">
            <div class="flex items-center gap-4 pb-4 border-b">
                <img src="${data.picture_url || 'assets/images/default-avatar.png'}" class="w-16 h-16 rounded-full">
                <div>
                    <h4 class="font-bold text-lg">${data.display_name || 'ไม่ระบุชื่อ'}</h4>
                    <p class="text-gray-500">${data.phone || '-'}</p>
                    ${data.type === 'escalation' ? '<span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded">ขอปรึกษาเภสัชกร</span>' : ''}
                    ${data.type === 'emergency_alert' ? '<span class="px-2 py-0.5 bg-red-100 text-red-700 text-xs rounded">🚨 ฉุกเฉิน</span>' : ''}
                </div>
            </div>
    `;
    
    // Red Flags (show first if present)
    if (redFlags && redFlags.length > 0) {
        html += `
            <div class="bg-red-50 p-3 rounded-lg border border-red-200">
                <h5 class="font-semibold text-red-700 mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>⚠️ Red Flags</h5>
                <ul class="text-red-600 text-sm space-y-1">
        `;
        redFlags.forEach(flag => {
            const flagMsg = typeof flag === 'object' ? (flag.message || flag.symptom || '') : flag;
            html += `<li>• ${flagMsg}</li>`;
        });
        html += `</ul></div>`;
    }
    
    // Symptoms
    if (symptoms && (Array.isArray(symptoms) ? symptoms.length > 0 : symptoms)) {
        const symptomsText = Array.isArray(symptoms) ? symptoms.join(', ') : symptoms;
        html += `
            <div>
                <h5 class="font-semibold text-gray-700 mb-2"><i class="fas fa-stethoscope text-blue-500 mr-2"></i>อาการ</h5>
                <p class="text-gray-600">${symptomsText}</p>
            </div>
        `;
    }
    
    // Duration
    if (duration) {
        html += `
            <div>
                <h5 class="font-semibold text-gray-700 mb-2"><i class="fas fa-clock text-green-500 mr-2"></i>ระยะเวลา</h5>
                <p class="text-gray-600">${duration}</p>
            </div>
        `;
    }
    
    // Severity
    if (severity) {
        const severityColor = severity >= 7 ? 'red' : (severity >= 4 ? 'yellow' : 'green');
        html += `
            <div>
                <h5 class="font-semibold text-gray-700 mb-2"><i class="fas fa-chart-bar text-orange-500 mr-2"></i>ความรุนแรง</h5>
                <div class="flex items-center gap-2">
                    <div class="flex-1 bg-gray-200 rounded-full h-3">
                        <div class="bg-${severityColor}-500 h-3 rounded-full" style="width: ${severity * 10}%"></div>
                    </div>
                    <span class="font-bold text-${severityColor}-600">${severity}/10</span>
                    ${severityLevel ? `<span class="text-xs px-2 py-0.5 rounded ${severityLevel === 'critical' ? 'bg-red-100 text-red-700' : (severityLevel === 'high' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600')}">${severityLevel}</span>` : ''}
                </div>
            </div>
        `;
    }
    
    // Associated Symptoms
    if (associatedSymptoms && (Array.isArray(associatedSymptoms) ? associatedSymptoms.length > 0 : associatedSymptoms)) {
        const assocText = Array.isArray(associatedSymptoms) ? associatedSymptoms.join(', ') : associatedSymptoms;
        html += `
            <div>
                <h5 class="font-semibold text-gray-700 mb-2"><i class="fas fa-plus-circle text-purple-500 mr-2"></i>อาการร่วม</h5>
                <p class="text-gray-600">${assocText}</p>
            </div>
        `;
    }
    
    // AI Assessment
    if (aiAssessment) {
        html += `
            <div class="bg-purple-50 p-3 rounded-lg">
                <h5 class="font-semibold text-purple-700 mb-2"><i class="fas fa-robot mr-2"></i>การประเมินของ AI</h5>
                <p class="text-purple-600">${aiAssessment}</p>
                ${recommendedAction ? `<p class="text-xs text-purple-500 mt-1">แนะนำ: ${recommendedAction}</p>` : ''}
            </div>
        `;
    }
    
    // Allergies
    if (allergies && (Array.isArray(allergies) ? allergies.length > 0 : allergies)) {
        const allergiesText = Array.isArray(allergies) ? allergies.join(', ') : allergies;
        html += `
            <div class="bg-red-50 p-3 rounded-lg">
                <h5 class="font-semibold text-red-700 mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>แพ้ยา</h5>
                <p class="text-red-600 font-medium">${allergiesText}</p>
            </div>
        `;
    }
    
    // Medical History
    if (medicalHistory && (Array.isArray(medicalHistory) ? medicalHistory.length > 0 : medicalHistory)) {
        const historyText = Array.isArray(medicalHistory) ? medicalHistory.join(', ') : medicalHistory;
        html += `
            <div class="bg-yellow-50 p-3 rounded-lg">
                <h5 class="font-semibold text-yellow-700 mb-2"><i class="fas fa-notes-medical mr-2"></i>โรคประจำตัว</h5>
                <p class="text-yellow-600">${historyText}</p>
            </div>
        `;
    }
    
    // Current Medications
    if (currentMedications && (Array.isArray(currentMedications) ? currentMedications.length > 0 : currentMedications)) {
        const medsText = Array.isArray(currentMedications) ? currentMedications.join(', ') : currentMedications;
        html += `
            <div class="bg-blue-50 p-3 rounded-lg">
                <h5 class="font-semibold text-blue-700 mb-2"><i class="fas fa-pills mr-2"></i>ยาที่ทานอยู่</h5>
                <p class="text-blue-600">${medsText}</p>
            </div>
        `;
    }
    
    // AI Recommendations
    if (recommendations && recommendations.length > 0) {
        html += `
            <div>
                <h5 class="font-semibold text-gray-700 mb-2"><i class="fas fa-robot text-purple-500 mr-2"></i>AI แนะนำ</h5>
                <ul class="space-y-2">
        `;
        recommendations.forEach(drug => {
            html += `<li class="flex justify-between items-center p-2 bg-gray-50 rounded">
                <span>${drug.name || drug}</span>
                ${drug.price ? `<span class="text-green-600 font-medium">฿${drug.price}</span>` : ''}
            </li>`;
        });
        html += `</ul></div>`;
    }
    
    // Drug Interactions
    if (interactions && interactions.length > 0) {
        html += `
            <div class="bg-orange-50 p-3 rounded-lg">
                <h5 class="font-semibold text-orange-700 mb-2"><i class="fas fa-exclamation-circle mr-2"></i>ข้อควรระวัง</h5>
                <ul class="text-orange-600 text-sm space-y-1">
        `;
        interactions.forEach(i => {
            html += `<li>• ${i.message || i}</li>`;
        });
        html += `</ul></div>`;
    }
    
    html += '</div>';
    
    // Store merged triage data for drug selection
    currentTriageData = {
        symptoms, duration, severity, severityLevel, associatedSymptoms,
        allergies, medicalHistory, currentMedications, recommendations, interactions
    };
    
    return html;
}

function closeModal() {
    document.getElementById('detailModal').classList.add('hidden');
    currentNotificationId = null;
    currentUserId = null;
    currentTriageData = null;
    selectedDrugs = [];
}

function handleNotification(id, status) {
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id, status })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelector(`[data-id="${id}"]`)?.remove();
        }
    });
}

function startVideoCall(userId) {
    window.open(`video-call.php?user_id=${userId}`, '_blank');
}

function viewSession(id) {
    window.location.href = `triage-analytics.php?session=${id}`;
}

function approveAndSend() {
    console.log('approveAndSend called', { currentNotificationId, currentUserId, selectedDrugs });
    
    if (!currentNotificationId || !currentUserId) {
        alert('ไม่พบข้อมูล notification');
        return;
    }
    
    if (selectedDrugs.length === 0) {
        alert('กรุณาเลือกยาที่จะแนะนำ');
        return;
    }
    
    if (!confirm('ยืนยันอนุมัติและส่งยาให้ลูกค้า?')) return;
    
    const note = document.getElementById('pharmacistNote').value;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'approve_drugs',
            notification_id: currentNotificationId,
            user_id: currentUserId,
            drugs: selectedDrugs,
            note: note
        })
    })
    .then(r => {
        console.log('Response status:', r.status);
        return r.text();
    })
    .then(text => {
        console.log('Response text:', text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
                alert('✅ อนุมัติเรียบร้อย! ' + (data.message || ''));
                closeModal();
                refreshData();
            } else {
                alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            alert('เกิดข้อผิดพลาด: Response ไม่ถูกต้อง');
        }
    })
    .catch(err => {
        console.error('Fetch error:', err);
        alert('เกิดข้อผิดพลาด: ' + err.message);
    });
}

function rejectCase() {
    if (!currentNotificationId || !currentUserId) return;
    
    const reason = prompt('ระบุเหตุผล:', 'อาการที่แจ้งมาควรพบแพทย์เพื่อตรวจวินิจฉัยเพิ่มเติม');
    if (reason === null) return;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'reject',
            notification_id: currentNotificationId,
            user_id: currentUserId,
            reason: reason
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('ส่งข้อความแจ้งลูกค้าแล้ว');
            closeModal();
            refreshData();
        }
    });
}

function sendMessage() {
    document.getElementById('messageModal').classList.remove('hidden');
}

function closeMessageModal() {
    document.getElementById('messageModal').classList.add('hidden');
    document.getElementById('customMessage').value = '';
}

function setQuickMessage(msg) {
    document.getElementById('customMessage').value = msg;
}

function sendCustomMessage() {
    const message = document.getElementById('customMessage').value.trim();
    if (!message || !currentUserId) return;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'send_message',
            user_id: currentUserId,
            message: message
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✅ ส่งข้อความเรียบร้อย');
            closeMessageModal();
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown error'));
        }
    });
}

// Auto refresh every 30 seconds
setInterval(refreshData, 30000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
