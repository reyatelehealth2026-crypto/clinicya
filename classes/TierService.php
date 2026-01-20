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

    // Default tiers if database table is empty
    const DEFAULT_TIERS = [
        ['tier_code' => 'bronze', 'tier_name' => 'Bronze', 'min_points' => 0, 'color' => '#CD7F32', 'icon' => '🥉', 'discount_percent' => 0],
        ['tier_code' => 'silver', 'tier_name' => 'Silver', 'min_points' => 1000, 'color' => '#C0C0C0', 'icon' => '🥈', 'discount_percent' => 3],
        ['tier_code' => 'gold', 'tier_name' => 'Gold', 'min_points' => 5000, 'color' => '#FFD700', 'icon' => '🥇', 'discount_percent' => 5],
        ['tier_code' => 'platinum', 'tier_name' => 'Platinum', 'min_points' => 15000, 'color' => '#6366F1', 'icon' => '💎', 'discount_percent' => 10]
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
            // Try tier_settings table first (this is where settings page saves!)
            $stmt = $this->db->prepare("
                SELECT name as tier_name, LOWER(REPLACE(name, ' ', '_')) as tier_code, 
                       min_points, badge_color as color, multiplier as discount_percent
                FROM tier_settings 
                WHERE (line_account_id = ? OR line_account_id IS NULL)
                ORDER BY min_points ASC
            ");
            $stmt->execute([$this->lineAccountId]);
            $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Add default icons based on tier name
            foreach ($tiers as &$tier) {
                $tier['icon'] = $this->getIconForTier($tier['tier_name']);
            }

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
        }

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
        // Get user points - try multiple columns
        $points = 0;

        try {
            $stmt = $this->db->prepare("
                SELECT points, total_points, available_points 
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Use total_points if available, otherwise points
                $points = (int) ($user['total_points'] ?? $user['points'] ?? 0);
            }
        } catch (Exception $e) {
            // Error fetching user points
        }

        return $this->calculateTier($points);
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
    }
}
