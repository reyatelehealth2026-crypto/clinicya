<?php
/**
 * Drip Campaigns - ระบบส่งข้อความอัตโนมัติตามลำดับ
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/CRMManager.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Drip Campaigns';

// Handle actions (before header to allow redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_campaign') {
        $campaignId = (int)$_POST['campaign_id'];
        $stmt = $db->prepare("UPDATE drip_campaigns SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$campaignId]);
        header('Location: drip-campaigns.php');
        exit;
    }
    
    if ($action === 'delete_campaign') {
        $campaignId = (int)$_POST['campaign_id'];
        $stmt = $db->prepare("DELETE FROM drip_campaigns WHERE id = ?");
        $stmt->execute([$campaignId]);
        header('Location: drip-campaigns.php?success=deleted');
        exit;
    }
}

// Include header to get $currentBotId
require_once 'includes/header.php';

// Initialize CRM after header
$crm = new CRMManager($db, $currentBotId ?? null);

// Handle create campaign (after header because it needs $crm)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_campaign') {
    $name = trim($_POST['name'] ?? '');
    $triggerType = $_POST['trigger_type'] ?? 'follow';
    
    if ($name) {
        $campaignId = $crm->createCampaign($name, $triggerType);
        echo "<script>window.location.href='drip-campaign-edit.php?id={$campaignId}';</script>";
        exit;
    }
}

$campaigns = $crm->getCampaigns();
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h2 class="text-2xl font-bold">📧 Drip Campaigns</h2>
        <p class="text-gray-600">ส่งข้อความอัตโนมัติตามลำดับเวลา</p>
    </div>
    <button onclick="openCreateModal()" class="btn-primary">
        <i class="fas fa-plus mr-2"></i>สร้าง Campaign
    </button>
</div>

<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Campaign</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trigger</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Steps</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Active Users</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($campaigns as $campaign): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4">
                    <a href="drip-campaign-edit.php?id=<?= $campaign['id'] ?>" class="font-medium text-blue-600 hover:text-blue-800">
                        <?= htmlspecialchars($campaign['name']) ?>
                    </a>
                </td>
                <td class="px-6 py-4">
                    <?php
                    $triggerIcons = [
                        'follow' => '👋 Follow',
                        'tag_added' => '🏷️ Tag Added',
                        'purchase' => '🛒 Purchase',
                        'no_purchase' => '❌ No Purchase',
                        'inactivity' => '😴 Inactivity'
                    ];
                    echo $triggerIcons[$campaign['trigger_type']] ?? $campaign['trigger_type'];
                    ?>
                </td>
                <td class="px-6 py-4 text-center"><?= $campaign['step_count'] ?></td>
                <td class="px-6 py-4 text-center"><?= number_format($campaign['active_users']) ?></td>
                <td class="px-6 py-4 text-center">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle_campaign">
                        <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                        <button type="submit" class="px-3 py-1 rounded-full text-sm <?= $campaign['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
                            <?= $campaign['is_active'] ? '✅ Active' : '⏸️ Paused' ?>
                        </button>
                    </form>
                </td>
                <td class="px-6 py-4 text-center">
                    <a href="drip-campaign-edit.php?id=<?= $campaign['id'] ?>" class="text-blue-500 hover:text-blue-700 mr-3">
                        <i class="fas fa-edit"></i>
                    </a>
                    <form method="POST" class="inline" onsubmit="return confirm('ลบ Campaign นี้?')">
                        <input type="hidden" name="action" value="delete_campaign">
                        <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                        <button type="submit" class="text-red-500 hover:text-red-700">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            
            <?php if (empty($campaigns)): ?>
            <tr>
                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                    <i class="fas fa-mail-bulk text-5xl text-gray-300 mb-4"></i>
                    <p>ยังไม่มี Drip Campaign</p>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Create Campaign Modal -->
<div id="createModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="p-6 border-b">
            <h3 class="text-xl font-semibold">📧 สร้าง Drip Campaign</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="create_campaign">
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">ชื่อ Campaign</label>
                <input type="text" name="name" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="เช่น Welcome Series, Re-engagement">
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">Trigger (เริ่มเมื่อ)</label>
                <select name="trigger_type" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    <option value="follow">👋 ผู้ใช้ Follow</option>
                    <option value="tag_added">🏷️ ได้รับ Tag</option>
                    <option value="purchase">🛒 ซื้อสินค้า</option>
                    <option value="no_purchase">❌ ทักแต่ไม่ซื้อ</option>
                    <option value="inactivity">😴 ไม่มี Activity</option>
                </select>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeCreateModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">สร้าง</button>
            </div>
        </form>
    </div>
</div>

<!-- Info Box -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4">
    <h4 class="font-semibold text-blue-800 mb-2">💡 Drip Campaign คืออะไร?</h4>
    <p class="text-blue-700 text-sm">
        Drip Campaign คือการส่งข้อความอัตโนมัติตามลำดับเวลา เช่น:
    </p>
    <ul class="text-blue-700 text-sm mt-2 space-y-1">
        <li>• <strong>Welcome Series:</strong> ส่งข้อความต้อนรับ → 1 ชม. ส่งแนะนำสินค้า → 1 วัน ส่งคูปอง</li>
        <li>• <strong>Re-engagement:</strong> ลูกค้าไม่ทักมา 7 วัน → ส่งข้อความถามไถ่ → 3 วัน ส่งโปรโมชั่น</li>
        <li>• <strong>Post-Purchase:</strong> ซื้อสินค้า → 3 วัน ถามความพอใจ → 7 วัน ขอรีวิว</li>
    </ul>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
}
function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
}
</script>

<?php require_once 'includes/footer.php'; ?>
