<?php
/**
 * Drug Pricing Engine Service
 * 
 * Provides real-time margin calculation, discount management, and customer loyalty
 * information for pharmacy consultations.
 * 
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
 */

class DrugPricingEngineService
{
    private $db;
    private $lineAccountId;
    
    // Default minimum margin percentage
    const DEFAULT_MIN_MARGIN = 10.0;
    
    // Alternative offer types when discount exceeds threshold
    const ALT_FREE_DELIVERY = 'free_delivery';
    const ALT_BONUS_VITAMINS = 'bonus_vitamins';
    const ALT_LOYALTY_POINTS = 'loyalty_points';
    const ALT_NEXT_PURCHASE = 'next_purchase_discount';
    
    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID for multi-tenant support
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Calculate margin for a drug
     * 
     * Requirements 3.1: Display cost, selling price, and current margin percentage
     * 
     * @param int $drugId Drug/Product ID from business_items
     * @param float|null $customPrice Optional custom selling price for real-time calculation
     * @return array ['cost' => float, 'price' => float, 'margin' => float, 'marginPercent' => float]
     */
    public function calculateMargin(int $drugId, ?float $customPrice = null): array
    {
        // Get drug data from business_items - try with cost_price first, fallback to without
        try {
            $stmt = $this->db->prepare("
                SELECT id, name, price, sale_price, cost_price 
                FROM business_items 
                WHERE id = ?
            ");
            $stmt->execute([$drugId]);
            $drug = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // cost_price column might not exist, try without it
            $stmt = $this->db->prepare("
                SELECT id, name, price, sale_price 
                FROM business_items 
                WHERE id = ?
            ");
            $stmt->execute([$drugId]);
            $drug = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$drug) {
            return [
                'cost' => 0.0,
                'price' => 0.0,
                'margin' => 0.0,
                'marginPercent' => 0.0,
                'error' => 'Drug not found'
            ];
        }
        
        // Get cost - try cost_price first, then estimate from price (assume 30% margin)
        $cost = (float)($drug['cost_price'] ?? 0);
        if ($cost <= 0) {
            // Estimate cost as 70% of price if not available
            $basePrice = (float)($drug['sale_price'] ?? $drug['price'] ?? 0);
            $cost = $basePrice * 0.7;
        }
        
        // Use custom price if provided, otherwise use sale_price or regular price
        if ($customPrice !== null) {
            $price = $customPrice;
        } else {
            $price = (float)($drug['sale_price'] ?? $drug['price'] ?? 0);
        }
        
        // Calculate margin
        $margin = $price - $cost;
        
        // Calculate margin percentage: ((price - cost) / price) * 100
        // Property 1: Drug Margin Calculation Correctness
        $marginPercent = $price > 0 ? (($price - $cost) / $price) * 100 : 0.0;
        
        return [
            'drugId' => $drugId,
            'drugName' => $drug['name'],
            'cost' => round($cost, 2),
            'price' => round($price, 2),
            'margin' => round($margin, 2),
            'marginPercent' => round($marginPercent, 2)
        ];
    }
    
    /**
     * Get maximum allowable discount while maintaining minimum margin
     * 
     * Requirements 3.2: Calculate maximum allowable discount while maintaining minimum margin
     * Property 2: Maximum Discount Preserves Minimum Margin
     * 
     * @param int $drugId Drug ID
     * @param float $minMarginPercent Minimum margin percentage to maintain (default 10%)
     * @return array ['maxDiscount' => float, 'maxDiscountPercent' => float, 'floorPrice' => float]
     */
    public function getMaxDiscount(int $drugId, float $minMarginPercent = self::DEFAULT_MIN_MARGIN): array
    {
        // Get current pricing
        $pricing = $this->calculateMargin($drugId);
        
        if (isset($pricing['error'])) {
            return [
                'maxDiscount' => 0.0,
                'maxDiscountPercent' => 0.0,
                'floorPrice' => 0.0,
                'error' => $pricing['error']
            ];
        }
        
        $cost = $pricing['cost'];
        $price = $pricing['price'];
        
        // Calculate floor price that maintains minimum margin
        // If margin% = ((price - cost) / price) * 100 >= minMargin
        // Then: price - cost >= price * minMargin / 100
        // So: price * (1 - minMargin/100) >= cost
        // Therefore: floorPrice = cost / (1 - minMargin/100)
        
        $minMarginDecimal = $minMarginPercent / 100;
        
        if ($minMarginDecimal >= 1) {
            // Invalid margin percentage (100% or more)
            return [
                'maxDiscount' => 0.0,
                'maxDiscountPercent' => 0.0,
                'floorPrice' => $price,
                'currentPrice' => $price,
                'cost' => $cost,
                'minMarginPercent' => $minMarginPercent,
                'error' => 'Invalid minimum margin percentage'
            ];
        }
        
        $floorPrice = $cost / (1 - $minMarginDecimal);
        
        // Max discount is the difference between current price and floor price
        $maxDiscount = max(0, $price - $floorPrice);
        
        // Max discount percentage
        $maxDiscountPercent = $price > 0 ? ($maxDiscount / $price) * 100 : 0.0;
        
        return [
            'drugId' => $drugId,
            'maxDiscount' => round($maxDiscount, 2),
            'maxDiscountPercent' => round($maxDiscountPercent, 2),
            'floorPrice' => round($floorPrice, 2),
            'currentPrice' => round($price, 2),
            'cost' => round($cost, 2),
            'minMarginPercent' => $minMarginPercent
        ];
    }

    
    /**
     * Suggest alternatives when discount exceeds safe threshold
     * 
     * Requirements 3.3: Suggest alternative offers instead of excessive discounts
     * Property 3: Discount Threshold Triggers Alternatives
     * 
     * @param int $drugId Drug ID
     * @param float $requestedDiscount Requested discount amount in baht
     * @return array ['alternatives' => array, 'exceedsThreshold' => bool]
     */
    public function suggestAlternatives(int $drugId, float $requestedDiscount): array
    {
        // Get max allowable discount
        $maxDiscountInfo = $this->getMaxDiscount($drugId);
        
        if (isset($maxDiscountInfo['error'])) {
            return [
                'alternatives' => [],
                'exceedsThreshold' => false,
                'error' => $maxDiscountInfo['error']
            ];
        }
        
        $maxDiscount = $maxDiscountInfo['maxDiscount'];
        $exceedsThreshold = $requestedDiscount > $maxDiscount;
        
        $alternatives = [];
        
        if ($exceedsThreshold) {
            $excessAmount = $requestedDiscount - $maxDiscount;
            
            // Alternative 1: Free delivery (typically worth 40-60 baht)
            $alternatives[] = [
                'type' => self::ALT_FREE_DELIVERY,
                'name' => 'ส่งฟรี',
                'description' => 'ฟรีค่าจัดส่ง',
                'value' => 50.0,
                'icon' => 'fa-truck'
            ];
            
            // Alternative 2: Bonus vitamins/supplements
            $alternatives[] = [
                'type' => self::ALT_BONUS_VITAMINS,
                'name' => 'แถมวิตามิน',
                'description' => 'แถมวิตามินซี 10 เม็ด',
                'value' => round($excessAmount * 0.8, 2),
                'icon' => 'fa-pills'
            ];
            
            // Alternative 3: Extra loyalty points
            $bonusPoints = (int)ceil($excessAmount * 2); // 2 points per baht
            $alternatives[] = [
                'type' => self::ALT_LOYALTY_POINTS,
                'name' => 'แต้มพิเศษ',
                'description' => "รับแต้มสะสมเพิ่ม {$bonusPoints} แต้ม",
                'value' => $bonusPoints,
                'icon' => 'fa-star'
            ];
            
            // Alternative 4: Discount on next purchase
            $nextDiscount = round($excessAmount * 1.2, 2); // 120% of excess as future discount
            $alternatives[] = [
                'type' => self::ALT_NEXT_PURCHASE,
                'name' => 'ส่วนลดครั้งหน้า',
                'description' => "รับส่วนลด ฿{$nextDiscount} สำหรับการซื้อครั้งถัดไป",
                'value' => $nextDiscount,
                'icon' => 'fa-ticket'
            ];
        }
        
        return [
            'drugId' => $drugId,
            'requestedDiscount' => round($requestedDiscount, 2),
            'maxAllowableDiscount' => round($maxDiscount, 2),
            'exceedsThreshold' => $exceedsThreshold,
            'excessAmount' => $exceedsThreshold ? round($requestedDiscount - $maxDiscount, 2) : 0.0,
            'alternatives' => $alternatives,
            'recommendation' => $exceedsThreshold 
                ? 'ส่วนลดที่ขอเกินกว่าที่กำหนด แนะนำให้เสนอทางเลือกอื่นแทน'
                : 'สามารถให้ส่วนลดได้ตามที่ขอ'
        ];
    }
    
    /**
     * Get customer's loyalty status and discount history
     * 
     * Requirements 3.5: Display customer's typical discount expectation and loyalty status
     * 
     * @param int $userId Customer user ID
     * @return array ['loyaltyTier' => string, 'avgDiscount' => float, 'totalPurchases' => float]
     */
    public function getCustomerLoyalty(int $userId): array
    {
        // Get user's total points and tier
        $tierInfo = $this->getUserTierInfo($userId);
        
        // Get purchase history statistics
        $purchaseStats = $this->getPurchaseStats($userId);
        
        // Get average discount from past orders
        $avgDiscount = $this->getAverageDiscount($userId);
        
        return [
            'userId' => $userId,
            'loyaltyTier' => $tierInfo['tier'],
            'tierColor' => $tierInfo['color'],
            'totalPoints' => $tierInfo['points'],
            'pointsToNextTier' => $tierInfo['pointsToNext'],
            'nextTierName' => $tierInfo['nextTier'],
            'avgDiscount' => round($avgDiscount, 2),
            'avgDiscountPercent' => $purchaseStats['avgOrderValue'] > 0 
                ? round(($avgDiscount / $purchaseStats['avgOrderValue']) * 100, 2) 
                : 0.0,
            'totalPurchases' => round($purchaseStats['totalSpent'], 2),
            'orderCount' => $purchaseStats['orderCount'],
            'avgOrderValue' => round($purchaseStats['avgOrderValue'], 2),
            'lastPurchaseDate' => $purchaseStats['lastPurchase'],
            'discountExpectation' => $this->calculateDiscountExpectation($tierInfo['tier'], $avgDiscount)
        ];
    }
    
    /**
     * Get user tier information
     * @param int $userId User ID
     * @return array Tier info
     */
    private function getUserTierInfo(int $userId): array
    {
        // Default tier structure
        $defaultTiers = [
            ['name' => 'Bronze', 'min_points' => 0, 'color' => '#CD7F32'],
            ['name' => 'Silver', 'min_points' => 1000, 'color' => '#C0C0C0'],
            ['name' => 'Gold', 'min_points' => 5000, 'color' => '#FFD700'],
            ['name' => 'Platinum', 'min_points' => 15000, 'color' => '#E5E4E2']
        ];
        
        // Get user's points
        $points = 0;
        try {
            $stmt = $this->db->prepare("SELECT total_points, available_points FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // `users.points` used to be the fallback — it holds only
                // withdrawn welcome bonuses now (ADR-008).
                $points = (int)($user['total_points'] ?? $user['available_points'] ?? 0);
            }
        } catch (PDOException $e) {
            // Use default 0 points
        }
        
        // Try to get custom tiers
        $tiers = $defaultTiers;
        try {
            $stmt = $this->db->prepare("
                SELECT name, tier_name, min_points, badge_color as color 
                FROM member_tiers 
                WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1 
                ORDER BY min_points ASC
            ");
            $stmt->execute([$this->lineAccountId]);
            $customTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($customTiers)) {
                $tiers = array_map(function($t) {
                    return [
                        'name' => $t['tier_name'] ?? $t['name'],
                        'min_points' => (int)$t['min_points'],
                        'color' => $t['color'] ?? '#6B7280'
                    ];
                }, $customTiers);
            }
        } catch (PDOException $e) {
            // Try points_tiers table
            try {
                $stmt = $this->db->prepare("
                    SELECT name, min_points, color 
                    FROM points_tiers 
                    WHERE line_account_id = ? OR line_account_id IS NULL 
                    ORDER BY min_points ASC
                ");
                $stmt->execute([$this->lineAccountId]);
                $customTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($customTiers)) {
                    $tiers = $customTiers;
                }
            } catch (PDOException $e2) {
                // Use default tiers
            }
        }
        
        // Determine current tier
        $currentTier = $tiers[0];
        $nextTier = null;
        
        foreach ($tiers as $i => $tier) {
            if ($points >= $tier['min_points']) {
                $currentTier = $tier;
                $nextTier = $tiers[$i + 1] ?? null;
            }
        }
        
        return [
            'tier' => $currentTier['name'],
            'color' => $currentTier['color'] ?? '#6B7280',
            'points' => $points,
            'pointsToNext' => $nextTier ? max(0, $nextTier['min_points'] - $points) : 0,
            'nextTier' => $nextTier ? $nextTier['name'] : null
        ];
    }

    
    /**
     * Get purchase statistics for a user
     * @param int $userId User ID
     * @return array Purchase stats
     */
    private function getPurchaseStats(int $userId): array
    {
        $stats = [
            'totalSpent' => 0.0,
            'orderCount' => 0,
            'avgOrderValue' => 0.0,
            'lastPurchase' => null
        ];
        
        try {
            // Try transactions table first
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as order_count,
                    COALESCE(SUM(grand_total), 0) as total_spent,
                    COALESCE(AVG(grand_total), 0) as avg_order,
                    MAX(created_at) as last_purchase
                FROM transactions 
                WHERE user_id = ? AND status NOT IN ('cancelled', 'pending', 'failed')
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $stats['totalSpent'] = (float)$result['total_spent'];
                $stats['orderCount'] = (int)$result['order_count'];
                $stats['avgOrderValue'] = (float)$result['avg_order'];
                $stats['lastPurchase'] = $result['last_purchase'];
            }
        } catch (PDOException $e) {
            // Try orders table as fallback
            try {
                $stmt = $this->db->prepare("
                    SELECT 
                        COUNT(*) as order_count,
                        COALESCE(SUM(grand_total), 0) as total_spent,
                        COALESCE(AVG(grand_total), 0) as avg_order,
                        MAX(created_at) as last_purchase
                    FROM orders 
                    WHERE user_id = ? AND status IN ('paid', 'confirmed', 'delivered', 'completed')
                ");
                $stmt->execute([$userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    $stats['totalSpent'] = (float)$result['total_spent'];
                    $stats['orderCount'] = (int)$result['order_count'];
                    $stats['avgOrderValue'] = (float)$result['avg_order'];
                    $stats['lastPurchase'] = $result['last_purchase'];
                }
            } catch (PDOException $e2) {
                // Return default stats
            }
        }
        
        return $stats;
    }
    
    /**
     * Get average discount amount from past orders
     * @param int $userId User ID
     * @return float Average discount
     */
    private function getAverageDiscount(int $userId): float
    {
        try {
            // Try transactions table
            $stmt = $this->db->prepare("
                SELECT COALESCE(AVG(discount_amount), 0) as avg_discount
                FROM transactions 
                WHERE user_id = ? 
                AND status NOT IN ('cancelled', 'pending', 'failed')
                AND discount_amount > 0
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['avg_discount'] > 0) {
                return (float)$result['avg_discount'];
            }
            
            // Try with discount column
            $stmt = $this->db->prepare("
                SELECT COALESCE(AVG(discount), 0) as avg_discount
                FROM transactions 
                WHERE user_id = ? 
                AND status NOT IN ('cancelled', 'pending', 'failed')
                AND discount > 0
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (float)($result['avg_discount'] ?? 0);
        } catch (PDOException $e) {
            return 0.0;
        }
    }
    
    /**
     * Calculate discount expectation based on tier and history
     * @param string $tier Customer tier
     * @param float $avgDiscount Average historical discount
     * @return array Discount expectation info
     */
    private function calculateDiscountExpectation(string $tier, float $avgDiscount): array
    {
        // Base discount expectations by tier
        $tierExpectations = [
            'Bronze' => ['min' => 0, 'max' => 5, 'typical' => 0],
            'Silver' => ['min' => 3, 'max' => 8, 'typical' => 5],
            'Gold' => ['min' => 5, 'max' => 12, 'typical' => 8],
            'Platinum' => ['min' => 8, 'max' => 15, 'typical' => 10]
        ];
        
        $expectation = $tierExpectations[$tier] ?? $tierExpectations['Bronze'];
        
        // Adjust based on historical average
        if ($avgDiscount > 0) {
            $expectation['historical'] = round($avgDiscount, 2);
            $expectation['recommendation'] = "ลูกค้าเคยได้รับส่วนลดเฉลี่ย ฿" . number_format($avgDiscount, 2);
        } else {
            $expectation['historical'] = 0;
            $expectation['recommendation'] = "ลูกค้าใหม่ แนะนำส่วนลด {$expectation['typical']}%";
        }
        
        return $expectation;
    }
    
    /**
     * Calculate real-time margin impact for a custom price
     * 
     * Requirements 3.4: Show real-time margin impact before confirming custom price
     * 
     * @param int $drugId Drug ID
     * @param float $customPrice Custom selling price
     * @return array Margin impact analysis
     */
    public function calculateMarginImpact(int $drugId, float $customPrice): array
    {
        // Get original pricing
        $originalPricing = $this->calculateMargin($drugId);
        
        if (isset($originalPricing['error'])) {
            return [
                'error' => $originalPricing['error']
            ];
        }
        
        // Calculate with custom price
        $customPricing = $this->calculateMargin($drugId, $customPrice);
        
        // Calculate differences
        $priceDiff = $customPrice - $originalPricing['price'];
        $marginDiff = $customPricing['margin'] - $originalPricing['margin'];
        $marginPercentDiff = $customPricing['marginPercent'] - $originalPricing['marginPercent'];
        
        // Determine status
        $status = 'ok';
        $warning = null;
        
        if ($customPricing['marginPercent'] < 0) {
            $status = 'loss';
            $warning = 'ราคานี้จะทำให้ขาดทุน!';
        } elseif ($customPricing['marginPercent'] < self::DEFAULT_MIN_MARGIN) {
            $status = 'low_margin';
            $warning = 'กำไรต่ำกว่าเกณฑ์ขั้นต่ำ ' . self::DEFAULT_MIN_MARGIN . '%';
        } elseif ($marginPercentDiff < -5) {
            $status = 'significant_reduction';
            $warning = 'กำไรลดลงมากกว่า 5%';
        }
        
        return [
            'drugId' => $drugId,
            'drugName' => $originalPricing['drugName'],
            'original' => [
                'price' => $originalPricing['price'],
                'margin' => $originalPricing['margin'],
                'marginPercent' => $originalPricing['marginPercent']
            ],
            'custom' => [
                'price' => round($customPrice, 2),
                'margin' => $customPricing['margin'],
                'marginPercent' => $customPricing['marginPercent']
            ],
            'impact' => [
                'priceDiff' => round($priceDiff, 2),
                'marginDiff' => round($marginDiff, 2),
                'marginPercentDiff' => round($marginPercentDiff, 2)
            ],
            'status' => $status,
            'warning' => $warning,
            'cost' => $originalPricing['cost']
        ];
    }
    
    /**
     * Get pricing summary for multiple drugs
     * @param array $drugIds Array of drug IDs
     * @return array Pricing summaries
     */
    public function getBulkPricing(array $drugIds): array
    {
        $results = [];
        
        foreach ($drugIds as $drugId) {
            $results[$drugId] = $this->calculateMargin((int)$drugId);
        }
        
        return $results;
    }
    
    /**
     * Check if a discount is within safe limits
     * @param int $drugId Drug ID
     * @param float $discountAmount Discount amount in baht
     * @param float $minMarginPercent Minimum margin to maintain
     * @return array Validation result
     */
    public function validateDiscount(int $drugId, float $discountAmount, float $minMarginPercent = self::DEFAULT_MIN_MARGIN): array
    {
        $maxDiscountInfo = $this->getMaxDiscount($drugId, $minMarginPercent);
        
        if (isset($maxDiscountInfo['error'])) {
            return [
                'valid' => false,
                'error' => $maxDiscountInfo['error']
            ];
        }
        
        $isValid = $discountAmount <= $maxDiscountInfo['maxDiscount'];
        
        return [
            'valid' => $isValid,
            'requestedDiscount' => round($discountAmount, 2),
            'maxAllowableDiscount' => $maxDiscountInfo['maxDiscount'],
            'excessAmount' => $isValid ? 0 : round($discountAmount - $maxDiscountInfo['maxDiscount'], 2),
            'finalPrice' => round($maxDiscountInfo['currentPrice'] - $discountAmount, 2),
            'finalMarginPercent' => $maxDiscountInfo['currentPrice'] > 0 
                ? round((($maxDiscountInfo['currentPrice'] - $discountAmount - $maxDiscountInfo['cost']) / ($maxDiscountInfo['currentPrice'] - $discountAmount)) * 100, 2)
                : 0
        ];
    }
}
