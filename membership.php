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
                    'points_required' => (int)($_POST['points_required'] ?? 0),
                    'reward_type' => $_POST['reward_type'] ?? 'gift',
                    'reward_value' => trim($_POST['reward_value'] ?? ''),
                    'stock' => (int)($_POST['stock'] ?? -1),
                    'max_per_user' => (int)($_POST['max_per_user'] ?? 0),
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
                $id = (int)($_POST['id'] ?? 0);
                $data = [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'points_required' => (int)($_POST['points_required'] ?? 0),
                    'reward_type' => $_POST['reward_type'] ?? 'gift',
                    'reward_value' => trim($_POST['reward_value'] ?? ''),
                    'stock' => (int)($_POST['stock'] ?? -1),
                    'max_per_user' => (int)($_POST['max_per_user'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'image_url' => trim($_POST['image_url'] ?? '')
                ];
                
                $loyalty->updateReward($id, $data);
                echo json_encode(['success' => true, 'message' => 'อัปเดตสำเร็จ']);
                exit;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
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
                $id = (int)($_POST['id'] ?? 0);
                $reward = $loyalty->getReward($id);
                if ($reward) {
                    $loyalty->updateReward($id, ['is_active' => $reward['is_active'] ? 0 : 1]);
                    echo json_encode(['success' => true, 'is_active' => !$reward['is_active']]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'ไม่พบรางวัล']);
                }
                exit;

            case 'approve_redemption':
                $redemptionId = (int)($_POST['redemption_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $loyalty->updateRedemptionStatus($redemptionId, 'approved', $adminId, $notes);
                // Send notification (defined later in the file)
                echo json_encode(['success' => true, 'message' => 'อนุมัติสำเร็จ']);
                exit;
                
            case 'deliver_redemption':
                $redemptionId = (int)($_POST['redemption_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $loyalty->updateRedemptionStatus($redemptionId, 'delivered', $adminId, $notes);
                echo json_encode(['success' => true, 'message' => 'บันทึกการส่งมอบสำเร็จ']);
                exit;
                
            case 'cancel_redemption':
                $redemptionId = (int)($_POST['redemption_id'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $db->prepare("SELECT * FROM reward_redemptions WHERE id = ?");
                $stmt->execute([$redemptionId]);
                $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($redemption && $redemption['status'] !== 'delivered') {
                    $loyalty->addPoints($redemption['user_id'], $redemption['points_used'], 'refund', $redemptionId, 'คืนแต้มจากการยกเลิก');
                    $stmt = $db->prepare("UPDATE rewards SET stock = stock + 1 WHERE id = ? AND stock >= 0");
                    $stmt->execute([$redemption['reward_id']]);
                    $loyalty->updateRedemptionStatus($redemptionId, 'cancelled', $adminId, $notes);
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
