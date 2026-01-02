<?php
/**
 * Rich Menu - Dynamic (Rule-based Assignment)
 * จัดการ Rich Menu แบบ Dynamic ตามเงื่อนไขผู้ใช้
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// Variables from parent: $db, $currentBotId, $line, $lineManager

require_once __DIR__ . '/../../classes/DynamicRichMenu.php';

$dynamicMenu = new DynamicRichMenu($db, $line, $currentBotId);

$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['tab'] ?? '') === 'dynamic') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create_rule':
                $conditions = [
                    'operator' => $_POST['operator'] ?? 'all'
                ];
                
                // Tags condition
                if (!empty($_POST['condition_tags'])) {
                    $conditions['tags'] = array_map('trim', explode(',', $_POST['condition_tags']));
                }
                
                // Registration condition
                if (isset($_POST['condition_registered']) && $_POST['condition_registered'] !== '') {
                    $conditions['is_registered'] = $_POST['condition_registered'] === '1';
                }
                
                // Points condition
                if (!empty($_POST['condition_points_min'])) {
                    $conditions['points_min'] = (int)$_POST['condition_points_min'];
                }
                if (!empty($_POST['condition_points_max'])) {
                    $conditions['points_max'] = (int)$_POST['condition_points_max'];
                }
                
                // Tier condition
                if (!empty($_POST['condition_tier'])) {
                    $conditions['tier'] = $_POST['condition_tier'];
                }
                
                // Days since follow
                if (!empty($_POST['condition_days_follow_max'])) {
                    $conditions['days_since_follow'] = ['max' => (int)$_POST['condition_days_follow_max']];
                }
                
                // Days inactive
                if (!empty($_POST['condition_days_inactive_min'])) {
                    $conditions['days_inactive'] = ['min' => (int)$_POST['condition_days_inactive_min']];
                }
                
                // Orders condition
                if (!empty($_POST['condition_orders_min'])) {
                    $conditions['completed_orders_min'] = (int)$_POST['condition_orders_min'];
                }
                
                $dynamicMenu->createRule(
                    $_POST['name'],
                    $_POST['rich_menu_id'],
                    $conditions,
                    (int)$_POST['priority'],
                    $_POST['description'] ?? ''
                );
                $message = 'สร้างกฎสำเร็จ';
                $messageType = 'success';
                break;
                
            case 'update_rule':
                $dynamicMenu->updateRule($_POST['rule_id'], [
                    'name' => $_POST['name'],
                    'rich_menu_id' => $_POST['rich_menu_id'],
                    'priority' => (int)$_POST['priority'],
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ]);
                $message = 'อัพเดทกฎสำเร็จ';
                $messageType = 'success';
                break;
                
            case 'delete_rule':
                $dynamicMenu->deleteRule($_POST['rule_id']);
                $message = 'ลบกฎสำเร็จ';
                $messageType = 'success';
                break;
                
            case 'assign_user':
                $result = $dynamicMenu->assignRichMenu(
                    $_POST['user_id'],
                    $_POST['line_user_id'],
                    $_POST['rich_menu_id'],
                    'manual'
                );
                $message = $result['success'] ? 'กำหนด Rich Menu สำเร็จ' : 'เกิดข้อผิดพลาด: ' . $result['error'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'evaluate_user':
                $result = $dynamicMenu->assignRichMenuByRules($_POST['user_id'], $_POST['line_user_id']);
                $message = $result['success'] ? 'ประเมินและกำหนด Rich Menu สำเร็จ: ' . $result['rich_menu'] : 'เกิดข้อผิดพลาด: ' . $result['error'];
                $messageType = $result['success'] ? 'success' : 'error';
                break;
                
            case 'bulk_evaluate':
                $result = $dynamicMenu->reEvaluateAllUsers((int)$_POST['limit'] ?: 100);
                $message = "ประเมินสำเร็จ {$result['success']} คน, ล้มเหลว {$result['failed']} คน";
                $messageType = $result['failed'] > 0 ? 'warning' : 'success';
                break;
                
            case 'assign_by_tag':
                $result = $dynamicMenu->assignByTag($_POST['tag_name'], $_POST['rich_menu_id']);
                $message = "กำหนดสำเร็จ {$result['success']} คน, ล้มเหลว {$result['failed']} คน";
                $messageType = $result['failed'] > 0 ? 'warning' : 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Get data
$rules = $dynamicMenu->getRules();
$statistics = $dynamicMenu->getStatistics();

// Get Rich Menus
$richMenus = [];
try {
    // Check if line_account_id column exists
    $cols = $db->query("SHOW COLUMNS FROM rich_menus")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('line_account_id', $cols)) {
        $stmt = $db->prepare("SELECT * FROM rich_menus WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
        $stmt->execute([$currentBotId]);
    } else {
        $stmt = $db->query("SELECT * FROM rich_menus ORDER BY name");
    }
    $richMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Get Tags - try user_tags first, fallback to tags
$tags = [];
try {
    $stmt = $db->prepare("SELECT DISTINCT name FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
    $stmt->execute([$currentBotId]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    try {
        $stmt = $db->query("SELECT DISTINCT name FROM tags ORDER BY name");
        $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e2) {}
}
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700') ?>">
    <i class="fas fa-<?= $messageType === 'success' ? 'check' : ($messageType === 'warning' ? 'exclamation' : 'times') ?>-circle mr-2"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                <i class="fas fa-th-large text-blue-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">Rich Menus</p>
                <p class="text-2xl font-bold"><?= count($richMenus) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                <i class="fas fa-cogs text-green-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">กฎที่ใช้งาน</p>
                <p class="text-2xl font-bold"><?= count(array_filter($rules, fn($r) => $r['is_active'])) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mr-4">
                <i class="fas fa-exchange-alt text-purple-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">Switch วันนี้</p>
                <p class="text-2xl font-bold"><?= $statistics['switches_today'] ?? 0 ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center mr-4">
                <i class="fas fa-users text-orange-500 text-xl"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">ผู้ใช้ที่กำหนด</p>
                <p class="text-2xl font-bold"><?= array_sum(array_column($statistics['menu_stats'] ?? [], 'user_count')) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Sub-Tabs -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="border-b">
        <nav class="flex -mb-px">
            <button onclick="showDynamicTab('rules')" id="dynamic-tab-rules" class="dynamic-tab-btn px-6 py-4 border-b-2 border-green-500 text-green-600 font-medium">
                <i class="fas fa-cogs mr-2"></i>กฎการกำหนด
            </button>
            <button onclick="showDynamicTab('manual')" id="dynamic-tab-manual" class="dynamic-tab-btn px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <i class="fas fa-user-cog mr-2"></i>กำหนดรายบุคคล
            </button>
            <button onclick="showDynamicTab('bulk')" id="dynamic-tab-bulk" class="dynamic-tab-btn px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <i class="fas fa-users-cog mr-2"></i>กำหนดแบบกลุ่ม
            </button>
            <button onclick="showDynamicTab('stats')" id="dynamic-tab-stats" class="dynamic-tab-btn px-6 py-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                <i class="fas fa-chart-bar mr-2"></i>สถิติ
            </button>
        </nav>
    </div>


    <!-- Rules Tab -->
    <div id="dynamic-content-rules" class="dynamic-tab-content p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold">กฎการกำหนด Rich Menu</h3>
            <button onclick="openRuleModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-plus mr-2"></i>สร้างกฎใหม่
            </button>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-medium text-gray-500">Priority</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-gray-500">ชื่อกฎ</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-gray-500">Rich Menu</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-gray-500">เงื่อนไข</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-gray-500">สถานะ</th>
                        <th class="px-4 py-3 text-left text-sm font-medium text-gray-500">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($rules as $rule): ?>
                    <?php $conditions = json_decode($rule['conditions'], true); ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-sm font-medium"><?= $rule['priority'] ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($rule['name']) ?></div>
                            <?php if ($rule['description']): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($rule['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><?= htmlspecialchars($rule['rich_menu_name'] ?? 'N/A') ?></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <?php if (!empty($conditions['tags'])): ?>
                                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs">Tags: <?= implode(', ', $conditions['tags']) ?></span>
                                <?php endif; ?>
                                <?php if (isset($conditions['is_registered'])): ?>
                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs"><?= $conditions['is_registered'] ? 'สมาชิก' : 'ไม่ใช่สมาชิก' ?></span>
                                <?php endif; ?>
                                <?php if (isset($conditions['points_min'])): ?>
                                <span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded text-xs">Points ≥ <?= $conditions['points_min'] ?></span>
                                <?php endif; ?>
                                <?php if (isset($conditions['tier'])): ?>
                                <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded text-xs">Tier: <?= is_array($conditions['tier']) ? implode(', ', $conditions['tier']) : $conditions['tier'] ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded text-xs <?= $rule['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                <?= $rule['is_active'] ? 'ใช้งาน' : 'ปิด' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="rich-menu.php?tab=dynamic" class="inline" onsubmit="return confirm('ลบกฎนี้?')">
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                <button type="submit" class="p-2 text-red-500 hover:bg-red-50 rounded">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rules)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            <i class="fas fa-cogs text-4xl mb-2"></i>
                            <p>ยังไม่มีกฎ คลิก "สร้างกฎใหม่" เพื่อเริ่มต้น</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Manual Assignment Tab -->
    <div id="dynamic-content-manual" class="dynamic-tab-content p-6 hidden">
        <h3 class="text-lg font-semibold mb-4">กำหนด Rich Menu รายบุคคล</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-medium mb-3"><i class="fas fa-search mr-2"></i>ค้นหาผู้ใช้</h4>
                <input type="text" id="searchUser" placeholder="ค้นหาชื่อหรือ LINE ID..." 
                       class="w-full px-4 py-2 border rounded-lg mb-3" onkeyup="searchUsers(this.value)">
                <div id="userResults" class="max-h-64 overflow-y-auto space-y-2"></div>
            </div>
            
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-medium mb-3"><i class="fas fa-user-check mr-2"></i>ผู้ใช้ที่เลือก</h4>
                <div id="selectedUser" class="mb-4 p-3 bg-white rounded-lg border hidden">
                    <div class="flex items-center">
                        <img id="selectedUserImg" src="" class="w-10 h-10 rounded-full mr-3">
                        <div>
                            <div id="selectedUserName" class="font-medium"></div>
                            <div id="selectedUserMenu" class="text-sm text-gray-500"></div>
                        </div>
                    </div>
                </div>
                
                <form method="POST" action="rich-menu.php?tab=dynamic" id="assignForm">
                    <input type="hidden" name="action" value="assign_user">
                    <input type="hidden" name="user_id" id="assignUserId">
                    <input type="hidden" name="line_user_id" id="assignLineUserId">
                    
                    <label class="block text-sm font-medium mb-2">เลือก Rich Menu</label>
                    <select name="rich_menu_id" required class="w-full px-4 py-2 border rounded-lg mb-4">
                        <option value="">-- เลือก Rich Menu --</option>
                        <?php foreach ($richMenus as $menu): ?>
                        <option value="<?= $menu['id'] ?>"><?= htmlspecialchars($menu['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div class="flex space-x-2">
                        <button type="submit" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                            <i class="fas fa-check mr-2"></i>กำหนด
                        </button>
                        <button type="button" onclick="evaluateUser()" class="flex-1 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                            <i class="fas fa-magic mr-2"></i>ประเมินตามกฎ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Assignment Tab -->
    <div id="dynamic-content-bulk" class="dynamic-tab-content p-6 hidden">
        <h3 class="text-lg font-semibold mb-4">กำหนด Rich Menu แบบกลุ่ม</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-medium mb-3"><i class="fas fa-magic mr-2"></i>ประเมินตามกฎอัตโนมัติ</h4>
                <p class="text-sm text-gray-600 mb-4">ระบบจะประเมินผู้ใช้ทั้งหมดและกำหนด Rich Menu ตามกฎที่ตั้งไว้</p>
                <form method="POST" action="rich-menu.php?tab=dynamic">
                    <input type="hidden" name="action" value="bulk_evaluate">
                    <label class="block text-sm font-medium mb-2">จำนวนผู้ใช้ (ต่อครั้ง)</label>
                    <input type="number" name="limit" value="100" min="1" max="500" class="w-full px-4 py-2 border rounded-lg mb-4">
                    <button type="submit" class="w-full px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                        <i class="fas fa-play mr-2"></i>เริ่มประเมิน
                    </button>
                </form>
            </div>
            
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-medium mb-3"><i class="fas fa-tag mr-2"></i>กำหนดตาม Tag</h4>
                <p class="text-sm text-gray-600 mb-4">กำหนด Rich Menu ให้ผู้ใช้ที่มี Tag ที่เลือก</p>
                <form method="POST" action="rich-menu.php?tab=dynamic">
                    <input type="hidden" name="action" value="assign_by_tag">
                    <label class="block text-sm font-medium mb-2">เลือก Tag</label>
                    <select name="tag_name" required class="w-full px-4 py-2 border rounded-lg mb-3">
                        <option value="">-- เลือก Tag --</option>
                        <?php foreach ($tags as $tag): ?>
                        <option value="<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="block text-sm font-medium mb-2">เลือก Rich Menu</label>
                    <select name="rich_menu_id" required class="w-full px-4 py-2 border rounded-lg mb-4">
                        <option value="">-- เลือก Rich Menu --</option>
                        <?php foreach ($richMenus as $menu): ?>
                        <option value="<?= $menu['id'] ?>"><?= htmlspecialchars($menu['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="w-full px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                        <i class="fas fa-users mr-2"></i>กำหนดทั้งหมด
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Statistics Tab -->
    <div id="dynamic-content-stats" class="dynamic-tab-content p-6 hidden">
        <h3 class="text-lg font-semibold mb-4">สถิติการใช้งาน Rich Menu</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-medium mb-3"><i class="fas fa-chart-pie mr-2"></i>จำนวนผู้ใช้แต่ละ Rich Menu</h4>
                <div class="space-y-3">
                    <?php foreach ($statistics['menu_stats'] ?? [] as $stat): ?>
                    <div class="flex items-center justify-between">
                        <span><?= htmlspecialchars($stat['name']) ?></span>
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm font-medium">
                            <?= number_format($stat['user_count']) ?> คน
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="bg-gray-50 rounded-lg p-4">
                <h4 class="font-medium mb-3"><i class="fas fa-exchange-alt mr-2"></i>การ Switch (7 วันล่าสุด)</h4>
                <div class="space-y-3">
                    <?php 
                    $switchTypes = ['rule' => 'ตามกฎ', 'manual' => 'Manual', 'event' => 'Event', 'api' => 'API'];
                    foreach ($switchTypes as $type => $label): 
                    ?>
                    <div class="flex items-center justify-between">
                        <span><?= $label ?></span>
                        <span class="px-3 py-1 bg-purple-100 text-purple-700 rounded-full text-sm font-medium">
                            <?= number_format($statistics['switch_by_type'][$type] ?? 0) ?> ครั้ง
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Rule Modal -->
<div id="ruleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 overflow-y-auto">
    <div class="bg-white rounded-xl w-full max-w-2xl mx-4 my-8">
        <form method="POST" action="rich-menu.php?tab=dynamic">
            <input type="hidden" name="action" value="create_rule">
            
            <div class="p-6 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold">สร้างกฎใหม่</h3>
                <button type="button" onclick="closeRuleModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อกฎ *</label>
                        <input type="text" name="name" required class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น VIP Members">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Priority *</label>
                        <input type="number" name="priority" value="50" min="0" max="100" class="w-full px-4 py-2 border rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">ยิ่งสูงยิ่งใช้ก่อน (0-100)</p>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">Rich Menu *</label>
                    <select name="rich_menu_id" required class="w-full px-4 py-2 border rounded-lg">
                        <option value="">-- เลือก Rich Menu --</option>
                        <?php foreach ($richMenus as $menu): ?>
                        <option value="<?= $menu['id'] ?>"><?= htmlspecialchars($menu['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">คำอธิบาย</label>
                    <input type="text" name="description" class="w-full px-4 py-2 border rounded-lg" placeholder="อธิบายกฎนี้...">
                </div>
                
                <hr>
                
                <div>
                    <label class="block text-sm font-medium mb-2">เงื่อนไข</label>
                    <div class="mb-3">
                        <label class="inline-flex items-center mr-4">
                            <input type="radio" name="operator" value="all" checked class="mr-2"> ตรงทุกเงื่อนไข (AND)
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="operator" value="any" class="mr-2"> ตรงอย่างน้อย 1 เงื่อนไข (OR)
                        </label>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Tags (คั่นด้วย ,)</label>
                        <input type="text" name="condition_tags" class="w-full px-4 py-2 border rounded-lg" placeholder="VIP, Premium">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">สถานะสมาชิก</label>
                        <select name="condition_registered" class="w-full px-4 py-2 border rounded-lg">
                            <option value="">-- ไม่ระบุ --</option>
                            <option value="1">ลงทะเบียนแล้ว</option>
                            <option value="0">ยังไม่ลงทะเบียน</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Points ขั้นต่ำ</label>
                        <input type="number" name="condition_points_min" class="w-full px-4 py-2 border rounded-lg" placeholder="1000">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Points สูงสุด</label>
                        <input type="number" name="condition_points_max" class="w-full px-4 py-2 border rounded-lg" placeholder="5000">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Tier</label>
                        <select name="condition_tier" class="w-full px-4 py-2 border rounded-lg">
                            <option value="">-- ไม่ระบุ --</option>
                            <option value="bronze">Bronze</option>
                            <option value="silver">Silver</option>
                            <option value="gold">Gold</option>
                            <option value="platinum">Platinum</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ติดตามไม่เกิน (วัน)</label>
                        <input type="number" name="condition_days_follow_max" class="w-full px-4 py-2 border rounded-lg" placeholder="7">
                        <p class="text-xs text-gray-500 mt-1">สำหรับผู้ใช้ใหม่</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ไม่ active เกิน (วัน)</label>
                        <input type="number" name="condition_days_inactive_min" class="w-full px-4 py-2 border rounded-lg" placeholder="30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Orders ขั้นต่ำ</label>
                        <input type="number" name="condition_orders_min" class="w-full px-4 py-2 border rounded-lg" placeholder="5">
                    </div>
                </div>
            </div>
            
            <div class="p-6 border-t flex justify-end space-x-2 bg-gray-50">
                <button type="button" onclick="closeRuleModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-save mr-2"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showDynamicTab(tab) {
    document.querySelectorAll('.dynamic-tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.dynamic-tab-btn').forEach(el => {
        el.classList.remove('border-green-500', 'text-green-600');
        el.classList.add('border-transparent', 'text-gray-500');
    });
    
    document.getElementById('dynamic-content-' + tab).classList.remove('hidden');
    document.getElementById('dynamic-tab-' + tab).classList.add('border-green-500', 'text-green-600');
    document.getElementById('dynamic-tab-' + tab).classList.remove('border-transparent', 'text-gray-500');
}

function openRuleModal() {
    document.getElementById('ruleModal').classList.remove('hidden');
    document.getElementById('ruleModal').classList.add('flex');
}

function closeRuleModal() {
    document.getElementById('ruleModal').classList.add('hidden');
    document.getElementById('ruleModal').classList.remove('flex');
}

let searchTimeout;
function searchUsers(query) {
    clearTimeout(searchTimeout);
    if (query.length < 2) {
        document.getElementById('userResults').innerHTML = '<p class="text-gray-500 text-sm">พิมพ์อย่างน้อย 2 ตัวอักษร</p>';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch('api/ajax_handler.php?action=search_users&q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(data => {
                if (data.success && data.users.length > 0) {
                    document.getElementById('userResults').innerHTML = data.users.map(u => `
                        <div onclick="selectUser(${u.id}, '${u.line_user_id}', '${u.display_name}', '${u.picture_url || ''}', '${u.current_menu || 'ไม่มี'}')" 
                             class="flex items-center p-2 bg-white rounded-lg border cursor-pointer hover:bg-gray-50">
                            <img src="${u.picture_url || 'assets/images/default-avatar.png'}" class="w-8 h-8 rounded-full mr-2">
                            <div>
                                <div class="font-medium text-sm">${u.display_name}</div>
                                <div class="text-xs text-gray-500">Menu: ${u.current_menu || 'ไม่มี'}</div>
                            </div>
                        </div>
                    `).join('');
                } else {
                    document.getElementById('userResults').innerHTML = '<p class="text-gray-500 text-sm">ไม่พบผู้ใช้</p>';
                }
            });
    }, 300);
}

function selectUser(id, lineUserId, name, img, currentMenu) {
    document.getElementById('assignUserId').value = id;
    document.getElementById('assignLineUserId').value = lineUserId;
    document.getElementById('selectedUserName').textContent = name;
    document.getElementById('selectedUserImg').src = img || 'assets/images/default-avatar.png';
    document.getElementById('selectedUserMenu').textContent = 'Menu ปัจจุบัน: ' + currentMenu;
    document.getElementById('selectedUser').classList.remove('hidden');
}

function evaluateUser() {
    const userId = document.getElementById('assignUserId').value;
    const lineUserId = document.getElementById('assignLineUserId').value;
    
    if (!userId) {
        alert('กรุณาเลือกผู้ใช้ก่อน');
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'rich-menu.php?tab=dynamic';
    form.innerHTML = `
        <input type="hidden" name="action" value="evaluate_user">
        <input type="hidden" name="user_id" value="${userId}">
        <input type="hidden" name="line_user_id" value="${lineUserId}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>
