<?php
/**
 * สถิติรวม - Analytics Dashboard
 * รวม: สถิติทั่วไป + วิเคราะห์ขั้นสูง + CRM Analytics
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สถิติรวม';
$lineAccountId = $_SESSION['current_bot_id'] ?? null;

// Date range filter
$period = $_GET['period'] ?? '30';
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime("-{$period} days"));
$endDate = $_GET['end'] ?? date('Y-m-d');

// === General Stats ===
$stats = [];

// Total followers
$stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_blocked = 0");
$stmt->execute([$lineAccountId]);
$stats['followers'] = $stmt->fetchColumn();

// New followers in range
$stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE DATE(created_at) BETWEEN ? AND ? AND (line_account_id = ? OR line_account_id IS NULL)");
$stmt->execute([$startDate, $endDate, $lineAccountId]);
$stats['new_followers'] = $stmt->fetchColumn();

// Total messages in range
$stmt = $db->prepare("SELECT COUNT(*) as total FROM messages WHERE DATE(created_at) BETWEEN ? AND ? AND (line_account_id = ? OR line_account_id IS NULL)");
$stmt->execute([$startDate, $endDate, $lineAccountId]);
$stats['messages'] = $stmt->fetchColumn();

// Broadcasts sent in range
$stmt = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(sent_count), 0) as recipients FROM broadcasts WHERE status = 'sent' AND DATE(sent_at) BETWEEN ? AND ? AND (line_account_id = ? OR line_account_id IS NULL)");
$stmt->execute([$startDate, $endDate, $lineAccountId]);
$broadcastStats = $stmt->fetch();
$stats['broadcasts'] = $broadcastStats['total'] ?? 0;
$stats['broadcast_recipients'] = $broadcastStats['recipients'] ?? 0;

// === Sales Stats ===
try {
    $stmt = $db->prepare("SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as revenue
        FROM transactions 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $salesStats = $stmt->fetch();
    $stats['orders'] = $salesStats['total_orders'] ?? 0;
    $stats['revenue'] = $salesStats['revenue'] ?? 0;
} catch (Exception $e) {
    $stats['orders'] = 0;
    $stats['revenue'] = 0;
}

// === CRM Stats ===
// Active users (sent message in period)
$stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM messages WHERE DATE(created_at) BETWEEN ? AND ? AND direction = 'incoming' AND (line_account_id = ? OR line_account_id IS NULL)");
$stmt->execute([$startDate, $endDate, $lineAccountId]);
$stats['active_users'] = $stmt->fetchColumn();

// Top Tags
$topTags = [];
try {
    $stmt = $db->prepare("SELECT t.name, t.color, COUNT(ut.user_id) as count 
        FROM tags t 
        LEFT JOIN user_tags ut ON t.id = ut.tag_id 
        WHERE (t.line_account_id = ? OR t.line_account_id IS NULL)
        GROUP BY t.id 
        ORDER BY count DESC 
        LIMIT 5");
    $stmt->execute([$lineAccountId]);
    $topTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Segments count
$segmentsCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM customer_segments WHERE (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$lineAccountId]);
    $segmentsCount = $stmt->fetchColumn();
} catch (Exception $e) {}

// === Chart Data ===
// Messages by day
$stmt = $db->prepare("SELECT DATE(created_at) as date, 
    SUM(direction = 'incoming') as incoming,
    SUM(direction = 'outgoing') as outgoing
    FROM messages 
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND (line_account_id = ? OR line_account_id IS NULL)
    GROUP BY DATE(created_at) ORDER BY date");
$stmt->execute([$startDate, $endDate, $lineAccountId]);
$messagesByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Followers by day
$stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as count
    FROM users 
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND (line_account_id = ? OR line_account_id IS NULL)
    GROUP BY DATE(created_at) ORDER BY date");
$stmt->execute([$startDate, $endDate, $lineAccountId]);
$followersByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Revenue by day
$revenueByDay = [];
try {
    $stmt = $db->prepare("SELECT DATE(created_at) as date, 
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as revenue
        FROM transactions 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND (line_account_id = ? OR line_account_id IS NULL)
        GROUP BY DATE(created_at) ORDER BY date");
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $revenueByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Top auto-reply keywords
$topKeywords = [];
try {
    $stmt = $db->prepare("SELECT keyword, hit_count FROM auto_replies 
        WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)
        ORDER BY hit_count DESC LIMIT 5");
    $stmt->execute([$lineAccountId]);
    $topKeywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once 'includes/header.php';
?>

<div class="content-area">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">📊 สถิติรวม</h2>
            <p class="text-sm text-gray-500">ภาพรวมข้อมูลลูกค้า ข้อความ และการตลาด</p>
        </div>
        
        <!-- Period Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex bg-white rounded-lg border overflow-hidden">
                <a href="?period=7" class="px-3 py-2 text-sm <?= $period == '7' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">7 วัน</a>
                <a href="?period=30" class="px-3 py-2 text-sm <?= $period == '30' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">30 วัน</a>
                <a href="?period=90" class="px-3 py-2 text-sm <?= $period == '90' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">90 วัน</a>
            </div>
            <form class="flex items-center gap-2">
                <input type="date" name="start" value="<?= $startDate ?>" class="px-3 py-2 border rounded-lg text-sm">
                <span class="text-gray-400">-</span>
                <input type="date" name="end" value="<?= $endDate ?>" class="px-3 py-2 border rounded-lg text-sm">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
    </div>
    
    <!-- Main Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
        <!-- Followers -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-xs">ผู้ติดตาม</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['followers']) ?></p>
                </div>
                <i class="fas fa-users text-3xl text-blue-300"></i>
            </div>
            <p class="text-xs text-blue-200 mt-2">+<?= number_format($stats['new_followers']) ?> ใหม่</p>
        </div>
        
        <!-- Active Users -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-xs">Active Users</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['active_users']) ?></p>
                </div>
                <i class="fas fa-user-check text-3xl text-green-300"></i>
            </div>
        </div>
        
        <!-- Messages -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-xs">ข้อความ</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['messages']) ?></p>
                </div>
                <i class="fas fa-envelope text-3xl text-purple-300"></i>
            </div>
        </div>
        
        <!-- Broadcasts -->
        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-xl p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-xs">Broadcast</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['broadcasts']) ?></p>
                </div>
                <i class="fas fa-bullhorn text-3xl text-orange-300"></i>
            </div>
            <p class="text-xs text-orange-200 mt-2"><?= number_format($stats['broadcast_recipients']) ?> ผู้รับ</p>
        </div>
        
        <!-- Orders -->
        <div class="bg-gradient-to-br from-cyan-500 to-cyan-600 rounded-xl p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-cyan-100 text-xs">ออเดอร์</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['orders']) ?></p>
                </div>
                <i class="fas fa-shopping-cart text-3xl text-cyan-300"></i>
            </div>
        </div>
        
        <!-- Revenue -->
        <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl p-4 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-emerald-100 text-xs">รายได้</p>
                    <p class="text-xl font-bold">฿<?= number_format($stats['revenue']) ?></p>
                </div>
                <i class="fas fa-baht-sign text-3xl text-emerald-300"></i>
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Messages Chart -->
        <div class="bg-white rounded-xl shadow-sm p-4">
            <h3 class="font-semibold text-gray-800 mb-4">💬 ข้อความรายวัน</h3>
            <canvas id="messagesChart" height="180"></canvas>
        </div>
        
        <!-- Followers Chart -->
        <div class="bg-white rounded-xl shadow-sm p-4">
            <h3 class="font-semibold text-gray-800 mb-4">👥 ผู้ติดตามใหม่</h3>
            <canvas id="followersChart" height="180"></canvas>
        </div>
        
        <!-- Revenue Chart -->
        <div class="bg-white rounded-xl shadow-sm p-4">
            <h3 class="font-semibold text-gray-800 mb-4">💰 รายได้รายวัน</h3>
            <canvas id="revenueChart" height="180"></canvas>
        </div>
    </div>
    
    <!-- CRM Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <!-- Top Tags -->
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold text-gray-800">🏷️ Top Tags</h3>
                <a href="/user-tags" class="text-sm text-purple-600 hover:underline">จัดการ →</a>
            </div>
            <?php if (!empty($topTags)): ?>
            <div class="space-y-2">
                <?php foreach ($topTags as $tag): ?>
                <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full" style="background-color: <?= $tag['color'] ?? '#6b7280' ?>"></div>
                        <span class="text-sm"><?= htmlspecialchars($tag['name']) ?></span>
                    </div>
                    <span class="text-sm font-medium text-gray-600"><?= number_format($tag['count']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-400 text-center py-4">ยังไม่มี Tags</p>
            <?php endif; ?>
        </div>
        
        <!-- Top Keywords -->
        <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold text-gray-800">🔑 Top Keywords</h3>
                <a href="/auto-reply" class="text-sm text-purple-600 hover:underline">จัดการ →</a>
            </div>
            <?php if (!empty($topKeywords)): ?>
            <div class="space-y-2">
                <?php foreach ($topKeywords as $i => $kw): ?>
                <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                    <div class="flex items-center gap-2">
                        <span class="w-5 h-5 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center text-xs"><?= $i + 1 ?></span>
                        <span class="text-sm"><?= htmlspecialchars($kw['keyword']) ?></span>
                    </div>
                    <span class="text-sm font-medium text-gray-600"><?= number_format($kw['hit_count'] ?? 0) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-gray-400 text-center py-4">ยังไม่มีข้อมูล</p>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-sm p-4">
            <h3 class="font-semibold text-gray-800 mb-4">🚀 Quick Actions</h3>
            <div class="grid grid-cols-2 gap-2">
                <a href="/customer-segments" class="p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition text-center">
                    <i class="fas fa-layer-group text-xl text-blue-500 mb-1"></i>
                    <p class="text-xs font-medium">Segments (<?= $segmentsCount ?>)</p>
                </a>
                <a href="/broadcast" class="p-3 bg-orange-50 rounded-lg hover:bg-orange-100 transition text-center">
                    <i class="fas fa-paper-plane text-xl text-orange-500 mb-1"></i>
                    <p class="text-xs font-medium">Broadcast</p>
                </a>
                <a href="/shop/reports" class="p-3 bg-green-50 rounded-lg hover:bg-green-100 transition text-center">
                    <i class="fas fa-chart-bar text-xl text-green-500 mb-1"></i>
                    <p class="text-xs font-medium">รายงานยอดขาย</p>
                </a>
                <a href="/users" class="p-3 bg-purple-50 rounded-lg hover:bg-purple-100 transition text-center">
                    <i class="fas fa-users text-xl text-purple-500 mb-1"></i>
                    <p class="text-xs font-medium">ลูกค้า</p>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Export Section -->
    <div class="bg-white rounded-xl shadow-sm p-4">
        <h3 class="font-semibold text-gray-800 mb-4">📥 Export ข้อมูล</h3>
        <div class="flex flex-wrap gap-3">
            <a href="/export?type=messages&start=<?= $startDate ?>&end=<?= $endDate ?>" class="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">
                <i class="fas fa-file-csv text-green-500"></i>
                <span class="text-sm">Export ข้อความ</span>
            </a>
            <a href="/export?type=users&start=<?= $startDate ?>&end=<?= $endDate ?>" class="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">
                <i class="fas fa-file-csv text-blue-500"></i>
                <span class="text-sm">Export ผู้ติดตาม</span>
            </a>
            <a href="/export?type=orders&start=<?= $startDate ?>&end=<?= $endDate ?>" class="flex items-center gap-2 px-4 py-2 bg-gray-50 rounded-lg hover:bg-gray-100">
                <i class="fas fa-file-csv text-purple-500"></i>
                <span class="text-sm">Export ออเดอร์</span>
            </a>
        </div>
    </div>
</div>

<script>
// Messages Chart
new Chart(document.getElementById('messagesChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($messagesByDay, 'date')) ?>,
        datasets: [
            { label: 'รับ', data: <?= json_encode(array_column($messagesByDay, 'incoming')) ?>, backgroundColor: '#3B82F6' },
            { label: 'ส่ง', data: <?= json_encode(array_column($messagesByDay, 'outgoing')) ?>, backgroundColor: '#10B981' }
        ]
    },
    options: { 
        responsive: true, 
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } 
    }
});

// Followers Chart
new Chart(document.getElementById('followersChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($followersByDay, 'date')) ?>,
        datasets: [{
            label: 'ผู้ติดตามใหม่',
            data: <?= json_encode(array_column($followersByDay, 'count')) ?>,
            borderColor: '#8B5CF6',
            backgroundColor: 'rgba(139, 92, 246, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: { 
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Revenue Chart
new Chart(document.getElementById('revenueChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($revenueByDay, 'date')) ?>,
        datasets: [{
            label: 'รายได้',
            data: <?= json_encode(array_column($revenueByDay, 'revenue')) ?>,
            borderColor: '#10B981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: { 
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { 
            y: { 
                beginAtZero: true,
                ticks: { callback: function(v) { return '฿' + v.toLocaleString(); } }
            } 
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
