<?php
/**
 * CRM Dashboard Tab Content
 * ศูนย์กลางจัดการลูกค้า
 */

require_once __DIR__ . '/../../classes/AutoTagManager.php';

$autoTagManager = new AutoTagManager($db, $currentBotId);

// รัน migration ถ้ายังไม่มีตาราง
try {
    $db->query("SELECT 1 FROM auto_tag_rules LIMIT 1");
} catch (Exception $e) {
    $migrationFile = __DIR__ . '/../../database/migration_auto_tags.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        $db->exec($sql);
    }
}

// สถิติ
$crmStats = [];

// จำนวนลูกค้าทั้งหมด
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR ? IS NULL) AND is_blocked = 0");
$stmt->execute([$currentBotId, $currentBotId]);
$crmStats['total_customers'] = $stmt->fetchColumn();

// ลูกค้าใหม่วันนี้
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR ? IS NULL) AND DATE(created_at) = CURDATE()");
$stmt->execute([$currentBotId, $currentBotId]);
$crmStats['new_today'] = $stmt->fetchColumn();

// ลูกค้าใหม่ 7 วัน
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR ? IS NULL) AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute([$currentBotId, $currentBotId]);
$crmStats['new_7days'] = $stmt->fetchColumn();

// จำนวน Tags
$stmt = $db->prepare("SELECT COUNT(*) FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL");
$stmt->execute([$currentBotId]);
$crmStats['total_tags'] = $stmt->fetchColumn();

// จำนวน Auto Rules
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM auto_tag_rules WHERE line_account_id = ? OR line_account_id IS NULL");
    $stmt->execute([$currentBotId]);
    $crmStats['auto_rules'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $crmStats['auto_rules'] = 0;
}

// Tags พร้อมจำนวนลูกค้า
$stmt = $db->prepare("
    SELECT t.*, COUNT(a.user_id) as customer_count 
    FROM user_tags t 
    LEFT JOIN user_tag_assignments a ON t.id = a.tag_id 
    WHERE t.line_account_id = ? OR t.line_account_id IS NULL 
    GROUP BY t.id 
    ORDER BY customer_count DESC
");
$stmt->execute([$currentBotId]);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ลูกค้าล่าสุด
$stmt = $db->prepare("
    SELECT u.*, 
    (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM user_tags t JOIN user_tag_assignments a ON t.id = a.tag_id WHERE a.user_id = u.id) as tags
    FROM users u 
    WHERE (u.line_account_id = ? OR ? IS NULL) AND u.is_blocked = 0
    ORDER BY u.created_at DESC 
    LIMIT 10
");
$stmt->execute([$currentBotId, $currentBotId]);
$recentCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto Tag Rules
$autoRules = $autoTagManager->getRules();
?>

<div class="space-y-6">
    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 text-xl">
                    <i class="fas fa-users"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">ลูกค้าทั้งหมด</p>
                    <p class="text-2xl font-bold"><?php echo number_format($crmStats['total_customers']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center text-green-600 text-xl">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">ใหม่วันนี้</p>
                    <p class="text-2xl font-bold"><?php echo number_format($crmStats['new_today']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center text-purple-600 text-xl">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">Tags</p>
                    <p class="text-2xl font-bold"><?php echo number_format($crmStats['total_tags']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center text-orange-600 text-xl">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">Auto Rules</p>
                    <p class="text-2xl font-bold"><?php echo number_format($crmStats['auto_rules']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Tags Overview -->
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold">🏷️ Tags</h3>
                <a href="user-tags.php" class="text-sm text-green-600 hover:underline">จัดการ Tags</a>
            </div>
            <div class="p-4 max-h-80 overflow-y-auto">
                <?php if (empty($tags)): ?>
                <p class="text-gray-400 text-center py-4">ยังไม่มี Tags</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($tags as $tag): ?>
                    <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <span class="w-3 h-3 rounded-full mr-2" style="background-color: <?php echo htmlspecialchars($tag['color'] ?? '#3B82F6'); ?>"></span>
                            <span class="text-sm"><?php echo htmlspecialchars($tag['name']); ?></span>
                            <?php if (isset($tag['tag_type']) && $tag['tag_type'] === 'auto'): ?>
                            <span class="ml-2 px-1.5 py-0.5 bg-orange-100 text-orange-600 text-xs rounded">Auto</span>
                            <?php elseif (isset($tag['tag_type']) && $tag['tag_type'] === 'system'): ?>
                            <span class="ml-2 px-1.5 py-0.5 bg-blue-100 text-blue-600 text-xs rounded">System</span>
                            <?php endif; ?>
                        </div>
                        <span class="text-sm font-medium"><?php echo number_format($tag['customer_count']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Auto Tag Rules -->
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold">🤖 Auto Tag Rules</h3>
                <a href="auto-tag-rules.php" class="text-sm text-green-600 hover:underline">จัดการ Rules</a>
            </div>
            <div class="p-4 max-h-80 overflow-y-auto">
                <?php if (empty($autoRules)): ?>
                <p class="text-gray-400 text-center py-4">ยังไม่มี Auto Rules</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($autoRules as $rule): ?>
                    <div class="p-2 bg-gray-50 rounded-lg">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium"><?php echo htmlspecialchars($rule['rule_name']); ?></span>
                            <span class="px-2 py-0.5 text-xs rounded <?php echo $rule['is_active'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $rule['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="flex items-center mt-1 text-xs text-gray-500">
                            <span class="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded mr-2"><?php echo $rule['trigger_type']; ?></span>
                            <span>→</span>
                            <span class="ml-2 px-1.5 py-0.5 rounded" style="background-color: <?php echo $rule['tag_color'] ?? '#3B82F6'; ?>20; color: <?php echo $rule['tag_color'] ?? '#3B82F6'; ?>">
                                <?php echo htmlspecialchars($rule['tag_name']); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Customers -->
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold">👥 ลูกค้าล่าสุด</h3>
                <a href="users.php" class="text-sm text-green-600 hover:underline">ดูทั้งหมด</a>
            </div>
            <div class="p-4 max-h-80 overflow-y-auto">
                <?php if (empty($recentCustomers)): ?>
                <p class="text-gray-400 text-center py-4">ยังไม่มีลูกค้า</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentCustomers as $customer): ?>
                    <div class="flex items-center">
                        <img src="<?php echo $customer['picture_url'] ?: 'https://via.placeholder.com/40'; ?>" class="w-10 h-10 rounded-full object-cover">
                        <div class="ml-3 flex-1 min-w-0">
                            <p class="text-sm font-medium truncate"><?php echo htmlspecialchars($customer['display_name'] ?? 'Unknown'); ?></p>
                            <div class="flex flex-wrap gap-1">
                                <?php if ($customer['tags']): ?>
                                    <?php foreach (explode(', ', $customer['tags']) as $tagName): ?>
                                    <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs"><?php echo htmlspecialchars($tagName); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="user-detail.php?id=<?php echo $customer['id']; ?>" class="text-green-500 hover:text-green-700">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-semibold mb-4">⚡ Quick Actions</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a href="users.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-users text-2xl text-blue-500 mb-2"></i>
                <span class="text-sm">ดูลูกค้าทั้งหมด</span>
            </a>
            <a href="user-tags.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-tags text-2xl text-purple-500 mb-2"></i>
                <span class="text-sm">จัดการ Tags</span>
            </a>
            <a href="auto-tag-rules.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-robot text-2xl text-orange-500 mb-2"></i>
                <span class="text-sm">Auto Tag Rules</span>
            </a>
            <a href="customer-segments.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-layer-group text-2xl text-green-500 mb-2"></i>
                <span class="text-sm">Segments</span>
            </a>
            <a href="drip-campaigns.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-paper-plane text-2xl text-pink-500 mb-2"></i>
                <span class="text-sm">Drip Campaigns</span>
            </a>
            <a href="broadcast.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-bullhorn text-2xl text-red-500 mb-2"></i>
                <span class="text-sm">Broadcast</span>
            </a>
            <a href="analytics.php?tab=crm" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-chart-pie text-2xl text-indigo-500 mb-2"></i>
                <span class="text-sm">Analytics</span>
            </a>
            <a href="link-tracking.php" class="flex flex-col items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <i class="fas fa-link text-2xl text-cyan-500 mb-2"></i>
                <span class="text-sm">Link Tracking</span>
            </a>
        </div>
    </div>
</div>
