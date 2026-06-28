<?php
/**
 * Medication Refill Reminder
 * แจ้งเตือนผู้ใช้เมื่อยาใกล้หมด (ควรสั่งซื้อใหม่)
 * 
 * Run: php cron/medication_refill_reminder.php
 * Schedule: Daily at 9:00 AM (0 9 * * *)
 * 
 * Logic:
 * - ดูจากประวัติการสั่งซื้อยา
 * - คำนวณว่ายาน่าจะหมดเมื่อไหร่ (จากจำนวนที่ซื้อ / วันที่ทาน)
 * - แจ้งเตือนล่วงหน้า 3 วันก่อนหมด (ตาม spec ของผู้ใช้)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../includes/liff-helper.php'; // reya_liff_url_or_oa()

$db = Database::getInstance()->getConnection();

echo "=== Medication Refill Reminder ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Ensure refill tracking table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS medication_refill_tracking (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        line_user_id VARCHAR(50),
        line_account_id INT,
        product_id INT NOT NULL,
        product_name VARCHAR(255),
        quantity_purchased INT DEFAULT 0,
        daily_dosage INT DEFAULT 1 COMMENT 'จำนวนที่ทานต่อวัน',
        purchase_date DATE,
        estimated_end_date DATE,
        reminder_sent_at TIMESTAMP NULL,
        order_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_end_date (estimated_end_date),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    echo "Table creation error: " . $e->getMessage() . "\n";
}

// Find medications that will run out in <=3 days
// and haven't been reminded in the last 7 days
$sql = "SELECT mrt.*,
               u.line_user_id, u.display_name,
               la.channel_access_token,
               p.image_url, p.price, p.sale_price,
               unp.drug_reminders as notify_enabled
        FROM medication_refill_tracking mrt
        JOIN users u ON mrt.user_id = u.id
        LEFT JOIN line_accounts la ON mrt.line_account_id = la.id
        LEFT JOIN business_items p ON mrt.product_id = p.id
        LEFT JOIN user_notification_preferences unp ON mrt.user_id = unp.user_id
        WHERE mrt.estimated_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND (mrt.reminder_sent_at IS NULL OR mrt.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
          AND (unp.drug_reminders IS NULL OR unp.drug_reminders = 1)
          AND u.line_user_id IS NOT NULL
          AND la.channel_access_token IS NOT NULL";

$stmt = $db->query($sql);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($items) . " medications running low\n\n";

$notified = 0;
$errors = 0;

foreach ($items as $item) {
    $daysLeft = (strtotime($item['estimated_end_date']) - strtotime('today')) / 86400;
    
    echo "Processing: {$item['product_name']}\n";
    echo "  User: {$item['display_name']} ({$item['line_user_id']})\n";
    echo "  Days left: {$daysLeft}\n";
    
    if (empty($item['channel_access_token'])) {
        echo "  ERROR: No channel access token\n\n";
        $errors++;
        continue;
    }
    
    // Create Flex Message
    $flexMessage = createRefillReminderFlex($item, $daysLeft);
    
    // Send via LINE API
    try {
        $line = new LineAPI($item['channel_access_token']);
        $result = $line->pushMessage($item['line_user_id'], [$flexMessage]);
        
        if ($result) {
            // Update reminder_sent_at
            $stmt = $db->prepare("UPDATE medication_refill_tracking SET reminder_sent_at = NOW() WHERE id = ?");
            $stmt->execute([$item['id']]);
            
            echo "  SUCCESS: Notification sent\n\n";
            $notified++;
        } else {
            echo "  ERROR: Failed to send\n\n";
            $errors++;
        }
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n\n";
        $errors++;
    }
}

echo "=== Summary ===\n";
echo "Notified: {$notified}\n";
echo "Errors: {$errors}\n";
echo "Done!\n";

/**
 * Create refill reminder Flex Message
 */
function createRefillReminderFlex($item, $daysLeft) {
    $productName = $item['product_name'];
    $currentPrice = $item['sale_price'] ?: $item['price'];
    $imageUrl = $item['image_url'] ?: 'https://via.placeholder.com/400x300?text=Medicine';
    
    // Urgency color based on days left
    $urgencyColor = '#F59E0B';
    $urgencyText = "เหลือ {$daysLeft} วัน";
    if ($daysLeft <= 0) {
        $urgencyColor = '#EF4444';
        $urgencyText = 'ครบกำหนด Refill';
    } elseif ($daysLeft <= 2) {
        $urgencyColor = '#EF4444';
        $urgencyText = "เหลือ {$daysLeft} วัน";
    }

    // LIFF-or-OA fallback: real liff_id → Mini App product page; empty/PENDING
    // → OA chat; nothing → '' (button is gated below so it is simply omitted).
    $productUrl = reya_liff_url_or_oa(
        $db,
        !empty($item['line_account_id']) ? (int) $item['line_account_id'] : null,
        '/shop/product?id=' . intval($item['product_id'])
    );

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'hero' => [
            'type' => 'image',
            'url' => $imageUrl,
            'size' => 'full',
            'aspectRatio' => '4:3',
            'aspectMode' => 'cover'
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => '🔁 Refill ยา', 'weight' => 'bold', 'color' => $urgencyColor, 'size' => 'sm', 'flex' => 1],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => $urgencyColor,
                            'cornerRadius' => 'md',
                            'paddingAll' => '4px',
                            'paddingStart' => '8px',
                            'paddingEnd' => '8px',
                            'flex' => 0,
                            'contents' => [
                                ['type' => 'text', 'text' => $urgencyText, 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'xs', 'align' => 'center']
                            ]
                        ]
                    ]
                ],
                ['type' => 'text', 'text' => $productName, 'weight' => 'bold', 'size' => 'lg', 'wrap' => true, 'margin' => 'md'],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '📅 ครบกำหนด Refill', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => date('d/m/Y', strtotime($item['estimated_end_date'])), 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                    ]
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => '💰 ราคา', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => '฿' . number_format($currentPrice), 'size' => 'sm', 'weight' => 'bold', 'color' => '#11B0A6', 'align' => 'end', 'flex' => 1]
                    ]
                ]
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => array_values(array_filter([
                // Only show the Mini-App "order refill" button when we have a real
                // LIFF / OA URL. Otherwise the message button below still lets the
                // customer reach the pharmacist — no broken Mini App link.
                $productUrl !== '' ? [
                    'type' => 'button',
                    'action' => ['type' => 'uri', 'label' => '🛒 สั่ง Refill เลย', 'uri' => $productUrl],
                    'style' => 'primary',
                    'color' => '#11B0A6',
                    'height' => 'sm'
                ] : null,
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'message',
                        'label' => '💬 ติดต่อเภสัชกรเพื่อสั่งซ้ำ',
                        'text' => "ขอ Refill ยา: {$productName}"
                    ],
                    'style' => 'secondary',
                    'height' => 'sm'
                ]
            ]))
        ]
    ];

    return [
        'type' => 'flex',
        'altText' => "🔁 Refill ยา: {$productName} ({$urgencyText})",
        'contents' => $bubble
    ];
}

/**
 * Helper function to add medication to refill tracking
 * Call this when user purchases medication
 */
function addMedicationToRefillTracking($db, $userId, $lineUserId, $lineAccountId, $productId, $productName, $quantity, $dailyDosage = 1, $orderId = null) {
    $daysSupply = $quantity / max(1, $dailyDosage);
    $estimatedEndDate = date('Y-m-d', strtotime("+{$daysSupply} days"));
    
    $stmt = $db->prepare("INSERT INTO medication_refill_tracking 
        (user_id, line_user_id, line_account_id, product_id, product_name, 
         quantity_purchased, daily_dosage, purchase_date, estimated_end_date, order_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
        ON DUPLICATE KEY UPDATE 
        quantity_purchased = quantity_purchased + VALUES(quantity_purchased),
        estimated_end_date = DATE_ADD(estimated_end_date, INTERVAL ? DAY),
        reminder_sent_at = NULL");
    
    $stmt->execute([
        $userId, $lineUserId, $lineAccountId, $productId, $productName,
        $quantity, $dailyDosage, $estimatedEndDate, $orderId, $daysSupply
    ]);
}
