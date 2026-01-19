<?php
/**
 * Loyalty Points System
 */

class LoyaltyPoints
{
    private $db;
    private $lineAccountId;
    private $settings;

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->loadSettings();
    }

    private function loadSettings()
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM points_settings WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY line_account_id DESC LIMIT 1");
            $stmt->execute([$this->lineAccountId]);
            $this->settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['points_per_baht' => 0.001, 'min_order_for_points' => 0, 'points_expiry_days' => 365, 'is_active' => 1];
        } catch (Exception $e) {
            $this->settings = ['points_per_baht' => 0.001, 'min_order_for_points' => 0, 'points_expiry_days' => 365, 'is_active' => 1];
        }
    }

    public function getSettings() { return $this->settings; }

    public function updateSettings($data)
    {
        $stmt = $this->db->prepare("INSERT INTO points_settings (line_account_id, points_per_baht, min_order_for_points, points_expiry_days, is_active) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE points_per_baht = VALUES(points_per_baht), min_order_for_points = VALUES(min_order_for_points), points_expiry_days = VALUES(points_expiry_days), is_active = VALUES(is_active)");
        return $stmt->execute([$this->lineAccountId, $data['points_per_baht'] ?? 0.001, $data['min_order_for_points'] ?? 0, $data['points_expiry_days'] ?? 365, $data['is_active'] ?? 1]);
    }

    public function calculatePoints($amount)
    {
        if (!$this->settings['is_active']) return 0;
        if ($amount < $this->settings['min_order_for_points']) return 0;
        return (int)floor($amount * $this->settings['points_per_baht']);
    }

    public function getUserPoints($userId)
    {
        $stmt = $this->db->prepare("SELECT total_points, available_points, used_points FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_points' => 0, 'available_points' => 0, 'used_points' => 0];
    }
    
    /**
     * Get member information by user ID
     * @param int $userId User ID
     * @return array|null Member information
     */
    public function getMemberByUserId($userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.id,
                    u.display_name,
                    u.picture_url,
                    u.total_points,
                    u.available_points,
                    u.used_points,
                    u.line_user_id
                FROM users u
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                return null;
            }
            
            // Get tier information
            $tier = $this->getUserTier($userId);
            $member['tier'] = $tier;
            
            // Ensure points fields exist
            $member['points'] = $member['available_points'] ?? 0;
            
            return $member;
        } catch (PDOException $e) {
            error_log("Error getting member by user ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user tier information
     * Requirements: 21.3, 21.4 - Display tier status with progress bar
     * @param int $userId User ID
     * @return array Tier information
     */
    public function getUserTier($userId)
    {
        // Get user points - try multiple columns
        $userPoints = $this->getUserPoints($userId);
        $totalPoints = (int)$userPoints['total_points'];
        
        // Fallback: get 'points' column if total_points is 0
        if ($totalPoints == 0) {
            try {
                $stmt = $this->db->prepare("SELECT points FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['points'])) {
                    $totalPoints = (int)$row['points'];
                }
            } catch (Exception $e) {}
        }
        
        // Default tier thresholds (matching admin settings)
        $tiers = [
            ['name' => 'Bronze', 'min_points' => 0, 'next_tier' => 'Silver', 'next_points' => 1000],
            ['name' => 'Silver', 'min_points' => 1000, 'next_tier' => 'Gold', 'next_points' => 5000],
            ['name' => 'Gold', 'min_points' => 5000, 'next_tier' => 'Platinum', 'next_points' => 15000],
            ['name' => 'Platinum', 'min_points' => 15000, 'next_tier' => null, 'next_points' => null]
        ];
        
        // Try to load from member_tiers table first (used by member card)
        try {
            $stmt = $this->db->prepare("SELECT * FROM member_tiers WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1 ORDER BY min_points ASC");
            $stmt->execute([$this->lineAccountId]);
            $customTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($customTiers)) {
                $tiers = [];
                foreach ($customTiers as $i => $tier) {
                    $nextTier = isset($customTiers[$i + 1]) ? $customTiers[$i + 1] : null;
                    $tiers[] = [
                        'name' => $tier['tier_name'] ?? $tier['name'],
                        'min_points' => (int)$tier['min_points'],
                        'next_tier' => $nextTier ? ($nextTier['tier_name'] ?? $nextTier['name']) : null,
                        'next_points' => $nextTier ? (int)$nextTier['min_points'] : null
                    ];
                }
            }
        } catch (Exception $e) {
            // Try points_tiers table as fallback
            try {
                $stmt = $this->db->prepare("SELECT * FROM points_tiers WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY min_points ASC");
                $stmt->execute([$this->lineAccountId]);
                $customTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($customTiers)) {
                    $tiers = [];
                    foreach ($customTiers as $i => $tier) {
                        $nextTier = isset($customTiers[$i + 1]) ? $customTiers[$i + 1] : null;
                        $tiers[] = [
                            'name' => $tier['name'],
                            'min_points' => (int)$tier['min_points'],
                            'next_tier' => $nextTier ? $nextTier['name'] : null,
                            'next_points' => $nextTier ? (int)$nextTier['min_points'] : null
                        ];
                    }
                }
            } catch (Exception $e2) {
                // Use default tiers
            }
        }
        
        // Determine current tier
        $currentTier = $tiers[0];
        foreach ($tiers as $tier) {
            if ($totalPoints >= $tier['min_points']) {
                $currentTier = $tier;
            }
        }
        
        // Calculate points to next tier
        $pointsToNext = $currentTier['next_points'] 
            ? max(0, $currentTier['next_points'] - $totalPoints)
            : 0;
        
        return [
            'name' => $currentTier['name'],
            'current_points' => $totalPoints,
            'min_points' => $currentTier['min_points'],
            'next_tier_name' => $currentTier['next_tier'] ?? 'Max Level',
            'next_tier_points' => $currentTier['next_points'] ?? $totalPoints,
            'points_to_next' => $pointsToNext
        ];
    }

    public function addPoints($userId, $points, $referenceType = null, $referenceId = null, $description = null)
    {
        if ($points <= 0) return false;
        $current = $this->getUserPoints($userId);
        $newBalance = $current['available_points'] + $points;
        $expiresAt = $this->settings['points_expiry_days'] > 0 ? date('Y-m-d H:i:s', strtotime("+{$this->settings['points_expiry_days']} days")) : null;

        $stmt = $this->db->prepare("UPDATE users SET total_points = total_points + ?, available_points = available_points + ? WHERE id = ?");
        $stmt->execute([$points, $points, $userId]);

        // Keep referenceId as integer or null (don't convert to string for INT column)
        $referenceIdValue = ($referenceId !== null && $referenceId !== '') ? (int)$referenceId : null;
        
        $stmt = $this->db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description, expires_at) VALUES (?, ?, 'earn', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $this->lineAccountId, $points, $newBalance, $referenceType, $referenceIdValue, $description ?? "Earned {$points} points", $expiresAt]);
        return true;
    }

    public function deductPoints($userId, $points, $referenceType = null, $referenceId = null, $description = null)
    {
        if ($points <= 0) return false;
        $current = $this->getUserPoints($userId);
        if ($current['available_points'] < $points) return false;
        $newBalance = $current['available_points'] - $points;

        $stmt = $this->db->prepare("UPDATE users SET available_points = available_points - ?, used_points = used_points + ? WHERE id = ?");
        $stmt->execute([$points, $points, $userId]);

        // Keep referenceId as integer or null (don't convert to string for INT column)
        $referenceIdValue = ($referenceId !== null && $referenceId !== '') ? (int)$referenceId : null;
        
        $stmt = $this->db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description) VALUES (?, ?, 'redeem', ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $this->lineAccountId, -$points, $newBalance, $referenceType, $referenceIdValue, $description ?? "Used {$points} points"]);
        return true;
    }

    public function awardPointsForOrder($userId, $orderId, $orderAmount)
    {
        $points = $this->calculatePoints($orderAmount);
        if ($points > 0) return $this->addPoints($userId, $points, 'order', $orderId, "Points from order #{$orderId}");
        return false;
    }

    public function getPointsHistory($userId, $limit = 20)
    {
        $stmt = $this->db->prepare("SELECT * FROM points_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRewards($activeOnly = true)
    {
        try {
            $hasLineAccountId = $this->columnExists('rewards', 'line_account_id');
            $hasIsActive = $this->columnExists('rewards', 'is_active');

            $sql = "SELECT * FROM rewards WHERE 1=1";
            $params = [];

            if ($hasLineAccountId) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }

            if ($activeOnly && $hasIsActive) {
                $sql .= " AND is_active = 1";
            }

            $sql .= " ORDER BY points_required ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalize stock field for consistency
            foreach ($rewards as &$reward) {
                // Convert stock to integer, handle NULL as -1 (unlimited)
                if (!isset($reward['stock']) || $reward['stock'] === null) {
                    $reward['stock'] = -1;
                } else {
                    $reward['stock'] = (int)$reward['stock'];
                }
            }

            return $rewards;
        } catch (PDOException $e) {
            error_log("Error getting rewards: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get active rewards (alias for getRewards with activeOnly=true)
     * @return array Active rewards
     */
    public function getActiveRewards()
    {
        return $this->getRewards(true);
    }

    public function getReward($rewardId)
    {
        $stmt = $this->db->prepare("SELECT * FROM rewards WHERE id = ?");
        $stmt->execute([$rewardId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new reward
     * Requirements 24.2, 24.3, 24.4: Capture name, description, image, points, stock, validity period
     * Support reward types: Discount Coupon, Free Shipping, Physical Gift, Product Voucher
     * @param array $data Reward data
     * @return int New reward ID
     */
    public function createReward($data)
    {
        $sql = "INSERT INTO rewards (line_account_id, name, description, image_url, points_required, reward_type, reward_value, stock, max_per_user, is_active, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $this->lineAccountId, 
            $data['name'], 
            $data['description'] ?? null, 
            $data['image_url'] ?? null, 
            $data['points_required'], 
            $data['reward_type'] ?? 'gift', 
            $data['reward_value'] ?? null, 
            $data['stock'] ?? -1, 
            $data['max_per_user'] ?? 0, 
            $data['is_active'] ?? 1,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null
        ]);
        return $this->db->lastInsertId();
    }

    /**
     * Update reward details
     * Requirement 24.5: Update reward details with immediate reflection in LIFF
     * Requirement 24.6: Disable reward (hide from catalog while preserving existing redemptions)
     * @param int $rewardId Reward ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function updateReward($rewardId, $data)
    {
        $fields = [];
        $values = [];
        $allowedFields = ['name', 'description', 'image_url', 'points_required', 'reward_type', 'reward_value', 'stock', 'max_per_user', 'is_active', 'start_date', 'end_date'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) { 
                $fields[] = "{$field} = ?"; 
                $values[] = $data[$field]; 
            }
        }
        if (empty($fields)) return false;
        $values[] = $rewardId;
        $stmt = $this->db->prepare("UPDATE rewards SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    public function deleteReward($rewardId)
    {
        $stmt = $this->db->prepare("DELETE FROM rewards WHERE id = ?");
        return $stmt->execute([$rewardId]);
    }

    /**
     * Redeem a reward for a user
     * Requirements: 23.7 - Deduct points and generate unique redemption code
     * @param int $userId User ID
     * @param int $rewardId Reward ID
     * @return array Result with success status, message, and redemption code
     */
    public function redeemReward($userId, $rewardId)
    {
        $reward = $this->getReward($rewardId);
        if (!$reward) return ['success' => false, 'message' => 'ไม่พบรางวัล'];

        // Check if reward is active (handle both string and boolean values)
        if (isset($reward['is_active']) && ($reward['is_active'] === 0 || $reward['is_active'] === '0' || $reward['is_active'] === false)) {
            return ['success' => false, 'message' => 'รางวัลนี้ไม่พร้อมให้บริการ'];
        }

        // Check stock
        if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] !== -1 && $reward['stock'] <= 0) {
            return ['success' => false, 'message' => 'รางวัลหมดแล้ว'];
        }

        $userPoints = $this->getUserPoints($userId);
        if ($userPoints['available_points'] < $reward['points_required']) {
            return ['success' => false, 'message' => 'แต้มไม่เพียงพอ'];
        }

        // Deduct points (Requirement 23.7)
        if (!$this->deductPoints($userId, $reward['points_required'], 'reward', $rewardId, "แลกรางวัล: {$reward['name']}")) {
            return ['success' => false, 'message' => 'ไม่สามารถหักแต้มได้'];
        }

        // Update stock if limited (check for positive stock, not -1 which means unlimited)
        if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] > 0 && $reward['stock'] !== -1) {
            $stmt = $this->db->prepare("UPDATE rewards SET stock = stock - 1 WHERE id = ? AND stock > 0");
            $stmt->execute([$rewardId]);
        }

        // Generate unique redemption code (Requirement 23.7)
        $code = $this->generateUniqueRedemptionCode();
        
        // Calculate expiry date if reward has validity period
        $expiresAt = null;
        if (!empty($reward['valid_until'])) {
            $expiresAt = $reward['valid_until'];
        } elseif (!empty($reward['validity_days'])) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$reward['validity_days']} days"));
        }
        
        $stmt = $this->db->prepare("INSERT INTO reward_redemptions (user_id, reward_id, line_account_id, points_used, redemption_code, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $rewardId, $this->lineAccountId, $reward['points_required'], $code, $expiresAt]);

        return [
            'success' => true,
            'message' => 'แลกรางวัลสำเร็จ!',
            'redemption_code' => $code,
            'reward' => $reward,
            'redemption_id' => $this->db->lastInsertId(),
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Generate a unique redemption code
     * Requirements: 23.7 - Generate unique redemption code
     * @return string Unique redemption code
     */
    private function generateUniqueRedemptionCode()
    {
        $maxAttempts = 10;
        $attempt = 0;
        
        do {
            // Generate code: RW + timestamp component + random component
            $timestamp = base_convert(time(), 10, 36);
            $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $code = 'RW' . strtoupper(substr($timestamp, -4)) . $random;
            
            // Check if code already exists
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM reward_redemptions WHERE redemption_code = ?");
            $stmt->execute([$code]);
            $exists = $stmt->fetchColumn() > 0;
            
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);
        
        // Fallback to UUID-based code if still not unique
        if ($exists) {
            $code = 'RW' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
        }
        
        return $code;
    }

    public function getUserRedemptions($userId, $limit = 20)
    {
        $stmt = $this->db->prepare("SELECT rr.*, r.name as reward_name, r.image_url as reward_image FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id WHERE rr.user_id = ? ORDER BY rr.created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get redemptions expiring soon (within specified days)
     * Requirements: 23.11 - Display expiry countdown and send reminder 3 days before
     * @param int $daysBeforeExpiry Days before expiry to check
     * @return array Redemptions expiring soon
     */
    public function getExpiringRedemptions($daysBeforeExpiry = 3)
    {
        $stmt = $this->db->prepare("
            SELECT rr.*, r.name as reward_name, r.image_url as reward_image, 
                   u.line_user_id, u.display_name
            FROM reward_redemptions rr 
            JOIN rewards r ON rr.reward_id = r.id 
            JOIN users u ON rr.user_id = u.id
            WHERE rr.status IN ('pending', 'approved')
            AND rr.expires_at IS NOT NULL
            AND rr.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
            AND rr.expires_at > NOW()
            AND (rr.expiry_reminder_sent IS NULL OR rr.expiry_reminder_sent = 0)
            AND (rr.line_account_id = ? OR rr.line_account_id IS NULL)
        ");
        $stmt->execute([$daysBeforeExpiry, $this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark redemption as expiry reminder sent
     * @param int $redemptionId Redemption ID
     * @return bool Success status
     */
    public function markExpiryReminderSent($redemptionId)
    {
        $stmt = $this->db->prepare("UPDATE reward_redemptions SET expiry_reminder_sent = 1 WHERE id = ?");
        return $stmt->execute([$redemptionId]);
    }

    /**
     * Get redemption with expiry info
     * @param int $redemptionId Redemption ID
     * @return array|null Redemption with expiry info
     */
    public function getRedemptionWithExpiry($redemptionId)
    {
        $stmt = $this->db->prepare("
            SELECT rr.*, r.name as reward_name, r.image_url as reward_image,
                   DATEDIFF(rr.expires_at, NOW()) as days_until_expiry
            FROM reward_redemptions rr 
            JOIN rewards r ON rr.reward_id = r.id 
            WHERE rr.id = ?
        ");
        $stmt->execute([$redemptionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllRedemptions($status = null, $limit = 50)
    {
        $hasLineAccountId = $this->columnExists('reward_redemptions', 'line_account_id');
        
        if ($hasLineAccountId) {
            $sql = "SELECT rr.*, r.name as reward_name, r.image_url as reward_image, u.display_name, u.picture_url FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id JOIN users u ON rr.user_id = u.id WHERE (rr.line_account_id = ? OR rr.line_account_id IS NULL)";
            $params = [$this->lineAccountId];
        } else {
            $sql = "SELECT rr.*, r.name as reward_name, r.image_url as reward_image, u.display_name, u.picture_url FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id JOIN users u ON rr.user_id = u.id WHERE 1=1";
            $params = [];
        }
        if ($status) { $sql .= " AND rr.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY rr.created_at DESC LIMIT ?";
        $params[] = $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateRedemptionStatus($redemptionId, $status, $adminId = null, $notes = null)
    {
        // Validate status against ENUM values
        $validStatuses = ['pending', 'approved', 'delivered', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            error_log("Invalid redemption status: $status. Must be one of: " . implode(', ', $validStatuses));
            return false;
        }
        
        $updates = ['status = ?'];
        $params = [$status];
        if ($status === 'approved') { $updates[] = 'approved_by = ?'; $updates[] = 'approved_at = NOW()'; $params[] = $adminId; }
        elseif ($status === 'delivered') { $updates[] = 'delivered_at = NOW()'; }
        if ($notes) { $updates[] = 'notes = ?'; $params[] = $notes; }
        $params[] = $redemptionId;
        $stmt = $this->db->prepare("UPDATE reward_redemptions SET " . implode(', ', $updates) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    public function getPointsSummary()
    {
        $summary = ['total_issued' => 0, 'total_redeemed' => 0, 'active_rewards' => 0, 'pending_redemptions' => 0];
        
        try {
            // Check if line_account_id column exists in points_transactions
            $hasLineAccountId = $this->columnExists('points_transactions', 'line_account_id');
            
            if ($hasLineAccountId) {
                $stmt = $this->db->prepare("SELECT COALESCE(SUM(points), 0) FROM points_transactions WHERE type = 'earn' AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$this->lineAccountId]);
            } else {
                $stmt = $this->db->query("SELECT COALESCE(SUM(points), 0) FROM points_transactions WHERE type = 'earn'");
            }
            $summary['total_issued'] = $stmt->fetchColumn();

            if ($hasLineAccountId) {
                $stmt = $this->db->prepare("SELECT COALESCE(SUM(ABS(points)), 0) FROM points_transactions WHERE type = 'redeem' AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$this->lineAccountId]);
            } else {
                $stmt = $this->db->query("SELECT COALESCE(SUM(ABS(points)), 0) FROM points_transactions WHERE type = 'redeem'");
            }
            $summary['total_redeemed'] = $stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM rewards WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$this->lineAccountId]);
            $summary['active_rewards'] = $stmt->fetchColumn();

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM reward_redemptions WHERE status = 'pending' AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$this->lineAccountId]);
            $summary['pending_redemptions'] = $stmt->fetchColumn();
        } catch (PDOException $e) {
            // Return defaults if tables don't exist yet
        }

        return $summary;
    }
    
    /**
     * Check if a column exists in a table
     */
    private function columnExists($table, $column)
    {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}
