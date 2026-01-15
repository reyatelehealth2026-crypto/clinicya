<?php
/**
 * Debug Auto Reply - ตรวจสอบปัญหา auto reply
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Debug Auto Reply System</h1>";
echo "<style>
body { font-family: sans-serif; padding: 20px; }
.box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin: 15px 0; }
.success { background: #d4edda; border-color: #c3e6cb; color: #155724; }
.error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
.warning { background: #fff3cd; border-color: #ffeaa7; color: #856404; }
.info { background: #d1ecf1; border-color: #bee5eb; color: #0c5460; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #f8f9fa; }
</style>";

// 1. ตรวจสอบตาราง auto_replies
echo "<div class='box'><h2>1. ตรวจสอบตาราง auto_replies</h2>";
try {
    $stmt = $db->query("SHOW TABLES LIKE 'auto_replies'");
    if ($stmt->rowCount() > 0) {
        echo "<p class='success'>✅ ตาราง auto_replies มีอยู่</p>";
        
        // ตรวจสอบ columns
        $stmt = $db->query("SHOW COLUMNS FROM auto_replies");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p><strong>Columns:</strong> " . implode(', ', $columns) . "</p>";
        
        // นับจำนวน rules
        $stmt = $db->query("SELECT COUNT(*) as total, SUM(is_active) as active FROM auto_replies");
        $stats = $stmt->fetch();
        echo "<p><strong>Total rules:</strong> {$stats['total']}, <strong>Active:</strong> {$stats['active']}</p>";
        
    } else {
        echo "<p class='error'>❌ ตาราง auto_replies ไม่มีอยู่</p>";
        echo "<p>ต้องรัน migration ก่อน: <a href='/install/run_auto_reply_migration.php'>รัน Migration</a></p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// 2. ตรวจสอบ auto reply rules ที่ active
echo "<div class='box'><h2>2. Auto Reply Rules ที่ Active</h2>";
try {
    $stmt = $db->query("SELECT * FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC LIMIT 10");
    $rules = $stmt->fetchAll();
    
    if (empty($rules)) {
        echo "<p class='warning'>⚠️ ไม่มี auto reply rules ที่ active</p>";
        echo "<p>ไปที่ <a href='/auto-reply.php'>Auto Reply</a> เพื่อเพิ่ม rules</p>";
    } else {
        echo "<p class='success'>✅ พบ " . count($rules) . " rules ที่ active</p>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Keyword</th><th>Match Type</th><th>Reply Type</th><th>Priority</th><th>Line Account</th></tr>";
        foreach ($rules as $rule) {
            echo "<tr>";
            echo "<td>{$rule['id']}</td>";
            echo "<td>" . htmlspecialchars($rule['keyword']) . "</td>";
            echo "<td>{$rule['match_type']}</td>";
            echo "<td>{$rule['reply_type']}</td>";
            echo "<td>{$rule['priority']}</td>";
            echo "<td>" . ($rule['line_account_id'] ?? 'Global') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// 3. ทดสอบ checkAutoReply function
echo "<div class='box'><h2>3. ทดสอบ checkAutoReply Function</h2>";

// Include checkAutoReply function from webhook
function checkAutoReplyDebug($db, $text, $lineAccountId = null) {
    // ดึงกฎที่ตรงกับ account นี้ หรือกฎ global (line_account_id IS NULL)
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

$testMessages = ['สวัสดี', 'hello', 'ราคา', 'price', 'ทดสอบ', 'test', 'สินค้า'];
$lineAccountId = $_GET['account_id'] ?? 1;

echo "<p><strong>Testing with Line Account ID:</strong> $lineAccountId</p>";
echo "<table>";
echo "<tr><th>Test Message</th><th>Match Result</th><th>Rule Details</th></tr>";

foreach ($testMessages as $msg) {
    $result = checkAutoReplyDebug($db, $msg, $lineAccountId);
    echo "<tr>";
    echo "<td>" . htmlspecialchars($msg) . "</td>";
    if ($result) {
        echo "<td class='success'>✅ Match</td>";
        echo "<td>Rule #{$result['id']}: {$result['keyword']} ({$result['match_type']})</td>";
    } else {
        echo "<td class='error'>❌ No match</td>";
        echo "<td>-</td>";
    }
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// 4. ตรวจสอบ webhook logs
echo "<div class='box'><h2>4. ตรวจสอบ Webhook Logs (ล่าสุด 10 รายการ)</h2>";
try {
    $stmt = $db->query("SELECT * FROM dev_logs WHERE source LIKE '%webhook%' ORDER BY created_at DESC LIMIT 10");
    $logs = $stmt->fetchAll();
    
    if (empty($logs)) {
        echo "<p class='info'>ℹ️ ไม่มี webhook logs</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Time</th><th>Type</th><th>Source</th><th>Message</th></tr>";
        foreach ($logs as $log) {
            echo "<tr>";
            echo "<td>{$log['created_at']}</td>";
            echo "<td>{$log['log_type']}</td>";
            echo "<td>{$log['source']}</td>";
            echo "<td>" . htmlspecialchars(mb_substr($log['message'], 0, 100)) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// 5. ตรวจสอบ LINE Account settings
echo "<div class='box'><h2>5. ตรวจสอบ LINE Account Settings</h2>";
try {
    $stmt = $db->query("SELECT id, name, bot_mode, is_active FROM line_accounts ORDER BY id");
    $accounts = $stmt->fetchAll();
    
    if (empty($accounts)) {
        echo "<p class='warning'>⚠️ ไม่มี LINE accounts</p>";
    } else {
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Bot Mode</th><th>Active</th></tr>";
        foreach ($accounts as $acc) {
            echo "<tr>";
            echo "<td>{$acc['id']}</td>";
            echo "<td>" . htmlspecialchars($acc['name']) . "</td>";
            echo "<td>" . ($acc['bot_mode'] ?? 'shop') . "</td>";
            echo "<td>" . ($acc['is_active'] ? '✅' : '❌') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// 6. แนะนำการแก้ไข
echo "<div class='box'><h2>6. 🔧 แนะนำการแก้ไข</h2>";
echo "<ol>";
echo "<li><strong>ถ้าไม่มี auto reply rules:</strong> ไปที่ <a href='/auto-reply.php'>Auto Reply</a> เพื่อเพิ่ม rules</li>";
echo "<li><strong>ถ้า rules ไม่ match:</strong> ตรวจสอบ match_type และ keyword ให้ถูกต้อง</li>";
echo "<li><strong>ถ้า webhook ไม่ทำงาน:</strong> ตรวจสอบ LINE webhook URL และ signature</li>";
echo "<li><strong>ถ้า bot_mode เป็น general:</strong> จะตอบเฉพาะ auto reply rules เท่านั้น</li>";
echo "<li><strong>ทดสอบ webhook:</strong> ส่งข้อความใน LINE แล้วดู logs ใน dev_logs table</li>";
echo "</ol>";
echo "</div>";

echo "<div class='box info'>";
echo "<h3>🧪 Quick Test</h3>";
echo "<p>ทดสอบด่วน: <a href='?test_msg=สวัสดี&account_id=1'>ทดสอบ 'สวัสดี'</a> | ";
echo "<a href='?test_msg=hello&account_id=1'>ทดสอบ 'hello'</a> | ";
echo "<a href='?test_msg=ราคา&account_id=1'>ทดสอบ 'ราคา'</a></p>";

if (isset($_GET['test_msg'])) {
    $testMsg = $_GET['test_msg'];
    $testAccountId = $_GET['account_id'] ?? 1;
    $testResult = checkAutoReplyDebug($db, $testMsg, $testAccountId);
    
    echo "<div style='margin-top: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;'>";
    echo "<strong>Test Result for '$testMsg':</strong><br>";
    if ($testResult) {
        echo "<span style='color: green;'>✅ Match found!</span><br>";
        echo "Rule: {$testResult['keyword']} ({$testResult['match_type']})<br>";
        echo "Reply: " . htmlspecialchars(mb_substr($testResult['reply_content'], 0, 100)) . "<br>";
    } else {
        echo "<span style='color: red;'>❌ No match found</span>";
    }
    echo "</div>";
}
echo "</div>";
?>