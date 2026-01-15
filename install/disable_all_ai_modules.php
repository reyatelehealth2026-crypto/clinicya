<?php
/**
 * ปิด AI Module ทุก Bot
 * ปิดทั้ง ai_settings และ ai_chat_settings
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔴 ปิด AI Module ทุก Bot</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .ok { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background-color: #f44336; color: white; }
</style>";

// ===== 1. แสดงสถานะปัจจุบัน =====
echo "<h2>1. สถานะปัจจุบัน</h2>";

echo "<h3>ai_settings (Webhook AI)</h3>";
$stmt = $db->query("
    SELECT la.id, la.bot_name, 
           COALESCE(ai.is_enabled, 0) as is_enabled,
           CASE WHEN ai.gemini_api_key != '' THEN 'มี' ELSE 'ไม่มี' END as has_key
    FROM line_accounts la
    LEFT JOIN ai_settings ai ON la.id = ai.line_account_id
    ORDER BY la.id
");
$aiSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Bot ID</th><th>Bot Name</th><th>Enabled</th><th>API Key</th></tr>";
foreach ($aiSettings as $row) {
    $enabled = $row['is_enabled'] ? '✅ เปิด' : '❌ ปิด';
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$enabled}</td><td>{$row['has_key']}</td></tr>";
}
echo "</table>";

echo "<h3>ai_chat_settings (AI Module)</h3>";
$stmt = $db->query("
    SELECT la.id, la.bot_name, 
           COALESCE(aic.is_enabled, 0) as is_enabled,
           CASE WHEN aic.gemini_api_key != '' THEN 'มี' ELSE 'ไม่มี' END as has_key
    FROM line_accounts la
    LEFT JOIN ai_chat_settings aic ON la.id = aic.line_account_id
    ORDER BY la.id
");
$aiChatSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Bot ID</th><th>Bot Name</th><th>Enabled</th><th>API Key</th></tr>";
foreach ($aiChatSettings as $row) {
    $enabled = $row['is_enabled'] ? '✅ เปิด' : '❌ ปิด';
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$enabled}</td><td>{$row['has_key']}</td></tr>";
}
echo "</table>";

// ===== 2. ปุ่มยืนยัน =====
if (!isset($_GET['confirm'])) {
    echo "<h2>2. ⚠️ คำเตือน</h2>";
    echo "<p class='error'>การดำเนินการนี้จะปิด AI ทั้งหมดในระบบ:</p>";
    echo "<ul>";
    echo "<li>ปิด <strong>ai_settings</strong> (Webhook AI) ทุก Bot</li>";
    echo "<li>ปิด <strong>ai_chat_settings</strong> (AI Module) ทุก Bot</li>";
    echo "<li>AI จะไม่ตอบข้อความอัตโนมัติอีกต่อไป</li>";
    echo "<li>ต้องเปิดใหม่ผ่านหน้า AI Settings</li>";
    echo "</ul>";
    
    echo "<p><a href='?confirm=1' onclick=\"return confirm('⚠️ ยืนยันการปิด AI ทุก Bot?\\n\\nการกระทำนี้ไม่สามารถย้อนกลับได้อัตโนมัติ')\" 
          style='display:inline-block; padding:15px 30px; background:#f44336; color:white; text-decoration:none; border-radius:5px; font-weight:bold;'>
          🔴 ยืนยันปิด AI ทุก Bot
          </a></p>";
    
    echo "<p><a href='check_bot_ai_settings.php'>← กลับไปตรวจสอบ</a></p>";
    exit;
}

// ===== 3. ดำเนินการปิด =====
echo "<h2>3. 🔧 กำลังปิด AI...</h2>";

try {
    $db->beginTransaction();
    
    // ปิด ai_settings
    $stmt = $db->prepare("UPDATE ai_settings SET is_enabled = 0");
    $stmt->execute();
    $aiSettingsCount = $stmt->rowCount();
    echo "<p class='ok'>✅ ปิด ai_settings แล้ว ({$aiSettingsCount} records)</p>";
    
    // ปิด ai_chat_settings
    $stmt = $db->prepare("UPDATE ai_chat_settings SET is_enabled = 0");
    $stmt->execute();
    $aiChatSettingsCount = $stmt->rowCount();
    echo "<p class='ok'>✅ ปิด ai_chat_settings แล้ว ({$aiChatSettingsCount} records)</p>";
    
    $db->commit();
    
    echo "<h2>4. ✅ สำเร็จ!</h2>";
    echo "<p class='ok'>ปิด AI Module ทุก Bot เรียบร้อยแล้ว</p>";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "<p class='error'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
}

// ===== 5. แสดงสถานะหลังปิด =====
echo "<h2>5. สถานะหลังปิด</h2>";

echo "<h3>ai_settings</h3>";
$stmt = $db->query("
    SELECT la.id, la.bot_name, 
           COALESCE(ai.is_enabled, 0) as is_enabled
    FROM line_accounts la
    LEFT JOIN ai_settings ai ON la.id = ai.line_account_id
    ORDER BY la.id
");
$afterAiSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Bot ID</th><th>Bot Name</th><th>Status</th></tr>";
foreach ($afterAiSettings as $row) {
    $status = $row['is_enabled'] ? '<span class="error">⚠️ ยังเปิดอยู่</span>' : '<span class="ok">✅ ปิดแล้ว</span>';
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$status}</td></tr>";
}
echo "</table>";

echo "<h3>ai_chat_settings</h3>";
$stmt = $db->query("
    SELECT la.id, la.bot_name, 
           COALESCE(aic.is_enabled, 0) as is_enabled
    FROM line_accounts la
    LEFT JOIN ai_chat_settings aic ON la.id = aic.line_account_id
    ORDER BY la.id
");
$afterAiChatSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>Bot ID</th><th>Bot Name</th><th>Status</th></tr>";
foreach ($afterAiChatSettings as $row) {
    $status = $row['is_enabled'] ? '<span class="error">⚠️ ยังเปิดอยู่</span>' : '<span class="ok">✅ ปิดแล้ว</span>';
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$status}</td></tr>";
}
echo "</table>";

// ===== 6. วิธีเปิดใหม่ =====
echo "<h2>6. 💡 วิธีเปิด AI ใหม่</h2>";
echo "<p>หากต้องการเปิด AI อีกครั้ง:</p>";
echo "<ol>";
echo "<li>ไปที่หน้า <strong>AI Settings</strong> ในแอดมิน</li>";
echo "<li>เลือก Bot ที่ต้องการเปิด</li>";
echo "<li>เปิดสวิตช์ <strong>Enable AI</strong></li>";
echo "<li>บันทึกการตั้งค่า</li>";
echo "</ol>";

echo "<p><strong>หรือใช้ SQL:</strong></p>";
echo "<pre>";
echo "-- เปิด AI สำหรับ Bot ID 1\n";
echo "UPDATE ai_settings SET is_enabled = 1 WHERE line_account_id = 1;\n";
echo "UPDATE ai_chat_settings SET is_enabled = 1 WHERE line_account_id = 1;\n";
echo "</pre>";

echo "<hr>";
echo "<p><a href='check_bot_ai_settings.php'>🔍 ตรวจสอบสถานะ AI</a></p>";
echo "<p><small>Generated: " . date('Y-m-d H:i:s') . "</small></p>";
?>
