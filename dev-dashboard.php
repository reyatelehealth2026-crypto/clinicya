<?php
/**
 * Developer Dashboard - Error Logs & Debug Tools
 * สำหรับดู error logs, webhook logs, และ debug ข้อมูลต่างๆ
 */
require_once 'config/database.php';
require_once 'config/config.php';

$pageTitle = '🛠️ Developer Dashboard';
$db = Database::getInstance()->getConnection();

// Handle actions
$action = $_GET['action'] ?? '';
$message = '';

if ($action === 'clear_logs') {
    try {
        $db->exec("DELETE FROM dev_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $message = '✅ ลบ logs เก่ากว่า 7 วันแล้ว';
    } catch (Exception $e) {
        $message = '❌ ' . $e->getMessage();
    }
}

if ($action === 'test_webhook') {
    // Log test entry
    try {
        $stmt = $db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute(['info', 'test', 'Test log entry', json_encode(['test' => true])]);
        $message = '✅ เพิ่ม test log แล้ว';
    } catch (Exception $e) {
        $message = '❌ ' . $e->getMessage();
    }
}

if ($action === 'clear_error_log') {
    $logFile = __DIR__ . '/error_log';
    if (file_exists($logFile) && is_writable($logFile)) {
        file_put_contents($logFile, '');
        $message = '✅ ล้าง error log แล้ว';
    } else {
        $message = '❌ ไม่สามารถล้าง error log ได้';
    }
}

// Create dev_logs table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dev_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_type ENUM('error', 'warning', 'info', 'debug', 'webhook') DEFAULT 'info',
        source VARCHAR(100),
        message TEXT,
        data JSON,
        user_id VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (log_type),
        INDEX idx_created (created_at),
        INDEX idx_source (source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Table might already exist
}

// Get filter params
$filterType = $_GET['type'] ?? '';
$filterSource = $_GET['source'] ?? '';
$filterDate = $_GET['date'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["DATE(created_at) = ?"];
$params = [$filterDate];

if ($filterType) {
    $where[] = "log_type = ?";
    $params[] = $filterType;
}
if ($filterSource) {
    $where[] = "source LIKE ?";
    $params[] = "%{$filterSource}%";
}

$whereClause = implode(' AND ', $where);

// Get logs
try {
    $stmt = $db->prepare("SELECT * FROM dev_logs WHERE {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM dev_logs WHERE {$whereClause}");
    $stmt->execute($params);
    $totalLogs = $stmt->fetchColumn();
} catch (Exception $e) {
    $logs = [];
    $totalLogs = 0;
}

// Get stats
try {
    $stats = [
        'total_today' => $db->query("SELECT COUNT(*) FROM dev_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'errors_today' => $db->query("SELECT COUNT(*) FROM dev_logs WHERE DATE(created_at) = CURDATE() AND log_type = 'error'")->fetchColumn(),
        'webhooks_today' => $db->query("SELECT COUNT(*) FROM dev_logs WHERE DATE(created_at) = CURDATE() AND log_type = 'webhook'")->fetchColumn(),
    ];
} catch (Exception $e) {
    $stats = ['total_today' => 0, 'errors_today' => 0, 'webhooks_today' => 0];
}

// Get sources for filter
try {
    $sources = $db->query("SELECT DISTINCT source FROM dev_logs ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $sources = [];
}

// Read PHP error log - try multiple locations
$phpErrorLog = '';
$errorLogPath = ini_get('error_log');
$possiblePaths = [
    '/var/www/vhosts/flexrich.site/logs/clinicya.re-ya.com/error_log',  // Plesk server log
    __DIR__ . '/error_log',  // Local error_log
    $errorLogPath,
    __DIR__ . '/php_errors.log',
    __DIR__ . '/logs/error.log',
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log',
    '/var/log/php-fpm/error.log',
    '/var/log/nginx/error.log',
    sys_get_temp_dir() . '/php_errors.log',
];

$foundLogPath = null;
foreach ($possiblePaths as $path) {
    if ($path && file_exists($path) && is_readable($path)) {
        $foundLogPath = $path;
        // Read last 300 lines for more context
        $lines = file($path);
        if ($lines) {
            $phpErrorLog = implode('', array_slice($lines, -300));
        }
        break;
    }
}
$errorLogPath = $foundLogPath ?: $errorLogPath;

// Parse error log into structured format
$parsedErrors = [];
if ($phpErrorLog) {
    $logLines = explode("\n", $phpErrorLog);
    $currentError = null;
    
    foreach ($logLines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Match PHP error format: [date] PHP message: ...
        if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
            if ($currentError) {
                $parsedErrors[] = $currentError;
            }
            $currentError = [
                'datetime' => $matches[1],
                'message' => $matches[2],
                'type' => 'info'
            ];
            
            // Determine error type
            if (stripos($matches[2], 'error') !== false || stripos($matches[2], 'fatal') !== false) {
                $currentError['type'] = 'error';
            } elseif (stripos($matches[2], 'warning') !== false || stripos($matches[2], 'deprecated') !== false) {
                $currentError['type'] = 'warning';
            } elseif (stripos($matches[2], 'notice') !== false) {
                $currentError['type'] = 'notice';
            }
        } elseif ($currentError) {
            // Continuation of previous error
            $currentError['message'] .= "\n" . $line;
        }
    }
    if ($currentError) {
        $parsedErrors[] = $currentError;
    }
    
    // Reverse to show newest first
    $parsedErrors = array_reverse($parsedErrors);
}

include 'includes/header.php';
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= strpos($message, '✅') !== false ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
    <?= $message ?>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center">
            <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                <i class="fas fa-list-alt text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Logs วันนี้</p>
                <p class="text-2xl font-bold"><?= number_format($stats['total_today']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center">
            <div class="p-3 rounded-full bg-red-100 text-red-600">
                <i class="fas fa-exclamation-triangle text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Errors วันนี้</p>
                <p class="text-2xl font-bold text-red-600"><?= number_format($stats['errors_today']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center">
            <div class="p-3 rounded-full bg-green-100 text-green-600">
                <i class="fas fa-webhook text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Webhooks วันนี้</p>
                <p class="text-2xl font-bold"><?= number_format($stats['webhooks_today']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center">
            <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                <i class="fas fa-database text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Total Records</p>
                <p class="text-2xl font-bold"><?= number_format($totalLogs) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="border-b">
        <nav class="flex -mb-px">
            <button onclick="showTab('logs')" id="tab-logs" class="tab-btn px-6 py-3 border-b-2 border-green-500 text-green-600 font-medium">
                <i class="fas fa-list mr-2"></i>Dev Logs
            </button>
            <button onclick="showTab('php')" id="tab-php" class="tab-btn px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <i class="fas fa-php mr-2"></i>PHP Error Log
            </button>
            <button onclick="showTab('test')" id="tab-test" class="tab-btn px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <i class="fas fa-vial mr-2"></i>Test Commands
            </button>
            <button onclick="showTab('info')" id="tab-info" class="tab-btn px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <i class="fas fa-info-circle mr-2"></i>System Info
            </button>
        </nav>
    </div>

    <!-- Dev Logs Tab -->
    <div id="content-logs" class="tab-content p-4">
        <!-- Filters -->
        <form method="GET" class="flex flex-wrap gap-4 mb-4">
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันที่</label>
                <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" class="border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">ประเภท</label>
                <select name="type" class="border rounded px-3 py-2">
                    <option value="">ทั้งหมด</option>
                    <option value="error" <?= $filterType === 'error' ? 'selected' : '' ?>>🔴 Error</option>
                    <option value="warning" <?= $filterType === 'warning' ? 'selected' : '' ?>>🟡 Warning</option>
                    <option value="info" <?= $filterType === 'info' ? 'selected' : '' ?>>🔵 Info</option>
                    <option value="debug" <?= $filterType === 'debug' ? 'selected' : '' ?>>⚪ Debug</option>
                    <option value="webhook" <?= $filterType === 'webhook' ? 'selected' : '' ?>>🟢 Webhook</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">Source</label>
                <select name="source" class="border rounded px-3 py-2">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($sources as $src): ?>
                    <option value="<?= htmlspecialchars($src) ?>" <?= $filterSource === $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    <i class="fas fa-search mr-1"></i>ค้นหา
                </button>
                <a href="?action=clear_logs" onclick="return confirm('ลบ logs เก่ากว่า 7 วัน?')" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
                    <i class="fas fa-trash mr-1"></i>ล้าง Logs เก่า
                </a>
            </div>
        </form>
        
        <!-- Logs Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">เวลา</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Source</th>
                        <th class="px-4 py-2 text-left">Message</th>
                        <th class="px-4 py-2 text-left">Data</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-2"></i>
                            <p>ไม่มี logs</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 whitespace-nowrap text-gray-500">
                            <?= date('H:i:s', strtotime($log['created_at'])) ?>
                        </td>
                        <td class="px-4 py-2">
                            <?php
                            $typeColors = [
                                'error' => 'bg-red-100 text-red-800',
                                'warning' => 'bg-yellow-100 text-yellow-800',
                                'info' => 'bg-blue-100 text-blue-800',
                                'debug' => 'bg-gray-100 text-gray-800',
                                'webhook' => 'bg-green-100 text-green-800',
                            ];
                            $color = $typeColors[$log['log_type']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 rounded text-xs font-medium <?= $color ?>">
                                <?= strtoupper($log['log_type']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($log['source'] ?? '-') ?></td>
                        <td class="px-4 py-2 max-w-md truncate" title="<?= htmlspecialchars($log['message']) ?>">
                            <?= htmlspecialchars($log['message']) ?>
                        </td>
                        <td class="px-4 py-2">
                            <?php if ($log['data']): ?>
                            <button onclick="showData(<?= htmlspecialchars(json_encode($log['data'])) ?>)" class="text-blue-500 hover:underline text-xs">
                                <i class="fas fa-eye"></i> ดู
                            </button>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalLogs > $perPage): ?>
        <div class="mt-4 flex justify-center gap-2">
            <?php for ($i = 1; $i <= ceil($totalLogs / $perPage); $i++): ?>
            <a href="?page=<?= $i ?>&date=<?= $filterDate ?>&type=<?= $filterType ?>&source=<?= $filterSource ?>" 
               class="px-3 py-1 rounded <?= $i === $page ? 'bg-green-500 text-white' : 'bg-gray-200 hover:bg-gray-300' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- PHP Error Log Tab -->
    <div id="content-php" class="tab-content p-4 hidden">
        <div class="mb-4 flex flex-wrap gap-4 items-center justify-between">
            <p class="text-sm text-gray-600">
                <i class="fas fa-file-alt mr-1"></i>Error Log Path: 
                <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($errorLogPath ?: 'Not configured') ?></code>
            </p>
            <div class="flex gap-2">
                <button onclick="location.reload()" class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">
                    <i class="fas fa-sync-alt mr-1"></i>Refresh
                </button>
                <?php if ($foundLogPath): ?>
                <a href="?action=clear_error_log" onclick="return confirm('ล้าง error log?')" class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600">
                    <i class="fas fa-trash mr-1"></i>Clear Log
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!$phpErrorLog): ?>
        <div class="bg-yellow-100 text-yellow-800 px-3 py-2 rounded text-sm mb-4">
            <i class="fas fa-info-circle mr-1"></i>
            ไม่พบ error log file - ลองเพิ่มใน php.ini: <code>error_log = <?= __DIR__ ?>/error_log</code>
        </div>
        <?php endif; ?>
        
        <!-- Filter by type -->
        <div class="mb-4 flex gap-2">
            <button onclick="filterErrors('all')" class="error-filter-btn px-3 py-1 rounded text-sm bg-gray-200 hover:bg-gray-300" data-filter="all">
                ทั้งหมด (<?= count($parsedErrors) ?>)
            </button>
            <button onclick="filterErrors('error')" class="error-filter-btn px-3 py-1 rounded text-sm bg-red-100 text-red-700 hover:bg-red-200" data-filter="error">
                🔴 Errors (<?= count(array_filter($parsedErrors, fn($e) => $e['type'] === 'error')) ?>)
            </button>
            <button onclick="filterErrors('warning')" class="error-filter-btn px-3 py-1 rounded text-sm bg-yellow-100 text-yellow-700 hover:bg-yellow-200" data-filter="warning">
                🟡 Warnings (<?= count(array_filter($parsedErrors, fn($e) => $e['type'] === 'warning')) ?>)
            </button>
            <button onclick="filterErrors('notice')" class="error-filter-btn px-3 py-1 rounded text-sm bg-blue-100 text-blue-700 hover:bg-blue-200" data-filter="notice">
                🔵 Notices (<?= count(array_filter($parsedErrors, fn($e) => $e['type'] === 'notice')) ?>)
            </button>
        </div>
        
        <?php if (!empty($parsedErrors)): ?>
        <!-- Parsed Error List -->
        <div class="space-y-2 max-h-[500px] overflow-y-auto" id="errorList">
            <?php foreach ($parsedErrors as $idx => $err): ?>
            <?php
            $bgColor = match($err['type']) {
                'error' => 'bg-red-50 border-red-400',
                'warning' => 'bg-yellow-50 border-yellow-400',
                'notice' => 'bg-blue-50 border-blue-400',
                default => 'bg-gray-50 border-gray-400'
            };
            $textColor = match($err['type']) {
                'error' => 'text-red-800',
                'warning' => 'text-yellow-800',
                'notice' => 'text-blue-800',
                default => 'text-gray-800'
            };
            ?>
            <div class="error-item border-l-4 <?= $bgColor ?> p-3 rounded-r" data-type="<?= $err['type'] ?>">
                <div class="flex justify-between items-start mb-1">
                    <span class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($err['datetime']) ?></span>
                    <span class="px-2 py-0.5 rounded text-xs font-medium <?= $textColor ?> <?= str_replace('border', 'bg', $bgColor) ?>">
                        <?= strtoupper($err['type']) ?>
                    </span>
                </div>
                <pre class="text-sm <?= $textColor ?> whitespace-pre-wrap break-words font-mono"><?= htmlspecialchars(substr($err['message'], 0, 500)) ?><?= strlen($err['message']) > 500 ? '...' : '' ?></pre>
                <?php if (strlen($err['message']) > 500): ?>
                <button onclick="showFullError(<?= $idx ?>)" class="text-blue-500 text-xs mt-1 hover:underline">ดูทั้งหมด</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Hidden full errors for modal -->
        <script>
        const fullErrors = <?= json_encode(array_map(fn($e) => $e['message'], $parsedErrors)) ?>;
        </script>
        
        <?php elseif ($phpErrorLog): ?>
        <!-- Raw log fallback -->
        <pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-xs font-mono max-h-96 overflow-y-auto"><?= htmlspecialchars($phpErrorLog) ?></pre>
        <?php else: ?>
        <div class="bg-gray-100 p-6 rounded-lg text-center">
            <i class="fas fa-check-circle text-green-500 text-4xl mb-3"></i>
            <p class="text-gray-600 mb-4">ไม่พบ error log หรือยังไม่มี errors</p>
            <div class="text-left bg-white p-4 rounded max-w-lg mx-auto text-sm">
                <p class="font-medium mb-2">💡 วิธีเปิดใช้งาน PHP Error Log:</p>
                <ol class="list-decimal list-inside space-y-1 text-gray-600">
                    <li>เพิ่มใน <code class="bg-gray-100 px-1">php.ini</code>:</li>
                    <pre class="bg-gray-800 text-green-400 p-2 rounded mt-1 mb-2 text-xs">error_log = <?= __DIR__ ?>/error_log
log_errors = On
error_reporting = E_ALL</pre>
                    <li>หรือเพิ่มใน <code class="bg-gray-100 px-1">.htaccess</code>:</li>
                    <pre class="bg-gray-800 text-green-400 p-2 rounded mt-1 text-xs">php_value error_log <?= __DIR__ ?>/error_log</pre>
                </ol>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent errors from dev_logs -->
        <div class="mt-6">
            <h4 class="font-medium mb-3"><i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>Recent Errors (from Dev Logs)</h4>
            <?php
            try {
                $recentErrors = $db->query("SELECT * FROM dev_logs WHERE log_type = 'error' ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $recentErrors = [];
            }
            ?>
            <?php if (empty($recentErrors)): ?>
            <p class="text-gray-500 text-sm">✅ ไม่มี errors ล่าสุด</p>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($recentErrors as $err): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-3 text-sm">
                    <div class="flex justify-between">
                        <span class="font-mono text-red-800"><?= htmlspecialchars($err['source']) ?></span>
                        <span class="text-gray-500 text-xs"><?= $err['created_at'] ?></span>
                    </div>
                    <p class="text-red-700 mt-1"><?= htmlspecialchars($err['message']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Test Commands Tab -->
    <div id="content-test" class="tab-content p-4 hidden">
        <h3 class="font-bold mb-4">🧪 ทดสอบคำสั่ง Bot</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-medium mb-2">คำสั่งที่รองรับ (BusinessBot)</h4>
                <table class="w-full text-sm">
                    <thead class="bg-gray-200">
                        <tr><th class="px-2 py-1 text-left">คำสั่ง</th><th class="px-2 py-1 text-left">ฟังก์ชัน</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr><td class="px-2 py-1"><code>เมนู</code>, <code>menu</code>, <code>?</code></td><td class="px-2 py-1">showMainMenu</td></tr>
                        <tr><td class="px-2 py-1"><code>shop</code>, <code>ร้าน</code>, <code>สินค้า</code></td><td class="px-2 py-1">showCategories</td></tr>
                        <tr><td class="px-2 py-1"><code>ตะกร้า</code>, <code>cart</code></td><td class="px-2 py-1">showCart</td></tr>
                        <tr><td class="px-2 py-1"><code>สั่งซื้อ</code>, <code>checkout</code></td><td class="px-2 py-1">startCheckout</td></tr>
                        <tr><td class="px-2 py-1"><code>คำสั่งซื้อ</code>, <code>orders</code></td><td class="px-2 py-1">showOrders</td></tr>
                        <tr><td class="px-2 py-1"><code>ติดต่อ</code>, <code>ติดต่อเรา</code></td><td class="px-2 py-1">showContact</td></tr>
                        <tr><td class="px-2 py-1"><code>หมวด [id]</code></td><td class="px-2 py-1">showCategoryItems</td></tr>
                        <tr><td class="px-2 py-1"><code>เพิ่ม [id]</code>, <code>add [id]</code></td><td class="px-2 py-1">addToCart</td></tr>
                        <tr><td class="px-2 py-1"><code>ลบ [id]</code>, <code>remove [id]</code></td><td class="px-2 py-1">removeFromCart</td></tr>
                        <tr><td class="px-2 py-1"><code>ค้นหา [keyword]</code></td><td class="px-2 py-1">searchItems</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-medium mb-2">Quick Actions</h4>
                <div class="space-y-2">
                    <a href="?action=test_webhook" class="block bg-blue-500 text-white px-4 py-2 rounded text-center hover:bg-blue-600">
                        <i class="fas fa-plus mr-1"></i>เพิ่ม Test Log
                    </a>
                    <a href="webhook.php" target="_blank" class="block bg-green-500 text-white px-4 py-2 rounded text-center hover:bg-green-600">
                        <i class="fas fa-external-link-alt mr-1"></i>ดู Webhook URL
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- System Info Tab -->
    <div id="content-info" class="tab-content p-4 hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-medium mb-3"><i class="fas fa-server mr-2"></i>Server Info</h4>
                <table class="w-full text-sm">
                    <tr class="border-b"><td class="py-2 text-gray-600">PHP Version</td><td class="py-2 font-mono"><?= PHP_VERSION ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">Server Software</td><td class="py-2 font-mono text-xs"><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">Document Root</td><td class="py-2 font-mono text-xs"><?= $_SERVER['DOCUMENT_ROOT'] ?? 'N/A' ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">Memory Limit</td><td class="py-2 font-mono"><?= ini_get('memory_limit') ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">Max Execution Time</td><td class="py-2 font-mono"><?= ini_get('max_execution_time') ?>s</td></tr>
                    <tr><td class="py-2 text-gray-600">Upload Max Size</td><td class="py-2 font-mono"><?= ini_get('upload_max_filesize') ?></td></tr>
                </table>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-medium mb-3"><i class="fas fa-database mr-2"></i>Database Info</h4>
                <?php
                try {
                    $dbVersion = $db->query("SELECT VERSION()")->fetchColumn();
                    $dbSize = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
                    $tableCount = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
                } catch (Exception $e) {
                    $dbVersion = 'N/A';
                    $dbSize = 'N/A';
                    $tableCount = 'N/A';
                }
                ?>
                <table class="w-full text-sm">
                    <tr class="border-b"><td class="py-2 text-gray-600">MySQL Version</td><td class="py-2 font-mono"><?= $dbVersion ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">Database Size</td><td class="py-2 font-mono"><?= $dbSize ?> MB</td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">Tables</td><td class="py-2 font-mono"><?= $tableCount ?></td></tr>
                    <tr><td class="py-2 text-gray-600">Charset</td><td class="py-2 font-mono"><?= $db->query("SELECT @@character_set_database")->fetchColumn() ?></td></tr>
                </table>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-medium mb-3"><i class="fas fa-cog mr-2"></i>App Config</h4>
                <table class="w-full text-sm">
                    <tr class="border-b"><td class="py-2 text-gray-600">APP_NAME</td><td class="py-2 font-mono"><?= defined('APP_NAME') ? APP_NAME : 'N/A' ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">LINE Channel</td><td class="py-2 font-mono"><?= defined('LINE_CHANNEL_ACCESS_TOKEN') ? '✅ Set' : '❌ Not Set' ?></td></tr>
                    <tr class="border-b"><td class="py-2 text-gray-600">OpenAI API</td><td class="py-2 font-mono"><?= defined('OPENAI_API_KEY') && OPENAI_API_KEY ? '✅ Set' : '❌ Not Set' ?></td></tr>
                    <tr><td class="py-2 text-gray-600">Telegram</td><td class="py-2 font-mono"><?= defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN ? '✅ Set' : '❌ Not Set' ?></td></tr>
                </table>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="font-medium mb-3"><i class="fas fa-table mr-2"></i>Tables Status</h4>
                <div class="max-h-48 overflow-y-auto">
                    <table class="w-full text-sm">
                        <?php
                        $tables = ['users', 'messages', 'dev_logs', 'business_items', 'cart_items', 'transactions', 'orders', 'item_categories', 'product_categories'];
                        foreach ($tables as $table):
                            try {
                                $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
                                $status = '✅';
                            } catch (Exception $e) {
                                $count = '-';
                                $status = '❌';
                            }
                        ?>
                        <tr class="border-b">
                            <td class="py-1 text-gray-600"><?= $status ?> <?= $table ?></td>
                            <td class="py-1 font-mono text-right"><?= is_numeric($count) ? number_format($count) : $count ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data Modal -->
<div id="dataModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[80vh] overflow-hidden">
        <div class="flex justify-between items-center p-4 border-b">
            <h3 class="font-bold">📋 Log Data</h3>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-4 overflow-auto max-h-[60vh]">
            <pre id="modalData" class="bg-gray-100 p-4 rounded text-sm font-mono whitespace-pre-wrap"></pre>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    // Hide all content
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    // Remove active from all tabs
    document.querySelectorAll('.tab-btn').forEach(el => {
        el.classList.remove('border-green-500', 'text-green-600');
        el.classList.add('border-transparent', 'text-gray-500');
    });
    // Show selected content
    document.getElementById('content-' + tab).classList.remove('hidden');
    // Activate tab
    const activeTab = document.getElementById('tab-' + tab);
    activeTab.classList.remove('border-transparent', 'text-gray-500');
    activeTab.classList.add('border-green-500', 'text-green-600');
}

function showData(data) {
    try {
        const parsed = typeof data === 'string' ? JSON.parse(data) : data;
        document.getElementById('modalData').textContent = JSON.stringify(parsed, null, 2);
    } catch (e) {
        document.getElementById('modalData').textContent = data;
    }
    document.getElementById('dataModal').classList.remove('hidden');
    document.getElementById('dataModal').classList.add('flex');
}

function showFullError(idx) {
    if (typeof fullErrors !== 'undefined' && fullErrors[idx]) {
        document.getElementById('modalData').textContent = fullErrors[idx];
        document.getElementById('dataModal').classList.remove('hidden');
        document.getElementById('dataModal').classList.add('flex');
    }
}

function filterErrors(type) {
    const items = document.querySelectorAll('.error-item');
    items.forEach(item => {
        if (type === 'all' || item.dataset.type === type) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update active button
    document.querySelectorAll('.error-filter-btn').forEach(btn => {
        btn.classList.remove('ring-2', 'ring-offset-1');
        if (btn.dataset.filter === type) {
            btn.classList.add('ring-2', 'ring-offset-1');
        }
    });
}

function closeModal() {
    document.getElementById('dataModal').classList.add('hidden');
    document.getElementById('dataModal').classList.remove('flex');
}

// Auto-refresh every 30 seconds
setTimeout(() => location.reload(), 30000);
</script>

<?php include 'includes/footer.php'; ?>
