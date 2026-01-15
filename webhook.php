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

// Multi-account support: ตรวจสอบว่ามาจาก account ไหน
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
     * แสดง Loading Animation ใน LINE Chat
     * @param LineAPI $line - LINE API instance
     * @param string $chatId - User ID หรือ Group ID
     * @param int $seconds - จำนวนวินาที (5-60)
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
            
            // Handle join/leave events (ไม่ต้องมี userId)
            if ($event['type'] === 'join') {
                handleJoinGroup($event, $db, $line, $lineAccountId);
                continue;
            }
            if ($event['type'] === 'leave') {
                handleLeaveGroup($event, $db, $lineAccountId);
                continue;
            }
            
            // สำหรับ event จากกลุ่ม - ตรวจสอบและสร้างกลุ่มอัตโนมัติถ้ายังไม่มี
            if (($sourceType === 'group' || $sourceType === 'room') && $groupId && $lineAccountId) {
                // ตรวจสอบและสร้างกลุ่มอัตโนมัติ
                ensureGroupExists($db, $line, $lineAccountId, $groupId, $sourceType);
                
                if ($userId) {
                    // บันทึกผู้ใช้จากกลุ่ม
                    $groupUser = getOrCreateUser($db, $line, $userId, $lineAccountId, $groupId);
                    $dbUserId = $groupUser['id'] ?? null;
                    
                    // บันทึก event พร้อม source_id (groupId)
                    saveAccountEvent($db, $lineAccountId, $event['type'], $userId, $dbUserId, $event);
                    
                    // อัพเดทสถิติกลุ่ม
                    updateGroupStats($db, $lineAccountId, $groupId, $event['type']);
                }
                // Skip saveAccountEvent if no userId (bot events from group)
            }
            
            if (!$userId) continue;
            
            // Deduplication: ป้องกันการประมวลผล event ซ้ำ
            $webhookEventId = $event['webhookEventId'] ?? null;
            $messageText = $event['message']['text'] ?? '';
            
            // Log ทุก event ที่เข้ามา
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
                        continue; // Event นี้ถูกประมวลผลแล้ว
                    }
                    // บันทึก event ID
                    $stmt = $db->prepare("INSERT INTO webhook_events (event_id) VALUES (?)");
                    $stmt->execute([$webhookEventId]);
                } catch (Exception $e) {
                    // Table doesn't exist or duplicate key - ignore and continue
                }
            }

            switch ($event['type']) {
                case 'follow':
                    // Follow event มี replyToken - ใช้ reply แทน push เพื่อประหยัด quota
                    handleFollow($userId, $replyToken, $db, $line, $lineAccountId, $event);
                    break;
                case 'unfollow':
                    handleUnfollow($userId, $db, $lineAccountId, $event);
                    break;
                case 'message':
                    handleMessage($event, $userId, $replyToken, $db, $line, $lineAccountId);
                    break;
            case 'postback':
                // บันทึก postback event
                $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
                $stmt->execute([$userId]);
                $dbUserId = $stmt->fetchColumn();
                
                if ($lineAccountId) {
                    saveAccountEvent($db, $lineAccountId, 'postback', $userId, $dbUserId, $event);
                }
                
                // Handle Broadcast Product Click - Auto Tag
                $postbackData = $event['postback']['data'] ?? '';
                
                // รองรับทั้ง 2 รูปแบบ: broadcast_click_{id}_{id} หรือ JSON {"action":"broadcast_click",...}
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
                // บันทึก beacon event
                if ($lineAccountId) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
                    $stmt->execute([$userId]);
                    $dbUserId = $stmt->fetchColumn();
                    saveAccountEvent($db, $lineAccountId, 'beacon', $userId, $dbUserId, $event);
                }
                break;
            case 'memberJoined':
                // สมาชิกใหม่เข้ากลุ่ม
                if ($groupId && $lineAccountId) {
                    handleMemberJoined($event, $groupId, $db, $line, $lineAccountId);
                }
                break;
            case 'memberLeft':
                // สมาชิกออกจากกลุ่ม
                if ($groupId && $lineAccountId) {
                    handleMemberLeft($event, $groupId, $db, $lineAccountId);
                }
                break;
            }
            
            // ถ้าเป็นข้อความจากกลุ่ม ให้บันทึกด้วย
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
     * ใช้ replyToken เพื่อประหยัด quota (reply ฟรี, push นับ quota)
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
        
        // บันทึกข้อมูล follower แยกตามบอท
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

        // Dynamic Rich Menu - กำหนด Rich Menu ตามกฎอัตโนมัติ
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

        // Send welcome message - ใช้ reply แทน push เพื่อประหยัด quota!
        sendWelcomeMessage($db, $line, $userId, $replyToken, $lineAccountId);

        // Log analytics
        logAnalytics($db, 'follow', ['user_id' => $userId, 'line_account_id' => $lineAccountId], $lineAccountId);

        // Telegram notification พร้อมชื่อบอท
        $accountName = getAccountName($db, $lineAccountId);
        sendTelegramNotification($db, 'follow', $displayName, '', $userId, $dbUserId, $accountName);
    }

    /**
     * Send welcome message to new follower
     * ใช้ replyMessage เพื่อประหยัด quota (ฟรี!) ถ้ามี replyToken
     * ถ้าไม่มี replyToken จะ fallback ไปใช้ pushMessage
     * V5.1: ใช้ welcome_settings จากหลังบ้านเท่านั้น - ไม่มี default hardcode
     */
    function sendWelcomeMessage($db, $line, $userId, $replyToken = null, $lineAccountId = null) {
        try {
            // Get user profile for personalized message
            $profile = $line->getProfile($userId);
            $displayName = $profile['displayName'] ?? 'คุณลูกค้า';
            $pictureUrl = $profile['pictureUrl'] ?? null;
            
            // Get shop name - แยกตาม LINE Account
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
                    // ใช้ reply - ฟรี ไม่นับ quota!
                    return $line->replyMessage($replyToken, $messages);
                } else {
                    // Fallback to push - นับ quota
                    return $line->pushMessage($userId, $messages);
                }
            };
            
            // Get welcome settings for this account - ใช้จากหลังบ้านเท่านั้น
            $welcomeSettings = null;
            try {
                $stmt = $db->prepare("SELECT * FROM welcome_settings WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_enabled = 1 ORDER BY line_account_id DESC LIMIT 1");
                $stmt->execute([$lineAccountId]);
                $welcomeSettings = $stmt->fetch();
            } catch (Exception $e) {}
            
            // ถ้ามี welcome_settings ที่เปิดใช้งาน - ใช้ค่าจากนั้น
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
                            'altText' => "ยินดีต้อนรับคุณ{$displayName}",
                            'contents' => $flexContent
                        ];
                        $sendMessage([$message]);
                        return;
                    }
                }
            }
            
            // ถ้าไม่มี welcome_settings - ไม่ส่งข้อความต้อนรับ (ให้ตั้งค่าจากหลังบ้าน)
            // Log เพื่อแจ้งให้ทราบว่ายังไม่ได้ตั้งค่า
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
     * Handle Broadcast Product Click - ติด Tag อัตโนมัติเมื่อลูกค้ากดสินค้า
     */
    function handleBroadcastClick($db, $line, $dbUserId, $lineUserId, $postbackData, $replyToken, $lineAccountId) {
        try {
            $campaignId = null;
            $productId = null;
            $tagId = null;
            
            // รองรับ 2 รูปแบบ: string format หรือ JSON
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
            
            // ดึงข้อมูล item
            $stmt = $db->prepare("SELECT bi.*, bc.auto_tag_enabled, bc.name as campaign_name 
                                FROM broadcast_items bi 
                                JOIN broadcast_campaigns bc ON bi.broadcast_id = bc.id 
                                WHERE bi.broadcast_id = ? AND bi.product_id = ?");
            $stmt->execute([$campaignId, $productId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) return;
            
            // บันทึก click
            try {
                $stmt = $db->prepare("INSERT INTO broadcast_clicks (broadcast_id, item_id, user_id, line_user_id, tag_assigned) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$campaignId, $item['id'], $dbUserId, $lineUserId, $item['auto_tag_enabled'] ? 1 : 0]);
                
                // อัพเดท click count
                $stmt = $db->prepare("UPDATE broadcast_items SET click_count = click_count + 1 WHERE id = ?");
                $stmt->execute([$item['id']]);
                
                $stmt = $db->prepare("UPDATE broadcast_campaigns SET click_count = click_count + 1 WHERE id = ?");
                $stmt->execute([$campaignId]);
            } catch (Exception $e) {}
            
            // ติด Tag ถ้าเปิด auto tag
            // ใช้ tag_id จาก item หรือจาก JSON postback data
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
            
            // ตอบกลับลูกค้า
            $replyText = "✅ ขอบคุณที่สนใจ {$item['item_name']}\n\nทีมงานจะติดต่อกลับโดยเร็วที่สุดค่ะ 🙏";
            $line->replyMessage($replyToken, [['type' => 'text', 'text' => $replyText]]);
            
            // แจ้ง Telegram
            sendTelegramNotification($db, 'broadcast_click', $item['item_name'], "ลูกค้าสนใจสินค้า: {$item['item_name']}", $lineUserId, $dbUserId);
            
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
        
        // บันทึกข้อมูล unfollow แยกตามบอท
        if ($lineAccountId) {
            saveAccountFollower($db, $lineAccountId, $userId, $dbUserId, null, false);
            saveAccountEvent($db, $lineAccountId, 'unfollow', $userId, $dbUserId, $event);
            updateAccountDailyStats($db, $lineAccountId, 'unfollowers');
        }

        logAnalytics($db, 'unfollow', ['user_id' => $userId, 'line_account_id' => $lineAccountId], $lineAccountId);
        
        // Telegram notification พร้อมชื่อบอท
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

            // Get or create user - ตรวจสอบและบันทึกผู้ใช้เสมอ (ไม่ว่าจะมาจากกลุ่มหรือแชทส่วนตัว)
            $user = getOrCreateUser($db, $line, $userId, $lineAccountId, $groupId);
            
            // ตรวจสอบว่าเป็นข้อความแรกหรือไม่ (นับจำนวนข้อความ incoming ของ user)
            // นับก่อนที่จะบันทึกข้อความใหม่ ดังนั้น == 0 คือข้อความแรก
            $isFirstMessage = false;
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND direction = 'incoming'");
                $stmt->execute([$user['id']]);
                $messageCount = (int)$stmt->fetchColumn();
                $isFirstMessage = ($messageCount == 0); // == 0 เพราะนับก่อนบันทึก
            } catch (Exception $e) {}

            // Check user state first (for waiting slip mode)
            $userState = getUserState($db, $user['id']);
            
            // Handle different message types
            $mediaUrl = null;
            if (in_array($messageType, ['image', 'video', 'audio', 'file'])) {
                // ดาวน์โหลดและเก็บ media ไว้ใน server ทันที (LINE จะลบ content หลังจากผ่านไประยะหนึ่ง)
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
                
                // ถ้าบันทึกรูปได้ ใช้ URL ที่บันทึก ถ้าไม่ได้ใช้ LINE message ID เป็น fallback
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

            // Save incoming message พร้อม line_account_id, is_read = 0, และ mark_as_read_token
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
            
            // บันทึก reply_token ใน users table (หมดอายุใน 20 นาที)
            if ($replyToken) {
                try {
                    // ตรวจสอบว่ามี column หรือไม่
                    $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'reply_token'");
                    if ($checkCol->rowCount() > 0) {
                        $expires = date('Y-m-d H:i:s', time() + (19 * 60)); // หมดอายุใน 19 นาที (เผื่อ delay)
                        $stmt = $db->prepare("UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?");
                        $stmt->execute([$replyToken, $expires, $user['id']]);
                    }
                } catch (Exception $e) {
                    // Ignore error
                }
            }
            
            // บันทึก event และอัพเดทสถิติแยกตามบอท
            if ($lineAccountId) {
                saveAccountEvent($db, $lineAccountId, 'message', $userId, $user['id'], $event);
                updateAccountDailyStats($db, $lineAccountId, 'incoming_messages');
                updateAccountDailyStats($db, $lineAccountId, 'total_messages');
                updateFollowerInteraction($db, $lineAccountId, $userId);
            }
            
            // Send Telegram notification with media support พร้อมชื่อบอท
            $accountName = getAccountName($db, $lineAccountId);
            $displayNameWithBot = $user['display_name'] . ($accountName ? " [{$accountName}]" : "");
            sendTelegramNotificationWithMedia($db, $line, $displayNameWithBot, $messageType, $messageContent, $messageId, $user['id'], $event['message']);

            // For non-text messages
            if ($messageType !== 'text') {
                return; // Don't process non-text further, just notify via Telegram
            }
            
            // ========== ตรวจสอบ Pending Order - ลูกค้าตอบ "ยืนยัน" ==========
            // Debug: log user state
            devLog($db, 'debug', 'webhook', 'Checking pending order state', [
                'user_id' => $user['id'],
                'has_state' => $userState ? 'yes' : 'no',
                'state' => $userState['state'] ?? 'none',
                'message' => mb_substr($messageText, 0, 30)
            ], $userId);
            
            if ($userState && $userState['state'] === 'pending_order') {
                $confirmKeywords = ['ยืนยัน', 'ตกลง', 'ok', 'yes', 'confirm', 'สั่งเลย', 'เอา', 'ได้'];
                $cancelKeywords = ['ยกเลิก', 'cancel', 'no', 'ไม่เอา', 'ไม่'];
                
                $textLowerTrim = mb_strtolower(trim($messageText));
                
                devLog($db, 'debug', 'webhook', 'Pending order - checking keywords', [
                    'user_id' => $user['id'],
                    'text_lower' => $textLowerTrim,
                    'is_confirm' => in_array($textLowerTrim, $confirmKeywords) ? 'yes' : 'no'
                ], $userId);
                
                if (in_array($textLowerTrim, $confirmKeywords)) {
                    // สร้าง Order จาก pending order
                    devLog($db, 'info', 'webhook', 'Creating order from pending state', [
                        'user_id' => $user['id']
                    ], $userId);
                    
                    $orderCreated = createOrderFromPendingState($db, $line, $user['id'], $userId, $userState, $replyToken, $lineAccountId);
                    if ($orderCreated) {
                        clearUserState($db, $user['id']);
                        return;
                    }
                } elseif (in_array($textLowerTrim, $cancelKeywords)) {
                    // ยกเลิก pending order
                    clearUserState($db, $user['id']);
                    $cancelMessage = [
                        'type' => 'text',
                        'text' => "❌ ยกเลิกรายการสั่งซื้อแล้วค่ะ\n\nหากต้องการสั่งซื้อใหม่ สามารถแจ้งได้เลยค่ะ 🙏"
                    ];
                    $line->replyMessage($replyToken, [$cancelMessage]);
                    saveOutgoingMessage($db, $user['id'], json_encode($cancelMessage), 'system', 'text');
                    return;
                }
            }
            
            // ========== ตรวจสอบ Consent PDPA ==========
            // ปิดการตรวจสอบ consent - ให้ถือว่า consent แล้วเสมอ
            // ถ้าต้องการเปิดใช้งานใหม่ ให้ uncomment บรรทัดด้านล่าง
            // $hasConsent = checkUserConsent($db, $user['id'], $userId);
            $hasConsent = true; // ข้าม consent check
            
            // ดึงข้อมูล LIFF ID และ shop name
            $liffShopUrl = '';
            $liffConsentUrl = '';
            $shopName = 'LINE Shop';
            
            if ($lineAccountId) {
                // ตรวจสอบว่ามี column liff_consent_id หรือไม่
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
                    // ใช้ liff_consent_id ถ้ามี หรือใช้ liff_id ปกติ
                    $consentLiffId = $accountInfo['liff_consent_id'] ?? $accountInfo['liff_id'] ?? '';
                    if ($consentLiffId) {
                        $liffConsentUrl = 'https://liff.line.me/' . $consentLiffId . '?page=consent';
                    }
                    
                    // ดึง shop name
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
            
            // ========== ปิดการส่ง Consent PDPA อัตโนมัติ ==========
            // หมายเหตุ: ปิดการส่ง liff-consent.php เมื่อใช้งานครั้งแรก
            // ถ้าต้องการเปิดใช้งานใหม่ ให้ uncomment โค้ดด้านล่าง
            /*
            if (!$hasConsent && $sourceType === 'user') {
                try {
                    $displayName = $user['display_name'] ?: 'คุณลูกค้า';
                    
                    // สร้าง Flex Message ขอความยินยอม
                    $consentFlex = [
                        'type' => 'bubble',
                        'size' => 'kilo',
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#2563EB',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => '🔒 ข้อตกลงและความยินยอม', 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center']
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => "สวัสดีค่ะ คุณ{$displayName} 👋", 'size' => 'md', 'weight' => 'bold'],
                                ['type' => 'text', 'text' => "ยินดีต้อนรับสู่ {$shopName}", 'size' => 'sm', 'color' => '#666666', 'margin' => 'sm'],
                                ['type' => 'separator', 'margin' => 'lg'],
                                ['type' => 'text', 'text' => 'ก่อนเริ่มใช้บริการ กรุณายอมรับข้อตกลงการใช้งานและนโยบายความเป็นส่วนตัว (PDPA)', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'lg']
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
                                        'label' => '📋 อ่านและยอมรับข้อตกลง',
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
                        'altText' => '🔒 กรุณายอมรับข้อตกลงก่อนใช้บริการ',
                        'contents' => $consentFlex
                    ];
                    
                    $line->replyMessage($replyToken, [$consentMessage]);
                    saveOutgoingMessage($db, $user['id'], 'consent_request');
                    
                    devLog($db, 'info', 'webhook', 'Sent consent request to user', [
                        'user_id' => $user['id'],
                        'display_name' => $displayName
                    ], $userId);
                    
                    return; // ส่ง Consent request แล้ว ไม่ต้อง process ต่อ
                    
                } catch (Exception $e) {
                    devLog($db, 'error', 'webhook', 'Consent request error: ' . $e->getMessage(), null, $userId);
                }
            }
            */
            
            // ========== LIFF Menu สำหรับข้อความแรก (หลังจาก consent แล้ว) ==========
            // ส่ง LIFF Menu เมื่อลูกค้าทักมาครั้งแรก
            if ($isFirstMessage && $sourceType === 'user' && $hasConsent) {
                try {
                    // ถ้ามี LIFF URL ให้ส่ง LIFF Menu
                    if ($liffShopUrl) {
                        $displayName = $user['display_name'] ?: 'คุณลูกค้า';
                        $liffMenuBubble = FlexTemplates::firstMessageMenu($shopName, $liffShopUrl, $displayName);
                        $liffMenuMessage = FlexTemplates::toMessage($liffMenuBubble, "ยินดีต้อนรับสู่ {$shopName}");
                        
                        // เพิ่ม Quick Reply
                        $liffMenuMessage = FlexTemplates::withQuickReply($liffMenuMessage, [
                            ['label' => '🛒 ดูสินค้า', 'text' => 'shop'],
                            ['label' => '📋 เมนู', 'text' => 'menu'],
                            ['label' => '💬 ติดต่อเรา', 'text' => 'contact']
                        ]);
                        
                        $line->replyMessage($replyToken, [$liffMenuMessage]);
                        saveOutgoingMessage($db, $user['id'], 'liff_menu');
                        
                        devLog($db, 'info', 'webhook', 'Sent LIFF Menu to new user', [
                            'user_id' => $user['id'],
                            'display_name' => $displayName,
                            'liff_url' => $liffShopUrl
                        ], $userId);
                        
                        return; // ส่ง LIFF Menu แล้ว ไม่ต้อง process ต่อ
                    }
                } catch (Exception $e) {
                    devLog($db, 'error', 'webhook', 'LIFF Menu error: ' . $e->getMessage(), null, $userId);
                }
            }

            // ตรวจสอบ bot_mode ก่อน - ถ้าเป็น general ไม่ตอบกลับอะไรเลย
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
            
            // ตรวจสอบคำสั่งและการเรียก AI
            $textLower = mb_strtolower(trim($messageText));
            $textTrimmed = trim($messageText);
            
            devLog($db, 'debug', 'webhook', 'Bot mode check', [
                'user_id' => $userId,
                'bot_mode' => $botMode,
                'message' => mb_substr($messageText, 0, 30),
                'text_lower' => $textLower
            ], $userId);
            
            // ถ้าเป็นโหมด general - เช็ค Auto Reply ก่อน ถ้าไม่ match ค่อยไม่ตอบ
            if ($botMode === 'general') {
                // Debug: log before checking auto reply
                devLog($db, 'debug', 'webhook', 'General mode - checking auto reply', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'line_account_id' => $lineAccountId
                ], $userId);
                
                // Check auto-reply rules first - ถ้ามี rule ที่ match ให้ตอบ
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
                
                // ไม่มี auto reply match - ไม่ตอบกลับ แค่บันทึกข้อมูล (รอแอดมินตอบ)
                devLog($db, 'info', 'webhook', 'General mode - no auto reply match, waiting for admin', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'bot_mode' => $botMode
                ], $userId);
                return; // ไม่ตอบกลับ - ข้อมูลถูกบันทึกไว้แล้วด้านบน
            }
            
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
            
            // ===== V3.2: AI ตอบทุกข้อความอัตโนมัติ (ยกเว้นคำสั่งพิเศษ) =====
            // คำสั่งที่ไม่ให้ AI ตอบ (ให้ระบบอื่นจัดการ)
            $systemCommands = ['ร้านค้า', 'shop', 'ร้าน', 'สินค้า', 'ซื้อ', 'สั่งซื้อ', 
                            'สลิป', 'slip', 'แนบสลิป', 'ส่งสลิป', 'โอนเงิน', 'โอนแล้ว',
                            'ออเดอร์', 'order', 'คำสั่งซื้อ', 'ติดตาม', 'tracking',
                            'เมนู', 'menu', 'help', 'ช่วยเหลือ', '?',
                            'quickmenu', 'เมนูด่วน', 'allmenu', 'เมนูทั้งหมด',
                            'contact', 'ติดต่อ', 'ติดต่อเรา',
                            'สมัครบัตร', 'บัตรสมาชิก', 'member', 'points', 'แต้ม'];
            $isSystemCommand = in_array($textLower, $systemCommands);
            
            // คำสั่งที่จะหยุด AI และส่งต่อเภสัชกร/แอดมิน


            $stopAICommands = ['ปรึกษาเภสัชกร', 'คุยกับเภสัชกร', 'ขอคุยกับคน', 'ขอคุยกับแอดมิน', 'ติดต่อเภสัชกร', 'ติดต่อแอดมิน', 'หยุดบอท', 'stop bot', 'human'];
            $isStopAICommand = in_array($textLower, $stopAICommands);
            
            // ตรวจสอบว่าเรียก AI หรือไม่ (@บอท, @bot, @ai หรือ /xxx)
            $isAICall = preg_match('/^@(บอท|bot|ai)\s*/iu', $textTrimmed, $aiMatch);
            $aiMessage = $isAICall ? trim(preg_replace('/^@(บอท|bot|ai)\s*/iu', '', $textTrimmed)) : '';
            
            // ตรวจสอบว่าเป็น / command หรือไม่ (เรียก AI โดยตรง)
            $isSlashCommand = preg_match('/^\/[\w\p{Thai}]+/u', $textTrimmed);
            
            // ถ้าพิมพ์ขอคุยกับเภสัชกร - หยุด AI
            if ($isStopAICommand) {
                // ใช้ sender จาก ai_settings
                $stopSender = getAISenderSettings($db, $lineAccountId, 'pharmacist');
                
                $stopMessage = [
                    'type' => 'text',
                    'text' => "📞 รับทราบค่ะ กำลังส่งต่อให้เภสัชกรดูแลค่ะ\n\nกรุณารอสักครู่ เภสัชกรจะติดต่อกลับโดยเร็วที่สุดค่ะ 🙏",
                    'sender' => $stopSender
                ];
                $line->replyMessage($replyToken, [$stopMessage]);
                saveOutgoingMessage($db, $user['id'], json_encode($stopMessage), 'system', 'text');
                devLog($db, 'info', 'webhook', 'User requested human pharmacist', ['user_id' => $userId], $userId);
                return;
            }
            
            // ===== / command - ส่งไปให้ AI ตอบโดยตรง =====
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
            
            // ===== AI ตอบเฉพาะเมื่อใช้ / หรือ @ command =====
            // ===== AI SIMPLE MODE: DISABLED - ให้แอดมินตอบเอง =====
            // ปิดการตอบอัตโนมัติของ AI ผ่าน webhook แล้ว
            // ใช้ Ghost Draft ใน Inbox V2 แทน
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
                        
                        // เรียก Gemini ตอบเลย
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
                            
                            // ส่งกลับด้วย replyMessage
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
            
            // ===== ถ้า AI ไม่ตอบ ให้ทำงานตามปกติ =====
            
            // คำสั่งที่บอทจะตอบ (เฉพาะคำสั่งเจาะจง)
            $shopCommands = ['ร้านค้า', 'shop', 'ร้าน', 'สินค้า', 'ซื้อ', 'สั่งซื้อ'];
            $slipCommands = ['สลิป', 'slip', 'แนบสลิป', 'ส่งสลิป', 'โอนเงิน', 'โอนแล้ว'];
            $orderCommands = ['ออเดอร์', 'order', 'คำสั่งซื้อ', 'ติดตาม', 'tracking'];
            $menuCommands = ['เมนู', 'menu', 'help', 'ช่วยเหลือ'];
            
            $isShopCommand = in_array($textLower, $shopCommands);
            $isSlipCommand = in_array($textLower, $slipCommands);
            $isOrderCommand = in_array($textLower, $orderCommands);
            $isMenuCommand = in_array($textLower, $menuCommands);
            
            // ===== Handle LIFF Action Messages (สั่งซื้อสำเร็จ, นัดหมายสำเร็จ, etc.) =====
            if (preg_match('/^สั่งซื้อสำเร็จ\s*#?(\w+)/u', $messageText, $matches)) {
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
                                ['type' => 'text', 'text' => '฿' . number_format($item['subtotal'], 0), 'size' => 'sm', 'color' => '#111111', 'flex' => 2, 'align' => 'end']
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
                                ['type' => 'text', 'text' => 'ยืนยันคำสั่งซื้อ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg']
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => array_merge(
                                [
                                    ['type' => 'text', 'text' => '#' . $order['order_number'], 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                                    ['type' => 'separator', 'margin' => 'lg'],
                                    ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg']
                                ],
                                $itemContents,
                                [
                                    ['type' => 'separator', 'margin' => 'lg'],
                                    [
                                        'type' => 'box',
                                        'layout' => 'horizontal',
                                        'margin' => 'lg',
                                        'contents' => [
                                            ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#555555'],
                                            ['type' => 'text', 'text' => '฿' . number_format($order['shipping_fee'] ?? 0, 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
                                        ]
                                    ],
                                    [
                                        'type' => 'box',
                                        'layout' => 'horizontal',
                                        'margin' => 'md',
                                        'contents' => [
                                            ['type' => 'text', 'text' => 'รวมทั้งหมด', 'size' => 'md', 'weight' => 'bold'],
                                            ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 0), 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755', 'align' => 'end']
                                        ]
                                    ]
                                ]
                            )
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                ['type' => 'text', 'text' => 'กรุณาชำระเงินและแนบสลิป', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                ['type' => 'text', 'text' => 'พิมพ์ "สลิป" เพื่อแนบหลักฐาน', 'size' => 'xs', 'color' => '#888888', 'align' => 'center', 'margin' => 'sm']
                            ]
                        ]
                    ];
                    
                    $message = [
                        'type' => 'flex',
                        'altText' => 'ยืนยันคำสั่งซื้อ #' . $order['order_number'],
                        'contents' => $orderFlex
                    ];
                    $line->replyMessage($replyToken, [$message]);
                    saveOutgoingMessage($db, $user['id'], 'order_confirmation_flex', 'system', 'flex');
                } else {
                    $line->replyMessage($replyToken, [['type' => 'text', 'text' => 'ไม่พบคำสั่งซื้อ #' . $orderNumber]]);
                }
                return;
            }
            
            // ถ้าเรียก AI (@บอท xxx) - ส่งไปให้ AI ตอบ (fallback)
            if ($isAICall && !empty($aiMessage)) {
                devLog($db, 'info', 'webhook', 'AI called with @bot', [
                    'user_id' => $userId,
                    'message' => $aiMessage
                ], $userId);
                
                $aiReply = checkAIChatbot($db, $aiMessage, $lineAccountId, $user['id'] ?? null);
                if ($aiReply) {
                    // ลอง replyMessage ก่อน (ฟรี!)
                    $replyResult = $line->replyMessage($replyToken, $aiReply);
                    $replyCode = $replyResult['code'] ?? 0;
                    
                    // ถ้า reply ไม่สำเร็จ ให้ใช้ pushMessage แทน
                    if ($replyCode !== 200) {
                        $line->pushMessage($userId, $aiReply);
                    }
                    
                    saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'flex');
                    return;
                } else {
                    // AI ไม่ได้เปิดใช้งาน
                    $line->replyMessage($replyToken, [['type' => 'text', 'text' => '❌ ระบบ AI ยังไม่ได้เปิดใช้งาน กรุณาติดต่อแอดมิน']]);
                    return;
                }
            }
            
            // ถ้าเป็นคำสั่งร้านค้า - ส่ง LIFF URL
            if ($isShopCommand && $liffId) {
                $liffUrl = "https://liff.line.me/{$liffId}";
                $shopFlex = [
                    'type' => 'bubble',
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => '🛍️ ร้านค้าออนไลน์', 'weight' => 'bold', 'size' => 'lg'],
                            ['type' => 'text', 'text' => 'กดปุ่มด้านล่างเพื่อดูสินค้าและสั่งซื้อ', 'size' => 'sm', 'color' => '#666666', 'margin' => 'md', 'wrap' => true]
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
                                'action' => ['type' => 'uri', 'label' => '🛒 เข้าสู่ร้านค้า', 'uri' => $liffUrl]
                            ]
                        ]
                    ]
                ];
                
                $message = [
                    'type' => 'flex',
                    'altText' => 'กดเพื่อเข้าสู่ร้านค้า',
                    'contents' => $shopFlex
                ];
                $line->replyMessage($replyToken, [$message]);
                saveOutgoingMessage($db, $user['id'], 'liff_redirect');
                return;
            }
            
            // ถ้าเป็นคำสั่งสลิป/ออเดอร์ - ให้ BusinessBot จัดการ (ด้านล่าง)
            // ถ้าเป็นคำสั่งเมนู - ให้ Auto Reply หรือ BusinessBot จัดการ (ด้านล่าง)
            
            // ถ้าไม่ใช่คำสั่งที่กำหนด และไม่ใช่โหมด general - ไม่ตอบ (รอแอดมิน)
            if (!$isSlipCommand && !$isOrderCommand && !$isMenuCommand && $botMode !== 'general') {
                // เช็ค Auto Reply ก่อน
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
                
                // ไม่ตอบ - รอแอดมิน
                devLog($db, 'info', 'webhook', 'No matching command - waiting for admin', [
                    'user_id' => $userId,
                    'message' => mb_substr($messageText, 0, 100),
                    'bot_mode' => $botMode
                ], $userId);
                return;
            }

            // Check for contact command FIRST (ก่อน Auto Reply)
            if (in_array($textLower, ['contact', 'ติดต่อ', 'ติดต่อเรา'])) {
                $contactBubble = FlexTemplates::notification(
                    'ติดต่อเรา',
                    'สามารถพิมพ์ข้อความถึงเราได้เลย\nทีมงานจะตอบกลับโดยเร็วที่สุด',
                    '📞',
                    '#3B82F6',
                    [['label' => '🛒 ดูสินค้า', 'text' => 'shop', 'style' => 'secondary']]
                );
                $contactMessage = FlexTemplates::toMessage($contactBubble, 'ติดต่อเรา');
                // เพิ่ม Quick Reply
                $contactMessage = FlexTemplates::withQuickReply($contactMessage, [
                    ['label' => '🛒 ดูสินค้า', 'text' => 'shop'],
                    ['label' => '📋 เมนู', 'text' => 'menu'],
                    ['label' => '📦 ออเดอร์', 'text' => 'orders']
                ]);
                
                // Send and log response
                $replyResult = $line->replyMessage($replyToken, [$contactMessage]);
                
                devLog($db, 'debug', 'webhook', 'Contact command reply result', [
                    'user_id' => $userId,
                    'code' => $replyResult['code'] ?? 0,
                    'body' => $replyResult['body'] ?? null,
                    'has_reply_token' => !empty($replyToken)
                ], $userId);
                
                if (($replyResult['code'] ?? 0) !== 200) {
                    devLog($db, 'error', 'webhook', 'Contact command reply FAILED', [
                        'user_id' => $userId,
                        'code' => $replyResult['code'] ?? 0,
                        'error' => $replyResult['body'] ?? null
                    ], $userId);
                }
                
                saveOutgoingMessage($db, $user['id'], 'contact');
                return;
            }
            
            // Check for slip command: "สลิป", "slip", "แนบสลิป", "ส่งสลิป"
            if (in_array($textLower, ['สลิป', 'slip', 'แนบสลิป', 'ส่งสลิป', 'โอนเงิน', 'โอนแล้ว'])) {
                devLog($db, 'debug', 'webhook', 'Slip command detected', ['user_id' => $user['id'], 'text' => $textLower], $userId);
                $handled = handleSlipCommand($db, $line, $user['id'], $replyToken);
                devLog($db, 'debug', 'webhook', 'Slip command result: ' . ($handled ? 'handled' : 'not handled'), ['user_id' => $user['id']], $userId);
                if ($handled) return;
            }
            
            // Check for menu command - แสดงเมนูหลักสวยๆ (อัพเกรด V2)
            if (in_array($textLower, ['menu', 'เมนู', 'help', 'ช่วยเหลือ', '?'])) {
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
                $menuMessage = FlexTemplates::toMessage($menuBubble, "เมนู {$shopName}");
                $line->replyMessage($replyToken, [$menuMessage]);
                saveOutgoingMessage($db, $user['id'], 'menu');
                return;
            }
            
            // Check for quick menu command - เมนูด่วนแบบ Carousel
            if (in_array($textLower, ['quickmenu', 'เมนูด่วน', 'allmenu', 'เมนูทั้งหมด'])) {
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
                $menuMessage = FlexTemplates::toMessage($menuCarousel, "เมนูทั้งหมด {$shopName}");
                $line->replyMessage($replyToken, [$menuMessage]);
                saveOutgoingMessage($db, $user['id'], 'quickmenu');
                return;
            }
            
            // Points/loyalty command - handled by BusinessBot.showPoints()

            // เช็ค Auto Reply ก่อน BusinessBot (สำหรับข้อความทั่วไป)
            // ยกเว้นคำสั่งพิเศษที่ BusinessBot ต้องจัดการ
            $specialCommands = ['shop', 'menu', 'orders', 'สินค้า', 'เมนู', 'ออเดอร์', 'points', 'แต้ม'];
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

            // V2.5: Check Business commands (ใช้ BusinessBot เท่านั้น)
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

            // Check auto-reply rules (รองรับ Sender, Quick Reply, Alt Text) - แยกตาม LINE Account
            $reply = checkAutoReply($db, $messageText, $lineAccountId);
            if ($reply) {
                $line->replyMessage($replyToken, [$reply]);
                saveOutgoingMessage($db, $user['id'], json_encode($reply), 'system', 'flex');
                return;
            }
            
            // ไม่ตอบ default reply - รอแอดมินตอบ
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
                $line->replyMessage($replyToken, ['type' => 'text', 'text' => '❌ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง']);
            } catch (Exception $e2) {}
        }
    }

        /**
         * Check auto-reply rules (Upgraded with Sender, Quick Reply, Alt Text)
         * แยกตาม LINE Account - ดึงเฉพาะกฎของ account นั้นๆ หรือกฎที่ไม่ระบุ account (global)
         */
        function checkAutoReply($db, $text, $lineAccountId = null) {
            // ดึงกฎที่ตรงกับ account นี้ หรือกฎ global (line_account_id IS NULL)
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
                        // Match all messages - ตอบทุกข้อความ
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
                            $altText = $rule['alt_text'] ?? $rule['keyword'] ?? 'ข้อความ';
                            
                            // Add share button if enabled
                            $enableShare = $rule['enable_share'] ?? false;
                            if ($enableShare && defined('LIFF_SHARE_ID') && LIFF_SHARE_ID) {
                                $shareLabel = $rule['share_button_label'] ?? '📤 แชร์ให้เพื่อน';
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
                                        // Share button - ใช้ LINE URI Scheme
                                        $shareText = $item['shareText'] ?? 'มาดูสิ่งนี้สิ!';
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
        function addShareButtonToFlex($flexContent, $ruleId, $label = '📤 แชร์ให้เพื่อน') {
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
         * V5.0: เพิ่ม Command Mode (/ai, /mims, /triage, /human)
         * V4.0: เพิ่ม Keyword Routing + Bot Pause Feature
         * V3.0: รองรับ PharmacyAI Adapter (Triage System)
         * V2.6: รองรับ Module ใหม่ (modules/AIChat)
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
                
                // ===== 0. ตรวจสอบ Command Mode (/ai, /mims, /triage, /human) =====
                $commandMode = null;
                $commandMessage = $originalText;
                
                // รูปแบบ: /command ข้อความ หรือ @command ข้อความ
                // รองรับ backtick หรือ character พิเศษข้างหน้า
                $cleanText = preg_replace('/^[`\'"\s]+/', '', $originalText);
                
                // ===== ตรวจสอบ "/" เดียว → เริ่ม AI และแสดงคำอธิบาย =====
                if ($cleanText === '/' || $cleanText === '@') {
                    // ตรวจสอบว่าเคยใช้ AI หรือยัง
                    $isFirstTime = true;
                    if ($userId) {
                        try {
                            $stmt = $db->prepare("SELECT COUNT(*) FROM ai_chat_logs WHERE user_id = ? LIMIT 1");
                            $stmt->execute([$userId]);
                            $isFirstTime = ($stmt->fetchColumn() == 0);
                        } catch (Exception $e) {}
                    }
                    
                    // ดึง AI mode จาก ai_settings
                    $configuredMode = 'sales'; // default
                    try {
                        $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                        $stmt->execute([$lineAccountId]);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($result && $result['ai_mode']) {
                            $configuredMode = $result['ai_mode'];
                        }
                    } catch (Exception $e) {}
                    
                    // บันทึกโหมด AI ตามที่ตั้งค่าไว้
                    if ($userId) {
                        setUserAIMode($db, $userId, $configuredMode);
                    }
                    
                    if ($isFirstTime) {
                        // ครั้งแรก - แสดงคำอธิบายการใช้งาน
                        return [[
                            'type' => 'text',
                            'text' => "🤖 ยินดีต้อนรับสู่ AI Assistant!\n\n✨ วิธีใช้งาน:\n• พิมพ์คำถามหรือสิ่งที่ต้องการได้เลย\n• AI จะช่วยตอบคำถาม แนะนำสินค้า และให้ข้อมูล\n\n📝 ตัวอย่าง:\n• \"มีสินค้าอะไรบ้าง\"\n• \"แนะนำสินค้าขายดี\"\n• \"ราคาสินค้า XXX\"\n\n💡 พิมพ์ /exit เพื่อออกจากโหมด AI\n\n🎯 เริ่มต้นได้เลย! พิมพ์คำถามของคุณ:",
                            'sender' => [
                                'name' => '🤖 AI Assistant',
                                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
                            ]
                        ]];
                    } else {
                        // เคยใช้แล้ว - แสดงข้อความสั้นๆ
                        return [[
                            'type' => 'text',
                            'text' => "🤖 AI พร้อมให้บริการค่ะ!\n\nพิมพ์คำถามหรือสิ่งที่ต้องการได้เลย\n(พิมพ์ /exit เพื่อออก)",
                            'sender' => [
                                'name' => '🤖 AI Assistant',
                                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
                            ]
                        ]];
                    }
                }
                
                // รองรับทั้ง / และ @ นำหน้า command (รองรับทั้ง English และ Thai)
                if (preg_match('/^[\/\@]([\w\p{Thai}]+)\s*(.*)/u', $cleanText, $matches)) {
                    $command = mb_strtolower($matches[1]);
                    $commandMessage = trim($matches[2]);
                    
                    // Map commands to modes
                    $commandMap = [
                        'ai' => 'auto',          // /ai = ใช้ mode จาก settings
                        'pharmacy' => 'pharmacist',
                        'pharmacist' => 'pharmacist',
                        'ยา' => 'pharmacist',
                        'ถาม' => 'auto',         // /ถาม = ใช้ mode จาก settings
                        'ขาย' => 'sales',        // /ขาย = โหมดขาย
                        'sales' => 'sales',
                        'support' => 'support',  // /support = โหมดซัพพอร์ต
                        'ซัพพอร์ต' => 'support',
                        
                        'mims' => 'mims',        // /mims = MIMS AI (ความรู้ทางการแพทย์)
                        'med' => 'mims',
                        'วิชาการ' => 'mims',
                        
                        'triage' => 'triage',    // /triage = ซักประวัติ
                        'ซักประวัติ' => 'triage',
                        'assess' => 'triage',
                        
                        'human' => 'human',      // /human = ขอคุยกับเภสัชกรจริง
                        'คน' => 'human',
                        'เภสัช' => 'human',
                        
                        'exit' => 'exit',        // /exit = ออกจากโหมด AI
                        'ออก' => 'exit',
                        'หยุด' => 'exit',
                        
                        'help' => 'help',        // /help = แสดงคำสั่งทั้งหมด
                        'ช่วย' => 'help',
                    ];
                    
                    if (isset($commandMap[$command])) {
                        $commandMode = $commandMap[$command];
                        
                        // ถ้าเป็น 'auto' ให้ดึง mode จาก ai_settings
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
                        // Unknown command → ถือว่าเป็นคำถามถาม AI
                        // ใช้ mode จาก ai_settings
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
                
                // ===== 0.5 ตรวจสอบ AI Mode ของ user =====
                // ถ้า user เคยพิมพ์ /ai, /mims, /triage → จำโหมดไว้
                // ข้อความถัดไปจะใช้โหมดนั้นต่อจนกว่าจะเปลี่ยน
                if (!$commandMode && $userId) {
                    $currentAIMode = getUserAIMode($db, $userId);
                    if ($currentAIMode) {
                        $commandMode = $currentAIMode;
                        $commandMessage = $originalText;
                        devLog($db, 'debug', 'AI_mode', 'Using saved AI mode', ['mode' => $currentAIMode, 'userId' => $userId], null);
                    }
                }
                
                // ถ้าพิมพ์ command ใหม่ → บันทึกโหมด
                if ($commandMode && $userId && in_array($commandMode, ['pharmacist', 'pharmacy', 'sales', 'support', 'mims', 'triage'])) {
                    setUserAIMode($db, $userId, $commandMode);
                }
                
                // ถ้าพิมพ์ /human หรือ /exit → ลบโหมด
                if (($commandMode === 'human' || $commandMode === 'exit') && $userId) {
                    clearUserAIMode($db, $userId);
                }
                
                // ===== DEBUG: Log after mode check =====
                devLog($db, 'debug', 'AI_trace_2', 'After mode check', [
                    'commandMode' => $commandMode,
                    'line' => __LINE__
                ], null);
                
                // ดึง sender settings สำหรับ system messages
                $systemSender = getAISenderSettings($db, $lineAccountId);
                
                // ===== /exit - ออกจากโหมด AI =====
                if ($commandMode === 'exit') {
                    return [[
                        'type' => 'text',
                        'text' => "✅ ออกจากโหมด AI แล้วค่ะ\n\nข้อความถัดไปจะส่งถึงแอดมินโดยตรง\n\n💡 พิมพ์ /ai, /mims หรือ /triage เพื่อกลับมาใช้ AI ได้ทุกเมื่อค่ะ",
                        'sender' => $systemSender
                    ]];
                }
                
                // ===== /help - แสดงคำสั่งทั้งหมด =====
                if ($commandMode === 'help') {
                    return [[
                        'type' => 'text',
                        'text' => "🤖 คำสั่ง AI ที่ใช้ได้:\n\n" .
                                "/ai - เข้าโหมด AI ตามที่ตั้งค่าไว้\n" .
                                "/mims - เข้าโหมด MIMS (ข้อมูลยา)\n" .
                                "/triage - เริ่มซักประวัติอาการ\n" .
                                "/human - ขอคุยกับเภสัชกรจริง\n" .
                                "/exit - ออกจากโหมด AI\n\n" .
                                "💡 เมื่อเข้าโหมดแล้ว พิมพ์ข้อความได้เลย\n" .
                                "AI จะตอบต่อจนกว่าจะพิมพ์ /exit",
                        'sender' => $systemSender
                    ]];
                }
                
                // ===== 1. ตรวจสอบว่า Bot ถูก Pause หรือไม่ =====
                if ($userId && isAIPaused($db, $userId)) {
                    // ถ้าพิมพ์ /ai หรือ command อื่น ให้ resume bot
                    if ($commandMode && $commandMode !== 'human') {
                        resumeAI($db, $userId);
                        devLog($db, 'info', 'AI_pause', 'AI resumed by command', ['user_id' => $userId, 'command' => $commandMode], null);
                    } else {
                        devLog($db, 'debug', 'AI_pause', 'AI is paused for user', ['user_id' => $userId], null);
                        return null; // ไม่ตอบ - ให้เภสัชกรจริงตอบ
                    }
                }
                
                // ===== 2. /human หรือ คำสั่งขอคุยกับเภสัชกรจริง =====
                if ($commandMode === 'human') {
                    pauseAI($db, $userId, 20);
                    notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $originalText);
                    
                    return [[
                        'type' => 'text',
                        'text' => "เข้าใจค่ะ 🙏\n\nระบบได้แจ้งเภสัชกรแล้ว จะมีเภสัชกรติดต่อกลับภายใน 5-10 นาทีค่ะ\n\n📞 หากต้องการติดต่อด่วน โทร: 02-XXX-XXXX\n\n(บอทจะหยุดตอบชั่วคราว 20 นาที)\n\n💡 พิมพ์ /ai เพื่อกลับมาใช้บอทได้ทุกเมื่อ",
                        'sender' => $systemSender
                    ]];
                }
                
                // ตรวจสอบ keyword ขอคุยกับเภสัชกรจริง (ไม่ใช้ command)
                $humanPharmacistKeywords = [
                    'คุยกับเภสัชกร', 'ขอคุยกับคน', 'ขอคุยกับเภสัช', 'เภสัชกรจริง', 
                    'คนจริง', 'ไม่ใช่บอท', 'ไม่เอาบอท', 'หยุดบอท', 'ปิดบอท',
                    'ขอพูดกับคน', 'ต้องการคุยกับคน', 'human', 'real pharmacist',
                    'ขอเภสัชกรตัวจริง', 'เภสัชตัวจริง', 'ไม่ต้องการ ai', 'ไม่เอา ai'
                ];
                
                if (!$commandMode) {
                    foreach ($humanPharmacistKeywords as $keyword) {
                        if (mb_strpos($textLower, $keyword) !== false) {
                            pauseAI($db, $userId, 20);
                            notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $text);
                            
                            return [[
                                'type' => 'text',
                                'text' => "เข้าใจค่ะ 🙏\n\nระบบได้แจ้งเภสัชกรแล้ว จะมีเภสัชกรติดต่อกลับภายใน 5-10 นาทีค่ะ\n\n📞 หากต้องการติดต่อด่วน โทร: 02-XXX-XXXX\n\n(บอทจะหยุดตอบชั่วคราว 20 นาที)\n\n💡 พิมพ์ /ai เพื่อกลับมาใช้บอทได้ทุกเมื่อ",
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
                            
                            // ดึง sender settings สำหรับ MIMS mode
                            $mimsSender = getAISenderSettings($db, $lineAccountId, 'mims');
                            
                            if ($isEnabled) {
                                // ถ้าไม่มีข้อความ ให้แสดงคำแนะนำ
                                if (empty($commandMessage)) {
                                    devLog($db, 'debug', 'AI_mims', 'MIMS empty message - showing help', [], null);
                                    return [[
                                        'type' => 'text',
                                        'text' => "📚 MIMS Pharmacist AI พร้อมให้บริการค่ะ\n\nสามารถถามข้อมูลเกี่ยวกับ:\n• ข้อมูลยาและสรรพคุณ\n• อาการและการรักษา\n• ข้อควรระวังในการใช้ยา\n\n💡 ตัวอย่าง:\n/mims ยา paracetamol\n/mims อาการปวดหัวไมเกรน\n/mims ยาแก้แพ้ตัวไหนดี",
                                        'sender' => $mimsSender
                                    ]];
                                }
                                
                                devLog($db, 'debug', 'AI_mims', 'MIMS processing message', ['message' => $commandMessage], null);
                                $result = $adapter->processMessage($commandMessage);
                                devLog($db, 'debug', 'AI_mims', 'MIMS result', ['success' => $result['success'] ?? false, 'hasMessage' => !empty($result['message']), 'hasResponse' => !empty($result['response']), 'error' => $result['error'] ?? null], null);
                                
                                if ($result['success'] && !empty($result['message'])) {
                                    $msg = $result['message'];
                                    // ตรวจสอบว่า message เป็น array ที่มี type หรือไม่
                                    if (is_array($msg) && isset($msg['type'])) {
                                        // ตรวจสอบว่ามี text content หรือไม่
                                        if (empty($msg['text'])) {
                                            // ถ้าไม่มี text ให้ใช้ response แทน
                                            $msg['text'] = $result['response'] ?? 'ขออภัยค่ะ ไม่สามารถประมวลผลได้';
                                            devLog($db, 'warning', 'AI_mims', 'MIMS message missing text, using response', ['response' => mb_substr($msg['text'], 0, 100)], null);
                                        }
                                        // เพิ่ม sender ถ้ายังไม่มี
                                        if (!isset($msg['sender'])) {
                                            $msg['sender'] = $mimsSender;
                                        }
                                        devLog($db, 'debug', 'AI_mims', 'MIMS returning message array', ['type' => $msg['type'], 'textLength' => strlen($msg['text'] ?? '')], null);
                                        return [$msg];
                                    }
                                    // ถ้าเป็น string ให้ wrap เป็น LINE message
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
                                
                                // ถ้า success แต่ไม่มี message ให้ใช้ response
                                if ($result['success'] && !empty($result['response'])) {
                                    devLog($db, 'debug', 'AI_mims', 'MIMS using response text', ['length' => strlen($result['response'])], null);
                                    return [[
                                        'type' => 'text',
                                        'text' => $result['response'],
                                        'sender' => $mimsSender
                                    ]];
                                }
                                
                                // ถ้าไม่ success ให้แสดง error
                                if (!$result['success']) {
                                    $errorMsg = $result['error'] ?? 'Unknown error';
                                    devLog($db, 'error', 'AI_mims', 'MIMS process failed: ' . $errorMsg, ['user_id' => $userId], null);
                                    return [[
                                        'type' => 'text',
                                        'text' => "❌ MIMS AI ขัดข้อง: {$errorMsg}\n\nลองใช้ /ai แทนได้ค่ะ",
                                        'sender' => $mimsSender
                                    ]];
                                }
                            } else {
                                devLog($db, 'warning', 'AI_mims', 'MIMS not enabled - no API key', [], null);
                                return [[
                                    'type' => 'text',
                                    'text' => "❌ MIMS AI ยังไม่ได้ตั้งค่า API Key\n\nกรุณาติดต่อผู้ดูแลระบบ หรือลองใช้ /ai แทนได้ค่ะ",
                                    'sender' => $mimsSender
                                ]];
                            }
                        } catch (\Throwable $e) {
                            devLog($db, 'error', 'AI_mims', 'MIMS AI error: ' . $e->getMessage(), ['user_id' => $userId, 'trace' => $e->getTraceAsString()], null);
                            return [[
                                'type' => 'text',
                                'text' => "❌ MIMS AI ขัดข้อง\n\nลองใช้ /ai แทนได้ค่ะ",
                                'sender' => $mimsSender
                            ]];
                        }
                    }
                    
                    return [[
                        'type' => 'text',
                        'text' => "❌ MIMS AI ไม่พร้อมใช้งานขณะนี้\n\nลองใช้ /ai แทนได้ค่ะ",
                        'sender' => getAISenderSettings($db, $lineAccountId, 'mims')
                    ]];
                }
                
                // ===== 4. /triage - ซักประวัติอาการ =====
                if ($commandMode === 'triage') {
                    devLog($db, 'debug', 'AI_triage', 'Triage command', ['userId' => $userId], null);
                    
                    // ดึง sender settings สำหรับ triage mode
                    $triageSender = getAISenderSettings($db, $lineAccountId, 'triage');
                    
                    if (file_exists(__DIR__ . '/modules/AIChat/Services/TriageEngine.php')) {
                        try {
                            // Load all required dependencies via Autoloader
                            require_once __DIR__ . '/modules/AIChat/Autoloader.php';
                            loadAIChatModule();
                            
                            // Pass $db connection to TriageEngine
                            $triage = new \Modules\AIChat\Services\TriageEngine($lineAccountId, $userId, $db);
                            
                            // Reset และเริ่มใหม่
                            $result = $triage->process($commandMessage ?: 'เริ่มซักประวัติ');
                            devLog($db, 'debug', 'AI_triage', 'Triage result', ['hasText' => !empty($result['text']), 'hasMessage' => !empty($result['message'])], null);
                            
                            $responseText = $result['text'] ?? $result['message'] ?? 'พร้อมซักประวัติค่ะ';
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
                                'text' => "❌ ระบบซักประวัติขัดข้อง\n\nลองใช้ /ai แทนได้ค่ะ",
                                'sender' => $triageSender
                            ]];
                        }
                    } else {
                        return [[
                            'type' => 'text',
                            'text' => "❌ ระบบซักประวัติไม่พร้อมใช้งาน\n\nลองใช้ /ai แทนได้ค่ะ",
                            'sender' => $triageSender
                        ]];
                    }
                }
                
                // ===== 5. /ai, /sales หรือ Default - ตรวจสอบ AI Mode ก่อน =====
                // ถ้าใช้ command /ai หรือ /sales ให้ใช้ข้อความหลัง command
                $messageToProcess = $text;
                if (!empty($commandMessage)) {
                    $messageToProcess = $commandMessage;
                }
                
                // ดึง AI mode จาก ai_settings เสมอ (ไม่ว่า commandMode จะเป็นอะไร)
                $currentAIMode = 'sales'; // default to sales
                try {
                    $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                    $stmt->execute([$lineAccountId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['ai_mode']) {
                        $currentAIMode = $result['ai_mode'];
                    }
                } catch (Exception $e) {}
                
                // ถ้า commandMode เป็น sales/support/pharmacist โดยตรง → override
                if (in_array($commandMode, ['sales', 'support', 'pharmacist', 'pharmacy'])) {
                    $currentAIMode = $commandMode;
                }
                
                devLog($db, 'debug', 'AI_section5', 'AI Mode determined', [
                    'currentAIMode' => $currentAIMode,
                    'commandMode' => $commandMode,
                    'message' => mb_substr($messageToProcess, 0, 50)
                ], null);
                
                // ===== ถ้าเป็น Sales/Support Mode → ใช้ GeminiChat (ไม่ใช่ PharmacyAI) =====
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
                            // ใช้ sender จาก ai_settings
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
                            // Sales mode แต่ GeminiChat return null → return null ไม่ fallthrough ไป PharmacyAI
                            return null;
                        }
                    } else {
                        devLog($db, 'warning', 'AI_sales', 'GeminiChat not enabled', [
                            'line_account_id' => $lineAccountId
                        ], null);
                        // Sales mode แต่ GeminiChat not enabled → return null ไม่ fallthrough ไป PharmacyAI
                        return null;
                    }
                }
                
                // ===== ถ้าเป็น Pharmacist Mode → ใช้ PharmacyAI Adapter =====
                // เข้าเฉพาะเมื่อ currentAIMode เป็น pharmacist/pharmacy เท่านั้น
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
                            
                            // ใช้ PharmacyAI Adapter
                            $result = $adapter->processMessage($messageToProcess);
                            
                            if ($result['success'] && !empty($result['message'])) {
                                devLog($db, 'debug', 'AI_pharmacy', 'AI response generated (PharmacyAI v5)', [
                                    'user_id' => $userId,
                                    'response_length' => mb_strlen($result['response'] ?? ''),
                                    'state' => $result['state'] ?? 'unknown',
                                    'is_critical' => $result['is_critical'] ?? false,
                                    'has_products' => !empty($result['products'])
                                ], null);
                                
                                // รองรับ multiple messages (text + product carousel)
                                $messages = $result['messages'] ?? $result['message'];
                                
                                // ถ้าเป็น single message ให้ wrap เป็น array
                                if (isset($messages['type'])) {
                                    return [$messages];
                                }
                                
                                // ถ้าเป็น array ของ messages แล้ว return ตรงๆ
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
                
                // ===== Fallback: ลองใช้ GeminiChatAdapter (เฉพาะ pharmacist mode) =====
                // ถ้าเป็น sales mode ไม่ต้อง fallback เพราะ GeminiChat ควรทำงานแล้ว
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
                        
                        // ใช้ method ใหม่ที่ return message object พร้อมใช้
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
                        // ถ้า Module ใหม่ error ให้ fallback ไปใช้ระบบเก่า
                        devLog($db, 'warning', 'AI_chatbot_v2', 'Module v2 error, fallback to v1: ' . $e->getMessage(), [
                            'user_id' => $userId
                        ], null);
                    }
                }
                
                // ===== Fallback: ใช้ GeminiChat เก่า =====
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
         * Developer Log - บันทึก log สำหรับ debug
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
                'name' => '🤖 AI Assistant',
                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
            ];
            
            try {
                $stmt = $db->prepare("SELECT sender_name, sender_icon, ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$lineAccountId]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($settings) {
                    $mode = $overrideMode ?? $settings['ai_mode'] ?? 'sales';
                    
                    // ใช้ sender_name จาก settings ถ้ามี
                    if (!empty($settings['sender_name'])) {
                        $defaultSender['name'] = $settings['sender_name'];
                    } else {
                        // Default sender name ตาม ai_mode
                        switch ($mode) {
                            case 'pharmacist':
                            case 'pharmacy':
                                $defaultSender['name'] = '💊 เภสัชกร AI';
                                break;
                            case 'mims':
                                $defaultSender['name'] = '📚 MIMS Pharmacist AI';
                                break;
                            case 'triage':
                                $defaultSender['name'] = '🩺 ซักประวัติ AI';
                                break;
                            case 'support':
                                $defaultSender['name'] = '💬 ซัพพอร์ต AI';
                                break;
                            case 'sales':
                            default:
                                $defaultSender['name'] = '🛒 พนักงานขาย AI';
                                break;
                        }
                    }
                    
                    // ใช้ sender_icon จาก settings ถ้ามี
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
         * ตรวจสอบว่าผู้ใช้ยินยอม PDPA แล้วหรือยัง
         * - ถ้าผู้ใช้เคย consent กับบอทใดบอทหนึ่งแล้ว ถือว่า consent แล้ว (ใช้ได้กับทุกบอท)
         * - เช็คจาก line_user_id แทน user_id เพื่อให้ consent ใช้ได้ข้ามบอท
         */
        function checkUserConsent($db, $userId, $lineUserId = null) {
            try {
                // ตรวจสอบว่ามี column consent_privacy หรือไม่
                $hasConsentCols = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_privacy'");
                    $hasConsentCols = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                // ถ้ายังไม่มี columns ให้ผ่านไปก่อน (ยังไม่ได้ run migration)
                if (!$hasConsentCols) {
                    return true;
                }
                
                // ตรวจสอบว่ามี column consent_at หรือไม่
                $hasConsentAt = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_at'");
                    $hasConsentAt = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                // ถ้ามี lineUserId ให้เช็คจาก line_user_id (ข้ามบอทได้)
                if ($lineUserId) {
                    // เช็คว่าผู้ใช้คนนี้เคย consent กับบอทใดบอทหนึ่งแล้วหรือยัง
                    $stmt = $db->prepare("SELECT id, consent_privacy, consent_terms FROM users WHERE line_user_id = ? AND consent_privacy = 1 AND consent_terms = 1 LIMIT 1");
                    $stmt->execute([$lineUserId]);
                    $consentedUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($consentedUser) {
                        // ถ้าเคย consent แล้ว ให้ copy consent ไปยัง user record ปัจจุบัน (ถ้าต่าง id)
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
                
                // ตรวจสอบจาก users table ตาม user_id
                $stmt = $db->prepare("SELECT consent_privacy, consent_terms FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && $user['consent_privacy'] && $user['consent_terms']) {
                    return true;
                }
                
                // ตรวจสอบจาก user_consents table
                try {
                    // เช็คจาก line_user_id ก่อน (ข้ามบอทได้)
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
                        // Copy consent ไปยัง user record ปัจจุบัน
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
                    // ถ้า user_consents table ไม่มี ให้ดูจาก users table อย่างเดียว
                    return false;
                }
                
            } catch (Exception $e) {
                // ถ้า error ให้ผ่านไปก่อน (ไม่ block user)
                return true;
            }
        }
        
        /**
         * Get or Create User - ตรวจสอบและบันทึกผู้ใช้เสมอ (ไม่ว่าจะมาจากกลุ่มหรือแชทส่วนตัว)
         */
        function getOrCreateUser($db, $line, $userId, $lineAccountId = null, $groupId = null) {
            // ตรวจสอบว่ามีผู้ใช้อยู่แล้วหรือไม่
            $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // ถ้ายังไม่มี ให้สร้างใหม่
            if (!$user) {
                // ดึงข้อมูลโปรไฟล์จาก LINE
                $profile = null;
                try {
                    if ($groupId) {
                        // ถ้ามาจากกลุ่ม ใช้ getGroupMemberProfile
                        $profile = $line->getGroupMemberProfile($groupId, $userId);
                    } else {
                        // ถ้ามาจากแชทส่วนตัว ใช้ getProfile
                        $profile = $line->getProfile($userId);
                    }
                } catch (Exception $e) {
                    error_log("getOrCreateUser profile error: " . $e->getMessage());
                }
                
                $displayName = $profile['displayName'] ?? 'Unknown';
                $pictureUrl = $profile['pictureUrl'] ?? '';
                $statusMessage = $profile['statusMessage'] ?? '';
                
                // บันทึกผู้ใช้ใหม่
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
                    
                    // บันทึกเป็น follower ด้วย (ถ้ามี lineAccountId)
                    if ($lineAccountId) {
                        saveAccountFollower($db, $lineAccountId, $userId, $user['id'], $profile, true);
                    }
                    
                } catch (Exception $e) {
                    error_log("getOrCreateUser insert error: " . $e->getMessage());
                    // ลองดึงอีกครั้ง (อาจมี race condition)
                    $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } else {
                // ถ้ามีอยู่แล้ว แต่ยังไม่มี line_account_id ให้อัพเดท
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
         * Save account follower - บันทึกข้อมูล follower แยกตามบอท
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
         * Save account event - บันทึก event แยกตามบอท
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
         * Update account daily stats - อัพเดทสถิติรายวัน
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
         * Update follower interaction - อัพเดทข้อมูล interaction ของ follower
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
            
            // เพิ่มชื่อบอทในข้อความ
            $botInfo = $accountName ? " [บอท: {$accountName}]" : "";

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
                // ดึงข้อมูลโดยไม่ตรวจสอบ expires_at ใน SQL
                $stmt = $db->prepare("SELECT * FROM user_states WHERE user_id = ?");
                $stmt->execute([$userId]);
                $state = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($state) {
                    // ตรวจสอบ expires_at ใน PHP
                    $expired = $state['expires_at'] && strtotime($state['expires_at']) < time();
                    if ($expired) {
                        // State หมดอายุ - ลบทิ้ง
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
                        'สร้างจากแชท - ลูกค้ายืนยัน'
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
                    $itemsList .= ($i + 1) . ". {$item['name']}\n   ฿" . number_format($item['price'] ?? 0) . " x {$item['qty']} = ฿" . number_format($itemTotal) . "\n";
                }
                
                $confirmMessage = [
                    'type' => 'flex',
                    'altText' => "✅ สร้างออเดอร์สำเร็จ #{$orderNumber}",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#10B981',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => '✅ สร้างออเดอร์สำเร็จ', 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center']
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '15px',
                            'contents' => [
                                ['type' => 'text', 'text' => "เลขที่: #{$orderNumber}", 'size' => 'md', 'weight' => 'bold', 'color' => '#10B981'],
                                ['type' => 'separator', 'margin' => 'md'],
                                ['type' => 'text', 'text' => '📦 รายการสินค้า', 'size' => 'sm', 'weight' => 'bold', 'margin' => 'md'],
                                ['type' => 'text', 'text' => $itemsList, 'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm'],
                                ['type' => 'separator', 'margin' => 'md'],
                                ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                                    ['type' => 'text', 'text' => '💰 รวมทั้งหมด', 'size' => 'md', 'weight' => 'bold'],
                                    ['type' => 'text', 'text' => '฿' . number_format($total), 'size' => 'lg', 'weight' => 'bold', 'color' => '#10B981', 'align' => 'end']
                                ]],
                                ['type' => 'text', 'text' => '📱 กรุณาชำระเงินและส่งสลิปมาค่ะ', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'lg']
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
                    'text' => "❌ ขออภัยค่ะ เกิดข้อผิดพลาดในการสร้างออเดอร์\n\nกรุณาลองใหม่อีกครั้งหรือติดต่อเจ้าหน้าที่ค่ะ 🙏"
                ];
                $line->replyMessage($replyToken, [$errorMessage]);
                
                return false;
            }
        }

        /**
         * Handle slip command - เมื่อลูกค้าพิมพ์ "สลิป"
         */
        function handleSlipCommand($db, $line, $dbUserId, $replyToken) {
            devLog($db, 'debug', 'handleSlipCommand', 'Start', ['user_id' => $dbUserId]);
            
            // Check if user has pending order - ลองหาจาก transactions ก่อน แล้วค่อย orders
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
                $line->replyMessage($replyToken, "❌ คุณยังไม่มีคำสั่งซื้อที่รอชำระเงิน\n\nพิมพ์ 'shop' เพื่อเริ่มช้อปปิ้ง");
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
                        ['type' => 'text', 'text' => '฿' . number_format($item['subtotal']), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
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
                        ['type' => 'text', 'text' => '💚', 'size' => 'sm', 'flex' => 0],
                        ['type' => 'text', 'text' => 'พร้อมเพย์: ' . $settings['promptpay_number'], 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
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
                                ['type' => 'text', 'text' => '🏦', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => "{$bank['name']}: {$bank['account']}", 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
                            ]
                        ],
                        ['type' => 'text', 'text' => "   ชื่อ: {$bank['holder']}", 'size' => 'xs', 'color' => '#888888']
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
                        ['type' => 'text', 'text' => "ออเดอร์ #{$orderNum}", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => '⏳ รอชำระเงิน', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold']
                            ]
                        ],
                        ['type' => 'separator', 'margin' => 'lg'],
                        ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
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
                                ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
                                ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                            ]
                        ],
                        ['type' => 'separator', 'margin' => 'lg'],
                        ['type' => 'text', 'text' => '📌 ช่องทางชำระเงิน:', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $paymentContents
                        ],
                        ['type' => 'text', 'text' => '📸 กรุณาส่งรูปสลิปมาเลย', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold', 'margin' => 'lg', 'wrap' => true],
                        ['type' => 'text', 'text' => '(ภายใน 10 นาที)', 'size' => 'xs', 'color' => '#888888']
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '📞 ติดต่อเรา', 'uri' => 'tel:' . ($settings['contact_phone'] ?? '0000000000')], 'style' => 'link']
                    ]
                ]
            ];
            
            $line->replyMessage($replyToken, [
                ['type' => 'flex', 'altText' => "ออเดอร์ #{$orderNum} - รอชำระเงิน", 'contents' => $bubble]
            ]);
            return true;
        }

        /**
         * Handle payment slip for specific order
         */
        function handlePaymentSlipForOrder($db, $line, $dbUserId, $messageId, $replyToken, $orderId) {
            // Get order - ลองหาจากทั้ง orders และ transactions
            $order = null;
            $orderTable = 'orders';
            
            // ลองหาจาก orders ก่อน
            try {
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
                $stmt->execute([$orderId, $dbUserId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            
            // ถ้าไม่เจอ ลองหาจาก transactions
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
                $line->replyMessage($replyToken, "❌ ไม่พบคำสั่งซื้อ กรุณาลองใหม่");
                return true;
            }
            
            // Download image from LINE and save
            $imageData = $line->getMessageContent($messageId);
            if (!$imageData || strlen($imageData) < 100) {
                $line->replyMessage($replyToken, "❌ ไม่สามารถรับรูปภาพได้ กรุณาส่งใหม่อีกครั้ง");
                return true;
            }
            
            // Save image
            $uploadDir = __DIR__ . '/uploads/slips/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    $line->replyMessage($replyToken, "❌ ระบบมีปัญหา ไม่สามารถบันทึกรูปได้ กรุณาติดต่อแอดมิน");
                    return true;
                }
            }
            
            // Check if directory is writable
            if (!is_writable($uploadDir)) {
                $line->replyMessage($replyToken, "❌ ระบบมีปัญหา (permission) กรุณาติดต่อแอดมิน");
                return true;
            }
            
            $filename = 'slip_' . $order['order_number'] . '_' . time() . '.jpg';
            $filepath = $uploadDir . $filename;
            
            $bytesWritten = file_put_contents($filepath, $imageData);
            if ($bytesWritten === false || $bytesWritten < 100) {
                $line->replyMessage($replyToken, "❌ ไม่สามารถบันทึกรูปได้ กรุณาส่งใหม่");
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
            $slipMessage = FlexTemplates::toMessage($slipBubble, "ได้รับสลิปออเดอร์ #{$orderNum} แล้ว");
            $slipMessage = FlexTemplates::withQuickReply($slipMessage, [
                ['label' => '📦 เช็คสถานะ', 'text' => 'orders'],
                ['label' => '🛒 ช้อปต่อ', 'text' => 'shop']
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
            
            $caption = "💳 <b>สลิปการชำระเงิน!</b>\n\n";
            $caption .= "📋 ออเดอร์: #{$order['order_number']}\n";
            $caption .= "👤 ลูกค้า: {$user['display_name']}\n";
            $caption .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
            $caption .= "📅 เวลา: " . date('d/m/Y H:i') . "\n\n";
            $caption .= "🔗 <a href=\"{$baseUrl}/shop/order-detail.php?id={$order['id']}\">ตรวจสอบ</a>";
            
            $telegram->sendPhoto($imageData, $caption, $dbUserId);
        }

        /**
         * Handle payment slip - ตรวจสอบและบันทึกสลิปการชำระเงิน (legacy - use transactions)
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
            $line->replyMessage($replyToken, "✅ ได้รับหลักฐานการชำระเงินแล้ว!\n\n📋 คำสั่งซื้อ: #{$order['order_number']}\n💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n\n⏳ กรุณารอการตรวจสอบจากทางร้าน\nจะแจ้งผลให้ทราบเร็วๆ นี้");
            
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
                
                $caption = "💳 <b>สลิปการชำระเงิน!</b>\n\n";
                $caption .= "📋 คำสั่งซื้อ: #{$order['order_number']}\n";
                $caption .= "👤 ลูกค้า: {$user['display_name']}\n";
                $caption .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
                $caption .= "📅 เวลา: " . date('Y-m-d H:i:s') . "\n\n";
                $caption .= "🔗 <a href=\"{$baseUrl}/shop/order-detail.php?id={$order['id']}\">ดูรายละเอียด</a>";
                
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
            $caption = "💬 <b>ข้อความใหม่!</b>\n\n";
            $caption .= "👤 จาก: {$displayName}\n";
            $caption .= "📅 เวลา: " . date('Y-m-d H:i:s') . "\n";
            $caption .= "\n💡 <i>ตอบกลับ:</i> <code>/r {$dbUserId} ข้อความ</code>";

            if ($messageType === 'image') {
                // Get image content from LINE
                $imageData = $line->getMessageContent($messageId);
                if ($imageData) {
                    $telegram->sendPhoto($imageData, $caption, $dbUserId);
                } else {
                    $telegram->notifyNewMessage($displayName, "[รูปภาพ] ไม่สามารถโหลดได้", '', $dbUserId);
                }
            } elseif ($messageType === 'video') {
                $telegram->notifyNewMessage($displayName, "[วิดีโอ] ID: {$messageId}", '', $dbUserId);
            } elseif ($messageType === 'audio') {
                $telegram->notifyNewMessage($displayName, "[เสียง] ID: {$messageId}", '', $dbUserId);
            } elseif ($messageType === 'sticker') {
                $stickerId = $messageData['stickerId'] ?? '';
                $packageId = $messageData['packageId'] ?? '';
                // LINE sticker URL
                $stickerUrl = "https://stickershop.line-scdn.net/stickershop/v1/sticker/{$stickerId}/iPhone/sticker.png";
                $telegram->sendPhotoUrl($stickerUrl, "🎨 <b>สติกเกอร์</b>\n\n👤 จาก: {$displayName}\n\n💡 <code>/r {$dbUserId} ข้อความ</code>", $dbUserId);
            } elseif ($messageType === 'location') {
                $lat = $messageData['latitude'] ?? 0;
                $lng = $messageData['longitude'] ?? 0;
                $address = $messageData['address'] ?? '';
                $telegram->sendLocation($lat, $lng, "📍 <b>ตำแหน่ง</b>\n\n👤 จาก: {$displayName}\n📍 {$address}\n\n💡 <code>/r {$dbUserId} ข้อความ</code>", $dbUserId);
            } else {
                $telegram->notifyNewMessage($displayName, "[{$messageType}]", '', $dbUserId);
            }
        }

        /**
         * Ensure group exists in database - สร้างกลุ่มอัตโนมัติถ้ายังไม่มี
         * ใช้เมื่อได้รับ event จากกลุ่มที่บอทอยู่แล้วแต่ยังไม่มีในระบบ
         */
        function ensureGroupExists($db, $line, $lineAccountId, $groupId, $sourceType = 'group') {
            if (!$lineAccountId || !$groupId) return;
            
            try {
                // ตรวจสอบว่ามีกลุ่มนี้ในระบบหรือยัง
                $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
                $stmt->execute([$lineAccountId, $groupId]);
                
                if ($stmt->fetch()) {
                    return; // มีอยู่แล้ว ไม่ต้องทำอะไร
                }
                
                // ยังไม่มี - ดึงข้อมูลกลุ่มจาก LINE API
                $groupInfo = [];
                try {
                    if ($sourceType === 'group') {
                        $groupInfo = $line->getGroupSummary($groupId);
                    }
                } catch (Exception $e) {
                    // API อาจ fail ถ้าบอทไม่มีสิทธิ์
                }
                
                $groupName = $groupInfo['groupName'] ?? 'Unknown Group';
                $pictureUrl = $groupInfo['pictureUrl'] ?? null;
                $memberCount = $groupInfo['memberCount'] ?? 0;
                
                // บันทึกกลุ่มใหม่
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
                // Ignore errors - ไม่ให้กระทบ flow หลัก
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
                
                // ไม่ส่งข้อความเข้ากลุ่มเพื่อประหยัด quota
                // (ถ้าต้องการส่ง สามารถเปิด comment ด้านล่างได้)
                // $botName = getAccountName($db, $lineAccountId) ?: 'Bot';
                // $welcomeBubble = FlexTemplates::groupWelcome($groupName, $botName);
                // $welcomeMessage = FlexTemplates::toMessage($welcomeBubble, "สวัสดีจาก {$botName}!");
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
                
                // ไม่ส่งข้อความต้อนรับสมาชิกใหม่เพื่อประหยัด quota
                // (ถ้าต้องการส่ง สามารถเปิด comment ด้านล่างได้)
                /*
                if (count($members) > 0) {
                    $names = [];
                    foreach ($members as $member) {
                        $userId = $member['userId'] ?? null;
                        if ($userId) {
                            $profile = $line->getGroupMemberProfile($groupId, $userId);
                            $names[] = $profile['displayName'] ?? 'สมาชิกใหม่';
                        }
                    }
                    $nameList = implode(', ', array_slice($names, 0, 3));
                    if (count($names) > 3) $nameList .= ' และอีก ' . (count($names) - 3) . ' คน';
                    
                    $welcomeText = "🎉 ยินดีต้อนรับ {$nameList} เข้าสู่กลุ่ม!\n\n💡 พิมพ์ 'menu' เพื่อดูคำสั่งที่ใช้ได้";
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
         * Update group stats - อัพเดทสถิติกลุ่ม
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
                $botInfo = $accountName ? " [บอท: {$accountName}]" : "";
                
                if ($type === 'join') {
                    $message = "🎉 <b>บอทถูกเชิญเข้ากลุ่ม!</b>\n\n";
                    $message .= "👥 กลุ่ม: {$groupName}\n";
                    $message .= "🤖 {$botInfo}\n";
                    $message .= "📅 เวลา: " . date('d/m/Y H:i:s');
                } else {
                    $message = "👋 <b>บอทออกจากกลุ่ม</b>\n\n";
                    $message .= "👥 กลุ่ม: {$groupName}\n";
                    $message .= "🤖 {$botInfo}\n";
                    $message .= "📅 เวลา: " . date('d/m/Y H:i:s');
                }
                
                $telegram->sendMessage($message);
                
            } catch (Exception $e) {
                error_log("notifyGroupEvent error: " . $e->getMessage());
            }
        }

        // ==================== AI Pause/Resume Functions ====================
        
        /**
         * ตรวจสอบว่า AI ถูก pause สำหรับ user นี้หรือไม่
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
         * Pause AI สำหรับ user (หน่วยเป็นนาที)
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
         * Resume AI สำหรับ user (ยกเลิก pause)
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
         * ดึง AI mode ปัจจุบันของ user
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
         * ตั้ง AI mode สำหรับ user (หมดอายุใน 10 นาที)
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
         * ลบ AI mode ของ user (ออกจากโหมด)
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
         * แจ้งเตือนเภสัชกรเมื่อลูกค้าขอคุยกับคนจริง
         */
        function notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $message) {
            try {
                // Get user info
                $stmt = $db->prepare("SELECT display_name, line_user_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $displayName = $user['display_name'] ?? 'Unknown';
                $lineUserId = $user['line_user_id'] ?? '';
                
                // 1. บันทึกลง pharmacist_queue (ถ้ามี table)
                try {
                    $stmt = $db->prepare("
                        INSERT INTO pharmacist_queue (user_id, line_account_id, request_type, message, status, created_at)
                        VALUES (?, ?, 'human_request', ?, 'pending', NOW())
                    ");
                    $stmt->execute([$userId, $lineAccountId, $message]);
                } catch (Exception $e) {
                    // Table might not exist
                }
                
                // 2. แจ้งเตือนผ่าน Telegram
                $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
                $stmt->execute();
                $telegramSettings = $stmt->fetch();
                
                if ($telegramSettings && $telegramSettings['is_enabled']) {
                    $telegram = new TelegramAPI();
                    $accountName = getAccountName($db, $lineAccountId);
                    
                    $text = "🚨 <b>ลูกค้าขอคุยกับเภสัชกรจริง!</b>\n\n";
                    $text .= "👤 ลูกค้า: {$displayName}\n";
                    $text .= "💬 ข้อความ: {$message}\n";
                    if ($accountName) $text .= "🤖 บอท: {$accountName}\n";
                    $text .= "📅 เวลา: " . date('d/m/Y H:i:s') . "\n\n";
                    $text .= "⏰ บอทจะหยุดตอบ 20 นาที\n";
                    $text .= "💡 ตอบกลับ: <code>/r {$userId} ข้อความ</code>";
                    
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
