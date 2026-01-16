<?php
/**
 * ตรวจสอบ Reply Tokens ในระบบ
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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ตรวจสอบ Reply Tokens</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #06C755; }
        .ok { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #06C755; color: white; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 ตรวจสอบ Reply Tokens</h1>
    
    <div class="info">
        <h3>📌 ข้อมูลเกี่ยวกับ Reply Token:</h3>
        <ul>
            <li><strong>Reply Token</strong> ได้จาก webhook event เมื่อ user ส่งข้อความมา</li>
            <li><strong>อายุ:</strong> 30 วินาที (หมดอายุเร็วมาก!)</li>
            <li><strong>ใช้ได้:</strong> 1 ครั้งเท่านั้น (single-use)</li>
            <li><strong>ข้อดี:</strong> ส่งฟรี ไม่นับ quota</li>
            <li><strong>ข้อเสีย:</strong> ต้องใช้ภายใน 30 วินาที</li>
        </ul>
        <p><strong>Push Message:</strong> ใช้เมื่อไม่มี reply token หรือ token หมดอายุ (นับ quota)</p>
    </div>
    
    <?php
    // ตรวจสอบว่ามี column reply_token หรือไม่
    $hasReplyToken = false;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'reply_token'");
        $hasReplyToken = $stmt->rowCount() > 0;
    } catch (Exception $e) {}
    
    if (!$hasReplyToken) {
        echo "<div class='info'>";
        echo "<p class='warning'>⚠️ ตาราง users ไม่มี column reply_token</p>";
        echo "<p>ระบบจะใช้ Push Message เท่านั้น (นับ quota)</p>";
        echo "</div>";
    } else {
        // แสดง users ที่มี reply token
        echo "<h2>👥 Users ที่มี Reply Token</h2>";
        
        $stmt = $db->query("
            SELECT 
                id,
                line_user_id,
                display_name,
                reply_token,
                reply_token_expires,
                TIMESTAMPDIFF(SECOND, NOW(), reply_token_expires) as seconds_left,
                CASE 
                    WHEN reply_token_expires IS NULL THEN 'No expiry set'
                    WHEN reply_token_expires < NOW() THEN 'Expired'
                    ELSE 'Valid'
                END as status
            FROM users 
            WHERE reply_token IS NOT NULL 
            ORDER BY reply_token_expires DESC
            LIMIT 50
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($users) {
            echo "<table>";
            echo "<tr>";
            echo "<th>ID</th>";
            echo "<th>Display Name</th>";
            echo "<th>LINE User ID</th>";
            echo "<th>Reply Token</th>";
            echo "<th>Expires</th>";
            echo "<th>Seconds Left</th>";
            echo "<th>Status</th>";
            echo "</tr>";
            
            foreach ($users as $user) {
                $statusClass = 'ok';
                if ($user['status'] === 'Expired') $statusClass = 'error';
                elseif ($user['status'] === 'No expiry set') $statusClass = 'warning';
                
                echo "<tr>";
                echo "<td>{$user['id']}</td>";
                echo "<td>" . htmlspecialchars($user['display_name']) . "</td>";
                echo "<td>" . htmlspecialchars(substr($user['line_user_id'], 0, 20)) . "...</td>";
                echo "<td>" . htmlspecialchars(substr($user['reply_token'], 0, 30)) . "...</td>";
                echo "<td>" . htmlspecialchars($user['reply_token_expires'] ?? 'N/A') . "</td>";
                echo "<td>" . ($user['seconds_left'] ?? 'N/A') . "</td>";
                echo "<td class='{$statusClass}'>{$user['status']}</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ ไม่มี users ที่มี reply token ในขณะนี้</p>";
        }
        
        // สถิติ
        echo "<h2>📊 สถิติ Reply Tokens</h2>";
        
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN reply_token IS NOT NULL THEN 1 ELSE 0 END) as has_token,
                SUM(CASE WHEN reply_token IS NOT NULL AND reply_token_expires > NOW() THEN 1 ELSE 0 END) as valid_tokens,
                SUM(CASE WHEN reply_token IS NOT NULL AND reply_token_expires < NOW() THEN 1 ELSE 0 END) as expired_tokens
            FROM users
        ");
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<table>";
        echo "<tr><th>Metric</th><th>Count</th></tr>";
        echo "<tr><td>Total Users</td><td>{$stats['total_users']}</td></tr>";
        echo "<tr><td>Users with Token</td><td>{$stats['has_token']}</td></tr>";
        echo "<tr><td>Valid Tokens</td><td class='ok'>{$stats['valid_tokens']}</td></tr>";
        echo "<tr><td>Expired Tokens</td><td class='error'>{$stats['expired_tokens']}</td></tr>";
        echo "</table>";
    }
    
    // ตรวจสอบ webhook logs ล่าสุด
    echo "<h2>📝 Recent Webhook Events (Bot ID 3)</h2>";
    
    try {
        $stmt = $db->query("
            SELECT * FROM dev_logs 
            WHERE (source = 'webhook' OR source = 'webhook_event')
            AND (data LIKE '%bot_id\":3%' OR data LIKE '%line_account_id\":3%')
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($logs) {
            echo "<table>";
            echo "<tr><th>Time</th><th>Type</th><th>Message</th><th>Data</th></tr>";
            foreach ($logs as $log) {
                $data = json_decode($log['data'], true);
                $hasReply = isset($data['has_reply']) ? ($data['has_reply'] ? '✅ Yes' : '❌ No') : 'N/A';
                
                echo "<tr>";
                echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
                echo "<td>" . htmlspecialchars($log['log_type']) . "</td>";
                echo "<td>" . htmlspecialchars(mb_substr($log['message'], 0, 50)) . "</td>";
                echo "<td><pre>" . htmlspecialchars(mb_substr($log['data'], 0, 300)) . "</pre></td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ ไม่พบ webhook logs</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
    
    <div class="info">
        <h3>💡 สรุปและคำแนะนำ:</h3>
        
        <h4>ปัญหาที่พบ:</h4>
        <ul>
            <li><strong>Reply Token หมดอายุ:</strong> Token มีอายุแค่ 30 วินาที</li>
            <li><strong>Token ถูกใช้ไปแล้ว:</strong> ใช้ได้ครั้งเดียว</li>
            <li><strong>ไม่มี Token:</strong> User ไม่ได้ส่งข้อความมาใหม่</li>
        </ul>
        
        <h4>วิธีแก้ไข:</h4>
        <ol>
            <li><strong>ใช้ Push Message แทน:</strong> เมื่อ reply token หมดอายุหรือไม่มี</li>
            <li><strong>Fallback Logic:</strong> ลอง reply ก่อน ถ้าไม่สำเร็จค่อย push</li>
            <li><strong>Clear Token หลังใช้:</strong> ลบ token ทันทีหลังใช้เพื่อป้องกันใช้ซ้ำ</li>
        </ol>
        
        <h4>ตรวจสอบ webhook.php:</h4>
        <pre>// ตัวอย่าง code ที่ถูกต้อง
if ($replyToken && !empty($replyToken)) {
    // ลอง reply ก่อน (ฟรี!)
    $result = $line->replyMessage($replyToken, $messages);
    
    if ($result['code'] !== 200) {
        // Reply ไม่สำเร็จ → ใช้ push แทน
        $line->pushMessage($userId, $messages);
    }
} else {
    // ไม่มี reply token → ใช้ push
    $line->pushMessage($userId, $messages);
}</pre>
    </div>
    
    <hr>
    <p><small>Generated: <?= date('Y-m-d H:i:s') ?></small></p>
</div>
</body>
</html>
