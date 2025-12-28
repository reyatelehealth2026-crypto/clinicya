<?php
/**
 * LIFF Bridge API - Fallback for external browser
 * Sends push messages when liff.sendMessages() is not available
 * 
 * Requirements: 20.10
 * - Detect when liff.sendMessages() unavailable
 * - Send via API instead (push message)
 */

// Suppress PHP errors from being output (they break JSON)
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit(0);
}

// Set up error handler to return JSON on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $error['message']
        ]);
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Try to load optional classes
try {
    require_once __DIR__ . '/../classes/LineAPI.php';
} catch (Exception $e) {
    // LineAPI not available
}

try {
    require_once __DIR__ . '/../classes/FlexTemplates.php';
} catch (Exception $e) {
    // FlexTemplates not available
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed');
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);

$action = $input['action'] ?? '';
$data = $input['data'] ?? [];
$message = $input['message'] ?? '';
$lineUserId = $input['line_user_id'] ?? '';
$lineAccountId = $input['line_account_id'] ?? 1;

if (empty($action) || empty($lineUserId)) {
    jsonResponse(false, 'Missing required parameters');
}

try {
    // Get LINE API instance for this account
    $line = getLineApi($db, $lineAccountId);
    
    if (!$line) {
        jsonResponse(false, 'LINE API not configured');
    }

    // Process action and send appropriate message
    $result = processAction($db, $line, $action, $data, $message, $lineUserId, $lineAccountId);
    
    jsonResponse($result['success'], $result['message'], $result['data'] ?? []);

} catch (Exception $e) {
    error_log("LIFF Bridge API error: " . $e->getMessage());
    jsonResponse(false, $e->getMessage());
}

/**
 * Get LINE API instance for account
 */
function getLineApi($db, $lineAccountId) {
    try {
        $stmt = $db->prepare("SELECT channel_access_token, channel_secret FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($account && !empty($account['channel_access_token'])) {
            return new LineAPI($account['channel_access_token'], $account['channel_secret']);
        }
    } catch (Exception $e) {
        error_log("getLineApi error: " . $e->getMessage());
    }
    
    // Fallback to default
    return new LineAPI();
}

/**
 * Process action and send message
 * Requirements: 20.3, 20.9, 20.12
 */
function processAction($db, $line, $action, $data, $message, $lineUserId, $lineAccountId) {
    // Log the action
    logLiffAction($db, $action, $data, $lineUserId, $lineAccountId);
    
    // Generate Flex Message based on action type
    $flexMessage = null;
    
    switch ($action) {
        case 'order_placed':
            // Requirements: 20.4, 20.12
            $flexMessage = createOrderConfirmationFlex($db, $data, $lineAccountId);
            break;
            
        case 'appointment_booked':
            // Requirements: 20.5
            $flexMessage = createAppointmentConfirmationFlex($data);
            break;
            
        case 'consult_request':
            // Requirements: 20.6
            $flexMessage = createConsultRequestFlex($data);
            break;
            
        case 'points_redeemed':
            // Requirements: 20.7
            $flexMessage = createPointsRedeemedFlex($data);
            break;
            
        case 'health_updated':
            // Requirements: 20.8
            $flexMessage = createHealthUpdatedFlex($data);
            break;
            
        default:
            // Generic text message
            if (!empty($message)) {
                $line->pushMessage($lineUserId, [['type' => 'text', 'text' => $message]]);
                return ['success' => true, 'message' => 'Message sent'];
            }
            return ['success' => false, 'message' => 'Unknown action'];
    }
    
    // Send Flex Message if generated
    if ($flexMessage) {
        $result = $line->pushMessage($lineUserId, [$flexMessage]);
        return ['success' => true, 'message' => 'Flex message sent', 'data' => ['action' => $action]];
    }
    
    return ['success' => false, 'message' => 'Failed to generate message'];
}

/**
 * Create order confirmation Flex Message
 * Requirements: 20.12
 */
function createOrderConfirmationFlex($db, $data, $lineAccountId) {
    $orderId = $data['orderId'] ?? '';
    $total = $data['total'] ?? 0;
    $items = $data['items'] ?? [];
    $itemCount = $data['itemCount'] ?? (is_array($items) ? count($items) : (int)$items);
    
    // Get shop name
    $shopName = 'ร้านค้า';
    try {
        $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
        $stmt->execute([$lineAccountId]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($shop && !empty($shop['shop_name'])) {
            $shopName = $shop['shop_name'];
        }
    } catch (Exception $e) {}
    
    // Get LIFF URL for tracking
    $liffUrl = '';
    try {
        $stmt = $db->prepare("SELECT liff_id FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account && !empty($account['liff_id'])) {
            $liffUrl = "https://liff.line.me/{$account['liff_id']}?page=order-detail&order_id={$orderId}";
        }
    } catch (Exception $e) {}
    
    // Build item summary
    $itemSummary = $itemCount > 0 ? "{$itemCount} รายการ" : '';
    
    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#11B0A6',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => '✅ สั่งซื้อสำเร็จ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => "ออเดอร์ #{$orderId}", 'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                ['type' => 'text', 'text' => $shopName, 'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'sm'],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => 'จำนวนสินค้า', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => $itemSummary, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 1]
                    ]
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ยอดรวม', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => '฿' . number_format($total, 2), 'size' => 'lg', 'weight' => 'bold', 'color' => '#11B0A6', 'align' => 'end', 'flex' => 1]
                    ]
                ],
                ['type' => 'separator', 'margin' => 'lg'],
                ['type' => 'text', 'text' => '⏳ รอตรวจสอบการชำระเงิน', 'size' => 'sm', 'color' => '#F59E0B', 'align' => 'center', 'margin' => 'lg']
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => []
        ]
    ];
    
    // Add tracking button if LIFF URL available
    if (!empty($liffUrl)) {
        $bubble['footer']['contents'][] = [
            'type' => 'button',
            'action' => ['type' => 'uri', 'label' => '📦 ติดตามออเดอร์', 'uri' => $liffUrl],
            'style' => 'primary',
            'color' => '#11B0A6',
            'height' => 'sm'
        ];
    }
    
    return [
        'type' => 'flex',
        'altText' => "✅ สั่งซื้อสำเร็จ #{$orderId}",
        'contents' => $bubble
    ];
}

/**
 * Create appointment confirmation Flex Message
 * Requirements: 20.5
 */
function createAppointmentConfirmationFlex($data) {
    $date = $data['date'] ?? '';
    $time = $data['time'] ?? '';
    $pharmacistName = $data['pharmacistName'] ?? 'เภสัชกร';
    
    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#3B82F6',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => '📅 นัดหมายสำเร็จ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => $pharmacistName, 'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '📆 วันที่', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => $date, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                    ]
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => '⏰ เวลา', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => $time, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                    ]
                ],
                ['type' => 'text', 'text' => '🔔 จะแจ้งเตือนก่อนนัด 30 นาที', 'size' => 'xs', 'color' => '#888888', 'align' => 'center', 'margin' => 'xl']
            ]
        ]
    ];
    
    return [
        'type' => 'flex',
        'altText' => "📅 นัดหมายสำเร็จ {$date} {$time}",
        'contents' => $bubble
    ];
}

/**
 * Create consultation request Flex Message
 * Requirements: 20.6
 */
function createConsultRequestFlex($data) {
    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#9333EA',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => '👨‍⚕️ ขอปรึกษาเภสัชกร', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => 'ได้รับคำขอปรึกษาแล้ว', 'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                ['type' => 'text', 'text' => 'เภสัชกรจะติดต่อกลับโดยเร็วที่สุด', 'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'md', 'wrap' => true],
                ['type' => 'separator', 'margin' => 'lg'],
                ['type' => 'text', 'text' => '⏳ กรุณารอสักครู่...', 'size' => 'sm', 'color' => '#F59E0B', 'align' => 'center', 'margin' => 'lg']
            ]
        ]
    ];
    
    return [
        'type' => 'flex',
        'altText' => '👨‍⚕️ ขอปรึกษาเภสัชกร - รอเภสัชกรติดต่อกลับ',
        'contents' => $bubble
    ];
}

/**
 * Create points redeemed Flex Message
 * Requirements: 20.7
 */
function createPointsRedeemedFlex($data) {
    $points = $data['points'] ?? 0;
    $rewardName = $data['rewardName'] ?? 'รางวัล';
    $remainingPoints = $data['remainingPoints'] ?? 0;
    
    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#F59E0B',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => '🎁 แลกแต้มสำเร็จ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => $rewardName, 'weight' => 'bold', 'size' => 'md', 'align' => 'center', 'wrap' => true],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => 'แต้มที่ใช้', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => number_format($points) . ' แต้ม', 'size' => 'sm', 'weight' => 'bold', 'color' => '#EF4444', 'align' => 'end', 'flex' => 1]
                    ]
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => 'แต้มคงเหลือ', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => number_format($remainingPoints) . ' แต้ม', 'size' => 'sm', 'weight' => 'bold', 'color' => '#11B0A6', 'align' => 'end', 'flex' => 1]
                    ]
                ]
            ]
        ]
    ];
    
    return [
        'type' => 'flex',
        'altText' => "🎁 แลกแต้มสำเร็จ {$points} แต้ม",
        'contents' => $bubble
    ];
}

/**
 * Create health profile updated Flex Message
 * Requirements: 20.8
 */
function createHealthUpdatedFlex($data) {
    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#10B981',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => '💚 อัพเดทข้อมูลสุขภาพ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => 'บันทึกข้อมูลสุขภาพเรียบร้อย', 'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                ['type' => 'text', 'text' => 'ข้อมูลนี้จะช่วยให้เภสัชกรให้คำแนะนำได้ดียิ่งขึ้น', 'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'md', 'wrap' => true],
                ['type' => 'separator', 'margin' => 'lg'],
                ['type' => 'text', 'text' => '✅ ข้อมูลถูกเข้ารหัสอย่างปลอดภัย', 'size' => 'xs', 'color' => '#10B981', 'align' => 'center', 'margin' => 'lg']
            ]
        ]
    ];
    
    return [
        'type' => 'flex',
        'altText' => '💚 อัพเดทข้อมูลสุขภาพสำเร็จ',
        'contents' => $bubble
    ];
}

/**
 * Log LIFF action to database
 */
function logLiffAction($db, $action, $data, $lineUserId, $lineAccountId) {
    try {
        // Check if dev_logs table exists
        $stmt = $db->query("SHOW TABLES LIKE 'dev_logs'");
        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES ('info', 'liff_bridge', ?, ?, NOW())");
            $stmt->execute([
                "LIFF action: {$action}",
                json_encode([
                    'action' => $action,
                    'data' => $data,
                    'line_user_id' => $lineUserId,
                    'line_account_id' => $lineAccountId
                ])
            ]);
        }
    } catch (Exception $e) {
        // Ignore logging errors
    }
}

/**
 * JSON response helper
 */
function jsonResponse($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        ...$data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
