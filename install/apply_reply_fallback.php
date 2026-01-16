<?php
/**
 * Apply Reply Token Fallback to All Critical Points
 * 
 * This script documents all the places where replyMessage() needs fallback
 * and provides the fixes to apply.
 */

echo "=== Reply Token Fallback Application Guide ===\n\n";

$fixes = [
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~811 - Order Cancellation',
        'current' => '$line->replyMessage($replyToken, [$cancelMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$cancelMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~1326 - Order Confirmation (Flex)',
        'current' => '$line->replyMessage($replyToken, [$message]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$message], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~1329 - Order Not Found',
        'current' => '$line->replyMessage($replyToken, [[\'type\' => \'text\', \'text\' => \'ไม่พบคำสั่งซื้อ #\' . $orderNumber]]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [[\'type\' => \'text\', \'text\' => \'ไม่พบคำสั่งซื้อ #\' . $orderNumber]], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~3326 - LIFF Order Confirmation',
        'current' => '$line->replyMessage($replyToken, [$confirmMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$confirmMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~3345 - Order Creation Error',
        'current' => '$line->replyMessage($replyToken, [$errorMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$errorMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~3388 - No Pending Order',
        'current' => '$line->replyMessage($replyToken, "❌ คุณยังไม่มีคำสั่งซื้อที่รอชำระเงิน...");',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [[\'type\' => \'text\', \'text\' => "❌ คุณยังไม่มีคำสั่งซื้อที่รอชำระเงิน..."]], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🔴 CRITICAL',
        'location' => 'Line ~3508 - Show Pending Order',
        'current' => '$line->replyMessage($replyToken, [[\'type\' => \'flex\', ...]]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [[\'type\' => \'flex\', ...]], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 DONE',
        'location' => 'Line ~3542-3606 - Payment Slip Upload (5 points)',
        'current' => 'Multiple $line->replyMessage() calls',
        'fixed' => 'All replaced with sendMessageWithFallback()',
        'status' => 'COMPLETED'
    ],
    [
        'priority' => '🟢 DONE',
        'location' => 'Line ~565 - Broadcast Click',
        'current' => '$line->replyMessage($replyToken, ...)',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $lineUserId, ...)',
        'status' => 'COMPLETED'
    ],
    [
        'priority' => '🟡 MEDIUM',
        'location' => 'Line ~923 - Consent Request',
        'current' => '$line->replyMessage($replyToken, [$consentMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$consentMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟡 MEDIUM',
        'location' => 'Line ~956 - LIFF Menu',
        'current' => '$line->replyMessage($replyToken, [$liffMenuMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$liffMenuMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟡 MEDIUM',
        'location' => 'Line ~1083 - LIFF Reply',
        'current' => '$line->replyMessage($replyToken, [$liffReply]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$liffReply], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟡 MEDIUM',
        'location' => 'Line ~1124 - Stop AI (Request Pharmacist)',
        'current' => '$line->replyMessage($replyToken, [$stopMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$stopMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟡 MEDIUM',
        'location' => 'Line ~1356 - AI Not Enabled',
        'current' => '$line->replyMessage($replyToken, [[\'type\' => \'text\', ...]]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [[\'type\' => \'text\', ...]], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟡 MEDIUM',
        'location' => 'Line ~1393 - Shop Flex Message',
        'current' => '$line->replyMessage($replyToken, [$message]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$message], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1411 - Auto-reply by Bot Mode',
        'current' => '$line->replyMessage($replyToken, [$autoReply]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$autoReply], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1443 - Contact Command',
        'current' => '$replyResult = $line->replyMessage($replyToken, [$contactMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$contactMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1490 - Menu Command',
        'current' => '$line->replyMessage($replyToken, [$menuMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$menuMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1513 - Quick Menu Command',
        'current' => '$line->replyMessage($replyToken, [$menuMessage]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$menuMessage], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1530 - Auto-reply Fallback',
        'current' => '$line->replyMessage($replyToken, [$autoReply]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$autoReply], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1576 - checkAutoReply',
        'current' => '$line->replyMessage($replyToken, [$reply]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [$reply], $db);',
        'status' => 'PENDING'
    ],
    [
        'priority' => '🟢 LOW',
        'location' => 'Line ~1600 - Generic Error',
        'current' => '$line->replyMessage($replyToken, [\'type\' => \'text\', ...]);',
        'fixed' => 'sendMessageWithFallback($line, $replyToken, $userId, [[\'type\' => \'text\', ...]], $db);',
        'status' => 'PENDING'
    ],
];

// Display summary
$completed = array_filter($fixes, fn($f) => $f['status'] === 'COMPLETED');
$pending = array_filter($fixes, fn($f) => $f['status'] === 'PENDING');

echo "Progress: " . count($completed) . "/" . count($fixes) . " completed\n\n";

echo "=== COMPLETED ===\n";
foreach ($completed as $fix) {
    echo "{$fix['priority']} {$fix['location']}\n";
}

echo "\n=== PENDING (Need to Fix) ===\n";
foreach ($pending as $fix) {
    echo "\n{$fix['priority']} {$fix['location']}\n";
    echo "Current: {$fix['current']}\n";
    echo "Fixed:   {$fix['fixed']}\n";
}

echo "\n\n=== NEXT STEPS ===\n";
echo "1. Apply fixes to all 🔴 CRITICAL points first\n";
echo "2. Test payment flow and order management\n";
echo "3. Apply fixes to 🟡 MEDIUM points\n";
echo "4. Apply fixes to 🟢 LOW points\n";
echo "5. Monitor dev_logs for 'Reply failed, used push fallback' messages\n";
echo "\n";
echo "Query to check fallback usage:\n";
echo "SELECT DATE(created_at) as date, COUNT(*) as fallback_count\n";
echo "FROM dev_logs\n";
echo "WHERE message = 'Reply failed, used push fallback'\n";
echo "GROUP BY DATE(created_at)\n";
echo "ORDER BY date DESC;\n";
