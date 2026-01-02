<?php
/**
 * Drip Campaigns - ระบบส่งข้อความอัตโนมัติตามลำดับ (Consolidated)
 * รวม: Campaign List + Campaign Edit (Modal)
 * 
 * @package FileConsolidation
 * @version 2.0.0
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
        header("Location: drip-campaigns.php?edit={$campaignId}&success=created");
        exit;
    }
}

// Handle step actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $editCampaignId = (int)($_POST['campaign_id'] ?? $_GET['edit'] ?? 0);
    
    if ($action === 'add_step' && $editCampaignId) {
        $stepOrder = (int)$_POST['step_order'];
        $delayMinutes = (int)$_POST['delay_minutes'];
        $messageType = $_POST['message_type'] ?? 'text';
        $content = trim($_POST['content'] ?? '');
        
        if ($content) {
            $crm->addCampaignStep($editCampaignId, $stepOrder, $delayMinutes, $messageType, $content);
            header("Location: drip-campaigns.php?edit={$editCampaignId}&success=step_added");
            exit;
        }
    }
    
    if ($action === 'delete_step' && $editCampaignId) {
        $stepId = (int)$_POST['step_id'];
        $stmt = $db->prepare("DELETE FROM drip_campaign_steps WHERE id = ? AND campaign_id = ?");
        $stmt->execute([$stepId, $editCampaignId]);
        header("Location: drip-campaigns.php?edit={$editCampaignId}&success=step_deleted");
        exit;
    }
    
    if ($action === 'update_campaign' && $editCampaignId) {
        $name = trim($_POST['name'] ?? '');
        $triggerType = $_POST['trigger_type'] ?? 'follow';
        
        if ($name) {
            $stmt = $db->prepare("UPDATE drip_campaigns SET name = ?, trigger_type = ? WHERE id = ?");
            $stmt->execute([$name, $triggerType, $editCampaignId]);
            header("Location: drip-campaigns.php?edit={$editCampaignId}&success=updated");
            exit;
        }
    }
}

$campaigns = $crm->getCampaigns();

// Check if editing a campaign
$editCampaignId = (int)($_GET['edit'] ?? 0);
$editCampaign = null;
$editSteps = [];
$nextStepOrder = 1;

if ($editCampaignId) {
    $stmt = $db->prepare("SELECT * FROM drip_campaigns WHERE id = ?");
    $stmt->execute([$editCampaignId]);
    $editCampaign = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($editCampaign) {
        $editSteps = $crm->getCampaignSteps($editCampaignId);
        $nextStepOrder = count($editSteps) + 1;
    }
}
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

<?php if (isset($_GET['success'])): ?>
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
    <i class="fas fa-check-circle mr-2"></i>บันทึกสำเร็จ!
</div>
<?php endif; ?>

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
                    <button onclick="openEditModal(<?= $campaign['id'] ?>)" class="font-medium text-blue-600 hover:text-blue-800">
                        <?= htmlspecialchars($campaign['name']) ?>
                    </button>
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
                    <button onclick="openEditModal(<?= $campaign['id'] ?>)" class="text-blue-500 hover:text-blue-700 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
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

<!-- Edit Campaign Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 <?= $editCampaign ? 'flex' : 'hidden' ?> items-center justify-center overflow-y-auto">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl mx-4 my-8 max-h-[90vh] overflow-y-auto">
        <?php if ($editCampaign): ?>
        <div class="p-6 border-b flex justify-between items-center sticky top-0 bg-white z-10">
            <div>
                <h3 class="text-xl font-semibold">📝 แก้ไข Campaign</h3>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($editCampaign['name']) ?></p>
            </div>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Campaign Settings -->
                <div class="lg:col-span-1">
                    <div class="bg-gray-50 rounded-xl p-6">
                        <h4 class="font-semibold text-lg mb-4">⚙️ Campaign Settings</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_campaign">
                            <input type="hidden" name="campaign_id" value="<?= $editCampaign['id'] ?>">
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium mb-2">ชื่อ</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editCampaign['name']) ?>" required class="w-full px-4 py-2 border rounded-lg">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium mb-2">Trigger</label>
                                <select name="trigger_type" class="w-full px-4 py-2 border rounded-lg">
                                    <option value="follow" <?= $editCampaign['trigger_type'] === 'follow' ? 'selected' : '' ?>>👋 Follow</option>
                                    <option value="tag_added" <?= $editCampaign['trigger_type'] === 'tag_added' ? 'selected' : '' ?>>🏷️ Tag Added</option>
                                    <option value="purchase" <?= $editCampaign['trigger_type'] === 'purchase' ? 'selected' : '' ?>>🛒 Purchase</option>
                                    <option value="no_purchase" <?= $editCampaign['trigger_type'] === 'no_purchase' ? 'selected' : '' ?>>❌ No Purchase</option>
                                    <option value="inactivity" <?= $editCampaign['trigger_type'] === 'inactivity' ? 'selected' : '' ?>>😴 Inactivity</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="w-full px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                                บันทึก
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Steps -->
                <div class="lg:col-span-2">
                    <div class="bg-gray-50 rounded-xl p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="font-semibold text-lg">📝 Message Steps</h4>
                            <button onclick="openAddStepModal()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm">
                                <i class="fas fa-plus mr-1"></i>เพิ่ม Step
                            </button>
                        </div>
                        
                        <?php if (empty($editSteps)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-list-ol text-4xl text-gray-300 mb-3"></i>
                            <p>ยังไม่มี Steps</p>
                            <button onclick="openAddStepModal()" class="mt-3 text-blue-600 hover:text-blue-700">
                                <i class="fas fa-plus mr-1"></i>เพิ่ม Step แรก
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($editSteps as $index => $step): ?>
                            <div class="bg-white border rounded-lg p-4 relative">
                                <!-- Timeline connector -->
                                <?php if ($index < count($editSteps) - 1): ?>
                                <div class="absolute left-8 top-full w-0.5 h-4 bg-gray-300"></div>
                                <?php endif; ?>
                                
                                <div class="flex items-start gap-4">
                                    <div class="w-12 h-12 bg-green-100 text-green-700 rounded-full flex items-center justify-center font-bold flex-shrink-0">
                                        <?= $step['step_order'] ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="text-sm text-gray-500">
                                                <i class="fas fa-clock mr-1"></i>
                                                <?php
                                                $delay = $step['delay_minutes'];
                                                if ($delay === 0) echo 'ทันที';
                                                elseif ($delay < 60) echo "{$delay} นาที";
                                                elseif ($delay < 1440) echo floor($delay / 60) . ' ชั่วโมง';
                                                else echo floor($delay / 1440) . ' วัน';
                                                ?>
                                            </span>
                                            <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs">
                                                <?= $step['message_type'] ?>
                                            </span>
                                        </div>
                                        <div class="bg-gray-50 rounded-lg p-3 text-sm">
                                            <?php if ($step['message_type'] === 'flex'): ?>
                                            <span class="text-purple-600"><i class="fas fa-cube mr-1"></i>Flex Message</span>
                                            <?php else: ?>
                                            <?= nl2br(htmlspecialchars(mb_substr($step['message_content'], 0, 200))) ?>
                                            <?php if (mb_strlen($step['message_content']) > 200): ?>...<?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('ลบ Step นี้?')">
                                        <input type="hidden" name="action" value="delete_step">
                                        <input type="hidden" name="campaign_id" value="<?= $editCampaign['id'] ?>">
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="p-6 text-center text-gray-500">
            <p>ไม่พบ Campaign</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Step Modal -->
<div id="addStepModal" class="fixed inset-0 bg-black/50 z-[60] hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
        <div class="p-6 border-b">
            <h3 class="text-xl font-semibold">📝 เพิ่ม Step</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add_step">
            <input type="hidden" name="campaign_id" value="<?= $editCampaignId ?>">
            <input type="hidden" name="step_order" value="<?= $nextStepOrder ?>">
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">ส่งหลังจาก Step ก่อนหน้า</label>
                <div class="flex gap-2">
                    <input type="number" name="delay_value" value="0" min="0" class="w-24 px-4 py-2 border rounded-lg">
                    <select name="delay_unit" id="delayUnit" class="px-4 py-2 border rounded-lg" onchange="updateDelayMinutes()">
                        <option value="0">ทันที</option>
                        <option value="1">นาที</option>
                        <option value="60">ชั่วโมง</option>
                        <option value="1440">วัน</option>
                    </select>
                    <input type="hidden" name="delay_minutes" id="delayMinutes" value="0">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-2">ประเภทข้อความ</label>
                <select name="message_type" class="w-full px-4 py-2 border rounded-lg">
                    <option value="text">Text</option>
                    <option value="flex">Flex Message (JSON)</option>
                </select>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">เนื้อหา</label>
                <textarea name="content" rows="5" required class="w-full px-4 py-2 border rounded-lg" placeholder="พิมพ์ข้อความ หรือวาง Flex JSON"></textarea>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="closeAddStepModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">เพิ่ม Step</button>
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

function openEditModal(campaignId) {
    window.location.href = 'drip-campaigns.php?edit=' + campaignId;
}
function closeEditModal() {
    window.location.href = 'drip-campaigns.php';
}

function openAddStepModal() {
    document.getElementById('addStepModal').classList.remove('hidden');
    document.getElementById('addStepModal').classList.add('flex');
}
function closeAddStepModal() {
    document.getElementById('addStepModal').classList.add('hidden');
    document.getElementById('addStepModal').classList.remove('flex');
}

function updateDelayMinutes() {
    const value = parseInt(document.querySelector('[name="delay_value"]').value) || 0;
    const unit = parseInt(document.getElementById('delayUnit').value) || 0;
    document.getElementById('delayMinutes').value = value * unit;
}

// Add event listener for delay value input
const delayValueInput = document.querySelector('[name="delay_value"]');
if (delayValueInput) {
    delayValueInput.addEventListener('input', updateDelayMinutes);
}

// Close modals on outside click
document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
document.getElementById('addStepModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddStepModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>
