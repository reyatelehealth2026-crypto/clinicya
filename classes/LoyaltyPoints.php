<?php
/**
 * Loyalty Points System
 */

class LoyaltyPoints
{
    private $db;
    private $lineAccountId;
    private $settings;
    /** null until first checked — see hasIdempotencyColumn() */
    private $hasIdempotencyColumn = null;

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

    public function getSettings()
    {
        return $this->settings;
    }

    public function updateSettings($data)
    {
        $stmt = $this->db->prepare("INSERT INTO points_settings (line_account_id, points_per_baht, min_order_for_points, points_expiry_days, is_active) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE points_per_baht = VALUES(points_per_baht), min_order_for_points = VALUES(min_order_for_points), points_expiry_days = VALUES(points_expiry_days), is_active = VALUES(is_active)");
        return $stmt->execute([$this->lineAccountId, $data['points_per_baht'] ?? 0.001, $data['min_order_for_points'] ?? 0, $data['points_expiry_days'] ?? 365, $data['is_active'] ?? 1]);
    }

    public function calculatePoints($amount)
    {
        if (!$this->settings['is_active'])
            return 0;
        if ($amount < $this->settings['min_order_for_points'])
            return 0;
        return (int) floor($amount * $this->settings['points_per_baht']);
    }

    public function getUserPoints($userId)
    {
        // First, try to get from points_transactions
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_points,
                COALESCE(SUM(points), 0) as available_points,
                COALESCE(SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END), 0) as used_points
            FROM points_transactions
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        error_log("getUserPoints: Query points_transactions result: " . json_encode($result));

        // If no data in points_transactions, fallback to users table
        if (!$result || (int) $result['available_points'] === 0) {
            error_log("getUserPoints: No data in points_transactions, checking users table");
            $stmt = $this->db->prepare("SELECT total_points, available_points, used_points FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userResult = $stmt->fetch(PDO::FETCH_ASSOC);
            error_log("getUserPoints: Users table result: " . json_encode($userResult));

            // `users.points` used to be read here when available_points was 0.
            // That column holds nothing but withdrawn welcome bonuses (ADR-008
            // §"4. โบนัสต้อนรับที่เคยแจกไปแล้ว — ตัดทิ้ง ไม่ต้องโอน"), so reading it
            // handed customers back the 50 points that were written off.
            if ($userResult && (int) $userResult['available_points'] > 0) {
                return $userResult;
            }
        }

        if (!$result) {
            return ['total_points' => 0, 'available_points' => 0, 'used_points' => 0];
        }

        // Ensure available_points is not negative
        $result['available_points'] = max(0, (int) $result['available_points']);

        return $result;
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
     * Uses TierService as single source of truth
     * 
     * @param int $userId User ID
     * @return array Tier information
     */
    public function getUserTier($userId)
    {
        // Use TierService for consistent tier calculation
        require_once __DIR__ . '/TierService.php';
        $tierService = new TierService($this->db, $this->lineAccountId);
        $tierInfo = $tierService->getUserTier($userId);

        // Return in expected format for backwards compatibility
        return [
            'name' => $tierInfo['tier_name'],
            'tier_code' => $tierInfo['tier_code'],
            'color' => $tierInfo['color'],
            'icon' => $tierInfo['icon'],
            'current_points' => $tierInfo['current_points'],
            'min_points' => $tierInfo['min_points'],
            'next_tier_name' => $tierInfo['next_tier_name'],
            'next_tier_points' => $tierInfo['next_tier_points'],
            'points_to_next' => $tierInfo['points_to_next'],
            'progress_percent' => $tierInfo['progress_percent'] ?? 0,
            'discount_percent' => $tierInfo['discount_percent'] ?? 0
        ];
    }

    public function addPoints($userId, $points, $referenceType = null, $referenceId = null, $description = null, $idempotencyKey = null)
    {
        if ($points <= 0)
            return false;

        $expiresAt = $this->settings['points_expiry_days'] > 0 ? date('Y-m-d H:i:s', strtotime("+{$this->settings['points_expiry_days']} days")) : null;

        // Keep referenceId as integer or null (don't convert to string for INT column)
        $referenceIdValue = ($referenceId !== null && $referenceId !== '') ? (int) $referenceId : null;

        $newBalance = $this->mutate(
            $userId,
            "UPDATE users SET total_points = total_points + ?, available_points = available_points + ? WHERE id = ?",
            $points,
            function ($balanceAfter, $key) use ($userId, $points, $referenceType, $referenceIdValue, $description, $expiresAt) {
                if ($key !== null) {
                    $stmt = $this->db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description, expires_at, idempotency_key) VALUES (?, ?, 'earn', ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $this->lineAccountId, $points, $balanceAfter, $referenceType, $referenceIdValue, $description ?? "Earned {$points} points", $expiresAt, $key]);
                    return;
                }
                $stmt = $this->db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description, expires_at) VALUES (?, ?, 'earn', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $this->lineAccountId, $points, $balanceAfter, $referenceType, $referenceIdValue, $description ?? "Earned {$points} points", $expiresAt]);
            },
            $idempotencyKey
        );

        if ($newBalance === null)
            return false;

        // Tier is a derived cache — never let it fail the award.
        $this->updateUserTier($userId, $newBalance);

        // Platform-owner activity feed + (throttled) Telegram (best-effort).
        if (@is_file(__DIR__ . '/TenantActivity.php')) {
            require_once __DIR__ . '/TenantActivity.php';
            TenantActivity::log(
                TenantActivity::currentTenantId(), 'points_award',
                'ลูกค้า #' . (int) $userId, '+' . (int) $points . ' แต้ม', true, 300
            );
        }
        return true;
    }

    public function deductPoints($userId, $points, $referenceType = null, $referenceId = null, $description = null, $idempotencyKey = null)
    {
        if ($points <= 0)
            return false;

        // Keep referenceId as integer or null (don't convert to string for INT column)
        $referenceIdValue = ($referenceId !== null && $referenceId !== '') ? (int) $referenceId : null;

        $newBalance = $this->mutate(
            $userId,
            "UPDATE users SET available_points = available_points - ?, used_points = used_points + ? WHERE id = ?",
            -$points,
            function ($balanceAfter, $key) use ($userId, $points, $referenceType, $referenceIdValue, $description) {
                if ($key !== null) {
                    $stmt = $this->db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description, idempotency_key) VALUES (?, ?, 'redeem', ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $this->lineAccountId, -$points, $balanceAfter, $referenceType, $referenceIdValue, $description ?? "Used {$points} points", $key]);
                    return;
                }
                $stmt = $this->db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description) VALUES (?, ?, 'redeem', ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $this->lineAccountId, -$points, $balanceAfter, $referenceType, $referenceIdValue, $description ?? "Used {$points} points"]);
            },
            $idempotencyKey
        );

        if ($newBalance === null)
            return false;

        // Tier is a derived cache — never let it fail the redemption.
        $this->updateUserTier($userId, $newBalance);
        return true;
    }

    /**
     * Move a user's balance and write the matching ledger row, atomically.
     *
     * The balance is read, the cache columns are updated, and the
     * points_transactions row is written inside one transaction with the user
     * row locked. Without the lock two concurrent awards both read the old
     * balance and stamp the same `balance_after`, so the ledger stops
     * reconciling with the cache even though the balance itself is correct.
     *
     * Callers that already opened a transaction (redeemReward via
     * api/points.php) join it rather than nesting, which PDO cannot do.
     *
     * An idempotency key makes the move safe to retry: the key is stored on
     * the ledger row, and a second call carrying the same key returns the
     * current balance without awarding anything. Keys are scoped to the LINE
     * account here, so callers pass a bare "order:1042" and cannot collide
     * across accounts in the same tenant.
     *
     * @param int         $userId
     * @param string      $balanceSql  UPDATE with three placeholders: amount, amount, userId
     * @param int         $delta       Signed change applied to the balance (+earn / -redeem)
     * @param callable    $writeLedger Receives the new balance and the scoped key; writes the ledger row
     * @param string|null $idempotencyKey Natural key for this award, or null when there isn't one
     * @return int|null New balance, or null if the move could not be made
     */
    private function mutate($userId, $balanceSql, $delta, callable $writeLedger, $idempotencyKey = null)
    {
        $scopedKey = ($idempotencyKey !== null && $idempotencyKey !== '' && $this->hasIdempotencyColumn())
            ? ((int) $this->lineAccountId) . ':' . $idempotencyKey
            : null;

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction)
            $this->db->beginTransaction();

        try {
            // Serialise every point movement for this user.
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() === false) {
                if ($ownTransaction)
                    $this->db->rollBack();
                return null;
            }

            // Already paid for under this key — report the balance, award nothing.
            if ($scopedKey !== null) {
                $stmt = $this->db->prepare("SELECT id FROM points_transactions WHERE idempotency_key = ? LIMIT 1");
                $stmt->execute([$scopedKey]);
                if ($stmt->fetchColumn() !== false) {
                    $balance = (int) $this->getUserPoints($userId)['available_points'];
                    if ($ownTransaction)
                        $this->db->commit();
                    error_log('LoyaltyPoints: duplicate award ignored for key ' . $scopedKey);
                    return $balance;
                }
            }

            $current = (int) $this->getUserPoints($userId)['available_points'];
            $newBalance = $current + $delta;

            // Refuse to spend points the customer does not have.
            if ($newBalance < 0) {
                if ($ownTransaction)
                    $this->db->rollBack();
                return null;
            }

            $amount = abs($delta);
            $stmt = $this->db->prepare($balanceSql);
            $stmt->execute([$amount, $amount, $userId]);

            $writeLedger($newBalance, $scopedKey);

            if ($ownTransaction)
                $this->db->commit();

            return $newBalance;
        } catch (Exception $e) {
            if ($ownTransaction && $this->db->inTransaction())
                $this->db->rollBack();
            error_log('LoyaltyPoints: balance move failed for user ' . $userId . ' - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Does this tenant DB have the idempotency column yet?
     *
     * database/migration_2026-09-07_points_idempotency.sql adds it. Until it
     * has run, keys are ignored and awards behave exactly as before rather
     * than failing on an unknown column.
     */
    private function hasIdempotencyColumn()
    {
        if ($this->hasIdempotencyColumn !== null)
            return $this->hasIdempotencyColumn;

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM points_transactions LIKE 'idempotency_key'");
            $this->hasIdempotencyColumn = $stmt->fetch() !== false;
        } catch (Exception $e) {
            $this->hasIdempotencyColumn = false;
        }

        return $this->hasIdempotencyColumn;
    }

    public function awardPointsForOrder($userId, $orderId, $orderAmount)
    {
        $points = $this->calculatePoints($orderAmount);
        if ($points > 0)
            return $this->addPoints($userId, $points, 'order', $orderId, "Points from order #{$orderId}");
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
                    $reward['stock'] = (int) $reward['stock'];
                }
            }

            return $rewards;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Update user tier based on points
     * Uses TierService to calculate correct tier
     */
    private function updateUserTier($userId, $points)
    {
        try {
            require_once __DIR__ . '/TierService.php';
            $tierService = new TierService($this->db, $this->lineAccountId);
            $tierInfo = $tierService->calculateTier($points);

            // Update member_tier column in users table
            // Use tier_code (lowercase) for consistency
            if (isset($tierInfo['tier_code'])) {
                $stmt = $this->db->prepare("UPDATE users SET member_tier = ? WHERE id = ?");
                $stmt->execute([$tierInfo['tier_code'], $userId]);
            }
        } catch (Throwable $e) {
            // Throwable, not Exception: the points are already committed by the
            // time this runs, so a missing TierService.php or a type error here
            // must not take the request down after the customer was paid.
            error_log("Failed to update user tier: " . $e->getMessage());
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
        if (empty($fields))
            return false;
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
        if (!$reward)
            return ['success' => false, 'message' => 'ไม่พบรางวัล'];

        // Check if reward is active (handle both string and boolean values)
        if (isset($reward['is_active']) && ($reward['is_active'] === 0 || $reward['is_active'] === '0' || $reward['is_active'] === false)) {
            return ['success' => false, 'message' => 'รางวัลนี้ไม่พร้อมให้บริการ'];
        }

        // Check stock
        if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] !== -1 && $reward['stock'] <= 0) {
            return ['success' => false, 'message' => 'รางวัลหมดแล้ว'];
        }

        $userPoints = $this->getUserPoints($userId);

        error_log("LoyaltyPoints: Checking points for redemption");
        error_log("  - userId: $userId");
        error_log("  - rewardId: $rewardId");
        error_log("  - lineAccountId: {$this->lineAccountId}");
        error_log("  - userPoints: " . json_encode($userPoints));
        error_log("  - available_points: {$userPoints['available_points']}");
        error_log("  - points_required: {$reward['points_required']}");
        error_log("  - has enough: " . ($userPoints['available_points'] >= $reward['points_required'] ? 'YES' : 'NO'));

        if ($userPoints['available_points'] < $reward['points_required']) {
            error_log("LoyaltyPoints: Points not enough - returning error");
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
        if ($status) {
            $sql .= " AND rr.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY rr.created_at DESC LIMIT ?";
        $params[] = $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateRedemptionStatus($redemptionId, $status, $adminId = null, $notes = null)
    {
        // Validate status against ENUM values
        $validStatuses = ['pending', 'approved', 'delivered', 'cancelled', 'expired'];

        error_log("updateRedemptionStatus called:");
        error_log("  - redemptionId: $redemptionId");
        error_log("  - status: '$status' (length: " . strlen($status) . ")");
        error_log("  - adminId: $adminId");
        error_log("  - notes: $notes");
        error_log("  - status type: " . gettype($status));
        error_log("  - status hex: " . bin2hex($status));

        if (!in_array($status, $validStatuses, true)) {
            error_log("Invalid redemption status: $status. Must be one of: " . implode(', ', $validStatuses));
            return false;
        }

        $updates = ['status = ?'];
        $params = [$status];
        if ($status === 'approved') {
            $updates[] = 'approved_by = ?';
            $updates[] = 'approved_at = NOW()';
            $params[] = $adminId;
        } elseif ($status === 'delivered') {
            $updates[] = 'delivered_at = NOW()';
        }
        if ($notes) {
            $updates[] = 'notes = ?';
            $params[] = $notes;
        }
        $params[] = $redemptionId;

        $sql = "UPDATE reward_redemptions SET " . implode(', ', $updates) . " WHERE id = ?";
        error_log("  - SQL: $sql");
        error_log("  - Params: " . json_encode($params));

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($params);

        error_log("  - Execute result: " . ($result ? 'SUCCESS' : 'FAILED'));
        if (!$result) {
            error_log("  - Error info: " . json_encode($stmt->errorInfo()));
        }

        return $result;
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
