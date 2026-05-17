<?php
/**
 * RefillTrackingHelper
 * คำนวณวันยาหมดจากรายการที่เภสัชกรจ่าย แล้วใส่ลง medication_refill_tracking
 * เพื่อให้ cron/medication_refill_reminder.php ส่งแจ้งเตือนล่วงหน้า
 */

class RefillTrackingHelper
{
    /**
     * แยก pack_size จาก unit string เช่น "1 กล่อง[50เม็ด]" → 50
     * รองรับรูปแบบ [N], [Nเม็ด], [Nซีซี], [Nแคปซูล] ฯลฯ
     * คืน 1 ถ้าไม่พบตัวเลข (เช่น unit ว่าง หรือ "ชิ้น")
     */
    public static function parsePackSize(string $unit): int
    {
        if ($unit === '') {
            return 1;
        }
        if (preg_match('/\[(\d+)/', $unit, $m)) {
            return max(1, intval($m[1]));
        }
        return 1;
    }

    public static function ensureTable(PDO $db): void
    {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS medication_refill_tracking (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                line_user_id VARCHAR(50),
                line_account_id INT,
                product_id INT NOT NULL,
                product_name VARCHAR(255),
                quantity_purchased INT DEFAULT 0,
                daily_dosage INT DEFAULT 1 COMMENT 'จำนวนที่ทานต่อวัน (รวมทุกมื้อ)',
                purchase_date DATE,
                estimated_end_date DATE,
                reminder_sent_at TIMESTAMP NULL,
                order_id INT,
                source VARCHAR(50) DEFAULT NULL COMMENT 'dispense | order | manual',
                source_ref_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_end_date (estimated_end_date),
                INDEX idx_product (product_id),
                INDEX idx_user_product (user_id, product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {
            error_log("RefillTrackingHelper::ensureTable: " . $e->getMessage());
        }
    }

    /**
     * รับรายการ dispense items แล้วบันทึก refill tracking สำหรับเฉพาะรายการที่เป็นยา (isMedicine=true)
     * @param array $ctx ['user_id', 'line_user_id', 'line_account_id', 'dispense_id']
     */
    public static function trackFromDispense(PDO $db, array $items, array $ctx): void
    {
        self::ensureTable($db);

        $userId = intval($ctx['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $lineUserId    = $ctx['line_user_id'] ?? null;
        $lineAccountId = $ctx['line_account_id'] ?? null;
        $dispenseId    = $ctx['dispense_id'] ?? null;

        foreach ($items as $item) {
            $isMedicine = !empty($item['isMedicine']) && $item['isMedicine'] !== false;
            if (!$isMedicine) {
                continue;
            }

            $productId = intval($item['product_id'] ?? 0);
            $qtyUnits  = intval($item['qty'] ?? 0);
            if ($productId <= 0 || $qtyUnits <= 0) {
                continue;
            }

            // แปลง qty (จำนวนกล่อง/หลอด) → จำนวนเม็ด/โดสจริง
            // unit รูป "กล่อง[50เม็ด]" หรือ "[10ซีซี]" → ดึงตัวเลขใน [ ] เป็น pack_size
            $packSize = self::parsePackSize($item['unit'] ?? '');
            $totalDoses = $qtyUnits * $packSize;

            $dosagePerTime = max(1, intval($item['dosage'] ?? 1));
            $timeOfDay     = $item['timeOfDay'] ?? [];
            $timesPerDay   = is_array($timeOfDay) && count($timeOfDay) > 0 ? count($timeOfDay) : 1;
            $dailyDosage   = $dosagePerTime * $timesPerDay;

            $daysSupply       = max(1, (int) ceil($totalDoses / $dailyDosage));
            $estimatedEndDate = date('Y-m-d', strtotime("+{$daysSupply} days"));
            $productName      = $item['name'] ?? '';
            $qty              = $totalDoses;  // เก็บเป็นจำนวนโดสจริงเพื่อให้ display คำนวณ remaining ถูก

            try {
                // Dedupe: ถ้ามี tracking ที่ยัง active สำหรับ (user, product) → ต่ออายุแทน insert ใหม่
                $stmt = $db->prepare("SELECT id, estimated_end_date FROM medication_refill_tracking
                    WHERE user_id = ? AND product_id = ? AND estimated_end_date >= CURDATE()
                    ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId, $productId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $newEndDate = date('Y-m-d', strtotime($existing['estimated_end_date'] . " +{$daysSupply} days"));
                    $stmt = $db->prepare("UPDATE medication_refill_tracking SET
                        quantity_purchased = quantity_purchased + ?,
                        daily_dosage       = ?,
                        estimated_end_date = ?,
                        reminder_sent_at   = NULL
                        WHERE id = ?");
                    $stmt->execute([$qty, $dailyDosage, $newEndDate, $existing['id']]);
                } else {
                    $stmt = $db->prepare("INSERT INTO medication_refill_tracking
                        (user_id, line_user_id, line_account_id, product_id, product_name,
                         quantity_purchased, daily_dosage, purchase_date, estimated_end_date,
                         order_id, source, source_ref_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'dispense', ?)");
                    $stmt->execute([
                        $userId,
                        $lineUserId,
                        $lineAccountId,
                        $productId,
                        $productName,
                        $qty,
                        $dailyDosage,
                        $estimatedEndDate,
                        $dispenseId,
                        $dispenseId,
                    ]);
                }
            } catch (Exception $e) {
                error_log("RefillTrackingHelper::trackFromDispense: " . $e->getMessage());
            }
        }
    }
}
