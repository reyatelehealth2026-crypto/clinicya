<?php
/**
 * Analyze Account 3 Messages - ดูว่าข้อความมาจากไหน
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Analyze Account 3 Messages ===\n\n";

// 1. ดูข้อความล่าสุด 20 รายการพร้อมรายละเอียด
echo "Recent Messages with Details:\n";
echo str_repeat("-", 100) . "\n";

$stmt = $db->query("
    SELECT 
        m.id,
        m.message_type,
        m.content,
        m.reply_token,
        m.created_at,
        u.line_user_id,
        u.display_name
    FROM messages m
    LEFT JOIN users u ON m.user_id = u.id
    WHERE m.line_account_id = 3
    AND m.direction = 'incoming'
    ORDER BY m.created_at DESC
    LIMIT 20
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hasToken = !empty($row['reply_token']) ? 'YES' : 'NO';
    $tokenPreview = !empty($row['reply_token']) ? substr($row['reply_token'], 0, 20) . '...' : 'NULL';
    
    echo "ID: {$row['id']} | Type: {$row['message_type']} | Token: {$hasToken}\n";
    echo "  User: {$row['display_name']} ({$row['line_user_id']})\n";
    echo "  Content: " . mb_substr($row['content'], 0, 50) . "\n";
    echo "  Token: {$tokenPreview}\n";
    echo "  Time: {$row['created_at']}\n";
    echo str_repeat("-", 100) . "\n";
}

echo "\n";

// 2. ตรวจสอบ message types
echo "Message Types Distribution:\n";
echo str_repeat("-", 100) . "\n";

$stmt = $db->query("
    SELECT 
        message_type,
        COUNT(*) as count,
        SUM(CASE WHEN reply_token IS NOT NULL AND reply_token != '' THEN 1 ELSE 0 END) as with_token
    FROM messages
    WHERE line_account_id = 3
    AND direction = 'incoming'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY message_type
    ORDER BY count DESC
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $percentage = $row['count'] > 0 ? round(($row['with_token'] / $row['count']) * 100, 2) : 0;
    echo "{$row['message_type']}: {$row['count']} messages | {$row['with_token']} with token ({$percentage}%)\n";
}

echo "\n";

// 3. ตรวจสอบว่ามี webhook_events table หรือไม่
echo "Checking webhook_events table:\n";
echo str_repeat("-", 100) . "\n";

try {
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM webhook_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $count = $stmt->fetchColumn();
    echo "Webhook events in last hour: {$count}\n";
    
    // ดู event_id ล่าสุด
    $stmt = $db->query("
        SELECT event_id, created_at
        FROM webhook_events
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    echo "\nRecent webhook event IDs:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  {$row['event_id']} | {$row['created_at']}\n";
    }
} catch (Exception $e) {
    echo "webhook_events table not found or error: " . $e->getMessage() . "\n";
}

echo "\n";

// 4. แนะนำการแก้ไข
echo "=== Analysis ===\n";
echo "ถ้าข้อความทั้งหมดไม่มี reply token แสดงว่า:\n\n";
echo "1. LINE API ไม่ส่ง replyToken มาจริงๆ\n";
echo "   - อาจเป็น account แบบ Free/Basic ที่มีข้อจำกัด\n";
echo "   - หรือ webhook events ถูกส่งแบบ notification only\n\n";
echo "2. ข้อความมาจาก source อื่น\n";
echo "   - LIFF postback\n";
echo "   - Flex Message action\n";
echo "   - Rich Menu action\n\n";
echo "3. Webhook.php ไม่ได้บันทึก replyToken\n";
echo "   - ตรวจสอบโค้ดที่บันทึกข้อความ\n";
echo "   - ดูว่า \$replyToken ถูกส่งต่อหรือไม่\n\n";

echo "=== Solution ===\n";
echo "เนื่องจาก Account 3 ไม่มี reply token เลย\n";
echo "ระบบจะต้องใช้ Push Message แทน Reply Message\n\n";
echo "ซึ่งหมายความว่า:\n";
echo "- ใช้ quota ของ LINE Official Account\n";
echo "- ไม่สามารถ reply ฟรีได้\n";
echo "- ต้องมี quota เพียงพอสำหรับการส่งข้อความ\n\n";
echo "แนะนำ: ตรวจสอบ LINE Official Account Plan ของ Account 3\n";
