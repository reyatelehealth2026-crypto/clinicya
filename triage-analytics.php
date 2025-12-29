<?php
/**
 * Triage Analytics - รายงานสถิติการซักประวัติ
 * Version 1.1 - Fixed to count all session statuses
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Triage Analytics';

// Date range - default to last 30 days
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end'] ?? date('Y-m-d');

// Get statistics
$stats = [
    'total_sessions' => 0,
    'completed' => 0,
    'escalated' => 0,
    'cancelled' => 0,
    'avg_completion_time' => 0,
    'urgent_cases' => 0,
    'in_progress' => 0,
];

$topSymptoms = [];
$dailyStats = [];
$completionRate = 0;

try {
    // Total sessions - count all statuses including NULL and 'active'
    // Note: priority column doesn't exist, use current_state = 'emergency' for urgent cases
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'escalated' OR current_state = 'emergency' THEN 1 ELSE 0 END) as escalated,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status IS NULL OR status = 'active' OR status = '' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN current_state = 'emergency' THEN 1 ELSE 0 END) as urgent
        FROM triage_sessions 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Debug log
    $stats['total_sessions'] = $result['total'] ?? 0;
    $stats['completed'] = $result['completed'] ?? 0;
    $stats['escalated'] = $result['escalated'] ?? 0;
    $stats['cancelled'] = $result['cancelled'] ?? 0;
    $stats['in_progress'] = $result['in_progress'] ?? 0;
    $stats['urgent_cases'] = $result['urgent'] ?? 0;
    
    if ($stats['total_sessions'] > 0) {
        $completionRate = round(($stats['completed'] / $stats['total_sessions']) * 100, 1);
    }
    
    // Average completion time
    $stmt = $db->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) as avg_time
        FROM triage_sessions 
        WHERE status = 'completed' 
        AND completed_at IS NOT NULL
        AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $avgResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['avg_completion_time'] = round($avgResult['avg_time'] ?? 0, 1);
    
    // Top symptoms
    $stmt = $db->prepare("
        SELECT triage_data
        FROM triage_sessions 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $symptomCounts = [];
    foreach ($sessions as $session) {
        $data = json_decode($session['triage_data'] ?? '{}', true);
        $symptoms = $data['symptoms'] ?? [];
        foreach ($symptoms as $symptom) {
            $symptomCounts[$symptom] = ($symptomCounts[$symptom] ?? 0) + 1;
        }
    }
    arsort($symptomCounts);
    $topSymptoms = array_slice($symptomCounts, 0, 10, true);
    
    // Daily stats - count all sessions
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status IS NULL OR status = 'active' OR status = '' THEN 1 ELSE 0 END) as in_progress
        FROM triage_sessions 
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date
    ");
    $stmt->execute([$startDate, $endDate]);
    $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Log error but continue
    error_log("Triage Analytics Error: " . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.stat-card { transition: all 0.3s; }
.stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
.chart-container { height: 300px; }
</style>

<div class="container mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-chart-pie text-purple-500 mr-2"></i>Triage Analytics
            </h1>
            <p class="text-gray-500 mt-1">สถิติการซักประวัติและปรึกษาเภสัชกร</p>
        </div>
        
        <!-- Date Filter -->
        <form class="flex items-center gap-3">
            <input type="date" name="start" value="<?= $startDate ?>" 
                   class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500">
            <span class="text-gray-400">ถึง</span>
            <input type="date" name="end" value="<?= $endDate ?>" 
                   class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500">
            <button type="submit" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                <i class="fas fa-filter mr-2"></i>กรอง
            </button>
        </form>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-4 mb-8">
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-gray-800"><?= number_format($stats['total_sessions']) ?></div>
            <div class="text-sm text-gray-500">Session ทั้งหมด</div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-green-600"><?= number_format($stats['completed']) ?></div>
            <div class="text-sm text-gray-500">เสร็จสมบูรณ์</div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-gray-600"><?= number_format($stats['in_progress'] ?? 0) ?></div>
            <div class="text-sm text-gray-500">กำลังดำเนินการ</div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-yellow-600"><?= number_format($stats['escalated']) ?></div>
            <div class="text-sm text-gray-500">ส่งต่อเภสัชกร</div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-red-600"><?= number_format($stats['urgent_cases']) ?></div>
            <div class="text-sm text-gray-500">เคสเร่งด่วน</div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-blue-600"><?= $completionRate ?>%</div>
            <div class="text-sm text-gray-500">อัตราสำเร็จ</div>
        </div>
        
        <div class="stat-card bg-white rounded-xl shadow p-4">
            <div class="text-3xl font-bold text-purple-600"><?= $stats['avg_completion_time'] ?></div>
            <div class="text-sm text-gray-500">นาที/เคส (เฉลี่ย)</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Daily Chart -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-800 mb-4">
                <i class="fas fa-chart-line text-blue-500 mr-2"></i>Session รายวัน
            </h3>
            <div class="chart-container">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>

        <!-- Top Symptoms -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-800 mb-4">
                <i class="fas fa-stethoscope text-green-500 mr-2"></i>อาการที่พบบ่อย
            </h3>
            <?php if (empty($topSymptoms)): ?>
            <p class="text-gray-400 text-center py-8">ยังไม่มีข้อมูล</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php 
                $maxCount = max($topSymptoms);
                foreach ($topSymptoms as $symptom => $count): 
                    $percentage = ($count / $maxCount) * 100;
                ?>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-700"><?= htmlspecialchars($symptom) ?></span>
                        <span class="text-gray-500"><?= $count ?> ครั้ง</span>
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

    <!-- Status Distribution -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-800 mb-4">
                <i class="fas fa-chart-pie text-purple-500 mr-2"></i>สถานะ Session
            </h3>
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <!-- Recent Sessions -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-800 mb-4">
                <i class="fas fa-history text-orange-500 mr-2"></i>Session ล่าสุด
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="pb-2">วันที่</th>
                            <th class="pb-2">ทั้งหมด</th>
                            <th class="pb-2">สำเร็จ</th>
                            <th class="pb-2">อัตรา</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse(array_slice($dailyStats, -7)) as $day): ?>
                        <tr class="border-b">
                            <td class="py-2"><?= date('d/m', strtotime($day['date'])) ?></td>
                            <td class="py-2"><?= $day['total'] ?></td>
                            <td class="py-2 text-green-600"><?= $day['completed'] ?></td>
                            <td class="py-2">
                                <?php $rate = $day['total'] > 0 ? round(($day['completed'] / $day['total']) * 100) : 0; ?>
                                <span class="px-2 py-0.5 bg-<?= $rate >= 70 ? 'green' : ($rate >= 50 ? 'yellow' : 'red') ?>-100 
                                             text-<?= $rate >= 70 ? 'green' : ($rate >= 50 ? 'yellow' : 'red') ?>-700 rounded-full text-xs">
                                    <?= $rate ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Daily Chart
const dailyData = <?= json_encode($dailyStats) ?>;
new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: dailyData.map(d => d.date.substring(5)),
        datasets: [
            {
                label: 'ทั้งหมด',
                data: dailyData.map(d => d.total),
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4
            },
            {
                label: 'สำเร็จ',
                data: dailyData.map(d => d.completed),
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true } }
    }
});

// Status Chart
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['สำเร็จ', 'ส่งต่อเภสัชกร', 'ยกเลิก', 'กำลังดำเนินการ'],
        datasets: [{
            data: [
                <?= $stats['completed'] ?>,
                <?= $stats['escalated'] ?>,
                <?= $stats['cancelled'] ?>,
                <?= $stats['in_progress'] ?? 0 ?>
            ],
            backgroundColor: ['#10B981', '#F59E0B', '#EF4444', '#6B7280']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
