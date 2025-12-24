<?php
/**
 * Scheduled Reports Cron Job
 * ส่งรายงานอัตโนมัติทาง LINE ตามเวลาที่กำหนด
 * 
 * ตั้ง cron ให้รันทุกนาที:
 * * * * * * php /path/to/cron/scheduled_reports.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';

$db = Database::getInstance()->getConnection();

// Get current time
$currentTime = date('H:i');
$currentDayOfWeek = date('w'); // 0 = Sunday
$currentDayOfMonth = date('j');

echo "[" . date('Y-m-d H:i:s') . "] Checking scheduled reports...\n";

try {
    // Get reports that should run now
    $stmt = $db->prepare("
        SELECT sr.*, la.channel_access_token 
        FROM scheduled_reports sr
        LEFT JOIN line_accounts la ON sr.line_account_id = la.id
        WHERE sr.is_active = 1 
        AND TIME_FORMAT(sr.schedule_time, '%H:%i') = ?
        AND (
            sr.schedule_type = 'daily'
            OR (sr.schedule_type = 'weekly' AND sr.schedule_day = ?)
            OR (sr.schedule_type = 'monthly' AND sr.schedule_day = ?)
        )
        AND (sr.last_sent_at IS NULL OR DATE(sr.last_sent_at) < CURDATE())
    ");
    $stmt->execute([$currentTime, $currentDayOfWeek, $currentDayOfMonth]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($reports) . " reports to send\n";
    
    foreach ($reports as $report) {
        echo "Processing report: {$report['name']} (ID: {$report['id']})\n";
        
        // Generate report content
        $reportData = generateReport($db, $report);
        
        if (!$reportData) {
            echo "  - Failed to generate report\n";
            continue;
        }
        
        // Get recipients
        $stmt = $db->prepare("
            SELECT srr.*, au.username, au.display_name, au.line_user_id as admin_line_id
            FROM scheduled_report_recipients srr
            JOIN admin_users au ON srr.admin_user_id = au.id
            WHERE srr.report_id = ?
        ");
        $stmt->execute([$report['id']]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "  - Recipients: " . count($recipients) . "\n";
        
        $successCount = 0;
        $failCount = 0;
        
        // Send to each recipient
        foreach ($recipients as $recipient) {
            $lineUserId = $recipient['line_user_id'] ?: $recipient['admin_line_id'];
            
            if (empty($lineUserId)) {
                echo "    - {$recipient['display_name']}: No LINE User ID\n";
                $failCount++;
                continue;
            }
            
            if ($recipient['notify_method'] === 'line' || $recipient['notify_method'] === 'both') {
                $sent = sendLineReport($report['channel_access_token'], $lineUserId, $reportData);
                if ($sent) {
                    echo "    - {$recipient['display_name']}: Sent via LINE\n";
                    $successCount++;
                } else {
                    echo "    - {$recipient['display_name']}: Failed to send\n";
                    $failCount++;
                }
            }
        }
        
        // Log the report
        $status = $failCount === 0 ? 'success' : ($successCount > 0 ? 'partial' : 'failed');
        $stmt = $db->prepare("
            INSERT INTO scheduled_report_logs (report_id, sent_at, recipients_count, status, report_data)
            VALUES (?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([$report['id'], $successCount, $status, json_encode($reportData)]);
        
        // Update last_sent_at
        $stmt = $db->prepare("UPDATE scheduled_reports SET last_sent_at = NOW() WHERE id = ?");
        $stmt->execute([$report['id']]);
        
        echo "  - Completed: {$successCount} success, {$failCount} failed\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

function generateReport($db, $report) {
    $data = [];
    
    switch ($report['report_type']) {
        case 'daily_sales':
            $data = generateDailySalesReport($db, $report['line_account_id']);
            break;
        case 'weekly_summary':
            $data = generateWeeklySummaryReport($db, $report['line_account_id']);
            break;
        case 'low_stock_alert':
            $data = generateLowStockReport($db, $report['line_account_id']);
            break;
        case 'pending_orders':
            $data = generatePendingOrdersReport($db, $report['line_account_id']);
            break;
        default:
            return null;
    }
    
    return $data;
}

function generateDailySalesReport($db, $botId) {
    // Yesterday's sales
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(grand_total), 0) as revenue,
            COALESCE(AVG(grand_total), 0) as avg_order
        FROM transactions 
        WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        AND status IN ('paid', 'confirmed', 'delivered')
    ");
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // New users yesterday
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$botId]);
    $newUsers = $stmt->fetchColumn();
    
    // Pending orders
    $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
    $pendingOrders = $stmt->fetchColumn();
    
    // Pending slips
    $stmt = $db->query("SELECT COUNT(DISTINCT transaction_id) FROM payment_slips WHERE status = 'pending'");
    $pendingSlips = $stmt->fetchColumn();
    
    $date = date('d/m/Y', strtotime('-1 day'));
    
    $text = "📊 รายงานยอดขายประจำวัน\n";
    $text .= "📅 {$date}\n";
    $text .= "━━━━━━━━━━━━━━━\n\n";
    $text .= "💰 รายได้: ฿" . number_format($sales['revenue'], 2) . "\n";
    $text .= "📦 ออเดอร์: " . number_format($sales['total_orders']) . " รายการ\n";
    $text .= "📈 เฉลี่ย/ออเดอร์: ฿" . number_format($sales['avg_order'], 2) . "\n";
    $text .= "👥 ลูกค้าใหม่: {$newUsers} คน\n\n";
    
    if ($pendingOrders > 0 || $pendingSlips > 0) {
        $text .= "⚠️ รอดำเนินการ:\n";
        if ($pendingOrders > 0) $text .= "  • ออเดอร์รอชำระ: {$pendingOrders}\n";
        if ($pendingSlips > 0) $text .= "  • สลิปรอตรวจ: {$pendingSlips}\n";
    }
    
    return [
        'type' => 'daily_sales',
        'date' => $date,
        'text' => $text,
        'data' => [
            'revenue' => $sales['revenue'],
            'orders' => $sales['total_orders'],
            'new_users' => $newUsers,
            'pending_orders' => $pendingOrders,
            'pending_slips' => $pendingSlips
        ]
    ];
}

function generateWeeklySummaryReport($db, $botId) {
    // This week's sales
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(grand_total), 0) as revenue
        FROM transactions 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND status IN ('paid', 'confirmed', 'delivered')
    ");
    $thisWeek = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Last week's sales
    $stmt = $db->query("
        SELECT COALESCE(SUM(grand_total), 0) as revenue
        FROM transactions 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        AND created_at < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND status IN ('paid', 'confirmed', 'delivered')
    ");
    $lastWeek = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate change
    $change = $lastWeek['revenue'] > 0 
        ? (($thisWeek['revenue'] - $lastWeek['revenue']) / $lastWeek['revenue'] * 100) 
        : 0;
    $changeIcon = $change >= 0 ? '📈' : '📉';
    $changeText = ($change >= 0 ? '+' : '') . number_format($change, 1) . '%';
    
    // New users this week
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$botId]);
    $newUsers = $stmt->fetchColumn();
    
    // Top products
    $topProducts = [];
    try {
        $stmt = $db->query("
            SELECT oi.product_name, SUM(oi.quantity) as qty
            FROM order_items oi
            JOIN transactions t ON oi.transaction_id = t.id
            WHERE t.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            AND t.status IN ('paid', 'confirmed', 'delivered')
            GROUP BY oi.product_id
            ORDER BY qty DESC
            LIMIT 3
        ");
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    
    $dateRange = date('d/m', strtotime('-7 days')) . ' - ' . date('d/m/Y');
    
    $text = "📊 รายงานสรุปประจำสัปดาห์\n";
    $text .= "📅 {$dateRange}\n";
    $text .= "━━━━━━━━━━━━━━━\n\n";
    $text .= "💰 รายได้รวม: ฿" . number_format($thisWeek['revenue'], 2) . "\n";
    $text .= "{$changeIcon} เทียบสัปดาห์ก่อน: {$changeText}\n";
    $text .= "📦 ออเดอร์: " . number_format($thisWeek['total_orders']) . " รายการ\n";
    $text .= "👥 ลูกค้าใหม่: {$newUsers} คน\n";
    
    if (!empty($topProducts)) {
        $text .= "\n🏆 สินค้าขายดี:\n";
        foreach ($topProducts as $i => $p) {
            $medal = ['🥇', '🥈', '🥉'][$i];
            $text .= "{$medal} {$p['product_name']} ({$p['qty']} ชิ้น)\n";
        }
    }
    
    return [
        'type' => 'weekly_summary',
        'date_range' => $dateRange,
        'text' => $text,
        'data' => [
            'revenue' => $thisWeek['revenue'],
            'orders' => $thisWeek['total_orders'],
            'change_percent' => $change,
            'new_users' => $newUsers
        ]
    ];
}

function generateLowStockReport($db, $botId) {
    $table = 'products';
    
    $stmt = $db->prepare("
        SELECT name, sku, stock 
        FROM {$table} 
        WHERE stock > 0 AND stock <= 5 AND is_active = 1 
        AND (line_account_id = ? OR line_account_id IS NULL)
        ORDER BY stock ASC
        LIMIT 10
    ");
    $stmt->execute([$botId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Out of stock
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE stock <= 0 AND is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$botId]);
    $outOfStock = $stmt->fetchColumn();
    
    $text = "⚠️ แจ้งเตือนสินค้าใกล้หมด\n";
    $text .= "📅 " . date('d/m/Y H:i') . "\n";
    $text .= "━━━━━━━━━━━━━━━\n\n";
    
    if ($outOfStock > 0) {
        $text .= "🔴 สินค้าหมด: {$outOfStock} รายการ\n\n";
    }
    
    if (!empty($products)) {
        $text .= "🟡 สินค้าใกล้หมด:\n";
        foreach ($products as $p) {
            $text .= "• {$p['name']} (เหลือ {$p['stock']})\n";
        }
    } else {
        $text .= "✅ ไม่มีสินค้าใกล้หมด";
    }
    
    return [
        'type' => 'low_stock',
        'text' => $text,
        'data' => [
            'out_of_stock' => $outOfStock,
            'low_stock_count' => count($products),
            'products' => $products
        ]
    ];
}

function generatePendingOrdersReport($db, $botId) {
    // Pending orders
    $stmt = $db->query("
        SELECT t.*, u.display_name 
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.status = 'pending'
        ORDER BY t.created_at ASC
        LIMIT 10
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pending slips
    $stmt = $db->query("SELECT COUNT(DISTINCT transaction_id) FROM payment_slips WHERE status = 'pending'");
    $pendingSlips = $stmt->fetchColumn();
    
    $text = "📦 รายงานออเดอร์รอดำเนินการ\n";
    $text .= "📅 " . date('d/m/Y H:i') . "\n";
    $text .= "━━━━━━━━━━━━━━━\n\n";
    
    $text .= "⏳ รอชำระ: " . count($orders) . " รายการ\n";
    $text .= "🧾 รอตรวจสลิป: {$pendingSlips} รายการ\n\n";
    
    if (!empty($orders)) {
        $text .= "📋 รายการล่าสุด:\n";
        foreach (array_slice($orders, 0, 5) as $o) {
            $age = floor((time() - strtotime($o['created_at'])) / 3600);
            $text .= "• #{$o['order_number']} - ฿" . number_format($o['grand_total']) . " ({$age}ชม.)\n";
        }
    }
    
    return [
        'type' => 'pending_orders',
        'text' => $text,
        'data' => [
            'pending_count' => count($orders),
            'pending_slips' => $pendingSlips
        ]
    ];
}

function sendLineReport($accessToken, $userId, $reportData) {
    if (empty($accessToken) || empty($userId)) {
        return false;
    }
    
    try {
        $lineAPI = new LineAPI($accessToken);
        return $lineAPI->pushMessage($userId, $reportData['text']);
    } catch (Exception $e) {
        error_log("Failed to send LINE report: " . $e->getMessage());
        return false;
    }
}
