<?php
/**
 * Membership Management - Tab-based Consolidated Page
 * รวมหน้า members.php, admin-rewards.php, admin-points-settings.php
 * 
 * @package FileConsolidation
 * @version 1.0.0
 * 
 * Requirements: 19.1, 19.2, 19.3, 19.4
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/components/tabs.php';

// Initialize database and session variables
$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

// Initialize LoyaltyPoints class if available
$loyalty = null;
try {
    require_once __DIR__ . '/classes/LoyaltyPoints.php';
    $loyalty = new LoyaltyPoints($db, $lineAccountId);
} catch (Exception $e) {
    // LoyaltyPoints class not available
}

// Include reward notification functions (just the function, no display logic)
require_once __DIR__ . '/includes/functions/reward_notifications.php';

// Handle AJAX requests BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reward_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['reward_action'];

    try {
        switch ($action) {
            case 'create':
                $data = [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'points_required' => (int) ($_POST['points_required'] ?? 0),
                    'reward_type' => $_POST['reward_type'] ?? 'gift',
                    'reward_value' => trim($_POST['reward_value'] ?? ''),
                    'stock' => (int) ($_POST['stock'] ?? -1),
                    'max_per_user' => (int) ($_POST['max_per_user'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'image_url' => trim($_POST['image_url'] ?? ''),
                    'terms' => trim($_POST['terms'] ?? '')
                ];

                if (empty($data['name']) || $data['points_required'] <= 0) {
                    echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบ']);
                    exit;
                }

                if (!empty($_POST['valid_from'])) {
                    $data['start_date'] = $_POST['valid_from'];
                }
                if (!empty($_POST['valid_until'])) {
                    $data['end_date'] = $_POST['valid_until'];
                }

                $id = $loyalty->createReward($data);
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'เพิ่มรางวัลสำเร็จ']);
                exit;

            case 'update':
                $id = (int) ($_POST['id'] ?? 0);
                $data = [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'points_required' => (int) ($_POST['points_required'] ?? 0),
                    'reward_type' => $_POST['reward_type'] ?? 'gift',
                    'reward_value' => trim($_POST['reward_value'] ?? ''),
                    'stock' => (int) ($_POST['stock'] ?? -1),
                    'max_per_user' => (int) ($_POST['max_per_user'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'image_url' => trim($_POST['image_url'] ?? '')
                ];

                $loyalty->updateReward($id, $data);
                echo json_encode(['success' => true, 'message' => 'อัปเดตสำเร็จ']);
                exit;

            case 'delete':
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $db->prepare("SELECT COUNT(*) FROM reward_redemptions WHERE reward_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    $loyalty->updateReward($id, ['is_active' => 0]);
                    echo json_encode(['success' => true, 'message' => 'ปิดใช้งานรางวัลแล้ว (มีประวัติการแลก)']);
                } else {
                    $loyalty->deleteReward($id);
                    echo json_encode(['success' => true, 'message' => 'ลบสำเร็จ']);
                }
                exit;

            case 'toggle':
                $id = (int) ($_POST['id'] ?? 0);
                $reward = $loyalty->getReward($id);
                if ($reward) {
                    $loyalty->updateReward($id, ['is_active' => $reward['is_active'] ? 0 : 1]);
                    echo json_encode(['success' => true, 'is_active' => !$reward['is_active']]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'ไม่พบรางวัล']);
                }
                exit;

            case 'approve_redemption':
                $redemptionId = (int) ($_POST['redemption_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');

                // Get redemption details for notification
                $stmt = $db->prepare("
                    SELECT rr.*, r.name as reward_name, u.line_user_id, u.display_name
                    FROM reward_redemptions rr
                    JOIN rewards r ON rr.reward_id = r.id
                    JOIN users u ON rr.user_id = u.id
                    WHERE rr.id = ?
                ");
                $stmt->execute([$redemptionId]);
                $redemption = $stmt->fetch(PDO::FETCH_ASSOC);

                $loyalty->updateRedemptionStatus($redemptionId, 'approved', $adminId, $notes);

                // Send LINE notification
                if ($redemption) {
                    sendRedemptionNotification($db, $lineAccountId, $redemption, 'approved');
                }

                echo json_encode(['success' => true, 'message' => 'อนุมัติสำเร็จ']);
                exit;

            case 'deliver_redemption':
                $redemptionId = (int) ($_POST['redemption_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');

                // Get redemption details for notification
                $stmt = $db->prepare("
                    SELECT rr.*, r.name as reward_name, u.line_user_id, u.display_name
                    FROM reward_redemptions rr
                    JOIN rewards r ON rr.reward_id = r.id
                    JOIN users u ON rr.user_id = u.id
                    WHERE rr.id = ?
                ");
                $stmt->execute([$redemptionId]);
                $redemption = $stmt->fetch(PDO::FETCH_ASSOC);

                $loyalty->updateRedemptionStatus($redemptionId, 'delivered', $adminId, $notes);

                // Send LINE notification
                if ($redemption) {
                    sendRedemptionNotification($db, $lineAccountId, $redemption, 'delivered');
                }

                echo json_encode(['success' => true, 'message' => 'บันทึกการส่งมอบสำเร็จ']);
                exit;

            case 'cancel_redemption':
                $redemptionId = (int) ($_POST['redemption_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');

                // Get redemption details with reward and user info
                $stmt = $db->prepare("
                    SELECT rr.*, r.name as reward_name, u.line_user_id, u.display_name
                    FROM reward_redemptions rr
                    JOIN rewards r ON rr.reward_id = r.id
                    JOIN users u ON rr.user_id = u.id
                    WHERE rr.id = ?
                ");
                $stmt->execute([$redemptionId]);
                $redemption = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($redemption && $redemption['status'] !== 'delivered') {
                    $loyalty->addPoints($redemption['user_id'], $redemption['points_used'], 'refund', $redemptionId, 'คืนแต้มจากการยกเลิก');
                    $stmt = $db->prepare("UPDATE rewards SET stock = stock + 1 WHERE id = ? AND stock >= 0");
                    $stmt->execute([$redemption['reward_id']]);
                    $loyalty->updateRedemptionStatus($redemptionId, 'cancelled', $adminId, $notes);

                    // Send LINE notification
                    sendRedemptionNotification($db, $lineAccountId, $redemption, 'cancelled');

                    echo json_encode(['success' => true, 'message' => 'ยกเลิกและคืนแต้มสำเร็จ']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'ไม่สามารถยกเลิกได้']);
                }
                exit;
        }
    } catch (Exception $e) {
        error_log("Reward action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle settings AJAX requests BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['settings_action'];

    try {
        switch ($action) {
            case 'update_rules':
                $data = [
                    'points_per_baht' => floatval($_POST['points_per_baht'] ?? 0.001),
                    'min_order_for_points' => floatval($_POST['min_order_for_points'] ?? 0),
                    'points_expiry_days' => intval($_POST['points_expiry_days'] ?? 365),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];
                $loyalty->updateSettings($data);
                echo json_encode(['success' => true, 'message' => 'บันทึกการตั้งค่าสำเร็จ']);
                exit;

            case 'create_campaign':
                $name = trim($_POST['name'] ?? '');
                $multiplier = floatval($_POST['multiplier'] ?? 2.0);
                $startDate = $_POST['start_date'] ?? date('Y-m-d');
                $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                if (empty($name)) {
                    echo json_encode(['success' => false, 'message' => 'กรุณาระบุชื่อแคมเปญ']);
                    exit;
                }
                $stmt = $db->prepare("INSERT INTO points_campaigns (line_account_id, name, multiplier, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$lineAccountId, $name, $multiplier, $startDate, $endDate, $isActive]);
                echo json_encode(['success' => true, 'message' => 'สร้างแคมเปญสำเร็จ', 'id' => $db->lastInsertId()]);
                exit;

            case 'update_campaign':
                $id = intval($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'Invalid campaign ID']);
                    exit;
                }
                $stmt = $db->prepare("UPDATE points_campaigns SET name = ?, multiplier = ?, start_date = ?, end_date = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([trim($_POST['name'] ?? ''), floatval($_POST['multiplier'] ?? 2.0), $_POST['start_date'] ?? null, $_POST['end_date'] ?? null, isset($_POST['is_active']) ? 1 : 0, $id]);
                echo json_encode(['success' => true, 'message' => 'อัปเดตแคมเปญสำเร็จ']);
                exit;

            case 'delete_campaign':
                $id = intval($_POST['id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM points_campaigns WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'ลบแคมเปญสำเร็จ']);
                exit;

            case 'toggle_campaign':
                $id = intval($_POST['id'] ?? 0);
                $stmt = $db->prepare("UPDATE points_campaigns SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'เปลี่ยนสถานะสำเร็จ']);
                exit;

            case 'save_category_bonus':
                $categoryId = intval($_POST['category_id'] ?? 0);
                $multiplier = floatval($_POST['multiplier'] ?? 1.0);
                if (!$categoryId) {
                    echo json_encode(['success' => false, 'message' => 'กรุณาเลือกหมวดหมู่']);
                    exit;
                }
                $stmt = $db->prepare("INSERT INTO category_points_bonus (line_account_id, category_id, multiplier) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE multiplier = VALUES(multiplier), updated_at = NOW()");
                $stmt->execute([$lineAccountId, $categoryId, $multiplier]);
                echo json_encode(['success' => true, 'message' => 'บันทึกโบนัสหมวดหมู่สำเร็จ']);
                exit;

            case 'delete_category_bonus':
                $id = intval($_POST['id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM category_points_bonus WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'ลบโบนัสหมวดหมู่สำเร็จ']);
                exit;

            case 'save_tier_settings':
                $tierNames = $_POST['tier_name'] ?? [];
                $tierPoints = $_POST['tier_points'] ?? [];
                $tierMultipliers = $_POST['tier_multiplier'] ?? [];
                $tierColors = $_POST['tier_color'] ?? [];
                if (empty($tierNames)) {
                    echo json_encode(['success' => false, 'message' => 'กรุณาระบุข้อมูลระดับสมาชิก']);
                    exit;
                }
                $prevPoints = -1;
                for ($i = 0; $i < count($tierPoints); $i++) {
                    $minPoints = intval($tierPoints[$i] ?? 0);
                    if ($minPoints <= $prevPoints && $prevPoints >= 0) {
                        echo json_encode(['success' => false, 'message' => 'คะแนนขั้นต่ำต้องเรียงจากน้อยไปมาก']);
                        exit;
                    }
                    $prevPoints = $minPoints;
                }
                $stmt = $db->prepare("DELETE FROM tier_settings WHERE line_account_id = ?");
                $stmt->execute([$lineAccountId]);
                $defaultColors = ['#9CA3AF', '#F59E0B', '#6366F1', '#10B981', '#EF4444', '#8B5CF6'];
                $stmt = $db->prepare("INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, badge_color) VALUES (?, ?, ?, ?, ?)");
                for ($i = 0; $i < count($tierNames); $i++) {
                    if (!empty($tierNames[$i])) {
                        // Use color from form, or fallback to default
                        $badgeColor = !empty($tierColors[$i]) ? $tierColors[$i] : $defaultColors[$i % count($defaultColors)];
                        $stmt->execute([$lineAccountId, trim($tierNames[$i]), intval($tierPoints[$i] ?? 0), floatval($tierMultipliers[$i] ?? 1.0), $badgeColor]);
                    }
                }
                // Clear tier cache
                require_once __DIR__ . '/classes/TierService.php';
                TierService::clearCache();
                echo json_encode(['success' => true, 'message' => 'บันทึกระดับสมาชิกสำเร็จ']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Define tabs
$tabs = [
    'members' => [
        'label' => 'สมาชิก',
        'icon' => 'fas fa-users'
    ],
    'rewards' => [
        'label' => 'รางวัลแลกแต้ม',
        'icon' => 'fas fa-gift'
    ],
    'settings' => [
        'label' => 'ตั้งค่าแต้ม',
        'icon' => 'fas fa-cog'
    ]
];

// Get active tab
$activeTab = getActiveTab($tabs, 'members');
$pageTitle = 'จัดการสมาชิก';

// Set page title based on active tab
switch ($activeTab) {
    case 'rewards':
        $pageTitle = 'รางวัลแลกแต้ม';
        break;
    case 'settings':
        $pageTitle = 'ตั้งค่าระบบแต้ม';
        break;
    default:
        $pageTitle = 'จัดการสมาชิก';
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">
        <i class="fas fa-id-card text-purple-600 mr-2"></i><?= $pageTitle ?>
    </h1>
    <p class="text-gray-500 mt-1">จัดการสมาชิก รางวัล และระบบแต้มสะสม</p>
</div>

<?php
// Output tab styles
echo getTabsStyles();

// Render tabs
echo renderTabs($tabs, $activeTab, ['style' => 'pills']);
?>

<!-- Tab Content -->
<div class="tab-panel">
    <?php
    // Load content based on active tab
    switch ($activeTab) {
        case 'rewards':
            if ($loyalty) {
                include __DIR__ . '/includes/membership/rewards.php';
            } else {
                echo '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">';
                echo '<h2 class="text-xl font-bold text-yellow-800 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>ไม่พบ LoyaltyPoints Class</h2>';
                echo '<p class="text-yellow-700">กรุณาตรวจสอบว่าไฟล์ classes/LoyaltyPoints.php มีอยู่และถูกต้อง</p>';
                echo '</div>';
            }
            break;

        case 'settings':
            if ($loyalty) {
                include __DIR__ . '/includes/membership/settings.php';
            } else {
                echo '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">';
                echo '<h2 class="text-xl font-bold text-yellow-800 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>ไม่พบ LoyaltyPoints Class</h2>';
                echo '<p class="text-yellow-700">กรุณาตรวจสอบว่าไฟล์ classes/LoyaltyPoints.php มีอยู่และถูกต้อง</p>';
                echo '</div>';
            }
            break;

        default: // members
            include __DIR__ . '/includes/membership/members.php';
            break;
    }
    ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>