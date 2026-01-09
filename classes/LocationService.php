<?php
/**
 * LocationService - จัดการ Warehouse Locations
 * 
 * Handles CRUD operations for warehouse storage locations
 * with Zone-Shelf-Bin hierarchy support.
 */

class LocationService {
    private $db;
    private $lineAccountId;
    private $validZoneTypes = null;
    
    // Default zone types (fallback if DB table doesn't exist)
    const DEFAULT_ZONE_TYPES = ['general', 'cold_storage', 'frozen', 'controlled', 'hazardous'];
    
    // Valid ergonomic levels
    const ERGONOMIC_LEVELS = ['golden', 'upper', 'lower'];
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId Line account ID for multi-tenant support
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    /**
     * Get valid zone types from database or use defaults
     * 
     * @return array List of valid zone type codes
     */
    private function getValidZoneTypes(): array {
        if ($this->validZoneTypes !== null) {
            return $this->validZoneTypes;
        }
        
        try {
            $stmt = $this->db->prepare("SELECT code FROM zone_types WHERE is_active = 1");
            $stmt->execute();
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($types)) {
                $this->validZoneTypes = $types;
                return $this->validZoneTypes;
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
        
        $this->validZoneTypes = self::DEFAULT_ZONE_TYPES;
        return $this->validZoneTypes;
    }
    
    /**
     * Validate location code format (Zone-Shelf-Bin)
     * Format: A1-03-02 (Zone letter + number, dash, 2-digit shelf, dash, 2-digit bin)
     * 
     * @param string $code Location code to validate
     * @return bool True if valid format
     */
    public function validateLocationCode(string $code): bool {
        // Pattern: Zone (letter + optional number), Shelf (1-2 digits), Bin (1-2 digits)
        // Examples: A1-03-02, B-01-05, RX-02-01, COLD-01-03
        $pattern = '/^[A-Z]+[0-9]*-[0-9]{1,2}-[0-9]{1,2}$/';
        return (bool)preg_match($pattern, strtoupper($code));
    }
    
    /**
     * Parse location code into components
     * 
     * @param string $code Location code
     * @return array|null Array with zone, shelf, bin or null if invalid
     */
    public function parseLocationCode(string $code): ?array {
        if (!$this->validateLocationCode($code)) {
            return null;
        }
        
        $parts = explode('-', strtoupper($code));
        if (count($parts) !== 3) {
            return null;
        }
        
        return [
            'zone' => $parts[0],
            'shelf' => (int)$parts[1],
            'bin' => (int)$parts[2]
        ];
    }
    
    /**
     * Generate location code from components
     * 
     * @param string $zone Zone identifier
     * @param int $shelf Shelf number
     * @param int $bin Bin number
     * @return string Formatted location code
     */
    public function generateLocationCode(string $zone, int $shelf, int $bin): string {
        return sprintf('%s-%02d-%02d', strtoupper($zone), $shelf, $bin);
    }

    
    /**
     * Create a new warehouse location
     * 
     * @param array $data Location data
     * @return int Created location ID
     * @throws Exception If validation fails or duplicate exists
     */
    public function createLocation(array $data): int {
        // Validate required fields
        if (empty($data['zone']) || !isset($data['shelf']) || !isset($data['bin'])) {
            throw new Exception('Zone, shelf, and bin are required', 400);
        }
        
        // Generate location code if not provided
        $locationCode = $data['location_code'] ?? $this->generateLocationCode(
            $data['zone'],
            (int)$data['shelf'],
            (int)$data['bin']
        );
        
        // Validate location code format
        if (!$this->validateLocationCode($locationCode)) {
            throw new Exception('Invalid location code format. Expected: Zone-Shelf-Bin (e.g., A1-03-02)', 400);
        }
        
        // Validate zone_type if provided - use dynamic list from DB
        $zoneType = $data['zone_type'] ?? 'general';
        $validZoneTypes = $this->getValidZoneTypes();
        if (!in_array($zoneType, $validZoneTypes)) {
            throw new Exception('Invalid zone type. Allowed: ' . implode(', ', $validZoneTypes), 400);
        }
        
        // Validate ergonomic_level if provided
        $ergonomicLevel = $data['ergonomic_level'] ?? 'golden';
        if (!in_array($ergonomicLevel, self::ERGONOMIC_LEVELS)) {
            throw new Exception('Invalid ergonomic level. Allowed: ' . implode(', ', self::ERGONOMIC_LEVELS), 400);
        }
        
        // Check for duplicate location code
        if ($this->getLocationByCode($locationCode)) {
            throw new Exception('Location code already exists: ' . $locationCode, 409);
        }
        
        // Parse location code to ensure zone/shelf/bin match
        $parsed = $this->parseLocationCode($locationCode);
        
        $stmt = $this->db->prepare("
            INSERT INTO warehouse_locations 
            (line_account_id, location_code, zone, shelf, bin, zone_type, ergonomic_level, capacity, description, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            strtoupper($locationCode),
            $parsed['zone'],
            $parsed['shelf'],
            $parsed['bin'],
            $zoneType,
            $ergonomicLevel,
            $data['capacity'] ?? 100,
            $data['description'] ?? null,
            $data['is_active'] ?? 1
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update an existing location
     * 
     * @param int $id Location ID
     * @param array $data Updated data
     * @return bool True on success
     * @throws Exception If location not found or validation fails
     */
    public function updateLocation(int $id, array $data): bool {
        // Check if location exists
        $existing = $this->getLocation($id);
        if (!$existing) {
            throw new Exception('Location not found', 404);
        }
        
        $updates = [];
        $params = [];
        
        // Handle location code update
        if (isset($data['location_code']) || isset($data['zone']) || isset($data['shelf']) || isset($data['bin'])) {
            $zone = $data['zone'] ?? $existing['zone'];
            $shelf = $data['shelf'] ?? $existing['shelf'];
            $bin = $data['bin'] ?? $existing['bin'];
            $locationCode = $data['location_code'] ?? $this->generateLocationCode($zone, (int)$shelf, (int)$bin);
            
            if (!$this->validateLocationCode($locationCode)) {
                throw new Exception('Invalid location code format', 400);
            }
            
            // Check for duplicate if code changed
            if (strtoupper($locationCode) !== strtoupper($existing['location_code'])) {
                $existingCode = $this->getLocationByCode($locationCode);
                if ($existingCode && $existingCode['id'] !== $id) {
                    throw new Exception('Location code already exists: ' . $locationCode, 409);
                }
            }
            
            $parsed = $this->parseLocationCode($locationCode);
            $updates[] = 'location_code = ?';
            $params[] = strtoupper($locationCode);
            $updates[] = 'zone = ?';
            $params[] = $parsed['zone'];
            $updates[] = 'shelf = ?';
            $params[] = $parsed['shelf'];
            $updates[] = 'bin = ?';
            $params[] = $parsed['bin'];
        }
        
        // Handle zone_type update
        if (isset($data['zone_type'])) {
            $validZoneTypes = $this->getValidZoneTypes();
            if (!in_array($data['zone_type'], $validZoneTypes)) {
                throw new Exception('Invalid zone type', 400);
            }
            $updates[] = 'zone_type = ?';
            $params[] = $data['zone_type'];
        }
        
        // Handle ergonomic_level update
        if (isset($data['ergonomic_level'])) {
            if (!in_array($data['ergonomic_level'], self::ERGONOMIC_LEVELS)) {
                throw new Exception('Invalid ergonomic level', 400);
            }
            $updates[] = 'ergonomic_level = ?';
            $params[] = $data['ergonomic_level'];
        }
        
        // Handle other fields
        if (isset($data['capacity'])) {
            $updates[] = 'capacity = ?';
            $params[] = (int)$data['capacity'];
        }
        
        if (isset($data['current_qty'])) {
            $updates[] = 'current_qty = ?';
            $params[] = (int)$data['current_qty'];
        }
        
        if (array_key_exists('description', $data)) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
        }
        
        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }
        
        if (empty($updates)) {
            return true; // Nothing to update
        }
        
        $params[] = $id;
        $params[] = $this->lineAccountId;
        
        $sql = "UPDATE warehouse_locations SET " . implode(', ', $updates) . 
               " WHERE id = ? AND line_account_id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    
    /**
     * Delete a location (soft delete by setting is_active = 0)
     * 
     * @param int $id Location ID
     * @return bool True on success
     * @throws Exception If location not found or has inventory
     */
    public function deleteLocation(int $id): bool {
        // Check if location exists
        $location = $this->getLocation($id);
        if (!$location) {
            throw new Exception('Location not found', 404);
        }
        
        // Check if location has inventory
        if ($location['current_qty'] > 0) {
            throw new Exception('Cannot delete location with inventory. Current quantity: ' . $location['current_qty'], 400);
        }
        
        // Check if any batches are assigned to this location (if table exists)
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM inventory_batches 
                WHERE location_id = ? AND status = 'active'
            ");
            $stmt->execute([$id]);
            $batchCount = (int)$stmt->fetchColumn();
            
            if ($batchCount > 0) {
                throw new Exception('Cannot delete location with active batches assigned', 400);
            }
        } catch (PDOException $e) {
            // Table might not exist yet, skip batch check
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                throw $e;
            }
        }
        
        // Soft delete
        $stmt = $this->db->prepare("
            UPDATE warehouse_locations 
            SET is_active = 0 
            WHERE id = ? AND line_account_id = ?
        ");
        
        return $stmt->execute([$id, $this->lineAccountId]);
    }
    
    /**
     * Get a location by ID
     * 
     * @param int $id Location ID
     * @return array|null Location data or null if not found
     */
    public function getLocation(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM warehouse_locations 
            WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$id, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Get a location by code
     * 
     * @param string $code Location code
     * @return array|null Location data or null if not found
     */
    public function getLocationByCode(string $code): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM warehouse_locations 
            WHERE location_code = ? AND line_account_id = ?
        ");
        $stmt->execute([strtoupper($code), $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }
    
    /**
     * Get all locations with optional filters
     * 
     * @param array $filters Optional filters (zone, zone_type, is_active, etc.)
     * @return array List of locations
     */
    public function getLocations(array $filters = []): array {
        $sql = "SELECT * FROM warehouse_locations WHERE line_account_id = ?";
        $params = [$this->lineAccountId];
        
        if (isset($filters['zone'])) {
            $sql .= " AND zone = ?";
            $params[] = strtoupper($filters['zone']);
        }
        if (isset($filters['zone_type'])) {
            $sql .= " AND zone_type = ?";
            $params[] = $filters['zone_type'];
        }
        
        if (isset($filters['ergonomic_level'])) {
            $sql .= " AND ergonomic_level = ?";
            $params[] = $filters['ergonomic_level'];
        }
        
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = (int)$filters['is_active'];
        } else {
            // Default to active only
            $sql .= " AND is_active = 1";
        }
        
        if (isset($filters['has_capacity']) && $filters['has_capacity']) {
            $sql .= " AND current_qty < capacity";
        }
        
        $sql .= " ORDER BY zone, shelf, bin";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all active locations (without line_account_id filter)
     * Used as fallback when no locations found for specific account
     * 
     * @return array List of locations
     */
    public function getAllActiveLocations(): array {
        $stmt = $this->db->prepare("
            SELECT * FROM warehouse_locations 
            WHERE is_active = 1
            ORDER BY zone, shelf, bin
            LIMIT 100
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all unique zones
     * 
     * @return array List of zones with counts
     */
    public function getZones(): array {
        $stmt = $this->db->prepare("
            SELECT zone, zone_type, 
                   COUNT(*) as location_count,
                   SUM(capacity) as total_capacity,
                   SUM(current_qty) as total_qty
            FROM warehouse_locations 
            WHERE line_account_id = ? AND is_active = 1
            GROUP BY zone, zone_type
            ORDER BY zone
        ");
        $stmt->execute([$this->lineAccountId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get shelves in a specific zone
     * 
     * @param string $zone Zone identifier
     * @return array List of shelves with counts
     */
    public function getShelvesInZone(string $zone): array {
        $stmt = $this->db->prepare("
            SELECT shelf, 
                   COUNT(*) as bin_count,
                   SUM(capacity) as total_capacity,
                   SUM(current_qty) as total_qty
            FROM warehouse_locations 
            WHERE line_account_id = ? AND zone = ? AND is_active = 1
            GROUP BY shelf
            ORDER BY shelf
        ");
        $stmt->execute([$this->lineAccountId, strtoupper($zone)]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get bins in a specific shelf
     * 
     * @param string $zone Zone identifier
     * @param int $shelf Shelf number
     * @return array List of bins
     */
    public function getBinsInShelf(string $zone, int $shelf): array {
        $stmt = $this->db->prepare("
            SELECT * FROM warehouse_locations 
            WHERE line_account_id = ? AND zone = ? AND shelf = ? AND is_active = 1
            ORDER BY bin
        ");
        $stmt->execute([$this->lineAccountId, strtoupper($zone), $shelf]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    
    /**
     * Check if a location is available (has capacity)
     * 
     * @param int $locationId Location ID
     * @return bool True if location has available capacity
     */
    public function isLocationAvailable(int $locationId): bool {
        $location = $this->getLocation($locationId);
        if (!$location || !$location['is_active']) {
            return false;
        }
        
        return $location['current_qty'] < $location['capacity'];
    }
    
    /**
     * Get location capacity information
     * 
     * @param int $locationId Location ID
     * @return array Capacity info (capacity, current_qty, available, utilization_percent)
     */
    public function getLocationCapacity(int $locationId): array {
        $location = $this->getLocation($locationId);
        if (!$location) {
            return [
                'capacity' => 0,
                'current_qty' => 0,
                'available' => 0,
                'utilization_percent' => 0
            ];
        }
        
        $available = max(0, $location['capacity'] - $location['current_qty']);
        $utilization = $location['capacity'] > 0 
            ? round(($location['current_qty'] / $location['capacity']) * 100, 2) 
            : 0;
        
        return [
            'capacity' => (int)$location['capacity'],
            'current_qty' => (int)$location['current_qty'],
            'available' => $available,
            'utilization_percent' => $utilization
        ];
    }
    
    /**
     * Get zone utilization percentage
     * 
     * @param string $zone Zone identifier
     * @return float Utilization percentage (0-100)
     */
    public function getZoneUtilization(string $zone): float {
        $stmt = $this->db->prepare("
            SELECT SUM(capacity) as total_capacity, SUM(current_qty) as total_qty
            FROM warehouse_locations 
            WHERE line_account_id = ? AND zone = ? AND is_active = 1
        ");
        $stmt->execute([$this->lineAccountId, strtoupper($zone)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['total_capacity'] == 0) {
            return 0.0;
        }
        
        return round(($result['total_qty'] / $result['total_capacity']) * 100, 2);
    }
    
    /**
     * Get underutilized locations (below threshold)
     * 
     * @param float $threshold Utilization threshold percentage (default 20%)
     * @return array List of underutilized locations
     */
    public function getUnderutilizedLocations(float $threshold = 20.0): array {
        $stmt = $this->db->prepare("
            SELECT *, 
                   ROUND((current_qty / capacity) * 100, 2) as utilization_percent
            FROM warehouse_locations 
            WHERE line_account_id = ? 
              AND is_active = 1 
              AND capacity > 0
              AND (current_qty / capacity) * 100 < ?
            ORDER BY utilization_percent ASC
        ");
        $stmt->execute([$this->lineAccountId, $threshold]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get overall warehouse utilization statistics
     * 
     * @return array Utilization statistics by zone
     */
    public function getWarehouseUtilization(): array {
        $stmt = $this->db->prepare("
            SELECT zone, zone_type,
                   COUNT(*) as location_count,
                   SUM(capacity) as total_capacity,
                   SUM(current_qty) as total_qty,
                   ROUND((SUM(current_qty) / SUM(capacity)) * 100, 2) as utilization_percent
            FROM warehouse_locations 
            WHERE line_account_id = ? AND is_active = 1 AND capacity > 0
            GROUP BY zone, zone_type
            ORDER BY zone
        ");
        $stmt->execute([$this->lineAccountId]);
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate overall totals
        $totalCapacity = array_sum(array_column($zones, 'total_capacity'));
        $totalQty = array_sum(array_column($zones, 'total_qty'));
        $overallUtilization = $totalCapacity > 0 
            ? round(($totalQty / $totalCapacity) * 100, 2) 
            : 0;
        
        return [
            'zones' => $zones,
            'total_capacity' => $totalCapacity,
            'total_qty' => $totalQty,
            'overall_utilization' => $overallUtilization,
            'location_count' => array_sum(array_column($zones, 'location_count'))
        ];
    }
    
    /**
     * Update location quantity
     * 
     * @param int $locationId Location ID
     * @param int $quantityChange Quantity to add (positive) or remove (negative)
     * @return bool True on success
     * @throws Exception If capacity exceeded or quantity goes negative
     */
    public function updateLocationQuantity(int $locationId, int $quantityChange): bool {
        $location = $this->getLocation($locationId);
        if (!$location) {
            throw new Exception('Location not found', 404);
        }
        
        $newQty = $location['current_qty'] + $quantityChange;
        
        if ($newQty < 0) {
            throw new Exception('Location quantity cannot be negative', 400);
        }
        
        if ($newQty > $location['capacity']) {
            throw new Exception('Location capacity exceeded. Available: ' . 
                ($location['capacity'] - $location['current_qty']), 400);
        }
        
        $stmt = $this->db->prepare("
            UPDATE warehouse_locations 
            SET current_qty = ? 
            WHERE id = ? AND line_account_id = ?
        ");
        
        return $stmt->execute([$newQty, $locationId, $this->lineAccountId]);
    }
    
    /**
     * Bulk create locations for a zone
     * 
     * @param string $zone Zone identifier
     * @param string $zoneType Zone type
     * @param int $shelves Number of shelves
     * @param int $binsPerShelf Number of bins per shelf
     * @param int $capacity Capacity per bin
     * @return array Created location IDs
     */
    public function bulkCreateLocations(
        string $zone, 
        string $zoneType, 
        int $shelves, 
        int $binsPerShelf, 
        int $capacity = 100
    ): array {
        $createdIds = [];
        
        for ($shelf = 1; $shelf <= $shelves; $shelf++) {
            // Determine ergonomic level based on shelf position
            $ergonomicLevel = 'golden';
            if ($shelf <= 1) {
                $ergonomicLevel = 'lower';
            } elseif ($shelf >= $shelves - 1 && $shelves > 3) {
                $ergonomicLevel = 'upper';
            }
            
            for ($bin = 1; $bin <= $binsPerShelf; $bin++) {
                try {
                    $id = $this->createLocation([
                        'zone' => $zone,
                        'shelf' => $shelf,
                        'bin' => $bin,
                        'zone_type' => $zoneType,
                        'ergonomic_level' => $ergonomicLevel,
                        'capacity' => $capacity
                    ]);
                    $createdIds[] = $id;
                } catch (Exception $e) {
                    // Skip duplicates, continue with others
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        throw $e;
                    }
                }
            }
        }
        
        return $createdIds;
    }
}
