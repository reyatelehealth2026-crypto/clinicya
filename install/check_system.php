<?php
/**
 * System Health Check
 * ตรวจสอบว่าระบบมีทุกอย่างครบหรือยัง
 * 
 * วิธีใช้: เข้า https://yourdomain.com/v1/install/check_system.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>System Health Check</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.success { color: #10B981; } .error { color: #EF4444; } .warning { color: #F59E0B; } .info { color: #3B82F6; }
h1 { color: #1E293B; } h2 { color: #475569; border-bottom: 2px solid #E2E8F0; padding-bottom: 10px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #E2E8F0; }
th { background: #F8FAFC; font-weight: 600; }
.check { color: #10B981; font-weight: bold; } .cross { color: #EF4444; font-weight: bold; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
.badge-success { background: #D1FAE5; color: #065F46; }
.badge-error { background: #FEE2E2; color: #991B1B; }
.badge-warning { background: #FEF3C7; color: #92400E; }
.summary { display: flex; gap: 20px; flex-wrap: wrap; }
.summary-item { flex: 1; min-width: 200px; padding: 20px; border-radius: 12px; text-align: center; }
.summary-item h3 { margin: 0; font-size: 36px; }
.summary-item p { margin: 5px 0 0; color: #64748B; }
.btn { display: inline-block; padding: 10px 20px; background: #10B981; color: white; text-decoration: none; border-radius: 8px; margin: 5px; }
.btn:hover { background: #059669; }
.progress { height: 8px; background: #E2E8F0; border-radius: 4px; overflow: hidden; }
.progress-bar { height: 100%; background: #10B981; transition: width 0.3s; }
</style></head><body>";

echo "<h1>🔍 System Health Check</h1>";

// ============ CONFIG CHECK ============
$configOk = file_exists(__DIR__ . '/../config/config.php');
if (!$configOk) {
    echo "<div class='card' style='background: #FEE2E2;'>";
    echo "<h2 class='error'>❌ Configuration Missing</h2>";
    echo "<p>ไม่พบไฟล์ config/config.php กรุณาสร้างไฟล์ก่อน</p>";
    echo "</div></body></html>";
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    echo "<div class='card' style='background: #FEE2E2;'>";
    echo "<h2 class='error'>❌ Database Connection Failed</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div></body></html>";
    exit;
}

// ============ REQUIRED TABLES ============
$requiredTables = [
    // Core
    'admin_users' => 'Admin Users',
    'users' => 'LINE Users',
    'messages' => 'Chat Messages',
    'line_accounts' => 'LINE Accounts',
    
    // Shop
    'business_items' => 'Products (business_items)',
    'item_categories' => 'Product Categories',
    'transactions' => 'Orders/Transactions',
    'transaction_items' => 'Order Items',
    'cart' => 'Shopping Cart',
    'payment_slips' => 'Payment Slips',
    'shop_settings' => 'Shop Settings',
    
    // CRM
    'user_tags' => 'User Tags',
    'user_tag_assignments' => 'Tag Assignments',
    'loyalty_points' => 'Loyalty Points',
    'points_history' => 'Points History',
    
    // Messaging
    'auto_replies' => 'Auto Replies',
    'broadcast_messages' => 'Broadcast Messages',
    'flex_templates' => 'Flex Templates',
    
    // Medical
    'symptom_assessments' => 'Symptom Assessments',
    'triage_sessions' => 'Triage Sessions',
    'pharmacist_consultations' => 'Pharmacist Consultations',
    
    // Appointments
    'appointments' => 'Appointments',
    'video_calls' => 'Video Calls',
    
    // System
    'settings' => 'System Settings',
    'ai_settings' => 'AI Settings',
    'scheduled_reports' => 'Scheduled Reports',
    'sync_queue' => 'Sync Queue',
    'liff_apps' => 'LIFF Apps',
];

// ============ REQUIRED COLUMNS ============
$requiredColumns = [
    'users' => ['reply_token', 'reply_token_expires', 'line_account_id', 'is_registered', 'loyalty_points'],
    'messages' => ['is_read', 'sent_by', 'line_account_id'],
    'transactions' => ['line_account_id', 'payment_status', 'shipping_tracking'],
    'business_items' => ['line_account_id', 'is_active', 'stock', 'sku'],
];

// ============ REQUIRED FILES ============
$requiredFiles = [
    // Core
    'config/config.php' => 'Configuration',
    'config/database.php' => 'Database Config',
    'index.php' => 'Dashboard',
    'webhook.php' => 'LINE Webhook',
    
    // Classes
    'classes/LineAPI.php' => 'LINE API Class',
    'classes/LineAccountManager.php' => 'LINE Account Manager',
    'classes/FlexTemplates.php' => 'Flex Templates',
    'classes/Database.php' => 'Database Class',
    
    // API
    'api/ai-admin.php' => 'AI Admin API',
    'api/ajax_handler.php' => 'AJAX Handler',
    'api/shop-products.php' => 'Shop Products API',
    'api/appointments.php' => 'Appointments API',
    'api/pharmacy-ai.php' => 'Pharmacy AI API',
    
    // Shop
    'shop/products.php' => 'Products Management',
    'shop/orders.php' => 'Orders Management',
    'shop/order-detail.php' => 'Order Detail',
    
    // LIFF
    'liff-shop.php' => 'LIFF Shop',
    'liff-checkout.php' => 'LIFF Checkout',
    'liff-register.php' => 'LIFF Register',
    'liff-my-orders.php' => 'LIFF My Orders',
    
    // Includes
    'includes/header.php' => 'Header Template',
    'includes/footer.php' => 'Footer Template',
    
    // Cron
    'cron/scheduled_reports.php' => 'Scheduled Reports Cron',
    'cron/appointment_reminder.php' => 'Appointment Reminder',
];

// ============ CHECK TABLES ============
$tableResults = [];
$tablesOk = 0;
$tablesMissing = 0;

foreach ($requiredTables as $table => $name) {
    try {
        $db->query("SELECT 1 FROM `$table` LIMIT 1");
        $tableResults[$table] = ['name' => $name, 'status' => true];
        $tablesOk++;
    } catch (PDOException $e) {
        $tableResults[$table] = ['name' => $name, 'status' => false];
        $tablesMissing++;
    }
}

// ============ CHECK COLUMNS ============
$columnResults = [];
$columnsOk = 0;
$columnsMissing = 0;

foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            if ($stmt->rowCount() > 0) {
                $columnResults["$table.$column"] = true;
                $columnsOk++;
            } else {
                $columnResults["$table.$column"] = false;
                $columnsMissing++;
            }
        } catch (PDOException $e) {
            $columnResults["$table.$column"] = false;
            $columnsMissing++;
        }
    }
}

// ============ CHECK FILES ============
$fileResults = [];
$filesOk = 0;
$filesMissing = 0;

foreach ($requiredFiles as $file => $name) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        $fileResults[$file] = ['name' => $name, 'status' => true];
        $filesOk++;
    } else {
        $fileResults[$file] = ['name' => $name, 'status' => false];
        $filesMissing++;
    }
}

// ============ CHECK SETTINGS ============
$settingsResults = [];
try {
    // Check LINE Account
    $stmt = $db->query("SELECT COUNT(*) FROM line_accounts WHERE is_active = 1");
    $lineAccounts = $stmt->fetchColumn();
    $settingsResults['LINE Accounts'] = $lineAccounts > 0 ? "$lineAccounts active" : false;
    
    // Check Admin Users
    $stmt = $db->query("SELECT COUNT(*) FROM admin_users WHERE is_active = 1");
    $adminUsers = $stmt->fetchColumn();
    $settingsResults['Admin Users'] = $adminUsers > 0 ? "$adminUsers active" : false;
    
    // Check Products
    $stmt = $db->query("SELECT COUNT(*) FROM products WHERE is_active = 1");
    $products = $stmt->fetchColumn();
    $settingsResults['Products'] = "$products items";
    
    // Check AI Settings
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM ai_settings WHERE gemini_api_key IS NOT NULL AND gemini_api_key != ''");
        $aiSettings = $stmt->fetchColumn();
        $settingsResults['AI (Gemini) API'] = $aiSettings > 0 ? 'Configured' : 'Not configured';
    } catch (Exception $e) {
        $settingsResults['AI (Gemini) API'] = 'Table missing';
    }
    
    // Check LIFF Apps
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM liff_apps");
        $liffApps = $stmt->fetchColumn();
        $settingsResults['LIFF Apps'] = "$liffApps configured";
    } catch (Exception $e) {
        $settingsResults['LIFF Apps'] = 'Table missing';
    }
    
} catch (Exception $e) {
    $settingsResults['Error'] = $e->getMessage();
}

// ============ CALCULATE SCORES ============
$totalTables = count($requiredTables);
$totalColumns = array_sum(array_map('count', $requiredColumns));
$totalFiles = count($requiredFiles);

$tableScore = $totalTables > 0 ? round(($tablesOk / $totalTables) * 100) : 0;
$columnScore = $totalColumns > 0 ? round(($columnsOk / $totalColumns) * 100) : 0;
$fileScore = $totalFiles > 0 ? round(($filesOk / $totalFiles) * 100) : 0;
$overallScore = round(($tableScore + $columnScore + $fileScore) / 3);

// ============ DISPLAY RESULTS ============

// Summary
echo "<div class='card'>";
echo "<h2>📊 Overall Status</h2>";
echo "<div class='summary'>";

$overallColor = $overallScore >= 90 ? '#D1FAE5' : ($overallScore >= 70 ? '#FEF3C7' : '#FEE2E2');
echo "<div class='summary-item' style='background: $overallColor;'><h3>$overallScore%</h3><p>Overall Score</p></div>";

$tableColor = $tableScore >= 90 ? '#D1FAE5' : ($tableScore >= 70 ? '#FEF3C7' : '#FEE2E2');
echo "<div class='summary-item' style='background: $tableColor;'><h3>$tablesOk/$totalTables</h3><p>Tables</p></div>";

$columnColor = $columnScore >= 90 ? '#D1FAE5' : ($columnScore >= 70 ? '#FEF3C7' : '#FEE2E2');
echo "<div class='summary-item' style='background: $columnColor;'><h3>$columnsOk/$totalColumns</h3><p>Columns</p></div>";

$fileColor = $fileScore >= 90 ? '#D1FAE5' : ($fileScore >= 70 ? '#FEF3C7' : '#FEE2E2');
echo "<div class='summary-item' style='background: $fileColor;'><h3>$filesOk/$totalFiles</h3><p>Files</p></div>";

echo "</div></div>";

// Tables
echo "<div class='card'>";
echo "<h2>🗄️ Database Tables ($tablesOk/$totalTables)</h2>";
echo "<div class='progress'><div class='progress-bar' style='width: $tableScore%;'></div></div>";
echo "<table><tr><th>Table</th><th>Description</th><th>Status</th></tr>";
foreach ($tableResults as $table => $info) {
    $status = $info['status'] ? "<span class='check'>✓ OK</span>" : "<span class='cross'>✗ Missing</span>";
    echo "<tr><td><code>$table</code></td><td>{$info['name']}</td><td>$status</td></tr>";
}
echo "</table></div>";

// Columns
echo "<div class='card'>";
echo "<h2>📋 Required Columns ($columnsOk/$totalColumns)</h2>";
echo "<div class='progress'><div class='progress-bar' style='width: $columnScore%;'></div></div>";
echo "<table><tr><th>Table.Column</th><th>Status</th></tr>";
foreach ($columnResults as $col => $status) {
    $statusText = $status ? "<span class='check'>✓ OK</span>" : "<span class='cross'>✗ Missing</span>";
    echo "<tr><td><code>$col</code></td><td>$statusText</td></tr>";
}
echo "</table></div>";

// Files
echo "<div class='card'>";
echo "<h2>📁 Required Files ($filesOk/$totalFiles)</h2>";
echo "<div class='progress'><div class='progress-bar' style='width: $fileScore%;'></div></div>";
echo "<table><tr><th>File</th><th>Description</th><th>Status</th></tr>";
foreach ($fileResults as $file => $info) {
    $status = $info['status'] ? "<span class='check'>✓ OK</span>" : "<span class='cross'>✗ Missing</span>";
    echo "<tr><td><code>$file</code></td><td>{$info['name']}</td><td>$status</td></tr>";
}
echo "</table></div>";

// Settings
echo "<div class='card'>";
echo "<h2>⚙️ System Settings</h2>";
echo "<table><tr><th>Setting</th><th>Status</th></tr>";
foreach ($settingsResults as $setting => $value) {
    if ($value === false) {
        $badge = "<span class='badge badge-error'>Not Set</span>";
    } elseif (strpos($value, 'missing') !== false || strpos($value, 'Not') !== false) {
        $badge = "<span class='badge badge-warning'>$value</span>";
    } else {
        $badge = "<span class='badge badge-success'>$value</span>";
    }
    echo "<tr><td>$setting</td><td>$badge</td></tr>";
}
echo "</table></div>";

// Actions
echo "<div class='card'>";
echo "<h2>🛠️ Actions</h2>";
if ($overallScore < 100) {
    echo "<a href='install_all.php' class='btn'>🚀 Run Installation</a>";
}
echo "<a href='check_system.php' class='btn' style='background: #3B82F6;'>🔄 Refresh Check</a>";
echo "<a href='../index.php' class='btn' style='background: #6B7280;'>🏠 Go to Dashboard</a>";
echo "</div>";

// PHP Info
echo "<div class='card'>";
echo "<h2>🐘 PHP Environment</h2>";
echo "<table>";
echo "<tr><td>PHP Version</td><td><span class='badge " . (version_compare(PHP_VERSION, '7.4', '>=') ? 'badge-success' : 'badge-error') . "'>" . PHP_VERSION . "</span></td></tr>";
echo "<tr><td>PDO MySQL</td><td><span class='badge " . (extension_loaded('pdo_mysql') ? 'badge-success' : 'badge-error') . "'>" . (extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled') . "</span></td></tr>";
echo "<tr><td>cURL</td><td><span class='badge " . (extension_loaded('curl') ? 'badge-success' : 'badge-error') . "'>" . (extension_loaded('curl') ? 'Enabled' : 'Disabled') . "</span></td></tr>";
echo "<tr><td>JSON</td><td><span class='badge " . (extension_loaded('json') ? 'badge-success' : 'badge-error') . "'>" . (extension_loaded('json') ? 'Enabled' : 'Disabled') . "</span></td></tr>";
echo "<tr><td>mbstring</td><td><span class='badge " . (extension_loaded('mbstring') ? 'badge-success' : 'badge-warning') . "'>" . (extension_loaded('mbstring') ? 'Enabled' : 'Disabled') . "</span></td></tr>";
echo "<tr><td>Memory Limit</td><td>" . ini_get('memory_limit') . "</td></tr>";
echo "<tr><td>Max Execution Time</td><td>" . ini_get('max_execution_time') . "s</td></tr>";
echo "<tr><td>Upload Max Size</td><td>" . ini_get('upload_max_filesize') . "</td></tr>";
echo "</table></div>";

echo "<p style='text-align: center; color: #94A3B8; margin-top: 20px;'>Generated at " . date('Y-m-d H:i:s') . "</p>";

echo "</body></html>";
