<?php
/**
 * TierService - Unified Membership Tier Management
 * 
 * Single source of truth for tier calculations.
 * Uses member_tiers table as primary data source.
 * 
 * @package LoyaltySystem
 * @version 1.0.0
 */

class TierService
{
    private $db;
    private $lineAccountId;
    private static $tierCache = [];

    /** @var array<string, bool> memoised column probes, shared across instances */
    private static $columnCache = [];

    // Default tiers if database table is empty
    const DEFAULT_TIERS = [
        ['tier_code' => 'bronze', 'tier_name' => 'Bronze', 'min_points' => 0, 'color' => '#CD7F32', 'icon' => '🥉', 'discount_percent' => 0, 'earn_multiplier' => 1.0],
        ['tier_code' => 'silver', 'tier_name' => 'Silver', 'min_points' => 1000, 'color' => '#C0C0C0', 'icon' => '🥈', 'discount_percent' => 3, 'earn_multiplier' => 1.0],
        ['tier_code' => 'gold', 'tier_name' => 'Gold', 'min_points' => 5000, 'color' => '#FFD700', 'icon' => '🥇', 'discount_percent' => 5, 'earn_multiplier' => 1.0],
        ['tier_code' => 'platinum', 'tier_name' => 'Platinum', 'min_points' => 15000, 'color' => '#6366F1', 'icon' => '💎', 'discount_percent' => 10, 'earn_multiplier' => 1.0],
    ];

    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID for multi-tenant
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }

    /**
     * Get all tier definitions
     * Loads from member_tiers table with fallback to defaults
     * 
     * @return array List of tier definitions
     */
    public function getTiers(): array
    {
        $cacheKey = 'tiers_' . $this->lineAccountId;

        // Check cache
        if (isset(self::$tierCache[$cacheKey])) {
            return self::$tierCache[$cacheKey];
        }

        $tiers = [];

        try {
            // PHASE 3: read the two benefits as the two separate concepts they are.
            //
            // This used to be `multiplier AS discount_percent`, so a Gold tier the
            // pharmacy configured as "earn 1.5x points" (that column's own DB
            // comment, and the admin UI's label "ตัวคูณแต้ม") was served to the
            // mini app as "1.5% discount". `earn_multiplier` and
            // `discount_percent` are now distinct columns; on a tenant that has
            // not yet run migration_2026-08-26_tier_semantics.sql we fall back to
            // reading `multiplier` as the EARN multiplier — its documented
            // meaning — rather than as a discount.
            $hasSplit = $this->columnExists('tier_settings', 'earn_multiplier');

            $sql = $hasSplit
                ? "SELECT name as tier_name, LOWER(REPLACE(name, ' ', '_')) as tier_code,
                          min_points, badge_color as color,
                          earn_multiplier, discount_percent
                     FROM tier_settings
                    WHERE (line_account_id = ? OR line_account_id IS NULL)
                    ORDER BY min_points ASC"
                : "SELECT name as tier_name, LOWER(REPLACE(name, ' ', '_')) as tier_code,
                          min_points, badge_color as color,
                          multiplier as earn_multiplier, 0 as discount_percent
                     FROM tier_settings
                    WHERE (line_account_id = ? OR line_account_id IS NULL)
                    ORDER BY min_points ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->lineAccountId]);
            $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add default icons based on tier name
            foreach ($tiers as &$tier) {
                $tier['icon'] = $this->getIconForTier($tier['tier_name']);
            }
            unset($tier);
        } catch (Exception $e) {
            // tier_settings table might not exist - try member_tiers as fallback
            try {
                $stmt = $this->db->prepare("
                    SELECT tier_code, tier_name, min_points, color, icon, discount_percent, benefits
                    FROM member_tiers
                    WHERE (line_account_id = ? OR line_account_id IS NULL)
                    AND is_active = 1
                    ORDER BY min_points ASC
                ");
                $stmt->execute([$this->lineAccountId]);
                $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                // Use defaults
            }
        }

        // Use defaults if no tiers found
        if (empty($tiers)) {
            $tiers = self::DEFAULT_TIERS;
        }

        // Normalize tier data
        foreach ($tiers as &$tier) {
            $tier['tier_code'] = $tier['tier_code'] ?? strtolower($tier['tier_name'] ?? 'bronze');
            $tier['tier_name'] = $tier['tier_name'] ?? ucfirst($tier['tier_code']);
            $tier['min_points'] = (int) ($tier['min_points'] ?? 0);
            $tier['color'] = $tier['color'] ?? '#6B7280';
            $tier['icon'] = $tier['icon'] ?? '🏅';
            $tier['discount_percent'] = (float) ($tier['discount_percent'] ?? 0);
            $tier['earn_multiplier'] = (float) ($tier['earn_multiplier'] ?? 1.0);
            if ($tier['earn_multiplier'] <= 0) {
                $tier['earn_multiplier'] = 1.0;
            }
        }
        unset($tier);

        // Cache result
        self::$tierCache[$cacheKey] = $tiers;

        return $tiers;
    }

    /**
     * Calculate tier from points
     * 
     * @param int $points Total points to determine tier
     * @return array Tier information with next tier data
     */
    public function calculateTier(int $points): array
    {
        $tiers = $this->getTiers();

        // Determine current tier (highest tier where points >= min_points)
        $currentTier = $tiers[0];
        $currentIndex = 0;

        foreach ($tiers as $index => $tier) {
            if ($points >= $tier['min_points']) {
                $currentTier = $tier;
                $currentIndex = $index;
            }
        }

        // Calculate next tier info
        $nextTier = isset($tiers[$currentIndex + 1]) ? $tiers[$currentIndex + 1] : null;
        $pointsToNext = $nextTier ? max(0, $nextTier['min_points'] - $points) : 0;

        // Calculate progress percentage
        $progress = 100;
        if ($nextTier) {
            $rangeStart = $currentTier['min_points'];
            $rangeEnd = $nextTier['min_points'];
            $range = $rangeEnd - $rangeStart;
            if ($range > 0) {
                $progress = min(100, floor((($points - $rangeStart) / $range) * 100));
            }
        }

        return [
            // Current tier info
            'tier_code' => $currentTier['tier_code'],
            'tier_name' => $currentTier['tier_name'],
            'name' => $currentTier['tier_name'], // Alias for compatibility
            'color' => $currentTier['color'],
            'icon' => $currentTier['icon'],
            'discount_percent' => $currentTier['discount_percent'],
            'earn_multiplier' => $currentTier['earn_multiplier'] ?? 1.0,
            'min_points' => $currentTier['min_points'],

            // Points info
            'current_points' => $points,
            'points_to_next' => $pointsToNext,
            'progress_percent' => $progress,

            // Next tier info
            'next_tier_code' => $nextTier['tier_code'] ?? null,
            'next_tier_name' => $nextTier['tier_name'] ?? 'Max Level',
            'next_tier_points' => $nextTier['min_points'] ?? null
        ];
    }

    /**
     * Get user's current tier
     * Fetches user points and calculates tier
     * 
     * @param int $userId User ID
     * @return array Tier information
     */
    public function getUserTier(int $userId): array
    {
        return $this->calculateTier($this->getQualifyingPoints($userId));
    }

    /**
     * The metric a tier is measured on: LIFETIME EARNED points, from the ledger.
     *
     * PHASE 3 (plan §12). Three separate concepts were previously conflated:
     *
     *   available_points   spendable currency
     *   total_points       lifetime earned
     *   qualifying_points  what determines STATUS   <- this
     *
     * The old implementation read `users.total_points ?? users.points`, a cache
     * that several writers never updated, and — worse — LoyaltyPoints called
     * updateUserTier() with the post-movement AVAILABLE balance, so spending
     * points demoted the member. Every redeem, POS points payment, POS void, POS
     * return and account merge was a downgrade path.
     *
     * Reading lifetime earned from the ledger makes a tier monotonic: a customer
     * who earned their way to Gold stays Gold after spending, which is what any
     * member would expect and what the tier is supposed to reward.
     *
     * Note `??` in the old code was a NULL-coalesce, so a row with
     * `total_points = 0` and `points = 4200` evaluated to 0 and showed Bronze
     * while the same screen showed 4,200 spendable points.
     */
    public function getQualifyingPoints(int $userId): int
    {
        try {
            require_once __DIR__ . '/LoyaltyLedgerService.php';
            $ledger = new LoyaltyLedgerService($this->db, $this->lineAccountId);

            return $ledger->getQualifyingPoints($userId);
        } catch (Exception $e) {
            error_log('TierService: qualifying points unavailable for user ' . $userId . ': ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Column introspection that works on MySQL and on sqlite (tests), instead of
     * a bare SHOW COLUMNS whose failure would be swallowed into "column missing".
     */
    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$columnCache)) {
            return self::$columnCache[$cacheKey];
        }

        $exists = false;
        try {
            $driver = strtolower((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $stmt = $this->db->query('PRAGMA table_info(`' . $table . '`)');
                foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                    if (isset($row['name']) && strcasecmp((string) $row['name'], $column) === 0) {
                        $exists = true;
                        break;
                    }
                }
            } else {
                $stmt = $this->db->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
                $stmt->execute([$column]);
                $exists = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }
        } catch (Exception $e) {
            $exists = false;
        }

        return self::$columnCache[$cacheKey] = $exists;
    }

    /**
     * Update user's tier in database
     * Call this after points change to keep users.member_tier in sync
     * 
     * @param int $userId User ID
     * @return bool Success
     */
    public function updateUserTier(int $userId): bool
    {
        try {
            $tierInfo = $this->getUserTier($userId);

            $stmt = $this->db->prepare("
                UPDATE users 
                SET member_tier = ? 
                WHERE id = ?
            ");
            $stmt->execute([$tierInfo['tier_code'], $userId]);

            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            // member_tier column might not exist
            return false;
        }
    }

    /**
     * Get icon for tier name (internal helper)
     * 
     * @param string $tierName Tier name
     * @return string Emoji icon
     */
    private function getIconForTier(string $tierName): string
    {
        $name = strtolower($tierName);
        if (strpos($name, 'bronze') !== false || strpos($name, 'member') !== false)
            return '🥉';
        if (strpos($name, 'silver') !== false)
            return '🥈';
        if (strpos($name, 'gold') !== false)
            return '🥇';
        if (strpos($name, 'platinum') !== false || strpos($name, 'diamond') !== false)
            return '💎';
        if (strpos($name, 'vip') !== false || strpos($name, 'royal') !== false)
            return '👑';
        return '🏅';
    }

    /**
     * Get tier icon by tier name
     * Helper for backwards compatibility
     * 
     * @param string $tierName Tier name
     * @return string Emoji icon
     */
    public static function getTierIcon(string $tierName): string
    {
        $icons = [
            'bronze' => '🥉',
            'silver' => '🥈',
            'gold' => '🥇',
            'platinum' => '💎',
            'vip' => '👑',
            'member' => '🏅'
        ];
        return $icons[strtolower($tierName)] ?? '🏅';
    }

    /**
     * Get tier color by tier name
     * Helper for backwards compatibility
     * 
     * @param string $tierName Tier name
     * @return string Hex color
     */
    public static function getTierColor(string $tierName): string
    {
        $colors = [
            'bronze' => '#CD7F32',
            'silver' => '#C0C0C0',
            'gold' => '#FFD700',
            'platinum' => '#6366F1',
            'vip' => '#EC4899',
            'member' => '#9CA3AF'
        ];
        return $colors[strtolower($tierName)] ?? '#6B7280';
    }

    /**
     * Clear tier cache
     * Call this after updating member_tiers table
     */
    public static function clearCache(): void
    {
        self::$tierCache = [];
        self::$columnCache = [];
    }
}
