<?php
/**
 * Check Profile Issues
 * ตรวจสอบปัญหาเกี่ยวกับข้อมูลโปรไฟล์ลูกค้า
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Profile Issues Report ===\n\n";

// 1. ตรวจสอบลูกค้าที่ไม่มีรูปโปรไฟล์
echo "1. Users without profile pictures:\n";
echo str_repeat('-', 60) . "\n";

$stmt = $db->query("
    SELECT 
        line_account_id,
        COUNT(*) as count,
        COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_count
    FROM users 
    WHERE (picture_url IS NULL OR picture_url = '' OR picture_url = 'null')
    AND is_blocked = 0
    GROUP BY line_account_id
    ORDER BY count DESC
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "✅ All users have profile pictures\n\n";
} else {
    $total = 0;
    $recentTotal = 0;
    foreach ($results as $row) {
        $accountId = $row['line_account_id'] ?? 'NULL';
        echo "  Account ID {$accountId}: {$row['count']} users ({$row['recent_count']} in last 7 days)\n";
        $total += $row['count'];
        $recentTotal += $row['recent_count'];
    }
    echo "\n  Total: {$total} users without pictures\n";
    echo "  Recent (7 days): {$recentTotal} users\n\n";
}

// 2. ตรวจสอบลูกค้าที่มีชื่อเป็น 'Unknown'
echo "2. Users with 'Unknown' display name:\n";
echo str_repeat('-', 60) . "\n";

$stmt = $db->query("
    SELECT 
        line_account_id,
        COUNT(*) as count
    FROM users 
    WHERE display_name = 'Unknown'
    AND is_blocked = 0
    GROUP BY line_account_id
    ORDER BY count DESC
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "✅ No users with 'Unknown' name\n\n";
} else {
    $total = 0;
    foreach ($results as $row) {
        $accountId = $row['line_account_id'] ?? 'NULL';
        echo "  Account ID {$accountId}: {$row['count']} users\n";
        $total += $row['count'];
    }
    echo "\n  Total: {$total} users with 'Unknown' name\n\n";
}

// 3. ตรวจสอบลูกค้าที่สร้างใหม่ในช่วง 24 ชั่วโมงที่ผ่านมา
echo "3. Recently created users (last 24 hours):\n";
echo str_repeat('-', 60) . "\n";

$stmt = $db->query("
    SELECT 
        line_account_id,
        COUNT(*) as total,
        COUNT(CASE WHEN picture_url IS NULL OR picture_url = '' THEN 1 END) as no_picture,
        COUNT(CASE WHEN display_name = 'Unknown' THEN 1 END) as unknown_name
    FROM users 
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY line_account_id
    ORDER BY total DESC
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    echo "No new users in last 24 hours\n\n";
} else {
    foreach ($results as $row) {
        $accountId = $row['line_account_id'] ?? 'NULL';
        echo "  Account ID {$accountId}:\n";
        echo "    Total: {$row['total']} users\n";
        echo "    No picture: {$row['no_picture']} users\n";
        echo "    Unknown name: {$row['unknown_name']} users\n";
        
        if ($row['no_picture'] > 0 || $row['unknown_name'] > 0) {
            echo "    ⚠️  Issues detected!\n";
        }
        echo "\n";
    }
}

// 4. ตรวจสอบ error logs ล่าสุด
echo "4. Recent profile-related errors (last 100):\n";
echo str_repeat('-', 60) . "\n";

try {
    $stmt = $db->query("
        SELECT 
            created_at,
            source,
            message,
            data
        FROM dev_logs 
        WHERE (
            message LIKE '%profile%' 
            OR message LIKE '%picture%'
            OR source LIKE '%getOrCreateUser%'
        )
        AND log_type = 'error'
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($logs)) {
        echo "✅ No recent profile errors\n\n";
    } else {
        foreach ($logs as $log) {
            echo "  [{$log['created_at']}] {$log['source']}: {$log['message']}\n";
            if (!empty($log['data'])) {
                $data = json_decode($log['data'], true);
                if ($data) {
                    echo "    Data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "⚠️  dev_logs table not available\n\n";
}

// 5. แสดงตัวอย่างลูกค้าที่มีปัญหา
echo "5. Sample users with issues (last 5):\n";
echo str_repeat('-', 60) . "\n";

$stmt = $db->query("
    SELECT 
        id,
        line_user_id,
        line_account_id,
        display_name,
        picture_url,
        created_at
    FROM users 
    WHERE (picture_url IS NULL OR picture_url = '' OR display_name = 'Unknown')
    AND is_blocked = 0
    ORDER BY created_at DESC 
    LIMIT 5
");
$samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($samples)) {
    echo "✅ No users with issues\n\n";
} else {
    foreach ($samples as $user) {
        echo "  User ID: {$user['id']}\n";
        echo "    LINE User ID: {$user['line_user_id']}\n";
        echo "    Account ID: " . ($user['line_account_id'] ?? 'NULL') . "\n";
        echo "    Display Name: {$user['display_name']}\n";
        echo "    Picture URL: " . ($user['picture_url'] ?: 'EMPTY') . "\n";
        echo "    Created: {$user['created_at']}\n";
        echo "\n";
    }
}

echo str_repeat('=', 60) . "\n";
echo "Report completed at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 60) . "\n";
