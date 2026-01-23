<?php
/**
 * Executive Dashboard Tab Content
 * ภาพรวมการทำงาน, ปัญหาที่พบ, ผลงาน Admin
 */

// Date filter
$dateFilter = $_GET['date'] ?? date('Y-m-d');
$dateStart = $dateFilter . ' 00:00:00';
$dateEnd = $dateFilter . ' 23:59:59';

// ==================== STATS ====================

// 1. ข้อความวันนี้
$msgStats = ['total' => 0, 'incoming' => 0, 'outgoing' => 0, 'unread' => 0];
try {
    $stmt = $db->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
        SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
        SUM(CASE WHEN direction = 'incoming' AND is_read = 0 THEN 1 ELSE 0 END) as unread
        FROM messages WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$dateStart, $dateEnd]);
    $msgStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $msgStats;
} catch (Exception $e) {
}

// 2. ลูกค้าที่ติดต่อมาวันนี้
$customersToday = 0;
$newCustomers = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM messages WHERE direction = 'incoming' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$dateStart, $dateEnd]);
    $customersToday = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$dateStart, $dateEnd]);
    $newCustomers = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
}

// 3. ออเดอร์วันนี้
$orderStats = ['total' => 0, 'pending' => 0, 'completed' => 0, 'revenue' => 0];
try {
    $ordersTable = 'transactions';
    try {
        $db->query("SELECT 1 FROM transactions LIMIT 1");
    } catch (Exception $e) {
        $ordersTable = 'orders';
    }

    $stmt = $db->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status IN ('completed', 'delivered') THEN 1 ELSE 0 END) as completed,
        COALESCE(SUM(CASE WHEN status IN ('completed', 'delivered', 'paid') THEN grand_total ELSE 0 END), 0) as revenue
        FROM {$ordersTable} WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$dateStart, $dateEnd]);
    $orderStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $orderStats;
} catch (Exception $e) {
}

// 4. เวลาตอบกลับเฉลี่ย
$avgResponseTime = 0;
try {
    $stmt = $db->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at)) as avg_time
        FROM messages m1
        JOIN messages m2 ON m1.user_id = m2.user_id 
            AND m2.direction = 'outgoing' 
            AND m2.created_at > m1.created_at
            AND m2.created_at < DATE_ADD(m1.created_at, INTERVAL 1 HOUR)
        WHERE m1.direction = 'incoming' 
            AND m1.created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$dateStart, $dateEnd]);
    $avgResponseTime = round($stmt->fetchColumn() ?: 0);
} catch (Exception $e) {
}

// 5. วิดีโอคอลวันนี้ (New)
$videoStats = ['total' => 0, 'completed' => 0, 'avg_duration' => 0];
try {
    $stmt = $db->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        AVG(CASE WHEN status = 'completed' THEN duration ELSE NULL END) as avg_duration
        FROM video_calls WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$dateStart, $dateEnd]);
    $videoStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $videoStats;
} catch (Exception $e) {
}

// ==================== PROBLEM DETECTION ====================
$problemKeywords = ['ปัญหา', 'ไม่พอใจ', 'ช้า', 'แย่', 'ผิด', 'เสีย', 'ไม่ได้', 'รอนาน', 'ไม่ตอบ', 'complaint', 'problem'];
$problemMessages = [];
try {
    $keywordConditions = array_map(fn($k) => "m.content LIKE ?", $problemKeywords);
    $keywordParams = array_map(fn($k) => "%{$k}%", $problemKeywords);

    $sql = "SELECT m.*, u.display_name, u.picture_url 
            FROM messages m 
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.direction = 'incoming' 
            AND m.created_at BETWEEN ? AND ?
            AND (" . implode(' OR ', $keywordConditions) . ")
            ORDER BY m.created_at DESC LIMIT 20";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$dateStart, $dateEnd], $keywordParams));
    $problemMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// ==================== ADMIN PERFORMANCE ====================
$adminPerformance = [];
try {
    $hasSentBy = false;
    try {
        $db->query("SELECT sent_by FROM messages LIMIT 1");
        $hasSentBy = true;
    } catch (Exception $e) {
    }

    if ($hasSentBy) {
        $stmt = $db->prepare("
            SELECT 
                COALESCE(m.sent_by, 'System/Bot') as admin_name,
                COUNT(*) as messages_sent,
                COUNT(DISTINCT m.user_id) as customers_handled
            FROM messages m
            WHERE m.direction = 'outgoing' 
            AND m.created_at BETWEEN ? AND ?
            GROUP BY m.sent_by
            ORDER BY messages_sent DESC
        ");
        $stmt->execute([$dateStart, $dateEnd]);
        $adminPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
}

// ==================== RECENT CONVERSATIONS ====================
$recentConversations = [];
try {
    $stmt = $db->prepare("
        SELECT u.id, u.display_name, u.picture_url, u.line_user_id,
               COUNT(m.id) as message_count,
               MAX(m.created_at) as last_message_at,
               (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message
        FROM users u
        JOIN messages m ON u.id = m.user_id
        WHERE m.created_at BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY last_message_at DESC
        LIMIT 15
    ");
    $stmt->execute([$dateStart, $dateEnd]);
    $recentConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// ==================== HOURLY ACTIVITY ====================
$hourlyActivity = array_fill(0, 24, 0);
try {
    $stmt = $db->prepare("
        SELECT HOUR(created_at) as hour, COUNT(*) as count
        FROM messages
        WHERE created_at BETWEEN ? AND ?
        GROUP BY HOUR(created_at)
    ");
    $stmt->execute([$dateStart, $dateEnd]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hourlyActivity[$row['hour']] = $row['count'];
    }
} catch (Exception $e) {
}

// ==================== TOP ISSUES ====================
$topIssues = [];
try {
    $stmt = $db->prepare("SELECT content FROM messages WHERE direction = 'incoming' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$dateStart, $dateEnd]);
    $messages = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $issueKeywords = [
        'สินค้า' => 0,
        'ราคา' => 0,
        'จัดส่ง' => 0,
        'ชำระเงิน' => 0,
        'คืนสินค้า' => 0,
        'สอบถาม' => 0,
        'แนะนำ' => 0,
        'ปัญหา' => 0
    ];

    foreach ($messages as $msg) {
        foreach ($issueKeywords as $keyword => &$count) {
            if (strpos($msg, $keyword) !== false)
                $count++;
        }
    }

    arsort($issueKeywords);
    $topIssues = array_slice($issueKeywords, 0, 5, true);
} catch (Exception $e) {
}
?>

<div class="space-y-6">
    <!-- Date Filter -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-gray-500">ภาพรวมการทำงานและวิเคราะห์ประจำวัน</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="date" id="dateFilter" value="<?= $dateFilter ?>"
                class="px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                onchange="window.location='?tab=executive&date='+this.value">
            <button onclick="window.print()" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-print mr-2"></i>พิมพ์
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-comments text-blue-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">ข้อความวันนี้</p>
                    <p class="text-2xl font-bold"><?= number_format($msgStats['total'] ?? 0) ?></p>
                    <p class="text-xs text-gray-400">รับ <?= number_format($msgStats['incoming'] ?? 0) ?> / ส่ง
                        <?= number_format($msgStats['outgoing'] ?? 0) ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-users text-green-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">ลูกค้าติดต่อ</p>
                    <p class="text-2xl font-bold"><?= number_format($customersToday) ?></p>
                    <p class="text-xs text-green-500">+<?= $newCustomers ?> ใหม่</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-shopping-cart text-orange-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">ออเดอร์</p>
                    <p class="text-2xl font-bold"><?= number_format($orderStats['total'] ?? 0) ?></p>
                    <p class="text-xs text-orange-500"><?= $orderStats['pending'] ?? 0 ?> รอดำเนินการ</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-baht-sign text-purple-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">รายได้</p>
                    <p class="text-2xl font-bold">฿<?= number_format($orderStats['revenue'] ?? 0) ?></p>
                    <p class="text-xs text-gray-400"><?= $orderStats['completed'] ?? 0 ?> สำเร็จ</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-video text-red-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">วิดีโอคอล</p>
                    <p class="text-2xl font-bold"><?= number_format($videoStats['total'] ?? 0) ?></p>
                    <p class="text-xs text-gray-400">เฉลี่ย <?= round($videoStats['avg_duration'] / 60, 1) ?> นาที</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Second Row -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-cyan-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-cyan-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">เวลาตอบกลับเฉลี่ย</p>
                    <p class="text-2xl font-bold"><?= $avgResponseTime ?> <span class="text-sm font-normal">นาที</span>
                    </p>
                    <p
                        class="text-xs <?= $avgResponseTime <= 5 ? 'text-green-500' : ($avgResponseTime <= 15 ? 'text-yellow-500' : 'text-red-500') ?>">
                        <?= $avgResponseTime <= 5 ? '✅ ดีมาก' : ($avgResponseTime <= 15 ? '⚠️ พอใช้' : '❌ ต้องปรับปรุง') ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div
                    class="w-12 h-12 <?= ($msgStats['unread'] ?? 0) > 0 ? 'bg-red-100' : 'bg-green-100' ?> rounded-lg flex items-center justify-center">
                    <i
                        class="fas fa-envelope <?= ($msgStats['unread'] ?? 0) > 0 ? 'text-red-500' : 'text-green-500' ?> text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">ยังไม่ได้อ่าน</p>
                    <p class="text-2xl font-bold <?= ($msgStats['unread'] ?? 0) > 0 ? 'text-red-500' : '' ?>">
                        <?= number_format($msgStats['unread'] ?? 0) ?>
                    </p>
                    <p class="text-xs text-gray-400">ข้อความ</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center gap-3">
                <div
                    class="w-12 h-12 <?= count($problemMessages) > 0 ? 'bg-red-100' : 'bg-green-100' ?> rounded-lg flex items-center justify-center">
                    <i
                        class="fas fa-exclamation-triangle <?= count($problemMessages) > 0 ? 'text-red-500' : 'text-green-500' ?> text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">ปัญหา/ข้อร้องเรียน</p>
                    <p class="text-2xl font-bold <?= count($problemMessages) > 0 ? 'text-red-500' : '' ?>">
                        <?= count($problemMessages) ?>
                    </p>
                    <p class="text-xs text-gray-400">รายการ</p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Admin Performance -->
        <div class="bg-white rounded-xl shadow">
            <div class="px-4 py-3 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-700"><i class="fas fa-user-tie text-blue-500 mr-2"></i>ผลงาน Admin
                    วันนี้</h3>
            </div>
            <div class="p-4">
                <?php if (empty($adminPerformance)): ?>
                    <p class="text-gray-400 text-center py-4">ไม่มีข้อมูล</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($adminPerformance as $i => $admin): ?>
                            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                                <div
                                    class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold">
                                    <?= $i + 1 ?>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium"><?= htmlspecialchars($admin['admin_name'] ?: 'System/Bot') ?></p>
                                    <p class="text-xs text-gray-500">ดูแล <?= $admin['customers_handled'] ?> ลูกค้า</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-bold text-blue-600">
                                        <?= number_format($admin['messages_sent'] ?? 0) ?>
                                    </p>
                                    <p class="text-xs text-gray-400">ข้อความ</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Hourly Activity Chart -->
        <div class="bg-white rounded-xl shadow">
            <div class="px-4 py-3 border-b">
                <h3 class="font-semibold text-gray-700"><i
                        class="fas fa-chart-area text-green-500 mr-2"></i>กิจกรรมรายชั่วโมง</h3>
            </div>
            <div class="p-4">
                <canvas id="hourlyChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Problem Messages -->
        <div class="bg-white rounded-xl shadow">
            <div class="px-4 py-3 border-b flex items-center justify-between bg-red-50">
                <h3 class="font-semibold text-red-700"><i
                        class="fas fa-exclamation-circle mr-2"></i>ข้อความที่อาจเป็นปัญหา</h3>
                <span class="px-2 py-1 bg-red-100 text-red-600 text-xs rounded-full"><?= count($problemMessages) ?>
                    รายการ</span>
            </div>
            <div class="divide-y max-h-96 overflow-y-auto">
                <?php if (empty($problemMessages)): ?>
                    <div class="p-8 text-center text-gray-400">
                        <i class="fas fa-check-circle text-4xl text-green-300 mb-2"></i>
                        <p>ไม่พบข้อความที่เป็นปัญหา 🎉</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($problemMessages as $msg): ?>
                        <div class="p-3 hover:bg-red-50 cursor-pointer" onclick="viewChat(<?= $msg['user_id'] ?>)">
                            <div class="flex items-start gap-3">
                                <img src="<?= $msg['picture_url'] ?: 'https://via.placeholder.com/40' ?>"
                                    class="w-10 h-10 rounded-full">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-medium text-sm"><?= htmlspecialchars($msg['display_name'] ?: 'ลูกค้า') ?></span>
                                        <span
                                            class="text-xs text-gray-400"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                                    </div>
                                    <p class="text-sm text-gray-600 truncate"><?= htmlspecialchars($msg['content'] ?? '') ?></p>
                                </div>
                                <i class="fas fa-chevron-right text-gray-300"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Conversations -->
        <div class="bg-white rounded-xl shadow">
            <div class="px-4 py-3 border-b">
                <h3 class="font-semibold text-gray-700"><i
                        class="fas fa-history text-purple-500 mr-2"></i>การสนทนาล่าสุด</h3>
            </div>
            <div class="divide-y max-h-96 overflow-y-auto">
                <?php foreach ($recentConversations as $conv): ?>
                    <div class="p-3 hover:bg-gray-50 cursor-pointer" onclick="viewChat(<?= $conv['id'] ?>)">
                        <div class="flex items-center gap-3">
                            <img src="<?= $conv['picture_url'] ?: 'https://via.placeholder.com/40' ?>"
                                class="w-10 h-10 rounded-full">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="font-medium text-sm"><?= htmlspecialchars($conv['display_name'] ?: 'ลูกค้า') ?></span>
                                    <span
                                        class="px-1.5 py-0.5 bg-blue-100 text-blue-600 text-[10px] rounded"><?= $conv['message_count'] ?>
                                        ข้อความ</span>
                                </div>
                                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($conv['last_message']) ?></p>
                            </div>
                            <span
                                class="text-xs text-gray-400"><?= date('H:i', strtotime($conv['last_message_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Top Issues -->
    <div class="bg-white rounded-xl shadow">
        <div class="px-4 py-3 border-b">
            <h3 class="font-semibold text-gray-700"><i
                    class="fas fa-tags text-orange-500 mr-2"></i>หัวข้อที่ลูกค้าถามบ่อย</h3>
        </div>
        <div class="p-4">
            <div class="flex flex-wrap gap-3">
                <?php foreach ($topIssues as $issue => $count): ?>
                    <?php if ($count > 0): ?>
                        <div class="px-4 py-2 bg-orange-50 border border-orange-200 rounded-full">
                            <span class="font-medium text-orange-700"><?= $issue ?></span>
                            <span
                                class="ml-2 px-2 py-0.5 bg-orange-200 text-orange-800 text-xs rounded-full"><?= $count ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Hourly Activity Chart
    const hourlyData = <?= json_encode(array_values($hourlyActivity)) ?>;
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: Array.from({ length: 24 }, (_, i) => i + ':00'),
            datasets: [{
                label: 'ข้อความ',
                data: hourlyData,
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    function viewChat(userId) {
        window.location.href = 'chat.php?user=' + userId;
    }
</script>