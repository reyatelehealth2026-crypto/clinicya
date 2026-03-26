<?php
/**
 * Test Flex Notifications
 * ทดสอบส่ง Flex Message โดยตรง ไม่ผ่าน webhook pipeline
 *
 * https://cny.re-ya.com/api/test-flex-notify.php?secret=TEST_ONLY_2026&type=points
 * https://cny.re-ya.com/api/test-flex-notify.php?secret=TEST_ONLY_2026&type=bdo
 * https://cny.re-ya.com/api/test-flex-notify.php?secret=TEST_ONLY_2026&type=all
 */

$allowedSecret = $_ENV['TEST_WEBHOOK_SECRET'] ?? 'TEST_ONLY_2026';
$givenSecret   = $_GET['secret'] ?? ($argv[1] ?? '');
if ($givenSecret !== $allowedSecret) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';

// ── Config ──────────────────────────────────────────────────────────────────
$LINE_USER_ID  = 'Ua1156d646cad2237e878457833bc07b3';
$LINE_ACCT_ID  = 3;
$DISPLAY_NAME  = 'ลูกค้าทดสอบ PC251007';
$PICTURE_URL   = '';
$INTERNAL_SEC  = defined('INTERNAL_API_SECRET') ? INTERNAL_API_SECRET : ($_ENV['INTERNAL_API_SECRET'] ?? '');
$BRIDGE_URL    = (defined('APP_URL') ? APP_URL : 'https://cny.re-ya.com') . '/api/liff-bridge.php';
$ODOO_BASE     = defined('ODOO_PRODUCTION_API_BASE_URL') ? ODOO_PRODUCTION_API_BASE_URL : 'https://erp.cnyrxapp.com';
$LIFF_URL      = 'https://liff.line.me/2008876929-yRgjH7fX';

$type = $_GET['type'] ?? 'all';

// ── Helper ───────────────────────────────────────────────────────────────────
function sendFlex(string $bridgeUrl, string $lineUserId, int $lineAccountId, string $internalSecret, array $flexMessage): array {
    $payload = json_encode([
        'action'          => 'send_flex_message',
        'line_user_id'    => $lineUserId,
        'lineUserId'      => $lineUserId,
        'line_account_id' => $lineAccountId,
        'lineAccountId'   => $lineAccountId,
        'data'            => ['flexMessage' => $flexMessage],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($bridgeUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . $internalSecret,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = $response ? @json_decode($response, true) : null;
    return ['http' => $httpCode, 'response' => $decoded ?? $response];
}

$results = [];

// ════════════════════════════════════════════════════════════════
//  1. INVOICE.PAID — แต้มสะสม
// ════════════════════════════════════════════════════════════════
if ($type === 'points' || $type === 'all') {
    $invoiceNumber = 'HS-TEST-97901';
    $orderName     = 'SO-TEST-99901';
    $amountTotal   = 5500.00;
    $points        = (int) floor($amountTotal / 1000);  // = 5
    $newPoints     = 999 + $points;                     // สมมติยอดเดิม 999
    $pdfUrl        = $ODOO_BASE . '/report/pdf/account.report_invoice/997901';
    $defaultAvatar = 'https://profile.line-scdn.net/0hLhff-3aQE0dbHwxJqYdsEGdaHSosMRUPI3EPJ39PTCBze1BDZC1Zc3ofGiUiLlcUZHgIJS0cTSV-';

    $pointsFlex = [
        'type'     => 'flex',
        'altText'  => "🧾 ได้รับแต้มจากออเดอ {$orderName} — +{$points} point!",
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '20px',
                'contents' => [
                    // Header
                    [
                        'type' => 'box', 'layout' => 'horizontal',
                        'paddingAll' => '14px', 'backgroundColor' => '#FFFFFF',
                        'cornerRadius' => '12px', 'borderWidth' => '2px', 'borderColor' => '#0C665D',
                        'contents' => [
                            [
                                'type' => 'box', 'layout' => 'vertical',
                                'width' => '56px', 'height' => '56px', 'cornerRadius' => '28px',
                                'contents' => [[
                                    'type' => 'image',
                                    'url'  => $PICTURE_URL ?: $defaultAvatar,
                                    'aspectMode' => 'cover', 'aspectRatio' => '1:1', 'size' => 'full',
                                ]],
                            ],
                            [
                                'type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'justifyContent' => 'center',
                                'contents' => [
                                    ['type' => 'text', 'text' => $DISPLAY_NAME, 'weight' => 'bold', 'size' => 'md', 'color' => '#1A1A1A', 'wrap' => true],
                                    ['type' => 'text', 'text' => 'ได้รับแต้มสะสม', 'size' => 'xs', 'color' => '#0C665D', 'margin' => 'xs'],
                                ],
                            ],
                        ],
                    ],
                    // Invoice info
                    [
                        'type' => 'box', 'layout' => 'vertical', 'margin' => 'xl',
                        'paddingAll' => '12px', 'backgroundColor' => '#F5F5F5', 'cornerRadius' => '10px',
                        'contents' => [
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => 'ใบแจ้งหนี้', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                                ['type' => 'text', 'text' => $invoiceNumber, 'size' => 'sm', 'color' => '#1A1A1A', 'weight' => 'bold', 'align' => 'end'],
                            ]],
                            ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'xs', 'contents' => [
                                ['type' => 'text', 'text' => 'ออเดอร์', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                                ['type' => 'text', 'text' => $orderName, 'size' => 'sm', 'color' => '#1A1A1A', 'align' => 'end'],
                            ]],
                            ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'xs', 'contents' => [
                                ['type' => 'text', 'text' => 'ยอดชำระ', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                                ['type' => 'text', 'text' => number_format($amountTotal, 2, '.', ',') . ' ฿', 'size' => 'sm', 'color' => '#1A1A1A', 'weight' => 'bold', 'align' => 'end'],
                            ]],
                        ],
                    ],
                    // Points earned
                    [
                        'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg',
                        'paddingAll' => '20px', 'backgroundColor' => '#F0F9F8', 'cornerRadius' => '16px',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ได้รับแต้มจากออเดอ', 'size' => 'sm', 'color' => '#666666', 'align' => 'center'],
                            ['type' => 'text', 'text' => "+{$points}", 'size' => '4xl', 'weight' => 'bold', 'color' => '#0C665D', 'align' => 'center'],
                            ['type' => 'text', 'text' => 'point', 'size' => 'md', 'color' => '#0C665D', 'weight' => 'bold', 'align' => 'center', 'margin' => 'xs'],
                        ],
                    ],
                    ['type' => 'text', 'text' => '(อัตรา 1,000 ฿ = 1 point)', 'size' => 'xs', 'color' => '#999999', 'align' => 'center', 'margin' => 'sm'],
                    // Balance
                    [
                        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'xl',
                        'paddingAll' => '14px', 'backgroundColor' => '#FFFFFF',
                        'cornerRadius' => '10px', 'borderWidth' => '1px', 'borderColor' => '#E5E5E5',
                        'contents' => [
                            ['type' => 'text', 'text' => 'แต้มคงเหลือ', 'size' => 'sm', 'color' => '#666666', 'flex' => 0],
                            ['type' => 'text', 'text' => number_format($newPoints) . ' แต้ม', 'size' => 'md', 'color' => '#0C665D', 'weight' => 'bold', 'align' => 'end'],
                        ],
                    ],
                    ['type' => 'text', 'text' => "ได้รับชำระเงินใบแจ้งหนี้ {$invoiceNumber} เรียบร้อยแล้ว ขอบคุณที่ใช้บริการ", 'size' => 'xs', 'color' => '#999999', 'margin' => 'xl', 'wrap' => true, 'align' => 'center'],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px',
                'contents' => [
                    ['type' => 'button', 'style' => 'primary', 'color' => '#0C665D', 'height' => 'sm',
                     'action' => ['type' => 'uri', 'label' => 'เข้าสู่เมนูแลกพอยท์', 'uri' => $LIFF_URL]],
                    ['type' => 'button', 'style' => 'secondary', 'height' => 'sm', 'margin' => 'sm',
                     'action' => ['type' => 'uri', 'label' => 'ดูใบแจ้งหนี้ PDF', 'uri' => $pdfUrl]],
                ],
            ],
        ],
    ];

    $res = sendFlex($BRIDGE_URL, $LINE_USER_ID, $LINE_ACCT_ID, $INTERNAL_SEC, $pointsFlex);
    $results[] = ['type' => 'invoice.paid (แต้มสะสม)', 'http' => $res['http'], 'status' => $res['http'] === 200 ? '✅' : '❌', 'response' => $res['response']];
}

// ════════════════════════════════════════════════════════════════
//  2. BDO CONFIRMED — แจ้งยอดชำระ BDO
// ════════════════════════════════════════════════════════════════
if ($type === 'bdo' || $type === 'all') {
    $bdoName    = 'BDO-TEST-98901';
    $soName     = 'SO-TEST-99901';
    $bdoAmount  = 5500.00;
    $invoiceNum = 'HS-TEST-97901';
    $dueDate    = date('d/m/Y');

    $bdoFlex = [
        'type'     => 'flex',
        'altText'  => "📋 BDO {$bdoName} ยืนยันแล้ว — ยอด " . number_format($bdoAmount, 0) . " ฿",
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px',
                'backgroundColor' => '#0C665D',
                'contents' => [
                    ['type' => 'text', 'text' => '📋 ใบสั่งชำระเงิน', 'color' => '#FFFFFF', 'size' => 'sm', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => $bdoName, 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'margin' => 'xs'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '18px',
                'contents' => [
                    // Customer
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                        ['type' => 'text', 'text' => 'ลูกค้า', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => $DISPLAY_NAME, 'size' => 'sm', 'color' => '#1A1A1A', 'flex' => 5, 'wrap' => true, 'align' => 'end'],
                    ]],
                    ['type' => 'separator', 'margin' => 'md', 'color' => '#F0F0F0'],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                        ['type' => 'text', 'text' => 'ออเดอร์', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => $soName, 'size' => 'sm', 'color' => '#1A1A1A', 'flex' => 5, 'align' => 'end'],
                    ]],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ใบแจ้งหนี้', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => $invoiceNum, 'size' => 'sm', 'color' => '#1A1A1A', 'flex' => 5, 'align' => 'end'],
                    ]],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ครบกำหนด', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => $dueDate, 'size' => 'sm', 'color' => '#E53935', 'flex' => 5, 'align' => 'end'],
                    ]],
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#E8F5E9'],
                    // Amount
                    [
                        'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg',
                        'paddingAll' => '16px', 'backgroundColor' => '#F0F9F8', 'cornerRadius' => '12px',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดที่ต้องชำระ', 'size' => 'sm', 'color' => '#666666', 'align' => 'center'],
                            ['type' => 'text', 'text' => number_format($bdoAmount, 2, '.', ',') . ' ฿', 'size' => 'xxl', 'weight' => 'bold', 'color' => '#0C665D', 'align' => 'center', 'margin' => 'sm'],
                        ],
                    ],
                    // Payment method
                    [
                        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'lg',
                        'paddingAll' => '12px', 'backgroundColor' => '#F5F5F5', 'cornerRadius' => '8px',
                        'contents' => [
                            ['type' => 'text', 'text' => '🏦', 'size' => 'sm', 'flex' => 0],
                            ['type' => 'box', 'layout' => 'vertical', 'margin' => 'sm', 'contents' => [
                                ['type' => 'text', 'text' => 'โอนเงินผ่านธนาคาร', 'size' => 'sm', 'weight' => 'bold', 'color' => '#1A1A1A'],
                                ['type' => 'text', 'text' => 'ธนาคารกสิกรไทย  123-4-56789-0', 'size' => 'xs', 'color' => '#666666', 'margin' => 'xs'],
                            ]],
                        ],
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'style' => 'primary', 'color' => '#0C665D', 'height' => 'sm',
                     'action' => ['type' => 'uri', 'label' => 'แนบสลิปชำระเงิน', 'uri' => $LIFF_URL]],
                    ['type' => 'button', 'style' => 'secondary', 'height' => 'sm',
                     'action' => ['type' => 'uri', 'label' => 'ดูใบแจ้งหนี้ PDF', 'uri' => $ODOO_BASE . '/report/pdf/account.report_invoice/997901']],
                ],
            ],
        ],
    ];

    $res = sendFlex($BRIDGE_URL, $LINE_USER_ID, $LINE_ACCT_ID, $INTERNAL_SEC, $bdoFlex);
    $results[] = ['type' => 'bdo.confirmed (แจ้งยอด)', 'http' => $res['http'], 'status' => $res['http'] === 200 ? '✅' : '❌', 'response' => $res['response']];
}

echo json_encode([
    'line_user_id' => $LINE_USER_ID,
    'bridge_url'   => $BRIDGE_URL,
    'results'      => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
