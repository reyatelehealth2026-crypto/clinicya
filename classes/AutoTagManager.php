<?php
/**
 * Auto Tag Manager - ระบบติด Tag อัตโนมัติ
 * LINE OA Manager V2.5
 */

class AutoTagManager
{
    private $db;
    private $lineAccountId;

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }

    /**
     * ติด Tag อัตโนมัติเมื่อ user follow
     */
    public function onFollow($userId)
    {
        $this->processAutoTags($userId, 'follow');
    }

    /**
     * ติด Tag อัตโนมัติเมื่อมี order ใหม่
     */
    public function onOrder($userId, $orderAmount)
    {
        // อัพเดทสถิติ user
        $this->updateUserStats($userId);

        // Check if first purchase
        $orderCount = $this->getOrderCount($userId);
        if ($orderCount == 1) {
            $this->processAutoTags($userId, 'first_purchase', ['amount' => $orderAmount]);
        } else {
            $this->processAutoTags($userId, 'repeat_purchase', ['amount' => $orderAmount, 'order_count' => $orderCount]);
        }

        // ประมวลผล auto tags
        $this->processAutoTags($userId, 'purchase', ['amount' => $orderAmount]);
        $this->processAutoTags($userId, 'order_count');
        $this->processAutoTags($userId, 'total_spent');
    }

    /**
     * ติด Tag อัตโนมัติเมื่อเลื่อน Tier
     */
    public function onTierUpgrade($userId, $oldTier, $newTier)
    {
        $this->processAutoTags($userId, 'tier_upgrade', [
            'old_tier' => $oldTier,
            'new_tier' => $newTier
        ]);
    }

    /**
     * ติด Tag อัตโนมัติเมื่อแต้มถึงเป้าหมาย
     */
    public function onPointMilestone($userId, $points, $milestone)
    {
        $this->processAutoTags($userId, 'point_milestone', [
            'points' => $points,
            'milestone' => $milestone
        ]);
    }

    /**
     * ติด Tag อัตโนมัติเมื่อปรึกษาเภสัชgr
     */
    public function onVideoCall($userId)
    {
        $this->processAutoTags($userId, 'video_call');
    }

    /**
     * ติด Tag อัตโนมัติเมื่อมีการ Referral
     */
    public function onReferral($userId, $referredUserId)
    {
        $this->processAutoTags($userId, 'referral', ['referred_user_id' => $referredUserId]);
    }

    /**
     * ติด Tag อัตโนมัติเมื่อส่งข้อความ
     */
    public function onMessage($userId)
    {
        // อัพเดท last_message_at
        try {
            $stmt = $this->db->prepare("UPDATE users SET last_message_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
        }

        // ลบ tag Inactive ถ้ามี
        $this->removeTagByName($userId, 'Inactive');
    }

    /**
     * ประมวลผล Auto Tags ตาม trigger type
     */
    public function processAutoTags($userId, $triggerType, $data = [])
    {
        // ดึง rules ที่ active
        $stmt = $this->db->prepare("
            SELECT r.*, t.name as tag_name 
            FROM auto_tag_rules r 
            JOIN user_tags t ON r.tag_id = t.id 
            WHERE r.trigger_type = ? 
            AND r.is_active = 1 
            AND (r.line_account_id = ? OR r.line_account_id IS NULL)
            ORDER BY r.priority DESC
        ");
        $stmt->execute([$triggerType, $this->lineAccountId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            if ($this->checkConditions($userId, $rule['conditions'], $data)) {
                $this->assignTag($userId, $rule['tag_id'], $rule['id'], $triggerType, $data);
            }
        }
    }

    /**
     * ตรวจสอบเงื่อนไข
     */
    private function checkConditions($userId, $conditionsJson, $data = [])
    {
        $conditions = json_decode($conditionsJson, true) ?: [];

        // ดึงข้อมูล user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user)
            return false;

        foreach ($conditions as $key => $value) {
            switch ($key) {
                case 'min_orders':
                    $orderCount = $this->getOrderCount($userId);
                    if ($orderCount < $value)
                        return false;
                    break;

                case 'max_orders':
                    $orderCount = $this->getOrderCount($userId);
                    if ($orderCount > $value)
                        return false;
                    break;

                case 'min_amount':
                    $totalSpent = $this->getTotalSpent($userId);
                    if ($totalSpent < $value)
                        return false;
                    break;

                case 'days':
                    // สำหรับ inactivity - ตรวจสอบว่าไม่มีกิจกรรมกี่วัน
                    $lastActivity = $this->getLastActivity($userId);
                    if ($lastActivity) {
                        $daysSince = (time() - strtotime($lastActivity)) / 86400;
                        if ($daysSince < $value)
                            return false;
                    }
                    break;

                case 'month':
                    // สำหรับ birthday
                    if ($value === 'current') {
                        $currentMonth = date('m');
                        $birthday = $user['birthday'] ?? null;
                        if (!$birthday || date('m', strtotime($birthday)) != $currentMonth) {
                            return false;
                        }
                    }
                    break;

                // ========== NEW CONDITIONS ==========
                case 'tier':
                    // ตรวจสอบ tier ของ user
                    try {
                        $stmt = $this->db->prepare("SELECT tier FROM loyalty_points WHERE user_id = ? LIMIT 1");
                        $stmt->execute([$userId]);
                        $userTier = $stmt->fetchColumn();
                        if (!$userTier || $userTier !== $value)
                            return false;
                    } catch (Exception $e) {
                        return false;
                    }
                    break;

                case 'min_points':
                    try {
                        $stmt = $this->db->prepare("SELECT points FROM loyalty_points WHERE user_id = ? LIMIT 1");
                        $stmt->execute([$userId]);
                        $points = (int) $stmt->fetchColumn();
                        if ($points < $value)
                            return false;
                    } catch (Exception $e) {
                        return false;
                    }
                    break;

                case 'max_points':
                    try {
                        $stmt = $this->db->prepare("SELECT points FROM loyalty_points WHERE user_id = ? LIMIT 1");
                        $stmt->execute([$userId]);
                        $points = (int) $stmt->fetchColumn();
                        if ($points > $value)
                            return false;
                    } catch (Exception $e) {
                        return false;
                    }
                    break;

                case 'province':
                    $userProvince = $user['province'] ?? null;
                    if (!$userProvince || $userProvince !== $value)
                        return false;
                    break;

                case 'min_age':
                    $birthday = $user['birthday'] ?? null;
                    if (!$birthday)
                        return false;
                    $age = (int) date('Y') - (int) date('Y', strtotime($birthday));
                    if ($age < $value)
                        return false;
                    break;

                case 'max_age':
                    $birthday = $user['birthday'] ?? null;
                    if (!$birthday)
                        return false;
                    $age = (int) date('Y') - (int) date('Y', strtotime($birthday));
                    if ($age > $value)
                        return false;
                    break;

                case 'gender':
                    $userGender = $user['gender'] ?? null;
                    if (!$userGender || $userGender !== $value)
                        return false;
                    break;

                case 'has_purchased_category':
                    // ตรวจสอบว่าเคยซื้อสินค้าในหมวดหมู่นี้หรือไม่
                    try {
                        $stmt = $this->db->prepare("
                            SELECT COUNT(*) FROM transaction_items ti
                            JOIN transactions t ON ti.transaction_id = t.id
                            JOIN business_items bi ON ti.product_id = bi.id
                            WHERE t.user_id = ? AND bi.category_id = ? AND t.status NOT IN ('cancelled')
                        ");
                        $stmt->execute([$userId, $value]);
                        if ((int) $stmt->fetchColumn() == 0)
                            return false;
                    } catch (Exception $e) {
                        return false;
                    }
                    break;

                case 'days_since_last_order':
                    try {
                        $stmt = $this->db->prepare("SELECT MAX(created_at) FROM transactions WHERE user_id = ? AND status NOT IN ('cancelled')");
                        $stmt->execute([$userId]);
                        $lastOrder = $stmt->fetchColumn();
                        if (!$lastOrder)
                            return false;
                        $daysSince = (time() - strtotime($lastOrder)) / 86400;
                        if ($daysSince < $value)
                            return false;
                    } catch (Exception $e) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * ติด Tag ให้ user
     */
    public function assignTag($userId, $tagId, $ruleId = null, $triggerType = 'manual', $data = [])
    {
        try {
            // ตรวจสอบว่ามี tag นี้แล้วหรือยัง
            $stmt = $this->db->prepare("SELECT id FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            $stmt->execute([$userId, $tagId]);
            if ($stmt->fetch())
                return false; // มีแล้ว

            // ติด tag
            $stmt = $this->db->prepare("INSERT INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $tagId, $ruleId ? 'auto' : 'manual']);

            // บันทึก log
            $this->logTagAction($userId, $tagId, $ruleId, 'assign', $triggerType, $data);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * ลบ Tag จาก user
     */
    public function removeTag($userId, $tagId, $ruleId = null, $triggerType = 'manual', $data = [])
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            $stmt->execute([$userId, $tagId]);

            // บันทึก log
            $this->logTagAction($userId, $tagId, $ruleId, 'remove', $triggerType, $data);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * ลบ Tag ตามชื่อ
     */
    public function removeTagByName($userId, $tagName)
    {
        try {
            $stmt = $this->db->prepare("SELECT id FROM user_tags WHERE name = ? LIMIT 1");
            $stmt->execute([$tagName]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tag) {
                return $this->removeTag($userId, $tag['id'], null, 'auto_remove');
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * บันทึก log การติด/ลบ tag
     */
    private function logTagAction($userId, $tagId, $ruleId, $action, $triggerType, $data)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO auto_tag_logs (user_id, tag_id, rule_id, action, trigger_type, trigger_data) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $tagId, $ruleId, $action, $triggerType, json_encode($data)]);
        } catch (Exception $e) {
            // ตาราง log อาจไม่มี - ไม่เป็นไร
        }
    }

    /**
     * อัพเดทสถิติ user
     */
    public function updateUserStats($userId)
    {
        try {
            // นับจำนวน orders
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status NOT IN ('cancelled')");
            $stmt->execute([$userId]);
            $orderCount = $stmt->fetchColumn();

            // รวมยอดซื้อ
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE user_id = ? AND status IN ('paid', 'confirmed', 'shipping', 'delivered')");
            $stmt->execute([$userId]);
            $totalSpent = $stmt->fetchColumn();

            // order ล่าสุด
            $stmt = $this->db->prepare("SELECT MAX(created_at) FROM orders WHERE user_id = ?");
            $stmt->execute([$userId]);
            $lastOrder = $stmt->fetchColumn();

            // อัพเดท
            $stmt = $this->db->prepare("UPDATE users SET total_orders = ?, total_spent = ?, last_order_at = ? WHERE id = ?");
            $stmt->execute([$orderCount, $totalSpent, $lastOrder, $userId]);

        } catch (Exception $e) {
            // columns อาจไม่มี
        }
    }

    /**
     * ดึงจำนวน orders ของ user
     */
    private function getOrderCount($userId)
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status NOT IN ('cancelled')");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * ดึงยอดซื้อรวมของ user
     */
    private function getTotalSpent($userId)
    {
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE user_id = ? AND status IN ('paid', 'confirmed', 'shipping', 'delivered')");
            $stmt->execute([$userId]);
            return (float) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * ดึงวันที่มีกิจกรรมล่าสุด
     */
    private function getLastActivity($userId)
    {
        try {
            // ดูจาก messages
            $stmt = $this->db->prepare("SELECT MAX(created_at) FROM messages WHERE user_id = ?");
            $stmt->execute([$userId]);
            $lastMessage = $stmt->fetchColumn();

            // ดูจาก orders
            $stmt = $this->db->prepare("SELECT MAX(created_at) FROM orders WHERE user_id = ?");
            $stmt->execute([$userId]);
            $lastOrder = $stmt->fetchColumn();

            // เอาค่าที่ใหม่กว่า
            if ($lastMessage && $lastOrder) {
                return max($lastMessage, $lastOrder);
            }
            return $lastMessage ?: $lastOrder;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * ประมวลผล inactive users (เรียกจาก cron)
     */
    public function processInactiveUsers($days = 30)
    {
        try {
            // หา users ที่ไม่มีกิจกรรมเกิน X วัน
            $stmt = $this->db->prepare("
                SELECT u.id FROM users u 
                WHERE (u.line_account_id = ? OR ? IS NULL)
                AND u.is_blocked = 0
                AND NOT EXISTS (
                    SELECT 1 FROM messages m 
                    WHERE m.user_id = u.id 
                    AND m.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM orders o 
                    WHERE o.user_id = u.id 
                    AND o.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                )
            ");
            $stmt->execute([$this->lineAccountId, $this->lineAccountId, $days, $days]);
            $inactiveUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // ติด tag Inactive
            $stmt = $this->db->prepare("SELECT id FROM user_tags WHERE name = 'Inactive' LIMIT 1");
            $stmt->execute();
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tag) {
                foreach ($inactiveUsers as $userId) {
                    $this->assignTag($userId, $tag['id'], null, 'inactivity', ['days' => $days]);
                }
            }

            return count($inactiveUsers);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * ประมวลผล birthday tags (เรียกจาก cron)
     */
    public function processBirthdayTags()
    {
        try {
            $currentMonth = date('m');

            // หา users ที่วันเกิดเดือนนี้
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE birthday IS NOT NULL 
                AND MONTH(birthday) = ?
                AND (line_account_id = ? OR ? IS NULL)
            ");
            $stmt->execute([$currentMonth, $this->lineAccountId, $this->lineAccountId]);
            $birthdayUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // ติด tag Birthday This Month
            $stmt = $this->db->prepare("SELECT id FROM user_tags WHERE name = 'Birthday This Month' LIMIT 1");
            $stmt->execute();
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tag) {
                foreach ($birthdayUsers as $userId) {
                    $this->assignTag($userId, $tag['id'], null, 'birthday');
                }
            }

            // ลบ tag จาก users ที่ไม่ใช่เดือนนี้แล้ว
            if ($tag) {
                $stmt = $this->db->prepare("
                    DELETE FROM user_tag_assignments 
                    WHERE tag_id = ? 
                    AND user_id NOT IN (
                        SELECT id FROM users WHERE MONTH(birthday) = ?
                    )
                ");
                $stmt->execute([$tag['id'], $currentMonth]);
            }

            return count($birthdayUsers);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * ดึง Auto Tag Rules ทั้งหมด
     */
    public function getRules()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.*, t.name as tag_name, t.color as tag_color,
                (SELECT COUNT(*) FROM auto_tag_logs WHERE rule_id = r.id) as usage_count
                FROM auto_tag_rules r 
                JOIN user_tags t ON r.tag_id = t.id 
                WHERE r.line_account_id = ? OR r.line_account_id IS NULL
                ORDER BY r.priority DESC, r.created_at DESC
            ");
            $stmt->execute([$this->lineAccountId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * สร้าง Auto Tag Rule ใหม่
     */
    public function createRule($tagId, $ruleName, $triggerType, $conditions)
    {
        $stmt = $this->db->prepare("
            INSERT INTO auto_tag_rules (line_account_id, tag_id, rule_name, trigger_type, conditions) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$this->lineAccountId, $tagId, $ruleName, $triggerType, json_encode($conditions)]);
        return $this->db->lastInsertId();
    }

    /**
     * ลบ Auto Tag Rule
     */
    public function deleteRule($ruleId)
    {
        $stmt = $this->db->prepare("DELETE FROM auto_tag_rules WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$ruleId, $this->lineAccountId]);
        return $stmt->rowCount() > 0;
    }
}
