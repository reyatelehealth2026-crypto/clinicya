<?php
/**
 * Cron Job - ส่งข้อความที่ตั้งเวลาไว้
 * ตั้ง cron ให้รันทุกนาที: * * * * * php /path/to/cron/send_scheduled.php
 * 
 * รองรับ:
 * - content_source: custom, template, product, flex
 * - target_type: all, group, user
 * - repeat_type: none, daily, weekly, monthly
 */

// Security: Allow only CLI or with secret key
$isCliMode = (php_sapi_name() === 'cli');
$secretKey = $_GET['key'] ?? '';
$validKey = 'scheduled_cron_2025'; // เปลี่ยนเป็น key ของคุณเอง

if (!$isCliMode && $secretKey !== $validKey) {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';

$db = Database::getInstance()->getConnection();

// Get pending scheduled messages that are due
$stmt = $db->prepare("SELECT sm.*, la.channel_access_token, la.channel_secret 
                      FROM scheduled_messages sm 
                      LEFT JOIN line_accounts la ON sm.line_account_id = la.id 
                      WHERE sm.status = 'pending' AND sm.scheduled_at <= NOW()");
$stmt->execute();
$schedules = $stmt->fetchAll();

echo date('Y-m-d H:i:s') . " - Found " . count($schedules) . " scheduled messages to send\n";

foreach ($schedules as $schedule) {
    $sentCount = 0;
    $error = null;
    
    try {
        // Initialize LINE API with correct channel token
        $channelToken = $schedule['channel_access_token'];
        if (empty($channelToken)) {
            throw new Exception("No channel access token for line_account_id: " . $schedule['line_account_id']);
        }
        
        $line = new LineAPI($channelToken);
        
        // Prepare message content based on content_source
        $messageContent = $schedule['content'];
        $messageType = $schedule['message_type'];
        $contentSource = $schedule['content_source'] ?? 'custom';
        
        // Process content based on source
        if ($contentSource === 'template' && !empty($schedule['template_id'])) {
            // Get template content
            $tplStmt = $db->prepare("SELECT message_type, content FROM templates WHERE id = ?");
            $tplStmt->execute([$schedule['template_id']]);
            $tpl = $tplStmt->fetch();
            if ($tpl) {
                $messageType = $tpl['message_type'];
                $messageContent = $tpl['content'];
            }
        } elseif ($contentSource === 'product' && !empty($schedule['product_ids'])) {
            // Build product carousel flex message from products
            $productIds = array_map('trim', explode(',', $schedule['product_ids']));
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            
            // Use products table
            $prodStmt = $db->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
            $prodStmt->execute($productIds);
            $products = $prodStmt->fetchAll();
            
            if (!empty($products)) {
                $messageType = 'flex';
                $messageContent = buildProductCarousel($products, $schedule['line_account_id']);
            } else {
                throw new Exception("No products found for IDs: " . $schedule['product_ids']);
            }
        } elseif ($contentSource === 'flex') {
            // Flex message - content is already JSON
            $messageType = 'flex';
            // Validate JSON
            $flexData = json_decode($messageContent, true);
            if (!$flexData) {
                throw new Exception("Invalid flex JSON content");
            }
            // Wrap in flex message format if needed
            if (!isset($flexData['type']) || $flexData['type'] !== 'flex') {
                $messageContent = json_encode([
                    'type' => 'flex',
                    'altText' => $schedule['title'] ?? 'ข้อความ',
                    'contents' => $flexData
                ]);
            }
        }
        
        // Get target users based on target_type
        $userIds = [];
        
        if ($schedule['target_type'] === 'all') {
            // Get all users for this LINE account
            $userStmt = $db->prepare("SELECT line_user_id FROM users 
                                      WHERE (line_account_id = ? OR line_account_id IS NULL) 
                                      AND is_blocked = 0 
                                      AND line_user_id IS NOT NULL 
                                      AND line_user_id != ''
                                      AND line_user_id LIKE 'U%'");
            $userStmt->execute([$schedule['line_account_id']]);
            $userIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);
            
        } elseif ($schedule['target_type'] === 'group' && $schedule['target_id']) {
            // Get users in specific group
            $userStmt = $db->prepare("SELECT u.line_user_id FROM users u 
                                      JOIN user_groups ug ON u.id = ug.user_id 
                                      WHERE ug.group_id = ? AND u.is_blocked = 0 
                                      AND u.line_user_id IS NOT NULL
                                      AND u.line_user_id != ''
                                      AND u.line_user_id LIKE 'U%'
                                      AND (u.line_account_id = ? OR u.line_account_id IS NULL)");
            $userStmt->execute([$schedule['target_id'], $schedule['line_account_id']]);
            $userIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);
            
        } elseif ($schedule['target_type'] === 'user' && $schedule['target_id']) {
            // Get specific user
            $userStmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ? AND line_user_id LIKE 'U%'");
            $userStmt->execute([$schedule['target_id']]);
            $user = $userStmt->fetch();
            if ($user && $user['line_user_id']) {
                $userIds = [$user['line_user_id']];
            }
        }
        
        // Filter valid LINE user IDs (must start with 'U' and be 33 chars)
        $userIds = array_filter($userIds, function($id) {
            return !empty($id) && strlen($id) === 33 && strpos($id, 'U') === 0;
        });
        $userIds = array_values($userIds); // Re-index array
        
        echo date('Y-m-d H:i:s') . " - Found " . count($userIds) . " valid users for schedule ID: {$schedule['id']}\n";
        
        // Build message array for LINE API
        $messages = buildMessageArray($messageContent, $messageType, $schedule['title']);
        
        // Send messages
        if (!empty($userIds) && !empty($messages)) {
            // Use multicast for multiple users (max 500 per request)
            $chunks = array_chunk($userIds, 500);
            foreach ($chunks as $chunk) {
                if (count($chunk) === 1) {
                    // Push to single user
                    $result = $line->pushMessage($chunk[0], $messages);
                } else {
                    // Multicast to multiple users
                    $result = $line->multicastMessage($chunk, $messages);
                }
                
                if (isset($result['code']) && $result['code'] === 200) {
                    $sentCount += count($chunk);
                } else {
                    $errorMsg = isset($result['body']['message']) ? $result['body']['message'] : 'Unknown error';
                    $error = "HTTP {$result['code']}: $errorMsg";
                    echo date('Y-m-d H:i:s') . " - Error sending to chunk: $error\n";
                }
            }
        } else {
            echo date('Y-m-d H:i:s') . " - No users found for schedule ID: {$schedule['id']}\n";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        echo date('Y-m-d H:i:s') . " - Exception: $error\n";
    }
    
    // Update status
    if ($schedule['repeat_type'] === 'none') {
        // One-time message - mark as sent
        $updateStmt = $db->prepare("UPDATE scheduled_messages SET status = 'sent' WHERE id = ?");
        $updateStmt->execute([$schedule['id']]);
    } else {
        // Repeating message - calculate next scheduled time
        $nextTime = calculateNextSchedule($schedule['scheduled_at'], $schedule['repeat_type']);
        if ($nextTime) {
            $updateStmt = $db->prepare("UPDATE scheduled_messages SET scheduled_at = ? WHERE id = ?");
            $updateStmt->execute([$nextTime, $schedule['id']]);
        }
    }
    
    echo date('Y-m-d H:i:s') . " - Sent schedule ID: {$schedule['id']}, Title: {$schedule['title']}, Recipients: {$sentCount}" . ($error ? ", Error: $error" : "") . "\n";
}

/**
 * Build message array for LINE API
 */
function buildMessageArray($content, $type, $altText = 'ข้อความ') {
    if ($type === 'flex') {
        // Parse flex JSON
        $flexData = json_decode($content, true);
        if ($flexData) {
            // Check if already wrapped in flex message format
            if (isset($flexData['type']) && $flexData['type'] === 'flex') {
                return [$flexData];
            }
            // Wrap bubble/carousel in flex message
            return [[
                'type' => 'flex',
                'altText' => $altText,
                'contents' => $flexData
            ]];
        }
        // Invalid JSON, send as text
        return [['type' => 'text', 'text' => $content]];
    }
    
    // Text message
    return [['type' => 'text', 'text' => $content]];
}

/**
 * Calculate next schedule time for repeating messages
 */
function calculateNextSchedule($currentTime, $repeatType) {
    switch ($repeatType) {
        case 'daily':
            return date('Y-m-d H:i:s', strtotime($currentTime . ' +1 day'));
        case 'weekly':
            return date('Y-m-d H:i:s', strtotime($currentTime . ' +1 week'));
        case 'monthly':
            return date('Y-m-d H:i:s', strtotime($currentTime . ' +1 month'));
        default:
            return null;
    }
}

/**
 * Build Product Carousel Flex Message
 */
function buildProductCarousel($products, $lineAccountId) {
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    
    $bubbles = [];
    foreach (array_slice($products, 0, 10) as $product) { // Max 10 bubbles in carousel
        $imageUrl = $product['image_url'] ?: $baseUrl . '/assets/images/image-placeholder.svg';
        
        // Handle price
        $price = $product['price'] ?? 0;
        $salePrice = $product['sale_price'] ?? null;
        $displayPrice = ($salePrice && $salePrice < $price) ? $salePrice : $price;
        
        $priceText = '฿' . number_format($displayPrice, 0);
        
        // Build product URL
        $productUrl = $baseUrl . '/liff/shop.php?product=' . $product['id'];
        if ($lineAccountId) {
            $productUrl .= '&account=' . $lineAccountId;
        }
        
        $bodyContents = [
            [
                'type' => 'text',
                'text' => $product['name'],
                'weight' => 'bold',
                'size' => 'sm',
                'wrap' => true,
                'maxLines' => 2
            ],
            [
                'type' => 'text',
                'text' => $priceText,
                'color' => '#06C755',
                'weight' => 'bold',
                'size' => 'lg',
                'margin' => 'md'
            ]
        ];
        
        // Add original price if on sale
        if ($salePrice && $salePrice < $price) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => '฿' . number_format($price, 0),
                'color' => '#AAAAAA',
                'size' => 'xs',
                'decoration' => 'line-through'
            ];
        }
        
        $bubbles[] = [
            'type' => 'bubble',
            'size' => 'micro',
            'hero' => [
                'type' => 'image',
                'url' => $imageUrl,
                'size' => 'full',
                'aspectMode' => 'cover',
                'aspectRatio' => '320:213',
                'action' => [
                    'type' => 'uri',
                    'uri' => $productUrl
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
                'spacing' => 'sm',
                'paddingAll' => '13px'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => [
                            'type' => 'uri',
                            'label' => '🛒 ดูสินค้า',
                            'uri' => $productUrl
                        ],
                        'style' => 'primary',
                        'color' => '#06C755',
                        'height' => 'sm'
                    ]
                ],
                'spacing' => 'sm',
                'paddingAll' => '13px'
            ]
        ];
    }
    
    return json_encode([
        'type' => 'flex',
        'altText' => 'สินค้าแนะนำ ' . count($products) . ' รายการ',
        'contents' => [
            'type' => 'carousel',
            'contents' => $bubbles
        ]
    ]);
}

echo date('Y-m-d H:i:s') . " - Done.\n";
