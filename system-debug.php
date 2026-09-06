<?php
/**
 * System Debug & Health Check
 * ทดสอบทุกระบบและแสดงสถานะ
 */
session_start();
// config.php first: database.php runs bootstrap/resolve_subdomain.php, which
// needs DB_HOST to look the tenant up. Loaded in the other order the lookup
// threw "Undefined constant DB_HOST" and fell through to the legacy database.
require_once 'config/config.php';
require_once 'config/database.php';

$pageTitle = '🔧 System Debug & Health Check';

// Initialize
$db = Database::getInstance()->getConnection();
$results = [];
$startTime = microtime(true);

// Helper function
function testModule($name, $callback) {
    $start = microtime(true);
    try {
        $result = $callback();
        $time = round((microtime(true) - $start) * 1000, 2);
        return [
            'name' => $name,
            'status' => $result['status'] ?? 'pass',
            'message' => $result['message'] ?? 'OK',
            'details' => $result['details'] ?? [],
            'time' => $time
        ];
    } catch (Exception $e) {
        return [
            'name' => $name,
            'status' => 'fail',
            'message' => $e->getMessage(),
            'details' => [],
            'time' => round((microtime(true) - $start) * 1000, 2)
        ];
    }
}

// ==================== TESTS ====================

// 1. Database Connection
$results[] = testModule('Database Connection', function() use ($db) {
    $version = $db->query("SELECT VERSION()")->fetchColumn();
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    return [
        'status' => 'pass',
        'message' => "Connected to {$dbName}",
        'details' => ['MySQL Version' => $version, 'Database' => $dbName]
    ];
});

// 2. Core Tables
$results[] = testModule('Core Tables', function() use ($db) {
    $tables = ['users', 'messages', 'business_items', 'transactions', 'line_accounts'];
    $found = [];
    $missing = [];
    
    foreach ($tables as $table) {
        try {
            $db->query("SELECT 1 FROM {$table} LIMIT 1");
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $found[$table] = $count;
        } catch (Exception $e) {
            $missing[] = $table;
        }
    }
    
    return [
        'status' => empty($missing) ? 'pass' : 'warning',
        'message' => count($found) . '/' . count($tables) . ' tables found',
        'details' => array_merge($found, $missing ? ['Missing' => implode(', ', $missing)] : [])
    ];
});

// 3. Inventory Tables
$results[] = testModule('Inventory System', function() use ($db) {
    $tables = ['product_batches', 'warehouse_locations', 'stock_by_location', 'inventory_movements'];
    $status = [];
    
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $status[$table] = $count . ' records';
        } catch (Exception $e) {
            $status[$table] = '❌ Missing';
        }
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some tables missing' : 'All tables OK',
        'details' => $status
    ];
});

// 4. WMS System
$results[] = testModule('WMS (Pick/Pack/Ship)', function() use ($db) {
    $tables = ['wms_pick_lists', 'wms_pick_items', 'wms_shipments'];
    $status = [];
    
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $status[$table] = $count . ' records';
        } catch (Exception $e) {
            $status[$table] = '❌ Missing';
        }
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Run migration: /install/run_wms_migration.php' : 'All tables OK',
        'details' => $status
    ];
});

// 5. Procurement System
$results[] = testModule('Procurement (PO/GR)', function() use ($db) {
    $tables = ['suppliers', 'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items'];
    $status = [];
    
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $status[$table] = $count . ' records';
        } catch (Exception $e) {
            $status[$table] = '❌ Missing';
        }
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some tables missing' : 'All tables OK',
        'details' => $status
    ];
});

// 6. Accounting System
$results[] = testModule('Accounting System', function() use ($db) {
    $tables = ['account_receivables', 'account_payables', 'expenses', 'expense_categories', 'receipt_vouchers', 'payment_vouchers'];
    $status = [];
    
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $status[$table] = $count . ' records';
        } catch (Exception $e) {
            $status[$table] = '❌ Missing';
        }
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Run migration: /install/run_accounting_migration.php' : 'All tables OK',
        'details' => $status
    ];
});

// 7. Pharmacy System
$results[] = testModule('Pharmacy System', function() use ($db) {
    $tables = ['pharmacists', 'drug_interactions', 'ai_triage_assessments', 'pharmacist_notifications'];
    $status = [];
    
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $status[$table] = $count . ' records';
        } catch (Exception $e) {
            $status[$table] = '❌ Missing';
        }
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some tables missing' : 'All tables OK',
        'details' => $status
    ];
});

// 8. CRM System
$results[] = testModule('CRM System', function() use ($db) {
    $tables = ['user_tags', 'user_tag_assignments', 'customer_segments', 'points_transactions'];
    $status = [];
    
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $status[$table] = $count . ' records';
        } catch (Exception $e) {
            $status[$table] = '❌ Missing';
        }
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some tables missing' : 'All tables OK',
        'details' => $status
    ];
});


// 9. LINE Integration
$results[] = testModule('LINE Integration', function() use ($db) {
    $status = [];
    
    // Check LINE accounts
    try {
        $stmt = $db->query("SELECT id, name, channel_access_token FROM line_accounts WHERE is_active = 1");
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $status['Active Accounts'] = count($accounts);
        
        foreach ($accounts as $acc) {
            $hasToken = !empty($acc['channel_access_token']);
            $status['Account #' . $acc['id']] = $acc['name'] . ' - ' . ($hasToken ? '✅ Token Set' : '❌ No Token');
        }
    } catch (Exception $e) {
        $status['line_accounts'] = '❌ Table missing';
    }
    
    // Check Rich Menus
    try {
        $count = $db->query("SELECT COUNT(*) FROM rich_menus")->fetchColumn();
        $status['Rich Menus'] = $count . ' menus';
    } catch (Exception $e) {
        $status['Rich Menus'] = '❌ Table missing';
    }
    
    return [
        'status' => 'pass',
        'message' => 'LINE configuration checked',
        'details' => $status
    ];
});

// 10. API Endpoints
$results[] = testModule('API Endpoints', function() {
    $apis = [
        'api/wms.php' => 'WMS API',
        'api/checkout.php' => 'Checkout API',
        'api/shop-products.php' => 'Products API',
        'api/accounting.php' => 'Accounting API',
        'api/inbox.php' => 'Inbox API',
        'api/put-away.php' => 'Put-Away API',
        'api/batches.php' => 'Batches API',
        'api/locations.php' => 'Locations API'
    ];
    
    $status = [];
    foreach ($apis as $file => $name) {
        $status[$name] = file_exists(__DIR__ . '/' . $file) ? '✅ Exists' : '❌ Missing';
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some APIs missing' : 'All APIs available',
        'details' => $status
    ];
});

// 11. Service Classes
$results[] = testModule('Service Classes', function() {
    $classes = [
        'classes/WMSService.php' => 'WMSService',
        'classes/BatchService.php' => 'BatchService',
        'classes/LocationService.php' => 'LocationService',
        'classes/PutAwayService.php' => 'PutAwayService',
        'classes/LineAPI.php' => 'LineAPI',
        'classes/AccountingDashboardService.php' => 'AccountingDashboardService'
    ];
    
    $status = [];
    foreach ($classes as $file => $name) {
        $status[$name] = file_exists(__DIR__ . '/' . $file) ? '✅ Exists' : '❌ Missing';
    }
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some classes missing' : 'All classes available',
        'details' => $status
    ];
});

// 12. AI Module
$results[] = testModule('AI Pharmacy Module', function() {
    $files = [
        'modules/AIChat/Adapters/PharmacyAIAdapter.php' => 'PharmacyAIAdapter',
        'modules/AIChat/Services/TriageEngine.php' => 'TriageEngine',
        'modules/AIChat/Services/PharmacyRAG.php' => 'PharmacyRAG'
    ];
    
    $status = [];
    foreach ($files as $file => $name) {
        $status[$name] = file_exists(__DIR__ . '/' . $file) ? '✅ Exists' : '❌ Missing';
    }
    
    // Check OpenAI config
    $status['OpenAI API Key'] = (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) ? '✅ Configured' : '❌ Not Set';
    
    $hasMissing = strpos(implode('', $status), '❌') !== false;
    return [
        'status' => $hasMissing ? 'warning' : 'pass',
        'message' => $hasMissing ? 'Some components missing' : 'AI Module ready',
        'details' => $status
    ];
});

// 13. File System
$results[] = testModule('File System', function() {
    $dirs = [
        'uploads' => 'Uploads Directory',
        'uploads/products' => 'Product Images',
        'uploads/receipts' => 'Receipt Images',
        'logs' => 'Logs Directory'
    ];
    
    $status = [];
    foreach ($dirs as $dir => $name) {
        $path = __DIR__ . '/' . $dir;
        if (is_dir($path)) {
            $writable = is_writable($path);
            $status[$name] = $writable ? '✅ Writable' : '⚠️ Not Writable';
        } else {
            $status[$name] = '❌ Missing';
        }
    }
    
    // Check error_log - try multiple paths
    $errorLogPaths = [
        '/var/www/vhosts/flexrich.site/logs/cny.re-ya.com/error_log',
        __DIR__ . '/error_log'
    ];
    $errorLogFound = false;
    foreach ($errorLogPaths as $errorLog) {
        if (file_exists($errorLog)) {
            $status['Error Log'] = '✅ ' . number_format(filesize($errorLog) / 1024, 1) . ' KB (' . basename(dirname($errorLog)) . ')';
            $errorLogFound = true;
            break;
        }
    }
    if (!$errorLogFound) {
        $status['Error Log'] = '⚠️ Not found';
    }
    
    return [
        'status' => 'pass',
        'message' => 'File system checked',
        'details' => $status
    ];
});

// 14. PHP Configuration
$results[] = testModule('PHP Configuration', function() {
    return [
        'status' => 'pass',
        'message' => 'PHP ' . PHP_VERSION,
        'details' => [
            'PHP Version' => PHP_VERSION,
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'Upload Max Size' => ini_get('upload_max_filesize'),
            'Post Max Size' => ini_get('post_max_size'),
            'Error Reporting' => ini_get('error_reporting'),
            'Display Errors' => ini_get('display_errors') ? 'On' : 'Off',
            'cURL' => function_exists('curl_init') ? '✅ Enabled' : '❌ Disabled',
            'JSON' => function_exists('json_encode') ? '✅ Enabled' : '❌ Disabled',
            'PDO MySQL' => extension_loaded('pdo_mysql') ? '✅ Enabled' : '❌ Disabled',
            'GD' => extension_loaded('gd') ? '✅ Enabled' : '❌ Disabled'
        ]
    ];
});


// 15. Recent Errors
$results[] = testModule('Recent Errors', function() use ($db) {
    $status = [];
    
    // Check dev_logs
    try {
        $errors = $db->query("SELECT COUNT(*) FROM dev_logs WHERE log_type = 'error' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        $status['Errors (24h)'] = $errors . ' errors';
        
        if ($errors > 0) {
            $recent = $db->query("SELECT source, message, created_at FROM dev_logs WHERE log_type = 'error' ORDER BY created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($recent as $i => $err) {
                $status['Error ' . ($i + 1)] = substr($err['source'] . ': ' . $err['message'], 0, 80) . '...';
            }
        }
    } catch (Exception $e) {
        $status['dev_logs'] = '❌ Table missing';
    }
    
    // Check PHP error log - try multiple paths
    $errorLogPaths = [
        '/var/www/vhosts/flexrich.site/logs/cny.re-ya.com/error_log',
        __DIR__ . '/error_log'
    ];
    foreach ($errorLogPaths as $errorLog) {
        if (file_exists($errorLog)) {
            $lines = file($errorLog);
            $status['PHP Error Log'] = count($lines) . ' lines (' . basename(dirname($errorLog)) . ')';
            break;
        }
    }
    
    return [
        'status' => ($errors ?? 0) > 10 ? 'warning' : 'pass',
        'message' => ($errors ?? 0) . ' errors in last 24 hours',
        'details' => $status
    ];
});

// 16. Database Size
$results[] = testModule('Database Statistics', function() use ($db) {
    $status = [];
    
    // Total size
    $size = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $status['Total Size'] = $size . ' MB';
    
    // Table count
    $tableCount = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $status['Tables'] = $tableCount;
    
    // Largest tables
    $largest = $db->query("SELECT table_name, ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($largest as $t) {
        $status[$t['table_name']] = $t['size_mb'] . ' MB';
    }
    
    return [
        'status' => 'pass',
        'message' => $size . ' MB total, ' . $tableCount . ' tables',
        'details' => $status
    ];
});

// Calculate totals
$totalTime = round((microtime(true) - $startTime) * 1000, 2);
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
$warnCount = count(array_filter($results, fn($r) => $r['status'] === 'warning'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'fail'));

include 'includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">🔧 System Debug & Health Check</h2>
            <p class="text-sm text-gray-500">ทดสอบทุกระบบและแสดงสถานะ</p>
        </div>
        <div class="flex gap-2">
            <button onclick="location.reload()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                <i class="fas fa-sync-alt mr-2"></i>Refresh
            </button>
            <a href="dev-dashboard.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                <i class="fas fa-code mr-2"></i>Dev Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-check-circle text-green-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">Passed</p>
                <p class="text-2xl font-bold text-green-600"><?= $passCount ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-yellow-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">Warnings</p>
                <p class="text-2xl font-bold text-yellow-600"><?= $warnCount ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-times-circle text-red-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">Failed</p>
                <p class="text-2xl font-bold text-red-600"><?= $failCount ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-blue-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">Total Time</p>
                <p class="text-2xl font-bold text-blue-600"><?= $totalTime ?> ms</p>
            </div>
        </div>
    </div>
</div>

<!-- Results -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="px-4 py-3 border-b bg-gray-50">
        <h3 class="font-semibold">Test Results</h3>
    </div>
    <div class="divide-y">
        <?php foreach ($results as $result): ?>
        <?php
        $statusColors = [
            'pass' => 'bg-green-100 text-green-800',
            'warning' => 'bg-yellow-100 text-yellow-800',
            'fail' => 'bg-red-100 text-red-800'
        ];
        $statusIcons = [
            'pass' => 'fa-check-circle text-green-500',
            'warning' => 'fa-exclamation-triangle text-yellow-500',
            'fail' => 'fa-times-circle text-red-500'
        ];
        ?>
        <div class="p-4 hover:bg-gray-50">
            <div class="flex items-center justify-between cursor-pointer" onclick="toggleDetails(this)">
                <div class="flex items-center gap-3">
                    <i class="fas <?= $statusIcons[$result['status']] ?> text-xl"></i>
                    <div>
                        <h4 class="font-medium"><?= htmlspecialchars($result['name']) ?></h4>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($result['message']) ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-2 py-1 rounded text-xs font-medium <?= $statusColors[$result['status']] ?>">
                        <?= strtoupper($result['status']) ?>
                    </span>
                    <span class="text-sm text-gray-400"><?= $result['time'] ?> ms</span>
                    <i class="fas fa-chevron-down text-gray-400 transition-transform"></i>
                </div>
            </div>
            <?php if (!empty($result['details'])): ?>
            <div class="details hidden mt-3 pl-10">
                <div class="bg-gray-50 rounded-lg p-3">
                    <table class="w-full text-sm">
                        <?php foreach ($result['details'] as $key => $value): ?>
                        <tr>
                            <td class="py-1 text-gray-600 w-1/3"><?= htmlspecialchars($key) ?></td>
                            <td class="py-1 font-mono"><?= htmlspecialchars($value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="mt-6 bg-white rounded-xl shadow p-4">
    <h3 class="font-semibold mb-3"><i class="fas fa-bolt text-yellow-500 mr-2"></i>Quick Actions</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <a href="install/run_wms_migration.php" target="_blank" class="px-4 py-3 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 text-center text-sm">
            <i class="fas fa-database mr-2"></i>Run WMS Migration
        </a>
        <a href="install/run_accounting_migration.php" target="_blank" class="px-4 py-3 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 text-center text-sm">
            <i class="fas fa-calculator mr-2"></i>Run Accounting Migration
        </a>
        <a href="install/run_put_away_location_migration.php" target="_blank" class="px-4 py-3 bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100 text-center text-sm">
            <i class="fas fa-warehouse mr-2"></i>Run Put-Away Migration
        </a>
        <a href="install/run_pharmacist_notifications_migration.php" target="_blank" class="px-4 py-3 bg-orange-50 text-orange-700 rounded-lg hover:bg-orange-100 text-center text-sm">
            <i class="fas fa-bell mr-2"></i>Run Pharmacy Migration
        </a>
    </div>
</div>

<script>
function toggleDetails(el) {
    const details = el.parentElement.querySelector('.details');
    const icon = el.querySelector('.fa-chevron-down');
    if (details) {
        details.classList.toggle('hidden');
        icon.classList.toggle('rotate-180');
    }
}
</script>

<?php include 'includes/footer.php'; ?>
