<?php
/**
 * ทดสอบส่งข้อความไปยัง Bot ID 3 และตรวจสอบ webhook logs
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'zrismpsz_cny';
$username = 'zrismpsz_cny';
$password = 'zrismpsz_cny';

try {
    $db = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage());
}

// ดึงข้อมูล Bot ID 3
$stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = 3");
$stmt->execute();
$bot3 = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bot3) {
    die("❌ ไม่พบ Bot ID 3");
}

$token = $bot3['channel_access_token'];
$testUserId = 'Ua1156d646cad2237e878457833bc07b3';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ทดสอบ Bot ID 3 Webhook</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #06C755; }
        .ok { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #06C755; color: white; }
        .btn { padding: 10px 20px; background: #06C755; color: white; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #05b04b; }
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 ทดสอบ Bot ID 3 Webhook</h1>
    
    <div class="info">
        <h3>📋 ขั้นตอนทดสอบ:</h3>
        <ol>
            <li>กดปุ่ม "ส่งข้อความทดสอบ" ด้านล่าง</li>
            <li>ส่งข้อความไปยัง Bot ID 3 บน LINE</li>
            <li>กลับมาดูหน้านี้อีกครั้ง (รีเฟรช)</li>
            <li>ตรวจสอบว่ามี logs ใหม่หรือไม่</li>
        </ol>
    </div>
    
    <?php
    if (isset($_POST['send_test'])) {
        echo "<h2>📤 กำลังส่งข้อความทดสอบ...</h2>";
        
        $testMessage = "🧪 ทดสอบ Bot ID 3\n\nเวลา: " . date('Y-m-d H:i:s') . "\n\nกรุณาตอบกลับข้อความนี้";
        
        $data = [
            'to' => $testUserId,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $testMessage
                ]
            ]
        ];
        
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            echo "<p class='ok'>✅ ส่งข้อความสำเร็จ!</p>";
            echo "<p><strong>ขั้นตอนถัดไป:</strong></p>";
            echo "<ol>";
            echo "<li>เปิด LINE และตอบกลับข้อความที่ Bot ส่งมา</li>";
            echo "<li>รอ 5 วินาที</li>";
            echo "<li>กลับมารีเฟรชหน้านี้เพื่อดู logs</li>";
            echo "</ol>";
        } else {
            echo "<p class='error'>❌ ส่งข้อความไม่สำเร็จ! HTTP Code: {$httpCode}</p>";
            echo "<pre>" . htmlspecialchars($response) . "</pre>";
        }
        
        echo "<hr>";
    }
    ?>
    
    <form method="POST">
        <button type="submit" name="send_test" class="btn">📤 ส่งข้อความทดสอบ</button>
    </form>
    
    <h2>📝 Recent Logs (ล่าสุด 20 รายการ)</h2>
    
    <?php
    // ดึง logs ทั้งหมดที่เกี่ยวข้องกับ Bot ID 3
    $stmt = $db->query("
        SELECT * FROM dev_logs 
        WHERE 
            data LIKE '%line_account_id\":3%' 
            OR data LIKE '%bot_id\":3%'
            OR (source = 'webhook' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($logs) {
        echo "<p class='ok'>✅ พบ " . count($logs) . " logs</p>";
        echo "<table>";
        echo "<tr><th>Time</th><th>Type</th><th>Source</th><th>Message</th><th>Data</th></tr>";
        foreach ($logs as $log) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
            echo "<td>" . htmlspecialchars($log['log_type']) . "</td>";
            echo "<td>" . htmlspecialchars($log['source']) . "</td>";
            echo "<td>" . htmlspecialchars(mb_substr($log['message'], 0, 50)) . "</td>";
            echo "<td><pre>" . htmlspecialchars(mb_substr($log['data'], 0, 200)) . "...</pre></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ ไม่พบ logs ของ Bot ID 3</p>";
        echo "<p>ลองทำตามขั้นตอนนี้:</p>";
        echo "<ol>";
        echo "<li>กดปุ่ม 'ส่งข้อความทดสอบ' ด้านบน</li>";
        echo "<li>เปิด LINE และตอบกลับข้อความ</li>";
        echo "<li>รีเฟรชหน้านี้</li>";
        echo "</ol>";
    }
    ?>
    
    <h2>👥 Users ที่มี Reply Token (ล่าสุด 10 คน)</h2>
    
    <?php
    $stmt = $db->query("
        SELECT 
            id,
            line_user_id,
            display_name,
            reply_token,
            reply_token_expires,
            TIMESTAMPDIFF(SECOND, NOW(), reply_token_expires) as seconds_left
        FROM users 
        WHERE reply_token IS NOT NULL 
        ORDER BY reply_token_expires DESC
        LIMIT 10
    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($users) {
        echo "<p class='ok'>✅ พบ " . count($users) . " users ที่มี reply token</p>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Token</th><th>Expires</th><th>Seconds Left</th></tr>";
        foreach ($users as $user) {
            $statusClass = ($user['seconds_left'] > 0) ? 'ok' : 'error';
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>" . htmlspecialchars($user['display_name']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($user['reply_token'], 0, 20)) . "...</td>";
            echo "<td>" . htmlspecialchars($user['reply_token_expires']) . "</td>";
            echo "<td class='{$statusClass}'>" . $user['seconds_left'] . "s</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ ไม่มี users ที่มี reply token</p>";
        echo "<p>นี่หมายความว่า:</p>";
        echo "<ul>";
        echo "<li>ไม่มี user ส่งข้อความมาใหม่ๆ</li>";
        echo "<li>หรือ webhook ไม่ได้รับ event จาก LINE</li>";
        echo "</ul>";
    }
    ?>
    
    <h2>🔧 ตรวจสอบ Webhook URL</h2>
    
    <div class="info">
        <p><strong>Webhook URL ที่ถูกต้องสำหรับ Bot ID 3:</strong></p>
        <pre>https://cny.re-ya.com/webhook.php?account=3</pre>
        
        <p><strong>วิธีตรวจสอบ:</strong></p>
        <ol>
            <li>ไปที่ <a href="https://developers.line.biz/console/" target="_blank">LINE Developers Console</a></li>
            <li>เลือก Provider และ Channel ของ Bot ID 3</li>
            <li>ไปที่แท็บ "Messaging API"</li>
            <li>ตรวจสอบ "Webhook URL" ว่าตรงกับด้านบนหรือไม่</li>
            <li>ตรวจสอบว่า "Use webhook" เปิดอยู่</li>
            <li>กด "Verify" เพื่อทดสอบ webhook</li>
        </ol>
    </div>
    
    <hr>
    <p><small>Generated: <?= date('Y-m-d H:i:s') ?></small></p>
</div>
</body>
</html>
