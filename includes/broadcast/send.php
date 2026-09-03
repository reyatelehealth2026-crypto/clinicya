<?php
/**
 * Broadcast Send Tab - ส่งข้อความแบบ Broadcast
 * รองรับ Segments, Tags (หลายรายการ), ตั้งเวลาส่ง และ Advanced Targeting
 *
 * @package FileConsolidation
 */

require_once __DIR__ . '/../../classes/BroadcastHelper.php';

// Get LineAPI for current bot
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);
$crm = new AdvancedCRM($db, $currentBotId, $line);

// ─── Auto DB Migration for target_group_id ──────────────────────────────────
try {
    $colStmt = $db->query("SHOW COLUMNS FROM broadcasts LIKE 'target_group_id'");
    $colInfo = $colStmt->fetch();
    if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
        $db->exec("ALTER TABLE broadcasts MODIFY target_group_id VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    // Ignore migration errors silently
}

// ─── Handle cancel scheduled broadcast ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_scheduled') {
    $broadcastId = (int) ($_POST['broadcast_id'] ?? 0);
    if ($broadcastId) {
        $stmt = $db->prepare("UPDATE broadcasts SET status = 'failed' WHERE id = ? AND status = 'scheduled' AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$broadcastId, $currentBotId]);
    }
    if (!headers_sent()) {
        header('Location: broadcast.php?tab=send&cancelled=1');
        exit;
    }
    echo '<script>window.location.href = "broadcast.php?tab=send&cancelled=1";</script>';
    exit;
}

// ─── Handle send / schedule ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $title       = $_POST['title'] ?? '';
    $messageType = $_POST['message_type'] ?? 'text';
    $targetType  = $_POST['target_type'] ?? 'database';
    $sendMode    = $_POST['send_mode'] ?? 'now';
    $scheduledAt = $_POST['scheduled_at'] ?? '';

    // Prepare message content
    if ($messageType === 'text') {
        $content  = $_POST['content'] ?? '';
        $messages = [['type' => 'text', 'text' => $content]];
    } elseif ($messageType === 'image') {
        $content  = $_POST['image_url'] ?? '';
        $messages = [['type' => 'image', 'originalContentUrl' => $content, 'previewImageUrl' => $content]];
    } elseif ($messageType === 'flex') {
        $content  = $_POST['flex_content'] ?? '';
        $flexJson = json_decode($content, true);
        $messages = [['type' => 'flex', 'altText' => $title, 'contents' => $flexJson]];
    } else {
        $content  = '';
        $messages = [];
    }

    // Store tag_ids as JSON in target_group_id column (for tag multi-select)
    $targetGroupId = null;
    if ($targetType === 'tag') {
        $tagIds = array_filter(array_map('intval', (array) ($_POST['tag_ids'] ?? [])));
        $targetGroupId = !empty($tagIds) ? json_encode(array_values($tagIds)) : null;
    }

    // ── Scheduled mode: save and defer ──────────────────────────────────────
    if ($sendMode === 'schedule' && !empty($scheduledAt)) {
        $scheduledAtDb = date('Y-m-d H:i:s', strtotime($scheduledAt));
        $stmt = $db->prepare("INSERT INTO broadcasts (line_account_id, title, message_type, content, target_type, target_group_id, sent_count, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, 'scheduled', ?, NOW())");
        $stmt->execute([$currentBotId, $title, $messageType, $content, $targetType, $targetGroupId, $scheduledAtDb]);
        $broadcastId = $db->lastInsertId();
        $activityLogger->logMessage(ActivityLogger::ACTION_SEND, 'ตั้งเวลา Broadcast: ' . $title, [
            'broadcast_id' => $broadcastId,
            'target_type'  => $targetType,
            'scheduled_at' => $scheduledAtDb,
        ]);
        if (!headers_sent()) {
            header('Location: broadcast.php?tab=send&scheduled=1');
            exit;
        }
        echo '<script>window.location.href = "broadcast.php?tab=send&scheduled=1";</script>';
        exit;
    }

    // ── Immediate send ───────────────────────────────────────────────────────
    $result    = BroadcastHelper::executeBroadcastSend($db, $line, $crm, $currentBotId, $targetType, $_POST, $messages);
    $sentCount = $result['sentCount'];
    if ($targetType !== 'tag') {
        $targetGroupId = $result['targetGroupId'];
    }

    $stmt = $db->prepare("INSERT INTO broadcasts (line_account_id, title, message_type, content, target_type, target_group_id, sent_count, status, sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), NOW())");
    $stmt->execute([$currentBotId, $title, $messageType, $content, $targetType, $targetGroupId, $sentCount]);

    $activityLogger->logMessage(ActivityLogger::ACTION_SEND, 'ส่ง Broadcast: ' . $title, [
        'broadcast_id' => $db->lastInsertId(),
        'target_type'  => $targetType,
        'sent_count'   => $sentCount,
        'message_type' => $messageType,
    ]);

    if (!headers_sent()) {
        header('Location: broadcast.php?tab=send&sent=' . $sentCount);
        exit;
    }
    echo '<script>window.location.href = "broadcast.php?tab=send&sent=' . $sentCount . '";</script>';
    exit;
}

// Trigger process of any scheduled broadcasts in background
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$triggerUrl = str_replace('/includes/broadcast', '/api', $baseUrl) . '/process_scheduled_broadcasts.php';

// Very lightweight async request
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 1
    ]
]);
@file_get_contents($triggerUrl, false, $context);

// Get groups for dropdown
$stmt = $db->query("SELECT g.*, COUNT(ug.user_id) as member_count FROM groups g LEFT JOIN user_groups ug ON g.id = ug.group_id GROUP BY g.id ORDER BY g.name");
$groups = $stmt->fetchAll();

// Get segments for dropdown
$segments = $crm->getSegments();

// Get tags for dropdown
$stmt = $db->prepare("SELECT t.*, COUNT(a.user_id) as user_count FROM user_tags t LEFT JOIN user_tag_assignments a ON t.id = a.tag_id WHERE t.line_account_id = ? OR t.line_account_id IS NULL GROUP BY t.id ORDER BY user_count DESC");
$stmt->execute([$currentBotId]);
$tags = $stmt->fetchAll();

// Get all users for selection
$stmt = $db->prepare("SELECT id, display_name, picture_url FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY display_name");
$stmt->execute([$currentBotId]);
$allUsers = $stmt->fetchAll();

// Get templates (รวม templates ปกติ + flex_templates จาก Flex Builder)
$templates = [];

// 1. ดึง templates ปกติ
try {
    $stmt = $db->query("SELECT id, name, category, message_type, content, created_at FROM templates ORDER BY category, name");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $templates = [];
}

// 2. ดึง flex_templates จาก Flex Builder
// ไม่รวม slot-bound overrides (Flex Studio) — พวกนั้นอาจมี {{placeholder}} ที่ต้อง substitute ตอน render
try {
    try {
        $stmt = $db->prepare("SELECT id, name, category, flex_json as content, created_at FROM flex_templates WHERE (line_account_id = ? OR line_account_id IS NULL) AND (slot_key IS NULL OR slot_key = '') ORDER BY created_at DESC");
        $stmt->execute([$currentBotId]);
    } catch (Exception $slotErr) {
        // slot_key column not migrated on this tenant DB → fall back to unfiltered
        $stmt = $db->prepare("SELECT id, name, category, flex_json as content, created_at FROM flex_templates WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY created_at DESC");
        $stmt->execute([$currentBotId]);
    }
    $flexTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // เพิ่ม message_type = 'flex' ให้กับ flex templates
    foreach ($flexTemplates as &$flexTpl) {
        $flexTpl['message_type'] = 'flex';
        $flexTpl['category'] = $flexTpl['category'] ?? 'Flex Builder';
    }
    
    // รวม flex templates เข้ากับ templates ปกติ
    $templates = array_merge($templates, $flexTemplates);
} catch (Exception $e) {
    // ถ้าตาราง flex_templates ยังไม่มี ก็ใช้แค่ templates ปกติ
}

// Get broadcast history (TASK 9: paginated, 10 per page)
$historyPage = max(1, (int)($_GET['hist_page'] ?? 1));
$historyLimit = 10;
$historyOffset = ($historyPage - 1) * $historyLimit;
$stmt = $db->prepare("SELECT b.*, g.name as group_name FROM broadcasts b LEFT JOIN groups g ON b.target_group_id = g.id WHERE (b.line_account_id = ? OR b.line_account_id IS NULL) ORDER BY b.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$currentBotId, $historyLimit + 1, $historyOffset]);
$historyRaw = $stmt->fetchAll();
$hasMoreHistory = count($historyRaw) > $historyLimit;
$history = array_slice($historyRaw, 0, $historyLimit);

// Count users in database
$stmt = $db->prepare("SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
$stmt->execute([$currentBotId]);
$totalUsers = $stmt->fetch()['c'];
?>

<?php if (isset($_GET['sent'])): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
        <i class="fas fa-check-circle text-2xl mr-3"></i>
        <div><p class="font-medium">ส่ง Broadcast สำเร็จ!</p><p class="text-sm">ส่งถึงผู้รับ <?= number_format((int)$_GET['sent']) ?> คน</p></div>
    </div>
<?php endif; ?>
<?php if (isset($_GET['scheduled'])): ?>
    <div class="mb-4 p-4 bg-blue-100 text-blue-700 rounded-lg flex items-center">
        <i class="fas fa-clock text-2xl mr-3"></i>
        <div><p class="font-medium">ตั้งเวลา Broadcast สำเร็จ!</p><p class="text-sm">ระบบจะส่งข้อความตามเวลาที่กำหนด</p></div>
    </div>
<?php endif; ?>
<?php if (isset($_GET['cancelled'])): ?>
    <div class="mb-4 p-4 bg-yellow-100 text-yellow-700 rounded-lg flex items-center">
        <i class="fas fa-ban text-2xl mr-3"></i>
        <div><p class="font-medium">ยกเลิก Broadcast ที่ตั้งเวลาแล้ว</p></div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Send Form -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Templates Quick Select -->
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">Templates</h3>
                <a href="templates.php" class="text-sm text-green-600 hover:underline">จัดการ Templates →</a>
            </div>
            <div class="flex flex-wrap gap-2 max-h-32 overflow-y-auto" id="templateButtons">
                <?php foreach ($templates as $tpl): ?>
                    <button type="button" 
                        class="template-btn px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm transition"
                        data-template="<?= htmlspecialchars(json_encode($tpl), ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars($tpl['category'] ?: '') ?>">
                        <?= htmlspecialchars($tpl['name']) ?>
                    </button>
                <?php endforeach; ?>
                <?php if (empty($templates)): ?>
                    <p class="text-gray-500 text-sm">ยังไม่มี Template</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Form -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4 flex items-center">
                <i class="fas fa-envelope text-green-500 mr-2"></i>
                สร้างข้อความใหม่
            </h3>

            <form method="POST" id="broadcastForm">
                <input type="hidden" name="action" value="send">

                <!-- Title -->
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">หัวข้อ (สำหรับบันทึก)</label>
                    <input type="text" name="title" required
                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                        placeholder="เช่น โปรโมชั่นเดือนธันวาคม">
                </div>

                <!-- Message Type -->
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">ประเภทข้อความ</label>
                    <div class="flex flex-wrap gap-2">
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="message_type" value="text" checked class="mr-2"
                                onchange="toggleMessageType()">
                            <i class="fas fa-font mr-2 text-gray-500"></i>
                            <span>ข้อความ</span>
                        </label>
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="message_type" value="image" class="mr-2"
                                onchange="toggleMessageType()">
                            <i class="fas fa-image mr-2 text-gray-500"></i>
                            <span>รูปภาพ</span>
                        </label>
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="message_type" value="flex" class="mr-2"
                                onchange="toggleMessageType()">
                            <i class="fas fa-code mr-2 text-gray-500"></i>
                            <span>Flex Message</span>
                        </label>
                    </div>
                </div>

                <!-- Text Content -->
                <div id="textContent" class="mb-4">
                    <div class="flex justify-between items-center mb-1">
                        <label class="block text-sm font-medium">ข้อความ</label>
                    </div>
                    <textarea name="content" id="contentText" rows="5"
                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                        placeholder="พิมพ์ข้อความที่ต้องการส่ง..."></textarea>
                    <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle mr-1"></i>รองรับ Emoji
                        และข้อความยาวสูงสุด 5,000 ตัวอักษร</p>
                </div>

                <!-- Image Content -->
                <div id="imageContent" class="mb-4 hidden">
                    <label class="block text-sm font-medium mb-1">URL รูปภาพ</label>
                    <input type="url" name="image_url" id="imageUrl"
                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                        placeholder="https://example.com/image.jpg">
                    <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle mr-1"></i>รองรับ JPEG, PNG
                        ขนาดไม่เกิน 10MB</p>
                    <div id="imagePreview" class="mt-2 hidden">
                        <img src="" class="max-w-xs rounded-lg border">
                    </div>
                </div>

                <!-- Flex Content -->
                <div id="flexContent" class="mb-4 hidden">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-sm font-medium">Flex Message JSON</label>
                        <a href="flex-builder.php" target="_blank"
                            class="px-3 py-1.5 bg-gradient-to-r from-green-500 to-emerald-600 text-white text-sm rounded-lg hover:opacity-90">
                            🎨 Flex Builder
                        </a>
                    </div>
                    <textarea name="flex_content" id="flexJson" rows="8"
                        class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono text-sm"
                        placeholder='{"type": "bubble", "body": {...}}'></textarea>
                </div>

                <!-- Target Type -->
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">ส่งถึง</label>
                    <div class="flex flex-wrap gap-2">
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="database" checked class="mr-2"
                                onchange="toggleTargetType()">
                            <i class="fas fa-database mr-2 text-blue-500"></i>
                            <span>ในฐานข้อมูล</span>
                        </label>
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="all" class="mr-2"
                                onchange="toggleTargetType()">
                            <i class="fas fa-users mr-2 text-blue-500"></i>
                            <span>เพื่อนทั้งหมด</span>
                        </label>
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-purple-50 has-[:checked]:border-purple-500">
                            <input type="radio" name="target_type" value="segment" class="mr-2"
                                onchange="toggleTargetType()">
                            <i class="fas fa-layer-group mr-2 text-purple-500"></i>
                            <span>Segment</span>
                        </label>
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-orange-50 has-[:checked]:border-orange-500">
                            <input type="radio" name="target_type" value="tag" class="mr-2"
                                onchange="toggleTargetType()">
                            <i class="fas fa-tag mr-2 text-orange-500"></i>
                            <span>Tag</span>
                        </label>
                        <label
                            class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="group" class="mr-2"
                                onchange="toggleTargetType()">
                            <i class="fas fa-users mr-2 text-blue-500"></i>
                            <span>กลุ่ม</span>
                        </label>
                    </div>
                </div>

                <!-- Target Options -->
                <div id="targetOptions" class="mb-4">
                    <div id="targetDatabase" class="p-4 bg-blue-50 rounded-lg">
                        <p class="text-blue-700"><i class="fas fa-database mr-2"></i>จะส่งข้อความถึงผู้ใช้ในฐานข้อมูล
                            <strong><?= number_format($totalUsers) ?></strong> คน</p>
                    </div>

                    <div id="targetAll" class="p-4 bg-blue-50 rounded-lg hidden">
                        <p class="text-blue-700"><i class="fas fa-users mr-2"></i>จะส่งข้อความถึงเพื่อนทั้งหมดของ LINE
                            OA</p>
                    </div>

                    <div id="targetSegment" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือก Customer Segment</label>
                        <select name="segment_id"
                            class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <option value="">-- เลือก Segment --</option>
                            <?php foreach ($segments as $segment): ?>
                                <option value="<?= $segment['id'] ?>"><?= htmlspecialchars($segment['name']) ?>
                                    (<?= number_format($segment['user_count']) ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="targetTag" class="hidden">
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-sm font-medium">เลือก Tag <span class="text-orange-500">(เลือกได้หลายรายการ)</span></label>
                            <div class="flex gap-2">
                                <button type="button" onclick="selectAllTags(true)" class="text-xs text-orange-600 hover:underline">เลือกทั้งหมด</button>
                                <span class="text-gray-300">|</span>
                                <button type="button" onclick="selectAllTags(false)" class="text-xs text-gray-500 hover:underline">ล้าง</button>
                            </div>
                        </div>
                        <div class="border rounded-lg max-h-52 overflow-y-auto divide-y" id="tagCheckboxList">
                            <?php if (empty($tags)): ?>
                                <p class="text-gray-500 text-sm p-3">ยังไม่มี Tag</p>
                            <?php else: ?>
                                <?php foreach ($tags as $tag): ?>
                                    <label class="flex items-center gap-3 px-3 py-2 hover:bg-orange-50 cursor-pointer">
                                        <input type="checkbox" name="tag_ids[]" value="<?= $tag['id'] ?>"
                                            class="tag-checkbox accent-orange-500 w-4 h-4" onchange="updateTagCount()">
                                        <span class="flex-1 text-sm"><?= htmlspecialchars($tag['name']) ?></span>
                                        <span class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full"><?= number_format($tag['user_count']) ?> คน</span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-orange-600 mt-1" id="tagSelectedInfo">ยังไม่ได้เลือก Tag</p>
                    </div>

                    <div id="targetGroup" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือกกลุ่ม</label>
                        <select name="target_group_id"
                            class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือกกลุ่ม --</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?>
                                    (<?= $group['member_count'] ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Recipient Count Preview (TASK 3) -->
                <div id="recipientPreview" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-3">
                    <div class="w-9 h-9 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-users text-green-600"></i>
                    </div>
                    <div class="flex-1">
                        <div class="text-xs text-gray-500">จำนวนผู้รับโดยประมาณ</div>
                        <div class="flex items-center gap-2">
                            <span id="recipientCount" class="text-xl font-bold text-green-700"><?= number_format($totalUsers) ?></span>
                            <span class="text-sm text-gray-500">คน</span>
                            <span id="recipientLoading" class="hidden"><i class="fas fa-circle-notch fa-spin text-green-400 text-sm"></i></span>
                        </div>
                    </div>
                </div>

                <!-- Send Mode: Now or Schedule -->
                <div class="mb-4 p-4 bg-gray-50 rounded-lg border">
                    <label class="block text-sm font-medium mb-2"><i class="fas fa-clock mr-1 text-gray-500"></i>เวลาส่ง</label>
                    <div class="flex flex-wrap gap-3 mb-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="send_mode" value="now" checked class="accent-green-500" onchange="toggleSendMode()">
                            <span class="text-sm font-medium text-green-700">ส่งทันที</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="send_mode" value="schedule" class="accent-blue-500" onchange="toggleSendMode()">
                            <span class="text-sm font-medium text-blue-700">ตั้งเวลาส่ง</span>
                        </label>
                    </div>
                    <div id="scheduleOptions" class="hidden">
                        <label class="block text-xs text-gray-500 mb-1">เลือกวันและเวลา</label>
                        <input type="datetime-local" name="scheduled_at" id="scheduledAt"
                            class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400 text-sm"
                            min="<?= date('Y-m-d\TH:i') ?>">
                        <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">
                            🕐 เวลาประเทศไทย (GMT+7)
                        </span>
                        <p class="text-xs text-blue-600 mt-1"><i class="fas fa-info-circle mr-1"></i>ข้อความจะถูกส่งเมื่อถึงเวลาที่กำหนด (ระบบตรวจสอบเมื่อมีการเปิดหน้านี้)</p>
                    </div>
                </div>

                <button type="button" id="submitBtn" onclick="openBroadcastConfirmModal()"
                    class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium transition">
                    <i class="fas fa-paper-plane mr-2"></i>ส่ง Broadcast
                </button>
            </form>
        </div>
    </div>

    <!-- WS-2: Type-to-Confirm Broadcast Modal ─────────────────────────────── -->
    <div id="broadcastConfirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4" role="dialog" aria-modal="true" aria-labelledby="bcModalTitle">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
            <div class="px-6 py-4 border-b bg-gradient-to-r from-red-50 to-orange-50">
                <h3 id="bcModalTitle" class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-red-500"></i>
                    ยืนยันการส่ง Broadcast
                </h3>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="text-xs text-gray-500 mb-1">จะส่งไปยัง</div>
                    <div class="text-sm font-medium text-gray-800" id="bcModalTargetLabel">—</div>
                    <div class="mt-3 flex items-end gap-2">
                        <span class="text-3xl font-bold text-green-600" id="bcModalCount">0</span>
                        <span class="text-sm text-gray-500 mb-1">คน</span>
                    </div>
                </div>

                <div id="bcModalCostBox" class="hidden p-4 bg-amber-50 border border-amber-200 rounded-lg">
                    <div class="flex items-center gap-2 text-amber-800 text-sm font-medium">
                        <i class="fas fa-coins"></i>
                        <span>ประมาณ ฿<span id="bcModalCost">0</span></span>
                    </div>
                    <p class="text-xs text-amber-700 mt-1">ต้นทุน LINE Push หลังโควต้าฟรี (≈ 0.30 ฿/ข้อความ)</p>
                </div>

                <div id="bcModalScheduleBox" class="hidden p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-700">
                    <i class="fas fa-clock mr-1"></i>
                    ตั้งเวลาส่ง: <span id="bcModalScheduleAt" class="font-medium"></span>
                    <span class="ml-1 text-xs">(GMT+7)</span>
                </div>

                <div>
                    <label for="bcModalConfirmInput" class="block text-sm font-medium text-gray-700 mb-1">
                        พิมพ์ <span class="font-mono font-bold text-red-600">SEND</span> เพื่อยืนยัน
                    </label>
                    <input type="text" id="bcModalConfirmInput" autocomplete="off" spellcheck="false"
                        class="w-full px-4 py-2 border-2 rounded-lg font-mono uppercase tracking-wider focus:outline-none focus:ring-2 focus:ring-red-400 focus:border-red-400"
                        placeholder="SEND"
                        oninput="validateBroadcastConfirmInput()">
                    <p class="text-xs text-gray-500 mt-1">ตัวพิมพ์ใหญ่ทั้งหมด</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 border-t flex items-center justify-end gap-2">
                <button type="button" onclick="closeBroadcastConfirmModal()"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                    ยกเลิก
                </button>
                <button type="button" id="bcModalConfirmBtn" disabled onclick="submitBroadcastForm()"
                    class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-lg hover:bg-red-600 disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-paper-plane mr-1"></i>
                    ยืนยันส่ง
                </button>
            </div>
        </div>
    </div>

    <!-- History Sidebar -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow p-6 sticky top-6">
            <h3 class="text-lg font-semibold mb-4">ประวัติการส่ง</h3>
            <div class="space-y-3 max-h-[640px] overflow-y-auto">
                <?php
                $statusColors = [
                    'sent'      => 'bg-green-100 text-green-700',
                    'scheduled' => 'bg-blue-100 text-blue-700',
                    'sending'   => 'bg-yellow-100 text-yellow-700',
                    'failed'    => 'bg-red-100 text-red-600',
                    'draft'     => 'bg-gray-100 text-gray-600',
                ];
                $statusLabels = [
                    'sent'      => 'ส่งแล้ว',
                    'scheduled' => '⏰ ตั้งเวลา',
                    'sending'   => 'กำลังส่ง',
                    'failed'    => 'ยกเลิก',
                    'draft'     => 'ร่าง',
                ];
                foreach ($history as $item):
                    $sc = $statusColors[$item['status']] ?? 'bg-gray-100 text-gray-600';
                    $sl = $statusLabels[$item['status']] ?? $item['status'];
                ?>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <div class="flex justify-between items-start mb-1">
                            <h4 class="font-medium text-sm truncate flex-1 mr-2"><?= htmlspecialchars($item['title']) ?></h4>
                            <span class="px-2 py-0.5 text-xs rounded shrink-0 <?= $sc ?>"><?= $sl ?></span>
                        </div>
                        <div class="flex justify-between text-xs text-gray-400 mb-1">
                            <span><i class="fas fa-comment mr-1"></i><?= htmlspecialchars($item['message_type']) ?></span>
                            <?php if ($item['status'] === 'scheduled' && !empty($item['scheduled_at'])): ?>
                                <span class="text-blue-500"><i class="fas fa-clock mr-1"></i><?= date('d/m H:i', strtotime($item['scheduled_at'])) ?></span>
                            <?php elseif (!empty($item['sent_at'])): ?>
                                <span><?= date('d/m H:i', strtotime($item['sent_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($item['status'] === 'sent'): ?>
                            <p class="text-xs text-gray-400"><i class="fas fa-users mr-1"></i><?= number_format($item['sent_count']) ?> คน</p>
                        <?php endif; ?>
                        <?php if ($item['status'] === 'scheduled'): ?>
                            <form method="POST" class="mt-1" onsubmit="return confirm('ยืนยันยกเลิก Broadcast นี้?')">
                                <input type="hidden" name="action" value="cancel_scheduled">
                                <input type="hidden" name="broadcast_id" value="<?= $item['id'] ?>">
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700">
                                    <i class="fas fa-times-circle mr-1"></i>ยกเลิก
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($history)): ?>
                    <!-- TASK 10: Empty State -->
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3">
                            <i class="fas fa-paper-plane text-gray-300 text-2xl"></i>
                        </div>
                        <p class="text-sm font-medium text-gray-500">ยังไม่มีประวัติการส่ง</p>
                        <p class="text-xs text-gray-400 mt-1">เริ่มส่ง Broadcast แรกของคุณได้เลย!</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- TASK 9: Load More -->
            <?php if ($hasMoreHistory): ?>
                <div class="mt-3 pt-3 border-t border-gray-100">
                    <a href="?hist_page=<?= $historyPage + 1 ?>" class="w-full flex items-center justify-center gap-2 py-2 text-sm text-green-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition">
                        <i class="fas fa-chevron-down text-xs"></i>โหลดเพิ่ม
                    </a>
                </div>
            <?php endif; ?>
            <?php if ($historyPage > 1): ?>
                <div class="mt-1">
                    <a href="?hist_page=<?= $historyPage - 1 ?>" class="w-full flex items-center justify-center gap-2 py-2 text-sm text-gray-400 hover:text-gray-600 hover:bg-gray-50 rounded-lg transition">
                        <i class="fas fa-chevron-up text-xs"></i>ย้อนกลับ
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleMessageType() {
        const type = document.querySelector('input[name="message_type"]:checked').value;
        document.getElementById('textContent').classList.toggle('hidden', type !== 'text');
        document.getElementById('imageContent').classList.toggle('hidden', type !== 'image');
        document.getElementById('flexContent').classList.toggle('hidden', type !== 'flex');
    }

    function toggleTargetType() {
        const type = document.querySelector('input[name="target_type"]:checked').value;
        ['targetDatabase','targetAll','targetSegment','targetTag','targetGroup'].forEach(id => {
            document.getElementById(id).classList.add('hidden');
        });
        const map = {
            'database': 'targetDatabase', 'all': 'targetAll',
            'segment': 'targetSegment', 'tag': 'targetTag', 'group': 'targetGroup'
        };
        if (map[type]) document.getElementById(map[type]).classList.remove('hidden');
        updateRecipientCount();
    }

    // ── TASK 3: Recipient Count Preview ──────────────────────────────────────
    let _rcTimer = null;
    function updateRecipientCount() {
        clearTimeout(_rcTimer);
        _rcTimer = setTimeout(_fetchRecipientCount, 300);
    }

    function _fetchRecipientCount() {
        const type = document.querySelector('input[name="target_type"]:checked')?.value || 'database';
        const loadEl = document.getElementById('recipientLoading');
        const countEl = document.getElementById('recipientCount');
        if (!countEl) return;

        loadEl?.classList.remove('hidden');
        countEl.style.opacity = '0.4';

        const params = new URLSearchParams({ type });

        if (type === 'tag') {
            const ids = [...document.querySelectorAll('.tag-checkbox:checked')].map(c => c.value);
            if (!ids.length) { countEl.textContent = '0'; countEl.style.opacity = '1'; loadEl?.classList.add('hidden'); return; }
            params.set('tag_ids', ids.join(','));
        } else if (type === 'segment') {
            const seg = document.querySelector('select[name="segment_id"]')?.value;
            if (seg) params.set('segment_id', seg);
        } else if (type === 'group') {
            const grp = document.querySelector('select[name="target_group_id"]')?.value;
            if (grp) params.set('group_id', grp);
        } else if (type === 'limit') {
            const lim = document.querySelector('input[name="limit_count"]')?.value || 100;
            params.set('limit_count', lim);
        }

        fetch('api/count_recipients.php?' + params.toString())
            .then(r => r.json())
            .then(data => {
                countEl.textContent = data.count?.toLocaleString() ?? '?';
                countEl.style.opacity = '1';
                loadEl?.classList.add('hidden');
            })
            .catch(() => { countEl.style.opacity = '1'; loadEl?.classList.add('hidden'); });
    }

    function toggleSendMode() {
        const mode = document.querySelector('input[name="send_mode"]:checked').value;
        document.getElementById('scheduleOptions').classList.toggle('hidden', mode !== 'schedule');
        const btn = document.getElementById('submitBtn');
        if (mode === 'schedule') {
            btn.innerHTML = '<i class="fas fa-clock mr-2"></i>ตั้งเวลาส่ง Broadcast';
            btn.className = 'w-full py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium transition';
        } else {
            btn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่ง Broadcast';
            btn.className = 'w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium transition';
        }
    }

    function selectAllTags(checked) {
        document.querySelectorAll('.tag-checkbox').forEach(cb => { cb.checked = checked; });
        updateTagCount();
    }

    function updateTagCount() {
        const checked = document.querySelectorAll('.tag-checkbox:checked');
        const info = document.getElementById('tagSelectedInfo');
        if (checked.length === 0) {
            info.textContent = 'ยังไม่ได้เลือก Tag';
        } else {
            info.textContent = 'เลือกแล้ว ' + checked.length + ' Tag';
        }
    }

    function loadTemplate(tpl) {
        if (tpl.message_type === 'text') {
            document.querySelector('input[name="message_type"][value="text"]').checked = true;
            document.getElementById('contentText').value = tpl.content;
        } else if (tpl.message_type === 'flex') {
            document.querySelector('input[name="message_type"][value="flex"]').checked = true;
            document.getElementById('flexJson').value = tpl.content;
        } else if (tpl.message_type === 'image') {
            document.querySelector('input[name="message_type"][value="image"]').checked = true;
            const imgUrl = document.getElementById('imageUrl');
            if (imgUrl) imgUrl.value = tpl.content;
        }
        toggleMessageType();
        // Scroll to the message content area so user sees the loaded content
        const broadcastForm = document.getElementById('broadcastForm');
        if (broadcastForm) broadcastForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ── WS-2: Type-to-Confirm Modal ──────────────────────────────────────────
    const BC_TYPE_NAMES = {
        'database': 'ผู้ใช้ในฐานข้อมูลทั้งหมด',
        'all':      'เพื่อนทั้งหมดของ LINE OA',
        'segment':  'สมาชิกใน Segment ที่เลือก',
        'tag':      'ผู้ใช้ที่มี Tag ที่เลือก',
        'group':    'สมาชิกในกลุ่มที่เลือก'
    };

    function openBroadcastConfirmModal() {
        const targetType = document.querySelector('input[name="target_type"]:checked').value;
        const sendMode   = document.querySelector('input[name="send_mode"]:checked').value;

        // Pre-flight validation (preserve existing behaviour)
        if (targetType === 'tag') {
            const checked = document.querySelectorAll('.tag-checkbox:checked');
            if (checked.length === 0) {
                alert('กรุณาเลือก Tag อย่างน้อย 1 รายการ');
                return;
            }
        }
        if (sendMode === 'schedule') {
            const dt = document.getElementById('scheduledAt').value;
            if (!dt) { alert('กรุณาเลือกวันและเวลาที่ต้องการส่ง'); return; }
        }
        const title = document.querySelector('input[name="title"]').value.trim();
        if (!title) { alert('กรุณากรอกหัวข้อ Broadcast'); return; }

        // Read recipient count already displayed on the page (refreshed by updateRecipientCount)
        const countEl = document.getElementById('recipientCount');
        const rawCount = (countEl?.textContent || '0').replace(/[^0-9]/g, '');
        const count = parseInt(rawCount, 10) || 0;

        // Populate modal
        document.getElementById('bcModalTargetLabel').textContent = BC_TYPE_NAMES[targetType] || targetType;
        document.getElementById('bcModalCount').textContent = count.toLocaleString();

        // Cost gate: count × 0.30 ฿ when > 500
        const costBox = document.getElementById('bcModalCostBox');
        if (count > 500) {
            const cost = Math.round(count * 0.30);
            document.getElementById('bcModalCost').textContent = cost.toLocaleString();
            costBox.classList.remove('hidden');
        } else {
            costBox.classList.add('hidden');
        }

        // Schedule preview
        const schedBox = document.getElementById('bcModalScheduleBox');
        if (sendMode === 'schedule') {
            const dt = document.getElementById('scheduledAt').value;
            document.getElementById('bcModalScheduleAt').textContent = dt.replace('T', ' ');
            schedBox.classList.remove('hidden');
        } else {
            schedBox.classList.add('hidden');
        }

        // Reset confirm input + button
        const input = document.getElementById('bcModalConfirmInput');
        input.value = '';
        document.getElementById('bcModalConfirmBtn').disabled = true;

        document.getElementById('broadcastConfirmModal').classList.remove('hidden');
        setTimeout(() => input.focus(), 50);
    }

    function closeBroadcastConfirmModal() {
        document.getElementById('broadcastConfirmModal').classList.add('hidden');
    }

    function validateBroadcastConfirmInput() {
        const v = document.getElementById('bcModalConfirmInput').value;
        // Case-sensitive: must equal "SEND"
        document.getElementById('bcModalConfirmBtn').disabled = (v !== 'SEND');
    }

    function submitBroadcastForm() {
        // Final gate: only submit if input is exactly "SEND"
        const v = document.getElementById('bcModalConfirmInput').value;
        if (v !== 'SEND') return;
        closeBroadcastConfirmModal();
        document.getElementById('broadcastForm').submit();
    }

    // Close modal on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const m = document.getElementById('broadcastConfirmModal');
            if (m && !m.classList.contains('hidden')) closeBroadcastConfirmModal();
        }
    });

    // Load Flex from sessionStorage if redirected from Flex Builder/Studio
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('flex') === '1') {
        const flexData = sessionStorage.getItem('flex_broadcast');
        const altText = sessionStorage.getItem('flex_broadcast_alttext');
        if (flexData) {
            try {
                const flexRadio = document.querySelector('input[name="message_type"][value="flex"]');
                if (flexRadio) flexRadio.checked = true;
                
                const flexTextarea = document.getElementById('flexJson');
                if (flexTextarea) flexTextarea.value = JSON.stringify(JSON.parse(flexData), null, 2);
                
                const titleInput = document.querySelector('input[name="title"]');
                if (titleInput && altText) titleInput.value = altText;
                
                // Also clear session storage so refreshing page doesn't keep reloading it
                sessionStorage.removeItem('flex_broadcast');
                sessionStorage.removeItem('flex_broadcast_alttext');
            } catch (e) {
                console.error('Failed to parse redirected Flex message:', e);
            }
        }
    }

    toggleMessageType();
    toggleTargetType();

    // Template button click handler
    document.querySelectorAll('.template-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            try {
                const templateData = JSON.parse(this.getAttribute('data-template'));
                loadTemplate(templateData);
            } catch (e) {
                console.error('Failed to load template:', e);
                alert('ไม่สามารถโหลด Template นี้ได้ กรุณาลองใหม่อีกครั้ง');
            }
        });
    });

    // Wire up recipient count updates
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('tag-checkbox')) {
            updateTagCount();
            updateRecipientCount();
        }
        if (e.target.name === 'segment_id' || e.target.name === 'target_group_id' || e.target.name === 'limit_count') {
            updateRecipientCount();
        }
    });
</script>