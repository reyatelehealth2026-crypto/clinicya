<?php
/**
 * Restock Notification - แจ้งเตือนสินค้าเข้าใหม่
 * แจ้งเตือนลูกค้าเมื่อสินค้าในรายการโปรดกลับมามีสต็อก
 * 
 * Run: php cron/restock_notification.php
 * Schedule: Every hour or when stock changes
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';

$db = Database::getInstance()->getConnection();

echo "=== Restock Notification ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Ensure restock_notifications table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS restock_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wishlist_id INT,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        notification_type VARCHAR(50) DEFAULT 'restock',
        old_stock INT DEFAULT 0,
        new_stock INT DEFAULT 0,
        message TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    echo "Table creation error: " . $e->getMessage() . "\n";
}

// Find wishlist items where:
// 1. Product was out of stock (stock = 0) when added or last checked
// 2. Product now has stock > 0
// 3. User has notify_on_restock = 1
// 4. Not notified in last 24 hours
$sql = "SELECT w.*, 
               p.name as product_name, p.price, p.sale_price, p.image_url, p.stock,
               u.line_user_id, u.display_name,
               la.channel_access_token,
               unp.restock_alerts
        FROM user_wishlist w
        JOIN business_items p ON w.product_id = p.id
        JOIN users u ON w.user_id = u.id
        LEFT JOIN line_accounts la ON w.line_account_id = la.id
        LEFT JOIN user_notification_preferences unp ON w.user_id = unp.user_id
        WHERE w.notify_on_restock = 1
          AND p.is_active = 1
          AND p.stock > 0
          AND (unp.restock_alerts IS NULL OR unp.restock_alerts = 1)
          AND (w.notified_at IS NULL OR w.notified_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
          AND NOT EXISTS (
              SELECT 1 FROM restock_notifications rn 
              WHERE rn.wishlist_id = w.id 
              AND rn.sent_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
          )";

$stmt = $db->query($sql);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($items) . " items to notify\n\n";

$notified = 0;
$errors = 0;

foreach ($items as $item) {
    $currentPrice = $item['sale_price'] ?: $item['price'];
    
    echo "Processing: {$item['product_name']}\n";
    echo "  User: {$item['display_name']} ({$item['line_user_id']})\n";
    echo "  Stock: {$item['stock']} units available\n";
    
    if (empty($item['channel_access_token'])) {
        echo "  ERROR: No channel access token\n\n";
        $errors++;
        continue;
    }
    
    // Create Flex Message
    $flexMessage = [
        'type' => 'flex',
        'altText' => "📦 {$item['product_name']} กลับมามีสต็อกแล้ว!",
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'hero' => [
                'type' => 'image',
                'url' => $item['image_url'] ?: 'https://via.placeholder.com/400x300?text=No+Image',
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
                            [
                                'type' => 'text',
                                'text' => '📦 สินค้าเข้าแล้ว!',
                                'weight' => 'bold',
                                'color' => '#10B981',
                                'size' => 'sm'
                            ],
                            [
                                'type' => 'text',
                                'text' => "เหลือ {$item['stock']} ชิ้น",
                                'weight' => 'bold',
                                'color' => '#FFFFFF',
                                'size' => 'xs',
                                'align' => 'center',
                                'backgroundColor' => '#10B981',
                                'cornerRadius' => 'md',
                                'margin' => 'sm',
                                'paddingAll' => 'xs'
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $item['product_name'],
                        'weight' => 'bold',
                        'size' => 'lg',
                        'wrap' => true,
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '฿' . number_format($currentPrice),
                                'weight' => 'bold',
                                'size' => 'xl',
                                'color' => '#11B0A6'
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => 'สินค้าในรายการโปรดของคุณกลับมามีสต็อกแล้ว!',
                        'size' => 'sm',
                        'color' => '#888888',
                        'margin' => 'md',
                        'wrap' => true
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => [
                            'type' => 'uri',
                            'label' => '🛒 ซื้อเลย',
                            'uri' => rtrim(BASE_URL, '/') . "/liff-product-detail.php?id={$item['product_id']}&user={$item['line_user_id']}&account={$item['line_account_id']}"
                        ],
                        'style' => 'primary',
                        'color' => '#10B981'
                    ]
                ]
            ]
        ]
    ];
    
    // Send via LINE API
    try {
        $line = new LineAPI($item['channel_access_token']);
        $result = $line->pushMessage($item['line_user_id'], [$flexMessage]);
        
        if ($result) {
            // Update notified_at
            $stmt = $db->prepare("UPDATE user_wishlist SET notified_at = NOW() WHERE id = ?");
            $stmt->execute([$item['id']]);
            
            // Log notification
            try {
                $db->prepare("INSERT INTO restock_notifications 
                    (wishlist_id, user_id, product_id, notification_type, new_stock, message)
                    VALUES (?, ?, ?, 'restock', ?, ?)")
                    ->execute([$item['id'], $item['user_id'], $item['product_id'], $item['stock'], "สินค้าเข้าแล้ว"]);
            } catch (Exception $e) {}
            
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
