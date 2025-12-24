<?php
/**
 * AI Admin Assistant API
 * ช่วยแอดมินตอบคำถามเกี่ยวกับระบบ ยอดขาย สถิติ
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check auth - ใช้ session_status() เพื่อเช็คก่อน start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_user'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance()->getConnection();
$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'No message']);
    exit;
}

// Get current bot ID
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Detect intent and get data
$response = processAdminQuery($db, $message, $currentBotId);

echo json_encode([
    'success' => true,
    'response' => $response['text'],
    'data' => $response['data'] ?? null,
    'type' => $response['type'] ?? 'text'
]);

function processAdminQuery($db, $message, $botId) {
    $msg = mb_strtolower($message);
    $originalMsg = $message; // เก็บข้อความต้นฉบับ
    
    // ==================== 1. AI ACTIONS (ทำงานได้เลย) - ต้องเช็คก่อน ====================
    
    // ปิดสินค้าหมด
    if (preg_match('/(ปิด|disable).*(สินค้าหมด|หมดสต็อก|stock.*0|out.*stock)/u', $msg)) {
        return actionDisableOutOfStock($db, $botId);
    }
    
    // เปิดสินค้าที่มี stock
    if (preg_match('/(เปิด|enable).*(สินค้า.*มี.*stock|มีสต็อก)/u', $msg)) {
        return actionEnableInStock($db, $botId);
    }
    
    // ยืนยันสลิป (ต้องเช็คก่อนยืนยันออเดอร์)
    if (preg_match('/(ยืนยัน|อนุมัติ|approve).*(สลิป|slip).*#?(TXN\d+|\d+)/ui', $msg, $matches)) {
        return actionApproveSlip($db, $matches[3], $botId);
    }
    
    // ปฏิเสธสลิป
    if (preg_match('/(ปฏิเสธ|reject|ไม่อนุมัติ).*(สลิป|slip).*#?(TXN\d+|\d+)/ui', $msg, $matches)) {
        return actionRejectSlip($db, $matches[3], $botId);
    }
    
    // ดูสลิปรอตรวจ
    if (preg_match('/(สลิป|slip).*(รอ|pending|ตรวจ)/u', $msg) || preg_match('/^(สลิปรอตรวจ|pending.?slip)$/ui', $msg)) {
        return getPendingSlips($db, $botId);
    }
    
    // ยืนยัน/อนุมัติออเดอร์
    if (preg_match('/(ยืนยัน|อนุมัติ|confirm|approve).*(ออเดอร์|order).*#?(TXN\d+|\d+)/ui', $msg, $matches)) {
        return actionConfirmOrder($db, $matches[3], $botId);
    }
    
    // ยกเลิกออเดอร์
    if (preg_match('/(ยกเลิก|cancel).*(ออเดอร์|order).*#?(TXN\d+|\d+)/ui', $msg, $matches)) {
        return actionCancelOrder($db, $matches[3], $botId);
    }
    
    // สร้าง broadcast draft
    if (preg_match('/(สร้าง|ร่าง|draft).*(broadcast|บรอดแคสต์).*(ลูกค้าใหม่|new.*user|7.*วัน|week)/u', $msg)) {
        return actionCreateBroadcastDraft($db, 'new_users_7days', $botId);
    }
    
    // ส่งข้อความหาลูกค้า (ต้องมี pattern ชัดเจน)
    if (preg_match('/(ส่งข้อความ|แชทหา).*(ถึง|กับ)[:\s]*(.+)/u', $msg, $matches)) {
        return actionOpenChat($db, trim($matches[3]), $botId);
    }
    
    // ==================== 2. SMART ALERTS ====================
    if (preg_match('/^(แจ้งเตือน|alert|warning|เช็คปัญหา|ตรวจสอบปัญหา)$/u', $msg)) {
        return getSmartAlerts($db, $botId);
    }
    
    // ==================== 3. ค้นหาเฉพาะเจาะจง (ต้องเช็คก่อน report ทั่วไป) ====================
    
    // ค้นหาสินค้าด้วยชื่อ - pattern: "หาสินค้า xxx" หรือ "ค้นหาสินค้า xxx" หรือ "สินค้าชื่อ xxx"
    if (preg_match('/(หา|ค้นหา|search|ค้น)\s*(สินค้า|product)[:\s]+(.+)/u', $msg, $matches)) {
        $keyword = trim($matches[3]);
        if (!empty($keyword) && !preg_match('/^(หมด|ใกล้หมด|ทั้งหมด|ขายดี|แพง|ถูก)$/u', $keyword)) {
            return searchProduct($db, $keyword, $botId);
        }
    }
    
    // ค้นหาสินค้าแบบ "สินค้า ชื่อxxx" หรือ "สินค้าตัวนี้ xxx"
    if (preg_match('/สินค้า\s*(ชื่อ|ตัว|ที่ชื่อ|คือ)[:\s]*(.+)/u', $msg, $matches)) {
        $keyword = trim($matches[2]);
        if (!empty($keyword)) {
            return searchProduct($db, $keyword, $botId);
        }
    }
    
    // ค้นหาลูกค้า - pattern: "หาลูกค้า xxx"
    if (preg_match('/(หา|ค้นหา|search)\s*(ลูกค้า|คน|user)[:\s]+(.+)/u', $msg, $matches)) {
        $keyword = trim($matches[3]);
        if (!empty($keyword) && !preg_match('/^(ใหม่|เก่า|ทั้งหมด|vip)$/u', $keyword)) {
            return searchCustomer($db, $keyword, $botId);
        }
    }
    
    // ค้นหาออเดอร์ - pattern: "หาออเดอร์ #123" หรือ "ออเดอร์ #123"
    if (preg_match('/(หา|ค้นหา|ดู)?\s*(ออเดอร์|order)\s*#?(\d+)/u', $msg, $matches)) {
        return searchOrder($db, $matches[3], $botId);
    }
    
    // ==================== 4. สินค้าแพง/ถูกที่สุด (ต้องเช็คก่อน report ทั่วไป) ====================
    if (preg_match('/สินค้า.*(แพง|ราคาสูง|expensive)/u', $msg)) {
        return getMostExpensiveProducts($db, $botId);
    }
    
    if (preg_match('/สินค้า.*(ถูก|ราคาต่ำ|cheap)/u', $msg)) {
        return getCheapestProducts($db, $botId);
    }
    
    // ==================== 5. Top / อันดับ (เฉพาะเจาะจง) ====================
    if (preg_match('/(top|อันดับ|ท็อป).*(ลูกค้า|customer)/u', $msg)) {
        return getTopReport($db, $msg, $botId);
    }
    
    if (preg_match('/(top|อันดับ|ท็อป|ขายดี|best.?sell).*(สินค้า|product)/u', $msg)) {
        return getTopReport($db, $msg, $botId);
    }
    
    if (preg_match('/สินค้า.*(ขายดี|best.?sell|ยอดนิยม)/u', $msg)) {
        return getTopReport($db, $msg, $botId);
    }
    
    // ==================== 6. รายงานเฉพาะ (ต้องมี keyword ชัดเจน) ====================
    
    // ยอดขาย - ต้องมีคำว่า "ยอดขาย" หรือ "รายได้" ชัดเจน
    if (preg_match('/(ยอดขาย|รายได้|revenue|sales)/u', $msg) && 
        !preg_match('/(หา|ค้นหา|search)/u', $msg)) {
        return getSalesReport($db, $msg, $botId);
    }
    
    // ออเดอร์ - รายงานออเดอร์ (ไม่ใช่ค้นหา)
    if (preg_match('/(รายงาน|สรุป|ดู).*(ออเดอร์|order|คำสั่งซื้อ)/u', $msg)) {
        return getOrdersReport($db, $msg, $botId);
    }
    
    if (preg_match('/(ออเดอร์|order).*(รอ|pending|ค้าง)/u', $msg)) {
        return getOrdersReport($db, $msg, $botId);
    }
    
    // สินค้า - รายงานสินค้า (ต้องมี keyword รายงาน หรือ หมด/ใกล้หมด)
    if (preg_match('/(รายงาน|สรุป|ดู).*(สินค้า|product)/u', $msg)) {
        return getProductsReport($db, $msg, $botId);
    }
    
    if (preg_match('/สินค้า.*(หมด|ใกล้หมด|out.*stock|low.*stock)/u', $msg)) {
        return getProductsReport($db, $msg, $botId);
    }
    
    if (preg_match('/^(สินค้าหมด|สินค้าใกล้หมด|stock)$/u', $msg)) {
        return getProductsReport($db, $msg, $botId);
    }
    
    // ลูกค้า - รายงานลูกค้า (ไม่ใช่ค้นหา)
    if (preg_match('/(รายงาน|สรุป|ดู|จำนวน).*(ลูกค้า|สมาชิก|user|member)/u', $msg)) {
        return getCustomersReport($db, $msg, $botId);
    }
    
    if (preg_match('/(ลูกค้า|สมาชิก).*(ใหม่|เก่า|ทั้งหมด|กี่คน)/u', $msg)) {
        return getCustomersReport($db, $msg, $botId);
    }
    
    // ข้อความ - รายงานข้อความ
    if (preg_match('/(ข้อความ|message).*(รอ|ยังไม่อ่าน|unread)/u', $msg)) {
        return getMessagesReport($db, $msg, $botId);
    }
    
    // ==================== 7. สรุปวันนี้ ====================
    if (preg_match('/^(สรุป|summary|สรุปวันนี้|ภาพรวม|overview)$/u', $msg)) {
        return getDailySummary($db, $botId);
    }
    
    if (preg_match('/(สรุป|summary).*(วันนี้|today)/u', $msg)) {
        return getDailySummary($db, $botId);
    }
    
    // ==================== 8. Broadcast ====================
    if (preg_match('/(รายงาน|สรุป|ดู).*(broadcast|บรอดแคสต์)/u', $msg)) {
        return getBroadcastReport($db, $msg, $botId);
    }
    
    // ==================== 9. นัดหมาย ====================
    if (preg_match('/(รายงาน|สรุป|ดู).*(นัดหมาย|appointment)/u', $msg)) {
        return getAppointmentsReport($db, $msg, $botId);
    }
    
    if (preg_match('/(นัดหมาย|appointment).*(วันนี้|today)/u', $msg)) {
        return getAppointmentsReport($db, $msg, $botId);
    }
    
    // ==================== 10. แต้ม ====================
    if (preg_match('/(รายงาน|สรุป|ดู).*(แต้ม|point|คะแนน)/u', $msg)) {
        return getPointsReport($db, $msg, $botId);
    }
    
    // ==================== 11. เปรียบเทียบ ====================
    if (preg_match('/(เปรียบเทียบ|compare)/u', $msg)) {
        return getComparisonReport($db, $msg, $botId);
    }
    
    // ==================== 12. System Status ====================
    if (preg_match('/^(ระบบ|system|status|สถานะระบบ|เช็คระบบ)$/u', $msg)) {
        return getSystemStatus($db, $botId);
    }
    
    // ==================== 13. AI Chat (Gemini) - ส่งไป AI ตอบ ====================
    // ถ้าไม่ตรงกับ pattern ใดๆ ให้ส่งไป Gemini AI พร้อมข้อมูลระบบ
    return askGeminiAI($db, $originalMsg, $botId);
}

// ==================== NEW: ค้นหาสินค้าแพง/ถูกที่สุด ====================

function getMostExpensiveProducts($db, $botId) {
    $table = 'products';
    
    try {
        $stmt = $db->prepare("SELECT name, sku, price, stock FROM {$table} 
            WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)
            ORDER BY price DESC LIMIT 10");
        $stmt->execute([$botId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $text = "💰 **สินค้าราคาสูงสุด 10 อันดับ**\n\n";
        $rank = 1;
        foreach ($products as $p) {
            $medal = $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank-1] : "{$rank}.";
            $text .= "{$medal} {$p['name']}\n";
            $text .= "   💵 ฿" . number_format($p['price'], 2) . " | Stock: {$p['stock']}\n\n";
            $rank++;
        }
        
        return ['text' => $text, 'type' => 'products', 'data' => $products];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลได้', 'type' => 'error'];
    }
}

function getCheapestProducts($db, $botId) {
    $table = 'products';
    
    try {
        $stmt = $db->prepare("SELECT name, sku, price, stock FROM {$table} 
            WHERE is_active = 1 AND price > 0 AND (line_account_id = ? OR line_account_id IS NULL)
            ORDER BY price ASC LIMIT 10");
        $stmt->execute([$botId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $text = "💸 **สินค้าราคาถูกสุด 10 อันดับ**\n\n";
        $rank = 1;
        foreach ($products as $p) {
            $medal = $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank-1] : "{$rank}.";
            $text .= "{$medal} {$p['name']}\n";
            $text .= "   💵 ฿" . number_format($p['price'], 2) . " | Stock: {$p['stock']}\n\n";
            $rank++;
        }
        
        return ['text' => $text, 'type' => 'products', 'data' => $products];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลได้', 'type' => 'error'];
    }
}

function getSalesReport($db, $msg, $botId) {
    $period = 'today';
    if (preg_match('/(เดือน|month)/', $msg)) $period = 'month';
    elseif (preg_match('/(สัปดาห์|week)/', $msg)) $period = 'week';
    elseif (preg_match('/(เมื่อวาน|yesterday)/', $msg)) $period = 'yesterday';
    
    switch ($period) {
        case 'yesterday':
            $dateCondition = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            $periodText = 'เมื่อวาน';
            break;
        case 'week':
            $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            $periodText = '7 วันที่ผ่านมา';
            break;
        case 'month':
            $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            $periodText = '30 วันที่ผ่านมา';
            break;
        default:
            $dateCondition = "DATE(created_at) = CURDATE()";
            $periodText = 'วันนี้';
    }
    
    try {
        // Total revenue
        $stmt = $db->query("SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(grand_total), 0) as revenue,
            COALESCE(AVG(grand_total), 0) as avg_order
            FROM transactions 
            WHERE {$dateCondition} AND status IN ('paid', 'confirmed', 'delivered')");
        $sales = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // By status
        $stmt = $db->query("SELECT status, COUNT(*) as cnt FROM transactions WHERE {$dateCondition} GROUP BY status");
        $byStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $text = "📊 **รายงานยอดขาย{$periodText}**\n\n";
        $text .= "💰 รายได้รวม: ฿" . number_format($sales['revenue'], 2) . "\n";
        $text .= "📦 จำนวนออเดอร์: " . number_format($sales['total_orders']) . " รายการ\n";
        $text .= "📈 เฉลี่ยต่อออเดอร์: ฿" . number_format($sales['avg_order'], 2) . "\n\n";
        
        if (!empty($byStatus)) {
            $text .= "📋 แยกตามสถานะ:\n";
            $statusLabels = ['pending' => 'รอชำระ', 'paid' => 'ชำระแล้ว', 'confirmed' => 'ยืนยันแล้ว', 'shipping' => 'กำลังจัดส่ง', 'delivered' => 'ส่งแล้ว', 'cancelled' => 'ยกเลิก'];
            foreach ($byStatus as $status => $count) {
                $label = $statusLabels[$status] ?? $status;
                $text .= "  • {$label}: {$count}\n";
            }
        }
        
        return ['text' => $text, 'type' => 'sales', 'data' => $sales];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลยอดขายได้', 'type' => 'error'];
    }
}

function getOrdersReport($db, $msg, $botId) {
    try {
        // Pending orders
        $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
        $pending = $stmt->fetchColumn();
        
        // Pending slips
        $stmt = $db->query("SELECT COUNT(DISTINCT transaction_id) FROM payment_slips WHERE status = 'pending'");
        $pendingSlips = $stmt->fetchColumn();
        
        // Recent orders
        $stmt = $db->query("SELECT t.*, u.display_name FROM transactions t 
            LEFT JOIN users u ON t.user_id = u.id 
            ORDER BY t.created_at DESC LIMIT 5");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $text = "📦 **รายงานออเดอร์**\n\n";
        $text .= "⏳ รอชำระเงิน: {$pending} รายการ\n";
        $text .= "🧾 รอตรวจสลิป: {$pendingSlips} รายการ\n\n";
        
        if (!empty($recent)) {
            $text .= "📋 ออเดอร์ล่าสุด:\n";
            foreach ($recent as $order) {
                $statusIcons = [
                    'pending' => '⏳',
                    'paid' => '💳',
                    'confirmed' => '✅',
                    'shipping' => '🚚',
                    'delivered' => '📬',
                    'cancelled' => '❌'
                ];
                $status = isset($statusIcons[$order['status']]) ? $statusIcons[$order['status']] : '•';
                $text .= "{$status} #{$order['order_number']} - {$order['display_name']} - ฿" . number_format($order['grand_total']) . "\n";
            }
        }
        
        if ($pendingSlips > 0) {
            $text .= "\n⚠️ มีสลิปรอตรวจสอบ {$pendingSlips} รายการ";
        }
        
        return ['text' => $text, 'type' => 'orders', 'data' => ['pending' => $pending, 'pending_slips' => $pendingSlips]];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลออเดอร์ได้', 'type' => 'error'];
    }
}

function getProductsReport($db, $msg, $botId) {
    $table = 'products';
    
    try {
        // Stats
        $stmt = $db->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN stock > 0 AND stock <= 5 THEN 1 ELSE 0 END) as low_stock
            FROM {$table} WHERE (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $text = "📦 **รายงานสินค้า**\n\n";
        $text .= "📊 สินค้าทั้งหมด: " . number_format($stats['total']) . " รายการ\n";
        $text .= "✅ เปิดขาย: " . number_format($stats['active']) . " รายการ\n";
        $text .= "⚠️ สินค้าใกล้หมด (≤5): " . number_format($stats['low_stock']) . " รายการ\n";
        $text .= "❌ สินค้าหมด: " . number_format($stats['out_of_stock']) . " รายการ\n";
        
        // Out of stock list
        if (preg_match('/(หมด|out)/', $msg) && $stats['out_of_stock'] > 0) {
            $stmt = $db->prepare("SELECT name, sku FROM {$table} WHERE stock <= 0 AND is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 10");
            $stmt->execute([$botId]);
            $outOfStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($outOfStock)) {
                $text .= "\n📋 สินค้าหมด (ยังเปิดขาย):\n";
                foreach ($outOfStock as $p) {
                    $text .= "  • {$p['name']} ({$p['sku']})\n";
                }
            }
        }
        
        // Best sellers
        if (preg_match('/(ขายดี|best)/', $msg)) {
            // This would need order_items table
            $text .= "\n🏆 สินค้าขายดี: (ต้องมีข้อมูลการขาย)";
        }
        
        return ['text' => $text, 'type' => 'products', 'data' => $stats];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลสินค้าได้: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function getCustomersReport($db, $msg, $botId) {
    try {
        $stmt = $db->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as week,
            SUM(CASE WHEN is_registered = 1 THEN 1 ELSE 0 END) as registered
            FROM users WHERE (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $text = "👥 **รายงานลูกค้า**\n\n";
        $text .= "📊 ลูกค้าทั้งหมด: " . number_format($stats['total']) . " คน\n";
        $text .= "📝 ลงทะเบียนแล้ว: " . number_format($stats['registered']) . " คน\n";
        $text .= "🆕 ใหม่วันนี้: " . number_format($stats['today']) . " คน\n";
        $text .= "📈 ใหม่ 7 วัน: " . number_format($stats['week']) . " คน\n";
        
        return ['text' => $text, 'type' => 'customers', 'data' => $stats];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลลูกค้าได้', 'type' => 'error'];
    }
}

function getMessagesReport($db, $msg, $botId) {
    try {
        $stmt = $db->prepare("SELECT 
            COUNT(*) as total_today,
            SUM(CASE WHEN is_read = 0 AND direction = 'incoming' THEN 1 ELSE 0 END) as unread
            FROM messages WHERE DATE(created_at) = CURDATE() AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $text = "💬 **รายงานข้อความ**\n\n";
        $text .= "📨 ข้อความวันนี้: " . number_format($stats['total_today']) . " ข้อความ\n";
        $text .= "📩 ยังไม่อ่าน: " . number_format($stats['unread']) . " ข้อความ\n";
        
        if ($stats['unread'] > 0) {
            $text .= "\n⚠️ มีข้อความรอตอบ {$stats['unread']} ข้อความ";
        }
        
        return ['text' => $text, 'type' => 'messages', 'data' => $stats];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลข้อความได้', 'type' => 'error'];
    }
}

function getDailySummary($db, $botId) {
    $text = "📊 **สรุปภาพรวมวันนี้**\n";
    $text .= "━━━━━━━━━━━━━━━━━━\n\n";
    
    try {
        // Sales
        $stmt = $db->query("SELECT COUNT(*) as orders, COALESCE(SUM(grand_total), 0) as revenue 
            FROM transactions WHERE DATE(created_at) = CURDATE() AND status IN ('paid', 'confirmed', 'delivered')");
        $sales = $stmt->fetch(PDO::FETCH_ASSOC);
        $text .= "💰 รายได้: ฿" . number_format($sales['revenue'], 2) . " ({$sales['orders']} ออเดอร์)\n";
        
        // Pending
        $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
        $pending = $stmt->fetchColumn();
        $text .= "⏳ รอชำระ: {$pending} รายการ\n";
        
        // Pending slips
        $stmt = $db->query("SELECT COUNT(DISTINCT transaction_id) FROM payment_slips WHERE status = 'pending'");
        $pendingSlips = $stmt->fetchColumn();
        if ($pendingSlips > 0) {
            $text .= "🧾 รอตรวจสลิป: {$pendingSlips} รายการ\n";
        }
        
        // New users
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $newUsers = $stmt->fetchColumn();
        $text .= "🆕 ลูกค้าใหม่: {$newUsers} คน\n";
        
        // Unread messages
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE is_read = 0 AND direction = 'incoming' AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $unread = $stmt->fetchColumn();
        $text .= "💬 ข้อความรอตอบ: {$unread} ข้อความ\n";
        
        // Alerts
        $alerts = [];
        if ($pendingSlips > 0) $alerts[] = "🧾 มีสลิปรอตรวจ {$pendingSlips} รายการ";
        if ($unread > 5) $alerts[] = "💬 มีข้อความรอตอบมาก";
        
        if (!empty($alerts)) {
            $text .= "\n⚠️ **แจ้งเตือน:**\n";
            foreach ($alerts as $alert) {
                $text .= "  {$alert}\n";
            }
        }
        
    } catch (Exception $e) {
        $text .= "\n❌ เกิดข้อผิดพลาดบางส่วน";
    }
    
    return ['text' => $text, 'type' => 'summary'];
}

function searchCustomer($db, $keyword, $botId) {
    $keyword = trim($keyword);
    if (empty($keyword)) {
        return ['text' => '❌ กรุณาระบุชื่อหรือเบอร์โทรที่ต้องการค้นหา', 'type' => 'error'];
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM users 
            WHERE (display_name LIKE ? OR phone LIKE ? OR line_user_id LIKE ?) 
            AND (line_account_id = ? OR line_account_id IS NULL) 
            LIMIT 5");
        $stmt->execute(["%{$keyword}%", "%{$keyword}%", "%{$keyword}%", $botId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($users)) {
            return ['text' => "❌ ไม่พบลูกค้าที่ค้นหา: {$keyword}", 'type' => 'search'];
        }
        
        $text = "🔍 **ผลการค้นหา:** {$keyword}\n\n";
        foreach ($users as $user) {
            $text .= "👤 {$user['display_name']}\n";
            $text .= "   📱 {$user['phone']}\n";
            $text .= "   📅 สมัคร: " . date('d/m/Y', strtotime($user['created_at'])) . "\n\n";
        }
        
        return ['text' => $text, 'type' => 'search', 'data' => $users];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาดในการค้นหา', 'type' => 'error'];
    }
}

function searchOrder($db, $orderId, $botId) {
    try {
        $stmt = $db->prepare("SELECT t.*, u.display_name, u.phone FROM transactions t 
            LEFT JOIN users u ON t.user_id = u.id 
            WHERE t.id = ? OR t.order_number LIKE ?");
        $stmt->execute([$orderId, "%{$orderId}%"]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return ['text' => "❌ ไม่พบออเดอร์ #{$orderId}", 'type' => 'search'];
        }
        
        $statusLabels = ['pending' => '⏳ รอชำระ', 'paid' => '💳 ชำระแล้ว', 'confirmed' => '✅ ยืนยันแล้ว', 'shipping' => '🚚 กำลังจัดส่ง', 'delivered' => '📬 ส่งแล้ว', 'cancelled' => '❌ ยกเลิก'];
        
        $text = "📦 **ออเดอร์ #{$order['order_number']}**\n\n";
        $text .= "👤 ลูกค้า: {$order['display_name']}\n";
        $text .= "📱 เบอร์: {$order['phone']}\n";
        $text .= "💰 ยอดรวม: ฿" . number_format($order['grand_total'], 2) . "\n";
        $text .= "📋 สถานะ: " . ($statusLabels[$order['status']] ?? $order['status']) . "\n";
        $text .= "📅 วันที่: " . date('d/m/Y H:i', strtotime($order['created_at'])) . "\n";
        
        return ['text' => $text, 'type' => 'order', 'data' => $order];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาดในการค้นหา', 'type' => 'error'];
    }
}

function getHelpMessage() {
    $text = "🤖 **AI Admin Assistant**\n\n";
    $text .= "ฉันช่วยคุณได้เรื่อง:\n\n";
    $text .= "📊 **ยอดขาย/รายได้**\n";
    $text .= "  • \"ยอดขายวันนี้\"\n";
    $text .= "  • \"รายได้เดือนนี้\"\n\n";
    $text .= "📦 **ออเดอร์**\n";
    $text .= "  • \"ออเดอร์รอดำเนินการ\"\n";
    $text .= "  • \"หาออเดอร์ #1234\"\n\n";
    $text .= "📦 **สินค้า**\n";
    $text .= "  • \"สินค้าหมด\"\n";
    $text .= "  • \"หาสินค้า ชื่อ\"\n\n";
    $text .= "👥 **ลูกค้า**\n";
    $text .= "  • \"ลูกค้าใหม่วันนี้\"\n";
    $text .= "  • \"หาลูกค้า ชื่อ\"\n\n";
    $text .= "� **สBroadcast**\n";
    $text .= "  • \"broadcast วันนี้\"\n\n";
    $text .= "📅 **นัดหมาย**\n";
    $text .= "  • \"นัดหมายวันนี้\"\n\n";
    $text .= "🏆 **อันดับ**\n";
    $text .= "  • \"top ลูกค้า\"\n";
    $text .= "  • \"สินค้าขายดี\"\n\n";
    $text .= "📋 **สรุป**\n";
    $text .= "  • \"สรุปวันนี้\"\n";
    $text .= "  • \"เปรียบเทียบสัปดาห์\"\n";
    
    return ['text' => $text, 'type' => 'help'];
}

// ==================== NEW FUNCTIONS ====================

function getBroadcastReport($db, $msg, $botId) {
    try {
        $stmt = $db->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
            FROM broadcasts");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Recent broadcasts
        $stmt = $db->query("SELECT name, status, sent_count, created_at FROM broadcasts ORDER BY created_at DESC LIMIT 5");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $text = "📢 **รายงาน Broadcast**\n\n";
        $text .= "📊 ทั้งหมด: " . number_format($stats['total']) . " แคมเปญ\n";
        $text .= "✅ ส่งแล้ว: " . number_format($stats['sent']) . "\n";
        $text .= "⏰ รอส่ง: " . number_format($stats['scheduled']) . "\n";
        $text .= "🆕 วันนี้: " . number_format($stats['today']) . "\n";
        
        if (!empty($recent)) {
            $text .= "\n📋 ล่าสุด:\n";
            foreach ($recent as $b) {
                $icon = $b['status'] == 'sent' ? '✅' : '⏰';
                $text .= "{$icon} {$b['name']} - ส่ง {$b['sent_count']} คน\n";
            }
        }
        
        return ['text' => $text, 'type' => 'broadcast', 'data' => $stats];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูล Broadcast ได้', 'type' => 'error'];
    }
}

function getAppointmentsReport($db, $msg, $botId) {
    try {
        // Check if table exists
        $stmt = $db->query("SHOW TABLES LIKE 'appointments'");
        if (!$stmt->fetch()) {
            return ['text' => '❌ ระบบนัดหมายยังไม่ได้ติดตั้ง', 'type' => 'error'];
        }
        
        $stmt = $db->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN DATE(appointment_date) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed
            FROM appointments");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $text = "📅 **รายงานนัดหมาย**\n\n";
        $text .= "📊 ทั้งหมด: " . number_format($stats['total']) . " นัด\n";
        $text .= "🆕 วันนี้: " . number_format($stats['today']) . " นัด\n";
        $text .= "⏳ รอยืนยัน: " . number_format($stats['pending']) . "\n";
        $text .= "✅ ยืนยันแล้ว: " . number_format($stats['confirmed']) . "\n";
        
        // Today's appointments
        if ($stats['today'] > 0) {
            $stmt = $db->query("SELECT a.*, u.display_name FROM appointments a 
                LEFT JOIN users u ON a.user_id = u.id 
                WHERE DATE(a.appointment_date) = CURDATE() 
                ORDER BY a.appointment_time LIMIT 5");
            $todayAppts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($todayAppts)) {
                $text .= "\n📋 นัดหมายวันนี้:\n";
                foreach ($todayAppts as $a) {
                    $time = date('H:i', strtotime($a['appointment_time']));
                    $text .= "  • {$time} - {$a['display_name']}\n";
                }
            }
        }
        
        return ['text' => $text, 'type' => 'appointments', 'data' => $stats];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลนัดหมายได้', 'type' => 'error'];
    }
}

function getPointsReport($db, $msg, $botId) {
    try {
        // Check if table exists
        $stmt = $db->query("SHOW TABLES LIKE 'points_history'");
        if (!$stmt->fetch()) {
            return ['text' => '❌ ระบบแต้มยังไม่ได้ติดตั้ง', 'type' => 'error'];
        }
        
        $stmt = $db->prepare("SELECT 
            COALESCE(SUM(CASE WHEN type = 'earn' THEN points ELSE 0 END), 0) as total_earned,
            COALESCE(SUM(CASE WHEN type = 'redeem' THEN points ELSE 0 END), 0) as total_redeemed,
            COUNT(DISTINCT user_id) as users_with_points
            FROM points_history WHERE (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Today
        $stmt = $db->query("SELECT 
            COALESCE(SUM(CASE WHEN type = 'earn' THEN points ELSE 0 END), 0) as earned_today,
            COALESCE(SUM(CASE WHEN type = 'redeem' THEN points ELSE 0 END), 0) as redeemed_today
            FROM points_history WHERE DATE(created_at) = CURDATE()");
        $today = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $text = "🎯 **รายงานแต้มสะสม**\n\n";
        $text .= "📊 แต้มที่แจกไป: " . number_format($stats['total_earned']) . " แต้ม\n";
        $text .= "🎁 แต้มที่ใช้ไป: " . number_format($stats['total_redeemed']) . " แต้ม\n";
        $text .= "👥 ลูกค้ามีแต้ม: " . number_format($stats['users_with_points']) . " คน\n\n";
        $text .= "🆕 วันนี้:\n";
        $text .= "  • แจก: " . number_format($today['earned_today']) . " แต้ม\n";
        $text .= "  • ใช้: " . number_format($today['redeemed_today']) . " แต้ม\n";
        
        return ['text' => $text, 'type' => 'points', 'data' => $stats];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลแต้มได้', 'type' => 'error'];
    }
}

function getComparisonReport($db, $msg, $botId) {
    try {
        // This week vs last week
        $stmt = $db->query("SELECT 
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN grand_total ELSE 0 END), 0) as this_week,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN grand_total ELSE 0 END), 0) as last_week
            FROM transactions WHERE status IN ('paid', 'confirmed', 'delivered')");
        $sales = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->query("SELECT 
            COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week,
            COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as last_week
            FROM transactions WHERE status IN ('paid', 'confirmed', 'delivered')");
        $orders = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("SELECT 
            COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week,
            COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as last_week
            FROM users WHERE (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $users = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate changes
        $salesChange = $sales['last_week'] > 0 ? (($sales['this_week'] - $sales['last_week']) / $sales['last_week'] * 100) : 0;
        $ordersChange = $orders['last_week'] > 0 ? (($orders['this_week'] - $orders['last_week']) / $orders['last_week'] * 100) : 0;
        $usersChange = $users['last_week'] > 0 ? (($users['this_week'] - $users['last_week']) / $users['last_week'] * 100) : 0;
        
        $text = "📊 **เปรียบเทียบ สัปดาห์นี้ vs สัปดาห์ที่แล้ว**\n\n";
        
        $text .= "💰 **ยอดขาย**\n";
        $text .= "  สัปดาห์นี้: ฿" . number_format($sales['this_week']) . "\n";
        $text .= "  สัปดาห์ที่แล้ว: ฿" . number_format($sales['last_week']) . "\n";
        $text .= "  " . ($salesChange >= 0 ? "📈 +" : "📉 ") . number_format($salesChange, 1) . "%\n\n";
        
        $text .= "📦 **ออเดอร์**\n";
        $text .= "  สัปดาห์นี้: " . number_format($orders['this_week']) . " รายการ\n";
        $text .= "  สัปดาห์ที่แล้ว: " . number_format($orders['last_week']) . " รายการ\n";
        $text .= "  " . ($ordersChange >= 0 ? "📈 +" : "📉 ") . number_format($ordersChange, 1) . "%\n\n";
        
        $text .= "👥 **ลูกค้าใหม่**\n";
        $text .= "  สัปดาห์นี้: " . number_format($users['this_week']) . " คน\n";
        $text .= "  สัปดาห์ที่แล้ว: " . number_format($users['last_week']) . " คน\n";
        $text .= "  " . ($usersChange >= 0 ? "📈 +" : "📉 ") . number_format($usersChange, 1) . "%\n";
        
        return ['text' => $text, 'type' => 'comparison'];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลเปรียบเทียบได้', 'type' => 'error'];
    }
}

function getTopReport($db, $msg, $botId) {
    $text = "🏆 **อันดับ**\n\n";
    
    try {
        // Top customers by orders
        if (preg_match('/(ลูกค้า|customer|user)/', $msg)) {
            $stmt = $db->query("SELECT u.display_name, COUNT(t.id) as order_count, SUM(t.grand_total) as total_spent
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                WHERE t.status IN ('paid', 'confirmed', 'delivered')
                GROUP BY t.user_id
                ORDER BY total_spent DESC
                LIMIT 10");
            $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $text .= "👥 **Top 10 ลูกค้า (ยอดซื้อสูงสุด)**\n";
            $rank = 1;
            foreach ($topCustomers as $c) {
                $medal = $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank-1] : "{$rank}.";
                $text .= "{$medal} {$c['display_name']} - ฿" . number_format($c['total_spent']) . " ({$c['order_count']} ออเดอร์)\n";
                $rank++;
            }
        }
        // Top products
        elseif (preg_match('/(สินค้า|product|ขายดี)/', $msg)) {
            // Check for order_items table
            $stmt = $db->query("SHOW TABLES LIKE 'order_items'");
            if ($stmt->fetch()) {
                $stmt = $db->query("SELECT oi.product_name, SUM(oi.quantity) as qty, SUM(oi.subtotal) as revenue
                    FROM order_items oi
                    JOIN transactions t ON oi.transaction_id = t.id
                    WHERE t.status IN ('paid', 'confirmed', 'delivered')
                    GROUP BY oi.product_id
                    ORDER BY qty DESC
                    LIMIT 10");
                $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $text .= "📦 **Top 10 สินค้าขายดี**\n";
                $rank = 1;
                foreach ($topProducts as $p) {
                    $medal = $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank-1] : "{$rank}.";
                    $text .= "{$medal} {$p['product_name']} - {$p['qty']} ชิ้น (฿" . number_format($p['revenue']) . ")\n";
                    $rank++;
                }
            } else {
                $text .= "📦 ไม่มีข้อมูลสินค้าขายดี (ต้องมีตาราง order_items)";
            }
        }
        else {
            $text .= "กรุณาระบุว่าต้องการดู top อะไร:\n";
            $text .= "  • \"top ลูกค้า\"\n";
            $text .= "  • \"top สินค้า\" หรือ \"สินค้าขายดี\"\n";
        }
        
        return ['text' => $text, 'type' => 'top'];
    } catch (Exception $e) {
        return ['text' => '❌ ไม่สามารถดึงข้อมูลอันดับได้: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function searchProduct($db, $keyword, $botId) {
    if (empty($keyword)) {
        return ['text' => '❌ กรุณาระบุชื่อสินค้าที่ต้องการค้นหา', 'type' => 'error'];
    }
    
    $table = 'products';
    
    try {
        $stmt = $db->prepare("SELECT * FROM {$table} 
            WHERE (name LIKE ? OR sku LIKE ? OR barcode LIKE ?) 
            AND (line_account_id = ? OR line_account_id IS NULL) 
            LIMIT 5");
        $stmt->execute(["%{$keyword}%", "%{$keyword}%", "%{$keyword}%", $botId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return ['text' => "❌ ไม่พบสินค้า: {$keyword}", 'type' => 'search'];
        }
        
        $text = "🔍 **ผลการค้นหาสินค้า:** {$keyword}\n\n";
        foreach ($products as $p) {
            $status = $p['is_active'] ? '✅' : '❌';
            $stockIcon = $p['stock'] <= 0 ? '🔴' : ($p['stock'] <= 5 ? '🟡' : '🟢');
            $text .= "{$status} **{$p['name']}**\n";
            $text .= "   SKU: {$p['sku']}\n";
            $text .= "   💰 ราคา: ฿" . number_format($p['price'], 2) . "\n";
            $text .= "   {$stockIcon} สต็อก: {$p['stock']}\n\n";
        }
        
        return ['text' => $text, 'type' => 'search', 'data' => $products];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาดในการค้นหา', 'type' => 'error'];
    }
}

function getSystemStatus($db, $botId) {
    $text = "🖥️ **สถานะระบบ**\n\n";
    
    // Database
    try {
        $stmt = $db->query("SELECT 1");
        $text .= "✅ Database: เชื่อมต่อปกติ\n";
    } catch (Exception $e) {
        $text .= "❌ Database: ไม่สามารถเชื่อมต่อได้\n";
    }
    
    // Tables check
    $tables = ['users', 'messages', 'transactions', 'products', 'broadcasts'];
    $text .= "\n📋 **ตารางข้อมูล:**\n";
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            $text .= "  ✅ {$table}: " . number_format($count) . " รายการ\n";
        } catch (Exception $e) {
            $text .= "  ❌ {$table}: ไม่พบตาราง\n";
        }
    }
    
    // PHP Info
    $text .= "\n⚙️ **Server:**\n";
    $text .= "  • PHP: " . phpversion() . "\n";
    $text .= "  • Memory: " . ini_get('memory_limit') . "\n";
    $text .= "  • Time: " . date('Y-m-d H:i:s') . "\n";
    
    // Disk space (if available)
    if (function_exists('disk_free_space')) {
        $free = disk_free_space('/');
        $total = disk_total_space('/');
        if ($free && $total) {
            $usedPercent = round((1 - $free / $total) * 100, 1);
            $text .= "  • Disk: {$usedPercent}% used\n";
        }
    }
    
    return ['text' => $text, 'type' => 'system'];
}

// ==================== GEMINI AI INTEGRATION ====================

function askGeminiAI($db, $message, $botId) {
    $msg = mb_strtolower($message);
    
    // ตรวจสอบว่าเป็นคำถามเกี่ยวกับสินค้าหรือไม่ - ถ้าใช่ให้ค้นหาก่อน
    if (preg_match('/สินค้า/u', $msg) && !preg_match('/(รายงาน|สรุป|หมด|ใกล้หมด|ทั้งหมด|กี่)/u', $msg)) {
        // ลองดึงคำค้นหาจากข้อความ
        $searchKeyword = extractProductKeyword($message);
        if (!empty($searchKeyword)) {
            return searchProduct($db, $searchKeyword, $botId);
        }
    }
    
    // ดึง API Key จาก database
    $apiKey = null;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM ai_settings WHERE setting_key = 'gemini_api_key' AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY line_account_id DESC LIMIT 1");
        $stmt->execute([$botId]);
        $apiKey = $stmt->fetchColumn();
    } catch (Exception $e) {}
    
    // ถ้าไม่มี API Key ให้แสดง help message
    if (empty($apiKey)) {
        return getHelpMessage();
    }
    
    // เตรียมข้อมูลสรุปจากระบบเพื่อให้ AI ใช้ตอบ
    $systemContext = getSystemContext($db, $botId);
    
    // ถ้าถามเกี่ยวกับสินค้า ให้เพิ่มข้อมูลสินค้าตัวอย่าง
    $productContext = "";
    if (preg_match('/(สินค้า|product|แพง|ถูก|ราคา)/u', $msg)) {
        $productContext = getProductContextForAI($db, $botId, $message);
    }
    
    // สร้าง prompt
    $systemPrompt = "คุณเป็น AI Admin Assistant สำหรับระบบ LINE CRM ช่วยตอบคำถามแอดมินเกี่ยวกับระบบ
ตอบเป็นภาษาไทย สั้น กระชับ ใช้ emoji ให้เหมาะสม

ข้อมูลระบบปัจจุบัน:
{$systemContext}
{$productContext}

กฎการตอบ:
- ถ้าผู้ใช้ถามหาสินค้าเฉพาะ ให้แนะนำพิมพ์ \"หาสินค้า ชื่อสินค้า\" เช่น \"หาสินค้า พาราเซตามอล\"
- ถ้าถามสินค้าแพงสุด/ถูกสุด ให้ตอบจากข้อมูลที่ให้มา
- ถ้าถามเรื่องยอดขาย ออเดอร์ ลูกค้า ให้แนะนำคำสั่งเฉพาะ เช่น \"ยอดขายวันนี้\" \"สรุป\"
- ถ้าถามเรื่องทั่วไปเกี่ยวกับการใช้งานระบบ ให้ตอบตามความรู้
- ถ้าไม่แน่ใจ ให้แนะนำให้ติดต่อทีมซัพพอร์ต";

    $prompt = $message;
    
    // เรียก Gemini API
    try {
        $result = callGeminiAPI($apiKey, $systemPrompt, $prompt);
        
        if ($result['success']) {
            return ['text' => $result['text'], 'type' => 'ai', 'data' => ['model' => $result['model']]];
        } else {
            // ถ้า API error ให้แสดง help แทน
            return getHelpMessage();
        }
    } catch (Exception $e) {
        return getHelpMessage();
    }
}

// ดึงคำค้นหาสินค้าจากข้อความ
function extractProductKeyword($message) {
    $msg = $message;
    
    // ลบคำที่ไม่ใช่ keyword
    $removeWords = ['สินค้า', 'ตัว', 'นี้', 'หน่อย', 'ให้', 'ดู', 'หา', 'ค้นหา', 'ชื่อ', 'คือ', 'อะไร', 'ที่', 'มี', 'ไหม', 'บ้าง', 'product'];
    
    foreach ($removeWords as $word) {
        $msg = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', '', $msg);
    }
    
    $msg = trim(preg_replace('/\s+/', ' ', $msg));
    
    // ถ้าเหลือคำที่มีความยาวพอ ให้ใช้เป็น keyword
    if (mb_strlen($msg) >= 2 && !preg_match('/^(แพง|ถูก|หมด|ใกล้หมด|ทั้งหมด|ขายดี)$/u', $msg)) {
        return $msg;
    }
    
    return '';
}

// ดึงข้อมูลสินค้าสำหรับ AI context
function getProductContextForAI($db, $botId, $message) {
    $msg = mb_strtolower($message);
    $context = "\nข้อมูลสินค้า:\n";
    
    $table = 'products';
    
    try {
        // ถ้าถามสินค้าแพงสุด
        if (preg_match('/(แพง|ราคาสูง|expensive)/u', $msg)) {
            $stmt = $db->prepare("SELECT name, price FROM {$table} WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY price DESC LIMIT 5");
            $stmt->execute([$botId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $context .= "สินค้าราคาสูงสุด:\n";
            foreach ($products as $i => $p) {
                $context .= ($i+1) . ". {$p['name']} - ฿" . number_format($p['price'], 2) . "\n";
            }
        }
        // ถ้าถามสินค้าถูกสุด
        elseif (preg_match('/(ถูก|ราคาต่ำ|cheap)/u', $msg)) {
            $stmt = $db->prepare("SELECT name, price FROM {$table} WHERE is_active = 1 AND price > 0 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY price ASC LIMIT 5");
            $stmt->execute([$botId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $context .= "สินค้าราคาถูกสุด:\n";
            foreach ($products as $i => $p) {
                $context .= ($i+1) . ". {$p['name']} - ฿" . number_format($p['price'], 2) . "\n";
            }
        }
        // ข้อมูลทั่วไป
        else {
            $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock FROM {$table} WHERE (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$botId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $context .= "- สินค้าทั้งหมด: {$stats['total']} รายการ\n";
            $context .= "- สินค้าหมด: {$stats['out_of_stock']} รายการ\n";
        }
    } catch (Exception $e) {
        $context .= "ไม่สามารถดึงข้อมูลสินค้าได้\n";
    }
    
    return $context;
}

function getSystemContext($db, $botId) {
    $context = "";
    
    try {
        // ยอดขายวันนี้
        $stmt = $db->query("SELECT COUNT(*) as orders, COALESCE(SUM(grand_total), 0) as revenue FROM transactions WHERE DATE(created_at) = CURDATE() AND status IN ('paid', 'confirmed', 'delivered')");
        $sales = $stmt->fetch(PDO::FETCH_ASSOC);
        $context .= "- ยอดขายวันนี้: ฿" . number_format($sales['revenue']) . " ({$sales['orders']} ออเดอร์)\n";
        
        // ออเดอร์รอดำเนินการ
        $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
        $pending = $stmt->fetchColumn();
        $context .= "- ออเดอร์รอชำระ: {$pending} รายการ\n";
        
        // สลิปรอตรวจ
        $stmt = $db->query("SELECT COUNT(DISTINCT transaction_id) FROM payment_slips WHERE status = 'pending'");
        $slips = $stmt->fetchColumn();
        if ($slips > 0) $context .= "- สลิปรอตรวจ: {$slips} รายการ\n";
        
        // ลูกค้าใหม่วันนี้
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $newUsers = $stmt->fetchColumn();
        $context .= "- ลูกค้าใหม่วันนี้: {$newUsers} คน\n";
        
        // ข้อความรอตอบ
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE is_read = 0 AND direction = 'incoming' AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $unread = $stmt->fetchColumn();
        $context .= "- ข้อความรอตอบ: {$unread} ข้อความ\n";
        
    } catch (Exception $e) {
        $context = "ไม่สามารถดึงข้อมูลระบบได้";
    }
    
    return $context;
}

function callGeminiAPI($apiKey, $systemPrompt, $userMessage) {
    $models = ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-pro'];
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\nคำถาม: " . $userMessage]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 500,
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                return [
                    'success' => true,
                    'text' => trim($result['candidates'][0]['content']['parts'][0]['text']),
                    'model' => $model
                ];
            }
        }
        
        // ถ้า 404 (model not found) ลอง model ถัดไป
        if ($httpCode === 404) continue;
        
        // ถ้า error อื่น หยุดเลย
        break;
    }
    
    return ['success' => false, 'error' => 'API Error'];
}

// ==================== AI ACTIONS ====================

function actionDisableOutOfStock($db, $botId) {
    $table = 'products';
    
    try {
        // Count first
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE stock <= 0 AND is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            return ['text' => "✅ ไม่มีสินค้าหมดที่ยังเปิดขายอยู่", 'type' => 'action'];
        }
        
        // Disable
        $stmt = $db->prepare("UPDATE {$table} SET is_active = 0 WHERE stock <= 0 AND is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $affected = $stmt->rowCount();
        
        $text = "✅ **ปิดสินค้าหมดสำเร็จ!**\n\n";
        $text .= "📦 ปิดไปทั้งหมด: {$affected} รายการ\n\n";
        $text .= "💡 สินค้าเหล่านี้จะไม่แสดงในร้านค้าจนกว่าจะเปิดใหม่";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['affected' => $affected]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionEnableInStock($db, $botId) {
    $table = 'products';
    
    try {
        // Count first
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE stock > 0 AND is_active = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            return ['text' => "✅ ไม่มีสินค้าที่มี stock แต่ยังปิดอยู่", 'type' => 'action'];
        }
        
        // Enable
        $stmt = $db->prepare("UPDATE {$table} SET is_active = 1 WHERE stock > 0 AND is_active = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $affected = $stmt->rowCount();
        
        $text = "✅ **เปิดสินค้าที่มี stock สำเร็จ!**\n\n";
        $text .= "📦 เปิดไปทั้งหมด: {$affected} รายการ\n\n";
        $text .= "💡 สินค้าเหล่านี้จะแสดงในร้านค้าแล้ว";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['affected' => $affected]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionConfirmOrder($db, $orderId, $botId) {
    try {
        // Find order
        $stmt = $db->prepare("SELECT * FROM transactions WHERE (id = ? OR order_number LIKE ?) AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$orderId, "%{$orderId}%", $botId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return ['text' => "❌ ไม่พบออเดอร์ #{$orderId}", 'type' => 'error'];
        }
        
        if ($order['status'] === 'confirmed' || $order['status'] === 'delivered') {
            return ['text' => "⚠️ ออเดอร์ #{$order['order_number']} ยืนยันไปแล้ว (สถานะ: {$order['status']})", 'type' => 'warning'];
        }
        
        // Update status
        $stmt = $db->prepare("UPDATE transactions SET status = 'confirmed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$order['id']]);
        
        // Also approve slip if exists - check columns first
        try {
            // Try with verified_at column
            $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved', verified_at = NOW() WHERE transaction_id = ? AND status = 'pending'");
            $stmt->execute([$order['id']]);
        } catch (Exception $e) {
            // Fallback without verified_at
            try {
                $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved' WHERE transaction_id = ? AND status = 'pending'");
                $stmt->execute([$order['id']]);
            } catch (Exception $e2) {
                // Ignore if payment_slips table doesn't exist
            }
        }
        
        $text = "✅ **ยืนยันออเดอร์สำเร็จ!**\n\n";
        $text .= "📦 ออเดอร์: #{$order['order_number']}\n";
        $text .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
        $text .= "📋 สถานะ: {$order['status']} → confirmed\n\n";
        $text .= "💡 ลูกค้าจะได้รับแจ้งเตือนอัตโนมัติ";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['order_id' => $order['id']]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionCancelOrder($db, $orderId, $botId) {
    try {
        // Find order
        $stmt = $db->prepare("SELECT * FROM transactions WHERE (id = ? OR order_number LIKE ?) AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$orderId, "%{$orderId}%", $botId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return ['text' => "❌ ไม่พบออเดอร์ #{$orderId}", 'type' => 'error'];
        }
        
        if ($order['status'] === 'cancelled') {
            return ['text' => "⚠️ ออเดอร์ #{$order['order_number']} ยกเลิกไปแล้ว", 'type' => 'warning'];
        }
        
        if ($order['status'] === 'delivered') {
            return ['text' => "⚠️ ไม่สามารถยกเลิกออเดอร์ที่ส่งแล้วได้", 'type' => 'warning'];
        }
        
        // Update status
        $stmt = $db->prepare("UPDATE transactions SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$order['id']]);
        
        $text = "✅ **ยกเลิกออเดอร์สำเร็จ!**\n\n";
        $text .= "📦 ออเดอร์: #{$order['order_number']}\n";
        $text .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
        $text .= "📋 สถานะ: {$order['status']} → cancelled\n";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['order_id' => $order['id']]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// ==================== SLIP MANAGEMENT ====================

function getPendingSlips($db, $botId) {
    try {
        $stmt = $db->query("
            SELECT ps.*, t.order_number, t.grand_total, u.display_name
            FROM payment_slips ps
            JOIN transactions t ON ps.transaction_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE ps.status = 'pending'
            ORDER BY ps.created_at ASC
            LIMIT 10
        ");
        $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($slips)) {
            return ['text' => "✅ ไม่มีสลิปรอตรวจสอบ", 'type' => 'info'];
        }
        
        $text = "🧾 **สลิปรอตรวจสอบ** (" . count($slips) . " รายการ)\n";
        $text .= "━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($slips as $slip) {
            $age = floor((time() - strtotime($slip['created_at'])) / 3600);
            $ageText = $age < 1 ? 'เมื่อกี้' : "{$age} ชม.";
            
            $text .= "📦 **#{$slip['order_number']}**\n";
            $text .= "   👤 {$slip['display_name']}\n";
            $text .= "   💰 ฿" . number_format($slip['grand_total']) . "\n";
            $text .= "   ⏰ {$ageText}\n";
            $text .= "   💡 พิมพ์ \"ยืนยันสลิป #{$slip['order_number']}\"\n\n";
        }
        
        $text .= "📌 คำสั่ง:\n";
        $text .= "• ยืนยันสลิป #เลขออเดอร์\n";
        $text .= "• ปฏิเสธสลิป #เลขออเดอร์";
        
        return ['text' => $text, 'type' => 'slips', 'data' => $slips];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionApproveSlip($db, $orderId, $botId) {
    try {
        // Find order by order_number or id
        $stmt = $db->prepare("SELECT t.*, u.display_name FROM transactions t 
            LEFT JOIN users u ON t.user_id = u.id
            WHERE (t.id = ? OR t.order_number LIKE ?) AND (t.line_account_id = ? OR t.line_account_id IS NULL)");
        $stmt->execute([$orderId, "%{$orderId}%", $botId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return ['text' => "❌ ไม่พบออเดอร์ #{$orderId}", 'type' => 'error'];
        }
        
        // Check if slip exists
        $stmt = $db->prepare("SELECT * FROM payment_slips WHERE transaction_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$order['id']]);
        $slip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$slip) {
            return ['text' => "❌ ไม่พบสลิปสำหรับออเดอร์ #{$order['order_number']}", 'type' => 'error'];
        }
        
        if ($slip['status'] === 'approved') {
            return ['text' => "⚠️ สลิปออเดอร์ #{$order['order_number']} อนุมัติไปแล้ว", 'type' => 'warning'];
        }
        
        // Update slip status
        try {
            $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved', verified_at = NOW() WHERE id = ?");
            $stmt->execute([$slip['id']]);
        } catch (Exception $e) {
            $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved' WHERE id = ?");
            $stmt->execute([$slip['id']]);
        }
        
        // Update order status to paid AND payment_status to paid
        $stmt = $db->prepare("UPDATE transactions SET status = 'paid', payment_status = 'paid', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$order['id']]);
        
        $text = "✅ **อนุมัติสลิปสำเร็จ!**\n\n";
        $text .= "📦 ออเดอร์: #{$order['order_number']}\n";
        $text .= "👤 ลูกค้า: {$order['display_name']}\n";
        $text .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n";
        $text .= "📋 สถานะ: {$order['status']} → 💰 ชำระแล้ว\n\n";
        $text .= "💡 ลูกค้าจะได้รับแจ้งเตือนอัตโนมัติ";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['order_id' => $order['id'], 'slip_id' => $slip['id']]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionRejectSlip($db, $orderId, $botId) {
    try {
        // Find order
        $stmt = $db->prepare("SELECT t.*, u.display_name FROM transactions t 
            LEFT JOIN users u ON t.user_id = u.id
            WHERE (t.id = ? OR t.order_number LIKE ?) AND (t.line_account_id = ? OR t.line_account_id IS NULL)");
        $stmt->execute([$orderId, "%{$orderId}%", $botId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return ['text' => "❌ ไม่พบออเดอร์ #{$orderId}", 'type' => 'error'];
        }
        
        // Check if slip exists
        $stmt = $db->prepare("SELECT * FROM payment_slips WHERE transaction_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$order['id']]);
        $slip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$slip) {
            return ['text' => "❌ ไม่พบสลิปสำหรับออเดอร์ #{$order['order_number']}", 'type' => 'error'];
        }
        
        if ($slip['status'] === 'rejected') {
            return ['text' => "⚠️ สลิปออเดอร์ #{$order['order_number']} ปฏิเสธไปแล้ว", 'type' => 'warning'];
        }
        
        // Update slip status
        $stmt = $db->prepare("UPDATE payment_slips SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$slip['id']]);
        
        // Keep order as pending so customer can upload new slip
        
        $text = "❌ **ปฏิเสธสลิปแล้ว**\n\n";
        $text .= "📦 ออเดอร์: #{$order['order_number']}\n";
        $text .= "👤 ลูกค้า: {$order['display_name']}\n";
        $text .= "💰 ยอด: ฿" . number_format($order['grand_total'], 2) . "\n\n";
        $text .= "💡 ลูกค้าสามารถอัพโหลดสลิปใหม่ได้";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['order_id' => $order['id'], 'slip_id' => $slip['id']]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionCreateBroadcastDraft($db, $targetType, $botId) {
    try {
        $targetName = '';
        $userCount = 0;
        
        switch ($targetType) {
            case 'new_users_7days':
                $targetName = 'ลูกค้าใหม่ 7 วัน';
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$botId]);
                $userCount = $stmt->fetchColumn();
                break;
        }
        
        // Create draft broadcast
        $stmt = $db->prepare("INSERT INTO broadcasts (line_account_id, name, message, target_type, target_filter, status, created_at) VALUES (?, ?, ?, 'filter', ?, 'draft', NOW())");
        $stmt->execute([
            $botId,
            "AI Draft: {$targetName}",
            "สวัสดีค่ะ! ขอบคุณที่เป็นลูกค้าใหม่ของเรา 🎉\n\nมีโปรโมชั่นพิเศษสำหรับคุณ...",
            json_encode(['type' => $targetType])
        ]);
        $broadcastId = $db->lastInsertId();
        
        $text = "✅ **สร้าง Broadcast Draft สำเร็จ!**\n\n";
        $text .= "📢 ชื่อ: AI Draft: {$targetName}\n";
        $text .= "👥 กลุ่มเป้าหมาย: {$userCount} คน\n";
        $text .= "📋 สถานะ: Draft\n\n";
        $text .= "💡 ไปแก้ไขและส่งได้ที่หน้า Broadcast\n";
        $text .= "🔗 [เปิดหน้า Broadcast](broadcast.php?edit={$broadcastId})";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['broadcast_id' => $broadcastId, 'url' => "broadcast.php?edit={$broadcastId}"]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

function actionOpenChat($db, $customerName, $botId) {
    try {
        $stmt = $db->prepare("SELECT id, display_name, line_user_id FROM users WHERE display_name LIKE ? AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 1");
        $stmt->execute(["%{$customerName}%", $botId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['text' => "❌ ไม่พบลูกค้าชื่อ: {$customerName}", 'type' => 'error'];
        }
        
        $text = "💬 **เปิดแชทกับลูกค้า**\n\n";
        $text .= "👤 ชื่อ: {$user['display_name']}\n\n";
        $text .= "🔗 [คลิกเพื่อเปิดแชท](messages.php?user={$user['id']})";
        
        return ['text' => $text, 'type' => 'action', 'data' => ['user_id' => $user['id'], 'url' => "messages.php?user={$user['id']}"]];
    } catch (Exception $e) {
        return ['text' => '❌ เกิดข้อผิดพลาด: ' . $e->getMessage(), 'type' => 'error'];
    }
}

// ==================== SMART ALERTS ====================

function getSmartAlerts($db, $botId) {
    $alerts = [];
    $text = "🚨 **Smart Alerts - ตรวจสอบปัญหา**\n";
    $text .= "━━━━━━━━━━━━━━━━━━\n\n";
    
    try {
        // 1. สลิปรอตรวจนาน (> 2 ชั่วโมง)
        $stmt = $db->query("SELECT COUNT(*) FROM payment_slips WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $oldSlips = $stmt->fetchColumn();
        if ($oldSlips > 0) {
            $alerts[] = ['type' => 'urgent', 'icon' => '🔴', 'msg' => "สลิปรอตรวจนานกว่า 2 ชม.: {$oldSlips} รายการ", 'action' => 'shop/orders.php?pending_slip=1'];
        }
        
        // 2. ออเดอร์ค้างนาน (pending > 24 ชม.)
        $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $oldOrders = $stmt->fetchColumn();
        if ($oldOrders > 0) {
            $alerts[] = ['type' => 'warning', 'icon' => '🟠', 'msg' => "ออเดอร์ค้างนานกว่า 24 ชม.: {$oldOrders} รายการ", 'action' => 'shop/orders.php?status=pending'];
        }
        
        // 3. สินค้าหมดแต่ยังเปิดขาย
        $table = 'products';
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE stock <= 0 AND is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $outOfStock = $stmt->fetchColumn();
        if ($outOfStock > 0) {
            $alerts[] = ['type' => 'warning', 'icon' => '🟠', 'msg' => "สินค้าหมดแต่ยังเปิดขาย: {$outOfStock} รายการ", 'action' => 'ปิดสินค้าหมด'];
        }
        
        // 4. สินค้าใกล้หมด (stock 1-5)
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE stock > 0 AND stock <= 5 AND is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $lowStock = $stmt->fetchColumn();
        if ($lowStock > 0) {
            $alerts[] = ['type' => 'info', 'icon' => '🟡', 'msg' => "สินค้าใกล้หมด (stock ≤5): {$lowStock} รายการ", 'action' => 'shop/products.php?filter=low_stock'];
        }
        
        // 5. ข้อความรอตอบนาน (> 1 ชม.)
        $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE is_read = 0 AND direction = 'incoming' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$botId]);
        $oldMessages = $stmt->fetchColumn();
        if ($oldMessages > 0) {
            $alerts[] = ['type' => 'warning', 'icon' => '🟠', 'msg' => "ข้อความรอตอบนานกว่า 1 ชม.: {$oldMessages} ข้อความ", 'action' => 'messages.php'];
        }
        
        // 6. ยอดขายตกผิดปกติ (วันนี้ < 50% ของค่าเฉลี่ย 7 วัน)
        $stmt = $db->query("SELECT COALESCE(SUM(grand_total), 0) FROM transactions WHERE DATE(created_at) = CURDATE() AND status IN ('paid', 'confirmed', 'delivered')");
        $todaySales = $stmt->fetchColumn();
        
        $stmt = $db->query("SELECT COALESCE(AVG(daily_total), 0) FROM (
            SELECT DATE(created_at) as dt, SUM(grand_total) as daily_total 
            FROM transactions 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND created_at < CURDATE() AND status IN ('paid', 'confirmed', 'delivered')
            GROUP BY DATE(created_at)
        ) as daily");
        $avgSales = $stmt->fetchColumn();
        
        if ($avgSales > 0 && $todaySales < ($avgSales * 0.5)) {
            $percent = round(($todaySales / $avgSales) * 100);
            $alerts[] = ['type' => 'warning', 'icon' => '📉', 'msg' => "ยอดขายวันนี้ต่ำกว่าปกติ ({$percent}% ของค่าเฉลี่ย)", 'action' => 'analytics.php'];
        }
        
        // 7. ลูกค้า VIP ส่งข้อความ (ลูกค้าที่ซื้อ > 5 ครั้ง)
        $stmt = $db->query("SELECT COUNT(DISTINCT m.user_id) FROM messages m
            INNER JOIN (
                SELECT user_id FROM transactions WHERE status IN ('paid', 'confirmed', 'delivered') GROUP BY user_id HAVING COUNT(*) >= 5
            ) vip ON m.user_id = vip.user_id
            WHERE m.is_read = 0 AND m.direction = 'incoming'");
        $vipMessages = $stmt->fetchColumn();
        if ($vipMessages > 0) {
            $alerts[] = ['type' => 'urgent', 'icon' => '⭐', 'msg' => "ลูกค้า VIP ส่งข้อความรอตอบ: {$vipMessages} คน", 'action' => 'messages.php'];
        }
        
    } catch (Exception $e) {
        $text .= "❌ เกิดข้อผิดพลาดในการตรวจสอบ\n";
    }
    
    // Display alerts
    if (empty($alerts)) {
        $text .= "✅ **ไม่พบปัญหาที่ต้องดำเนินการ**\n\n";
        $text .= "ระบบทำงานปกติ 👍";
    } else {
        // Sort by priority
        usort($alerts, function($a, $b) {
            $priority = ['urgent' => 0, 'warning' => 1, 'info' => 2];
            return ($priority[$a['type']] ?? 3) - ($priority[$b['type']] ?? 3);
        });
        
        $urgentCount = count(array_filter($alerts, function($a) { return $a['type'] === 'urgent'; }));
        $warningCount = count(array_filter($alerts, function($a) { return $a['type'] === 'warning'; }));
        
        if ($urgentCount > 0) {
            $text .= "🔴 **เร่งด่วน ({$urgentCount})**\n";
            foreach ($alerts as $alert) {
                if ($alert['type'] === 'urgent') {
                    $text .= "{$alert['icon']} {$alert['msg']}\n";
                }
            }
            $text .= "\n";
        }
        
        if ($warningCount > 0) {
            $text .= "🟠 **ควรดำเนินการ ({$warningCount})**\n";
            foreach ($alerts as $alert) {
                if ($alert['type'] === 'warning') {
                    $text .= "{$alert['icon']} {$alert['msg']}\n";
                }
            }
            $text .= "\n";
        }
        
        $infoCount = count($alerts) - $urgentCount - $warningCount;
        if ($infoCount > 0) {
            $text .= "🟡 **แจ้งเตือน ({$infoCount})**\n";
            foreach ($alerts as $alert) {
                if ($alert['type'] === 'info') {
                    $text .= "{$alert['icon']} {$alert['msg']}\n";
                }
            }
        }
        
        $text .= "\n💡 พิมพ์ \"ปิดสินค้าหมด\" เพื่อปิดสินค้าหมดทั้งหมด";
    }
    
    return ['text' => $text, 'type' => 'alerts', 'data' => $alerts];
}
