<?php
/**
 * Members Management - จัดการสมาชิก
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'จัดการสมาชิก';

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_points') {
        $userId = (int)$_POST['user_id'];
        $points = (int)$_POST['points'];
        $description = trim($_POST['description'] ?? 'เพิ่มแต้มโดยแอดมิน');
        
        if ($userId && $points != 0) {
            // Get current points
            $stmt = $db->prepare("SELECT points FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $newBalance = ($user['points'] ?? 0) + $points;
            
            // Update points
            $stmt = $db->prepare("UPDATE users SET points = ? WHERE id = ?");
            $stmt->execute([$newBalance, $userId]);
            
            // Log
            $type = $points > 0 ? 'adjust' : 'adjust';
            $stmt = $db->prepare("INSERT INTO points_history (user_id, points, type, description, balance_after) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $points, $type, $description, $newBalance]);
            
            $message = ($points > 0 ? 'เพิ่ม' : 'หัก') . 'แต้มสำเร็จ!';
            $messageType = 'success';
        }
    } elseif ($action === 'update_tier') {
        $userId = (int)$_POST['user_id'];
        $tier = $_POST['tier'];
        
        $stmt = $db->prepare("UPDATE users SET member_tier = ? WHERE id = ?");
        $stmt->execute([$tier, $userId]);
        
        $message = 'อัพเดทระดับสมาชิกสำเร็จ!';
        $messageType = 'success';
    }
}

// Filters
$search = $_GET['search'] ?? '';
$tier = $_GET['tier'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Check if required columns exist
$hasIsRegistered = false;
$hasMemberTier = false;
$hasRegisteredAt = false;
$hasPoints = false;
$hasMemberId = false;

try {
    $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsRegistered = in_array('is_registered', $cols);
    $hasMemberTier = in_array('member_tier', $cols);
    $hasRegisteredAt = in_array('registered_at', $cols);
    $hasPoints = in_array('points', $cols);
    $hasMemberId = in_array('member_id', $cols);
} catch (Exception $e) {}

// Build query based on available columns
$where = "WHERE 1=1";
if ($hasIsRegistered) {
    $where = "WHERE is_registered = 1";
}
$params = [];

if ($search) {
    $searchFields = ["first_name LIKE ?", "last_name LIKE ?", "phone LIKE ?", "display_name LIKE ?"];
    if ($hasMemberId) {
        $searchFields[] = "member_id LIKE ?";
    }
    $where .= " AND (" . implode(" OR ", $searchFields) . ")";
    $searchParam = "%{$search}%";
    $params = array_fill(0, count($searchFields), $searchParam);
}
if ($tier && $hasMemberTier) {
    $where .= " AND member_tier = ?";
    $params[] = $tier;
}

// Get total
$stmt = $db->prepare("SELECT COUNT(*) FROM users {$where}");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// Get members
$orderBy = $hasRegisteredAt ? "ORDER BY registered_at DESC" : "ORDER BY id DESC";
$stmt = $db->prepare("SELECT * FROM users {$where} {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tiers
$tiers = [];
try {
    $tiers = $db->query("SELECT * FROM member_tiers ORDER BY min_points ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist, use default tiers
    $tiers = [
        ['name' => 'bronze', 'min_points' => 0],
        ['name' => 'silver', 'min_points' => 500],
        ['name' => 'gold', 'min_points' => 2000],
        ['name' => 'platinum', 'min_points' => 5000]
    ];
}

require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-times-circle' ?> mr-2"></i><?= $message ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $statsQuery = "SELECT COUNT(*) as total";
    if ($hasMemberTier) {
        $statsQuery .= ",
            SUM(CASE WHEN member_tier = 'bronze' THEN 1 ELSE 0 END) as bronze,
            SUM(CASE WHEN member_tier = 'silver' THEN 1 ELSE 0 END) as silver,
            SUM(CASE WHEN member_tier = 'gold' THEN 1 ELSE 0 END) as gold,
            SUM(CASE WHEN member_tier = 'platinum' THEN 1 ELSE 0 END) as platinum";
    }
    $statsQuery .= " FROM users";
    if ($hasIsRegistered) {
        $statsQuery .= " WHERE is_registered = 1";
    }
    $stats = $db->query($statsQuery)->fetch();
    if (!$hasMemberTier) {
        $stats['bronze'] = $stats['silver'] = $stats['gold'] = $stats['platinum'] = 0;
    }
    ?>
    <div class="bg-white rounded-xl shadow p-4">
        <p class="text-gray-500 text-sm">สมาชิกทั้งหมด</p>
        <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total']) ?></p>
    </div>
    <div class="bg-gradient-to-r from-amber-600 to-amber-700 rounded-xl shadow p-4 text-white">
        <p class="text-white/70 text-sm">🥉 Bronze</p>
        <p class="text-2xl font-bold"><?= number_format($stats['bronze']) ?></p>
    </div>
    <div class="bg-gradient-to-r from-gray-400 to-gray-500 rounded-xl shadow p-4 text-white">
        <p class="text-white/70 text-sm">🥈 Silver</p>
        <p class="text-2xl font-bold"><?= number_format($stats['silver']) ?></p>
    </div>
    <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-xl shadow p-4 text-white">
        <p class="text-white/70 text-sm">🥇 Gold+</p>
        <p class="text-2xl font-bold"><?= number_format($stats['gold'] + $stats['platinum']) ?></p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อ, รหัสสมาชิก, เบอร์โทร..." 
            class="flex-1 min-w-[200px] px-4 py-2 border rounded-lg">
        <select name="tier" class="px-4 py-2 border rounded-lg">
            <option value="">ทุกระดับ</option>
            <option value="bronze" <?= $tier === 'bronze' ? 'selected' : '' ?>>🥉 Bronze</option>
            <option value="silver" <?= $tier === 'silver' ? 'selected' : '' ?>>🥈 Silver</option>
            <option value="gold" <?= $tier === 'gold' ? 'selected' : '' ?>>🥇 Gold</option>
            <option value="platinum" <?= $tier === 'platinum' ? 'selected' : '' ?>>💎 Platinum</option>
        </select>
        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
            <i class="fas fa-search mr-2"></i>ค้นหา
        </button>
    </form>
</div>

<!-- Members Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">สมาชิก</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">รหัส</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">ระดับ</th>
                    <th class="px-4 py-3 text-right text-sm font-medium text-gray-600">แต้ม</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">เบอร์โทร</th>
                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-600">สมัครเมื่อ</th>
                    <th class="px-4 py-3 text-center text-sm font-medium text-gray-600">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($members as $member): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img src="<?= $member['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full object-cover">
                            <div>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($member['display_name'] ?? '') ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-mono text-sm"><?= $hasMemberId ? ($member['member_id'] ?? '-') : '-' ?></td>
                    <td class="px-4 py-3">
                        <?php
                        $tierIcons = ['bronze' => '🥉', 'silver' => '🥈', 'gold' => '🥇', 'platinum' => '💎', 'vip' => '👑'];
                        $tierColors = ['bronze' => 'bg-amber-100 text-amber-700', 'silver' => 'bg-gray-100 text-gray-700', 'gold' => 'bg-yellow-100 text-yellow-700', 'platinum' => 'bg-purple-100 text-purple-700', 'vip' => 'bg-pink-100 text-pink-700'];
                        $t = $hasMemberTier ? ($member['member_tier'] ?? 'bronze') : 'bronze';
                        ?>
                        <span class="px-2 py-1 rounded-full text-xs font-medium <?= $tierColors[$t] ?? '' ?>">
                            <?= $tierIcons[$t] ?? '' ?> <?= ucfirst($t) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-purple-600"><?= number_format($hasPoints ? ($member['points'] ?? 0) : 0) ?></td>
                    <td class="px-4 py-3 text-sm"><?= $member['phone'] ?: '-' ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?= ($hasRegisteredAt && $member['registered_at']) ? date('d/m/Y', strtotime($member['registered_at'])) : '-' ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="openPointsModal(<?= $member['id'] ?>, '<?= htmlspecialchars($member['first_name'] ?? '') ?>', <?= $hasPoints ? ($member['points'] ?? 0) : 0 ?>)" 
                            class="px-3 py-1 bg-purple-100 text-purple-600 rounded-lg text-sm hover:bg-purple-200" title="จัดการแต้ม">
                            <i class="fas fa-coins"></i>
                        </button>
                        <a href="user-detail.php?id=<?= $member['id'] ?>" class="px-3 py-1 bg-blue-100 text-blue-600 rounded-lg text-sm hover:bg-blue-200" title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูลสมาชิก</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t flex justify-between items-center">
        <p class="text-sm text-gray-500">แสดง <?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> จาก <?= $total ?> รายการ</p>
        <div class="flex gap-1">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&tier=<?= $tier ?>" 
                class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Points Modal -->
<div id="pointsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-bold mb-4"><i class="fas fa-coins text-yellow-500 mr-2"></i>จัดการแต้ม</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_points">
            <input type="hidden" name="user_id" id="modal_user_id">
            
            <p class="mb-2">สมาชิก: <b id="modal_user_name"></b></p>
            <p class="mb-4">แต้มปัจจุบัน: <b id="modal_current_points" class="text-purple-600"></b></p>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">จำนวนแต้ม (ใส่ - เพื่อหัก)</label>
                <input type="number" name="points" required class="w-full px-4 py-2 border rounded-lg" placeholder="เช่น 100 หรือ -50">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <input type="text" name="description" class="w-full px-4 py-2 border rounded-lg" placeholder="เหตุผลในการเพิ่ม/หักแต้ม">
            </div>
            
            <div class="flex gap-2">
                <button type="button" onclick="closePointsModal()" class="flex-1 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPointsModal(userId, name, points) {
    document.getElementById('modal_user_id').value = userId;
    document.getElementById('modal_user_name').textContent = name;
    document.getElementById('modal_current_points').textContent = points.toLocaleString();
    document.getElementById('pointsModal').classList.remove('hidden');
    document.getElementById('pointsModal').classList.add('flex');
}

function closePointsModal() {
    document.getElementById('pointsModal').classList.add('hidden');
    document.getElementById('pointsModal').classList.remove('flex');
}
</script>

<?php require_once 'includes/footer.php'; ?>
