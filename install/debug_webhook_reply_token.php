<?php
/**
 * Debug Webhook Reply Token
 * วางไฟล์นี้ที่ root และตั้งเป็น Webhook URL ชั่วคราวเพื่อดู raw data
 */

// Log ทุกอย่างที่เข้ามา
$logFile = __DIR__ . '/../webhook_debug.log';

$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? 'NO_SIGNATURE';
$accountParam = $_GET['account'] ?? 'NO_ACCOUNT_PARAM';

$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'account_param' => $accountParam,
    'signature' => substr($signature, 0, 20) . '...',
    'body' => json_decode($body, true)
];

file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

// ตรวจสอบ replyToken ในแต่ละ event
$events = json_decode($body, true)['events'] ?? [];
foreach ($events as $event) {
    $hasReplyToken = isset($event['replyToken']) && !empty($event['replyToken']);
    $eventType = $event['type'] ?? 'unknown';
    $userId = $event['source']['userId'] ?? 'no_user';
    
    $tokenLog = [
        'event_type' => $eventType,
        'user_id' => substr($userId, 0, 10) . '...',
        'has_reply_token' => $hasReplyToken ? 'YES' : 'NO',
        'reply_token' => $hasReplyToken ? substr($event['replyToken'], 0, 30) . '...' : 'NULL'
    ];
    
    file_put_contents($logFile, "Event: " . json_encode($tokenLog, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

http_response_code(200);
echo "OK";
