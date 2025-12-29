<?php
/**
 * Test Flex Message Send
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Test Flex Message</h2>";

// Get user and token
$stmt = $db->query("SELECT u.line_user_id, u.line_account_id, la.channel_access_token 
                    FROM users u 
                    JOIN line_accounts la ON u.line_account_id = la.id 
                    WHERE u.id = 28");
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("User or LINE account not found");
}

$lineUserId = $data['line_user_id'];
$token = $data['channel_access_token'];

echo "LINE User ID: {$lineUserId}<br>";
echo "Token: " . substr($token, 0, 20) . "...<br><br>";

// Test drugs
$approvedDrugs = [
    ['name' => 'พาราเซตามอล 500mg', 'price' => 50],
    ['name' => 'ยาแก้ไอ', 'price' => 80]
];

// Build Flex Message
$drugContents = [];
$total = 0;

foreach ($approvedDrugs as $drug) {
    $price = $drug['price'] ?? 0;
    $total += $price;
    
    $drugContents[] = [
        'type' => 'box',
        'layout' => 'horizontal',
        'contents' => [
            [
                'type' => 'text',
                'text' => '💊 ' . ($drug['name'] ?? 'ยา'),
                'size' => 'sm',
                'flex' => 3
            ],
            [
                'type' => 'text',
                'text' => "฿{$price}",
                'size' => 'sm',
                'align' => 'end',
                'flex' => 1
            ]
        ]
    ];
}

$flexMessage = [
    'type' => 'flex',
    'altText' => '✅ เภสัชกรอนุมัติยาแล้ว',
    'contents' => [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '✅ เภสัชกรอนุมัติยาแล้ว',
                    'color' => '#FFFFFF',
                    'weight' => 'bold',
                    'size' => 'lg'
                ]
            ],
            'backgroundColor' => '#059669',
            'paddingAll' => '15px'
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => array_merge(
                [
                    [
                        'type' => 'text',
                        'text' => 'ยาที่แนะนำ',
                        'weight' => 'bold',
                        'size' => 'md'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ]
                ],
                $drugContents,
                [
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'รวม',
                                'weight' => 'bold',
                                'flex' => 3
                            ],
                            [
                                'type' => 'text',
                                'text' => "฿{$total}",
                                'weight' => 'bold',
                                'color' => '#059669',
                                'align' => 'end',
                                'flex' => 1
                            ]
                        ]
                    ]
                ]
            ),
            'paddingAll' => '20px'
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'uri',
                        'label' => '🛒 สั่งซื้อเลย',
                        'uri' => 'https://clinicya.re-ya.com/liff/'
                    ],
                    'style' => 'primary',
                    'color' => '#059669'
                ],
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'message',
                        'label' => '💬 สอบถามเพิ่มเติม',
                        'text' => 'สอบถามเพิ่มเติม'
                    ],
                    'style' => 'secondary',
                    'margin' => 'sm'
                ]
            ],
            'paddingAll' => '15px'
        ]
    ]
];

echo "<h3>Flex Message JSON:</h3>";
echo "<pre style='background:#f3f4f6;padding:10px;font-size:11px;max-height:300px;overflow:auto;'>";
echo htmlspecialchars(json_encode($flexMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "</pre>";

if (isset($_GET['send'])) {
    echo "<h3>Sending Flex Message...</h3>";
    
    $requestData = [
        'to' => $lineUserId,
        'messages' => [$flexMessage]
    ];
    
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($requestData)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: {$httpCode}<br>";
    echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";
    
    if ($httpCode === 200) {
        echo "<br>✅ <strong style='color:green'>SUCCESS! Check your LINE app.</strong>";
    } else {
        echo "<br>❌ <strong style='color:red'>FAILED</strong>";
    }
} else {
    echo "<br><a href='?send=1' style='background:#10b981;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>📤 ส่ง Flex Message ทดสอบ</a>";
}
