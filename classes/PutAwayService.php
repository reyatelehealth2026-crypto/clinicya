<?php
/**
 * PutAwayService - จัดการ Put Away และแนะนำตำแหน่งจัดเก็บ
 * 
 * Handles location suggestions, ABC analysis, zone restrictions,
 * and product/batch assignments to warehouse locations.
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.4, 6.5, 7.1
 */

class PutAwayService {
    private $db;
    private $lineAccountId;
    private $locationService;
    private $batchService;
    
    // ABC Classification thresholds (percentile-based)
    const ABC_A_THRESHOLD = 80; // Top 20% of products (80th percentile)
    const ABC_B_THRESHOLD = 50; // Next 30% (50th-80th percentile)
    // C class: Bottom 50%
    
    // Zone type mappings
    const ZONE_TYPE_CONTROLLED = 'controlled';
    const ZONE_TYPE_COLD_STORAGE = 'cold_storage';
    const ZONE_TYPE_GENERAL = 'general';
    const ZONE_TYPE_HAZARDOUS = 'hazardous';
    
    // Drug categories that require controlled zone
    const CONTROLLED_DRUG_CATEGORIES = ['controlled', 'narcotic', 'psychotropic'];
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId Line account ID for multi-tenant support
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
        $this->locationService = new LocationService($db, $this->lineAccountId);
        $this->batchService = new BatchService($db, $this->lineAccountId);
    }
    
    // =========================================
    // Location Suggestion Methods (Requirements 4.1, 4.2, 4.4)
    // =========================================
    
    /**
     * Suggest optimal location for a product
     * 
     * @param int $productId Product ID
     * @return array Suggested locations with reasons
     */
    public function suggestLocation(int $productId): array {
        // Get product details
        $product = $this->getProduct($productId);
        if (!$product) {
            return [
                'success' => false,
                'error' => 'ไม่พบสินค้า',
                'suggestions' => []
            ];
        }
        
        // Determine required zone type
        $requiredZoneType = $this->getRequiredZoneType($product);
        
        // Get ABC class for ergonomic level preference
        $abcClass = $product['movement_class'] ?? 'C';
        $preferredErgonomicLevel = $this->getPreferredErgonomicLevel($abcClass);
        
        // Build filters for location search
        $filters = [
            'zone_type' => $requiredZoneType,
            'is_active' => 1
        ];
        
        // Get available locations
        $locations = $this->locationService->getLocations($filters);
        
        // Fallback: if no locations found with required zone type, try all active locations
        if (empty($locations)) {
            $fallbackFilters = [
                'is_active' => 1
            ];
            $locations = $this->locationService->getLocations($fallbackFilters);
        }
        
        if (empty($locations)) {
            return [
                'success' => false,
                'error' => 'ไม่พบตำแหน่งจัดเก็บ กรุณาสร้างตำแหน่งใหม่ก่อน (ไปที่ tab ตำแหน่งจัดเก็บ)',
                'suggestions' => [],
                'product' => $product,
                'required_zone_type' => $requiredZoneType
            ];
        }
        
        // Score and sort locations
        $scoredLocations = $this->scoreLocations($locations, $product, $preferredErgonomicLevel);
        
        // Sort by score descending
        usort($scoredLocations, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Return top suggestions
        $suggestions = array_slice($scoredLocations, 0, 5);
        
        return [
            'success' => true,
            'suggestions' => $suggestions,
            'product' => $product,
            'abc_class' => $abcClass,
            'required_zone_type' => $requiredZoneType,
            'preferred_ergonomic_level' => $preferredErgonomicLevel
        ];
    }

    
    /**
     * Suggest optimal location for a batch
     * 
     * @param int $batchId Batch ID
     * @return array Suggested locations with reasons
     */
    public function suggestLocationForBatch(int $batchId): array {
        // Get batch details
        $batch = $this->batchService->getBatch($batchId);
        if (!$batch) {
            return [
                'success' => false,
                'error' => 'Batch not found',
                'suggestions' => []
            ];
        }
        
        // Get product suggestions
        $result = $this->suggestLocation($batch['product_id']);
        
        // Add batch-specific info
        $result['batch'] = $batch;
        
        // If batch has expiry, prioritize locations near picking area for FEFO
        if (!empty($batch['expiry_date']) && $batch['is_near_expiry']) {
            $result['priority_note'] = 'Near-expiry batch - consider placing in easily accessible location for quick picking';
        }
        
        return $result;
    }
    
    /**
     * Score locations based on product requirements
     * 
     * @param array $locations Available locations
     * @param array $product Product data
     * @param string $preferredErgonomicLevel Preferred ergonomic level
     * @return array Locations with scores and reasons
     */
    private function scoreLocations(array $locations, array $product, string $preferredErgonomicLevel): array {
        $scoredLocations = [];
        
        foreach ($locations as $location) {
            $score = 0;
            $reasons = [];
            
            // Score based on ergonomic level match (Requirements 2.2, 4.4)
            if ($location['ergonomic_level'] === $preferredErgonomicLevel) {
                $score += 30;
                $reasons[] = "Optimal ergonomic level ({$preferredErgonomicLevel})";
            } elseif ($preferredErgonomicLevel === 'golden' && $location['ergonomic_level'] !== 'golden') {
                $score -= 10;
                $reasons[] = "Not in Golden Zone";
            }
            
            // Score based on available capacity
            $available = $location['capacity'] - $location['current_qty'];
            $utilizationPercent = ($location['current_qty'] / max(1, $location['capacity'])) * 100;
            
            if ($utilizationPercent < 50) {
                $score += 20;
                $reasons[] = "Good capacity available ({$available} units)";
            } elseif ($utilizationPercent < 80) {
                $score += 10;
                $reasons[] = "Moderate capacity available";
            } else {
                $score += 5;
                $reasons[] = "Limited capacity";
            }
            
            // Score based on proximity to similar products (grouping)
            if ($this->hasNearbyProducts($location, $product['id'])) {
                $score += 15;
                $reasons[] = "Near similar products";
            }
            
            // Prefer empty bins
            if ($location['current_qty'] == 0) {
                $score += 10;
                $reasons[] = "Empty bin";
            }
            
            $scoredLocations[] = array_merge($location, [
                'score' => $score,
                'reasons' => $reasons,
                'available_capacity' => $available
            ]);
        }
        
        return $scoredLocations;
    }
    
    /**
     * Check if location has nearby products of same category
     * 
     * @param array $location Location data
     * @param int $productId Product ID
     * @return bool True if similar products nearby
     */
    private function hasNearbyProducts(array $location, int $productId): bool {
        // Check if same zone has products from same category
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM inventory_batches ib
            JOIN warehouse_locations wl ON ib.location_id = wl.id
            JOIN business_items bi ON ib.product_id = bi.id
            JOIN business_items bi2 ON bi2.id = ?
            WHERE wl.zone = ? 
              AND wl.line_account_id = ?
              AND bi.category_id = bi2.category_id
              AND ib.status = 'active'
        ");
        $stmt->execute([$productId, $location['zone'], $this->lineAccountId]);
        
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Get preferred ergonomic level based on ABC class
     * 
     * @param string $abcClass ABC classification (A, B, C)
     * @return string Preferred ergonomic level
     */
    private function getPreferredErgonomicLevel(string $abcClass): string {
        switch (strtoupper($abcClass)) {
            case 'A':
                return 'golden'; // Fast-moving: eye-to-waist level
            case 'B':
                return 'golden'; // Medium: still prefer golden
            case 'C':
            default:
                return 'upper'; // Slow-moving: upper/lower shelves
        }
    }

    
    // =========================================
    // ABC Analysis Methods (Requirements 2.1, 2.2, 2.3, 2.4)
    // =========================================
    
    /**
     * Run ABC Analysis on all products
     * Classifies products based on sales velocity
     * 
     * @param int $daysBack Number of days to analyze (default 90)
     * @return array Analysis results
     */
    public function runABCAnalysis(int $daysBack = 90): array {
        $startDate = date('Y-m-d', strtotime("-{$daysBack} days"));
        
        // Get sales velocity for all products
        $stmt = $this->db->prepare("
            SELECT 
                bi.id as product_id,
                bi.name,
                bi.sku,
                COALESCE(SUM(oi.quantity), 0) as total_sold,
                COUNT(DISTINCT oi.order_id) as order_count
            FROM business_items bi
            LEFT JOIN order_items oi ON bi.id = oi.product_id
            LEFT JOIN orders o ON oi.order_id = o.id AND o.created_at >= ?
            WHERE bi.line_account_id = ? AND bi.is_active = 1
            GROUP BY bi.id, bi.name, bi.sku
            ORDER BY total_sold DESC
        ");
        $stmt->execute([$startDate, $this->lineAccountId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return [
                'success' => true,
                'message' => 'No products found for analysis',
                'updated' => 0,
                'summary' => ['A' => 0, 'B' => 0, 'C' => 0]
            ];
        }
        
        // Calculate percentiles
        $totalProducts = count($products);
        $aThresholdIndex = (int)ceil($totalProducts * (1 - self::ABC_A_THRESHOLD / 100));
        $bThresholdIndex = (int)ceil($totalProducts * (1 - self::ABC_B_THRESHOLD / 100));
        
        $updated = 0;
        $summary = ['A' => 0, 'B' => 0, 'C' => 0];
        $results = [];
        
        foreach ($products as $index => $product) {
            // Determine ABC class based on position
            if ($index < $aThresholdIndex) {
                $class = 'A';
            } elseif ($index < $bThresholdIndex) {
                $class = 'B';
            } else {
                $class = 'C';
            }
            
            // Update product
            if ($this->updateProductABCClass($product['product_id'], $class)) {
                $updated++;
            }
            
            $summary[$class]++;
            $results[] = [
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'total_sold' => (int)$product['total_sold'],
                'order_count' => (int)$product['order_count'],
                'class' => $class
            ];
        }
        
        return [
            'success' => true,
            'message' => "ABC Analysis completed for {$totalProducts} products",
            'updated' => $updated,
            'summary' => $summary,
            'analysis_period_days' => $daysBack,
            'results' => $results
        ];
    }
    
    /**
     * Get ABC class for a specific product
     * 
     * @param int $productId Product ID
     * @return string ABC class (A, B, or C)
     */
    public function getProductABCClass(int $productId): string {
        $stmt = $this->db->prepare("
            SELECT movement_class FROM business_items 
            WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$productId, $this->lineAccountId]);
        $result = $stmt->fetchColumn();
        
        return $result ?: 'C';
    }
    
    /**
     * Update ABC class for a product
     * 
     * @param int $productId Product ID
     * @param string $class ABC class (A, B, or C)
     * @return bool True on success
     */
    public function updateProductABCClass(int $productId, string $class): bool {
        $class = strtoupper($class);
        if (!in_array($class, ['A', 'B', 'C'])) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            UPDATE business_items 
            SET movement_class = ?
            WHERE id = ? AND line_account_id = ?
        ");
        
        return $stmt->execute([$class, $productId, $this->lineAccountId]);
    }

    
    // =========================================
    // Zone Restriction Methods (Requirements 3.3, 3.4, 7.1)
    // =========================================
    
    /**
     * Validate if a product can be assigned to a location
     * Checks zone type restrictions for controlled and cold-chain products
     * 
     * @param int $productId Product ID
     * @param int $locationId Location ID
     * @return array Validation result with success flag and message
     */
    public function validateZoneForProduct(int $productId, int $locationId): array {
        // Get product details
        $product = $this->getProduct($productId);
        if (!$product) {
            return [
                'valid' => false,
                'error' => 'Product not found',
                'code' => 'PRODUCT_NOT_FOUND'
            ];
        }
        
        // Get location details
        $location = $this->locationService->getLocation($locationId);
        if (!$location) {
            return [
                'valid' => false,
                'error' => 'Location not found',
                'code' => 'LOCATION_NOT_FOUND'
            ];
        }
        
        // Check if location is active
        if (!$location['is_active']) {
            return [
                'valid' => false,
                'error' => 'Location is not active',
                'code' => 'LOCATION_INACTIVE'
            ];
        }
        
        // Get required zone type for product
        $requiredZoneType = $this->getRequiredZoneType($product);
        $locationZoneType = $location['zone_type'];
        
        // Validate controlled drugs (Requirements 3.3, 7.1)
        if ($this->isControlledDrug($product)) {
            if ($locationZoneType !== self::ZONE_TYPE_CONTROLLED) {
                return [
                    'valid' => false,
                    'error' => 'Controlled drugs must be stored in controlled zone (RX)',
                    'code' => 'CONTROLLED_ZONE_REQUIRED',
                    'required_zone_type' => self::ZONE_TYPE_CONTROLLED,
                    'location_zone_type' => $locationZoneType
                ];
            }
        }
        
        // Validate cold-chain products (Requirement 3.4)
        if ($this->isColdChainProduct($product)) {
            if ($locationZoneType !== self::ZONE_TYPE_COLD_STORAGE) {
                return [
                    'valid' => false,
                    'error' => 'Cold-chain products must be stored in cold storage zone',
                    'code' => 'COLD_STORAGE_REQUIRED',
                    'required_zone_type' => self::ZONE_TYPE_COLD_STORAGE,
                    'location_zone_type' => $locationZoneType
                ];
            }
        }
        
        // Validate hazardous products
        if ($this->isHazardousProduct($product)) {
            if ($locationZoneType !== self::ZONE_TYPE_HAZARDOUS) {
                return [
                    'valid' => false,
                    'error' => 'Hazardous products must be stored in hazardous zone',
                    'code' => 'HAZARDOUS_ZONE_REQUIRED',
                    'required_zone_type' => self::ZONE_TYPE_HAZARDOUS,
                    'location_zone_type' => $locationZoneType
                ];
            }
        }
        
        return [
            'valid' => true,
            'message' => 'Location is valid for this product',
            'product_zone_type' => $requiredZoneType,
            'location_zone_type' => $locationZoneType
        ];
    }
    
    /**
     * Check if product is a controlled drug
     * 
     * @param array $product Product data
     * @return bool True if controlled drug
     */
    private function isControlledDrug(array $product): bool {
        // Check drug_category field
        $drugCategory = strtolower($product['drug_category'] ?? '');
        if (in_array($drugCategory, self::CONTROLLED_DRUG_CATEGORIES)) {
            return true;
        }
        
        // Check storage_zone_type field
        if (($product['storage_zone_type'] ?? '') === self::ZONE_TYPE_CONTROLLED) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if product requires cold storage
     * 
     * @param array $product Product data
     * @return bool True if cold-chain product
     */
    private function isColdChainProduct(array $product): bool {
        // Check storage_zone_type field
        if (($product['storage_zone_type'] ?? '') === self::ZONE_TYPE_COLD_STORAGE) {
            return true;
        }
        
        // Check storage_condition field for cold-related keywords
        $storageCondition = strtolower($product['storage_condition'] ?? '');
        $coldKeywords = ['cold', 'refrigerat', 'freeze', '2-8', '2°c', '8°c', 'เย็น', 'แช่เย็น', 'ตู้เย็น'];
        
        foreach ($coldKeywords as $keyword) {
            if (strpos($storageCondition, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if product is hazardous
     * 
     * @param array $product Product data
     * @return bool True if hazardous product
     */
    private function isHazardousProduct(array $product): bool {
        return ($product['storage_zone_type'] ?? '') === self::ZONE_TYPE_HAZARDOUS;
    }
    
    /**
     * Get required zone type for a product
     * 
     * @param array $product Product data
     * @return string Required zone type
     */
    private function getRequiredZoneType(array $product): string {
        // Priority: controlled > cold_storage > hazardous > general
        if ($this->isControlledDrug($product)) {
            return self::ZONE_TYPE_CONTROLLED;
        }
        
        if ($this->isColdChainProduct($product)) {
            return self::ZONE_TYPE_COLD_STORAGE;
        }
        
        if ($this->isHazardousProduct($product)) {
            return self::ZONE_TYPE_HAZARDOUS;
        }
        
        // Use product's storage_zone_type if set, otherwise general
        return $product['storage_zone_type'] ?? self::ZONE_TYPE_GENERAL;
    }

    
    // =========================================
    // Assignment Methods (Requirements 3.1, 3.2, 6.5)
    // =========================================
    
    /**
     * Assign a product to a location
     * 
     * @param int $productId Product ID
     * @param int $locationId Location ID
     * @param int $quantity Quantity to assign (default 1)
     * @param int|null $staffId Staff ID performing the action
     * @return array Result with success flag
     */
    public function assignProductToLocation(int $productId, int $locationId, int $quantity = 1, ?int $staffId = null): array {
        // Validate zone restrictions
        $validation = $this->validateZoneForProduct($productId, $locationId);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'],
                'code' => $validation['code'] ?? 'VALIDATION_FAILED'
            ];
        }
        
        // Check location capacity (Requirement 3.1)
        $location = $this->locationService->getLocation($locationId);
        $available = $location['capacity'] - $location['current_qty'];
        
        if ($quantity > $available) {
            return [
                'success' => false,
                'error' => "Location capacity exceeded. Available: {$available}, Requested: {$quantity}",
                'code' => 'CAPACITY_EXCEEDED',
                'available_capacity' => $available
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Update location quantity
            $this->locationService->updateLocationQuantity($locationId, $quantity);
            
            // Update product's default location (Requirement 3.2)
            $stmt = $this->db->prepare("
                UPDATE business_items 
                SET default_location_id = ?
                WHERE id = ? AND line_account_id = ?
            ");
            $stmt->execute([$locationId, $productId, $this->lineAccountId]);
            
            // Log movement (Requirement 6.5)
            $this->logMovement([
                'product_id' => $productId,
                'to_location_id' => $locationId,
                'quantity' => $quantity,
                'movement_type' => 'put_away',
                'staff_id' => $staffId
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Product assigned to location successfully',
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $quantity
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'Failed to assign product: ' . $e->getMessage(),
                'code' => 'ASSIGNMENT_FAILED'
            ];
        }
    }
    
    /**
     * Assign a batch to a location
     * 
     * @param int $batchId Batch ID
     * @param int $locationId Location ID
     * @param int|null $staffId Staff ID performing the action
     * @return array Result with success flag
     */
    public function assignBatchToLocation(int $batchId, int $locationId, ?int $staffId = null): array {
        // Get batch details
        $batch = $this->batchService->getBatch($batchId);
        if (!$batch) {
            return [
                'success' => false,
                'error' => 'Batch not found',
                'code' => 'BATCH_NOT_FOUND'
            ];
        }
        
        // Validate zone restrictions
        $validation = $this->validateZoneForProduct($batch['product_id'], $locationId);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'],
                'code' => $validation['code'] ?? 'VALIDATION_FAILED'
            ];
        }
        
        // Check location capacity
        $location = $this->locationService->getLocation($locationId);
        $available = $location['capacity'] - $location['current_qty'];
        $quantity = $batch['quantity_available'];
        
        if ($quantity > $available) {
            return [
                'success' => false,
                'error' => "Location capacity exceeded. Available: {$available}, Batch quantity: {$quantity}",
                'code' => 'CAPACITY_EXCEEDED',
                'available_capacity' => $available
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Get old location for movement logging
            $oldLocationId = $batch['location_id'];
            
            // Update batch location
            $this->batchService->updateBatch($batchId, ['location_id' => $locationId]);
            
            // Update location quantities
            if ($oldLocationId) {
                $this->locationService->updateLocationQuantity($oldLocationId, -$quantity);
            }
            $this->locationService->updateLocationQuantity($locationId, $quantity);
            
            // Log movement
            $this->logMovement([
                'product_id' => $batch['product_id'],
                'batch_id' => $batchId,
                'from_location_id' => $oldLocationId,
                'to_location_id' => $locationId,
                'quantity' => $quantity,
                'movement_type' => $oldLocationId ? 'transfer' : 'put_away',
                'staff_id' => $staffId
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Batch assigned to location successfully',
                'batch_id' => $batchId,
                'location_id' => $locationId,
                'quantity' => $quantity
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'Failed to assign batch: ' . $e->getMessage(),
                'code' => 'ASSIGNMENT_FAILED'
            ];
        }
    }
    
    /**
     * Move a product from one location to another
     * 
     * @param int $productId Product ID
     * @param int $fromLocationId Source location ID
     * @param int $toLocationId Destination location ID
     * @param int $quantity Quantity to move
     * @param int|null $staffId Staff ID performing the action
     * @return array Result with success flag
     */
    public function moveProduct(int $productId, int $fromLocationId, int $toLocationId, int $quantity = 1, ?int $staffId = null): array {
        // Validate destination zone restrictions
        $validation = $this->validateZoneForProduct($productId, $toLocationId);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'],
                'code' => $validation['code'] ?? 'VALIDATION_FAILED'
            ];
        }
        
        // Check source location has enough quantity
        $fromLocation = $this->locationService->getLocation($fromLocationId);
        if (!$fromLocation) {
            return [
                'success' => false,
                'error' => 'Source location not found',
                'code' => 'SOURCE_NOT_FOUND'
            ];
        }
        
        if ($fromLocation['current_qty'] < $quantity) {
            return [
                'success' => false,
                'error' => "Insufficient quantity at source. Available: {$fromLocation['current_qty']}",
                'code' => 'INSUFFICIENT_QUANTITY'
            ];
        }
        
        // Check destination capacity
        $toLocation = $this->locationService->getLocation($toLocationId);
        if (!$toLocation) {
            return [
                'success' => false,
                'error' => 'Destination location not found',
                'code' => 'DESTINATION_NOT_FOUND'
            ];
        }
        
        $available = $toLocation['capacity'] - $toLocation['current_qty'];
        if ($quantity > $available) {
            return [
                'success' => false,
                'error' => "Destination capacity exceeded. Available: {$available}",
                'code' => 'CAPACITY_EXCEEDED',
                'available_capacity' => $available
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Update source location
            $this->locationService->updateLocationQuantity($fromLocationId, -$quantity);
            
            // Update destination location
            $this->locationService->updateLocationQuantity($toLocationId, $quantity);
            
            // Log movement
            $this->logMovement([
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
                'movement_type' => 'transfer',
                'staff_id' => $staffId
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Product moved successfully',
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'Failed to move product: ' . $e->getMessage(),
                'code' => 'MOVE_FAILED'
            ];
        }
    }

    
    // =========================================
    // Helper Methods
    // =========================================
    
    /**
     * Get product details
     * 
     * @param int $productId Product ID
     * @return array|null Product data or null if not found
     */
    private function getProduct(int $productId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, name, sku, category_id, drug_category, 
                   storage_condition, storage_zone_type, movement_class,
                   default_location_id, requires_batch_tracking, requires_expiry_tracking
            FROM business_items 
            WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$productId, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Log a movement to location_movements table
     * 
     * @param array $data Movement data
     * @return int Created movement ID
     */
    private function logMovement(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO location_movements 
            (line_account_id, product_id, batch_id, from_location_id, to_location_id, 
             quantity, movement_type, reference_type, reference_id, staff_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $data['product_id'],
            $data['batch_id'] ?? null,
            $data['from_location_id'] ?? null,
            $data['to_location_id'] ?? null,
            $data['quantity'],
            $data['movement_type'],
            $data['reference_type'] ?? null,
            $data['reference_id'] ?? null,
            $data['staff_id'] ?? null,
            $data['notes'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get movement history for a product
     * 
     * @param int $productId Product ID
     * @param int $limit Maximum records to return
     * @return array Movement history
     */
    public function getMovementHistory(int $productId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT lm.*, 
                   wl_from.location_code as from_location_code,
                   wl_to.location_code as to_location_code,
                   ib.batch_number
            FROM location_movements lm
            LEFT JOIN warehouse_locations wl_from ON lm.from_location_id = wl_from.id
            LEFT JOIN warehouse_locations wl_to ON lm.to_location_id = wl_to.id
            LEFT JOIN inventory_batches ib ON lm.batch_id = ib.id
            WHERE lm.product_id = ? AND lm.line_account_id = ?
            ORDER BY lm.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$productId, $this->lineAccountId, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get movement history for a batch
     * 
     * @param int $batchId Batch ID
     * @return array Movement history
     */
    public function getBatchMovementHistory(int $batchId): array {
        $stmt = $this->db->prepare("
            SELECT lm.*, 
                   wl_from.location_code as from_location_code,
                   wl_to.location_code as to_location_code
            FROM location_movements lm
            LEFT JOIN warehouse_locations wl_from ON lm.from_location_id = wl_from.id
            LEFT JOIN warehouse_locations wl_to ON lm.to_location_id = wl_to.id
            WHERE lm.batch_id = ? AND lm.line_account_id = ?
            ORDER BY lm.created_at DESC
        ");
        $stmt->execute([$batchId, $this->lineAccountId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get products by ABC class
     * 
     * @param string $class ABC class (A, B, or C)
     * @param int $limit Maximum records to return
     * @return array Products
     */
    public function getProductsByABCClass(string $class, int $limit = 100): array {
        $class = strtoupper($class);
        if (!in_array($class, ['A', 'B', 'C'])) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT id, name, sku, movement_class, storage_zone_type, default_location_id
            FROM business_items 
            WHERE movement_class = ? AND line_account_id = ? AND is_active = 1
            ORDER BY name
            LIMIT ?
        ");
        $stmt->execute([$class, $this->lineAccountId, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get ABC analysis summary
     * 
     * @return array Summary statistics
     */
    public function getABCAnalysisSummary(): array {
        $stmt = $this->db->prepare("
            SELECT 
                movement_class,
                COUNT(*) as product_count
            FROM business_items 
            WHERE line_account_id = ? AND is_active = 1
            GROUP BY movement_class
        ");
        $stmt->execute([$this->lineAccountId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $summary = ['A' => 0, 'B' => 0, 'C' => 0, 'total' => 0];
        foreach ($results as $row) {
            $class = $row['movement_class'] ?? 'C';
            $summary[$class] = (int)$row['product_count'];
            $summary['total'] += (int)$row['product_count'];
        }
        
        return $summary;
    }
}
