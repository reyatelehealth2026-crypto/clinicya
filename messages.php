<?php
/**
 * Messages Inbox - Pro Version with Customer Panel
 * - Collapsible sidebar
 * - Customer details panel
 * - Notes feature
 */
// Load config - support both config.php and confFig.php
if (file_exists(__DIR__ . '/config/config.php')) {
    require_once 'config/config.php';
} elseif (file_exists(__DIR__ . '/config/confFig.php')) {
    require_once 'config/confFig.php';
}
require_once 'config/database.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();

// Auto-create tables - ใช้ user_tags และ user_tag_assignments ตาม user-tags.php
try {
    // ตาราง user_tags สำหรับเก็บ tags (ใช้ร่วมกับ user-tags.php)
    $stmt = $db->query("SHOW TABLES LIKE 'user_tags'");
    if ($stmt->rowCount() == 0) {
        $db->exec("CREATE TABLE IF NOT EXISTS `user_tags` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `line_account_id` INT DEFAULT NULL,
            `name` varchar(100) NOT NULL,
            `color` varchar(7) DEFAULT '#3B82F6',
            `description` TEXT,
            `auto_assign_rules` JSON DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX idx_line_account (line_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // ตาราง user_tag_assignments สำหรับ assign tags ให้ users
    $stmt = $db->query("SHOW TABLES LIKE 'user_tag_assignments'");
    if ($stmt->rowCount() == 0) {
        $db->exec("CREATE TABLE IF NOT EXISTS `user_tag_assignments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `tag_id` int(11) NOT NULL,
            `assigned_by` VARCHAR(50) DEFAULT 'manual',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user_tag` (`user_id`,`tag_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Notes table
    $stmt = $db->query("SHOW TABLES LIKE 'user_notes'");
    if ($stmt->rowCount() == 0) {
        $db->exec("CREATE TABLE IF NOT EXISTS `user_notes` (`id` int(11) NOT NULL AUTO_INCREMENT, `user_id` int(11) NOT NULL, `note` text, `created_by` int(11), `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), KEY `user_id` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
    // Dispensing records table
    $stmt = $db->query("SHOW TABLES LIKE 'dispensing_records'");
    if ($stmt->rowCount() == 0) {
        $db->exec("CREATE TABLE IF NOT EXISTS `dispensing_records` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `line_account_id` INT DEFAULT NULL,
            `user_id` int(11) NOT NULL,
            `pharmacist_id` int(11) DEFAULT NULL,
            `order_number` varchar(50) NOT NULL,
            `items` JSON,
            `total_amount` decimal(10,2) DEFAULT 0,
            `payment_method` varchar(50) DEFAULT 'cash',
            `payment_status` varchar(20) DEFAULT 'paid',
            `notes` text,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `order_number` (`order_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {}

// AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'send_message') {
            $userId = $_POST['user_id'];
            $message = trim($_POST['message'] ?? '');
            if (!$userId || !$message) throw new Exception("Invalid data");
            $stmt = $db->prepare("SELECT line_user_id, line_account_id, reply_token, reply_token_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new Exception("User not found");
            $lineManager = new LineAccountManager($db);
            $line = $lineManager->getLineAPI($user['line_account_id']);
            // ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) - with fallback
            if (method_exists($line, 'sendMessage')) {
                $result = $line->sendMessage($user['line_user_id'], $message, $user['reply_token'] ?? null, $user['reply_token_expires'] ?? null, $db, $userId);
            } else {
                $result = $line->pushMessage($user['line_user_id'], [['type' => 'text', 'text' => $message]]);
                $result['method'] = 'push';
            }

            if ($result['code'] === 200) {
                // Get admin name
                $adminName = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin';
                
                // Check if sent_by column exists
                $hasSentBy = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                    $hasSentBy = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                if ($hasSentBy) {
                    $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, ?, NOW(), 1)");
                    $stmt->execute([$user['line_account_id'], $userId, $message, 'admin:' . $adminName]);
                } else {
                    $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, NOW(), 1)");
                    $stmt->execute([$user['line_account_id'], $userId, $message]);
                }
                echo json_encode(['success' => true, 'content' => $message, 'time' => date('H:i'), 'sent_by' => 'admin:' . $adminName, 'method' => $result['method'] ?? 'push']);
            } else { throw new Exception("LINE API Error"); }
            exit;
        } elseif ($action === 'ai_reply') {
            // AI Reply - Generate AI response
            ob_start(); // Buffer any unexpected output
            
            $userId = $_POST['user_id'];
            $lastMessage = $_POST['last_message'] ?? '';
            
            if (!$userId) throw new Exception("User ID required");
            
            $stmt = $db->prepare("SELECT line_user_id, line_account_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new Exception("User not found");
            
            $lineAccountId = $user['line_account_id'];
            
            try {
                // Load AI Module
                require_once 'modules/AIChat/Autoloader.php';
                // Autoloader is already registered via spl_autoload_register in the file
                
                $adapter = new \Modules\AIChat\Adapters\GeminiChatAdapter($db, $lineAccountId);
                
                // Check if AI is enabled
                if (!$adapter->isEnabled()) {
                    throw new Exception("AI ยังไม่ได้เปิดใช้งาน กรุณาตั้งค่า API Key ก่อน");
                }
                
                $response = $adapter->generateResponse($lastMessage, $userId);
                
                ob_end_clean(); // Clear any buffered output
                
                if ($response) {
                    echo json_encode([
                        'success' => true, 
                        'message' => $response,
                        'ai_settings' => []
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    throw new Exception("AI ไม่สามารถสร้างคำตอบได้");
                }
            } catch (Exception $aiError) {
                ob_end_clean();
                throw new Exception("AI Error: " . $aiError->getMessage());
            }
            exit;
        } elseif ($action === 'send_ai_reply') {
            // Send AI Reply to LINE
            $userId = $_POST['user_id'];
            $messageJson = $_POST['message'] ?? '';
            
            if (!$userId || !$messageJson) throw new Exception("Invalid data");
            
            $message = json_decode($messageJson, true);
            if (!$message) throw new Exception("Invalid message format");
            
            $stmt = $db->prepare("SELECT line_user_id, line_account_id, reply_token, reply_token_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new Exception("User not found");
            
            $lineManager = new LineAccountManager($db);
            $line = $lineManager->getLineAPI($user['line_account_id']);
            
            // ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) - with fallback
            $replyToken = $user['reply_token'] ?? null;
            $tokenExpires = $user['reply_token_expires'] ?? null;
            if (method_exists($line, 'sendMessage')) {
                $result = $line->sendMessage($user['line_user_id'], [$message], $replyToken, $tokenExpires, $db, $userId);
            } else {
                $result = $line->pushMessage($user['line_user_id'], [$message]);
                $result['method'] = 'push';
            }
            $method = $result['method'] ?? 'push';
            
            if ($result['code'] === 200) {
                // Save to messages
                $content = $message['text'] ?? json_encode($message);
                
                $hasSentBy = false;
                try {
                    $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                    $hasSentBy = $checkCol->rowCount() > 0;
                } catch (Exception $e) {}
                
                if ($hasSentBy) {
                    $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, 'ai', NOW(), 1)");
                    $stmt->execute([$user['line_account_id'], $userId, $content]);
                } else {
                    $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, NOW(), 1)");
                    $stmt->execute([$user['line_account_id'], $userId, $content]);
                }
                
                echo json_encode(['success' => true, 'content' => $content, 'time' => date('H:i'), 'method' => $method]);
            } else {
                throw new Exception("LINE API Error: " . ($result['error'] ?? 'Unknown'));
            }
            exit;
        } elseif ($action === 'update_tags') {
            $userId = $_POST['user_id']; $tagId = $_POST['tag_id']; $operation = $_POST['operation'];
            if ($operation === 'add') { 
                $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')"); 
                $stmt->execute([$userId, $tagId]); 
            } else { 
                $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?"); 
                $stmt->execute([$userId, $tagId]); 
            }
            $stmt = $db->prepare("SELECT t.* FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'tags' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        } elseif ($action === 'create_tag') {
            $tagName = trim($_POST['tag_name'] ?? ''); 
            $color = $_POST['color'] ?? '#3B82F6';
            $colorMap = ['gray' => '#6B7280', 'blue' => '#3B82F6', 'green' => '#10B981', 'red' => '#EF4444', 'yellow' => '#F59E0B'];
            $hexColor = $colorMap[$color] ?? $color;
            $currentBotId = $_SESSION['current_bot_id'] ?? null;
            if($tagName) { 
                $stmt = $db->prepare("INSERT INTO user_tags (line_account_id, name, color) VALUES (?, ?, ?)"); 
                $stmt->execute([$currentBotId, $tagName, $hexColor]); 
                echo json_encode(['success' => true]); 
            }
            exit;
        } elseif ($action === 'save_note') {
            $userId = $_POST['user_id']; $note = trim($_POST['note'] ?? '');
            $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$userId, $note]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
            exit;
        } elseif ($action === 'get_notes') {
            $userId = $_POST['user_id'];
            $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'notes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        } elseif ($action === 'delete_note') {
            $noteId = $_POST['note_id'];
            $stmt = $db->prepare("DELETE FROM user_notes WHERE id = ?");
            $stmt->execute([$noteId]);
            echo json_encode(['success' => true]);
            exit;
        } elseif ($action === 'save_medical') {
            $userId = $_POST['user_id'];
            $medicalConditions = trim($_POST['medical_conditions'] ?? '');
            $drugAllergies = trim($_POST['drug_allergies'] ?? '');
            $currentMedications = trim($_POST['current_medications'] ?? '');
            $stmt = $db->prepare("UPDATE users SET medical_conditions = ?, drug_allergies = ?, current_medications = ? WHERE id = ?");
            $stmt->execute([$medicalConditions, $drugAllergies, $currentMedications, $userId]);
            echo json_encode(['success' => true]);
            exit;
        } elseif ($action === 'dispense') {
            require_once __DIR__ . '/classes/FlexTemplates.php';
            require_once __DIR__ . '/includes/liff-helper.php'; // reya_liff_url_or_oa()

            $userId = $_POST['user_id'] ?? null;
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $items = $_POST['items'] ?? '[]';
            $totalAmount = floatval($_POST['total_amount'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['notes'] ?? '');
            $shopNameInput = trim($_POST['shop_name'] ?? '');
            $pharmacistName = trim($_POST['pharmacist_name'] ?? '');
            $currentBotId = $_SESSION['current_bot_id'] ?? null;
            $pharmacistId = $_SESSION['admin_id'] ?? null;
            $orderNumber = 'DIS' . date('ymdHis') . rand(100, 999);
            
            // Validate items
            $itemsArr = json_decode($items, true);
            if (!is_array($itemsArr) || count($itemsArr) === 0) {
                throw new Exception('No items to dispense');
            }
            
            // Get user info with reply token
            $stmt = $db->prepare("SELECT line_user_id, line_account_id, display_name, reply_token, reply_token_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Get shop info - use input from pharmacist if provided
            $shopInfo = ['name' => 'ร้านยา', 'address' => '', 'phone' => '', 'open_hours' => '08:00-24:00 น.', 'pharmacist' => $pharmacistName];
            try {
                $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ?");
                $stmt->execute([$user['line_account_id']]);
                $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($lineAccount) {
                    $shopInfo['name'] = $lineAccount['display_name'] ?? $lineAccount['channel_name'] ?? 'ร้านยา';
                }
                
                // Try to get shop settings
                $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
                $stmt->execute([$user['line_account_id']]);
                $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($shopSettings) {
                    $shopInfo['address'] = $shopSettings['address'] ?? '';
                    $shopInfo['phone'] = $shopSettings['phone'] ?? '';
                }
            } catch (Exception $e) {}
            
            // Override shop name if pharmacist provided custom name
            if (!empty($shopNameInput)) {
                $shopInfo['name'] = $shopNameInput;
            }
            
            // Save dispense record
            $stmt = $db->prepare("INSERT INTO dispensing_records (line_account_id, user_id, pharmacist_id, order_number, items, total_amount, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$currentBotId, $userId, $pharmacistId, $orderNumber, $items, $totalAmount, $paymentMethod, $notes]);
            $dispenseId = $db->lastInsertId();
            
            // ทุกครั้งที่จ่ายยา → สร้าง transaction ให้เข้าออเดอร์ลูกค้า
            // cash → payment_status='paid', transfer/later → 'pending' (รอจ่ายเงิน)
            $isPaid = ($paymentMethod === 'cash');
            $paymentStatus = $isPaid ? 'paid' : 'pending';
            $txnStatus     = $isPaid ? 'completed' : 'pending';
            $transactionId = null;

            try {
                if (!$isPaid) {
                    try {
                        $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND line_account_id = ?");
                        $stmt->execute([$userId, $currentBotId]);
                    } catch (Exception $e) {}

                    foreach ($itemsArr as $item) {
                        try {
                            $stmt = $db->prepare("INSERT INTO cart (line_account_id, user_id, product_id, quantity, created_at) VALUES (?, ?, ?, ?, NOW())");
                            $stmt->execute([$currentBotId, $userId, $item['product_id'], $item['qty']]);
                        } catch (Exception $e) {
                            error_log("Failed to add to cart: " . $e->getMessage());
                        }
                    }
                }

                $txnOrderNumber = 'TXN' . date('YmdHis') . rand(100, 999);
                $deliveryInfo = json_encode(['type' => 'pickup', 'dispense_id' => $dispenseId, 'payment_method' => $paymentMethod]);
                $stmt = $db->prepare("INSERT INTO transactions (line_account_id, user_id, line_user_id, order_number, transaction_type, status, payment_status, payment_method, total_amount, grand_total, delivery_info, note, created_at) VALUES (?, ?, ?, ?, 'purchase', ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$currentBotId, $userId, $user['line_user_id'] ?? null, $txnOrderNumber, $txnStatus, $paymentStatus, $paymentMethod, $totalAmount, $totalAmount, $deliveryInfo, 'จ่ายยา: ' . $orderNumber]);
                $transactionId = $db->lastInsertId();

                foreach ($itemsArr as $item) {
                    $stmt = $db->prepare("INSERT INTO transaction_items (transaction_id, product_id, product_name, product_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                    $subtotal = ($item['price'] ?? 0) * ($item['qty'] ?? 1);
                    $stmt->execute([$transactionId, $item['product_id'], $item['name'], $item['price'] ?? 0, $item['qty'], $subtotal]);
                }
            } catch (Exception $e) {
                error_log("Failed to create transaction: " . $e->getMessage());
            }

            if ($isPaid) {
                foreach ($itemsArr as $item) {
                    if (!empty($item['product_id']) && !empty($item['qty'])) {
                        $stmt = $db->prepare("UPDATE business_items SET stock = stock - ? WHERE id = ? AND stock >= ?");
                        $stmt->execute([$item['qty'], $item['product_id'], $item['qty']]);
                    }
                }
            }

            // แจ้งเตือนยาใกล้หมด — คำนวณวันยาหมดจาก dosage × ครั้ง/วัน
            try {
                require_once __DIR__ . '/classes/RefillTrackingHelper.php';
                RefillTrackingHelper::trackFromDispense($db, $itemsArr, [
                    'user_id'         => $userId,
                    'line_user_id'    => $user['line_user_id'] ?? null,
                    'line_account_id' => $currentBotId,
                    'dispense_id'     => $dispenseId,
                ]);
            } catch (Exception $e) {
                error_log("Dispense: refill tracking failed: " . $e->getMessage());
            }
            
            // Send LINE Flex Message to customer
            error_log("Dispense: Starting to send LINE message to user {$userId}, line_user_id: " . ($user['line_user_id'] ?? 'NULL'));
            
            if ($user['line_user_id']) {
                $lineManager = new LineAccountManager($db);
                $line = $lineManager->getLineAPI($user['line_account_id']);
                
                error_log("Dispense: LINE API initialized for account " . $user['line_account_id']);
                
                // Build checkout URL with LIFF-or-OA fallback.
                // Priority: dedicated liff_apps wrapper → line_accounts.liff_id
                // (via helper) → OA chat → '' (no checkout button rendered).
                // Customers of tenants without a real LIFF must NOT be sent to the
                // shared Mini App without LIFF context.
                $deepLink = !empty($transactionId) ? '/order?id=' . $transactionId : '/orders';
                $checkoutUrl = '';
                // 1. Dedicated LIFF wrapper for the Mini App, if configured.
                try {
                    $stmt = $db->prepare("SELECT liff_id FROM liff_apps WHERE line_account_id = ? AND is_active = 1 AND (name IN ('miniapp','order','checkout') OR endpoint_url LIKE '%/miniapp%') ORDER BY FIELD(name,'order','checkout','miniapp') LIMIT 1");
                    $stmt->execute([$user['line_account_id']]);
                    $liffApp = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($liffApp && reya_is_real_liff_id($liffApp['liff_id'] ?? null)) {
                        $sep = (strpos($deepLink, '?') !== false) ? '&' : '?';
                        $checkoutUrl = 'https://liff.line.me/' . $liffApp['liff_id'] . $deepLink . $sep . 'la=' . (int) $user['line_account_id'];
                    }
                } catch (Exception $e) {}
                // 2. Fall back to line_accounts.liff_id, else OA chat, else ''.
                if ($checkoutUrl === '') {
                    $checkoutUrl = reya_liff_url_or_oa($db, (int) $user['line_account_id'], $deepLink);
                }
                
                // Add product images to items
                foreach ($itemsArr as &$item) {
                    if (!empty($item['product_id']) && empty($item['image'])) {
                        try {
                            $stmt = $db->prepare("SELECT image_url FROM business_items WHERE id = ?");
                            $stmt->execute([$item['product_id']]);
                            $product = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($product && !empty($product['image_url'])) {
                                $item['image'] = $product['image_url'];
                            }
                        } catch (Exception $e) {}
                    }
                }
                unset($item);
                
                try {
                    // Create Flex Message carousel with medicine labels
                    if (count($itemsArr) > 1) {
                        // Multiple items - use carousel
                        $flexContents = FlexTemplates::medicineLabelsCarousel(
                            $itemsArr, 
                            $shopInfo, 
                            $user['display_name'],
                            ($paymentMethod === 'later' || $paymentMethod === 'transfer') ? $checkoutUrl : null
                        );
                    } else {
                        // Single item - use single bubble
                        $flexContents = FlexTemplates::medicineLabel(
                            $itemsArr[0], 
                            $shopInfo, 
                            $user['display_name'],
                            ($paymentMethod === 'later' || $paymentMethod === 'transfer') ? $checkoutUrl : null
                        );
                    }
                    
                    $flexMessage = FlexTemplates::toMessage($flexContents, '💊 รายการจ่ายยา #' . $orderNumber);
                    
                    // Log for debugging
                    error_log("Dispense Flex Message: " . json_encode($flexMessage, JSON_UNESCAPED_UNICODE));
                    
                    // Send Flex Message - ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) - with fallback
                    if (method_exists($line, 'sendMessage')) {
                        $result = $line->sendMessage($user['line_user_id'], [$flexMessage], $user['reply_token'] ?? null, $user['reply_token_expires'] ?? null, $db, $userId);
                    } else {
                        $result = $line->pushMessage($user['line_user_id'], [$flexMessage]);
                    }
                    error_log("LINE Send Result: " . json_encode($result));
                    
                    // Save message to chat history
                    $hasSentBy = false;
                    try {
                        $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                        $hasSentBy = $checkCol->rowCount() > 0;
                    } catch (Exception $e) {}
                    
                    $msgContent = json_encode($flexMessage);
                    if ($hasSentBy) {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'flex', ?, 'system:dispense', NOW(), 1)");
                        $stmt->execute([$user['line_account_id'], $userId, $msgContent]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'flex', ?, NOW(), 1)");
                        $stmt->execute([$user['line_account_id'], $userId, $msgContent]);
                    }
                } catch (Exception $e) {
                    error_log("Failed to send LINE Flex message: " . $e->getMessage());
                }
            }
            
            echo json_encode(['success' => true, 'order_number' => $orderNumber]);
            exit;
        }
    } catch (Exception $e) { http_response_code(400); echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit; }
}

$pageTitle = 'Messages Inbox';
require_once 'includes/header.php';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Get Users List
$sql = "SELECT u.*, (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread
        FROM users u WHERE u.line_account_id = ? ORDER BY last_time DESC";
$stmt = $db->prepare($sql); $stmt->execute([$currentBotId]); $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Selected User
$selectedUser = null; $messages = []; $userTags = []; $allTags = []; $userNotes = []; $userOrders = [];
if (isset($_GET['user'])) {
    $uid = $_GET['user'];
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$uid]); $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selectedUser) {
        $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction = 'incoming'")->execute([$uid]);
        $stmt = $db->prepare("SELECT * FROM messages WHERE user_id = ? ORDER BY created_at ASC"); $stmt->execute([$uid]); $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        try { 
              $stmt = $db->prepare("SELECT t.* FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = ?"); 
              $stmt->execute([$uid]); 
              $userTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
              $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name ASC");
              $stmt->execute([$currentBotId]);
              $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
              $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"); $stmt->execute([$uid]); $userNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
              $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"); $stmt->execute([$uid]); $userOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
}
function getMessagePreview($content, $type) { if ($content === null) return ''; if ($type === 'image') return '📷 รูปภาพ'; if ($type === 'sticker') return '😊 สติกเกอร์'; if ($type === 'flex') return '📋 Flex'; return mb_strlen($content) > 30 ? mb_substr($content, 0, 30) . '...' : $content; }
?>

<style>
.chat-scroll::-webkit-scrollbar { width: 5px; }
.chat-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 3px; }
.chat-bubble { white-space: pre-wrap; word-wrap: break-word; line-height: 1.5; max-width: 100%; }
.chat-incoming { background: #fff; color: #1E293B; border-radius: 0 12px 12px 12px; border: 1px solid #E2E8F0; }
.chat-outgoing { background: #10B981; color: white; border-radius: 12px 0 12px 12px; }
.tag-badge { font-size: 0.65rem; padding: 2px 6px; border-radius: 9999px; font-weight: 500; }
.tag-blue { background: #DBEAFE; color: #1E40AF; } .tag-red { background: #FEE2E2; color: #991B1B; }
.tag-green { background: #D1FAE5; color: #065F46; } .tag-yellow { background: #FEF3C7; color: #92400E; }
.tag-gray { background: #F3F4F6; color: #374151; }
.sidebar-collapsed { width: 0 !important; padding: 0 !important; overflow: hidden; }
.panel-collapsed { width: 0 !important; padding: 0 !important; overflow: hidden; }

/* LINE Official Chat Style */
#chatBox {
    background: linear-gradient(180deg, #7494A5 0%, #6B8A9A 100%);
}
.flex-preview {
    max-width: 280px;
}
</style>

<div class="h-[calc(100vh-80px)] flex bg-white rounded-xl shadow-lg border overflow-hidden">
    
    <!-- LEFT SIDEBAR: User List -->
    <div id="sidebar" class="w-72 bg-white border-r flex flex-col transition-all duration-300">
        <div class="p-3 border-b bg-gray-50 flex items-center justify-between">
            <h2 class="text-sm font-bold text-gray-700"><i class="fas fa-comments text-green-500 mr-2"></i>Inbox</h2>
            <button onclick="toggleSidebar()" class="text-gray-400 hover:text-gray-600 p-1"><i class="fas fa-chevron-left"></i></button>
        </div>
        <div class="p-2 border-b">
            <input type="text" id="userSearch" placeholder="ค้นหา..." class="w-full px-3 py-1.5 bg-gray-100 rounded-lg text-xs focus:ring-1 focus:ring-green-500 outline-none" onkeyup="filterUsers(this.value)">
        </div>
        <div class="flex-1 overflow-y-auto chat-scroll">
            <?php if (empty($users)): ?>
                <div class="p-6 text-center text-gray-400 text-sm">ยังไม่มีแชท</div>
            <?php else: foreach ($users as $user): ?>
                <a href="?user=<?= $user['id'] ?>" class="user-item block p-2.5 hover:bg-green-50 border-b border-gray-50 <?= ($selectedUser && $selectedUser['id'] == $user['id']) ? 'bg-green-50 border-l-3 border-l-green-500' : '' ?>" data-name="<?= strtolower($user['display_name']) ?>">
                    <div class="flex items-center gap-2">
                        <div class="relative flex-shrink-0">
                            <img src="<?= $user['picture_url'] ?: 'assets/images/default-avatar.png' ?>" class="w-9 h-9 rounded-full object-cover border">
                            <?php if ($user['unread'] > 0): ?><div class="absolute -top-1 -right-1 bg-red-500 text-white text-[9px] w-4 h-4 flex items-center justify-center rounded-full"><?= $user['unread'] > 9 ? '9+' : $user['unread'] ?></div><?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline">
                                <h3 class="text-xs font-semibold text-gray-800 truncate"><?= htmlspecialchars($user['display_name']) ?></h3>
                                <span class="text-[9px] text-gray-400"><?= $user['last_time'] ? date('H:i', strtotime($user['last_time'])) : '' ?></span>
                            </div>
                            <p class="text-[10px] text-gray-500 truncate"><?= htmlspecialchars(getMessagePreview($user['last_msg'], $user['last_type'])) ?></p>
                        </div>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <!-- Toggle Button (when sidebar collapsed) -->
    <button id="sidebarToggle" onclick="toggleSidebar()" class="hidden w-8 bg-gray-100 hover:bg-gray-200 text-gray-500 flex-shrink-0 flex items-center justify-center border-r">
        <i class="fas fa-chevron-right"></i>
    </button>

    <!-- CENTER: Chat Area -->
    <div class="flex-1 flex flex-col bg-[#F8FAFC] min-w-0">
        <?php if ($selectedUser): ?>

        <!-- Chat Header -->
        <div class="h-12 bg-white border-b flex items-center justify-between px-4 flex-shrink-0">
            <div class="flex items-center gap-2">
                <img src="<?= $selectedUser['picture_url'] ?: 'assets/images/default-avatar.png' ?>" class="w-8 h-8 rounded-full border">
                <div>
                    <h3 class="text-sm font-bold text-gray-800"><?= htmlspecialchars($selectedUser['display_name']) ?></h3>
                    <div id="userTagsContainer" class="flex gap-1"><?php foreach ($userTags as $tag): ?><span class="tag-badge" style="background-color: <?= htmlspecialchars($tag['color']) ?>20; color: <?= htmlspecialchars($tag['color']) ?>;"><?= htmlspecialchars($tag['name']) ?></span><?php endforeach; ?></div>
                </div>
            </div>
            <div class="flex gap-1">
                <button onclick="openTagModal()" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded text-xs"><i class="fas fa-tag"></i></button>
                <button onclick="togglePanel()" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded text-xs"><i class="fas fa-user"></i></button>
            </div>
        </div>

        <!-- Chat Messages -->
        <div id="chatBox" class="flex-1 overflow-y-auto p-3 space-y-3 chat-scroll">
            <?php 
            // Get admin name for sender display
            $adminName = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin';
            
            foreach ($messages as $msg): 
                $isOutgoing = ($msg['direction'] === 'outgoing'); 
                $content = $msg['content'] ?? ''; 
                $type = $msg['message_type'] ?? 'text';
                $sentBy = $msg['sent_by'] ?? '';
                $json = null;
                $sender = null;
                $quickReply = null;
                
                // Parse JSON content
                if (substr(trim($content), 0, 1) === '{' || substr(trim($content), 0, 1) === '[') {
                    $json = json_decode($content, true);
                    
                    // Extract sender info
                    if (isset($json['sender'])) {
                        $sender = $json['sender'];
                    } elseif (isset($json[0]['sender'])) {
                        $sender = $json[0]['sender'];
                    }
                    
                    // Extract quick reply
                    if (isset($json['quickReply'])) {
                        $quickReply = $json['quickReply'];
                    } elseif (isset($json[0]['quickReply'])) {
                        $quickReply = $json[0]['quickReply'];
                    }
                    
                    // Get actual message content
                    if (isset($json['text'])) {
                        $content = $json['text'];
                        $type = 'text';
                    } elseif (isset($json[0]['text'])) {
                        $content = $json[0]['text'];
                        $type = 'text';
                    } elseif (isset($json['type']) && $json['type'] === 'flex') {
                        $type = 'flex';
                    } elseif (isset($json[0]['type']) && $json[0]['type'] === 'flex') {
                        $type = 'flex';
                        $json = $json[0];
                    }
                }
                
                // Extract sticker info
                $stickerInfo = null;
                if ($type === 'sticker' && preg_match('/Package:\s*(\d+),\s*Sticker:\s*(\d+)/', $content, $matches)) {
                    $stickerInfo = ['packageId' => $matches[1], 'stickerId' => $matches[2]];
                }
                
                // Extract image ID
                $imageId = null;
                if ($type === 'image' && preg_match('/ID:\s*(\d+)/', $content, $matches)) {
                    $imageId = $matches[1];
                }
                
                // Determine sender label for outgoing messages
                $senderLabel = '';
                $senderIcon = '👤';
                if ($isOutgoing) {
                    if ($sentBy === 'ai' || $sentBy === 'AI') {
                        $senderLabel = '🤖 AI ตอบ';
                        $senderIcon = '🤖';
                    } elseif ($sentBy === 'system' || $sentBy === 'bot' || $sentBy === 'webhook') {
                        $senderLabel = '🤖 บอทตอบอัตโนมัติ';
                        $senderIcon = '🤖';
                    } elseif (strpos($sentBy, 'admin:') === 0) {
                        $senderLabel = '👤 ' . substr($sentBy, 6);
                        $senderIcon = '👤';
                    } elseif ($sender && isset($sender['name'])) {
                        $senderLabel = $sender['name'];
                    } else {
                        $senderLabel = '👤 แอดมิน';
                    }
                }
                
                $msgTime = date('H:i', strtotime($msg['created_at']));
            ?>
            
            <?php if (!$isOutgoing): ?>
            <!-- Incoming Message (Customer) - LEFT SIDE -->
            <div class="flex justify-start group">
                <img src="<?= $selectedUser['picture_url'] ?: 'assets/images/default-avatar.svg' ?>" class="w-8 h-8 rounded-full self-end mr-2 flex-shrink-0">
                <div class="flex flex-col items-start" style="max-width:70%">
                    <!-- Message Content -->
                    <?php if ($type === 'text'): ?>
                        <div class="bg-white rounded-2xl rounded-tl-sm px-4 py-2 text-sm text-gray-800 shadow chat-incoming whitespace-pre-wrap"><?= nl2br(htmlspecialchars($content)) ?></div>
                    
                    <?php elseif ($type === 'image'): ?>
                        <?php if ($imageId): ?>
                            <img src="api/line_content.php?id=<?= $imageId ?>" class="rounded-2xl max-w-[200px] shadow cursor-pointer" onclick="openImageModal(this.src)" onerror="this.src='assets/images/image-placeholder.svg'">
                        <?php elseif (filter_var($content, FILTER_VALIDATE_URL)): ?>
                            <img src="<?= htmlspecialchars($content) ?>" class="rounded-2xl max-w-[200px] shadow cursor-pointer" onclick="openImageModal(this.src)">
                        <?php else: ?>
                            <div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-500 shadow"><i class="fas fa-image mr-1"></i>รูปภาพ</div>
                        <?php endif; ?>
                    
                    <?php elseif ($type === 'sticker'): ?>
                        <?php if ($stickerInfo): ?>
                            <img src="https://stickershop.line-scdn.net/stickershop/v1/sticker/<?= $stickerInfo['stickerId'] ?>/android/sticker.png" class="w-24 h-24 object-contain">
                        <?php else: ?>
                            <div class="bg-white rounded-2xl p-4 text-center shadow">
                                <i class="far fa-smile text-3xl text-gray-400"></i>
                                <p class="text-[10px] text-gray-400 mt-1">Sticker</p>
                            </div>
                        <?php endif; ?>
                    
                    <?php elseif ($type === 'video'): ?>
                        <div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-600 shadow flex items-center gap-2">
                            <i class="fas fa-video text-blue-500"></i><span>วิดีโอ</span>
                        </div>
                    
                    <?php elseif ($type === 'audio'): ?>
                        <div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-600 shadow flex items-center gap-2">
                            <i class="fas fa-microphone text-purple-500"></i><span>ข้อความเสียง</span>
                        </div>
                    
                    <?php elseif ($type === 'location'): ?>
                        <div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-600 shadow flex items-center gap-2">
                            <i class="fas fa-map-marker-alt text-red-500"></i><span>ตำแหน่งที่ตั้ง</span>
                        </div>
                    
                    <?php else: ?>
                        <div class="bg-white rounded-2xl rounded-tl-sm px-4 py-2 text-sm text-gray-800 shadow chat-incoming whitespace-pre-wrap"><?= nl2br(htmlspecialchars($content)) ?></div>
                    <?php endif; ?>
                    
                    <!-- Time -->
                    <span class="text-[10px] text-gray-400 mt-1"><?= $msgTime ?></span>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Outgoing Message (Admin/AI/Bot) - RIGHT SIDE -->
            <div class="flex justify-end group">
                <div class="flex flex-col items-end" style="max-width:70%">
                    <!-- Sender Label -->
                    <span class="text-[10px] text-gray-500 mb-1"><?= $senderLabel ?></span>
                    
                    <!-- Message Content -->
                    <?php if ($type === 'text'): ?>
                        <div class="bg-green-500 text-white rounded-2xl rounded-tr-sm px-4 py-2 text-sm shadow chat-outgoing whitespace-pre-wrap"><?= nl2br(htmlspecialchars($content)) ?></div>
                    
                    <?php elseif ($type === 'flex' && $json): ?>
                        <?php 
                        $flexContents = $json['contents'] ?? $json;
                        $altText = $json['altText'] ?? 'Flex Message';
                        ?>
                        <div class="bg-white rounded-2xl shadow overflow-hidden max-w-[280px]">
                            <?php if (isset($flexContents['body']['contents'])): ?>
                                <div class="px-4 py-3 text-sm text-gray-800">
                                    <?php foreach ($flexContents['body']['contents'] as $item): ?>
                                        <?php if (isset($item['type']) && $item['type'] === 'text'): ?>
                                            <p class="<?= ($item['weight'] ?? '') === 'bold' ? 'font-bold' : '' ?> <?= ($item['size'] ?? '') === 'sm' ? 'text-xs text-gray-600' : '' ?> mb-1">
                                                <?= nl2br(htmlspecialchars($item['text'] ?? '')) ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="px-4 py-3 text-sm text-gray-600">
                                    <i class="fas fa-layer-group text-blue-500 mr-1"></i>
                                    <?= htmlspecialchars($altText) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    
                    <?php elseif ($type === 'image'): ?>
                        <?php if (filter_var($content, FILTER_VALIDATE_URL)): ?>
                            <img src="<?= htmlspecialchars($content) ?>" class="rounded-2xl max-w-[200px] shadow cursor-pointer" onclick="openImageModal(this.src)">
                        <?php else: ?>
                            <div class="bg-green-500 text-white rounded-2xl px-4 py-3 text-sm shadow"><i class="fas fa-image mr-1"></i>รูปภาพ</div>
                        <?php endif; ?>
                    
                    <?php else: ?>
                        <div class="bg-green-500 text-white rounded-2xl rounded-tr-sm px-4 py-2 text-sm shadow chat-outgoing whitespace-pre-wrap"><?= nl2br(htmlspecialchars($content)) ?></div>
                    <?php endif; ?>
                    
                    <!-- Quick Reply Buttons -->
                    <?php if ($quickReply && isset($quickReply['items'])): ?>
                    <div class="flex flex-wrap gap-1 mt-2 justify-end">
                        <?php foreach ($quickReply['items'] as $item): 
                            $action = $item['action'] ?? [];
                            $label = $action['label'] ?? '';
                        ?>
                            <span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-[10px]"><?= htmlspecialchars($label) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Time -->
                    <span class="text-[10px] text-gray-400 mt-1"><?= $msgTime ?></span>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Input Area -->
        <div class="p-3 bg-white border-t flex-shrink-0">
            <form id="sendForm" class="flex gap-2 items-end" onsubmit="sendMessage(event)">
                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                <div class="flex-1 bg-gray-100 rounded-xl px-3 py-2 focus-within:ring-1 focus-within:ring-green-500">
                    <textarea name="message" id="messageInput" rows="1" class="w-full bg-transparent border-0 outline-none text-sm resize-none max-h-20" placeholder="พิมพ์ข้อความ..." oninput="autoResize(this)"></textarea>
                </div>
                <button type="submit" class="bg-green-500 hover:bg-green-600 text-white w-9 h-9 rounded-full flex items-center justify-center shadow"><i class="fas fa-paper-plane text-xs"></i></button>
            </form>
        </div>
        <?php else: ?>
        <div class="flex-1 flex flex-col items-center justify-center text-gray-400">
            <i class="far fa-comments text-5xl mb-3 text-gray-300"></i>
            <p class="text-sm">เลือกแชทเพื่อเริ่มสนทนา</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT PANEL: Customer Details -->
    <?php if ($selectedUser): ?>
    <div id="customerPanel" class="w-72 bg-white border-l flex flex-col transition-all duration-300 overflow-hidden">
        <div class="p-3 border-b bg-gray-50 flex items-center justify-between flex-shrink-0">
            <h3 class="text-sm font-bold text-gray-700"><i class="fas fa-user text-green-500 mr-2"></i>รายละเอียดลูกค้า</h3>
            <button onclick="togglePanel()" class="text-gray-400 hover:text-gray-600 p-1"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="flex-1 overflow-y-auto chat-scroll p-3 space-y-4">
            <!-- Profile -->
            <div class="text-center pb-3 border-b">
                <img src="<?= $selectedUser['picture_url'] ?: 'assets/images/default-avatar.png' ?>" class="w-16 h-16 rounded-full mx-auto border-2 border-green-500 mb-2">
                <h4 class="font-bold text-gray-800"><?= htmlspecialchars($selectedUser['display_name']) ?></h4>
                <p class="text-xs text-gray-500"><?= $selectedUser['phone'] ?: 'ไม่มีเบอร์โทร' ?></p>
                <div class="flex justify-center gap-1 mt-2"><?php foreach ($userTags as $tag): ?><span class="tag-badge" style="background-color: <?= htmlspecialchars($tag['color']) ?>20; color: <?= htmlspecialchars($tag['color']) ?>;"><?= htmlspecialchars($tag['name']) ?></span><?php endforeach; ?></div>
            </div>
            
            <!-- Quick Info -->
            <div class="space-y-2 text-xs">
                <div class="flex justify-between"><span class="text-gray-500">สมาชิกตั้งแต่</span><span class="font-medium"><?= date('d/m/Y', strtotime($selectedUser['created_at'])) ?></span></div>
                <div class="flex justify-between"><span class="text-gray-500">ออเดอร์ทั้งหมด</span><span class="font-medium"><?= count($userOrders) ?> รายการ</span></div>
                <?php $totalSpent = array_sum(array_column($userOrders, 'grand_total')); ?>
                <div class="flex justify-between"><span class="text-gray-500">ยอดซื้อรวม</span><span class="font-medium text-green-600">฿<?= number_format($totalSpent) ?></span></div>
            </div>
            
            <!-- Medical Info Section -->
            <div class="pt-3 border-t">
                <div class="flex items-center justify-between mb-2">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-heartbeat text-red-500 mr-1"></i>ข้อมูลสุขภาพ</h5>
                    <button onclick="openMedicalModal()" class="text-blue-500 hover:text-blue-600 text-xs"><i class="fas fa-edit"></i></button>
                </div>
                <div class="space-y-2 text-xs">
                    <div class="bg-red-50 border border-red-200 rounded-lg p-2">
                        <p class="text-red-600 font-medium text-[10px] mb-1"><i class="fas fa-disease mr-1"></i>โรคประจำตัว</p>
                        <p class="text-gray-700" id="medicalConditions"><?= htmlspecialchars($selectedUser['medical_conditions'] ?? '') ?: '<span class="text-gray-400">ไม่ระบุ</span>' ?></p>
                    </div>
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-2">
                        <p class="text-orange-600 font-medium text-[10px] mb-1"><i class="fas fa-allergies mr-1"></i>แพ้ยา</p>
                        <p class="text-gray-700" id="drugAllergies"><?= htmlspecialchars($selectedUser['drug_allergies'] ?? '') ?: '<span class="text-gray-400">ไม่ระบุ</span>' ?></p>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-2">
                        <p class="text-blue-600 font-medium text-[10px] mb-1"><i class="fas fa-pills mr-1"></i>ยาที่ใช้อยู่</p>
                        <p class="text-gray-700" id="currentMedications"><?= htmlspecialchars($selectedUser['current_medications'] ?? '') ?: '<span class="text-gray-400">ไม่ระบุ</span>' ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Dispense Button -->
            <div class="pt-3 border-t">
                <button onclick="openDispenseModal()" class="w-full bg-purple-500 hover:bg-purple-600 text-white text-xs py-2.5 rounded-lg font-medium">
                    <i class="fas fa-prescription-bottle-alt mr-1"></i>จ่ายยา
                </button>
            </div>
            
            <!-- Notes Section -->
            <div class="pt-3 border-t">
                <h5 class="text-xs font-bold text-gray-700 mb-2"><i class="fas fa-sticky-note text-yellow-500 mr-1"></i>โน๊ต</h5>
                <form onsubmit="saveNote(event)" class="mb-2">
                    <textarea id="noteInput" rows="2" class="w-full border rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-green-500 outline-none resize-none" placeholder="เพิ่มโน๊ตเกี่ยวกับลูกค้า..."></textarea>
                    <button type="submit" class="w-full mt-1 bg-green-500 hover:bg-green-600 text-white text-xs py-1.5 rounded-lg">บันทึกโน๊ต</button>
                </form>
                <div id="notesList" class="space-y-2 max-h-40 overflow-y-auto">
                    <?php foreach ($userNotes as $note): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-2 text-xs relative group">
                        <p class="text-gray-700"><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                        <p class="text-[9px] text-gray-400 mt-1"><?= date('d/m/Y H:i', strtotime($note['created_at'])) ?></p>
                        <button onclick="deleteNote(<?= $note['id'] ?>, this)" class="absolute top-1 right-1 text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100"><i class="fas fa-times text-[10px]"></i></button>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($userNotes)): ?><p class="text-gray-400 text-xs text-center py-2">ยังไม่มีโน๊ต</p><?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Orders -->
            <?php if (!empty($userOrders)): ?>
            <div class="pt-3 border-t">
                <h5 class="text-xs font-bold text-gray-700 mb-2"><i class="fas fa-shopping-bag text-blue-500 mr-1"></i>ออเดอร์ล่าสุด</h5>
                <div class="space-y-1.5">
                    <?php foreach (array_slice($userOrders, 0, 3) as $order): ?>
                    <div class="bg-gray-50 rounded-lg p-2 text-xs">
                        <div class="flex justify-between"><span class="font-medium">#<?= $order['order_number'] ?? $order['id'] ?></span><span class="text-green-600">฿<?= number_format($order['grand_total']) ?></span></div>
                        <div class="text-[9px] text-gray-400"><?= date('d/m/Y', strtotime($order['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="pt-3 border-t space-y-1.5">
                <!-- AI Reply Button -->
                <button onclick="aiReply()" id="aiReplyBtn" class="w-full bg-gradient-to-r from-blue-500 to-indigo-500 hover:from-blue-600 hover:to-indigo-600 text-white text-xs py-2.5 rounded-lg font-medium">
                    <i class="fas fa-robot mr-1"></i>AI ตอบแชท
                </button>
                
                <a href="user-detail.php?id=<?= $selectedUser['id'] ?>" class="block w-full text-center bg-gray-500 hover:bg-gray-600 text-white text-xs py-2 rounded-lg"><i class="fas fa-external-link-alt mr-1"></i>ดูโปรไฟล์เต็ม</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Tag Modal -->
<?php if ($selectedUser): ?>
<div id="tagModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-xs overflow-hidden">
        <div class="p-3 border-b flex justify-between items-center bg-gray-50">
            <h3 class="font-bold text-sm text-gray-800">จัดการแท็ก</h3>
            <button onclick="closeTagModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-3 space-y-3">
            <div class="flex gap-1">
                <input type="text" id="newTagName" placeholder="สร้างแท็กใหม่..." class="flex-1 border rounded px-2 py-1.5 text-xs focus:ring-1 focus:ring-green-500 outline-none">
                <select id="newTagColor" class="border rounded text-xs bg-white px-1"><option value="gray">เทา</option><option value="blue">ฟ้า</option><option value="green">เขียว</option><option value="red">แดง</option><option value="yellow">เหลือง</option></select>
                <button onclick="createNewTag()" class="bg-green-500 text-white px-2 rounded hover:bg-green-600 text-xs"><i class="fas fa-plus"></i></button>
            </div>
            <hr>
            <div class="space-y-1 max-h-48 overflow-y-auto" id="tagList">
                <?php foreach ($allTags as $t): $hasTag = false; foreach($userTags as $ut) { if($ut['id'] == $t['id']) $hasTag = true; } ?>
                <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded cursor-pointer" onclick="toggleTag(<?= $t['id'] ?>, this)">
                    <div class="flex items-center gap-2"><span class="tag-badge" style="background-color: <?= htmlspecialchars($t['color']) ?>20; color: <?= htmlspecialchars($t['color']) ?>;"><?= htmlspecialchars($t['name']) ?></span></div>
                    <div class="check-indicator text-green-500 <?= $hasTag ? '' : 'hidden' ?>"><i class="fas fa-check-circle"></i></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Medical Info Modal -->
<div id="medicalModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="p-3 border-b flex justify-between items-center bg-red-50">
            <h3 class="font-bold text-sm text-red-700"><i class="fas fa-heartbeat mr-1"></i>ข้อมูลสุขภาพ</h3>
            <button onclick="closeMedicalModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form onsubmit="saveMedical(event)" class="p-4 space-y-3">
            <div>
                <label class="block text-xs font-medium text-red-600 mb-1"><i class="fas fa-disease mr-1"></i>โรคประจำตัว</label>
                <textarea id="inputMedicalConditions" rows="2" class="w-full border border-red-200 rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-red-500 outline-none resize-none" placeholder="เช่น เบาหวาน, ความดันโลหิตสูง..."><?= htmlspecialchars($selectedUser['medical_conditions'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-orange-600 mb-1"><i class="fas fa-allergies mr-1"></i>แพ้ยา</label>
                <textarea id="inputDrugAllergies" rows="2" class="w-full border border-orange-200 rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-orange-500 outline-none resize-none" placeholder="เช่น Penicillin, Aspirin..."><?= htmlspecialchars($selectedUser['drug_allergies'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-blue-600 mb-1"><i class="fas fa-pills mr-1"></i>ยาที่ใช้อยู่</label>
                <textarea id="inputCurrentMedications" rows="2" class="w-full border border-blue-200 rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none resize-none" placeholder="เช่น Metformin 500mg วันละ 2 ครั้ง..."><?= htmlspecialchars($selectedUser['current_medications'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white text-xs py-2 rounded-lg font-medium">
                <i class="fas fa-save mr-1"></i>บันทึกข้อมูล
            </button>
        </form>
    </div>
</div>

<!-- Dispense Modal -->
<div id="dispenseModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-2">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden max-h-[95vh] flex flex-col">
        <div class="p-3 border-b flex justify-between items-center bg-purple-50 flex-shrink-0">
            <h3 class="font-bold text-sm text-purple-700"><i class="fas fa-prescription-bottle-alt mr-1"></i>จ่ายยา - <?= htmlspecialchars($selectedUser['display_name'] ?? '') ?></h3>
            <button onclick="closeDispenseModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        
        <!-- Warning if allergies -->
        <?php if (!empty($selectedUser['drug_allergies'])): ?>
        <div class="bg-red-100 border-b border-red-200 px-4 py-2 text-xs text-red-700">
            <i class="fas fa-exclamation-triangle mr-1"></i><strong>⚠️ แพ้ยา:</strong> <?= htmlspecialchars($selectedUser['drug_allergies']) ?>
        </div>
        <?php endif; ?>
        
        <div class="flex-1 overflow-y-auto p-4">
            <!-- Shop Info -->
            <div class="grid grid-cols-2 gap-3 mb-3 p-3 bg-green-50 rounded-lg">
                <div>
                    <label class="block text-xs font-medium text-green-700 mb-1"><i class="fas fa-store mr-1"></i>ชื่อร้าน</label>
                    <input type="text" id="dispenseShopName" class="w-full border border-green-200 rounded-lg px-2 py-1.5 text-sm focus:ring-1 focus:ring-green-500 outline-none" placeholder="ชื่อร้านยา..." value="<?= htmlspecialchars($_SESSION['shop_name'] ?? 'ร้านยา') ?>">
                </div>
                <div>
                    <label class="block text-xs font-medium text-green-700 mb-1"><i class="fas fa-user-md mr-1"></i>ผู้จ่ายยา</label>
                    <input type="text" id="dispensePharmacist" class="w-full border border-green-200 rounded-lg px-2 py-1.5 text-sm focus:ring-1 focus:ring-green-500 outline-none" placeholder="ชื่อเภสัชกร..." value="<?= htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['username'] ?? '') ?>">
                </div>
            </div>
            
            <!-- Search Product -->
            <div class="mb-3">
                <input type="text" id="productSearch" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-1 focus:ring-purple-500 outline-none" placeholder="🔍 ค้นหายา (ชื่อ, SKU, บาร์โค้ด)..." oninput="searchProducts(this.value)">
                <div id="productResults" class="mt-1 border rounded-lg max-h-32 overflow-y-auto hidden"></div>
            </div>
            
            <!-- Selected Items with Details -->
            <div class="border rounded-lg overflow-hidden mb-3">
                <div class="bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600 border-b flex justify-between">
                    <span>รายการยา</span>
                    <span id="itemCount">0 รายการ</span>
                </div>
                <div id="dispenseItems" class="divide-y max-h-[50vh] overflow-y-auto">
                    <div class="p-4 text-center text-gray-400 text-xs" id="emptyDispense">
                        <i class="fas fa-pills text-2xl mb-2"></i><br>
                        ยังไม่มีรายการ - ค้นหาและเลือกยาด้านบน
                    </div>
                </div>
            </div>
            
            <!-- Payment -->
            <div class="flex gap-3 mb-3">
                <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-600 mb-1">ชำระเงิน</label>
                    <select id="paymentMethod" class="w-full border rounded-lg px-2 py-2 text-sm">
                        <option value="cash">💵 เงินสด</option>
                        <option value="transfer">📱 โอนเงิน</option>
                        <option value="credit">💳 บัตรเครดิต</option>
                        <option value="later">⏰ จ่ายทีหลัง</option>
                    </select>
                </div>
                <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-600 mb-1">ยอดรวม</label>
                    <div class="text-2xl font-bold text-purple-600" id="dispenseTotal">฿0</div>
                </div>
            </div>
        </div>
        
        <div class="p-3 border-t bg-gray-50 flex-shrink-0">
            <button type="button" id="submitDispenseBtn" onclick="submitDispense()" class="w-full bg-purple-500 hover:bg-purple-600 text-white py-3 rounded-lg font-medium text-sm">
                <i class="fas fa-check mr-1"></i>ยืนยันจ่ายยา
            </button>
        </div>
    </div>
</div>

<!-- Medicine Detail Modal (for each item) -->
<div id="medicineDetailModal" class="fixed inset-0 bg-black/50 z-[60] hidden flex items-center justify-center p-2">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden max-h-[90vh] flex flex-col">
        <div class="p-3 border-b bg-green-50 flex justify-between items-center flex-shrink-0">
            <h3 class="font-bold text-sm text-green-700"><i class="fas fa-pills mr-1"></i>รายละเอียดสินค้า</h3>
            <button onclick="closeMedicineDetail()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="p-4 overflow-y-auto flex-1">
            <input type="hidden" id="editingItemIndex">
            
            <!-- Product Name -->
            <div class="mb-3 p-3 bg-green-50 rounded-lg">
                <p class="text-xs text-gray-500">ชื่อสินค้า</p>
                <p class="font-bold text-green-700" id="medDetailName">-</p>
            </div>
            
            <!-- Is Medicine Checkbox -->
            <div class="mb-3 p-3 bg-blue-50 rounded-lg">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="isMedicine" checked onchange="toggleMedicineFields()" class="w-5 h-5 text-blue-500 rounded">
                    <span class="text-sm font-medium text-blue-700">💊 เป็นยา (แสดงรายละเอียดการใช้ยา)</span>
                </label>
            </div>
            
            <!-- Medicine Fields (shown when isMedicine is checked) -->
            <div id="medicineFields">
                <!-- Indication -->
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">ข้อบ่งใช้ (Indication)</label>
                    <input type="text" id="medIndication" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="เช่น แก้ปวด, ลดไข้, แก้อักเสบ">
                </div>
                
                <!-- Dosage -->
                <div class="grid grid-cols-3 gap-2 mb-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">รับประทานครั้งละ</label>
                        <input type="number" id="medDosage" class="w-full border rounded-lg px-3 py-2 text-sm text-center" value="1" min="0.5" step="0.5">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">หน่วย</label>
                        <select id="medDosageUnit" class="w-full border rounded-lg px-2 py-2 text-sm">
                            <option value="เม็ด">เม็ด</option>
                            <option value="แคปซูล">แคปซูล</option>
                            <option value="ช้อนชา">ช้อนชา</option>
                            <option value="ช้อนโต๊ะ">ช้อนโต๊ะ</option>
                            <option value="มล.">มล.</option>
                            <option value="ซอง">ซอง</option>
                            <option value="หยด">หยด</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">วันละ</label>
                        <select id="medFrequency" class="w-full border rounded-lg px-2 py-2 text-sm">
                            <option value="1">1 ครั้ง</option>
                            <option value="2">2 ครั้ง</option>
                            <option value="3" selected>3 ครั้ง</option>
                            <option value="4">4 ครั้ง</option>
                            <option value="prn">เมื่อมีอาการ</option>
                        </select>
                    </div>
                </div>
                
                <!-- Meal Timing -->
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-600 mb-2">เวลารับประทาน</label>
                    <div class="flex gap-2">
                        <label class="flex items-center gap-1 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="mealTiming" value="before" class="text-green-500">
                            <span class="text-xs">ก่อนอาหาร</span>
                        </label>
                        <label class="flex items-center gap-1 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="mealTiming" value="after" checked class="text-green-500">
                            <span class="text-xs">หลังอาหาร</span>
                        </label>
                        <label class="flex items-center gap-1 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="mealTiming" value="with" class="text-green-500">
                            <span class="text-xs">พร้อมอาหาร</span>
                        </label>
                    </div>
                </div>
                
                <!-- Time of Day -->
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-600 mb-2">มื้อที่รับประทาน</label>
                    <div class="grid grid-cols-4 gap-2">
                        <label class="flex flex-col items-center p-2 border rounded-lg cursor-pointer hover:bg-yellow-50 has-[:checked]:bg-yellow-100 has-[:checked]:border-yellow-400">
                            <span class="text-xl">🌅</span>
                            <input type="checkbox" name="timeOfDay" value="morning" checked class="mt-1">
                            <span class="text-xs">เช้า</span>
                        </label>
                        <label class="flex flex-col items-center p-2 border rounded-lg cursor-pointer hover:bg-orange-50 has-[:checked]:bg-orange-100 has-[:checked]:border-orange-400">
                            <span class="text-xl">☀️</span>
                            <input type="checkbox" name="timeOfDay" value="noon" checked class="mt-1">
                            <span class="text-xs">กลางวัน</span>
                        </label>
                        <label class="flex flex-col items-center p-2 border rounded-lg cursor-pointer hover:bg-blue-50 has-[:checked]:bg-blue-100 has-[:checked]:border-blue-400">
                            <span class="text-xl">🌆</span>
                            <input type="checkbox" name="timeOfDay" value="evening" checked class="mt-1">
                            <span class="text-xs">เย็น</span>
                        </label>
                        <label class="flex flex-col items-center p-2 border rounded-lg cursor-pointer hover:bg-purple-50 has-[:checked]:bg-purple-100 has-[:checked]:border-purple-400">
                            <span class="text-xl">🌙</span>
                            <input type="checkbox" name="timeOfDay" value="bedtime" class="mt-1">
                            <span class="text-xs">ก่อนนอน</span>
                        </label>
                    </div>
                </div>
                
                <!-- Usage Type -->
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-600 mb-2">ประเภทการใช้</label>
                    <div class="flex gap-2">
                        <label class="flex items-center gap-1 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="usageType" value="internal" checked class="text-green-500">
                            <span class="text-xs">💊 ยาใช้ภายใน</span>
                        </label>
                        <label class="flex items-center gap-1 px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="usageType" value="external" class="text-green-500">
                            <span class="text-xs">🧴 ยาใช้ภายนอก</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Special Instructions (for medicine) -->
            <div class="mb-3" id="specialInstSection">
                <label class="block text-xs font-medium text-gray-600 mb-2">คำแนะนำพิเศษ</label>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <label class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="specialInst" value="before_meal_30">
                        <span>ก่อนอาหาร 30 นาที</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="specialInst" value="after_meal_immediately">
                        <span>หลังอาหารทันที</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="specialInst" value="take_until_finish">
                        <span>ทานยาติดต่อกันจนหมด</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="specialInst" value="drink_water">
                        <span>ดื่มน้ำตามมากๆ</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="specialInst" value="drowsiness">
                        <span>⚠️ ยานี้อาจทำให้ง่วงซึม</span>
                    </label>
                    <label class="flex items-center gap-2 p-2 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="specialInst" value="no_alcohol">
                        <span>⚠️ ห้ามดื่มแอลกอฮอล์</span>
                    </label>
                </div>
            </div>
            
            <!-- Quantity -->
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-600 mb-1">จำนวนที่จ่าย</label>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="adjustMedQty(-1)" class="w-10 h-10 bg-gray-100 rounded-lg hover:bg-gray-200 text-lg">-</button>
                    <input type="number" id="medQuantity" class="w-20 border rounded-lg px-3 py-2 text-center text-lg font-bold" value="1" min="1">
                    <button type="button" onclick="adjustMedQty(1)" class="w-10 h-10 bg-gray-100 rounded-lg hover:bg-gray-200 text-lg">+</button>
                    <span class="text-sm text-gray-500" id="medUnit">ชิ้น</span>
                </div>
            </div>
            
            <!-- Usage Instructions (for non-medicine) -->
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-600 mb-1" id="notesLabel">คำแนะนำการใช้</label>
                <textarea id="medNotes" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="คำแนะนำการใช้งาน..."></textarea>
            </div>
        </div>
        
        <div class="p-3 border-t bg-gray-50 flex gap-2">
            <button onclick="closeMedicineDetail()" class="flex-1 py-2 border rounded-lg hover:bg-gray-100 text-sm">ยกเลิก</button>
            <button onclick="saveMedicineDetail()" class="flex-1 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm font-medium">
                <i class="fas fa-check mr-1"></i>บันทึก
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const userId = "<?= $selectedUser ? ($selectedUser['id'] ?? '') : '' ?>";
const chatBox = document.getElementById('chatBox');
if(chatBox) chatBox.scrollTop = chatBox.scrollHeight;

function autoResize(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 80) + 'px'; }

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    sidebar.classList.toggle('sidebar-collapsed');
    toggle.classList.toggle('hidden');
}

function togglePanel() {
    const panel = document.getElementById('customerPanel');
    if(panel) panel.classList.toggle('panel-collapsed');
}

function filterUsers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.user-item').forEach(el => {
        el.style.display = el.dataset.name.includes(q) ? '' : 'none';
    });
}

async function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('messageInput');
    const msg = input.value.trim();
    if (!msg) return;
    appendMessage(msg, 'outgoing');
    input.value = ''; input.style.height = 'auto';
    
    // Show typing indicator while waiting for AI response
    showTypingIndicator();
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('user_id', userId);
        formData.append('message', msg);
        await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
    } catch (err) { console.error(err); }
    
    // Hide typing indicator after 15 seconds max (AI should respond within this time)
    // Polling will hide it earlier when message arrives
    setTimeout(hideTypingIndicator, 15000);
}

// Typing indicator functions
function showTypingIndicator() {
    hideTypingIndicator(); // Remove existing first
    const div = document.createElement('div');
    div.id = 'typingIndicator';
    div.className = 'flex justify-start';
    const botName = '<?= addslashes($botName ?? "ร้านค้า") ?>';
    div.innerHTML = `
        <div class="flex flex-col items-start" style="max-width:75%">
            <div class="flex items-center gap-2 mb-1">
                <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center text-white text-xs font-bold shadow">
                    ${botName.charAt(0)}
                </div>
                <span class="text-xs text-white/90 font-medium">${botName} 🤖</span>
            </div>
            <div class="bg-white rounded-2xl rounded-tl-sm shadow-lg px-4 py-3">
                <div class="typing-dots flex gap-1">
                    <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                    <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                    <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                </div>
            </div>
        </div>
    `;
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight;
}

function hideTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) indicator.remove();
}

function appendMessage(content, direction, msgType = 'text', extra = {}) {
    const div = document.createElement('div');
    const time = new Date().toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'});
    const adminName = '<?= addslashes($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin') ?>';
    const customerPic = '<?= addslashes($selectedUser ? ($selectedUser['picture_url'] ?? 'assets/images/default-avatar.svg') : 'assets/images/default-avatar.svg') ?>';
    
    // incoming = ลูกค้า (ซ้าย), outgoing = ผู้ส่ง/แอดมิน/AI/บอท (ขวา)
    const isOutgoing = direction === 'outgoing';
    
    if (!isOutgoing) {
        // Customer message (LEFT side)
        div.className = 'flex justify-start group';
        
        let messageContent = '';
        if (msgType === 'image') {
            messageContent = `<img src="${escapeHtml(content)}" class="rounded-2xl max-w-[200px] shadow cursor-pointer" onclick="openImageModal(this.src)" onerror="this.src='assets/images/image-placeholder.svg'">`;
        } else if (msgType === 'sticker') {
            const stickerId = extra.stickerId || '';
            if (stickerId) {
                messageContent = `<img src="https://stickershop.line-scdn.net/stickershop/v1/sticker/${stickerId}/android/sticker.png" class="w-24 h-24 object-contain">`;
            } else {
                messageContent = `<div class="bg-white rounded-2xl p-4 text-center shadow"><i class="far fa-smile text-3xl text-gray-400"></i><p class="text-[10px] text-gray-400 mt-1">Sticker</p></div>`;
            }
        } else if (msgType === 'video') {
            messageContent = `<div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-600 shadow flex items-center gap-2"><i class="fas fa-video text-blue-500"></i><span>วิดีโอ</span></div>`;
        } else if (msgType === 'audio') {
            messageContent = `<div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-600 shadow flex items-center gap-2"><i class="fas fa-microphone text-purple-500"></i><span>ข้อความเสียง</span></div>`;
        } else if (msgType === 'location') {
            messageContent = `<div class="bg-white rounded-2xl px-4 py-3 text-sm text-gray-600 shadow flex items-center gap-2"><i class="fas fa-map-marker-alt text-red-500"></i><span>ตำแหน่งที่ตั้ง</span></div>`;
        } else {
            messageContent = `<div class="bg-white rounded-2xl rounded-tl-sm px-4 py-2 text-sm text-gray-800 shadow chat-incoming whitespace-pre-wrap">${escapeHtml(content).replace(/\n/g, '<br>')}</div>`;
        }
        
        div.innerHTML = `
            <img src="${escapeHtml(customerPic)}" class="w-8 h-8 rounded-full self-end mr-2 flex-shrink-0" onerror="this.src='assets/images/default-avatar.svg'">
            <div class="flex flex-col items-start" style="max-width:70%">
                ${messageContent}
                <span class="text-[10px] text-gray-400 mt-1">${time}</span>
            </div>
        `;
    } else {
        // Outgoing message (RIGHT side) - Admin/AI/Bot
        div.className = 'flex justify-end group';
        
        // Determine sender label
        let senderLabel = '👤 ' + adminName;
        if (extra.isAI || extra.sentBy === 'ai') {
            senderLabel = '🤖 AI ตอบ';
        } else if (extra.sentBy === 'bot' || extra.sentBy === 'system' || extra.sentBy === 'webhook') {
            senderLabel = '🤖 บอทตอบอัตโนมัติ';
        } else if (extra.sentBy && extra.sentBy.startsWith('admin:')) {
            senderLabel = '👤 ' + extra.sentBy.substring(6);
        }
        
        let messageContent = '';
        if (msgType === 'image') {
            messageContent = `<img src="${escapeHtml(content)}" class="rounded-2xl max-w-[200px] shadow cursor-pointer" onclick="openImageModal(this.src)">`;
        } else if (msgType === 'flex') {
            messageContent = `<div class="bg-white rounded-2xl shadow overflow-hidden max-w-[280px] px-4 py-3 text-sm text-gray-600"><i class="fas fa-layer-group text-blue-500 mr-1"></i>Flex Message</div>`;
        } else if (msgType === 'sticker') {
            const stickerId = extra.stickerId || '';
            if (stickerId) {
                messageContent = `<img src="https://stickershop.line-scdn.net/stickershop/v1/sticker/${stickerId}/android/sticker.png" class="w-24 h-24 object-contain">`;
            } else {
                messageContent = `<div class="bg-green-500 text-white rounded-2xl p-4 text-center shadow"><i class="far fa-smile text-3xl"></i></div>`;
            }
        } else {
            messageContent = `<div class="bg-green-500 text-white rounded-2xl rounded-tr-sm px-4 py-2 text-sm shadow chat-outgoing whitespace-pre-wrap">${escapeHtml(content).replace(/\n/g, '<br>')}</div>`;
        }
        
        div.innerHTML = `
            <div class="flex flex-col items-end" style="max-width:70%">
                <span class="text-[10px] text-gray-500 mb-1">${senderLabel}</span>
                ${messageContent}
                <span class="text-[10px] text-gray-400 mt-1">${time}</span>
            </div>
        `;
    }
    
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight;
}

function escapeHtml(text) { const div = document.createElement('div'); div.innerText = text; return div.innerHTML; }

async function saveNote(e) {
    e.preventDefault();
    const input = document.getElementById('noteInput');
    const note = input.value.trim();
    if (!note) return;
    try {
        const formData = new FormData();
        formData.append('action', 'save_note');
        formData.append('user_id', userId);
        formData.append('note', note);
        const res = await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        if (data.success) {
            const list = document.getElementById('notesList');
            const div = document.createElement('div');
            div.className = 'bg-yellow-50 border border-yellow-200 rounded-lg p-2 text-xs';
            div.innerHTML = `<p class="text-gray-700">${escapeHtml(note)}</p><p class="text-[9px] text-gray-400 mt-1">เมื่อสักครู่</p>`;
            list.insertBefore(div, list.firstChild);
            input.value = '';
        }
    } catch (err) { console.error(err); }
}

async function deleteNote(noteId, btn) {
    if (!confirm('ลบโน๊ตนี้?')) return;
    try {
        const formData = new FormData();
        formData.append('action', 'delete_note');
        formData.append('note_id', noteId);
        await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        btn.closest('.bg-yellow-50').remove();
    } catch (err) {}
}

function openTagModal() { document.getElementById('tagModal').classList.remove('hidden'); }
function closeTagModal() { document.getElementById('tagModal').classList.add('hidden'); }

async function toggleTag(tagId, el) {
    const indicator = el.querySelector('.check-indicator');
    const isAdding = indicator.classList.contains('hidden');
    indicator.classList.toggle('hidden');
    try {
        const formData = new FormData();
        formData.append('action', 'update_tags');
        formData.append('user_id', userId);
        formData.append('tag_id', tagId);
        formData.append('operation', isAdding ? 'add' : 'remove');
        const res = await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        if(data.success) updateTagsUI(data.tags);
    } catch(err) { indicator.classList.toggle('hidden'); }
}

async function createNewTag() {
    const name = document.getElementById('newTagName').value.trim();
    const color = document.getElementById('newTagColor').value;
    if(!name) return;
    try {
        const formData = new FormData();
        formData.append('action', 'create_tag');
        formData.append('tag_name', name);
        formData.append('color', color);
        await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        location.reload();
    } catch(err) {}
}

function updateTagsUI(tags) {
    document.getElementById('userTagsContainer').innerHTML = tags.map(t => `<span class="tag-badge" style="background-color: ${t.color}20; color: ${t.color};">${escapeHtml(t.name)}</span>`).join('');
}

document.getElementById('tagModal')?.addEventListener('click', (e) => { if(e.target.id === 'tagModal') closeTagModal(); });

// Medical Info Functions
function openMedicalModal() { document.getElementById('medicalModal').classList.remove('hidden'); }
function closeMedicalModal() { document.getElementById('medicalModal').classList.add('hidden'); }

async function saveMedical(e) {
    e.preventDefault();
    const medicalConditions = document.getElementById('inputMedicalConditions').value;
    const drugAllergies = document.getElementById('inputDrugAllergies').value;
    const currentMedications = document.getElementById('inputCurrentMedications').value;
    
    try {
        const formData = new FormData();
        formData.append('action', 'save_medical');
        formData.append('user_id', userId);
        formData.append('medical_conditions', medicalConditions);
        formData.append('drug_allergies', drugAllergies);
        formData.append('current_medications', currentMedications);
        
        const res = await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        
        if (data.success) {
            document.getElementById('medicalConditions').innerHTML = medicalConditions || '<span class="text-gray-400">ไม่ระบุ</span>';
            document.getElementById('drugAllergies').innerHTML = drugAllergies || '<span class="text-gray-400">ไม่ระบุ</span>';
            document.getElementById('currentMedications').innerHTML = currentMedications || '<span class="text-gray-400">ไม่ระบุ</span>';
            closeMedicalModal();
            alert('บันทึกข้อมูลสำเร็จ');
        }
    } catch (err) { console.error(err); }
}

document.getElementById('medicalModal')?.addEventListener('click', (e) => { if(e.target.id === 'medicalModal') closeMedicalModal(); });

// Dispense Functions
let dispenseItems = [];

function openDispenseModal() { 
    document.getElementById('dispenseModal').classList.remove('hidden'); 
    dispenseItems = [];
    updateDispenseUI();
}
function closeDispenseModal() { document.getElementById('dispenseModal').classList.add('hidden'); }

let searchTimeout;
async function searchProducts(query) {
    clearTimeout(searchTimeout);
    const resultsDiv = document.getElementById('productResults');
    
    if (query.length < 1) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    searchTimeout = setTimeout(async () => {
        try {
            resultsDiv.innerHTML = '<div class="p-2 text-gray-400 text-xs text-center"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังค้นหา...</div>';
            resultsDiv.classList.remove('hidden');
            
            // Search in local DB
            const res = await fetch(`api/ajax_handler.php?action=search_products&q=${encodeURIComponent(query)}`);
            const data = await res.json();
            
            let products = [];
            if (data.success && data.products) {
                products = data.products;
            }
            
            // Also try CNY sync if available
            try {
                const res2 = await fetch(`api/cny_sync.php?action=find_local&sku=${encodeURIComponent(query)}`);
                const data2 = await res2.json();
                if (data2.found && data2.product) {
                    // Check if not already in list
                    if (!products.find(p => p.id === data2.product.id)) {
                        products.unshift(data2.product);
                    }
                }
            } catch (e) {}
            
            if (products.length > 0) {
                resultsDiv.innerHTML = products.slice(0, 8).map(p => `
                    <div class="p-2 hover:bg-purple-50 cursor-pointer text-xs border-b last:border-0" onclick="addDispenseItem(${p.id}, '${escapeHtml(p.name)}', ${p.price || 0}, '${escapeHtml(p.unit || 'ชิ้น')}')">
                        <div class="font-medium text-gray-800">${escapeHtml(p.name)}</div>
                        <div class="text-gray-500 flex gap-2">
                            ${p.sku ? `<span>${p.sku}</span>` : ''}
                            <span class="text-green-600 font-medium">฿${Number(p.price || 0).toLocaleString()}</span>
                            <span>คงเหลือ: ${p.stock ?? '-'}</span>
                        </div>
                    </div>
                `).join('');
            } else {
                resultsDiv.innerHTML = '<div class="p-3 text-gray-400 text-xs text-center"><i class="fas fa-search mr-1"></i>ไม่พบสินค้า "' + escapeHtml(query) + '"</div>';
            }
        } catch (err) { 
            console.error(err);
            resultsDiv.innerHTML = '<div class="p-2 text-red-400 text-xs text-center">เกิดข้อผิดพลาด</div>';
        }
    }, 200);
}

function addDispenseItem(productId, name, price, unit) {
    const existing = dispenseItems.find(i => i.product_id === productId);
    if (existing) {
        // Open detail modal to edit
        openMedicineDetail(dispenseItems.indexOf(existing));
    } else {
        // Add new item with default values
        dispenseItems.push({ 
            product_id: productId, 
            name, 
            price, 
            unit: unit || 'ชิ้น', // Use product's unit
            qty: 1,
            isMedicine: true, // Default to medicine
            // Medicine details
            indication: '',
            dosage: 1,
            dosageUnit: 'เม็ด',
            frequency: '3',
            mealTiming: 'after',
            timeOfDay: ['morning', 'noon', 'evening'],
            usageType: 'internal',
            specialInstructions: [],
            notes: ''
        });
        // Open detail modal for new item
        openMedicineDetail(dispenseItems.length - 1);
    }
    document.getElementById('productSearch').value = '';
    document.getElementById('productResults').classList.add('hidden');
}

function toggleMedicineFields() {
    const isMedicine = document.getElementById('isMedicine').checked;
    const medicineFields = document.getElementById('medicineFields');
    const specialInstSection = document.getElementById('specialInstSection');
    const notesLabel = document.getElementById('notesLabel');
    
    if (isMedicine) {
        medicineFields.style.display = 'block';
        specialInstSection.style.display = 'block';
        notesLabel.textContent = 'หมายเหตุเพิ่มเติม';
        document.getElementById('medNotes').placeholder = 'คำแนะนำอื่นๆ...';
    } else {
        medicineFields.style.display = 'none';
        specialInstSection.style.display = 'none';
        notesLabel.textContent = 'คำแนะนำการใช้';
        document.getElementById('medNotes').placeholder = 'คำแนะนำการใช้งาน...';
    }
}

function openMedicineDetail(index) {
    const item = dispenseItems[index];
    document.getElementById('editingItemIndex').value = index;
    document.getElementById('medDetailName').textContent = item.name;
    document.getElementById('medIndication').value = item.indication || '';
    document.getElementById('medDosage').value = item.dosage || 1;
    document.getElementById('medDosageUnit').value = item.dosageUnit || 'เม็ด';
    document.getElementById('medFrequency').value = item.frequency || '3';
    document.getElementById('medQuantity').value = item.qty || 1;
    document.getElementById('medUnit').textContent = item.unit || 'ชิ้น';
    document.getElementById('medNotes').value = item.notes || '';
    
    // Set isMedicine checkbox
    const isMedicineCheckbox = document.getElementById('isMedicine');
    isMedicineCheckbox.checked = item.isMedicine !== false; // Default true
    toggleMedicineFields();
    
    // Set meal timing
    document.querySelectorAll('input[name="mealTiming"]').forEach(r => {
        r.checked = r.value === (item.mealTiming || 'after');
    });
    
    // Set time of day
    document.querySelectorAll('input[name="timeOfDay"]').forEach(cb => {
        cb.checked = (item.timeOfDay || ['morning', 'noon', 'evening']).includes(cb.value);
    });
    
    // Set usage type
    document.querySelectorAll('input[name="usageType"]').forEach(r => {
        r.checked = r.value === (item.usageType || 'internal');
    });
    
    // Set special instructions
    document.querySelectorAll('input[name="specialInst"]').forEach(cb => {
        cb.checked = (item.specialInstructions || []).includes(cb.value);
    });
    
    document.getElementById('medicineDetailModal').classList.remove('hidden');
}

function closeMedicineDetail() {
    document.getElementById('medicineDetailModal').classList.add('hidden');
}

function adjustMedQty(delta) {
    const input = document.getElementById('medQuantity');
    let val = parseInt(input.value) || 1;
    val = Math.max(1, val + delta);
    input.value = val;
}

function saveMedicineDetail() {
    const index = parseInt(document.getElementById('editingItemIndex').value);
    const item = dispenseItems[index];
    
    // Save isMedicine flag
    item.isMedicine = document.getElementById('isMedicine').checked;
    item.qty = parseInt(document.getElementById('medQuantity').value) || 1;
    item.notes = document.getElementById('medNotes').value;
    
    // Only save medicine details if it's a medicine
    if (item.isMedicine) {
        item.indication = document.getElementById('medIndication').value;
        item.dosage = parseFloat(document.getElementById('medDosage').value) || 1;
        item.dosageUnit = document.getElementById('medDosageUnit').value;
        item.frequency = document.getElementById('medFrequency').value;
        
        // Get meal timing
        item.mealTiming = document.querySelector('input[name="mealTiming"]:checked')?.value || 'after';
        
        // Get time of day
        item.timeOfDay = Array.from(document.querySelectorAll('input[name="timeOfDay"]:checked')).map(cb => cb.value);
        
        // Get usage type
        item.usageType = document.querySelector('input[name="usageType"]:checked')?.value || 'internal';
        
        // Get special instructions
        item.specialInstructions = Array.from(document.querySelectorAll('input[name="specialInst"]:checked')).map(cb => cb.value);
    } else {
        // Clear medicine-specific fields for non-medicine items
        item.indication = '';
        item.dosage = null;
        item.dosageUnit = null;
        item.frequency = null;
        item.mealTiming = null;
        item.timeOfDay = [];
        item.usageType = null;
        item.specialInstructions = [];
    }
    
    closeMedicineDetail();
    updateDispenseUI();
}

function removeDispenseItem(index) {
    dispenseItems.splice(index, 1);
    updateDispenseUI();
}

function updateDispenseQty(index, qty) {
    if (qty < 1) {
        removeDispenseItem(index);
    } else {
        dispenseItems[index].qty = qty;
        updateDispenseUI();
    }
}

function getTimeIcons(timeOfDay) {
    const icons = {
        morning: '🌅',
        noon: '☀️',
        evening: '🌆',
        bedtime: '🌙'
    };
    return (timeOfDay || []).map(t => icons[t] || '').join(' ');
}

function getMealTimingText(timing) {
    const texts = { before: 'ก่อนอาหาร', after: 'หลังอาหาร', with: 'พร้อมอาหาร' };
    return texts[timing] || timing;
}

function getFrequencyText(freq) {
    if (freq === 'prn') return 'เมื่อมีอาการ';
    return freq + ' ครั้ง/วัน';
}

function updateDispenseUI() {
    const container = document.getElementById('dispenseItems');
    
    if (dispenseItems.length === 0) {
        container.innerHTML = `<div class="p-4 text-center text-gray-400 text-xs" id="emptyDispense">
            <i class="fas fa-pills text-2xl mb-2"></i><br>
            ยังไม่มีรายการ - ค้นหาและเลือกสินค้าด้านบน
        </div>`;
    } else {
        container.innerHTML = dispenseItems.map((item, i) => {
            // Check if item is medicine
            const isMedicine = item.isMedicine !== false;
            
            if (isMedicine) {
                // Medicine display with full details
                return `
                <div class="p-3 hover:bg-gray-50 border-b">
                    <div class="flex items-start gap-2">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-sm text-gray-800">${escapeHtml(item.name)}</span>
                                <span class="text-xs px-2 py-0.5 rounded ${item.usageType === 'external' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'}">
                                    ${item.usageType === 'external' ? '🧴 ภายนอก' : '💊 ภายใน'}
                                </span>
                            </div>
                            ${item.indication ? `<div class="text-xs text-gray-500 mt-1">ข้อบ่งใช้: ${escapeHtml(item.indication)}</div>` : ''}
                            <div class="flex flex-wrap gap-2 mt-2 text-xs">
                                <span class="px-2 py-1 bg-purple-50 text-purple-700 rounded">
                                    ${item.dosage} ${item.dosageUnit} × ${getFrequencyText(item.frequency)}
                                </span>
                                <span class="px-2 py-1 bg-yellow-50 text-yellow-700 rounded">
                                    ${getMealTimingText(item.mealTiming)}
                                </span>
                                <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded">
                                    ${getTimeIcons(item.timeOfDay)}
                                </span>
                            </div>
                            ${item.specialInstructions && item.specialInstructions.length > 0 ? `
                            <div class="mt-2 text-xs text-orange-600">
                                ${item.specialInstructions.includes('drowsiness') ? '⚠️ อาจทำให้ง่วงซึม ' : ''}
                                ${item.specialInstructions.includes('take_until_finish') ? '📌 ทานจนหมด ' : ''}
                                ${item.specialInstructions.includes('drink_water') ? '💧 ดื่มน้ำมากๆ ' : ''}
                            </div>` : ''}
                            ${item.notes ? `<div class="mt-1 text-xs text-gray-500 italic">📝 ${escapeHtml(item.notes)}</div>` : ''}
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-sm font-bold text-purple-600">฿${(item.price * item.qty).toLocaleString()}</div>
                            <div class="text-xs text-gray-500">${item.qty} ${item.unit}</div>
                            <div class="flex gap-1 mt-2">
                                <button onclick="openMedicineDetail(${i})" class="px-2 py-1 text-xs bg-blue-100 text-blue-600 rounded hover:bg-blue-200">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="removeDispenseItem(${i})" class="px-2 py-1 text-xs bg-red-100 text-red-600 rounded hover:bg-red-200">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            } else {
                // Non-medicine display (simple)
                return `
                <div class="p-3 hover:bg-gray-50 border-b">
                    <div class="flex items-start gap-2">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-sm text-gray-800">${escapeHtml(item.name)}</span>
                                <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">📦 สินค้าทั่วไป</span>
                            </div>
                            ${item.notes ? `<div class="mt-2 text-xs text-gray-600">📝 คำแนะนำ: ${escapeHtml(item.notes)}</div>` : ''}
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-sm font-bold text-purple-600">฿${(item.price * item.qty).toLocaleString()}</div>
                            <div class="text-xs text-gray-500">${item.qty} ${item.unit}</div>
                            <div class="flex gap-1 mt-2">
                                <button onclick="openMedicineDetail(${i})" class="px-2 py-1 text-xs bg-blue-100 text-blue-600 rounded hover:bg-blue-200">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="removeDispenseItem(${i})" class="px-2 py-1 text-xs bg-red-100 text-red-600 rounded hover:bg-red-200">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
            }
        }).join('');
    }
    
    document.getElementById('itemCount').textContent = dispenseItems.length + ' รายการ';
    const total = dispenseItems.reduce((sum, item) => sum + (item.price * item.qty), 0);
    document.getElementById('dispenseTotal').textContent = '฿' + total.toLocaleString();
}

async function submitDispense() {
    console.log('submitDispense called, items:', dispenseItems);
    
    if (dispenseItems.length === 0) {
        alert('กรุณาเลือกรายการสินค้า');
        return;
    }
    
    // Check if medicine items have indication (only for items marked as medicine)
    const missingIndication = dispenseItems.filter(i => i.isMedicine !== false && !i.indication);
    if (missingIndication.length > 0) {
        if (!confirm(`มี ${missingIndication.length} รายการยาที่ยังไม่ได้ระบุข้อบ่งใช้ ต้องการดำเนินการต่อหรือไม่?`)) {
            return;
        }
    }
    
    if (!confirm('ยืนยันการจ่ายสินค้า?')) return;
    
    const total = dispenseItems.reduce((sum, item) => sum + (item.price * item.qty), 0);
    
    console.log('Submitting dispense:', { userId, items: dispenseItems, total });
    
    try {
        const formData = new FormData();
        formData.append('action', 'dispense');
        formData.append('user_id', userId);
        formData.append('items', JSON.stringify(dispenseItems));
        formData.append('total_amount', total);
        formData.append('payment_method', document.getElementById('paymentMethod')?.value || 'cash');
        formData.append('shop_name', document.getElementById('dispenseShopName')?.value || 'ร้านยา');
        formData.append('pharmacist_name', document.getElementById('dispensePharmacist')?.value || '');
        
        const res = await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const text = await res.text();
        console.log('Response:', text);
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e, 'Response:', text);
            alert('เกิดข้อผิดพลาด: ไม่สามารถอ่านข้อมูลจากเซิร์ฟเวอร์');
            return;
        }
        
        if (data.success) {
            alert('จ่ายยาสำเร็จ! เลขที่: ' + data.order_number);
            closeDispenseModal();
            dispenseItems = [];
            updateDispenseUI();
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
        }
    } catch (err) { 
        console.error('Fetch error:', err); 
        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
    }
}

document.getElementById('medicineDetailModal')?.addEventListener('click', (e) => { if(e.target.id === 'medicineDetailModal') closeMedicineDetail(); });

document.getElementById('dispenseModal')?.addEventListener('click', (e) => { if(e.target.id === 'dispenseModal') closeDispenseModal(); });

// ===== Real-time Message Polling =====
let lastMessageId = <?php 
    $lastMsg = !empty($messages) ? end($messages) : null;
    echo $lastMsg ? ($lastMsg['id'] ?? 0) : 0;
?>;
let pollingInterval = null;

async function pollNewMessages() {
    if (!userId) return;
    
    try {
        const res = await fetch(`api/ajax_handler.php?action=get_messages&user_id=${userId}&last_id=${lastMessageId}`);
        const data = await res.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            hideTypingIndicator();
            
            data.messages.forEach(msg => {
                if (msg.id > lastMessageId) {
                    lastMessageId = msg.id;
                    
                    const msgType = msg.message_type || 'text';
                    const content = msg.content || '';
                    const direction = msg.direction || 'incoming';
                    
                    // Parse extra info
                    let extra = {};
                    const sentBy = msg.sent_by || '';
                    extra.sentBy = sentBy;
                    
                    // Check if AI response
                    if (direction === 'outgoing' && (sentBy === 'ai' || sentBy === 'AI')) {
                        extra.isAI = true;
                    }
                    
                    // Parse sticker info
                    if (msgType === 'sticker') {
                        const match = content.match(/Sticker:\s*(\d+)/);
                        if (match) extra.stickerId = match[1];
                        try {
                            const json = JSON.parse(content);
                            if (json.stickerId) extra.stickerId = json.stickerId;
                        } catch(e) {}
                    }
                    
                    // Parse image URL or ID
                    let displayContent = content;
                    if (msgType === 'image') {
                        const idMatch = content.match(/ID:\s*(\d+)/);
                        if (idMatch) {
                            displayContent = `api/line_content.php?id=${idMatch[1]}`;
                        }
                    }
                    
                    appendMessage(displayContent, direction, msgType, extra);
                }
            });
        }
    } catch (err) {
        console.error('Polling error:', err);
    }
}

// Start polling when page loads
if (userId) {
    pollingInterval = setInterval(pollNewMessages, 3000); // Poll every 3 seconds
}

// Stop polling when leaving page
window.addEventListener('beforeunload', () => {
    if (pollingInterval) clearInterval(pollingInterval);
});

// Image Modal
function openImageModal(src) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4';
    modal.onclick = () => modal.remove();
    modal.innerHTML = `
        <div class="relative max-w-4xl max-h-[90vh]">
            <img src="${src}" class="max-w-full max-h-[90vh] object-contain rounded-lg shadow-2xl">
            <button onclick="event.stopPropagation(); this.parentElement.parentElement.remove();" class="absolute top-2 right-2 w-8 h-8 bg-white/20 hover:bg-white/40 rounded-full flex items-center justify-center text-white">
                <i class="fas fa-times"></i>
            </button>
            <a href="${src}" download class="absolute bottom-2 right-2 px-3 py-1.5 bg-white/20 hover:bg-white/40 rounded-lg text-white text-sm" onclick="event.stopPropagation();">
                <i class="fas fa-download mr-1"></i>ดาวน์โหลด
            </a>
        </div>
    `;
    document.body.appendChild(modal);
}

// Video Player
function playVideo(src) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4';
    modal.onclick = () => modal.remove();
    modal.innerHTML = `
        <div class="relative max-w-2xl w-full">
            <video src="${src}" controls autoplay class="w-full rounded-lg shadow-2xl"></video>
            <button onclick="event.stopPropagation(); this.parentElement.parentElement.remove();" class="absolute top-2 right-2 w-8 h-8 bg-white/20 hover:bg-white/40 rounded-full flex items-center justify-center text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    document.body.appendChild(modal);
}

// ==================== AI REPLY FUNCTIONS ====================
let pendingAIMessage = null;

async function aiReply() {
    if (!userId) { alert('กรุณาเลือกลูกค้าก่อน'); return; }
    
    // Get last incoming message
    const incomingMsgs = document.querySelectorAll('.chat-incoming');
    let lastMessage = '';
    if (incomingMsgs.length > 0) {
        lastMessage = incomingMsgs[incomingMsgs.length - 1].textContent.trim();
    }
    
    if (!lastMessage) {
        alert('ไม่พบข้อความจากลูกค้า');
        return;
    }
    
    const btn = document.getElementById('aiReplyBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังคิด...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'ai_reply');
        formData.append('user_id', userId);
        formData.append('last_message', lastMessage);
        
        const res = await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        
        if (data.success && data.message) {
            pendingAIMessage = { type: 'text', text: data.message };
            showAIPreviewModal(data);
        } else {
            alert(data.error || 'ไม่สามารถสร้างคำตอบได้');
        }
    } catch (err) {
        alert('เกิดข้อผิดพลาด: ' + err.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function showAIPreviewModal(data) {
    const msg = data.message || '';
    const settings = data.ai_settings || {};
    
    // Build preview HTML
    let previewHtml = '<div class="bg-gray-50 rounded-lg p-4 mb-4">';
    
    // Sender info
    if (settings.sender_name) {
        previewHtml += '<div class="flex items-center gap-2 mb-2">';
        if (settings.sender_icon) {
            previewHtml += `<img src="${escapeHtml(settings.sender_icon)}" class="w-8 h-8 rounded-full" onerror="this.style.display='none'">`;
        }
        previewHtml += `<span class="font-medium text-sm">${escapeHtml(settings.sender_name)}</span>`;
        previewHtml += '</div>';
    }
    
    // Message content
    previewHtml += '<div class="bg-white rounded-lg p-3 shadow-sm border whitespace-pre-wrap">';
    previewHtml += escapeHtml(msg);
    previewHtml += '</div>';
    previewHtml += '</div>';
    
    // Create modal
    const modal = document.createElement('div');
    modal.id = 'aiPreviewModal';
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    modal.innerHTML = `
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-bold text-lg">🤖 Preview AI Reply</h3>
                <button onclick="closeAIPreviewModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-4">
                ${previewHtml}
                <div class="text-xs text-gray-500 mb-4">
                    <i class="fas fa-info-circle mr-1"></i>
                    จะลอง Reply Token ก่อน ถ้าหมดอายุจะใช้ Push Message
                </div>
            </div>
            <div class="p-4 border-t flex gap-2">
                <button onclick="closeAIPreviewModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">
                    ยกเลิก
                </button>
                <button onclick="editAIReply()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">
                    <i class="fas fa-edit mr-1"></i>แก้ไข
                </button>
                <button onclick="sendAIReply()" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-paper-plane mr-1"></i>ส่ง
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Close on backdrop click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeAIPreviewModal();
    });
}

function closeAIPreviewModal() {
    const modal = document.getElementById('aiPreviewModal');
    if (modal) modal.remove();
}

function editAIReply() {
    if (pendingAIMessage && pendingAIMessage.text) {
        document.getElementById('messageInput').value = pendingAIMessage.text;
        document.getElementById('messageInput').focus();
    }
    closeAIPreviewModal();
    pendingAIMessage = null;
}

async function sendAIReply() {
    if (!pendingAIMessage) return;
    
    const modal = document.getElementById('aiPreviewModal');
    const sendBtn = modal.querySelector('button:last-child');
    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังส่ง...';
    sendBtn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_ai_reply');
        formData.append('user_id', userId);
        formData.append('message', JSON.stringify(pendingAIMessage));
        
        const res = await fetch('chat.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await res.json();
        
        if (data.success) {
            // Add message to chat with AI indicator
            appendMessage(data.content, 'outgoing', 'text', { isAI: true });
            
            closeAIPreviewModal();
            pendingAIMessage = null;
        } else {
            alert(data.error || 'ส่งข้อความไม่สำเร็จ');
        }
    } catch (err) {
        alert('เกิดข้อผิดพลาด: ' + err.message);
    } finally {
        if (sendBtn) {
            sendBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>ส่ง';
            sendBtn.disabled = false;
        }
    }
}
</script>

<style>
/* Typing indicator animation */
.typing-dots span {
    animation: bounce 1.4s infinite ease-in-out both;
}
.typing-dots span:nth-child(1) { animation-delay: -0.32s; }
.typing-dots span:nth-child(2) { animation-delay: -0.16s; }

@keyframes bounce {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
}

/* Loading overlay for AI processing */
.ai-loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.ai-loading-spinner {
    background: white;
    padding: 24px 32px;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    text-align: center;
}

.ai-loading-spinner .spinner {
    width: 40px;
    height: 40px;
    border: 3px solid #e5e7eb;
    border-top-color: #10B981;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 12px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<?php require_once 'includes/footer.php'; ?>
