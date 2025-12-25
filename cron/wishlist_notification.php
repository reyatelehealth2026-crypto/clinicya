<?php
/**
 * Wishlist Price Drop Notification
 * แจ้งเตือนลูกค้าเมื่อสินค้าในรายการโปรดลดราคา
 * 
 * Run: php cron/wishlist_notification.php
 * Schedule: Every hour or when prices change
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';

$db = Database::getInstance()->getConnection();

echo "=== Wishlist Price Drop Notification ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Find wishlist items where current price < price_when_added
// and not notified in last 24 hours
$sql = "SELECT w.*, 
               p.name as product_name, p.price, p.sale_price, p.image_url,
               u.line_user_id, u.display_name,
               la.channel_access_token
        FROM user_wishlist w
        JOIN business_items p ON w.product_id = p.id
        JOIN users u ON w.user_id = u.id
        LEFT JOIN line_accounts la ON w.line_account_id = la.id
        WHERE w.notify_on_sale = 1
          AND p.is_active = 1
          AND (
              (p.sale_price IS NOT NULL AND p.sale_price < w.price_when_added)
              OR (p.sale_price IS NULL AND p.price < w.price_when_added)
          )
          AND (w.notified_at IS NULL OR w.notified_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))";

$stmt = $db->query($sql);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($items) . " items to notify\n\n";

$notified = 0;
$errors = 0;

foreach ($items as $item) {
    $currentPrice = $item['sale_price'] ?: $item['price'];
    $oldPrice = $item['price_when_added'];
    $discount = round((1 - $currentPrice / $oldPrice) * 100);
    
    echo "Processing: {$item['product_name']}\n";
    echo "  User: {$item['display_name']} ({$item['line_user_id']})\n";
    echo "  Old Price: {$oldPrice} -> New Price: {$currentPrice} (-{$discount}%)\n";
    
    if (empty($item['channel_access_token'])) {
        echo "  ERROR: No channel access token\n\n";
        $errors++;
        continue;
    }
    
    // Create Flex Message
    $flexMessage = [
        'type' => 'flex',
        'altText' => "🔥 {$item['product_name']} ลดราคา {$discount}%!",
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
                                'text' => '🔥 ลดราคาแล้ว!',
                                'weight' => 'bold',
                                'color' => '#EF4444',
                                'size' => 'sm'
                            ],
                            [
                                'type' => 'text',
                                'text' => "-{$discount}%",
                                'weight' => 'bold',
                                'color' => '#FFFFFF',
                                'size' => 'sm',
                                'align' => 'center',
                                'backgroundColor' => '#EF4444',
                                'cornerRadius' => 'md',
                                'margin' => 'sm'
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
                                'color' => '#EF4444'
                            ],
                            [
                                'type' => 'text',
                                'text' => '฿' . number_format($oldPrice),
                                'size' => 'md',
                                'color' => '#AAAAAA',
                                'decoration' => 'line-through',
                                'align' => 'end',
                                'gravity' => 'bottom'
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => 'สินค้าในรายการโปรดของคุณลดราคาแล้ว!',
                        'size' => 'sm',
                        'color' => '#888888',
                        'margin' => 'md'
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
                        'color' => '#EF4444'
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
                $db->prepare("INSERT INTO wishlist_notifications 
                    (wishlist_id, user_id, product_id, notification_type, old_price, new_price, discount_percent, message)
                    VALUES (?, ?, ?, 'price_drop', ?, ?, ?, ?)")
                    ->execute([$item['id'], $item['user_id'], $item['product_id'], $oldPrice, $currentPrice, $discount, "ลดราคา {$discount}%"]);
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
