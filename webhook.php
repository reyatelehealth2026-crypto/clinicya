<?php
/**
 * LINE Webhook Handler - Multi-Account Support
 * V2.5 - Universal Business Platform
 */

// Global error handler for webhook
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch all errors and log them
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES ('error', 'webhook_fatal', ?, ?, NOW())");
            $stmt->execute([
                $error['message'],
                json_encode(['file' => $error['file'], 'line' => $error['line'], 'type' => $error['type']])
            ]);
        } catch (Exception $e) {
            error_log("Webhook fatal error: " . $error['message']);
        }
    }
});

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/ActivityLogger.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';
require_once 'classes/OpenAI.php';
require_once 'classes/TelegramAPI.php';
require_once 'classes/FlexTemplates.php';

// V2.5: Load BusinessBot if available, fallback to ShopBot
if (file_exists(__DIR__ . '/classes/BusinessBot.php')) {
    require_once 'classes/BusinessBot.php';
}
if (file_exists(__DIR__ . '/classes/ShopBot.php')) {
    require_once 'classes/ShopBot.php';
}
if (file_exists(__DIR__ . '/classes/CRMManager.php')) {
    require_once 'classes/CRMManager.php';
}
if (file_exists(__DIR__ . '/classes/AutoTagManager.php')) {
    require_once 'classes/AutoTagManager.php';
}
// LIFF Message Handler for processing LIFF-triggered messages
if (file_exists(__DIR__ . '/classes/LiffMessageHandler.php')) {
    require_once 'classes/LiffMessageHandler.php';
}

// Get request body and signature
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

$db = Database::getInstance()->getConnection();

// Multi-account support: à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸²à¸ˆà¸²à¸ account à¹„à¸«à¸™
$lineAccountId = null;
$lineAccount = null;
$line = null;

// Try to get account from query parameter first
if (isset($_GET['account'])) {
    $manager = new LineAccountManager($db);
    $lineAccount = $manager->getAccountById($_GET['account']);
    if ($lineAccount) {
        $line = new LineAPI($lineAccount['channel_access_token'], $lineAccount['channel_secret']);
        if ($line->validateSignature($body, $signature)) {
            $lineAccountId = $lineAccount['id'];
        } else {
            $lineAccount = null;
            $line = null;
        }
    }
}

// If no account from parameter, try to find by signature
if (!$lineAccount) {
    try {
        $manager = new LineAccountManager($db);
        $lineAccount = $manager->validateAndGetAccount($body, $signature);
        if ($lineAccount) {
            $lineAccountId = $lineAccount['id'];
            $line = new LineAPI($lineAccount['channel_access_token'], $lineAccount['channel_secret']);
        }
    } catch (Exception $e) {
        // Table doesn't exist, use default
    }
}

// Fallback to default config
if (!$line) {
    $line = new LineAPI();
    if (!$line->validateSignature($body, $signature)) {
        http_response_code(400);
            exit('Invalid signature');
        }
    }

        $events = json_decode($body, true)['events'] ?? [];

    /**
     * à¹à¸ªà¸”à¸‡ Loading Animation à¹ƒà¸™ LINE Chat
     * @param LineAPI $line - LINE API instance
     * @param string $chatId - User ID à¸«à¸£à¸·à¸­ Group ID
     * @param int $seconds - à¸ˆà¸³à¸™à¸§à¸™à¸§à¸´à¸™à¸²à¸—à¸µ (5-60)
     */
    function showLoadingAnimation($line, $chatId, $seconds = 10) {
        try {
            $url = 'https://api.line.me/v2/bot/chat/loading/start';
            $data = [
                'chatId' => $chatId,
                'loadingSeconds' => min(max($seconds, 5), 60) // 5-60 seconds
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $line->getAccessToken()
                ],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
        } catch (Exception $e) {
            error_log("showLoadingAnimation error: " . $e->getMessage());
            return false;
        }
    }

    // Log incoming webhook
    if (!empty($events)) {
        try {
            devLog($db, 'webhook', 'webhook', 'Incoming webhook', [
                'event_count' => count($events),
                'account_id' => $lineAccountId,
                'events' => array_map(fn($e) => $e['type'] ?? 'unknown', $events)
            ]);
        } catch (Exception $e) {}
    }

    foreach ($events as $event) {
        try {
            $userId = $event['source']['userId'] ?? null;
            $replyToken = $event['replyToken'] ?? null;
            $sourceType = $event['source']['type'] ?? 'user';
            $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
            
            // Handle join/leave events (à¹„à¸¡à¹ˆà¸•à¹‰à¸­à¸‡à¸¡à¸µ userId)
            if ($event['type'] === 'join') {
                handleJoinGroup($event, $db, $line, $lineAccountId);
                continue;
            }
            if ($event['type'] === 'leave') {
                handleLeaveGroup($event, $db, $lineAccountId);
                continue;
            }
            
            // à¸ªà¸³à¸«à¸£à¸±à¸š event à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡ - à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¹à¸¥à¸°à¸ªà¸£à¹‰à¸²à¸‡à¸à¸¥à¸¸à¹ˆà¸¡à¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´à¸–à¹‰à¸²à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ
            if (($sourceType === 'group' || $sourceType === 'room') && $groupId && $lineAccountId) {
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¹à¸¥à¸°à¸ªà¸£à¹‰à¸²à¸‡à¸à¸¥à¸¸à¹ˆà¸¡à¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´
                ensureGroupExists($db, $line, $lineAccountId, $groupId, $sourceType);
                
                if ($userId) {
                    // à¸šà¸±à¸™à¸—à¸¶à¸à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡
                    $groupUser = getOrCreateUser($db, $line, $userId, $lineAccountId, $groupId);
                    $dbUserId = $groupUser['id'] ?? null;
                    
                    // à¸šà¸±à¸™à¸—à¸¶à¸ event à¸žà¸£à¹‰à¸­à¸¡ source_id (groupId)
                    saveAccountEvent($db, $lineAccountId, $event['type'], $userId, $dbUserId, $event);
                    
                    // à¸­à¸±à¸žà¹€à¸”à¸—à¸ªà¸–à¸´à¸•à¸´à¸à¸¥à¸¸à¹ˆà¸¡
                    updateGroupStats($db, $lineAccountId, $groupId, $event['type']);
                }
                // Skip saveAccountEvent if no userId (bot events from group)
            }
            
            if (!$userId) continue;
            
            // Deduplication: à¸›à¹‰à¸­à¸‡à¸à¸±à¸™à¸à¸²à¸£à¸›à¸£à¸°à¸¡à¸§à¸¥à¸œà¸¥ event à¸‹à¹‰à¸³
            $webhookEventId = $event['webhookEventId'] ?? null;
            $messageText = $event['message']['text'] ?? '';
            
            // Log à¸—à¸¸à¸ event à¸—à¸µà¹ˆà¹€à¸‚à¹‰à¸²à¸¡à¸²
            devLog($db, 'debug', 'webhook', 'Event received', [
                'event_id' => $webhookEventId ? substr($webhookEventId, 0, 20) : 'none',
                'type' => $event['type'] ?? 'unknown',
                'message' => mb_substr($messageText, 0, 30),
                'user_id' => $userId
            ], $userId);
            
            if ($webhookEventId) {
                try {
                    $stmt = $db->prepare("SELECT id FROM webhook_events WHERE event_id = ?");
                    $stmt->execute([$webhookEventId]);
                    if ($stmt->fetch()) {
                        devLog($db, 'warning', 'webhook', 'Duplicate event skipped', [
                            'event_id' => substr($webhookEventId, 0, 20)
                        ], $userId);
                        continue; // Event à¸™à¸µà¹‰à¸–à¸¹à¸à¸›à¸£à¸°à¸¡à¸§à¸¥à¸œà¸¥à¹à¸¥à¹‰à¸§
                    }
                    // à¸šà¸±à¸™à¸—à¸¶à¸ event ID
                    $stmt = $db->prepare("INSERT INTO webhook_events (event_id) VALUES (?)");
                    $stmt->execute([$webhookEventId]);
                } catch (Exception $e) {
                    // Table doesn't exist or duplicate key - ignore and continue
                }
            }

            switch ($event['type']) {
                case 'follow':
                    // Follow event à¸¡à¸µ replyToken - à¹ƒà¸Šà¹‰ reply à¹à¸—à¸™ push à¹€à¸žà¸·à¹ˆà¸­à¸›à¸£à¸°à¸«à¸¢à¸±à¸” quota
                    handleFollow($userId, $replyToken, $db, $line, $lineAccountId, $event);
                    break;
                case 'unfollow':
                    handleUnfollow($userId, $db, $lineAccountId, $event);
                    break;
                case 'message':
                    handleMessage($event, $userId, $replyToken, $db, $line, $lineAccountId);
                    break;
            case 'postback':
                // à¸šà¸±à¸™à¸—à¸¶à¸ postback event
                $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
                $stmt->execute([$userId]);
                $dbUserId = $stmt->fetchColumn();
                
                if ($lineAccountId) {
                    saveAccountEvent($db, $lineAccountId, 'postback', $userId, $dbUserId, $event);
                }
                
                // Handle Broadcast Product Click - Auto Tag
                $postbackData = $event['postback']['data'] ?? '';
                
                // à¸£à¸­à¸‡à¸£à¸±à¸šà¸—à¸±à¹‰à¸‡ 2 à¸£à¸¹à¸›à¹à¸šà¸š: broadcast_click_{id}_{id} à¸«à¸£à¸·à¸­ JSON {"action":"broadcast_click",...}
                $isBroadcastClick = false;
                if (strpos($postbackData, 'broadcast_click_') === 0) {
                    $isBroadcastClick = true;
                } elseif (strpos($postbackData, '{') === 0) {
                    $jsonData = json_decode($postbackData, true);
                    if ($jsonData && ($jsonData['action'] ?? '') === 'broadcast_click') {
                        $isBroadcastClick = true;
                    }
                }
                
                if ($isBroadcastClick && $dbUserId) {
                    handleBroadcastClick($db, $line, $dbUserId, $userId, $postbackData, $replyToken, $lineAccountId);
                }
                break;
            case 'beacon':
                // à¸šà¸±à¸™à¸—à¸¶à¸ beacon event
                if ($lineAccountId) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
                    $stmt->execute([$userId]);
                    $dbUserId = $stmt->fetchColumn();
                    saveAccountEvent($db, $lineAccountId, 'beacon', $userId, $dbUserId, $event);
                }
                break;
            case 'memberJoined':
                // à¸ªà¸¡à¸²à¸Šà¸´à¸à¹ƒà¸«à¸¡à¹ˆà¹€à¸‚à¹‰à¸²à¸à¸¥à¸¸à¹ˆà¸¡
                if ($groupId && $lineAccountId) {
                    handleMemberJoined($event, $groupId, $db, $line, $lineAccountId);
                }
                break;
            case 'memberLeft':
                // à¸ªà¸¡à¸²à¸Šà¸´à¸à¸­à¸­à¸à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡
                if ($groupId && $lineAccountId) {
                    handleMemberLeft($event, $groupId, $db, $lineAccountId);
                }
                break;
            }
            
            // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡ à¹ƒà¸«à¹‰à¸šà¸±à¸™à¸—à¸¶à¸à¸”à¹‰à¸§à¸¢
            if ($event['type'] === 'message' && $groupId && $lineAccountId) {
                saveGroupMessage($db, $lineAccountId, $groupId, $userId, $event);
            }
        } catch (Exception $e) {
            // Log error to dev_logs
            devLog($db, 'error', 'webhook_event', $e->getMessage(), [
                'event_type' => $event['type'] ?? 'unknown',
                'user_id' => $userId ?? null,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice($e->getTrace(), 0, 5)
            ], $userId ?? null);
            error_log("Webhook event error: " . $e->getMessage());
        }
    }

        http_response_code(200);

    /**
     * Handle follow event
     * à¹ƒà¸Šà¹‰ replyToken à¹€à¸žà¸·à¹ˆà¸­à¸›à¸£à¸°à¸«à¸¢à¸±à¸” quota (reply à¸Ÿà¸£à¸µ, push à¸™à¸±à¸š quota)
     */
    function handleFollow($userId, $replyToken, $db, $line, $lineAccountId = null, $event = null) {
        $profile = $line->getProfile($userId);
        $displayName = $profile['displayName'] ?? '';
        $pictureUrl = $profile['pictureUrl'] ?? '';
        $statusMessage = $profile['statusMessage'] ?? '';
        
        // Check if line_account_id column exists
        $hasAccountCol = false;
        try {
            $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
            $hasAccountCol = $stmt->rowCount() > 0;
        } catch (Exception $e) {}
        
        $dbUserId = null;
        if ($hasAccountCol && $lineAccountId) {
            $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name, picture_url, status_message) 
                                VALUES (?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE display_name = ?, picture_url = ?, is_blocked = 0");
            $stmt->execute([
                $lineAccountId,
                $userId,
                $displayName,
                $pictureUrl,
                $statusMessage,
                $displayName,
                $pictureUrl
            ]);
            $dbUserId = $db->lastInsertId() ?: null;
        } else {
            $stmt = $db->prepare("INSERT INTO users (line_user_id, display_name, picture_url, status_message) 
                                VALUES (?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE display_name = ?, picture_url = ?, is_blocked = 0");
            $stmt->execute([
                $userId,
                $displayName,
                $pictureUrl,
                $statusMessage,
                $displayName,
                $pictureUrl
            ]);
            $dbUserId = $db->lastInsertId() ?: null;
        }
        
        // Get user ID if not from insert
        if (!$dbUserId) {
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
            $stmt->execute([$userId]);
            $dbUserId = $stmt->fetchColumn();
        }
        
        // à¸šà¸±à¸™à¸—à¸¶à¸à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ follower à¹à¸¢à¸à¸•à¸²à¸¡à¸šà¸­à¸—
        if ($lineAccountId) {
            saveAccountFollower($db, $lineAccountId, $userId, $dbUserId, $profile, true);
            saveAccountEvent($db, $lineAccountId, 'follow', $userId, $dbUserId, $event);
            updateAccountDailyStats($db, $lineAccountId, 'new_followers');
        }

        // V2.5: CRM - Auto-tag new customer & trigger drip campaigns
        if ($dbUserId && class_exists('CRMManager')) {
            try {
                $crm = new CRMManager($db, $lineAccountId);
                $crm->onUserFollow($dbUserId);
            } catch (Exception $e) {
                error_log("CRM onUserFollow error: " . $e->getMessage());
            }
        }
        
        // V2.5: Auto Tag Manager
        if ($dbUserId && class_exists('AutoTagManager')) {
            try {
                $autoTag = new AutoTagManager($db, $lineAccountId);
                $autoTag->onFollow($dbUserId);
            } catch (Exception $e) {
                error_log("AutoTag onFollow error: " . $e->getMessage());
            }
        }

        // Dynamic Rich Menu - à¸à¸³à¸«à¸™à¸” Rich Menu à¸•à¸²à¸¡à¸à¸Žà¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´
        if ($dbUserId && $lineAccountId) {
            try {
                if (file_exists(__DIR__ . '/classes/DynamicRichMenu.php')) {
                    require_once __DIR__ . '/classes/DynamicRichMenu.php';
                    $dynamicMenu = new DynamicRichMenu($db, $line, $lineAccountId);
                    $dynamicMenu->assignRichMenuByRules($dbUserId, $userId);
                }
            } catch (Exception $e) {
                error_log("DynamicRichMenu onFollow error: " . $e->getMessage());
            }
        }

        // Send welcome message - à¹ƒà¸Šà¹‰ reply à¹à¸—à¸™ push à¹€à¸žà¸·à¹ˆà¸­à¸›à¸£à¸°à¸«à¸¢à¸±à¸” quota!
        sendWelcomeMessage($db, $line, $userId, $replyToken, $lineAccountId);

        // Log analytics
        logAnalytics($db, 'follow', ['user_id' => $userId, 'line_account_id' => $lineAccountId], $lineAccountId);

        // Telegram notification à¸žà¸£à¹‰à¸­à¸¡à¸Šà¸·à¹ˆà¸­à¸šà¸­à¸—
        $accountName = getAccountName($db, $lineAccountId);
        sendTelegramNotification($db, 'follow', $displayName, '', $userId, $dbUserId, $accountName);
    }

    /**
     * Send welcome message to new follower
     * à¹ƒà¸Šà¹‰ replyMessage à¹€à¸žà¸·à¹ˆà¸­à¸›à¸£à¸°à¸«à¸¢à¸±à¸” quota (à¸Ÿà¸£à¸µ!) à¸–à¹‰à¸²à¸¡à¸µ replyToken
     * à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¸¡à¸µ replyToken à¸ˆà¸° fallback à¹„à¸›à¹ƒà¸Šà¹‰ pushMessage
     * V5.1: à¹ƒà¸Šà¹‰ welcome_settings à¸ˆà¸²à¸à¸«à¸¥à¸±à¸‡à¸šà¹‰à¸²à¸™à¹€à¸—à¹ˆà¸²à¸™à¸±à¹‰à¸™ - à¹„à¸¡à¹ˆà¸¡à¸µ default hardcode
     */
    function sendWelcomeMessage($db, $line, $userId, $replyToken = null, $lineAccountId = null) {
        try {
            // Get user profile for personalized message
            $profile = $line->getProfile($userId);
            $displayName = $profile['displayName'] ?? 'à¸„à¸¸à¸“à¸¥à¸¹à¸à¸„à¹‰à¸²';
            $pictureUrl = $profile['pictureUrl'] ?? null;
            
            // Get shop name - à¹à¸¢à¸à¸•à¸²à¸¡ LINE Account
            $shopName = 'LINE Shop';
            try {
                if ($lineAccountId) {
                    $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
                    $stmt->execute([$lineAccountId]);
                } else {
                    $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
                }
                $shopSettings = $stmt->fetch();
                if ($shopSettings && $shopSettings['shop_name']) $shopName = $shopSettings['shop_name'];
            } catch (Exception $e) {}
            
            // Helper function to send message (reply if possible, otherwise push)
            $sendMessage = function($messages) use ($line, $userId, $replyToken) {
                if ($replyToken) {
                    // à¹ƒà¸Šà¹‰ reply - à¸Ÿà¸£à¸µ à¹„à¸¡à¹ˆà¸™à¸±à¸š quota!
                    return $line->replyMessage($replyToken, $messages);
                } else {
                    // Fallback to push - à¸™à¸±à¸š quota
                    return $line->pushMessage($userId, $messages);
                }
            };
            
            // Get welcome settings for this account - à¹ƒà¸Šà¹‰à¸ˆà¸²à¸à¸«à¸¥à¸±à¸‡à¸šà¹‰à¸²à¸™à¹€à¸—à¹ˆà¸²à¸™à¸±à¹‰à¸™
            $welcomeSettings = null;
            try {
                $stmt = $db->prepare("SELECT * FROM welcome_settings WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_enabled = 1 ORDER BY line_account_id DESC LIMIT 1");
                $stmt->execute([$lineAccountId]);
                $welcomeSettings = $stmt->fetch();
            } catch (Exception $e) {}
            
            // à¸–à¹‰à¸²à¸¡à¸µ welcome_settings à¸—à¸µà¹ˆà¹€à¸›à¸´à¸”à¹ƒà¸Šà¹‰à¸‡à¸²à¸™ - à¹ƒà¸Šà¹‰à¸„à¹ˆà¸²à¸ˆà¸²à¸à¸™à¸±à¹‰à¸™
            if ($welcomeSettings) {
                if ($welcomeSettings['message_type'] === 'text' && !empty($welcomeSettings['text_content'])) {
                    // Replace placeholders
                    $text = str_replace(['{name}', '{shop}'], [$displayName, $shopName], $welcomeSettings['text_content']);
                    $sendMessage([['type' => 'text', 'text' => $text]]);
                    return;
                } elseif ($welcomeSettings['message_type'] === 'flex' && !empty($welcomeSettings['flex_content'])) {
                    $flexContent = json_decode($welcomeSettings['flex_content'], true);
                    if ($flexContent) {
                        // Replace placeholders in flex JSON
                        $flexJson = str_replace(['{name}', '{shop}'], [$displayName, $shopName], $welcomeSettings['flex_content']);
                        $flexContent = json_decode($flexJson, true);
                        $message = [
                            'type' => 'flex',
                            'altText' => "à¸¢à¸´à¸™à¸”à¸µà¸•à¹‰à¸­à¸™à¸£à¸±à¸šà¸„à¸¸à¸“{$displayName}",
                            'contents' => $flexContent
                        ];
                        $sendMessage([$message]);
                        return;
                    }
                }
            }
            
            // à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¸¡à¸µ welcome_settings - à¹„à¸¡à¹ˆà¸ªà¹ˆà¸‡à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸•à¹‰à¸­à¸™à¸£à¸±à¸š (à¹ƒà¸«à¹‰à¸•à¸±à¹‰à¸‡à¸„à¹ˆà¸²à¸ˆà¸²à¸à¸«à¸¥à¸±à¸‡à¸šà¹‰à¸²à¸™)
            // Log à¹€à¸žà¸·à¹ˆà¸­à¹à¸ˆà¹‰à¸‡à¹ƒà¸«à¹‰à¸—à¸£à¸²à¸šà¸§à¹ˆà¸²à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¹„à¸”à¹‰à¸•à¸±à¹‰à¸‡à¸„à¹ˆà¸²
            devLog($db, 'info', 'welcome_message', 'No welcome_settings configured', [
                'line_account_id' => $lineAccountId,
                'user_id' => $userId
            ], $userId);
            
        } catch (Exception $e) {
            // Table doesn't exist or error - ignore
            error_log("Welcome message error: " . $e->getMessage());
        }
    }

    /**
     * Handle Broadcast Product Click - à¸•à¸´à¸” Tag à¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´à¹€à¸¡à¸·à¹ˆà¸­à¸¥à¸¹à¸à¸„à¹‰à¸²à¸à¸”à¸ªà¸´à¸™à¸„à¹‰à¸²
     */
    function handleBroadcastClick($db, $line, $dbUserId, $lineUserId, $postbackData, $replyToken, $lineAccountId) {
        try {
            $campaignId = null;
            $productId = null;
            $tagId = null;
            
            // à¸£à¸­à¸‡à¸£à¸±à¸š 2 à¸£à¸¹à¸›à¹à¸šà¸š: string format à¸«à¸£à¸·à¸­ JSON
            if (strpos($postbackData, '{') === 0) {
                // JSON format: {"action":"broadcast_click","campaign_id":1,"product_id":2,"tag_id":3}
                $jsonData = json_decode($postbackData, true);
                if ($jsonData) {
                    $campaignId = (int)($jsonData['campaign_id'] ?? 0);
                    $productId = (int)($jsonData['product_id'] ?? 0);
                    $tagId = $jsonData['tag_id'] ?? null;
                }
            } else {
                // String format: broadcast_click_{campaignId}_{productId}
                $parts = explode('_', $postbackData);
                if (count($parts) >= 4) {
                    $campaignId = (int)$parts[2];
                    $productId = (int)$parts[3];
                }
            }
            
            if (!$campaignId || !$productId) return;
            
            // à¸”à¸¶à¸‡à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ item
            $stmt = $db->prepare("SELECT bi.*, bc.auto_tag_enabled, bc.name as campaign_name 
                                FROM broadcast_items bi 
                                JOIN broadcast_campaigns bc ON bi.broadcast_id = bc.id 
                                WHERE bi.broadcast_id = ? AND bi.product_id = ?");
            $stmt->execute([$campaignId, $productId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) return;
            
            // à¸šà¸±à¸™à¸—à¸¶à¸ click
            try {
                $stmt = $db->prepare("INSERT INTO broadcast_clicks (broadcast_id, item_id, user_id, line_user_id, tag_assigned) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$campaignId, $item['id'], $dbUserId, $lineUserId, $item['auto_tag_enabled'] ? 1 : 0]);
                
                // à¸­à¸±à¸žà¹€à¸”à¸— click count
                $stmt = $db->prepare("UPDATE broadcast_items SET click_count = click_count + 1 WHERE id = ?");
                $stmt->execute([$item['id']]);
                
                $stmt = $db->prepare("UPDATE broadcast_campaigns SET click_count = click_count + 1 WHERE id = ?");
                $stmt->execute([$campaignId]);
            } catch (Exception $e) {}
            
            // à¸•à¸´à¸” Tag à¸–à¹‰à¸²à¹€à¸›à¸´à¸” auto tag
            // à¹ƒà¸Šà¹‰ tag_id à¸ˆà¸²à¸ item à¸«à¸£à¸·à¸­à¸ˆà¸²à¸ JSON postback data
            $finalTagId = $item['tag_id'] ?? $tagId;
            if ($item['auto_tag_enabled'] && $finalTagId) {
                try {
                    $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'broadcast')");
                    $stmt->execute([$dbUserId, $finalTagId]);
                    
                    // Log tag assignment
                    devLog($db, 'info', 'broadcast_auto_tag', "Auto tag assigned", [
                        'user_id' => $dbUserId,
                        'tag_id' => $finalTagId,
                        'campaign_id' => $campaignId,
                        'product_id' => $productId
                    ], $lineUserId);
                } catch (Exception $e) {
                    error_log("Auto tag error: " . $e->getMessage());
                }
            }
            
            // à¸•à¸­à¸šà¸à¸¥à¸±à¸šà¸¥à¸¹à¸à¸„à¹‰à¸²
            $replyText = "âœ… à¸‚à¸­à¸šà¸„à¸¸à¸“à¸—à¸µà¹ˆà¸ªà¸™à¹ƒà¸ˆ {$item['item_name']}\n\nà¸—à¸µà¸¡à¸‡à¸²à¸™à¸ˆà¸°à¸•à¸´à¸”à¸•à¹ˆà¸­à¸à¸¥à¸±à¸šà¹‚à¸”à¸¢à¹€à¸£à¹‡à¸§à¸—à¸µà¹ˆà¸ªà¸¸à¸”à¸„à¹ˆà¸° ðŸ™";
            $line->replyMessage($replyToken, [['type' => 'text', 'text' => $replyText]]);
            
            // à¹à¸ˆà¹‰à¸‡ Telegram
            sendTelegramNotification($db, 'broadcast_click', $item['item_name'], "à¸¥à¸¹à¸à¸„à¹‰à¸²à¸ªà¸™à¹ƒà¸ˆà¸ªà¸´à¸™à¸„à¹‰à¸²: {$item['item_name']}", $lineUserId, $dbUserId);
            
        } catch (Exception $e) {
            error_log("handleBroadcastClick error: " . $e->getMessage());
        }
    }

    /**
     * Handle unfollow event
     */
    function handleUnfollow($userId, $db, $lineAccountId = null, $event = null) {
        $stmt = $db->prepare("UPDATE users SET is_blocked = 1 WHERE line_user_id = ?");
        $stmt->execute([$userId]);

        // Get user info for notification
        $stmt = $db->prepare("SELECT id, display_name FROM users WHERE line_user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $dbUserId = $user['id'] ?? null;
        $displayName = $user['display_name'] ?? 'Unknown';
        
        // à¸šà¸±à¸™à¸—à¸¶à¸à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ unfollow à¹à¸¢à¸à¸•à¸²à¸¡à¸šà¸­à¸—
        if ($lineAccountId) {
            saveAccountFollower($db, $lineAccountId, $userId, $dbUserId, null, false);
            saveAccountEvent($db, $lineAccountId, 'unfollow', $userId, $dbUserId, $event);
            updateAccountDailyStats($db, $lineAccountId, 'unfollowers');
        }

        logAnalytics($db, 'unfollow', ['user_id' => $userId, 'line_account_id' => $lineAccountId], $lineAccountId);
        
        // Telegram notification à¸žà¸£à¹‰à¸­à¸¡à¸Šà¸·à¹ˆà¸­à¸šà¸­à¸—
        $accountName = getAccountName($db, $lineAccountId);
        sendTelegramNotification($db, 'unfollow', $displayName, '', $userId, $dbUserId, $accountName);
    }

    /**
     * Handle message event
     */
    function handleMessage($event, $userId, $replyToken, $db, $line, $lineAccountId = null) {
        try {
            $messageType = $event['message']['type'];
            $messageId = $event['message']['id'] ?? '';
            $messageText = $event['message']['text'] ?? '';
            $messageContent = $messageText;
            $sourceType = $event['source']['type'] ?? 'user';
            $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
            
            // Get markAsReadToken from message event (for LINE Mark as Read feature)
            $markAsReadToken = $event['message']['markAsReadToken'] ?? null;

            // Get or create user - à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¹à¸¥à¸°à¸šà¸±à¸™à¸—à¸¶à¸à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¹€à¸ªà¸¡à¸­ (à¹„à¸¡à¹ˆà¸§à¹ˆà¸²à¸ˆà¸°à¸¡à¸²à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡à¸«à¸£à¸·à¸­à¹à¸Šà¸—à¸ªà¹ˆà¸§à¸™à¸•à¸±à¸§)
            $user = getOrCreateUser($db, $line, $userId, $lineAccountId, $groupId);
            
            // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¹€à¸›à¹‡à¸™à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹à¸£à¸à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ (à¸™à¸±à¸šà¸ˆà¸³à¸™à¸§à¸™à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡ incoming à¸‚à¸­à¸‡ user)
            // à¸™à¸±à¸šà¸à¹ˆà¸­à¸™à¸—à¸µà¹ˆà¸ˆà¸°à¸šà¸±à¸™à¸—à¸¶à¸à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹ƒà¸«à¸¡à¹ˆ à¸”à¸±à¸‡à¸™à¸±à¹‰à¸™ == 0 à¸„à¸·à¸­à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹à¸£à¸
            $isFirstMessage = false;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND direction = 'incoming'");
                $stmt->execute([$user['id']]);
                $messageCount = (int)$stmt->fetchColumn();
                $isFirstMessage = ($messageCount == 0); // == 0 à¹€à¸žà¸£à¸²à¸°à¸™à¸±à¸šà¸à¹ˆà¸­à¸™à¸šà¸±à¸™à¸—à¸¶à¸
            } catch (Exception $e) {}

            // Check user state first (for waiting slip mode)
            $userState = getUserState($db, $user['id']);
            
            // Handle different message types
            $mediaUrl = null;
            if (in_array($messageType, ['image', 'video', 'audio', 'file'])) {
                // à¸”à¸²à¸§à¸™à¹Œà¹‚à¸«à¸¥à¸”à¹à¸¥à¸°à¹€à¸à¹‡à¸š media à¹„à¸§à¹‰à¹ƒà¸™ server à¸—à¸±à¸™à¸—à¸µ (LINE à¸ˆà¸°à¸¥à¸š content à¸«à¸¥à¸±à¸‡à¸ˆà¸²à¸à¸œà¹ˆà¸²à¸™à¹„à¸›à¸£à¸°à¸¢à¸°à¸«à¸™à¸¶à¹ˆà¸‡)
                $savedMediaUrl = null;
                if ($messageType === 'image') {
                    try {
                        $imageData = $line->getMessageContent($messageId);
                        if ($imageData && strlen($imageData) > 100) {
                            $uploadDir = __DIR__ . '/uploads/line_images/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            
                            // Detect extension from binary
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mimeType = $finfo->buffer($imageData) ?: 'image/jpeg';
                            $ext = 'jpg';
                            if ($mimeType === 'image/png') $ext = 'png';
                            elseif ($mimeType === 'image/gif') $ext = 'gif';
                            elseif ($mimeType === 'image/webp') $ext = 'webp';
                            
                            $filename = 'line_' . $messageId . '_' . time() . '.' . $ext;
                            $filepath = $uploadDir . $filename;
                            
                            if (file_put_contents($filepath, $imageData)) {
                                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                                $host = $_SERVER['HTTP_HOST'] ?? (defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_HOST) : 'localhost');
                                $savedMediaUrl = $protocol . $host . '/uploads/line_images/' . $filename;
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Failed to save LINE image: " . $e->getMessage());
                    }
                }
                
                // à¸–à¹‰à¸²à¸šà¸±à¸™à¸—à¸¶à¸à¸£à¸¹à¸›à¹„à¸”à¹‰ à¹ƒà¸Šà¹‰ URL à¸—à¸µà¹ˆà¸šà¸±à¸™à¸—à¸¶à¸ à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¹„à¸”à¹‰à¹ƒà¸Šà¹‰ LINE message ID à¹€à¸›à¹‡à¸™ fallback
                if ($savedMediaUrl) {
                    $messageContent = $savedMediaUrl;
                } else {
                    $messageContent = "[{$messageType}] ID: {$messageId}";
                }
                $mediaUrl = $messageId;
                
                // Check if user is in "waiting_slip" or "awaiting_slip" state - auto accept slip
                if ($messageType === 'image' && $userState && in_array($userState['state'], ['waiting_slip', 'awaiting_slip'])) {
                    $stateData = json_decode($userState['state_data'] ?? '{}', true);
                    $orderId = $stateData['order_id'] ?? $stateData['transaction_id'] ?? null;
                    if ($orderId) {
                        // Save message first
                        $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', ?, ?, ?)");
                        $stmt->execute([$user['id'], $messageType, $messageContent, $replyToken]);
                        
                        // Handle slip
                        $slipHandled = handlePaymentSlipForOrder($db, $line, $user['id'], $messageId, $replyToken, $orderId);
                        if ($slipHandled) {
                            clearUserState($db, $user['id']);
                            return;
                        }
                    }
                }
            } elseif ($messageType === 'sticker') {
                $stickerId = $event['message']['stickerId'] ?? '';
                $packageId = $event['message']['packageId'] ?? '';
                $messageContent = "[sticker] Package: {$packageId}, Sticker: {$stickerId}";
            } elseif ($messageType === 'location') {
                $lat = $event['message']['latitude'] ?? '';
                $lng = $event['message']['longitude'] ?? '';
                $address = $event['message']['address'] ?? '';
                $messageContent = "[location] {$address} ({$lat}, {$lng})";
            }

            // Save incoming message à¸žà¸£à¹‰à¸­à¸¡ line_account_id, is_read = 0, à¹à¸¥à¸° mark_as_read_token
            try {
                $stmt = $db->query("SHOW COLUMNS FROM messages LIKE 'line_account_id'");
                if ($stmt->rowCount() > 0) {
                    // Check if mark_as_read_token column exists
                    $stmt3 = $db->query("SHOW COLUMNS FROM messages LIKE 'mark_as_read_token'");
                    $hasMarkAsReadToken = $stmt3->rowCount() > 0;
                    
                    // Check if is_read column exists
                    $stmt2 = $db->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
                    if ($stmt2->rowCount() > 0) {
                        if ($hasMarkAsReadToken) {
                            $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, reply_token, is_read, mark_as_read_token) VALUES (?, ?, 'incoming', ?, ?, ?, 0, ?)");
                            $stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken, $markAsReadToken]);
                        } else {
                            $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, reply_token, is_read) VALUES (?, ?, 'incoming', ?, ?, ?, 0)");
                            $stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken]);
                        }
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, reply_token) VALUES (?, ?, 'incoming', ?, ?, ?)");
                        $stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken]);
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', ?, ?, ?)");
                    $stmt->execute([$user['id'], $messageType, $messageContent, $replyToken]);
                }
            } catch (Exception $e) {
                $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', ?, ?, ?)");
                $stmt->execute([$user['id'], $messageType, $messageContent, $replyToken]);
            }

            logAnalytics($db, 'message_received', ['user_id' => $userId, 'type' => $messageType, 'line_account_id' => $lineAccountId, 'source' => $sourceType], $lineAccountId);
            
            // à¸šà¸±à¸™à¸—à¸¶à¸ reply_token à¹ƒà¸™ users table (à¸«à¸¡à¸”à¸­à¸²à¸¢à¸¸à¹ƒà¸™ 20 à¸™à¸²à¸—à¸µ)
            if ($replyToken) {
                try {
                    // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µ column à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
                    $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'reply_token'");
                    if ($checkCol->rowCount() > 0) {
                        $expires = date('Y-m-d H:i:s', time() + (19 * 60)); // à¸«à¸¡à¸”à¸­à¸²à¸¢à¸¸à¹ƒà¸™ 19 à¸™à¸²à¸—à¸µ (à¹€à¸œà¸·à¹ˆà¸­ delay)
                        $stmt = $db->prepare("UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?");
                        $stmt->execute([$replyToken, $expires, $user['id']]);
                    }
                } catch (Exception $e) {
                    // Ignore error
                }
            }
            
            // à¸šà¸±à¸™à¸—à¸¶à¸ event à¹à¸¥à¸°à¸­à¸±à¸žà¹€à¸”à¸—à¸ªà¸–à¸´à¸•à¸´à¹à¸¢à¸à¸•à¸²à¸¡à¸šà¸­à¸—
            if ($lineAccountId) {
                saveAccountEvent($db, $lineAccountId, 'message', $userId, $user['id'], $event);
                updateAccountDailyStats($db, $lineAccountId, 'incoming_messages');
                updateAccountDailyStats($db, $lineAccountId, 'total_messages');
                updateFollowerInteraction($db, $lineAccountId, $userId);
            }
            
            // Send Telegram notification with media support à¸žà¸£à¹‰à¸­à¸¡à¸Šà¸·à¹ˆà¸­à¸šà¸­à¸—
            $accountName = getAccountName($db, $lineAccountId);
            $displayNameWithBot = $user['display_name'] . ($accountName ? " [{$accountName}]" : "");
            sendTelegramNotificationWithMedia($db, $line, $displayNameWithBot, $messageType, $messageContent, $messageId, $user['id'], $event['message']);

            // For non-text messages
            if ($messageType !== 'text') {
                return; // Don't process non-text further, just notify via Telegram
            }
            
            // ========== à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š Pending Order - à¸¥à¸¹à¸à¸„à¹‰à¸²à¸•à¸­à¸š "à¸¢à¸·à¸™à¸¢à¸±à¸™" ==========
            // Debug: log user state
            devLog($db, 'debug', 'webhook', 'Checking pending order state', [
                'user_id' => $user['id'],
                'has_state' => $userState ? 'yes' : 'no',
                'state' => $userState['state'] ?? 'none',
                'message' => mb_substr($messageText, 0, 30)
            ], $userId);
            
            if ($userState && $userState['state'] === 'pending_order') {
                $confirmKeywords = ['à¸¢à¸·à¸™à¸¢à¸±à¸™', 'à¸•à¸à¸¥à¸‡', 'ok', 'yes', 'confirm', 'à¸ªà¸±à¹ˆà¸‡à¹€à¸¥à¸¢', 'à¹€à¸­à¸²', 'à¹„à¸”à¹‰'];
                $cancelKeywords = ['à¸¢à¸à¹€à¸¥à¸´à¸', 'cancel', 'no', 'à¹„à¸¡à¹ˆà¹€à¸­à¸²', 'à¹„à¸¡à¹ˆ'];
                
                $textLowerTrim = mb_strtolower(trim($messageText));
                
                devLog($db, 'debug', 'webhook', 'Pending order - checking keywords', [
                    'user_id' => $user['id'],
                    'text_lower' => $textLowerTrim,
                    'is_confirm' => in_array($textLowerTrim, $confirmKeywords) ? 'yes' : 'no'
                ], $userId);
                
                if (in_array($textLowerTrim, $confirmKeywords)) {
                    // à¸ªà¸£à¹‰à¸²à¸‡ Order à¸ˆà¸²à¸ pending order
                    devLog($db, 'info', 'webhook', 'Creating order from pending state', [
                        'user_id' => $user['id']
                    ], $userId);
                    
                    $orderCreated = createOrderFromPendingState($db, $line, $user['id'], $userId, $userState, $replyToken, $lineAccountId);
                    if ($orderCreated) {
                        clearUserState($db, $user['id']);
                        return;
                    }
                } elseif (in_array($textLowerTrim, $cancelKeywords)) {
                    // à¸¢à¸à¹€à¸¥à¸´à¸ pending order
                    clearUserState($db, $user['id']);
                    $cancelMessage = [
                        'type' => 'text',
                        'text' => "âŒ à¸¢à¸à¹€à¸¥à¸´à¸à¸£à¸²à¸¢à¸à¸²à¸£à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­à¹à¸¥à¹‰à¸§à¸„à¹ˆà¸°\n\nà¸«à¸²à¸à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­à¹ƒà¸«à¸¡à¹ˆ à¸ªà¸²à¸¡à¸²à¸£à¸–à¹à¸ˆà¹‰à¸‡à¹„à¸”à¹‰à¹€à¸¥à¸¢à¸„à¹ˆà¸° ðŸ™"
                    ];
                    $line->replyMessage($replyToken, [$cancelMessage]);
                    saveOutgoingMessage($db, $user['id'], json_encode($cancelMessage), 'system', 'text');
                    return;
                }
            }
            
            // ========== à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š Consent PDPA ==========
            // à¸›à¸´à¸”à¸à¸²à¸£à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š consent - à¹ƒà¸«à¹‰à¸–à¸·à¸­à¸§à¹ˆà¸² consent à¹à¸¥à¹‰à¸§à¹€à¸ªà¸¡à¸­
            // à¸–à¹‰à¸²à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¹€à¸›à¸´à¸”à¹ƒà¸Šà¹‰à¸‡à¸²à¸™à¹ƒà¸«à¸¡à¹ˆ à¹ƒà¸«à¹‰ uncomment à¸šà¸£à¸£à¸—à¸±à¸”à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡
            // $hasConsent = checkUserConsent($db, $user['id'], $userId);
            $hasConsent = true; // à¸‚à¹‰à¸²à¸¡ consent check
            
            // à¸”à¸¶à¸‡à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ LIFF ID à¹à¸¥à¸° shop name
            $liffShopUrl = '';
            $liffConsentUrl = '';
            $shopName = 'LINE Shop';
            
            if ($lineAccountId) {
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µ column liff_consent_id à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
                $hasConsentCol = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'liff_consent_id'");
                    $hasConsentCol = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                if ($hasConsentCol) {
                    $stmt = $db->prepare("SELECT liff_id, liff_consent_id, name FROM line_accounts WHERE id = ?");
                } else {
                    $stmt = $db->prepare("SELECT liff_id, NULL as liff_consent_id, name FROM line_accounts WHERE id = ?");
                }
                $stmt->execute([$lineAccountId]);
                $accountInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($accountInfo) {
                    if (!empty($accountInfo['liff_id'])) {
                        $liffShopUrl = 'https://liff.line.me/' . $accountInfo['liff_id'];
                    }
                    // à¹ƒà¸Šà¹‰ liff_consent_id à¸–à¹‰à¸²à¸¡à¸µ à¸«à¸£à¸·à¸­à¹ƒà¸Šà¹‰ liff_id à¸›à¸à¸•à¸´
                    $consentLiffId = $accountInfo['liff_consent_id'] ?? $accountInfo['liff_id'] ?? '';
                    if ($consentLiffId) {
                        $liffConsentUrl = 'https://liff.line.me/' . $consentLiffId . '?page=consent';
                    }
                    
                    // à¸”à¸¶à¸‡ shop name
                    $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
                    $stmt->execute([$lineAccountId]);
                    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($shopSettings && !empty($shopSettings['shop_name'])) {
                        $shopName = $shopSettings['shop_name'];
                    } elseif (!empty($accountInfo['name'])) {
                        $shopName = $accountInfo['name'];
                    }
                }
            }
            
            // ========== à¸›à¸´à¸”à¸à¸²à¸£à¸ªà¹ˆà¸‡ Consent PDPA à¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´ ==========
            // à¸«à¸¡à¸²à¸¢à¹€à¸«à¸•à¸¸: à¸›à¸´à¸”à¸à¸²à¸£à¸ªà¹ˆà¸‡ liff-consent.php à¹€à¸¡à¸·à¹ˆà¸­à¹ƒà¸Šà¹‰à¸‡à¸²à¸™à¸„à¸£à¸±à¹‰à¸‡à¹à¸£à¸
            // à¸–à¹‰à¸²à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¹€à¸›à¸´à¸”à¹ƒà¸Šà¹‰à¸‡à¸²à¸™à¹ƒà¸«à¸¡à¹ˆ à¹ƒà¸«à¹‰ uncomment à¹‚à¸„à¹‰à¸”à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡
            /*
            if (!$hasConsent && $sourceType === 'user') {
                try {
                    $displayName = $user['display_name'] ?: 'à¸„à¸¸à¸“à¸¥à¸¹à¸à¸„à¹‰à¸²';
                    
                    // à¸ªà¸£à¹‰à¸²à¸‡ Flex Message à¸‚à¸­à¸„à¸§à¸²à¸¡à¸¢à¸´à¸™à¸¢à¸­à¸¡
                    $consentFlex = [
                        'type' => 'bubble',
                        'size' => 'kilo',
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#2563EB',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => 'ðŸ”’ à¸‚à¹‰à¸­à¸•à¸à¸¥à¸‡à¹à¸¥à¸°à¸„à¸§à¸²à¸¡à¸¢à¸´à¸™à¸¢à¸­à¸¡', 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center']
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => "à¸ªà¸§à¸±à¸ªà¸”à¸µà¸„à¹ˆà¸° à¸„à¸¸à¸“{$displayName} ðŸ‘‹", 'size' => 'md', 'weight' => 'bold'],
                                ['type' => 'text', 'text' => "à¸¢à¸´à¸™à¸”à¸µà¸•à¹‰à¸­à¸™à¸£à¸±à¸šà¸ªà¸¹à¹ˆ {$shopName}", 'size' => 'sm', 'color' => '#666666', 'margin' => 'sm'],
                                ['type' => 'separator', 'margin' => 'lg'],
                                ['type' => 'text', 'text' => 'à¸à¹ˆà¸­à¸™à¹€à¸£à¸´à¹ˆà¸¡à¹ƒà¸Šà¹‰à¸šà¸£à¸´à¸à¸²à¸£ à¸à¸£à¸¸à¸“à¸²à¸¢à¸­à¸¡à¸£à¸±à¸šà¸‚à¹‰à¸­à¸•à¸à¸¥à¸‡à¸à¸²à¸£à¹ƒà¸Šà¹‰à¸‡à¸²à¸™à¹à¸¥à¸°à¸™à¹‚à¸¢à¸šà¸²à¸¢à¸„à¸§à¸²à¸¡à¹€à¸›à¹‡à¸™à¸ªà¹ˆà¸§à¸™à¸•à¸±à¸§ (PDPA)', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'lg']
                            ]
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '15px',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'action' => [
                                        'type' => 'uri',
                                        'label' => 'ðŸ“‹ à¸­à¹ˆà¸²à¸™à¹à¸¥à¸°à¸¢à¸­à¸¡à¸£à¸±à¸šà¸‚à¹‰à¸­à¸•à¸à¸¥à¸‡',
                                        'uri' => $liffConsentUrl ?: (defined('BASE_URL') ? BASE_URL . 'liff-consent.php' : 'https://likesms.net/v1/liff-consent.php')
                                    ],
                                    'style' => 'primary',
                                    'color' => '#2563EB'
                                ]
                            ]
                        ]
                    ];
                    
                    $consentMessage = [
                        'type' => 'flex',
                        'altText' => 'ðŸ”’ à¸à¸£à¸¸à¸“à¸²à¸¢à¸­à¸¡à¸£à¸±à¸šà¸‚à¹‰à¸­à¸•à¸à¸¥à¸‡à¸à¹ˆà¸­à¸™à¹ƒà¸Šà¹‰à¸šà¸£à¸´à¸à¸²à¸£',
                        'contents' => $consentFlex
                    ];
                    
                    $line->replyMessage($replyToken, [$consentMessage]);
                    saveOutgoingMessage($db, $user['id'], 'consent_request');
                    
                    devLog($db, 'info', 'webhook', 'Sent consent request to user', [
                        'user_id' => $user['id'],
                        'display_name' => $displayName
                    ], $userId);
                    
                    return; // à¸ªà¹ˆà¸‡ Consent request à¹à¸¥à¹‰à¸§ à¹„à¸¡à¹ˆà¸•à¹‰à¸­à¸‡ process à¸•à¹ˆà¸­
                    
                } catch (Exception $e) {
                    devLog($db, 'error', 'webhook', 'Consent request error: ' . $e->getMessage(), null, $userId);
                }
            }
            */
            
            // ========== LIFF Menu à¸ªà¸³à¸«à¸£à¸±à¸šà¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹à¸£à¸ (à¸«à¸¥à¸±à¸‡à¸ˆà¸²à¸ consent à¹à¸¥à¹‰à¸§) ==========
            // à¸ªà¹ˆà¸‡ LIFF Menu à¹€à¸¡à¸·à¹ˆà¸­à¸¥à¸¹à¸à¸„à¹‰à¸²à¸—à¸±à¸à¸¡à¸²à¸„à¸£à¸±à¹‰à¸‡à¹à¸£à¸
            if ($isFirstMessage && $sourceType === 'user' && $hasConsent) {
                try {
                    // à¸–à¹‰à¸²à¸¡à¸µ LIFF URL à¹ƒà¸«à¹‰à¸ªà¹ˆà¸‡ LIFF Menu
                    if ($liffShopUrl) {
                        $displayName = $user['display_name'] ?: 'à¸„à¸¸à¸“à¸¥à¸¹à¸à¸„à¹‰à¸²';
                        $liffMenuBubble = FlexTemplates::firstMessageMenu($shopName, $liffShopUrl, $displayName);
                        $liffMenuMessage = FlexTemplates::toMessage($liffMenuBubble, "à¸¢à¸´à¸™à¸”à¸µà¸•à¹‰à¸­à¸™à¸£à¸±à¸šà¸ªà¸¹à¹ˆ {$shopName}");
                        
                        // à¹€à¸žà¸´à¹ˆà¸¡ Quick Reply
                        $liffMenuMessage = FlexTemplates::withQuickReply($liffMenuMessage, [
                            ['label' => 'ðŸ›’ à¸”à¸¹à¸ªà¸´à¸™à¸„à¹‰à¸²', 'text' => 'shop'],
                            ['label' => 'ðŸ“‹ à¹€à¸¡à¸™à¸¹', 'text' => 'menu'],
                            ['label' => 'ðŸ’¬ à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸£à¸²', 'text' => 'contact']
                        ]);
                        
                        $line->replyMessage($replyToken, [$liffMenuMessage]);
                        saveOutgoingMessage($db, $user['id'], 'liff_menu');
                        
                        devLog($db, 'info', 'webhook', 'Sent LIFF Menu to new user', [
                            'user_id' => $user['id'],
                            'display_name' => $displayName,
                            'liff_url' => $liffShopUrl
                        ], $userId);
                        
                        return; // à¸ªà¹ˆà¸‡ LIFF Menu à¹à¸¥à¹‰à¸§ à¹„à¸¡à¹ˆà¸•à¹‰à¸­à¸‡ process à¸•à¹ˆà¸­
                    }
                } catch (Exception $e) {
                    devLog($db, 'error', 'webhook', 'LIFF Menu error: ' . $e->getMessage(), null, $userId);
                }
            }

            // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š bot_mode à¸à¹ˆà¸­à¸™ - à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ general à¹„à¸¡à¹ˆà¸•à¸­à¸šà¸à¸¥à¸±à¸šà¸­à¸°à¹„à¸£à¹€à¸¥à¸¢
            $botMode = 'shop'; // default
            $liffId = '';
            try {
                if ($lineAccountId) {
                    $stmt = $db->prepare("SELECT bot_mode, liff_id FROM line_accounts WHERE id = ?");
                    $stmt->execute([$lineAccountId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result) {
                        if (!empty($result['bot_mode'])) {
                            $botMode = $result['bot_mode'];
                        }
                        $liffId = $result['liff_id'] ?? '';
                    }
                }
            } catch (Exception $e) {}
            
            // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™à¹‚à¸«à¸¡à¸” general - à¹€à¸Šà¹‡à¸„ Auto Reply à¸à¹ˆà¸­à¸™ à¸–à¹‰à¸²à¹„à¸¡à¹ˆ match à¸„à¹ˆà¸­à¸¢à¹„à¸¡à¹ˆà¸•à¸­à¸š
            if ($botMode === 'general') {
                // Debug: log before checking auto reply
                devLog($db, 'debug', 'webhook', 'General mode - checking auto reply', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'line_account_id' => $lineAccountId
                ], $userId);
                
                // Check auto-reply rules first - à¸–à¹‰à¸²à¸¡à¸µ rule à¸—à¸µà¹ˆ match à¹ƒà¸«à¹‰à¸•à¸­à¸š
                $autoReply = checkAutoReply($db, $messageText, $lineAccountId);
                
                // Debug: log result
                devLog($db, 'debug', 'webhook', 'General mode - auto reply result', [
                    'user_id' => $userId,
                    'has_reply' => $autoReply ? true : false,
                    'reply_type' => $autoReply ? ($autoReply['type'] ?? 'unknown') : null
                ], $userId);
                
                if ($autoReply) {
                    devLog($db, 'info', 'webhook', 'General mode - auto reply matched, sending reply', [
                        'user_id' => $userId,
                        'message' => mb_substr($messageText, 0, 100),
                        'bot_mode' => $botMode
                    ], $userId);
                    $line->replyMessage($replyToken, [$autoReply]);
                    saveOutgoingMessage($db, $user['id'], json_encode($autoReply));
                    return;
                }
                
                // à¹„à¸¡à¹ˆà¸¡à¸µ auto reply match - à¹„à¸¡à¹ˆà¸•à¸­à¸šà¸à¸¥à¸±à¸š à¹à¸„à¹ˆà¸šà¸±à¸™à¸—à¸¶à¸à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ (à¸£à¸­à¹à¸­à¸”à¸¡à¸´à¸™à¸•à¸­à¸š)
                devLog($db, 'info', 'webhook', 'General mode - no auto reply match, waiting for admin', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'bot_mode' => $botMode
                ], $userId);
                return; // à¹„à¸¡à¹ˆà¸•à¸­à¸šà¸à¸¥à¸±à¸š - à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¸–à¸¹à¸à¸šà¸±à¸™à¸—à¸¶à¸à¹„à¸§à¹‰à¹à¸¥à¹‰à¸§à¸”à¹‰à¸²à¸™à¸šà¸™
            }
            
            // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸„à¸³à¸ªà¸±à¹ˆà¸‡à¹à¸¥à¸°à¸à¸²à¸£à¹€à¸£à¸µà¸¢à¸ AI
            $textLower = mb_strtolower(trim($messageText));
            $textTrimmed = trim($messageText);
            
            // ===== LIFF Message Handler - Process LIFF-triggered messages =====
            // Requirements: 20.3, 20.9, 20.12
            if (class_exists('LiffMessageHandler')) {
                $liffHandler = new LiffMessageHandler($db, $line, $lineAccountId);
                $liffAction = $liffHandler->detectLiffAction($messageText);
                
                // Log all incoming messages for debugging
                devLog($db, 'debug', 'webhook', 'Checking for LIFF action', [
                    'message' => mb_substr($messageText, 0, 100),
                    'detected_action' => $liffAction,
                    'user_id' => $userId
                ], $userId);
                
                if ($liffAction) {
                    devLog($db, 'info', 'webhook', 'LIFF action detected', [
                        'action' => $liffAction,
                        'user_id' => $userId,
                        'message' => mb_substr($messageText, 0, 100)
                    ], $userId);
                    
                    $liffReply = $liffHandler->processMessage($messageText, $user['id'], $userId);
                    
                    if ($liffReply) {
                        devLog($db, 'info', 'webhook', 'Sending LIFF reply', [
                            'action' => $liffAction,
                            'reply_type' => $liffReply['type'] ?? 'unknown'
                        ], $userId);
                        
                        $line->replyMessage($replyToken, [$liffReply]);
                        saveOutgoingMessage($db, $user['id'], json_encode($liffReply), 'liff', 'flex');
                        return; // LIFF message handled
                    }
                }
            }
            
            // ===== V3.2: AI à¸•à¸­à¸šà¸—à¸¸à¸à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´ (à¸¢à¸à¹€à¸§à¹‰à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸žà¸´à¹€à¸¨à¸©) =====
            // à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸µà¹ˆà¹„à¸¡à¹ˆà¹ƒà¸«à¹‰ AI à¸•à¸­à¸š (à¹ƒà¸«à¹‰à¸£à¸°à¸šà¸šà¸­à¸·à¹ˆà¸™à¸ˆà¸±à¸”à¸à¸²à¸£)
            $systemCommands = ['à¸£à¹‰à¸²à¸™à¸„à¹‰à¸²', 'shop', 'à¸£à¹‰à¸²à¸™', 'à¸ªà¸´à¸™à¸„à¹‰à¸²', 'à¸‹à¸·à¹‰à¸­', 'à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­', 
                            'à¸ªà¸¥à¸´à¸›', 'slip', 'à¹à¸™à¸šà¸ªà¸¥à¸´à¸›', 'à¸ªà¹ˆà¸‡à¸ªà¸¥à¸´à¸›', 'à¹‚à¸­à¸™à¹€à¸‡à¸´à¸™', 'à¹‚à¸­à¸™à¹à¸¥à¹‰à¸§',
                            'à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ', 'order', 'à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­', 'à¸•à¸´à¸”à¸•à¸²à¸¡', 'tracking',
                            'à¹€à¸¡à¸™à¸¹', 'menu', 'help', 'à¸Šà¹ˆà¸§à¸¢à¹€à¸«à¸¥à¸·à¸­', '?',
                            'quickmenu', 'à¹€à¸¡à¸™à¸¹à¸”à¹ˆà¸§à¸™', 'allmenu', 'à¹€à¸¡à¸™à¸¹à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸”',
                            'contact', 'à¸•à¸´à¸”à¸•à¹ˆà¸­', 'à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸£à¸²',
                            'à¸ªà¸¡à¸±à¸„à¸£à¸šà¸±à¸•à¸£', 'à¸šà¸±à¸•à¸£à¸ªà¸¡à¸²à¸Šà¸´à¸', 'member', 'points', 'à¹à¸•à¹‰à¸¡'];
            $isSystemCommand = in_array($textLower, $systemCommands);
            
            // à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸µà¹ˆà¸ˆà¸°à¸«à¸¢à¸¸à¸” AI à¹à¸¥à¸°à¸ªà¹ˆà¸‡à¸•à¹ˆà¸­à¹€à¸ à¸ªà¸±à¸Šà¸à¸£/à¹à¸­à¸”à¸¡à¸´à¸™


            $stopAICommands = ['à¸›à¸£à¸¶à¸à¸©à¸²à¹€à¸ à¸ªà¸±à¸Šà¸à¸£', 'à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£', 'à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¸„à¸™', 'à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹à¸­à¸”à¸¡à¸´à¸™', 'à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸ à¸ªà¸±à¸Šà¸à¸£', 'à¸•à¸´à¸”à¸•à¹ˆà¸­à¹à¸­à¸”à¸¡à¸´à¸™', 'à¸«à¸¢à¸¸à¸”à¸šà¸­à¸—', 'stop bot', 'human'];
            $isStopAICommand = in_array($textLower, $stopAICommands);
            
            // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¹€à¸£à¸µà¸¢à¸ AI à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ (@à¸šà¸­à¸—, @bot, @ai à¸«à¸£à¸·à¸­ /xxx)
            $isAICall = preg_match('/^@(à¸šà¸­à¸—|bot|ai)\s*/iu', $textTrimmed, $aiMatch);
            $aiMessage = $isAICall ? trim(preg_replace('/^@(à¸šà¸­à¸—|bot|ai)\s*/iu', '', $textTrimmed)) : '';
            
            // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¹€à¸›à¹‡à¸™ / command à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ (à¹€à¸£à¸µà¸¢à¸ AI à¹‚à¸”à¸¢à¸•à¸£à¸‡)
            $isSlashCommand = preg_match('/^\/[\w\p{Thai}]+/u', $textTrimmed);
            
            // à¸–à¹‰à¸²à¸žà¸´à¸¡à¸žà¹Œà¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£ - à¸«à¸¢à¸¸à¸” AI
            if ($isStopAICommand) {
                // à¹ƒà¸Šà¹‰ sender à¸ˆà¸²à¸ ai_settings
                $stopSender = getAISenderSettings($db, $lineAccountId, 'pharmacist');
                
                $stopMessage = [
                    'type' => 'text',
                    'text' => "ðŸ“ž à¸£à¸±à¸šà¸—à¸£à¸²à¸šà¸„à¹ˆà¸° à¸à¸³à¸¥à¸±à¸‡à¸ªà¹ˆà¸‡à¸•à¹ˆà¸­à¹ƒà¸«à¹‰à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸”à¸¹à¹à¸¥à¸„à¹ˆà¸°\n\nà¸à¸£à¸¸à¸“à¸²à¸£à¸­à¸ªà¸±à¸à¸„à¸£à¸¹à¹ˆ à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸°à¸•à¸´à¸”à¸•à¹ˆà¸­à¸à¸¥à¸±à¸šà¹‚à¸”à¸¢à¹€à¸£à¹‡à¸§à¸—à¸µà¹ˆà¸ªà¸¸à¸”à¸„à¹ˆà¸° ðŸ™",
                    'sender' => $stopSender
                ];
                $line->replyMessage($replyToken, [$stopMessage]);
                saveOutgoingMessage($db, $user['id'], json_encode($stopMessage), 'system', 'text');
                devLog($db, 'info', 'webhook', 'User requested human pharmacist', ['user_id' => $userId], $userId);
                return;
            }
            
            // ===== / command - à¸ªà¹ˆà¸‡à¹„à¸›à¹ƒà¸«à¹‰ AI à¸•à¸­à¸šà¹‚à¸”à¸¢à¸•à¸£à¸‡ =====
            if ($isSlashCommand && isset($user['id'])) {
                devLog($db, 'info', 'webhook', 'Slash command detected', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 30)
                ], $userId);
                
                $aiReply = checkAIChatbot($db, $messageText, $lineAccountId, $user['id']);
                if ($aiReply) {
                    $replyResult = $line->replyMessage($replyToken, $aiReply);
                    $replyCode = $replyResult['code'] ?? 0;
                    
                    devLog($db, 'debug', 'webhook', 'Slash command reply result', [
                        'code' => $replyCode,
                        'message' => mb_substr($messageText, 0, 30)
                    ], $userId);
                    
                    saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'flex');
                    return;
                }
            }
            
            // ===== AI à¸•à¸­à¸šà¹€à¸‰à¸žà¸²à¸°à¹€à¸¡à¸·à¹ˆà¸­à¹ƒà¸Šà¹‰ / à¸«à¸£à¸·à¸­ @ command =====
            // ===== AI SIMPLE MODE: DISABLED - à¹ƒà¸«à¹‰à¹à¸­à¸”à¸¡à¸´à¸™à¸•à¸­à¸šà¹€à¸­à¸‡ =====
            // à¸›à¸´à¸”à¸à¸²à¸£à¸•à¸­à¸šà¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´à¸‚à¸­à¸‡ AI à¸œà¹ˆà¸²à¸™ webhook à¹à¸¥à¹‰à¸§
            // à¹ƒà¸Šà¹‰ Ghost Draft à¹ƒà¸™ Inbox V2 à¹à¸—à¸™
            /*
            if (isset($user['id'])) {
                try {
                    require_once __DIR__ . '/classes/GeminiChat.php';
                    $gemini = new GeminiChat($db, $lineAccountId);
                    
                    devLog($db, 'debug', 'webhook', 'AI Simple Mode check', [
                        'is_enabled' => $gemini->isEnabled() ? 'yes' : 'no',
                        'message' => mb_substr($messageText, 0, 30)
                    ], $userId);
                    
                    if ($gemini->isEnabled()) {
                        $currentReplyToken = $event['replyToken'] ?? $replyToken ?? null;
                        
                        devLog($db, 'debug', 'webhook', 'Calling Gemini API...', [
                            'has_token' => $currentReplyToken ? 'yes' : 'no'
                        ], $userId);
                        
                        // à¹€à¸£à¸µà¸¢à¸ Gemini à¸•à¸­à¸šà¹€à¸¥à¸¢
                        set_time_limit(60);
                        $startTime = microtime(true);
                        $response = $gemini->generateResponse($messageText, $user['id'], []);
                        $elapsed = round((microtime(true) - $startTime) * 1000);
                        
                        devLog($db, 'debug', 'webhook', 'Gemini response received', [
                            'elapsed_ms' => $elapsed,
                            'has_response' => $response ? 'yes' : 'no',
                            'response_length' => $response ? mb_strlen($response) : 0
                        ], $userId);
                        
                        if ($response) {
                            $aiReply = [[
                                'type' => 'text',
                                'text' => $response
                            ]];
                            
                            // à¸ªà¹ˆà¸‡à¸à¸¥à¸±à¸šà¸”à¹‰à¸§à¸¢ replyMessage
                            if ($currentReplyToken) {
                                $replyResult = $line->replyMessage($currentReplyToken, $aiReply);
                                devLog($db, 'debug', 'webhook', 'AI reply sent', [
                                    'code' => $replyResult['code'] ?? 0,
                                    'body' => json_encode($replyResult['body'] ?? null),
                                    'message' => mb_substr($messageText, 0, 30)
                                ], $userId);
                            } else {
                                devLog($db, 'error', 'webhook', 'No replyToken for AI response', [], $userId);
                            }
                            
                            saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'text');
                            return;
                        }
                    }
                } catch (Exception $e) {
                    devLog($db, 'error', 'webhook', 'AI error: ' . $e->getMessage(), [], $userId);
                }
            }
            */
            
            // ===== à¸–à¹‰à¸² AI à¹„à¸¡à¹ˆà¸•à¸­à¸š à¹ƒà¸«à¹‰à¸—à¸³à¸‡à¸²à¸™à¸•à¸²à¸¡à¸›à¸à¸•à¸´ =====
            
            // à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸µà¹ˆà¸šà¸­à¸—à¸ˆà¸°à¸•à¸­à¸š (à¹€à¸‰à¸žà¸²à¸°à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¹€à¸ˆà¸²à¸°à¸ˆà¸‡)
            $shopCommands = ['à¸£à¹‰à¸²à¸™à¸„à¹‰à¸²', 'shop', 'à¸£à¹‰à¸²à¸™', 'à¸ªà¸´à¸™à¸„à¹‰à¸²', 'à¸‹à¸·à¹‰à¸­', 'à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­'];
            $slipCommands = ['à¸ªà¸¥à¸´à¸›', 'slip', 'à¹à¸™à¸šà¸ªà¸¥à¸´à¸›', 'à¸ªà¹ˆà¸‡à¸ªà¸¥à¸´à¸›', 'à¹‚à¸­à¸™à¹€à¸‡à¸´à¸™', 'à¹‚à¸­à¸™à¹à¸¥à¹‰à¸§'];
            $orderCommands = ['à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ', 'order', 'à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­', 'à¸•à¸´à¸”à¸•à¸²à¸¡', 'tracking'];
            $menuCommands = ['à¹€à¸¡à¸™à¸¹', 'menu', 'help', 'à¸Šà¹ˆà¸§à¸¢à¹€à¸«à¸¥à¸·à¸­'];
            
            $isShopCommand = in_array($textLower, $shopCommands);
            $isSlipCommand = in_array($textLower, $slipCommands);
            $isOrderCommand = in_array($textLower, $orderCommands);
            $isMenuCommand = in_array($textLower, $menuCommands);
            
            // ===== Handle LIFF Action Messages (à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­à¸ªà¸³à¹€à¸£à¹‡à¸ˆ, à¸™à¸±à¸”à¸«à¸¡à¸²à¸¢à¸ªà¸³à¹€à¸£à¹‡à¸ˆ, etc.) =====
            if (preg_match('/^à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­à¸ªà¸³à¹€à¸£à¹‡à¸ˆ\s*#?(\w+)/u', $messageText, $matches)) {
                $orderNumber = $matches[1];
                devLog($db, 'info', 'webhook', 'Order confirmation message received', [
                    'user_id' => $userId,
                    'order_number' => $orderNumber
                ], $userId);
                
                // Get order details
                $stmt = $db->prepare("
                    SELECT t.*, 
                           (SELECT SUM(quantity) FROM transaction_items WHERE transaction_id = t.id) as item_count
                    FROM transactions t 
                    WHERE t.order_number = ? AND t.user_id = ?
                ");
                $stmt->execute([$orderNumber, $user['id']]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($order) {
                    // Get order items
                    $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
                    $stmt->execute([$order['id']]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Build Flex Message for order confirmation
                    $itemContents = [];
                    foreach ($items as $item) {
                        $itemContents[] = [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => $item['product_name'], 'size' => 'sm', 'color' => '#555555', 'flex' => 4, 'wrap' => true],
                                ['type' => 'text', 'text' => 'x' . $item['quantity'], 'size' => 'sm', 'color' => '#111111', 'flex' => 1, 'align' => 'end'],
                                ['type' => 'text', 'text' => 'à¸¿' . number_format($item['subtotal'], 0), 'size' => 'sm', 'color' => '#111111', 'flex' => 2, 'align' => 'end']
                            ]
                        ];
                    }
                    
                    $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
                    
                    $orderFlex = [
                        'type' => 'bubble',
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#06C755',
                            'paddingAll' => 'lg',
                            'contents' => [
                                ['type' => 'text', 'text' => 'à¸¢à¸·à¸™à¸¢à¸±à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg']
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => array_merge(
                                [
                                    ['type' => 'text', 'text' => '#' . $order['order_number'], 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                                    ['type' => 'separator', 'margin' => 'lg'],
                                    ['type' => 'text', 'text' => 'à¸£à¸²à¸¢à¸à¸²à¸£à¸ªà¸´à¸™à¸„à¹‰à¸²', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg']
                                ],
                                $itemContents,
                                [
                                    ['type' => 'separator', 'margin' => 'lg'],
                                    [
                                        'type' => 'box',
                                        'layout' => 'horizontal',
                                        'margin' => 'lg',
                                        'contents' => [
                                            ['type' => 'text', 'text' => 'à¸„à¹ˆà¸²à¸ˆà¸±à¸”à¸ªà¹ˆà¸‡', 'size' => 'sm', 'color' => '#555555'],
                                            ['type' => 'text', 'text' => 'à¸¿' . number_format($order['shipping_fee'] ?? 0, 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
                                        ]
                                    ],
                                    [
                                        'type' => 'box',
                                        'layout' => 'horizontal',
                                        'margin' => 'md',
                                        'contents' => [
                                            ['type' => 'text', 'text' => 'à¸£à¸§à¸¡à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸”', 'size' => 'md', 'weight' => 'bold'],
                                            ['type' => 'text', 'text' => 'à¸¿' . number_format($order['grand_total'], 0), 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755', 'align' => 'end']
                                        ]
                                    ]
                                ]
                            )
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                ['type' => 'text', 'text' => 'à¸à¸£à¸¸à¸“à¸²à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™à¹à¸¥à¸°à¹à¸™à¸šà¸ªà¸¥à¸´à¸›', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                ['type' => 'text', 'text' => 'à¸žà¸´à¸¡à¸žà¹Œ "à¸ªà¸¥à¸´à¸›" à¹€à¸žà¸·à¹ˆà¸­à¹à¸™à¸šà¸«à¸¥à¸±à¸à¸à¸²à¸™', 'size' => 'xs', 'color' => '#888888', 'align' => 'center', 'margin' => 'sm']
                            ]
                        ]
                    ];
                    
                    $message = [
                        'type' => 'flex',
                        'altText' => 'à¸¢à¸·à¸™à¸¢à¸±à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­ #' . $order['order_number'],
                        'contents' => $orderFlex
                    ];
                    $line->replyMessage($replyToken, [$message]);
                    saveOutgoingMessage($db, $user['id'], 'order_confirmation_flex', 'system', 'flex');
                } else {
                    $line->replyMessage($replyToken, [['type' => 'text', 'text' => 'à¹„à¸¡à¹ˆà¸žà¸šà¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­ #' . $orderNumber]]);
                }
                return;
            }
            
            // à¸–à¹‰à¸²à¹€à¸£à¸µà¸¢à¸ AI (@à¸šà¸­à¸— xxx) - à¸ªà¹ˆà¸‡à¹„à¸›à¹ƒà¸«à¹‰ AI à¸•à¸­à¸š (fallback)
            if ($isAICall && !empty($aiMessage)) {
                devLog($db, 'info', 'webhook', 'AI called with @bot', [
                    'user_id' => $userId,
                    'message' => $aiMessage
                ], $userId);
                
                $aiReply = checkAIChatbot($db, $aiMessage, $lineAccountId, $user['id'] ?? null);
                if ($aiReply) {
                    // à¸¥à¸­à¸‡ replyMessage à¸à¹ˆà¸­à¸™ (à¸Ÿà¸£à¸µ!)
                    $replyResult = $line->replyMessage($replyToken, $aiReply);
                    $replyCode = $replyResult['code'] ?? 0;
                    
                    // à¸–à¹‰à¸² reply à¹„à¸¡à¹ˆà¸ªà¸³à¹€à¸£à¹‡à¸ˆ à¹ƒà¸«à¹‰à¹ƒà¸Šà¹‰ pushMessage à¹à¸—à¸™
                    if ($replyCode !== 200) {
                        $line->pushMessage($userId, $aiReply);
                    }
                    
                    saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'flex');
                    return;
                } else {
                    // AI à¹„à¸¡à¹ˆà¹„à¸”à¹‰à¹€à¸›à¸´à¸”à¹ƒà¸Šà¹‰à¸‡à¸²à¸™
                    $line->replyMessage($replyToken, [['type' => 'text', 'text' => 'âŒ à¸£à¸°à¸šà¸š AI à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¹„à¸”à¹‰à¹€à¸›à¸´à¸”à¹ƒà¸Šà¹‰à¸‡à¸²à¸™ à¸à¸£à¸¸à¸“à¸²à¸•à¸´à¸”à¸•à¹ˆà¸­à¹à¸­à¸”à¸¡à¸´à¸™']]);
                    return;
                }
            }
            
            // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸£à¹‰à¸²à¸™à¸„à¹‰à¸² - à¸ªà¹ˆà¸‡ LIFF URL
            if ($isShopCommand && $liffId) {
                $liffUrl = "https://liff.line.me/{$liffId}";
                $shopFlex = [
                    'type' => 'bubble',
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ðŸ›ï¸ à¸£à¹‰à¸²à¸™à¸„à¹‰à¸²à¸­à¸­à¸™à¹„à¸¥à¸™à¹Œ', 'weight' => 'bold', 'size' => 'lg'],
                            ['type' => 'text', 'text' => 'à¸à¸”à¸›à¸¸à¹ˆà¸¡à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡à¹€à¸žà¸·à¹ˆà¸­à¸”à¸¹à¸ªà¸´à¸™à¸„à¹‰à¸²à¹à¸¥à¸°à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­', 'size' => 'sm', 'color' => '#666666', 'margin' => 'md', 'wrap' => true]
                        ]
                    ],
                    'footer' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'button',
                                'style' => 'primary',
                                'color' => '#06C755',
                                'action' => ['type' => 'uri', 'label' => 'ðŸ›’ à¹€à¸‚à¹‰à¸²à¸ªà¸¹à¹ˆà¸£à¹‰à¸²à¸™à¸„à¹‰à¸²', 'uri' => $liffUrl]
                            ]
                        ]
                    ]
                ];
                
                $message = [
                    'type' => 'flex',
                    'altText' => 'à¸à¸”à¹€à¸žà¸·à¹ˆà¸­à¹€à¸‚à¹‰à¸²à¸ªà¸¹à¹ˆà¸£à¹‰à¸²à¸™à¸„à¹‰à¸²',
                    'contents' => $shopFlex
                ];
                $line->replyMessage($replyToken, [$message]);
                saveOutgoingMessage($db, $user['id'], 'liff_redirect');
                return;
            }
            
            // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸ªà¸¥à¸´à¸›/à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ - à¹ƒà¸«à¹‰ BusinessBot à¸ˆà¸±à¸”à¸à¸²à¸£ (à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡)
            // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¹€à¸¡à¸™à¸¹ - à¹ƒà¸«à¹‰ Auto Reply à¸«à¸£à¸·à¸­ BusinessBot à¸ˆà¸±à¸”à¸à¸²à¸£ (à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡)
            
            // à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¹ƒà¸Šà¹ˆà¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸µà¹ˆà¸à¸³à¸«à¸™à¸” à¹à¸¥à¸°à¹„à¸¡à¹ˆà¹ƒà¸Šà¹ˆà¹‚à¸«à¸¡à¸” general - à¹„à¸¡à¹ˆà¸•à¸­à¸š (à¸£à¸­à¹à¸­à¸”à¸¡à¸´à¸™)
            if (!$isSlipCommand && !$isOrderCommand && !$isMenuCommand && $botMode !== 'general') {
                // à¹€à¸Šà¹‡à¸„ Auto Reply à¸à¹ˆà¸­à¸™
                $autoReply = checkAutoReply($db, $messageText, $lineAccountId);
                if ($autoReply) {
                    devLog($db, 'info', 'webhook', 'Auto reply matched (non-general mode)', [
                        'user_id' => $userId,
                        'message' => mb_substr($messageText, 0, 100),
                        'bot_mode' => $botMode
                    ], $userId);
                    $line->replyMessage($replyToken, [$autoReply]);
                    saveOutgoingMessage($db, $user['id'], json_encode($autoReply));
                    return;
                }
                
                // à¹„à¸¡à¹ˆà¸•à¸­à¸š - à¸£à¸­à¹à¸­à¸”à¸¡à¸´à¸™
                devLog($db, 'info', 'webhook', 'No matching command - waiting for admin', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'bot_mode' => $botMode
                ], $userId);
                return;
            }

            // Check for slip command: "à¸ªà¸¥à¸´à¸›", "slip", "à¹à¸™à¸šà¸ªà¸¥à¸´à¸›", "à¸ªà¹ˆà¸‡à¸ªà¸¥à¸´à¸›"
            if (in_array($textLower, ['à¸ªà¸¥à¸´à¸›', 'slip', 'à¹à¸™à¸šà¸ªà¸¥à¸´à¸›', 'à¸ªà¹ˆà¸‡à¸ªà¸¥à¸´à¸›', 'à¹‚à¸­à¸™à¹€à¸‡à¸´à¸™', 'à¹‚à¸­à¸™à¹à¸¥à¹‰à¸§'])) {
                devLog($db, 'debug', 'webhook', 'Slip command detected', ['user_id' => $user['id'], 'text' => $textLower], $userId);
                $handled = handleSlipCommand($db, $line, $user['id'], $replyToken);
                devLog($db, 'debug', 'webhook', 'Slip command result: ' . ($handled ? 'handled' : 'not handled'), ['user_id' => $user['id']], $userId);
                if ($handled) return;
            }
            
            // Check for menu command - à¹à¸ªà¸”à¸‡à¹€à¸¡à¸™à¸¹à¸«à¸¥à¸±à¸à¸ªà¸§à¸¢à¹† (à¸­à¸±à¸žà¹€à¸à¸£à¸” V2)
            if (in_array($textLower, ['menu', 'à¹€à¸¡à¸™à¸¹', 'help', 'à¸Šà¹ˆà¸§à¸¢à¹€à¸«à¸¥à¸·à¸­', '?'])) {
                $shopName = 'LINE Shop';
                try {
                    if ($lineAccountId) {
                        $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
                        $stmt->execute([$lineAccountId]);
                        $shopSettings = $stmt->fetch();
                    }
                    if (empty($shopSettings)) {
                        $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
                        $shopSettings = $stmt->fetch();
                    }
                    if ($shopSettings && $shopSettings['shop_name']) $shopName = $shopSettings['shop_name'];
                } catch (Exception $e) {}
                
                $menuBubble = FlexTemplates::mainMenu($shopName);
                $menuMessage = FlexTemplates::toMessage($menuBubble, "à¹€à¸¡à¸™à¸¹ {$shopName}");
                $line->replyMessage($replyToken, [$menuMessage]);
                saveOutgoingMessage($db, $user['id'], 'menu');
                return;
            }
            
            // Check for quick menu command - à¹€à¸¡à¸™à¸¹à¸”à¹ˆà¸§à¸™à¹à¸šà¸š Carousel
            if (in_array($textLower, ['quickmenu', 'à¹€à¸¡à¸™à¸¹à¸”à¹ˆà¸§à¸™', 'allmenu', 'à¹€à¸¡à¸™à¸¹à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸”'])) {
                $shopName = 'LINE Shop';
                try {
                    if ($lineAccountId) {
                        $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
                        $stmt->execute([$lineAccountId]);
                        $shopSettings = $stmt->fetch();
                    }
                    if (empty($shopSettings)) {
                        $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
                        $shopSettings = $stmt->fetch();
                    }
                    if ($shopSettings && $shopSettings['shop_name']) $shopName = $shopSettings['shop_name'];
                } catch (Exception $e) {}
                
                $menuCarousel = FlexTemplates::quickMenu($shopName);
                $menuMessage = FlexTemplates::toMessage($menuCarousel, "à¹€à¸¡à¸™à¸¹à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸” {$shopName}");
                $line->replyMessage($replyToken, [$menuMessage]);
                saveOutgoingMessage($db, $user['id'], 'quickmenu');
                return;
            }
            
            // Check for contact command
            if (in_array($textLower, ['contact', 'à¸•à¸´à¸”à¸•à¹ˆà¸­', 'à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸£à¸²'])) {
                $contactBubble = FlexTemplates::notification(
                    'à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸£à¸²',
                    'à¸ªà¸²à¸¡à¸²à¸£à¸–à¸žà¸´à¸¡à¸žà¹Œà¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸–à¸¶à¸‡à¹€à¸£à¸²à¹„à¸”à¹‰à¹€à¸¥à¸¢\nà¸—à¸µà¸¡à¸‡à¸²à¸™à¸ˆà¸°à¸•à¸­à¸šà¸à¸¥à¸±à¸šà¹‚à¸”à¸¢à¹€à¸£à¹‡à¸§à¸—à¸µà¹ˆà¸ªà¸¸à¸”',
                    'ðŸ“ž',
                    '#3B82F6',
                    [['label' => 'ðŸ›’ à¸”à¸¹à¸ªà¸´à¸™à¸„à¹‰à¸²', 'text' => 'shop', 'style' => 'secondary']]
                );
                $contactMessage = FlexTemplates::toMessage($contactBubble, 'à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸£à¸²');
                // à¹€à¸žà¸´à¹ˆà¸¡ Quick Reply
                $contactMessage = FlexTemplates::withQuickReply($contactMessage, [
                    ['label' => 'ðŸ›’ à¸”à¸¹à¸ªà¸´à¸™à¸„à¹‰à¸²', 'text' => 'shop'],
                    ['label' => 'ðŸ“‹ à¹€à¸¡à¸™à¸¹', 'text' => 'menu'],
                    ['label' => 'ðŸ“¦ à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ', 'text' => 'orders']
                ]);
                $line->replyMessage($replyToken, [$contactMessage]);
                saveOutgoingMessage($db, $user['id'], 'contact');
                return;
            }
            
            // Points/loyalty command - handled by BusinessBot.showPoints()

            // à¹€à¸Šà¹‡à¸„ Auto Reply à¸à¹ˆà¸­à¸™ BusinessBot (à¸ªà¸³à¸«à¸£à¸±à¸šà¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸—à¸±à¹ˆà¸§à¹„à¸›)
            // à¸¢à¸à¹€à¸§à¹‰à¸™à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸žà¸´à¹€à¸¨à¸©à¸—à¸µà¹ˆ BusinessBot à¸•à¹‰à¸­à¸‡à¸ˆà¸±à¸”à¸à¸²à¸£
            $specialCommands = ['shop', 'menu', 'orders', 'à¸ªà¸´à¸™à¸„à¹‰à¸²', 'à¹€à¸¡à¸™à¸¹', 'à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ', 'points', 'à¹à¸•à¹‰à¸¡'];
            if (!in_array($textLower, $specialCommands) && !$isSlipCommand && !$isOrderCommand) {
                $autoReply = checkAutoReply($db, $messageText, $lineAccountId);
                if ($autoReply) {
                    devLog($db, 'info', 'webhook', 'Auto reply matched (before BusinessBot)', [
                        'user_id' => $userId,
                        'message' => mb_substr($messageText, 0, 100)
                    ], $userId);
                    $line->replyMessage($replyToken, [$autoReply]);
                    saveOutgoingMessage($db, $user['id'], json_encode($autoReply));
                    return;
                }
            }

            // V2.5: Check Business commands (à¹ƒà¸Šà¹‰ BusinessBot à¹€à¸—à¹ˆà¸²à¸™à¸±à¹‰à¸™)
            $botMode = 'shop'; // default
            $businessBot = null;
            
            try {
                if (class_exists('BusinessBot')) {
                    devLog($db, 'debug', 'BusinessBot', 'Processing message', [
                        'user_id' => $userId,
                        'message' => mb_substr($messageText, 0, 50)
                    ], $userId);
                    
                    $businessBot = new BusinessBot($db, $line, $lineAccountId);
                    $botMode = $businessBot->getBotMode();
                    $handled = $businessBot->processMessage($userId, $user['id'], $messageText, $replyToken);
                    
                    devLog($db, 'debug', 'BusinessBot', 'Result: ' . ($handled ? 'handled' : 'not handled'), [
                        'user_id' => $userId,
                        'command' => mb_substr($messageText, 0, 50),
                        'handled' => $handled ? true : false,
                        'bot_mode' => $botMode
                    ], $userId);
                    
                    if ($handled) {
                        return; // Business command handled
                    }
                }
            } catch (Exception $e) {
                devLog($db, 'error', 'BusinessBot', $e->getMessage(), [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ], $userId);
                error_log("BusinessBot error: " . $e->getMessage());
            }

            // Check auto-reply rules (à¸£à¸­à¸‡à¸£à¸±à¸š Sender, Quick Reply, Alt Text) - à¹à¸¢à¸à¸•à¸²à¸¡ LINE Account
            $reply = checkAutoReply($db, $messageText, $lineAccountId);
            if ($reply) {
                $line->replyMessage($replyToken, [$reply]);
                saveOutgoingMessage($db, $user['id'], json_encode($reply), 'system', 'flex');
                return;
            }
            
            // à¹„à¸¡à¹ˆà¸•à¸­à¸š default reply - à¸£à¸­à¹à¸­à¸”à¸¡à¸´à¸™à¸•à¸­à¸š
            devLog($db, 'info', 'webhook', 'No command matched - waiting for admin', [
                'user_id' => $userId,
                'message' => mb_substr($messageText, 0, 100)
            ], $userId);
            
        } catch (Exception $e) {
            // Log error
            devLog($db, 'error', 'handleMessage', $e->getMessage(), [
                'user_id' => $userId,
                'message_type' => $messageType ?? 'unknown',
                'message_text' => mb_substr($messageText ?? '', 0, 100),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], $userId);
            error_log("handleMessage error: " . $e->getMessage());
            
            // Try to reply with error message
            try {
                $line->replyMessage($replyToken, ['type' => 'text', 'text' => 'âŒ à¹€à¸à¸´à¸”à¸‚à¹‰à¸­à¸œà¸´à¸”à¸žà¸¥à¸²à¸” à¸à¸£à¸¸à¸“à¸²à¸¥à¸­à¸‡à¹ƒà¸«à¸¡à¹ˆà¸­à¸µà¸à¸„à¸£à¸±à¹‰à¸‡']);
            } catch (Exception $e2) {}
        }
    }

        /**
         * Check auto-reply rules (Upgraded with Sender, Quick Reply, Alt Text)
         * à¹à¸¢à¸à¸•à¸²à¸¡ LINE Account - à¸”à¸¶à¸‡à¹€à¸‰à¸žà¸²à¸°à¸à¸Žà¸‚à¸­à¸‡ account à¸™à¸±à¹‰à¸™à¹† à¸«à¸£à¸·à¸­à¸à¸Žà¸—à¸µà¹ˆà¹„à¸¡à¹ˆà¸£à¸°à¸šà¸¸ account (global)
         */
        function checkAutoReply($db, $text, $lineAccountId = null) {
            // à¸”à¸¶à¸‡à¸à¸Žà¸—à¸µà¹ˆà¸•à¸£à¸‡à¸à¸±à¸š account à¸™à¸µà¹‰ à¸«à¸£à¸·à¸­à¸à¸Ž global (line_account_id IS NULL)
            if ($lineAccountId) {
                $stmt = $db->prepare("SELECT * FROM auto_replies WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY line_account_id DESC, priority DESC");
                $stmt->execute([$lineAccountId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC");
                $stmt->execute();
            }
            $rules = $stmt->fetchAll();

            foreach ($rules as $rule) {
                $matched = false;
                switch ($rule['match_type']) {
                    case 'exact':
                        $matched = (mb_strtolower($text) === mb_strtolower($rule['keyword']));
                        break;
                    case 'contains':
                        $matched = (mb_stripos($text, $rule['keyword']) !== false);
                        break;
                    case 'starts_with':
                        $matched = (mb_stripos($text, $rule['keyword']) === 0);
                        break;
                    case 'regex':
                        $matched = preg_match('/' . $rule['keyword'] . '/i', $text);
                        break;
                    case 'all':
                        // Match all messages - à¸•à¸­à¸šà¸—à¸¸à¸à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡
                        $matched = true;
                        break;
                }

                if ($matched) {
                    // Update use count if column exists
                    try {
                        $stmt2 = $db->prepare("UPDATE auto_replies SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?");
                        $stmt2->execute([$rule['id']]);
                    } catch (Exception $e) {}
                    
                    // Build message
                    $message = null;
                    
                    if ($rule['reply_type'] === 'text') {
                        $message = ['type' => 'text', 'text' => $rule['reply_content']];
                    } else {
                        // Flex Message
                        $flexContent = json_decode($rule['reply_content'], true);
                        if ($flexContent) {
                            $altText = $rule['alt_text'] ?? $rule['keyword'] ?? 'à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡';
                            
                            // Add share button if enabled
                            $enableShare = $rule['enable_share'] ?? false;
                            if ($enableShare && defined('LIFF_SHARE_ID') && LIFF_SHARE_ID) {
                                $shareLabel = $rule['share_button_label'] ?? 'ðŸ“¤ à¹à¸Šà¸£à¹Œà¹ƒà¸«à¹‰à¹€à¸žà¸·à¹ˆà¸­à¸™';
                                $flexContent = addShareButtonToFlex($flexContent, $rule['id'], $shareLabel);
                            }
                            
                            $message = [
                                'type' => 'flex',
                                'altText' => $altText,
                                'contents' => $flexContent
                            ];
                        }
                    }
                    
                    if (!$message) return null;
                    
                    // Add Sender if exists
                    $senderName = $rule['sender_name'] ?? null;
                    $senderIcon = $rule['sender_icon'] ?? null;
                    if ($senderName) {
                        $message['sender'] = ['name' => $senderName];
                        if ($senderIcon) {
                            $message['sender']['iconUrl'] = $senderIcon;
                        }
                    }
                    
                    // Add Quick Reply if exists (Full Featured)
                    $quickReply = $rule['quick_reply'] ?? null;
                    if ($quickReply) {
                        $qrItems = json_decode($quickReply, true);
                        if ($qrItems && is_array($qrItems)) {
                            $quickReplyActions = [];
                            foreach ($qrItems as $item) {
                                $qrItem = ['type' => 'action'];
                                
                                // Add icon if exists
                                if (!empty($item['imageUrl'])) {
                                    $qrItem['imageUrl'] = $item['imageUrl'];
                                }
                                
                                $actionType = $item['type'] ?? 'message';
                                
                                switch ($actionType) {
                                    case 'message':
                                        $qrItem['action'] = [
                                            'type' => 'message',
                                            'label' => $item['label'],
                                            'text' => $item['text'] ?? $item['label']
                                        ];
                                        break;
                                        
                                    case 'uri':
                                        $qrItem['action'] = [
                                            'type' => 'uri',
                                            'label' => $item['label'],
                                            'uri' => $item['uri']
                                        ];
                                        break;
                                        
                                    case 'postback':
                                        $qrItem['action'] = [
                                            'type' => 'postback',
                                            'label' => $item['label'],
                                            'data' => $item['data'] ?? ''
                                        ];
                                        if (!empty($item['displayText'])) {
                                            $qrItem['action']['displayText'] = $item['displayText'];
                                        }
                                        break;
                                        
                                    case 'datetimepicker':
                                        $qrItem['action'] = [
                                            'type' => 'datetimepicker',
                                            'label' => $item['label'],
                                            'data' => $item['data'] ?? '',
                                            'mode' => $item['mode'] ?? 'datetime'
                                        ];
                                        if (!empty($item['initial'])) {
                                            $qrItem['action']['initial'] = $item['initial'];
                                        }
                                        if (!empty($item['min'])) {
                                            $qrItem['action']['min'] = $item['min'];
                                        }
                                        if (!empty($item['max'])) {
                                            $qrItem['action']['max'] = $item['max'];
                                        }
                                        break;
                                        
                                    case 'camera':
                                    case 'cameraRoll':
                                    case 'location':
                                        $qrItem['action'] = [
                                            'type' => $actionType,
                                            'label' => $item['label']
                                        ];
                                        break;
                                    
                                    case 'share':
                                        // Share button - à¹ƒà¸Šà¹‰ LINE URI Scheme
                                        $shareText = $item['shareText'] ?? 'à¸¡à¸²à¸”à¸¹à¸ªà¸´à¹ˆà¸‡à¸™à¸µà¹‰à¸ªà¸´!';
                                        $encodedText = urlencode($shareText);
                                        $qrItem['action'] = [
                                            'type' => 'uri',
                                            'label' => $item['label'],
                                            'uri' => "https://line.me/R/share?text=" . $encodedText
                                        ];
                                        break;
                                        
                                    default:
                                        $qrItem['action'] = [
                                            'type' => 'message',
                                            'label' => $item['label'],
                                            'text' => $item['text'] ?? $item['label']
                                        ];
                                }
                                
                                $quickReplyActions[] = $qrItem;
                            }
                            if (!empty($quickReplyActions)) {
                                $message['quickReply'] = ['items' => $quickReplyActions];
                            }
                        }
                    }
                    
                    return $message;
                }
            }
            return null;
        }

        /**
         * Add share button to Flex Message
         * @param array $flexContent - Flex bubble or carousel
         * @param int $ruleId - Auto-reply rule ID
         * @param string $label - Button label
         * @return array - Modified flex content
         */
        function addShareButtonToFlex($flexContent, $ruleId, $label = 'ðŸ“¤ à¹à¸Šà¸£à¹Œà¹ƒà¸«à¹‰à¹€à¸žà¸·à¹ˆà¸­à¸™') {
            $liffId = LIFF_SHARE_ID;
            $shareUrl = "https://liff.line.me/{$liffId}?rule={$ruleId}";
            
            $shareButton = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => $label,
                    'uri' => $shareUrl
                ],
                'style' => 'secondary',
                'color' => '#3B82F6',
                'height' => 'sm',
                'margin' => 'sm'
            ];
            
            // Handle bubble
            if (isset($flexContent['type']) && $flexContent['type'] === 'bubble') {
                if (!isset($flexContent['footer'])) {
                    $flexContent['footer'] = [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [],
                        'paddingAll' => 'lg'
                    ];
                }
                $flexContent['footer']['contents'][] = $shareButton;
            }
            // Handle carousel
            elseif (isset($flexContent['type']) && $flexContent['type'] === 'carousel') {
                foreach ($flexContent['contents'] as &$bubble) {
                    if (!isset($bubble['footer'])) {
                        $bubble['footer'] = [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [],
                            'paddingAll' => 'lg'
                        ];
                    }
                    $bubble['footer']['contents'][] = $shareButton;
                }
            }
            
            return $flexContent;
        }

        /**
         * Check AI chatbot - Using Gemini 2.0 with Conversation History
         * Enhanced for conversation continuity
         * 
         * V5.0: à¹€à¸žà¸´à¹ˆà¸¡ Command Mode (/ai, /mims, /triage, /human)
         * V4.0: à¹€à¸žà¸´à¹ˆà¸¡ Keyword Routing + Bot Pause Feature
         * V3.0: à¸£à¸­à¸‡à¸£à¸±à¸š PharmacyAI Adapter (Triage System)
         * V2.6: à¸£à¸­à¸‡à¸£à¸±à¸š Module à¹ƒà¸«à¸¡à¹ˆ (modules/AIChat)
         */
        function checkAIChatbot($db, $text, $lineAccountId = null, $userId = null) {
            try {
                // Log entry point
                error_log("AI_entry: checkAIChatbot called - text: " . mb_substr($text, 0, 50) . ", lineAccountId: $lineAccountId, userId: $userId");
                devLog($db, 'debug', 'AI_entry', 'checkAIChatbot called', [
                    'text' => mb_substr($text, 0, 50),
                    'line_account_id' => $lineAccountId,
                    'user_id' => $userId
                ], null);
                
                $textLower = mb_strtolower(trim($text));
                $originalText = trim($text);
                
                // ===== 0. à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š Command Mode (/ai, /mims, /triage, /human) =====
                $commandMode = null;
                $commandMessage = $originalText;
                
                // à¸£à¸¹à¸›à¹à¸šà¸š: /command à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡ à¸«à¸£à¸·à¸­ @command à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡
                // à¸£à¸­à¸‡à¸£à¸±à¸š backtick à¸«à¸£à¸·à¸­ character à¸žà¸´à¹€à¸¨à¸©à¸‚à¹‰à¸²à¸‡à¸«à¸™à¹‰à¸²
                $cleanText = preg_replace('/^[`\'"\s]+/', '', $originalText);
                
                // ===== à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š "/" à¹€à¸”à¸µà¸¢à¸§ â†’ à¹€à¸£à¸´à¹ˆà¸¡ AI à¹à¸¥à¸°à¹à¸ªà¸”à¸‡à¸„à¸³à¸­à¸˜à¸´à¸šà¸²à¸¢ =====
                if ($cleanText === '/' || $cleanText === '@') {
                    // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¹€à¸„à¸¢à¹ƒà¸Šà¹‰ AI à¸«à¸£à¸·à¸­à¸¢à¸±à¸‡
                    $isFirstTime = true;
                    if ($userId) {
                        try {
                            $stmt = $db->prepare("SELECT COUNT(*) FROM ai_chat_logs WHERE user_id = ? LIMIT 1");
                            $stmt->execute([$userId]);
                            $isFirstTime = ($stmt->fetchColumn() == 0);
                        } catch (Exception $e) {}
                    }
                    
                    // à¸”à¸¶à¸‡ AI mode à¸ˆà¸²à¸ ai_settings
                    $configuredMode = 'sales'; // default
                    try {
                        $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                        $stmt->execute([$lineAccountId]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($result && $result['ai_mode']) {
                            $configuredMode = $result['ai_mode'];
                        }
                    } catch (Exception $e) {}
                    
                    // à¸šà¸±à¸™à¸—à¸¶à¸à¹‚à¸«à¸¡à¸” AI à¸•à¸²à¸¡à¸—à¸µà¹ˆà¸•à¸±à¹‰à¸‡à¸„à¹ˆà¸²à¹„à¸§à¹‰
                    if ($userId) {
                        setUserAIMode($db, $userId, $configuredMode);
                    }
                    
                    if ($isFirstTime) {
                        // à¸„à¸£à¸±à¹‰à¸‡à¹à¸£à¸ - à¹à¸ªà¸”à¸‡à¸„à¸³à¸­à¸˜à¸´à¸šà¸²à¸¢à¸à¸²à¸£à¹ƒà¸Šà¹‰à¸‡à¸²à¸™
                        return [[
                            'type' => 'text',
                            'text' => "ðŸ¤– à¸¢à¸´à¸™à¸”à¸µà¸•à¹‰à¸­à¸™à¸£à¸±à¸šà¸ªà¸¹à¹ˆ AI Assistant!\n\nâœ¨ à¸§à¸´à¸˜à¸µà¹ƒà¸Šà¹‰à¸‡à¸²à¸™:\nâ€¢ à¸žà¸´à¸¡à¸žà¹Œà¸„à¸³à¸–à¸²à¸¡à¸«à¸£à¸·à¸­à¸ªà¸´à¹ˆà¸‡à¸—à¸µà¹ˆà¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¹„à¸”à¹‰à¹€à¸¥à¸¢\nâ€¢ AI à¸ˆà¸°à¸Šà¹ˆà¸§à¸¢à¸•à¸­à¸šà¸„à¸³à¸–à¸²à¸¡ à¹à¸™à¸°à¸™à¸³à¸ªà¸´à¸™à¸„à¹‰à¸² à¹à¸¥à¸°à¹ƒà¸«à¹‰à¸‚à¹‰à¸­à¸¡à¸¹à¸¥\n\nðŸ“ à¸•à¸±à¸§à¸­à¸¢à¹ˆà¸²à¸‡:\nâ€¢ \"à¸¡à¸µà¸ªà¸´à¸™à¸„à¹‰à¸²à¸­à¸°à¹„à¸£à¸šà¹‰à¸²à¸‡\"\nâ€¢ \"à¹à¸™à¸°à¸™à¸³à¸ªà¸´à¸™à¸„à¹‰à¸²à¸‚à¸²à¸¢à¸”à¸µ\"\nâ€¢ \"à¸£à¸²à¸„à¸²à¸ªà¸´à¸™à¸„à¹‰à¸² XXX\"\n\nðŸ’¡ à¸žà¸´à¸¡à¸žà¹Œ /exit à¹€à¸žà¸·à¹ˆà¸­à¸­à¸­à¸à¸ˆà¸²à¸à¹‚à¸«à¸¡à¸” AI\n\nðŸŽ¯ à¹€à¸£à¸´à¹ˆà¸¡à¸•à¹‰à¸™à¹„à¸”à¹‰à¹€à¸¥à¸¢! à¸žà¸´à¸¡à¸žà¹Œà¸„à¸³à¸–à¸²à¸¡à¸‚à¸­à¸‡à¸„à¸¸à¸“:",
                            'sender' => [
                                'name' => 'ðŸ¤– AI Assistant',
                                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
                            ]
                        ]];
                    } else {
                        // à¹€à¸„à¸¢à¹ƒà¸Šà¹‰à¹à¸¥à¹‰à¸§ - à¹à¸ªà¸”à¸‡à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸ªà¸±à¹‰à¸™à¹†
                        return [[
                            'type' => 'text',
                            'text' => "ðŸ¤– AI à¸žà¸£à¹‰à¸­à¸¡à¹ƒà¸«à¹‰à¸šà¸£à¸´à¸à¸²à¸£à¸„à¹ˆà¸°!\n\nà¸žà¸´à¸¡à¸žà¹Œà¸„à¸³à¸–à¸²à¸¡à¸«à¸£à¸·à¸­à¸ªà¸´à¹ˆà¸‡à¸—à¸µà¹ˆà¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¹„à¸”à¹‰à¹€à¸¥à¸¢\n(à¸žà¸´à¸¡à¸žà¹Œ /exit à¹€à¸žà¸·à¹ˆà¸­à¸­à¸­à¸)",
                            'sender' => [
                                'name' => 'ðŸ¤– AI Assistant',
                                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
                            ]
                        ]];
                    }
                }
                
                // à¸£à¸­à¸‡à¸£à¸±à¸šà¸—à¸±à¹‰à¸‡ / à¹à¸¥à¸° @ à¸™à¸³à¸«à¸™à¹‰à¸² command (à¸£à¸­à¸‡à¸£à¸±à¸šà¸—à¸±à¹‰à¸‡ English à¹à¸¥à¸° Thai)
                if (preg_match('/^[\/\@]([\w\p{Thai}]+)\s*(.*)/u', $cleanText, $matches)) {
                    $command = mb_strtolower($matches[1]);
                    $commandMessage = trim($matches[2]);
                    
                    // Map commands to modes
                    $commandMap = [
                        'ai' => 'auto',          // /ai = à¹ƒà¸Šà¹‰ mode à¸ˆà¸²à¸ settings
                        'pharmacy' => 'pharmacist',
                        'pharmacist' => 'pharmacist',
                        'à¸¢à¸²' => 'pharmacist',
                        'à¸–à¸²à¸¡' => 'auto',         // /à¸–à¸²à¸¡ = à¹ƒà¸Šà¹‰ mode à¸ˆà¸²à¸ settings
                        'à¸‚à¸²à¸¢' => 'sales',        // /à¸‚à¸²à¸¢ = à¹‚à¸«à¸¡à¸”à¸‚à¸²à¸¢
                        'sales' => 'sales',
                        'support' => 'support',  // /support = à¹‚à¸«à¸¡à¸”à¸‹à¸±à¸žà¸žà¸­à¸£à¹Œà¸•
                        'à¸‹à¸±à¸žà¸žà¸­à¸£à¹Œà¸•' => 'support',
                        
                        'mims' => 'mims',        // /mims = MIMS AI (à¸„à¸§à¸²à¸¡à¸£à¸¹à¹‰à¸—à¸²à¸‡à¸à¸²à¸£à¹à¸žà¸—à¸¢à¹Œ)
                        'med' => 'mims',
                        'à¸§à¸´à¸Šà¸²à¸à¸²à¸£' => 'mims',
                        
                        'triage' => 'triage',    // /triage = à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´
                        'à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´' => 'triage',
                        'assess' => 'triage',
                        
                        'human' => 'human',      // /human = à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡
                        'à¸„à¸™' => 'human',
                        'à¹€à¸ à¸ªà¸±à¸Š' => 'human',
                        
                        'exit' => 'exit',        // /exit = à¸­à¸­à¸à¸ˆà¸²à¸à¹‚à¸«à¸¡à¸” AI
                        'à¸­à¸­à¸' => 'exit',
                        'à¸«à¸¢à¸¸à¸”' => 'exit',
                        
                        'help' => 'help',        // /help = à¹à¸ªà¸”à¸‡à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸”
                        'à¸Šà¹ˆà¸§à¸¢' => 'help',
                    ];
                    
                    if (isset($commandMap[$command])) {
                        $commandMode = $commandMap[$command];
                        
                        // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ 'auto' à¹ƒà¸«à¹‰à¸”à¸¶à¸‡ mode à¸ˆà¸²à¸ ai_settings
                        if ($commandMode === 'auto') {
                            try {
                                $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                                $stmt->execute([$lineAccountId]);
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                $commandMode = ($result && $result['ai_mode']) ? $result['ai_mode'] : 'sales';
                            } catch (Exception $e) {
                                $commandMode = 'sales';
                            }
                        }
                        
                        devLog($db, 'debug', 'AI_command', 'Command detected', [
                            'command' => $command, 
                            'mode' => $commandMode, 
                            'message' => $commandMessage,
                            'original' => $originalText,
                            'cleaned' => $cleanText
                        ], null);
                    } else {
                        // Unknown command â†’ à¸–à¸·à¸­à¸§à¹ˆà¸²à¹€à¸›à¹‡à¸™à¸„à¸³à¸–à¸²à¸¡à¸–à¸²à¸¡ AI
                        // à¹ƒà¸Šà¹‰ mode à¸ˆà¸²à¸ ai_settings
                        try {
                            $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                            $stmt->execute([$lineAccountId]);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            $commandMode = ($result && $result['ai_mode']) ? $result['ai_mode'] : 'sales';
                        } catch (Exception $e) {
                            $commandMode = 'sales';
                        }
                        $commandMessage = $command . ($commandMessage ? ' ' . $commandMessage : '');
                        devLog($db, 'debug', 'AI_command', 'Unknown command - treating as AI question', [
                            'command' => $command,
                            'mode' => $commandMode,
                            'message' => $commandMessage,
                            'original' => $originalText
                        ], null);
                    }
                } else {
                    devLog($db, 'debug', 'AI_command', 'No command pattern matched', [
                        'original' => $originalText,
                        'cleaned' => $cleanText
                    ], null);
                }
                
                // ===== DEBUG: Log after command parsing =====
                error_log("AI_TRACE_1: commandMode=$commandMode, line=" . __LINE__);
                try {
                    devLog($db, 'debug', 'AI_trace_1', 'After command parsing', [
                        'commandMode' => $commandMode,
                        'commandMessage' => mb_substr($commandMessage ?? '', 0, 30),
                        'line' => __LINE__
                    ], null);
                } catch (Exception $e) {
                    error_log("AI_trace_1 error: " . $e->getMessage());
                }
                
                // ===== 0.5 à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š AI Mode à¸‚à¸­à¸‡ user =====
                // à¸–à¹‰à¸² user à¹€à¸„à¸¢à¸žà¸´à¸¡à¸žà¹Œ /ai, /mims, /triage â†’ à¸ˆà¸³à¹‚à¸«à¸¡à¸”à¹„à¸§à¹‰
                // à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸–à¸±à¸”à¹„à¸›à¸ˆà¸°à¹ƒà¸Šà¹‰à¹‚à¸«à¸¡à¸”à¸™à¸±à¹‰à¸™à¸•à¹ˆà¸­à¸ˆà¸™à¸à¸§à¹ˆà¸²à¸ˆà¸°à¹€à¸›à¸¥à¸µà¹ˆà¸¢à¸™
                if (!$commandMode && $userId) {
                    $currentAIMode = getUserAIMode($db, $userId);
                    if ($currentAIMode) {
                        $commandMode = $currentAIMode;
                        $commandMessage = $originalText;
                        devLog($db, 'debug', 'AI_mode', 'Using saved AI mode', ['mode' => $currentAIMode, 'userId' => $userId], null);
                    }
                }
                
                // à¸–à¹‰à¸²à¸žà¸´à¸¡à¸žà¹Œ command à¹ƒà¸«à¸¡à¹ˆ â†’ à¸šà¸±à¸™à¸—à¸¶à¸à¹‚à¸«à¸¡à¸”
                if ($commandMode && $userId && in_array($commandMode, ['pharmacist', 'pharmacy', 'sales', 'support', 'mims', 'triage'])) {
                    setUserAIMode($db, $userId, $commandMode);
                }
                
                // à¸–à¹‰à¸²à¸žà¸´à¸¡à¸žà¹Œ /human à¸«à¸£à¸·à¸­ /exit â†’ à¸¥à¸šà¹‚à¸«à¸¡à¸”
                if (($commandMode === 'human' || $commandMode === 'exit') && $userId) {
                    clearUserAIMode($db, $userId);
                }
                
                // ===== DEBUG: Log after mode check =====
                devLog($db, 'debug', 'AI_trace_2', 'After mode check', [
                    'commandMode' => $commandMode,
                    'line' => __LINE__
                ], null);
                
                // à¸”à¸¶à¸‡ sender settings à¸ªà¸³à¸«à¸£à¸±à¸š system messages
                $systemSender = getAISenderSettings($db, $lineAccountId);
                
                // ===== /exit - à¸­à¸­à¸à¸ˆà¸²à¸à¹‚à¸«à¸¡à¸” AI =====
                if ($commandMode === 'exit') {
                    return [[
                        'type' => 'text',
                        'text' => "âœ… à¸­à¸­à¸à¸ˆà¸²à¸à¹‚à¸«à¸¡à¸” AI à¹à¸¥à¹‰à¸§à¸„à¹ˆà¸°\n\nà¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸–à¸±à¸”à¹„à¸›à¸ˆà¸°à¸ªà¹ˆà¸‡à¸–à¸¶à¸‡à¹à¸­à¸”à¸¡à¸´à¸™à¹‚à¸”à¸¢à¸•à¸£à¸‡\n\nðŸ’¡ à¸žà¸´à¸¡à¸žà¹Œ /ai, /mims à¸«à¸£à¸·à¸­ /triage à¹€à¸žà¸·à¹ˆà¸­à¸à¸¥à¸±à¸šà¸¡à¸²à¹ƒà¸Šà¹‰ AI à¹„à¸”à¹‰à¸—à¸¸à¸à¹€à¸¡à¸·à¹ˆà¸­à¸„à¹ˆà¸°",
                        'sender' => $systemSender
                    ]];
                }
                
                // ===== /help - à¹à¸ªà¸”à¸‡à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸” =====
                if ($commandMode === 'help') {
                    return [[
                        'type' => 'text',
                        'text' => "ðŸ¤– à¸„à¸³à¸ªà¸±à¹ˆà¸‡ AI à¸—à¸µà¹ˆà¹ƒà¸Šà¹‰à¹„à¸”à¹‰:\n\n" .
                                "/ai - à¹€à¸‚à¹‰à¸²à¹‚à¸«à¸¡à¸” AI à¸•à¸²à¸¡à¸—à¸µà¹ˆà¸•à¸±à¹‰à¸‡à¸„à¹ˆà¸²à¹„à¸§à¹‰\n" .
                                "/mims - à¹€à¸‚à¹‰à¸²à¹‚à¸«à¸¡à¸” MIMS (à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¸¢à¸²)\n" .
                                "/triage - à¹€à¸£à¸´à¹ˆà¸¡à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´à¸­à¸²à¸à¸²à¸£\n" .
                                "/human - à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡\n" .
                                "/exit - à¸­à¸­à¸à¸ˆà¸²à¸à¹‚à¸«à¸¡à¸” AI\n\n" .
                                "ðŸ’¡ à¹€à¸¡à¸·à¹ˆà¸­à¹€à¸‚à¹‰à¸²à¹‚à¸«à¸¡à¸”à¹à¸¥à¹‰à¸§ à¸žà¸´à¸¡à¸žà¹Œà¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹„à¸”à¹‰à¹€à¸¥à¸¢\n" .
                                "AI à¸ˆà¸°à¸•à¸­à¸šà¸•à¹ˆà¸­à¸ˆà¸™à¸à¸§à¹ˆà¸²à¸ˆà¸°à¸žà¸´à¸¡à¸žà¹Œ /exit",
                        'sender' => $systemSender
                    ]];
                }
                
                // ===== 1. à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸² Bot à¸–à¸¹à¸ Pause à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ =====
                if ($userId && isAIPaused($db, $userId)) {
                    // à¸–à¹‰à¸²à¸žà¸´à¸¡à¸žà¹Œ /ai à¸«à¸£à¸·à¸­ command à¸­à¸·à¹ˆà¸™ à¹ƒà¸«à¹‰ resume bot
                    if ($commandMode && $commandMode !== 'human') {
                        resumeAI($db, $userId);
                        devLog($db, 'info', 'AI_pause', 'AI resumed by command', ['user_id' => $userId, 'command' => $commandMode], null);
                    } else {
                        devLog($db, 'debug', 'AI_pause', 'AI is paused for user', ['user_id' => $userId], null);
                        return null; // à¹„à¸¡à¹ˆà¸•à¸­à¸š - à¹ƒà¸«à¹‰à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡à¸•à¸­à¸š
                    }
                }
                
                // ===== 2. /human à¸«à¸£à¸·à¸­ à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡ =====
                if ($commandMode === 'human') {
                    pauseAI($db, $userId, 20);
                    notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $originalText);
                    
                    return [[
                        'type' => 'text',
                        'text' => "à¹€à¸‚à¹‰à¸²à¹ƒà¸ˆà¸„à¹ˆà¸° ðŸ™\n\nà¸£à¸°à¸šà¸šà¹„à¸”à¹‰à¹à¸ˆà¹‰à¸‡à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¹à¸¥à¹‰à¸§ à¸ˆà¸°à¸¡à¸µà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸•à¸´à¸”à¸•à¹ˆà¸­à¸à¸¥à¸±à¸šà¸ à¸²à¸¢à¹ƒà¸™ 5-10 à¸™à¸²à¸—à¸µà¸„à¹ˆà¸°\n\nðŸ“ž à¸«à¸²à¸à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¸•à¸´à¸”à¸•à¹ˆà¸­à¸”à¹ˆà¸§à¸™ à¹‚à¸—à¸£: 02-XXX-XXXX\n\n(à¸šà¸­à¸—à¸ˆà¸°à¸«à¸¢à¸¸à¸”à¸•à¸­à¸šà¸Šà¸±à¹ˆà¸§à¸„à¸£à¸²à¸§ 20 à¸™à¸²à¸—à¸µ)\n\nðŸ’¡ à¸žà¸´à¸¡à¸žà¹Œ /ai à¹€à¸žà¸·à¹ˆà¸­à¸à¸¥à¸±à¸šà¸¡à¸²à¹ƒà¸Šà¹‰à¸šà¸­à¸—à¹„à¸”à¹‰à¸—à¸¸à¸à¹€à¸¡à¸·à¹ˆà¸­",
                        'sender' => $systemSender
                    ]];
                }
                
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š keyword à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡ (à¹„à¸¡à¹ˆà¹ƒà¸Šà¹‰ command)
                $humanPharmacistKeywords = [
                    'à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£', 'à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¸„à¸™', 'à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Š', 'à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡', 
                    'à¸„à¸™à¸ˆà¸£à¸´à¸‡', 'à¹„à¸¡à¹ˆà¹ƒà¸Šà¹ˆà¸šà¸­à¸—', 'à¹„à¸¡à¹ˆà¹€à¸­à¸²à¸šà¸­à¸—', 'à¸«à¸¢à¸¸à¸”à¸šà¸­à¸—', 'à¸›à¸´à¸”à¸šà¸­à¸—',
                    'à¸‚à¸­à¸žà¸¹à¸”à¸à¸±à¸šà¸„à¸™', 'à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¸„à¸¸à¸¢à¸à¸±à¸šà¸„à¸™', 'human', 'real pharmacist',
                    'à¸‚à¸­à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸•à¸±à¸§à¸ˆà¸£à¸´à¸‡', 'à¹€à¸ à¸ªà¸±à¸Šà¸•à¸±à¸§à¸ˆà¸£à¸´à¸‡', 'à¹„à¸¡à¹ˆà¸•à¹‰à¸­à¸‡à¸à¸²à¸£ ai', 'à¹„à¸¡à¹ˆà¹€à¸­à¸² ai'
                ];
                
                if (!$commandMode) {
                    foreach ($humanPharmacistKeywords as $keyword) {
                        if (mb_strpos($textLower, $keyword) !== false) {
                            pauseAI($db, $userId, 20);
                            notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $text);
                            
                            return [[
                                'type' => 'text',
                                'text' => "à¹€à¸‚à¹‰à¸²à¹ƒà¸ˆà¸„à¹ˆà¸° ðŸ™\n\nà¸£à¸°à¸šà¸šà¹„à¸”à¹‰à¹à¸ˆà¹‰à¸‡à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¹à¸¥à¹‰à¸§ à¸ˆà¸°à¸¡à¸µà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸•à¸´à¸”à¸•à¹ˆà¸­à¸à¸¥à¸±à¸šà¸ à¸²à¸¢à¹ƒà¸™ 5-10 à¸™à¸²à¸—à¸µà¸„à¹ˆà¸°\n\nðŸ“ž à¸«à¸²à¸à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¸•à¸´à¸”à¸•à¹ˆà¸­à¸”à¹ˆà¸§à¸™ à¹‚à¸—à¸£: 02-XXX-XXXX\n\n(à¸šà¸­à¸—à¸ˆà¸°à¸«à¸¢à¸¸à¸”à¸•à¸­à¸šà¸Šà¸±à¹ˆà¸§à¸„à¸£à¸²à¸§ 20 à¸™à¸²à¸—à¸µ)\n\nðŸ’¡ à¸žà¸´à¸¡à¸žà¹Œ /ai à¹€à¸žà¸·à¹ˆà¸­à¸à¸¥à¸±à¸šà¸¡à¸²à¹ƒà¸Šà¹‰à¸šà¸­à¸—à¹„à¸”à¹‰à¸—à¸¸à¸à¹€à¸¡à¸·à¹ˆà¸­",
                                'sender' => $systemSender
                            ]];
                        }
                    }
                }
                
                // ===== 3. /mims - MIMS Pharmacist AI =====
                if ($commandMode === 'mims') {
                    $mimsFileExists = file_exists(__DIR__ . '/modules/AIChat/Adapters/MIMSPharmacistAI.php');
                    devLog($db, 'debug', 'AI_mims', 'MIMS command', ['fileExists' => $mimsFileExists, 'message' => $commandMessage], null);
                    
                    if ($mimsFileExists) {
                        try {
                            require_once __DIR__ . '/modules/AIChat/Adapters/MIMSPharmacistAI.php';
                            $adapter = new \Modules\AIChat\Adapters\MIMSPharmacistAI($db, $lineAccountId);
                            if ($userId) $adapter->setUserId($userId);
                            
                            $isEnabled = $adapter->isEnabled();
                            devLog($db, 'debug', 'AI_mims', 'MIMS isEnabled', ['enabled' => $isEnabled, 'commandMessage' => $commandMessage], null);
                            
                            // à¸”à¸¶à¸‡ sender settings à¸ªà¸³à¸«à¸£à¸±à¸š MIMS mode
                            $mimsSender = getAISenderSettings($db, $lineAccountId, 'mims');
                            
                            if ($isEnabled) {
                                // à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¸¡à¸µà¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡ à¹ƒà¸«à¹‰à¹à¸ªà¸”à¸‡à¸„à¸³à¹à¸™à¸°à¸™à¸³
                                if (empty($commandMessage)) {
                                    devLog($db, 'debug', 'AI_mims', 'MIMS empty message - showing help', [], null);
                                    return [[
                                        'type' => 'text',
                                        'text' => "ðŸ“š MIMS Pharmacist AI à¸žà¸£à¹‰à¸­à¸¡à¹ƒà¸«à¹‰à¸šà¸£à¸´à¸à¸²à¸£à¸„à¹ˆà¸°\n\nà¸ªà¸²à¸¡à¸²à¸£à¸–à¸–à¸²à¸¡à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¹€à¸à¸µà¹ˆà¸¢à¸§à¸à¸±à¸š:\nâ€¢ à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¸¢à¸²à¹à¸¥à¸°à¸ªà¸£à¸£à¸žà¸„à¸¸à¸“\nâ€¢ à¸­à¸²à¸à¸²à¸£à¹à¸¥à¸°à¸à¸²à¸£à¸£à¸±à¸à¸©à¸²\nâ€¢ à¸‚à¹‰à¸­à¸„à¸§à¸£à¸£à¸°à¸§à¸±à¸‡à¹ƒà¸™à¸à¸²à¸£à¹ƒà¸Šà¹‰à¸¢à¸²\n\nðŸ’¡ à¸•à¸±à¸§à¸­à¸¢à¹ˆà¸²à¸‡:\n/mims à¸¢à¸² paracetamol\n/mims à¸­à¸²à¸à¸²à¸£à¸›à¸§à¸”à¸«à¸±à¸§à¹„à¸¡à¹€à¸à¸£à¸™\n/mims à¸¢à¸²à¹à¸à¹‰à¹à¸žà¹‰à¸•à¸±à¸§à¹„à¸«à¸™à¸”à¸µ",
                                        'sender' => $mimsSender
                                    ]];
                                }
                                
                                devLog($db, 'debug', 'AI_mims', 'MIMS processing message', ['message' => $commandMessage], null);
                                $result = $adapter->processMessage($commandMessage);
                                devLog($db, 'debug', 'AI_mims', 'MIMS result', ['success' => $result['success'] ?? false, 'hasMessage' => !empty($result['message']), 'hasResponse' => !empty($result['response']), 'error' => $result['error'] ?? null], null);
                                
                                if ($result['success'] && !empty($result['message'])) {
                                    $msg = $result['message'];
                                    // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸² message à¹€à¸›à¹‡à¸™ array à¸—à¸µà¹ˆà¸¡à¸µ type à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
                                    if (is_array($msg) && isset($msg['type'])) {
                                        // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µ text content à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
                                        if (empty($msg['text'])) {
                                            // à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¸¡à¸µ text à¹ƒà¸«à¹‰à¹ƒà¸Šà¹‰ response à¹à¸—à¸™
                                            $msg['text'] = $result['response'] ?? 'à¸‚à¸­à¸­à¸ à¸±à¸¢à¸„à¹ˆà¸° à¹„à¸¡à¹ˆà¸ªà¸²à¸¡à¸²à¸£à¸–à¸›à¸£à¸°à¸¡à¸§à¸¥à¸œà¸¥à¹„à¸”à¹‰';
                                            devLog($db, 'warning', 'AI_mims', 'MIMS message missing text, using response', ['response' => mb_substr($msg['text'], 0, 100)], null);
                                        }
                                        // à¹€à¸žà¸´à¹ˆà¸¡ sender à¸–à¹‰à¸²à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ
                                        if (!isset($msg['sender'])) {
                                            $msg['sender'] = $mimsSender;
                                        }
                                        devLog($db, 'debug', 'AI_mims', 'MIMS returning message array', ['type' => $msg['type'], 'textLength' => strlen($msg['text'] ?? '')], null);
                                        return [$msg];
                                    }
                                    // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ string à¹ƒà¸«à¹‰ wrap à¹€à¸›à¹‡à¸™ LINE message
                                    if (is_string($msg)) {
                                        devLog($db, 'debug', 'AI_mims', 'MIMS returning string message', ['length' => strlen($msg)], null);
                                        return [[
                                            'type' => 'text',
                                            'text' => $msg,
                                            'sender' => $mimsSender
                                        ]];
                                    }
                                    devLog($db, 'debug', 'AI_mims', 'MIMS message format unknown', ['messageType' => gettype($msg)], null);
                                    return [$msg];
                                }
                                
                                // à¸–à¹‰à¸² success à¹à¸•à¹ˆà¹„à¸¡à¹ˆà¸¡à¸µ message à¹ƒà¸«à¹‰à¹ƒà¸Šà¹‰ response
                                if ($result['success'] && !empty($result['response'])) {
                                    devLog($db, 'debug', 'AI_mims', 'MIMS using response text', ['length' => strlen($result['response'])], null);
                                    return [[
                                        'type' => 'text',
                                        'text' => $result['response'],
                                        'sender' => $mimsSender
                                    ]];
                                }
                                
                                // à¸–à¹‰à¸²à¹„à¸¡à¹ˆ success à¹ƒà¸«à¹‰à¹à¸ªà¸”à¸‡ error
                                if (!$result['success']) {
                                    $errorMsg = $result['error'] ?? 'Unknown error';
                                    devLog($db, 'error', 'AI_mims', 'MIMS process failed: ' . $errorMsg, ['user_id' => $userId], null);
                                    return [[
                                        'type' => 'text',
                                        'text' => "âŒ MIMS AI à¸‚à¸±à¸”à¸‚à¹‰à¸­à¸‡: {$errorMsg}\n\nà¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ /ai à¹à¸—à¸™à¹„à¸”à¹‰à¸„à¹ˆà¸°",
                                        'sender' => $mimsSender
                                    ]];
                                }
                            } else {
                                devLog($db, 'warning', 'AI_mims', 'MIMS not enabled - no API key', [], null);
                                return [[
                                    'type' => 'text',
                                    'text' => "âŒ MIMS AI à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¹„à¸”à¹‰à¸•à¸±à¹‰à¸‡à¸„à¹ˆà¸² API Key\n\nà¸à¸£à¸¸à¸“à¸²à¸•à¸´à¸”à¸•à¹ˆà¸­à¸œà¸¹à¹‰à¸”à¸¹à¹à¸¥à¸£à¸°à¸šà¸š à¸«à¸£à¸·à¸­à¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ /ai à¹à¸—à¸™à¹„à¸”à¹‰à¸„à¹ˆà¸°",
                                    'sender' => $mimsSender
                                ]];
                            }
                        } catch (\Throwable $e) {
                            devLog($db, 'error', 'AI_mims', 'MIMS AI error: ' . $e->getMessage(), ['user_id' => $userId, 'trace' => $e->getTraceAsString()], null);
                            return [[
                                'type' => 'text',
                                'text' => "âŒ MIMS AI à¸‚à¸±à¸”à¸‚à¹‰à¸­à¸‡\n\nà¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ /ai à¹à¸—à¸™à¹„à¸”à¹‰à¸„à¹ˆà¸°",
                                'sender' => $mimsSender
                            ]];
                        }
                    }
                    
                    return [[
                        'type' => 'text',
                        'text' => "âŒ MIMS AI à¹„à¸¡à¹ˆà¸žà¸£à¹‰à¸­à¸¡à¹ƒà¸Šà¹‰à¸‡à¸²à¸™à¸‚à¸“à¸°à¸™à¸µà¹‰\n\nà¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ /ai à¹à¸—à¸™à¹„à¸”à¹‰à¸„à¹ˆà¸°",
                        'sender' => getAISenderSettings($db, $lineAccountId, 'mims')
                    ]];
                }
                
                // ===== 4. /triage - à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´à¸­à¸²à¸à¸²à¸£ =====
                if ($commandMode === 'triage') {
                    devLog($db, 'debug', 'AI_triage', 'Triage command', ['userId' => $userId], null);
                    
                    // à¸”à¸¶à¸‡ sender settings à¸ªà¸³à¸«à¸£à¸±à¸š triage mode
                    $triageSender = getAISenderSettings($db, $lineAccountId, 'triage');
                    
                    if (file_exists(__DIR__ . '/modules/AIChat/Services/TriageEngine.php')) {
                        try {
                            // Load all required dependencies via Autoloader
                            require_once __DIR__ . '/modules/AIChat/Autoloader.php';
                            loadAIChatModule();
                            
                            // Pass $db connection to TriageEngine
                            $triage = new \Modules\AIChat\Services\TriageEngine($lineAccountId, $userId, $db);
                            
                            // Reset à¹à¸¥à¸°à¹€à¸£à¸´à¹ˆà¸¡à¹ƒà¸«à¸¡à¹ˆ
                            $result = $triage->process($commandMessage ?: 'à¹€à¸£à¸´à¹ˆà¸¡à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´');
                            devLog($db, 'debug', 'AI_triage', 'Triage result', ['hasText' => !empty($result['text']), 'hasMessage' => !empty($result['message'])], null);
                            
                            $responseText = $result['text'] ?? $result['message'] ?? 'à¸žà¸£à¹‰à¸­à¸¡à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´à¸„à¹ˆà¸°';
                            $lineMessage = [
                                'type' => 'text',
                                'text' => $responseText,
                                'sender' => $triageSender
                            ];
                            
                            if (!empty($result['quickReplies'])) {
                                $lineMessage['quickReply'] = ['items' => $result['quickReplies']];
                            }
                            
                            return [$lineMessage];
                        } catch (\Throwable $e) {
                            devLog($db, 'error', 'AI_triage', 'Triage error: ' . $e->getMessage(), ['user_id' => $userId, 'trace' => $e->getTraceAsString()], null);
                            return [[
                                'type' => 'text',
                                'text' => "âŒ à¸£à¸°à¸šà¸šà¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´à¸‚à¸±à¸”à¸‚à¹‰à¸­à¸‡\n\nà¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ /ai à¹à¸—à¸™à¹„à¸”à¹‰à¸„à¹ˆà¸°",
                                'sender' => $triageSender
                            ]];
                        }
                    } else {
                        return [[
                            'type' => 'text',
                            'text' => "âŒ à¸£à¸°à¸šà¸šà¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´à¹„à¸¡à¹ˆà¸žà¸£à¹‰à¸­à¸¡à¹ƒà¸Šà¹‰à¸‡à¸²à¸™\n\nà¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ /ai à¹à¸—à¸™à¹„à¸”à¹‰à¸„à¹ˆà¸°",
                            'sender' => $triageSender
                        ]];
                    }
                }
                
                // ===== 5. /ai, /sales à¸«à¸£à¸·à¸­ Default - à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š AI Mode à¸à¹ˆà¸­à¸™ =====
                // à¸–à¹‰à¸²à¹ƒà¸Šà¹‰ command /ai à¸«à¸£à¸·à¸­ /sales à¹ƒà¸«à¹‰à¹ƒà¸Šà¹‰à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸«à¸¥à¸±à¸‡ command
                $messageToProcess = $text;
                if (!empty($commandMessage)) {
                    $messageToProcess = $commandMessage;
                }
                
                // à¸”à¸¶à¸‡ AI mode à¸ˆà¸²à¸ ai_settings à¹€à¸ªà¸¡à¸­ (à¹„à¸¡à¹ˆà¸§à¹ˆà¸² commandMode à¸ˆà¸°à¹€à¸›à¹‡à¸™à¸­à¸°à¹„à¸£)
                $currentAIMode = 'sales'; // default to sales
                try {
                    $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                    $stmt->execute([$lineAccountId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['ai_mode']) {
                        $currentAIMode = $result['ai_mode'];
                    }
                } catch (Exception $e) {}
                
                // à¸–à¹‰à¸² commandMode à¹€à¸›à¹‡à¸™ sales/support/pharmacist à¹‚à¸”à¸¢à¸•à¸£à¸‡ â†’ override
                if (in_array($commandMode, ['sales', 'support', 'pharmacist', 'pharmacy'])) {
                    $currentAIMode = $commandMode;
                }
                
                devLog($db, 'debug', 'AI_section5', 'AI Mode determined', [
                    'currentAIMode' => $currentAIMode,
                    'commandMode' => $commandMode,
                    'message' => mb_substr($messageToProcess, 0, 50)
                ], null);
                
                // ===== à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ Sales/Support Mode â†’ à¹ƒà¸Šà¹‰ GeminiChat (à¹„à¸¡à¹ˆà¹ƒà¸Šà¹ˆ PharmacyAI) =====
                if (in_array($currentAIMode, ['sales', 'support']) && file_exists(__DIR__ . '/classes/GeminiChat.php')) {
                    require_once __DIR__ . '/classes/GeminiChat.php';
                    
                    $gemini = new GeminiChat($db, $lineAccountId);
                    
                    devLog($db, 'debug', 'AI_sales', 'GeminiChat check', [
                        'line_account_id' => $lineAccountId,
                        'is_enabled' => $gemini->isEnabled() ? 'yes' : 'no',
                        'mode' => $gemini->getMode()
                    ], null);
                    
                    if ($gemini->isEnabled()) {
                        $history = $userId ? $gemini->getConversationHistory($userId, 10) : [];
                        
                        devLog($db, 'debug', 'AI_sales', 'Processing AI request (Sales Mode)', [
                            'user_id' => $userId,
                            'line_account_id' => $lineAccountId,
                            'message' => mb_substr($messageToProcess, 0, 50),
                            'history_count' => count($history)
                        ], null);
                        
                        // Extend timeout for AI processing
                        devLog($db, 'debug', 'AI_sales', 'Before set_time_limit', [], null);
                        @set_time_limit(60);
                        devLog($db, 'debug', 'AI_sales', 'After set_time_limit', [], null);
                        
                        $startTime = microtime(true);
                        devLog($db, 'debug', 'AI_sales', 'Calling generateResponse...', [
                            'message_length' => mb_strlen($messageToProcess)
                        ], null);
                        
                        $response = null;
                        try {
                            $response = $gemini->generateResponse($messageToProcess, $userId, $history);
                            devLog($db, 'debug', 'AI_sales', 'generateResponse returned', [
                                'response_type' => gettype($response),
                                'response_null' => $response === null ? 'yes' : 'no'
                            ], null);
                        } catch (Exception $e) {
                            devLog($db, 'error', 'AI_sales', 'generateResponse exception: ' . $e->getMessage(), [
                                'trace' => mb_substr($e->getTraceAsString(), 0, 500)
                            ], null);
                        } catch (Throwable $t) {
                            devLog($db, 'error', 'AI_sales', 'generateResponse throwable: ' . $t->getMessage(), [
                                'trace' => mb_substr($t->getTraceAsString(), 0, 500)
                            ], null);
                        }
                        
                        $elapsed = round((microtime(true) - $startTime) * 1000);
                        
                        devLog($db, 'debug', 'AI_sales', 'GeminiChat response received', [
                            'elapsed_ms' => $elapsed,
                            'response_null' => $response === null ? 'yes' : 'no',
                            'response_length' => $response ? mb_strlen($response) : 0
                        ], null);
                        
                        if ($response) {
                            // à¹ƒà¸Šà¹‰ sender à¸ˆà¸²à¸ ai_settings
                            $sender = getAISenderSettings($db, $lineAccountId, $currentAIMode);
                            
                            $message = [
                                'type' => 'text',
                                'text' => $response,
                                'sender' => $sender
                            ];
                            
                            devLog($db, 'debug', 'AI_sales', 'AI response generated (Sales Mode)', [
                                'user_id' => $userId,
                                'response_length' => mb_strlen($response)
                            ], null);
                            
                            return [$message];
                        } else {
                            devLog($db, 'warning', 'AI_sales', 'GeminiChat returned null response', [
                                'user_id' => $userId,
                                'message' => mb_substr($messageToProcess, 0, 50)
                            ], null);
                            // Sales mode à¹à¸•à¹ˆ GeminiChat return null â†’ return null à¹„à¸¡à¹ˆ fallthrough à¹„à¸› PharmacyAI
                            return null;
                        }
                    } else {
                        devLog($db, 'warning', 'AI_sales', 'GeminiChat not enabled', [
                            'line_account_id' => $lineAccountId
                        ], null);
                        // Sales mode à¹à¸•à¹ˆ GeminiChat not enabled â†’ return null à¹„à¸¡à¹ˆ fallthrough à¹„à¸› PharmacyAI
                        return null;
                    }
                }
                
                // ===== à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ Pharmacist Mode â†’ à¹ƒà¸Šà¹‰ PharmacyAI Adapter =====
                // à¹€à¸‚à¹‰à¸²à¹€à¸‰à¸žà¸²à¸°à¹€à¸¡à¸·à¹ˆà¸­ currentAIMode à¹€à¸›à¹‡à¸™ pharmacist/pharmacy à¹€à¸—à¹ˆà¸²à¸™à¸±à¹‰à¸™
                $usePharmacyAI = in_array($currentAIMode, ['pharmacist', 'pharmacy']) 
                                 && file_exists(__DIR__ . '/modules/AIChat/Adapters/PharmacyAIAdapter.php');
                
                devLog($db, 'debug', 'AI_pharmacy_check', 'PharmacyAI condition', [
                    'currentAIMode' => $currentAIMode,
                    'usePharmacyAI' => $usePharmacyAI ? 'yes' : 'no',
                    'file_exists' => file_exists(__DIR__ . '/modules/AIChat/Adapters/PharmacyAIAdapter.php') ? 'yes' : 'no'
                ], null);
                
                if ($usePharmacyAI && $userId) {
                    try {
                        require_once __DIR__ . '/modules/AIChat/Adapters/PharmacyAIAdapter.php';
                        
                        $adapter = new \Modules\AIChat\Adapters\PharmacyAIAdapter($db, $lineAccountId);
                        $adapter->setUserId($userId);
                        
                        // Log isEnabled status
                        devLog($db, 'debug', 'AI_pharmacy', 'PharmacyAI isEnabled check', [
                            'user_id' => $userId,
                            'line_account_id' => $lineAccountId,
                            'is_enabled' => $adapter->isEnabled() ? 'yes' : 'no'
                        ], null);
                        
                        if (!$adapter->isEnabled()) {
                            devLog($db, 'warning', 'AI_pharmacy', 'PharmacyAI not enabled - no API key', [
                                'line_account_id' => $lineAccountId
                            ], null);
                            // Fallback to other methods
                        } else {
                            // Log for debugging
                            devLog($db, 'debug', 'AI_pharmacy', 'Processing AI request (PharmacyAI v5)', [
                                'user_id' => $userId,
                                'line_account_id' => $lineAccountId,
                                'message' => mb_substr($messageToProcess, 0, 50),
                                'command_mode' => $commandMode
                            ], null);
                            
                            // à¹ƒà¸Šà¹‰ PharmacyAI Adapter
                            $result = $adapter->processMessage($messageToProcess);
                            
                            if ($result['success'] && !empty($result['message'])) {
                                devLog($db, 'debug', 'AI_pharmacy', 'AI response generated (PharmacyAI v5)', [
                                    'user_id' => $userId,
                                    'response_length' => mb_strlen($result['response'] ?? ''),
                                    'state' => $result['state'] ?? 'unknown',
                                    'is_critical' => $result['is_critical'] ?? false,
                                    'has_products' => !empty($result['products'])
                                ], null);
                                
                                // à¸£à¸­à¸‡à¸£à¸±à¸š multiple messages (text + product carousel)
                                $messages = $result['messages'] ?? $result['message'];
                                
                                // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ single message à¹ƒà¸«à¹‰ wrap à¹€à¸›à¹‡à¸™ array
                                if (isset($messages['type'])) {
                                    return [$messages];
                                }
                                
                                // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ array à¸‚à¸­à¸‡ messages à¹à¸¥à¹‰à¸§ return à¸•à¸£à¸‡à¹†
                                return $messages;
                            }
                            
                            return null;
                        }
                    } catch (Exception $e) {
                        devLog($db, 'warning', 'AI_pharmacy', 'PharmacyAI error, fallback: ' . $e->getMessage(), [
                            'user_id' => $userId
                        ], null);
                    }
                }
                
                // ===== Fallback: à¸¥à¸­à¸‡à¹ƒà¸Šà¹‰ GeminiChatAdapter (à¹€à¸‰à¸žà¸²à¸° pharmacist mode) =====
                // à¸–à¹‰à¸²à¹€à¸›à¹‡à¸™ sales mode à¹„à¸¡à¹ˆà¸•à¹‰à¸­à¸‡ fallback à¹€à¸žà¸£à¸²à¸° GeminiChat à¸„à¸§à¸£à¸—à¸³à¸‡à¸²à¸™à¹à¸¥à¹‰à¸§
                $useNewModule = ($currentAIMode !== 'sales') && file_exists(__DIR__ . '/modules/AIChat/Autoloader.php');
                
                if ($useNewModule) {
                    try {
                        require_once __DIR__ . '/modules/AIChat/Adapters/GeminiChatAdapter.php';
                        
                        $adapter = new \Modules\AIChat\Adapters\GeminiChatAdapter($db, $lineAccountId);
                        
                        if (!$adapter->isEnabled()) {
                            return null;
                        }
                        
                        // Log for debugging
                        devLog($db, 'debug', 'AI_chatbot_v2', 'Processing AI request (Module v2)', [
                            'user_id' => $userId,
                            'line_account_id' => $lineAccountId,
                            'message' => mb_substr($text, 0, 50)
                        ], null);
                        
                        // à¹ƒà¸Šà¹‰ method à¹ƒà¸«à¸¡à¹ˆà¸—à¸µà¹ˆ return message object à¸žà¸£à¹‰à¸­à¸¡à¹ƒà¸Šà¹‰
                        $result = $adapter->generateResponseWithMessage($text, $userId);
                        
                        if ($result['success'] && !empty($result['message'])) {
                            devLog($db, 'debug', 'AI_chatbot_v2', 'AI response generated (Module v2)', [
                                'user_id' => $userId,
                                'response_length' => mb_strlen($result['response'])
                            ], null);
                            
                            return [$result['message']];
                        }
                        
                        return null;
                        
                    } catch (Exception $e) {
                        // à¸–à¹‰à¸² Module à¹ƒà¸«à¸¡à¹ˆ error à¹ƒà¸«à¹‰ fallback à¹„à¸›à¹ƒà¸Šà¹‰à¸£à¸°à¸šà¸šà¹€à¸à¹ˆà¸²
                        devLog($db, 'warning', 'AI_chatbot_v2', 'Module v2 error, fallback to v1: ' . $e->getMessage(), [
                            'user_id' => $userId
                        ], null);
                    }
                }
                
                // ===== Fallback: à¹ƒà¸Šà¹‰ GeminiChat à¹€à¸à¹ˆà¸² =====
                if (file_exists(__DIR__ . '/classes/GeminiChat.php')) {
                    require_once __DIR__ . '/classes/GeminiChat.php';
                    
                    $gemini = new GeminiChat($db, $lineAccountId);
                    
                    if (!$gemini->isEnabled()) {
                        return null;
                    }
                    
                    // Get conversation history for context
                    $history = $userId ? $gemini->getConversationHistory($userId, 10) : [];
                    
                    // Log for debugging
                    devLog($db, 'debug', 'AI_chatbot', 'Processing AI request (Legacy)', [
                        'user_id' => $userId,
                        'line_account_id' => $lineAccountId,
                        'message' => mb_substr($text, 0, 50),
                        'history_count' => count($history)
                    ], null);
                    
                    // Generate response with full history
                    $response = $gemini->generateResponse($text, $userId, $history);
                    
                    if ($response) {
                        // Build message with sender and quick reply from settings
                        $message = ['type' => 'text', 'text' => $response];
                        
                        // Get AI settings for sender and quick reply
                        try {
                            $stmtAI = $db->prepare("SELECT sender_name, sender_icon, quick_reply_buttons FROM ai_chat_settings WHERE line_account_id = ?");
                            $stmtAI->execute([$lineAccountId]);
                            $aiSettings = $stmtAI->fetch(PDO::FETCH_ASSOC);
                            
                            // Add Sender if configured
                            if ($aiSettings && !empty($aiSettings['sender_name'])) {
                                $message['sender'] = ['name' => $aiSettings['sender_name']];
                                if (!empty($aiSettings['sender_icon'])) {
                                    $message['sender']['iconUrl'] = $aiSettings['sender_icon'];
                                }
                            }
                            
                            // Add Quick Reply if configured
                            if ($aiSettings && !empty($aiSettings['quick_reply_buttons'])) {
                                $qrButtons = json_decode($aiSettings['quick_reply_buttons'], true);
                                if ($qrButtons && is_array($qrButtons) && count($qrButtons) > 0) {
                                    $quickReplyItems = [];
                                    foreach ($qrButtons as $btn) {
                                        if (!empty($btn['label']) && !empty($btn['text'])) {
                                            $quickReplyItems[] = [
                                                'type' => 'action',
                                                'action' => [
                                                    'type' => 'message',
                                                    'label' => $btn['label'],
                                                    'text' => $btn['text']
                                                ]
                                            ];
                                        }
                                    }
                                    if (count($quickReplyItems) > 0) {
                                        $message['quickReply'] = ['items' => array_slice($quickReplyItems, 0, 13)];
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Ignore errors, just send without sender/quick reply
                        }
                        
                        return [$message];
                    }
                }
                
                // Fallback to old method if GeminiChat not available
                $stmt = $db->prepare("SELECT * FROM ai_settings WHERE id = 1");
                $stmt->execute();
                $settings = $stmt->fetch();

                if (!$settings || !$settings['is_enabled']) return null;

                // Try OpenAI if available
                if (class_exists('OpenAI')) {
                    $openai = new OpenAI();
                    $result = $openai->chat(
                        $text,
                        $settings['system_prompt'],
                        $settings['model'],
                        $settings['max_tokens'],
                        $settings['temperature']
                    );
                    return $result['success'] ? $result['message'] : null;
                }
                
                return null;
                
            } catch (Exception $e) {
                error_log("checkAIChatbot error: " . $e->getMessage());
                devLog($db, 'error', 'AI_chatbot', $e->getMessage(), [
                    'user_id' => $userId,
                    'line_account_id' => $lineAccountId
                ], null);
                return null;
            }
        }

        /**
         * Save outgoing message
         * @param PDO $db Database connection
         * @param int $userId User ID
         * @param mixed $content Message content
         * @param string $sentBy Who sent the message: 'ai', 'admin', 'system', 'webhook'
         * @param string $messageType Message type: 'text', 'flex', 'image', etc.
         */
        function saveOutgoingMessage($db, $userId, $content, $sentBy = 'system', $messageType = 'text') {
            try {
                // Check if sent_by column exists
                $hasSentBy = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                    $hasSentBy = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                $contentStr = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content;
                
                if ($hasSentBy) {
                    $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, sent_by) VALUES (?, 'outgoing', ?, ?, ?)");
                    $stmt->execute([$userId, $messageType, $contentStr, $sentBy]);
                } else {
                    $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content) VALUES (?, 'outgoing', ?, ?)");
                    $stmt->execute([$userId, $messageType, $contentStr]);
                }
            } catch (Exception $e) {
                error_log("saveOutgoingMessage error: " . $e->getMessage());
            }
        }

        /**
         * Log analytics event
         */
        function logAnalytics($db, $eventType, $data, $lineAccountId = null) {
            try {
                // Check if line_account_id column exists
                $stmt = $db->query("SHOW COLUMNS FROM analytics LIKE 'line_account_id'");
                if ($stmt->rowCount() > 0) {
                    $stmt = $db->prepare("INSERT INTO analytics (line_account_id, event_type, event_data) VALUES (?, ?, ?)");
                    $stmt->execute([$lineAccountId, $eventType, json_encode($data)]);
                } else {
                    $stmt = $db->prepare("INSERT INTO analytics (event_type, event_data) VALUES (?, ?)");
                    $stmt->execute([$eventType, json_encode($data)]);
                }
            } catch (Exception $e) {
                // Fallback
                $stmt = $db->prepare("INSERT INTO analytics (event_type, event_data) VALUES (?, ?)");
                $stmt->execute([$eventType, json_encode($data)]);
            }
        }
        
        /**
         * Developer Log - à¸šà¸±à¸™à¸—à¸¶à¸ log à¸ªà¸³à¸«à¸£à¸±à¸š debug
         * @param PDO $db Database connection
         * @param string $type Log type: error, warning, info, debug, webhook
         * @param string $source Source of log (e.g., 'webhook', 'BusinessBot', 'LineAPI')
         * @param string $message Log message
         * @param array|null $data Additional data
         * @param string|null $userId LINE user ID (optional)
         */
        function devLog($db, $type, $source, $message, $data = null, $userId = null) {
            try {
                $stmt = $db->prepare("INSERT INTO dev_logs (log_type, source, message, data, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $type,
                    $source,
                    $message,
                    $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
                    $userId
                ]);
            } catch (Exception $e) {
                // Table might not exist - log to error_log instead
                error_log("[{$type}] [{$source}] {$message} " . ($data ? json_encode($data) : ''));
            }
        }
        
        /**
         * Get AI Sender Settings from ai_settings table
         * @param PDO $db Database connection
         * @param int|null $lineAccountId LINE Account ID
         * @param string|null $overrideMode Override AI mode (optional)
         * @return array ['name' => string, 'iconUrl' => string]
         */
        function getAISenderSettings($db, $lineAccountId = null, $overrideMode = null) {
            $defaultSender = [
                'name' => 'ðŸ¤– AI Assistant',
                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
            ];
            
            try {
                $stmt = $db->prepare("SELECT sender_name, sender_icon, ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$lineAccountId]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($settings) {
                    $mode = $overrideMode ?? $settings['ai_mode'] ?? 'sales';
                    
                    // à¹ƒà¸Šà¹‰ sender_name à¸ˆà¸²à¸ settings à¸–à¹‰à¸²à¸¡à¸µ
                    if (!empty($settings['sender_name'])) {
                        $defaultSender['name'] = $settings['sender_name'];
                    } else {
                        // Default sender name à¸•à¸²à¸¡ ai_mode
                        switch ($mode) {
                            case 'pharmacist':
                            case 'pharmacy':
                                $defaultSender['name'] = 'ðŸ’Š à¹€à¸ à¸ªà¸±à¸Šà¸à¸£ AI';
                                break;
                            case 'mims':
                                $defaultSender['name'] = 'ðŸ“š MIMS Pharmacist AI';
                                break;
                            case 'triage':
                                $defaultSender['name'] = 'ðŸ©º à¸‹à¸±à¸à¸›à¸£à¸°à¸§à¸±à¸•à¸´ AI';
                                break;
                            case 'support':
                                $defaultSender['name'] = 'ðŸ’¬ à¸‹à¸±à¸žà¸žà¸­à¸£à¹Œà¸• AI';
                                break;
                            case 'sales':
                            default:
                                $defaultSender['name'] = 'ðŸ›’ à¸žà¸™à¸±à¸à¸‡à¸²à¸™à¸‚à¸²à¸¢ AI';
                                break;
                        }
                    }
                    
                    // à¹ƒà¸Šà¹‰ sender_icon à¸ˆà¸²à¸ settings à¸–à¹‰à¸²à¸¡à¸µ
                    if (!empty($settings['sender_icon'])) {
                        $defaultSender['iconUrl'] = $settings['sender_icon'];
                    }
                }
            } catch (Exception $e) {
                // Use default
            }
            
            return $defaultSender;
        }
        
        /**
         * Get account name by ID
         */
        function getAccountName($db, $lineAccountId) {
            if (!$lineAccountId) return null;
            try {
                $stmt = $db->prepare("SELECT name FROM line_accounts WHERE id = ?");
                $stmt->execute([$lineAccountId]);
                return $stmt->fetchColumn() ?: null;
            } catch (Exception $e) {
                return null;
            }
        }
        
        /**
         * à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¸¢à¸´à¸™à¸¢à¸­à¸¡ PDPA à¹à¸¥à¹‰à¸§à¸«à¸£à¸·à¸­à¸¢à¸±à¸‡
         * - à¸–à¹‰à¸²à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¹€à¸„à¸¢ consent à¸à¸±à¸šà¸šà¸­à¸—à¹ƒà¸”à¸šà¸­à¸—à¸«à¸™à¸¶à¹ˆà¸‡à¹à¸¥à¹‰à¸§ à¸–à¸·à¸­à¸§à¹ˆà¸² consent à¹à¸¥à¹‰à¸§ (à¹ƒà¸Šà¹‰à¹„à¸”à¹‰à¸à¸±à¸šà¸—à¸¸à¸à¸šà¸­à¸—)
         * - à¹€à¸Šà¹‡à¸„à¸ˆà¸²à¸ line_user_id à¹à¸—à¸™ user_id à¹€à¸žà¸·à¹ˆà¸­à¹ƒà¸«à¹‰ consent à¹ƒà¸Šà¹‰à¹„à¸”à¹‰à¸‚à¹‰à¸²à¸¡à¸šà¸­à¸—
         */
        function checkUserConsent($db, $userId, $lineUserId = null) {
            try {
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µ column consent_privacy à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
                $hasConsentCols = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_privacy'");
                    $hasConsentCols = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                // à¸–à¹‰à¸²à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ columns à¹ƒà¸«à¹‰à¸œà¹ˆà¸²à¸™à¹„à¸›à¸à¹ˆà¸­à¸™ (à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¹„à¸”à¹‰ run migration)
                if (!$hasConsentCols) {
                    return true;
                }
                
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µ column consent_at à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
                $hasConsentAt = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_at'");
                    $hasConsentAt = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                // à¸–à¹‰à¸²à¸¡à¸µ lineUserId à¹ƒà¸«à¹‰à¹€à¸Šà¹‡à¸„à¸ˆà¸²à¸ line_user_id (à¸‚à¹‰à¸²à¸¡à¸šà¸­à¸—à¹„à¸”à¹‰)
                if ($lineUserId) {
                    // à¹€à¸Šà¹‡à¸„à¸§à¹ˆà¸²à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¸„à¸™à¸™à¸µà¹‰à¹€à¸„à¸¢ consent à¸à¸±à¸šà¸šà¸­à¸—à¹ƒà¸”à¸šà¸­à¸—à¸«à¸™à¸¶à¹ˆà¸‡à¹à¸¥à¹‰à¸§à¸«à¸£à¸·à¸­à¸¢à¸±à¸‡
                    $stmt = $db->prepare("SELECT id, consent_privacy, consent_terms FROM users WHERE line_user_id = ? AND consent_privacy = 1 AND consent_terms = 1 LIMIT 1");
                    $stmt->execute([$lineUserId]);
                    $consentedUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($consentedUser) {
                        // à¸–à¹‰à¸²à¹€à¸„à¸¢ consent à¹à¸¥à¹‰à¸§ à¹ƒà¸«à¹‰ copy consent à¹„à¸›à¸¢à¸±à¸‡ user record à¸›à¸±à¸ˆà¸ˆà¸¸à¸šà¸±à¸™ (à¸–à¹‰à¸²à¸•à¹ˆà¸²à¸‡ id)
                        if ($consentedUser['id'] != $userId) {
                            try {
                                if ($hasConsentAt) {
                                    $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1, consent_at = NOW() WHERE id = ?");
                                } else {
                                    $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1 WHERE id = ?");
                                }
                                $stmt->execute([$userId]);
                            } catch (Exception $e) {
                                // Ignore error, consent check still passes
                            }
                        }
                        return true;
                    }
                }
                
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸ˆà¸²à¸ users table à¸•à¸²à¸¡ user_id
                $stmt = $db->prepare("SELECT consent_privacy, consent_terms FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['consent_privacy'] && $user['consent_terms']) {
                    return true;
                }
                
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸ˆà¸²à¸ user_consents table
                try {
                    // à¹€à¸Šà¹‡à¸„à¸ˆà¸²à¸ line_user_id à¸à¹ˆà¸­à¸™ (à¸‚à¹‰à¸²à¸¡à¸šà¸­à¸—à¹„à¸”à¹‰)
                    if ($lineUserId) {
                        $stmt = $db->prepare("
                            SELECT uc.consent_type, uc.is_accepted 
                            FROM user_consents uc
                            JOIN users u ON uc.user_id = u.id
                            WHERE u.line_user_id = ? AND uc.consent_type IN ('privacy_policy', 'terms_of_service') AND uc.is_accepted = 1
                        ");
                        $stmt->execute([$lineUserId]);
                    } else {
                        $stmt = $db->prepare("
                            SELECT consent_type, is_accepted 
                            FROM user_consents 
                            WHERE user_id = ? AND consent_type IN ('privacy_policy', 'terms_of_service')
                        ");
                        $stmt->execute([$userId]);
                    }
                    $consents = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    $hasPrivacy = !empty($consents['privacy_policy']);
                    $hasTerms = !empty($consents['terms_of_service']);
                    
                    if ($hasPrivacy && $hasTerms) {
                        // Copy consent à¹„à¸›à¸¢à¸±à¸‡ user record à¸›à¸±à¸ˆà¸ˆà¸¸à¸šà¸±à¸™
                        try {
                            if ($hasConsentAt) {
                                $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1, consent_at = NOW() WHERE id = ?");
                            } else {
                                $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1 WHERE id = ?");
                            }
                            $stmt->execute([$userId]);
                        } catch (Exception $e) {}
                        return true;
                    }
                    
                    return false;
                } catch (Exception $e) {
                    // à¸–à¹‰à¸² user_consents table à¹„à¸¡à¹ˆà¸¡à¸µ à¹ƒà¸«à¹‰à¸”à¸¹à¸ˆà¸²à¸ users table à¸­à¸¢à¹ˆà¸²à¸‡à¹€à¸”à¸µà¸¢à¸§
                    return false;
                }
                
            } catch (Exception $e) {
                // à¸–à¹‰à¸² error à¹ƒà¸«à¹‰à¸œà¹ˆà¸²à¸™à¹„à¸›à¸à¹ˆà¸­à¸™ (à¹„à¸¡à¹ˆ block user)
                return true;
            }
        }
        
        /**
         * Get or Create User - à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¹à¸¥à¸°à¸šà¸±à¸™à¸—à¸¶à¸à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¹€à¸ªà¸¡à¸­ (à¹„à¸¡à¹ˆà¸§à¹ˆà¸²à¸ˆà¸°à¸¡à¸²à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡à¸«à¸£à¸·à¸­à¹à¸Šà¸—à¸ªà¹ˆà¸§à¸™à¸•à¸±à¸§)
         */
        function getOrCreateUser($db, $line, $userId, $lineAccountId = null, $groupId = null) {
            // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µà¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¸­à¸¢à¸¹à¹ˆà¹à¸¥à¹‰à¸§à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
            $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // à¸–à¹‰à¸²à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ à¹ƒà¸«à¹‰à¸ªà¸£à¹‰à¸²à¸‡à¹ƒà¸«à¸¡à¹ˆ
            if (!$user) {
                // à¸”à¸¶à¸‡à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¹‚à¸›à¸£à¹„à¸Ÿà¸¥à¹Œà¸ˆà¸²à¸ LINE
                $profile = null;
                try {
                    if ($groupId) {
                        // à¸–à¹‰à¸²à¸¡à¸²à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡ à¹ƒà¸Šà¹‰ getGroupMemberProfile
                        $profile = $line->getGroupMemberProfile($groupId, $userId);
                    } else {
                        // à¸–à¹‰à¸²à¸¡à¸²à¸ˆà¸²à¸à¹à¸Šà¸—à¸ªà¹ˆà¸§à¸™à¸•à¸±à¸§ à¹ƒà¸Šà¹‰ getProfile
                        $profile = $line->getProfile($userId);
                    }
                } catch (Exception $e) {
                    error_log("getOrCreateUser profile error: " . $e->getMessage());
                }
                
                $displayName = $profile['displayName'] ?? 'Unknown';
                $pictureUrl = $profile['pictureUrl'] ?? '';
                $statusMessage = $profile['statusMessage'] ?? '';
                
                // à¸šà¸±à¸™à¸—à¸¶à¸à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¹ƒà¸«à¸¡à¹ˆ
                try {
                    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
                    if ($stmt->rowCount() > 0) {
                        $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name, picture_url, status_message) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$lineAccountId, $userId, $displayName, $pictureUrl, $statusMessage]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO users (line_user_id, display_name, picture_url, status_message) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$userId, $displayName, $pictureUrl, $statusMessage]);
                    }
                    
                    $user = [
                        'id' => $db->lastInsertId(),
                        'display_name' => $displayName,
                        'picture_url' => $pictureUrl,
                        'line_account_id' => $lineAccountId
                    ];
                    
                    // à¸šà¸±à¸™à¸—à¸¶à¸à¹€à¸›à¹‡à¸™ follower à¸”à¹‰à¸§à¸¢ (à¸–à¹‰à¸²à¸¡à¸µ lineAccountId)
                    if ($lineAccountId) {
                        saveAccountFollower($db, $lineAccountId, $userId, $user['id'], $profile, true);
                    }
                    
                } catch (Exception $e) {
                    error_log("getOrCreateUser insert error: " . $e->getMessage());
                    // à¸¥à¸­à¸‡à¸”à¸¶à¸‡à¸­à¸µà¸à¸„à¸£à¸±à¹‰à¸‡ (à¸­à¸²à¸ˆà¸¡à¸µ race condition)
                    $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } else {
                // à¸–à¹‰à¸²à¸¡à¸µà¸­à¸¢à¸¹à¹ˆà¹à¸¥à¹‰à¸§ à¹à¸•à¹ˆà¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ line_account_id à¹ƒà¸«à¹‰à¸­à¸±à¸žà¹€à¸”à¸—
                if ($lineAccountId && empty($user['line_account_id'])) {
                    try {
                        $stmt = $db->prepare("UPDATE users SET line_account_id = ? WHERE id = ? AND (line_account_id IS NULL OR line_account_id = 0)");
                        $stmt->execute([$lineAccountId, $user['id']]);
                        $user['line_account_id'] = $lineAccountId;
                    } catch (Exception $e) {}
                }
            }
            
            return $user;
        }
        
        /**
         * Save account follower - à¸šà¸±à¸™à¸—à¸¶à¸à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ follower à¹à¸¢à¸à¸•à¸²à¸¡à¸šà¸­à¸—
         */
        function saveAccountFollower($db, $lineAccountId, $lineUserId, $dbUserId, $profile, $isFollow) {
            try {
                if ($isFollow) {
                    // Follow event
                    $stmt = $db->prepare("
                        INSERT INTO account_followers 
                        (line_account_id, line_user_id, user_id, display_name, picture_url, status_message, is_following, followed_at, follow_count) 
                        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), 1)
                        ON DUPLICATE KEY UPDATE 
                            display_name = VALUES(display_name),
                            picture_url = VALUES(picture_url),
                            status_message = VALUES(status_message),
                            is_following = 1,
                            followed_at = IF(is_following = 0, NOW(), followed_at),
                            follow_count = follow_count + IF(is_following = 0, 1, 0),
                            unfollowed_at = NULL,
                            updated_at = NOW()
                    ");
                    $stmt->execute([
                        $lineAccountId,
                        $lineUserId,
                        $dbUserId,
                        $profile['displayName'] ?? '',
                        $profile['pictureUrl'] ?? '',
                        $profile['statusMessage'] ?? ''
                    ]);
                } else {
                    // Unfollow event
                    $stmt = $db->prepare("
                        UPDATE account_followers 
                        SET is_following = 0, unfollowed_at = NOW(), updated_at = NOW()
                        WHERE line_account_id = ? AND line_user_id = ?
                    ");
                    $stmt->execute([$lineAccountId, $lineUserId]);
                }
            } catch (Exception $e) {
                error_log("saveAccountFollower error: " . $e->getMessage());
            }
        }
        
        /**
         * Save account event - à¸šà¸±à¸™à¸—à¸¶à¸ event à¹à¸¢à¸à¸•à¸²à¸¡à¸šà¸­à¸—
         */
        function saveAccountEvent($db, $lineAccountId, $eventType, $lineUserId, $dbUserId, $event) {
            // Skip if no line_user_id (required field)
            if (empty($lineUserId)) {
                return;
            }
            
            try {
                $webhookEventId = $event['webhookEventId'] ?? null;
                $timestamp = $event['timestamp'] ?? null;
                $replyToken = $event['replyToken'] ?? null;
                $sourceType = $event['source']['type'] ?? 'user';
                $sourceId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
                
                $stmt = $db->prepare("
                    INSERT INTO account_events 
                    (line_account_id, event_type, line_user_id, user_id, event_data, webhook_event_id, source_type, source_id, reply_token, timestamp) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $lineAccountId,
                    $eventType,
                    $lineUserId,
                    $dbUserId,
                    json_encode($event),
                    $webhookEventId,
                    $sourceType,
                    $sourceId,
                    $replyToken,
                    $timestamp
                ]);
            } catch (Exception $e) {
                error_log("saveAccountEvent error: " . $e->getMessage());
            }
        }
        
        /**
         * Update account daily stats - à¸­à¸±à¸žà¹€à¸”à¸—à¸ªà¸–à¸´à¸•à¸´à¸£à¸²à¸¢à¸§à¸±à¸™
         */
        function updateAccountDailyStats($db, $lineAccountId, $field) {
            try {
                $today = date('Y-m-d');
                $validFields = ['new_followers', 'unfollowers', 'total_messages', 'incoming_messages', 'outgoing_messages', 'unique_users'];
                if (!in_array($field, $validFields)) return;
                
                $stmt = $db->prepare("
                    INSERT INTO account_daily_stats (line_account_id, stat_date, {$field}) 
                    VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE {$field} = {$field} + 1, updated_at = NOW()
                ");
                $stmt->execute([$lineAccountId, $today]);
            } catch (Exception $e) {
                error_log("updateAccountDailyStats error: " . $e->getMessage());
            }
        }
        
        /**
         * Update follower interaction - à¸­à¸±à¸žà¹€à¸”à¸—à¸‚à¹‰à¸­à¸¡à¸¹à¸¥ interaction à¸‚à¸­à¸‡ follower
         */
        function updateFollowerInteraction($db, $lineAccountId, $lineUserId) {
            try {
                $stmt = $db->prepare("
                    UPDATE account_followers 
                    SET last_interaction_at = NOW(), total_messages = total_messages + 1, updated_at = NOW()
                    WHERE line_account_id = ? AND line_user_id = ?
                ");
                $stmt->execute([$lineAccountId, $lineUserId]);
            } catch (Exception $e) {
                // Ignore
            }
        }

        /**
         * Send Telegram notification
         */
        function sendTelegramNotification($db, $type, $displayName, $message = '', $lineUserId = '', $dbUserId = null, $accountName = null) {
            $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
            $stmt->execute();
            $settings = $stmt->fetch();

            if (!$settings || !$settings['is_enabled']) return;

            $telegram = new TelegramAPI();
            
            // à¹€à¸žà¸´à¹ˆà¸¡à¸Šà¸·à¹ˆà¸­à¸šà¸­à¸—à¹ƒà¸™à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡
            $botInfo = $accountName ? " [à¸šà¸­à¸—: {$accountName}]" : "";

            switch ($type) {
                case 'follow':
                    if ($settings['notify_new_follower']) {
                        $telegram->notifyNewFollower($displayName . $botInfo, $lineUserId);
                    }
                    break;
                case 'unfollow':
                    if ($settings['notify_unfollow']) {
                        $telegram->notifyUnfollow($displayName . $botInfo);
                    }
                    break;
                case 'message':
                    if ($settings['notify_new_message']) {
                        $telegram->notifyNewMessage($displayName . $botInfo, $message, $lineUserId, $dbUserId);
                    }
                    break;
            }
        }

        /**
         * Get user state
         */
        function getUserState($db, $userId) {
            try {
                // à¸”à¸¶à¸‡à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¹‚à¸”à¸¢à¹„à¸¡à¹ˆà¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š expires_at à¹ƒà¸™ SQL
                $stmt = $db->prepare("SELECT * FROM user_states WHERE user_id = ?");
                $stmt->execute([$userId]);
                $state = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($state) {
                    // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š expires_at à¹ƒà¸™ PHP
                    $expired = $state['expires_at'] && strtotime($state['expires_at']) < time();
                    if ($expired) {
                        // State à¸«à¸¡à¸”à¸­à¸²à¸¢à¸¸ - à¸¥à¸šà¸—à¸´à¹‰à¸‡
                        clearUserState($db, $userId);
                        return null;
                    }
                    return $state;
                }
                return null;
            } catch (Exception $e) {
                return null; // Table doesn't exist or error
            }
        }

        /**
         * Set user state
         */
        function setUserState($db, $userId, $state, $data = null, $expiresMinutes = 10) {
            try {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresMinutes} minutes"));
                
                // Check if user_states has user_id as PRIMARY KEY or separate id
                $stmt = $db->query("SHOW KEYS FROM user_states WHERE Key_name = 'PRIMARY'");
                $primaryKey = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($primaryKey && $primaryKey['Column_name'] === 'user_id') {
                    // user_id is PRIMARY KEY - use ON DUPLICATE KEY
                    $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE state = ?, state_data = ?, expires_at = ?");
                    $stmt->execute([$userId, $state, json_encode($data), $expiresAt, $state, json_encode($data), $expiresAt]);
                } else {
                    // Separate id column - delete first then insert
                    $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    
                    $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $state, json_encode($data), $expiresAt]);
                }
                
                devLog($db, 'debug', 'setUserState', 'State saved', ['user_id' => $userId, 'state' => $state, 'data' => $data]);
            } catch (Exception $e) {
                devLog($db, 'error', 'setUserState', 'Error: ' . $e->getMessage(), ['user_id' => $userId]);
            }
        }

        /**
         * Clear user state
         */
        function clearUserState($db, $userId) {
            try {
                $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
                $stmt->execute([$userId]);
            } catch (Exception $e) {
                // Table doesn't exist, ignore
            }
        }

        /**
         * Create order from pending state when customer confirms
         */
        function createOrderFromPendingState($db, $line, $dbUserId, $lineUserId, $userState, $replyToken, $lineAccountId) {
            try {
                $stateData = json_decode($userState['state_data'] ?? '{}', true);
                $items = $stateData['items'] ?? [];
                $total = (float)($stateData['total'] ?? 0);
                $subtotal = (float)($stateData['subtotal'] ?? $total);
                $discount = (float)($stateData['discount'] ?? 0);
                
                if (empty($items)) {
                    devLog($db, 'error', 'createOrderFromPendingState', 'No items in pending order', ['user_id' => $dbUserId]);
                    return false;
                }
                
                // Check if transactions table exists
                try {
                    $tableCheck = $db->query("SHOW TABLES LIKE 'transactions'")->fetch();
                    if (!$tableCheck) {
                        devLog($db, 'error', 'createOrderFromPendingState', 'transactions table does not exist', ['user_id' => $dbUserId]);
                        return false;
                    }
                } catch (Exception $e) {
                    devLog($db, 'error', 'createOrderFromPendingState', 'Error checking tables: ' . $e->getMessage(), ['user_id' => $dbUserId]);
                    return false;
                }
                
                // Generate order number
                $orderNumber = 'ORD' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                devLog($db, 'debug', 'createOrderFromPendingState', 'Creating transaction', [
                    'order_number' => $orderNumber,
                    'user_id' => $dbUserId,
                    'total' => $total,
                    'items_count' => count($items)
                ]);
                
                // Create transaction - use only basic columns that definitely exist
                try {
                    $stmt = $db->prepare("INSERT INTO transactions 
                        (line_account_id, order_number, user_id, total_amount, grand_total, status, payment_status, note) 
                        VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?)");
                    $stmt->execute([
                        $lineAccountId,
                        $orderNumber,
                        $dbUserId,
                        $total,
                        $total,
                        'à¸ªà¸£à¹‰à¸²à¸‡à¸ˆà¸²à¸à¹à¸Šà¸— - à¸¥à¸¹à¸à¸„à¹‰à¸²à¸¢à¸·à¸™à¸¢à¸±à¸™'
                    ]);
                } catch (PDOException $e) {
                    devLog($db, 'error', 'createOrderFromPendingState', 'Failed to insert transaction: ' . $e->getMessage(), [
                        'user_id' => $dbUserId,
                        'sql_error' => $e->getCode()
                    ]);
                    return false;
                }
                
                $transactionId = $db->lastInsertId();
                
                devLog($db, 'debug', 'createOrderFromPendingState', 'Transaction created', [
                    'transaction_id' => $transactionId
                ]);
                
                // Insert transaction items - check if table exists first
                try {
                    $itemTableCheck = $db->query("SHOW TABLES LIKE 'transaction_items'")->fetch();
                    if ($itemTableCheck) {
                        foreach ($items as $item) {
                            $itemSubtotal = (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
                            $stmt = $db->prepare("INSERT INTO transaction_items 
                                (transaction_id, product_id, product_name, product_price, quantity, subtotal) 
                                VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $transactionId,
                                $item['id'] ?? null,
                                $item['name'] ?? 'Unknown',
                                $item['price'] ?? 0,
                                $item['qty'] ?? 1,
                                $itemSubtotal
                            ]);
                        }
                    } else {
                        devLog($db, 'warning', 'createOrderFromPendingState', 'transaction_items table does not exist, skipping items insert', [
                            'transaction_id' => $transactionId
                        ]);
                    }
                } catch (PDOException $e) {
                    devLog($db, 'error', 'createOrderFromPendingState', 'Failed to insert transaction items: ' . $e->getMessage(), [
                        'transaction_id' => $transactionId,
                        'sql_error' => $e->getCode()
                    ]);
                    // Continue anyway - transaction was created
                }
                
                devLog($db, 'info', 'createOrderFromPendingState', 'Order created', [
                    'user_id' => $dbUserId,
                    'order_number' => $orderNumber,
                    'transaction_id' => $transactionId,
                    'total' => $total,
                    'items_count' => count($items)
                ]);
                
                // Build confirmation message
                $itemsList = '';
                foreach ($items as $i => $item) {
                    $itemTotal = ($item['price'] ?? 0) * ($item['qty'] ?? 1);
                    $itemsList .= ($i + 1) . ". {$item['name']}\n   à¸¿" . number_format($item['price'] ?? 0) . " x {$item['qty']} = à¸¿" . number_format($itemTotal) . "\n";
                }
                
                $confirmMessage = [
                    'type' => 'flex',
                    'altText' => "âœ… à¸ªà¸£à¹‰à¸²à¸‡à¸­à¸­à¹€à¸”à¸­à¸£à¹Œà¸ªà¸³à¹€à¸£à¹‡à¸ˆ #{$orderNumber}",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#10B981',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => 'âœ… à¸ªà¸£à¹‰à¸²à¸‡à¸­à¸­à¹€à¸”à¸­à¸£à¹Œà¸ªà¸³à¹€à¸£à¹‡à¸ˆ', 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center']
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => "à¹€à¸¥à¸‚à¸—à¸µà¹ˆ: #{$orderNumber}", 'size' => 'md', 'weight' => 'bold', 'color' => '#10B981'],
                                ['type' => 'separator', 'margin' => 'md'],
                                ['type' => 'text', 'text' => 'ðŸ“¦ à¸£à¸²à¸¢à¸à¸²à¸£à¸ªà¸´à¸™à¸„à¹‰à¸²', 'size' => 'sm', 'weight' => 'bold', 'margin' => 'md'],
                                ['type' => 'text', 'text' => $itemsList, 'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm'],
                                ['type' => 'separator', 'margin' => 'md'],
                                ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                                    ['type' => 'text', 'text' => 'ðŸ’° à¸£à¸§à¸¡à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸”', 'size' => 'md', 'weight' => 'bold'],
                                    ['type' => 'text', 'text' => 'à¸¿' . number_format($total), 'size' => 'lg', 'weight' => 'bold', 'color' => '#10B981', 'align' => 'end']
                                ]],
                                ['type' => 'text', 'text' => 'ðŸ“± à¸à¸£à¸¸à¸“à¸²à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™à¹à¸¥à¸°à¸ªà¹ˆà¸‡à¸ªà¸¥à¸´à¸›à¸¡à¸²à¸„à¹ˆà¸°', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'lg']
                            ]
                        ]
                    ]
                ];
                
                $line->replyMessage($replyToken, [$confirmMessage]);
                saveOutgoingMessage($db, $dbUserId, json_encode($confirmMessage), 'system', 'flex');
                
                // Set user state to waiting for slip
                setUserState($db, $dbUserId, 'waiting_slip', ['order_id' => $transactionId, 'order_number' => $orderNumber], 60);
                
                return true;
                
            } catch (Exception $e) {
                devLog($db, 'error', 'createOrderFromPendingState', 'Error: ' . $e->getMessage(), [
                    'user_id' => $dbUserId,
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Send error message
                $errorMessage = [
                    'type' => 'text',
                    'text' => "âŒ à¸‚à¸­à¸­à¸ à¸±à¸¢à¸„à¹ˆà¸° à¹€à¸à¸´à¸”à¸‚à¹‰à¸­à¸œà¸´à¸”à¸žà¸¥à¸²à¸”à¹ƒà¸™à¸à¸²à¸£à¸ªà¸£à¹‰à¸²à¸‡à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ\n\nà¸à¸£à¸¸à¸“à¸²à¸¥à¸­à¸‡à¹ƒà¸«à¸¡à¹ˆà¸­à¸µà¸à¸„à¸£à¸±à¹‰à¸‡à¸«à¸£à¸·à¸­à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸ˆà¹‰à¸²à¸«à¸™à¹‰à¸²à¸—à¸µà¹ˆà¸„à¹ˆà¸° ðŸ™"
                ];
                $line->replyMessage($replyToken, [$errorMessage]);
                
                return false;
            }
        }

        /**
         * Handle slip command - à¹€à¸¡à¸·à¹ˆà¸­à¸¥à¸¹à¸à¸„à¹‰à¸²à¸žà¸´à¸¡à¸žà¹Œ "à¸ªà¸¥à¸´à¸›"
         */
        function handleSlipCommand($db, $line, $dbUserId, $replyToken) {
            devLog($db, 'debug', 'handleSlipCommand', 'Start', ['user_id' => $dbUserId]);
            
            // Check if user has pending order - à¸¥à¸­à¸‡à¸«à¸²à¸ˆà¸²à¸ transactions à¸à¹ˆà¸­à¸™ à¹à¸¥à¹‰à¸§à¸„à¹ˆà¸­à¸¢ orders
            $order = null;
            $orderTable = 'orders';
            $itemsTable = 'order_items';
            $itemsFk = 'order_id';
            
            // Try transactions first
            try {
                $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND status IN ('pending', 'confirmed') AND payment_status = 'pending' ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$dbUserId]);
                $order = $stmt->fetch();
                devLog($db, 'debug', 'handleSlipCommand', 'Transactions query', ['user_id' => $dbUserId, 'found' => $order ? 'yes' : 'no', 'order_id' => $order['id'] ?? null]);
                if ($order) {
                    $orderTable = 'transactions';
                    $itemsTable = 'transaction_items';
                    $itemsFk = 'transaction_id';
                }
            } catch (Exception $e) {
                devLog($db, 'error', 'handleSlipCommand', 'Transactions error: ' . $e->getMessage(), ['user_id' => $dbUserId]);
            }
            
            // Fallback to orders
            if (!$order) {
                try {
                    $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? AND status IN ('pending', 'confirmed') AND payment_status = 'pending' ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$dbUserId]);
                    $order = $stmt->fetch();
                } catch (Exception $e) {}
            }
            
            if (!$order) {
                $line->replyMessage($replyToken, "âŒ à¸„à¸¸à¸“à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µà¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­à¸—à¸µà¹ˆà¸£à¸­à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™\n\nà¸žà¸´à¸¡à¸žà¹Œ 'shop' à¹€à¸žà¸·à¹ˆà¸­à¹€à¸£à¸´à¹ˆà¸¡à¸Šà¹‰à¸­à¸›à¸›à¸´à¹‰à¸‡");
                return true;
            }
            
            // Set user state to waiting for slip
            $stateData = $orderTable === 'transactions' ? ['transaction_id' => $order['id']] : ['order_id' => $order['id']];
            setUserState($db, $dbUserId, 'waiting_slip', $stateData, 10);
            
            // Get payment info & order items
            $stmt = $db->query("SELECT * FROM shop_settings WHERE id = 1");
            $settings = $stmt->fetch();
            $bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
            
            $stmt = $db->prepare("SELECT * FROM {$itemsTable} WHERE {$itemsFk} = ?");
            $stmt->execute([$order['id']]);
            $items = $stmt->fetchAll();
            
            // Build items content
            $itemsContent = [];
            foreach ($items as $item) {
                $itemsContent[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => "{$item['product_name']}  x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                        ['type' => 'text', 'text' => 'à¸¿' . number_format($item['subtotal']), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                    ]
                ];
            }
            
            // Build payment contents
            $paymentContents = [];
            if (!empty($settings['promptpay_number'])) {
                $paymentContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ðŸ’š', 'size' => 'sm', 'flex' => 0],
                        ['type' => 'text', 'text' => 'à¸žà¸£à¹‰à¸­à¸¡à¹€à¸žà¸¢à¹Œ: ' . $settings['promptpay_number'], 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
                    ]
                ];
            }
            foreach ($bankAccounts as $bank) {
                $paymentContents[] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => 'ðŸ¦', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => "{$bank['name']}: {$bank['account']}", 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
                            ]
                        ],
                        ['type' => 'text', 'text' => "   à¸Šà¸·à¹ˆà¸­: {$bank['holder']}", 'size' => 'xs', 'color' => '#888888']
                    ]
                ];
            }
            
            $orderNum = str_replace('ORD', '', $order['order_number']);
            
            // Build Flex Message
            $bubble = [
                'type' => 'bubble',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => "à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ #{$orderNum}", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => 'â³ à¸£à¸­à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold']
                            ]
                        ],
                        ['type' => 'separator', 'margin' => 'lg'],
                        ['type' => 'text', 'text' => 'à¸£à¸²à¸¢à¸à¸²à¸£à¸ªà¸´à¸™à¸„à¹‰à¸²', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $itemsContent
                        ],
                        ['type' => 'separator', 'margin' => 'lg'],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'margin' => 'lg',
                            'contents' => [
                                ['type' => 'text', 'text' => 'à¸¢à¸­à¸”à¸£à¸§à¸¡à¸—à¸±à¹‰à¸‡à¸«à¸¡à¸”', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
                                ['type' => 'text', 'text' => 'à¸¿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                            ]
                        ],
                        ['type' => 'separator', 'margin' => 'lg'],
                        ['type' => 'text', 'text' => 'ðŸ“Œ à¸Šà¹ˆà¸­à¸‡à¸—à¸²à¸‡à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™:', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $paymentContents
                        ],
                        ['type' => 'text', 'text' => 'ðŸ“¸ à¸à¸£à¸¸à¸“à¸²à¸ªà¹ˆà¸‡à¸£à¸¹à¸›à¸ªà¸¥à¸´à¸›à¸¡à¸²à¹€à¸¥à¸¢', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold', 'margin' => 'lg', 'wrap' => true],
                        ['type' => 'text', 'text' => '(à¸ à¸²à¸¢à¹ƒà¸™ 10 à¸™à¸²à¸—à¸µ)', 'size' => 'xs', 'color' => '#888888']
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'uri', 'label' => 'ðŸ“ž à¸•à¸´à¸”à¸•à¹ˆà¸­à¹€à¸£à¸²', 'uri' => 'tel:' . ($settings['contact_phone'] ?? '0000000000')], 'style' => 'link']
                    ]
                ]
            ];
            
            $line->replyMessage($replyToken, [
                ['type' => 'flex', 'altText' => "à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ #{$orderNum} - à¸£à¸­à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™", 'contents' => $bubble]
            ]);
            return true;
        }

        /**
         * Handle payment slip for specific order
         */
        function handlePaymentSlipForOrder($db, $line, $dbUserId, $messageId, $replyToken, $orderId) {
            // Get order - à¸¥à¸­à¸‡à¸«à¸²à¸ˆà¸²à¸à¸—à¸±à¹‰à¸‡ orders à¹à¸¥à¸° transactions
            $order = null;
            $orderTable = 'orders';
            
            // à¸¥à¸­à¸‡à¸«à¸²à¸ˆà¸²à¸ orders à¸à¹ˆà¸­à¸™
            try {
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
                $stmt->execute([$orderId, $dbUserId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            
            // à¸–à¹‰à¸²à¹„à¸¡à¹ˆà¹€à¸ˆà¸­ à¸¥à¸­à¸‡à¸«à¸²à¸ˆà¸²à¸ transactions
            if (!$order) {
                try {
                    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                    $stmt->execute([$orderId, $dbUserId]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($order) {
                        $orderTable = 'transactions';
                    }
                } catch (Exception $e) {}
            }
            
            if (!$order) {
                $line->replyMessage($replyToken, "âŒ à¹„à¸¡à¹ˆà¸žà¸šà¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­ à¸à¸£à¸¸à¸“à¸²à¸¥à¸­à¸‡à¹ƒà¸«à¸¡à¹ˆ");
                return true;
            }
            
            // Download image from LINE and save
            $imageData = $line->getMessageContent($messageId);
            if (!$imageData || strlen($imageData) < 100) {
                $line->replyMessage($replyToken, "âŒ à¹„à¸¡à¹ˆà¸ªà¸²à¸¡à¸²à¸£à¸–à¸£à¸±à¸šà¸£à¸¹à¸›à¸ à¸²à¸žà¹„à¸”à¹‰ à¸à¸£à¸¸à¸“à¸²à¸ªà¹ˆà¸‡à¹ƒà¸«à¸¡à¹ˆà¸­à¸µà¸à¸„à¸£à¸±à¹‰à¸‡");
                return true;
            }
            
            // Save image
            $uploadDir = __DIR__ . '/uploads/slips/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    $line->replyMessage($replyToken, "âŒ à¸£à¸°à¸šà¸šà¸¡à¸µà¸›à¸±à¸à¸«à¸² à¹„à¸¡à¹ˆà¸ªà¸²à¸¡à¸²à¸£à¸–à¸šà¸±à¸™à¸—à¸¶à¸à¸£à¸¹à¸›à¹„à¸”à¹‰ à¸à¸£à¸¸à¸“à¸²à¸•à¸´à¸”à¸•à¹ˆà¸­à¹à¸­à¸”à¸¡à¸´à¸™");
                    return true;
                }
            }
            
            // Check if directory is writable
            if (!is_writable($uploadDir)) {
                $line->replyMessage($replyToken, "âŒ à¸£à¸°à¸šà¸šà¸¡à¸µà¸›à¸±à¸à¸«à¸² (permission) à¸à¸£à¸¸à¸“à¸²à¸•à¸´à¸”à¸•à¹ˆà¸­à¹à¸­à¸”à¸¡à¸´à¸™");
                return true;
            }
            
            $filename = 'slip_' . $order['order_number'] . '_' . time() . '.jpg';
            $filepath = $uploadDir . $filename;
            
            $bytesWritten = file_put_contents($filepath, $imageData);
            if ($bytesWritten === false || $bytesWritten < 100) {
                $line->replyMessage($replyToken, "âŒ à¹„à¸¡à¹ˆà¸ªà¸²à¸¡à¸²à¸£à¸–à¸šà¸±à¸™à¸—à¸¶à¸à¸£à¸¹à¸›à¹„à¸”à¹‰ à¸à¸£à¸¸à¸“à¸²à¸ªà¹ˆà¸‡à¹ƒà¸«à¸¡à¹ˆ");
                return true;
            }
            
            // Get base URL from config or construct it
            $baseUrl = defined('BASE_URL') ? BASE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/slips/' . $filename;
            
            // Save payment slip record - use transaction_id (unified with LIFF)
            try {
                $stmt = $db->prepare("INSERT INTO payment_slips (transaction_id, user_id, image_url, status) VALUES (?, ?, ?, 'pending')");
                $stmt->execute([$order['id'], $dbUserId, $imageUrl]);
            } catch (Exception $e) {
                devLog($db, 'error', 'handlePaymentSlip', 'Cannot save slip: ' . $e->getMessage());
            }
            
            // Update order status to 'paid' (pending admin verification)
            try {
                $stmt = $db->prepare("UPDATE {$orderTable} SET status = 'paid', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$order['id']]);
                devLog($db, 'info', 'handlePaymentSlip', 'Order status updated to paid', ['order_id' => $order['id'], 'table' => $orderTable]);
            } catch (Exception $e) {
                devLog($db, 'error', 'handlePaymentSlip', 'Cannot update order status: ' . $e->getMessage());
            }
            
            // Reply to customer with beautiful Flex Message
            $orderNum = str_replace(['ORD', 'TXN'], '', $order['order_number']);
            $slipBubble = FlexTemplates::slipReceived($orderNum, $order['grand_total']);
            $slipMessage = FlexTemplates::toMessage($slipBubble, "à¹„à¸”à¹‰à¸£à¸±à¸šà¸ªà¸¥à¸´à¸›à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ #{$orderNum} à¹à¸¥à¹‰à¸§");
            $slipMessage = FlexTemplates::withQuickReply($slipMessage, [
                ['label' => 'ðŸ“¦ à¹€à¸Šà¹‡à¸„à¸ªà¸–à¸²à¸™à¸°', 'text' => 'orders'],
                ['label' => 'ðŸ›’ à¸Šà¹‰à¸­à¸›à¸•à¹ˆà¸­', 'text' => 'shop']
            ]);
            $line->replyMessage($replyToken, [$slipMessage]);
            
            // Notify admin via Telegram
            notifyAdminNewSlip($db, $line, $order, $dbUserId, $imageData, $baseUrl);
            
            return true;
        }

        /**
         * Notify admin about new payment slip
         */
        function notifyAdminNewSlip($db, $line, $order, $dbUserId, $imageData, $baseUrl) {
            $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
            $stmt->execute();
            $telegramSettings = $stmt->fetch();
            
            if (!$telegramSettings || !$telegramSettings['is_enabled']) return;
            
            $telegram = new TelegramAPI();
            
            $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$dbUserId]);
            $user = $stmt->fetch();
            
            $caption = "ðŸ’³ <b>à¸ªà¸¥à¸´à¸›à¸à¸²à¸£à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™!</b>\n\n";
            $caption .= "ðŸ“‹ à¸­à¸­à¹€à¸”à¸­à¸£à¹Œ: #{$order['order_number']}\n";
            $caption .= "ðŸ‘¤ à¸¥à¸¹à¸à¸„à¹‰à¸²: {$user['display_name']}\n";
            $caption .= "ðŸ’° à¸¢à¸­à¸”: à¸¿" . number_format($order['grand_total'], 2) . "\n";
            $caption .= "ðŸ“… à¹€à¸§à¸¥à¸²: " . date('d/m/Y H:i') . "\n\n";
            $caption .= "ðŸ”— <a href=\"{$baseUrl}/shop/order-detail.php?id={$order['id']}\">à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸š</a>";
            
            $telegram->sendPhoto($imageData, $caption, $dbUserId);
        }

        /**
         * Handle payment slip - à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¹à¸¥à¸°à¸šà¸±à¸™à¸—à¸¶à¸à¸ªà¸¥à¸´à¸›à¸à¸²à¸£à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™ (legacy - use transactions)
         */
        function handlePaymentSlip($db, $line, $dbUserId, $messageId, $replyToken) {
            // Check if user has pending/confirmed order waiting for payment (use transactions table)
            $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND status IN ('pending', 'confirmed') AND payment_status = 'pending' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$dbUserId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                return false; // No pending order, not a payment slip
            }
            
            // Download image from LINE and save
            $imageData = $line->getMessageContent($messageId);
            if (!$imageData) {
                return false;
            }
            
            // Save image to uploads folder
            $uploadDir = __DIR__ . '/uploads/slips/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $filename = 'slip_' . $order['order_number'] . '_' . time() . '.jpg';
            $filepath = $uploadDir . $filename;
            file_put_contents($filepath, $imageData);
            
            // Get base URL for image - use BASE_URL from config
            $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $imageUrl = $baseUrl . '/uploads/slips/' . $filename;
            
            // Save payment slip record (use transaction_id - unified with LIFF)
            $stmt = $db->prepare("INSERT INTO payment_slips (transaction_id, user_id, image_url, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$order['id'], $dbUserId, $imageUrl]);
            
            // Reply to customer
            $line->replyMessage($replyToken, "âœ… à¹„à¸”à¹‰à¸£à¸±à¸šà¸«à¸¥à¸±à¸à¸à¸²à¸™à¸à¸²à¸£à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™à¹à¸¥à¹‰à¸§!\n\nðŸ“‹ à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­: #{$order['order_number']}\nðŸ’° à¸¢à¸­à¸”: à¸¿" . number_format($order['grand_total'], 2) . "\n\nâ³ à¸à¸£à¸¸à¸“à¸²à¸£à¸­à¸à¸²à¸£à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸ˆà¸²à¸à¸—à¸²à¸‡à¸£à¹‰à¸²à¸™\nà¸ˆà¸°à¹à¸ˆà¹‰à¸‡à¸œà¸¥à¹ƒà¸«à¹‰à¸—à¸£à¸²à¸šà¹€à¸£à¹‡à¸§à¹† à¸™à¸µà¹‰");
            
            // Notify admin via Telegram
            $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
            $stmt->execute();
            $telegramSettings = $stmt->fetch();
            
            if ($telegramSettings && $telegramSettings['is_enabled']) {
                $telegram = new TelegramAPI();
                
                // Get customer name
                $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
                $stmt->execute([$dbUserId]);
                $user = $stmt->fetch();
                
                $caption = "ðŸ’³ <b>à¸ªà¸¥à¸´à¸›à¸à¸²à¸£à¸Šà¸³à¸£à¸°à¹€à¸‡à¸´à¸™!</b>\n\n";
                $caption .= "ðŸ“‹ à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸‹à¸·à¹‰à¸­: #{$order['order_number']}\n";
                $caption .= "ðŸ‘¤ à¸¥à¸¹à¸à¸„à¹‰à¸²: {$user['display_name']}\n";
                $caption .= "ðŸ’° à¸¢à¸­à¸”: à¸¿" . number_format($order['grand_total'], 2) . "\n";
                $caption .= "ðŸ“… à¹€à¸§à¸¥à¸²: " . date('Y-m-d H:i:s') . "\n\n";
                $caption .= "ðŸ”— <a href=\"{$baseUrl}/shop/order-detail.php?id={$order['id']}\">à¸”à¸¹à¸£à¸²à¸¢à¸¥à¸°à¹€à¸­à¸µà¸¢à¸”</a>";
                
                // Send slip image to Telegram
                $telegram->sendPhoto($imageData, $caption, $dbUserId);
            }
            
            return true; // Slip handled
        }

        /**
         * Send Telegram notification with media support
         */
        function sendTelegramNotificationWithMedia($db, $line, $displayName, $messageType, $messageContent, $messageId, $dbUserId, $messageData) {
            $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
            $stmt->execute();
            $settings = $stmt->fetch();

            if (!$settings || !$settings['is_enabled'] || !$settings['notify_new_message']) return;

            $telegram = new TelegramAPI();

            // For text messages, use normal notification
            if ($messageType === 'text') {
                $telegram->notifyNewMessage($displayName, $messageContent, '', $dbUserId);
                return;
            }

            // For media messages
            $caption = "ðŸ’¬ <b>à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹ƒà¸«à¸¡à¹ˆ!</b>\n\n";
            $caption .= "ðŸ‘¤ à¸ˆà¸²à¸: {$displayName}\n";
            $caption .= "ðŸ“… à¹€à¸§à¸¥à¸²: " . date('Y-m-d H:i:s') . "\n";
            $caption .= "\nðŸ’¡ <i>à¸•à¸­à¸šà¸à¸¥à¸±à¸š:</i> <code>/r {$dbUserId} à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡</code>";

            if ($messageType === 'image') {
                // Get image content from LINE
                $imageData = $line->getMessageContent($messageId);
                if ($imageData) {
                    $telegram->sendPhoto($imageData, $caption, $dbUserId);
                } else {
                    $telegram->notifyNewMessage($displayName, "[à¸£à¸¹à¸›à¸ à¸²à¸ž] à¹„à¸¡à¹ˆà¸ªà¸²à¸¡à¸²à¸£à¸–à¹‚à¸«à¸¥à¸”à¹„à¸”à¹‰", '', $dbUserId);
                }
            } elseif ($messageType === 'video') {
                $telegram->notifyNewMessage($displayName, "[à¸§à¸´à¸”à¸µà¹‚à¸­] ID: {$messageId}", '', $dbUserId);
            } elseif ($messageType === 'audio') {
                $telegram->notifyNewMessage($displayName, "[à¹€à¸ªà¸µà¸¢à¸‡] ID: {$messageId}", '', $dbUserId);
            } elseif ($messageType === 'sticker') {
                $stickerId = $messageData['stickerId'] ?? '';
                $packageId = $messageData['packageId'] ?? '';
                // LINE sticker URL
                $stickerUrl = "https://stickershop.line-scdn.net/stickershop/v1/sticker/{$stickerId}/iPhone/sticker.png";
                $telegram->sendPhotoUrl($stickerUrl, "ðŸŽ¨ <b>à¸ªà¸•à¸´à¸à¹€à¸à¸­à¸£à¹Œ</b>\n\nðŸ‘¤ à¸ˆà¸²à¸: {$displayName}\n\nðŸ’¡ <code>/r {$dbUserId} à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡</code>", $dbUserId);
            } elseif ($messageType === 'location') {
                $lat = $messageData['latitude'] ?? 0;
                $lng = $messageData['longitude'] ?? 0;
                $address = $messageData['address'] ?? '';
                $telegram->sendLocation($lat, $lng, "ðŸ“ <b>à¸•à¸³à¹à¸«à¸™à¹ˆà¸‡</b>\n\nðŸ‘¤ à¸ˆà¸²à¸: {$displayName}\nðŸ“ {$address}\n\nðŸ’¡ <code>/r {$dbUserId} à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡</code>", $dbUserId);
            } else {
                $telegram->notifyNewMessage($displayName, "[{$messageType}]", '', $dbUserId);
            }
        }

        /**
         * Ensure group exists in database - à¸ªà¸£à¹‰à¸²à¸‡à¸à¸¥à¸¸à¹ˆà¸¡à¸­à¸±à¸•à¹‚à¸™à¸¡à¸±à¸•à¸´à¸–à¹‰à¸²à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ
         * à¹ƒà¸Šà¹‰à¹€à¸¡à¸·à¹ˆà¸­à¹„à¸”à¹‰à¸£à¸±à¸š event à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡à¸—à¸µà¹ˆà¸šà¸­à¸—à¸­à¸¢à¸¹à¹ˆà¹à¸¥à¹‰à¸§à¹à¸•à¹ˆà¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µà¹ƒà¸™à¸£à¸°à¸šà¸š
         */
        function ensureGroupExists($db, $line, $lineAccountId, $groupId, $sourceType = 'group') {
            if (!$lineAccountId || !$groupId) return;
            
            try {
                // à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸²à¸¡à¸µà¸à¸¥à¸¸à¹ˆà¸¡à¸™à¸µà¹‰à¹ƒà¸™à¸£à¸°à¸šà¸šà¸«à¸£à¸·à¸­à¸¢à¸±à¸‡
                $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                
                if ($stmt->fetch()) {
                    return; // à¸¡à¸µà¸­à¸¢à¸¹à¹ˆà¹à¸¥à¹‰à¸§ à¹„à¸¡à¹ˆà¸•à¹‰à¸­à¸‡à¸—à¸³à¸­à¸°à¹„à¸£
                }
                
                // à¸¢à¸±à¸‡à¹„à¸¡à¹ˆà¸¡à¸µ - à¸”à¸¶à¸‡à¸‚à¹‰à¸­à¸¡à¸¹à¸¥à¸à¸¥à¸¸à¹ˆà¸¡à¸ˆà¸²à¸ LINE API
                $groupInfo = [];
                try {
                    if ($sourceType === 'group') {
                        $groupInfo = $line->getGroupSummary($groupId);
                    }
                } catch (Exception $e) {
                    // API à¸­à¸²à¸ˆ fail à¸–à¹‰à¸²à¸šà¸­à¸—à¹„à¸¡à¹ˆà¸¡à¸µà¸ªà¸´à¸—à¸˜à¸´à¹Œ
                }
                
                $groupName = $groupInfo['groupName'] ?? 'Unknown Group';
                $pictureUrl = $groupInfo['pictureUrl'] ?? null;
                $memberCount = $groupInfo['memberCount'] ?? 0;
                
                // à¸šà¸±à¸™à¸—à¸¶à¸à¸à¸¥à¸¸à¹ˆà¸¡à¹ƒà¸«à¸¡à¹ˆ
                $stmt = $db->prepare("
                    INSERT INTO line_groups (line_account_id, group_id, group_type, group_name, picture_url, member_count, is_active, joined_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                        is_active = 1,
                        updated_at = NOW()
                ");
                $stmt->execute([$lineAccountId, $groupId, $sourceType, $groupName, $pictureUrl, $memberCount]);
                
                // Log
                devLog($db, 'info', 'webhook', 'Auto-created group from event', [
                    'group_id' => $groupId,
                    'group_name' => $groupName,
                    'line_account_id' => $lineAccountId
                ]);
                
            } catch (Exception $e) {
                // Ignore errors - à¹„à¸¡à¹ˆà¹ƒà¸«à¹‰à¸à¸£à¸°à¸—à¸š flow à¸«à¸¥à¸±à¸
            }
        }

        /**
         * Handle bot join group/room event
         */
        function handleJoinGroup($event, $db, $line, $lineAccountId) {
            if (!$lineAccountId) return;
            
            $sourceType = $event['source']['type'] ?? 'group';
            $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
            
            if (!$groupId) return;
            
            try {
                // Get group info from LINE API
                $groupInfo = [];
                if ($sourceType === 'group') {
                    $groupInfo = $line->getGroupSummary($groupId);
                }
                
                $groupName = $groupInfo['groupName'] ?? 'Unknown Group';
                $pictureUrl = $groupInfo['pictureUrl'] ?? null;
                $memberCount = $groupInfo['memberCount'] ?? 0;
                
                // Save to database
                $stmt = $db->prepare("
                    INSERT INTO line_groups (line_account_id, group_id, group_type, group_name, picture_url, member_count, is_active, joined_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                        group_name = VALUES(group_name),
                        picture_url = VALUES(picture_url),
                        member_count = VALUES(member_count),
                        is_active = 1,
                        joined_at = NOW(),
                        left_at = NULL,
                        updated_at = NOW()
                ");
                $stmt->execute([$lineAccountId, $groupId, $sourceType, $groupName, $pictureUrl, $memberCount]);
                
                // Log event (skip saveAccountEvent - no line_user_id for join events)
                
                // à¹„à¸¡à¹ˆà¸ªà¹ˆà¸‡à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¹€à¸‚à¹‰à¸²à¸à¸¥à¸¸à¹ˆà¸¡à¹€à¸žà¸·à¹ˆà¸­à¸›à¸£à¸°à¸«à¸¢à¸±à¸” quota
                // (à¸–à¹‰à¸²à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¸ªà¹ˆà¸‡ à¸ªà¸²à¸¡à¸²à¸£à¸–à¹€à¸›à¸´à¸” comment à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡à¹„à¸”à¹‰)
                // $botName = getAccountName($db, $lineAccountId) ?: 'Bot';
                // $welcomeBubble = FlexTemplates::groupWelcome($groupName, $botName);
                // $welcomeMessage = FlexTemplates::toMessage($welcomeBubble, "à¸ªà¸§à¸±à¸ªà¸”à¸µà¸ˆà¸²à¸ {$botName}!");
                // $line->pushMessage($groupId, [$welcomeMessage]);
                
                // Notify via Telegram
                notifyGroupEvent($db, 'join', $groupName, $lineAccountId);
                
            } catch (Exception $e) {
                error_log("handleJoinGroup error: " . $e->getMessage());
            }
        }
        
        /**
         * Handle bot leave group/room event
         */
        function handleLeaveGroup($event, $db, $lineAccountId) {
            if (!$lineAccountId) return;
            
            $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
            if (!$groupId) return;
            
            try {
                // Get group name before updating
                $stmt = $db->prepare("SELECT group_name FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                $group = $stmt->fetch();
                $groupName = $group['group_name'] ?? 'Unknown Group';
                
                // Update database
                $stmt = $db->prepare("
                    UPDATE line_groups 
                    SET is_active = 0, left_at = NOW(), updated_at = NOW()
                    WHERE line_account_id = ? AND group_id = ?
                ");
                $stmt->execute([$lineAccountId, $groupId]);
                
                // Log event (skip saveAccountEvent - no line_user_id for leave events)
                
                // Notify via Telegram
                notifyGroupEvent($db, 'leave', $groupName, $lineAccountId);
                
            } catch (Exception $e) {
                error_log("handleLeaveGroup error: " . $e->getMessage());
            }
        }
        
        /**
         * Handle member joined group event
         */
        function handleMemberJoined($event, $groupId, $db, $line, $lineAccountId) {
            try {
                // Get group DB ID
                $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                $dbGroupId = $stmt->fetchColumn();
                
                if (!$dbGroupId) return;
                
                $members = $event['joined']['members'] ?? [];
                foreach ($members as $member) {
                    $userId = $member['userId'] ?? null;
                    if (!$userId) continue;
                    
                    // Get member profile
                    $profile = $line->getGroupMemberProfile($groupId, $userId);
                    $displayName = $profile['displayName'] ?? 'Unknown';
                    $pictureUrl = $profile['pictureUrl'] ?? null;
                    
                    // Save member
                    $stmt = $db->prepare("
                        INSERT INTO line_group_members (group_id, line_user_id, display_name, picture_url, is_active, joined_at)
                        VALUES (?, ?, ?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE 
                            display_name = VALUES(display_name),
                            picture_url = VALUES(picture_url),
                            is_active = 1,
                            joined_at = NOW(),
                            left_at = NULL,
                            updated_at = NOW()
                    ");
                    $stmt->execute([$dbGroupId, $userId, $displayName, $pictureUrl]);
                }
                
                // Update member count
                $stmt = $db->prepare("UPDATE line_groups SET member_count = member_count + ? WHERE id = ?");
                $stmt->execute([count($members), $dbGroupId]);
                
                // à¹„à¸¡à¹ˆà¸ªà¹ˆà¸‡à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡à¸•à¹‰à¸­à¸™à¸£à¸±à¸šà¸ªà¸¡à¸²à¸Šà¸´à¸à¹ƒà¸«à¸¡à¹ˆà¹€à¸žà¸·à¹ˆà¸­à¸›à¸£à¸°à¸«à¸¢à¸±à¸” quota
                // (à¸–à¹‰à¸²à¸•à¹‰à¸­à¸‡à¸à¸²à¸£à¸ªà¹ˆà¸‡ à¸ªà¸²à¸¡à¸²à¸£à¸–à¹€à¸›à¸´à¸” comment à¸”à¹‰à¸²à¸™à¸¥à¹ˆà¸²à¸‡à¹„à¸”à¹‰)
                /*
                if (count($members) > 0) {
                    $names = [];
                    foreach ($members as $member) {
                        $userId = $member['userId'] ?? null;
                        if ($userId) {
                            $profile = $line->getGroupMemberProfile($groupId, $userId);
                            $names[] = $profile['displayName'] ?? 'à¸ªà¸¡à¸²à¸Šà¸´à¸à¹ƒà¸«à¸¡à¹ˆ';
                        }
                    }
                    $nameList = implode(', ', array_slice($names, 0, 3));
                    if (count($names) > 3) $nameList .= ' à¹à¸¥à¸°à¸­à¸µà¸ ' . (count($names) - 3) . ' à¸„à¸™';
                    
                    $welcomeText = "ðŸŽ‰ à¸¢à¸´à¸™à¸”à¸µà¸•à¹‰à¸­à¸™à¸£à¸±à¸š {$nameList} à¹€à¸‚à¹‰à¸²à¸ªà¸¹à¹ˆà¸à¸¥à¸¸à¹ˆà¸¡!\n\nðŸ’¡ à¸žà¸´à¸¡à¸žà¹Œ 'menu' à¹€à¸žà¸·à¹ˆà¸­à¸”à¸¹à¸„à¸³à¸ªà¸±à¹ˆà¸‡à¸—à¸µà¹ˆà¹ƒà¸Šà¹‰à¹„à¸”à¹‰";
                    $line->pushMessage($groupId, $welcomeText);
                }
                */
                
            } catch (Exception $e) {
                error_log("handleMemberJoined error: " . $e->getMessage());
            }
        }
        
        /**
         * Handle member left group event
         */
        function handleMemberLeft($event, $groupId, $db, $lineAccountId) {
            try {
                // Get group DB ID
                $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                $dbGroupId = $stmt->fetchColumn();
                
                if (!$dbGroupId) return;
                
                $members = $event['left']['members'] ?? [];
                foreach ($members as $member) {
                    $userId = $member['userId'] ?? null;
                    if (!$userId) continue;
                    
                    // Update member
                    $stmt = $db->prepare("
                        UPDATE line_group_members 
                        SET is_active = 0, left_at = NOW(), updated_at = NOW()
                        WHERE group_id = ? AND line_user_id = ?
                    ");
                    $stmt->execute([$dbGroupId, $userId]);
                }
                
                // Update member count
                $stmt = $db->prepare("UPDATE line_groups SET member_count = GREATEST(0, member_count - ?) WHERE id = ?");
                $stmt->execute([count($members), $dbGroupId]);
                
            } catch (Exception $e) {
                error_log("handleMemberLeft error: " . $e->getMessage());
            }
        }
        
        /**
         * Save group message
         */
        function saveGroupMessage($db, $lineAccountId, $groupId, $userId, $event) {
            try {
                // Get group DB ID
                $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                $dbGroupId = $stmt->fetchColumn();
                
                if (!$dbGroupId) return;
                
                $messageType = $event['message']['type'] ?? 'text';
                $content = $event['message']['text'] ?? "[{$messageType}]";
                $messageId = $event['message']['id'] ?? null;
                
                // Save message
                $stmt = $db->prepare("
                    INSERT INTO line_group_messages (group_id, line_user_id, message_type, content, message_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$dbGroupId, $userId, $messageType, $content, $messageId]);
                
                // Update group stats
                $stmt = $db->prepare("UPDATE line_groups SET total_messages = total_messages + 1, last_activity_at = NOW() WHERE id = ?");
                $stmt->execute([$dbGroupId]);
                
                // Update member stats
                $stmt = $db->prepare("
                    UPDATE line_group_members 
                    SET total_messages = total_messages + 1, last_message_at = NOW()
                    WHERE group_id = ? AND line_user_id = ?
                ");
                $stmt->execute([$dbGroupId, $userId]);
                
            } catch (Exception $e) {
                error_log("saveGroupMessage error: " . $e->getMessage());
            }
        }
        
        /**
         * Update group stats - à¸­à¸±à¸žà¹€à¸”à¸—à¸ªà¸–à¸´à¸•à¸´à¸à¸¥à¸¸à¹ˆà¸¡
         */
        function updateGroupStats($db, $lineAccountId, $groupId, $eventType) {
            try {
                // Get group DB ID
                $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                $dbGroupId = $stmt->fetchColumn();
                
                if (!$dbGroupId) return;
                
                // Update based on event type
                if ($eventType === 'message') {
                    $stmt = $db->prepare("UPDATE line_groups SET total_messages = total_messages + 1, last_activity_at = NOW(), updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$dbGroupId]);
                } else {
                    // Update last activity for other events
                    $stmt = $db->prepare("UPDATE line_groups SET last_activity_at = NOW(), updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$dbGroupId]);
                }
            } catch (Exception $e) {
                error_log("updateGroupStats error: " . $e->getMessage());
            }
        }
        
        /**
         * Notify group event via Telegram
         */
        function notifyGroupEvent($db, $type, $groupName, $lineAccountId) {
            try {
                $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
                $stmt->execute();
                $settings = $stmt->fetch();
                
                if (!$settings || !$settings['is_enabled']) return;
                
                $telegram = new TelegramAPI();
                $accountName = getAccountName($db, $lineAccountId);
                $botInfo = $accountName ? " [à¸šà¸­à¸—: {$accountName}]" : "";
                
                if ($type === 'join') {
                    $message = "ðŸŽ‰ <b>à¸šà¸­à¸—à¸–à¸¹à¸à¹€à¸Šà¸´à¸à¹€à¸‚à¹‰à¸²à¸à¸¥à¸¸à¹ˆà¸¡!</b>\n\n";
                    $message .= "ðŸ‘¥ à¸à¸¥à¸¸à¹ˆà¸¡: {$groupName}\n";
                    $message .= "ðŸ¤– {$botInfo}\n";
                    $message .= "ðŸ“… à¹€à¸§à¸¥à¸²: " . date('d/m/Y H:i:s');
                } else {
                    $message = "ðŸ‘‹ <b>à¸šà¸­à¸—à¸­à¸­à¸à¸ˆà¸²à¸à¸à¸¥à¸¸à¹ˆà¸¡</b>\n\n";
                    $message .= "ðŸ‘¥ à¸à¸¥à¸¸à¹ˆà¸¡: {$groupName}\n";
                    $message .= "ðŸ¤– {$botInfo}\n";
                    $message .= "ðŸ“… à¹€à¸§à¸¥à¸²: " . date('d/m/Y H:i:s');
                }
                
                $telegram->sendMessage($message);
                
            } catch (Exception $e) {
                error_log("notifyGroupEvent error: " . $e->getMessage());
            }
        }

        // ==================== AI Pause/Resume Functions ====================
        
        /**
         * à¸•à¸£à¸§à¸ˆà¸ªà¸­à¸šà¸§à¹ˆà¸² AI à¸–à¸¹à¸ pause à¸ªà¸³à¸«à¸£à¸±à¸š user à¸™à¸µà¹‰à¸«à¸£à¸·à¸­à¹„à¸¡à¹ˆ
         */
        function isAIPaused($db, $userId) {
            try {
                $stmt = $db->prepare("SELECT pause_until FROM ai_user_pause WHERE user_id = ? AND pause_until > NOW()");
                $stmt->execute([$userId]);
                return $stmt->fetch() !== false;
            } catch (Exception $e) {
                // Table might not exist - create it
                try {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS ai_user_pause (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            pause_until DATETIME NOT NULL,
                            reason VARCHAR(255) DEFAULT 'human_request',
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY unique_user (user_id),
                            INDEX idx_pause_until (pause_until)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                } catch (Exception $e2) {}
                return false;
            }
        }
        
        /**
         * Pause AI à¸ªà¸³à¸«à¸£à¸±à¸š user (à¸«à¸™à¹ˆà¸§à¸¢à¹€à¸›à¹‡à¸™à¸™à¸²à¸—à¸µ)
         */
        function pauseAI($db, $userId, $minutes = 20) {
            try {
                // Create table if not exists
                $db->exec("
                    CREATE TABLE IF NOT EXISTS ai_user_pause (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        pause_until DATETIME NOT NULL,
                        reason VARCHAR(255) DEFAULT 'human_request',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_user (user_id),
                        INDEX idx_pause_until (pause_until)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                
                $pauseUntil = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
                
                $stmt = $db->prepare("
                    INSERT INTO ai_user_pause (user_id, pause_until, reason) VALUES (?, ?, 'human_request')
                    ON DUPLICATE KEY UPDATE pause_until = ?, reason = 'human_request'
                ");
                $stmt->execute([$userId, $pauseUntil, $pauseUntil]);
                
                return true;
            } catch (Exception $e) {
                error_log("pauseAI error: " . $e->getMessage());
                return false;
            }
        }
        
        /**
         * Resume AI à¸ªà¸³à¸«à¸£à¸±à¸š user (à¸¢à¸à¹€à¸¥à¸´à¸ pause)
         */
        function resumeAI($db, $userId) {
            try {
                $stmt = $db->prepare("DELETE FROM ai_user_pause WHERE user_id = ?");
                $stmt->execute([$userId]);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }
        
        // ==================== AI Mode Functions ====================
        
        /**
         * à¸”à¸¶à¸‡ AI mode à¸›à¸±à¸ˆà¸ˆà¸¸à¸šà¸±à¸™à¸‚à¸­à¸‡ user
         */
        function getUserAIMode($db, $userId) {
            try {
                $stmt = $db->prepare("SELECT ai_mode FROM ai_user_mode WHERE user_id = ? AND expires_at > NOW()");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ? $row['ai_mode'] : null;
            } catch (Exception $e) {
                // Table might not exist - create it
                try {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS ai_user_mode (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            ai_mode VARCHAR(50) NOT NULL,
                            expires_at DATETIME NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY unique_user (user_id),
                            INDEX idx_expires (expires_at)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                } catch (Exception $e2) {}
                return null;
            }
        }
        
        /**
         * à¸•à¸±à¹‰à¸‡ AI mode à¸ªà¸³à¸«à¸£à¸±à¸š user (à¸«à¸¡à¸”à¸­à¸²à¸¢à¸¸à¹ƒà¸™ 10 à¸™à¸²à¸—à¸µ)
         */
        function setUserAIMode($db, $userId, $mode, $minutes = 10) {
            try {
                // Create table if not exists
                $db->exec("
                    CREATE TABLE IF NOT EXISTS ai_user_mode (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        ai_mode VARCHAR(50) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_user (user_id),
                        INDEX idx_expires (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
                
                $stmt = $db->prepare("
                    INSERT INTO ai_user_mode (user_id, ai_mode, expires_at) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE ai_mode = ?, expires_at = ?
                ");
                $stmt->execute([$userId, $mode, $expiresAt, $mode, $expiresAt]);
                
                return true;
            } catch (Exception $e) {
                error_log("setUserAIMode error: " . $e->getMessage());
                return false;
            }
        }
        
        /**
         * à¸¥à¸š AI mode à¸‚à¸­à¸‡ user (à¸­à¸­à¸à¸ˆà¸²à¸à¹‚à¸«à¸¡à¸”)
         */
        function clearUserAIMode($db, $userId) {
            try {
                $stmt = $db->prepare("DELETE FROM ai_user_mode WHERE user_id = ?");
                $stmt->execute([$userId]);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }
        
        /**
         * à¹à¸ˆà¹‰à¸‡à¹€à¸•à¸·à¸­à¸™à¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¹€à¸¡à¸·à¹ˆà¸­à¸¥à¸¹à¸à¸„à¹‰à¸²à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¸„à¸™à¸ˆà¸£à¸´à¸‡
         */
        function notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $message) {
            try {
                // Get user info
                $stmt = $db->prepare("SELECT display_name, line_user_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $displayName = $user['display_name'] ?? 'Unknown';
                $lineUserId = $user['line_user_id'] ?? '';
                
                // 1. à¸šà¸±à¸™à¸—à¸¶à¸à¸¥à¸‡ pharmacist_queue (à¸–à¹‰à¸²à¸¡à¸µ table)
                try {
                    $stmt = $db->prepare("
                        INSERT INTO pharmacist_queue (user_id, line_account_id, request_type, message, status, created_at)
                        VALUES (?, ?, 'human_request', ?, 'pending', NOW())
                    ");
                    $stmt->execute([$userId, $lineAccountId, $message]);
                } catch (Exception $e) {
                    // Table might not exist
                }
                
                // 2. à¹à¸ˆà¹‰à¸‡à¹€à¸•à¸·à¸­à¸™à¸œà¹ˆà¸²à¸™ Telegram
                $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
                $stmt->execute();
                $telegramSettings = $stmt->fetch();
                
                if ($telegramSettings && $telegramSettings['is_enabled']) {
                    $telegram = new TelegramAPI();
                    $accountName = getAccountName($db, $lineAccountId);
                    
                    $text = "ðŸš¨ <b>à¸¥à¸¹à¸à¸„à¹‰à¸²à¸‚à¸­à¸„à¸¸à¸¢à¸à¸±à¸šà¹€à¸ à¸ªà¸±à¸Šà¸à¸£à¸ˆà¸£à¸´à¸‡!</b>\n\n";
                    $text .= "ðŸ‘¤ à¸¥à¸¹à¸à¸„à¹‰à¸²: {$displayName}\n";
                    $text .= "ðŸ’¬ à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡: {$message}\n";
                    if ($accountName) $text .= "ðŸ¤– à¸šà¸­à¸—: {$accountName}\n";
                    $text .= "ðŸ“… à¹€à¸§à¸¥à¸²: " . date('d/m/Y H:i:s') . "\n\n";
                    $text .= "â° à¸šà¸­à¸—à¸ˆà¸°à¸«à¸¢à¸¸à¸”à¸•à¸­à¸š 20 à¸™à¸²à¸—à¸µ\n";
                    $text .= "ðŸ’¡ à¸•à¸­à¸šà¸à¸¥à¸±à¸š: <code>/r {$userId} à¸‚à¹‰à¸­à¸„à¸§à¸²à¸¡</code>";
                    
                    $telegram->sendMessage($text);
                }
                
                // 3. Log event
                devLog($db, 'info', 'human_request', 'Customer requested human pharmacist', [
                    'user_id' => $userId,
                    'display_name' => $displayName,
                    'message' => $message,
                    'line_account_id' => $lineAccountId
                ], $lineUserId);
                
            } catch (Exception $e) {
                error_log("notifyPharmacistForHumanRequest error: " . $e->getMessage());
            }
        }