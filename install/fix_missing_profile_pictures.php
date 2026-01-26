<?php
/**
 * Fix Missing Profile Pictures
 * แก้ไขข้อมูลลูกค้าที่ไม่มีรูปโปรไฟล์โดยดึงข้อมูลจาก LINE API ใหม่
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();

echo "=== Fix Missing Profile Pictures ===\n\n";

// ดึงรายการ LINE accounts
$manager = new LineAccountManager($db);
$accounts = $manager->getAllAccounts();

if (empty($accounts)) {
    echo "❌ No LINE accounts found\n";
    exit;
}

echo "Found " . count($accounts) . " LINE account(s)\n\n";

$totalFixed = 0;
$totalFailed = 0;

foreach ($accounts as $account) {
    echo "Processing Account: {$account['name']} (ID: {$account['id']})\n";
    echo str_repeat('-', 60) . "\n";
    
    $line = new LineAPI($account['channel_access_token'], $account['channel_secret']);
    
    // ดึงรายการลูกค้าที่ไม่มีรูปโปรไฟล์
    $stmt = $db->prepare("
        SELECT id, line_user_id, display_name, picture_url 
        FROM users 
        WHERE line_account_id = ? 
        AND (picture_url IS NULL OR picture_url = '' OR picture_url = 'null')
        AND is_blocked = 0
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$account['id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($users) . " users without profile pictures\n\n";
    
    if (empty($users)) {
        echo "✅ All users have profile pictures\n\n";
        continue;
    }
    
    foreach ($users as $user) {
        echo "User: {$user['display_name']} ({$user['line_user_id']})\n";
        
        try {
            // ดึงข้อมูลโปรไฟล์จาก LINE API
            $profile = $line->getProfile($user['line_user_id']);
            
            if ($profile && !empty($profile['pictureUrl'])) {
                // อัพเดทข้อมูล
                $updateStmt = $db->prepare("
                    UPDATE users 
                    SET picture_url = ?, 
                        display_name = ?,
                        status_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $profile['pictureUrl'],
                    $profile['displayName'] ?? $user['display_name'],
                    $profile['statusMessage'] ?? '',
                    $user['id']
                ]);
                
                echo "  ✅ Fixed - Picture URL: " . substr($profile['pictureUrl'], 0, 50) . "...\n";
                $totalFixed++;
                
                // อัพเดท account_followers ด้วย
                try {
                    $followerStmt = $db->prepare("
                        UPDATE account_followers 
                        SET picture_url = ?,
                            display_name = ?,
                            status_message = ?,
                            updated_at = NOW()
                        WHERE line_account_id = ? AND line_user_id = ?
                    ");
                    $followerStmt->execute([
                        $profile['pictureUrl'],
                        $profile['displayName'] ?? $user['display_name'],
                        $profile['statusMessage'] ?? '',
                        $account['id'],
                        $user['line_user_id']
                    ]);
                } catch (Exception $e) {
                    // Table might not exist
                }
                
            } else {
                echo "  ⚠️  No picture URL in profile response\n";
                $totalFailed++;
            }
            
            // Rate limiting - หยุด 0.1 วินาทีระหว่างแต่ละ request
            usleep(100000);
            
        } catch (Exception $e) {
            echo "  ❌ Error: " . $e->getMessage() . "\n";
            $totalFailed++;
        }
        
        echo "\n";
    }
    
    echo "\n";
}

echo str_repeat('=', 60) . "\n";
echo "Summary:\n";
echo "  ✅ Fixed: {$totalFixed} users\n";
echo "  ❌ Failed: {$totalFailed} users\n";
echo str_repeat('=', 60) . "\n";
