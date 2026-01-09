<?php
/**
 * TrustBadgeService - จัดการ Trust Badges สำหรับ Landing Page
 * 
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 10.5
 */

class TrustBadgeService {
    private $db;
    private $lineAccountId;
    
    // Available icons for custom badges
    public const AVAILABLE_ICONS = [
        'shield-check' => 'Shield Check',
        'certificate' => 'Certificate',
        'award' => 'Award',
        'medal' => 'Medal',
        'trophy' => 'Trophy',
        'star' => 'Star',
        'heart' => 'Heart',
        'thumbs-up' => 'Thumbs Up',
        'check-circle' => 'Check Circle',
        'badge-check' => 'Badge Check',
        'user-check' => 'User Check',
        'clock' => 'Clock',
        'truck' => 'Truck',
        'headset' => 'Headset',
        'pills' => 'Pills',
        'stethoscope' => 'Stethoscope',
        'hand-holding-medical' => 'Medical Hand',
        'hospital' => 'Hospital',
        'first-aid' => 'First Aid',
        'prescription-bottle' => 'Prescription'
    ];
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get all trust badges with data
     * Requirements: 3.1-3.5 - Display trust indicators
     * 
     * @return array Array of badges with their data
     */
    public function getBadges(): array {
        $badges = [];
        
        // License badge (Requirements: 3.1)
        $licenseInfo = $this->getLicenseInfo();
        if ($licenseInfo !== null) {
            $badges[] = [
                'type' => 'license',
                'icon' => 'shield-check',
                'label' => 'ใบอนุญาตร้านยา',
                'value' => $licenseInfo['number'],
                'data' => $licenseInfo
            ];
        }
        
        // Customer count badge (Requirements: 3.2)
        $customerCount = $this->getCustomerCount();
        if ($customerCount > 0) {
            $badges[] = [
                'type' => 'customers',
                'icon' => 'users',
                'label' => 'ลูกค้าที่ไว้วางใจ',
                'value' => $this->formatNumber($customerCount) . '+',
                'data' => ['count' => $customerCount]
            ];
        }
        
        // Order count badge (Requirements: 3.2)
        $orderCount = $this->getOrderCount();
        if ($orderCount > 0) {
            $badges[] = [
                'type' => 'orders',
                'icon' => 'shopping-bag',
                'label' => 'ออเดอร์สำเร็จ',
                'value' => $this->formatNumber($orderCount) . '+',
                'data' => ['count' => $orderCount]
            ];
        }
        
        // Rating badge (Requirements: 3.3)
        $avgRating = $this->getAverageRating();
        $reviewCount = $this->getReviewCount();
        if ($avgRating > 0 && $reviewCount > 0) {
            $badges[] = [
                'type' => 'rating',
                'icon' => 'star',
                'label' => 'คะแนนรีวิว',
                'value' => number_format($avgRating, 1) . '/5',
                'data' => [
                    'rating' => $avgRating,
                    'count' => $reviewCount
                ]
            ];
        }
        
        // Years of operation badge (Requirements: 3.4)
        $establishmentYear = $this->getEstablishmentYear();
        if ($establishmentYear !== null) {
            $yearsInBusiness = date('Y') - $establishmentYear;
            if ($yearsInBusiness > 0) {
                $badges[] = [
                    'type' => 'experience',
                    'icon' => 'award',
                    'label' => 'ประสบการณ์',
                    'value' => $yearsInBusiness . ' ปี',
                    'data' => [
                        'establishment_year' => $establishmentYear,
                        'years' => $yearsInBusiness
                    ]
                ];
            }
        }
        
        // Custom badges (Requirements: 10.5)
        $customBadges = $this->getCustomBadges();
        foreach ($customBadges as $customBadge) {
            $badges[] = [
                'type' => 'custom',
                'icon' => $customBadge['icon'],
                'label' => $customBadge['label'],
                'value' => $customBadge['value'],
                'data' => ['id' => $customBadge['id']]
            ];
        }
        
        return $badges;
    }
    
    /**
     * Get custom badges from settings
     * Requirements: 10.5 - Custom badge configuration
     * 
     * @return array
     */
    public function getCustomBadges(): array {
        $customBadgesJson = $this->getSetting('custom_badges');
        
        if (empty($customBadgesJson)) {
            return [];
        }
        
        $customBadges = json_decode($customBadgesJson, true);
        
        if (!is_array($customBadges)) {
            return [];
        }
        
        // Filter only active badges
        return array_filter($customBadges, function($badge) {
            return isset($badge['is_active']) && $badge['is_active'];
        });
    }
    
    /**
     * Save custom badges to settings
     * Requirements: 10.5 - Custom badge configuration
     * 
     * @param array $badges Array of custom badges
     * @return bool
     */
    public function saveCustomBadges(array $badges): bool {
        // Validate and sanitize badges
        $sanitizedBadges = [];
        foreach ($badges as $index => $badge) {
            if (empty($badge['label']) || empty($badge['value'])) {
                continue;
            }
            
            $sanitizedBadges[] = [
                'id' => $badge['id'] ?? ($index + 1),
                'icon' => $this->validateIcon($badge['icon'] ?? 'star'),
                'label' => trim($badge['label']),
                'value' => trim($badge['value']),
                'is_active' => isset($badge['is_active']) ? (bool)$badge['is_active'] : true
            ];
        }
        
        return $this->saveSetting('custom_badges', json_encode($sanitizedBadges, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Validate icon name
     * 
     * @param string $icon
     * @return string
     */
    private function validateIcon(string $icon): string {
        return array_key_exists($icon, self::AVAILABLE_ICONS) ? $icon : 'star';
    }
    
    /**
     * Save a setting to landing_settings table
     * 
     * @param string $key
     * @param string $value
     * @return bool
     */
    private function saveSetting(string $key, string $value): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            return $stmt->execute([$this->lineAccountId, $key, $value]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get customer count from users/members table
     * Requirements: 3.2 - Display customer count statistics
     * 
     * @return int
     */
    public function getCustomerCount(): int {
        try {
            // Try users table first
            $sql = "SELECT COUNT(*) FROM users WHERE 1=1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get completed order count
     * Requirements: 3.2 - Display order count statistics
     * 
     * @return int
     */
    public function getOrderCount(): int {
        try {
            $sql = "SELECT COUNT(*) FROM orders WHERE status IN ('completed', 'delivered', 'shipped')";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get average rating from testimonials
     * Requirements: 3.3 - Display average rating
     * 
     * @return float
     */
    public function getAverageRating(): float {
        try {
            $sql = "SELECT AVG(rating) FROM landing_testimonials WHERE status = 'approved'";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $avg = $stmt->fetchColumn();
            
            return $avg ? round((float)$avg, 1) : 0.0;
        } catch (Exception $e) {
            return 0.0;
        }
    }
    
    /**
     * Get review count
     * 
     * @return int
     */
    public function getReviewCount(): int {
        try {
            $sql = "SELECT COUNT(*) FROM landing_testimonials WHERE status = 'approved'";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get license information from settings
     * Requirements: 3.1 - Display pharmacy license badge
     * 
     * @return array|null
     */
    public function getLicenseInfo(): ?array {
        $licenseNumber = $this->getSetting('license_number');
        
        if (empty($licenseNumber)) {
            return null;
        }
        
        return [
            'number' => $licenseNumber,
            'type' => 'pharmacy'
        ];
    }
    
    /**
     * Get establishment year from settings
     * Requirements: 3.4 - Display years of operation
     * 
     * @return int|null
     */
    public function getEstablishmentYear(): ?int {
        $year = $this->getSetting('establishment_year');
        
        if (empty($year)) {
            return null;
        }
        
        $year = (int)$year;
        
        // Validate year is reasonable (between 1900 and current year)
        if ($year < 1900 || $year > (int)date('Y')) {
            return null;
        }
        
        return $year;
    }

    
    /**
     * Get setting value from landing_settings table
     * 
     * @param string $key Setting key
     * @return string|null
     */
    private function getSetting(string $key): ?string {
        try {
            $sql = "SELECT setting_value FROM landing_settings WHERE setting_key = ?";
            $params = [$key];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            } else {
                $sql .= " AND line_account_id IS NULL";
            }
            
            $sql .= " ORDER BY line_account_id DESC LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            
            return $value !== false ? $value : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Format number for display (e.g., 1500 -> 1.5K)
     * 
     * @param int $number
     * @return string
     */
    private function formatNumber(int $number): string {
        if ($number >= 1000000) {
            return number_format($number / 1000000, 1) . 'M';
        }
        if ($number >= 1000) {
            return number_format($number / 1000, 1) . 'K';
        }
        return (string)$number;
    }
    
    /**
     * Get aggregate rating structured data for JSON-LD
     * 
     * @return array|null
     */
    public function getAggregateRatingData(): ?array {
        $avgRating = $this->getAverageRating();
        $reviewCount = $this->getReviewCount();
        
        if ($avgRating <= 0 || $reviewCount <= 0) {
            return null;
        }
        
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($avgRating, 1),
            'bestRating' => '5',
            'worstRating' => '1',
            'ratingCount' => $reviewCount
        ];
    }
    
    /**
     * Check if any trust badges are available
     * Requirements: 3.5 - Gracefully hide badges with missing data
     * 
     * @return bool
     */
    public function hasBadges(): bool {
        return count($this->getBadges()) > 0;
    }
    
    /**
     * Get specific badge by type
     * 
     * @param string $type Badge type (license, customers, orders, rating, experience)
     * @return array|null
     */
    public function getBadgeByType(string $type): ?array {
        $badges = $this->getBadges();
        
        foreach ($badges as $badge) {
            if ($badge['type'] === $type) {
                return $badge;
            }
        }
        
        return null;
    }
    
    /**
     * Get pharmacist count (if pharmacists table exists)
     * 
     * @return int
     */
    public function getPharmacistCount(): int {
        try {
            $sql = "SELECT COUNT(*) FROM pharmacists WHERE is_active = 1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}
