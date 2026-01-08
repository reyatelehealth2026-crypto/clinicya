<?php
/**
 * LINE CRM Pharmacy - Fresh Installation Script
 * 
 * สคริปต์ติดตั้งระบบใหม่ทั้งหมด
 * รันครั้งเดียวแล้วลบไฟล์นี้ทิ้ง!
 */

// Start session FIRST before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

// Security: ต้องมี key ถึงจะรันได้
$installKey = $_GET['key'] ?? '';
$requiredKey = 'FRESH_' . date('Ymd');

$step = $_GET['step'] ?? 1;
$step = (int)$step;

// Handle POST redirects before any output
if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbName = $_POST['db_name'] ?? '';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';
    
    try {
        $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $_SESSION['install_db'] = [
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass
        ];
        
        header('Location: ?step=3');
        exit;
    } catch (PDOException $e) {
        $_SESSION['db_error'] = 'Database Error: ' . $e->getMessage();
    }
}

if ($step == 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['install_db'])) {
        header('Location: ?step=2');
        exit;
    }
    
    $_SESSION['install_app'] = [
        'app_name' => $_POST['app_name'] ?? 'LINE CRM Pharmacy',
        'app_url' => rtrim($_POST['app_url'] ?? '', '/'),
        'admin_user' => $_POST['admin_user'] ?? 'admin',
        'admin_pass' => $_POST['admin_pass'] ?? '',
        'admin_email' => $_POST['admin_email'] ?? ''
    ];
    
    if (strlen($_SESSION['install_app']['admin_pass']) < 6) {
        $_SESSION['app_error'] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } else {
        header('Location: ?step=4');
        exit;
    }
}

// Redirect checks
if ($step == 3 && empty($_SESSION['install_db'])) {
    header('Location: ?step=2');
    exit;
}

if ($step == 4 && (empty($_SESSION['install_db']) || empty($_SESSION['install_app']))) {
    header('Location: ?step=2');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LINE CRM Pharmacy - Fresh Installation</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { padding: 30px; }
        .steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 0 20px;
        }
        .step-item {
            text-align: center;
            flex: 1;
            position: relative;
        }
        .step-item::after {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #E5E7EB;
        }
        .step-item:last-child::after { display: none; }
        .step-num {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #E5E7EB;
            color: #6B7280;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 1;
        }
        .step-item.active .step-num { background: #10B981; color: white; }
        .step-item.done .step-num { background: #059669; color: white; }
        .step-label { font-size: 12px; color: #6B7280; margin-top: 5px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #374151; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #10B981;
        }
        .form-group small { color: #6B7280; font-size: 12px; }
        
        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: #10B981;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover { background: #059669; }
        .btn-secondary { background: #6B7280; }
        .btn-secondary:hover { background: #4B5563; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #D1FAE5; color: #065F46; }
        .alert-error { background: #FEE2E2; color: #991B1B; }
        .alert-warning { background: #FEF3C7; color: #92400E; }
        .alert-info { background: #DBEAFE; color: #1E40AF; }
        
        .check-list { list-style: none; padding: 0; }
        .check-list li { padding: 10px 0; border-bottom: 1px solid #E5E7EB; display: flex; align-items: center; }
        .check-list li:last-child { border-bottom: none; }
        .check-icon { width: 24px; height: 24px; margin-right: 10px; }
        .check-ok { color: #10B981; }
        .check-fail { color: #EF4444; }
        
        pre {
            background: #1F2937;
            color: #10B981;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏥 LINE CRM Pharmacy</h1>
            <p>Fresh Installation Wizard</p>
        </div>
        
        <div class="content">
            <div class="steps">
                <div class="step-item <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">
                    <div class="step-num">1</div>
                    <div class="step-label">ตรวจสอบ</div>
                </div>
                <div class="step-item <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">
                    <div class="step-num">2</div>
                    <div class="step-label">Database</div>
                </div>
                <div class="step-item <?= $step >= 3 ? ($step > 3 ? 'done' : 'active') : '' ?>">
                    <div class="step-num">3</div>
                    <div class="step-label">Config</div>
                </div>
                <div class="step-item <?= $step >= 4 ? ($step > 4 ? 'done' : 'active') : '' ?>">
                    <div class="step-num">4</div>
                    <div class="step-label">ติดตั้ง</div>
                </div>
                <div class="step-item <?= $step >= 5 ? 'active' : '' ?>">
                    <div class="step-num">5</div>
                    <div class="step-label">เสร็จสิ้น</div>
                </div>
            </div>

            <?php
            // ============ STEP 1: System Check ============
            if ($step == 1):
                $checks = [
                    'PHP Version >= 8.0' => version_compare(PHP_VERSION, '8.0', '>='),
                    'PDO MySQL Extension' => extension_loaded('pdo_mysql'),
                    'cURL Extension' => extension_loaded('curl'),
                    'JSON Extension' => extension_loaded('json'),
                    'mbstring Extension' => extension_loaded('mbstring'),
                    'OpenSSL Extension' => extension_loaded('openssl'),
                    'uploads/ Writable' => is_writable(__DIR__ . '/../uploads') || @mkdir(__DIR__ . '/../uploads', 0755, true),
                    'config/ Writable' => is_writable(__DIR__ . '/../config'),
                ];
                $allPassed = !in_array(false, $checks);
            ?>
                <h2>📋 Step 1: System Requirements Check</h2>
                <ul class="check-list">
                    <?php foreach ($checks as $name => $passed): ?>
                    <li>
                        <span class="check-icon <?= $passed ? 'check-ok' : 'check-fail' ?>">
                            <?= $passed ? '✓' : '✗' ?>
                        </span>
                        <?= $name ?>
                        <span style="margin-left: auto; color: <?= $passed ? '#10B981' : '#EF4444' ?>">
                            <?= $passed ? 'OK' : 'FAILED' ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if ($allPassed): ?>
                    <div class="alert alert-success">✅ ระบบพร้อมสำหรับการติดตั้ง</div>
                    <a href="?step=2" class="btn">ถัดไป →</a>
                <?php else: ?>
                    <div class="alert alert-error">❌ กรุณาแก้ไขปัญหาข้างต้นก่อนดำเนินการต่อ</div>
                <?php endif; ?>

            <?php
            // ============ STEP 2: Database Config ============
            elseif ($step == 2):
                $error = $_SESSION['db_error'] ?? '';
                unset($_SESSION['db_error']);
            ?>
                <h2>🗄️ Step 2: Database Configuration</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Database Host</label>
                            <input type="text" name="db_host" value="localhost" required>
                        </div>
                        <div class="form-group">
                            <label>Database Name</label>
                            <input type="text" name="db_name" placeholder="linecrm" required>
                            <small>จะสร้างให้อัตโนมัติถ้ายังไม่มี</small>
                        </div>
                        <div class="form-group">
                            <label>Database Username</label>
                            <input type="text" name="db_user" required>
                        </div>
                        <div class="form-group">
                            <label>Database Password</label>
                            <input type="password" name="db_pass">
                        </div>
                    </div>
                    <a href="?step=1" class="btn btn-secondary">← ย้อนกลับ</a>
                    <button type="submit" class="btn">ทดสอบและถัดไป →</button>
                </form>

            <?php
            // ============ STEP 3: App Config ============
            elseif ($step == 3):
                $error = $_SESSION['app_error'] ?? '';
                unset($_SESSION['app_error']);
                
                // Auto-detect URL
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $path = dirname(dirname($_SERVER['REQUEST_URI']));
                $autoUrl = $protocol . '://' . $host . $path;
            ?>
                <h2>⚙️ Step 3: Application Configuration</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Application Name</label>
                            <input type="text" name="app_name" value="LINE CRM Pharmacy" required>
                        </div>
                        <div class="form-group">
                            <label>Application URL</label>
                            <input type="url" name="app_url" value="<?= htmlspecialchars($autoUrl) ?>" required>
                            <small>URL หลักของระบบ (ไม่ต้องมี / ท้าย)</small>
                        </div>
                    </div>
                    
                    <h3>👤 Admin Account</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Admin Username</label>
                            <input type="text" name="admin_user" value="admin" required>
                        </div>
                        <div class="form-group">
                            <label>Admin Email</label>
                            <input type="email" name="admin_email" placeholder="admin@example.com" required>
                        </div>
                        <div class="form-group">
                            <label>Admin Password</label>
                            <input type="password" name="admin_pass" minlength="6" required>
                            <small>อย่างน้อย 6 ตัวอักษร</small>
                        </div>
                    </div>
                    
                    <a href="?step=2" class="btn btn-secondary">← ย้อนกลับ</a>
                    <button type="submit" class="btn">ถัดไป →</button>
                </form>

            <?php
            // ============ STEP 4: Install ============
            elseif ($step == 4):
                $db = $_SESSION['install_db'];
                $app = $_SESSION['install_app'];
                $logs = [];
                $success = true;
                
                try {
                    // Use mysqli for better multi-query support
                    $mysqli = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
                    if ($mysqli->connect_error) {
                        throw new Exception("Connection failed: " . $mysqli->connect_error);
                    }
                    $mysqli->set_charset('utf8mb4');
                    $mysqli->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $logs[] = "✓ Connected to database (UTF-8)";
                    
                    // Function to execute SQL file safely using mysqli
                    $executeSqlFile = function($filePath) use ($mysqli, $db) {
                        if (!file_exists($filePath)) return 0;
                        
                        $sql = file_get_contents($filePath);
                        
                        // Remove DELIMITER statements and stored procedures
                        $sql = preg_replace('/DELIMITER\s+[^\s]+/i', '', $sql);
                        // Remove single-line comments
                        $sql = preg_replace('/--.*$/m', '', $sql);
                        // Remove multi-line comments
                        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
                        
                        // Split by semicolon followed by newline
                        $statements = preg_split('/;\s*[\r\n]+/', $sql);
                        $executed = 0;
                        
                        foreach ($statements as $stmt) {
                            $stmt = trim($stmt);
                            
                            // Skip empty or invalid statements
                            if (empty($stmt)) continue;
                            if (strlen($stmt) < 10) continue; // Too short to be valid SQL
                            if (strtoupper(trim($stmt)) === 'NULL') continue;
                            if (preg_match('/^\s*NULL\s*$/i', $stmt)) continue;
                            if (stripos($stmt, 'DELIMITER') !== false) continue;
                            
                            // Must start with valid SQL keyword
                            $validStarts = ['CREATE', 'ALTER', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'SET', 'USE', 'TRUNCATE', 'REPLACE', 'CALL'];
                            $isValid = false;
                            $stmtUpper = strtoupper(ltrim($stmt));
                            foreach ($validStarts as $keyword) {
                                if (strpos($stmtUpper, $keyword) === 0) {
                                    $isValid = true;
                                    break;
                                }
                            }
                            if (!$isValid) continue;
                            
                            // Create fresh connection for each statement to avoid buffering issues
                            try {
                                $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
                                if ($conn->connect_error) continue;
                                $conn->set_charset('utf8mb4');
                                $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                                
                                $conn->query($stmt);
                                $executed++;
                                
                                // Close and free results
                                while ($conn->more_results() && $conn->next_result()) {
                                    if ($result = $conn->store_result()) {
                                        $result->free();
                                    }
                                }
                                $conn->close();
                            } catch (Exception $e) {
                                // Ignore errors and continue
                            }
                        }
                        return $executed;
                    };
                    
                    // Run install.sql
                    $installSql = __DIR__ . '/../database/install.sql';
                    if (file_exists($installSql)) {
                        $executed = $executeSqlFile($installSql);
                        $logs[] = "✓ Executed install.sql ($executed statements)";
                    }
                    
                    // Run schema_complete.sql if exists
                    $schemaSql = __DIR__ . '/../database/schema_complete.sql';
                    if (file_exists($schemaSql)) {
                        $executed = $executeSqlFile($schemaSql);
                        $logs[] = "✓ Executed schema_complete.sql ($executed statements)";
                    }
                    
                    // Run all migrations
                    $migrationDir = __DIR__ . '/../database/';
                    $migrations = glob($migrationDir . 'migration_*.sql');
                    sort($migrations);
                    
                    foreach ($migrations as $migration) {
                        $executed = $executeSqlFile($migration);
                        $logs[] = "✓ " . basename($migration) . " ($executed)";
                    }
                    
                    // Create admin user using fresh connection
                    try {
                        $adminConn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
                        if (!$adminConn->connect_error) {
                            $adminConn->set_charset('utf8mb4');
                            $password = password_hash($app['admin_pass'], PASSWORD_DEFAULT);
                            $stmt = $adminConn->prepare("INSERT INTO admin_users (username, email, password, role, is_active) VALUES (?, ?, ?, 'super_admin', 1) ON DUPLICATE KEY UPDATE password = VALUES(password)");
                            if ($stmt) {
                                $stmt->bind_param("sss", $app['admin_user'], $app['admin_email'], $password);
                                $stmt->execute();
                                $stmt->close();
                            }
                            $adminConn->close();
                            $logs[] = "✓ Created admin user: {$app['admin_user']}";
                        }
                    } catch (Exception $e) {
                        $logs[] = "⚠️ Admin user: " . $e->getMessage();
                    }
                    
                    $mysqli->close();
                    
                    // Create config.php
                    $configContent = "<?php
/**
 * LINE CRM Pharmacy - Configuration
 * Generated by Installation Wizard on " . date('Y-m-d H:i:s') . "
 */

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database
define('DB_HOST', '{$db['host']}');
define('DB_NAME', '{$db['name']}');
define('DB_USER', '{$db['user']}');
define('DB_PASS', '{$db['pass']}');

// Application
define('APP_NAME', '{$app['app_name']}');
define('APP_URL', '{$app['app_url']}');
define('BASE_URL', '{$app['app_url']}');
define('TIMEZONE', 'Asia/Bangkok');

// LINE API (ตั้งค่าผ่านหน้า Admin)
define('LINE_CHANNEL_ACCESS_TOKEN', '');
define('LINE_CHANNEL_SECRET', '');

// Table Names
define('TABLE_USERS', 'users');
define('TABLE_GROUPS', 'groups');

date_default_timezone_set(TIMEZONE);
";
                    file_put_contents(__DIR__ . '/../config/config.php', $configContent);
                    $logs[] = "✓ Created config/config.php";
                    
                    // Create installed.lock
                    file_put_contents(__DIR__ . '/../config/installed.lock', date('Y-m-d H:i:s'));
                    $logs[] = "✓ Created installed.lock";
                    
                    // Clear session
                    unset($_SESSION['install_db'], $_SESSION['install_app']);
                    
                } catch (Exception $e) {
                    $success = false;
                    $logs[] = "✗ Error: " . $e->getMessage();
                }
            ?>
                <h2>📦 Step 4: Installing...</h2>
                
                <pre><?= implode("\n", $logs) ?></pre>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        ✅ ติดตั้งเสร็จสมบูรณ์!
                    </div>
                    <a href="?step=5" class="btn">ดูสรุป →</a>
                <?php else: ?>
                    <div class="alert alert-error">
                        ❌ เกิดข้อผิดพลาดในการติดตั้ง กรุณาตรวจสอบ log ด้านบน
                    </div>
                    <a href="?step=2" class="btn btn-secondary">← ลองใหม่</a>
                <?php endif; ?>

            <?php
            // ============ STEP 5: Complete ============
            elseif ($step == 5):
            ?>
                <h2>🎉 Installation Complete!</h2>
                
                <div class="alert alert-success">
                    <strong>ติดตั้งระบบเสร็จสมบูรณ์แล้ว!</strong>
                </div>
                
                <h3>📋 ขั้นตอนถัดไป:</h3>
                <ol style="line-height: 2;">
                    <li><strong>ลบไฟล์ติดตั้ง</strong> - ลบโฟลเดอร์ <code>install/</code> เพื่อความปลอดภัย</li>
                    <li><strong>ตั้งค่า LINE Account</strong> - เพิ่ม LINE OA ในหน้า Admin</li>
                    <li><strong>ตั้งค่า Webhook</strong> - ใส่ URL: <code><?= htmlspecialchars(dirname(dirname($_SERVER['REQUEST_URI'])) . '/webhook.php') ?></code></li>
                    <li><strong>ตั้งค่า LIFF</strong> - สร้าง LIFF Apps ใน LINE Developers Console</li>
                    <li><strong>ตั้งค่า AI</strong> - ใส่ Gemini API Key ในหน้า AI Settings</li>
                </ol>
                
                <div style="margin-top: 30px;">
                    <a href="../admin/" class="btn">🏠 ไปหน้า Dashboard</a>
                    <a href="check_system.php" class="btn btn-secondary">🔍 ตรวจสอบระบบ</a>
                </div>
                
                <div class="alert alert-warning" style="margin-top: 20px;">
                    ⚠️ <strong>สำคัญ:</strong> กรุณาลบโฟลเดอร์ <code>install/</code> หลังติดตั้งเสร็จ!
                </div>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>
