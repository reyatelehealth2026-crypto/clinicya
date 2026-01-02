<?php
/**
 * Broadcast Send Tab - ส่งข้อความแบบ Broadcast
 * รองรับ Segments, Tags, และ Advanced Targeting
 * 
 * @package FileConsolidation
 */

// Get LineAPI for current bot
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);
$crm = new AdvancedCRM($db, $currentBotId, $line);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $title = $_POST['title'];
    $messageType = $_POST['message_type'];
    $targetType = $_POST['target_type'];
    
    // Prepare message based on type
    if ($messageType === 'text') {
        $content = $_POST['content'];
        $messages = [['type' => 'text', 'text' => $content]];
    } elseif ($messageType === 'image') {
        $imageUrl = $_POST['image_url'];
        $messages = [['type' => 'image', 'originalContentUrl' => $imageUrl, 'previewImageUrl' => $imageUrl]];
        $content = $imageUrl;
    } elseif ($messageType === 'flex') {
        $content = $_POST['flex_content'];
        $flexJson = json_decode($content, true);
        $messages = [['type' => 'flex', 'altText' => $title, 'contents' => $flexJson]];
    }
    
    $sentCount = 0;
    $targetGroupId = null;
    
    // Send based on target type
    if ($targetType === 'database') {
        $stmt = $db->prepare("SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$currentBotId]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userIds)) {
            $chunks = array_chunk($userIds, 500);
            foreach ($chunks as $chunk) {
                $result = $line->multicastMessage($chunk, $messages);
                if ($result['code'] === 200) {
                    $sentCount += count($chunk);
                }
            }
        }
    } elseif ($targetType === 'all') {
        $result = $line->broadcastMessage($messages);
        if ($result['code'] === 200) {
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$currentBotId]);
            $sentCount = $stmt->fetch()['c'];
        }
    } elseif ($targetType === 'limit') {
        $limit = (int)$_POST['limit_count'];
        $stmt = $db->prepare("SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$currentBotId, $limit]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userIds)) {
            $result = $line->multicastMessage($userIds, $messages);
            if ($result['code'] === 200) {
                $sentCount = count($userIds);
            }
        }
    } elseif ($targetType === 'narrowcast') {
        $narrowcastLimit = (int)$_POST['narrowcast_limit'];
        $narrowcastFilter = $_POST['narrowcast_filter'] ?? 'none';
        
        $filter = null;
        if ($narrowcastFilter !== 'none') {
            switch ($narrowcastFilter) {
                case 'male':
                    $filter = ['demographic' => ['gender' => 'male']];
                    break;
                case 'female':
                    $filter = ['demographic' => ['gender' => 'female']];
                    break;
                case 'age_15_24':
                    $filter = ['demographic' => ['age' => ['gte' => 'age_15', 'lt' => 'age_25']]];
                    break;
                case 'age_25_34':
                    $filter = ['demographic' => ['age' => ['gte' => 'age_25', 'lt' => 'age_35']]];
                    break;
                case 'age_35_44':
                    $filter = ['demographic' => ['age' => ['gte' => 'age_35', 'lt' => 'age_45']]];
                    break;
                case 'age_45_plus':
                    $filter = ['demographic' => ['age' => ['gte' => 'age_45']]];
                    break;
            }
        }
        
        $result = $line->narrowcastMessage($messages, $narrowcastLimit, null, $filter);
        
        if ($result['code'] === 202) {
            $sentCount = $narrowcastLimit;
            $requestId = $result['requestId'] ?? null;
            if ($requestId) {
                $targetGroupId = $requestId;
            }
        }
    } elseif ($targetType === 'group') {
        $targetGroupId = $_POST['target_group_id'];
        $stmt = $db->prepare("SELECT u.line_user_id FROM users u 
                              JOIN user_groups ug ON u.id = ug.user_id 
                              WHERE ug.group_id = ? AND u.is_blocked = 0");
        $stmt->execute([$targetGroupId]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userIds)) {
            $result = $line->multicastMessage($userIds, $messages);
            if ($result['code'] === 200) {
                $sentCount = count($userIds);
            }
        }
    } elseif ($targetType === 'segment') {
        $segmentId = (int)$_POST['segment_id'];
        $segmentMembers = $crm->getSegmentMembers($segmentId);
        $userIds = [];
        foreach ($segmentMembers as $member) {
            if (!empty($member['line_user_id'])) {
                $userIds[] = $member['line_user_id'];
            }
        }
        
        if (!empty($userIds)) {
            $chunks = array_chunk($userIds, 500);
            foreach ($chunks as $chunk) {
                $result = $line->multicastMessage($chunk, $messages);
                if ($result['code'] === 200) {
                    $sentCount += count($chunk);
                }
            }
        }
    } elseif ($targetType === 'tag') {
        $tagId = (int)$_POST['tag_id'];
        $tagUsers = $crm->getUsersByTag($tagId);
        $userIds = [];
        foreach ($tagUsers as $user) {
            if (!empty($user['line_user_id'])) {
                $userIds[] = $user['line_user_id'];
            }
        }
        
        if (!empty($userIds)) {
            $chunks = array_chunk($userIds, 500);
            foreach ($chunks as $chunk) {
                $result = $line->multicastMessage($chunk, $messages);
                if ($result['code'] === 200) {
                    $sentCount += count($chunk);
                }
            }
        }
    } elseif ($targetType === 'select') {
        $selectedUsers = $_POST['selected_users'] ?? [];
        if (!empty($selectedUsers)) {
            $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
            $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id IN ($placeholders) AND is_blocked = 0");
            $stmt->execute($selectedUsers);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($userIds)) {
                $result = $line->multicastMessage($userIds, $messages);
                if ($result['code'] === 200) {
                    $sentCount = count($userIds);
                }
            }
        }
    } elseif ($targetType === 'single') {
        $userId = $_POST['single_user_id'];
        $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $result = $line->pushMessage($user['line_user_id'], $messages);
            if ($result['code'] === 200) {
                $sentCount = 1;
            }
        }
    }
    
    // Save broadcast record
    $stmt = $db->prepare("INSERT INTO broadcasts (line_account_id, title, message_type, content, target_type, target_group_id, sent_count, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW())");
    $stmt->execute([$currentBotId, $title, $messageType, $content, $targetType, $targetGroupId, $sentCount]);
    
    header('Location: broadcast.php?tab=send&sent=' . $sentCount);
    exit;
}

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

// Get templates
$stmt = $db->query("SELECT * FROM templates ORDER BY category, name");
$templates = $stmt->fetchAll();

// Get broadcast history
$stmt = $db->prepare("SELECT b.*, g.name as group_name FROM broadcasts b LEFT JOIN groups g ON b.target_group_id = g.id WHERE (b.line_account_id = ? OR b.line_account_id IS NULL) ORDER BY b.created_at DESC LIMIT 20");
$stmt->execute([$currentBotId]);
$history = $stmt->fetchAll();

// Count users in database
$stmt = $db->prepare("SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
$stmt->execute([$currentBotId]);
$totalUsers = $stmt->fetch()['c'];
?>

<?php if (isset($_GET['sent'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
    <i class="fas fa-check-circle text-2xl mr-3"></i>
    <div>
        <p class="font-medium">ส่ง Broadcast สำเร็จ!</p>
        <p class="text-sm">ส่งถึงผู้รับ <?= number_format($_GET['sent']) ?> คน</p>
    </div>
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
            <div class="flex flex-wrap gap-2" id="templateButtons">
                <?php 
                $templateCount = 0;
                foreach ($templates as $tpl): 
                    if ($templateCount >= 8) break;
                    $templateCount++;
                ?>
                <button type="button" onclick='loadTemplate(<?= json_encode($tpl) ?>)' class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm transition">
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
                    <input type="text" name="title" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="เช่น โปรโมชั่นเดือนธันวาคม">
                </div>
                
                <!-- Message Type -->
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">ประเภทข้อความ</label>
                    <div class="flex flex-wrap gap-2">
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="message_type" value="text" checked class="mr-2" onchange="toggleMessageType()">
                            <i class="fas fa-font mr-2 text-gray-500"></i>
                            <span>ข้อความ</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="message_type" value="image" class="mr-2" onchange="toggleMessageType()">
                            <i class="fas fa-image mr-2 text-gray-500"></i>
                            <span>รูปภาพ</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="message_type" value="flex" class="mr-2" onchange="toggleMessageType()">
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
                    <textarea name="content" id="contentText" rows="5" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="พิมพ์ข้อความที่ต้องการส่ง..."></textarea>
                    <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle mr-1"></i>รองรับ Emoji และข้อความยาวสูงสุด 5,000 ตัวอักษร</p>
                </div>
                
                <!-- Image Content -->
                <div id="imageContent" class="mb-4 hidden">
                    <label class="block text-sm font-medium mb-1">URL รูปภาพ</label>
                    <input type="url" name="image_url" id="imageUrl" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="https://example.com/image.jpg">
                    <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle mr-1"></i>รองรับ JPEG, PNG ขนาดไม่เกิน 10MB</p>
                    <div id="imagePreview" class="mt-2 hidden">
                        <img src="" class="max-w-xs rounded-lg border">
                    </div>
                </div>
                
                <!-- Flex Content -->
                <div id="flexContent" class="mb-4 hidden">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-sm font-medium">Flex Message JSON</label>
                        <a href="flex-builder.php" target="_blank" class="px-3 py-1.5 bg-gradient-to-r from-green-500 to-emerald-600 text-white text-sm rounded-lg hover:opacity-90">
                            🎨 Flex Builder
                        </a>
                    </div>
                    <textarea name="flex_content" id="flexJson" rows="8" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono text-sm" placeholder='{"type": "bubble", "body": {...}}'></textarea>
                </div>
                
                <!-- Target Type -->
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">ส่งถึง</label>
                    <div class="flex flex-wrap gap-2">
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="database" checked class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-database mr-2 text-blue-500"></i>
                            <span>ในฐานข้อมูล</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="all" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-users mr-2 text-blue-500"></i>
                            <span>เพื่อนทั้งหมด</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-purple-50 has-[:checked]:border-purple-500">
                            <input type="radio" name="target_type" value="segment" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-layer-group mr-2 text-purple-500"></i>
                            <span>Segment</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-orange-50 has-[:checked]:border-orange-500">
                            <input type="radio" name="target_type" value="tag" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-tag mr-2 text-orange-500"></i>
                            <span>Tag</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="group" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-users mr-2 text-blue-500"></i>
                            <span>กลุ่ม</span>
                        </label>
                    </div>
                </div>
                
                <!-- Target Options -->
                <div id="targetOptions" class="mb-4">
                    <div id="targetDatabase" class="p-4 bg-blue-50 rounded-lg">
                        <p class="text-blue-700"><i class="fas fa-database mr-2"></i>จะส่งข้อความถึงผู้ใช้ในฐานข้อมูล <strong><?= number_format($totalUsers) ?></strong> คน</p>
                    </div>
                    
                    <div id="targetAll" class="p-4 bg-blue-50 rounded-lg hidden">
                        <p class="text-blue-700"><i class="fas fa-users mr-2"></i>จะส่งข้อความถึงเพื่อนทั้งหมดของ LINE OA</p>
                    </div>
                    
                    <div id="targetSegment" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือก Customer Segment</label>
                        <select name="segment_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <option value="">-- เลือก Segment --</option>
                            <?php foreach ($segments as $segment): ?>
                            <option value="<?= $segment['id'] ?>"><?= htmlspecialchars($segment['name']) ?> (<?= number_format($segment['user_count']) ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="targetTag" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือก Tag</label>
                        <select name="tag_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="">-- เลือก Tag --</option>
                            <?php foreach ($tags as $tag): ?>
                            <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?> (<?= number_format($tag['user_count']) ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="targetGroup" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือกกลุ่ม</label>
                        <select name="target_group_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือกกลุ่ม --</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?> (<?= $group['member_count'] ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <button type="submit" onclick="return confirmSend()" class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium transition">
                    <i class="fas fa-paper-plane mr-2"></i>ส่ง Broadcast
                </button>
            </form>
        </div>
    </div>
    
    <!-- History Sidebar -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow p-6 sticky top-6">
            <h3 class="text-lg font-semibold mb-4">ประวัติการส่ง</h3>
            <div class="space-y-3 max-h-[600px] overflow-y-auto">
                <?php foreach ($history as $item): ?>
                <div class="p-3 bg-gray-50 rounded-lg">
                    <div class="flex justify-between items-start mb-1">
                        <h4 class="font-medium text-sm truncate flex-1"><?= htmlspecialchars($item['title']) ?></h4>
                        <span class="px-2 py-0.5 text-xs rounded ml-2 <?= $item['status'] === 'sent' ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-600' ?>">
                            <?= $item['message_type'] ?>
                        </span>
                    </div>
                    <div class="flex justify-between text-xs text-gray-400">
                        <span><i class="fas fa-users mr-1"></i><?= number_format($item['sent_count']) ?> คน</span>
                        <span><?= date('d/m H:i', strtotime($item['sent_at'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($history)): ?>
                <p class="text-gray-500 text-center py-4 text-sm">ยังไม่มีประวัติการส่ง</p>
                <?php endif; ?>
            </div>
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
    
    document.getElementById('targetDatabase').classList.add('hidden');
    document.getElementById('targetAll').classList.add('hidden');
    document.getElementById('targetSegment').classList.add('hidden');
    document.getElementById('targetTag').classList.add('hidden');
    document.getElementById('targetGroup').classList.add('hidden');
    
    const targetMap = {
        'database': 'targetDatabase',
        'all': 'targetAll',
        'segment': 'targetSegment',
        'tag': 'targetTag',
        'group': 'targetGroup'
    };
    
    if (targetMap[type]) {
        document.getElementById(targetMap[type]).classList.remove('hidden');
    }
}

function loadTemplate(tpl) {
    if (tpl.message_type === 'text') {
        document.querySelector('input[name="message_type"][value="text"]').checked = true;
        document.getElementById('contentText').value = tpl.content;
    } else if (tpl.message_type === 'flex') {
        document.querySelector('input[name="message_type"][value="flex"]').checked = true;
        document.getElementById('flexJson').value = tpl.content;
    }
    toggleMessageType();
}

function confirmSend() {
    const type = document.querySelector('input[name="target_type"]:checked').value;
    const typeNames = {
        'database': 'ผู้ใช้ในฐานข้อมูลทั้งหมด',
        'all': 'เพื่อนทั้งหมดของ LINE OA',
        'segment': 'สมาชิกใน Segment ที่เลือก',
        'tag': 'ผู้ใช้ที่มี Tag ที่เลือก',
        'group': 'สมาชิกในกลุ่มที่เลือก'
    };
    return confirm('ยืนยันการส่ง Broadcast ไปยัง ' + typeNames[type] + '?');
}

toggleMessageType();
toggleTargetType();
</script>
