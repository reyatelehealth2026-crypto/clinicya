<?php
/**
 * Broadcast - ส่งข้อความแบบ Broadcast (ครอบคลุมทุกรูปแบบ)
 * รองรับ Segments, Tags, และ Advanced Targeting
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';
require_once 'classes/AdvancedCRM.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Broadcast';

require_once 'includes/header.php';

// Get LineAPI for current bot
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);
$crm = new AdvancedCRM($db, $currentBotId, $line);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send') {
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
            // ส่งถึงผู้ใช้ในฐานข้อมูลทั้งหมด (filtered by current bot)
            $stmt = $db->prepare("SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$currentBotId]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($userIds)) {
                // Split into chunks of 500 (LINE API limit)
                $chunks = array_chunk($userIds, 500);
                foreach ($chunks as $chunk) {
                    $result = $line->multicastMessage($chunk, $messages);
                    if ($result['code'] === 200) {
                        $sentCount += count($chunk);
                    }
                }
            }
        } elseif ($targetType === 'all') {
            // ส่งถึงเพื่อนทั้งหมด (Broadcast API)
            $result = $line->broadcastMessage($messages);
            if ($result['code'] === 200) {
                $stmt = $db->prepare("SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$currentBotId]);
                $sentCount = $stmt->fetch()['c'];
            }
        } elseif ($targetType === 'limit') {
            // จำกัดจำนวน (จากฐานข้อมูล)
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
            // Narrowcast - ส่งถึงเพื่อนทั้งหมดใน LINE OA แบบจำกัดจำนวน
            $narrowcastLimit = (int)$_POST['narrowcast_limit'];
            $narrowcastFilter = $_POST['narrowcast_filter'] ?? 'none';
            
            // สร้าง demographic filter (ถ้าเลือก)
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
            
            if ($result['code'] === 202) { // Narrowcast returns 202 Accepted
                $sentCount = $narrowcastLimit; // ประมาณการ (จะรู้จริงจาก progress API)
                $requestId = $result['requestId'] ?? null;
                
                // บันทึก request_id สำหรับติดตามสถานะ
                if ($requestId) {
                    // จะใช้ตรวจสอบสถานะภายหลังได้
                    $targetGroupId = $requestId; // เก็บ requestId ไว้ใน target_group_id ชั่วคราว
                }
            }
        } elseif ($targetType === 'group') {
            // เลือกกลุ่ม
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
            // ส่งไปยัง Customer Segment
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
            // ส่งไปยังลูกค้าที่มี Tag
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
            // เลือกเอง (หลายคน)
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
            // คนเดียว
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
        
        header('Location: broadcast.php?sent=' . $sentCount);
        exit;
    }
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

// Get all users for selection (filtered by current bot)
$stmt = $db->prepare("SELECT id, display_name, picture_url FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY display_name");
$stmt->execute([$currentBotId]);
$allUsers = $stmt->fetchAll();

// Get templates
$stmt = $db->query("SELECT * FROM templates ORDER BY category, name");
$templates = $stmt->fetchAll();

// Get broadcast history (filtered by current bot)
$stmt = $db->prepare("SELECT b.*, g.name as group_name FROM broadcasts b LEFT JOIN groups g ON b.target_group_id = g.id WHERE (b.line_account_id = ? OR b.line_account_id IS NULL) ORDER BY b.created_at DESC LIMIT 20");
$stmt->execute([$currentBotId]);
$history = $stmt->fetchAll();

// Count users in database (filtered by current bot)
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
                        <button type="button" onclick="openAIModal()" class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                            <i class="fas fa-magic"></i> ใช้ AI ช่วยคิด
                        </button>
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
                        <div class="flex gap-2">
                            <a href="flex-builder.php" target="_blank" class="px-3 py-1.5 bg-gradient-to-r from-green-500 to-emerald-600 text-white text-sm rounded-lg hover:opacity-90">
                                🎨 Flex Builder (Drag & Drop)
                            </a>
                        </div>
                    </div>
                    <textarea name="flex_content" id="flexJson" rows="8" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono text-sm" placeholder='{"type": "bubble", "body": {...}}'></textarea>
                    <div id="flexPreviewArea" class="mt-3 hidden">
                        <div class="text-sm font-medium mb-2">📱 Preview:</div>
                        <div id="flexPreviewContent" class="bg-[#7494A5] p-4 rounded-lg max-w-[300px]"></div>
                    </div>
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
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="limit" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-hashtag mr-2 text-blue-500"></i>
                            <span>จำกัดจำนวน</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-green-50 has-[:checked]:border-green-500">
                            <input type="radio" name="target_type" value="narrowcast" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-bullseye mr-2 text-green-500"></i>
                            <span>Narrowcast (ทั้ง OA)</span>
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
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="select" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-user-check mr-2 text-blue-500"></i>
                            <span>เลือกเอง</span>
                        </label>
                        <label class="flex items-center px-4 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 has-[:checked]:bg-blue-50 has-[:checked]:border-blue-500">
                            <input type="radio" name="target_type" value="single" class="mr-2" onchange="toggleTargetType()">
                            <i class="fas fa-user mr-2 text-blue-500"></i>
                            <span>คนเดียว</span>
                        </label>
                    </div>
                </div>
                
                <!-- Target Options -->
                <div id="targetOptions" class="mb-4">
                    <!-- Database Info -->
                    <div id="targetDatabase" class="p-4 bg-blue-50 rounded-lg">
                        <p class="text-blue-700"><i class="fas fa-database mr-2"></i>จะส่งข้อความถึงผู้ใช้ในฐานข้อมูล <strong><?= number_format($totalUsers) ?></strong> คน</p>
                    </div>
                    
                    <!-- All Info -->
                    <div id="targetAll" class="p-4 bg-blue-50 rounded-lg hidden">
                        <p class="text-blue-700"><i class="fas fa-users mr-2"></i>จะส่งข้อความถึงเพื่อนทั้งหมดของ LINE OA (รวมคนที่ไม่ได้อยู่ในฐานข้อมูล)</p>
                        <p class="text-xs text-blue-600 mt-1">* ใช้ Broadcast API ของ LINE</p>
                    </div>
                    
                    <!-- Limit -->
                    <div id="targetLimit" class="hidden">
                        <label class="block text-sm font-medium mb-1">จำนวนที่ต้องการส่ง</label>
                        <input type="number" name="limit_count" min="1" max="10000" value="<?= min(100, $totalUsers) ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        <p class="text-xs text-gray-500 mt-1">ส่งถึงผู้ใช้ล่าสุด (เรียงตามวันที่เพิ่มเพื่อน) - มีผู้ใช้ในระบบ <?= number_format($totalUsers) ?> คน</p>
                    </div>
                    
                    <!-- Narrowcast - ส่งถึงเพื่อนทั้งหมดใน LINE OA แบบจำกัดจำนวน -->
                    <div id="targetNarrowcast" class="hidden space-y-4">
                        <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-bullseye text-green-500 text-xl mt-0.5"></i>
                                <div>
                                    <h4 class="font-medium text-green-800">Narrowcast - ส่งถึงเพื่อนทั้งหมดใน LINE OA</h4>
                                    <p class="text-sm text-green-700 mt-1">ส่งข้อความแบบสุ่มถึงเพื่อนทั้งหมดใน LINE OA (รวมคนที่ไม่ได้อยู่ในฐานข้อมูล) โดยจำกัดจำนวนผู้รับได้</p>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1">จำนวนที่ต้องการส่ง (สูงสุด)</label>
                            <input type="number" name="narrowcast_limit" min="1" max="100000" value="500" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <p class="text-xs text-gray-500 mt-1">LINE จะสุ่มส่งถึงเพื่อนตามจำนวนที่กำหนด (ไม่เกิน quota ที่เหลือ)</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1">กรองตาม Demographic (ไม่บังคับ)</label>
                            <select name="narrowcast_filter" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="none">ไม่กรอง - ส่งถึงทุกคน</option>
                                <optgroup label="เพศ">
                                    <option value="male">ผู้ชาย</option>
                                    <option value="female">ผู้หญิง</option>
                                </optgroup>
                                <optgroup label="อายุ">
                                    <option value="age_15_24">15-24 ปี</option>
                                    <option value="age_25_34">25-34 ปี</option>
                                    <option value="age_35_44">35-44 ปี</option>
                                    <option value="age_45_plus">45 ปีขึ้นไป</option>
                                </optgroup>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">* Demographic filter ใช้ได้เฉพาะ LINE OA ที่มีเพื่อน 100+ คน</p>
                        </div>
                        
                        <div class="p-3 bg-yellow-50 rounded-lg border border-yellow-200">
                            <p class="text-sm text-yellow-800"><i class="fas fa-info-circle mr-1"></i>Narrowcast เป็นการส่งแบบ async - ผลลัพธ์จะแสดงในประวัติหลังส่งเสร็จ</p>
                        </div>
                    </div>
                    
                    <!-- Segment Select -->
                    <div id="targetSegment" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือก Customer Segment</label>
                        <select name="segment_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <option value="">-- เลือก Segment --</option>
                            <?php foreach ($segments as $segment): ?>
                            <option value="<?= $segment['id'] ?>"><?= htmlspecialchars($segment['name']) ?> (<?= number_format($segment['user_count']) ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($segments)): ?>
                        <p class="text-xs text-purple-500 mt-1"><i class="fas fa-info-circle mr-1"></i><a href="customer-segments.php" class="underline">สร้าง Segment</a> เพื่อแบ่งกลุ่มลูกค้าอัจฉริยะ</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Tag Select -->
                    <div id="targetTag" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือก Tag</label>
                        <select name="tag_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="">-- เลือก Tag --</option>
                            <?php foreach ($tags as $tag): ?>
                            <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?> (<?= number_format($tag['user_count']) ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($tags)): ?>
                        <p class="text-xs text-orange-500 mt-1"><i class="fas fa-info-circle mr-1"></i><a href="user-tags.php" class="underline">สร้าง Tag</a> เพื่อจัดกลุ่มลูกค้า</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Group Select -->
                    <div id="targetGroup" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือกกลุ่ม</label>
                        <select name="target_group_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือกกลุ่ม --</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?> (<?= $group['member_count'] ?> คน)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($groups)): ?>
                        <p class="text-xs text-orange-500 mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>ยังไม่มีกลุ่ม <a href="groups.php" class="underline">สร้างกลุ่ม</a></p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Multi Select -->
                    <div id="targetSelect" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือกผู้ใช้ (เลือกได้หลายคน)</label>
                        <div class="border rounded-lg max-h-48 overflow-y-auto">
                            <?php foreach ($allUsers as $user): ?>
                            <label class="flex items-center p-2 hover:bg-gray-50 cursor-pointer border-b last:border-b-0">
                                <input type="checkbox" name="selected_users[]" value="<?= $user['id'] ?>" class="mr-3 user-checkbox">
                                <img src="<?= $user['picture_url'] ?: 'https://via.placeholder.com/32' ?>" class="w-8 h-8 rounded-full mr-2">
                                <span class="text-sm"><?= htmlspecialchars($user['display_name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-sm text-gray-500">เลือกแล้ว: <span id="selectedCount">0</span> คน</span>
                            <div class="space-x-2">
                                <button type="button" onclick="selectAllUsers()" class="text-sm text-blue-600 hover:underline">เลือกทั้งหมด</button>
                                <button type="button" onclick="deselectAllUsers()" class="text-sm text-gray-600 hover:underline">ยกเลิกทั้งหมด</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Single User -->
                    <div id="targetSingle" class="hidden">
                        <label class="block text-sm font-medium mb-1">เลือกผู้ใช้</label>
                        <select name="single_user_id" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">-- เลือกผู้ใช้ --</option>
                            <?php foreach ($allUsers as $user): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Submit -->
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
                    <p class="text-xs text-gray-500 truncate mb-1"><?= htmlspecialchars(substr($item['content'], 0, 50)) ?>...</p>
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
// Toggle message type sections
function toggleMessageType() {
    const type = document.querySelector('input[name="message_type"]:checked').value;
    document.getElementById('textContent').classList.toggle('hidden', type !== 'text');
    document.getElementById('imageContent').classList.toggle('hidden', type !== 'image');
    document.getElementById('flexContent').classList.toggle('hidden', type !== 'flex');
}

// Check for Flex from Builder (sessionStorage)
document.addEventListener('DOMContentLoaded', function() {
    const flexFromBuilder = sessionStorage.getItem('flex_broadcast');
    if (flexFromBuilder) {
        // Select Flex type
        document.querySelector('input[name="message_type"][value="flex"]').checked = true;
        document.getElementById('flexJson').value = JSON.stringify(JSON.parse(flexFromBuilder), null, 2);
        toggleMessageType();
        updateFlexPreview();
        sessionStorage.removeItem('flex_broadcast');
        showToast('โหลด Flex จาก Builder สำเร็จ!');
    }
    
    // Listen for flex json changes
    document.getElementById('flexJson')?.addEventListener('input', updateFlexPreview);
});

// Update Flex Preview
function updateFlexPreview() {
    const flexJson = document.getElementById('flexJson').value;
    const previewArea = document.getElementById('flexPreviewArea');
    const previewContent = document.getElementById('flexPreviewContent');
    
    if (!flexJson.trim()) {
        previewArea?.classList.add('hidden');
        return;
    }
    
    try {
        const flex = JSON.parse(flexJson);
        previewArea?.classList.remove('hidden');
        previewContent.innerHTML = renderFlexPreview(flex);
    } catch (e) {
        previewArea?.classList.add('hidden');
    }
}

// Simple Flex Preview Renderer
function renderFlexPreview(element) {
    if (!element) return '';
    const type = element.type;
    
    if (type === 'bubble') {
        return `<div class="bg-white rounded-2xl overflow-hidden shadow-lg">
            ${element.hero ? renderFlexPreview(element.hero) : ''}
            ${element.body ? '<div class="p-3">' + renderFlexPreview(element.body) + '</div>' : ''}
            ${element.footer ? '<div class="px-3 pb-3">' + renderFlexPreview(element.footer) + '</div>' : ''}
        </div>`;
    } else if (type === 'carousel') {
        return `<div class="flex gap-2 overflow-x-auto">
            ${element.contents?.map(b => '<div class="min-w-[240px]">' + renderFlexPreview(b) + '</div>').join('') || ''}
        </div>`;
    } else if (type === 'box') {
        const layout = element.layout === 'horizontal' ? 'flex-row' : 'flex-col';
        return `<div class="flex ${layout} gap-1">
            ${element.contents?.map(c => renderFlexPreview(c)).join('') || ''}
        </div>`;
    } else if (type === 'image') {
        return `<img src="${element.url}" class="w-full object-cover" style="aspect-ratio: ${element.aspectRatio?.replace(':', '/') || '16/9'}">`;
    } else if (type === 'text') {
        const size = {'xs':'text-xs','sm':'text-sm','md':'text-base','lg':'text-lg','xl':'text-xl','xxl':'text-2xl'}[element.size] || 'text-sm';
        const weight = element.weight === 'bold' ? 'font-bold' : '';
        return `<div class="${size} ${weight}" style="color:${element.color||'#333'}">${element.text||''}</div>`;
    } else if (type === 'button') {
        const bg = element.style === 'primary' ? (element.color || '#06C755') : 'transparent';
        const color = element.style === 'primary' ? '#fff' : (element.color || '#06C755');
        return `<button class="w-full py-2 px-3 rounded-lg text-sm font-medium" style="background:${bg};color:${color}">${element.action?.label||'Button'}</button>`;
    } else if (type === 'separator') {
        return '<hr class="border-gray-200 my-2">';
    }
    return '';
}

// Toggle target type sections
function toggleTargetType() {
    const type = document.querySelector('input[name="target_type"]:checked').value;
    
    // Hide all
    document.getElementById('targetDatabase').classList.add('hidden');
    document.getElementById('targetAll').classList.add('hidden');
    document.getElementById('targetLimit').classList.add('hidden');
    document.getElementById('targetNarrowcast').classList.add('hidden');
    document.getElementById('targetSegment').classList.add('hidden');
    document.getElementById('targetTag').classList.add('hidden');
    document.getElementById('targetGroup').classList.add('hidden');
    document.getElementById('targetSelect').classList.add('hidden');
    document.getElementById('targetSingle').classList.add('hidden');
    
    // Show selected
    const targetMap = {
        'database': 'targetDatabase',
        'all': 'targetAll',
        'limit': 'targetLimit',
        'narrowcast': 'targetNarrowcast',
        'segment': 'targetSegment',
        'tag': 'targetTag',
        'group': 'targetGroup',
        'select': 'targetSelect',
        'single': 'targetSingle'
    };
    
    if (targetMap[type]) {
        document.getElementById(targetMap[type]).classList.remove('hidden');
    }
}

// Load template
function loadTemplate(tpl) {
    if (tpl.message_type === 'text') {
        document.querySelector('input[name="message_type"][value="text"]').checked = true;
        document.getElementById('contentText').value = tpl.content;
    } else if (tpl.message_type === 'flex') {
        document.querySelector('input[name="message_type"][value="flex"]').checked = true;
        document.getElementById('flexJson').value = tpl.content;
    }
    toggleMessageType();
    showToast('โหลด Template: ' + tpl.name);
}

// Image preview
document.getElementById('imageUrl')?.addEventListener('input', function(e) {
    const url = e.target.value;
    const preview = document.getElementById('imagePreview');
    if (url) {
        preview.querySelector('img').src = url;
        preview.classList.remove('hidden');
    } else {
        preview.classList.add('hidden');
    }
});

// User selection count
document.querySelectorAll('.user-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

function updateSelectedCount() {
    const count = document.querySelectorAll('.user-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

function selectAllUsers() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function deselectAllUsers() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

// Confirm send
function confirmSend() {
    const type = document.querySelector('input[name="target_type"]:checked').value;
    const typeNames = {
        'database': 'ผู้ใช้ในฐานข้อมูลทั้งหมด',
        'all': 'เพื่อนทั้งหมดของ LINE OA',
        'limit': 'ผู้ใช้ตามจำนวนที่กำหนด',
        'narrowcast': 'เพื่อนใน LINE OA (Narrowcast) สูงสุด ' + (document.querySelector('input[name="narrowcast_limit"]')?.value || '500') + ' คน',
        'segment': 'สมาชิกใน Segment ที่เลือก',
        'tag': 'ผู้ใช้ที่มี Tag ที่เลือก',
        'group': 'สมาชิกในกลุ่มที่เลือก',
        'select': 'ผู้ใช้ที่เลือก ' + document.querySelectorAll('.user-checkbox:checked').length + ' คน',
        'single': 'ผู้ใช้ 1 คน'
    };
    
    return confirm('ยืนยันการส่ง Broadcast ไปยัง ' + typeNames[type] + '?');
}

// Initialize
toggleMessageType();
toggleTargetType();
</script>

<?php require_once 'includes/ai_helper.php'; ?>
<?php require_once 'includes/footer.php'; ?>
