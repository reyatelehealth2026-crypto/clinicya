<?php
/**
 * Consent Management Tab Content
 * Part of consolidated settings.php
 */

// Get statistics
$consentStats = [];
$recentLogs = [];
$accessLogs = [];
$consentError = null;

try {
    // Total users with consent
    $stmt = $db->query("SELECT COUNT(DISTINCT user_id) as total FROM user_consents WHERE is_accepted = 1");
    $consentStats['total_consented'] = $stmt->fetchColumn();
    
    // By consent type
    $stmt = $db->query("
        SELECT consent_type, COUNT(*) as count 
        FROM user_consents 
        WHERE is_accepted = 1 
        GROUP BY consent_type
    ");
    $consentStats['by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Recent consent logs
    $stmt = $db->query("
        SELECT cl.*, u.display_name, u.line_user_id
        FROM consent_logs cl
        JOIN users u ON cl.user_id = u.id
        ORDER BY cl.created_at DESC
        LIMIT 50
    ");
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Data access logs
    $stmt = $db->query("
        SELECT dal.*, au.username as admin_name, u.display_name as target_user
        FROM data_access_logs dal
        LEFT JOIN admin_users au ON dal.admin_user_id = au.id
        LEFT JOIN users u ON dal.user_id = u.id
        ORDER BY dal.created_at DESC
        LIMIT 50
    ");
    $accessLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $consentError = $e->getMessage();
}

$consentTypeLabels = [
    'privacy_policy' => '🔒 นโยบายความเป็นส่วนตัว',
    'terms_of_service' => '📋 ข้อตกลงการใช้งาน',
    'health_data' => '💊 ข้อมูลสุขภาพ',
    'marketing' => '📢 การตลาด'
];

$actionLabels = [
    'accept' => '<span class="text-green-600">✅ ยอมรับ</span>',
    'withdraw' => '<span class="text-red-600">❌ ถอนความยินยอม</span>',
    'update' => '<span class="text-blue-600">🔄 อัพเดท</span>'
];
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-xl font-bold">🔒 Consent Management</h2>
            <p class="text-gray-500">จัดการความยินยอมและ Audit Log ตาม PDPA</p>
        </div>
        <div class="flex gap-2">
            <a href="privacy-policy.php" target="_blank" class="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200">
                📄 Privacy Policy
            </a>
            <a href="terms-of-service.php" target="_blank" class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200">
                📋 Terms of Service
            </a>
        </div>
    </div>

    <?php if ($consentError): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
        <p>❌ <?= htmlspecialchars($consentError) ?></p>
        <p class="text-sm mt-2">กรุณารัน migration ก่อน</p>
    </div>
    <?php else: ?>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($consentStats['total_consented'] ?? 0) ?></div>
            <div class="text-gray-500 text-sm">ผู้ใช้ที่ยินยอมแล้ว</div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-green-600"><?= number_format($consentStats['by_type']['privacy_policy'] ?? 0) ?></div>
            <div class="text-gray-500 text-sm">ยอมรับ Privacy Policy</div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($consentStats['by_type']['terms_of_service'] ?? 0) ?></div>
            <div class="text-gray-500 text-sm">ยอมรับ Terms of Service</div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-orange-600"><?= number_format($consentStats['by_type']['health_data'] ?? 0) ?></div>
            <div class="text-gray-500 text-sm">ยินยอมข้อมูลสุขภาพ</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow">
        <div class="border-b">
            <nav class="flex -mb-px">
                <button onclick="showConsentTab('consent')" id="consent-tab-consent" class="consent-tab-btn px-6 py-3 border-b-2 border-blue-500 text-blue-600 font-medium">
                    📝 Consent Logs
                </button>
                <button onclick="showConsentTab('access')" id="consent-tab-access" class="consent-tab-btn px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                    👁️ Data Access Logs
                </button>
            </nav>
        </div>

        <!-- Consent Logs Tab -->
        <div id="consent-panel-consent" class="p-4">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เวลา</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ใช้</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ประเภท</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">การกระทำ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เวอร์ชัน</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($recentLogs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-500">
                                <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium"><?= htmlspecialchars($log['display_name'] ?? 'Unknown') ?></div>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars(substr($log['line_user_id'] ?? '', 0, 10)) ?>...</div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?= $consentTypeLabels[$log['consent_type']] ?? $log['consent_type'] ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?= $actionLabels[$log['action']] ?? $log['action'] ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                v<?= htmlspecialchars($log['consent_version']) ?>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-400">
                                <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentLogs)): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                ยังไม่มีข้อมูล Consent Log
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Data Access Logs Tab -->
        <div id="consent-panel-access" class="p-4 hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เวลา</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Admin</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">การกระทำ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ข้อมูลที่เข้าถึง</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($accessLogs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-500">
                                <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="px-4 py-3 font-medium">
                                <?= htmlspecialchars($log['admin_name'] ?? 'System') ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?= htmlspecialchars($log['action']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="text-gray-500"><?= htmlspecialchars($log['resource_type']) ?></span>
                                <?php if ($log['target_user']): ?>
                                <span class="text-blue-600">(<?= htmlspecialchars($log['target_user']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-400">
                                <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($accessLogs)): ?>
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                ยังไม่มีข้อมูล Access Log
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
function showConsentTab(tab) {
    // Hide all panels
    document.querySelectorAll('[id^="consent-panel-"]').forEach(p => p.classList.add('hidden'));
    // Deactivate all tabs
    document.querySelectorAll('.consent-tab-btn').forEach(t => {
        t.classList.remove('border-blue-500', 'text-blue-600');
        t.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected panel
    document.getElementById('consent-panel-' + tab).classList.remove('hidden');
    // Activate selected tab
    const activeTab = document.getElementById('consent-tab-' + tab);
    activeTab.classList.add('border-blue-500', 'text-blue-600');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
}
</script>
