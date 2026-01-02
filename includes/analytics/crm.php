<?php
/**
 * CRM Analytics Tab Content
 * วิเคราะห์ข้อมูลลูกค้า
 * 
 * Variables expected from parent:
 * - $db: Database connection
 * - $lineAccountId: Current bot ID (as $currentBotId)
 */

require_once __DIR__ . '/../../classes/AdvancedCRM.php';

$currentBotId = $lineAccountId ?? ($_SESSION['current_bot_id'] ?? null);
$crm = new AdvancedCRM($db, $currentBotId);
$days = (int)($_GET['days'] ?? 30);

$analytics = $crm->getUserAnalytics($days);

// Get total users
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_blocked = 0");
$stmt->execute([$currentBotId]);
$totalUsers = $stmt->fetchColumn();

// Get segments
$segments = $crm->getSegments();
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h3 class="text-xl font-bold">📊 CRM Analytics</h3>
        <p class="text-gray-600">วิเคราะห์ข้อมูลลูกค้า</p>
    </div>
    <div class="flex gap-2">
        <a href="?tab=crm&days=7" class="px-4 py-2 rounded-lg <?= $days == 7 ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">7 วัน</a>
        <a href="?tab=crm&days=30" class="px-4 py-2 rounded-lg <?= $days == 30 ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">30 วัน</a>
        <a href="?tab=crm&days=90" class="px-4 py-2 rounded-lg <?= $days == 90 ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">90 วัน</a>
    </div>
</div>

<!-- Main Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow p-5 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-blue-100 text-sm">Total Users</p>
                <p class="text-3xl font-bold"><?= number_format($totalUsers) ?></p>
            </div>
            <i class="fas fa-users text-4xl text-blue-300"></i>
        </div>
    </div>
    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow p-5 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-green-100 text-sm">Active Users (<?= $days ?>d)</p>
                <p class="text-3xl font-bold"><?= number_format($analytics['active_users'] ?? 0) ?></p>
            </div>
            <i class="fas fa-user-check text-4xl text-green-300"></i>
        </div>
    </div>
    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow p-5 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-purple-100 text-sm">New Users (<?= $days ?>d)</p>
                <p class="text-3xl font-bold"><?= number_format($analytics['new_users'] ?? 0) ?></p>
            </div>
            <i class="fas fa-user-plus text-4xl text-purple-300"></i>
        </div>
    </div>
    <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl shadow p-5 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-orange-100 text-sm">Segments</p>
                <p class="text-3xl font-bold"><?= count($segments) ?></p>
            </div>
            <i class="fas fa-layer-group text-4xl text-orange-300"></i>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Top Tags -->
    <div class="bg-white rounded-xl shadow p-5">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold">🏷️ Top Tags</h3>
            <a href="user-tags.php" class="text-sm text-blue-600 hover:underline">จัดการ →</a>
        </div>
        <?php if (!empty($analytics['top_tags'])): ?>
        <div class="space-y-2">
            <?php foreach ($analytics['top_tags'] as $tag): ?>
            <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 rounded-full" style="background-color: <?= $tag['color'] ?>"></div>
                    <span class="text-sm"><?= htmlspecialchars($tag['name']) ?></span>
                </div>
                <span class="text-sm font-medium"><?= number_format($tag['count']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-gray-500 text-center py-4">ยังไม่มี Tags</p>
        <?php endif; ?>
    </div>
    
    <!-- Segments -->
    <div class="bg-white rounded-xl shadow p-5">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold">🎯 Customer Segments</h3>
            <a href="customer-segments.php" class="text-sm text-blue-600 hover:underline">จัดการ →</a>
        </div>
        <?php if (!empty($segments)): ?>
        <div class="space-y-2">
            <?php foreach (array_slice($segments, 0, 5) as $segment): ?>
            <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                <div>
                    <p class="font-medium text-sm"><?= htmlspecialchars($segment['name']) ?></p>
                    <p class="text-xs text-gray-500"><?= $segment['segment_type'] ?></p>
                </div>
                <span class="text-lg font-bold text-blue-600"><?= number_format($segment['user_count']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-gray-500 text-center py-4">ยังไม่มี Segments</p>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="mt-6 bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-xl p-5">
    <h4 class="font-semibold text-green-800 mb-3">🚀 Quick Actions</h4>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="customer-segments.php" class="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <i class="fas fa-layer-group text-2xl text-blue-500 mb-2"></i>
            <p class="text-sm font-medium">สร้าง Segment</p>
        </a>
        <a href="link-tracking.php" class="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <i class="fas fa-link text-2xl text-green-500 mb-2"></i>
            <p class="text-sm font-medium">สร้าง Tracked Link</p>
        </a>
        <a href="user-tags.php" class="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <i class="fas fa-tags text-2xl text-purple-500 mb-2"></i>
            <p class="text-sm font-medium">จัดการ Tags</p>
        </a>
        <a href="broadcast.php" class="p-3 bg-white rounded-lg hover:shadow-md transition text-center">
            <i class="fas fa-paper-plane text-2xl text-orange-500 mb-2"></i>
            <p class="text-sm font-medium">ส่ง Broadcast</p>
        </a>
    </div>
</div>
