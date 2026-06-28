<?php
/**
 * Fix AI Sales Mode - แก้ไขปัญหา AI ไม่ใช้ Sales Mode
 * 
 * ปัญหา: เมื่อพิมพ์ /ai หรือ / command ระบบใช้ PharmacyAI แทน Sales AI
 * สาเหตุ: ai_settings.is_enabled = 0 หรือ gemini_api_key ว่าง
 * 
 * วิธีใช้: เปิดไฟล์นี้ในเบราว์เซอร์
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔧 Fix AI Sales Mode</h1>";
echo "<style>
body{font-family:sans-serif;padding:20px;max-width:800px;margin:0 auto;} 
.ok{color:green;font-weight:bold;} 
.error{color:red;font-weight:bold;} 
.box{background:#f5f5f5;padding:15px;border-radius:8px;margin:10px 0;}
</style>";

// Get line_account_id
$stmt = $db->query("SELECT id, name FROM line_accounts LIMIT 1");
$account = $stmt->fetch(PDO::FETCH_ASSOC);
$lineAccountId = $account ? $account['id'] : null;

if (!$lineAccountId) {
    echo "<p class='error'>❌ ไม่พบ LINE Account</p>";
    exit;
}

echo "<p>LINE Account: <b>{$account['name']}</b> (ID: {$lineAccountId})</p>";

// ===== Step 1: Get API key from ai_chat_settings =====
$apiKey = null;
try {
    $stmt = $db->prepare("SELECT gemini_api_key FROM ai_chat_settings WHERE line_account_id = ? AND gemini_api_key IS NOT NULL AND gemini_api_key != ''");
    $stmt->execute([$lineAccountId]);
    $apiKey = $stmt->fetchColumn();
} catch (Exception $e) {}

// Fallback: try without line_account_id
if (!$apiKey) {
    try {
        $stmt = $db->query("SELECT gemini_api_key FROM ai_chat_settings WHERE gemini_api_key IS NOT NULL AND gemini_api_key != '' LIMIT 1");
        $apiKey = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

echo "<div class='box'>";
echo "<h3>Step 1: ตรวจสอบ API Key</h3>";
if ($apiKey) {
    echo "<p class='ok'>✅ พบ API Key ใน ai_chat_settings (" . strlen($apiKey) . " chars)</p>";
} else {
    echo "<p class='error'>❌ ไม่พบ API Key ใน ai_chat_settings</p>";
    
    // Try ai_settings
    $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? AND gemini_api_key IS NOT NULL AND gemini_api_key != ''");
    $stmt->execute([$lineAccountId]);
    $apiKey = $stmt->fetchColumn();
    
    if ($apiKey) {
        echo "<p class='ok'>✅ พบ API Key ใน ai_settings (" . strlen($apiKey) . " chars)</p>";
    } else {
        echo "<p class='error'>❌ ไม่พบ API Key ในทั้งสอง table - กรุณาใส่ API Key ในหน้าตั้งค่า AI</p>";
    }
}
echo "</div>";

// ===== Step 2: Check/Create ai_settings =====
echo "<div class='box'>";
echo "<h3>Step 2: ตรวจสอบ ai_settings</h3>";

$stmt = $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ?");
$stmt->execute([$lineAccountId]);
$aiSettings = $stmt->fetch(PDO::FETCH_ASSOC);

if ($aiSettings) {
    echo "<p>พบข้อมูลใน ai_settings:</p>";
    echo "<ul>";
    echo "<li>is_enabled: " . ($aiSettings['is_enabled'] ? '✅ Yes' : '❌ No') . "</li>";
    echo "<li>ai_mode: <b>" . ($aiSettings['ai_mode'] ?? 'NULL') . "</b></li>";
    echo "<li>gemini_api_key: " . (empty($aiSettings['gemini_api_key']) ? '❌ EMPTY' : '✅ SET') . "</li>";
    echo "</ul>";
} else {
    echo "<p class='error'>❌ ไม่พบข้อมูลใน ai_settings</p>";
}
echo "</div>";

// ===== Step 3: Fix =====
echo "<div class='box'>";
echo "<h3>Step 3: แก้ไข</h3>";

if (isset($_POST['fix'])) {
    try {
        if ($aiSettings) {
            // Update existing - ใช้ COLLATE เพื่อหลีกเลี่ยง collation error
            $db->exec("UPDATE ai_settings SET is_enabled = 1, ai_mode = 'sales' WHERE line_account_id = {$lineAccountId}");
            
            if ($apiKey && empty($aiSettings['gemini_api_key'])) {
                $stmt = $db->prepare("UPDATE ai_settings SET gemini_api_key = ? WHERE line_account_id = ?");
                $stmt->execute([$apiKey, $lineAccountId]);
            }
            
            echo "<p class='ok'>✅ อัพเดท ai_settings สำเร็จ!</p>";
        } else {
            // Insert new
            $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, is_enabled, ai_mode, gemini_api_key, model, temperature, max_tokens) VALUES (?, 1, 'sales', ?, 'gemini-2.5-flash', 0.7, 500)");
            $stmt->execute([$lineAccountId, $apiKey]);
            
            echo "<p class='ok'>✅ สร้าง ai_settings ใหม่สำเร็จ!</p>";
        }
        
        echo "<p><a href='debug_ai_flow.php'>🔍 ตรวจสอบผลลัพธ์</a></p>";
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p>กดปุ่มด้านล่างเพื่อแก้ไข:</p>";
    echo "<ul>";
    echo "<li>ตั้ง is_enabled = 1</li>";
    echo "<li>ตั้ง ai_mode = 'sales'</li>";
    if ($apiKey && (!$aiSettings || empty($aiSettings['gemini_api_key']))) {
        echo "<li>Copy API Key จาก ai_chat_settings</li>";
    }
    echo "</ul>";
}
echo "</div>";

?>

<form method="POST">
    <button type="submit" name="fix" style="padding:15px 30px;background:#10b981;color:white;border:none;border-radius:8px;cursor:pointer;font-size:18px;margin:20px 0;">
        🚀 แก้ไขเลย!
    </button>
</form>

<hr>
<h2>📋 ขั้นตอนหลังแก้ไข</h2>
<ol>
    <li>กดปุ่ม "แก้ไขเลย!" ด้านบน</li>
    <li>ไปที่ <a href="debug_ai_flow.php">debug_ai_flow.php</a> เพื่อตรวจสอบ</li>
    <li>ทดสอบส่งข้อความ /ai หรือ /แนะนำสินค้า ใน LINE</li>
    <li>ตรวจสอบ dev_logs ว่าเข้า "Processing AI request (Sales Mode)" หรือไม่</li>
</ol>
