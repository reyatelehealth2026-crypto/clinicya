<?php
/**
 * Health Profile API
 * Manages user health profile data including personal info, medical history, allergies, and medications
 * 
 * Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7, 18.8, 18.9, 18.10
 */

// CRITICAL: Error handling must be FIRST - before any includes
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

// Set headers before any potential output
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Register shutdown function to clean output buffer on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => true]);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Get database connection
$pdo = Database::getInstance()->getConnection();

// Create tables if not exist
createHealthProfileTables($pdo);

// Handle request
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    switch ($action) {
        case 'get':
            getHealthProfile($pdo);
            break;
        case 'get_allergies':
            getAllergies($pdo);
            break;
        case 'get_medications':
            getMedications($pdo);
            break;
        case 'search_drugs':
            searchDrugs($pdo);
            break;
        default:
            jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_personal':
            updatePersonalInfo($pdo, $input);
            break;
        case 'update_medical_history':
            updateMedicalHistory($pdo, $input);
            break;
        case 'add_allergy':
            addAllergy($pdo, $input);
            break;
        case 'remove_allergy':
            removeAllergy($pdo, $input);
            break;
        case 'add_medication':
            addMedication($pdo, $input);
            break;
        case 'update_medication':
            updateMedication($pdo, $input);
            break;
        case 'remove_medication':
            removeMedication($pdo, $input);
            break;
        default:
            jsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
    }
} else {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

/**
 * Create health profile tables
 */
function createHealthProfileTables($pdo) {
    // Health profile main table
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_health_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_user_id VARCHAR(50) NOT NULL,
        line_account_id INT DEFAULT 0,
        age INT DEFAULT NULL,
        gender ENUM('male', 'female', 'other') DEFAULT NULL,
        weight DECIMAL(5,2) DEFAULT NULL,
        height DECIMAL(5,2) DEFAULT NULL,
        blood_type ENUM('A', 'B', 'AB', 'O', 'unknown') DEFAULT 'unknown',
        medical_conditions JSON DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user (line_user_id, line_account_id),
        INDEX idx_line_user (line_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Drug allergies table
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_drug_allergies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_user_id VARCHAR(50) NOT NULL,
        line_account_id INT DEFAULT 0,
        drug_name VARCHAR(255) NOT NULL,
        drug_id INT DEFAULT NULL,
        reaction_type ENUM('rash', 'breathing', 'swelling', 'other') DEFAULT 'other',
        reaction_notes TEXT DEFAULT NULL,
        severity ENUM('mild', 'moderate', 'severe') DEFAULT 'moderate',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_line_user (line_user_id),
        INDEX idx_drug (drug_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Current medications table
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_current_medications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_user_id VARCHAR(50) NOT NULL,
        line_account_id INT DEFAULT 0,
        medication_name VARCHAR(255) NOT NULL,
        product_id INT DEFAULT NULL,
        dosage VARCHAR(100) DEFAULT NULL,
        frequency VARCHAR(100) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_line_user (line_user_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Get health profile
 * Requirements: 18.1, 18.10 - Display sections and completion percentage
 */
function getHealthProfile($pdo) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = $_GET['line_account_id'] ?? 0;
    
    if (empty($lineUserId)) {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }
    
    try {
        // Get or create profile
        $stmt = $pdo->prepare("SELECT * FROM user_health_profiles WHERE line_user_id = ? AND line_account_id = ?");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$profile) {
            // Create empty profile
            $stmt = $pdo->prepare("INSERT INTO user_health_profiles (line_user_id, line_account_id) VALUES (?, ?)");
            $stmt->execute([$lineUserId, $lineAccountId]);
            
            $profile = [
                'id' => $pdo->lastInsertId(),
                'line_user_id' => $lineUserId,
                'age' => null,
                'gender' => null,
                'weight' => null,
                'height' => null,
                'blood_type' => 'unknown',
                'medical_conditions' => null
            ];
        }
        
        // Parse medical conditions
        $profile['medical_conditions'] = $profile['medical_conditions'] 
            ? json_decode($profile['medical_conditions'], true) 
            : [];
        
        // Get allergies
        $stmt = $pdo->prepare("SELECT * FROM user_drug_allergies WHERE line_user_id = ? AND line_account_id = ? ORDER BY created_at DESC");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $allergies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get current medications
        $stmt = $pdo->prepare("SELECT * FROM user_current_medications WHERE line_user_id = ? AND line_account_id = ? AND is_active = 1 ORDER BY created_at DESC");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate completion percentage
        $completion = calculateCompletionPercentage($profile, $allergies, $medications);
        
        jsonResponse([
            'success' => true,
            'profile' => [
                'personal_info' => [
                    'age' => $profile['age'],
                    'gender' => $profile['gender'],
                    'weight' => $profile['weight'],
                    'height' => $profile['height'],
                    'blood_type' => $profile['blood_type']
                ],
                'medical_conditions' => $profile['medical_conditions'],
                'allergies' => $allergies,
                'medications' => $medications,
                'completion_percent' => $completion,
                'updated_at' => $profile['updated_at'] ?? null
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Health profile error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Calculate profile completion percentage
 * Requirements: 18.10 - Show completion percentage
 */
function calculateCompletionPercentage($profile, $allergies, $medications) {
    $totalFields = 8; // age, gender, weight, height, blood_type, conditions, allergies, medications
    $filledFields = 0;
    
    if (!empty($profile['age'])) $filledFields++;
    if (!empty($profile['gender'])) $filledFields++;
    if (!empty($profile['weight'])) $filledFields++;
    if (!empty($profile['height'])) $filledFields++;
    if (!empty($profile['blood_type']) && $profile['blood_type'] !== 'unknown') $filledFields++;
    
    $conditions = is_array($profile['medical_conditions']) ? $profile['medical_conditions'] : [];
    if (!empty($conditions)) $filledFields++;
    
    // Allergies and medications count as filled if user has reviewed them (even if empty)
    // For now, count as filled if they have any entries
    if (!empty($allergies)) $filledFields++;
    if (!empty($medications)) $filledFields++;
    
    return round(($filledFields / $totalFields) * 100);
}

/**
 * Update personal info
 * Requirements: 18.2 - Allow input of age, gender, weight, height, blood type
 */
function updatePersonalInfo($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $lineAccountId = $input['line_account_id'] ?? 0;
    
    if (empty($lineUserId)) {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }
    
    $age = isset($input['age']) ? intval($input['age']) : null;
    $gender = $input['gender'] ?? null;
    $weight = isset($input['weight']) ? floatval($input['weight']) : null;
    $height = isset($input['height']) ? floatval($input['height']) : null;
    $bloodType = $input['blood_type'] ?? 'unknown';
    
    // Validate
    if ($age !== null && ($age < 0 || $age > 150)) {
        jsonResponse(['success' => false, 'error' => 'Invalid age'], 400);
        return;
    }
    
    if ($gender !== null && !in_array($gender, ['male', 'female', 'other'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid gender'], 400);
        return;
    }
    
    if (!in_array($bloodType, ['A', 'B', 'AB', 'O', 'unknown'])) {
        $bloodType = 'unknown';
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_health_profiles (line_user_id, line_account_id, age, gender, weight, height, blood_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                age = VALUES(age),
                gender = VALUES(gender),
                weight = VALUES(weight),
                height = VALUES(height),
                blood_type = VALUES(blood_type),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([$lineUserId, $lineAccountId, $age, $gender, $weight, $height, $bloodType]);
        
        jsonResponse([
            'success' => true,
            'message' => 'บันทึกข้อมูลส่วนตัวแล้ว'
        ]);
        
    } catch (PDOException $e) {
        error_log("Update personal info error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Update medical history
 * Requirements: 18.3 - Provide checkboxes for common conditions
 */
function updateMedicalHistory($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $lineAccountId = $input['line_account_id'] ?? 0;
    $conditions = $input['conditions'] ?? [];
    
    if (empty($lineUserId)) {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }
    
    // Validate conditions
    $validConditions = ['diabetes', 'hypertension', 'heart_disease', 'kidney_disease', 'liver_disease', 'pregnancy', 'asthma', 'thyroid', 'cancer', 'other'];
    $conditions = array_filter($conditions, function($c) use ($validConditions) {
        return in_array($c, $validConditions);
    });
    
    try {
        $conditionsJson = json_encode(array_values($conditions));
        
        $stmt = $pdo->prepare("
            INSERT INTO user_health_profiles (line_user_id, line_account_id, medical_conditions)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                medical_conditions = VALUES(medical_conditions),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([$lineUserId, $lineAccountId, $conditionsJson]);
        
        jsonResponse([
            'success' => true,
            'message' => 'บันทึกประวัติการแพทย์แล้ว'
        ]);
        
    } catch (PDOException $e) {
        error_log("Update medical history error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Add drug allergy
 * Requirements: 18.4, 18.5 - Add drug allergies with autocomplete and reaction type
 */
function addAllergy($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $lineAccountId = $input['line_account_id'] ?? 0;
    $drugName = trim($input['drug_name'] ?? '');
    $drugId = $input['drug_id'] ?? null;
    $reactionType = $input['reaction_type'] ?? 'other';
    $reactionNotes = $input['reaction_notes'] ?? '';
    $severity = $input['severity'] ?? 'moderate';
    
    if (empty($lineUserId) || empty($drugName)) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields'], 400);
        return;
    }
    
    // Validate reaction type
    if (!in_array($reactionType, ['rash', 'breathing', 'swelling', 'other'])) {
        $reactionType = 'other';
    }
    
    // Validate severity
    if (!in_array($severity, ['mild', 'moderate', 'severe'])) {
        $severity = 'moderate';
    }
    
    try {
        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM user_drug_allergies WHERE line_user_id = ? AND line_account_id = ? AND drug_name = ?");
        $stmt->execute([$lineUserId, $lineAccountId, $drugName]);
        
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว'], 400);
            return;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO user_drug_allergies (line_user_id, line_account_id, drug_name, drug_id, reaction_type, reaction_notes, severity)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$lineUserId, $lineAccountId, $drugName, $drugId, $reactionType, $reactionNotes, $severity]);
        
        $allergyId = $pdo->lastInsertId();
        
        jsonResponse([
            'success' => true,
            'message' => 'เพิ่มข้อมูลการแพ้ยาแล้ว',
            'allergy' => [
                'id' => $allergyId,
                'drug_name' => $drugName,
                'reaction_type' => $reactionType,
                'severity' => $severity
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Add allergy error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Remove drug allergy
 */
function removeAllergy($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $allergyId = $input['allergy_id'] ?? 0;
    
    if (empty($lineUserId) || empty($allergyId)) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields'], 400);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM user_drug_allergies WHERE id = ? AND line_user_id = ?");
        $stmt->execute([$allergyId, $lineUserId]);
        
        jsonResponse([
            'success' => true,
            'message' => 'ลบข้อมูลการแพ้ยาแล้ว'
        ]);
        
    } catch (PDOException $e) {
        error_log("Remove allergy error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}


/**
 * Add current medication
 * Requirements: 18.6, 18.7 - Add medications with dosage and frequency, check for interactions
 */
function addMedication($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $lineAccountId = $input['line_account_id'] ?? 0;
    $medicationName = trim($input['medication_name'] ?? '');
    $productId = $input['product_id'] ?? null;
    $dosage = $input['dosage'] ?? '';
    $frequency = $input['frequency'] ?? '';
    $startDate = $input['start_date'] ?? null;
    $notes = $input['notes'] ?? '';
    
    if (empty($lineUserId) || empty($medicationName)) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields'], 400);
        return;
    }
    
    try {
        // Check for interactions with existing medications
        $interactions = checkMedicationInteractions($pdo, $lineUserId, $lineAccountId, $medicationName, $productId);
        
        // Insert medication
        $stmt = $pdo->prepare("
            INSERT INTO user_current_medications (line_user_id, line_account_id, medication_name, product_id, dosage, frequency, start_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$lineUserId, $lineAccountId, $medicationName, $productId, $dosage, $frequency, $startDate, $notes]);
        
        $medicationId = $pdo->lastInsertId();
        
        $response = [
            'success' => true,
            'message' => 'เพิ่มยาที่ใช้ประจำแล้ว',
            'medication' => [
                'id' => $medicationId,
                'medication_name' => $medicationName,
                'dosage' => $dosage,
                'frequency' => $frequency
            ]
        ];
        
        // Include interactions if found
        if (!empty($interactions)) {
            $response['interactions'] = $interactions;
            $response['has_interactions'] = true;
        }
        
        jsonResponse($response);
        
    } catch (PDOException $e) {
        error_log("Add medication error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Check medication interactions
 * Requirements: 18.7 - Check for interactions with existing medications
 */
function checkMedicationInteractions($pdo, $lineUserId, $lineAccountId, $newMedicationName, $newProductId = null) {
    $interactions = [];
    
    try {
        // Get existing medications
        $stmt = $pdo->prepare("SELECT medication_name, product_id FROM user_current_medications WHERE line_user_id = ? AND line_account_id = ? AND is_active = 1");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $existingMeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($existingMeds)) {
            return [];
        }
        
        // Check if drug_interactions table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'drug_interactions'");
        if ($tableCheck->rowCount() === 0) {
            return [];
        }
        
        // Check interactions for each existing medication
        foreach ($existingMeds as $med) {
            // Try to find interaction by product ID or name
            $stmt = $pdo->prepare("
                SELECT di.*, 
                       p1.name as drug1_name, 
                       p2.name as drug2_name
                FROM drug_interactions di
                LEFT JOIN products p1 ON di.drug1_id = p1.id
                LEFT JOIN products p2 ON di.drug2_id = p2.id
                WHERE (
                    (di.drug1_id = ? AND di.drug2_id = ?) OR
                    (di.drug1_id = ? AND di.drug2_id = ?) OR
                    (p1.name LIKE ? AND p2.name LIKE ?) OR
                    (p1.name LIKE ? AND p2.name LIKE ?)
                )
                LIMIT 1
            ");
            
            $existingProductId = $med['product_id'] ?? 0;
            $existingName = '%' . $med['medication_name'] . '%';
            $newName = '%' . $newMedicationName . '%';
            
            $stmt->execute([
                $existingProductId, $newProductId,
                $newProductId, $existingProductId,
                $existingName, $newName,
                $newName, $existingName
            ]);
            
            $interaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($interaction) {
                $interactions[] = [
                    'drug1' => $med['medication_name'],
                    'drug2' => $newMedicationName,
                    'severity' => $interaction['severity'] ?? 'moderate',
                    'description' => $interaction['description'] ?? 'อาจมีปฏิกิริยาระหว่างยา',
                    'recommendation' => $interaction['recommendation'] ?? 'ควรปรึกษาเภสัชกร'
                ];
            }
        }
        
    } catch (PDOException $e) {
        error_log("Check interactions error: " . $e->getMessage());
    }
    
    return $interactions;
}

/**
 * Update medication
 */
function updateMedication($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $medicationId = $input['medication_id'] ?? 0;
    $dosage = $input['dosage'] ?? '';
    $frequency = $input['frequency'] ?? '';
    $notes = $input['notes'] ?? '';
    $isActive = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : 1;
    
    if (empty($lineUserId) || empty($medicationId)) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields'], 400);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE user_current_medications 
            SET dosage = ?, frequency = ?, notes = ?, is_active = ?
            WHERE id = ? AND line_user_id = ?
        ");
        
        $stmt->execute([$dosage, $frequency, $notes, $isActive, $medicationId, $lineUserId]);
        
        jsonResponse([
            'success' => true,
            'message' => 'อัพเดทข้อมูลยาแล้ว'
        ]);
        
    } catch (PDOException $e) {
        error_log("Update medication error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Remove medication
 */
function removeMedication($pdo, $input) {
    $lineUserId = $input['line_user_id'] ?? '';
    $medicationId = $input['medication_id'] ?? 0;
    
    if (empty($lineUserId) || empty($medicationId)) {
        jsonResponse(['success' => false, 'error' => 'Missing required fields'], 400);
        return;
    }
    
    try {
        // Soft delete - set is_active to 0
        $stmt = $pdo->prepare("UPDATE user_current_medications SET is_active = 0 WHERE id = ? AND line_user_id = ?");
        $stmt->execute([$medicationId, $lineUserId]);
        
        jsonResponse([
            'success' => true,
            'message' => 'ลบยาออกจากรายการแล้ว'
        ]);
        
    } catch (PDOException $e) {
        error_log("Remove medication error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Get allergies list
 */
function getAllergies($pdo) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = $_GET['line_account_id'] ?? 0;
    
    if (empty($lineUserId)) {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_drug_allergies WHERE line_user_id = ? AND line_account_id = ? ORDER BY created_at DESC");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $allergies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'allergies' => $allergies,
            'count' => count($allergies)
        ]);
        
    } catch (PDOException $e) {
        error_log("Get allergies error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Get medications list
 */
function getMedications($pdo) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = $_GET['line_account_id'] ?? 0;
    
    if (empty($lineUserId)) {
        jsonResponse(['success' => false, 'error' => 'Missing line_user_id'], 400);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_current_medications WHERE line_user_id = ? AND line_account_id = ? AND is_active = 1 ORDER BY created_at DESC");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'medications' => $medications,
            'count' => count($medications)
        ]);
        
    } catch (PDOException $e) {
        error_log("Get medications error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

/**
 * Search drugs for autocomplete
 * Requirements: 18.4 - Autocomplete from drug database
 */
function searchDrugs($pdo) {
    $query = $_GET['q'] ?? '';
    
    if (strlen($query) < 2) {
        jsonResponse(['success' => true, 'drugs' => []]);
        return;
    }
    
    try {
        // Search in products table for drugs
        $searchTerm = '%' . $query . '%';
        $stmt = $pdo->prepare("
            SELECT id, name, generic_name, sku 
            FROM products 
            WHERE (name LIKE ? OR generic_name LIKE ? OR sku LIKE ?)
            AND category_id IN (SELECT id FROM categories WHERE name LIKE '%ยา%')
            LIMIT 10
        ");
        
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'drugs' => $drugs
        ]);
        
    } catch (PDOException $e) {
        // If products table doesn't exist or query fails, return empty
        jsonResponse(['success' => true, 'drugs' => []]);
    }
}

/**
 * JSON response helper
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}
