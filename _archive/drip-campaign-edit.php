<?php
/**
 * Edit Drip Campaign - แก้ไข Steps ของ Campaign
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/CRMManager.php';

$db = Database::getInstance()->getConnection();
$crm = new CRMManager($db, $currentBotId);

$campaignId = (int)($_GET['id'] ?? 0);
if (!$campaignId) {
    header('Location: drip-campaigns.php');
    exit;
}

// Get campaign
$stmt = $db->prepare("SELECT * FROM drip_campaigns WHERE id = ?");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    header('Location: drip-campaigns.php');
    exit;
}

$pageTitle = 'Edit: ' . $campaign['name'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_step') {
        $stepOrder = (int)$_POST['step_order'];
        $delayMinutes = (int)$_POST['delay_minutes'];
        $messageType = $_POST['message_type'] ?? 'text';
        $content = trim($_POST['content'] ?? '');
        
        if ($content) {
            $crm->addCampaignStep($campaignId, $stepOrder, $delayMinutes, $messageType, $content);
            header("Location: drip-campaign-edit.php?id={$campaignId}&success=added");
            exit;
        }
    }
    
    if ($action === 'delete_step') {
        $stepId = (int)$_POST['step_id'];
        $stmt = $db->prepare("DELETE FROM drip_campaign_steps WHERE id = ? AND campaign_id = ?");
        $stmt->execute([$stepId, $campaignId]);
        header("Location: drip-campaign-edit.php?id={$campaignId}&success=deleted");
        exit;
    }
    
    if ($action === 'update_campaign') {
        $name = trim($_POST['name'] ?? '');
        $triggerType = $_POST['trigger_type'] ?? 'follow';
        
        if ($name) {
            $stmt = $db->prepare("UPDATE drip_campaigns SET name = ?, trigger_type = ? WHERE id = ?");
            $stmt->execute([$name, $triggerType, $campaignId]);
            header("Location: drip-campaign-edit.php?id={$campaignId}&success=updated");
            exit;
        }
    }
}

$steps = $crm->getCampaignSteps($campaignId);
$nextStepOrder = count($steps) + 1;

require_once 'includes/header.php';
?>

<div class="mb-6">
    <a href="drip-campaigns.php" class="text-gray-600 hover:text-gray-800">
        <i class="fas fa-arrow-left mr-2"></i>กลับ
    </a>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
    บันทึกสำเร็จ!
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Campaign Settings -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-lg mb-4">⚙️ Campaign Settings</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update_campaign">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">ชื่อ</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($campaign['name']) ?>" required class="w-full px-4 py-2 border rounded-lg">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Trigger</label>
                    <select name="trigger_type" class="w-full px-4 py-2 border rounded-lg">
                        <option value="follow" <?= $campaign['trigger_type'] === 'follow' ? 'selected' : '' ?>>👋 Follow</option>
                        <option value="tag_added" <?= $campaign['trigger_type'] === 'tag_added' ? 'selected' : '' ?>>🏷️ Tag Added</option>
                        <option value="purchase" <?= $campaign['trigger_type'] === 'purchase' ? 'selected' : '' ?>>🛒 Purchase</option>
                        <option value="no_purchase" <?= $campaign['trigger_type'] === 'no_purchase' ? 'selected' : '' ?>>❌ No Purchase</option>
                        <option value="inactivity" <?= $campaign['trigger_type'] === 'inactivity' ? 'selected' : '' ?>>😴 Inactivity</option>
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
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold text-lg">📝 Message Steps</h3>
                <button onclick="openAddStepModal()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm">
                    <i class="fas fa-plus mr-1"></i>เพิ่ม Step
                </button>
            </div>
            
            <?php if (empty($steps)): ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-list-ol text-4xl text-gray-300 mb-3"></i>
                <p>ยังไม่มี Steps</p>
                <button onclick="openAddStepModal()" class="mt-3 text-blue-600 hover:text-blue-700">
                    <i class="fas fa-plus mr-1"></i>เพิ่ม Step แรก
                </button>
            </div>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($steps as $index => $step): ?>
                <div class="border rounded-lg p-4 relative">
                    <!-- Timeline connector -->
                    <?php if ($index < count($steps) - 1): ?>
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

<!-- Add Step Modal -->
<div id="addStepModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
        <div class="p-6 border-b">
            <h3 class="text-xl font-semibold">📝 เพิ่ม Step</h3>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="add_step">
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

<script>
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
document.querySelector('[name="delay_value"]').addEventListener('input', updateDelayMinutes);
</script>

<?php require_once 'includes/footer.php'; ?>
