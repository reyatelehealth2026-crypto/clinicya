<?php
/**
 * Check Reply Token by Account - ตรวจสอบจาก Database
 * ใช้ไฟล์นี้เพื่อดูว่า Account ไหนได้รับ reply token บ้าง
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== Reply Token Analysis by LINE Account ===\n\n";
    
    // 1. ข้อความล่าสุด 30 รายการ พร้อม reply_token
    echo "Recent Messages (Last 30):\n";
    echo str_repeat("-", 100) . "\n";
    
    $stmt = $db->query("
        SELECT 
            m.id,
            m.line_account_id,
            COALESCE(la.account_name, 'Unknown') as account_name,
            u.display_name,
            CASE 
                WHEN m.reply_token IS NOT NULL AND m.reply_token != '' THEN 'YES'
                ELSE 'NO'
            END as has_token,
            CASE 
                WHEN m.reply_token IS NOT NULL AND m.reply_token != '' 
                THEN CONCAT(LEFT(m.reply_token, 30), '...')
                ELSE 'NULL'
            END as token_preview,
            LEFT(m.content, 40) as content_preview,
            m.created_at
        FROM messages m
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN line_accounts la ON m.line_account_id = la.id
        WHERE m.direction = 'incoming'
        ORDER BY m.created_at DESC
        LIMIT 30
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "ID:%5d | Acc:%2s (%-15s) | Token:%-3s | %s | %s\n",
            $row['id'],
            $row['line_account_id'] ?? 'NULL',
            substr($row['account_name'], 0, 15),
            $row['has_token'],
            substr($row['content_preview'], 0, 30),
            $row['created_at']
        );
    }
    
    echo "\n\n";
    
    // 2. สถิติแยกตาม Account (24 ชั่วโมงล่าสุด)
    echo "Statistics by Account (Last 24 hours):\n";
    echo str_repeat("-", 100) . "\n";
    
    $stmt = $db->query("
        SELECT 
            COALESCE(m.line_account_id, 0) as account_id,
            COALESCE(la.account_name, 'Unknown') as account_name,
            COUNT(*) as total_messages,
            SUM(CASE WHEN m.reply_token IS NOT NULL AND m.reply_token != '' THEN 1 ELSE 0 END) as with_token,
            SUM(CASE WHEN m.reply_token IS NULL OR m.reply_token = '' THEN 1 ELSE 0 END) as without_token,
            ROUND(
                (SUM(CASE WHEN m.reply_token IS NOT NULL AND m.reply_token != '' THEN 1 ELSE 0 END) * 100.0) / COUNT(*),
                2
            ) as token_percentage
        FROM messages m
        LEFT JOIN line_accounts la ON m.line_account_id = la.id
        WHERE m.direction = 'incoming'
        AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY m.line_account_id, la.account_name
        ORDER BY m.line_account_id
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "Account %2d (%-20s): Total:%4d | With Token:%4d (%6.2f%%) | Without:%4d\n",
            $row['account_id'],
            substr($row['account_name'], 0, 20),
            $row['total_messages'],
            $row['with_token'],
            $row['token_percentage'],
            $row['without_token']
        );
        
        // แจ้งเตือนถ้า Account มี token น้อยกว่า 50%
        if ($row['token_percentage'] < 50 && $row['total_messages'] > 5) {
            echo "  ⚠️  WARNING: Account {$row['account_id']} has low reply token rate!\n";
        }
    }
    
    echo "\n\n";
    
    // 3. ตรวจสอบ Webhook URL configuration
    echo "LINE Account Webhook Configuration:\n";
    echo str_repeat("-", 100) . "\n";
    
    $stmt = $db->query("
        SELECT 
            id,
            account_name,
            webhook_url,
            CASE WHEN channel_access_token IS NOT NULL THEN 'Yes' ELSE 'No' END as has_token
        FROM line_accounts
        ORDER BY id
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Account {$row['id']}: {$row['account_name']}\n";
        echo "  Webhook: {$row['webhook_url']}\n";
        echo "  Has Access Token: {$row['has_token']}\n";
        
        // ตรวจสอบว่า webhook_url มี ?account= parameter หรือไม่
        if (!empty($row['webhook_url']) && strpos($row['webhook_url'], '?account=') === false) {
            echo "  ⚠️  WARNING: Webhook URL missing ?account={$row['id']} parameter!\n";
        }
        echo "\n";
    }
    
    echo "\n=== Recommendations ===\n";
    echo "1. ตรวจสอบว่า Webhook URL ของแต่ละ Account มี ?account=X parameter\n";
    echo "2. ถ้า Account 3 ไม่มี token ให้ตรวจสอบ:\n";
    echo "   - LINE Developers Console > Messaging API > Webhook URL\n";
    echo "   - ต้องเป็น: https://yourdomain.com/webhook.php?account=3\n";
    echo "3. ทดสอบส่งข้อความไปที่ Account 3 แล้วดู webhook_debug.log\n";
    echo "4. ตรวจสอบ dev_logs table สำหรับ errors\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
