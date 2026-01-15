<?php
/**
 * ตรวจสอบการตั้งค่า AI สำหรับทุก Bot ID
 * เพื่อหาสาเหตุว่าทำไม Bot ID 1 ใช้งานได้แต่ Bot ID อื่นไม่ได้
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 ตรวจสอบการตั้งค่า AI ทุก Bot</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background-color: #4CAF50; color: white; }
    .ok { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }
</style>";

// ===== 1. ดึงข้อมูล LINE Accounts ทั้งหมด =====
echo "<h2>1. LINE Accounts ทั้งหมด</h2>";
$stmt = $db->query("SELECT id, bot_name, channel_access_token, channel_secret FROM line_accounts ORDER BY id");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>ID</th><th>Bot Name</th><th>Has Token</th><th>Has Secret</th></tr>";
foreach ($accounts as $acc) {
    $hasToken = !empty($acc['channel_access_token']) ? '✅' : '❌';
    $hasSecret = !empty($acc['channel_secret']) ? '✅' : '❌';
    echo "<tr>";
    echo "<td>{$acc['id']}</td>";
    echo "<td>{$acc['bot_name']}</td>";
    echo "<td>{$hasToken}</td>";
    echo "<td>{$hasSecret}</td>";
    echo "</tr>";
}
echo "</table>";

// ===== 2. ตรวจสอบ ai_settings สำหรับแต่ละ Bot =====
echo "<h2>2. AI Settings สำหรับแต่ละ Bot</h2>";
echo "<table>";
echo "<tr><th>Bot ID</th><th>Bot Name</th><th>AI Enabled</th><th>AI Mode</th><th>Has API Key</th><th>Sender Name</th><th>Status</th></tr>";

foreach ($accounts as $acc) {
    $botId = $acc['id'];
    $botName = $acc['bot_name'];
    
    // ดึง ai_settings
    $stmt = $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$botId]);
    $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($aiSettings) {
        $isEnabled = $aiSettings['is_enabled'] == 1 ? '✅ Yes' : '❌ No';
        $aiMode = $aiSettings['ai_mode'] ?: 'N/A';
        $hasKey = !empty($aiSettings['gemini_api_key']) ? '✅ Yes' : '❌ No';
        $senderName = $aiSettings['sender_name'] ?: 'N/A';
        
        // ประเมินสถานะ
        if ($aiSettings['is_enabled'] == 1 && !empty($aiSettings['gemini_api_key'])) {
            $status = "<span class='ok'>✅ พร้อมใช้งาน</span>";
        } else {
            $status = "<span class='error'>❌ ไม่พร้อม</span>";
        }
    } else {
        $isEnabled = '❌ No Record';
        $aiMode = 'N/A';
        $hasKey = '❌ No Record';
        $senderName = 'N/A';
        $status = "<span class='error'>❌ ไม่มีข้อมูล</span>";
    }
    
    echo "<tr>";
    echo "<td>{$botId}</td>";
    echo "<td>{$botName}</td>";
    echo "<td>{$isEnabled}</td>";
    echo "<td>{$aiMode}</td>";
    echo "<td>{$hasKey}</td>";
    echo "<td>{$senderName}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}
echo "</table>";

// ===== 3. ตรวจสอบ Gemini API Key ที่ใช้ =====
echo "<h2>3. Gemini API Keys</h2>";
echo "<table>";
echo "<tr><th>Bot ID</th><th>API Key (First 20 chars)</th><th>Key Length</th></tr>";

foreach ($accounts as $acc) {
    $botId = $acc['id'];
    
    $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$botId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['gemini_api_key'])) {
        $key = $result['gemini_api_key'];
        $keyPreview = substr($key, 0, 20) . '...';
        $keyLength = strlen($key);
        echo "<tr><td>{$botId}</td><td>{$keyPreview}</td><td>{$keyLength}</td></tr>";
    } else {
        echo "<tr><td>{$botId}</td><td class='error'>❌ No API Key</td><td>0</td></tr>";
    }
}
echo "</table>";

// ===== 4. แนะนำการแก้ไข =====
echo "<h2>4. 🔧 วิธีแก้ไข</h2>";

$problemBots = [];
foreach ($accounts as $acc) {
    $botId = $acc['id'];
    $stmt = $db->prepare("SELECT is_enabled, gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$botId]);
    $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$aiSettings || $aiSettings['is_enabled'] != 1 || empty($aiSettings['gemini_api_key'])) {
        $problemBots[] = $botId;
    }
}

if (count($problemBots) > 0) {
    echo "<p class='error'>❌ Bot ID ที่มีปัญหา: " . implode(', ', $problemBots) . "</p>";
    echo "<p>วิธีแก้ไข:</p>";
    echo "<ol>";
    echo "<li>ไปที่หน้า <strong>AI Settings</strong> ในแอดมิน</li>";
    echo "<li>เลือก Bot ID ที่ต้องการตั้งค่า</li>";
    echo "<li>เปิดใช้งาน AI (Enable AI)</li>";
    echo "<li>ใส่ Gemini API Key</li>";
    echo "<li>เลือก AI Mode (sales/pharmacist)</li>";
    echo "<li>บันทึกการตั้งค่า</li>";
    echo "</ol>";
    
    echo "<p><strong>หรือใช้ SQL แก้ไขด่วน:</strong></p>";
    
    // ดึง API Key จาก Bot ID 1 เพื่อใช้เป็นตัวอย่าง
    $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = 1 LIMIT 1");
    $stmt->execute();
    $bot1Key = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($bot1Key && !empty($bot1Key['gemini_api_key'])) {
        echo "<p class='ok'>✅ พบ API Key จาก Bot ID 1 - สามารถใช้ร่วมกันได้</p>";
        foreach ($problemBots as $botId) {
            echo "<pre>";
            echo "-- สำหรับ Bot ID {$botId}\n";
            echo "UPDATE ai_settings \n";
            echo "SET is_enabled = 1, \n";
            echo "    ai_mode = 'sales', \n";
            echo "    gemini_api_key = (SELECT gemini_api_key FROM ai_settings WHERE line_account_id = 1 LIMIT 1),\n";
            echo "    sender_name = '🤖 AI Assistant'\n";
            echo "WHERE line_account_id = {$botId};\n";
            echo "</pre>";
        }
        
        echo "<p><a href='?fix=1' onclick=\"return confirm('ต้องการคัดลอก API Key จาก Bot ID 1 ไปยัง Bot อื่นทั้งหมด?')\">🔧 คลิกเพื่อแก้ไขอัตโนมัติ</a></p>";
    } else {
        echo "<p class='error'>❌ Bot ID 1 ก็ไม่มี API Key - ต้องตั้งค่าใหม่ทั้งหมด</p>";
    }
} else {
    echo "<p class='ok'>✅ ทุก Bot พร้อมใช้งาน!</p>";
}

// ===== 5. Auto Fix =====
if (isset($_GET['fix']) && $_GET['fix'] == 1) {
    echo "<h2>5. 🔧 กำลังแก้ไข...</h2>";
    
    // ดึง API Key จาก Bot ID 1
    $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = 1 LIMIT 1");
    $stmt->execute();
    $bot1Key = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($bot1Key && !empty($bot1Key['gemini_api_key'])) {
        $apiKey = $bot1Key['gemini_api_key'];
        $fixed = 0;
        
        foreach ($problemBots as $botId) {
            // ตรวจสอบว่ามี record หรือยัง
            $stmt = $db->prepare("SELECT id FROM ai_settings WHERE line_account_id = ?");
            $stmt->execute([$botId]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                // Update
                $stmt = $db->prepare("
                    UPDATE ai_settings 
                    SET is_enabled = 1, 
                        ai_mode = 'sales', 
                        gemini_api_key = ?,
                        sender_name = '🤖 AI Assistant'
                    WHERE line_account_id = ?
                ");
                $stmt->execute([$apiKey, $botId]);
            } else {
                // Insert
                $stmt = $db->prepare("
                    INSERT INTO ai_settings (line_account_id, is_enabled, ai_mode, gemini_api_key, sender_name)
                    VALUES (?, 1, 'sales', ?, '🤖 AI Assistant')
                ");
                $stmt->execute([$botId, $apiKey]);
            }
            $fixed++;
        }
        
        echo "<p class='ok'>✅ แก้ไขเรียบร้อย {$fixed} Bot(s)</p>";
        echo "<p><a href='?'>🔄 Refresh เพื่อตรวจสอบอีกครั้ง</a></p>";
    } else {
        echo "<p class='error'>❌ ไม่พบ API Key จาก Bot ID 1</p>";
    }
}

echo "<hr>";
echo "<p><small>Generated: " . date('Y-m-d H:i:s') . "</small></p>";
?>
