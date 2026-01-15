<?php
/**
 * Debug Webhook Simple - ตรวจสอบปัญหา webhook แบบง่ายๆ
 */
require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Webhook Debug (Simple)</h1>";
echo "<style>
body { font-family: sans-serif; padding: 20px; }
.success { background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
.error { background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
.warning { background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0; }
.info { background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #f8f9fa; }
</style>";

// 1. ตรวจสอบ auto_replies table
echo "<h2>1. ตรวจสอบ Auto Reply Rules</h2>";
try {
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(is_active) as active FROM auto_replies");
    $stats = $stmt->fetch();
    
    if ($stats['total'] == 0) {
        echo "<div class='warning'>⚠️ ไม่มี auto reply rules<br>";
        echo "<a href='test_auto_reply.php'>คลิกเพื่อเพิ่ม rules ตัวอย่าง</a></div>";
    } else {
        echo "<div class='success'>✅ มี auto reply rules {$stats['total']} รายการ (active: {$stats['active']})</div>";
        
        // แสดง active rules
        $stmt = $db->query("SELECT keyword, match_type, reply_type FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC LIMIT 5");
        $rules = $stmt->fetchAll();
        
        if (!empty($rules)) {
            echo "<table>";
            echo "<tr><th>Keyword</th><th>Match Type</th><th>Reply Type</th></tr>";
            foreach ($rules as $rule) {
                echo "<tr><td>{$rule['keyword']}</td><td>{$rule['match_type']}</td><td>{$rule['reply_type']}</td></tr>";
            }
            echo "</table>";
        }
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// 2. ตรวจสอบ LINE accounts
echo "<h2>2. ตรวจสอบ LINE Accounts</h2>";
try {
    $stmt = $db->query("SELECT id, name, bot_mode, is_active FROM line_accounts WHERE is_active = 1");
    $accounts = $stmt->fetchAll();
    
    if (empty($accounts)) {
        echo "<div class='warning'>⚠️ ไม่มี LINE accounts ที่ active</div>";
    } else {
        echo "<div class='success'>✅ มี LINE accounts " . count($accounts) . " รายการ</div>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Bot Mode</th></tr>";
        foreach ($accounts as $acc) {
            echo "<tr><td>{$acc['id']}</td><td>{$acc['name']}</td><td>" . ($acc['bot_mode'] ?? 'shop') . "</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// 3. ตรวจสอบ webhook logs ล่าสุด
echo "<h2>3. Webhook Logs ล่าสุด (5 รายการ)</h2>";
try {
    $stmt = $db->query("SELECT created_at, log_type, source, message FROM dev_logs WHERE source LIKE '%webhook%' ORDER BY created_at DESC LIMIT 5");
    $logs = $stmt->fetchAll();
    
    if (empty($logs)) {
        echo "<div class='info'>ℹ️ ไม่มี webhook logs (อาจยังไม่มีการส่งข้อความใน LINE)</div>";
    } else {
        echo "<div class='success'>✅ พบ webhook logs</div>";
        echo "<table>";
        echo "<tr><th>Time</th><th>Type</th><th>Source</th><th>Message</th></tr>";
        foreach ($logs as $log) {
            echo "<tr>";
            echo "<td>{$log['created_at']}</td>";
            echo "<td>{$log['log_type']}</td>";
            echo "<td>{$log['source']}</td>";
            echo "<td>" . htmlspecialchars(mb_substr($log['message'], 0, 80)) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// 4. ทดสอบ checkAutoReply function
echo "<h2>4. ทดสอบ Auto Reply Function</h2>";

// Copy checkAutoReply function from webhook
function testCheckAutoReply($db, $text, $lineAccountId = null) {
    if ($lineAccountId) {
        $stmt = $db->prepare("SELECT * FROM auto_replies WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY line_account_id DESC, priority DESC");
        $stmt->execute([$lineAccountId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC");
        $stmt->execute();
    }
    $rules = $stmt->fetchAll();

    foreach ($rules as $rule) {
        $matched = false;
        switch ($rule['match_type']) {
            case 'exact':
                $matched = (mb_strtolower($text) === mb_strtolower($rule['keyword']));
                break;
            case 'contains':
                $matched = (mb_stripos($text, $rule['keyword']) !== false);
                break;
            case 'starts_with':
                $matched = (mb_stripos($text, $rule['keyword']) === 0);
                break;
            case 'regex':
                $matched = preg_match('/' . $rule['keyword'] . '/i', $text);
                break;
            case 'all':
                $matched = true;
                break;
        }

        if ($matched) {
            return $rule;
        }
    }
    return null;
}

$testMessages = ['สวัสดี', 'hello', 'ราคา', 'price', 'ทดสอบ'];

echo "<table>";
echo "<tr><th>Test Message</th><th>Result</th><th>Matched Rule</th></tr>";

foreach ($testMessages as $msg) {
    $result = testCheckAutoReply($db, $msg, 1);
    echo "<tr>";
    echo "<td>" . htmlspecialchars($msg) . "</td>";
    if ($result) {
        echo "<td style='color: green;'>✅ Match</td>";
        echo "<td>{$result['keyword']} ({$result['match_type']})</td>";
    } else {
        echo "<td style='color: red;'>❌ No match</td>";
        echo "<td>-</td>";
    }
    echo "</tr>";
}
echo "</table>";

// 5. แนะนำการแก้ไข
echo "<h2>5. 🔧 การแก้ไขปัญหา</h2>";
echo "<div class='info'>";
echo "<h3>ถ้า Auto Reply ไม่ทำงาน:</h3>";
echo "<ol>";
echo "<li><strong>ไม่มี rules:</strong> <a href='test_auto_reply.php'>เพิ่ม auto reply rules</a></li>";
echo "<li><strong>Rules ไม่ match:</strong> ตรวจสอบ keyword และ match_type ใน <a href='/auto-reply.php'>Auto Reply</a></li>";
echo "<li><strong>Bot mode ผิด:</strong> ตรวจสอบ bot_mode ใน line_accounts table</li>";
echo "<li><strong>Webhook ไม่ทำงาน:</strong> ตรวจสอบ LINE webhook URL และ channel secret</li>";
echo "</ol>";

echo "<h3>ขั้นตอนการทดสอบ:</h3>";
echo "<ol>";
echo "<li>ส่งข้อความ 'สวัสดี' ใน LINE Official Account</li>";
echo "<li>ตรวจสอบว่ามี response หรือไม่</li>";
echo "<li>ดู logs ใน dev_logs table</li>";
echo "<li>ถ้าไม่มี logs แสดงว่า webhook ไม่ทำงาน</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<h2>🔗 ลิงก์ที่เป็นประโยชน์</h2>";
echo "<ul>";
echo "<li><a href='test_auto_reply.php'>Setup Auto Reply Rules</a></li>";
echo "<li><a href='/auto-reply.php'>จัดการ Auto Reply</a></li>";
echo "<li><a href='/debug-auto-reply.php'>Debug Auto Reply (Full)</a></li>";
echo "<li><a href='/system-status.php'>System Status</a></li>";
echo "</ul>";
?>