<?php
/**
 * Pharmacy Dashboard Tab Content
 * แดชบอร์ดสำหรับเภสัชกร
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// Get statistics
$stats = [
    'pending' => 0,
    'urgent' => 0,
    'today_completed' => 0,
    'total_sessions' => 0,
];

try {
    // Pending - count from triage_sessions where status is NULL, active, or empty (not completed/cancelled)
    $stmt = $db->query("SELECT COUNT(*) FROM triage_sessions WHERE status IS NULL OR status = 'active' OR status = ''");
    $stats['pending'] = $stmt->fetchColumn();
    
    // Urgent - count emergency cases from triage_sessions
    $stmt = $db->query("SELECT COUNT(*) FROM triage_sessions WHERE current_state = 'emergency' AND (status IS NULL OR status = 'active' OR status = '')");
    $stats['urgent'] = $stmt->fetchColumn();
    
    // Today completed
    $stmt = $db->query("SELECT COUNT(*) FROM triage_sessions WHERE status = 'completed' AND DATE(completed_at) = CURDATE()");
    $stats['today_completed'] = $stmt->fetchColumn();
    
    // Total sessions
    $stmt = $db->query("SELECT COUNT(*) FROM triage_sessions");
    $stats['total_sessions'] = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Pharmacist Dashboard Stats Error: " . $e->getMessage());
}

// Get pending notifications - now from triage_sessions directly
$notifications = [];
try {
    $stmt = $db->query("
        SELECT ts.id, ts.user_id, ts.current_state, ts.triage_data, ts.status as session_status,
               ts.created_at, ts.line_account_id,
               u.display_name, u.picture_url, u.phone,
               CASE WHEN ts.current_state = 'emergency' THEN 'urgent' ELSE 'normal' END as priority
        FROM triage_sessions ts
        LEFT JOIN users u ON ts.user_id = u.id
        WHERE ts.status IS NULL OR ts.status = 'active' OR ts.status = ''
        ORDER BY 
            CASE WHEN ts.current_state = 'emergency' THEN 0 ELSE 1 END,
            ts.created_at DESC
        LIMIT 20
    ");
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

<!-- Header Actions -->
<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-gray-500">จัดการคำขอปรึกษาและอนุมัติยา</p>
    </div>
    <div class="flex gap-3">
        <button onclick="refreshData()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
            <i class="fas fa-sync-alt mr-2"></i>รีเฟรช
        </button>
        <a href="pharmacy.php?tab=interactions" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
            <i class="fas fa-pills mr-2"></i>ยาตีกัน
        </a>
        <a href="triage-analytics.php" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
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
                    $isUrgent = $notif['priority'] === 'urgent';
                    $symptoms = $triageData['symptoms'] ?? [];
                    if (is_string($symptoms)) $symptoms = [$symptoms];
                    $severity = $triageData['severity'] ?? null;
                    $severityLevel = null;
                    if ($severity !== null) {
                        if ($severity >= 8) $severityLevel = 'critical';
                        elseif ($severity >= 6) $severityLevel = 'high';
                        elseif ($severity >= 4) $severityLevel = 'medium';
                    }
                    $redFlags = $triageData['red_flags'] ?? [];
                    $isEmergency = ($notif['current_state'] ?? '') === 'emergency';
                    if ($isEmergency && empty($redFlags)) {
                        $redFlags = [['message' => 'ผู้ป่วยอยู่ในสถานะฉุกเฉิน']];
                    }
                    $sessionId = $notif['id'];
                ?>
                <div class="notification-card p-4 <?= $isUrgent ? 'priority-urgent bg-red-50' : 'priority-normal' ?>" 
                     data-id="<?= $sessionId ?>">
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
                                <?php if ($isEmergency): ?>
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
                            </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($redFlags)): ?>
                            <div class="text-sm text-red-600 mb-1">
                                <?php foreach (array_slice($redFlags, 0, 2) as $flag): ?>
                                <p><i class="fas fa-exclamation-triangle mr-1"></i><?= htmlspecialchars(is_array($flag) ? ($flag['message'] ?? '') : $flag) ?></p>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <p class="text-xs text-gray-400">
                                <i class="far fa-clock mr-1"></i>
                                <?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?>
                            </p>
                        </div>
                        
                        <div class="flex flex-col gap-2">
                            <a href="pharmacy.php?tab=dispense&session_id=<?= $sessionId ?>" 
                               class="px-3 py-1.5 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600 text-center">
                                <i class="fas fa-pills mr-1"></i>จ่ายยา
                            </a>
                            <button onclick="handleSession(<?= $sessionId ?>, 'completed')" 
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

    <!-- Recent Sessions & Quick Actions -->
    <div>
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b">
                <h3 class="font-semibold text-gray-800">
                    <i class="fas fa-history text-purple-500 mr-2"></i>Session ล่าสุด
                </h3>
            </div>
            
            <div class="divide-y max-h-[400px] overflow-y-auto">
                <?php if (empty($recentSessions)): ?>
                <div class="p-6 text-center text-gray-400">
                    <p>ยังไม่มี session</p>
                </div>
                <?php else: ?>
                <?php foreach ($recentSessions as $session): ?>
                <?php $statusClass = 'status-' . ($session['status'] ?? 'active'); ?>
                <div class="p-4 hover:bg-gray-50 cursor-pointer" onclick="viewSession(<?= $session['id'] ?>)">
                    <div class="flex items-center gap-3 mb-2">
                        <img src="<?= htmlspecialchars($session['picture_url'] ?? 'assets/images/default-avatar.png') ?>" 
                             class="w-8 h-8 rounded-full object-cover" alt="">
                        <span class="font-medium text-sm text-gray-800">
                            <?= htmlspecialchars($session['display_name'] ?? 'ไม่ระบุ') ?>
                        </span>
                        <span class="status-badge <?= $statusClass ?>">
                            <?= ucfirst($session['status'] ?? 'active') ?>
                        </span>
                    </div>
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
                <a href="inbox.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <i class="fas fa-comments text-blue-500"></i>
                    <span class="text-sm">เปิดแชท</span>
                </a>
                <a href="users.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <i class="fas fa-users text-green-500"></i>
                    <span class="text-sm">รายชื่อลูกค้า</span>
                </a>
                <a href="pharmacy.php?tab=interactions" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <i class="fas fa-pills text-red-500"></i>
                    <span class="text-sm">ยาตีกัน</span>
                </a>
                <a href="triage-analytics.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                    <i class="fas fa-chart-pie text-purple-500"></i>
                    <span class="text-sm">สถิติ Triage</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function refreshData() {
    location.reload();
}

function handleSession(sessionId, status) {
    if (!confirm('ยืนยันการเปลี่ยนสถานะ?')) return;
    
    fetch('api/pharmacist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', session_id: sessionId, status: status })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
        }
    });
}

function startVideoCall(userId) {
    window.open('video-call.php?user_id=' + userId, '_blank');
}

function viewSession(sessionId) {
    window.location.href = 'pharmacy.php?tab=dispense&session_id=' + sessionId;
}
</script>
