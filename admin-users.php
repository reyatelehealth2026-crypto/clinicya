<?php
/**
 * Admin Users Management V2.0
 * จัดการผู้ดูแลระบบและสิทธิ์การเข้าถึง LINE Bot
 * รองรับ 6 roles: owner, admin, pharmacist, staff, marketing, tech
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/AdminAuth.php';
require_once __DIR__ . '/classes/LineAccountManager.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$auth = new AdminAuth($db);
$lineManager = new LineAccountManager($db);
$activityLogger = ActivityLogger::getInstance($db);

// Require super admin or admin with permission
$auth->requireLogin('auth/login.php');
$currentUser = $auth->getCurrentUser();

// Only super_admin and admin can access this page
if (!in_array($currentUser['role'], ['super_admin', 'admin'])) {
    header('Location: index.php?error=no_permission');
    exit;
}

$pageTitle = 'จัดการบุคลากร & สิทธิ์';
$error = null;
$success = null;

// Role definitions with Thai labels and colors
$roleDefinitions = [
    'super_admin' => [
        'label' => 'Owner (Super Admin)',
        'label_th' => 'เจ้าของร้าน',
        'color' => 'red',
        'icon' => 'fa-crown',
        'description' => 'เข้าถึงได้ทุก LINE Bot และจัดการผู้ดูแลได้',
        'can_create' => false // Only existing super_admin can exist
    ],
    'admin' => [
        'label' => 'Admin',
        'label_th' => 'ผู้ดูแลระบบ',
        'color' => 'blue',
        'icon' => 'fa-user-shield',
        'description' => 'จัดการระบบส่วนใหญ่ ยกเว้นการตั้งค่าระดับสูง',
        'can_create' => true
    ],
    'pharmacist' => [
        'label' => 'Pharmacist',
        'label_th' => 'เภสัชกร',
        'color' => 'green',
        'icon' => 'fa-user-md',
        'description' => 'รับเคสแชท, Video Call, อนุมัติยา, AI ช่วยเหลือ',
        'can_create' => true
    ],
    'staff' => [
        'label' => 'Staff',
        'label_th' => 'พนักงาน',
        'color' => 'gray',
        'icon' => 'fa-user',
        'description' => 'สิทธิ์จำกัด ดูข้อมูลและจัดการออเดอร์พื้นฐาน',
        'can_create' => true
    ],
    'marketing' => [
        'label' => 'Marketing',
        'label_th' => 'การตลาด',
        'color' => 'purple',
        'icon' => 'fa-bullhorn',
        'description' => 'บรอดแคสต์, Drip Campaign, Rich Menu, LIFF',
        'can_create' => true
    ],
    'tech' => [
        'label' => 'Tech',
        'label_th' => 'IT/Technical',
        'color' => 'cyan',
        'icon' => 'fa-code',
        'description' => 'ตั้งค่า API, Integrations, LINE Accounts',
        'can_create' => true
    ],
];

// Get all LINE accounts
$allBots = $lineManager->getAllAccounts();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                // Only super_admin can create new admins
                if ($currentUser['role'] !== 'super_admin') {
                    throw new Exception('คุณไม่มีสิทธิ์สร้างผู้ดูแลใหม่');
                }
                
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $displayName = trim($_POST['display_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $lineUserId = trim($_POST['line_user_id'] ?? '');
                $idCard = trim($_POST['id_card'] ?? '');
                $birthDate = trim($_POST['birth_date'] ?? '');
                $salary = floatval($_POST['salary'] ?? 0);
                $role = $_POST['role'] ?? 'staff';
                
                // Validate role
                if (!isset($roleDefinitions[$role]) || !$roleDefinitions[$role]['can_create']) {
                    throw new Exception('ไม่สามารถสร้าง role นี้ได้');
                }
                
                if (empty($username) || empty($password)) {
                    throw new Exception('กรุณากรอกชื่อผู้ใช้และรหัสผ่าน');
                }
                
                if (strlen($password) < 6) {
                    throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
                }
                
                // Validate ID card if provided
                if (!empty($idCard) && !preg_match('/^[0-9]{13}$/', $idCard)) {
                    throw new Exception('เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก');
                }
                
                $adminId = $auth->createAdmin([
                    'username' => $username,
                    'password' => $password,
                    'display_name' => $displayName ?: $username,
                    'email' => $email,
                    'phone' => $phone,
                    'line_user_id' => $lineUserId,
                    'id_card' => $idCard ?: null,
                    'birth_date' => $birthDate ?: null,
                    'salary' => $salary,
                    'role' => $role
                ]);
                
                // Grant bot access
                $botAccess = $_POST['bot_access'] ?? [];
                foreach ($botAccess as $botId) {
                    $auth->grantBotAccess($adminId, $botId, [
                        'can_view' => 1,
                        'can_edit' => isset($_POST['perm_edit'][$botId]) ? 1 : 0,
                        'can_broadcast' => isset($_POST['perm_broadcast'][$botId]) ? 1 : 0,
                        'can_manage_users' => isset($_POST['perm_users'][$botId]) ? 1 : 0,
                        'can_manage_shop' => isset($_POST['perm_shop'][$botId]) ? 1 : 0,
                        'can_view_analytics' => isset($_POST['perm_analytics'][$botId]) ? 1 : 0,
                    ]);
                }
                
                $success = "สร้างผู้ดูแล '{$username}' สำเร็จ";
                
                // Log activity
                $activityLogger->logAdmin(ActivityLogger::ACTION_CREATE, 'สร้างผู้ดูแลใหม่', [
                    'entity_type' => 'admin_user',
                    'entity_id' => $adminId,
                    'new_value' => ['username' => $username, 'role' => $role, 'display_name' => $displayName]
                ]);
                break;
                
            case 'update':
                $adminId = (int)$_POST['admin_id'];
                
                // Get target admin info
                $targetAdmin = $auth->getAdminById($adminId);
                if (!$targetAdmin) {
                    throw new Exception('ไม่พบผู้ดูแลที่ต้องการแก้ไข');
                }
                
                // Only super_admin can edit other super_admin or change roles
                if ($currentUser['role'] !== 'super_admin') {
                    if ($targetAdmin['role'] === 'super_admin') {
                        throw new Exception('คุณไม่มีสิทธิ์แก้ไข Super Admin');
                    }
                }
                
                $displayName = trim($_POST['display_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $lineUserId = trim($_POST['line_user_id'] ?? '');
                $idCard = trim($_POST['id_card'] ?? '');
                $birthDate = trim($_POST['birth_date'] ?? '');
                $salary = floatval($_POST['salary'] ?? 0);
                $role = $_POST['role'] ?? $targetAdmin['role'];
                $password = $_POST['password'] ?? '';
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                // Cannot change super_admin role
                if ($targetAdmin['role'] === 'super_admin') {
                    $role = 'super_admin';
                }
                
                // Validate ID card if provided
                if (!empty($idCard) && !preg_match('/^[0-9]{13}$/', $idCard)) {
                    throw new Exception('เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก');
                }
                
                $updateData = [
                    'display_name' => $displayName,
                    'email' => $email,
                    'phone' => $phone,
                    'line_user_id' => $lineUserId,
                    'id_card' => $idCard ?: null,
                    'birth_date' => $birthDate ?: null,
                    'salary' => $salary,
                    'role' => $role,
                    'is_active' => $isActive
                ];
                
                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
                    }
                    $updateData['password'] = $password;
                }
                
                $auth->updateAdmin($adminId, $updateData);
                
                // Update bot access - remove all first (only for non-super_admin)
                if ($role !== 'super_admin') {
                    $stmt = $db->prepare("DELETE FROM admin_bot_access WHERE admin_id = ?");
                    $stmt->execute([$adminId]);
                    
                    // Re-grant
                    $botAccess = $_POST['bot_access'] ?? [];
                    foreach ($botAccess as $botId) {
                        $auth->grantBotAccess($adminId, $botId, [
                            'can_view' => 1,
                            'can_edit' => isset($_POST['perm_edit'][$botId]) ? 1 : 0,
                            'can_broadcast' => isset($_POST['perm_broadcast'][$botId]) ? 1 : 0,
                            'can_manage_users' => isset($_POST['perm_users'][$botId]) ? 1 : 0,
                            'can_manage_shop' => isset($_POST['perm_shop'][$botId]) ? 1 : 0,
                            'can_view_analytics' => isset($_POST['perm_analytics'][$botId]) ? 1 : 0,
                        ]);
                    }
                }
                
                $success = "อัพเดทผู้ดูแลสำเร็จ";
                
                // Log activity
                $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, 'แก้ไขข้อมูลผู้ดูแล', [
                    'entity_type' => 'admin_user',
                    'entity_id' => $adminId,
                    'new_value' => ['display_name' => $displayName, 'role' => $role, 'is_active' => $isActive]
                ]);
                break;
                
            case 'delete':
                if ($currentUser['role'] !== 'super_admin') {
                    throw new Exception('คุณไม่มีสิทธิ์ลบผู้ดูแล');
                }
                
                $adminId = (int)$_POST['admin_id'];
                if ($auth->deleteAdmin($adminId)) {
                    $success = "ลบผู้ดูแลสำเร็จ";
                    
                    // Log activity
                    $activityLogger->logAdmin(ActivityLogger::ACTION_DELETE, 'ลบผู้ดูแล', [
                        'entity_type' => 'admin_user',
                        'entity_id' => $adminId
                    ]);
                } else {
                    throw new Exception('ไม่สามารถลบ Super Admin ได้');
                }
                break;
                
            case 'toggle_active':
                $adminId = (int)$_POST['admin_id'];
                $targetAdmin = $auth->getAdminById($adminId);
                
                if ($targetAdmin['role'] === 'super_admin') {
                    throw new Exception('ไม่สามารถปิดการใช้งาน Super Admin ได้');
                }
                
                $newStatus = $targetAdmin['is_active'] ? 0 : 1;
                $auth->updateAdmin($adminId, ['is_active' => $newStatus]);
                $success = $newStatus ? "เปิดใช้งานผู้ดูแลแล้ว" : "ปิดใช้งานผู้ดูแลแล้ว";
                
                // Log activity
                $activityLogger->logAdmin(ActivityLogger::ACTION_UPDATE, $newStatus ? 'เปิดใช้งานผู้ดูแล' : 'ปิดใช้งานผู้ดูแล', [
                    'entity_type' => 'admin_user',
                    'entity_id' => $adminId,
                    'new_value' => ['is_active' => $newStatus]
                ]);
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all admins
$admins = $auth->getAllAdmins();

// Get bot access for each admin
foreach ($admins as &$admin) {
    $admin['bot_access'] = $auth->getAdminBotAccess($admin['id']);
}
unset($admin); // สำคัญ! ต้อง unset reference หลัง foreach

// Group admins by role for display
$adminsByRole = [];
foreach ($admins as $admin) {
    $role = $admin['role'];
    if (!isset($adminsByRole[$role])) {
        $adminsByRole[$role] = [];
    }
    $adminsByRole[$role][] = $admin;
}

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-users-cog text-purple-500 mr-2"></i>จัดการบุคลากร & สิทธิ์
            </h1>
            <p class="text-gray-500 text-sm mt-1">จัดการผู้ดูแลระบบและกำหนดสิทธิ์การเข้าถึง</p>
        </div>
        <?php if ($currentUser['role'] === 'super_admin'): ?>
        <button onclick="showCreateModal()" class="px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl text-sm font-medium hover:from-green-600 hover:to-green-700 shadow-lg shadow-green-500/25 transition-all">
            <i class="fas fa-plus mr-2"></i>เพิ่มผู้ดูแลใหม่
        </button>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center">
        <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl flex items-center">
        <i class="fas fa-check-circle mr-3 text-green-500"></i>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
        <!-- Admin List -->
        <div class="xl:col-span-3 space-y-4">
            <?php 
            // Display order for roles
            $roleOrder = ['super_admin', 'admin', 'pharmacist', 'marketing', 'tech', 'staff'];
            foreach ($roleOrder as $role): 
                if (!isset($adminsByRole[$role]) || empty($adminsByRole[$role])) continue;
                $roleDef = $roleDefinitions[$role] ?? ['label' => $role, 'color' => 'gray', 'icon' => 'fa-user'];
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-4 py-3 bg-<?= $roleDef['color'] ?>-50 border-b border-<?= $roleDef['color'] ?>-100 flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas <?= $roleDef['icon'] ?> text-<?= $roleDef['color'] ?>-500 mr-2"></i>
                        <span class="font-semibold text-<?= $roleDef['color'] ?>-700"><?= $roleDef['label_th'] ?? $roleDef['label'] ?></span>
                        <span class="ml-2 px-2 py-0.5 bg-<?= $roleDef['color'] ?>-100 text-<?= $roleDef['color'] ?>-600 rounded-full text-xs">
                            <?= count($adminsByRole[$role]) ?> คน
                        </span>
                    </div>
                    <span class="text-xs text-<?= $roleDef['color'] ?>-600"><?= $roleDef['description'] ?? '' ?></span>
                </div>
                
                <div class="divide-y divide-gray-100">
                    <?php foreach ($adminsByRole[$role] as $admin): ?>
                    <div class="p-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center min-w-0">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-<?= $roleDef['color'] ?>-400 to-<?= $roleDef['color'] ?>-600 flex items-center justify-center text-white font-bold text-lg mr-4 flex-shrink-0 shadow-lg shadow-<?= $roleDef['color'] ?>-500/25">
                                    <?= strtoupper(substr($admin['username'], 0, 1)) ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($admin['display_name'] ?: $admin['username']) ?></span>
                                        <?php if ($admin['display_name'] && $admin['display_name'] !== $admin['username']): ?>
                                        <span class="text-xs text-gray-400">@<?= htmlspecialchars($admin['username']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!$admin['is_active']): ?>
                                        <span class="px-2 py-0.5 bg-red-100 text-red-600 rounded-full text-xs font-medium">
                                            <i class="fas fa-ban mr-1"></i>ปิดใช้งาน
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                        <?php if (!empty($admin['email'])): ?>
                                        <span><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($admin['email']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($admin['phone'])): ?>
                                        <span><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($admin['phone']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($admin['line_user_id'])): ?>
                                        <span class="text-green-600"><i class="fab fa-line mr-1"></i>เชื่อมต่อแล้ว</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($role !== 'super_admin'): ?>
                                        <?php if (!empty($admin['bot_access'])): ?>
                                        <div class="flex flex-wrap gap-1 mt-2">
                                            <?php foreach ($admin['bot_access'] as $access): ?>
                                            <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">
                                                <i class="fab fa-line mr-1"></i><?= htmlspecialchars($access['bot_name']) ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-xs text-orange-500 mt-2">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>ยังไม่ได้กำหนดสิทธิ์ Bot
                                        </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <div class="text-xs text-purple-600 mt-2">
                                        <i class="fas fa-infinity mr-1"></i>เข้าถึงได้ทุก Bot
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2 flex-shrink-0 ml-4">
                                <?php if ($admin['last_login']): ?>
                                <span class="text-xs text-gray-400 hidden sm:inline">
                                    <i class="fas fa-clock mr-1"></i><?= date('d/m H:i', strtotime($admin['last_login'])) ?>
                                </span>
                                <?php endif; ?>
                                
                                <button onclick="editAdmin(<?= htmlspecialchars(json_encode($admin)) ?>)" 
                                        class="p-2 text-blue-500 hover:bg-blue-50 rounded-lg transition-colors" title="แก้ไข">
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <?php if ($role !== 'super_admin' && $currentUser['role'] === 'super_admin'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                                    <button type="submit" class="p-2 <?= $admin['is_active'] ? 'text-orange-500 hover:bg-orange-50' : 'text-green-500 hover:bg-green-50' ?> rounded-lg transition-colors" 
                                            title="<?= $admin['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>">
                                        <i class="fas <?= $admin['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i>
                                    </button>
                                </form>
                                
                                <form method="POST" class="inline" onsubmit="return confirm('ลบผู้ดูแล <?= htmlspecialchars($admin['display_name'] ?: $admin['username']) ?>?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                                    <button type="submit" class="p-2 text-red-500 hover:bg-red-50 rounded-lg transition-colors" title="ลบ">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Sidebar Info -->
        <div class="space-y-4">
            <!-- Role Legend -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h4 class="font-semibold mb-4 flex items-center">
                    <i class="fas fa-shield-alt text-purple-500 mr-2"></i>ระดับสิทธิ์
                </h4>
                <div class="space-y-3">
                    <?php foreach ($roleDefinitions as $roleKey => $roleDef): ?>
                    <div class="p-3 bg-<?= $roleDef['color'] ?>-50 rounded-lg border border-<?= $roleDef['color'] ?>-100">
                        <div class="flex items-center">
                            <i class="fas <?= $roleDef['icon'] ?> text-<?= $roleDef['color'] ?>-500 mr-2"></i>
                            <span class="font-medium text-<?= $roleDef['color'] ?>-700 text-sm"><?= $roleDef['label_th'] ?></span>
                        </div>
                        <div class="text-<?= $roleDef['color'] ?>-600 text-xs mt-1"><?= $roleDef['description'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Bot Permissions Legend -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h4 class="font-semibold mb-4 flex items-center">
                    <i class="fas fa-key text-green-500 mr-2"></i>สิทธิ์ต่อ Bot
                </h4>
                <div class="space-y-2 text-sm text-gray-600">
                    <div class="flex items-center"><i class="fas fa-eye text-blue-500 w-5 mr-2"></i>ดูข้อมูล</div>
                    <div class="flex items-center"><i class="fas fa-edit text-yellow-500 w-5 mr-2"></i>แก้ไขข้อมูล</div>
                    <div class="flex items-center"><i class="fas fa-paper-plane text-purple-500 w-5 mr-2"></i>ส่ง Broadcast</div>
                    <div class="flex items-center"><i class="fas fa-users text-green-500 w-5 mr-2"></i>จัดการผู้ใช้</div>
                    <div class="flex items-center"><i class="fas fa-store text-orange-500 w-5 mr-2"></i>จัดการร้านค้า</div>
                    <div class="flex items-center"><i class="fas fa-chart-bar text-pink-500 w-5 mr-2"></i>ดู Analytics</div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h4 class="font-semibold mb-4 flex items-center">
                    <i class="fas fa-chart-pie text-blue-500 mr-2"></i>สรุป
                </h4>
                <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">ผู้ดูแลทั้งหมด</span>
                        <span class="font-semibold"><?= count($admins) ?> คน</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">ใช้งานอยู่</span>
                        <span class="font-semibold text-green-600"><?= count(array_filter($admins, fn($a) => $a['is_active'])) ?> คน</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">LINE Bots</span>
                        <span class="font-semibold"><?= count($allBots) ?> บัญชี</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="adminModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center bg-gradient-to-r from-purple-500 to-purple-600 text-white">
            <h3 class="font-semibold text-lg" id="modalTitle">
                <i class="fas fa-user-plus mr-2"></i>เพิ่มผู้ดูแลใหม่
            </h3>
            <button onclick="closeModal()" class="text-white/80 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" id="adminForm" class="overflow-y-auto max-h-[calc(90vh-140px)]">
            <div class="p-6 space-y-5">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="admin_id" id="adminId">
                
                <!-- Basic Info -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            ชื่อผู้ใช้ <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="username" id="username" required 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="username">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            รหัสผ่าน <span class="text-red-500" id="pwdReq">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="password" id="password" 
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all pr-10"
                                   placeholder="••••••••">
                            <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fas fa-eye" id="pwdIcon"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1" id="pwdHint">อย่างน้อย 6 ตัวอักษร</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อที่แสดง</label>
                        <input type="text" name="display_name" id="displayName" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="ชื่อ-นามสกุล">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">อีเมล</label>
                        <input type="email" name="email" id="email" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="email@example.com">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทร</label>
                        <input type="tel" name="phone" id="phone" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="08x-xxx-xxxx">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">เลขบัตรประชาชน</label>
                        <input type="text" name="id_card" id="idCard" maxlength="13" pattern="[0-9]{13}"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="เลข 13 หลัก">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">วันเดือนปีเกิด</label>
                        <input type="date" name="birth_date" id="birthDate" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">เงินเดือน (บาท)</label>
                        <input type="number" name="salary" id="salary" min="0" step="0.01"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="0.00">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>               <label class="block text-sm font-medium text-gray-700 mb-1">
                            LINE User ID
                            <button type="button" onclick="showLineIdHelp()" class="text-blue-500 ml-1">
                                <i class="fas fa-question-circle"></i>
                            </button>
                        </label>
                        <input type="text" name="line_user_id" id="lineUserId" 
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="Uxxxxxxxxxx...">
                    </div>
                </div>
                
                <!-- Role Selection -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ระดับสิทธิ์</label>
                        <select name="role" id="role" onchange="onRoleChange()"
                                class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                            <?php foreach ($roleDefinitions as $roleKey => $roleDef): ?>
                                <?php if ($roleDef['can_create']): ?>
                                <option value="<?= $roleKey ?>"><?= $roleDef['label_th'] ?> (<?= $roleDef['label'] ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1" id="roleDescription"></p>
                    </div>
                    <div class="flex items-center">
                        <label class="flex items-center cursor-pointer mt-6">
                            <input type="checkbox" name="is_active" id="isActive" checked 
                                   class="w-5 h-5 text-purple-500 border-gray-300 rounded focus:ring-purple-500">
                            <span class="ml-2 text-sm text-gray-700">เปิดใช้งาน</span>
                        </label>
                    </div>
                </div>
                
                <!-- Bot Access -->
                <div id="botAccessSection">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fab fa-line text-green-500 mr-1"></i>สิทธิ์เข้าถึง LINE Bot
                    </label>
                    <div class="border border-gray-200 rounded-xl divide-y max-h-60 overflow-y-auto">
                        <?php if (empty($allBots)): ?>
                        <div class="p-4 text-center text-gray-500">
                            <i class="fas fa-inbox text-3xl mb-2"></i>
                            <p>ยังไม่มี LINE Bot</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($allBots as $bot): ?>
                        <div class="p-3 hover:bg-gray-50 transition-colors">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="bot_access[]" value="<?= $bot['id'] ?>" 
                                       class="w-5 h-5 text-green-500 border-gray-300 rounded focus:ring-green-500 bot-checkbox" 
                                       data-bot="<?= $bot['id'] ?>" onchange="toggleBotPerms(<?= $bot['id'] ?>)">
                                <div class="ml-3">
                                    <span class="font-medium text-gray-800"><?= htmlspecialchars($bot['name']) ?></span>
                                    <?php if (!empty($bot['basic_id'])): ?>
                                    <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars($bot['basic_id']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </label>
                            <div class="ml-8 mt-2 flex flex-wrap gap-3 bot-perms hidden" id="perms_<?= $bot['id'] ?>">
                                <label class="flex items-center text-xs cursor-pointer">
                                    <input type="checkbox" name="perm_edit[<?= $bot['id'] ?>]" checked class="w-4 h-4 text-yellow-500 border-gray-300 rounded mr-1">
                                    <i class="fas fa-edit text-yellow-500 mr-1"></i>แก้ไข
                                </label>
                                <label class="flex items-center text-xs cursor-pointer">
                                    <input type="checkbox" name="perm_broadcast[<?= $bot['id'] ?>]" checked class="w-4 h-4 text-purple-500 border-gray-300 rounded mr-1">
                                    <i class="fas fa-paper-plane text-purple-500 mr-1"></i>Broadcast
                                </label>
                                <label class="flex items-center text-xs cursor-pointer">
                                    <input type="checkbox" name="perm_users[<?= $bot['id'] ?>]" checked class="w-4 h-4 text-green-500 border-gray-300 rounded mr-1">
                                    <i class="fas fa-users text-green-500 mr-1"></i>ผู้ใช้
                                </label>
                                <label class="flex items-center text-xs cursor-pointer">
                                    <input type="checkbox" name="perm_shop[<?= $bot['id'] ?>]" checked class="w-4 h-4 text-orange-500 border-gray-300 rounded mr-1">
                                    <i class="fas fa-store text-orange-500 mr-1"></i>ร้านค้า
                                </label>
                                <label class="flex items-center text-xs cursor-pointer">
                                    <input type="checkbox" name="perm_analytics[<?= $bot['id'] ?>]" checked class="w-4 h-4 text-pink-500 border-gray-300 rounded mr-1">
                                    <i class="fas fa-chart-bar text-pink-500 mr-1"></i>Analytics
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="p-4 bg-gray-50 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal()" 
                        class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-100 transition-colors font-medium">
                    ยกเลิก
                </button>
                <button type="submit" 
                        class="px-5 py-2.5 bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-xl hover:from-purple-600 hover:to-purple-700 transition-all font-medium shadow-lg shadow-purple-500/25">
                    <i class="fas fa-save mr-2"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Role definitions for JS
const roleDefinitions = <?= json_encode($roleDefinitions) ?>;

function showCreateModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus mr-2"></i>เพิ่มผู้ดูแลใหม่';
    document.getElementById('formAction').value = 'create';
    document.getElementById('adminId').value = '';
    document.getElementById('username').value = '';
    document.getElementById('username').disabled = false;
    document.getElementById('password').value = '';
    document.getElementById('password').required = true;
    document.getElementById('pwdReq').style.display = '';
    document.getElementById('pwdHint').textContent = 'อย่างน้อย 6 ตัวอักษร';
    document.getElementById('displayName').value = '';
    document.getElementById('email').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('lineUserId').value = '';
    document.getElementById('role').value = 'admin';
    document.getElementById('isActive').checked = true;
    
    // Clear bot access
    document.querySelectorAll('.bot-checkbox').forEach(cb => {
        cb.checked = false;
        toggleBotPerms(cb.dataset.bot);
    });
    
    onRoleChange();
    openModal();
}

function editAdmin(admin) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit mr-2"></i>แก้ไขผู้ดูแล';
    document.getElementById('formAction').value = 'update';
    document.getElementById('adminId').value = admin.id;
    document.getElementById('username').value = admin.username;
    document.getElementById('username').disabled = true;
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('pwdReq').style.display = 'none';
    document.getElementById('pwdHint').textContent = 'เว้นว่างถ้าไม่ต้องการเปลี่ยน';
    document.getElementById('displayName').value = admin.display_name || '';
    document.getElementById('email').value = admin.email || '';
    document.getElementById('phone').value = admin.phone || '';
    document.getElementById('lineUserId').value = admin.line_user_id || '';
    document.getElementById('role').value = admin.role;
    document.getElementById('isActive').checked = admin.is_active == 1;
    
    // Disable role change for super_admin
    document.getElementById('role').disabled = admin.role === 'super_admin';
    
    // Set bot access
    const accessBots = admin.bot_access.map(a => a.line_account_id);
    document.querySelectorAll('.bot-checkbox').forEach(cb => {
        const botId = parseInt(cb.dataset.bot);
        cb.checked = accessBots.includes(botId);
        toggleBotPerms(botId);
        
        // Set permissions
        const access = admin.bot_access.find(a => a.line_account_id === botId);
        if (access) {
            const permsDiv = document.getElementById('perms_' + botId);
            if (permsDiv) {
                const editPerm = permsDiv.querySelector('[name="perm_edit['+botId+']"]');
                const broadcastPerm = permsDiv.querySelector('[name="perm_broadcast['+botId+']"]');
                const usersPerm = permsDiv.querySelector('[name="perm_users['+botId+']"]');
                const shopPerm = permsDiv.querySelector('[name="perm_shop['+botId+']"]');
                const analyticsPerm = permsDiv.querySelector('[name="perm_analytics['+botId+']"]');
                
                if (editPerm) editPerm.checked = access.can_edit == 1;
                if (broadcastPerm) broadcastPerm.checked = access.can_broadcast == 1;
                if (usersPerm) usersPerm.checked = access.can_manage_users == 1;
                if (shopPerm) shopPerm.checked = access.can_manage_shop == 1;
                if (analyticsPerm) analyticsPerm.checked = access.can_view_analytics == 1;
            }
        }
    });
    
    onRoleChange();
    openModal();
}

function openModal() {
    document.getElementById('adminModal').classList.remove('hidden');
    document.getElementById('adminModal').classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('adminModal').classList.add('hidden');
    document.getElementById('adminModal').classList.remove('flex');
    document.body.style.overflow = '';
    document.getElementById('role').disabled = false;
}

function toggleBotPerms(botId) {
    const cb = document.querySelector('.bot-checkbox[data-bot="'+botId+'"]');
    const perms = document.getElementById('perms_' + botId);
    if (cb && perms) {
        if (cb.checked) {
            perms.classList.remove('hidden');
        } else {
            perms.classList.add('hidden');
        }
    }
}

function onRoleChange() {
    const role = document.getElementById('role').value;
    const roleDef = roleDefinitions[role];
    const descEl = document.getElementById('roleDescription');
    const botSection = document.getElementById('botAccessSection');
    
    if (roleDef) {
        descEl.textContent = roleDef.description || '';
    }
    
    // Super admin doesn't need bot access selection
    if (role === 'super_admin') {
        botSection.style.display = 'none';
    } else {
        botSection.style.display = 'block';
    }
}

function togglePassword() {
    const pwd = document.getElementById('password');
    const icon = document.getElementById('pwdIcon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        pwd.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function showLineIdHelp() {
    alert('วิธีหา LINE User ID:\n\n1. ไปที่หน้า Inbox หรือ Users\n2. คลิกที่ผู้ใช้ที่ต้องการ\n3. LINE User ID จะขึ้นต้นด้วย U ตามด้วยตัวอักษร 32 ตัว\n   เช่น Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n\nหมายเหตุ: ผู้ใช้ต้องทักมาที่ LINE OA ก่อนจึงจะมี User ID');
}

// Close modal on outside click
document.getElementById('adminModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// Initialize role description
onRoleChange();
</script>

<?php require_once 'includes/footer.php'; ?>
