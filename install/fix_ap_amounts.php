<?php
/**
 * Fix AP Amounts - แก้ไขยอด AP ที่สร้างผิดจาก partial GR
 * 
 * ปัญหา: AP ใช้ยอดรวมของ PO แทนยอดที่รับจริงใน GR
 * วิธีแก้: คำนวณยอดใหม่จาก goods_receive_items
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Fix AP Amounts from GR</h2>";
echo "<style>body{font-family:sans-serif;padding:20px;} .ok{color:green;} .fix{color:orange;} .error{color:red;} table{border-collapse:collapse;margin:10px 0;} td,th{border:1px solid #ddd;padding:8px;}</style>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all AP records that have gr_id
    $stmt = $db->query("
        SELECT ap.id, ap.ap_number, ap.gr_id, ap.total_amount, ap.balance, ap.paid_amount, ap.status,
               gr.gr_number, po.po_number, po.total_amount as po_total
        FROM account_payables ap
        LEFT JOIN goods_receives gr ON ap.gr_id = gr.id
        LEFT JOIN purchase_orders po ON ap.po_id = po.id
        WHERE ap.gr_id IS NOT NULL
        ORDER BY ap.id DESC
    ");
    $aps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>พบ AP ที่มี GR: <strong>" . count($aps) . "</strong> รายการ</p>";
    
    if (empty($aps)) {
        echo "<p class='ok'>✅ ไม่มี AP ที่ต้องแก้ไข</p>";
        exit;
    }
    
    echo "<table>";
    echo "<tr><th>AP#</th><th>GR#</th><th>ยอดเดิม</th><th>ยอดที่ถูกต้อง</th><th>สถานะ</th><th>Action</th></tr>";
    
    $fixCount = 0;
    $errors = [];
    
    foreach ($aps as $ap) {
        // Calculate correct amount from GR items
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(gri.quantity * poi.unit_cost), 0) as correct_total
            FROM goods_receive_items gri
            LEFT JOIN purchase_order_items poi ON gri.po_item_id = poi.id
            WHERE gri.gr_id = ?
        ");
        $stmt->execute([$ap['gr_id']]);
        $correctTotal = (float)$stmt->fetchColumn();
        
        $currentTotal = (float)$ap['total_amount'];
        $needsFix = abs($currentTotal - $correctTotal) > 0.01 && $correctTotal > 0;
        
        echo "<tr>";
        echo "<td>{$ap['ap_number']}</td>";
        echo "<td>{$ap['gr_number']}</td>";
        echo "<td>" . number_format($currentTotal, 2) . "</td>";
        echo "<td>" . number_format($correctTotal, 2) . "</td>";
        
        if ($needsFix && $correctTotal > 0) {
            // Calculate new balance
            $paidAmount = (float)$ap['paid_amount'];
            $newBalance = $correctTotal - $paidAmount;
            
            // Determine new status
            if ($newBalance <= 0) {
                $newStatus = 'paid';
            } elseif ($paidAmount > 0) {
                $newStatus = 'partial';
            } else {
                $newStatus = 'open';
            }
            
            if (isset($_GET['fix']) && $_GET['fix'] == '1') {
                // Actually fix it
                try {
                    $stmt = $db->prepare("
                        UPDATE account_payables 
                        SET total_amount = ?, balance = ?, status = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$correctTotal, max(0, $newBalance), $newStatus, $ap['id']]);
                    
                    echo "<td class='fix'>แก้ไขแล้ว → {$newStatus}</td>";
                    echo "<td class='ok'>✅ Fixed</td>";
                    $fixCount++;
                } catch (Exception $e) {
                    echo "<td class='error'>Error</td>";
                    echo "<td class='error'>" . $e->getMessage() . "</td>";
                    $errors[] = $ap['ap_number'] . ': ' . $e->getMessage();
                }
            } else {
                echo "<td class='fix'>ต้องแก้ไข → {$newStatus}</td>";
                echo "<td>-</td>";
            }
        } else {
            echo "<td class='ok'>ถูกต้อง</td>";
            echo "<td>-</td>";
        }
        echo "</tr>";
    }
    
    echo "</table>";
    
    if (!isset($_GET['fix'])) {
        echo "<p><a href='?fix=1' style='background:#f59e0b;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>🔧 แก้ไขทั้งหมด</a></p>";
        echo "<p style='color:#666;'>คลิกปุ่มด้านบนเพื่อแก้ไขยอด AP ที่ผิด</p>";
    } else {
        echo "<p class='ok'>✅ แก้ไขเสร็จสิ้น: {$fixCount} รายการ</p>";
        if (!empty($errors)) {
            echo "<p class='error'>❌ Errors:</p><ul>";
            foreach ($errors as $err) {
                echo "<li class='error'>{$err}</li>";
            }
            echo "</ul>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}
