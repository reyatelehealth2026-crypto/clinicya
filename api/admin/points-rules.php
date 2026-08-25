<?php
/**
 * Admin Points Rules API
 * Requirements: 25.1-25.12 - Points Earning Rules Configuration
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

// Check admin authentication
if (!$adminId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    switch ($method) {
        case 'GET':
            // Requirement 25.1: Display current earning rules and multipliers
            if ($action === 'rules') {
                echo json_encode(['success' => true, 'data' => getPointsRules($db, $lineAccountId)]);
            } elseif ($action === 'campaigns') {
                echo json_encode(['success' => true, 'data' => getCampaigns($db, $lineAccountId)]);
            } elseif ($action === 'categories') {
                echo json_encode(['success' => true, 'data' => getCategoryBonuses($db, $lineAccountId)]);
            } elseif ($action === 'tiers') {
                echo json_encode(['success' => true, 'data' => getTierSettings($db, $lineAccountId)]);
            } else {
                // Return all settings
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'rules' => getPointsRules($db, $lineAccountId),
                        'campaigns' => getCampaigns($db, $lineAccountId),
                        'category_bonuses' => getCategoryBonuses($db, $lineAccountId),
                        'tier_settings' => getTierSettings($db, $lineAccountId),
                        'categories' => getCategories($db, $lineAccountId)
                    ]
                ]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $action = $input['action'] ?? $action;
            
            switch ($action) {
                case 'update_rules':
                    // Requirements 25.2, 25.6, 25.7: Base rate, minimum order, expiry
                    $result = updatePointsRules($db, $lineAccountId, $input);
                    echo json_encode($result);
                    break;
                    
                case 'create_campaign':
                    // Requirement 25.3: Time-limited campaigns
                    $result = createCampaign($db, $lineAccountId, $input);
                    echo json_encode($result);
                    break;
                    
                case 'update_campaign':
                    $result = updateCampaign($db, $input);
                    echo json_encode($result);
                    break;
                    
                case 'delete_campaign':
                    $result = deleteCampaign($db, $input['id']);
                    echo json_encode($result);
                    break;
                    
                case 'toggle_campaign':
                    $result = toggleCampaign($db, $input['id']);
                    echo json_encode($result);
                    break;
                    
                case 'update_category_bonus':
                    // Requirement 25.4: Category bonus configuration
                    $result = updateCategoryBonus($db, $lineAccountId, $input);
                    echo json_encode($result);
                    break;
                    
                case 'delete_category_bonus':
                    $result = deleteCategoryBonus($db, $input['id']);
                    echo json_encode($result);
                    break;
                    
                case 'update_tier_settings':
                    // Requirements 25.5, 25.8: Tier multipliers and thresholds
                    $result = updateTierSettings($db, $lineAccountId, $input);
                    echo json_encode($result);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Get points earning rules
 * Requirement 25.1: Display current earning rules
 */
function getPointsRules($db, $lineAccountId) {
    $stmt = $db->prepare("
        SELECT * FROM points_settings 
        WHERE line_account_id = ? OR line_account_id IS NULL 
        ORDER BY line_account_id DESC LIMIT 1
    ");
    $stmt->execute([$lineAccountId]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rules) {
        // Return defaults
        return [
            'id' => null,
            'line_account_id' => $lineAccountId,
            'points_per_baht' => 0.001,
            'min_order_for_points' => 0,
            'points_expiry_days' => 365,
            'is_active' => 1
        ];
    }
    
    return $rules;
}

/**
 * Get active and upcoming campaigns
 * Requirement 25.3: Time-limited campaigns
 */
function getCampaigns($db, $lineAccountId) {
    $stmt = $db->prepare("
        SELECT pc.*, 
               CASE 
                   WHEN NOW() BETWEEN pc.start_date AND pc.end_date THEN 'active'
                   WHEN pc.start_date > NOW() THEN 'upcoming'
                   ELSE 'expired'
               END as status
        FROM points_campaigns pc
        WHERE pc.line_account_id = ? OR pc.line_account_id IS NULL
        ORDER BY pc.start_date DESC
    ");
    $stmt->execute([$lineAccountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get category bonus settings
 * Requirement 25.4: Category bonus configuration
 */
function getCategoryBonuses($db, $lineAccountId) {
    $stmt = $db->prepare("
        SELECT cb.*, ic.name as category_name
        FROM category_points_bonus cb
        LEFT JOIN item_categories ic ON cb.category_id = ic.id
        WHERE cb.line_account_id = ? OR cb.line_account_id IS NULL
        ORDER BY cb.multiplier DESC
    ");
    $stmt->execute([$lineAccountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get tier settings
 * Requirements 25.5, 25.8: Tier multipliers and thresholds
 */
function getTierSettings($db, $lineAccountId) {
    $stmt = $db->prepare("
        SELECT * FROM tier_settings 
        WHERE line_account_id = ? OR line_account_id IS NULL
        ORDER BY min_points ASC
    ");
    $stmt->execute([$lineAccountId]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tiers)) {
        // Return defaults
        return [
            ['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0],
            ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5],
            ['name' => 'Platinum', 'min_points' => 5000, 'multiplier' => 2.0]
        ];
    }
    
    return $tiers;
}

/**
 * Get all categories for dropdown
 */
function getCategories($db, $lineAccountId) {
    $stmt = $db->prepare("
        SELECT id, name FROM item_categories 
        WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1
        ORDER BY name ASC
    ");
    $stmt->execute([$lineAccountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Update points earning rules
 * Requirements 25.2, 25.6, 25.7
 */
function updatePointsRules($db, $lineAccountId, $data) {
    $pointsPerBaht = floatval($data['points_per_baht'] ?? 0.001);
    $minOrder = floatval($data['min_order_for_points'] ?? 0);
    $expiryDays = intval($data['points_expiry_days'] ?? 365);
    $isActive = isset($data['is_active']) ? 1 : 0;
    
    // Requirement 25.9: Apply new rules to future transactions only
    $stmt = $db->prepare("
        INSERT INTO points_settings (line_account_id, points_per_baht, min_order_for_points, points_expiry_days, is_active)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            points_per_baht = VALUES(points_per_baht),
            min_order_for_points = VALUES(min_order_for_points),
            points_expiry_days = VALUES(points_expiry_days),
            is_active = VALUES(is_active),
            updated_at = NOW()
    ");
    $stmt->execute([$lineAccountId, $pointsPerBaht, $minOrder, $expiryDays, $isActive]);
    
    return ['success' => true, 'message' => 'บันทึกการตั้งค่าสำเร็จ'];
}

/**
 * Create a new campaign
 * Requirement 25.3: Time-limited campaigns
 */
function createCampaign($db, $lineAccountId, $data) {
    $name = trim($data['name'] ?? '');
    $multiplier = floatval($data['multiplier'] ?? 2.0);
    $startDate = $data['start_date'] ?? date('Y-m-d');
    $endDate = $data['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
    $categoryIds = $data['category_ids'] ?? null;
    $isActive = isset($data['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        return ['success' => false, 'message' => 'กรุณาระบุชื่อแคมเปญ'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO points_campaigns (line_account_id, name, multiplier, start_date, end_date, applicable_categories, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $lineAccountId, 
        $name, 
        $multiplier, 
        $startDate, 
        $endDate, 
        $categoryIds ? json_encode($categoryIds) : null,
        $isActive
    ]);
    
    return ['success' => true, 'message' => 'สร้างแคมเปญสำเร็จ', 'id' => $db->lastInsertId()];
}

/**
 * Update campaign
 */
function updateCampaign($db, $data) {
    $id = intval($data['id'] ?? 0);
    if (!$id) {
        return ['success' => false, 'message' => 'Invalid campaign ID'];
    }
    
    $name = trim($data['name'] ?? '');
    $multiplier = floatval($data['multiplier'] ?? 2.0);
    $startDate = $data['start_date'] ?? null;
    $endDate = $data['end_date'] ?? null;
    $categoryIds = $data['category_ids'] ?? null;
    $isActive = isset($data['is_active']) ? 1 : 0;
    
    $stmt = $db->prepare("
        UPDATE points_campaigns SET 
            name = ?, multiplier = ?, start_date = ?, end_date = ?, 
            applicable_categories = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $name, $multiplier, $startDate, $endDate,
        $categoryIds ? json_encode($categoryIds) : null,
        $isActive, $id
    ]);
    
    return ['success' => true, 'message' => 'อัปเดตแคมเปญสำเร็จ'];
}

/**
 * Delete campaign
 */
function deleteCampaign($db, $id) {
    $stmt = $db->prepare("DELETE FROM points_campaigns WHERE id = ?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'ลบแคมเปญสำเร็จ'];
}

/**
 * Toggle campaign active status
 */
function toggleCampaign($db, $id) {
    $stmt = $db->prepare("UPDATE points_campaigns SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'เปลี่ยนสถานะสำเร็จ'];
}

/**
 * Update category bonus
 * Requirement 25.4: Category bonus configuration
 */
function updateCategoryBonus($db, $lineAccountId, $data) {
    $categoryId = intval($data['category_id'] ?? 0);
    $multiplier = floatval($data['multiplier'] ?? 1.0);
    
    if (!$categoryId) {
        return ['success' => false, 'message' => 'กรุณาเลือกหมวดหมู่'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO category_points_bonus (line_account_id, category_id, multiplier)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE multiplier = VALUES(multiplier), updated_at = NOW()
    ");
    $stmt->execute([$lineAccountId, $categoryId, $multiplier]);
    
    return ['success' => true, 'message' => 'บันทึกโบนัสหมวดหมู่สำเร็จ'];
}

/**
 * Delete category bonus
 */
function deleteCategoryBonus($db, $id) {
    $stmt = $db->prepare("DELETE FROM category_points_bonus WHERE id = ?");
    $stmt->execute([$id]);
    return ['success' => true, 'message' => 'ลบโบนัสหมวดหมู่สำเร็จ'];
}

/**
 * Update tier settings
 * Requirements 25.5, 25.8: Tier multipliers and thresholds
 */
function updateTierSettings($db, $lineAccountId, $data) {
    $tiers = $data['tiers'] ?? [];
    
    if (empty($tiers)) {
        return ['success' => false, 'message' => 'กรุณาระบุข้อมูลระดับสมาชิก'];
    }
    
    // Validate tier thresholds are in ascending order (Property 38)
    $prevPoints = -1;
    foreach ($tiers as $tier) {
        $minPoints = intval($tier['min_points'] ?? 0);
        if ($minPoints <= $prevPoints && $prevPoints >= 0) {
            return ['success' => false, 'message' => 'คะแนนขั้นต่ำต้องเรียงจากน้อยไปมาก'];
        }
        $prevPoints = $minPoints;
    }
    
    // Delete existing tiers for this account
    $stmt = $db->prepare("DELETE FROM tier_settings WHERE line_account_id = ?");
    $stmt->execute([$lineAccountId]);
    
    // Insert new tiers
    // PHASE 3: earn_multiplier and discount_percent are separate benefits now.
    $hasSplitColumns = false;
    try {
        $probe = $db->prepare('SHOW COLUMNS FROM `tier_settings` LIKE ?');
        $probe->execute(['earn_multiplier']);
        $hasSplitColumns = $probe->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Throwable $e) {
        $hasSplitColumns = false;
    }

    $stmt = $hasSplitColumns
        ? $db->prepare('INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, earn_multiplier, discount_percent, benefits) VALUES (?, ?, ?, ?, ?, ?, ?)')
        : $db->prepare('INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, benefits) VALUES (?, ?, ?, ?, ?)');
    
    foreach ($tiers as $tier) {
        $earnMultiplier = floatval($tier['earn_multiplier'] ?? $tier['multiplier'] ?? 1.0);
        $discountPercent = floatval($tier['discount_percent'] ?? 0);

        $row = [
            $lineAccountId,
            trim($tier['name'] ?? 'Tier'),
            intval($tier['min_points'] ?? 0),
            $earnMultiplier,
        ];
        if ($hasSplitColumns) {
            $row[] = $earnMultiplier;
            $row[] = $discountPercent;
        }
        $row[] = $tier['benefits'] ?? null;

        $stmt->execute($row);
    }

    return ['success' => true, 'message' => 'บันทึกระดับสมาชิกสำเร็จ'];
}
