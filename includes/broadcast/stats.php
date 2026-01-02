<?php
/**
 * Broadcast Stats Tab - สถิติ Broadcast
 * 
 * @package FileConsolidation
 */

$campaignId = (int)($_GET['id'] ?? 0);

// ดึงข้อมูล campaign
$campaign = null;
$items = [];
$clicks = [];

if ($campaignId) {
    $stmt = $db->prepare("SELECT * FROM broadcast_campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($campaign) {
        // ดึง items พร้อม click count
        $stmt = $db->prepare("SELECT * FROM broadcast_items WHERE broadcast_id = ? ORDER BY click_count DESC");
        $stmt->execute([$campaignId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ดึง clicks พร้อมข้อมูล user
        try {
            $stmt = $db->prepare("
                SELECT bc.*, u.display_name, u.picture_url, bi.item_name 
                FROM broadcast_clicks bc 
                JOIN users u ON bc.user_id = u.id 
                JOIN broadcast_items bi ON bc.item_id = bi.id 
                WHERE bc.broadcast_id = ? 
                ORDER BY bc.clicked_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$campaignId]);
            $clicks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

// Get all campaigns for selection
$allCampaigns = [];
try {
    $stmt = $db->prepare("SELECT id, name, status, created_at FROM broadcast_campaigns WHERE (line_account_id = ? OR line_account_id IS NULL) ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$currentBotId]);
    $allCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get overall stats
$overallStats = [
    'total_campaigns' => 0,
    'sent_campaigns' => 0,
    'total_clicks' => 0
];

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM broadcast_campaigns WHERE line_account_id = ? OR line_account_id IS NULL");
    $stmt->execute([$currentBotId]);
    $overallStats['total_campaigns'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM broadcast_campaigns WHERE (line_account_id = ? OR line_account_id IS NULL) AND status = 'sent'");
    $stmt->execute([$currentBotId]);
    $overallStats['sent_campaigns'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $db->query("SELECT COUNT(*) FROM broadcast_clicks");
    $overallStats['total_clicks'] = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}
?>

<?php if (!$campaignId): ?>
<!-- Campaign Selection -->
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h3 class="font-semibold mb-4"><i class="fas fa-chart-bar text-blue-500 mr-2"></i>เลือก Campaign เพื่อดูสถิติ</h3>
    
    <!-- Overall Stats -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-blue-50 rounded-lg p-4 text-center">
            <p class="text-3xl font-bold text-blue-600"><?= number_format($overallStats['total_campaigns']) ?></p>
            <p class="text-gray-500 text-sm">Campaigns ทั้งหมด</p>
        </div>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <p class="text-3xl font-bold text-green-600"><?= number_format($overallStats['sent_campaigns']) ?></p>
            <p class="text-gray-500 text-sm">ส่งแล้ว</p>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <p class="text-3xl font-bold text-purple-600"><?= number_format($overallStats['total_clicks']) ?></p>
            <p class="text-gray-500 text-sm">Total Clicks</p>
        </div>
    </div>
    
    <?php if (empty($allCampaigns)): ?>
    <div class="text-center text-gray-400 py-8">
        <i class="fas fa-chart-pie text-4xl mb-3"></i>
        <p>ยังไม่มี Campaign</p>
        <a href="broadcast.php?tab=products" class="text-green-500 hover:underline">สร้าง Broadcast ใหม่</a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($allCampaigns as $c): ?>
        <a href="broadcast.php?tab=stats&id=<?= $c['id'] ?>" class="block p-4 border rounded-lg hover:bg-gray-50 transition">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-medium truncate"><?= htmlspecialchars($c['name']) ?></h4>
                <span class="px-2 py-1 text-xs rounded-full <?= $c['status'] === 'sent' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' ?>">
                    <?= $c['status'] === 'sent' ? 'ส่งแล้ว' : 'รอส่ง' ?>
                </span>
            </div>
            <p class="text-xs text-gray-500"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif (!$campaign): ?>
<div class="bg-white rounded-xl shadow p-8 text-center">
    <i class="fas fa-exclamation-circle text-4xl text-gray-300 mb-4"></i>
    <p class="text-gray-500">ไม่พบ Broadcast</p>
    <a href="broadcast.php?tab=stats" class="mt-4 inline-block text-green-500 hover:underline">กลับไปเลือก Campaign</a>
</div>

<?php else: ?>
<!-- Campaign Stats -->
<div class="mb-6">
    <a href="broadcast.php?tab=stats" class="text-green-500 hover:underline mb-2 inline-block">
        <i class="fas fa-arrow-left mr-1"></i>กลับ
    </a>
    <h2 class="text-2xl font-bold"><?= htmlspecialchars($campaign['name']) ?></h2>
    <p class="text-gray-600">สถิติการคลิกและ Tags ที่ติด</p>
</div>

<!-- Stats Overview -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <p class="text-3xl font-bold text-blue-600"><?= number_format($campaign['total_sent'] ?? 0) ?></p>
        <p class="text-gray-500 text-sm">ส่งแล้ว</p>
    </div>
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <?php $totalClicks = array_sum(array_column($items, 'click_count')); ?>
        <p class="text-3xl font-bold text-green-600"><?= number_format($totalClicks) ?></p>
        <p class="text-gray-500 text-sm">คลิกทั้งหมด</p>
    </div>
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <p class="text-3xl font-bold text-purple-600"><?= count($items) ?></p>
        <p class="text-gray-500 text-sm">สินค้า</p>
    </div>
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <?php 
        $sent = $campaign['total_sent'] ?? 0;
        $ctr = $sent > 0 ? ($totalClicks / $sent * 100) : 0;
        ?>
        <p class="text-3xl font-bold text-orange-600"><?= number_format($ctr, 1) ?>%</p>
        <p class="text-gray-500 text-sm">CTR</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Items Performance -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h3 class="font-semibold">📦 สินค้าที่มีคนสนใจ</h3>
        </div>
        <div class="p-4">
            <?php if (empty($items)): ?>
            <p class="text-gray-400 text-center py-4">ไม่มีข้อมูล</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php 
                $maxClicks = max(array_column($items, 'click_count')) ?: 1;
                foreach ($items as $item): 
                $percentage = ($item['click_count'] / $maxClicks) * 100;
                ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center">
                            <img src="<?= $item['item_image'] ?: 'https://via.placeholder.com/30' ?>" class="w-8 h-8 rounded object-cover mr-2">
                            <span class="text-sm"><?= htmlspecialchars($item['item_name']) ?></span>
                        </div>
                        <span class="font-medium"><?= number_format($item['click_count']) ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Clicks -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h3 class="font-semibold">👆 การคลิกล่าสุด</h3>
        </div>
        <div class="p-4 max-h-80 overflow-y-auto">
            <?php if (empty($clicks)): ?>
            <p class="text-gray-400 text-center py-4">ยังไม่มีการคลิก</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($clicks as $click): ?>
                <div class="flex items-center">
                    <img src="<?= $click['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full object-cover">
                    <div class="ml-3 flex-1">
                        <p class="text-sm font-medium"><?= htmlspecialchars($click['display_name']) ?></p>
                        <p class="text-xs text-gray-500">สนใจ: <?= htmlspecialchars($click['item_name']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400"><?= date('d/m H:i', strtotime($click['clicked_at'])) ?></p>
                        <?php if (!empty($click['tag_assigned'])): ?>
                        <span class="text-xs text-green-600"><i class="fas fa-tag"></i> Tagged</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
