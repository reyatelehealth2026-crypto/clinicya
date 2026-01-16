<?php
/**
 * ทดสอบส่งข้อความผ่าน LINE API โดยตรง
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

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>ทดสอบส่งข้อความ LINE</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
    h1 { color: #06C755; }
    .ok { color: #4CAF50; font-weight: bold; }
    .error { color: #f44336; font-weight: bold; }
    .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
    pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; }
    .form-group { margin: 20px 0; }
    label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, textarea, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
    button { padding: 12px 24px; background: #06C755; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
    button:hover { background: #05b04b; }
</style></head><body><div class='container'>";

echo "<h1>🧪 ทดสอบส่งข้อความ LINE API</h1>";

// ดึงรายการ Bot
try {
    $stmt = $db->query("SELECT * FROM line_accounts ORDER BY id");
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("<p class='error'>Error loading bots: " . htmlspecialchars($e->getMessage()) . "</p></div></body></html>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $botId = $_POST['bot_id'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $message = $_POST['message'] ?? 'ทดสอบส่งข้อความ';
    
    if ($botId && $userId) {
        // ดึงข้อมูล Bot
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ?");
        $stmt->execute([$botId]);
        $bot = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($bot && !empty($bot['channel_access_token'])) {
            echo "<h2>📤 กำลังส่งข้อความ...</h2>";
            echo "<div class='info'>";
            echo "<p><strong>Bot:</strong> " . htmlspecialchars($bot['name'] ?? 'Bot #' . $bot['id']) . "</p>";
            echo "<p><strong>User ID:</strong> " . htmlspecialchars($userId) . "</p>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($message) . "</p>";
            echo "</div>";
            
            // เตรียมข้อความ
            $data = [
                'to' => $userId,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $message
                    ]
                ]
            ];
            
            // ส่งผ่าน LINE API
            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $bot['channel_access_token']
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            echo "<h3>📊 ผลลัพธ์</h3>";
            
            if ($httpCode === 200) {
                echo "<p class='ok'>✅ ส่งข้อความสำเร็จ!</p>";
                echo "<p>HTTP Code: {$httpCode}</p>";
            } else {
                echo "<p class='error'>❌ ส่งข้อความไม่สำเร็จ!</p>";
                echo "<p>HTTP Code: {$httpCode}</p>";
                
                if ($curlError) {
                    echo "<p class='error'>cURL Error: " . htmlspecialchars($curlError) . "</p>";
                }
                
                if ($response) {
                    $responseData = json_decode($response, true);
                    echo "<h4>Response from LINE:</h4>";
                    echo "<pre>" . json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    
                    // แสดงคำอธิบาย Error
                    if (isset($responseData['message'])) {
                        echo "<div class='info'>";
                        echo "<h4>🔍 คำอธิบาย Error:</h4>";
                        
                        $errorMsg = $responseData['message'];
                        if (strpos($errorMsg, 'Invalid access token') !== false) {
                            echo "<p class='error'><strong>Token ไม่ถูกต้อง!</strong></p>";
                            echo "<p>กรุณาตรวจสอบ Channel Access Token ใน LINE Developers Console</p>";
                            echo "<p>1. ไปที่ <a href='https://developers.line.biz/console/' target='_blank'>LINE Developers Console</a></p>";
                            echo "<p>2. เลือก Provider และ Channel</p>";
                            echo "<p>3. ไปที่ Messaging API > Channel access token</p>";
                            echo "<p>4. Issue new token หรือ copy token ที่มีอยู่</p>";
                        } elseif (strpos($errorMsg, 'The request body has 1 error(s)') !== false) {
                            echo "<p class='error'><strong>รูปแบบข้อมูลไม่ถูกต้อง!</strong></p>";
                            echo "<p>ตรวจสอบ User ID หรือรูปแบบข้อความ</p>";
                        } elseif (strpos($errorMsg, 'Not found') !== false) {
                            echo "<p class='error'><strong>ไม่พบ User!</strong></p>";
                            echo "<p>User ID อาจไม่ถูกต้อง หรือ User ยังไม่ได้เพิ่มเพื่อน Bot</p>";
                        }
                        
                        echo "</div>";
                    }
                }
            }
            
            echo "<hr>";
        } else {
            echo "<p class='error'>❌ ไม่พบ Bot หรือไม่มี Token</p>";
        }
    }
}

// ฟอร์มทดสอบ
echo "<h2>📝 ฟอร์มทดสอบ</h2>";
echo "<form method='POST'>";

echo "<div class='form-group'>";
echo "<label>เลือก Bot:</label>";
echo "<select name='bot_id' required>";
echo "<option value=''>-- เลือก Bot --</option>";
foreach ($bots as $bot) {
    $botName = $bot['name'] ?? $bot['bot_name'] ?? $bot['channel_name'] ?? 'Bot #' . $bot['id'];
    echo "<option value='{$bot['id']}'>{$botName} (ID: {$bot['id']})</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='form-group'>";
echo "<label>User ID (LINE User ID):</label>";
echo "<input type='text' name='user_id' placeholder='Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' required>";
echo "<small>ตัวอย่าง: Ua1156d646cad2237e878457833bc07b3</small>";
echo "</div>";

echo "<div class='form-group'>";
echo "<label>ข้อความที่ต้องการส่ง:</label>";
echo "<textarea name='message' rows='4' required>สวัสดีค่ะ! 👋

ทดสอบส่งข้อความจากระบบ

💡 พิมพ์ "menu" เพื่อดูเมนู</textarea>";
echo "</div>";

echo "<button type='submit'>📤 ส่งข้อความทดสอบ</button>";
echo "</form>";

echo "<hr><p><small>Generated: " . date('Y-m-d H:i:s') . "</small></p>";
echo "</div></body></html>";
?>
