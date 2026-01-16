<?php
/**
 * วินิจฉัยปัญหา Bot ID 3 - ทำไมส่งข้อความไม่ถึง LINE
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// เชื่อมต่อ Database
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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>วินิจฉัย Bot ID 3</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #06C755; }
        h2 { color: #333; border-bottom: 2px solid #06C755; padding-bottom: 10px; margin-top: 30px; }
        .ok { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #06C755; color: white; }
        .test-btn { padding: 10px 20px; background: #06C755; color: white; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .test-btn:hover { background: #05b04b; }
        .section { margin: 30px 0; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 วินิจฉัยปัญหา Bot ID 3</h1>
    
    <?php
    // ดึงข้อมูล Bot ID 3
    $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = 3");
    $stmt->execute();
    $bot3 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bot3) {
        echo "<p class='error'>❌ ไม่พบ Bot ID 3 ในระบบ!</p>";
        exit;
    }
    
    // ดึงข้อมูล Bot ID 4 เพื่อเปรียบเทียบ
    $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = 4");
    $stmt->execute();
    $bot4 = $stmt->fetch(PDO::FETCH_ASSOC);
    ?>
    
    <div class="section">
        <h2>📊 ข้อมูล Bot ID 3</h2>
        <table>
            <tr>
                <th>Field</th>
                <th>Value</th>
                <th>Status</th>
            </tr>
            <tr>
                <td>ID</td>
                <td><?= $bot3['id'] ?></td>
                <td class="ok">✅</td>
            </tr>
            <tr>
                <td>Name</td>
                <td><?= htmlspecialchars($bot3['name'] ?? 'N/A') ?></td>
                <td><?= !empty($bot3['name']) ? "<span class='ok'>✅</span>" : "<span class='error'>❌</span>" ?></td>
            </tr>
            <tr>
                <td>Channel ID</td>
                <td><?= htmlspecialchars($bot3['channel_id'] ?? 'N/A') ?></td>
                <td><?= !empty($bot3['channel_id']) ? "<span class='ok'>✅</span>" : "<span class='error'>❌</span>" ?></td>
            </tr>
            <tr>
                <td>Channel Secret</td>
                <td><?= !empty($bot3['channel_secret']) ? substr($bot3['channel_secret'], 0, 20) . '...' : 'N/A' ?></td>
                <td><?= !empty($bot3['channel_secret']) ? "<span class='ok'>✅</span>" : "<span class='error'>❌</span>" ?></td>
            </tr>
            <tr>
                <td>Access Token</td>
                <td><?= !empty($bot3['channel_access_token']) ? substr($bot3['channel_access_token'], 0, 30) . '...' : 'N/A' ?></td>
                <td><?= !empty($bot3['channel_access_token']) ? "<span class='ok'>✅</span>" : "<span class='error'>❌</span>" ?></td>
            </tr>
            <tr>
                <td>Basic ID</td>
                <td><?= htmlspecialchars($bot3['basic_id'] ?? 'N/A') ?></td>
                <td><?= !empty($bot3['basic_id']) ? "<span class='ok'>✅</span>" : "<span class='warning'>⚠️</span>" ?></td>
            </tr>
            <tr>
                <td>Bot Mode</td>
                <td><?= htmlspecialchars($bot3['bot_mode'] ?? 'N/A') ?></td>
                <td><?= !empty($bot3['bot_mode']) ? "<span class='ok'>✅</span>" : "<span class='warning'>⚠️</span>" ?></td>
            </tr>
            <tr>
                <td>Is Active</td>
                <td><?= $bot3['is_active'] ? 'Yes' : 'No' ?></td>
                <td><?= $bot3['is_active'] ? "<span class='ok'>✅</span>" : "<span class='error'>❌ INACTIVE!</span>" ?></td>
            </tr>
        </table>
    </div>
    
    <?php if ($bot4): ?>
    <div class="section">
        <h2>🔄 เปรียบเทียบกับ Bot ID 4 (ที่ใช้งานได้)</h2>
        <table>
            <tr>
                <th>Field</th>
                <th>Bot ID 3</th>
                <th>Bot ID 4</th>
                <th>Match?</th>
            </tr>
            <tr>
                <td>Bot Mode</td>
                <td><?= htmlspecialchars($bot3['bot_mode'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($bot4['bot_mode'] ?? 'N/A') ?></td>
                <td><?= ($bot3['bot_mode'] ?? '') === ($bot4['bot_mode'] ?? '') ? "✅" : "❌" ?></td>
            </tr>
            <tr>
                <td>Is Active</td>
                <td><?= $bot3['is_active'] ? 'Yes' : 'No' ?></td>
                <td><?= $bot4['is_active'] ? 'Yes' : 'No' ?></td>
                <td><?= $bot3['is_active'] === $bot4['is_active'] ? "✅" : "❌" ?></td>
            </tr>
            <tr>
                <td>Token Length</td>
                <td><?= strlen($bot3['channel_access_token'] ?? '') ?> chars</td>
                <td><?= strlen($bot4['channel_access_token'] ?? '') ?> chars</td>
                <td><?= abs(strlen($bot3['channel_access_token'] ?? '') - strlen($bot4['channel_access_token'] ?? '')) < 50 ? "✅" : "⚠️" ?></td>
            </tr>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>🧪 ทดสอบ LINE API Token</h2>
        <p>กดปุ่มด้านล่างเพื่อทดสอบว่า Token ของ Bot ID 3 ใช้งานได้หรือไม่</p>
        
        <?php
        if (isset($_POST['test_token'])) {
            echo "<h3>📤 กำลังทดสอบ...</h3>";
            
            $token = $bot3['channel_access_token'];
            $testUserId = $_POST['test_user_id'] ?? 'Ua1156d646cad2237e878457833bc07b3';
            
            // ทดสอบ 1: Get Bot Info
            echo "<h4>Test 1: Get Bot Info</h4>";
            $ch = curl_init('https://api.line.me/v2/bot/info');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $botInfo = json_decode($response, true);
                echo "<p class='ok'>✅ Token ใช้งานได้! Bot Name: " . htmlspecialchars($botInfo['displayName'] ?? 'N/A') . "</p>";
                echo "<pre>" . json_encode($botInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            } else {
                echo "<p class='error'>❌ Token ไม่ถูกต้อง! HTTP Code: {$httpCode}</p>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
                echo "<div class='info'>";
                echo "<h4>🔧 วิธีแก้ไข:</h4>";
                echo "<ol>";
                echo "<li>ไปที่ <a href='https://developers.line.biz/console/' target='_blank'>LINE Developers Console</a></li>";
                echo "<li>เลือก Provider และ Channel ของ Bot ID 3</li>";
                echo "<li>ไปที่แท็บ Messaging API</li>";
                echo "<li>Issue new Channel Access Token</li>";
                echo "<li>Copy token ใหม่มาอัพเดทในระบบ</li>";
                echo "</ol>";
                echo "</div>";
            }
            
            // ทดสอบ 2: Send Test Message
            if ($httpCode === 200) {
                echo "<h4>Test 2: Send Test Message</h4>";
                $data = [
                    'to' => $testUserId,
                    'messages' => [
                        [
                            'type' => 'text',
                            'text' => "🧪 ทดสอบจาก Bot ID 3\n\nเวลา: " . date('Y-m-d H:i:s')
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
                    echo "<p class='ok'>✅ ส่งข้อความสำเร็จ! ตรวจสอบ LINE ของคุณ</p>";
                } else {
                    echo "<p class='error'>❌ ส่งข้อความไม่สำเร็จ! HTTP Code: {$httpCode}</p>";
                    $errorData = json_decode($response, true);
                    echo "<pre>" . json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    
                    if (isset($errorData['message'])) {
                        echo "<div class='info'>";
                        echo "<h4>🔍 สาเหตุ:</h4>";
                        $errorMsg = $errorData['message'];
                        if (strpos($errorMsg, 'Invalid access token') !== false) {
                            echo "<p class='error'>Token หมดอายุหรือไม่ถูกต้อง - ต้อง Issue token ใหม่</p>";
                        } elseif (strpos($errorMsg, 'Not found') !== false) {
                            echo "<p class='error'>User ID ไม่ถูกต้อง หรือ User ยังไม่ได้เพิ่มเพื่อน Bot</p>";
                        } else {
                            echo "<p>" . htmlspecialchars($errorMsg) . "</p>";
                        }
                        echo "</div>";
                    }
                }
            }
        }
        ?>
        
        <form method="POST">
            <div style="margin: 20px 0;">
                <label><strong>User ID สำหรับทดสอบ:</strong></label>
                <input type="text" name="test_user_id" value="Ua1156d646cad2237e878457833bc07b3" style="width: 100%; padding: 10px; margin-top: 5px;">
                <small>ใส่ LINE User ID ของคุณเอง (ดูได้จาก Inbox)</small>
            </div>
            <button type="submit" name="test_token" class="test-btn">🧪 ทดสอบ Token และส่งข้อความ</button>
        </form>
    </div>
    
    <div class="section">
        <h2>📋 Recent Logs (Bot ID 3)</h2>
        <?php
        // ดึง logs ล่าสุดของ Bot ID 3
        try {
            $stmt = $db->prepare("
                SELECT * FROM dev_logs 
                WHERE data LIKE '%bot_id\":3%' OR data LIKE '%line_account_id\":3%'
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($logs) {
                echo "<table>";
                echo "<tr><th>Time</th><th>Type</th><th>Source</th><th>Message</th><th>Data</th></tr>";
                foreach ($logs as $log) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
                    echo "<td>" . htmlspecialchars($log['log_type']) . "</td>";
                    echo "<td>" . htmlspecialchars($log['source']) . "</td>";
                    echo "<td>" . htmlspecialchars(mb_substr($log['message'], 0, 50)) . "</td>";
                    echo "<td><pre style='max-height: 100px; overflow-y: auto;'>" . htmlspecialchars(mb_substr($log['data'], 0, 200)) . "</pre></td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='warning'>⚠️ ไม่พบ logs ของ Bot ID 3</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>💡 สรุปและคำแนะนำ</h2>
        <div class="info">
            <h3>ปัญหาที่พบบ่อย:</h3>
            <ol>
                <li><strong>Token หมดอายุ:</strong> LINE Access Token อาจหมดอายุ - ต้อง Issue ใหม่จาก LINE Developers Console</li>
                <li><strong>Webhook URL ไม่ถูกต้อง:</strong> ตรวจสอบว่า Webhook URL ใน LINE Developers ตั้งค่าถูกต้อง</li>
                <li><strong>Bot ถูกปิดใช้งาน:</strong> ตรวจสอบว่า is_active = 1 ในตาราง line_accounts</li>
                <li><strong>User ยังไม่ได้เพิ่มเพื่อน:</strong> User ต้องเพิ่มเพื่อน Bot ก่อนจึงจะรับข้อความได้</li>
            </ol>
            
            <h3>ขั้นตอนแก้ไข:</h3>
            <ol>
                <li>กดปุ่ม "ทดสอบ Token" ด้านบน</li>
                <li>ถ้า Token ไม่ถูกต้อง → Issue token ใหม่จาก LINE Developers Console</li>
                <li>ถ้า Token ถูกต้องแต่ส่งไม่ถึง → ตรวจสอบว่า User เพิ่มเพื่อน Bot แล้วหรือยัง</li>
                <li>ตรวจสอบ Webhook logs เพื่อดูว่ามี error อะไร</li>
            </ol>
        </div>
    </div>
    
    <hr>
    <p><small>Generated: <?= date('Y-m-d H:i:s') ?></small></p>
</div>
</body>
</html>
