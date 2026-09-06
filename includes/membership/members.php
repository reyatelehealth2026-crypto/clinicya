<?php
/**
 * Members Tab Content - จัดการสมาชิก
 * Part of membership.php consolidated page
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// This file is included from membership.php
// Variables available: $db, $lineAccountId, $adminId

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['member_action'])) {
    $action = $_POST['member_action'];

    if ($action === 'update_tier') {
        $userId = (int) $_POST['user_id'];
        $tier = $_POST['tier'];

        $stmt = $db->prepare("UPDATE users SET member_tier = ? WHERE id = ?");
        $stmt->execute([$tier, $userId]);

        $message = 'อัพเดทระดับสมาชิกสำเร็จ!';
        $messageType = 'success';
    }
}

// Filters
// Reject array input (?tier[]=x): $tier is echoed into the pagination link and
// $search is passed to urlencode(), which throws a TypeError on an array.
$search = is_string($_GET['search'] ?? '') ? $_GET['search'] : '';
$tier = is_string($_GET['tier'] ?? '') ? $_GET['tier'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
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
    // available_points, not points — see ADR-008; the tier counts below are
    // derived from the balance, and `points` holds withdrawn welcome bonuses.
    $hasPoints = in_array('available_points', $cols);
    $hasMemberId = in_array('member_id', $cols);
} catch (Exception $e) {
}

// Build query based on available columns
$where = "WHERE 1=1";
if ($hasIsRegistered) {
    $where = "WHERE is_registered = 1";
}
$params = [];

if ($search) {
    $searchFields = ["first_name LIKE ?", "last_name LIKE ?", "phone LIKE ?", "display_name LIKE ?", "real_name LIKE ?", "email LIKE ?"];
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

// Get tiers from TierService (unified source)
$allTiers = [];
try {
    require_once __DIR__ . '/../../classes/TierService.php';
    $tierService = new TierService($db, $lineAccountId ?? null);
    $allTiers = $tierService->getTiers();
} catch (Exception $e) {
    // Use TierService defaults
    $allTiers = TierService::DEFAULT_TIERS;
}
?>

<?php if ($message): ?>
    <div
        class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
        <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-times-circle' ?> mr-2"></i><?= $message ?>
    </div>
<?php endif; ?>

<!-- Stats - Dynamic from TierService -->
<div class="grid grid-cols-2 md:grid-cols-<?= min(6, count($allTiers) + 1) ?> gap-4 mb-6">
    <?php
    // Get total members count
    $totalQuery = "SELECT COUNT(*) as total FROM users";
    if ($hasIsRegistered) {
        $totalQuery .= " WHERE is_registered = 1";
    }
    $total = $db->query($totalQuery)->fetch()['total'];

    // Count members per tier by calculating from points
    $tierCounts = [];
    foreach ($allTiers as $tier) {
        $tierCounts[$tier['tier_code']] = 0;
    }

    if ($hasPoints) {
        // Get all members with points and count per tier
        $membersQuery = "SELECT available_points FROM users";
        if ($hasIsRegistered) {
            $membersQuery .= " WHERE is_registered = 1";
        }
        $allMembers = $db->query($membersQuery)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allMembers as $member) {
            $memberPoints = (int) ($member['available_points'] ?? 0);
            $memberTier = $tierService->calculateTier($memberPoints);
            $tierCode = $memberTier['tier_code'];
            if (isset($tierCounts[$tierCode])) {
                $tierCounts[$tierCode]++;
            }
        }
    }
    ?>
    <div class="bg-white rounded-xl shadow p-4">
        <p class="text-gray-500 text-sm">สมาชิกทั้งหมด</p>
        <p class="text-2xl font-bold text-gray-800"><?= number_format($total) ?></p>
    </div>
    <?php foreach ($allTiers as $tier):
        $tierCode = $tier['tier_code'];
        $tierName = $tier['tier_name'];
        $tierColor = $tier['color'] ?? '#6B7280';
        $tierIcon = $tier['icon'] ?? '🏅';
        $count = $tierCounts[$tierCode] ?? 0;
        ?>
        <div class="rounded-xl shadow p-4 text-white"
            style="background: linear-gradient(135deg, <?= $tierColor ?>, <?= $tierColor ?>cc);">
            <p class="text-white/70 text-sm"><?= $tierIcon ?>     <?= htmlspecialchars($tierName) ?></p>
            <p class="text-2xl font-bold"><?= number_format($count) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <input type="hidden" name="tab" value="members">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
            placeholder="ค้นหาชื่อ, รหัสสมาชิก, เบอร์โทร..." class="flex-1 min-w-[200px] px-4 py-2 border rounded-lg">
        <select name="tier" class="px-4 py-2 border rounded-lg">
            <option value="">ทุกระดับ</option>
            <?php foreach ($allTiers as $t): ?>
                <option value="<?= $t['tier_code'] ?>" <?= $tier === $t['tier_code'] ? 'selected' : '' ?>>
                    <?= $t['icon'] ?? '🏅' ?>     <?= htmlspecialchars($t['tier_name']) ?>
                </option>
            <?php endforeach; ?>
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
                                <img src="<?= $member['picture_url'] ?: 'https://via.placeholder.com/40' ?>"
                                    class="w-10 h-10 rounded-full object-cover">
                                <div>
                                    <p class="font-medium text-gray-800">
                                        <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                                    </p>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($member['display_name'] ?? '') ?>
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 font-mono text-sm"><?= $hasMemberId ? ($member['member_id'] ?? '-') : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php
                            // available_points is the balance; `points` was dropped
                            // from this chain — it holds only withdrawn welcome
                            // bonuses (ADR-008).
                            $displayPoints = $member['available_points'] ?? $member['total_points'] ?? 0;

                            // Calculate tier dynamicallly
                            $tierInfo = $tierService->calculateTier($displayPoints);
                            $tierName = $tierInfo['tier_name'];
                            $tierColor = $tierInfo['color'];
                            $tierIcon = $tierInfo['icon'];
                            ?>
                            <span class="px-2 py-1 rounded-full text-xs font-medium border"
                                style="background-color: <?= $tierColor ?>15; color: <?= $tierColor ?>; border-color: <?= $tierColor ?>30;">
                                <?= $tierIcon ?>     <?= htmlspecialchars($tierName) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-bold text-purple-600">
                            <?= number_format($displayPoints) ?>
                        </td>
                        <td class="px-4 py-3 text-sm"><?= $member['phone'] ?: '-' ?></td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            <?= ($hasRegisteredAt && $member['registered_at']) ? date('d/m/Y', strtotime($member['registered_at'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="user-detail.php?id=<?= $member['id'] ?>"
                                class="px-3 py-1.5 bg-blue-100 text-blue-600 rounded-lg text-sm hover:bg-blue-200 inline-flex items-center gap-1"
                                title="ดูรายละเอียด / จัดการแต้ม">
                                <i class="fas fa-eye"></i>
                                <span class="hidden sm:inline">ดูรายละเอียด</span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูลสมาชิก</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="px-4 py-3 border-t flex justify-between items-center">
            <p class="text-sm text-gray-500">แสดง <?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> จาก
                <?= $total ?> รายการ
            </p>
            <div class="flex gap-1">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?tab=members&page=<?= $i ?>&search=<?= urlencode($search) ?>&tier=<?= $tier ?>"
                        class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>