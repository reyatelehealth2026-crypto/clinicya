<?php
/**
 * Debug Reply Token by LINE Account
 * ตรวจสอบว่า reply_token ถูกบันทึกถูกต้องสำหรับแต่ละ LINE Account หรือไม่
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Debug Reply Token by LINE Account ===\n\n";

// 1. ตรวจสอบ LINE Accounts ที่มีในระบบ
echo "1. LINE Accounts in System:\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT id, account_name, 
           CASE WHEN channel_access_token IS NOT NULL THEN 'Yes' ELSE 'No' END as has_token
    FROM line_accounts 
    ORDER BY id
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']} | Name: {$row['account_name']} | Has Token: {$row['has_token']}\n";
}
echo "\n";

// 2. ตรวจสอบข้อความล่าสุดพร้อม reply_token แยกตาม line_account_id
echo "2. Recent Messages with Reply Token (Last 20):\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT 
        m.id,
        m.line_account_id,
        la.account_name,
        u.display_name,
        u.line_user_id,
        m.reply_token,
        m.content,
        m.created_at
    FROM messages m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN line_accounts la ON m.line_account_id = la.id
    WHERE m.direction = 'incoming'
    ORDER BY m.created_at DESC
    LIMIT 20
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hasToken = !empty($row['reply_token']) ? 'YES' : 'NO';
    $tokenPreview = !empty($row['reply_token']) ? substr($row['reply_token'], 0, 20) . '...' : 'NULL';
    $accountName = $row['account_name'] ?? 'NULL';
    $accountId = $row['line_account_id'] ?? 'NULL';
    
    echo "Msg ID: {$row['id']} | Account: {$accountId} ({$accountName})\n";
    echo "  User: {$row['display_name']} ({$row['line_user_id']})\n";
    echo "  Has Token: {$hasToken} | Token: {$tokenPreview}\n";
    echo "  Content: " . mb_substr($row['content'], 0, 50) . "\n";
    echo "  Time: {$row['created_at']}\n";
    echo str_repeat("-", 80) . "\n";
}
echo "\n";

// 3. สถิติ reply_token แยกตาม line_account_id
echo "3. Reply Token Statistics by Account:\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT 
        m.line_account_id,
        la.account_name,
        COUNT(*) as total_messages,
        SUM(CASE WHEN m.reply_token IS NOT NULL AND m.reply_token != '' THEN 1 ELSE 0 END) as with_token,
        SUM(CASE WHEN m.reply_token IS NULL OR m.reply_token = '' THEN 1 ELSE 0 END) as without_token
    FROM messages m
    LEFT JOIN line_accounts la ON m.line_account_id = la.id
    WHERE m.direction = 'incoming'
    AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    GROUP BY m.line_account_id, la.account_name
    ORDER BY m.line_account_id
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $accountId = $row['line_account_id'] ?? 'NULL';
    $accountName = $row['account_name'] ?? 'Unknown';
    $percentage = $row['total_messages'] > 0 ? round(($row['with_token'] / $row['total_messages']) * 100, 2) : 0;
    
    echo "Account ID: {$accountId} ({$accountName})\n";
    echo "  Total Messages: {$row['total_messages']}\n";
    echo "  With Token: {$row['with_token']} ({$percentage}%)\n";
    echo "  Without Token: {$row['without_token']}\n";
    echo str_repeat("-", 80) . "\n";
}
echo "\n";

// 4. ตรวจสอบ users table - reply_token ล่าสุด
echo "4. Users with Reply Token (Last 10):\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT 
        u.id,
        u.line_account_id,
        la.account_name,
        u.display_name,
        u.line_user_id,
        u.reply_token,
        u.reply_token_expires
    FROM users u
    LEFT JOIN line_accounts la ON u.line_account_id = la.id
    WHERE u.reply_token IS NOT NULL
    ORDER BY u.reply_token_expires DESC
    LIMIT 10
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $tokenPreview = substr($row['reply_token'], 0, 20) . '...';
    $accountId = $row['line_account_id'] ?? 'NULL';
    $accountName = $row['account_name'] ?? 'Unknown';
    $expired = strtotime($row['reply_token_expires']) < time() ? 'EXPIRED' : 'VALID';
    
    echo "User ID: {$row['id']} | Account: {$accountId} ({$accountName})\n";
    echo "  Name: {$row['display_name']}\n";
    echo "  LINE ID: {$row['line_user_id']}\n";
    echo "  Token: {$tokenPreview}\n";
    echo "  Expires: {$row['reply_token_expires']} ({$expired})\n";
    echo str_repeat("-", 80) . "\n";
}
echo "\n";

// 5. ตรวจสอบ webhook events ล่าสุด
echo "5. Recent Webhook Events (Last 10):\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT 
        ae.id,
        ae.line_account_id,
        la.account_name,
        ae.event_type,
        ae.line_user_id,
        ae.reply_token,
        ae.timestamp
    FROM account_events ae
    LEFT JOIN line_accounts la ON ae.line_account_id = la.id
    WHERE ae.event_type = 'message'
    ORDER BY ae.timestamp DESC
    LIMIT 10
");

if ($stmt->rowCount() > 0) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hasToken = !empty($row['reply_token']) ? 'YES' : 'NO';
        $tokenPreview = !empty($row['reply_token']) ? substr($row['reply_token'], 0, 20) . '...' : 'NULL';
        $accountId = $row['line_account_id'] ?? 'NULL';
        $accountName = $row['account_name'] ?? 'Unknown';
        
        echo "Event ID: {$row['id']} | Account: {$accountId} ({$accountName})\n";
        echo "  Type: {$row['event_type']}\n";
        echo "  LINE User: {$row['line_user_id']}\n";
        echo "  Has Token: {$hasToken} | Token: {$tokenPreview}\n";
        echo "  Time: {$row['timestamp']}\n";
        echo str_repeat("-", 80) . "\n";
    }
} else {
    echo "No account_events found (table may not have reply_token column)\n";
}
echo "\n";

echo "=== Analysis ===\n";
echo "ถ้า Account ID 3 มี 'Without Token' สูง แสดงว่า:\n";
echo "1. Webhook ของ Account 3 อาจไม่ได้ส่ง replyToken มา\n";
echo "2. Webhook URL ของ Account 3 อาจไม่ถูกต้อง\n";
echo "3. LINE Account 3 อาจมีปัญหาการตั้งค่า\n\n";
echo "แนะนำ:\n";
echo "- ตรวจสอบ Webhook URL ของแต่ละ account ใน LINE Developers Console\n";
echo "- ตรวจสอบว่า webhook.php รับ replyToken จาก LINE API ถูกต้องหรือไม่\n";
echo "- ดู dev_logs เพื่อดูว่ามี error อะไรเกิดขึ้นกับ Account 3\n";
