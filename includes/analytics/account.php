<?php
/**
 * Account Analytics Tab Content
 * สถิติแยกตามบอทแต่ละตัว
 * 
 * Variables expected from parent:
 * - $db: Database connection
 */

require_once __DIR__ . '/../../classes/LineAccountManager.php';

$manager = new LineAccountManager($db);
$accounts = $manager->getAllAccounts();

// Get selected account
$selectedAccountId = $_GET['account_id'] ?? null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get account info
$selectedAccount = null;
if ($selectedAccountId) {
    $selectedAccount = $manager->getAccountById($selectedAccountId);
}

// Get followers for selected account
$followers = [];
$followerStats = ['total' => 0, 'active' => 0, 'unfollowed' => 0];
$recentEvents = [];
$dailyStats = [];

if ($selectedAccountId) {
    // Get followers
    $stmt = $db->prepare("
        SELECT af.*, u.display_name as current_name, u.picture_url as current_picture
        FROM account_followers af
        LEFT JOIN users u ON af.user_id = u.id
        WHERE af.line_account_id = ?
        ORDER BY af.followed_at DESC
        LIMIT 100
    ");
    $stmt->execute([$selectedAccountId]);
    $followers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get follower stats
    $stmt = $db->prepare("SELECT COUNT(*) FROM account_followers WHERE line_account_id = ?");
    $stmt->execute([$selectedAccountId]);
    $followerStats['total'] = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM account_followers WHERE line_account_id = ? AND is_following = 1");
    $stmt->execute([$selectedAccountId]);
    $followerStats['active'] = $stmt->fetchColumn();
    
    $followerStats['unfollowed'] = $followerStats['total'] - $followerStats['active'];
    
    // Get recent events
    $stmt = $db->prepare("
        SELECT ae.*, u.display_name
        FROM account_events ae
        LEFT JOIN users u ON ae.user_id = u.id
        WHERE ae.line_account_id = ?
        ORDER BY ae.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$selectedAccountId]);
    $recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get daily stats
    $stmt = $db->prepare("
        SELECT * FROM account_daily_stats 
        WHERE line_account_id = ? AND stat_date BETWEEN ? AND ?
        ORDER BY stat_date DESC
    ");
    $stmt->execute([$selectedAccountId, $dateFrom, $dateTo]);
    $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<h3 class="text-xl font-bold mb-6">📊 สถิติแยกตามบอท</h3>

<!-- Account Selector -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <input type="hidden" name="tab" value="account">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium text-gray-700 mb-1">เลือกบอท</label>
            <select name="account_id" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
                <option value="">-- เลือกบอท --</option>
                <?php foreach ($accounts as $acc): ?>
                <option value="<?= $acc['id'] ?>" <?= $selectedAccountId == $acc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($acc['name']) ?> <?= $acc['is_default'] ? '(หลัก)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">จากวันที่</label>
            <input type="date" name="date_from" value="<?= $dateFrom ?>" class="border rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ถึงวันที่</label>
            <input type="date" name="date_to" value="<?= $dateTo ?>" class="border rounded-lg px-3 py-2">
        </div>
        <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
            🔍 ดูข้อมูล
        </button>
    </form>
</div>

<?php if ($selectedAccount): ?>
<!-- Account Info -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <div class="flex items-center gap-4">
        <?php if ($selectedAccount['picture_url']): ?>
        <img src="<?= htmlspecialchars($selectedAccount['picture_url']) ?>" class="w-16 h-16 rounded-full">
        <?php else: ?>
        <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center text-2xl">🤖</div>
        <?php endif; ?>
        <div>
            <h2 class="text-xl font-bold"><?= htmlspecialchars($selectedAccount['name']) ?></h2>
            <p class="text-gray-500"><?= htmlspecialchars($selectedAccount['basic_id'] ?? '-') ?></p>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-3xl font-bold text-green-500"><?= number_format($followerStats['active']) ?></div>
        <div class="text-gray-500">ผู้ติดตามปัจจุบัน</div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-3xl font-bold text-blue-500"><?= number_format($followerStats['total']) ?></div>
        <div class="text-gray-500">ผู้ติดตามทั้งหมด</div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-3xl font-bold text-red-500"><?= number_format($followerStats['unfollowed']) ?></div>
        <div class="text-gray-500">ยกเลิกติดตาม</div>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <div class="text-3xl font-bold text-purple-500"><?= count($recentEvents) ?></div>
        <div class="text-gray-500">Events ล่าสุด</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Followers -->
    <div class="bg-white rounded-lg shadow">
        <div class="p-4 border-b">
            <h3 class="font-bold">👥 ผู้ติดตามล่าสุด</h3>
        </div>
        <div class="p-4 max-h-96 overflow-y-auto">
            <?php if (empty($followers)): ?>
            <p class="text-gray-500 text-center py-4">ยังไม่มีข้อมูล</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($followers as $f): ?>
                <div class="flex items-center gap-3 p-2 rounded-lg <?= $f['is_following'] ? 'bg-green-50' : 'bg-red-50' ?>">
                    <img src="<?= htmlspecialchars($f['current_picture'] ?: $f['picture_url'] ?: 'https://via.placeholder.com/40') ?>" 
                         class="w-10 h-10 rounded-full">
                    <div class="flex-1">
                        <div class="font-medium"><?= htmlspecialchars($f['current_name'] ?: $f['display_name'] ?: 'Unknown') ?></div>
                        <div class="text-xs text-gray-500">
                            Follow: <?= date('d/m/Y H:i', strtotime($f['followed_at'])) ?>
                            <?php if (!$f['is_following']): ?>
                            <span class="text-red-500">| Unfollow: <?= date('d/m/Y H:i', strtotime($f['unfollowed_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-gray-400">
                            ข้อความ: <?= number_format($f['total_messages']) ?> | Follow ครั้งที่: <?= $f['follow_count'] ?>
                        </div>
                    </div>
                    <span class="px-2 py-1 rounded text-xs <?= $f['is_following'] ? 'bg-green-500 text-white' : 'bg-red-500 text-white' ?>">
                        <?= $f['is_following'] ? 'Active' : 'Left' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Events -->
    <div class="bg-white rounded-lg shadow">
        <div class="p-4 border-b">
            <h3 class="font-bold">📋 Events ล่าสุด</h3>
        </div>
        <div class="p-4 max-h-96 overflow-y-auto">
            <?php if (empty($recentEvents)): ?>
            <p class="text-gray-500 text-center py-4">ยังไม่มีข้อมูล</p>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($recentEvents as $ev): ?>
                <div class="flex items-center gap-3 p-2 border-b">
                    <span class="text-xl">
                        <?php
                        $icons = [
                            'follow' => '➕',
                            'unfollow' => '➖',
                            'message' => '💬',
                            'postback' => '🔘',
                            'beacon' => '📡'
                        ];
                        echo $icons[$ev['event_type']] ?? '📌';
                        ?>
                    </span>
                    <div class="flex-1">
                        <div class="font-medium"><?= htmlspecialchars($ev['display_name'] ?: $ev['line_user_id']) ?></div>
                        <div class="text-xs text-gray-500">
                            <?= ucfirst($ev['event_type']) ?> | <?= date('d/m/Y H:i:s', strtotime($ev['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Daily Stats Table -->
<?php if (!empty($dailyStats)): ?>
<div class="bg-white rounded-lg shadow mt-6">
    <div class="p-4 border-b">
        <h3 class="font-bold">📈 สถิติรายวัน</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">วันที่</th>
                    <th class="px-4 py-2 text-center">ผู้ติดตามใหม่</th>
                    <th class="px-4 py-2 text-center">ยกเลิกติดตาม</th>
                    <th class="px-4 py-2 text-center">ข้อความขาเข้า</th>
                    <th class="px-4 py-2 text-center">ข้อความขาออก</th>
                    <th class="px-4 py-2 text-center">รวมข้อความ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyStats as $stat): ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-4 py-2"><?= date('d/m/Y', strtotime($stat['stat_date'])) ?></td>
                    <td class="px-4 py-2 text-center text-green-600">+<?= $stat['new_followers'] ?></td>
                    <td class="px-4 py-2 text-center text-red-600">-<?= $stat['unfollowers'] ?></td>
                    <td class="px-4 py-2 text-center"><?= $stat['incoming_messages'] ?></td>
                    <td class="px-4 py-2 text-center"><?= $stat['outgoing_messages'] ?></td>
                    <td class="px-4 py-2 text-center font-bold"><?= $stat['total_messages'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
    <div class="text-4xl mb-2">👆</div>
    <p class="text-yellow-700">กรุณาเลือกบอทเพื่อดูสถิติ</p>
</div>
<?php endif; ?>
