<?php
/**
 * AJAX Handler - API สำหรับ Real-time Actions
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Ensure clean output
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Error handler to catch all errors
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $error['message']]);
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/DripCampaignService.php';
require_once __DIR__ . '/../classes/LinkTrackingService.php';

// Load LineAccountManager if exists
$lineManager = null;
if (file_exists(__DIR__ . '/../classes/LineAccountManager.php')) {
    require_once __DIR__ . '/../classes/LineAccountManager.php';
    try {
        $db = Database::getInstance()->getConnection();
        $lineManager = new LineAccountManager($db);
    } catch (Exception $e) {
        // LineAccountManager failed, continue without it
    }
}

if (!isset($db)) {
    $db = Database::getInstance()->getConnection();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentBotId = $_SESSION['current_bot_id'] ?? null;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Handle JSON input for bulk actions
$jsonInput = null;
if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonInput = json_decode($rawInput, true);
        if ($jsonInput && isset($jsonInput['action'])) {
            $action = $jsonInput['action'];
        }
    }
}

try {
    switch ($action) {
        // ==================== BULK TAG ACTIONS ====================
        case 'bulk_assign_tag':
            $userIds = $jsonInput['user_ids'] ?? [];
            $tagId = (int) ($jsonInput['tag_id'] ?? 0);
            if (empty($userIds) || !$tagId)
                throw new Exception('Missing required fields');

            $count = 0;
            $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'bulk')");
            foreach ($userIds as $userId) {
                $stmt->execute([(int) $userId, $tagId]);
                if ($stmt->rowCount() > 0)
                    $count++;
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'count' => $count, 'message' => "เพิ่ม Tag ให้ {$count} คนสำเร็จ"]);
            break;

        case 'bulk_remove_tag':
            $userIds = $jsonInput['user_ids'] ?? [];
            $tagId = (int) ($jsonInput['tag_id'] ?? 0);
            if (empty($userIds) || !$tagId)
                throw new Exception('Missing required fields');

            $count = 0;
            $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            foreach ($userIds as $userId) {
                $stmt->execute([(int) $userId, $tagId]);
                if ($stmt->rowCount() > 0)
                    $count++;
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'count' => $count, 'message' => "ลบ Tag จาก {$count} คนสำเร็จ"]);
            break;

        case 'send_message':
            $userId = (int) $_POST['user_id'];
            $message = trim($_POST['message'] ?? '');
            if (!$userId || !$message)
                throw new Exception('Missing required fields');
            $stmt = $db->prepare("SELECT line_user_id, reply_token, reply_token_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user)
                throw new Exception('User not found');
            $line = $lineManager->getLineAPI($currentBotId);
            // ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) - with fallback
            if (method_exists($line, 'sendMessage')) {
                $result = $line->sendMessage($user['line_user_id'], $message, $user['reply_token'] ?? null, $user['reply_token_expires'] ?? null, $db, $userId);
            } else {
                $result = $line->pushMessage($user['line_user_id'], [['type' => 'text', 'text' => $message]]);
                $result['method'] = 'push';
            }
            if ($result['code'] !== 200)
                throw new Exception('Failed to send message');
            $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, is_read) VALUES (?, ?, 'outgoing', 'text', ?, 1)");
            $stmt->execute([$currentBotId, $userId, $message]);
            $msgId = $db->lastInsertId();

            // Log activity
            require_once __DIR__ . '/../classes/ActivityLogger.php';
            $activityLogger = ActivityLogger::getInstance($db);
            $activityLogger->logMessage(ActivityLogger::ACTION_SEND, 'ส่งข้อความถึงลูกค้า', [
                'user_id' => $userId,
                'message_id' => $msgId,
                'message_type' => 'text',
                'content' => $message
            ]);

            echo json_encode(['success' => true, 'message_id' => $msgId, 'content' => $message, 'time' => date('H:i'), 'method' => $result['method'] ?? 'push']);
            break;

        case 'get_messages':
            $userId = (int) $_GET['user_id'];
            $lastId = (int) ($_GET['last_id'] ?? 0);
            if (!$userId)
                throw new Exception('Missing user_id');
            $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction = 'incoming' AND is_read = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$userId, $currentBotId]);
            $sql = "SELECT * FROM messages WHERE user_id = ? AND (line_account_id = ? OR line_account_id IS NULL)";
            $params = [$userId, $currentBotId];
            if ($lastId > 0) {
                $sql .= " AND id > ?";
                $params[] = $lastId;
            }
            $sql .= " ORDER BY created_at ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'assign_tag':
            $userId = (int) $_POST['user_id'];
            $tagId = (int) $_POST['tag_id'];
            if (!$userId || !$tagId)
                throw new Exception('Missing required fields');
            $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')");
            $stmt->execute([$userId, $tagId]);
            $stmt = $db->prepare("SELECT * FROM user_tags WHERE id = ?");
            $stmt->execute([$tagId]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);

            // Log activity
            require_once __DIR__ . '/../classes/ActivityLogger.php';
            $activityLogger = ActivityLogger::getInstance($db);
            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ติด Tag ลูกค้า', [
                'entity_type' => 'user_tag',
                'entity_id' => $tagId,
                'user_id' => $userId, // The user being tagged
                'extra_data' => ['tag_name' => $tag['name'] ?? '']
            ]);

            echo json_encode(['success' => true, 'tag' => $tag, 'message' => 'เพิ่ม Tag สำเร็จ']);
            break;

        case 'remove_tag':
            $userId = (int) $_POST['user_id'];
            $tagId = (int) $_POST['tag_id'];
            if (!$userId || !$tagId)
                throw new Exception('Missing required fields');
            $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            $stmt->execute([$userId, $tagId]);
            echo json_encode(['success' => true, 'message' => 'ลบ Tag สำเร็จ']);
            break;

        case 'get_user_tags':
            $userId = (int) $_GET['user_id'];
            $stmt = $db->prepare("SELECT t.* FROM user_tags t JOIN user_tag_assignments a ON t.id = a.tag_id WHERE a.user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'tags' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'create_tag':
            $name = trim($_POST['name'] ?? '');
            $color = $_POST['color'] ?? '#3B82F6';
            $description = trim($_POST['description'] ?? '');
            if (!$name)
                throw new Exception('กรุณาระบุชื่อ Tag');

            // Check if user_tags table exists
            try {
                $db->query("SELECT 1 FROM user_tags LIMIT 1");
            } catch (Exception $e) {
                // Create table if not exists
                $db->exec("CREATE TABLE IF NOT EXISTS user_tags (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT DEFAULT NULL,
                    name VARCHAR(100) NOT NULL,
                    color VARCHAR(7) DEFAULT '#3B82F6',
                    description TEXT,
                    auto_assign_rules JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_line_account (line_account_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }

            $stmt = $db->prepare("INSERT INTO user_tags (line_account_id, name, color, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$currentBotId, $name, $color, $description]);
            ob_end_clean();
            echo json_encode(['success' => true, 'tag_id' => $db->lastInsertId(), 'message' => 'สร้าง Tag สำเร็จ']);
            break;

        case 'update_tag':
            $tagId = (int) $_POST['tag_id'];
            $name = trim($_POST['name'] ?? '');
            $color = $_POST['color'] ?? '#3B82F6';
            $description = trim($_POST['description'] ?? '');
            if (!$tagId || !$name)
                throw new Exception('กรุณาระบุข้อมูลให้ครบ');
            $stmt = $db->prepare("UPDATE user_tags SET name = ?, color = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $color, $description, $tagId]);
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'อัพเดท Tag สำเร็จ']);
            break;

        case 'delete_tag':
            $tagId = (int) $_POST['tag_id'];
            // Delete assignments first
            try {
                $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE tag_id = ?");
                $stmt->execute([$tagId]);
            } catch (Exception $e) {
            }
            $stmt = $db->prepare("DELETE FROM user_tags WHERE id = ?");
            $stmt->execute([$tagId]);
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'ลบ Tag สำเร็จ']);
            break;

        case 'get_unread_count':
            $stmt = $db->prepare("SELECT user_id, COUNT(*) as count FROM messages WHERE direction = 'incoming' AND (is_read = 0 OR is_read IS NULL) AND (line_account_id = ? OR line_account_id IS NULL) GROUP BY user_id");
            $stmt->execute([$currentBotId]);
            echo json_encode(['success' => true, 'counts' => $stmt->fetchAll(PDO::FETCH_KEY_PAIR)]);
            break;

        case 'get_order':
            $orderId = (int) ($_GET['order_id'] ?? 0);
            if (!$orderId)
                throw new Exception('Missing order_id');
            $stmt = $db->prepare("SELECT o.*, u.display_name, u.picture_url, u.line_user_id FROM transactions o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? AND (o.line_account_id = ? OR o.line_account_id IS NULL)");
            $stmt->execute([$orderId, $currentBotId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order)
                throw new Exception('Order not found');
            $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("SELECT * FROM payment_slips WHERE transaction_id = ? ORDER BY created_at DESC");
            $stmt->execute([$orderId]);
            echo json_encode(['success' => true, 'order' => $order, 'items' => $items, 'slips' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'update_order_status':
            $orderId = (int) $_POST['order_id'];
            $newStatus = $_POST['status'] ?? '';
            $tracking = trim($_POST['tracking'] ?? '');
            if (!$orderId || !$newStatus)
                throw new Exception('Missing required fields');
            $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM transactions o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? AND (o.line_account_id = ? OR o.line_account_id IS NULL)");
            $stmt->execute([$orderId, $currentBotId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order)
                throw new Exception('Order not found');

            // ถ้าเปลี่ยนเป็น cancelled ให้คืนสต็อก
            if ($newStatus === 'cancelled' && $order['status'] !== 'cancelled') {
                $stmt = $db->prepare("SELECT product_id, quantity FROM transaction_items WHERE transaction_id = ?");
                $stmt->execute([$orderId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    // คืนสต็อก
                    $stmt = $db->prepare("UPDATE business_items SET stock = stock + ? WHERE id = ?");
                    $stmt->execute([$item['quantity'], $item['product_id']]);

                    // บันทึก stock movement
                    try {
                        $stmtStock = $db->prepare("SELECT stock, name FROM business_items WHERE id = ?");
                        $stmtStock->execute([$item['product_id']]);
                        $product = $stmtStock->fetch(PDO::FETCH_ASSOC);

                        $stmt = $db->prepare("
                            INSERT INTO stock_movements 
                            (line_account_id, product_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, reference_number, notes)
                            VALUES (?, ?, 'return', ?, ?, ?, 'order', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $order['line_account_id'],
                            $item['product_id'],
                            $item['quantity'],
                            $product['stock'] - $item['quantity'],
                            $product['stock'],
                            $orderId,
                            $order['order_number'],
                            'คืนสต็อก (ยกเลิกออเดอร์): ' . ($product['name'] ?? '')
                        ]);
                    } catch (Exception $e) {
                        // stock_movements table might not exist
                    }
                }
            }

            $stmt = $db->prepare("UPDATE transactions SET status = ?, shipping_tracking = ? WHERE id = ?");
            $stmt->execute([$newStatus, $tracking, $orderId]);
            if ($order['line_user_id']) {
                $lineAccount = $lineManager->getAccount($currentBotId);
                $statusMessages = [
                    'confirmed' => "✅ คำสั่งซื้อ #{$order['order_number']} ได้รับการยืนยันแล้ว",
                    'paid' => "💰 ได้รับการชำระเงินคำสั่งซื้อ #{$order['order_number']} แล้ว",
                    'shipping' => "🚚 คำสั่งซื้อ #{$order['order_number']} กำลังจัดส่ง" . ($tracking ? "\nเลขพัสดุ: $tracking" : ''),
                    'delivered' => "📦 คำสั่งซื้อ #{$order['order_number']} จัดส่งสำเร็จแล้ว",
                    'cancelled' => "❌ คำสั่งซื้อ #{$order['order_number']} ถูกยกเลิก"
                ];
                if (isset($statusMessages[$newStatus]) && $lineAccount) {
                    $lineApi = new LineAPI($lineAccount['channel_access_token']);
                    // ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) - with fallback
                    if (method_exists($lineApi, 'sendMessage')) {
                        $lineApi->sendMessage($order['line_user_id'], ['type' => 'text', 'text' => $statusMessages[$newStatus]], $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
                    } else {
                        $lineApi->pushMessage($order['line_user_id'], [['type' => 'text', 'text' => $statusMessages[$newStatus]]]);
                    }
                }
            }


            // Log activity
            require_once __DIR__ . '/../classes/ActivityLogger.php';
            $activityLogger = ActivityLogger::getInstance($db);
            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'อัพเดทสถานะคำสั่งซื้อ', [
                'entity_type' => 'transaction',
                'entity_id' => $orderId,
                'user_id' => $order['user_id'] ?? null,
                'extra_data' => [
                    'order_number' => $order['order_number'],
                    'status' => $newStatus,
                    'tracking' => $tracking
                ]
            ]);

            echo json_encode(['success' => true, 'message' => 'อัพเดทสถานะสำเร็จ', 'status' => $newStatus]);
            break;

        case 'approve_slip':
            $orderId = (int) $_POST['order_id'];
            $slipId = (int) $_POST['slip_id'];
            if (!$orderId || !$slipId)
                throw new Exception('Missing required fields');
            $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM transactions o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? AND (o.line_account_id = ? OR o.line_account_id IS NULL)");
            $stmt->execute([$orderId, $currentBotId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order)
                throw new Exception('Order not found');
            $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved' WHERE id = ? AND transaction_id = ?");
            $stmt->execute([$slipId, $orderId]);
            $stmt = $db->prepare("UPDATE transactions SET payment_status = 'paid', status = 'paid' WHERE id = ?");
            $stmt->execute([$orderId]);
            if ($order['line_user_id']) {
                $lineAccount = $lineManager->getAccount($currentBotId);
                if ($lineAccount) {
                    $lineApi = new LineAPI($lineAccount['channel_access_token']);
                    // ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) - with fallback
                    $msgText = "✅ ยืนยันการชำระเงินคำสั่งซื้อ #{$order['order_number']} แล้ว";
                    if (method_exists($lineApi, 'sendMessage')) {
                        $lineApi->sendMessage($order['line_user_id'], ['type' => 'text', 'text' => $msgText], $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
                    } else {
                        $lineApi->pushMessage($order['line_user_id'], [['type' => 'text', 'text' => $msgText]]);
                    }
                }
            }


            // Log activity
            require_once __DIR__ . '/../classes/ActivityLogger.php';
            $activityLogger = ActivityLogger::getInstance($db);
            $activityLogger->logData(ActivityLogger::ACTION_APPROVE, 'อนุมัติการชำระเงิน', [
                'entity_type' => 'payment_slip',
                'entity_id' => $slipId,
                'user_id' => $order['user_id'] ?? null,
                'extra_data' => ['order_id' => $orderId, 'order_number' => $order['order_number']]
            ]);

            echo json_encode(['success' => true, 'message' => 'ยืนยันการชำระเงินสำเร็จ']);
            break;

        case 'reject_slip':
            $orderId = (int) $_POST['order_id'];
            $slipId = (int) $_POST['slip_id'];
            if (!$orderId || !$slipId)
                throw new Exception('Missing required fields');
            $stmt = $db->prepare("UPDATE payment_slips SET status = 'rejected' WHERE id = ? AND transaction_id = ?");
            $stmt->execute([$slipId, $orderId]);

            // Log activity
            require_once __DIR__ . '/../classes/ActivityLogger.php';
            $activityLogger = ActivityLogger::getInstance($db);
            $activityLogger->logData(ActivityLogger::ACTION_REJECT, 'ปฏิเสธการชำระเงิน', [
                'entity_type' => 'payment_slip',
                'entity_id' => $slipId,
                'extra_data' => ['order_id' => $orderId]
            ]);

            echo json_encode(['success' => true, 'message' => 'ปฏิเสธหลักฐานการชำระเงินแล้ว']);
            break;

        case 'create_product':
        case 'update_product':
            $name = trim($_POST['name'] ?? '');
            $price = (float) ($_POST['price'] ?? 0);
            $salePrice = !empty($_POST['sale_price']) ? (float) $_POST['sale_price'] : null;
            $stock = (int) ($_POST['stock'] ?? 0);
            $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
            $description = trim($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $imageUrl = $_POST['image_url'] ?? '';
            if (empty($name) || $price <= 0)
                throw new Exception('กรุณากรอกชื่อสินค้าและราคา');
            if ($action === 'create_product') {
                $stmt = $db->prepare("INSERT INTO business_items (line_account_id, category_id, name, price, sale_price, stock, description, image_url, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$currentBotId, $categoryId, $name, $price, $salePrice, $stock, $description, $imageUrl, $isActive]);
                echo json_encode(['success' => true, 'product_id' => $db->lastInsertId(), 'message' => 'เพิ่มสินค้าสำเร็จ']);
            } else {
                $id = (int) $_POST['id'];
                $stmt = $db->prepare("UPDATE business_items SET category_id = ?, name = ?, price = ?, sale_price = ?, stock = ?, description = ?, image_url = ?, is_active = ? WHERE id = ? AND line_account_id = ?");
                $stmt->execute([$categoryId, $name, $price, $salePrice, $stock, $description, $imageUrl, $isActive, $id, $currentBotId]);
                echo json_encode(['success' => true, 'message' => 'อัพเดทสินค้าสำเร็จ']);
            }
            break;

        case 'delete_product':
            $id = (int) $_POST['id'];
            if (!$id)
                throw new Exception('Missing product id');
            $stmt = $db->prepare("DELETE FROM business_items WHERE id = ? AND line_account_id = ?");
            $stmt->execute([$id, $currentBotId]);
            echo json_encode(['success' => true, 'message' => 'ลบสินค้าสำเร็จ']);
            break;

        case 'upload_product_image':
            if (empty($_FILES['image']['name']))
                throw new Exception('No image uploaded');
            $uploadDir = __DIR__ . '/../uploads/products/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed))
                throw new Exception('ไฟล์รูปภาพไม่ถูกต้อง');
            $filename = 'product_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $filename)) {
                echo json_encode(['success' => true, 'image_url' => BASE_URL . '/uploads/products/' . $filename]);
            } else {
                throw new Exception('อัพโหลดรูปภาพไม่สำเร็จ');
            }
            break;

        case 'create_category':
        case 'update_category':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $imageUrl = trim($_POST['image_url'] ?? '');
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;
            if (empty($name))
                throw new Exception('กรุณากรอกชื่อหมวดหมู่');

            // Detect table
            $catTable = 'product_categories';
            try {
                $db->query("SELECT 1 FROM item_categories LIMIT 1");
                $catTable = 'item_categories';
            } catch (Exception $e) {
            }

            if ($action === 'create_category') {
                $stmt = $db->prepare("INSERT INTO {$catTable} (line_account_id, name, description, image_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$currentBotId, $name, $description, $imageUrl, $sortOrder, $isActive]);
                ob_end_clean();
                echo json_encode(['success' => true, 'category_id' => $db->lastInsertId(), 'message' => 'เพิ่มหมวดหมู่สำเร็จ']);
            } else {
                $id = (int) $_POST['id'];
                $stmt = $db->prepare("UPDATE {$catTable} SET name = ?, description = ?, image_url = ?, sort_order = ?, is_active = ? WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$name, $description, $imageUrl, $sortOrder, $isActive, $id, $currentBotId]);
                ob_end_clean();
                echo json_encode(['success' => true, 'message' => 'อัพเดทหมวดหมู่สำเร็จ']);
            }
            break;

        case 'delete_category':
            $id = (int) $_POST['id'];
            if (!$id)
                throw new Exception('Missing category id');

            // Detect table
            $catTable = 'product_categories';
            try {
                $db->query("SELECT 1 FROM item_categories LIMIT 1");
                $catTable = 'item_categories';
            } catch (Exception $e) {
            }

            $stmt = $db->prepare("DELETE FROM {$catTable} WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$id, $currentBotId]);
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'ลบหมวดหมู่สำเร็จ']);
            break;

        case 'create_auto_reply':
        case 'update_auto_reply':
            $keyword = trim($_POST['keyword'] ?? '');
            $matchType = $_POST['match_type'] ?? 'contains';
            $replyContent = trim($_POST['reply_content'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if (empty($keyword) || empty($replyContent))
                throw new Exception('กรุณากรอกข้อมูลให้ครบ');
            if ($action === 'create_auto_reply') {
                $stmt = $db->prepare("INSERT INTO auto_replies (line_account_id, keyword, match_type, reply_content, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$currentBotId, $keyword, $matchType, $replyContent, $isActive]);
                echo json_encode(['success' => true, 'rule_id' => $db->lastInsertId(), 'message' => 'เพิ่มกฎตอบกลับสำเร็จ']);
            } else {
                $id = (int) $_POST['id'];
                $stmt = $db->prepare("UPDATE auto_replies SET keyword = ?, match_type = ?, reply_content = ?, is_active = ? WHERE id = ? AND line_account_id = ?");
                $stmt->execute([$keyword, $matchType, $replyContent, $isActive, $id, $currentBotId]);
                echo json_encode(['success' => true, 'message' => 'อัพเดทกฎตอบกลับสำเร็จ']);
            }
            break;

        case 'delete_auto_reply':
            $id = (int) $_POST['id'];
            if (!$id)
                throw new Exception('Missing rule id');
            $stmt = $db->prepare("DELETE FROM auto_replies WHERE id = ? AND line_account_id = ?");
            $stmt->execute([$id, $currentBotId]);
            echo json_encode(['success' => true, 'message' => 'ลบกฎตอบกลับสำเร็จ']);
            break;

        case 'toggle_auto_reply':
            $id = (int) $_POST['id'];
            if (!$id)
                throw new Exception('Missing rule id');
            $stmt = $db->prepare("UPDATE auto_replies SET is_active = NOT is_active WHERE id = ? AND line_account_id = ?");
            $stmt->execute([$id, $currentBotId]);
            $stmt = $db->prepare("SELECT is_active FROM auto_replies WHERE id = ?");
            $stmt->execute([$id]);
            $newStatus = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'is_active' => (bool) $newStatus, 'message' => $newStatus ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว']);
            break;

        // ==================== DRIP CAMPAIGNS ====================
        case 'drip_list_campaigns':
            $service = new DripCampaignService($db, $currentBotId);
            $campaigns = $service->listCampaignsWithStats();
            $summary = $service->getQueueSummary();
            ob_end_clean();
            echo json_encode(['success' => true, 'campaigns' => $campaigns, 'summary' => $summary]);
            break;

        case 'drip_create_campaign':
            $service = new DripCampaignService($db, $currentBotId);
            $payload = $jsonInput ?? $_POST;
            $name = trim($payload['name'] ?? '');
            $triggerType = $payload['trigger_type'] ?? 'follow';
            if (!$name) throw new Exception('กรุณาระบุชื่อ Campaign');
            $campaign = $service->createCampaign($name, $triggerType, $payload['trigger_config'] ?? null);
            ob_end_clean();
            echo json_encode(['success' => true, 'campaign' => $campaign]);
            break;

        case 'drip_update_campaign':
            $service = new DripCampaignService($db, $currentBotId);
            $payload = $jsonInput ?? $_POST;
            $campaignId = (int) ($payload['campaign_id'] ?? 0);
            if (!$campaignId) throw new Exception('ไม่พบ Campaign');
            $campaign = $service->updateCampaign($campaignId, $payload);
            ob_end_clean();
            echo json_encode(['success' => true, 'campaign' => $campaign]);
            break;

        case 'drip_toggle_campaign':
            $service = new DripCampaignService($db, $currentBotId);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if (!$campaignId) throw new Exception('ไม่พบ Campaign');
            $campaign = $service->toggleCampaign($campaignId);
            ob_end_clean();
            echo json_encode(['success' => true, 'campaign' => $campaign]);
            break;

        case 'drip_delete_campaign':
            $service = new DripCampaignService($db, $currentBotId);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if (!$campaignId) throw new Exception('ไม่พบ Campaign');
            $campaign = $service->deleteCampaign($campaignId);
            ob_end_clean();
            echo json_encode(['success' => true, 'deleted' => $campaign]);
            break;

        case 'drip_add_step':
            $service = new DripCampaignService($db, $currentBotId);
            $payload = $jsonInput ?? $_POST;
            $campaignId = (int) ($payload['campaign_id'] ?? 0);
            if (!$campaignId) throw new Exception('ไม่พบ Campaign');
            if (isset($payload['delay_value'], $payload['delay_unit'])) {
                $payload['delay_minutes'] = (int) $payload['delay_value'] * (int) $payload['delay_unit'];
            }
            $step = $service->addStep($campaignId, $payload);
            ob_end_clean();
            echo json_encode(['success' => true, 'step' => $step]);
            break;

        case 'drip_delete_step':
            $service = new DripCampaignService($db, $currentBotId);
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            $stepId = (int) ($_POST['step_id'] ?? 0);
            if (!$campaignId || !$stepId) throw new Exception('ข้อมูลไม่ครบ');
            $service->deleteStep($campaignId, $stepId);
            ob_end_clean();
            echo json_encode(['success' => true]);
            break;

        // ==================== LINK TRACKING ====================
        case 'create_link':
            $service = new LinkTrackingService($db, $currentBotId);
            $payload = $jsonInput ?? $_POST;
            $url = trim($payload['url'] ?? '');
            $title = trim($payload['title'] ?? '');
            if (!$url) throw new Exception('กรุณาระบุ URL');
            if (!filter_var($url, FILTER_VALIDATE_URL)) throw new Exception('URL ไม่ถูกต้อง');
            $link = $service->createLink($url, $title);
            ob_end_clean();
            echo json_encode(['success' => true, 'link' => $link]);
            break;

        case 'update_link':
            $service = new LinkTrackingService($db, $currentBotId);
            $payload = $jsonInput ?? $_POST;
            $linkId = (int) ($payload['link_id'] ?? 0);
            $url = trim($payload['url'] ?? '');
            $title = trim($payload['title'] ?? '');
            if (!$linkId || !$url) throw new Exception('กรุณาระบุข้อมูลให้ครบ');
            if (!filter_var($url, FILTER_VALIDATE_URL)) throw new Exception('URL ไม่ถูกต้อง');
            $link = $service->updateLink($linkId, $url, $title);
            ob_end_clean();
            echo json_encode(['success' => true, 'link' => $link]);
            break;

        case 'delete_link':
            $service = new LinkTrackingService($db, $currentBotId);
            $linkId = (int) ($_POST['link_id'] ?? 0);
            if (!$linkId) throw new Exception('Missing link_id');
            $service->deleteLink($linkId);
            ob_end_clean();
            echo json_encode(['success' => true]);
            break;

        case 'bulk_delete_links':
            $service = new LinkTrackingService($db, $currentBotId);
            $payload = $jsonInput ?? $_POST;
            $ids = $payload['link_ids'] ?? [];
            $deleted = $service->deleteLinks(is_array($ids) ? $ids : []);
            ob_end_clean();
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;

        case 'save_gemini_key':
            $apiKey = trim($_POST['api_key'] ?? '');

            // 1. Save to ai_settings table (primary)
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS ai_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT DEFAULT NULL,
                    setting_key VARCHAR(100) NOT NULL,
                    setting_value TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_setting (line_account_id, setting_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                if ($currentBotId) {
                    $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, gemini_api_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE gemini_api_key = ?");
                    $stmt->execute([$currentBotId, $apiKey, $apiKey]);
                }
            } catch (Exception $e) {
                // Fallback: try ai_chat_settings table
                try {
                    if ($currentBotId) {
                        $stmt = $db->prepare("INSERT INTO ai_chat_settings (line_account_id, gemini_api_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE gemini_api_key = ?");
                        $stmt->execute([$currentBotId, $apiKey, $apiKey]);
                    }
                } catch (Exception $e2) {
                }
            }

            // 2. Also save to line_accounts table (backup)
            if ($currentBotId) {
                try {
                    $stmt = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'gemini_api_key'");
                    if ($stmt->rowCount() === 0) {
                        $db->exec("ALTER TABLE line_accounts ADD COLUMN gemini_api_key VARCHAR(255) DEFAULT NULL");
                    }
                    $stmt = $db->prepare("UPDATE line_accounts SET gemini_api_key = ? WHERE id = ?");
                    $stmt->execute([$apiKey ?: null, $currentBotId]);
                } catch (Exception $e) {
                }
            }

            // 3. Also save to settings table (fallback)
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    `key` VARCHAR(100) NOT NULL UNIQUE,
                    value TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $db->prepare("INSERT INTO settings (`key`, value) VALUES ('gemini_api_key', ?) ON DUPLICATE KEY UPDATE value = ?");
                $stmt->execute([$apiKey, $apiKey]);
            } catch (Exception $e) {
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'บันทึก API Key สำเร็จ']);
            break;

        case 'get_products_for_ai':
            // Get products for AI knowledge base
            $products = [];
            try {
                $sql = "SELECT name, price, description FROM business_items WHERE is_active = 1";
                $params = [];

                if ($currentBotId) {
                    $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                    $params[] = $currentBotId;
                }

                $sql .= " ORDER BY id DESC LIMIT 50";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'products' => $products]);
            break;

        case 'get_business_info_for_ai':
            // Get comprehensive business info for AI knowledge base
            $businessInfo = [];
            $productKnowledge = '';

            try {
                // 1. Get shop settings
                $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY line_account_id DESC LIMIT 1");
                $stmt->execute([$currentBotId]);
                $shop = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($shop) {
                    $businessInfo[] = "ชื่อร้าน: " . ($shop['shop_name'] ?? 'ไม่ระบุ');
                    if (!empty($shop['shop_address']))
                        $businessInfo[] = "ที่อยู่: " . $shop['shop_address'];
                    if (!empty($shop['contact_phone']))
                        $businessInfo[] = "เบอร์โทร: " . $shop['contact_phone'];
                    if (!empty($shop['shop_email']))
                        $businessInfo[] = "อีเมล: " . $shop['shop_email'];
                    if (!empty($shop['line_id']))
                        $businessInfo[] = "LINE ID: " . $shop['line_id'];
                    if (!empty($shop['shipping_fee']))
                        $businessInfo[] = "ค่าส่ง: " . number_format($shop['shipping_fee']) . " บาท";
                    if (!empty($shop['free_shipping_min']))
                        $businessInfo[] = "ส่งฟรีเมื่อซื้อขั้นต่ำ: " . number_format($shop['free_shipping_min']) . " บาท";
                    if (!empty($shop['promptpay_number']))
                        $businessInfo[] = "พร้อมเพย์: " . $shop['promptpay_number'];

                    // Bank accounts
                    if (!empty($shop['bank_accounts'])) {
                        $banks = json_decode($shop['bank_accounts'], true);
                        if (!empty($banks['banks'])) {
                            $bankList = [];
                            foreach ($banks['banks'] as $bank) {
                                $bankList[] = $bank['name'] . " " . $bank['account'] . " (" . $bank['holder'] . ")";
                            }
                            $businessInfo[] = "บัญชีธนาคาร: " . implode(", ", $bankList);
                        }
                    }
                }

                // 2. Get LINE account info
                $stmt = $db->prepare("SELECT name, basic_id FROM line_accounts WHERE id = ?");
                $stmt->execute([$currentBotId]);
                $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($lineAccount) {
                    if (!empty($lineAccount['basic_id']))
                        $businessInfo[] = "LINE OA: " . $lineAccount['basic_id'];
                }

                // 3. Get categories
                $categories = [];
                try {
                    $stmt = $db->query("SELECT name FROM item_categories ORDER BY id LIMIT 20");
                    $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($cats)) {
                        $businessInfo[] = "หมวดหมู่สินค้า: " . implode(", ", $cats);
                    }
                } catch (Exception $e) {
                }

                // 4. Get products (top 30 bestsellers and featured)
                $sql = "SELECT name, price, sale_price, description, is_bestseller, is_featured 
                        FROM business_items 
                        WHERE is_active = 1";
                $params = [];
                if ($currentBotId) {
                    $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                    $params[] = $currentBotId;
                }
                $sql .= " ORDER BY is_bestseller DESC, is_featured DESC, id DESC LIMIT 30";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($products)) {
                    $productLines = ["สินค้าในร้าน:"];
                    $bestsellers = [];
                    $featured = [];

                    foreach ($products as $p) {
                        $price = !empty($p['sale_price']) ? $p['sale_price'] . " (ลดจาก " . $p['price'] . ")" : $p['price'];
                        $line = "- " . $p['name'] . ": " . $price . " บาท";
                        if (!empty($p['description'])) {
                            $line .= " (" . mb_substr($p['description'], 0, 50) . ")";
                        }
                        $productLines[] = $line;

                        if ($p['is_bestseller'])
                            $bestsellers[] = $p['name'];
                        if ($p['is_featured'])
                            $featured[] = $p['name'];
                    }

                    $productKnowledge = implode("\n", $productLines);

                    if (!empty($bestsellers)) {
                        $businessInfo[] = "สินค้าขายดี: " . implode(", ", array_slice($bestsellers, 0, 5));
                    }
                    if (!empty($featured)) {
                        $businessInfo[] = "สินค้าแนะนำ: " . implode(", ", array_slice($featured, 0, 5));
                    }
                }

                // 5. Get active promotions
                try {
                    $stmt = $db->prepare("SELECT name, discount_type, discount_value, min_purchase 
                                          FROM promotions 
                                          WHERE is_active = 1 AND (end_date IS NULL OR end_date >= CURDATE())
                                          AND (line_account_id = ? OR line_account_id IS NULL)
                                          LIMIT 5");
                    $stmt->execute([$currentBotId]);
                    $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($promos)) {
                        $promoList = [];
                        foreach ($promos as $promo) {
                            $discount = $promo['discount_type'] === 'percent'
                                ? "ลด " . $promo['discount_value'] . "%"
                                : "ลด " . number_format($promo['discount_value']) . " บาท";
                            $promoList[] = $promo['name'] . " (" . $discount . ")";
                        }
                        $businessInfo[] = "โปรโมชั่น: " . implode(", ", $promoList);
                    }
                } catch (Exception $e) {
                }

                // 6. Get pharmacist info (if pharmacy)
                try {
                    $stmt = $db->prepare("SELECT name, title, specialty FROM pharmacists WHERE line_account_id = ? AND is_active = 1 LIMIT 3");
                    $stmt->execute([$currentBotId]);
                    $pharmacists = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($pharmacists)) {
                        $pharmList = [];
                        foreach ($pharmacists as $pharm) {
                            $pharmList[] = ($pharm['title'] ?? '') . $pharm['name'];
                        }
                        $businessInfo[] = "เภสัชกร: " . implode(", ", $pharmList);
                    }
                } catch (Exception $e) {
                }

            } catch (Exception $e) {
                // Ignore errors
            }

            ob_end_clean();
            echo json_encode([
                'success' => true,
                'business_info' => implode("\n", $businessInfo),
                'product_knowledge' => $productKnowledge,
                'raw' => [
                    'shop' => $shop ?? null,
                    'product_count' => count($products ?? [])
                ]
            ]);
            break;

        case 'search_products':
            $query = trim($_GET['q'] ?? '');
            $products = [];
            if (strlen($query) >= 1) {
                $table = 'business_items';

                $search = '%' . $query . '%';
                $sql = "SELECT id, name, sku, barcode, price, stock, unit FROM {$table} 
                    WHERE (name LIKE ? OR sku LIKE ? OR barcode LIKE ?) AND is_active = 1";
                $params = [$search, $search, $search];

                // Filter by line_account_id if set
                if ($currentBotId) {
                    $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                    $params[] = $currentBotId;
                }

                $sql .= " ORDER BY name ASC LIMIT 15";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            ob_end_clean();
            echo json_encode(['success' => true, 'products' => $products]);
            break;

        case 'search_users':
            $query = trim($_GET['q'] ?? '');
            if (strlen($query) < 2) {
                echo json_encode(['success' => true, 'users' => []]);
                break;
            }

            $sql = "SELECT u.id, u.line_user_id, u.display_name, u.picture_url, rm.name as current_menu
                    FROM users u
                    LEFT JOIN user_rich_menus urm ON u.id = urm.user_id AND urm.line_account_id = ?
                    LEFT JOIN rich_menus rm ON urm.rich_menu_id = rm.id
                    WHERE u.line_account_id = ? 
                    AND u.line_user_id IS NOT NULL
                    AND (u.display_name LIKE ? OR u.line_user_id LIKE ?)
                    ORDER BY u.display_name ASC
                    LIMIT 20";

            $searchTerm = '%' . $query . '%';
            $stmt = $db->prepare($sql);
            $stmt->execute([$currentBotId, $currentBotId, $searchTerm, $searchTerm]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_end_clean();
            echo json_encode(['success' => true, 'users' => $users]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
