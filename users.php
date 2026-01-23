<?php
/**
 * Users Management - รายชื่อผู้ใช้/ลูกค้า
 * แสดงทุก follow = 1 customer
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Users';

require_once 'includes/header.php';

// Get filter parameters
$tagFilter = isset($_GET['tag']) ? (int) $_GET['tag'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Advanced filters
$tierFilter = isset($_GET['tier']) ? trim($_GET['tier']) : '';
$pointsFilter = isset($_GET['points']) ? trim($_GET['points']) : '';
$activityFilter = isset($_GET['activity']) ? trim($_GET['activity']) : '';
$purchaseFilter = isset($_GET['purchase']) ? trim($_GET['purchase']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Get tag info if filtering
$currentTag = null;
// Check if user_tags tables exist
$hasTagTables = false;
try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'user_tags'");
    $hasTagTables = $checkStmt->fetch() !== false;
} catch (Exception $e) {
    $hasTagTables = false;
}

if ($tagFilter && $hasTagTables) {
    try {
        $stmt = $db->prepare("SELECT * FROM user_tags WHERE id = ?");
        $stmt->execute([$tagFilter]);
        $currentTag = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $tagFilter = null; // Reset filter if table doesn't exist
    }
}

// Build query
$whereConditions = ["1=1"];
$params = [];

if ($tagFilter && $hasTagTables) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM user_tag_assignments uta WHERE uta.user_id = u.id AND uta.tag_id = ?)";
    $params[] = $tagFilter;
}

if ($search) {
    $whereConditions[] = "(u.display_name LIKE ? OR u.line_user_id LIKE ? OR u.real_name LIKE ? OR u.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Tier filter
if ($tierFilter) {
    $whereConditions[] = "u.id IN (SELECT user_id FROM loyalty_points WHERE tier = ?)";
    $params[] = $tierFilter;
}

// Points filter
if ($pointsFilter) {
    switch ($pointsFilter) {
        case '0-100':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 0 AND 100";
            break;
        case '100-500':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 100 AND 500";
            break;
        case '500-1000':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 500 AND 1000";
            break;
        case '1000+':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) > 1000";
            break;
    }
}

// Activity filter
if ($activityFilter) {
    switch ($activityFilter) {
        case 'today':
            $whereConditions[] = "DATE(u.updated_at) = CURDATE()";
            break;
        case '7days':
            $whereConditions[] = "u.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30days':
            $whereConditions[] = "u.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'inactive':
            $whereConditions[] = "u.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
    }
}

// Purchase filter
if ($purchaseFilter) {
    switch ($purchaseFilter) {
        case 'purchased':
            $whereConditions[] = "EXISTS (SELECT 1 FROM orders WHERE user_id = u.id AND status != 'cancelled')";
            break;
        case 'never':
            $whereConditions[] = "NOT EXISTS (SELECT 1 FROM orders WHERE user_id = u.id)";
            break;
        case '1000+':
            $whereConditions[] = "(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 1000";
            break;
        case '5000+':
            $whereConditions[] = "(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 5000";
            break;
    }
}

// Status filter
if ($statusFilter) {
    switch ($statusFilter) {
        case 'active':
            $whereConditions[] = "u.is_blocked = 0";
            break;
        case 'blocked':
            $whereConditions[] = "u.is_blocked = 1";
            break;
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
try {
    $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalUsers = $stmt->fetchColumn();
} catch (Exception $e) {
    $totalUsers = 0;
}
$totalPages = ceil($totalUsers / $perPage);

// Get users
try {
    // Check if extra columns exist
    $hasExtraCols = false;
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'real_name'");
        $hasExtraCols = $checkStmt->fetch() !== false;
    } catch (Exception $e) {
        $hasExtraCols = false;
    }

    // Check if line_account_id column exists
    $hasLineAccountId = false;
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
        $hasLineAccountId = $checkStmt->fetch() !== false;
    } catch (Exception $e) {
        $hasLineAccountId = false;
    }

    // Build SELECT clause based on available columns
    $selectCols = "u.id, u.line_user_id, u.display_name, u.picture_url, u.status_message, u.is_blocked, u.created_at, u.updated_at";
    if ($hasLineAccountId) {
        $selectCols .= ", u.line_account_id";
    }
    if ($hasExtraCols) {
        $selectCols .= ", u.real_name, u.phone, u.email, u.birthday";
    }

    // Check if user_tags table exists
    $hasUserTags = false;
    try {
        $checkStmt = $db->query("SHOW TABLES LIKE 'user_tags'");
        $hasUserTags = $checkStmt->fetch() !== false;
    } catch (Exception $e) {
        $hasUserTags = false;
    }

    // Build tags subquery only if table exists
    $tagsSubquery = "NULL as tags";
    if ($hasUserTags) {
        $tagsSubquery = "(SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM user_tags t 
             JOIN user_tag_assignments uta ON t.id = uta.tag_id 
             WHERE uta.user_id = u.id) as tags";
    }

    $sql = "SELECT {$selectCols},
            {$tagsSubquery},
            (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id) as message_count,
            (SELECT MAX(created_at) FROM messages m WHERE m.user_id = u.id) as last_message_at
            FROM users u 
            WHERE {$whereClause}
            ORDER BY u.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add default values for missing columns
    foreach ($users as &$user) {
        if (!$hasLineAccountId) {
            $user['line_account_id'] = null;
        }
        if (!$hasExtraCols) {
            $user['real_name'] = null;
            $user['phone'] = null;
            $user['email'] = null;
            $user['birthday'] = null;
        }
    }
    unset($user);
} catch (Exception $e) {
    // Fallback query - use only basic columns
    $sql = "SELECT u.id, u.line_user_id, u.display_name, u.picture_url, u.status_message, u.is_blocked, u.created_at, u.updated_at 
            FROM users u WHERE {$whereClause} ORDER BY u.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user['tags'] = null;
        $user['message_count'] = 0;
        $user['last_message_at'] = null;
        $user['real_name'] = null;
        $user['phone'] = null;
        $user['email'] = null;
        $user['birthday'] = null;
        $user['line_account_id'] = null;
    }
    unset($user);
}

// Get all tags (only if table exists)
$allTags = [];
if ($hasTagTables) {
    try {
        $currentBotId = $currentBotId ?? null;
        $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
        $stmt->execute([$currentBotId]);
        $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_tag') {
        $userId = (int) $_POST['user_id'];
        $tagId = (int) $_POST['tag_id'];
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')");
            $stmt->execute([$userId, $tagId]);
        } catch (Exception $e) {
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'remove_tag') {
        $userId = (int) $_POST['user_id'];
        $tagId = (int) $_POST['tag_id'];
        try {
            $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            $stmt->execute([$userId, $tagId]);
        } catch (Exception $e) {
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h2 class="text-2xl font-bold">👥 Customers</h2>
        <p class="text-gray-600">ทั้งหมด <?php echo number_format($totalUsers); ?> คน</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" id="filterForm">
        <!-- Basic Search Row -->
        <div class="flex flex-wrap gap-4 items-end mb-4">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium mb-1">ค้นหา</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="ชื่อ, เบอร์โทร, LINE ID..."
                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
            </div>
            <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-search mr-1"></i>ค้นหา
            </button>
            <button type="button" onclick="toggleAdvancedFilters()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                <i class="fas fa-filter mr-1"></i>ตัวกรอง
                <?php 
                $activeFilters = array_filter([$tierFilter, $pointsFilter, $activityFilter, $purchaseFilter, $statusFilter, $tagFilter]);
                if (count($activeFilters) > 0): ?>
                <span class="ml-1 px-2 py-0.5 bg-green-500 text-white text-xs rounded-full"><?= count($activeFilters) ?></span>
                <?php endif; ?>
            </button>
        </div>
        
        <!-- Advanced Filters (collapsible) -->
        <div id="advancedFilters" class="<?= count($activeFilters) > 0 ? '' : 'hidden' ?> pt-4 border-t">
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <!-- Tier Filter -->
                <div>
                    <label class="block text-sm font-medium mb-1">ระดับสมาชิก</label>
                    <select name="tier" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">ทั้งหมด</option>
                        <option value="bronze" <?= $tierFilter === 'bronze' ? 'selected' : '' ?>>🥉 Bronze</option>
                        <option value="silver" <?= $tierFilter === 'silver' ? 'selected' : '' ?>>🥈 Silver</option>
                        <option value="gold" <?= $tierFilter === 'gold' ? 'selected' : '' ?>>🥇 Gold</option>
                        <option value="platinum" <?= $tierFilter === 'platinum' ? 'selected' : '' ?>>💎 Platinum</option>
                    </select>
                </div>
                
                <!-- Points Filter -->
                <div>
                    <label class="block text-sm font-medium mb-1">แต้มสะสม</label>
                    <select name="points" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">ทั้งหมด</option>
                        <option value="0-100" <?= $pointsFilter === '0-100' ? 'selected' : '' ?>>0-100 แต้ม</option>
                        <option value="100-500" <?= $pointsFilter === '100-500' ? 'selected' : '' ?>>100-500 แต้ม</option>
                        <option value="500-1000" <?= $pointsFilter === '500-1000' ? 'selected' : '' ?>>500-1,000 แต้ม</option>
                        <option value="1000+" <?= $pointsFilter === '1000+' ? 'selected' : '' ?>>1,000+ แต้ม</option>
                    </select>
                </div>
                
                <!-- Activity Filter -->
                <div>
                    <label class="block text-sm font-medium mb-1">กิจกรรมล่าสุด</label>
                    <select name="activity" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">ทั้งหมด</option>
                        <option value="today" <?= $activityFilter === 'today' ? 'selected' : '' ?>>วันนี้</option>
                        <option value="7days" <?= $activityFilter === '7days' ? 'selected' : '' ?>>7 วันที่ผ่านมา</option>
                        <option value="30days" <?= $activityFilter === '30days' ? 'selected' : '' ?>>30 วันที่ผ่านมา</option>
                        <option value="inactive" <?= $activityFilter === 'inactive' ? 'selected' : '' ?>>ไม่มีกิจกรรม (>30 วัน)</option>
                    </select>
                </div>
                
                <!-- Purchase Filter -->
                <div>
                    <label class="block text-sm font-medium mb-1">ประวัติซื้อ</label>
                    <select name="purchase" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">ทั้งหมด</option>
                        <option value="purchased" <?= $purchaseFilter === 'purchased' ? 'selected' : '' ?>>เคยซื้อแล้ว</option>
                        <option value="never" <?= $purchaseFilter === 'never' ? 'selected' : '' ?>>ยังไม่เคยซื้อ</option>
                        <option value="1000+" <?= $purchaseFilter === '1000+' ? 'selected' : '' ?>>ซื้อ ≥ ฿1,000</option>
                        <option value="5000+" <?= $purchaseFilter === '5000+' ? 'selected' : '' ?>>ซื้อ ≥ ฿5,000</option>
                    </select>
                </div>
                
                <!-- Tag Filter -->
                <div>
                    <label class="block text-sm font-medium mb-1">Tags</label>
                    <select name="tag" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($allTags as $tag): ?>
                        <option value="<?= $tag['id'] ?>" <?= $tagFilter == $tag['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Status Filter -->
                <div>
                    <label class="block text-sm font-medium mb-1">สถานะ</label>
                    <select name="status" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="">ทั้งหมด</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>✅ Active</option>
                        <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>🚫 Blocked</option>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-2 mt-4">
                <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-filter mr-1"></i>กรองข้อมูล
                </button>
                <a href="users.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                    <i class="fas fa-times mr-1"></i>ล้างตัวกรอง
                </a>
            </div>
        </div>
    </form>
</div>

<script>
function toggleAdvancedFilters() {
    const filters = document.getElementById('advancedFilters');
    filters.classList.toggle('hidden');
}
</script>

<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ใช้</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tags</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">ข้อความ</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($users as $user): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4">
                        <div class="flex items-center">
                            <img src="<?php echo $user['picture_url'] ?: 'https://via.placeholder.com/40'; ?>"
                                class="w-10 h-10 rounded-full object-cover mr-3">
                            <div>
                                <p class="font-medium"><?php echo htmlspecialchars($user['display_name'] ?: 'Unknown'); ?>
                                </p>
                                <p class="text-xs text-gray-500"><?php echo substr($user['line_user_id'], 0, 15); ?>...</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($user['tags']): ?>
                            <?php foreach (explode(', ', $user['tags']) as $tagName): ?>
                                <span
                                    class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs"><?php echo htmlspecialchars($tagName); ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-gray-400 text-xs">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="font-medium"><?php echo number_format($user['message_count'] ?? 0); ?></span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <?php if ($user['is_blocked']): ?>
                            <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs">Blocked</span>
                        <?php else: ?>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex justify-center gap-2">
                            <a href="user-detail.php?id=<?php echo $user['id']; ?>"
                                class="text-green-500 hover:text-green-700" title="ดูรายละเอียด">
                                <i class="fas fa-user"></i>
                            </a>
                            <a href="messages.php?user=<?php echo $user['id']; ?>" class="text-blue-500 hover:text-blue-700"
                                title="ดูแชท">
                                <i class="fas fa-comments"></i>
                            </a>
                            <button
                                onclick="openTagModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['display_name'] ?? '', ENT_QUOTES); ?>')"
                                class="text-purple-500 hover:text-purple-700" title="จัดการ Tags">
                                <i class="fas fa-tags"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-users text-4xl text-gray-300 mb-3 block"></i>
                        <p>ไม่พบผู้ใช้</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="mt-4 flex justify-center gap-2">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                class="px-3 py-1 rounded <?php echo $i == $page ? 'bg-green-500 text-white' : 'bg-white hover:bg-gray-100'; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<!-- Tag Modal -->
<div id="tagModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="p-6 border-b flex justify-between items-center">
            <div>
                <h3 class="text-xl font-semibold">🏷️ จัดการ Tags</h3>
                <p class="text-gray-600 text-sm" id="tagModalUserName"></p>
            </div>
            <button onclick="closeTagModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">Tags ปัจจุบัน</label>
                <div id="currentTags" class="flex flex-wrap gap-2 min-h-[32px] p-2 bg-gray-50 rounded-lg">
                    <span class="text-gray-400 text-sm">กำลังโหลด...</span>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">เพิ่ม Tag</label>
                <div class="flex gap-2">
                    <select id="tagSelect" class="flex-1 px-4 py-2 border rounded-lg">
                        <?php foreach ($allTags as $tag): ?>
                            <option value="<?php echo $tag['id']; ?>"
                                data-color="<?php echo htmlspecialchars($tag['color'] ?? '#3B82F6'); ?>">
                                <?php echo htmlspecialchars($tag['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="assignTag()"
                        class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t">
                <button type="button" onclick="closeTagModal()"
                    class="w-full px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentUserId = null;

    function openTagModal(userId, userName) {
        currentUserId = userId;
        document.getElementById('tagModalUserName').textContent = userName;
        document.getElementById('tagModal').classList.remove('hidden');
        document.getElementById('tagModal').classList.add('flex');
        loadUserTags(userId);
    }

    function closeTagModal() {
        document.getElementById('tagModal').classList.add('hidden');
        document.getElementById('tagModal').classList.remove('flex');
        currentUserId = null;
    }

    async function loadUserTags(userId) {
        const container = document.getElementById('currentTags');
        container.innerHTML = '<span class="text-gray-400 text-sm">กำลังโหลด...</span>';

        try {
            const response = await fetch('api/ajax_handler.php?action=get_user_tags&user_id=' + userId);
            const result = await response.json();

            if (result.success) {
                if (result.tags.length === 0) {
                    container.innerHTML = '<span class="text-gray-400 text-sm">ยังไม่มี Tags</span>';
                } else {
                    container.innerHTML = result.tags.map(tag =>
                        '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-sm" style="background-color: ' + (tag.color || '#3B82F6') + '20; color: ' + (tag.color || '#3B82F6') + '">' +
                        '<span class="w-2 h-2 rounded-full" style="background-color: ' + (tag.color || '#3B82F6') + '"></span>' +
                        tag.name +
                        '<button onclick="removeTag(' + tag.id + ')" class="ml-1 hover:opacity-70">×</button>' +
                        '</span>'
                    ).join('');
                }
            } else {
                container.innerHTML = '<span class="text-red-500 text-sm">' + (result.error || 'เกิดข้อผิดพลาด') + '</span>';
            }
        } catch (e) {
            container.innerHTML = '<span class="text-red-500 text-sm">เกิดข้อผิดพลาด: ' + e.message + '</span>';
        }
    }

    async function assignTag() {
        if (!currentUserId) return;

        const tagId = document.getElementById('tagSelect').value;

        try {
            const formData = new FormData();
            formData.append('action', 'assign_tag');
            formData.append('user_id', currentUserId);
            formData.append('tag_id', tagId);

            const response = await fetch('api/ajax_handler.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                loadUserTags(currentUserId);
            } else {
                alert(result.error || 'เกิดข้อผิดพลาด');
            }
        } catch (e) {
            alert('เกิดข้อผิดพลาด: ' + e.message);
        }
    }

    async function removeTag(tagId) {
        if (!currentUserId) return;

        try {
            const formData = new FormData();
            formData.append('action', 'remove_tag');
            formData.append('user_id', currentUserId);
            formData.append('tag_id', tagId);

            const response = await fetch('api/ajax_handler.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                loadUserTags(currentUserId);
            } else {
                alert(result.error || 'เกิดข้อผิดพลาด');
            }
        } catch (e) {
            alert('เกิดข้อผิดพลาด: ' + e.message);
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>