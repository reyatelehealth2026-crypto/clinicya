<?php
/**
 * Rewards Tab Content - จัดการรางวัลแลกแต้ม
 * Part of membership.php consolidated page
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// This file is included from membership.php
// Variables available: $db, $lineAccountId, $adminId, $loyalty
// AJAX handlers are now in membership.php (before HTML output)

// Check if required tables exist
try {
    $db->query("SELECT 1 FROM rewards LIMIT 1");
} catch (PDOException $e) {
    echo '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">';
    echo '<h2 class="text-xl font-bold text-yellow-800 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>ต้องรัน Migration ก่อน</h2>';
    echo '<p class="text-yellow-700 mb-4">ตาราง rewards ยังไม่มีในฐานข้อมูล กรุณารัน migration ก่อนใช้งาน</p>';
    echo '<a href="/install/run_loyalty_points_migration.php" class="inline-block px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">รัน Migration</a>';
    echo '</div>';
    return;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? null;
    
    $sql = "SELECT rr.id, rr.redemption_code, rr.created_at, rr.status, rr.points_used, rr.approved_at, rr.delivered_at, rr.notes,
            r.name as reward_name, r.reward_type, r.points_required, u.display_name, u.phone, u.line_user_id, a.username as approved_by
            FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id JOIN users u ON rr.user_id = u.id
            LEFT JOIN admin_users a ON rr.approved_by = a.id WHERE (rr.line_account_id = ? OR rr.line_account_id IS NULL) AND DATE(rr.created_at) BETWEEN ? AND ?";
    $params = [$lineAccountId, $startDate, $endDate];
    if ($status) { $sql .= " AND rr.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY rr.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $redemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="redemptions_' . $startDate . '_to_' . $endDate . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'รหัส', 'วันที่', 'สถานะ', 'รางวัล', 'ประเภท', 'แต้ม', 'ผู้แลก', 'เบอร์โทร', 'LINE ID', 'อนุมัติโดย', 'วันอนุมัติ', 'วันส่งมอบ', 'หมายเหตุ']);
    $statusLabels = ['pending' => 'รอดำเนินการ', 'approved' => 'อนุมัติแล้ว', 'delivered' => 'ส่งมอบแล้ว', 'cancelled' => 'ยกเลิก'];
    $typeLabels = ['discount' => 'ส่วนลด', 'shipping' => 'ค่าส่งฟรี', 'gift' => 'ของแถม', 'product' => 'สินค้า', 'coupon' => 'คูปอง', 'voucher' => 'บัตรกำนัล'];
    foreach ($redemptions as $row) {
        fputcsv($output, [$row['id'], $row['redemption_code'], $row['created_at'], $statusLabels[$row['status']] ?? $row['status'],
            $row['reward_name'], $typeLabels[$row['reward_type']] ?? $row['reward_type'], $row['points_used'], $row['display_name'],
            $row['phone'] ?? '-', $row['line_user_id'], $row['approved_by'] ?? '-', $row['approved_at'] ?? '-', $row['delivered_at'] ?? '-', $row['notes'] ?? '']);
    }
    fclose($output);
    exit;
}

// Helper function to send LINE notification
function sendRedemptionNotification($db, $lineAccountId, $redemptionId, $status) {
    try {
        $stmt = $db->prepare("SELECT rr.*, r.name as reward_name, u.line_user_id FROM reward_redemptions rr
            JOIN rewards r ON rr.reward_id = r.id JOIN users u ON rr.user_id = u.id WHERE rr.id = ?");
        $stmt->execute([$redemptionId]);
        $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$redemption) return false;
        
        $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || empty($account['channel_access_token'])) return false;
        
        $messages = [
            'approved' => "✅ รางวัลได้รับการอนุมัติ\n\nรางวัล: {$redemption['reward_name']}\nรหัส: {$redemption['redemption_code']}\n\nกรุณาติดต่อรับรางวัลที่ร้าน",
            'delivered' => "🎁 ส่งมอบรางวัลแล้ว\n\nรางวัล: {$redemption['reward_name']}\nรหัส: {$redemption['redemption_code']}\n\nขอบคุณที่ใช้บริการ",
            'cancelled' => "❌ ยกเลิกการแลกรางวัล\n\nรางวัล: {$redemption['reward_name']}\n\nแต้มได้ถูกคืนเข้าบัญชีของคุณแล้ว"
        ];
        $msg = $messages[$status] ?? null;
        if (!$msg) return false;
        
        $data = ['to' => $redemption['line_user_id'], 'messages' => [['type' => 'text', 'text' => $msg]]];
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $account['channel_access_token']],
            CURLOPT_POSTFIELDS => json_encode($data)]);
        curl_exec($ch);
        curl_close($ch);
        return true;
    } catch (Exception $e) { return false; }
}

// Get data for display
$sql = "SELECT r.*, COALESCE(rc.redemption_count, 0) as redemption_count FROM rewards r
    LEFT JOIN (SELECT reward_id, COUNT(*) as redemption_count FROM reward_redemptions GROUP BY reward_id) rc ON r.id = rc.reward_id
    WHERE r.line_account_id = ? OR r.line_account_id IS NULL ORDER BY r.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute([$lineAccountId]);
$rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $loyalty->getPointsSummary();
$pendingRedemptions = $loyalty->getAllRedemptions('pending', 50);
$recentRedemptions = $loyalty->getAllRedemptions(null, 100);

$rewardTab = $_GET['reward_tab'] ?? 'rewards';

// Helper functions
function getRewardIcon($type) {
    $icons = ['discount' => 'fa-percent', 'shipping' => 'fa-truck', 'gift' => 'fa-gift', 'product' => 'fa-box', 'coupon' => 'fa-ticket-alt', 'voucher' => 'fa-credit-card'];
    return $icons[$type] ?? 'fa-gift';
}
function getRewardTypeBadge($type) {
    $badges = ['discount' => 'bg-green-100 text-green-700', 'shipping' => 'bg-blue-100 text-blue-700', 'gift' => 'bg-pink-100 text-pink-700', 'product' => 'bg-orange-100 text-orange-700', 'coupon' => 'bg-purple-100 text-purple-700', 'voucher' => 'bg-indigo-100 text-indigo-700'];
    return $badges[$type] ?? 'bg-gray-100 text-gray-700';
}
function getRewardTypeLabel($type) {
    $labels = ['discount' => 'ส่วนลด', 'shipping' => 'ค่าส่งฟรี', 'gift' => 'ของแถม', 'product' => 'สินค้า', 'coupon' => 'คูปอง', 'voucher' => 'บัตรกำนัล'];
    return $labels[$type] ?? $type;
}
function getStatusBadge($status) {
    $badges = ['pending' => 'bg-orange-100 text-orange-700', 'approved' => 'bg-green-100 text-green-700', 'delivered' => 'bg-blue-100 text-blue-700', 'cancelled' => 'bg-red-100 text-red-700'];
    return $badges[$status] ?? 'bg-gray-100 text-gray-700';
}
function getStatusLabel($status) {
    $labels = ['pending' => 'รอดำเนินการ', 'approved' => 'อนุมัติแล้ว', 'delivered' => 'ส่งมอบแล้ว', 'cancelled' => 'ยกเลิก'];
    return $labels[$status] ?? $status;
}
?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-coins text-purple-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">แต้มที่แจกไป</p>
                <p class="text-xl font-bold text-purple-600"><?= number_format($summary['total_issued']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-exchange-alt text-green-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">แต้มที่ใช้ไป</p>
                <p class="text-xl font-bold text-green-600"><?= number_format($summary['total_redeemed']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-gift text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">รางวัลที่เปิดใช้</p>
                <p class="text-xl font-bold text-blue-600"><?= number_format($summary['active_rewards']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-orange-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">รอดำเนินการ</p>
                <p class="text-xl font-bold text-orange-600"><?= number_format($summary['pending_redemptions']) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Sub Tabs -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <a href="?tab=rewards&reward_tab=rewards" class="px-6 py-3 font-medium whitespace-nowrap <?= $rewardTab === 'rewards' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-gift mr-2"></i>รางวัล
        </a>
        <a href="?tab=rewards&reward_tab=redemptions" class="px-6 py-3 font-medium whitespace-nowrap <?= $rewardTab === 'redemptions' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-history mr-2"></i>ประวัติการแลก
            <?php if ($summary['pending_redemptions'] > 0): ?>
            <span class="ml-1 px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full"><?= $summary['pending_redemptions'] ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php if ($rewardTab === 'rewards'): ?>
<!-- Rewards List -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-gift mr-2 text-purple-500"></i>รายการรางวัล</h2>
        <button onclick="openRewardModal()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-plus mr-1"></i>เพิ่มรางวัล
        </button>
    </div>
    
    <?php if (empty($rewards)): ?>
    <div class="p-8 text-center">
        <i class="fas fa-gift text-5xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">ยังไม่มีรางวัล</p>
        <button onclick="openRewardModal()" class="mt-4 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-plus mr-1"></i>เพิ่มรางวัลแรก
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รางวัล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">ประเภท</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">แต้มที่ใช้</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">คงเหลือ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">แลกไปแล้ว</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($rewards as $reward): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0 overflow-hidden">
                                <?php if ($reward['image_url']): ?>
                                <img src="<?= htmlspecialchars($reward['image_url']) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                <i class="fas <?= getRewardIcon($reward['reward_type']) ?> text-xl text-gray-400"></i>
                                <?php endif; ?>
                            </div>
                            <div class="ml-3">
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($reward['name']) ?></p>
                                <p class="text-xs text-gray-500 line-clamp-1"><?= htmlspecialchars($reward['description'] ?? '-') ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?= getRewardTypeBadge($reward['reward_type']) ?>">
                            <?= getRewardTypeLabel($reward['reward_type']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-purple-600"><?= number_format($reward['points_required']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($reward['stock'] < 0): ?>
                        <span class="text-green-600">ไม่จำกัด</span>
                        <?php elseif ($reward['stock'] == 0): ?>
                        <span class="text-red-600 font-bold">หมด</span>
                        <?php else: ?>
                        <span class="font-medium"><?= number_format($reward['stock']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-blue-600 font-medium"><?= number_format($reward['redemption_count']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleReward(<?= $reward['id'] ?>)" class="px-3 py-1 rounded-full text-xs font-medium <?= $reward['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $reward['is_active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="editReward(<?= htmlspecialchars(json_encode($reward)) ?>)" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteReward(<?= $reward['id'] ?>, '<?= htmlspecialchars($reward['name']) ?>')" class="p-2 text-red-600 hover:bg-red-50 rounded-lg" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($rewardTab === 'redemptions'): ?>
<!-- Redemptions List -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex flex-wrap justify-between items-center gap-4">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-history mr-2 text-purple-500"></i>ประวัติการแลกรางวัล</h2>
        <div class="flex items-center gap-2">
            <input type="date" id="exportStartDate" value="<?= date('Y-m-01') ?>" class="px-3 py-2 border rounded-lg text-sm">
            <span class="text-gray-500">ถึง</span>
            <input type="date" id="exportEndDate" value="<?= date('Y-m-d') ?>" class="px-3 py-2 border rounded-lg text-sm">
            <button onclick="exportCSV()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                <i class="fas fa-file-csv mr-1"></i>Export CSV
            </button>
        </div>
    </div>
    
    <div class="px-4 pt-4 flex gap-2 flex-wrap">
        <a href="?tab=rewards&reward_tab=redemptions" class="px-3 py-1 rounded-full text-sm <?= !isset($_GET['status']) ? 'bg-purple-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">ทั้งหมด</a>
        <a href="?tab=rewards&reward_tab=redemptions&status=pending" class="px-3 py-1 rounded-full text-sm <?= ($_GET['status'] ?? '') === 'pending' ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">รอดำเนินการ</a>
        <a href="?tab=rewards&reward_tab=redemptions&status=approved" class="px-3 py-1 rounded-full text-sm <?= ($_GET['status'] ?? '') === 'approved' ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">อนุมัติแล้ว</a>
        <a href="?tab=rewards&reward_tab=redemptions&status=delivered" class="px-3 py-1 rounded-full text-sm <?= ($_GET['status'] ?? '') === 'delivered' ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">ส่งมอบแล้ว</a>
        <a href="?tab=rewards&reward_tab=redemptions&status=cancelled" class="px-3 py-1 rounded-full text-sm <?= ($_GET['status'] ?? '') === 'cancelled' ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">ยกเลิก</a>
    </div>
    
    <?php $filteredRedemptions = isset($_GET['status']) ? $loyalty->getAllRedemptions($_GET['status'], 100) : $recentRedemptions; ?>
    
    <?php if (empty($filteredRedemptions)): ?>
    <div class="p-8 text-center">
        <i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">ไม่มีรายการ</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้แลก</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รางวัล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">แต้มที่ใช้</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">รหัส</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">วันที่</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($filteredRedemptions as $r): ?>
                <tr class="hover:bg-gray-50 <?= $r['status'] === 'pending' ? 'bg-orange-50' : '' ?>">
                    <td class="px-4 py-3">
                        <div class="flex items-center">
                            <img src="<?= htmlspecialchars($r['picture_url'] ?? 'https://via.placeholder.com/40') ?>" class="w-8 h-8 rounded-full">
                            <span class="ml-2 text-sm"><?= htmlspecialchars($r['display_name'] ?? 'Unknown') ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($r['reward_name']) ?></td>
                    <td class="px-4 py-3 text-center font-medium text-purple-600"><?= number_format($r['points_used']) ?></td>
                    <td class="px-4 py-3 text-center"><code class="px-2 py-1 bg-gray-100 rounded text-xs"><?= htmlspecialchars($r['redemption_code']) ?></code></td>
                    <td class="px-4 py-3 text-center"><span class="px-2 py-1 text-xs rounded-full <?= getStatusBadge($r['status']) ?>"><?= getStatusLabel($r['status']) ?></span></td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($r['status'] === 'pending'): ?>
                        <button onclick="approveRedemption(<?= $r['id'] ?>)" class="px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600" title="อนุมัติ"><i class="fas fa-check"></i></button>
                        <button onclick="cancelRedemption(<?= $r['id'] ?>)" class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600" title="ยกเลิก"><i class="fas fa-times"></i></button>
                        <?php elseif ($r['status'] === 'approved'): ?>
                        <button onclick="deliverRedemption(<?= $r['id'] ?>)" class="px-2 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600" title="ส่งมอบแล้ว"><i class="fas fa-truck"></i> ส่งมอบ</button>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Reward Modal -->
<div id="rewardModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-semibold text-lg" id="rewardModalTitle">เพิ่มรางวัล</h3>
            <button onclick="closeRewardModal()" class="p-2 hover:bg-gray-100 rounded-lg"><i class="fas fa-times"></i></button>
        </div>
        <form id="rewardForm" class="p-4 space-y-4">
            <input type="hidden" name="reward_action" value="create">
            <input type="hidden" name="id" value="">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อรางวัล <span class="text-red-500">*</span></label>
                <input type="text" name="name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="เช่น ส่วนลด 50 บาท">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด</label>
                <textarea name="description" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="รายละเอียดเพิ่มเติม"></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ประเภท</label>
                    <select name="reward_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <option value="discount">ส่วนลด</option>
                        <option value="shipping">ค่าส่งฟรี</option>
                        <option value="gift">ของแถม</option>
                        <option value="product">สินค้า</option>
                        <option value="coupon">คูปอง</option>
                        <option value="voucher">บัตรกำนัล</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">แต้มที่ใช้ <span class="text-red-500">*</span></label>
                    <input type="number" name="points_required" required min="1" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="100">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">มูลค่า/รหัส</label>
                <input type="text" name="reward_value" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="เช่น 50 (บาท) หรือ COUPON123">
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนคงเหลือ</label>
                    <input type="number" name="stock" value="-1" min="-1" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <p class="text-xs text-gray-500 mt-1">-1 = ไม่จำกัด</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">จำกัดต่อคน</label>
                    <input type="number" name="max_per_user" value="0" min="0" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <p class="text-xs text-gray-500 mt-1">0 = ไม่จำกัด</p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">เริ่มใช้ได้</label>
                    <input type="date" name="valid_from" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">หมดอายุ</label>
                    <input type="date" name="valid_until" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">URL รูปภาพ</label>
                <input type="url" name="image_url" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="https://...">
            </div>
            
            <div>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" checked class="w-5 h-5 text-purple-600 rounded">
                    <span class="ml-2">เปิดใช้งาน</span>
                </label>
            </div>
            
            <div class="pt-4 border-t flex gap-2">
                <button type="button" onclick="closeRewardModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function openRewardModal() {
    document.getElementById('rewardModalTitle').textContent = 'เพิ่มรางวัล';
    document.getElementById('rewardForm').reset();
    document.querySelector('#rewardForm [name="reward_action"]').value = 'create';
    document.querySelector('#rewardForm [name="id"]').value = '';
    document.querySelector('#rewardForm [name="is_active"]').checked = true;
    document.getElementById('rewardModal').classList.remove('hidden');
    document.getElementById('rewardModal').classList.add('flex');
}

function closeRewardModal() {
    document.getElementById('rewardModal').classList.add('hidden');
    document.getElementById('rewardModal').classList.remove('flex');
}

function editReward(reward) {
    document.getElementById('rewardModalTitle').textContent = 'แก้ไขรางวัล';
    document.querySelector('#rewardForm [name="reward_action"]').value = 'update';
    document.querySelector('#rewardForm [name="id"]').value = reward.id;
    document.querySelector('#rewardForm [name="name"]').value = reward.name || '';
    document.querySelector('#rewardForm [name="description"]').value = reward.description || '';
    document.querySelector('#rewardForm [name="reward_type"]').value = reward.reward_type || 'gift';
    document.querySelector('#rewardForm [name="points_required"]').value = reward.points_required || 0;
    document.querySelector('#rewardForm [name="reward_value"]').value = reward.reward_value || '';
    document.querySelector('#rewardForm [name="stock"]').value = reward.stock ?? -1;
    document.querySelector('#rewardForm [name="max_per_user"]').value = reward.max_per_user || 0;
    document.querySelector('#rewardForm [name="image_url"]').value = reward.image_url || '';
    document.querySelector('#rewardForm [name="is_active"]').checked = reward.is_active == 1;
    document.querySelector('#rewardForm [name="valid_from"]').value = reward.start_date || '';
    document.querySelector('#rewardForm [name="valid_until"]').value = reward.end_date || '';
    document.getElementById('rewardModal').classList.remove('hidden');
    document.getElementById('rewardModal').classList.add('flex');
}

async function deleteReward(id, name) {
    const result = await Swal.fire({ title: 'ยืนยันการลบ', html: `ต้องการลบรางวัล <b>${name}</b>?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก' });
    if (!result.isConfirmed) return;
    const formData = new FormData();
    formData.append('reward_action', 'delete');
    formData.append('id', id);
    try {
        const res = await fetch('membership.php?tab=rewards', { method: 'POST', body: formData });
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }
        const data = await res.json();
        if (data.success) { Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false }); setTimeout(() => location.reload(), 1500); }
        else { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message }); }
    } catch (error) {
        console.error('Delete reward error:', error);
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถลบรางวัลได้' });
    }
}

async function toggleReward(id) {
    const formData = new FormData();
    formData.append('reward_action', 'toggle');
    formData.append('id', id);
    try {
        const res = await fetch('membership.php?tab=rewards', { method: 'POST', body: formData });
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }
        const data = await res.json();
        if (data.success) { location.reload(); }
    } catch (error) {
        console.error('Toggle reward error:', error);
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถเปลี่ยนสถานะได้' });
    }
}

async function approveRedemption(id) {
    const result = await Swal.fire({ title: 'อนุมัติการแลกรางวัล?', text: 'ระบบจะส่งแจ้งเตือนไปยังผู้ใช้', icon: 'question', showCancelButton: true, confirmButtonColor: '#10B981', confirmButtonText: 'อนุมัติ', cancelButtonText: 'ยกเลิก' });
    if (!result.isConfirmed) return;
    const formData = new FormData();
    formData.append('reward_action', 'approve_redemption');
    formData.append('redemption_id', id);
    try {
        const res = await fetch('membership.php?tab=rewards', { method: 'POST', body: formData });
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }
        const data = await res.json();
        if (data.success) { Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false }); setTimeout(() => location.reload(), 1500); }
        else { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message }); }
    } catch (error) {
        console.error('Approve redemption error:', error);
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถอนุมัติได้' });
    }
}

async function deliverRedemption(id) {
    const result = await Swal.fire({ title: 'ยืนยันการส่งมอบ?', text: 'บันทึกว่าได้ส่งมอบรางวัลแล้ว', icon: 'question', showCancelButton: true, confirmButtonColor: '#3B82F6', confirmButtonText: 'ยืนยัน', cancelButtonText: 'ยกเลิก' });
    if (!result.isConfirmed) return;
    const formData = new FormData();
    formData.append('reward_action', 'deliver_redemption');
    formData.append('redemption_id', id);
    try {
        const res = await fetch('membership.php?tab=rewards', { method: 'POST', body: formData });
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }
        const data = await res.json();
        if (data.success) { Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false }); setTimeout(() => location.reload(), 1500); }
        else { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message }); }
    } catch (error) {
        console.error('Deliver redemption error:', error);
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถบันทึกการส่งมอบได้' });
    }
}

async function cancelRedemption(id) {
    const result = await Swal.fire({ title: 'ยกเลิกการแลกรางวัล?', text: 'แต้มจะถูกคืนให้ผู้ใช้', icon: 'warning', showCancelButton: true, confirmButtonColor: '#EF4444', confirmButtonText: 'ยกเลิกการแลก', cancelButtonText: 'ไม่ใช่' });
    if (!result.isConfirmed) return;
    const formData = new FormData();
    formData.append('reward_action', 'cancel_redemption');
    formData.append('redemption_id', id);
    try {
        const res = await fetch('membership.php?tab=rewards', { method: 'POST', body: formData });
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }
        const data = await res.json();
        if (data.success) { Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false }); setTimeout(() => location.reload(), 1500); }
        else { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message }); }
    } catch (error) {
        console.error('Cancel redemption error:', error);
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถยกเลิกได้' });
    }
}

function exportCSV() {
    const startDate = document.getElementById('exportStartDate').value;
    const endDate = document.getElementById('exportEndDate').value;
    const status = new URLSearchParams(window.location.search).get('status') || '';
    window.location.href = `membership.php?tab=rewards&export=csv&start_date=${startDate}&end_date=${endDate}&status=${status}`;
}

document.getElementById('rewardForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const res = await fetch('membership.php?tab=rewards', { method: 'POST', body: formData });
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response');
        }
        const data = await res.json();
        if (data.success) { closeRewardModal(); Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false }); setTimeout(() => location.reload(), 1500); }
        else { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message }); }
    } catch (error) {
        console.error('Form submit error:', error);
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'ไม่สามารถบันทึกข้อมูลได้' });
    }
});

document.getElementById('rewardModal').addEventListener('click', function(e) { if (e.target === this) closeRewardModal(); });
</script>
