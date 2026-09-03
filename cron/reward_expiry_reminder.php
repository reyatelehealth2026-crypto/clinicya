<?php
/**
 * Reward Expiry Reminder Cron Job
 * Requirements: 23.11 - Send reminder 3 days before reward expiry
 * 
 * Run this cron job daily:
 * 0 9 * * * php /path/to/cron/reward_expiry_reminder.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LoyaltyPoints.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/NotificationGate.php';

$db = Database::getInstance()->getConnection();

// Get all active LINE accounts
$stmt = $db->query("SELECT id, channel_access_token FROM line_accounts WHERE is_active = 1");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalReminders = 0;

foreach ($accounts as $account) {
    $lineAccountId = $account['id'];
    $accessToken = $account['channel_access_token'];
    
    if (empty($accessToken)) {
        continue;
    }
    
    $loyalty = new LoyaltyPoints($db, $lineAccountId);
    $gate = new NotificationGate($db);
    
    // Get redemptions expiring within 3 days
    $expiringRedemptions = $loyalty->getExpiringRedemptions(3);
    
    foreach ($expiringRedemptions as $redemption) {
        try {
            // Calculate days until expiry
            $expiryDate = new DateTime($redemption['expires_at']);
            $now = new DateTime();
            $daysLeft = $now->diff($expiryDate)->days;
            
            // Build notification message
            $message = buildExpiryReminderMessage($redemption, $daysLeft);
            
            // Send LINE push notification
            // ผ่าน Gate — เคารพสวิตช์ promotions, ช่วงห้ามรบกวน และเพดานต่อวัน
            $result = $gate->send([
                'user_id' => (int) $redemption['user_id'],
                'line_user_id' => $redemption['line_user_id'],
                'line_account_id' => $lineAccountId,
                'channel_access_token' => $accessToken,
                'event_type' => 'reward_expiry',
                'dedupe_key' => 'reward_expiry:' . $redemption['id'],
                'messages' => (is_array($message) && isset($message['type'])) ? [$message] : (array) $message,
            ])['sent'];
            
            if ($result) {
                // Mark reminder as sent
                $loyalty->markExpiryReminderSent($redemption['id']);
                $totalReminders++;
                
                echo "Sent expiry reminder for redemption #{$redemption['id']} to user {$redemption['display_name']}\n";
            }
        } catch (Exception $e) {
            error_log("Failed to send expiry reminder for redemption #{$redemption['id']}: " . $e->getMessage());
        }
    }
}

echo "Total expiry reminders sent: {$totalReminders}\n";

/**
 * Build expiry reminder message
 * @param array $redemption Redemption data
 * @param int $daysLeft Days until expiry
 * @return array LINE message
 */
function buildExpiryReminderMessage($redemption, $daysLeft) {
    $expiryText = $daysLeft == 0 ? 'วันนี้' : "อีก {$daysLeft} วัน";
    
    return [
        'type' => 'flex',
        'altText' => "รางวัลของคุณใกล้หมดอายุ - {$redemption['reward_name']}",
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '⚠️ รางวัลใกล้หมดอายุ',
                        'weight' => 'bold',
                        'size' => 'md',
                        'color' => '#F59E0B'
                    ]
                ],
                'backgroundColor' => '#FEF3C7',
                'paddingAll' => 'lg'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $redemption['reward_name'],
                        'weight' => 'bold',
                        'size' => 'lg',
                        'wrap' => true
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'รหัส:',
                                'size' => 'sm',
                                'color' => '#6B7280',
                                'flex' => 0
                            ],
                            [
                                'type' => 'text',
                                'text' => $redemption['redemption_code'],
                                'size' => 'sm',
                                'weight' => 'bold',
                                'color' => '#764ba2'
                            ]
                        ],
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'text',
                        'text' => "หมดอายุ{$expiryText}",
                        'size' => 'sm',
                        'color' => '#EF4444',
                        'margin' => 'md'
                    ]
                ],
                'paddingAll' => 'lg'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'กรุณาใช้รางวัลก่อนหมดอายุ',
                        'size' => 'xs',
                        'color' => '#9CA3AF',
                        'align' => 'center'
                    ]
                ],
                'paddingAll' => 'md'
            ]
        ]
    ];
}
