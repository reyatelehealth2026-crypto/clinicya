<?php
/**
 * ตรวจสอบ LINE Channel Access Token ของแต่ละ Bot
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$host = 'localhost';
$dbname = 'zrismpsz_cny';
$username = 'zrismpsz_cny';
$password = 'zrismpsz_cny';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>ตรวจสอบ Bot Tokens</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    h1 { color: #2196F3; border-bottom: 3px solid #2196F3; padding-bottom: 10px; }
    h2 { color: #333; margin-top: 30px; }
    .ok { color: #4CAF50; font-weight: bold; }
    .error { color: #f44336; font-weight: bold; }
    .warning { color: #ff9800; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; font-size: 13px; }
    th { background-color: #2196F3; color: white; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
    .token { font-family: monospace; font-size: 11px; word-break: break-all; }
    .test-btn { padding: 5px 10px; background: #4CAF50; color: white; border: none; border-radius: 3px; cursor: pointer; }
    .test-btn:hover { background: #45a049; }
</style></head><body><div class='container'>";

echo "<h1>🔍 ตรวจสอบ LINE Bot Tokens</h1>";

// ตรวจสอบโครงสร้างตารางก่อน
try {
    $columns = $db->query("SHOW COLUMNS FROM line_accounts")->fetchAll(PDO::FETCH_COLUMN);
    echo "<div class='info'><strong>Columns in line_accounts:</strong> " . implode(', ', $columns) . "</div>";
} catch (Exception $e) {
    echo "<div class='info'><strong>Error checking columns:</strong> " . $e->getMessage() . "</div>";
}

// ดึงข้อมูล Bot ทั้งหมด - ใช้ชื่อคอลัมน์ที่มีจริง
$stmt = $db->query("SELECT * FROM line_accounts ORDER BY id");
$bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>📊 Bot ทั้งหมดในระบบ</h2>";
echo "<table>";
echo "<tr>
    <th>Bot ID</th>
    <th>ชื่อ Bot</th>
    <th>Token Status</th>
    <th>Token (ตัวอย่าง)</th>
    <th>Webhook URL</th>
    <th>สถานะ</th>
    <th>ทดสอบ</th>
</tr>";

foreach ($bots as $bot) {
    $tokenStatus = '';
    $tokenPreview = '';
    
    // ใช้ชื่อคอลัมน์ที่มีจริง
    $botName = $bot['name'] ?? $bot['bot_name'] ?? $bot['channel_name'] ?? 'Bot #' . $bot['id'];
    
    if (empty($bot['channel_access_token'])) {
        $tokenStatus = '<span class="error">❌ ไม่มี Token</span>';
        $tokenPreview = '-';
    } else {
        $token = $bot['channel_access_token'];
        $tokenLength = strlen($token);
        
        if ($tokenLength < 100) {
            $tokenStatus = '<span class="warning">⚠️ Token สั้นผิดปกติ</span>';
        } else {
            $tokenStatus = '<span class="ok">✅ มี Token</span>';
        }
        
        // แสดง 20 ตัวแรก + ... + 10 ตัวท้าย
        $tokenPreview = substr($token, 0, 20) . '...' . substr($token, -10);
    }
    
    $activeStatus = ($bot['is_active'] ?? 1) ? '<span class="ok">✅ เปิด</span>' : '<span class="error">❌ ปิด</span>';
    $webhookUrl = $bot['webhook_url'] ?? '-';
    
    echo "<tr>";
    echo "<td><strong>{$bot['id']}</strong></td>";
    echo "<td>" . htmlspecialchars($botName) . "</td>";
    echo "<td>{$tokenStatus}</td>";
    echo "<td class='token'>{$tokenPreview}</td>";
    echo "<td class='token'>" . htmlspecialchars($webhookUrl) . "</td>";
    echo "<td>{$activeStatus}</td>";
    echo "<td><button class='test-btn' onclick='testBot({$bot['id']})'>ทดสอบ</button></td>";
    echo "</tr>";
}

echo "</table>";

// ส่วนทดสอบ Token
echo "<h2>🧪 ทดสอบ Token กับ LINE API</h2>";
echo "<div id='test-result'></div>";

// ฟังก์ชันทดสอบ Token
if (isset($_GET['test_bot_id'])) {
    $testBotId = (int)$_GET['test_bot_id'];
    
    $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ?");
    $stmt->execute([$testBotId]);
    $bot = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($bot && !empty($bot['channel_access_token'])) {
        $botName = $bot['name'] ?? $bot['bot_name'] ?? $bot['channel_name'] ?? 'Bot #' . $bot['id'];
        
        echo "<div class='info'>";
        echo "<h3>ทดสอบ Bot ID: {$bot['id']} - {$botName}</h3>";
        
        // ทดสอบ 1: Get Bot Info
        $ch = curl_init('https://api.line.me/v2/bot/info');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $bot['channel_access_token']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "<p><strong>1. ทดสอบ Get Bot Info:</strong></p>";
        
        if ($httpCode === 200) {
            $botInfo = json_decode($response, true);
            echo "<p class='ok'>✅ Token ใช้งานได้!</p>";
            echo "<pre>" . json_encode($botInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        } else {
            echo "<p class='error'>❌ Token ไม่ถูกต้องหรือหมดอายุ!</p>";
            echo "<p>HTTP Code: {$httpCode}</p>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
        }
        
        // ทดสอบ 2: Get Quota
        $ch = curl_init('https://api.line.me/v2/bot/message/quota');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $bot['channel_access_token']
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "<p><strong>2. ทดสอบ Get Message Quota:</strong></p>";
        
        if ($httpCode === 200) {
            $quota = json_decode($response, true);
            echo "<p class='ok'>✅ สามารถเช็ค Quota ได้!</p>";
            echo "<pre>" . json_encode($quota, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        } else {
            echo "<p class='error'>❌ ไม่สามารถเช็ค Quota ได้!</p>";
            echo "<p>HTTP Code: {$httpCode}</p>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
        }
        
        echo "</div>";
    } else {
        echo "<div class='info'><p class='error'>❌ ไม่พบ Bot หรือไม่มี Token</p></div>";
    }
}

echo "<script>
function testBot(botId) {
    window.location.href = '?test_bot_id=' + botId;
}
</script>";

echo "<hr><p><small>Generated: " . date('Y-m-d H:i:s') . "</small></p>";
echo "</div></body></html>";
?>
