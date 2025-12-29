<?php
/**
 * Test LINE Push Message
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Test LINE Push Message</h2>";

// 1. Get LINE account
echo "<h3>1. LINE Account</h3>";
try {
    $stmt = $db->query("SELECT id, name, channel_access_token FROM line_accounts WHERE is_active = 1 LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account) {
        echo "Account ID: " . $account['id'] . "<br>";
        echo "Account Name: " . $account['name'] . "<br>";
        echo "Token exists: " . (!empty($account['channel_access_token']) ? 'Yes (' . strlen($account['channel_access_token']) . ' chars)' : 'No') . "<br>";
    } else {
        echo "❌ No active LINE account found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 2. Get user
echo "<h3>2. User Info</h3>";
try {
    $stmt = $db->query("SELECT id, display_name, line_user_id FROM users WHERE id = 28");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "User ID: " . $user['id'] . "<br>";
        echo "Display Name: " . $user['display_name'] . "<br>";
        echo "LINE User ID: " . ($user['line_user_id'] ?? 'N/A') . "<br>";
    } else {
        echo "❌ User not found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 3. Test PharmacistNotifier
echo "<h3>3. Test PharmacistNotifier</h3>";
try {
    require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
    $notifier = new \Modules\AIChat\Services\PharmacistNotifier();
    echo "✅ PharmacistNotifier created<br>";
    
    // Try to send a test message
    if (isset($_GET['send']) && $user && !empty($user['line_user_id'])) {
        echo "<br><strong>Sending test message...</strong><br>";
        $result = $notifier->sendToCustomer(28, "🧪 ทดสอบส่งข้อความจาก Pharmacist Dashboard\n\nเวลา: " . date('Y-m-d H:i:s'));
        echo "Result: " . ($result ? '✅ Success' : '❌ Failed') . "<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 4. Manual test
echo "<h3>4. Manual Test</h3>";
if (!isset($_GET['send'])) {
    echo "<a href='?send=1' class='btn' style='background:#10b981;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>📤 ส่งข้อความทดสอบ</a>";
}

// 5. Check error log
echo "<h3>5. Recent Error Log</h3>";
$errorLog = __DIR__ . '/../error_log';
if (file_exists($errorLog)) {
    $lines = array_slice(file($errorLog), -20);
    $relevantLines = array_filter($lines, function($line) {
        return stripos($line, 'LINE') !== false || stripos($line, 'Pharmacist') !== false || stripos($line, 'Push') !== false;
    });
    if (!empty($relevantLines)) {
        echo "<pre style='background:#f3f4f6;padding:10px;font-size:11px;max-height:200px;overflow:auto;'>";
        echo htmlspecialchars(implode('', array_slice($relevantLines, -10)));
        echo "</pre>";
    } else {
        echo "No relevant errors found<br>";
    }
} else {
    echo "Error log not found<br>";
}
