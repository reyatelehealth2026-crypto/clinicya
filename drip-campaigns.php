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
require_once 'classes/DripCampaignService.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/data-table.php';
require_once __DIR__ . '/includes/components/empty-state.php';
require_once __DIR__ . '/includes/components/modal.php';
require_once __DIR__ . '/includes/components/toast.php';

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

// Initialize services after header
$crm = new CRMManager($db, $currentBotId ?? null);
$dripService = new DripCampaignService($db, $currentBotId ?? null);

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

$campaigns = $dripService->listCampaignsWithStats();
$queueSummary = $dripService->getQueueSummary();

// Check if editing a campaign
$editCampaignId = (int)($_GET['edit'] ?? 0);
$editCampaign = null;
$editSteps = [];
$nextStepOrder = 1;

if ($editCampaignId) {
    $editCampaign = $dripService->getCampaign($editCampaignId);
    
    if ($editCampaign) {
        $editSteps = $dripService->getCampaignSteps($editCampaignId);
        $nextStepOrder = count($editSteps) + 1;
    }
}
?>

<?= getPageHeaderStyles() ?>
<?= getDataTableStyles() ?>
<?= getEmptyStateStyles() ?>
<?= getModalStyles() ?>
<?= getToastStyles() ?>

<style>
.drip-info-box {
    background: var(--color-primary-50);
    border: 1px solid var(--color-primary-100);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-4, 16px);
    margin-top: var(--space-6, 24px);
}
.drip-info-box h4 { font-weight: 600; color: var(--color-primary-800, #3730a3); margin-bottom: var(--space-2, 8px); }
.drip-info-box p, .drip-info-box li { font-size: var(--text-sm, 14px); color: var(--color-primary-700, #4338ca); }
.drip-info-box ul { margin-top: var(--space-2, 8px); padding-left: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; }
.step-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 48px; height: 48px; border-radius: var(--radius-full, 9999px);
    background: var(--color-emerald-100); color: var(--color-emerald-700);
    font-weight: 700; font-size: var(--text-base, 16px); flex-shrink: 0;
}
.step-card {
    background: #ffffff; border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px); padding: var(--space-4, 16px);
    position: relative;
}
.step-connector {
    position: absolute; left: 24px; top: 100%;
    width: 2px; height: 16px; background: var(--color-slate-300);
}
.step-preview {
    background: var(--color-slate-50); border-radius: var(--radius-sm, 8px);
    padding: var(--space-3, 12px); font-size: var(--text-sm, 14px);
    color: var(--color-dark-700); margin-top: var(--space-2, 8px);
}
.dc-settings-panel {
    background: var(--color-slate-50); border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px); padding: var(--space-4, 16px);
}
.dc-settings-panel h4 { font-weight: 600; font-size: var(--text-base, 16px); color: var(--color-dark-800); margin-bottom: var(--space-4, 16px); }
.dc-field { margin-bottom: var(--space-4, 16px); }
.dc-field label { display: block; font-size: var(--text-sm, 14px); font-weight: 500; color: var(--color-dark-800); margin-bottom: 6px; }
.dc-input {
    width: 100%; padding: 10px var(--space-3, 12px); border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px); font-size: var(--text-sm, 14px); color: var(--color-dark-800);
    background: #ffffff; transition: all var(--transition-fast, 150ms ease); box-sizing: border-box;
}
.dc-input:focus { outline: none; border-color: var(--color-primary-400); box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }
.dc-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px var(--space-4, 16px);
    border-radius: var(--radius-md, 12px); font-size: var(--text-sm, 14px); font-weight: 600;
    cursor: pointer; border: 1px solid var(--color-slate-200); background: #ffffff; color: var(--color-dark-700);
    transition: all var(--transition-fast, 150ms ease);
}
.dc-btn:hover { background: var(--color-slate-50); }
.dc-btn-primary { background: var(--color-emerald-500); border-color: var(--color-emerald-500); color: #ffffff; width: 100%; justify-content: center; }
.dc-btn-primary:hover { background: var(--color-emerald-600); border-color: var(--color-emerald-600); }
.dc-btn-blue { background: var(--color-primary-600); border-color: var(--color-primary-600); color: #ffffff; }
.dc-btn-blue:hover { background: var(--color-primary-700); }
.status-pill {
    display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
    border-radius: var(--radius-full, 9999px); font-size: var(--text-xs, 12px); font-weight: 600;
    border: none; cursor: pointer; transition: all var(--transition-fast, 150ms ease);
}
.status-pill-active { background: var(--color-emerald-100); color: var(--color-emerald-700); }
.status-pill-paused { background: var(--color-slate-100); color: var(--color-dark-600); }
.edit-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: var(--space-4, 16px) var(--space-5, 20px);
    border-bottom: 1px solid var(--color-slate-200);
    background: var(--color-slate-50);
    position: sticky; top: 0; z-index: 10;
    border-radius: var(--radius-lg, 16px) var(--radius-lg, 16px) 0 0;
}
.edit-panel-header h3 { font-size: var(--text-lg, 18px); font-weight: 600; color: var(--color-dark-800); margin: 0; }
.edit-panel-header p { font-size: var(--text-sm, 14px); color: var(--color-dark-500); margin: 2px 0 0 0; }
.close-btn {
    width: 32px; height: 32px; border-radius: var(--radius-sm, 8px); border: none;
    background: transparent; color: var(--color-dark-500); cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: all var(--transition-fast, 150ms ease);
}
.close-btn:hover { background: var(--color-slate-200); color: var(--color-dark-800); }
.edit-grid { display: grid; grid-template-columns: 1fr 2fr; gap: var(--space-6, 24px); padding: var(--space-5, 20px); }
@media (max-width: 768px) { .edit-grid { grid-template-columns: 1fr; } }
/* Dark mode */
.dark .drip-info-box { background: rgba(79,70,229,0.08); border-color: rgba(99,102,241,0.2); }
.dark .drip-info-box h4 { color: var(--color-primary-300); }
.dark .drip-info-box p, .dark .drip-info-box li { color: var(--color-primary-200); }
.dark .step-card { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .step-preview { background: var(--color-dark-900); color: var(--color-slate-300); }
.dark .dc-settings-panel { background: var(--color-dark-900); border-color: var(--color-dark-700); }
.dark .dc-settings-panel h4 { color: var(--color-slate-100); }
.dark .dc-input { background: var(--color-dark-800); border-color: var(--color-dark-700); color: var(--color-slate-100); }
.dark .dc-btn { background: var(--color-dark-700); border-color: var(--color-dark-600); color: var(--color-slate-300); }
.dark .edit-panel-header { background: var(--color-dark-900); border-color: var(--color-dark-700); }
.dark .edit-panel-header h3 { color: var(--color-slate-100); }
.dark .close-btn { color: var(--color-slate-400); }
.dark .close-btn:hover { background: var(--color-dark-700); color: var(--color-slate-100); }
</style>

<?= renderPageHeader(
    'Drip Campaigns',
    'ส่งข้อความอัตโนมัติตามลำดับเวลา',
    [
        'label'   => 'สร้าง Campaign',
        'icon'    => 'fas fa-plus',
        'onclick' => 'openCreateModal()',
        'variant' => 'success',
    ],
    [
        ['label' => 'Marketing', 'href' => null],
        ['label' => 'Drip Campaigns', 'href' => null],
    ]
) ?>

<?php if (isset($_GET['success'])): ?>
<div style="background:var(--color-emerald-50);border:1px solid var(--color-emerald-200);color:var(--color-emerald-700);padding:12px 16px;border-radius:var(--radius-md,12px);margin-bottom:var(--space-4,16px);font-size:var(--text-sm,14px);">
    <i class="fas fa-check-circle" style="margin-right:8px;"></i>บันทึกสำเร็จ!
</div>
<?php endif; ?>

<?php
$triggerIcons = [
    'follow'      => '👋 Follow',
    'tag_added'   => '🏷️ Tag Added',
    'purchase'    => '🛒 Purchase',
    'no_purchase' => '❌ No Purchase',
    'inactivity'  => '😴 Inactivity',
];

$columns = [
    [
        'key'    => 'name',
        'label'  => 'Campaign',
        'align'  => 'left',
        'render' => function ($row) {
            return '<button onclick="openEditModal(' . (int)$row['id'] . ')" style="font-weight:600;color:var(--color-primary-600);background:none;border:none;cursor:pointer;font-size:var(--text-sm,14px);padding:0;text-align:left;">'
                . htmlspecialchars($row['name']) . '</button>';
        },
    ],
    [
        'key'    => 'trigger_type',
        'label'  => 'Trigger',
        'align'  => 'left',
        'render' => function ($row) use ($triggerIcons) {
            return htmlspecialchars($triggerIcons[$row['trigger_type']] ?? $row['trigger_type']);
        },
    ],
    [
        'key'    => 'step_count',
        'label'  => 'Steps',
        'align'  => 'center',
        'render' => function ($row) { return (int)$row['step_count']; },
    ],
    [
        'key'    => 'active_users',
        'label'  => 'Active Users',
        'align'  => 'center',
        'render' => function ($row) { return number_format($row['active_users']); },
    ],
    [
        'key'    => 'is_active',
        'label'  => 'Status',
        'align'  => 'center',
        'render' => function ($row) {
            $cls = $row['is_active'] ? 'status-pill-active' : 'status-pill-paused';
            $label = $row['is_active'] ? 'Active' : 'Paused';
            return '<form method="POST" class="inline">'
                . '<input type="hidden" name="action" value="toggle_campaign">'
                . '<input type="hidden" name="campaign_id" value="' . (int)$row['id'] . '">'
                . '<button type="submit" class="status-pill ' . $cls . '">' . htmlspecialchars($label) . '</button>'
                . '</form>';
        },
    ],
    [
        'key'    => 'actions',
        'label'  => 'Actions',
        'align'  => 'center',
        'render' => function ($row) {
            return '<div class="data-table-row-actions">'
                . '<button onclick="openEditModal(' . (int)$row['id'] . ')" class="data-table-row-action" title="แก้ไข"><i class="fas fa-edit"></i></button>'
                . '<form method="POST" class="inline" onsubmit="return confirm(\'ลบ Campaign นี้?\')">'
                . '<input type="hidden" name="action" value="delete_campaign">'
                . '<input type="hidden" name="campaign_id" value="' . (int)$row['id'] . '">'
                . '<button type="submit" class="data-table-row-action data-table-row-action-danger" title="ลบ"><i class="fas fa-trash"></i></button>'
                . '</form>'
                . '</div>';
        },
    ],
];

$emptyHtml = renderEmptyState(
    'fas fa-mail-bulk',
    'ยังไม่มี Drip Campaign',
    'สร้าง Campaign แรกเพื่อเริ่มส่งข้อความอัตโนมัติ',
    ['label' => 'สร้าง Campaign', 'icon' => 'fas fa-plus', 'onclick' => 'openCreateModal()']
);

echo renderDataTable($columns, $campaigns, ['emptyContent' => $emptyHtml]);
?>

<!-- Create Campaign Modal -->
<?php
$createBody = '
<div class="dc-field">
    <label>ชื่อ Campaign</label>
    <input type="text" name="name" required class="dc-input" placeholder="เช่น Welcome Series, Re-engagement">
</div>
<div class="dc-field" style="margin-bottom:0">
    <label>Trigger (เริ่มเมื่อ)</label>
    <select name="trigger_type" class="dc-input">
        <option value="follow">👋 ผู้ใช้ Follow</option>
        <option value="tag_added">🏷️ ได้รับ Tag</option>
        <option value="purchase">🛒 ซื้อสินค้า</option>
        <option value="no_purchase">❌ ทักแต่ไม่ซื้อ</option>
        <option value="inactivity">😴 ไม่มี Activity</option>
    </select>
</div>';

$createFooter = '
<button type="button" onclick="closeModalShell(\'createCampaignModal\')" class="dc-btn">ยกเลิก</button>
<button type="submit" class="dc-btn dc-btn-primary" style="flex:1;"><i class="fas fa-plus"></i>สร้าง</button>';

echo renderModal(
    'createCampaignModal',
    'สร้าง Drip Campaign',
    $createBody,
    $createFooter,
    [
        'size'      => 'sm',
        'formOpen'  => '<form method="POST"><input type="hidden" name="action" value="create_campaign">',
        'formClose' => '</form>',
    ]
);
?>

<!-- Edit Campaign Panel (shown when $editCampaign is set) -->
<?php if ($editCampaign): ?>
<div id="editModal" class="modal-shell" role="dialog" aria-modal="true" style="display:flex;">
    <div class="modal-shell-backdrop" onclick="closeEditModal()"></div>
    <div class="modal-shell-panel modal-shell-xl" style="max-height:95vh;overflow-y:auto;">
        <div class="edit-panel-header">
            <div>
                <h3>แก้ไข Campaign</h3>
                <p><?= htmlspecialchars($editCampaign['name']) ?></p>
            </div>
            <button onclick="closeEditModal()" class="close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <div class="edit-grid">
            <!-- Campaign Settings -->
            <div class="dc-settings-panel">
                <h4><i class="fas fa-cog" style="margin-right:8px;"></i>Campaign Settings</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="update_campaign">
                    <input type="hidden" name="campaign_id" value="<?= $editCampaign['id'] ?>">
                    <div class="dc-field">
                        <label>ชื่อ</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($editCampaign['name']) ?>" required class="dc-input">
                    </div>
                    <div class="dc-field">
                        <label>Trigger</label>
                        <select name="trigger_type" class="dc-input">
                            <option value="follow" <?= $editCampaign['trigger_type'] === 'follow' ? 'selected' : '' ?>>👋 Follow</option>
                            <option value="tag_added" <?= $editCampaign['trigger_type'] === 'tag_added' ? 'selected' : '' ?>>🏷️ Tag Added</option>
                            <option value="purchase" <?= $editCampaign['trigger_type'] === 'purchase' ? 'selected' : '' ?>>🛒 Purchase</option>
                            <option value="no_purchase" <?= $editCampaign['trigger_type'] === 'no_purchase' ? 'selected' : '' ?>>❌ No Purchase</option>
                            <option value="inactivity" <?= $editCampaign['trigger_type'] === 'inactivity' ? 'selected' : '' ?>>😴 Inactivity</option>
                        </select>
                    </div>
                    <button type="submit" class="dc-btn dc-btn-primary"><i class="fas fa-save"></i>บันทึก</button>
                </form>
            </div>

            <!-- Steps -->
            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4,16px);">
                    <h4 style="font-weight:600;font-size:var(--text-base,16px);color:var(--color-dark-800);margin:0;">Message Steps</h4>
                    <button onclick="openAddStepModal()" class="dc-btn dc-btn-blue">
                        <i class="fas fa-plus"></i>เพิ่ม Step
                    </button>
                </div>

                <?php if (empty($editSteps)): ?>
                <?= renderEmptyState(
                    'fas fa-list-ol',
                    'ยังไม่มี Steps',
                    'เพิ่ม Step แรกเพื่อกำหนดข้อความ',
                    ['label' => 'เพิ่ม Step แรก', 'icon' => 'fas fa-plus', 'onclick' => 'openAddStepModal()']
                ) ?>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:var(--space-3,12px);">
                    <?php foreach ($editSteps as $index => $step): ?>
                    <div class="step-card">
                        <?php if ($index < count($editSteps) - 1): ?>
                        <div class="step-connector"></div>
                        <?php endif; ?>
                        <div style="display:flex;align-items:flex-start;gap:var(--space-3,12px);">
                            <div class="step-badge"><?= $step['step_order'] ?></div>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:var(--space-2,8px);">
                                    <span style="font-size:var(--text-xs,12px);color:var(--color-dark-500);">
                                        <i class="fas fa-clock" style="margin-right:4px;"></i>
                                        <?php
                                        $delay = $step['delay_minutes'];
                                        if ($delay === 0) echo 'ทันที';
                                        elseif ($delay < 60) echo "{$delay} นาที";
                                        elseif ($delay < 1440) echo floor($delay / 60) . ' ชั่วโมง';
                                        else echo floor($delay / 1440) . ' วัน';
                                        ?>
                                    </span>
                                    <span style="padding:2px 8px;background:var(--color-slate-100);color:var(--color-dark-600);border-radius:var(--radius-full,9999px);font-size:var(--text-xs,12px);">
                                        <?= htmlspecialchars($step['message_type']) ?>
                                    </span>
                                </div>
                                <div class="step-preview">
                                    <?php if ($step['message_type'] === 'flex'): ?>
                                    <span style="color:var(--color-violet-600);"><i class="fas fa-cube" style="margin-right:4px;"></i>Flex Message</span>
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
                                <button type="submit" class="data-table-row-action data-table-row-action-danger"><i class="fas fa-trash"></i></button>
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
<div id="editModal" class="modal-shell" role="dialog" aria-modal="true" hidden></div>
<?php endif; ?>

<!-- Add Step Modal -->
<?php
$addStepBody = '
<div class="dc-field">
    <label>ส่งหลังจาก Step ก่อนหน้า</label>
    <div style="display:flex;gap:var(--space-2,8px);">
        <input type="number" name="delay_value" value="0" min="0" class="dc-input" style="width:80px;">
        <select name="delay_unit" id="delayUnit" class="dc-input" style="width:auto;" onchange="updateDelayMinutes()">
            <option value="0">ทันที</option>
            <option value="1">นาที</option>
            <option value="60">ชั่วโมง</option>
            <option value="1440">วัน</option>
        </select>
        <input type="hidden" name="delay_minutes" id="delayMinutes" value="0">
    </div>
</div>
<div class="dc-field">
    <label>ประเภทข้อความ</label>
    <select name="message_type" class="dc-input">
        <option value="text">Text</option>
        <option value="flex">Flex Message (JSON)</option>
    </select>
</div>
<div class="dc-field" style="margin-bottom:0;">
    <label>เนื้อหา</label>
    <textarea name="content" rows="5" required class="dc-input" placeholder="พิมพ์ข้อความ หรือวาง Flex JSON"></textarea>
</div>';

$addStepFooter = '
<button type="button" onclick="closeModalShell(\'addStepModal\')" class="dc-btn">ยกเลิก</button>
<button type="submit" class="dc-btn dc-btn-primary" style="flex:1;"><i class="fas fa-plus"></i>เพิ่ม Step</button>';

echo renderModal(
    'addStepModal',
    'เพิ่ม Step',
    $addStepBody,
    $addStepFooter,
    [
        'size'      => 'md',
        'formOpen'  => '<form method="POST">'
            . '<input type="hidden" name="action" value="add_step">'
            . '<input type="hidden" name="campaign_id" value="' . $editCampaignId . '">'
            . '<input type="hidden" name="step_order" value="' . $nextStepOrder . '">',
        'formClose' => '</form>',
    ]
);
?>

<!-- Info Box -->
<div class="drip-info-box">
    <h4><i class="fas fa-lightbulb" style="margin-right:8px;"></i>Drip Campaign คืออะไร?</h4>
    <p>Drip Campaign คือการส่งข้อความอัตโนมัติตามลำดับเวลา เช่น:</p>
    <ul>
        <li>• <strong>Welcome Series:</strong> ส่งข้อความต้อนรับ → 1 ชม. ส่งแนะนำสินค้า → 1 วัน ส่งคูปอง</li>
        <li>• <strong>Re-engagement:</strong> ลูกค้าไม่ทักมา 7 วัน → ส่งข้อความถามไถ่ → 3 วัน ส่งโปรโมชั่น</li>
        <li>• <strong>Post-Purchase:</strong> ซื้อสินค้า → 3 วัน ถามความพอใจ → 7 วัน ขอรีวิว</li>
    </ul>
</div>

<script>
function openCreateModal() {
    openModalShell('createCampaignModal');
}

function openEditModal(campaignId) {
    window.location.href = 'drip-campaigns.php?edit=' + campaignId;
}
function closeEditModal() {
    window.location.href = 'drip-campaigns.php';
}

function openAddStepModal() {
    openModalShell('addStepModal');
}

function updateDelayMinutes() {
    var valueInput = document.querySelector('[name="delay_value"]');
    var unitEl = document.getElementById('delayUnit');
    var minutesEl = document.getElementById('delayMinutes');
    if (!valueInput || !unitEl || !minutesEl) return;
    var value = parseInt(valueInput.value) || 0;
    var unit = parseInt(unitEl.value) || 0;
    minutesEl.value = value * unit;
}

document.addEventListener('DOMContentLoaded', function () {
    var delayValueInput = document.querySelector('[name="delay_value"]');
    if (delayValueInput) {
        delayValueInput.addEventListener('input', updateDelayMinutes);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
