<?php
/**
 * Admin Users Management
 * เธเธฑเธ”เธเธฒเธฃเธเธนเนเธ”เธนเนเธฅเธฃเธฐเธเธเนเธฅเธฐเธชเธดเธ—เธเธดเนเธเธฒเธฃเน€เธเนเธฒเธ–เธถเธ LINE Bot
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/AdminAuth.php';
require_once __DIR__ . '/classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$auth = new AdminAuth($db);
$lineManager = new LineAccountManager($db);

// Require super admin
$auth->requireSuperAdmin('index.php');

$pageTitle = 'เธเธฑเธ”เธเธฒเธฃเธเธนเนเธ”เธนเนเธฅเธฃเธฐเธเธ';
$error = null;
$success = null;

// Get all LINE accounts
$allBots = $lineManager->getAllAccounts();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $displayName = trim($_POST['display_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $lineUserId = trim($_POST['line_user_id'] ?? '');
                $role = $_POST['role'] ?? 'admin';
                
                if (empty($username) || empty($password)) {
                    throw new Exception('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเธทเนเธญเธเธนเนเนเธเนเนเธฅเธฐเธฃเธซเธฑเธชเธเนเธฒเธ');
                }
                
                $adminId = $auth->createAdmin([
                    'username' => $username,
                    'password' => $password,
                    'display_name' => $displayName ?: $username,
                    'email' => $email,
                    'phone' => $phone,
                    'line_user_id' => $lineUserId,
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
                
                $success = "เธชเธฃเนเธฒเธเธเธนเนเธ”เธนเนเธฅ '{$username}' เธชเธณเน€เธฃเนเธ";
                break;
                
            case 'update':
                $adminId = (int)$_POST['admin_id'];
                $displayName = trim($_POST['display_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $lineUserId = trim($_POST['line_user_id'] ?? '');
                $role = $_POST['role'] ?? 'admin';
                $password = $_POST['password'] ?? '';
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                $updateData = [
                    'display_name' => $displayName,
                    'email' => $email,
                    'phone' => $phone,
                    'line_user_id' => $lineUserId,
                    'role' => $role,
                    'is_active' => $isActive
                ];
                
                if (!empty($password)) {
                    $updateData['password'] = $password;
                }
                
                $auth->updateAdmin($adminId, $updateData);
                
                // Update bot access - remove all first
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
                
                $success = "เธญเธฑเธเน€เธ”เธ—เธเธนเนเธ”เธนเนเธฅเธชเธณเน€เธฃเนเธ";
                break;
                
            case 'delete':
                $adminId = (int)$_POST['admin_id'];
                if ($auth->deleteAdmin($adminId)) {
                    $success = "เธฅเธเธเธนเนเธ”เธนเนเธฅเธชเธณเน€เธฃเนเธ";
                } else {
                    throw new Exception('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธ Super Admin เนเธ”เน');
                }
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

require_once 'includes/header.php';
?>

<?php if ($error): ?>
<div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Admin List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold"><i class="fas fa-users-cog text-purple-500 mr-2"></i>เธฃเธฒเธขเธเธทเนเธญเธเธนเนเธ”เธนเนเธฅ</h3>
                <button onclick="showCreateModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg text-sm hover:bg-green-600">
                    <i class="fas fa-plus mr-1"></i>เน€เธเธดเนเธกเธเธนเนเธ”เธนเนเธฅ
                </button>
            </div>
            
            <div class="divide-y">
                <?php foreach ($admins as $admin): ?>
                <div class="p-4 hover:bg-gray-50">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white font-bold text-lg mr-4">
                                <?= strtoupper(substr($admin['username'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium"><?= htmlspecialchars($admin['display_name'] ?: $admin['username']) ?></span>
                                    <?php if ($admin['display_name'] && $admin['display_name'] !== $admin['username']): ?>
                                    <span class="text-xs text-gray-400">(@<?= htmlspecialchars($admin['username']) ?>)</span>
                                    <?php endif; ?>
                                    <?php if ($admin['role'] === 'super_admin'): ?>
                                    <span class="px-2 py-0.5 bg-red-100 text-red-600 rounded text-xs">Super Admin</span>
                                    <?php elseif ($admin['role'] === 'admin'): ?>
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-600 rounded text-xs">Admin</span>
                                    <?php elseif ($admin['role'] === 'pharmacist'): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-600 rounded text-xs">เน€เธ เธชเธฑเธเธเธฃ</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs">Staff</span>
                                    <?php endif; ?>
                                    <?php if (!$admin['is_active']): ?>
                                    <span class="px-2 py-0.5 bg-red-100 text-red-600 rounded text-xs">เธเธดเธ”เนเธเนเธเธฒเธ</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($admin['line_user_id'])): ?>
                                <div class="text-xs text-green-600 mt-1"><i class="fab fa-line mr-1"></i>LINE เน€เธเธทเนเธญเธกเธ•เนเธญเนเธฅเนเธง</div>
                                <?php endif; ?>
                                <?php if ($admin['role'] !== 'super_admin' && !empty($admin['bot_access'])): ?>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    <?php foreach ($admin['bot_access'] as $access): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">
                                        <?= htmlspecialchars($access['bot_name']) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php elseif ($admin['role'] === 'super_admin'): ?>
                                <div class="text-xs text-purple-600 mt-1"><i class="fas fa-infinity mr-1"></i>เน€เธเนเธฒเธ–เธถเธเนเธ”เนเธ—เธธเธ Bot</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($admin['last_login']): ?>
                            <span class="text-xs text-gray-400">เน€เธเนเธฒเธฅเนเธฒเธชเธธเธ”: <?= date('d/m H:i', strtotime($admin['last_login'])) ?></span>
                            <?php endif; ?>
                            <button onclick="editAdmin(<?= htmlspecialchars(json_encode($admin)) ?>)" class="p-2 text-blue-500 hover:bg-blue-50 rounded">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('เธฅเธเธเธนเนเธ”เธนเนเธฅเธเธตเน?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= $admin['id'] ?>">
                                <button type="submit" class="p-2 text-red-500 hover:bg-red-50 rounded">
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
    </div>
    
    <!-- Info -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow p-4">
            <h4 class="font-semibold mb-3"><i class="fas fa-info-circle text-blue-500 mr-2"></i>เธฃเธฐเธ”เธฑเธเธชเธดเธ—เธเธดเน</h4>
            <div class="space-y-3 text-sm">
                <div class="p-3 bg-red-50 rounded-lg">
                    <div class="font-medium text-red-700">๐”ด Super Admin</div>
                    <div class="text-red-600 text-xs mt-1">เน€เธเนเธฒเธ–เธถเธเนเธ”เนเธ—เธธเธ LINE Bot เนเธฅเธฐเธเธฑเธ”เธเธฒเธฃเธเธนเนเธ”เธนเนเธฅเนเธ”เน</div>
                </div>
                <div class="p-3 bg-blue-50 rounded-lg">
                    <div class="font-medium text-blue-700">๐”ต Admin</div>
                    <div class="text-blue-600 text-xs mt-1">เน€เธเนเธฒเธ–เธถเธเนเธ”เนเน€เธเธเธฒเธฐ LINE Bot เธ—เธตเนเธ–เธนเธเธเธณเธซเธเธ”</div>
                </div>
                <div class="p-3 bg-gray-50 rounded-lg">
                    <div class="font-medium text-gray-700">โช Staff</div>
                    <div class="text-gray-600 text-xs mt-1">เธชเธดเธ—เธเธดเนเธเธณเธเธฑเธ” เธ”เธนเธเนเธญเธกเธนเธฅเนเธ”เนเธญเธขเนเธฒเธเน€เธ”เธตเธขเธง</div>
                </div>
                <div class="p-3 bg-green-50 rounded-lg">
                    <div class="font-medium text-green-700">๐’ เน€เธ เธชเธฑเธเธเธฃ</div>
                    <div class="text-green-600 text-xs mt-1">เธฃเธฑเธเนเธเนเธเน€เธ•เธทเธญเธเนเธฅเธฐเธญเธเธธเธกเธฑเธ•เธดเธขเธฒ</div>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow p-4">
            <h4 class="font-semibold mb-3"><i class="fas fa-shield-alt text-green-500 mr-2"></i>เธชเธดเธ—เธเธดเนเธ•เนเธญ Bot</h4>
            <div class="space-y-2 text-xs text-gray-600">
                <div><i class="fas fa-eye text-blue-500 mr-2"></i>เธ”เธนเธเนเธญเธกเธนเธฅ</div>
                <div><i class="fas fa-edit text-yellow-500 mr-2"></i>เนเธเนเนเธเธเนเธญเธกเธนเธฅ</div>
                <div><i class="fas fa-paper-plane text-purple-500 mr-2"></i>เธชเนเธ Broadcast</div>
                <div><i class="fas fa-users text-green-500 mr-2"></i>เธเธฑเธ”เธเธฒเธฃเธเธนเนเนเธเน</div>
                <div><i class="fas fa-store text-orange-500 mr-2"></i>เธเธฑเธ”เธเธฒเธฃเธฃเนเธฒเธเธเนเธฒ</div>
                <div><i class="fas fa-chart-bar text-pink-500 mr-2"></i>เธ”เธน Analytics</div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="adminModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-semibold" id="modalTitle">เน€เธเธดเนเธกเธเธนเนเธ”เธนเนเธฅ</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        
        <form method="POST" id="adminForm" class="p-4 space-y-4">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="admin_id" id="adminId">
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">เธเธทเนเธญเธเธนเนเนเธเน <span class="text-red-500">*</span></label>
                    <input type="text" name="username" id="username" required class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เธฃเธซเธฑเธชเธเนเธฒเธ <span class="text-red-500" id="pwdReq">*</span></label>
                    <input type="password" name="password" id="password" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1" id="pwdHint"></p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">เธเธทเนเธญเธ—เธตเนเนเธชเธ”เธ</label>
                    <input type="text" name="display_name" id="displayName" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">เธญเธตเน€เธกเธฅ</label>
                    <input type="email" name="email" id="email" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">เน€เธเธญเธฃเนเนเธ—เธฃ</label>
                    <input type="tel" name="phone" id="phone" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">
                        LINE User ID
                        <a href="javascript:void(0)" onclick="showLineIdHelp()" class="text-blue-500 ml-1"><i class="fas fa-question-circle"></i></a>
                    </label>
                    <input type="text" name="line_user_id" id="lineUserId" placeholder="Uxxxxxxxxxx..." class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">เธชเธณเธซเธฃเธฑเธเธฃเธฑเธเนเธเนเธเน€เธ•เธทเธญเธเธเนเธฒเธ LINE</p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">เธฃเธฐเธ”เธฑเธเธชเธดเธ—เธเธดเน</label>
                    <select name="role" id="role" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" onchange="toggleBotAccess()">
                        <option value="admin">Admin</option>
                        <option value="pharmacist">เน€เธ เธชเธฑเธเธเธฃ</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div class="flex items-center">
                    <label class="flex items-center cursor-pointer mt-6">
                        <input type="checkbox" name="is_active" id="isActive" checked class="mr-2">
                        <span class="text-sm">เน€เธเธดเธ”เนเธเนเธเธฒเธ</span>
                    </label>
                </div>
            </div>
            
            <!-- Bot Access -->
            <div id="botAccessSection">
                <label class="block text-sm font-medium mb-2">เธชเธดเธ—เธเธดเนเน€เธเนเธฒเธ–เธถเธ LINE Bot</label>
                <div class="border rounded-lg divide-y max-h-60 overflow-y-auto">
                    <?php foreach ($allBots as $bot): ?>
                    <div class="p-3 hover:bg-gray-50">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="bot_access[]" value="<?= $bot['id'] ?>" class="mr-3 bot-checkbox" data-bot="<?= $bot['id'] ?>" onchange="toggleBotPerms(<?= $bot['id'] ?>)">
                            <span class="font-medium"><?= htmlspecialchars($bot['name']) ?></span>
                            <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars($bot['basic_id'] ?? '') ?></span>
                        </label>
                        <div class="ml-6 mt-2 flex flex-wrap gap-2 bot-perms hidden" id="perms_<?= $bot['id'] ?>">
                            <label class="text-xs"><input type="checkbox" name="perm_edit[<?= $bot['id'] ?>]" checked class="mr-1">เนเธเนเนเธ</label>
                            <label class="text-xs"><input type="checkbox" name="perm_broadcast[<?= $bot['id'] ?>]" checked class="mr-1">Broadcast</label>
                            <label class="text-xs"><input type="checkbox" name="perm_users[<?= $bot['id'] ?>]" checked class="mr-1">เธเธฑเธ”เธเธฒเธฃเธเธนเนเนเธเน</label>
                            <label class="text-xs"><input type="checkbox" name="perm_shop[<?= $bot['id'] ?>]" checked class="mr-1">เธฃเนเธฒเธเธเนเธฒ</label>
                            <label class="text-xs"><input type="checkbox" name="perm_analytics[<?= $bot['id'] ?>]" checked class="mr-1">Analytics</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">เธขเธเน€เธฅเธดเธ</button>
                <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-save mr-1"></i>เธเธฑเธเธ—เธถเธ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreateModal() {
    document.getElementById('modalTitle').textContent = 'เน€เธเธดเนเธกเธเธนเนเธ”เธนเนเธฅ';
    document.getElementById('formAction').value = 'create';
    document.getElementById('adminId').value = '';
    document.getElementById('username').value = '';
    document.getElementById('username').disabled = false;
    document.getElementById('password').value = '';
    document.getElementById('password').required = true;
    document.getElementById('pwdReq').style.display = '';
    document.getElementById('pwdHint').textContent = '';
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
    
    document.getElementById('adminModal').classList.remove('hidden');
    document.getElementById('adminModal').classList.add('flex');
}

function editAdmin(admin) {
    document.getElementById('modalTitle').textContent = 'เนเธเนเนเธเธเธนเนเธ”เธนเนเธฅ';
    document.getElementById('formAction').value = 'update';
    document.getElementById('adminId').value = admin.id;
    document.getElementById('username').value = admin.username;
    document.getElementById('username').disabled = true;
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('pwdReq').style.display = 'none';
    document.getElementById('pwdHint').textContent = 'เน€เธงเนเธเธงเนเธฒเธเธ–เนเธฒเนเธกเนเธ•เนเธญเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธ';
    document.getElementById('displayName').value = admin.display_name || '';
    document.getElementById('email').value = admin.email || '';
    document.getElementById('phone').value = admin.phone || '';
    document.getElementById('lineUserId').value = admin.line_user_id || '';
    document.getElementById('role').value = admin.role;
    document.getElementById('isActive').checked = admin.is_active == 1;
    
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
            permsDiv.querySelector('[name="perm_edit['+botId+']"]').checked = access.can_edit == 1;
            permsDiv.querySelector('[name="perm_broadcast['+botId+']"]').checked = access.can_broadcast == 1;
            permsDiv.querySelector('[name="perm_users['+botId+']"]').checked = access.can_manage_users == 1;
            permsDiv.querySelector('[name="perm_shop['+botId+']"]').checked = access.can_manage_shop == 1;
            permsDiv.querySelector('[name="perm_analytics['+botId+']"]').checked = access.can_view_analytics == 1;
        }
    });
    
    document.getElementById('adminModal').classList.remove('hidden');
    document.getElementById('adminModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('adminModal').classList.add('hidden');
    document.getElementById('adminModal').classList.remove('flex');
}

function toggleBotPerms(botId) {
    const cb = document.querySelector('.bot-checkbox[data-bot="'+botId+'"]');
    const perms = document.getElementById('perms_' + botId);
    if (cb.checked) {
        perms.classList.remove('hidden');
    } else {
        perms.classList.add('hidden');
    }
}

function toggleBotAccess() {
    // Staff role has limited permissions
}

function showLineIdHelp() {
    alert('เธงเธดเธเธตเธซเธฒ LINE User ID:\n\n1. เนเธเธ—เธตเนเธซเธเนเธฒ Inbox เธซเธฃเธทเธญ Users\n2. เธเธฅเธดเธเธ—เธตเนเธเธนเนเนเธเนเธ—เธตเนเธ•เนเธญเธเธเธฒเธฃ\n3. LINE User ID เธเธฐเธเธถเนเธเธ•เนเธเธ”เนเธงเธข U เธ•เธฒเธกเธ”เนเธงเธขเธ•เธฑเธงเธญเธฑเธเธฉเธฃ 32 เธ•เธฑเธง\n   เน€เธเนเธ Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n\nเธซเธกเธฒเธขเน€เธซเธ•เธธ: เธเธนเนเนเธเนเธ•เนเธญเธเธ—เธฑเธเธกเธฒเธ—เธตเน LINE OA เธเนเธญเธเธเธถเธเธเธฐเธกเธต User ID');
}

// Close modal on outside click
document.getElementById('adminModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>

