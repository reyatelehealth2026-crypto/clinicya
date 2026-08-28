<?php
/**
 * Pharmacy Integration Service
 * 
 * Integrates Vibe Selling OS v2 with existing pharmacy systems:
 * - drug_interactions table for interaction checking
 * - users table for medical history (medical_conditions, drug_allergies, current_medications)
 * - business_items table for drug inventory
 * 
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5
 */

class PharmacyIntegrationService
{
    private $db;
    private $lineAccountId;
    
    // Severity levels for drug interactions
    const SEVERITY_CONTRAINDICATED = 'contraindicated';
    const SEVERITY_SEVERE = 'severe';
    const SEVERITY_MODERATE = 'moderate';
    const SEVERITY_MILD = 'mild';
    
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
    
    // =========================================================================
    // DRUG INTERACTIONS (Requirements 10.5)
    // =========================================================================
    
    /**
     * Check drug interactions using the existing drug_interactions table
     * 
     * Requirements 10.5: Use the existing drug_interactions table
     * 
     * @param array $drugNames Array of drug names to check
     * @param int|null $userId User ID to check against current medications
     * @return array ['hasInteractions' => bool, 'interactions' => array, 'severity' => string]
     */
    public function checkDrugInteractions(array $drugNames, ?int $userId = null): array
    {
        $interactions = [];
        $highestSeverity = self::SEVERITY_MILD;
        
        // Get user's current medications if userId provided
        $currentMedications = [];
        if ($userId) {
            $healthData = $this->getUserMedicalHistory($userId);
            $currentMedications = $healthData['currentMedications'] ?? [];
        }
        
        // Combine new drugs with current medications for comprehensive check
        $allDrugs = array_merge($drugNames, $currentMedications);
        $allDrugs = array_unique(array_filter($allDrugs));
        
        // Check interactions between all drug pairs
        for ($i = 0; $i < count($allDrugs); $i++) {
            for ($j = $i + 1; $j < count($allDrugs); $j++) {
                $drug1 = $allDrugs[$i];
                $drug2 = $allDrugs[$j];
                
                $interaction = $this->findInteraction($drug1, $drug2);
                
                if ($interaction) {
                    $interactions[] = $interaction;
                    $highestSeverity = $this->getHigherSeverity($highestSeverity, $interaction['severity']);
                }
            }
        }
        
        return [
            'hasInteractions' => !empty($interactions),
            'interactions' => $interactions,
            'severity' => $highestSeverity,
            'severityLabel' => $this->getSeverityLabel($highestSeverity),
            'drugsChecked' => $allDrugs,
            'interactionCount' => count($interactions)
        ];
    }

    
    /**
     * Find interaction between two drugs in the drug_interactions table
     * 
     * @param string $drug1 First drug name
     * @param string $drug2 Second drug name
     * @return array|null Interaction data or null if none found
     */
    private function findInteraction(string $drug1, string $drug2): ?array
    {
        try {
            // Check if drug_interactions table exists
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'drug_interactions'");
            if ($tableCheck->rowCount() === 0) {
                return null;
            }
            
            $drug1Lower = mb_strtolower(trim($drug1));
            $drug2Lower = mb_strtolower(trim($drug2));
            
            // Search for interaction in both directions
            $stmt = $this->db->prepare("
                SELECT id, drug1_name, drug1_generic, drug2_name, drug2_generic, 
                       severity, description, recommendation
                FROM drug_interactions 
                WHERE (LOWER(drug1_name) LIKE ? OR LOWER(drug1_generic) LIKE ?)
                  AND (LOWER(drug2_name) LIKE ? OR LOWER(drug2_generic) LIKE ?)
                UNION
                SELECT id, drug1_name, drug1_generic, drug2_name, drug2_generic, 
                       severity, description, recommendation
                FROM drug_interactions 
                WHERE (LOWER(drug1_name) LIKE ? OR LOWER(drug1_generic) LIKE ?)
                  AND (LOWER(drug2_name) LIKE ? OR LOWER(drug2_generic) LIKE ?)
                LIMIT 1
            ");
            
            $stmt->execute([
                "%{$drug1Lower}%", "%{$drug1Lower}%",
                "%{$drug2Lower}%", "%{$drug2Lower}%",
                "%{$drug2Lower}%", "%{$drug2Lower}%",
                "%{$drug1Lower}%", "%{$drug1Lower}%"
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'id' => $result['id'],
                    'drug1' => $result['drug1_name'],
                    'drug1Generic' => $result['drug1_generic'],
                    'drug2' => $result['drug2_name'],
                    'drug2Generic' => $result['drug2_generic'],
                    'severity' => $result['severity'] ?? self::SEVERITY_MODERATE,
                    'description' => $result['description'],
                    'recommendation' => $result['recommendation'],
                    'source' => 'database'
                ];
            }
            
            return null;
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration findInteraction error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all drug interactions from database
     * 
     * @param string|null $severity Filter by severity
     * @return array List of interactions
     */
    public function getAllInteractions(?string $severity = null): array
    {
        try {
            $sql = "SELECT * FROM drug_interactions";
            $params = [];
            
            if ($severity) {
                $sql .= " WHERE severity = ?";
                $params[] = $severity;
            }
            
            $sql .= " ORDER BY severity DESC, drug1_name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getAllInteractions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add a new drug interaction
     * 
     * @param array $data Interaction data
     * @return array ['success' => bool, 'id' => int|null, 'error' => string|null]
     */
    public function addInteraction(array $data): array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO drug_interactions 
                (drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                trim($data['drug1_name'] ?? ''),
                trim($data['drug1_generic'] ?? ''),
                trim($data['drug2_name'] ?? ''),
                trim($data['drug2_generic'] ?? ''),
                $data['severity'] ?? self::SEVERITY_MODERATE,
                trim($data['description'] ?? ''),
                trim($data['recommendation'] ?? '')
            ]);
            
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'error' => null
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'id' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    
    // =========================================================================
    // USER MEDICAL HISTORY (Requirements 10.1, 10.4)
    // =========================================================================
    
    /**
     * Get user's medical history from users table
     * 
     * Requirements 10.1: Fetch medical history, allergies, and current medications from users table
     * Requirements 10.4: Incorporate existing tags, notes, and prescription history
     * 
     * @param int $userId User ID
     * @return array Medical history data
     */
    public function getUserMedicalHistory(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id, display_name, first_name, last_name,
                    weight, height, birthday AS birth_date, gender,
                    drug_allergies, chronic_diseases, current_medications, medical_conditions
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'userId' => $userId,
                    'found' => false,
                    'allergies' => [],
                    'conditions' => [],
                    'currentMedications' => [],
                    'weight' => null,
                    'height' => null,
                    'age' => null,
                    'gender' => null
                ];
            }
            
            // Parse text fields into arrays
            $allergies = $this->parseTextToArray($user['drug_allergies']);
            $conditions = array_merge(
                $this->parseTextToArray($user['chronic_diseases']),
                $this->parseTextToArray($user['medical_conditions'])
            );
            $conditions = array_unique($conditions);
            $medications = $this->parseTextToArray($user['current_medications']);
            
            // Calculate age
            $age = null;
            if ($user['birth_date']) {
                $birthDate = new DateTime($user['birth_date']);
                $today = new DateTime();
                $age = $birthDate->diff($today)->y;
            }
            
            return [
                'userId' => $userId,
                'found' => true,
                'displayName' => $user['display_name'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'],
                'allergies' => $allergies,
                'conditions' => $conditions,
                'currentMedications' => $medications,
                'weight' => $user['weight'] ? (float)$user['weight'] : null,
                'height' => $user['height'] ? (float)$user['height'] : null,
                'age' => $age,
                'gender' => $user['gender'],
                'hasAllergies' => !empty($allergies),
                'hasConditions' => !empty($conditions),
                'hasMedications' => !empty($medications)
            ];
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getUserMedicalHistory error: " . $e->getMessage());
            return [
                'userId' => $userId,
                'found' => false,
                'error' => $e->getMessage(),
                'allergies' => [],
                'conditions' => [],
                'currentMedications' => []
            ];
        }
    }
    
    /**
     * Update user's medical history
     * 
     * @param int $userId User ID
     * @param array $data Medical data to update
     * @return bool Success
     */
    public function updateUserMedicalHistory(int $userId, array $data): bool
    {
        try {
            $updates = [];
            $params = [];
            
            if (isset($data['drug_allergies'])) {
                $updates[] = 'drug_allergies = ?';
                $params[] = is_array($data['drug_allergies']) 
                    ? implode(', ', $data['drug_allergies']) 
                    : $data['drug_allergies'];
            }
            
            if (isset($data['chronic_diseases'])) {
                $updates[] = 'chronic_diseases = ?';
                $params[] = is_array($data['chronic_diseases']) 
                    ? implode(', ', $data['chronic_diseases']) 
                    : $data['chronic_diseases'];
            }
            
            if (isset($data['current_medications'])) {
                $updates[] = 'current_medications = ?';
                $params[] = is_array($data['current_medications']) 
                    ? implode(', ', $data['current_medications']) 
                    : $data['current_medications'];
            }
            
            if (isset($data['medical_conditions'])) {
                $updates[] = 'medical_conditions = ?';
                $params[] = is_array($data['medical_conditions']) 
                    ? implode(', ', $data['medical_conditions']) 
                    : $data['medical_conditions'];
            }
            
            if (empty($updates)) {
                return true; // Nothing to update
            }
            
            $params[] = $userId;
            
            $stmt = $this->db->prepare("
                UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?
            ");
            
            return $stmt->execute($params);
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration updateUserMedicalHistory error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user has allergy to a specific drug
     * 
     * @param int $userId User ID
     * @param string $drugName Drug name to check
     * @return array ['hasAllergy' => bool, 'matchedAllergies' => array]
     */
    public function checkUserAllergy(int $userId, string $drugName): array
    {
        $medicalHistory = $this->getUserMedicalHistory($userId);
        $allergies = $medicalHistory['allergies'] ?? [];
        $matchedAllergies = [];
        
        $drugLower = mb_strtolower($drugName);
        
        foreach ($allergies as $allergy) {
            $allergyLower = mb_strtolower($allergy);
            
            // Check for direct match or partial match
            if (mb_strpos($drugLower, $allergyLower) !== false ||
                mb_strpos($allergyLower, $drugLower) !== false) {
                $matchedAllergies[] = [
                    'allergy' => $allergy,
                    'drug' => $drugName,
                    'matchType' => 'direct'
                ];
            }
        }
        
        return [
            'hasAllergy' => !empty($matchedAllergies),
            'matchedAllergies' => $matchedAllergies,
            'allUserAllergies' => $allergies
        ];
    }
    
    /**
     * Get user's prescription history
     * 
     * Requirements 10.4: Incorporate prescription history
     * 
     * @param int $userId User ID
     * @param int $limit Number of records to return
     * @return array Prescription history
     */
    public function getUserPrescriptionHistory(int $userId, int $limit = 20): array
    {
        try {
            // Get from transactions with prescription items
            $stmt = $this->db->prepare("
                SELECT 
                    t.id as transaction_id,
                    t.order_number,
                    t.created_at,
                    t.status,
                    ti.product_name,
                    ti.quantity,
                    bi.generic_name,
                    bi.is_prescription,
                    bi.drug_category
                FROM transactions t
                JOIN transaction_items ti ON t.id = ti.transaction_id
                LEFT JOIN business_items bi ON ti.product_id = bi.id
                WHERE t.user_id = ?
                AND t.status NOT IN ('cancelled', 'failed')
                AND (bi.is_prescription = 1 OR bi.drug_category IN ('dangerous', 'controlled'))
                ORDER BY t.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getUserPrescriptionHistory error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user tags and notes
     * 
     * Requirements 10.4: Incorporate existing tags and notes
     * 
     * @param int $userId User ID
     * @return array Tags and notes
     */
    public function getUserTagsAndNotes(int $userId): array
    {
        $result = [
            'tags' => [],
            'notes' => []
        ];
        
        try {
            // Get user tags
            $stmt = $this->db->prepare("
                SELECT ut.id, ut.name, ut.color, ut.description
                FROM user_tags ut
                JOIN user_tag_assignments uta ON ut.id = uta.tag_id
                WHERE uta.user_id = ?
            ");
            $stmt->execute([$userId]);
            $result['tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            // Tags table might not exist
        }
        
        try {
            // Get customer notes
            $stmt = $this->db->prepare("
                SELECT id, note, note_type, created_at, created_by
                FROM customer_notes
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            $result['notes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            // Notes table might not exist
        }
        
        return $result;
    }

    
    // =========================================================================
    // DRUG INVENTORY (Requirements 10.2, 10.3)
    // =========================================================================
    
    /**
     * Check drug inventory from business_items table
     * 
     * Requirements 10.2: Check real-time inventory from stock tables
     * 
     * @param int $productId Product/Drug ID
     * @return array Inventory data
     */
    public function getDrugInventory(int $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id, sku, name, generic_name, 
                    stock, min_stock, 
                    price, sale_price, cost_price,
                    is_active, is_prescription, drug_category,
                    storage_condition, storage_zone_type,
                    requires_batch_tracking, requires_expiry_tracking
                FROM business_items 
                WHERE id = ?
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return [
                    'found' => false,
                    'productId' => $productId,
                    'inStock' => false,
                    'stock' => 0
                ];
            }
            
            $stock = (int)$product['stock'];
            $minStock = (int)$product['min_stock'];
            
            return [
                'found' => true,
                'productId' => $productId,
                'sku' => $product['sku'],
                'name' => $product['name'],
                'genericName' => $product['generic_name'],
                'stock' => $stock,
                'minStock' => $minStock,
                'inStock' => $stock > 0,
                'isLowStock' => $stock > 0 && $stock <= $minStock,
                'isOutOfStock' => $stock <= 0,
                'price' => (float)$product['price'],
                'salePrice' => $product['sale_price'] ? (float)$product['sale_price'] : null,
                'costPrice' => $product['cost_price'] ? (float)$product['cost_price'] : null,
                'isActive' => (bool)$product['is_active'],
                'isPrescription' => (bool)$product['is_prescription'],
                'drugCategory' => $product['drug_category'],
                'storageCondition' => $product['storage_condition'],
                'storageZoneType' => $product['storage_zone_type'],
                'requiresBatchTracking' => (bool)$product['requires_batch_tracking'],
                'requiresExpiryTracking' => (bool)$product['requires_expiry_tracking']
            ];
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getDrugInventory error: " . $e->getMessage());
            return [
                'found' => false,
                'productId' => $productId,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Search drugs in inventory
     * 
     * @param string $query Search query
     * @param bool $inStockOnly Only return in-stock items
     * @param int $limit Result limit
     * @return array Matching drugs
     */
    public function searchDrugInventory(string $query, bool $inStockOnly = false, int $limit = 20): array
    {
        try {
            $queryLower = mb_strtolower(trim($query));
            
            $sql = "
                SELECT 
                    id, sku, name, generic_name, 
                    stock, min_stock, 
                    price, sale_price, cost_price,
                    is_active, is_prescription, drug_category,
                    image_url
                FROM business_items 
                WHERE is_active = 1
                AND (
                    LOWER(name) LIKE ? 
                    OR LOWER(generic_name) LIKE ?
                    OR LOWER(sku) LIKE ?
                    OR LOWER(barcode) LIKE ?
                )
            ";
            
            if ($inStockOnly) {
                $sql .= " AND stock > 0";
            }
            
            $sql .= " ORDER BY stock DESC, name ASC LIMIT ?";
            
            $searchPattern = "%{$queryLower}%";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $limit]);
            
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stock = (int)$row['stock'];
                $results[] = [
                    'id' => $row['id'],
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'genericName' => $row['generic_name'],
                    'stock' => $stock,
                    'inStock' => $stock > 0,
                    'isLowStock' => $stock > 0 && $stock <= (int)$row['min_stock'],
                    'price' => (float)$row['price'],
                    'salePrice' => $row['sale_price'] ? (float)$row['sale_price'] : null,
                    'isPrescription' => (bool)$row['is_prescription'],
                    'drugCategory' => $row['drug_category'],
                    'imageUrl' => $row['image_url']
                ];
            }
            
            return $results;
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration searchDrugInventory error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get drug pricing data from business_items
     * 
     * Requirements 10.3: Use cost data from business_items table
     * 
     * @param int $productId Product ID
     * @return array Pricing data
     */
    public function getDrugPricing(int $productId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id, name, price, sale_price, cost_price
                FROM business_items 
                WHERE id = ?
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return [
                    'found' => false,
                    'productId' => $productId
                ];
            }
            
            $price = (float)$product['price'];
            $salePrice = $product['sale_price'] ? (float)$product['sale_price'] : null;
            $costPrice = $product['cost_price'] ? (float)$product['cost_price'] : null;
            
            // Calculate margin
            $effectivePrice = $salePrice ?? $price;
            $margin = $costPrice ? ($effectivePrice - $costPrice) : null;
            $marginPercent = ($costPrice && $effectivePrice > 0) 
                ? (($effectivePrice - $costPrice) / $effectivePrice) * 100 
                : null;
            
            return [
                'found' => true,
                'productId' => $productId,
                'name' => $product['name'],
                'price' => $price,
                'salePrice' => $salePrice,
                'costPrice' => $costPrice,
                'effectivePrice' => $effectivePrice,
                'margin' => $margin ? round($margin, 2) : null,
                'marginPercent' => $marginPercent ? round($marginPercent, 2) : null,
                'hasCostData' => $costPrice !== null
            ];
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getDrugPricing error: " . $e->getMessage());
            return [
                'found' => false,
                'productId' => $productId,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get drugs that are low on stock
     * 
     * @param int $limit Result limit
     * @return array Low stock drugs
     */
    public function getLowStockDrugs(int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id, sku, name, generic_name, 
                    stock, min_stock,
                    drug_category, is_prescription
                FROM business_items 
                WHERE is_active = 1
                AND stock <= min_stock
                AND stock > 0
                ORDER BY (stock / GREATEST(min_stock, 1)) ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getLowStockDrugs error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get out of stock drugs
     * 
     * @param int $limit Result limit
     * @return array Out of stock drugs
     */
    public function getOutOfStockDrugs(int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id, sku, name, generic_name, 
                    stock, min_stock,
                    drug_category, is_prescription
                FROM business_items 
                WHERE is_active = 1
                AND stock <= 0
                ORDER BY name ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("PharmacyIntegration getOutOfStockDrugs error: " . $e->getMessage());
            return [];
        }
    }

    
    // =========================================================================
    // HELPER METHODS
    // =========================================================================
    
    /**
     * Parse text field to array (comma or newline separated)
     * 
     * @param string|null $text Text to parse
     * @return array Parsed items
     */
    private function parseTextToArray(?string $text): array
    {
        if (empty($text)) {
            return [];
        }
        
        // Split by comma, newline, or semicolon
        $items = preg_split('/[,;\n]+/', $text);
        
        // Clean up each item
        $items = array_map('trim', $items);
        $items = array_filter($items); // Remove empty items
        
        return array_values($items);
    }
    
    /**
     * Get higher severity between two severity levels
     * 
     * @param string $severity1 First severity
     * @param string $severity2 Second severity
     * @return string Higher severity
     */
    private function getHigherSeverity(string $severity1, string $severity2): string
    {
        $order = [
            self::SEVERITY_MILD => 1,
            self::SEVERITY_MODERATE => 2,
            self::SEVERITY_SEVERE => 3,
            self::SEVERITY_CONTRAINDICATED => 4
        ];
        
        $level1 = $order[$severity1] ?? 1;
        $level2 = $order[$severity2] ?? 1;
        
        return $level1 >= $level2 ? $severity1 : $severity2;
    }
    
    /**
     * Get severity label in Thai
     * 
     * @param string $severity Severity level
     * @return string Thai label
     */
    private function getSeverityLabel(string $severity): string
    {
        $labels = [
            self::SEVERITY_MILD => 'เล็กน้อย',
            self::SEVERITY_MODERATE => 'ปานกลาง',
            self::SEVERITY_SEVERE => 'รุนแรง',
            self::SEVERITY_CONTRAINDICATED => 'ห้ามใช้ร่วมกัน'
        ];
        
        return $labels[$severity] ?? 'ไม่ระบุ';
    }
    
    /**
     * Get comprehensive patient profile for pharmacy consultation
     * 
     * Combines all data sources for a complete view
     * 
     * @param int $userId User ID
     * @return array Complete patient profile
     */
    public function getComprehensivePatientProfile(int $userId): array
    {
        // Get medical history
        $medicalHistory = $this->getUserMedicalHistory($userId);
        
        // Get tags and notes
        $tagsAndNotes = $this->getUserTagsAndNotes($userId);
        
        // Get prescription history
        $prescriptionHistory = $this->getUserPrescriptionHistory($userId, 10);
        
        // Check for any drug interactions with current medications
        $currentMeds = $medicalHistory['currentMedications'] ?? [];
        $interactionCheck = [];
        if (count($currentMeds) > 1) {
            $interactionCheck = $this->checkDrugInteractions($currentMeds);
        }
        
        return [
            'userId' => $userId,
            'found' => $medicalHistory['found'],
            'displayName' => $medicalHistory['displayName'] ?? null,
            'demographics' => [
                'age' => $medicalHistory['age'],
                'gender' => $medicalHistory['gender'],
                'weight' => $medicalHistory['weight'],
                'height' => $medicalHistory['height']
            ],
            'health' => [
                'allergies' => $medicalHistory['allergies'],
                'conditions' => $medicalHistory['conditions'],
                'currentMedications' => $medicalHistory['currentMedications'],
                'hasAllergies' => $medicalHistory['hasAllergies'] ?? false,
                'hasConditions' => $medicalHistory['hasConditions'] ?? false,
                'hasMedications' => $medicalHistory['hasMedications'] ?? false
            ],
            'tags' => $tagsAndNotes['tags'],
            'notes' => $tagsAndNotes['notes'],
            'prescriptionHistory' => $prescriptionHistory,
            'currentMedicationInteractions' => $interactionCheck,
            'warnings' => $this->generatePatientWarnings($medicalHistory, $interactionCheck)
        ];
    }
    
    /**
     * Generate patient warnings based on profile
     * 
     * @param array $medicalHistory Medical history data
     * @param array $interactionCheck Interaction check results
     * @return array Warnings
     */
    private function generatePatientWarnings(array $medicalHistory, array $interactionCheck): array
    {
        $warnings = [];
        
        // Allergy warning
        if (!empty($medicalHistory['allergies'])) {
            $warnings[] = [
                'type' => 'allergy',
                'severity' => 'high',
                'message' => 'ลูกค้าแพ้ยา: ' . implode(', ', $medicalHistory['allergies']),
                'icon' => 'fa-exclamation-triangle',
                'color' => 'red'
            ];
        }
        
        // Chronic condition warning
        if (!empty($medicalHistory['conditions'])) {
            $warnings[] = [
                'type' => 'condition',
                'severity' => 'medium',
                'message' => 'โรคประจำตัว: ' . implode(', ', $medicalHistory['conditions']),
                'icon' => 'fa-heartbeat',
                'color' => 'orange'
            ];
        }
        
        // Drug interaction warning
        if (!empty($interactionCheck['interactions'])) {
            $warnings[] = [
                'type' => 'interaction',
                'severity' => $interactionCheck['severity'] === self::SEVERITY_CONTRAINDICATED ? 'critical' : 'high',
                'message' => 'พบยาตีกันในยาที่ใช้อยู่ ' . count($interactionCheck['interactions']) . ' คู่',
                'icon' => 'fa-pills',
                'color' => 'purple',
                'details' => $interactionCheck['interactions']
            ];
        }
        
        // Elderly patient warning
        if (isset($medicalHistory['age']) && $medicalHistory['age'] >= 65) {
            $warnings[] = [
                'type' => 'elderly',
                'severity' => 'medium',
                'message' => 'ผู้สูงอายุ (อายุ ' . $medicalHistory['age'] . ' ปี) - ควรระวังขนาดยา',
                'icon' => 'fa-user-clock',
                'color' => 'blue'
            ];
        }
        
        return $warnings;
    }
    
    /**
     * Validate drug recommendation against patient profile
     * 
     * @param int $userId User ID
     * @param int $productId Product ID to recommend
     * @return array Validation result
     */
    public function validateDrugRecommendation(int $userId, int $productId): array
    {
        $issues = [];
        $canRecommend = true;
        
        // Get drug info
        $drugInfo = $this->getDrugInventory($productId);
        
        if (!$drugInfo['found']) {
            return [
                'canRecommend' => false,
                'issues' => [['type' => 'not_found', 'message' => 'ไม่พบข้อมูลยา']]
            ];
        }
        
        // Check stock
        if (!$drugInfo['inStock']) {
            $issues[] = [
                'type' => 'out_of_stock',
                'message' => 'ยาหมดสต็อก',
                'severity' => 'high'
            ];
            $canRecommend = false;
        }
        
        // Get patient profile
        $medicalHistory = $this->getUserMedicalHistory($userId);
        
        // Check allergies
        $drugName = $drugInfo['name'];
        $genericName = $drugInfo['genericName'] ?? '';
        
        foreach ($medicalHistory['allergies'] as $allergy) {
            $allergyLower = mb_strtolower($allergy);
            if (mb_strpos(mb_strtolower($drugName), $allergyLower) !== false ||
                mb_strpos(mb_strtolower($genericName), $allergyLower) !== false) {
                $issues[] = [
                    'type' => 'allergy',
                    'message' => "ลูกค้าแพ้ยา: {$allergy}",
                    'severity' => 'critical'
                ];
                $canRecommend = false;
            }
        }
        
        // Check drug interactions with current medications
        $currentMeds = $medicalHistory['currentMedications'];
        if (!empty($currentMeds)) {
            $interactionCheck = $this->checkDrugInteractions([$drugName, $genericName], $userId);
            
            if ($interactionCheck['hasInteractions']) {
                foreach ($interactionCheck['interactions'] as $interaction) {
                    $issues[] = [
                        'type' => 'interaction',
                        'message' => "ยาตีกับ {$interaction['drug2']}: {$interaction['description']}",
                        'severity' => $interaction['severity'],
                        'recommendation' => $interaction['recommendation']
                    ];
                    
                    if ($interaction['severity'] === self::SEVERITY_CONTRAINDICATED) {
                        $canRecommend = false;
                    }
                }
            }
        }
        
        return [
            'canRecommend' => $canRecommend,
            'drugInfo' => $drugInfo,
            'issues' => $issues,
            'issueCount' => count($issues),
            'hasCriticalIssues' => !$canRecommend
        ];
    }
}
