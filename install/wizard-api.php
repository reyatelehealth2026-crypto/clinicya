<?php
/**
 * LINE Telepharmacy CRM - Installation Wizard API
 * Version: 3.2
 * 
 * AJAX API handlers for the installation wizard
 */

header('Content-Type: application/json; charset=utf-8');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(600);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'check_requirements':
            checkRequirements();
            break;

        case 'test_database':
            testDatabase();
            break;

        case 'install':
            runInstallation();
            break;

        case 'test_line_api':
            testLineAPI();
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Check system requirements
 */
function checkRequirements()
{
    $requirements = [];

    // PHP Version
    $requirements[] = [
        'name' => 'PHP Version >= 8.0',
        'passed' => version_compare(PHP_VERSION, '8.0', '>='),
        'current' => PHP_VERSION
    ];

    // PDO MySQL
    $requirements[] = [
        'name' => 'PDO MySQL Extension',
        'passed' => extension_loaded('pdo_mysql')
    ];

    // MySQLi
    $requirements[] = [
        'name' => 'MySQLi Extension',
        'passed' => extension_loaded('mysqli')
    ];

    // cURL
    $requirements[] = [
        'name' => 'cURL Extension',
        'passed' => extension_loaded('curl')
    ];

    // JSON
    $requirements[] = [
        'name' => 'JSON Extension',
        'passed' => extension_loaded('json')
    ];

    // mbstring
    $requirements[] = [
        'name' => 'mbstring Extension',
        'passed' => extension_loaded('mbstring')
    ];

    // OpenSSL
    $requirements[] = [
        'name' => 'OpenSSL Extension',
        'passed' => extension_loaded('openssl')
    ];

    // GD or Imagick
    $requirements[] = [
        'name' => 'GD หรือ Imagick Extension (สำหรับรูปภาพ)',
        'passed' => extension_loaded('gd') || extension_loaded('imagick')
    ];

    // config/ writable
    $configPath = __DIR__ . '/../config';
    $requirements[] = [
        'name' => 'config/ Writable',
        'passed' => is_writable($configPath)
    ];

    // uploads/ writable
    $uploadsPath = __DIR__ . '/../uploads';
    if (!is_dir($uploadsPath)) {
        @mkdir($uploadsPath, 0755, true);
    }
    $requirements[] = [
        'name' => 'uploads/ Writable',
        'passed' => is_dir($uploadsPath) && is_writable($uploadsPath)
    ];

    // Check if database folder exists
    $databasePath = __DIR__ . '/../database';
    $requirements[] = [
        'name' => 'database/ Directory Exists',
        'passed' => is_dir($databasePath)
    ];

    // Check SQL file
    $sqlFile = __DIR__ . '/../database/install_complete_latest.sql';
    $requirements[] = [
        'name' => 'Installation SQL File Exists',
        'passed' => file_exists($sqlFile)
    ];

    jsonResponse([
        'success' => true,
        'requirements' => $requirements
    ]);
}

/**
 * Test database connection
 */
function testDatabase()
{
    $input = json_decode(file_get_contents('php://input'), true);

    $host = $input['host'] ?? 'localhost';
    $name = $input['name'] ?? '';
    $user = $input['user'] ?? '';
    $pass = $input['pass'] ?? '';

    if (empty($name) || empty($user)) {
        jsonResponse(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        return;
    }

    try {
        // Try to connect
        $pdo = new PDO("mysql:host=$host", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if database exists
        $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($name));
        $dbExists = $stmt->fetch();

        if ($dbExists) {
            $message = "เชื่อมต่อสำเร็จ! ฐานข้อมูล '$name' มีอยู่แล้ว";
        } else {
            // Create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $message = "เชื่อมต่อสำเร็จ! สร้างฐานข้อมูล '$name' ใหม่แล้ว";
        }

        // Store in session
        $_SESSION['install_db'] = [
            'host' => $host,
            'name' => $name,
            'user' => $user,
            'pass' => $pass
        ];

        jsonResponse([
            'success' => true,
            'message' => $message
        ]);

    } catch (PDOException $e) {
        jsonResponse([
            'success' => false,
            'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้: ' . $e->getMessage()
        ]);
    }
}

/**
 * Run full installation
 */
function runInstallation()
{
    $input = json_decode(file_get_contents('php://input'), true);

    $db = $input['database'] ?? [];
    $app = $input['app'] ?? [];
    $line = $input['line'] ?? [];
    $admin = $input['admin'] ?? [];

    $logs = [];

    try {
        // Step 1: Connect to database
        $mysqli = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        if ($mysqli->connect_error) {
            throw new Exception("Database connection failed: " . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');
        $mysqli->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $logs[] = "✓ Connected to database: " . $db['name'];

        // Step 2: Run installation SQL
        $sqlFile = __DIR__ . '/../database/install_complete_latest.sql';
        if (!file_exists($sqlFile)) {
            // Try alternative file
            $sqlFile = __DIR__ . '/../database/schema_complete.sql';
        }

        if (file_exists($sqlFile)) {
            $executed = executeSqlFile($mysqli, $sqlFile, $db);
            $logs[] = "✓ Executed main SQL file ($executed statements)";
        } else {
            $logs[] = "⚠️ Main SQL file not found, skipping...";
        }

        // Step 3: Run migrations
        $migrationDir = __DIR__ . '/../database/';
        $migrations = glob($migrationDir . 'migration_*.sql');
        sort($migrations);

        foreach ($migrations as $migration) {
            $executed = executeSqlFile($mysqli, $migration, $db);
            $logs[] = "✓ " . basename($migration) . " ($executed)";
        }

        // Step 4: Create admin user
        $password = password_hash($admin['password'], PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("INSERT INTO admin_users (username, email, password, role, is_active) 
                                  VALUES (?, ?, ?, 'super_admin', 1) 
                                  ON DUPLICATE KEY UPDATE password = VALUES(password)");
        $stmt->bind_param("sss", $admin['username'], $admin['email'], $password);
        $stmt->execute();
        $stmt->close();
        $logs[] = "✓ Created admin user: " . $admin['username'];

        // Step 5: Create LINE account if provided
        if (!empty($line['channelSecret'])) {
            $stmt = $mysqli->prepare("INSERT INTO line_accounts (name, channel_id, channel_secret, channel_access_token, liff_id, is_active, is_default) 
                                      VALUES (?, ?, ?, ?, ?, 1, 1) 
                                      ON DUPLICATE KEY UPDATE channel_access_token = VALUES(channel_access_token)");
            $stmt->bind_param("sssss", $app['name'], $line['channelId'], $line['channelSecret'], $line['accessToken'], $line['liffId']);
            $stmt->execute();
            $stmt->close();
            $logs[] = "✓ Created LINE account";
        } else {
            $logs[] = "⚠️ LINE API not configured (can be done later)";
        }

        $mysqli->close();

        // Step 6: Create config.php
        $configContent = generateConfigFile($db, $app, $line);
        $configPath = __DIR__ . '/../config/config.php';
        file_put_contents($configPath, $configContent);
        $logs[] = "✓ Created config/config.php";

        // Step 7: Create installed.lock
        $lockFile = __DIR__ . '/../config/installed.lock';
        file_put_contents($lockFile, json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'version' => '3.2',
            'admin_email' => $admin['email']
        ], JSON_PRETTY_PRINT));
        $logs[] = "✓ Created installed.lock";

        // Clear session
        unset($_SESSION['install_db'], $_SESSION['install_app']);

        jsonResponse([
            'success' => true,
            'message' => 'Installation completed successfully!',
            'logs' => $logs
        ]);

    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => $e->getMessage(),
            'logs' => $logs
        ]);
    }
}

/**
 * Execute SQL file
 */
function executeSqlFile($mysqli, $filePath, $db)
{
    if (!file_exists($filePath))
        return 0;

    $sql = file_get_contents($filePath);

    // Remove comments and DELIMITER statements
    $sql = preg_replace('/DELIMITER\s+[^\s]+/i', '', $sql);
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Split by semicolon followed by newline
    $statements = preg_split('/;\s*[\r\n]+/', $sql);
    $executed = 0;

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);

        // Skip invalid statements
        if (empty($stmt))
            continue;
        if (strlen($stmt) < 10)
            continue;
        if (strtoupper(trim($stmt)) === 'NULL')
            continue;
        if (stripos($stmt, 'DELIMITER') !== false)
            continue;

        // Must start with valid SQL keyword
        $validStarts = ['CREATE', 'ALTER', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'SET', 'USE', 'TRUNCATE', 'REPLACE'];
        $isValid = false;
        $stmtUpper = strtoupper(ltrim($stmt));
        foreach ($validStarts as $keyword) {
            if (strpos($stmtUpper, $keyword) === 0) {
                $isValid = true;
                break;
            }
        }
        if (!$isValid)
            continue;

        try {
            $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
            $conn->set_charset('utf8mb4');
            $conn->query($stmt);
            $executed++;
            $conn->close();
        } catch (Exception $e) {
            // Ignore errors and continue
        }
    }

    return $executed;
}

/**
 * Generate config.php content
 */
function generateConfigFile($db, $app, $line)
{
    $date = date('Y-m-d H:i:s');
    $encryptionKey = bin2hex(random_bytes(16));

    return "<?php
/**
 * LINE Telepharmacy CRM - Configuration
 * Generated by Installation Wizard on {$date}
 * Version: 3.2
 */

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('{$app['timezone']}');

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', '{$db['host']}');
define('DB_NAME', '{$db['name']}');
define('DB_USER', '{$db['user']}');
define('DB_PASS', '{$db['pass']}');

// ============================================
// APPLICATION CONFIGURATION
// ============================================
define('APP_NAME', '{$app['name']}');
define('APP_URL', '{$app['url']}');
define('BASE_URL', '{$app['url']}');
define('TIMEZONE', '{$app['timezone']}');

// ============================================
// LINE API CONFIGURATION
// ============================================
define('LINE_CHANNEL_ID', '{$line['channelId']}');
define('LINE_CHANNEL_SECRET', '{$line['channelSecret']}');
define('LINE_CHANNEL_ACCESS_TOKEN', '{$line['accessToken']}');
define('LIFF_ID', '{$line['liffId']}');

// ============================================
// SECURITY
// ============================================
define('ENCRYPTION_KEY', '{$encryptionKey}');
define('SESSION_LIFETIME', 7200); // 2 hours

// ============================================
// TABLE NAMES
// ============================================
define('TABLE_USERS', 'users');
define('TABLE_GROUPS', 'groups');

// ============================================
// UPLOAD SETTINGS
// ============================================
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp']);

// ============================================
// DEBUG MODE
// ============================================
define('DEBUG_MODE', " . ($app['debug'] ? 'true' : 'false') . ");

// ============================================
// INITIALIZATION
// ============================================
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Error reporting
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
";
}

/**
 * Test LINE API connection
 */
function testLineAPI()
{
    $input = json_decode(file_get_contents('php://input'), true);

    $accessToken = $input['accessToken'] ?? '';

    if (empty($accessToken)) {
        jsonResponse(['success' => false, 'message' => 'Access Token is required']);
        return;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.line.me/v2/bot/info');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        jsonResponse([
            'success' => true,
            'message' => 'Connected to LINE Bot: ' . ($data['displayName'] ?? 'Unknown'),
            'data' => $data
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Failed to connect to LINE API (HTTP ' . $httpCode . ')'
        ]);
    }
}

/**
 * JSON Response helper
 */
function jsonResponse($data)
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
