<?php
/**
 * Broadcast Stats - สถิติ Broadcast
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Broadcast Stats';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

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

require_once 'includes/header.php';
?>

<?php if (!$campaign): ?>
<div class="bg-white rounded-xl shadow p-8 text-center">
    <i class="fas fa-exclamation-circle text-4xl text-gray-300 mb-4"></i>
    <p class="text-gray-500">ไม่พบ Broadcast</p>
    <a href="broadcast-products.php" class="mt-4 inline-block text-green-500 hover:underline">กลับไปหน้า Broadcast</a>
</div>
<?php else: ?>

<div class="mb-6">
    <a href="broadcast-products.php" class="text-green-500 hover:underline mb-2 inline-block">
        <i class="fas fa-arrow-left mr-1"></i>กลับ
    </a>
    <h2 class="text-2xl font-bold"><?php echo htmlspecialchars($campaign['name']); ?></h2>
    <p class="text-gray-600">สถิติการคลิกและ Tags ที่ติด</p>
</div>

<!-- Stats Overview -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <p class="text-3xl font-bold text-blue-600"><?php echo number_format($campaign['sent_count']); ?></p>
        <p class="text-gray-500 text-sm">ส่งแล้ว</p>
    </div>
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <p class="text-3xl font-bold text-green-600"><?php echo number_format($campaign['click_count']); ?></p>
        <p class="text-gray-500 text-sm">คลิกทั้งหมด</p>
    </div>
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <p class="text-3xl font-bold text-purple-600"><?php echo count($items); ?></p>
        <p class="text-gray-500 text-sm">สินค้า</p>
    </div>
    <div class="bg-white rounded-xl shadow p-4 text-center">
        <?php 
        $ctr = $campaign['sent_count'] > 0 ? ($campaign['click_count'] / $campaign['sent_count'] * 100) : 0;
        ?>
        <p class="text-3xl font-bold text-orange-600"><?php echo number_format($ctr, 1); ?>%</p>
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
                            <img src="<?php echo $item['item_image'] ?: 'https://via.placeholder.com/30'; ?>" class="w-8 h-8 rounded object-cover mr-2">
                            <span class="text-sm"><?php echo htmlspecialchars($item['item_name']); ?></span>
                        </div>
                        <span class="font-medium"><?php echo number_format($item['click_count']); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
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
                    <img src="<?php echo $click['picture_url'] ?: 'https://via.placeholder.com/40'; ?>" class="w-10 h-10 rounded-full object-cover">
                    <div class="ml-3 flex-1">
                        <p class="text-sm font-medium"><?php echo htmlspecialchars($click['display_name']); ?></p>
                        <p class="text-xs text-gray-500">สนใจ: <?php echo htmlspecialchars($click['item_name']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400"><?php echo date('d/m H:i', strtotime($click['clicked_at'])); ?></p>
                        <?php if ($click['tag_assigned']): ?>
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

<?php require_once 'includes/footer.php'; ?>
