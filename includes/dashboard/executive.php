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

// 5. วิดีโอคอลวันนี้
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

$responseAccent = $avgResponseTime <= 5 ? 'emerald' : ($avgResponseTime <= 15 ? 'amber' : 'rose');
$responseLabel  = $avgResponseTime <= 5 ? 'ดีมาก' : ($avgResponseTime <= 15 ? 'พอใช้' : 'ต้องปรับปรุง');
$problemCount   = count($problemMessages);
?>

<!-- ─── Command Strip: Date & Actions ─── -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-1">
    <p class="text-sm text-gray-500 font-medium">
        <i class="fas fa-calendar-day mr-1.5 text-gray-400"></i>
        <?= date('l, j M Y', strtotime($dateFilter)) ?>
    </p>
    <div class="flex items-center gap-2">
        <input type="date" id="dateFilter" value="<?= htmlspecialchars($dateFilter) ?>"
            class="px-3 py-2 text-sm border border-gray-200 rounded-xl bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-400 outline-none transition"
            onchange="window.location='?tab=executive&date='+this.value">
        <button onclick="window.print()"
            class="px-3 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition">
            <i class="fas fa-print mr-1.5"></i>พิมพ์
        </button>
    </div>
</div>

<!-- ─── Primary KPI Row ─── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
    <?= renderKpiCard('indigo',  'ข้อความวันนี้', number_format($msgStats['total'] ?? 0),
        'รับ ' . number_format($msgStats['incoming'] ?? 0) . ' / ส่ง ' . number_format($msgStats['outgoing'] ?? 0),
        'fas fa-comments') ?>
    <?= renderKpiCard('emerald', 'ลูกค้าติดต่อ', number_format($customersToday),
        '+' . $newCustomers . ' ใหม่', 'fas fa-users') ?>
    <?= renderKpiCard('amber',   'ออเดอร์', number_format($orderStats['total'] ?? 0),
        ($orderStats['pending'] ?? 0) . ' รอดำเนินการ', 'fas fa-shopping-cart') ?>
    <?= renderKpiCard('violet',  'รายได้', '฿' . number_format($orderStats['revenue'] ?? 0),
        ($orderStats['completed'] ?? 0) . ' สำเร็จ', 'fas fa-baht-sign') ?>
    <?= renderKpiCard('indigo',  'วิดีโอคอล', number_format($videoStats['total'] ?? 0),
        'เฉลี่ย ' . round(($videoStats['avg_duration'] ?? 0) / 60, 1) . ' นาที', 'fas fa-video') ?>
</div>

<!-- ─── Attention Zone: Response Time + Unread + Problems ─── -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <?= renderKpiCard($responseAccent, 'เวลาตอบกลับเฉลี่ย',
        $avgResponseTime . ' นาที', $responseLabel, 'fas fa-clock') ?>

    <?php
    $unread       = (int)($msgStats['unread'] ?? 0);
    $unreadAccent = $unread > 0 ? 'rose' : 'emerald';
    $unreadAttrs  = $unread > 0 ? ['class' => 'kpi-card--alert'] : [];
    echo renderKpiCard($unreadAccent, 'ยังไม่ได้อ่าน', number_format($unread),
        'ข้อความ', 'fas fa-envelope', $unreadAttrs);
    ?>

    <?php
    $problemAccent = $problemCount > 0 ? 'rose' : 'emerald';
    $problemAttrs  = $problemCount > 0 ? ['class' => 'kpi-card--alert'] : [];
    echo renderKpiCard($problemAccent, 'ปัญหา/ข้อร้องเรียน', (string)$problemCount,
        'รายการ', 'fas fa-exclamation-triangle', $problemAttrs);
    ?>
</div>

<!-- ─── Analytics: Admin Performance + Hourly Activity ─── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <?php
    ob_start();
    if (empty($adminPerformance)): ?>
        <div class="sc-empty">
            <div class="sc-empty__circle"><i class="fas fa-user-clock" aria-hidden="true"></i></div>
            <p class="sc-empty__title">ไม่มีข้อมูลผลงาน Admin</p>
        </div>
    <?php else:
        foreach ($adminPerformance as $i => $admin): ?>
            <div class="sc-row">
                <div style="width:36px;height:36px;border-radius:10px;background:var(--color-primary-600);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:14px;flex-shrink:0;">
                    <?= $i + 1 ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:600;color:#132235;"><?= htmlspecialchars($admin['admin_name'] ?: 'System/Bot') ?></div>
                    <div style="font-size:11px;color:#74869a;">ดูแล <?= (int)$admin['customers_handled'] ?> ลูกค้า</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:20px;font-weight:800;color:var(--color-primary-600);"><?= number_format($admin['messages_sent'] ?? 0) ?></div>
                    <div style="font-size:11px;color:#94a3b8;">ข้อความ</div>
                </div>
            </div>
        <?php endforeach;
    endif;
    echo renderSectionCardFlush('ผลงาน Admin วันนี้', 'fas fa-user-tie', ob_get_clean(), null, 'indigo');
    ?>

    <?php
    ob_start(); ?>
        <div style="padding:20px;"><canvas id="hourlyChart" height="200"></canvas></div>
    <?php
    echo renderSectionCard('กิจกรรมรายชั่วโมง', 'fas fa-chart-area', ob_get_clean(), null, 'emerald');
    ?>
</div>

<!-- ─── Attention: Problem Messages + Recent Conversations ─── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <?php
    ob_start();
    if (empty($problemMessages)): ?>
        <div class="sc-empty">
            <div class="sc-empty__circle"><i class="fas fa-check-circle" aria-hidden="true" style="color:var(--color-emerald-400);"></i></div>
            <p class="sc-empty__title">ไม่พบข้อความที่เป็นปัญหา</p>
        </div>
    <?php else:
        foreach ($problemMessages as $msg): ?>
            <div class="sc-row cursor-pointer" onclick="viewChat(<?= (int)$msg['user_id'] ?>)">
                <img src="<?= htmlspecialchars($msg['picture_url'] ?: 'https://via.placeholder.com/40') ?>"
                    style="width:40px;height:40px;border-radius:12px;object-fit:cover;flex-shrink:0;" alt="">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:13px;font-weight:600;color:#132235;"><?= htmlspecialchars($msg['display_name'] ?: 'ลูกค้า') ?></span>
                        <span style="font-size:11px;color:#94a3b8;"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                    </div>
                    <p style="font-size:12px;color:#5f7286;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0;"><?= htmlspecialchars($msg['content'] ?? '') ?></p>
                </div>
                <i class="fas fa-chevron-right" style="color:#cbd5e1;font-size:12px;"></i>
            </div>
        <?php endforeach;
    endif;
    $problemBadge  = '<span class="db-section-badge" style="background:#fef2f2;color:#dc2626;border-color:#fecaca;">' . $problemCount . ' รายการ</span>';
    $problemCardAttrs = $problemCount > 0 ? ['class' => 'section-card--alert'] : [];
    echo renderSectionCardFlush('ข้อความที่อาจเป็นปัญหา', 'fas fa-exclamation-circle',
        '<div style="max-height:400px;overflow-y:auto;">' . ob_get_clean() . '</div>',
        $problemBadge, 'rose', $problemCardAttrs);
    ?>

    <?php
    ob_start();
    if (empty($recentConversations)): ?>
        <div class="sc-empty">
            <div class="sc-empty__circle"><i class="fas fa-comments" aria-hidden="true"></i></div>
            <p class="sc-empty__title">ยังไม่มีการสนทนาวันนี้</p>
        </div>
    <?php else:
        foreach ($recentConversations as $conv): ?>
            <div class="sc-row cursor-pointer" onclick="viewChat(<?= (int)$conv['id'] ?>)">
                <img src="<?= htmlspecialchars($conv['picture_url'] ?: 'https://via.placeholder.com/40') ?>"
                    style="width:40px;height:40px;border-radius:12px;object-fit:cover;flex-shrink:0;" alt="">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:13px;font-weight:600;color:#132235;"><?= htmlspecialchars($conv['display_name'] ?: 'ลูกค้า') ?></span>
                        <span style="display:inline-flex;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;background:var(--color-primary-100);color:var(--color-primary-700);"><?= (int)$conv['message_count'] ?> ข้อความ</span>
                    </div>
                    <p style="font-size:12px;color:#5f7286;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0;"><?= htmlspecialchars($conv['last_message']) ?></p>
                </div>
                <span style="font-size:11px;color:#94a3b8;flex-shrink:0;"><?= date('H:i', strtotime($conv['last_message_at'])) ?></span>
            </div>
        <?php endforeach;
    endif;
    echo renderSectionCardFlush('การสนทนาล่าสุด', 'fas fa-history',
        '<div style="max-height:400px;overflow-y:auto;">' . ob_get_clean() . '</div>',
        null, 'indigo');
    ?>
</div>

<!-- ─── Top Issues ─── -->
<?php
ob_start();
$hasIssues = false;
foreach ($topIssues as $cnt) { if ($cnt > 0) { $hasIssues = true; break; } }
if (!$hasIssues): ?>
    <div class="sc-empty">
        <div class="sc-empty__circle"><i class="fas fa-tags" aria-hidden="true"></i></div>
        <p class="sc-empty__title">ยังไม่มีข้อมูลหัวข้อ</p>
    </div>
<?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach ($topIssues as $issue => $cnt): ?>
            <?php if ($cnt > 0): ?>
                <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;font-size:13px;">
                    <span style="font-weight:600;color:#9a3412;"><?= htmlspecialchars($issue) ?></span>
                    <span style="display:inline-flex;align-items:center;justify-content:center;min-width:24px;padding:2px 8px;border-radius:999px;background:#fdba74;color:#7c2d12;font-size:11px;font-weight:700;"><?= $cnt ?></span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif;
echo renderSectionCard('หัวข้อที่ลูกค้าถามบ่อย', 'fas fa-tags', ob_get_clean(), null, 'amber');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const hourlyData = <?= json_encode(array_values($hourlyActivity)) ?>;
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: Array.from({ length: 24 }, (_, i) => i + ':00'),
            datasets: [{
                label: 'ข้อความ',
                data: hourlyData,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,0.08)',
                fill: true,
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#6366f1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#132235',
                    titleFont: { size: 12, weight: '600' },
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 10,
                    displayColors: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 }, color: '#94a3b8' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 }, color: '#94a3b8', maxRotation: 0 }
                }
            }
        }
    });

    function viewChat(userId) {
        window.location.href = 'chat.php?user=' + userId;
    }
</script>
