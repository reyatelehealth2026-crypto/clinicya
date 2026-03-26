<?php
/**
 * Test Webhook Events Script
 * ยิง webhook ทุก event type สำหรับ user PC251007 / Ua1156d646cad2237e878457833bc07b3
 *
 * วิธีใช้ (CLI):    php api/test-webhook-events.php TEST_ONLY_2026
 * วิธีใช้ (Browser): https://cny.re-ya.com/api/test-webhook-events.php?secret=TEST_ONLY_2026
 * ยิงเฉพาะ event:   ...?secret=TEST_ONLY_2026&event=invoice.paid
 */

// ── Guard ──────────────────────────────────────────────────────────────────
$allowedSecret = $_ENV['TEST_WEBHOOK_SECRET'] ?? 'TEST_ONLY_2026';
$givenSecret   = $_GET['secret'] ?? ($argv[1] ?? '');
if ($givenSecret !== $allowedSecret) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden. Pass ?secret=TEST_ONLY_2026']));
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';

// ── Config ──────────────────────────────────────────────────────────────────
// ใช้ new handler ที่ใช้งานจริง (api/webhook/odoo.php) ไม่ใช่ไฟล์เก่า
$webhookUrl     = (defined('APP_URL') ? APP_URL : 'https://cny.re-ya.com') . '/api/webhook/odoo.php';
$webhookSecret  = defined('ODOO_WEBHOOK_SECRET') ? ODOO_WEBHOOK_SECRET : ($_ENV['ODOO_WEBHOOK_SECRET'] ?? '');
$internalSecret = $_ENV['INTERNAL_API_SECRET'] ?? '';

/**
 * สร้าง headers ที่ new handler ต้องการ:
 *   X-Odoo-Delivery-Id  — unique per request (idempotency key)
 *   X-Odoo-Timestamp    — unix timestamp ปัจจุบัน
 *   X-Odoo-Signature    — sha256=HMAC(payload, secret)  [FORMAT 1: new standard]
 *   X-Odoo-Event        — event type string
 */
function buildWebhookHeaders(string $payload, string $eventType, string $deliveryId, string $secret): array {
    $timestamp = (string) time();
    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return [
        'Content-Type: application/json',
        'X-Odoo-Delivery-Id: '  . $deliveryId,
        'X-Odoo-Timestamp: '    . $timestamp,
        'X-Odoo-Signature: '    . $signature,
        'X-Odoo-Event: '        . $eventType,
    ];
}

// ── Test customer data ──────────────────────────────────────────────────────
$LINE_USER_ID   = 'Ua1156d646cad2237e878457833bc07b3';
$PARTNER_ID     = 125638;
$CUSTOMER_NAME  = 'ลูกค้าทดสอบ (PC251007)';
$CUSTOMER_REF   = 'PC251007';

// Fake IDs — ใช้เลขสูงๆ ไม่ชนของจริง
$ORDER_ID       = 999901;
$ORDER_NAME     = 'SO-TEST-99901';
$BDO_ID         = 998901;
$BDO_NAME       = 'BDO-TEST-98901';
$INVOICE_ID     = 997901;   // invoice.paid → เพิ่มแต้ม
$INVOICE_NUM    = 'HS-TEST-97901';
$INVOICE_ID2    = 997902;   // invoice.created
$INVOICE_ID3    = 997903;   // invoice.overdue
$PAYMENT_ID     = 996901;
$AMOUNT         = 5500.00;  // 5,500 ฿ → 5 points

$now    = date('Y-m-d\TH:i:s\Z');
$today  = date('Y-m-d');

$customer = [
    'id'           => $PARTNER_ID,
    'ref'          => $CUSTOMER_REF,
    'name'         => $CUSTOMER_NAME,
    'line_user_id' => $LINE_USER_ID,
    'phone'        => '0891234567',
];
$salesperson = ['id' => 29, 'name' => 'พนักงานทดสอบ', 'line_user_id' => false];

// ── Event definitions ──────────────────────────────────────────────────────
$events = [

    // ── ORDER ─────────────────────────────────────────────────────────────
    ['label' => '1. order.validated — ยืนยันออเดอร์',
     'event' => 'order.validated',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME, 'order_ref' => $CUSTOMER_REF,
        'new_state' => 'validated', 'old_state' => 'sale',
        'customer' => $customer, 'salesperson' => $salesperson,
        'amount_total' => $AMOUNT, 'amount_untaxed' => 5140.19, 'amount_tax' => 359.81,
        'order_date' => $today, 'payment_method' => 'bank_transfer', 'payment_state' => 'not_paid',
    ]],

    ['label' => '2. order.picking — กำลังจัดสินค้า',
     'event' => 'order.picking',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'picking', 'old_state' => 'validated',
        'customer' => $customer, 'amount_total' => $AMOUNT,
        'picker' => ['id' => 5, 'name' => 'พนักงานจัดของ'],
    ]],

    ['label' => '3. order.packed — แพ็คเสร็จ',
     'event' => 'order.packed',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'packed', 'old_state' => 'picking',
        'customer' => $customer, 'amount_total' => $AMOUNT,
    ]],

    ['label' => '4. order.awaiting_payment — รอชำระเงิน',
     'event' => 'order.awaiting_payment',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'awaiting_payment', 'old_state' => 'packed',
        'customer' => $customer, 'amount_total' => $AMOUNT,
    ]],

    ['label' => '5. order.paid — ชำระแล้ว',
     'event' => 'order.paid',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'paid', 'old_state' => 'awaiting_payment',
        'customer' => $customer, 'amount_total' => $AMOUNT, 'payment_state' => 'paid',
    ]],

    ['label' => '6. order.to_delivery — เตรียมส่ง',
     'event' => 'order.to_delivery',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'to_delivery', 'old_state' => 'paid',
        'customer' => $customer, 'amount_total' => $AMOUNT,
    ]],

    ['label' => '7. order.in_delivery — กำลังจัดส่ง',
     'event' => 'order.in_delivery',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'in_delivery', 'old_state' => 'to_delivery',
        'customer' => $customer, 'amount_total' => $AMOUNT,
        'driver' => ['id' => 7, 'name' => 'คนขับทดสอบ'],
    ]],

    ['label' => '8. order.delivered — จัดส่งแล้ว',
     'event' => 'order.delivered',
     'data'  => [
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'new_state' => 'delivered', 'old_state' => 'in_delivery',
        'customer' => $customer, 'amount_total' => $AMOUNT,
    ]],

    // ── BDO ──────────────────────────────────────────────────────────────
    ['label' => '9. bdo.confirmed — BDO ยืนยันแล้ว',
     'event' => 'bdo.confirmed',
     'data'  => [
        'bdo_id' => $BDO_ID, 'bdo_name' => $BDO_NAME, 'bdo_date' => $today,
        'new_state' => 'confirmed',
        'customer' => $customer,
        'sale_order' => ['id' => $ORDER_ID, 'name' => $ORDER_NAME],
        'invoice' => [
            'invoice_id' => $INVOICE_ID, 'invoice_number' => $INVOICE_NUM,
            'amount_total' => $AMOUNT, 'amount_untaxed' => 5140.19, 'amount_tax' => 359.81,
            'amount_residual' => $AMOUNT, 'state' => 'open',
            'invoice_date' => $today, 'due_date' => $today,
            'pdf_url' => "/report/pdf/account.report_invoice/{$INVOICE_ID}",
        ],
        'payment' => [
            'method' => 'bank_transfer',
            'bank_transfer' => ['bank_name' => 'กสิกรไทย', 'account_number' => '123-4-56789-0'],
            'promptpay' => [],
        ],
        'amount_total' => $AMOUNT, 'amount_net_to_pay' => $AMOUNT,
        'delivery_type' => 'company',
    ]],

    ['label' => '10. bdo.done — BDO เสร็จสิ้น',
     'event' => 'bdo.done',
     'data'  => [
        'bdo_id' => $BDO_ID, 'bdo_name' => $BDO_NAME,
        'new_state' => 'done', 'old_state' => 'confirmed',
        'customer' => $customer,
        'sale_order' => ['id' => $ORDER_ID, 'name' => $ORDER_NAME],
        'amount_total' => $AMOUNT,
    ]],

    ['label' => '11. bdo.cancelled — BDO ยกเลิก (BDO คนละตัว)',
     'event' => 'bdo.cancelled',
     'data'  => [
        'bdo_id' => $BDO_ID + 1, 'bdo_name' => 'BDO-TEST-CANCEL',
        'new_state' => 'cancelled',
        'customer' => $customer,
        'sale_order' => ['id' => $ORDER_ID, 'name' => $ORDER_NAME],
        'amount_total' => $AMOUNT,
    ]],

    // ── DELIVERY ─────────────────────────────────────────────────────────
    ['label' => '12. delivery.departed — ออกจัดส่ง',
     'event' => 'delivery.departed',
     'data'  => [
        'delivery_id' => 88901, 'delivery_name' => 'DLV-TEST-88901',
        'driver' => ['id' => 7, 'name' => 'คนขับทดสอบ'],
        'orders' => [['order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME]],
    ]],

    ['label' => '13. delivery.completed — ส่งถึงแล้ว',
     'event' => 'delivery.completed',
     'data'  => [
        'delivery_id' => 88901, 'delivery_name' => 'DLV-TEST-88901',
        'driver' => ['id' => 7, 'name' => 'คนขับทดสอบ'],
        'orders' => [['order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME]],
    ]],

    // ── INVOICE ───────────────────────────────────────────────────────────
    ['label' => '14. invoice.created — สร้างใบแจ้งหนี้',
     'event' => 'invoice.created',
     'data'  => [
        'invoice_id' => $INVOICE_ID2, 'invoice_number' => 'HS-TEST-97902',
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'customer' => $customer, 'salesperson' => $salesperson,
        'amount_total' => $AMOUNT, 'amount_tax' => 359.81, 'amount_untaxed' => 5140.19,
        'amount_residual' => $AMOUNT, 'state' => 'open',
        'invoice_date' => $today, 'due_date' => $today,
        'pdf_url' => "/report/pdf/account.report_invoice/{$INVOICE_ID2}",
    ]],

    ['label' => '15. invoice.paid — ชำระใบแจ้งหนี้ ✨ (จะเพิ่มแต้ม 5 pt)',
     'event' => 'invoice.paid',
     'data'  => [
        'invoice_id' => $INVOICE_ID, 'invoice_number' => $INVOICE_NUM,
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'customer' => $customer, 'salesperson' => $salesperson,
        'amount_total' => $AMOUNT, 'amount_tax' => 359.81, 'amount_untaxed' => 5140.19,
        'currency' => 'THB', 'invoice_date' => $today, 'due_date' => $today,
        'payment_term' => 'โอนก่อนส่ง',
        'pdf_url' => "/report/pdf/account.report_invoice/{$INVOICE_ID}",
    ],
     'notify' => ['customer' => true, 'salesperson' => false],
     'message_template' => ['customer' => ['th' => "ได้รับชำระเงินใบแจ้งหนี้ {$INVOICE_NUM} เรียบร้อยแล้ว"]],
    ],

    ['label' => '16. invoice.overdue — ใบแจ้งหนี้เกินกำหนด',
     'event' => 'invoice.overdue',
     'data'  => [
        'invoice_id' => $INVOICE_ID3, 'invoice_number' => 'HS-TEST-97903',
        'order_id' => $ORDER_ID, 'order_name' => $ORDER_NAME,
        'customer' => $customer,
        'amount_total' => 3000.00, 'amount_residual' => 3000.00,
        'due_date' => date('Y-m-d', strtotime('-5 days')),
        'pdf_url' => "/report/pdf/account.report_invoice/{$INVOICE_ID3}",
    ]],

    // ── PAYMENT ───────────────────────────────────────────────────────────
    ['label' => '17. payment.confirmed — ยืนยันการชำระเงิน',
     'event' => 'payment.confirmed',
     'data'  => [
        'payment_id' => $PAYMENT_ID, 'payment_name' => 'PAY-TEST-96901',
        'invoice_id' => $INVOICE_ID, 'new_state' => 'post',
        'customer' => $customer,
        'payment' => [
            'amount' => $AMOUNT, 'currency' => 'THB', 'method' => 'bank_transfer',
            'reference' => 'REF-TEST-001', 'date' => $today,
            'bank_name' => 'กสิกรไทย', 'bank_account' => '123-4-56789-0',
        ],
        'related_orders' => [$ORDER_NAME],
    ]],
];

// ── Run ─────────────────────────────────────────────────────────────────────
$runOnly = $_GET['event'] ?? null;   // ?event=invoice.paid ยิงแค่ event เดียว
$results = [];

foreach ($events as $item) {
    if ($runOnly && $item['event'] !== $runOnly) continue;

    // Build full webhook body
    $body = [
        'event'     => $item['event'],
        'timestamp' => $now,
        'data'      => $item['data'],
    ];
    if (isset($item['notify']))           $body['notify']           = $item['notify'];
    if (isset($item['message_template'])) $body['message_template'] = $item['message_template'];

    $payload    = json_encode($body, JSON_UNESCAPED_UNICODE);
    // Unique delivery_id per run+event — prevents idempotency collision on re-runs
    $deliveryId = 'test-' . date('YmdHis') . '-' . substr(md5($item['event']), 0, 8);
    $headers    = buildWebhookHeaders($payload, $item['event'], $deliveryId, $webhookSecret);

    // curl POST to webhook endpoint
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $decoded = $response ? @json_decode($response, true) : null;
    $ok      = $httpCode === 200 && ($decoded['success'] ?? false);

    $row = [
        'label'    => $item['label'],
        'event'    => $item['event'],
        'http'     => $httpCode,
        'status'   => $ok ? '✅ ok' : '❌ fail',
        'response' => $decoded ?? $response,
    ];
    if ($curlErr) $row['curl_error'] = $curlErr;

    $results[] = $row;

    // CLI output
    if (php_sapi_name() === 'cli') {
        echo ($ok ? "✅" : "❌") . " [{$httpCode}] {$item['label']}\n";
        if (!$ok) echo "   → " . ($decoded['error'] ?? $response) . "\n";
    }
}

echo json_encode([
    'webhook_url' => $webhookUrl,
    'total'       => count($results),
    'passed'      => count(array_filter($results, fn($r) => str_starts_with($r['status'], '✅'))),
    'results'     => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
