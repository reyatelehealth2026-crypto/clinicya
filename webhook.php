  1 <?php
   2 /**
   3  * LINE Webhook Handler - Multi-Account Support
   4  * V2.5 - Universal Business Platform
   5  */
   6 
   7 // Global error handler for webhook
   8 set_error_handler(function($severity, $message, $file, $line) {
   9     throw new ErrorException($message, 0, $severity, $file, $line);
  10 });
  11 
  12 // Catch all errors and log them
  13 register_shutdown_function(function() {
  14     $error = error_get_last();
  15     if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
  16         try {
  17             $db = Database::getInstance()->getConnection();
  18             $stmt = $db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES ('error', 'webhook_fatal', ?, ?, NOW())");
  19             $stmt->execute([
  20                 $error['message'],
  21                 json_encode(['file' => $error['file'], 'line' => $error['line'], 'type' => $error['type']])
  22             ]);
  23         } catch (Exception $e) {
  24             error_log("Webhook fatal error: " . $error['message']);
  25         }
  26     }
  27 });
  28 
  29 require_once 'config/config.php';
  30 require_once 'config/database.php';
  31 require_once 'classes/ActivityLogger.php';
  32 require_once 'classes/LineAPI.php';
  33 require_once 'classes/LineAccountManager.php';
  34 require_once 'classes/OpenAI.php';
  35 require_once 'classes/TelegramAPI.php';
  36 require_once 'classes/FlexTemplates.php';
  37 
  38 // V2.5: Load BusinessBot if available, fallback to ShopBot
  39 if (file_exists(__DIR__ . '/classes/BusinessBot.php')) {
  40     require_once 'classes/BusinessBot.php';
  41 }
  42 if (file_exists(__DIR__ . '/classes/ShopBot.php')) {
  43     require_once 'classes/ShopBot.php';
  44 }
  45 if (file_exists(__DIR__ . '/classes/CRMManager.php')) {
  46     require_once 'classes/CRMManager.php';
  47 }
  48 if (file_exists(__DIR__ . '/classes/AutoTagManager.php')) {
  49     require_once 'classes/AutoTagManager.php';
  50 }
  51 // LIFF Message Handler for processing LIFF-triggered messages
  52 if (file_exists(__DIR__ . '/classes/LiffMessageHandler.php')) {
  53     require_once 'classes/LiffMessageHandler.php';
  54 }
  55 
  56 // Get request body and signature
  57 $body = file_get_contents('php://input');
  58 $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
  59 
  60 $db = Database::getInstance()->getConnection();
  61 
  62 // Multi-account support: ตรวจสอบว่ามาจาก account ไหน
  63 $lineAccountId = null;
  64 $lineAccount = null;
  65 $line = null;
  66 
  67 // Try to get account from query parameter first
  68 if (isset($_GET['account'])) {
  69     $manager = new LineAccountManager($db);
  70     $lineAccount = $manager->getAccountById($_GET['account']);
  71     if ($lineAccount) {
  72         $line = new LineAPI($lineAccount['channel_access_token'], $lineAccount['channel_secret']);
  73         if ($line->validateSignature($body, $signature)) {
  74             $lineAccountId = $lineAccount['id'];
  75         } else {
  76             $lineAccount = null;
  77             $line = null;
  78         }
  79     }
  80 }
  81 
  82 // If no account from parameter, try to find by signature
  83 if (!$lineAccount) {
  84     try {
  85         $manager = new LineAccountManager($db);
  86         $lineAccount = $manager->validateAndGetAccount($body, $signature);
  87         if ($lineAccount) {
  88             $lineAccountId = $lineAccount['id'];
  89             $line = new LineAPI($lineAccount['channel_access_token'], $lineAccount['channel_secret']);
  90         }
  91     } catch (Exception $e) {
  92         // Table doesn't exist, use default
  93     }
  94 }
  95 
  96 // Fallback to default config
  97 if (!$line) {
  98     $line = new LineAPI();
  99     if (!$line->validateSignature($body, $signature)) {
 100         http_response_code(400);
 101             exit('Invalid signature');
 102         }
 103     }
 104 
 105         $events = json_decode($body, true)['events'] ?? [];
 106 
 107     /**
 108      * แสดง Loading Animation ใน LINE Chat
 109      * @param LineAPI $line - LINE API instance
 110      * @param string $chatId - User ID หรือ Group ID
 111      * @param int $seconds - จำนวนวินาที (5-60)
 112      */
 113     function showLoadingAnimation($line, $chatId, $seconds = 10) {
 114         try {
 115             $url = 'https://api.line.me/v2/bot/chat/loading/start';
 116             $data = [
 117                 'chatId' => $chatId,
 118                 'loadingSeconds' => min(max($seconds, 5), 60) // 5-60 seconds
 119             ];
 120             
 121             $ch = curl_init($url);
 122             curl_setopt_array($ch, [
 123                 CURLOPT_RETURNTRANSFER => true,
 124                 CURLOPT_POST => true,
 125                 CURLOPT_HTTPHEADER => [
 126                     'Content-Type: application/json',
 127                     'Authorization: Bearer ' . $line->getAccessToken()
 128                 ],
 129                 CURLOPT_POSTFIELDS => json_encode($data),
 130                 CURLOPT_TIMEOUT => 5
 131             ]);
 132             
 133             $response = curl_exec($ch);
 134             $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 135             curl_close($ch);
 136             
 137             return $httpCode === 200;
 138         } catch (Exception $e) {
 139             error_log("showLoadingAnimation error: " . $e->getMessage());
 140             return false;
 141         }
 142     }
 143 
 144     // Log incoming webhook
 145     if (!empty($events)) {
 146         try {
 147             devLog($db, 'webhook', 'webhook', 'Incoming webhook', [
 148                 'event_count' => count($events),
 149                 'account_id' => $lineAccountId,
 150                 'events' => array_map(fn($e) => $e['type'] ?? 'unknown', $events)
 151             ]);
 152         } catch (Exception $e) {}
 153     }
 154 
 155     foreach ($events as $event) {
 156         try {
 157             $userId = $event['source']['userId'] ?? null;
 158             $replyToken = $event['replyToken'] ?? null;
 159             $sourceType = $event['source']['type'] ?? 'user';
 160             $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
 161             
 162             // Handle join/leave events (ไม่ต้องมี userId)
 163             if ($event['type'] === 'join') {
 164                 handleJoinGroup($event, $db, $line, $lineAccountId);
 165                 continue;
 166             }
 167             if ($event['type'] === 'leave') {
 168                 handleLeaveGroup($event, $db, $lineAccountId);
 169                 continue;
 170             }
 171             
 172             // สำหรับ event จากกลุ่ม - ตรวจสอบและสร้างกลุ่มอัตโนมัติถ้ายังไม่มี
 173             if (($sourceType === 'group' || $sourceType === 'room') && $groupId && $lineAccountId) {
 174                 // ตรวจสอบและสร้างกลุ่มอัตโนมัติ
 175                 ensureGroupExists($db, $line, $lineAccountId, $groupId, $sourceType);
 176                 
 177                 if ($userId) {
 178                     // บันทึกผู้ใช้จากกลุ่ม
 179                     $groupUser = getOrCreateUser($db, $line, $userId, $lineAccountId, $groupId);
 180                     $dbUserId = $groupUser['id'] ?? null;
 181                     
 182                     // บันทึก event พร้อม source_id (groupId)
 183                     saveAccountEvent($db, $lineAccountId, $event['type'], $userId, $dbUserId, $event);
 184                     
 185                     // อัพเดทสถิติกลุ่ม
 186                     updateGroupStats($db, $lineAccountId, $groupId, $event['type']);
 187                 }
 188                 // Skip saveAccountEvent if no userId (bot events from group)
 189             }
 190             
 191             if (!$userId) continue;
 192             
 193             // Deduplication: ป้องกันการประมวลผล event ซ้ำ
 194             $webhookEventId = $event['webhookEventId'] ?? null;
 195             $messageText = $event['message']['text'] ?? '';
 196             
 197             // Log ทุก event ที่เข้ามา
 198             devLog($db, 'debug', 'webhook', 'Event received', [
 199                 'event_id' => $webhookEventId ? substr($webhookEventId, 0, 20) : 'none',
 200                 'type' => $event['type'] ?? 'unknown',
 201                 'message' => mb_substr($messageText, 0, 30),
 202                 'user_id' => $userId
 203             ], $userId);
 204             
 205             if ($webhookEventId) {
 206                 try {
 207                     $stmt = $db->prepare("SELECT id FROM webhook_events WHERE event_id = ?");
 208                     $stmt->execute([$webhookEventId]);
 209                     if ($stmt->fetch()) {
 210                         devLog($db, 'warning', 'webhook', 'Duplicate event skipped', [
 211                             'event_id' => substr($webhookEventId, 0, 20)
 212                         ], $userId);
 213                         continue; // Event นี้ถูกประมวลผลแล้ว
 214                     }
 215                     // บันทึก event ID
 216                     $stmt = $db->prepare("INSERT INTO webhook_events (event_id) VALUES (?)");
 217                     $stmt->execute([$webhookEventId]);
 218                 } catch (Exception $e) {
 219                     // Table doesn't exist or duplicate key - ignore and continue
 220                 }
 221             }
 222 
 223             switch ($event['type']) {
 224                 case 'follow':
 225                     // Follow event มี replyToken - ใช้ reply แทน push เพื่อประหยัด quota
 226                     handleFollow($userId, $replyToken, $db, $line, $lineAccountId, $event);
 227                     break;
 228                 case 'unfollow':
 229                     handleUnfollow($userId, $db, $lineAccountId, $event);
 230                     break;
 231                 case 'message':
 232                     handleMessage($event, $userId, $replyToken, $db, $line, $lineAccountId);
 233                     break;
 234             case 'postback':
 235                 // บันทึก postback event
 236                 $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
 237                 $stmt->execute([$userId]);
 238                 $dbUserId = $stmt->fetchColumn();
 239                 
 240                 if ($lineAccountId) {
 241                     saveAccountEvent($db, $lineAccountId, 'postback', $userId, $dbUserId, $event);
 242                 }
 243                 
 244                 // Handle Broadcast Product Click - Auto Tag
 245                 $postbackData = $event['postback']['data'] ?? '';
 246                 
 247                 // รองรับทั้ง 2 รูปแบบ: broadcast_click_{id}_{id} หรือ JSON {"action":"broadcast_click",...}
 248                 $isBroadcastClick = false;
 249                 if (strpos($postbackData, 'broadcast_click_') === 0) {
 250                     $isBroadcastClick = true;
 251                 } elseif (strpos($postbackData, '{') === 0) {
 252                     $jsonData = json_decode($postbackData, true);
 253                     if ($jsonData && ($jsonData['action'] ?? '') === 'broadcast_click') {
 254                         $isBroadcastClick = true;
 255                     }
 256                 }
 257                 
 258                 if ($isBroadcastClick && $dbUserId) {
 259                     handleBroadcastClick($db, $line, $dbUserId, $userId, $postbackData, $replyToken, $lineAccountId);
 260                 }
 261                 break;
 262             case 'beacon':
 263                 // บันทึก beacon event
 264                 if ($lineAccountId) {
 265                     $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
 266                     $stmt->execute([$userId]);
 267                     $dbUserId = $stmt->fetchColumn();
 268                     saveAccountEvent($db, $lineAccountId, 'beacon', $userId, $dbUserId, $event);
 269                 }
 270                 break;
 271             case 'memberJoined':
 272                 // สมาชิกใหม่เข้ากลุ่ม
 273                 if ($groupId && $lineAccountId) {
 274                     handleMemberJoined($event, $groupId, $db, $line, $lineAccountId);
 275                 }
 276                 break;
 277             case 'memberLeft':
 278                 // สมาชิกออกจากกลุ่ม
 279                 if ($groupId && $lineAccountId) {
 280                     handleMemberLeft($event, $groupId, $db, $lineAccountId);
 281                 }
 282                 break;
 283             }
 284             
 285             // ถ้าเป็นข้อความจากกลุ่ม ให้บันทึกด้วย
 286             if ($event['type'] === 'message' && $groupId && $lineAccountId) {
 287                 saveGroupMessage($db, $lineAccountId, $groupId, $userId, $event);
 288             }
 289         } catch (Exception $e) {
 290             // Log error to dev_logs
 291             devLog($db, 'error', 'webhook_event', $e->getMessage(), [
 292                 'event_type' => $event['type'] ?? 'unknown',
 293                 'user_id' => $userId ?? null,
 294                 'file' => $e->getFile(),
 295                 'line' => $e->getLine(),
 296                 'trace' => array_slice($e->getTrace(), 0, 5)
 297             ], $userId ?? null);
 298             error_log("Webhook event error: " . $e->getMessage());
 299         }
 300     }
 301 
 302         http_response_code(200);
 303 
 304     /**
 305      * Handle follow event
 306      * ใช้ replyToken เพื่อประหยัด quota (reply ฟรี, push นับ quota)
 307      */
 308     function handleFollow($userId, $replyToken, $db, $line, $lineAccountId = null, $event = null) {
 309         $profile = $line->getProfile($userId);
 310         $displayName = $profile['displayName'] ?? '';
 311         $pictureUrl = $profile['pictureUrl'] ?? '';
 312         $statusMessage = $profile['statusMessage'] ?? '';
 313         
 314         // Check if line_account_id column exists
 315         $hasAccountCol = false;
 316         try {
 317             $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
 318             $hasAccountCol = $stmt->rowCount() > 0;
 319         } catch (Exception $e) {}
 320         
 321         $dbUserId = null;
 322         if ($hasAccountCol && $lineAccountId) {
 323             $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name, picture_url, status_message) 
 324                                 VALUES (?, ?, ?, ?, ?) 
 325                                 ON DUPLICATE KEY UPDATE display_name = ?, picture_url = ?, is_blocked = 0");
 326             $stmt->execute([
 327                 $lineAccountId,
 328                 $userId,
 329                 $displayName,
 330                 $pictureUrl,
 331                 $statusMessage,
 332                 $displayName,
 333                 $pictureUrl
 334             ]);
 335             $dbUserId = $db->lastInsertId() ?: null;
 336         } else {
 337             $stmt = $db->prepare("INSERT INTO users (line_user_id, display_name, picture_url, status_message) 
 338                                 VALUES (?, ?, ?, ?) 
 339                                 ON DUPLICATE KEY UPDATE display_name = ?, picture_url = ?, is_blocked = 0");
 340             $stmt->execute([
 341                 $userId,
 342                 $displayName,
 343                 $pictureUrl,
 344                 $statusMessage,
 345                 $displayName,
 346                 $pictureUrl
 347             ]);
 348             $dbUserId = $db->lastInsertId() ?: null;
 349         }
 350         
 351         // Get user ID if not from insert
 352         if (!$dbUserId) {
 353             $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
 354             $stmt->execute([$userId]);
 355             $dbUserId = $stmt->fetchColumn();
 356         }
 357         
 358         // บันทึกข้อมูล follower แยกตามบอท
 359         if ($lineAccountId) {
 360             saveAccountFollower($db, $lineAccountId, $userId, $dbUserId, $profile, true);
 361             saveAccountEvent($db, $lineAccountId, 'follow', $userId, $dbUserId, $event);
 362             updateAccountDailyStats($db, $lineAccountId, 'new_followers');
 363         }
 364 
 365         // V2.5: CRM - Auto-tag new customer & trigger drip campaigns
 366         if ($dbUserId && class_exists('CRMManager')) {
 367             try {
 368                 $crm = new CRMManager($db, $lineAccountId);
 369                 $crm->onUserFollow($dbUserId);
 370             } catch (Exception $e) {
 371                 error_log("CRM onUserFollow error: " . $e->getMessage());
 372             }
 373         }
 374         
 375         // V2.5: Auto Tag Manager
 376         if ($dbUserId && class_exists('AutoTagManager')) {
 377             try {
 378                 $autoTag = new AutoTagManager($db, $lineAccountId);
 379                 $autoTag->onFollow($dbUserId);
 380             } catch (Exception $e) {
 381                 error_log("AutoTag onFollow error: " . $e->getMessage());
 382             }
 383         }
 384 
 385         // Dynamic Rich Menu - กำหนด Rich Menu ตามกฎอัตโนมัติ
 386         if ($dbUserId && $lineAccountId) {
 387             try {
 388                 if (file_exists(__DIR__ . '/classes/DynamicRichMenu.php')) {
 389                     require_once __DIR__ . '/classes/DynamicRichMenu.php';
 390                     $dynamicMenu = new DynamicRichMenu($db, $line, $lineAccountId);
 391                     $dynamicMenu->assignRichMenuByRules($dbUserId, $userId);
 392                 }
 393             } catch (Exception $e) {
 394                 error_log("DynamicRichMenu onFollow error: " . $e->getMessage());
 395             }
 396         }
 397 
 398         // Send welcome message - ใช้ reply แทน push เพื่อประหยัด quota!
 399         sendWelcomeMessage($db, $line, $userId, $replyToken, $lineAccountId);
 400 
 401         // Log analytics
 402         logAnalytics($db, 'follow', ['user_id' => $userId, 'line_account_id' => $lineAccountId], $lineAccountId);
 403 
 404         // Telegram notification พร้อมชื่อบอท
 405         $accountName = getAccountName($db, $lineAccountId);
 406         sendTelegramNotification($db, 'follow', $displayName, '', $userId, $dbUserId, $accountName);
 407     }
 408 
 409     /**
 410      * Send welcome message to new follower
 411      * ใช้ replyMessage เพื่อประหยัด quota (ฟรี!) ถ้ามี replyToken
 412      * ถ้าไม่มี replyToken จะ fallback ไปใช้ pushMessage
 413      * V5.1: ใช้ welcome_settings จากหลังบ้านเท่านั้น - ไม่มี default hardcode
 414      */
 415     function sendWelcomeMessage($db, $line, $userId, $replyToken = null, $lineAccountId = null) {
 416         try {
 417             // Get user profile for personalized message
 418             $profile = $line->getProfile($userId);
 419             $displayName = $profile['displayName'] ?? 'คุณลูกค้า';
 420             $pictureUrl = $profile['pictureUrl'] ?? null;
 421             
 422             // Get shop name - แยกตาม LINE Account
 423             $shopName = 'LINE Shop';
 424             try {
 425                 if ($lineAccountId) {
 426                     $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
 427                     $stmt->execute([$lineAccountId]);
 428                 } else {
 429                     $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
 430                 }
 431                 $shopSettings = $stmt->fetch();
 432                 if ($shopSettings && $shopSettings['shop_name']) $shopName = $shopSettings['shop_name'];
 433             } catch (Exception $e) {}
 434             
 435             // Helper function to send message (reply if possible, otherwise push)
 436             $sendMessage = function($messages) use ($line, $userId, $replyToken) {
 437                 if ($replyToken) {
 438                     // ใช้ reply - ฟรี ไม่นับ quota!
 439                     return $line->replyMessage($replyToken, $messages);
 440                 } else {
 441                     // Fallback to push - นับ quota
 442                     return $line->pushMessage($userId, $messages);
 443                 }
 444             };
 445             
 446             // Get welcome settings for this account - ใช้จากหลังบ้านเท่านั้น
 447             $welcomeSettings = null;
 448             try {
 449                 $stmt = $db->prepare("SELECT * FROM welcome_settings WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_enabled = 1 ORDER BY line_account_id DESC LIMIT 1");
 450                 $stmt->execute([$lineAccountId]);
 451                 $welcomeSettings = $stmt->fetch();
 452             } catch (Exception $e) {}
 453             
 454             // ถ้ามี welcome_settings ที่เปิดใช้งาน - ใช้ค่าจากนั้น
 455             if ($welcomeSettings) {
 456                 if ($welcomeSettings['message_type'] === 'text' && !empty($welcomeSettings['text_content'])) {
 457                     // Replace placeholders
 458                     $text = str_replace(['{name}', '{shop}'], [$displayName, $shopName], $welcomeSettings['text_content']);
 459                     $sendMessage([['type' => 'text', 'text' => $text]]);
 460                     return;
 461                 } elseif ($welcomeSettings['message_type'] === 'flex' && !empty($welcomeSettings['flex_content'])) {
 462                     $flexContent = json_decode($welcomeSettings['flex_content'], true);
 463                     if ($flexContent) {
 464                         // Replace placeholders in flex JSON
 465                         $flexJson = str_replace(['{name}', '{shop}'], [$displayName, $shopName], $welcomeSettings['flex_content']);
 466                         $flexContent = json_decode($flexJson, true);
 467                         $message = [
 468                             'type' => 'flex',
 469                             'altText' => "ยินดีต้อนรับคุณ{$displayName}",
 470                             'contents' => $flexContent
 471                         ];
 472                         $sendMessage([$message]);
 473                         return;
 474                     }
 475                 }
 476             }
 477             
 478             // ถ้าไม่มี welcome_settings - ไม่ส่งข้อความต้อนรับ (ให้ตั้งค่าจากหลังบ้าน)
 479             // Log เพื่อแจ้งให้ทราบว่ายังไม่ได้ตั้งค่า
 480             devLog($db, 'info', 'welcome_message', 'No welcome_settings configured', [
 481                 'line_account_id' => $lineAccountId,
 482                 'user_id' => $userId
 483             ], $userId);
 484             
 485         } catch (Exception $e) {
 486             // Table doesn't exist or error - ignore
 487             error_log("Welcome message error: " . $e->getMessage());
 488         }
 489     }
 490 
 491     /**
 492      * Handle Broadcast Product Click - ติด Tag อัตโนมัติเมื่อลูกค้ากดสินค้า
 493      */
 494     function handleBroadcastClick($db, $line, $dbUserId, $lineUserId, $postbackData, $replyToken, $lineAccountId) {
 495         try {
 496             $campaignId = null;
 497             $productId = null;
 498             $tagId = null;
 499             
 500             // รองรับ 2 รูปแบบ: string format หรือ JSON
 501             if (strpos($postbackData, '{') === 0) {
 502                 // JSON format: {"action":"broadcast_click","campaign_id":1,"product_id":2,"tag_id":3}
 503                 $jsonData = json_decode($postbackData, true);
 504                 if ($jsonData) {
 505                     $campaignId = (int)($jsonData['campaign_id'] ?? 0);
 506                     $productId = (int)($jsonData['product_id'] ?? 0);
 507                     $tagId = $jsonData['tag_id'] ?? null;
 508                 }
 509             } else {
 510                 // String format: broadcast_click_{campaignId}_{productId}
 511                 $parts = explode('_', $postbackData);
 512                 if (count($parts) >= 4) {
 513                     $campaignId = (int)$parts[2];
 514                     $productId = (int)$parts[3];
 515                 }
 516             }
 517             
 518             if (!$campaignId || !$productId) return;
 519             
 520             // ดึงข้อมูล item
 521             $stmt = $db->prepare("SELECT bi.*, bc.auto_tag_enabled, bc.name as campaign_name 
 522                                 FROM broadcast_items bi 
 523                                 JOIN broadcast_campaigns bc ON bi.broadcast_id = bc.id 
 524                                 WHERE bi.broadcast_id = ? AND bi.product_id = ?");
 525             $stmt->execute([$campaignId, $productId]);
 526             $item = $stmt->fetch(PDO::FETCH_ASSOC);
 527             
 528             if (!$item) return;
 529             
 530             // บันทึก click
 531             try {
 532                 $stmt = $db->prepare("INSERT INTO broadcast_clicks (broadcast_id, item_id, user_id, line_user_id, tag_assigned) VALUES (?, ?, ?, ?, ?)");
 533                 $stmt->execute([$campaignId, $item['id'], $dbUserId, $lineUserId, $item['auto_tag_enabled'] ? 1 : 0]);
 534                 
 535                 // อัพเดท click count
 536                 $stmt = $db->prepare("UPDATE broadcast_items SET click_count = click_count + 1 WHERE id = ?");
 537                 $stmt->execute([$item['id']]);
 538                 
 539                 $stmt = $db->prepare("UPDATE broadcast_campaigns SET click_count = click_count + 1 WHERE id = ?");
 540                 $stmt->execute([$campaignId]);
 541             } catch (Exception $e) {}
 542             
 543             // ติด Tag ถ้าเปิด auto tag
 544             // ใช้ tag_id จาก item หรือจาก JSON postback data
 545             $finalTagId = $item['tag_id'] ?? $tagId;
 546             if ($item['auto_tag_enabled'] && $finalTagId) {
 547                 try {
 548                     $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'broadcast')");
 549                     $stmt->execute([$dbUserId, $finalTagId]);
 550                     
 551                     // Log tag assignment
 552                     devLog($db, 'info', 'broadcast_auto_tag', "Auto tag assigned", [
 553                         'user_id' => $dbUserId,
 554                         'tag_id' => $finalTagId,
 555                         'campaign_id' => $campaignId,
 556                         'product_id' => $productId
 557                     ], $lineUserId);
 558                 } catch (Exception $e) {
 559                     error_log("Auto tag error: " . $e->getMessage());
 560                 }
 561             }
 562             
 563             // ตอบกลับลูกค้า
 564             $replyText = "✅ ขอบคุณที่สนใจ {$item['item_name']}\n\nทีมงานจะติดต่อกลับโดยเร็วที่สุดค่ะ 🙏";
 565             $line->replyMessage($replyToken, [['type' => 'text', 'text' => $replyText]]);
 566             
 567             // แจ้ง Telegram
 568             sendTelegramNotification($db, 'broadcast_click', $item['item_name'], "ลูกค้าสนใจสินค้า: {$item['item_name']}", $lineUserId, $dbUserId);
 569             
 570         } catch (Exception $e) {
 571             error_log("handleBroadcastClick error: " . $e->getMessage());
 572         }
 573     }
 574 
 575     /**
 576      * Handle unfollow event
 577      */
 578     function handleUnfollow($userId, $db, $lineAccountId = null, $event = null) {
 579         $stmt = $db->prepare("UPDATE users SET is_blocked = 1 WHERE line_user_id = ?");
 580         $stmt->execute([$userId]);
 581 
 582         // Get user info for notification
 583         $stmt = $db->prepare("SELECT id, display_name FROM users WHERE line_user_id = ?");
 584         $stmt->execute([$userId]);
 585         $user = $stmt->fetch();
 586         $dbUserId = $user['id'] ?? null;
 587         $displayName = $user['display_name'] ?? 'Unknown';
 588         
 589         // บันทึกข้อมูล unfollow แยกตามบอท
 590         if ($lineAccountId) {
 591             saveAccountFollower($db, $lineAccountId, $userId, $dbUserId, null, false);
 592             saveAccountEvent($db, $lineAccountId, 'unfollow', $userId, $dbUserId, $event);
 593             updateAccountDailyStats($db, $lineAccountId, 'unfollowers');
 594         }
 595 
 596         logAnalytics($db, 'unfollow', ['user_id' => $userId, 'line_account_id' => $lineAccountId], $lineAccountId);
 597         
 598         // Telegram notification พร้อมชื่อบอท
 599         $accountName = getAccountName($db, $lineAccountId);
 600         sendTelegramNotification($db, 'unfollow', $displayName, '', $userId, $dbUserId, $accountName);
 601     }
 602 
 603     /**
 604      * Handle message event
 605      */
 606     function handleMessage($event, $userId, $replyToken, $db, $line, $lineAccountId = null) {
 607         try {
 608             $messageType = $event['message']['type'];
 609             $messageId = $event['message']['id'] ?? '';
 610             $messageText = $event['message']['text'] ?? '';
 611             $messageContent = $messageText;
 612             $sourceType = $event['source']['type'] ?? 'user';
 613             $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
 614             
 615             // Get markAsReadToken from message event (for LINE Mark as Read feature)
 616             $markAsReadToken = $event['message']['markAsReadToken'] ?? null;
 617 
 618             // Get or create user - ตรวจสอบและบันทึกผู้ใช้เสมอ (ไม่ว่าจะมาจากกลุ่มหรือแชทส่วนตัว)
 619             $user = getOrCreateUser($db, $line, $userId, $lineAccountId, $groupId);
 620             
 621             // ตรวจสอบว่าเป็นข้อความแรกหรือไม่ (นับจำนวนข้อความ incoming ของ user)
 622             // นับก่อนที่จะบันทึกข้อความใหม่ ดังนั้น == 0 คือข้อความแรก
 623             $isFirstMessage = false;
 624             try {
 625                 $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND direction = 'incoming'");
 626                 $stmt->execute([$user['id']]);
 627                 $messageCount = (int)$stmt->fetchColumn();
 628                 $isFirstMessage = ($messageCount == 0); // == 0 เพราะนับก่อนบันทึก
 629             } catch (Exception $e) {}
 630 
 631             // Check user state first (for waiting slip mode)
 632             $userState = getUserState($db, $user['id']);
 633             
 634             // Handle different message types
 635             $mediaUrl = null;
 636             if (in_array($messageType, ['image', 'video', 'audio', 'file'])) {
 637                 // ดาวน์โหลดและเก็บ media ไว้ใน server ทันที (LINE จะลบ content หลังจากผ่านไประยะหนึ่ง)
 638                 $savedMediaUrl = null;
 639                 if ($messageType === 'image') {
 640                     try {
 641                         $imageData = $line->getMessageContent($messageId);
 642                         if ($imageData && strlen($imageData) > 100) {
 643                             $uploadDir = __DIR__ . '/uploads/line_images/';
 644                             if (!is_dir($uploadDir)) {
 645                                 mkdir($uploadDir, 0755, true);
 646                             }
 647                             
 648                             // Detect extension from binary
 649                             $finfo = new finfo(FILEINFO_MIME_TYPE);
 650                             $mimeType = $finfo->buffer($imageData) ?: 'image/jpeg';
 651                             $ext = 'jpg';
 652                             if ($mimeType === 'image/png') $ext = 'png';
 653                             elseif ($mimeType === 'image/gif') $ext = 'gif';
 654                             elseif ($mimeType === 'image/webp') $ext = 'webp';
 655                             
 656                             $filename = 'line_' . $messageId . '_' . time() . '.' . $ext;
 657                             $filepath = $uploadDir . $filename;
 658                             
 659                             if (file_put_contents($filepath, $imageData)) {
 660                                 $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
 661                                 $host = $_SERVER['HTTP_HOST'] ?? (defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_HOST) : 'localhost');
 662                                 $savedMediaUrl = $protocol . $host . '/uploads/line_images/' . $filename;
 663                             }
 664                         }
 665                     } catch (Exception $e) {
 666                         error_log("Failed to save LINE image: " . $e->getMessage());
 667                     }
 668                 }
 669                 
 670                 // ถ้าบันทึกรูปได้ ใช้ URL ที่บันทึก ถ้าไม่ได้ใช้ LINE message ID เป็น fallback
 671                 if ($savedMediaUrl) {
 672                     $messageContent = $savedMediaUrl;
 673                 } else {
 674                     $messageContent = "[{$messageType}] ID: {$messageId}";
 675                 }
 676                 $mediaUrl = $messageId;
 677                 
 678                 // Check if user is in "waiting_slip" or "awaiting_slip" state - auto accept slip
 679                 if ($messageType === 'image' && $userState && in_array($userState['state'], ['waiting_slip', 'awaiting_slip'])) {
 680                     $stateData = json_decode($userState['state_data'] ?? '{}', true);
 681                     $orderId = $stateData['order_id'] ?? $stateData['transaction_id'] ?? null;
 682                     if ($orderId) {
 683                         // Save message first
 684                         $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', ?, ?, ?)");
 685                         $stmt->execute([$user['id'], $messageType, $messageContent, $replyToken]);
 686                         
 687                         // Handle slip
 688                         $slipHandled = handlePaymentSlipForOrder($db, $line, $user['id'], $messageId, $replyToken, $orderId);
 689                         if ($slipHandled) {
 690                             clearUserState($db, $user['id']);
 691                             return;
 692                         }
 693                     }
 694                 }
 695             } elseif ($messageType === 'sticker') {
 696                 $stickerId = $event['message']['stickerId'] ?? '';
 697                 $packageId = $event['message']['packageId'] ?? '';
 698                 $messageContent = "[sticker] Package: {$packageId}, Sticker: {$stickerId}";
 699             } elseif ($messageType === 'location') {
 700                 $lat = $event['message']['latitude'] ?? '';
 701                 $lng = $event['message']['longitude'] ?? '';
 702                 $address = $event['message']['address'] ?? '';
 703                 $messageContent = "[location] {$address} ({$lat}, {$lng})";
 704             }
 705 
 706             // Save incoming message พร้อม line_account_id, is_read = 0, และ mark_as_read_token
 707             try {
 708                 $stmt = $db->query("SHOW COLUMNS FROM messages LIKE 'line_account_id'");
 709                 if ($stmt->rowCount() > 0) {
 710                     // Check if mark_as_read_token column exists
 711                     $stmt3 = $db->query("SHOW COLUMNS FROM messages LIKE 'mark_as_read_token'");
 712                     $hasMarkAsReadToken = $stmt3->rowCount() > 0;
 713                     
 714                     // Check if is_read column exists
 715                     $stmt2 = $db->query("SHOW COLUMNS FROM messages LIKE 'is_read'");
 716                     if ($stmt2->rowCount() > 0) {
 717                         if ($hasMarkAsReadToken) {
 718                             $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, reply_token, is_read, mark_as_read_token) VALUES (?, ?, 'incoming', ?, ?, ?, 0, ?)");
 719                             $stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken, $markAsReadToken]);
 720                         } else {
 721                             $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, reply_token, is_read) VALUES (?, ?, 'incoming', ?, ?, ?, 0)");
 722                             $stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken]);
 723                         }
 724                     } else {
 725                         $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, reply_token) VALUES (?, ?, 'incoming', ?, ?, ?)");
 726                         $stmt->execute([$lineAccountId, $user['id'], $messageType, $messageContent, $replyToken]);
 727                     }
 728                 } else {
 729                     $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', ?, ?, ?)");
 730                     $stmt->execute([$user['id'], $messageType, $messageContent, $replyToken]);
 731                 }
 732             } catch (Exception $e) {
 733                 $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', ?, ?, ?)");
 734                 $stmt->execute([$user['id'], $messageType, $messageContent, $replyToken]);
 735             }
 736 
 737             logAnalytics($db, 'message_received', ['user_id' => $userId, 'type' => $messageType, 'line_account_id' => $lineAccountId, 'source' => $sourceType], $lineAccountId);
 738             
 739             // บันทึก reply_token ใน users table (หมดอายุใน 20 นาที)
 740             if ($replyToken) {
 741                 try {
 742                     // ตรวจสอบว่ามี column หรือไม่
 743                     $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'reply_token'");
 744                     if ($checkCol->rowCount() > 0) {
 745                         $expires = date('Y-m-d H:i:s', time() + (19 * 60)); // หมดอายุใน 19 นาที (เผื่อ delay)
 746                         $stmt = $db->prepare("UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?");
 747                         $stmt->execute([$replyToken, $expires, $user['id']]);
 748                     }
 749                 } catch (Exception $e) {
 750                     // Ignore error
 751                 }
 752             }
 753             
 754             // บันทึก event และอัพเดทสถิติแยกตามบอท
 755             if ($lineAccountId) {
 756                 saveAccountEvent($db, $lineAccountId, 'message', $userId, $user['id'], $event);
 757                 updateAccountDailyStats($db, $lineAccountId, 'incoming_messages');
 758                 updateAccountDailyStats($db, $lineAccountId, 'total_messages');
 759                 updateFollowerInteraction($db, $lineAccountId, $userId);
 760             }
 761             
 762             // Send Telegram notification with media support พร้อมชื่อบอท
 763             $accountName = getAccountName($db, $lineAccountId);
 764             $displayNameWithBot = $user['display_name'] . ($accountName ? " [{$accountName}]" : "");
 765             sendTelegramNotificationWithMedia($db, $line, $displayNameWithBot, $messageType, $messageContent, $messageId, $user['id'], $event['message']);
 766 
 767             // For non-text messages
 768             if ($messageType !== 'text') {
 769                 return; // Don't process non-text further, just notify via Telegram
 770             }
 771             
 772             // ========== ตรวจสอบ Pending Order - ลูกค้าตอบ "ยืนยัน" ==========
 773             // Debug: log user state
 774             devLog($db, 'debug', 'webhook', 'Checking pending order state', [
 775                 'user_id' => $user['id'],
 776                 'has_state' => $userState ? 'yes' : 'no',
 777                 'state' => $userState['state'] ?? 'none',
 778                 'message' => mb_substr($messageText, 0, 30)
 779             ], $userId);
 780             
 781             if ($userState && $userState['state'] === 'pending_order') {
 782                 $confirmKeywords = ['ยืนยัน', 'ตกลง', 'ok', 'yes', 'confirm', 'สั่งเลย', 'เอา', 'ได้'];
 783                 $cancelKeywords = ['ยกเลิก', 'cancel', 'no', 'ไม่เอา', 'ไม่'];
 784                 
 785                 $textLowerTrim = mb_strtolower(trim($messageText));
 786                 
 787                 devLog($db, 'debug', 'webhook', 'Pending order - checking keywords', [
 788                     'user_id' => $user['id'],
 789                     'text_lower' => $textLowerTrim,
 790                     'is_confirm' => in_array($textLowerTrim, $confirmKeywords) ? 'yes' : 'no'
 791                 ], $userId);
 792                 
 793                 if (in_array($textLowerTrim, $confirmKeywords)) {
 794                     // สร้าง Order จาก pending order
 795                     devLog($db, 'info', 'webhook', 'Creating order from pending state', [
 796                         'user_id' => $user['id']
 797                     ], $userId);
 798                     
 799                     $orderCreated = createOrderFromPendingState($db, $line, $user['id'], $userId, $userState, $replyToken, $lineAccountId);
 800                     if ($orderCreated) {
 801                         clearUserState($db, $user['id']);
 802                         return;
 803                     }
 804                 } elseif (in_array($textLowerTrim, $cancelKeywords)) {
 805                     // ยกเลิก pending order
 806                     clearUserState($db, $user['id']);
 807                     $cancelMessage = [
 808                         'type' => 'text',
 809                         'text' => "❌ ยกเลิกรายการสั่งซื้อแล้วค่ะ\n\nหากต้องการสั่งซื้อใหม่ สามารถแจ้งได้เลยค่ะ 🙏"
 810                     ];
 811                     $line->replyMessage($replyToken, [$cancelMessage]);
 812                     saveOutgoingMessage($db, $user['id'], json_encode($cancelMessage), 'system', 'text');
 813                     return;
 814                 }
 815             }
 816             
 817             // ========== ตรวจสอบ Consent PDPA ==========
 818             // ปิดการตรวจสอบ consent - ให้ถือว่า consent แล้วเสมอ
 819             // ถ้าต้องการเปิดใช้งานใหม่ ให้ uncomment บรรทัดด้านล่าง
 820             // $hasConsent = checkUserConsent($db, $user['id'], $userId);
 821             $hasConsent = true; // ข้าม consent check
 822             
 823             // ดึงข้อมูล LIFF ID และ shop name
 824             $liffShopUrl = '';
 825             $liffConsentUrl = '';
 826             $shopName = 'LINE Shop';
 827             
 828             if ($lineAccountId) {
 829                 // ตรวจสอบว่ามี column liff_consent_id หรือไม่
 830                 $hasConsentCol = false;
 831                 try {
 832                     $checkCol = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'liff_consent_id'");
 833                     $hasConsentCol = $checkCol->rowCount() > 0;
 834                 } catch (Exception $e) {}
 835                 
 836                 if ($hasConsentCol) {
 837                     $stmt = $db->prepare("SELECT liff_id, liff_consent_id, name FROM line_accounts WHERE id = ?");
 838                 } else {
 839                     $stmt = $db->prepare("SELECT liff_id, NULL as liff_consent_id, name FROM line_accounts WHERE id = ?");
 840                 }
 841                 $stmt->execute([$lineAccountId]);
 842                 $accountInfo = $stmt->fetch(PDO::FETCH_ASSOC);
 843                 
 844                 if ($accountInfo) {
 845                     if (!empty($accountInfo['liff_id'])) {
 846                         $liffShopUrl = 'https://liff.line.me/' . $accountInfo['liff_id'];
 847                     }
 848                     // ใช้ liff_consent_id ถ้ามี หรือใช้ liff_id ปกติ
 849                     $consentLiffId = $accountInfo['liff_consent_id'] ?? $accountInfo['liff_id'] ?? '';
 850                     if ($consentLiffId) {
 851                         $liffConsentUrl = 'https://liff.line.me/' . $consentLiffId . '?page=consent';
 852                     }
 853                     
 854                     // ดึง shop name
 855                     $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
 856                     $stmt->execute([$lineAccountId]);
 857                     $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
 858                     if ($shopSettings && !empty($shopSettings['shop_name'])) {
 859                         $shopName = $shopSettings['shop_name'];
 860                     } elseif (!empty($accountInfo['name'])) {
 861                         $shopName = $accountInfo['name'];
 862                     }
 863                 }
 864             }
 865             
 866             // ========== ปิดการส่ง Consent PDPA อัตโนมัติ ==========
 867             // หมายเหตุ: ปิดการส่ง liff-consent.php เมื่อใช้งานครั้งแรก
 868             // ถ้าต้องการเปิดใช้งานใหม่ ให้ uncomment โค้ดด้านล่าง
 869             /*
 870             if (!$hasConsent && $sourceType === 'user') {
 871                 try {
 872                     $displayName = $user['display_name'] ?: 'คุณลูกค้า';
 873                     
 874                     // สร้าง Flex Message ขอความยินยอม
 875                     $consentFlex = [
 876                         'type' => 'bubble',
 877                         'size' => 'kilo',
 878                         'header' => [
 879                             'type' => 'box',
 880                             'layout' => 'vertical',
 881                             'backgroundColor' => '#2563EB',
 882                             'paddingAll' => '15px',
 883                             'contents' => [
 884                                 ['type' => 'text', 'text' => '🔒 ข้อตกลงและความยินยอม', 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center']
 885                             ]
 886                         ],
 887                         'body' => [
 888                             'type' => 'box',
 889                             'layout' => 'vertical',
 890                             'paddingAll' => '15px',
 891                             'contents' => [
 892                                 ['type' => 'text', 'text' => "สวัสดีค่ะ คุณ{$displayName} 👋", 'size' => 'md', 'weight' => 'bold'],
 893                                 ['type' => 'text', 'text' => "ยินดีต้อนรับสู่ {$shopName}", 'size' => 'sm', 'color' => '#666666', 'margin' => 'sm'],
 894                                 ['type' => 'separator', 'margin' => 'lg'],
 895                                 ['type' => 'text', 'text' => 'ก่อนเริ่มใช้บริการ กรุณายอมรับข้อตกลงการใช้งานและนโยบายความเป็นส่วนตัว (PDPA)', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'lg']
 896                             ]
 897                         ],
 898                         'footer' => [
 899                             'type' => 'box',
 900                             'layout' => 'vertical',
 901                             'paddingAll' => '15px',
 902                             'contents' => [
 903                                 [
 904                                     'type' => 'button',
 905                                     'action' => [
 906                                         'type' => 'uri',
 907                                         'label' => '📋 อ่านและยอมรับข้อตกลง',
 908                                         'uri' => $liffConsentUrl ?: (defined('BASE_URL') ? BASE_URL . 'liff-consent.php' : 'https://likesms.net/v1/liff-consent.php')
 909                                     ],
 910                                     'style' => 'primary',
 911                                     'color' => '#2563EB'
 912                                 ]
 913                             ]
 914                         ]
 915                     ];
 916                     
 917                     $consentMessage = [
 918                         'type' => 'flex',
 919                         'altText' => '🔒 กรุณายอมรับข้อตกลงก่อนใช้บริการ',
 920                         'contents' => $consentFlex
 921                     ];
 922                     
 923                     $line->replyMessage($replyToken, [$consentMessage]);
 924                     saveOutgoingMessage($db, $user['id'], 'consent_request');
 925                     
 926                     devLog($db, 'info', 'webhook', 'Sent consent request to user', [
 927                         'user_id' => $user['id'],
 928                         'display_name' => $displayName
 929                     ], $userId);
 930                     
 931                     return; // ส่ง Consent request แล้ว ไม่ต้อง process ต่อ
 932                     
 933                 } catch (Exception $e) {
 934                     devLog($db, 'error', 'webhook', 'Consent request error: ' . $e->getMessage(), null, $userId);
 935                 }
 936             }
 937             */
 938             
 939             // ========== LIFF Menu สำหรับข้อความแรก (หลังจาก consent แล้ว) ==========
 940             // ส่ง LIFF Menu เมื่อลูกค้าทักมาครั้งแรก
 941             if ($isFirstMessage && $sourceType === 'user' && $hasConsent) {
 942                 try {
 943                     // ถ้ามี LIFF URL ให้ส่ง LIFF Menu
 944                     if ($liffShopUrl) {
 945                         $displayName = $user['display_name'] ?: 'คุณลูกค้า';
 946                         $liffMenuBubble = FlexTemplates::firstMessageMenu($shopName, $liffShopUrl, $displayName);
 947                         $liffMenuMessage = FlexTemplates::toMessage($liffMenuBubble, "ยินดีต้อนรับสู่ {$shopName}");
 948                         
 949                         // เพิ่ม Quick Reply
 950                         $liffMenuMessage = FlexTemplates::withQuickReply($liffMenuMessage, [
 951                             ['label' => '🛒 ดูสินค้า', 'text' => 'shop'],
 952                             ['label' => '📋 เมนู', 'text' => 'menu'],
 953                             ['label' => '💬 ติดต่อเรา', 'text' => 'contact']
 954                         ]);
 955                         
 956                         $line->replyMessage($replyToken, [$liffMenuMessage]);
 957                         saveOutgoingMessage($db, $user['id'], 'liff_menu');
 958                         
 959                         devLog($db, 'info', 'webhook', 'Sent LIFF Menu to new user', [
 960                             'user_id' => $user['id'],
 961                             'display_name' => $displayName,
 962                             'liff_url' => $liffShopUrl
 963                         ], $userId);
 964                         
 965                         return; // ส่ง LIFF Menu แล้ว ไม่ต้อง process ต่อ
 966                     }
 967                 } catch (Exception $e) {
 968                     devLog($db, 'error', 'webhook', 'LIFF Menu error: ' . $e->getMessage(), null, $userId);
 969                 }
 970             }
 971 
 972             // ตรวจสอบ bot_mode ก่อน - ถ้าเป็น general ไม่ตอบกลับอะไรเลย
 973             $botMode = 'shop'; // default
 974             $liffId = '';
 975             try {
 976                 if ($lineAccountId) {
 977                     $stmt = $db->prepare("SELECT bot_mode, liff_id FROM line_accounts WHERE id = ?");
 978                     $stmt->execute([$lineAccountId]);
 979                     $result = $stmt->fetch(PDO::FETCH_ASSOC);
 980                     if ($result) {
 981                         if (!empty($result['bot_mode'])) {
 982                             $botMode = $result['bot_mode'];
 983                         }
 984                         $liffId = $result['liff_id'] ?? '';
 985                     }
 986                 }
 987             } catch (Exception $e) {}
 988             
 989             // ถ้าเป็นโหมด general - เช็ค Auto Reply ก่อน ถ้าไม่ match ค่อยไม่ตอบ
 990             if ($botMode === 'general') {
 991                 // Debug: log before checking auto reply
 992                 devLog($db, 'debug', 'webhook', 'General mode - checking auto reply', [
 993                     'user_id' => $userId,
 994                     'message' => mb_substr($messageText, 0, 100),
 995                     'line_account_id' => $lineAccountId
 996                 ], $userId);
 997                 
 998                 // Check auto-reply rules first - ถ้ามี rule ที่ match ให้ตอบ
 999                 $autoReply = checkAutoReply($db, $messageText, $lineAccountId);
1000                 
1001                 // Debug: log result
1002                 devLog($db, 'debug', 'webhook', 'General mode - auto reply result', [
1003                     'user_id' => $userId,
1004                     'has_reply' => $autoReply ? true : false,
1005                     'reply_type' => $autoReply ? ($autoReply['type'] ?? 'unknown') : null
1006                 ], $userId);
1007                 
1008                 if ($autoReply) {
1009                     devLog($db, 'info', 'webhook', 'General mode - auto reply matched, sending reply', [
1010                         'user_id' => $userId,
1011                         'message' => mb_substr($messageText, 0, 100),
1012                         'bot_mode' => $botMode
1013                     ], $userId);
1014                     $line->replyMessage($replyToken, [$autoReply]);
1015                     saveOutgoingMessage($db, $user['id'], json_encode($autoReply));
1016                     return;
1017                 }
1018                 
1019                 // ไม่มี auto reply match - ไม่ตอบกลับ แค่บันทึกข้อมูล (รอแอดมินตอบ)
1020                 devLog($db, 'info', 'webhook', 'General mode - no auto reply match, waiting for admin', [
1021                     'user_id' => $userId,
1022                     'message' => mb_substr($messageText, 0, 100),
1023                     'bot_mode' => $botMode
1024                 ], $userId);
1025                 return; // ไม่ตอบกลับ - ข้อมูลถูกบันทึกไว้แล้วด้านบน
1026             }
1027             
1028             // ตรวจสอบคำสั่งและการเรียก AI
1029             $textLower = mb_strtolower(trim($messageText));
1030             $textTrimmed = trim($messageText);
1031             
1032             // ===== LIFF Message Handler - Process LIFF-triggered messages =====
1033             // Requirements: 20.3, 20.9, 20.12
1034             if (class_exists('LiffMessageHandler')) {
1035                 $liffHandler = new LiffMessageHandler($db, $line, $lineAccountId);
1036                 $liffAction = $liffHandler->detectLiffAction($messageText);
1037                 
1038                 // Log all incoming messages for debugging
1039                 devLog($db, 'debug', 'webhook', 'Checking for LIFF action', [
1040                     'message' => mb_substr($messageText, 0, 100),
1041                     'detected_action' => $liffAction,
1042                     'user_id' => $userId
1043                 ], $userId);
1044                 
1045                 if ($liffAction) {
1046                     devLog($db, 'info', 'webhook', 'LIFF action detected', [
1047                         'action' => $liffAction,
1048                         'user_id' => $userId,
1049                         'message' => mb_substr($messageText, 0, 100)
1050                     ], $userId);
1051                     
1052                     $liffReply = $liffHandler->processMessage($messageText, $user['id'], $userId);
1053                     
1054                     if ($liffReply) {
1055                         devLog($db, 'info', 'webhook', 'Sending LIFF reply', [
1056                             'action' => $liffAction,
1057                             'reply_type' => $liffReply['type'] ?? 'unknown'
1058                         ], $userId);
1059                         
1060                         $line->replyMessage($replyToken, [$liffReply]);
1061                         saveOutgoingMessage($db, $user['id'], json_encode($liffReply), 'liff', 'flex');
1062                         return; // LIFF message handled
1063                     }
1064                 }
1065             }
1066             
1067             // ===== V3.2: AI ตอบทุกข้อความอัตโนมัติ (ยกเว้นคำสั่งพิเศษ) =====
1068             // คำสั่งที่ไม่ให้ AI ตอบ (ให้ระบบอื่นจัดการ)
1069             $systemCommands = ['ร้านค้า', 'shop', 'ร้าน', 'สินค้า', 'ซื้อ', 'สั่งซื้อ', 
1070                             'สลิป', 'slip', 'แนบสลิป', 'ส่งสลิป', 'โอนเงิน', 'โอนแล้ว',
1071                             'ออเดอร์', 'order', 'คำสั่งซื้อ', 'ติดตาม', 'tracking',
1072                             'เมนู', 'menu', 'help', 'ช่วยเหลือ', '?',
1073                             'quickmenu', 'เมนูด่วน', 'allmenu', 'เมนูทั้งหมด',
1074                             'contact', 'ติดต่อ', 'ติดต่อเรา',
1075                             'สมัครบัตร', 'บัตรสมาชิก', 'member', 'points', 'แต้ม'];
1076             $isSystemCommand = in_array($textLower, $systemCommands);
1077             
1078             // คำสั่งที่จะหยุด AI และส่งต่อเภสัชกร/แอดมิน
1079 
1080 
1081             $stopAICommands = ['ปรึกษาเภสัชกร', 'คุยกับเภสัชกร', 'ขอคุยกับคน', 'ขอคุยกับแอดมิน', 'ติดต่อเภสัชกร', 'ติดต่อแอดมิน', 'หยุดบอท', 'stop bot', 'human'];
1082             $isStopAICommand = in_array($textLower, $stopAICommands);
1083             
1084             // ตรวจสอบว่าเรียก AI หรือไม่ (@บอท, @bot, @ai หรือ /xxx)
1085             $isAICall = preg_match('/^@(บอท|bot|ai)\s*/iu', $textTrimmed, $aiMatch);
1086             $aiMessage = $isAICall ? trim(preg_replace('/^@(บอท|bot|ai)\s*/iu', '', $textTrimmed)) : '';
1087             
1088             // ตรวจสอบว่าเป็น / command หรือไม่ (เรียก AI โดยตรง)
1089             $isSlashCommand = preg_match('/^\/[\w\p{Thai}]+/u', $textTrimmed);
1090             
1091             // ถ้าพิมพ์ขอคุยกับเภสัชกร - หยุด AI
1092             if ($isStopAICommand) {
1093                 // ใช้ sender จาก ai_settings
1094                 $stopSender = getAISenderSettings($db, $lineAccountId, 'pharmacist');
1095                 
1096                 $stopMessage = [
1097                     'type' => 'text',
1098                     'text' => "📞 รับทราบค่ะ กำลังส่งต่อให้เภสัชกรดูแลค่ะ\n\nกรุณารอสักครู่ เภสัชกรจะติดต่อกลับโดยเร็วที่สุดค่ะ 🙏",
1099                     'sender' => $stopSender
1100                 ];
1101                 $line->replyMessage($replyToken, [$stopMessage]);
1102                 saveOutgoingMessage($db, $user['id'], json_encode($stopMessage), 'system', 'text');
1103                 devLog($db, 'info', 'webhook', 'User requested human pharmacist', ['user_id' => $userId], $userId);
1104                 return;
1105             }
1106             
1107             // ===== / command - ส่งไปให้ AI ตอบโดยตรง =====
1108             if ($isSlashCommand && isset($user['id'])) {
1109                 devLog($db, 'info', 'webhook', 'Slash command detected', [
1110                     'user_id' => $userId,
1111                     'message' => mb_substr($messageText, 0, 30)
1112                 ], $userId);
1113                 
1114                 $aiReply = checkAIChatbot($db, $messageText, $lineAccountId, $user['id']);
1115                 if ($aiReply) {
1116                     $replyResult = $line->replyMessage($replyToken, $aiReply);
1117                     $replyCode = $replyResult['code'] ?? 0;
1118                     
1119                     devLog($db, 'debug', 'webhook', 'Slash command reply result', [
1120                         'code' => $replyCode,
1121                         'message' => mb_substr($messageText, 0, 30)
1122                     ], $userId);
1123                     
1124                     saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'flex');
1125                     return;
1126                 }
1127             }
1128             
1129             // ===== AI ตอบเฉพาะเมื่อใช้ / หรือ @ command =====
1130             // ===== AI SIMPLE MODE: DISABLED - ให้แอดมินตอบเอง =====
1131             // ปิดการตอบอัตโนมัติของ AI ผ่าน webhook แล้ว
1132             // ใช้ Ghost Draft ใน Inbox V2 แทน
1133             /*
1134             if (isset($user['id'])) {
1135                 try {
1136                     require_once __DIR__ . '/classes/GeminiChat.php';
1137                     $gemini = new GeminiChat($db, $lineAccountId);
1138                     
1139                     devLog($db, 'debug', 'webhook', 'AI Simple Mode check', [
1140                         'is_enabled' => $gemini->isEnabled() ? 'yes' : 'no',
1141                         'message' => mb_substr($messageText, 0, 30)
1142                     ], $userId);
1143                     
1144                     if ($gemini->isEnabled()) {
1145                         $currentReplyToken = $event['replyToken'] ?? $replyToken ?? null;
1146                         
1147                         devLog($db, 'debug', 'webhook', 'Calling Gemini API...', [
1148                             'has_token' => $currentReplyToken ? 'yes' : 'no'
1149                         ], $userId);
1150                         
1151                         // เรียก Gemini ตอบเลย
1152                         set_time_limit(60);
1153                         $startTime = microtime(true);
1154                         $response = $gemini->generateResponse($messageText, $user['id'], []);
1155                         $elapsed = round((microtime(true) - $startTime) * 1000);
1156                         
1157                         devLog($db, 'debug', 'webhook', 'Gemini response received', [
1158                             'elapsed_ms' => $elapsed,
1159                             'has_response' => $response ? 'yes' : 'no',
1160                             'response_length' => $response ? mb_strlen($response) : 0
1161                         ], $userId);
1162                         
1163                         if ($response) {
1164                             $aiReply = [[
1165                                 'type' => 'text',
1166                                 'text' => $response
1167                             ]];
1168                             
1169                             // ส่งกลับด้วย replyMessage
1170                             if ($currentReplyToken) {
1171                                 $replyResult = $line->replyMessage($currentReplyToken, $aiReply);
1172                                 devLog($db, 'debug', 'webhook', 'AI reply sent', [
1173                                     'code' => $replyResult['code'] ?? 0,
1174                                     'body' => json_encode($replyResult['body'] ?? null),
1175                                     'message' => mb_substr($messageText, 0, 30)
1176                                 ], $userId);
1177                             } else {
1178                                 devLog($db, 'error', 'webhook', 'No replyToken for AI response', [], $userId);
1179                             }
1180                             
1181                             saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'text');
1182                             return;
1183                         }
1184                     }
1185                 } catch (Exception $e) {
1186                     devLog($db, 'error', 'webhook', 'AI error: ' . $e->getMessage(), [], $userId);
1187                 }
1188             }
1189             */
1190             
1191             // ===== ถ้า AI ไม่ตอบ ให้ทำงานตามปกติ =====
1192             
1193             // คำสั่งที่บอทจะตอบ (เฉพาะคำสั่งเจาะจง)
1194             $shopCommands = ['ร้านค้า', 'shop', 'ร้าน', 'สินค้า', 'ซื้อ', 'สั่งซื้อ'];
1195             $slipCommands = ['สลิป', 'slip', 'แนบสลิป', 'ส่งสลิป', 'โอนเงิน', 'โอนแล้ว'];
1196             $orderCommands = ['ออเดอร์', 'order', 'คำสั่งซื้อ', 'ติดตาม', 'tracking'];
1197             $menuCommands = ['เมนู', 'menu', 'help', 'ช่วยเหลือ'];
1198             
1199             $isShopCommand = in_array($textLower, $shopCommands);
1200             $isSlipCommand = in_array($textLower, $slipCommands);
1201             $isOrderCommand = in_array($textLower, $orderCommands);
1202             $isMenuCommand = in_array($textLower, $menuCommands);
1203             
1204             // ===== Handle LIFF Action Messages (สั่งซื้อสำเร็จ, นัดหมายสำเร็จ, etc.) =====
1205             if (preg_match('/^สั่งซื้อสำเร็จ\s*#?(\w+)/u', $messageText, $matches)) {
1206                 $orderNumber = $matches[1];
1207                 devLog($db, 'info', 'webhook', 'Order confirmation message received', [
1208                     'user_id' => $userId,
1209                     'order_number' => $orderNumber
1210                 ], $userId);
1211                 
1212                 // Get order details
1213                 $stmt = $db->prepare("
1214                     SELECT t.*, 
1215                            (SELECT SUM(quantity) FROM transaction_items WHERE transaction_id = t.id) as item_count
1216                     FROM transactions t 
1217                     WHERE t.order_number = ? AND t.user_id = ?
1218                 ");
1219                 $stmt->execute([$orderNumber, $user['id']]);
1220                 $order = $stmt->fetch(PDO::FETCH_ASSOC);
1221                 
1222                 if ($order) {
1223                     // Get order items
1224                     $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
1225                     $stmt->execute([$order['id']]);
1226                     $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
1227                     
1228                     // Build Flex Message for order confirmation
1229                     $itemContents = [];
1230                     foreach ($items as $item) {
1231                         $itemContents[] = [
1232                             'type' => 'box',
1233                             'layout' => 'horizontal',
1234                             'contents' => [
1235                                 ['type' => 'text', 'text' => $item['product_name'], 'size' => 'sm', 'color' => '#555555', 'flex' => 4, 'wrap' => true],
1236                                 ['type' => 'text', 'text' => 'x' . $item['quantity'], 'size' => 'sm', 'color' => '#111111', 'flex' => 1, 'align' => 'end'],
1237                                 ['type' => 'text', 'text' => '฿' . number_format($item['subtotal'], 0), 'size' => 'sm', 'color' => '#111111', 'flex' => 2, 'align' => 'end']
1238                             ]
1239                         ];
1240                     }
1241                     
1242                     $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
1243                     
1244                     $orderFlex = [
1245                         'type' => 'bubble',
1246                         'header' => [
1247                             'type' => 'box',
1248                             'layout' => 'vertical',
1249                             'backgroundColor' => '#06C755',
1250                             'paddingAll' => 'lg',
1251                             'contents' => [
1252                                 ['type' => 'text', 'text' => 'ยืนยันคำสั่งซื้อ', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg']
1253                             ]
1254                         ],
1255                         'body' => [
1256                             'type' => 'box',
1257                             'layout' => 'vertical',
1258                             'contents' => array_merge(
1259                                 [
1260                                     ['type' => 'text', 'text' => '#' . $order['order_number'], 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
1261                                     ['type' => 'separator', 'margin' => 'lg'],
1262                                     ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg']
1263                                 ],
1264                                 $itemContents,
1265                                 [
1266                                     ['type' => 'separator', 'margin' => 'lg'],
1267                                     [
1268                                         'type' => 'box',
1269                                         'layout' => 'horizontal',
1270                                         'margin' => 'lg',
1271                                         'contents' => [
1272                                             ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#555555'],
1273                                             ['type' => 'text', 'text' => '฿' . number_format($order['shipping_fee'] ?? 0, 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
1274                                         ]
1275                                     ],
1276                                     [
1277                                         'type' => 'box',
1278                                         'layout' => 'horizontal',
1279                                         'margin' => 'md',
1280                                         'contents' => [
1281                                             ['type' => 'text', 'text' => 'รวมทั้งหมด', 'size' => 'md', 'weight' => 'bold'],
1282                                             ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 0), 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755', 'align' => 'end']
1283                                         ]
1284                                     ]
1285                                 ]
1286                             )
1287                         ],
1288                         'footer' => [
1289                             'type' => 'box',
1290                             'layout' => 'vertical',
1291                             'contents' => [
1292                                 ['type' => 'text', 'text' => 'กรุณาชำระเงินและแนบสลิป', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
1293                                 ['type' => 'text', 'text' => 'พิมพ์ "สลิป" เพื่อแนบหลักฐาน', 'size' => 'xs', 'color' => '#888888', 'align' => 'center', 'margin' => 'sm']
1294                             ]
1295                         ]
1296                     ];
1297                     
1298                     $message = [
1299                         'type' => 'flex',
1300                         'altText' => 'ยืนยันคำสั่งซื้อ #' . $order['order_number'],
1301                         'contents' => $orderFlex
1302                     ];
1303                     $line->replyMessage($replyToken, [$message]);
1304                     saveOutgoingMessage($db, $user['id'], 'order_confirmation_flex', 'system', 'flex');
1305                 } else {
1306                     $line->replyMessage($replyToken, [['type' => 'text', 'text' => 'ไม่พบคำสั่งซื้อ #' . $orderNumber]]);
1307                 }
1308                 return;
1309             }
1310             
1311             // ถ้าเรียก AI (@บอท xxx) - ส่งไปให้ AI ตอบ (fallback)
1312             if ($isAICall && !empty($aiMessage)) {
1313                 devLog($db, 'info', 'webhook', 'AI called with @bot', [
1314                     'user_id' => $userId,
1315                     'message' => $aiMessage
1316                 ], $userId);
1317                 
1318                 $aiReply = checkAIChatbot($db, $aiMessage, $lineAccountId, $user['id'] ?? null);
1319                 if ($aiReply) {
1320                     // ลอง replyMessage ก่อน (ฟรี!)
1321                     $replyResult = $line->replyMessage($replyToken, $aiReply);
1322                     $replyCode = $replyResult['code'] ?? 0;
1323                     
1324                     // ถ้า reply ไม่สำเร็จ ให้ใช้ pushMessage แทน
1325                     if ($replyCode !== 200) {
1326                         $line->pushMessage($userId, $aiReply);
1327                     }
1328                     
1329                     saveOutgoingMessage($db, $user['id'], $aiReply, 'ai', 'flex');
1330                     return;
1331                 } else {
1332                     // AI ไม่ได้เปิดใช้งาน
1333                     $line->replyMessage($replyToken, [['type' => 'text', 'text' => '❌ ระบบ AI ยังไม่ได้เปิดใช้งาน กรุณาติดต่อแอดมิน']]);
1334                     return;
1335                 }
1336             }
1337             
1338             // ถ้าเป็นคำสั่งร้านค้า - ส่ง LIFF URL
1339             if ($isShopCommand && $liffId) {
1340                 $liffUrl = "https://liff.line.me/{$liffId}";
1341                 $shopFlex = [
1342                     'type' => 'bubble',
1343                     'body' => [
1344                         'type' => 'box',
1345                         'layout' => 'vertical',
1346                         'contents' => [
1347                             ['type' => 'text', 'text' => '🛍️ ร้านค้าออนไลน์', 'weight' => 'bold', 'size' => 'lg'],
1348                             ['type' => 'text', 'text' => 'กดปุ่มด้านล่างเพื่อดูสินค้าและสั่งซื้อ', 'size' => 'sm', 'color' => '#666666', 'margin' => 'md', 'wrap' => true]
1349                         ]
1350                     ],
1351                     'footer' => [
1352                         'type' => 'box',
1353                         'layout' => 'vertical',
1354                         'contents' => [
1355                             [
1356                                 'type' => 'button',
1357                                 'style' => 'primary',
1358                                 'color' => '#06C755',
1359                                 'action' => ['type' => 'uri', 'label' => '🛒 เข้าสู่ร้านค้า', 'uri' => $liffUrl]
1360                             ]
1361                         ]
1362                     ]
1363                 ];
1364                 
1365                 $message = [
1366                     'type' => 'flex',
1367                     'altText' => 'กดเพื่อเข้าสู่ร้านค้า',
1368                     'contents' => $shopFlex
1369                 ];
1370                 $line->replyMessage($replyToken, [$message]);
1371                 saveOutgoingMessage($db, $user['id'], 'liff_redirect');
1372                 return;
1373             }
1374             
1375             // ถ้าเป็นคำสั่งสลิป/ออเดอร์ - ให้ BusinessBot จัดการ (ด้านล่าง)
1376             // ถ้าเป็นคำสั่งเมนู - ให้ Auto Reply หรือ BusinessBot จัดการ (ด้านล่าง)
1377             
1378             // ถ้าไม่ใช่คำสั่งที่กำหนด และไม่ใช่โหมด general - ไม่ตอบ (รอแอดมิน)
1379             if (!$isSlipCommand && !$isOrderCommand && !$isMenuCommand && $botMode !== 'general') {
1380                 // เช็ค Auto Reply ก่อน
1381                 $autoReply = checkAutoReply($db, $messageText, $lineAccountId);
1382                 if ($autoReply) {
1383                     devLog($db, 'info', 'webhook', 'Auto reply matched (non-general mode)', [
1384                         'user_id' => $userId,
1385                         'message' => mb_substr($messageText, 0, 100),
1386                         'bot_mode' => $botMode
1387                     ], $userId);
1388                     $line->replyMessage($replyToken, [$autoReply]);
1389                     saveOutgoingMessage($db, $user['id'], json_encode($autoReply));
1390                     return;
1391                 }
1392                 
1393                 // ไม่ตอบ - รอแอดมิน
1394                 devLog($db, 'info', 'webhook', 'No matching command - waiting for admin', [
1395                     'user_id' => $userId,
1396                     'message' => mb_substr($messageText, 0, 100),
1397                     'bot_mode' => $botMode
1398                 ], $userId);
1399                 return;
1400             }
1401 
1402             // Check for slip command: "สลิป", "slip", "แนบสลิป", "ส่งสลิป"
1403             if (in_array($textLower, ['สลิป', 'slip', 'แนบสลิป', 'ส่งสลิป', 'โอนเงิน', 'โอนแล้ว'])) {
1404                 devLog($db, 'debug', 'webhook', 'Slip command detected', ['user_id' => $user['id'], 'text' => $textLower], $userId);
1405                 $handled = handleSlipCommand($db, $line, $user['id'], $replyToken);
1406                 devLog($db, 'debug', 'webhook', 'Slip command result: ' . ($handled ? 'handled' : 'not handled'), ['user_id' => $user['id']], $userId);
1407                 if ($handled) return;
1408             }
1409             
1410             // Check for menu command - แสดงเมนูหลักสวยๆ (อัพเกรด V2)
1411             if (in_array($textLower, ['menu', 'เมนู', 'help', 'ช่วยเหลือ', '?'])) {
1412                 $shopName = 'LINE Shop';
1413                 try {
1414                     if ($lineAccountId) {
1415                         $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
1416                         $stmt->execute([$lineAccountId]);
1417                         $shopSettings = $stmt->fetch();
1418                     }
1419                     if (empty($shopSettings)) {
1420                         $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
1421                         $shopSettings = $stmt->fetch();
1422                     }
1423                     if ($shopSettings && $shopSettings['shop_name']) $shopName = $shopSettings['shop_name'];
1424                 } catch (Exception $e) {}
1425                 
1426                 $menuBubble = FlexTemplates::mainMenu($shopName);
1427                 $menuMessage = FlexTemplates::toMessage($menuBubble, "เมนู {$shopName}");
1428                 $line->replyMessage($replyToken, [$menuMessage]);
1429                 saveOutgoingMessage($db, $user['id'], 'menu');
1430                 return;
1431             }
1432             
1433             // Check for quick menu command - เมนูด่วนแบบ Carousel
1434             if (in_array($textLower, ['quickmenu', 'เมนูด่วน', 'allmenu', 'เมนูทั้งหมด'])) {
1435                 $shopName = 'LINE Shop';
1436                 try {
1437                     if ($lineAccountId) {
1438                         $stmt = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
1439                         $stmt->execute([$lineAccountId]);
1440                         $shopSettings = $stmt->fetch();
1441                     }
1442                     if (empty($shopSettings)) {
1443                         $stmt = $db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
1444                         $shopSettings = $stmt->fetch();
1445                     }
1446                     if ($shopSettings && $shopSettings['shop_name']) $shopName = $shopSettings['shop_name'];
1447                 } catch (Exception $e) {}
1448                 
1449                 $menuCarousel = FlexTemplates::quickMenu($shopName);
1450                 $menuMessage = FlexTemplates::toMessage($menuCarousel, "เมนูทั้งหมด {$shopName}");
1451                 $line->replyMessage($replyToken, [$menuMessage]);
1452                 saveOutgoingMessage($db, $user['id'], 'quickmenu');
1453                 return;
1454             }
1455             
1456             // Check for contact command
1457             if (in_array($textLower, ['contact', 'ติดต่อ', 'ติดต่อเรา'])) {
1458                 $contactBubble = FlexTemplates::notification(
1459                     'ติดต่อเรา',
1460                     'สามารถพิมพ์ข้อความถึงเราได้เลย\nทีมงานจะตอบกลับโดยเร็วที่สุด',
1461                     '📞',
1462                     '#3B82F6',
1463                     [['label' => '🛒 ดูสินค้า', 'text' => 'shop', 'style' => 'secondary']]
1464                 );
1465                 $contactMessage = FlexTemplates::toMessage($contactBubble, 'ติดต่อเรา');
1466                 // เพิ่ม Quick Reply
1467                 $contactMessage = FlexTemplates::withQuickReply($contactMessage, [
1468                     ['label' => '🛒 ดูสินค้า', 'text' => 'shop'],
1469                     ['label' => '📋 เมนู', 'text' => 'menu'],
1470                     ['label' => '📦 ออเดอร์', 'text' => 'orders']
1471                 ]);
1472                 $line->replyMessage($replyToken, [$contactMessage]);
1473                 saveOutgoingMessage($db, $user['id'], 'contact');
1474                 return;
1475             }
1476             
1477             // Points/loyalty command - handled by BusinessBot.showPoints()
1478 
1479             // เช็ค Auto Reply ก่อน BusinessBot (สำหรับข้อความทั่วไป)
1480             // ยกเว้นคำสั่งพิเศษที่ BusinessBot ต้องจัดการ
1481             $specialCommands = ['shop', 'menu', 'orders', 'สินค้า', 'เมนู', 'ออเดอร์', 'points', 'แต้ม'];
1482             if (!in_array($textLower, $specialCommands) && !$isSlipCommand && !$isOrderCommand) {
1483                 $autoReply = checkAutoReply($db, $messageText, $lineAccountId);
1484                 if ($autoReply) {
1485                     devLog($db, 'info', 'webhook', 'Auto reply matched (before BusinessBot)', [
1486                         'user_id' => $userId,
1487                         'message' => mb_substr($messageText, 0, 100)
1488                     ], $userId);
1489                     $line->replyMessage($replyToken, [$autoReply]);
1490                     saveOutgoingMessage($db, $user['id'], json_encode($autoReply));
1491                     return;
1492                 }
1493             }
1494 
1495             // V2.5: Check Business commands (ใช้ BusinessBot เท่านั้น)
1496             $botMode = 'shop'; // default
1497             $businessBot = null;
1498             
1499             try {
1500                 if (class_exists('BusinessBot')) {
1501                     devLog($db, 'debug', 'BusinessBot', 'Processing message', [
1502                         'user_id' => $userId,
1503                         'message' => mb_substr($messageText, 0, 50)
1504                     ], $userId);
1505                     
1506                     $businessBot = new BusinessBot($db, $line, $lineAccountId);
1507                     $botMode = $businessBot->getBotMode();
1508                     $handled = $businessBot->processMessage($userId, $user['id'], $messageText, $replyToken);
1509                     
1510                     devLog($db, 'debug', 'BusinessBot', 'Result: ' . ($handled ? 'handled' : 'not handled'), [
1511                         'user_id' => $userId,
1512                         'command' => mb_substr($messageText, 0, 50),
1513                         'handled' => $handled ? true : false,
1514                         'bot_mode' => $botMode
1515                     ], $userId);
1516                     
1517                     if ($handled) {
1518                         return; // Business command handled
1519                     }
1520                 }
1521             } catch (Exception $e) {
1522                 devLog($db, 'error', 'BusinessBot', $e->getMessage(), [
1523                     'user_id' => $userId,
1524                     'message' => mb_substr($messageText, 0, 100),
1525                     'file' => $e->getFile(),
1526                     'line' => $e->getLine(),
1527                     'trace' => $e->getTraceAsString()
1528                 ], $userId);
1529                 error_log("BusinessBot error: " . $e->getMessage());
1530             }
1531 
1532             // Check auto-reply rules (รองรับ Sender, Quick Reply, Alt Text) - แยกตาม LINE Account
1533             $reply = checkAutoReply($db, $messageText, $lineAccountId);
1534             if ($reply) {
1535                 $line->replyMessage($replyToken, [$reply]);
1536                 saveOutgoingMessage($db, $user['id'], json_encode($reply), 'system', 'flex');
1537                 return;
1538             }
1539             
1540             // ไม่ตอบ default reply - รอแอดมินตอบ
1541             devLog($db, 'info', 'webhook', 'No command matched - waiting for admin', [
1542                 'user_id' => $userId,
1543                 'message' => mb_substr($messageText, 0, 100)
1544             ], $userId);
1545             
1546         } catch (Exception $e) {
1547             // Log error
1548             devLog($db, 'error', 'handleMessage', $e->getMessage(), [
1549                 'user_id' => $userId,
1550                 'message_type' => $messageType ?? 'unknown',
1551                 'message_text' => mb_substr($messageText ?? '', 0, 100),
1552                 'file' => $e->getFile(),
1553                 'line' => $e->getLine()
1554             ], $userId);
1555             error_log("handleMessage error: " . $e->getMessage());
1556             
1557             // Try to reply with error message
1558             try {
1559                 $line->replyMessage($replyToken, ['type' => 'text', 'text' => '❌ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง']);
1560             } catch (Exception $e2) {}
1561         }
1562     }
1563 
1564         /**
1565          * Check auto-reply rules (Upgraded with Sender, Quick Reply, Alt Text)
1566          * แยกตาม LINE Account - ดึงเฉพาะกฎของ account นั้นๆ หรือกฎที่ไม่ระบุ account (global)
1567          */
1568         function checkAutoReply($db, $text, $lineAccountId = null) {
1569             // ดึงกฎที่ตรงกับ account นี้ หรือกฎ global (line_account_id IS NULL)
1570             if ($lineAccountId) {
1571                 $stmt = $db->prepare("SELECT * FROM auto_replies WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY line_account_id DESC, priority DESC");
1572                 $stmt->execute([$lineAccountId]);
1573             } else {
1574                 $stmt = $db->prepare("SELECT * FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC");
1575                 $stmt->execute();
1576             }
1577             $rules = $stmt->fetchAll();
1578 
1579             foreach ($rules as $rule) {
1580                 $matched = false;
1581                 switch ($rule['match_type']) {
1582                     case 'exact':
1583                         $matched = (mb_strtolower($text) === mb_strtolower($rule['keyword']));
1584                         break;
1585                     case 'contains':
1586                         $matched = (mb_stripos($text, $rule['keyword']) !== false);
1587                         break;
1588                     case 'starts_with':
1589                         $matched = (mb_stripos($text, $rule['keyword']) === 0);
1590                         break;
1591                     case 'regex':
1592                         $matched = preg_match('/' . $rule['keyword'] . '/i', $text);
1593                         break;
1594                     case 'all':
1595                         // Match all messages - ตอบทุกข้อความ
1596                         $matched = true;
1597                         break;
1598                 }
1599 
1600                 if ($matched) {
1601                     // Update use count if column exists
1602                     try {
1603                         $stmt2 = $db->prepare("UPDATE auto_replies SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?");
1604                         $stmt2->execute([$rule['id']]);
1605                     } catch (Exception $e) {}
1606                     
1607                     // Build message
1608                     $message = null;
1609                     
1610                     if ($rule['reply_type'] === 'text') {
1611                         $message = ['type' => 'text', 'text' => $rule['reply_content']];
1612                     } else {
1613                         // Flex Message
1614                         $flexContent = json_decode($rule['reply_content'], true);
1615                         if ($flexContent) {
1616                             $altText = $rule['alt_text'] ?? $rule['keyword'] ?? 'ข้อความ';
1617                             
1618                             // Add share button if enabled
1619                             $enableShare = $rule['enable_share'] ?? false;
1620                             if ($enableShare && defined('LIFF_SHARE_ID') && LIFF_SHARE_ID) {
1621                                 $shareLabel = $rule['share_button_label'] ?? '📤 แชร์ให้เพื่อน';
1622                                 $flexContent = addShareButtonToFlex($flexContent, $rule['id'], $shareLabel);
1623                             }
1624                             
1625                             $message = [
1626                                 'type' => 'flex',
1627                                 'altText' => $altText,
1628                                 'contents' => $flexContent
1629                             ];
1630                         }
1631                     }
1632                     
1633                     if (!$message) return null;
1634                     
1635                     // Add Sender if exists
1636                     $senderName = $rule['sender_name'] ?? null;
1637                     $senderIcon = $rule['sender_icon'] ?? null;
1638                     if ($senderName) {
1639                         $message['sender'] = ['name' => $senderName];
1640                         if ($senderIcon) {
1641                             $message['sender']['iconUrl'] = $senderIcon;
1642                         }
1643                     }
1644                     
1645                     // Add Quick Reply if exists (Full Featured)
1646                     $quickReply = $rule['quick_reply'] ?? null;
1647                     if ($quickReply) {
1648                         $qrItems = json_decode($quickReply, true);
1649                         if ($qrItems && is_array($qrItems)) {
1650                             $quickReplyActions = [];
1651                             foreach ($qrItems as $item) {
1652                                 $qrItem = ['type' => 'action'];
1653                                 
1654                                 // Add icon if exists
1655                                 if (!empty($item['imageUrl'])) {
1656                                     $qrItem['imageUrl'] = $item['imageUrl'];
1657                                 }
1658                                 
1659                                 $actionType = $item['type'] ?? 'message';
1660                                 
1661                                 switch ($actionType) {
1662                                     case 'message':
1663                                         $qrItem['action'] = [
1664                                             'type' => 'message',
1665                                             'label' => $item['label'],
1666                                             'text' => $item['text'] ?? $item['label']
1667                                         ];
1668                                         break;
1669                                         
1670                                     case 'uri':
1671                                         $qrItem['action'] = [
1672                                             'type' => 'uri',
1673                                             'label' => $item['label'],
1674                                             'uri' => $item['uri']
1675                                         ];
1676                                         break;
1677                                         
1678                                     case 'postback':
1679                                         $qrItem['action'] = [
1680                                             'type' => 'postback',
1681                                             'label' => $item['label'],
1682                                             'data' => $item['data'] ?? ''
1683                                         ];
1684                                         if (!empty($item['displayText'])) {
1685                                             $qrItem['action']['displayText'] = $item['displayText'];
1686                                         }
1687                                         break;
1688                                         
1689                                     case 'datetimepicker':
1690                                         $qrItem['action'] = [
1691                                             'type' => 'datetimepicker',
1692                                             'label' => $item['label'],
1693                                             'data' => $item['data'] ?? '',
1694                                             'mode' => $item['mode'] ?? 'datetime'
1695                                         ];
1696                                         if (!empty($item['initial'])) {
1697                                             $qrItem['action']['initial'] = $item['initial'];
1698                                         }
1699                                         if (!empty($item['min'])) {
1700                                             $qrItem['action']['min'] = $item['min'];
1701                                         }
1702                                         if (!empty($item['max'])) {
1703                                             $qrItem['action']['max'] = $item['max'];
1704                                         }
1705                                         break;
1706                                         
1707                                     case 'camera':
1708                                     case 'cameraRoll':
1709                                     case 'location':
1710                                         $qrItem['action'] = [
1711                                             'type' => $actionType,
1712                                             'label' => $item['label']
1713                                         ];
1714                                         break;
1715                                     
1716                                     case 'share':
1717                                         // Share button - ใช้ LINE URI Scheme
1718                                         $shareText = $item['shareText'] ?? 'มาดูสิ่งนี้สิ!';
1719                                         $encodedText = urlencode($shareText);
1720                                         $qrItem['action'] = [
1721                                             'type' => 'uri',
1722                                             'label' => $item['label'],
1723                                             'uri' => "https://line.me/R/share?text=" . $encodedText
1724                                         ];
1725                                         break;
1726                                         
1727                                     default:
1728                                         $qrItem['action'] = [
1729                                             'type' => 'message',
1730                                             'label' => $item['label'],
1731                                             'text' => $item['text'] ?? $item['label']
1732                                         ];
1733                                 }
1734                                 
1735                                 $quickReplyActions[] = $qrItem;
1736                             }
1737                             if (!empty($quickReplyActions)) {
1738                                 $message['quickReply'] = ['items' => $quickReplyActions];
1739                             }
1740                         }
1741                     }
1742                     
1743                     return $message;
1744                 }
1745             }
1746             return null;
1747         }
1748 
1749         /**
1750          * Add share button to Flex Message
1751          * @param array $flexContent - Flex bubble or carousel
1752          * @param int $ruleId - Auto-reply rule ID
1753          * @param string $label - Button label
1754          * @return array - Modified flex content
1755          */
1756         function addShareButtonToFlex($flexContent, $ruleId, $label = '📤 แชร์ให้เพื่อน') {
1757             $liffId = LIFF_SHARE_ID;
1758             $shareUrl = "https://liff.line.me/{$liffId}?rule={$ruleId}";
1759             
1760             $shareButton = [
1761                 'type' => 'button',
1762                 'action' => [
1763                     'type' => 'uri',
1764                     'label' => $label,
1765                     'uri' => $shareUrl
1766                 ],
1767                 'style' => 'secondary',
1768                 'color' => '#3B82F6',
1769                 'height' => 'sm',
1770                 'margin' => 'sm'
1771             ];
1772             
1773             // Handle bubble
1774             if (isset($flexContent['type']) && $flexContent['type'] === 'bubble') {
1775                 if (!isset($flexContent['footer'])) {
1776                     $flexContent['footer'] = [
1777                         'type' => 'box',
1778                         'layout' => 'vertical',
1779                         'contents' => [],
1780                         'paddingAll' => 'lg'
1781                     ];
1782                 }
1783                 $flexContent['footer']['contents'][] = $shareButton;
1784             }
1785             // Handle carousel
1786             elseif (isset($flexContent['type']) && $flexContent['type'] === 'carousel') {
1787                 foreach ($flexContent['contents'] as &$bubble) {
1788                     if (!isset($bubble['footer'])) {
1789                         $bubble['footer'] = [
1790                             'type' => 'box',
1791                             'layout' => 'vertical',
1792                             'contents' => [],
1793                             'paddingAll' => 'lg'
1794                         ];
1795                     }
1796                     $bubble['footer']['contents'][] = $shareButton;
1797                 }
1798             }
1799             
1800             return $flexContent;
1801         }
1802 
1803         /**
1804          * Check AI chatbot - Using Gemini 2.0 with Conversation History
1805          * Enhanced for conversation continuity
1806          * 
1807          * V5.0: เพิ่ม Command Mode (/ai, /mims, /triage, /human)
1808          * V4.0: เพิ่ม Keyword Routing + Bot Pause Feature
1809          * V3.0: รองรับ PharmacyAI Adapter (Triage System)
1810          * V2.6: รองรับ Module ใหม่ (modules/AIChat)
1811          */
1812         function checkAIChatbot($db, $text, $lineAccountId = null, $userId = null) {
1813             try {
1814                 // Log entry point
1815                 error_log("AI_entry: checkAIChatbot called - text: " . mb_substr($text, 0, 50) . ", lineAccountId: $lineAccountId, userId: $userId");
1816                 devLog($db, 'debug', 'AI_entry', 'checkAIChatbot called', [
1817                     'text' => mb_substr($text, 0, 50),
1818                     'line_account_id' => $lineAccountId,
1819                     'user_id' => $userId
1820                 ], null);
1821                 
1822                 $textLower = mb_strtolower(trim($text));
1823                 $originalText = trim($text);
1824                 
1825                 // ===== 0. ตรวจสอบ Command Mode (/ai, /mims, /triage, /human) =====
1826                 $commandMode = null;
1827                 $commandMessage = $originalText;
1828                 
1829                 // รูปแบบ: /command ข้อความ หรือ @command ข้อความ
1830                 // รองรับ backtick หรือ character พิเศษข้างหน้า
1831                 $cleanText = preg_replace('/^[`\'"\s]+/', '', $originalText);
1832                 
1833                 // ===== ตรวจสอบ "/" เดียว → เริ่ม AI และแสดงคำอธิบาย =====
1834                 if ($cleanText === '/' || $cleanText === '@') {
1835                     // ตรวจสอบว่าเคยใช้ AI หรือยัง
1836                     $isFirstTime = true;
1837                     if ($userId) {
1838                         try {
1839                             $stmt = $db->prepare("SELECT COUNT(*) FROM ai_chat_logs WHERE user_id = ? LIMIT 1");
1840                             $stmt->execute([$userId]);
1841                             $isFirstTime = ($stmt->fetchColumn() == 0);
1842                         } catch (Exception $e) {}
1843                     }
1844                     
1845                     // ดึง AI mode จาก ai_settings
1846                     $configuredMode = 'sales'; // default
1847                     try {
1848                         $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
1849                         $stmt->execute([$lineAccountId]);
1850                         $result = $stmt->fetch(PDO::FETCH_ASSOC);
1851                         if ($result && $result['ai_mode']) {
1852                             $configuredMode = $result['ai_mode'];
1853                         }
1854                     } catch (Exception $e) {}
1855                     
1856                     // บันทึกโหมด AI ตามที่ตั้งค่าไว้
1857                     if ($userId) {
1858                         setUserAIMode($db, $userId, $configuredMode);
1859                     }
1860                     
1861                     if ($isFirstTime) {
1862                         // ครั้งแรก - แสดงคำอธิบายการใช้งาน
1863                         return [[
1864                             'type' => 'text',
1865                             'text' => "🤖 ยินดีต้อนรับสู่ AI Assistant!\n\n✨ วิธีใช้งาน:\n• พิมพ์คำถามหรือสิ่งที่ต้องการได้เลย\n• AI จะช่วยตอบคำถาม แนะนำสินค้า และให้ข้อมูล\n\n📝 ตัวอย่าง:\n• \"มีสินค้าอะไรบ้าง\"\n• \"แนะนำสินค้าขายดี\"\n• \"ราคาสินค้า XXX\"\n\n💡 พิมพ์ /exit เพื่อออกจากโหมด AI\n\n🎯 เริ่มต้นได้เลย! พิมพ์คำถามของคุณ:",
1866                             'sender' => [
1867                                 'name' => '🤖 AI Assistant',
1868                                 'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
1869                             ]
1870                         ]];
1871                     } else {
1872                         // เคยใช้แล้ว - แสดงข้อความสั้นๆ
1873                         return [[
1874                             'type' => 'text',
1875                             'text' => "🤖 AI พร้อมให้บริการค่ะ!\n\nพิมพ์คำถามหรือสิ่งที่ต้องการได้เลย\n(พิมพ์ /exit เพื่อออก)",
1876                             'sender' => [
1877                                 'name' => '🤖 AI Assistant',
1878                                 'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
1879                             ]
1880                         ]];
1881                     }
1882                 }
1883                 
1884                 // รองรับทั้ง / และ @ นำหน้า command (รองรับทั้ง English และ Thai)
1885                 if (preg_match('/^[\/\@]([\w\p{Thai}]+)\s*(.*)/u', $cleanText, $matches)) {
1886                     $command = mb_strtolower($matches[1]);
1887                     $commandMessage = trim($matches[2]);
1888                     
1889                     // Map commands to modes
1890                     $commandMap = [
1891                         'ai' => 'auto',          // /ai = ใช้ mode จาก settings
1892                         'pharmacy' => 'pharmacist',
1893                         'pharmacist' => 'pharmacist',
1894                         'ยา' => 'pharmacist',
1895                         'ถาม' => 'auto',         // /ถาม = ใช้ mode จาก settings
1896                         'ขาย' => 'sales',        // /ขาย = โหมดขาย
1897                         'sales' => 'sales',
1898                         'support' => 'support',  // /support = โหมดซัพพอร์ต
1899                         'ซัพพอร์ต' => 'support',
1900                         
1901                         'mims' => 'mims',        // /mims = MIMS AI (ความรู้ทางการแพทย์)
1902                         'med' => 'mims',
1903                         'วิชาการ' => 'mims',
1904                         
1905                         'triage' => 'triage',    // /triage = ซักประวัติ
1906                         'ซักประวัติ' => 'triage',
1907                         'assess' => 'triage',
1908                         
1909                         'human' => 'human',      // /human = ขอคุยกับเภสัชกรจริง
1910                         'คน' => 'human',
1911                         'เภสัช' => 'human',
1912                         
1913                         'exit' => 'exit',        // /exit = ออกจากโหมด AI
1914                         'ออก' => 'exit',
1915                         'หยุด' => 'exit',
1916                         
1917                         'help' => 'help',        // /help = แสดงคำสั่งทั้งหมด
1918                         'ช่วย' => 'help',
1919                     ];
1920                     
1921                     if (isset($commandMap[$command])) {
1922                         $commandMode = $commandMap[$command];
1923                         
1924                         // ถ้าเป็น 'auto' ให้ดึง mode จาก ai_settings
1925                         if ($commandMode === 'auto') {
1926                             try {
1927                                 $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
1928                                 $stmt->execute([$lineAccountId]);
1929                                 $result = $stmt->fetch(PDO::FETCH_ASSOC);
1930                                 $commandMode = ($result && $result['ai_mode']) ? $result['ai_mode'] : 'sales';
1931                             } catch (Exception $e) {
1932                                 $commandMode = 'sales';
1933                             }
1934                         }
1935                         
1936                         devLog($db, 'debug', 'AI_command', 'Command detected', [
1937                             'command' => $command, 
1938                             'mode' => $commandMode, 
1939                             'message' => $commandMessage,
1940                             'original' => $originalText,
1941                             'cleaned' => $cleanText
1942                         ], null);
1943                     } else {
1944                         // Unknown command → ถือว่าเป็นคำถามถาม AI
1945                         // ใช้ mode จาก ai_settings
1946                         try {
1947                             $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
1948                             $stmt->execute([$lineAccountId]);
1949                             $result = $stmt->fetch(PDO::FETCH_ASSOC);
1950                             $commandMode = ($result && $result['ai_mode']) ? $result['ai_mode'] : 'sales';
1951                         } catch (Exception $e) {
1952                             $commandMode = 'sales';
1953                         }
1954                         $commandMessage = $command . ($commandMessage ? ' ' . $commandMessage : '');
1955                         devLog($db, 'debug', 'AI_command', 'Unknown command - treating as AI question', [
1956                             'command' => $command,
1957                             'mode' => $commandMode,
1958                             'message' => $commandMessage,
1959                             'original' => $originalText
1960                         ], null);
1961                     }
1962                 } else {
1963                     devLog($db, 'debug', 'AI_command', 'No command pattern matched', [
1964                         'original' => $originalText,
1965                         'cleaned' => $cleanText
1966                     ], null);
1967                 }
1968                 
1969                 // ===== DEBUG: Log after command parsing =====
1970                 error_log("AI_TRACE_1: commandMode=$commandMode, line=" . __LINE__);
1971                 try {
1972                     devLog($db, 'debug', 'AI_trace_1', 'After command parsing', [
1973                         'commandMode' => $commandMode,
1974                         'commandMessage' => mb_substr($commandMessage ?? '', 0, 30),
1975                         'line' => __LINE__
1976                     ], null);
1977                 } catch (Exception $e) {
1978                     error_log("AI_trace_1 error: " . $e->getMessage());
1979                 }
1980                 
1981                 // ===== 0.5 ตรวจสอบ AI Mode ของ user =====
1982                 // ถ้า user เคยพิมพ์ /ai, /mims, /triage → จำโหมดไว้
1983                 // ข้อความถัดไปจะใช้โหมดนั้นต่อจนกว่าจะเปลี่ยน
1984                 if (!$commandMode && $userId) {
1985                     $currentAIMode = getUserAIMode($db, $userId);
1986                     if ($currentAIMode) {
1987                         $commandMode = $currentAIMode;
1988                         $commandMessage = $originalText;
1989                         devLog($db, 'debug', 'AI_mode', 'Using saved AI mode', ['mode' => $currentAIMode, 'userId' => $userId], null);
1990                     }
1991                 }
1992                 
1993                 // ถ้าพิมพ์ command ใหม่ → บันทึกโหมด
1994                 if ($commandMode && $userId && in_array($commandMode, ['pharmacist', 'pharmacy', 'sales', 'support', 'mims', 'triage'])) {
1995                     setUserAIMode($db, $userId, $commandMode);
1996                 }
1997                 
1998                 // ถ้าพิมพ์ /human หรือ /exit → ลบโหมด
1999                 if (($commandMode === 'human' || $commandMode === 'exit') && $userId) {
2000                     clearUserAIMode($db, $userId);
2001                 }
2002                 
2003                 // ===== DEBUG: Log after mode check =====
2004                 devLog($db, 'debug', 'AI_trace_2', 'After mode check', [
2005                     'commandMode' => $commandMode,
2006                     'line' => __LINE__
2007                 ], null);
2008                 
2009                 // ดึง sender settings สำหรับ system messages
2010                 $systemSender = getAISenderSettings($db, $lineAccountId);
2011                 
2012                 // ===== /exit - ออกจากโหมด AI =====
2013                 if ($commandMode === 'exit') {
2014                     return [[
2015                         'type' => 'text',
2016                         'text' => "✅ ออกจากโหมด AI แล้วค่ะ\n\nข้อความถัดไปจะส่งถึงแอดมินโดยตรง\n\n💡 พิมพ์ /ai, /mims หรือ /triage เพื่อกลับมาใช้ AI ได้ทุกเมื่อค่ะ",
2017                         'sender' => $systemSender
2018                     ]];
2019                 }
2020                 
2021                 // ===== /help - แสดงคำสั่งทั้งหมด =====
2022                 if ($commandMode === 'help') {
2023                     return [[
2024                         'type' => 'text',
2025                         'text' => "🤖 คำสั่ง AI ที่ใช้ได้:\n\n" .
2026                                 "/ai - เข้าโหมด AI ตามที่ตั้งค่าไว้\n" .
2027                                 "/mims - เข้าโหมด MIMS (ข้อมูลยา)\n" .
2028                                 "/triage - เริ่มซักประวัติอาการ\n" .
2029                                 "/human - ขอคุยกับเภสัชกรจริง\n" .
2030                                 "/exit - ออกจากโหมด AI\n\n" .
2031                                 "💡 เมื่อเข้าโหมดแล้ว พิมพ์ข้อความได้เลย\n" .
2032                                 "AI จะตอบต่อจนกว่าจะพิมพ์ /exit",
2033                         'sender' => $systemSender
2034                     ]];
2035                 }
2036                 
2037                 // ===== 1. ตรวจสอบว่า Bot ถูก Pause หรือไม่ =====
2038                 if ($userId && isAIPaused($db, $userId)) {
2039                     // ถ้าพิมพ์ /ai หรือ command อื่น ให้ resume bot
2040                     if ($commandMode && $commandMode !== 'human') {
2041                         resumeAI($db, $userId);
2042                         devLog($db, 'info', 'AI_pause', 'AI resumed by command', ['user_id' => $userId, 'command' => $commandMode], null);
2043                     } else {
2044                         devLog($db, 'debug', 'AI_pause', 'AI is paused for user', ['user_id' => $userId], null);
2045                         return null; // ไม่ตอบ - ให้เภสัชกรจริงตอบ
2046                     }
2047                 }
2048                 
2049                 // ===== 2. /human หรือ คำสั่งขอคุยกับเภสัชกรจริง =====
2050                 if ($commandMode === 'human') {
2051                     pauseAI($db, $userId, 20);
2052                     notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $originalText);
2053                     
2054                     return [[
2055                         'type' => 'text',
2056                         'text' => "เข้าใจค่ะ 🙏\n\nระบบได้แจ้งเภสัชกรแล้ว จะมีเภสัชกรติดต่อกลับภายใน 5-10 นาทีค่ะ\n\n📞 หากต้องการติดต่อด่วน โทร: 02-XXX-XXXX\n\n(บอทจะหยุดตอบชั่วคราว 20 นาที)\n\n💡 พิมพ์ /ai เพื่อกลับมาใช้บอทได้ทุกเมื่อ",
2057                         'sender' => $systemSender
2058                     ]];
2059                 }
2060                 
2061                 // ตรวจสอบ keyword ขอคุยกับเภสัชกรจริง (ไม่ใช้ command)
2062                 $humanPharmacistKeywords = [
2063                     'คุยกับเภสัชกร', 'ขอคุยกับคน', 'ขอคุยกับเภสัช', 'เภสัชกรจริง', 
2064                     'คนจริง', 'ไม่ใช่บอท', 'ไม่เอาบอท', 'หยุดบอท', 'ปิดบอท',
2065                     'ขอพูดกับคน', 'ต้องการคุยกับคน', 'human', 'real pharmacist',
2066                     'ขอเภสัชกรตัวจริง', 'เภสัชตัวจริง', 'ไม่ต้องการ ai', 'ไม่เอา ai'
2067                 ];
2068                 
2069                 if (!$commandMode) {
2070                     foreach ($humanPharmacistKeywords as $keyword) {
2071                         if (mb_strpos($textLower, $keyword) !== false) {
2072                             pauseAI($db, $userId, 20);
2073                             notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $text);
2074                             
2075                             return [[
2076                                 'type' => 'text',
2077                                 'text' => "เข้าใจค่ะ 🙏\n\nระบบได้แจ้งเภสัชกรแล้ว จะมีเภสัชกรติดต่อกลับภายใน 5-10 นาทีค่ะ\n\n📞 หากต้องการติดต่อด่วน โทร: 02-XXX-XXXX\n\n(บอทจะหยุดตอบชั่วคราว 20 นาที)\n\n💡 พิมพ์ /ai เพื่อกลับมาใช้บอทได้ทุกเมื่อ",
2078                                 'sender' => $systemSender
2079                             ]];
2080                         }
2081                     }
2082                 }
2083                 
2084                 // ===== 3. /mims - MIMS Pharmacist AI =====
2085                 if ($commandMode === 'mims') {
2086                     $mimsFileExists = file_exists(__DIR__ . '/modules/AIChat/Adapters/MIMSPharmacistAI.php');
2087                     devLog($db, 'debug', 'AI_mims', 'MIMS command', ['fileExists' => $mimsFileExists, 'message' => $commandMessage], null);
2088                     
2089                     if ($mimsFileExists) {
2090                         try {
2091                             require_once __DIR__ . '/modules/AIChat/Adapters/MIMSPharmacistAI.php';
2092                             $adapter = new \Modules\AIChat\Adapters\MIMSPharmacistAI($db, $lineAccountId);
2093                             if ($userId) $adapter->setUserId($userId);
2094                             
2095                             $isEnabled = $adapter->isEnabled();
2096                             devLog($db, 'debug', 'AI_mims', 'MIMS isEnabled', ['enabled' => $isEnabled, 'commandMessage' => $commandMessage], null);
2097                             
2098                             // ดึง sender settings สำหรับ MIMS mode
2099                             $mimsSender = getAISenderSettings($db, $lineAccountId, 'mims');
2100                             
2101                             if ($isEnabled) {
2102                                 // ถ้าไม่มีข้อความ ให้แสดงคำแนะนำ
2103                                 if (empty($commandMessage)) {
2104                                     devLog($db, 'debug', 'AI_mims', 'MIMS empty message - showing help', [], null);
2105                                     return [[
2106                                         'type' => 'text',
2107                                         'text' => "📚 MIMS Pharmacist AI พร้อมให้บริการค่ะ\n\nสามารถถามข้อมูลเกี่ยวกับ:\n• ข้อมูลยาและสรรพคุณ\n• อาการและการรักษา\n• ข้อควรระวังในการใช้ยา\n\n💡 ตัวอย่าง:\n/mims ยา paracetamol\n/mims อาการปวดหัวไมเกรน\n/mims ยาแก้แพ้ตัวไหนดี",
2108                                         'sender' => $mimsSender
2109                                     ]];
2110                                 }
2111                                 
2112                                 devLog($db, 'debug', 'AI_mims', 'MIMS processing message', ['message' => $commandMessage], null);
2113                                 $result = $adapter->processMessage($commandMessage);
2114                                 devLog($db, 'debug', 'AI_mims', 'MIMS result', ['success' => $result['success'] ?? false, 'hasMessage' => !empty($result['message']), 'hasResponse' => !empty($result['response']), 'error' => $result['error'] ?? null], null);
2115                                 
2116                                 if ($result['success'] && !empty($result['message'])) {
2117                                     $msg = $result['message'];
2118                                     // ตรวจสอบว่า message เป็น array ที่มี type หรือไม่
2119                                     if (is_array($msg) && isset($msg['type'])) {
2120                                         // ตรวจสอบว่ามี text content หรือไม่
2121                                         if (empty($msg['text'])) {
2122                                             // ถ้าไม่มี text ให้ใช้ response แทน
2123                                             $msg['text'] = $result['response'] ?? 'ขออภัยค่ะ ไม่สามารถประมวลผลได้';
2124                                             devLog($db, 'warning', 'AI_mims', 'MIMS message missing text, using response', ['response' => mb_substr($msg['text'], 0, 100)], null);
2125                                         }
2126                                         // เพิ่ม sender ถ้ายังไม่มี
2127                                         if (!isset($msg['sender'])) {
2128                                             $msg['sender'] = $mimsSender;
2129                                         }
2130                                         devLog($db, 'debug', 'AI_mims', 'MIMS returning message array', ['type' => $msg['type'], 'textLength' => strlen($msg['text'] ?? '')], null);
2131                                         return [$msg];
2132                                     }
2133                                     // ถ้าเป็น string ให้ wrap เป็น LINE message
2134                                     if (is_string($msg)) {
2135                                         devLog($db, 'debug', 'AI_mims', 'MIMS returning string message', ['length' => strlen($msg)], null);
2136                                         return [[
2137                                             'type' => 'text',
2138                                             'text' => $msg,
2139                                             'sender' => $mimsSender
2140                                         ]];
2141                                     }
2142                                     devLog($db, 'debug', 'AI_mims', 'MIMS message format unknown', ['messageType' => gettype($msg)], null);
2143                                     return [$msg];
2144                                 }
2145                                 
2146                                 // ถ้า success แต่ไม่มี message ให้ใช้ response
2147                                 if ($result['success'] && !empty($result['response'])) {
2148                                     devLog($db, 'debug', 'AI_mims', 'MIMS using response text', ['length' => strlen($result['response'])], null);
2149                                     return [[
2150                                         'type' => 'text',
2151                                         'text' => $result['response'],
2152                                         'sender' => $mimsSender
2153                                     ]];
2154                                 }
2155                                 
2156                                 // ถ้าไม่ success ให้แสดง error
2157                                 if (!$result['success']) {
2158                                     $errorMsg = $result['error'] ?? 'Unknown error';
2159                                     devLog($db, 'error', 'AI_mims', 'MIMS process failed: ' . $errorMsg, ['user_id' => $userId], null);
2160                                     return [[
2161                                         'type' => 'text',
2162                                         'text' => "❌ MIMS AI ขัดข้อง: {$errorMsg}\n\nลองใช้ /ai แทนได้ค่ะ",
2163                                         'sender' => $mimsSender
2164                                     ]];
2165                                 }
2166                             } else {
2167                                 devLog($db, 'warning', 'AI_mims', 'MIMS not enabled - no API key', [], null);
2168                                 return [[
2169                                     'type' => 'text',
2170                                     'text' => "❌ MIMS AI ยังไม่ได้ตั้งค่า API Key\n\nกรุณาติดต่อผู้ดูแลระบบ หรือลองใช้ /ai แทนได้ค่ะ",
2171                                     'sender' => $mimsSender
2172                                 ]];
2173                             }
2174                         } catch (\Throwable $e) {
2175                             devLog($db, 'error', 'AI_mims', 'MIMS AI error: ' . $e->getMessage(), ['user_id' => $userId, 'trace' => $e->getTraceAsString()], null);
2176                             return [[
2177                                 'type' => 'text',
2178                                 'text' => "❌ MIMS AI ขัดข้อง\n\nลองใช้ /ai แทนได้ค่ะ",
2179                                 'sender' => $mimsSender
2180                             ]];
2181                         }
2182                     }
2183                     
2184                     return [[
2185                         'type' => 'text',
2186                         'text' => "❌ MIMS AI ไม่พร้อมใช้งานขณะนี้\n\nลองใช้ /ai แทนได้ค่ะ",
2187                         'sender' => getAISenderSettings($db, $lineAccountId, 'mims')
2188                     ]];
2189                 }
2190                 
2191                 // ===== 4. /triage - ซักประวัติอาการ =====
2192                 if ($commandMode === 'triage') {
2193                     devLog($db, 'debug', 'AI_triage', 'Triage command', ['userId' => $userId], null);
2194                     
2195                     // ดึง sender settings สำหรับ triage mode
2196                     $triageSender = getAISenderSettings($db, $lineAccountId, 'triage');
2197                     
2198                     if (file_exists(__DIR__ . '/modules/AIChat/Services/TriageEngine.php')) {
2199                         try {
2200                             // Load all required dependencies via Autoloader
2201                             require_once __DIR__ . '/modules/AIChat/Autoloader.php';
2202                             loadAIChatModule();
2203                             
2204                             // Pass $db connection to TriageEngine
2205                             $triage = new \Modules\AIChat\Services\TriageEngine($lineAccountId, $userId, $db);
2206                             
2207                             // Reset และเริ่มใหม่
2208                             $result = $triage->process($commandMessage ?: 'เริ่มซักประวัติ');
2209                             devLog($db, 'debug', 'AI_triage', 'Triage result', ['hasText' => !empty($result['text']), 'hasMessage' => !empty($result['message'])], null);
2210                             
2211                             $responseText = $result['text'] ?? $result['message'] ?? 'พร้อมซักประวัติค่ะ';
2212                             $lineMessage = [
2213                                 'type' => 'text',
2214                                 'text' => $responseText,
2215                                 'sender' => $triageSender
2216                             ];
2217                             
2218                             if (!empty($result['quickReplies'])) {
2219                                 $lineMessage['quickReply'] = ['items' => $result['quickReplies']];
2220                             }
2221                             
2222                             return [$lineMessage];
2223                         } catch (\Throwable $e) {
2224                             devLog($db, 'error', 'AI_triage', 'Triage error: ' . $e->getMessage(), ['user_id' => $userId, 'trace' => $e->getTraceAsString()], null);
2225                             return [[
2226                                 'type' => 'text',
2227                                 'text' => "❌ ระบบซักประวัติขัดข้อง\n\nลองใช้ /ai แทนได้ค่ะ",
2228                                 'sender' => $triageSender
2229                             ]];
2230                         }
2231                     } else {
2232                         return [[
2233                             'type' => 'text',
2234                             'text' => "❌ ระบบซักประวัติไม่พร้อมใช้งาน\n\nลองใช้ /ai แทนได้ค่ะ",
2235                             'sender' => $triageSender
2236                         ]];
2237                     }
2238                 }
2239                 
2240                 // ===== 5. /ai, /sales หรือ Default - ตรวจสอบ AI Mode ก่อน =====
2241                 // ถ้าใช้ command /ai หรือ /sales ให้ใช้ข้อความหลัง command
2242                 $messageToProcess = $text;
2243                 if (!empty($commandMessage)) {
2244                     $messageToProcess = $commandMessage;
2245                 }
2246                 
2247                 // ดึง AI mode จาก ai_settings เสมอ (ไม่ว่า commandMode จะเป็นอะไร)
2248                 $currentAIMode = 'sales'; // default to sales
2249                 try {
2250                     $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
2251                     $stmt->execute([$lineAccountId]);
2252                     $result = $stmt->fetch(PDO::FETCH_ASSOC);
2253                     if ($result && $result['ai_mode']) {
2254                         $currentAIMode = $result['ai_mode'];
2255                     }
2256                 } catch (Exception $e) {}
2257                 
2258                 // ถ้า commandMode เป็น sales/support/pharmacist โดยตรง → override
2259                 if (in_array($commandMode, ['sales', 'support', 'pharmacist', 'pharmacy'])) {
2260                     $currentAIMode = $commandMode;
2261                 }
2262                 
2263                 devLog($db, 'debug', 'AI_section5', 'AI Mode determined', [
2264                     'currentAIMode' => $currentAIMode,
2265                     'commandMode' => $commandMode,
2266                     'message' => mb_substr($messageToProcess, 0, 50)
2267                 ], null);
2268                 
2269                 // ===== ถ้าเป็น Sales/Support Mode → ใช้ GeminiChat (ไม่ใช่ PharmacyAI) =====
2270                 if (in_array($currentAIMode, ['sales', 'support']) && file_exists(__DIR__ . '/classes/GeminiChat.php')) {
2271                     require_once __DIR__ . '/classes/GeminiChat.php';
2272                     
2273                     $gemini = new GeminiChat($db, $lineAccountId);
2274                     
2275                     devLog($db, 'debug', 'AI_sales', 'GeminiChat check', [
2276                         'line_account_id' => $lineAccountId,
2277                         'is_enabled' => $gemini->isEnabled() ? 'yes' : 'no',
2278                         'mode' => $gemini->getMode()
2279                     ], null);
2280                     
2281                     if ($gemini->isEnabled()) {
2282                         $history = $userId ? $gemini->getConversationHistory($userId, 10) : [];
2283                         
2284                         devLog($db, 'debug', 'AI_sales', 'Processing AI request (Sales Mode)', [
2285                             'user_id' => $userId,
2286                             'line_account_id' => $lineAccountId,
2287                             'message' => mb_substr($messageToProcess, 0, 50),
2288                             'history_count' => count($history)
2289                         ], null);
2290                         
2291                         // Extend timeout for AI processing
2292                         devLog($db, 'debug', 'AI_sales', 'Before set_time_limit', [], null);
2293                         @set_time_limit(60);
2294                         devLog($db, 'debug', 'AI_sales', 'After set_time_limit', [], null);
2295                         
2296                         $startTime = microtime(true);
2297                         devLog($db, 'debug', 'AI_sales', 'Calling generateResponse...', [
2298                             'message_length' => mb_strlen($messageToProcess)
2299                         ], null);
2300                         
2301                         $response = null;
2302                         try {
2303                             $response = $gemini->generateResponse($messageToProcess, $userId, $history);
2304                             devLog($db, 'debug', 'AI_sales', 'generateResponse returned', [
2305                                 'response_type' => gettype($response),
2306                                 'response_null' => $response === null ? 'yes' : 'no'
2307                             ], null);
2308                         } catch (Exception $e) {
2309                             devLog($db, 'error', 'AI_sales', 'generateResponse exception: ' . $e->getMessage(), [
2310                                 'trace' => mb_substr($e->getTraceAsString(), 0, 500)
2311                             ], null);
2312                         } catch (Throwable $t) {
2313                             devLog($db, 'error', 'AI_sales', 'generateResponse throwable: ' . $t->getMessage(), [
2314                                 'trace' => mb_substr($t->getTraceAsString(), 0, 500)
2315                             ], null);
2316                         }
2317                         
2318                         $elapsed = round((microtime(true) - $startTime) * 1000);
2319                         
2320                         devLog($db, 'debug', 'AI_sales', 'GeminiChat response received', [
2321                             'elapsed_ms' => $elapsed,
2322                             'response_null' => $response === null ? 'yes' : 'no',
2323                             'response_length' => $response ? mb_strlen($response) : 0
2324                         ], null);
2325                         
2326                         if ($response) {
2327                             // ใช้ sender จาก ai_settings
2328                             $sender = getAISenderSettings($db, $lineAccountId, $currentAIMode);
2329                             
2330                             $message = [
2331                                 'type' => 'text',
2332                                 'text' => $response,
2333                                 'sender' => $sender
2334                             ];
2335                             
2336                             devLog($db, 'debug', 'AI_sales', 'AI response generated (Sales Mode)', [
2337                                 'user_id' => $userId,
2338                                 'response_length' => mb_strlen($response)
2339                             ], null);
2340                             
2341                             return [$message];
2342                         } else {
2343                             devLog($db, 'warning', 'AI_sales', 'GeminiChat returned null response', [
2344                                 'user_id' => $userId,
2345                                 'message' => mb_substr($messageToProcess, 0, 50)
2346                             ], null);
2347                             // Sales mode แต่ GeminiChat return null → return null ไม่ fallthrough ไป PharmacyAI
2348                             return null;
2349                         }
2350                     } else {
2351                         devLog($db, 'warning', 'AI_sales', 'GeminiChat not enabled', [
2352                             'line_account_id' => $lineAccountId
2353                         ], null);
2354                         // Sales mode แต่ GeminiChat not enabled → return null ไม่ fallthrough ไป PharmacyAI
2355                         return null;
2356                     }
2357                 }
2358                 
2359                 // ===== ถ้าเป็น Pharmacist Mode → ใช้ PharmacyAI Adapter =====
2360                 // เข้าเฉพาะเมื่อ currentAIMode เป็น pharmacist/pharmacy เท่านั้น
2361                 $usePharmacyAI = in_array($currentAIMode, ['pharmacist', 'pharmacy']) 
2362                                  && file_exists(__DIR__ . '/modules/AIChat/Adapters/PharmacyAIAdapter.php');
2363                 
2364                 devLog($db, 'debug', 'AI_pharmacy_check', 'PharmacyAI condition', [
2365                     'currentAIMode' => $currentAIMode,
2366                     'usePharmacyAI' => $usePharmacyAI ? 'yes' : 'no',
2367                     'file_exists' => file_exists(__DIR__ . '/modules/AIChat/Adapters/PharmacyAIAdapter.php') ? 'yes' : 'no'
2368                 ], null);
2369                 
2370                 if ($usePharmacyAI && $userId) {
2371                     try {
2372                         require_once __DIR__ . '/modules/AIChat/Adapters/PharmacyAIAdapter.php';
2373                         
2374                         $adapter = new \Modules\AIChat\Adapters\PharmacyAIAdapter($db, $lineAccountId);
2375                         $adapter->setUserId($userId);
2376                         
2377                         // Log isEnabled status
2378                         devLog($db, 'debug', 'AI_pharmacy', 'PharmacyAI isEnabled check', [
2379                             'user_id' => $userId,
2380                             'line_account_id' => $lineAccountId,
2381                             'is_enabled' => $adapter->isEnabled() ? 'yes' : 'no'
2382                         ], null);
2383                         
2384                         if (!$adapter->isEnabled()) {
2385                             devLog($db, 'warning', 'AI_pharmacy', 'PharmacyAI not enabled - no API key', [
2386                                 'line_account_id' => $lineAccountId
2387                             ], null);
2388                             // Fallback to other methods
2389                         } else {
2390                             // Log for debugging
2391                             devLog($db, 'debug', 'AI_pharmacy', 'Processing AI request (PharmacyAI v5)', [
2392                                 'user_id' => $userId,
2393                                 'line_account_id' => $lineAccountId,
2394                                 'message' => mb_substr($messageToProcess, 0, 50),
2395                                 'command_mode' => $commandMode
2396                             ], null);
2397                             
2398                             // ใช้ PharmacyAI Adapter
2399                             $result = $adapter->processMessage($messageToProcess);
2400                             
2401                             if ($result['success'] && !empty($result['message'])) {
2402                                 devLog($db, 'debug', 'AI_pharmacy', 'AI response generated (PharmacyAI v5)', [
2403                                     'user_id' => $userId,
2404                                     'response_length' => mb_strlen($result['response'] ?? ''),
2405                                     'state' => $result['state'] ?? 'unknown',
2406                                     'is_critical' => $result['is_critical'] ?? false,
2407                                     'has_products' => !empty($result['products'])
2408                                 ], null);
2409                                 
2410                                 // รองรับ multiple messages (text + product carousel)
2411                                 $messages = $result['messages'] ?? $result['message'];
2412                                 
2413                                 // ถ้าเป็น single message ให้ wrap เป็น array
2414                                 if (isset($messages['type'])) {
2415                                     return [$messages];
2416                                 }
2417                                 
2418                                 // ถ้าเป็น array ของ messages แล้ว return ตรงๆ
2419                                 return $messages;
2420                             }
2421                             
2422                             return null;
2423                         }
2424                     } catch (Exception $e) {
2425                         devLog($db, 'warning', 'AI_pharmacy', 'PharmacyAI error, fallback: ' . $e->getMessage(), [
2426                             'user_id' => $userId
2427                         ], null);
2428                     }
2429                 }
2430                 
2431                 // ===== Fallback: ลองใช้ GeminiChatAdapter (เฉพาะ pharmacist mode) =====
2432                 // ถ้าเป็น sales mode ไม่ต้อง fallback เพราะ GeminiChat ควรทำงานแล้ว
2433                 $useNewModule = ($currentAIMode !== 'sales') && file_exists(__DIR__ . '/modules/AIChat/Autoloader.php');
2434                 
2435                 if ($useNewModule) {
2436                     try {
2437                         require_once __DIR__ . '/modules/AIChat/Adapters/GeminiChatAdapter.php';
2438                         
2439                         $adapter = new \Modules\AIChat\Adapters\GeminiChatAdapter($db, $lineAccountId);
2440                         
2441                         if (!$adapter->isEnabled()) {
2442                             return null;
2443                         }
2444                         
2445                         // Log for debugging
2446                         devLog($db, 'debug', 'AI_chatbot_v2', 'Processing AI request (Module v2)', [
2447                             'user_id' => $userId,
2448                             'line_account_id' => $lineAccountId,
2449                             'message' => mb_substr($text, 0, 50)
2450                         ], null);
2451                         
2452                         // ใช้ method ใหม่ที่ return message object พร้อมใช้
2453                         $result = $adapter->generateResponseWithMessage($text, $userId);
2454                         
2455                         if ($result['success'] && !empty($result['message'])) {
2456                             devLog($db, 'debug', 'AI_chatbot_v2', 'AI response generated (Module v2)', [
2457                                 'user_id' => $userId,
2458                                 'response_length' => mb_strlen($result['response'])
2459                             ], null);
2460                             
2461                             return [$result['message']];
2462                         }
2463                         
2464                         return null;
2465                         
2466                     } catch (Exception $e) {
2467                         // ถ้า Module ใหม่ error ให้ fallback ไปใช้ระบบเก่า
2468                         devLog($db, 'warning', 'AI_chatbot_v2', 'Module v2 error, fallback to v1: ' . $e->getMessage(), [
2469                             'user_id' => $userId
2470                         ], null);
2471                     }
2472                 }
2473                 
2474                 // ===== Fallback: ใช้ GeminiChat เก่า =====
2475                 if (file_exists(__DIR__ . '/classes/GeminiChat.php')) {
2476                     require_once __DIR__ . '/classes/GeminiChat.php';
2477                     
2478                     $gemini = new GeminiChat($db, $lineAccountId);
2479                     
2480                     if (!$gemini->isEnabled()) {
2481                         return null;
2482                     }
2483                     
2484                     // Get conversation history for context
2485                     $history = $userId ? $gemini->getConversationHistory($userId, 10) : [];
2486                     
2487                     // Log for debugging
2488                     devLog($db, 'debug', 'AI_chatbot', 'Processing AI request (Legacy)', [
2489                         'user_id' => $userId,
2490                         'line_account_id' => $lineAccountId,
2491                         'message' => mb_substr($text, 0, 50),
2492                         'history_count' => count($history)
2493                     ], null);
2494                     
2495                     // Generate response with full history
2496                     $response = $gemini->generateResponse($text, $userId, $history);
2497                     
2498                     if ($response) {
2499                         // Build message with sender and quick reply from settings
2500                         $message = ['type' => 'text', 'text' => $response];
2501                         
2502                         // Get AI settings for sender and quick reply
2503                         try {
2504                             $stmtAI = $db->prepare("SELECT sender_name, sender_icon, quick_reply_buttons FROM ai_chat_settings WHERE line_account_id = ?");
2505                             $stmtAI->execute([$lineAccountId]);
2506                             $aiSettings = $stmtAI->fetch(PDO::FETCH_ASSOC);
2507                             
2508                             // Add Sender if configured
2509                             if ($aiSettings && !empty($aiSettings['sender_name'])) {
2510                                 $message['sender'] = ['name' => $aiSettings['sender_name']];
2511                                 if (!empty($aiSettings['sender_icon'])) {
2512                                     $message['sender']['iconUrl'] = $aiSettings['sender_icon'];
2513                                 }
2514                             }
2515                             
2516                             // Add Quick Reply if configured
2517                             if ($aiSettings && !empty($aiSettings['quick_reply_buttons'])) {
2518                                 $qrButtons = json_decode($aiSettings['quick_reply_buttons'], true);
2519                                 if ($qrButtons && is_array($qrButtons) && count($qrButtons) > 0) {
2520                                     $quickReplyItems = [];
2521                                     foreach ($qrButtons as $btn) {
2522                                         if (!empty($btn['label']) && !empty($btn['text'])) {
2523                                             $quickReplyItems[] = [
2524                                                 'type' => 'action',
2525                                                 'action' => [
2526                                                     'type' => 'message',
2527                                                     'label' => $btn['label'],
2528                                                     'text' => $btn['text']
2529                                                 ]
2530                                             ];
2531                                         }
2532                                     }
2533                                     if (count($quickReplyItems) > 0) {
2534                                         $message['quickReply'] = ['items' => array_slice($quickReplyItems, 0, 13)];
2535                                     }
2536                                 }
2537                             }
2538                         } catch (Exception $e) {
2539                             // Ignore errors, just send without sender/quick reply
2540                         }
2541                         
2542                         return [$message];
2543                     }
2544                 }
2545                 
2546                 // Fallback to old method if GeminiChat not available
2547                 $stmt = $db->prepare("SELECT * FROM ai_settings WHERE id = 1");
2548                 $stmt->execute();
2549                 $settings = $stmt->fetch();
2550 
2551                 if (!$settings || !$settings['is_enabled']) return null;
2552 
2553                 // Try OpenAI if available
2554                 if (class_exists('OpenAI')) {
2555                     $openai = new OpenAI();
2556                     $result = $openai->chat(
2557                         $text,
2558                         $settings['system_prompt'],
2559                         $settings['model'],
2560                         $settings['max_tokens'],
2561                         $settings['temperature']
2562                     );
2563                     return $result['success'] ? $result['message'] : null;
2564                 }
2565                 
2566                 return null;
2567                 
2568             } catch (Exception $e) {
2569                 error_log("checkAIChatbot error: " . $e->getMessage());
2570                 devLog($db, 'error', 'AI_chatbot', $e->getMessage(), [
2571                     'user_id' => $userId,
2572                     'line_account_id' => $lineAccountId
2573                 ], null);
2574                 return null;
2575             }
2576         }
2577 
2578         /**
2579          * Save outgoing message
2580          * @param PDO $db Database connection
2581          * @param int $userId User ID
2582          * @param mixed $content Message content
2583          * @param string $sentBy Who sent the message: 'ai', 'admin', 'system', 'webhook'
2584          * @param string $messageType Message type: 'text', 'flex', 'image', etc.
2585          */
2586         function saveOutgoingMessage($db, $userId, $content, $sentBy = 'system', $messageType = 'text') {
2587             try {
2588                 // Check if sent_by column exists
2589                 $hasSentBy = false;
2590                 try {
2591                     $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
2592                     $hasSentBy = $checkCol->rowCount() > 0;
2593                 } catch (Exception $e) {}
2594                 
2595                 $contentStr = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content;
2596                 
2597                 if ($hasSentBy) {
2598                     $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, sent_by) VALUES (?, 'outgoing', ?, ?, ?)");
2599                     $stmt->execute([$userId, $messageType, $contentStr, $sentBy]);
2600                 } else {
2601                     $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content) VALUES (?, 'outgoing', ?, ?)");
2602                     $stmt->execute([$userId, $messageType, $contentStr]);
2603                 }
2604             } catch (Exception $e) {
2605                 error_log("saveOutgoingMessage error: " . $e->getMessage());
2606             }
2607         }
2608 
2609         /**
2610          * Log analytics event
2611          */
2612         function logAnalytics($db, $eventType, $data, $lineAccountId = null) {
2613             try {
2614                 // Check if line_account_id column exists
2615                 $stmt = $db->query("SHOW COLUMNS FROM analytics LIKE 'line_account_id'");
2616                 if ($stmt->rowCount() > 0) {
2617                     $stmt = $db->prepare("INSERT INTO analytics (line_account_id, event_type, event_data) VALUES (?, ?, ?)");
2618                     $stmt->execute([$lineAccountId, $eventType, json_encode($data)]);
2619                 } else {
2620                     $stmt = $db->prepare("INSERT INTO analytics (event_type, event_data) VALUES (?, ?)");
2621                     $stmt->execute([$eventType, json_encode($data)]);
2622                 }
2623             } catch (Exception $e) {
2624                 // Fallback
2625                 $stmt = $db->prepare("INSERT INTO analytics (event_type, event_data) VALUES (?, ?)");
2626                 $stmt->execute([$eventType, json_encode($data)]);
2627             }
2628         }
2629         
2630         /**
2631          * Developer Log - บันทึก log สำหรับ debug
2632          * @param PDO $db Database connection
2633          * @param string $type Log type: error, warning, info, debug, webhook
2634          * @param string $source Source of log (e.g., 'webhook', 'BusinessBot', 'LineAPI')
2635          * @param string $message Log message
2636          * @param array|null $data Additional data
2637          * @param string|null $userId LINE user ID (optional)
2638          */
2639         function devLog($db, $type, $source, $message, $data = null, $userId = null) {
2640             try {
2641                 $stmt = $db->prepare("INSERT INTO dev_logs (log_type, source, message, data, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
2642                 $stmt->execute([
2643                     $type,
2644                     $source,
2645                     $message,
2646                     $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
2647                     $userId
2648                 ]);
2649             } catch (Exception $e) {
2650                 // Table might not exist - log to error_log instead
2651                 error_log("[{$type}] [{$source}] {$message} " . ($data ? json_encode($data) : ''));
2652             }
2653         }
2654         
2655         /**
2656          * Get AI Sender Settings from ai_settings table
2657          * @param PDO $db Database connection
2658          * @param int|null $lineAccountId LINE Account ID
2659          * @param string|null $overrideMode Override AI mode (optional)
2660          * @return array ['name' => string, 'iconUrl' => string]
2661          */
2662         function getAISenderSettings($db, $lineAccountId = null, $overrideMode = null) {
2663             $defaultSender = [
2664                 'name' => '🤖 AI Assistant',
2665                 'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png'
2666             ];
2667             
2668             try {
2669                 $stmt = $db->prepare("SELECT sender_name, sender_icon, ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
2670                 $stmt->execute([$lineAccountId]);
2671                 $settings = $stmt->fetch(PDO::FETCH_ASSOC);
2672                 
2673                 if ($settings) {
2674                     $mode = $overrideMode ?? $settings['ai_mode'] ?? 'sales';
2675                     
2676                     // ใช้ sender_name จาก settings ถ้ามี
2677                     if (!empty($settings['sender_name'])) {
2678                         $defaultSender['name'] = $settings['sender_name'];
2679                     } else {
2680                         // Default sender name ตาม ai_mode
2681                         switch ($mode) {
2682                             case 'pharmacist':
2683                             case 'pharmacy':
2684                                 $defaultSender['name'] = '💊 เภสัชกร AI';
2685                                 break;
2686                             case 'mims':
2687                                 $defaultSender['name'] = '📚 MIMS Pharmacist AI';
2688                                 break;
2689                             case 'triage':
2690                                 $defaultSender['name'] = '🩺 ซักประวัติ AI';
2691                                 break;
2692                             case 'support':
2693                                 $defaultSender['name'] = '💬 ซัพพอร์ต AI';
2694                                 break;
2695                             case 'sales':
2696                             default:
2697                                 $defaultSender['name'] = '🛒 พนักงานขาย AI';
2698                                 break;
2699                         }
2700                     }
2701                     
2702                     // ใช้ sender_icon จาก settings ถ้ามี
2703                     if (!empty($settings['sender_icon'])) {
2704                         $defaultSender['iconUrl'] = $settings['sender_icon'];
2705                     }
2706                 }
2707             } catch (Exception $e) {
2708                 // Use default
2709             }
2710             
2711             return $defaultSender;
2712         }
2713         
2714         /**
2715          * Get account name by ID
2716          */
2717         function getAccountName($db, $lineAccountId) {
2718             if (!$lineAccountId) return null;
2719             try {
2720                 $stmt = $db->prepare("SELECT name FROM line_accounts WHERE id = ?");
2721                 $stmt->execute([$lineAccountId]);
2722                 return $stmt->fetchColumn() ?: null;
2723             } catch (Exception $e) {
2724                 return null;
2725             }
2726         }
2727         
2728         /**
2729          * ตรวจสอบว่าผู้ใช้ยินยอม PDPA แล้วหรือยัง
2730          * - ถ้าผู้ใช้เคย consent กับบอทใดบอทหนึ่งแล้ว ถือว่า consent แล้ว (ใช้ได้กับทุกบอท)
2731          * - เช็คจาก line_user_id แทน user_id เพื่อให้ consent ใช้ได้ข้ามบอท
2732          */
2733         function checkUserConsent($db, $userId, $lineUserId = null) {
2734             try {
2735                 // ตรวจสอบว่ามี column consent_privacy หรือไม่
2736                 $hasConsentCols = false;
2737                 try {
2738                     $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_privacy'");
2739                     $hasConsentCols = $checkCol->rowCount() > 0;
2740                 } catch (Exception $e) {}
2741                 
2742                 // ถ้ายังไม่มี columns ให้ผ่านไปก่อน (ยังไม่ได้ run migration)
2743                 if (!$hasConsentCols) {
2744                     return true;
2745                 }
2746                 
2747                 // ตรวจสอบว่ามี column consent_at หรือไม่
2748                 $hasConsentAt = false;
2749                 try {
2750                     $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_at'");
2751                     $hasConsentAt = $checkCol->rowCount() > 0;
2752                 } catch (Exception $e) {}
2753                 
2754                 // ถ้ามี lineUserId ให้เช็คจาก line_user_id (ข้ามบอทได้)
2755                 if ($lineUserId) {
2756                     // เช็คว่าผู้ใช้คนนี้เคย consent กับบอทใดบอทหนึ่งแล้วหรือยัง
2757                     $stmt = $db->prepare("SELECT id, consent_privacy, consent_terms FROM users WHERE line_user_id = ? AND consent_privacy = 1 AND consent_terms = 1 LIMIT 1");
2758                     $stmt->execute([$lineUserId]);
2759                     $consentedUser = $stmt->fetch(PDO::FETCH_ASSOC);
2760                     
2761                     if ($consentedUser) {
2762                         // ถ้าเคย consent แล้ว ให้ copy consent ไปยัง user record ปัจจุบัน (ถ้าต่าง id)
2763                         if ($consentedUser['id'] != $userId) {
2764                             try {
2765                                 if ($hasConsentAt) {
2766                                     $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1, consent_at = NOW() WHERE id = ?");
2767                                 } else {
2768                                     $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1 WHERE id = ?");
2769                                 }
2770                                 $stmt->execute([$userId]);
2771                             } catch (Exception $e) {
2772                                 // Ignore error, consent check still passes
2773                             }
2774                         }
2775                         return true;
2776                     }
2777                 }
2778                 
2779                 // ตรวจสอบจาก users table ตาม user_id
2780                 $stmt = $db->prepare("SELECT consent_privacy, consent_terms FROM users WHERE id = ?");
2781                 $stmt->execute([$userId]);
2782                 $user = $stmt->fetch(PDO::FETCH_ASSOC);
2783                 
2784                 if ($user && $user['consent_privacy'] && $user['consent_terms']) {
2785                     return true;
2786                 }
2787                 
2788                 // ตรวจสอบจาก user_consents table
2789                 try {
2790                     // เช็คจาก line_user_id ก่อน (ข้ามบอทได้)
2791                     if ($lineUserId) {
2792                         $stmt = $db->prepare("
2793                             SELECT uc.consent_type, uc.is_accepted 
2794                             FROM user_consents uc
2795                             JOIN users u ON uc.user_id = u.id
2796                             WHERE u.line_user_id = ? AND uc.consent_type IN ('privacy_policy', 'terms_of_service') AND uc.is_accepted = 1
2797                         ");
2798                         $stmt->execute([$lineUserId]);
2799                     } else {
2800                         $stmt = $db->prepare("
2801                             SELECT consent_type, is_accepted 
2802                             FROM user_consents 
2803                             WHERE user_id = ? AND consent_type IN ('privacy_policy', 'terms_of_service')
2804                         ");
2805                         $stmt->execute([$userId]);
2806                     }
2807                     $consents = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
2808                     
2809                     $hasPrivacy = !empty($consents['privacy_policy']);
2810                     $hasTerms = !empty($consents['terms_of_service']);
2811                     
2812                     if ($hasPrivacy && $hasTerms) {
2813                         // Copy consent ไปยัง user record ปัจจุบัน
2814                         try {
2815                             if ($hasConsentAt) {
2816                                 $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1, consent_at = NOW() WHERE id = ?");
2817                             } else {
2818                                 $stmt = $db->prepare("UPDATE users SET consent_privacy = 1, consent_terms = 1 WHERE id = ?");
2819                             }
2820                             $stmt->execute([$userId]);
2821                         } catch (Exception $e) {}
2822                         return true;
2823                     }
2824                     
2825                     return false;
2826                 } catch (Exception $e) {
2827                     // ถ้า user_consents table ไม่มี ให้ดูจาก users table อย่างเดียว
2828                     return false;
2829                 }
2830                 
2831             } catch (Exception $e) {
2832                 // ถ้า error ให้ผ่านไปก่อน (ไม่ block user)
2833                 return true;
2834             }
2835         }
2836         
2837         /**
2838          * Get or Create User - ตรวจสอบและบันทึกผู้ใช้เสมอ (ไม่ว่าจะมาจากกลุ่มหรือแชทส่วนตัว)
2839          */
2840         function getOrCreateUser($db, $line, $userId, $lineAccountId = null, $groupId = null) {
2841             // ตรวจสอบว่ามีผู้ใช้อยู่แล้วหรือไม่
2842             $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
2843             $stmt->execute([$userId]);
2844             $user = $stmt->fetch(PDO::FETCH_ASSOC);
2845             
2846             // ถ้ายังไม่มี ให้สร้างใหม่
2847             if (!$user) {
2848                 // ดึงข้อมูลโปรไฟล์จาก LINE
2849                 $profile = null;
2850                 try {
2851                     if ($groupId) {
2852                         // ถ้ามาจากกลุ่ม ใช้ getGroupMemberProfile
2853                         $profile = $line->getGroupMemberProfile($groupId, $userId);
2854                     } else {
2855                         // ถ้ามาจากแชทส่วนตัว ใช้ getProfile
2856                         $profile = $line->getProfile($userId);
2857                     }
2858                 } catch (Exception $e) {
2859                     error_log("getOrCreateUser profile error: " . $e->getMessage());
2860                 }
2861                 
2862                 $displayName = $profile['displayName'] ?? 'Unknown';
2863                 $pictureUrl = $profile['pictureUrl'] ?? '';
2864                 $statusMessage = $profile['statusMessage'] ?? '';
2865                 
2866                 // บันทึกผู้ใช้ใหม่
2867                 try {
2868                     $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
2869                     if ($stmt->rowCount() > 0) {
2870                         $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name, picture_url, status_message) VALUES (?, ?, ?, ?, ?)");
2871                         $stmt->execute([$lineAccountId, $userId, $displayName, $pictureUrl, $statusMessage]);
2872                     } else {
2873                         $stmt = $db->prepare("INSERT INTO users (line_user_id, display_name, picture_url, status_message) VALUES (?, ?, ?, ?)");
2874                         $stmt->execute([$userId, $displayName, $pictureUrl, $statusMessage]);
2875                     }
2876                     
2877                     $user = [
2878                         'id' => $db->lastInsertId(),
2879                         'display_name' => $displayName,
2880                         'picture_url' => $pictureUrl,
2881                         'line_account_id' => $lineAccountId
2882                     ];
2883                     
2884                     // บันทึกเป็น follower ด้วย (ถ้ามี lineAccountId)
2885                     if ($lineAccountId) {
2886                         saveAccountFollower($db, $lineAccountId, $userId, $user['id'], $profile, true);
2887                     }
2888                     
2889                 } catch (Exception $e) {
2890                     error_log("getOrCreateUser insert error: " . $e->getMessage());
2891                     // ลองดึงอีกครั้ง (อาจมี race condition)
2892                     $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
2893                     $stmt->execute([$userId]);
2894                     $user = $stmt->fetch(PDO::FETCH_ASSOC);
2895                 }
2896             } else {
2897                 // ถ้ามีอยู่แล้ว แต่ยังไม่มี line_account_id ให้อัพเดท
2898                 if ($lineAccountId && empty($user['line_account_id'])) {
2899                     try {
2900                         $stmt = $db->prepare("UPDATE users SET line_account_id = ? WHERE id = ? AND (line_account_id IS NULL OR line_account_id = 0)");
2901                         $stmt->execute([$lineAccountId, $user['id']]);
2902                         $user['line_account_id'] = $lineAccountId;
2903                     } catch (Exception $e) {}
2904                 }
2905             }
2906             
2907             return $user;
2908         }
2909         
2910         /**
2911          * Save account follower - บันทึกข้อมูล follower แยกตามบอท
2912          */
2913         function saveAccountFollower($db, $lineAccountId, $lineUserId, $dbUserId, $profile, $isFollow) {
2914             try {
2915                 if ($isFollow) {
2916                     // Follow event
2917                     $stmt = $db->prepare("
2918                         INSERT INTO account_followers 
2919                         (line_account_id, line_user_id, user_id, display_name, picture_url, status_message, is_following, followed_at, follow_count) 
2920                         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), 1)
2921                         ON DUPLICATE KEY UPDATE 
2922                             display_name = VALUES(display_name),
2923                             picture_url = VALUES(picture_url),
2924                             status_message = VALUES(status_message),
2925                             is_following = 1,
2926                             followed_at = IF(is_following = 0, NOW(), followed_at),
2927                             follow_count = follow_count + IF(is_following = 0, 1, 0),
2928                             unfollowed_at = NULL,
2929                             updated_at = NOW()
2930                     ");
2931                     $stmt->execute([
2932                         $lineAccountId,
2933                         $lineUserId,
2934                         $dbUserId,
2935                         $profile['displayName'] ?? '',
2936                         $profile['pictureUrl'] ?? '',
2937                         $profile['statusMessage'] ?? ''
2938                     ]);
2939                 } else {
2940                     // Unfollow event
2941                     $stmt = $db->prepare("
2942                         UPDATE account_followers 
2943                         SET is_following = 0, unfollowed_at = NOW(), updated_at = NOW()
2944                         WHERE line_account_id = ? AND line_user_id = ?
2945                     ");
2946                     $stmt->execute([$lineAccountId, $lineUserId]);
2947                 }
2948             } catch (Exception $e) {
2949                 error_log("saveAccountFollower error: " . $e->getMessage());
2950             }
2951         }
2952         
2953         /**
2954          * Save account event - บันทึก event แยกตามบอท
2955          */
2956         function saveAccountEvent($db, $lineAccountId, $eventType, $lineUserId, $dbUserId, $event) {
2957             // Skip if no line_user_id (required field)
2958             if (empty($lineUserId)) {
2959                 return;
2960             }
2961             
2962             try {
2963                 $webhookEventId = $event['webhookEventId'] ?? null;
2964                 $timestamp = $event['timestamp'] ?? null;
2965                 $replyToken = $event['replyToken'] ?? null;
2966                 $sourceType = $event['source']['type'] ?? 'user';
2967                 $sourceId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
2968                 
2969                 $stmt = $db->prepare("
2970                     INSERT INTO account_events 
2971                     (line_account_id, event_type, line_user_id, user_id, event_data, webhook_event_id, source_type, source_id, reply_token, timestamp) 
2972                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
2973                 ");
2974                 $stmt->execute([
2975                     $lineAccountId,
2976                     $eventType,
2977                     $lineUserId,
2978                     $dbUserId,
2979                     json_encode($event),
2980                     $webhookEventId,
2981                     $sourceType,
2982                     $sourceId,
2983                     $replyToken,
2984                     $timestamp
2985                 ]);
2986             } catch (Exception $e) {
2987                 error_log("saveAccountEvent error: " . $e->getMessage());
2988             }
2989         }
2990         
2991         /**
2992          * Update account daily stats - อัพเดทสถิติรายวัน
2993          */
2994         function updateAccountDailyStats($db, $lineAccountId, $field) {
2995             try {
2996                 $today = date('Y-m-d');
2997                 $validFields = ['new_followers', 'unfollowers', 'total_messages', 'incoming_messages', 'outgoing_messages', 'unique_users'];
2998                 if (!in_array($field, $validFields)) return;
2999                 
3000                 $stmt = $db->prepare("
3001                     INSERT INTO account_daily_stats (line_account_id, stat_date, {$field}) 
3002                     VALUES (?, ?, 1)
3003                     ON DUPLICATE KEY UPDATE {$field} = {$field} + 1, updated_at = NOW()
3004                 ");
3005                 $stmt->execute([$lineAccountId, $today]);
3006             } catch (Exception $e) {
3007                 error_log("updateAccountDailyStats error: " . $e->getMessage());
3008             }
3009         }
3010         
3011         /**
3012          * Update follower interaction - อัพเดทข้อมูล interaction ของ follower
3013          */
3014         function updateFollowerInteraction($db, $lineAccountId, $lineUserId) {
3015             try {
3016                 $stmt = $db->prepare("
3017                     UPDATE account_followers 
3018                     SET last_interaction_at = NOW(), total_messages = total_messages + 1, updated_at = NOW()
3019                     WHERE line_account_id = ? AND line_user_id = ?
3020                 ");
3021                 $stmt->execute([$lineAccountId, $lineUserId]);
3022             } catch (Exception $e) {
3023                 // Ignore
3024             }
3025         }
3026 
3027         /**
3028          * Send Telegram notification
3029          */
3030         function sendTelegramNotification($db, $type, $displayName, $message = '', $lineUserId = '', $dbUserId = null, $accountName = null) {
3031             $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
3032             $stmt->execute();
3033             $settings = $stmt->fetch();
3034 
3035             if (!$settings || !$settings['is_enabled']) return;
3036 
3037             $telegram = new TelegramAPI();
3038             
3039             // เพิ่มชื่อบอทในข้อความ
3040             $botInfo = $accountName ? " [บอท: {$accountName}]" : "";
3041 
3042             switch ($type) {
3043                 case 'follow':
3044                     if ($settings['notify_new_follower']) {
3045                         $telegram->notifyNewFollower($displayName . $botInfo, $lineUserId);
3046                     }
3047                     break;
3048                 case 'unfollow':
3049                     if ($settings['notify_unfollow']) {
3050                         $telegram->notifyUnfollow($displayName . $botInfo);
3051                     }
3052                     break;
3053                 case 'message':
3054                     if ($settings['notify_new_message']) {
3055                         $telegram->notifyNewMessage($displayName . $botInfo, $message, $lineUserId, $dbUserId);
3056                     }
3057                     break;
3058             }
3059         }
3060 
3061         /**
3062          * Get user state
3063          */
3064         function getUserState($db, $userId) {
3065             try {
3066                 // ดึงข้อมูลโดยไม่ตรวจสอบ expires_at ใน SQL
3067                 $stmt = $db->prepare("SELECT * FROM user_states WHERE user_id = ?");
3068                 $stmt->execute([$userId]);
3069                 $state = $stmt->fetch(PDO::FETCH_ASSOC);
3070                 
3071                 if ($state) {
3072                     // ตรวจสอบ expires_at ใน PHP
3073                     $expired = $state['expires_at'] && strtotime($state['expires_at']) < time();
3074                     if ($expired) {
3075                         // State หมดอายุ - ลบทิ้ง
3076                         clearUserState($db, $userId);
3077                         return null;
3078                     }
3079                     return $state;
3080                 }
3081                 return null;
3082             } catch (Exception $e) {
3083                 return null; // Table doesn't exist or error
3084             }
3085         }
3086 
3087         /**
3088          * Set user state
3089          */
3090         function setUserState($db, $userId, $state, $data = null, $expiresMinutes = 10) {
3091             try {
3092                 $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresMinutes} minutes"));
3093                 
3094                 // Check if user_states has user_id as PRIMARY KEY or separate id
3095                 $stmt = $db->query("SHOW KEYS FROM user_states WHERE Key_name = 'PRIMARY'");
3096                 $primaryKey = $stmt->fetch(PDO::FETCH_ASSOC);
3097                 
3098                 if ($primaryKey && $primaryKey['Column_name'] === 'user_id') {
3099                     // user_id is PRIMARY KEY - use ON DUPLICATE KEY
3100                     $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?) 
3101                                         ON DUPLICATE KEY UPDATE state = ?, state_data = ?, expires_at = ?");
3102                     $stmt->execute([$userId, $state, json_encode($data), $expiresAt, $state, json_encode($data), $expiresAt]);
3103                 } else {
3104                     // Separate id column - delete first then insert
3105                     $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
3106                     $stmt->execute([$userId]);
3107                     
3108                     $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)");
3109                     $stmt->execute([$userId, $state, json_encode($data), $expiresAt]);
3110                 }
3111                 
3112                 devLog($db, 'debug', 'setUserState', 'State saved', ['user_id' => $userId, 'state' => $state, 'data' => $data]);
3113             } catch (Exception $e) {
3114                 devLog($db, 'error', 'setUserState', 'Error: ' . $e->getMessage(), ['user_id' => $userId]);
3115             }
3116         }
3117 
3118         /**
3119          * Clear user state
3120          */
3121         function clearUserState($db, $userId) {
3122             try {
3123                 $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
3124                 $stmt->execute([$userId]);
3125             } catch (Exception $e) {
3126                 // Table doesn't exist, ignore
3127             }
3128         }
3129 
3130         /**
3131          * Create order from pending state when customer confirms
3132          */
3133         function createOrderFromPendingState($db, $line, $dbUserId, $lineUserId, $userState, $replyToken, $lineAccountId) {
3134             try {
3135                 $stateData = json_decode($userState['state_data'] ?? '{}', true);
3136                 $items = $stateData['items'] ?? [];
3137                 $total = (float)($stateData['total'] ?? 0);
3138                 $subtotal = (float)($stateData['subtotal'] ?? $total);
3139                 $discount = (float)($stateData['discount'] ?? 0);
3140                 
3141                 if (empty($items)) {
3142                     devLog($db, 'error', 'createOrderFromPendingState', 'No items in pending order', ['user_id' => $dbUserId]);
3143                     return false;
3144                 }
3145                 
3146                 // Check if transactions table exists
3147                 try {
3148                     $tableCheck = $db->query("SHOW TABLES LIKE 'transactions'")->fetch();
3149                     if (!$tableCheck) {
3150                         devLog($db, 'error', 'createOrderFromPendingState', 'transactions table does not exist', ['user_id' => $dbUserId]);
3151                         return false;
3152                     }
3153                 } catch (Exception $e) {
3154                     devLog($db, 'error', 'createOrderFromPendingState', 'Error checking tables: ' . $e->getMessage(), ['user_id' => $dbUserId]);
3155                     return false;
3156                 }
3157                 
3158                 // Generate order number
3159                 $orderNumber = 'ORD' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
3160                 
3161                 devLog($db, 'debug', 'createOrderFromPendingState', 'Creating transaction', [
3162                     'order_number' => $orderNumber,
3163                     'user_id' => $dbUserId,
3164                     'total' => $total,
3165                     'items_count' => count($items)
3166                 ]);
3167                 
3168                 // Create transaction - use only basic columns that definitely exist
3169                 try {
3170                     $stmt = $db->prepare("INSERT INTO transactions 
3171                         (line_account_id, order_number, user_id, total_amount, grand_total, status, payment_status, note) 
3172                         VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?)");
3173                     $stmt->execute([
3174                         $lineAccountId,
3175                         $orderNumber,
3176                         $dbUserId,
3177                         $total,
3178                         $total,
3179                         'สร้างจากแชท - ลูกค้ายืนยัน'
3180                     ]);
3181                 } catch (PDOException $e) {
3182                     devLog($db, 'error', 'createOrderFromPendingState', 'Failed to insert transaction: ' . $e->getMessage(), [
3183                         'user_id' => $dbUserId,
3184                         'sql_error' => $e->getCode()
3185                     ]);
3186                     return false;
3187                 }
3188                 
3189                 $transactionId = $db->lastInsertId();
3190                 
3191                 devLog($db, 'debug', 'createOrderFromPendingState', 'Transaction created', [
3192                     'transaction_id' => $transactionId
3193                 ]);
3194                 
3195                 // Insert transaction items - check if table exists first
3196                 try {
3197                     $itemTableCheck = $db->query("SHOW TABLES LIKE 'transaction_items'")->fetch();
3198                     if ($itemTableCheck) {
3199                         foreach ($items as $item) {
3200                             $itemSubtotal = (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
3201                             $stmt = $db->prepare("INSERT INTO transaction_items 
3202                                 (transaction_id, product_id, product_name, product_price, quantity, subtotal) 
3203                                 VALUES (?, ?, ?, ?, ?, ?)");
3204                             $stmt->execute([
3205                                 $transactionId,
3206                                 $item['id'] ?? null,
3207                                 $item['name'] ?? 'Unknown',
3208                                 $item['price'] ?? 0,
3209                                 $item['qty'] ?? 1,
3210                                 $itemSubtotal
3211                             ]);
3212                         }
3213                     } else {
3214                         devLog($db, 'warning', 'createOrderFromPendingState', 'transaction_items table does not exist, skipping items insert', [
3215                             'transaction_id' => $transactionId
3216                         ]);
3217                     }
3218                 } catch (PDOException $e) {
3219                     devLog($db, 'error', 'createOrderFromPendingState', 'Failed to insert transaction items: ' . $e->getMessage(), [
3220                         'transaction_id' => $transactionId,
3221                         'sql_error' => $e->getCode()
3222                     ]);
3223                     // Continue anyway - transaction was created
3224                 }
3225                 
3226                 devLog($db, 'info', 'createOrderFromPendingState', 'Order created', [
3227                     'user_id' => $dbUserId,
3228                     'order_number' => $orderNumber,
3229                     'transaction_id' => $transactionId,
3230                     'total' => $total,
3231                     'items_count' => count($items)
3232                 ]);
3233                 
3234                 // Build confirmation message
3235                 $itemsList = '';
3236                 foreach ($items as $i => $item) {
3237                     $itemTotal = ($item['price'] ?? 0) * ($item['qty'] ?? 1);
3238                     $itemsList .= ($i + 1) . ". {$item['name']}\n   ฿" . number_format($item['price'] ?? 0) . " x {$item['qty']} = ฿" . number_format($itemTotal) . "\n";
3239                 }
3240                 
3241                 $confirmMessage = [
3242                     'type' => 'flex',
3243                     'altText' => "✅ สร้างออเดอร์สำเร็จ #{$orderNumber}",
3244                     'contents' => [
3245                         'type' => 'bubble',
3246                         'size' => 'mega',
3247                         'header' => [
3248                             'type' => 'box',
3249                             'layout' => 'vertical',
3250                             'backgroundColor' => '#10B981',
3251                             'paddingAll' => '15px',
3252                             'contents' => [
3253                                 ['type' => 'text', 'text' => '✅ สร้างออเดอร์สำเร็จ', 'color' => '#FFFFFF', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center']
3254                             ]
3255                         ],
3256                         'body' => [
3257                             'type' => 'box',
3258                             'layout' => 'vertical',
3259                             'paddingAll' => '15px',
3260                             'contents' => [
3261                                 ['type' => 'text', 'text' => "เลขที่: #{$orderNumber}", 'size' => 'md', 'weight' => 'bold', 'color' => '#10B981'],
3262                                 ['type' => 'separator', 'margin' => 'md'],
3263                                 ['type' => 'text', 'text' => '📦 รายการสินค้า', 'size' => 'sm', 'weight' => 'bold', 'margin' => 'md'],
3264                                 ['type' => 'text', 'text' => $itemsList, 'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm'],
3265                                 ['type' => 'separator', 'margin' => 'md'],
3266                                 ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
3267                                     ['type' => 'text', 'text' => '💰 รวมทั้งหมด', 'size' => 'md', 'weight' => 'bold'],
3268                                     ['type' => 'text', 'text' => '฿' . number_format($total), 'size' => 'lg', 'weight' => 'bold', 'color' => '#10B981', 'align' => 'end']
3269                                 ]],
3270                                 ['type' => 'text', 'text' => '📱 กรุณาชำระเงินและส่งสลิปมาค่ะ', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'margin' => 'lg']
3271                             ]
3272                         ]
3273                     ]
3274                 ];
3275                 
3276                 $line->replyMessage($replyToken, [$confirmMessage]);
3277                 saveOutgoingMessage($db, $dbUserId, json_encode($confirmMessage), 'system', 'flex');
3278                 
3279                 // Set user state to waiting for slip
3280                 setUserState($db, $dbUserId, 'waiting_slip', ['order_id' => $transactionId, 'order_number' => $orderNumber], 60);
3281                 
3282                 return true;
3283                 
3284             } catch (Exception $e) {
3285                 devLog($db, 'error', 'createOrderFromPendingState', 'Error: ' . $e->getMessage(), [
3286                     'user_id' => $dbUserId,
3287                     'trace' => $e->getTraceAsString()
3288                 ]);
3289                 
3290                 // Send error message
3291                 $errorMessage = [
3292                     'type' => 'text',
3293                     'text' => "❌ ขออภัยค่ะ เกิดข้อผิดพลาดในการสร้างออเดอร์\n\nกรุณาลองใหม่อีกครั้งหรือติดต่อเจ้าหน้าที่ค่ะ 🙏"
3294                 ];
3295                 $line->replyMessage($replyToken, [$errorMessage]);
3296                 
3297                 return false;
3298             }
3299         }
3300 
3301         /**
3302          * Handle slip command - เมื่อลูกค้าพิมพ์ "สลิป"
3303          */
3304         function handleSlipCommand($db, $line, $dbUserId, $replyToken) {
3305             devLog($db, 'debug', 'handleSlipCommand', 'Start', ['user_id' => $dbUserId]);
3306             
3307             // Check if user has pending order - ลองหาจาก transactions ก่อน แล้วค่อย orders
3308             $order = null;
3309             $orderTable = 'orders';
3310             $itemsTable = 'order_items';
3311             $itemsFk = 'order_id';
3312             
3313             // Try transactions first
3314             try {
3315                 $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND status IN ('pending', 'confirmed') AND payment_status = 'pending' ORDER BY created_at DESC LIMIT 1");
3316                 $stmt->execute([$dbUserId]);
3317                 $order = $stmt->fetch();
3318                 devLog($db, 'debug', 'handleSlipCommand', 'Transactions query', ['user_id' => $dbUserId, 'found' => $order ? 'yes' : 'no', 'order_id' => $order['id'] ?? null]);
3319                 if ($order) {
3320                     $orderTable = 'transactions';
3321                     $itemsTable = 'transaction_items';
3322                     $itemsFk = 'transaction_id';
3323                 }
3324             } catch (Exception $e) {
3325                 devLog($db, 'error', 'handleSlipCommand', 'Transactions error: ' . $e->getMessage(), ['user_id' => $dbUserId]);
3326             }
3327             
3328             // Fallback to orders
3329             if (!$order) {
3330                 try {
3331                     $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? AND status IN ('pending', 'confirmed') AND payment_status = 'pending' ORDER BY created_at DESC LIMIT 1");
3332                     $stmt->execute([$dbUserId]);
3333                     $order = $stmt->fetch();
3334                 } catch (Exception $e) {}
3335             }
3336             
3337             if (!$order) {
3338                 $line->replyMessage($replyToken, "❌ คุณยังไม่มีคำสั่งซื้อที่รอชำระเงิน\n\nพิมพ์ 'shop' เพื่อเริ่มช้อปปิ้ง");
3339                 return true;
3340             }
3341             
3342             // Set user state to waiting for slip
3343             $stateData = $orderTable === 'transactions' ? ['transaction_id' => $order['id']] : ['order_id' => $order['id']];
3344             setUserState($db, $dbUserId, 'waiting_slip', $stateData, 10);
3345             
3346             // Get payment info & order items
3347             $stmt = $db->query("SELECT * FROM shop_settings WHERE id = 1");
3348             $settings = $stmt->fetch();
3349             $bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
3350             
3351             $stmt = $db->prepare("SELECT * FROM {$itemsTable} WHERE {$itemsFk} = ?");
3352             $stmt->execute([$order['id']]);
3353             $items = $stmt->fetchAll();
3354             
3355             // Build items content
3356             $itemsContent = [];
3357             foreach ($items as $item) {
3358                 $itemsContent[] = [
3359                     'type' => 'box',
3360                     'layout' => 'horizontal',
3361                     'contents' => [
3362                         ['type' => 'text', 'text' => "{$item['product_name']}  x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
3363                         ['type' => 'text', 'text' => '฿' . number_format($item['subtotal']), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
3364                     ]
3365                 ];
3366             }
3367             
3368             // Build payment contents
3369             $paymentContents = [];
3370             if (!empty($settings['promptpay_number'])) {
3371                 $paymentContents[] = [
3372                     'type' => 'box',
3373                     'layout' => 'horizontal',
3374                     'contents' => [
3375                         ['type' => 'text', 'text' => '💚', 'size' => 'sm', 'flex' => 0],
3376                         ['type' => 'text', 'text' => 'พร้อมเพย์: ' . $settings['promptpay_number'], 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
3377                     ]
3378                 ];
3379             }
3380             foreach ($bankAccounts as $bank) {
3381                 $paymentContents[] = [
3382                     'type' => 'box',
3383                     'layout' => 'vertical',
3384                     'contents' => [
3385                         [
3386                             'type' => 'box',
3387                             'layout' => 'horizontal',
3388                             'contents' => [
3389                                 ['type' => 'text', 'text' => '🏦', 'size' => 'sm', 'flex' => 0],
3390                                 ['type' => 'text', 'text' => "{$bank['name']}: {$bank['account']}", 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
3391                             ]
3392                         ],
3393                         ['type' => 'text', 'text' => "   ชื่อ: {$bank['holder']}", 'size' => 'xs', 'color' => '#888888']
3394                     ]
3395                 ];
3396             }
3397             
3398             $orderNum = str_replace('ORD', '', $order['order_number']);
3399             
3400             // Build Flex Message
3401             $bubble = [
3402                 'type' => 'bubble',
3403                 'body' => [
3404                     'type' => 'box',
3405                     'layout' => 'vertical',
3406                     'contents' => [
3407                         ['type' => 'text', 'text' => "ออเดอร์ #{$orderNum}", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
3408                         [
3409                             'type' => 'box',
3410                             'layout' => 'horizontal',
3411                             'margin' => 'md',
3412                             'contents' => [
3413                                 ['type' => 'text', 'text' => '⏳ รอชำระเงิน', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold']
3414                             ]
3415                         ],
3416                         ['type' => 'separator', 'margin' => 'lg'],
3417                         ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
3418                         [
3419                             'type' => 'box',
3420                             'layout' => 'vertical',
3421                             'margin' => 'md',
3422                             'spacing' => 'sm',
3423                             'contents' => $itemsContent
3424                         ],
3425                         ['type' => 'separator', 'margin' => 'lg'],
3426                         [
3427                             'type' => 'box',
3428                             'layout' => 'horizontal',
3429                             'margin' => 'lg',
3430                             'contents' => [
3431                                 ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
3432                                 ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
3433                             ]
3434                         ],
3435                         ['type' => 'separator', 'margin' => 'lg'],
3436                         ['type' => 'text', 'text' => '📌 ช่องทางชำระเงิน:', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
3437                         [
3438                             'type' => 'box',
3439                             'layout' => 'vertical',
3440                             'margin' => 'md',
3441                             'spacing' => 'sm',
3442                             'contents' => $paymentContents
3443                         ],
3444                         ['type' => 'text', 'text' => '📸 กรุณาส่งรูปสลิปมาเลย', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold', 'margin' => 'lg', 'wrap' => true],
3445                         ['type' => 'text', 'text' => '(ภายใน 10 นาที)', 'size' => 'xs', 'color' => '#888888']
3446                     ]
3447                 ],
3448                 'footer' => [
3449                     'type' => 'box',
3450                     'layout' => 'vertical',
3451                     'spacing' => 'sm',
3452                     'contents' => [
3453                         ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '📞 ติดต่อเรา', 'uri' => 'tel:' . ($settings['contact_phone'] ?? '0000000000')], 'style' => 'link']
3454                     ]
3455                 ]
3456             ];
3457             
3458             $line->replyMessage($replyToken, [
3459                 ['type' => 'flex', 'altText' => "ออเดอร์ #{$orderNum} - รอชำระเงิน", 'contents' => $bubble]
3460             ]);
3461             return true;
3462         }
3463 
3464         /**
3465          * Handle payment slip for specific order
3466          */
3467         function handlePaymentSlipForOrder($db, $line, $dbUserId, $messageId, $replyToken, $orderId) {
3468             // Get order - ลองหาจากทั้ง orders และ transactions
3469             $order = null;
3470             $orderTable = 'orders';
3471             
3472             // ลองหาจาก orders ก่อน
3473             try {
3474                 $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
3475                 $stmt->execute([$orderId, $dbUserId]);
3476                 $order = $stmt->fetch(PDO::FETCH_ASSOC);
3477             } catch (Exception $e) {}
3478             
3479             // ถ้าไม่เจอ ลองหาจาก transactions
3480             if (!$order) {
3481                 try {
3482                     $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
3483                     $stmt->execute([$orderId, $dbUserId]);
3484                     $order = $stmt->fetch(PDO::FETCH_ASSOC);
3485                     if ($order) {
3486                         $orderTable = 'transactions';
3487                     }
3488                 } catch (Exception $e) {}
3489             }
3490             
3491             if (!$order) {
3492                 $line->replyMessage($replyToken, "❌ ไม่พบคำสั่งซื้อ กรุณาลองใหม่");
3493                 return true;
3494             }
3495             
3496             // Download image from LINE and save
3497             $imageData = $line->getMessageContent($messageId);
3498             if (!$imageData || strlen($imageData) < 100) {
3499                 $line->replyMessage($replyToken, "❌ ไม่สามารถรับรูปภาพได้ กรุณาส่งใหม่อีกครั้ง");
3500                 return true;
3501             }
3502             
3503             // Save image
3504             $uploadDir = __DIR__ . '/uploads/slips/';
3505             if (!is_dir($uploadDir)) {
3506                 if (!mkdir($uploadDir, 0755, true)) {
3507                     $line->replyMessage($replyToken, "❌ ระบบมีปัญหา ไม่สามารถบันทึกรูปได้ กรุณาติดต่อแอดมิน");
3508                     return true;
3509                 }
3510             }
3511             
3512             // Check if directory is writable
3513             if (!is_writable($uploadDir)) {
3514                 $line->replyMessage($replyToken, "❌ ระบบมีปัญหา (permission) กรุณาติดต่อแอดมิน");
3515                 return true;
3516             }
3517             
3518             $filename = 'slip_' . $order['order_number'] . '_' . time() . '.jpg';
3519             $filepath = $uploadDir . $filename;
3520             
3521             $bytesWritten = file_put_contents($filepath, $imageData);
3522             if ($bytesWritten === false || $bytesWritten < 100) {
3523                 $line->replyMessage($replyToken, "❌ ไม่สามารถบันทึกรูปได้ กรุณาส่งใหม่");
3524                 return true;
3525             }
3526             
3527             // Get base URL from config or construct it
3528             $baseUrl = defined('BASE_URL') ? BASE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
3529             $imageUrl = rtrim($baseUrl, '/') . '/uploads/slips/' . $filename;
3530             
3531             // Save payment slip record - use transaction_id (unified with LIFF)
3532             try {
3533                 $stmt = $db->prepare("INSERT INTO payment_slips (transaction_id, user_id, image_url, status) VALUES (?, ?, ?, 'pending')");
3534                 $stmt->execute([$order['id'], $dbUserId, $imageUrl]);
3535             } catch (Exception $e) {
3536                 devLog($db, 'error', 'handlePaymentSlip', 'Cannot save slip: ' . $e->getMessage());
3537             }
3538             
3539             // Update order status to 'paid' (pending admin verification)
3540             try {
3541                 $stmt = $db->prepare("UPDATE {$orderTable} SET status = 'paid', updated_at = NOW() WHERE id = ?");
3542                 $stmt->execute([$order['id']]);
3543                 devLog($db, 'info', 'handlePaymentSlip', 'Order status updated to paid', ['order_id' => $order['id'], 'table' => $orderTable]);
3544             } catch (Exception $e) {
3545                 devLog($db, 'error', 'handlePaymentSlip', 'Cannot update order status: ' . $e->getMessage());
3546             }
3547             
3548             // Reply to customer with beautiful Flex Message
3549             $orderNum = str_replace(['ORD', 'TXN'], '', $order['order_number']);
3550             $slipBubble = FlexTemplates::slipReceived($orderNum, $order['grand_total']);
3551             $slipMessage = FlexTemplates::toMessage($slipBubble, "ได้รับสลิปออเดอร์ #{$orderNum} แล้ว");
3552             $slipMessage = FlexTemplates::withQuickReply($slipMessage, [
3553                 ['label' => '📦 เช็คสถานะ', 'text' => 'orders'],
3554                 ['label' => '🛒 ช้อปต่อ', 'text' => 'shop']
3555             ]);
3556             $line->replyMessage($replyToken, [$slipMessage]);
3557             
3558             // Notify admin via Telegram
3559             notifyAdminNewSlip($db, $line, $order, $dbUserId, $imageData, $baseUrl);
3560             
3561             return true;
3562         }
3563 
3564         /**
3565          * Notify admin about new payment slip
3566          */
3567         function notifyAdminNewSlip($db, $line, $order, $dbUserId, $imageData, $baseUrl) {
3568             $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
3569             $stmt->execute();
3570             $telegramSettings = $stmt->fetch();
3571             
3572             if (!$telegramSettings || !$telegramSettings['is_enabled']) return;
3573             
3574             $telegram = new TelegramAPI();
3575             
3576             $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
3577             $stmt->execute([$dbUserId]);
3578             $user = $stmt->fetch();
3579             
3580             $caption = "💳 <b>สลิปการชำระเงิน!</b>\n\n";
3581             $caption .= "📋 ออเดอร์: #{$order['order_number']}\n";
3582             $caption .= "👤 ลูกค้า: {$user['display_name']}\n";
3583             $caption .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
3584             $caption .= "📅 เวลา: " . date('d/m/Y H:i') . "\n\n";
3585             $caption .= "🔗 <a href=\"{$baseUrl}/shop/order-detail.php?id={$order['id']}\">ตรวจสอบ</a>";
3586             
3587             $telegram->sendPhoto($imageData, $caption, $dbUserId);
3588         }
3589 
3590         /**
3591          * Handle payment slip - ตรวจสอบและบันทึกสลิปการชำระเงิน (legacy - use transactions)
3592          */
3593         function handlePaymentSlip($db, $line, $dbUserId, $messageId, $replyToken) {
3594             // Check if user has pending/confirmed order waiting for payment (use transactions table)
3595             $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? AND status IN ('pending', 'confirmed') AND payment_status = 'pending' ORDER BY created_at DESC LIMIT 1");
3596             $stmt->execute([$dbUserId]);
3597             $order = $stmt->fetch();
3598             
3599             if (!$order) {
3600                 return false; // No pending order, not a payment slip
3601             }
3602             
3603             // Download image from LINE and save
3604             $imageData = $line->getMessageContent($messageId);
3605             if (!$imageData) {
3606                 return false;
3607             }
3608             
3609             // Save image to uploads folder
3610             $uploadDir = __DIR__ . '/uploads/slips/';
3611             if (!is_dir($uploadDir)) {
3612                 mkdir($uploadDir, 0755, true);
3613             }
3614             
3615             $filename = 'slip_' . $order['order_number'] . '_' . time() . '.jpg';
3616             $filepath = $uploadDir . $filename;
3617             file_put_contents($filepath, $imageData);
3618             
3619             // Get base URL for image - use BASE_URL from config
3620             $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
3621             $imageUrl = $baseUrl . '/uploads/slips/' . $filename;
3622             
3623             // Save payment slip record (use transaction_id - unified with LIFF)
3624             $stmt = $db->prepare("INSERT INTO payment_slips (transaction_id, user_id, image_url, status) VALUES (?, ?, ?, 'pending')");
3625             $stmt->execute([$order['id'], $dbUserId, $imageUrl]);
3626             
3627             // Reply to customer
3628             $line->replyMessage($replyToken, "✅ ได้รับหลักฐานการชำระเงินแล้ว!\n\n📋 คำสั่งซื้อ: #{$order['order_number']}\n💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n\n⏳ กรุณารอการตรวจสอบจากทางร้าน\nจะแจ้งผลให้ทราบเร็วๆ นี้");
3629             
3630             // Notify admin via Telegram
3631             $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
3632             $stmt->execute();
3633             $telegramSettings = $stmt->fetch();
3634             
3635             if ($telegramSettings && $telegramSettings['is_enabled']) {
3636                 $telegram = new TelegramAPI();
3637                 
3638                 // Get customer name
3639                 $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
3640                 $stmt->execute([$dbUserId]);
3641                 $user = $stmt->fetch();
3642                 
3643                 $caption = "💳 <b>สลิปการชำระเงิน!</b>\n\n";
3644                 $caption .= "📋 คำสั่งซื้อ: #{$order['order_number']}\n";
3645                 $caption .= "👤 ลูกค้า: {$user['display_name']}\n";
3646                 $caption .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
3647                 $caption .= "📅 เวลา: " . date('Y-m-d H:i:s') . "\n\n";
3648                 $caption .= "🔗 <a href=\"{$baseUrl}/shop/order-detail.php?id={$order['id']}\">ดูรายละเอียด</a>";
3649                 
3650                 // Send slip image to Telegram
3651                 $telegram->sendPhoto($imageData, $caption, $dbUserId);
3652             }
3653             
3654             return true; // Slip handled
3655         }
3656 
3657         /**
3658          * Send Telegram notification with media support
3659          */
3660         function sendTelegramNotificationWithMedia($db, $line, $displayName, $messageType, $messageContent, $messageId, $dbUserId, $messageData) {
3661             $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
3662             $stmt->execute();
3663             $settings = $stmt->fetch();
3664 
3665             if (!$settings || !$settings['is_enabled'] || !$settings['notify_new_message']) return;
3666 
3667             $telegram = new TelegramAPI();
3668 
3669             // For text messages, use normal notification
3670             if ($messageType === 'text') {
3671                 $telegram->notifyNewMessage($displayName, $messageContent, '', $dbUserId);
3672                 return;
3673             }
3674 
3675             // For media messages
3676             $caption = "💬 <b>ข้อความใหม่!</b>\n\n";
3677             $caption .= "👤 จาก: {$displayName}\n";
3678             $caption .= "📅 เวลา: " . date('Y-m-d H:i:s') . "\n";
3679             $caption .= "\n💡 <i>ตอบกลับ:</i> <code>/r {$dbUserId} ข้อความ</code>";
3680 
3681             if ($messageType === 'image') {
3682                 // Get image content from LINE
3683                 $imageData = $line->getMessageContent($messageId);
3684                 if ($imageData) {
3685                     $telegram->sendPhoto($imageData, $caption, $dbUserId);
3686                 } else {
3687                     $telegram->notifyNewMessage($displayName, "[รูปภาพ] ไม่สามารถโหลดได้", '', $dbUserId);
3688                 }
3689             } elseif ($messageType === 'video') {
3690                 $telegram->notifyNewMessage($displayName, "[วิดีโอ] ID: {$messageId}", '', $dbUserId);
3691             } elseif ($messageType === 'audio') {
3692                 $telegram->notifyNewMessage($displayName, "[เสียง] ID: {$messageId}", '', $dbUserId);
3693             } elseif ($messageType === 'sticker') {
3694                 $stickerId = $messageData['stickerId'] ?? '';
3695                 $packageId = $messageData['packageId'] ?? '';
3696                 // LINE sticker URL
3697                 $stickerUrl = "https://stickershop.line-scdn.net/stickershop/v1/sticker/{$stickerId}/iPhone/sticker.png";
3698                 $telegram->sendPhotoUrl($stickerUrl, "🎨 <b>สติกเกอร์</b>\n\n👤 จาก: {$displayName}\n\n💡 <code>/r {$dbUserId} ข้อความ</code>", $dbUserId);
3699             } elseif ($messageType === 'location') {
3700                 $lat = $messageData['latitude'] ?? 0;
3701                 $lng = $messageData['longitude'] ?? 0;
3702                 $address = $messageData['address'] ?? '';
3703                 $telegram->sendLocation($lat, $lng, "📍 <b>ตำแหน่ง</b>\n\n👤 จาก: {$displayName}\n📍 {$address}\n\n💡 <code>/r {$dbUserId} ข้อความ</code>", $dbUserId);
3704             } else {
3705                 $telegram->notifyNewMessage($displayName, "[{$messageType}]", '', $dbUserId);
3706             }
3707         }
3708 
3709         /**
3710          * Ensure group exists in database - สร้างกลุ่มอัตโนมัติถ้ายังไม่มี
3711          * ใช้เมื่อได้รับ event จากกลุ่มที่บอทอยู่แล้วแต่ยังไม่มีในระบบ
3712          */
3713         function ensureGroupExists($db, $line, $lineAccountId, $groupId, $sourceType = 'group') {
3714             if (!$lineAccountId || !$groupId) return;
3715             
3716             try {
3717                 // ตรวจสอบว่ามีกลุ่มนี้ในระบบหรือยัง
3718                 $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
3719                 $stmt->execute([$lineAccountId, $groupId]);
3720                 
3721                 if ($stmt->fetch()) {
3722                     return; // มีอยู่แล้ว ไม่ต้องทำอะไร
3723                 }
3724                 
3725                 // ยังไม่มี - ดึงข้อมูลกลุ่มจาก LINE API
3726                 $groupInfo = [];
3727                 try {
3728                     if ($sourceType === 'group') {
3729                         $groupInfo = $line->getGroupSummary($groupId);
3730                     }
3731                 } catch (Exception $e) {
3732                     // API อาจ fail ถ้าบอทไม่มีสิทธิ์
3733                 }
3734                 
3735                 $groupName = $groupInfo['groupName'] ?? 'Unknown Group';
3736                 $pictureUrl = $groupInfo['pictureUrl'] ?? null;
3737                 $memberCount = $groupInfo['memberCount'] ?? 0;
3738                 
3739                 // บันทึกกลุ่มใหม่
3740                 $stmt = $db->prepare("
3741                     INSERT INTO line_groups (line_account_id, group_id, group_type, group_name, picture_url, member_count, is_active, joined_at)
3742                     VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
3743                     ON DUPLICATE KEY UPDATE 
3744                         is_active = 1,
3745                         updated_at = NOW()
3746                 ");
3747                 $stmt->execute([$lineAccountId, $groupId, $sourceType, $groupName, $pictureUrl, $memberCount]);
3748                 
3749                 // Log
3750                 devLog($db, 'info', 'webhook', 'Auto-created group from event', [
3751                     'group_id' => $groupId,
3752                     'group_name' => $groupName,
3753                     'line_account_id' => $lineAccountId
3754                 ]);
3755                 
3756             } catch (Exception $e) {
3757                 // Ignore errors - ไม่ให้กระทบ flow หลัก
3758             }
3759         }
3760 
3761         /**
3762          * Handle bot join group/room event
3763          */
3764         function handleJoinGroup($event, $db, $line, $lineAccountId) {
3765             if (!$lineAccountId) return;
3766             
3767             $sourceType = $event['source']['type'] ?? 'group';
3768             $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
3769             
3770             if (!$groupId) return;
3771             
3772             try {
3773                 // Get group info from LINE API
3774                 $groupInfo = [];
3775                 if ($sourceType === 'group') {
3776                     $groupInfo = $line->getGroupSummary($groupId);
3777                 }
3778                 
3779                 $groupName = $groupInfo['groupName'] ?? 'Unknown Group';
3780                 $pictureUrl = $groupInfo['pictureUrl'] ?? null;
3781                 $memberCount = $groupInfo['memberCount'] ?? 0;
3782                 
3783                 // Save to database
3784                 $stmt = $db->prepare("
3785                     INSERT INTO line_groups (line_account_id, group_id, group_type, group_name, picture_url, member_count, is_active, joined_at)
3786                     VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
3787                     ON DUPLICATE KEY UPDATE 
3788                         group_name = VALUES(group_name),
3789                         picture_url = VALUES(picture_url),
3790                         member_count = VALUES(member_count),
3791                         is_active = 1,
3792                         joined_at = NOW(),
3793                         left_at = NULL,
3794                         updated_at = NOW()
3795                 ");
3796                 $stmt->execute([$lineAccountId, $groupId, $sourceType, $groupName, $pictureUrl, $memberCount]);
3797                 
3798                 // Log event (skip saveAccountEvent - no line_user_id for join events)
3799                 
3800                 // ไม่ส่งข้อความเข้ากลุ่มเพื่อประหยัด quota
3801                 // (ถ้าต้องการส่ง สามารถเปิด comment ด้านล่างได้)
3802                 // $botName = getAccountName($db, $lineAccountId) ?: 'Bot';
3803                 // $welcomeBubble = FlexTemplates::groupWelcome($groupName, $botName);
3804                 // $welcomeMessage = FlexTemplates::toMessage($welcomeBubble, "สวัสดีจาก {$botName}!");
3805                 // $line->pushMessage($groupId, [$welcomeMessage]);
3806                 
3807                 // Notify via Telegram
3808                 notifyGroupEvent($db, 'join', $groupName, $lineAccountId);
3809                 
3810             } catch (Exception $e) {
3811                 error_log("handleJoinGroup error: " . $e->getMessage());
3812             }
3813         }
3814         
3815         /**
3816          * Handle bot leave group/room event
3817          */
3818         function handleLeaveGroup($event, $db, $lineAccountId) {
3819             if (!$lineAccountId) return;
3820             
3821             $groupId = $event['source']['groupId'] ?? $event['source']['roomId'] ?? null;
3822             if (!$groupId) return;
3823             
3824             try {
3825                 // Get group name before updating
3826                 $stmt = $db->prepare("SELECT group_name FROM line_groups WHERE line_account_id = ? AND group_id = ?");
3827                 $stmt->execute([$lineAccountId, $groupId]);
3828                 $group = $stmt->fetch();
3829                 $groupName = $group['group_name'] ?? 'Unknown Group';
3830                 
3831                 // Update database
3832                 $stmt = $db->prepare("
3833                     UPDATE line_groups 
3834                     SET is_active = 0, left_at = NOW(), updated_at = NOW()
3835                     WHERE line_account_id = ? AND group_id = ?
3836                 ");
3837                 $stmt->execute([$lineAccountId, $groupId]);
3838                 
3839                 // Log event (skip saveAccountEvent - no line_user_id for leave events)
3840                 
3841                 // Notify via Telegram
3842                 notifyGroupEvent($db, 'leave', $groupName, $lineAccountId);
3843                 
3844             } catch (Exception $e) {
3845                 error_log("handleLeaveGroup error: " . $e->getMessage());
3846             }
3847         }
3848         
3849         /**
3850          * Handle member joined group event
3851          */
3852         function handleMemberJoined($event, $groupId, $db, $line, $lineAccountId) {
3853             try {
3854                 // Get group DB ID
3855                 $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
3856                 $stmt->execute([$lineAccountId, $groupId]);
3857                 $dbGroupId = $stmt->fetchColumn();
3858                 
3859                 if (!$dbGroupId) return;
3860                 
3861                 $members = $event['joined']['members'] ?? [];
3862                 foreach ($members as $member) {
3863                     $userId = $member['userId'] ?? null;
3864                     if (!$userId) continue;
3865                     
3866                     // Get member profile
3867                     $profile = $line->getGroupMemberProfile($groupId, $userId);
3868                     $displayName = $profile['displayName'] ?? 'Unknown';
3869                     $pictureUrl = $profile['pictureUrl'] ?? null;
3870                     
3871                     // Save member
3872                     $stmt = $db->prepare("
3873                         INSERT INTO line_group_members (group_id, line_user_id, display_name, picture_url, is_active, joined_at)
3874                         VALUES (?, ?, ?, ?, 1, NOW())
3875                         ON DUPLICATE KEY UPDATE 
3876                             display_name = VALUES(display_name),
3877                             picture_url = VALUES(picture_url),
3878                             is_active = 1,
3879                             joined_at = NOW(),
3880                             left_at = NULL,
3881                             updated_at = NOW()
3882                     ");
3883                     $stmt->execute([$dbGroupId, $userId, $displayName, $pictureUrl]);
3884                 }
3885                 
3886                 // Update member count
3887                 $stmt = $db->prepare("UPDATE line_groups SET member_count = member_count + ? WHERE id = ?");
3888                 $stmt->execute([count($members), $dbGroupId]);
3889                 
3890                 // ไม่ส่งข้อความต้อนรับสมาชิกใหม่เพื่อประหยัด quota
3891                 // (ถ้าต้องการส่ง สามารถเปิด comment ด้านล่างได้)
3892                 /*
3893                 if (count($members) > 0) {
3894                     $names = [];
3895                     foreach ($members as $member) {
3896                         $userId = $member['userId'] ?? null;
3897                         if ($userId) {
3898                             $profile = $line->getGroupMemberProfile($groupId, $userId);
3899                             $names[] = $profile['displayName'] ?? 'สมาชิกใหม่';
3900                         }
3901                     }
3902                     $nameList = implode(', ', array_slice($names, 0, 3));
3903                     if (count($names) > 3) $nameList .= ' และอีก ' . (count($names) - 3) . ' คน';
3904                     
3905                     $welcomeText = "🎉 ยินดีต้อนรับ {$nameList} เข้าสู่กลุ่ม!\n\n💡 พิมพ์ 'menu' เพื่อดูคำสั่งที่ใช้ได้";
3906                     $line->pushMessage($groupId, $welcomeText);
3907                 }
3908                 */
3909                 
3910             } catch (Exception $e) {
3911                 error_log("handleMemberJoined error: " . $e->getMessage());
3912             }
3913         }
3914         
3915         /**
3916          * Handle member left group event
3917          */
3918         function handleMemberLeft($event, $groupId, $db, $lineAccountId) {
3919             try {
3920                 // Get group DB ID
3921                 $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
3922                 $stmt->execute([$lineAccountId, $groupId]);
3923                 $dbGroupId = $stmt->fetchColumn();
3924                 
3925                 if (!$dbGroupId) return;
3926                 
3927                 $members = $event['left']['members'] ?? [];
3928                 foreach ($members as $member) {
3929                     $userId = $member['userId'] ?? null;
3930                     if (!$userId) continue;
3931                     
3932                     // Update member
3933                     $stmt = $db->prepare("
3934                         UPDATE line_group_members 
3935                         SET is_active = 0, left_at = NOW(), updated_at = NOW()
3936                         WHERE group_id = ? AND line_user_id = ?
3937                     ");
3938                     $stmt->execute([$dbGroupId, $userId]);
3939                 }
3940                 
3941                 // Update member count
3942                 $stmt = $db->prepare("UPDATE line_groups SET member_count = GREATEST(0, member_count - ?) WHERE id = ?");
3943                 $stmt->execute([count($members), $dbGroupId]);
3944                 
3945             } catch (Exception $e) {
3946                 error_log("handleMemberLeft error: " . $e->getMessage());
3947             }
3948         }
3949         
3950         /**
3951          * Save group message
3952          */
3953         function saveGroupMessage($db, $lineAccountId, $groupId, $userId, $event) {
3954             try {
3955                 // Get group DB ID
3956                 $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
3957                 $stmt->execute([$lineAccountId, $groupId]);
3958                 $dbGroupId = $stmt->fetchColumn();
3959                 
3960                 if (!$dbGroupId) return;
3961                 
3962                 $messageType = $event['message']['type'] ?? 'text';
3963                 $content = $event['message']['text'] ?? "[{$messageType}]";
3964                 $messageId = $event['message']['id'] ?? null;
3965                 
3966                 // Save message
3967                 $stmt = $db->prepare("
3968                     INSERT INTO line_group_messages (group_id, line_user_id, message_type, content, message_id)
3969                     VALUES (?, ?, ?, ?, ?)
3970                 ");
3971                 $stmt->execute([$dbGroupId, $userId, $messageType, $content, $messageId]);
3972                 
3973                 // Update group stats
3974                 $stmt = $db->prepare("UPDATE line_groups SET total_messages = total_messages + 1, last_activity_at = NOW() WHERE id = ?");
3975                 $stmt->execute([$dbGroupId]);
3976                 
3977                 // Update member stats
3978                 $stmt = $db->prepare("
3979                     UPDATE line_group_members 
3980                     SET total_messages = total_messages + 1, last_message_at = NOW()
3981                     WHERE group_id = ? AND line_user_id = ?
3982                 ");
3983                 $stmt->execute([$dbGroupId, $userId]);
3984                 
3985             } catch (Exception $e) {
3986                 error_log("saveGroupMessage error: " . $e->getMessage());
3987             }
3988         }
3989         
3990         /**
3991          * Update group stats - อัพเดทสถิติกลุ่ม
3992          */
3993         function updateGroupStats($db, $lineAccountId, $groupId, $eventType) {
3994             try {
3995                 // Get group DB ID
3996                 $stmt = $db->prepare("SELECT id FROM line_groups WHERE line_account_id = ? AND group_id = ?");
3997                 $stmt->execute([$lineAccountId, $groupId]);
3998                 $dbGroupId = $stmt->fetchColumn();
3999                 
4000                 if (!$dbGroupId) return;
4001                 
4002                 // Update based on event type
4003                 if ($eventType === 'message') {
4004                     $stmt = $db->prepare("UPDATE line_groups SET total_messages = total_messages + 1, last_activity_at = NOW(), updated_at = NOW() WHERE id = ?");
4005                     $stmt->execute([$dbGroupId]);
4006                 } else {
4007                     // Update last activity for other events
4008                     $stmt = $db->prepare("UPDATE line_groups SET last_activity_at = NOW(), updated_at = NOW() WHERE id = ?");
4009                     $stmt->execute([$dbGroupId]);
4010                 }
4011             } catch (Exception $e) {
4012                 error_log("updateGroupStats error: " . $e->getMessage());
4013             }
4014         }
4015         
4016         /**
4017          * Notify group event via Telegram
4018          */
4019         function notifyGroupEvent($db, $type, $groupName, $lineAccountId) {
4020             try {
4021                 $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
4022                 $stmt->execute();
4023                 $settings = $stmt->fetch();
4024                 
4025                 if (!$settings || !$settings['is_enabled']) return;
4026                 
4027                 $telegram = new TelegramAPI();
4028                 $accountName = getAccountName($db, $lineAccountId);
4029                 $botInfo = $accountName ? " [บอท: {$accountName}]" : "";
4030                 
4031                 if ($type === 'join') {
4032                     $message = "🎉 <b>บอทถูกเชิญเข้ากลุ่ม!</b>\n\n";
4033                     $message .= "👥 กลุ่ม: {$groupName}\n";
4034                     $message .= "🤖 {$botInfo}\n";
4035                     $message .= "📅 เวลา: " . date('d/m/Y H:i:s');
4036                 } else {
4037                     $message = "👋 <b>บอทออกจากกลุ่ม</b>\n\n";
4038                     $message .= "👥 กลุ่ม: {$groupName}\n";
4039                     $message .= "🤖 {$botInfo}\n";
4040                     $message .= "📅 เวลา: " . date('d/m/Y H:i:s');
4041                 }
4042                 
4043                 $telegram->sendMessage($message);
4044                 
4045             } catch (Exception $e) {
4046                 error_log("notifyGroupEvent error: " . $e->getMessage());
4047             }
4048         }
4049 
4050         // ==================== AI Pause/Resume Functions ====================
4051         
4052         /**
4053          * ตรวจสอบว่า AI ถูก pause สำหรับ user นี้หรือไม่
4054          */
4055         function isAIPaused($db, $userId) {
4056             try {
4057                 $stmt = $db->prepare("SELECT pause_until FROM ai_user_pause WHERE user_id = ? AND pause_until > NOW()");
4058                 $stmt->execute([$userId]);
4059                 return $stmt->fetch() !== false;
4060             } catch (Exception $e) {
4061                 // Table might not exist - create it
4062                 try {
4063                     $db->exec("
4064                         CREATE TABLE IF NOT EXISTS ai_user_pause (
4065                             id INT AUTO_INCREMENT PRIMARY KEY,
4066                             user_id INT NOT NULL,
4067                             pause_until DATETIME NOT NULL,
4068                             reason VARCHAR(255) DEFAULT 'human_request',
4069                             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
4070                             UNIQUE KEY unique_user (user_id),
4071                             INDEX idx_pause_until (pause_until)
4072                         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
4073                     ");
4074                 } catch (Exception $e2) {}
4075                 return false;
4076             }
4077         }
4078         
4079         /**
4080          * Pause AI สำหรับ user (หน่วยเป็นนาที)
4081          */
4082         function pauseAI($db, $userId, $minutes = 20) {
4083             try {
4084                 // Create table if not exists
4085                 $db->exec("
4086                     CREATE TABLE IF NOT EXISTS ai_user_pause (
4087                         id INT AUTO_INCREMENT PRIMARY KEY,
4088                         user_id INT NOT NULL,
4089                         pause_until DATETIME NOT NULL,
4090                         reason VARCHAR(255) DEFAULT 'human_request',
4091                         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
4092                         UNIQUE KEY unique_user (user_id),
4093                         INDEX idx_pause_until (pause_until)
4094                     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
4095                 ");
4096                 
4097                 $pauseUntil = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
4098                 
4099                 $stmt = $db->prepare("
4100                     INSERT INTO ai_user_pause (user_id, pause_until, reason) VALUES (?, ?, 'human_request')
4101                     ON DUPLICATE KEY UPDATE pause_until = ?, reason = 'human_request'
4102                 ");
4103                 $stmt->execute([$userId, $pauseUntil, $pauseUntil]);
4104                 
4105                 return true;
4106             } catch (Exception $e) {
4107                 error_log("pauseAI error: " . $e->getMessage());
4108                 return false;
4109             }
4110         }
4111         
4112         /**
4113          * Resume AI สำหรับ user (ยกเลิก pause)
4114          */
4115         function resumeAI($db, $userId) {
4116             try {
4117                 $stmt = $db->prepare("DELETE FROM ai_user_pause WHERE user_id = ?");
4118                 $stmt->execute([$userId]);
4119                 return true;
4120             } catch (Exception $e) {
4121                 return false;
4122             }
4123         }
4124         
4125         // ==================== AI Mode Functions ====================
4126         
4127         /**
4128          * ดึง AI mode ปัจจุบันของ user
4129          */
4130         function getUserAIMode($db, $userId) {
4131             try {
4132                 $stmt = $db->prepare("SELECT ai_mode FROM ai_user_mode WHERE user_id = ? AND expires_at > NOW()");
4133                 $stmt->execute([$userId]);
4134                 $row = $stmt->fetch(PDO::FETCH_ASSOC);
4135                 return $row ? $row['ai_mode'] : null;
4136             } catch (Exception $e) {
4137                 // Table might not exist - create it
4138                 try {
4139                     $db->exec("
4140                         CREATE TABLE IF NOT EXISTS ai_user_mode (
4141                             id INT AUTO_INCREMENT PRIMARY KEY,
4142                             user_id INT NOT NULL,
4143                             ai_mode VARCHAR(50) NOT NULL,
4144                             expires_at DATETIME NOT NULL,
4145                             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
4146                             UNIQUE KEY unique_user (user_id),
4147                             INDEX idx_expires (expires_at)
4148                         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
4149                     ");
4150                 } catch (Exception $e2) {}
4151                 return null;
4152             }
4153         }
4154         
4155         /**
4156          * ตั้ง AI mode สำหรับ user (หมดอายุใน 10 นาที)
4157          */
4158         function setUserAIMode($db, $userId, $mode, $minutes = 10) {
4159             try {
4160                 // Create table if not exists
4161                 $db->exec("
4162                     CREATE TABLE IF NOT EXISTS ai_user_mode (
4163                         id INT AUTO_INCREMENT PRIMARY KEY,
4164                         user_id INT NOT NULL,
4165                         ai_mode VARCHAR(50) NOT NULL,
4166                         expires_at DATETIME NOT NULL,
4167                         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
4168                         UNIQUE KEY unique_user (user_id),
4169                         INDEX idx_expires (expires_at)
4170                     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
4171                 ");
4172                 
4173                 $expiresAt = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
4174                 
4175                 $stmt = $db->prepare("
4176                     INSERT INTO ai_user_mode (user_id, ai_mode, expires_at) VALUES (?, ?, ?)
4177                     ON DUPLICATE KEY UPDATE ai_mode = ?, expires_at = ?
4178                 ");
4179                 $stmt->execute([$userId, $mode, $expiresAt, $mode, $expiresAt]);
4180                 
4181                 return true;
4182             } catch (Exception $e) {
4183                 error_log("setUserAIMode error: " . $e->getMessage());
4184                 return false;
4185             }
4186         }
4187         
4188         /**
4189          * ลบ AI mode ของ user (ออกจากโหมด)
4190          */
4191         function clearUserAIMode($db, $userId) {
4192             try {
4193                 $stmt = $db->prepare("DELETE FROM ai_user_mode WHERE user_id = ?");
4194                 $stmt->execute([$userId]);
4195                 return true;
4196             } catch (Exception $e) {
4197                 return false;
4198             }
4199         }
4200         
4201         /**
4202          * แจ้งเตือนเภสัชกรเมื่อลูกค้าขอคุยกับคนจริง
4203          */
4204         function notifyPharmacistForHumanRequest($db, $userId, $lineAccountId, $message) {
4205             try {
4206                 // Get user info
4207                 $stmt = $db->prepare("SELECT display_name, line_user_id FROM users WHERE id = ?");
4208                 $stmt->execute([$userId]);
4209                 $user = $stmt->fetch(PDO::FETCH_ASSOC);
4210                 
4211                 $displayName = $user['display_name'] ?? 'Unknown';
4212                 $lineUserId = $user['line_user_id'] ?? '';
4213                 
4214                 // 1. บันทึกลง pharmacist_queue (ถ้ามี table)
4215                 try {
4216                     $stmt = $db->prepare("
4217                         INSERT INTO pharmacist_queue (user_id, line_account_id, request_type, message, status, created_at)
4218                         VALUES (?, ?, 'human_request', ?, 'pending', NOW())
4219                     ");
4220                     $stmt->execute([$userId, $lineAccountId, $message]);
4221                 } catch (Exception $e) {
4222                     // Table might not exist
4223                 }
4224                 
4225                 // 2. แจ้งเตือนผ่าน Telegram
4226                 $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
4227                 $stmt->execute();
4228                 $telegramSettings = $stmt->fetch();
4229                 
4230                 if ($telegramSettings && $telegramSettings['is_enabled']) {
4231                     $telegram = new TelegramAPI();
4232                     $accountName = getAccountName($db, $lineAccountId);
4233                     
4234                     $text = "🚨 <b>ลูกค้าขอคุยกับเภสัชกรจริง!</b>\n\n";
4235                     $text .= "👤 ลูกค้า: {$displayName}\n";
4236                     $text .= "💬 ข้อความ: {$message}\n";
4237                     if ($accountName) $text .= "🤖 บอท: {$accountName}\n";
4238                     $text .= "📅 เวลา: " . date('d/m/Y H:i:s') . "\n\n";
4239                     $text .= "⏰ บอทจะหยุดตอบ 20 นาที\n";
4240                     $text .= "💡 ตอบกลับ: <code>/r {$userId} ข้อความ</code>";
4241                     
4242                     $telegram->sendMessage($text);
4243                 }
4244                 
4245                 // 3. Log event
4246                 devLog($db, 'info', 'human_request', 'Customer requested human pharmacist', [
4247                     'user_id' => $userId,
4248                     'display_name' => $displayName,
4249                     'message' => $message,
4250                     'line_account_id' => $lineAccountId
4251                 ], $lineUserId);
4252                 
4253             } catch (Exception $e) {
4254                 error_log("notifyPharmacistForHumanRequest error: " . $e->getMessage());
4255             }
4256         }