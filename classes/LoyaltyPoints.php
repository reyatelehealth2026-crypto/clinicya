<?php
/**
 * Loyalty Points System — compatibility facade over LoyaltyLedgerService.
 *
 * As of Batch 1 (docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md) this
 * class no longer writes points itself. Every balance read and every point
 * movement is delegated to classes/LoyaltyLedgerService.php, which is the
 * canonical ledger: atomic, idempotent, and honest about whether a zero balance
 * means "nothing recorded" or "recorded and it nets to zero".
 *
 * The public surface is unchanged on purpose — ~15 call sites across POS, the
 * QR claim flow, the LINE webhook, the admin pages and BusinessBot still call
 * addPoints()/deductPoints()/getUserPoints() and keep working untouched. They
 * are migrated onto the ledger's richer API one at a time in Batch 2; the
 * optional trailing $options argument on addPoints()/deductPoints() is the seam
 * for that (it carries idempotency keys, metadata and the acting user).
 *
 * Everything else here — settings, rewards CRUD, redemption admin — is
 * untouched legacy that later phases consolidate into RewardRedemptionService.
 */

require_once __DIR__ . '/LoyaltyLedgerService.php';

class LoyaltyPoints
{
    private $db;
    private $lineAccountId;
    private $settings;

    /** @var LoyaltyLedgerService the canonical ledger this facade delegates to */
    private $ledger;

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->ledger = new LoyaltyLedgerService($db, $lineAccountId);
        $this->loadSettings();
    }

    /**
     * The canonical ledger behind this facade. Batch 2 callers that need
     * idempotency keys, metadata or typed movements should use this directly
     * rather than adding parameters here.
     */
    public function ledger(): LoyaltyLedgerService
    {
        return $this->ledger;
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

    /**
     * The member's spendable balance.
     *
     * FIXED IN BATCH 1. The previous implementation fell back to the legacy
     * `users` columns whenever the ledger SUM came out as zero — but a ledger
     * that nets to zero because +500 and -500 cancelled is not a ledger with
     * nothing in it. Because `users.points` is written by api/member.php,
     * api/points.php, shop/order-detail.php and the Odoo webhook but is NEVER
     * decremented by deductPoints(), that fallback resurrected an already-spent
     * balance and handed it back as spendable currency: a member who earned and
     * then spent an Odoo-awarded 300 got 300 free points back, and redeeming
     * them drove `users.available_points` to -300 permanently.
     *
     * LoyaltyLedgerService::getBalance() distinguishes the two cases by counting
     * ledger rows, and only case A (no rows at all — a member who predates the
     * ledger) is still allowed to read the legacy columns.
     *
     * @return array{total_points:int, available_points:int, used_points:int}
     */
    public function getUserPoints($userId)
    {
        $balance = $this->ledger->getBalance((int) $userId);

        // Keep the historical return shape: three int keys, nothing else.
        // Callers across POS, BusinessBot, inbox-v2 and the mini app index into
        // ['available_points'] / ['total_points'] directly.
        return [
            'total_points' => $balance['total_points'],
            'available_points' => $balance['available_points'],
            'used_points' => $balance['used_points'],
        ];
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
            'discount_percent' => $tierInfo['discount_percent'] ?? 0,
            // PHASE 3: these are two different benefits and are no longer the
            // same column wearing two names.
            'earn_multiplier' => $tierInfo['earn_multiplier'] ?? 1.0,
        ];
    }

    /**
     * Credit points. Delegates to the canonical ledger.
     *
     * Behaviour preserved: same signature, same bool return, same 'earn' row,
     * same expiry derived from points_settings, same TenantActivity ping.
     * What changed underneath: the ledger row, the `users` cache update and the
     * tier refresh now happen inside ONE database transaction (enlisting in the
     * caller's, if it opened one), and the cache is recomputed from the ledger
     * rather than incremented, so it can no longer drift.
     *
     * @param array $options Batch-2 seam — 'idempotency_key' makes a replayed
     *                       webhook or a double-clicked button a no-op;
     *                       'metadata' stores the rule breakdown; 'created_by'
     *                       records the actor; 'type' allows 'bonus'/'refund'.
     * @return bool true when the movement was recorded (or was a recognised replay)
     */
    public function addPoints($userId, $points, $referenceType = null, $referenceId = null, $description = null, array $options = [])
    {
        if ($points <= 0) {
            return false;
        }

        $expiryDays = (int) ($this->settings['points_expiry_days'] ?? 0);
        $expiresAt = $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$expiryDays} days")) : null;

        $result = $this->ledger->credit((int) $userId, (int) $points, $options + [
            'type' => LoyaltyLedgerService::TYPE_EARN,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description ?? "Earned {$points} points",
            'expires_at' => $expiresAt,
            'line_account_id' => $this->lineAccountId,
        ]);

        if (!$result['success']) {
            return false;
        }

        // A replay must not re-fire the tier refresh or the activity feed.
        if ($result['duplicate']) {
            return true;
        }

        // PHASE 3: the tier now qualifies on LIFETIME EARNED points, read from the
        // ledger, not on the post-movement spendable balance.
        $this->updateUserTier($userId);

        // Platform-owner activity feed + (throttled) Telegram (best-effort).
        if (@is_file(__DIR__ . '/TenantActivity.php')) {
            require_once __DIR__ . '/TenantActivity.php';
            TenantActivity::log(
                TenantActivity::currentTenantId(),
                'points_award',
                'ลูกค้า #' . (int) $userId,
                '+' . (int) $points . ' แต้ม',
                true,
                300
            );
        }

        return true;
    }

    /**
     * Spend points. Delegates to the canonical ledger.
     *
     * Behaviour preserved: same signature, same bool return (false when the
     * balance is short), same 'redeem' row. What changed: the sufficiency check
     * now runs AFTER the member row is locked, so two concurrent redemptions can
     * no longer both pass it and drive `users.available_points` negative.
     *
     * @param array $options see addPoints()
     */
    public function deductPoints($userId, $points, $referenceType = null, $referenceId = null, $description = null, array $options = [])
    {
        if ($points <= 0) {
            return false;
        }

        $result = $this->ledger->debit((int) $userId, (int) $points, $options + [
            'type' => LoyaltyLedgerService::TYPE_REDEEM,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description ?? "Used {$points} points",
            'line_account_id' => $this->lineAccountId,
        ]);

        if (!$result['success']) {
            return false;
        }

        if ($result['duplicate']) {
            return true;
        }

        // PHASE 3: spending no longer demotes — see updateUserTier().
        $this->updateUserTier($userId);

        return true;
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
    private function updateUserTier($userId, $points = null)
    {
        try {
            require_once __DIR__ . '/TierService.php';
            $tierService = new TierService($this->db, $this->lineAccountId);

            // PHASE 3. $points is retained only for backward compatibility with
            // any caller that still passes an explicit figure; when omitted — the
            // normal path now — the tier is derived from LIFETIME EARNED points.
            //
            // This used to be called with the post-movement AVAILABLE balance, so
            // a Gold member with 5,500 accumulated points who redeemed 5,000 fell
            // back toward Bronze. Every redeem, POS points payment, POS void, POS
            // return and account merge was a downgrade path.
            $tierInfo = $points === null
                ? $tierService->getUserTier((int) $userId)
                : $tierService->calculateTier((int) $points);

            // Update member_tier column in users table
            // Use tier_code (lowercase) for consistency
            if (isset($tierInfo['tier_code'])) {
                $stmt = $this->db->prepare('UPDATE users SET member_tier = ? WHERE id = ?');
                $stmt->execute([$tierInfo['tier_code'], $userId]);
            }
        } catch (Exception $e) {
            error_log('Failed to update user tier: ' . $e->getMessage());
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
    /**
     * Redeem a reward for a user.
     * Requirements: 23.7 - Deduct points and generate unique redemption code
     *
     * PHASE 5: this is now a thin adapter over RewardRedemptionService, which is
     * the single implementation both this facade and api/points.php call. The
     * validity window, max_per_user and tier eligibility — unenforced by every
     * previous implementation — are checked there, so no caller can forget one.
     *
     * @param int $userId User ID
     * @param int $rewardId Reward ID
     * @return array Result with success status, message, and redemption code
     */
    public function redeemReward($userId, $rewardId)
    {
        require_once __DIR__ . '/RewardRedemptionService.php';
        $service = new RewardRedemptionService($this->db, $this->lineAccountId, $this->ledger);

        $result = $service->redeem((int) $userId, (int) $rewardId);

        if (!$result['ok']) {
            return ['success' => false, 'message' => $result['message']];
        }

        return [
            'success' => true,
            'message' => $result['message'],
            'redemption_code' => $result['redemption_code'],
            'reward' => $result['reward'],
            'redemption_id' => $result['redemption_id'],
            'expires_at' => $result['reward']['valid_until'] ?? null,
        ];
    }

    /**
     * NOTE(Phase 5): generateUniqueRedemptionCode() lived here and is gone.
     * RewardRedemptionService owns code generation now, so keeping a second
     * implementation would just be a second thing to drift.
     */
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
