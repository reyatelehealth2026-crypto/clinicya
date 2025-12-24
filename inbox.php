<?php
/**
 * Unified Inbox - Real-time Chat System
 * รวมระบบแชททั้งหมดเป็นหนึ่งเดียว พร้อม Real-time Updates
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/config/config.php')) {
    require_once 'config/config.php';
} elseif (file_exists(__DIR__ . '/config/confFig.php')) {
    require_once 'config/confFig.php';
}
require_once 'config/database.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'send_message':
                $userId = intval($_POST['user_id'] ?? 0);
                $message = trim($_POST['message'] ?? '');
                if (!$userId || !$message) throw new Exception("Invalid data");
                
                $stmt = $db->prepare("SELECT line_user_id, line_account_id, reply_token, reply_token_expires FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("User not found");
                
                $lineManager = new LineAccountManager($db);
                $line = $lineManager->getLineAPI($user['line_account_id']);

                if (method_exists($line, 'sendMessage')) {
                    $result = $line->sendMessage($user['line_user_id'], $message, $user['reply_token'] ?? null, $user['reply_token_expires'] ?? null, $db, $userId);
                } else {
                    $result = $line->pushMessage($user['line_user_id'], [['type' => 'text', 'text' => $message]]);
                    $result['method'] = 'push';
                }
                
                if ($result['code'] === 200) {
                    $adminName = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin';
                    $hasSentBy = false;
                    try {
                        $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                        $hasSentBy = $checkCol->rowCount() > 0;
                    } catch (Exception $e) {}
                    
                    if ($hasSentBy) {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, ?, NOW(), 1)");
                        $stmt->execute([$user['line_account_id'], $userId, $message, 'admin:' . $adminName]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, NOW(), 1)");
                        $stmt->execute([$user['line_account_id'], $userId, $message]);
                    }
                    $msgId = $db->lastInsertId();
                    echo json_encode(['success' => true, 'message_id' => $msgId, 'content' => $message, 'time' => date('H:i'), 'sent_by' => 'admin:' . $adminName, 'method' => $result['method'] ?? 'push']);
                } else {
                    throw new Exception("LINE API Error");
                }
                break;
                
            case 'ai_reply':
                require_once 'modules/AIChat/Autoloader.php';
                $userId = intval($_POST['user_id'] ?? 0);
                $lastMessage = $_POST['last_message'] ?? '';
                if (!$userId) throw new Exception("User ID required");
                
                $stmt = $db->prepare("SELECT line_user_id, line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("User not found");
                
                $adapter = new \Modules\AIChat\Adapters\GeminiChatAdapter($db, $user['line_account_id']);
                if (!$adapter->isEnabled()) throw new Exception("AI ยังไม่ได้เปิดใช้งาน");
                
                $response = $adapter->generateResponse($lastMessage, $userId);
                echo json_encode(['success' => true, 'message' => $response], JSON_UNESCAPED_UNICODE);
                break;
                
            case 'update_tags':
                $userId = intval($_POST['user_id'] ?? 0);
                $tagId = intval($_POST['tag_id'] ?? 0);
                $operation = $_POST['operation'] ?? 'add';
                
                if ($operation === 'add') {
                    $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')");
                    $stmt->execute([$userId, $tagId]);
                } else {
                    $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
                    $stmt->execute([$userId, $tagId]);
                }
                $stmt = $db->prepare("SELECT t.* FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = ?");
                $stmt->execute([$userId]);
                echo json_encode(['success' => true, 'tags' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
                
            case 'save_note':
                $userId = intval($_POST['user_id'] ?? 0);
                $note = trim($_POST['note'] ?? '');
                $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$userId, $note]);
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
                break;
                
            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Inbox';
require_once 'includes/header.php';

// Get Users List
$sql = "SELECT u.*, 
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread
        FROM users u WHERE u.line_account_id = ? ORDER BY last_time DESC";
$stmt = $db->prepare($sql);
$stmt->execute([$currentBotId]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Selected User
$selectedUser = null;
$messages = [];
$userTags = [];
$allTags = [];

if (isset($_GET['user'])) {
    $uid = intval($_GET['user']);
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedUser) {
        $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction = 'incoming'")->execute([$uid]);
        $stmt = $db->prepare("SELECT * FROM messages WHERE user_id = ? ORDER BY created_at ASC");
        $stmt->execute([$uid]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        try {
            $stmt = $db->prepare("SELECT t.* FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = ?");
            $stmt->execute([$uid]);
            $userTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name ASC");
            $stmt->execute([$currentBotId]);
            $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
}

function getMessagePreview($content, $type) {
    if ($content === null) return '';
    if ($type === 'image') return '📷 รูปภาพ';
    if ($type === 'sticker') return '😊 สติกเกอร์';
    if ($type === 'flex') return '📋 Flex';
    return mb_strlen($content) > 30 ? mb_substr($content, 0, 30) . '...' : $content;
}
?>

<style>
:root { --primary: #10B981; --primary-dark: #059669; }
.chat-scroll::-webkit-scrollbar { width: 5px; }
.chat-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 3px; }
.chat-bubble { white-space: pre-wrap; word-wrap: break-word; line-height: 1.5; max-width: 100%; }
.chat-incoming { background: #fff; color: #1E293B; border-radius: 0 12px 12px 12px; border: 1px solid #E2E8F0; }
.chat-outgoing { background: var(--primary); color: white; border-radius: 12px 0 12px 12px; }
.user-item.active { background: linear-gradient(90deg, #D1FAE5 0%, #ECFDF5 100%); border-left: 3px solid var(--primary); }
.user-item:hover { background: #F0FDF4; }
.tag-badge { font-size: 0.6rem; padding: 2px 6px; border-radius: 9999px; font-weight: 500; }
#chatBox { background: linear-gradient(180deg, #7494A5 0%, #6B8A9A 100%); }
.typing-indicator { display: flex; gap: 4px; padding: 8px 12px; background: #fff; border-radius: 12px; }
.typing-indicator span { width: 8px; height: 8px; background: #94A3B8; border-radius: 50%; animation: typing 1.4s infinite; }
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-4px); } }
.pulse-dot { animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
.new-message-flash { animation: flash 0.5s ease-out; }
@keyframes flash { 0% { background: #FEF3C7; } 100% { background: transparent; } }
</style>

<div class="h-[calc(100vh-80px)] flex bg-white rounded-xl shadow-lg border overflow-hidden">
    
    <!-- LEFT: User List -->
    <div id="sidebar" class="w-72 bg-white border-r flex flex-col">
        <div class="p-3 border-b bg-gradient-to-r from-emerald-500 to-green-600 flex items-center justify-between">
            <h2 class="text-white font-bold flex items-center">
                <i class="fas fa-inbox mr-2"></i>Inbox
                <span id="totalUnread" class="ml-2 text-xs bg-white/20 px-2 py-0.5 rounded-full"><?= count($users) ?></span>
            </h2>
            <div class="flex items-center gap-2">
                <span id="liveIndicator" class="w-2 h-2 bg-green-300 rounded-full pulse-dot" title="Real-time Active"></span>
            </div>
        </div>
        <div class="p-2 border-b">
            <input type="text" id="userSearch" placeholder="🔍 ค้นหา..." 
                   class="w-full px-3 py-2 bg-gray-100 rounded-lg text-sm focus:ring-2 focus:ring-emerald-500 outline-none" 
                   onkeyup="filterUsers(this.value)">
        </div>
        <div id="userList" class="flex-1 overflow-y-auto chat-scroll">
            <?php if (empty($users)): ?>
                <div class="p-6 text-center text-gray-400">
                    <i class="fas fa-inbox text-4xl mb-2"></i>
                    <p class="text-sm">ยังไม่มีแชท</p>
                </div>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                <a href="?user=<?= $user['id'] ?>" 
                   class="user-item block p-3 border-b border-gray-50 <?= ($selectedUser && $selectedUser['id'] == $user['id']) ? 'active' : '' ?>" 
                   data-user-id="<?= $user['id'] ?>"
                   data-name="<?= strtolower($user['display_name']) ?>">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-shrink-0">
                            <img src="<?= $user['picture_url'] ?: 'https://via.placeholder.com/40' ?>" 
                                 class="w-10 h-10 rounded-full object-cover border-2 border-white shadow">
                            <?php if ($user['unread'] > 0): ?>
                            <div class="unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold">
                                <?= $user['unread'] > 9 ? '9+' : $user['unread'] ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline">
                                <h3 class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($user['display_name']) ?></h3>
                                <span class="last-time text-[10px] text-gray-400"><?= $user['last_time'] ? date('H:i', strtotime($user['last_time'])) : '' ?></span>
                            </div>
                            <p class="last-msg text-xs text-gray-500 truncate"><?= htmlspecialchars(getMessagePreview($user['last_msg'], $user['last_type'])) ?></p>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- CENTER: Chat Area -->
    <div class="flex-1 flex flex-col bg-slate-100 min-w-0">
        <?php if ($selectedUser): ?>
        
        <!-- Chat Header -->
        <div class="h-14 bg-white border-b flex items-center justify-between px-4 shadow-sm">
            <div class="flex items-center gap-3">
                <img src="<?= $selectedUser['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full border-2 border-emerald-500">
                <div>
                    <h3 class="font-bold text-gray-800"><?= htmlspecialchars($selectedUser['display_name']) ?></h3>
                    <div id="userTags" class="flex gap-1 flex-wrap">
                        <?php foreach ($userTags as $tag): ?>
                        <span class="tag-badge" style="background-color: <?= htmlspecialchars($tag['color']) ?>20; color: <?= htmlspecialchars($tag['color']) ?>;"><?= htmlspecialchars($tag['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="generateAIReply()" class="px-3 py-1.5 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 rounded-lg text-sm font-medium" title="AI ช่วยตอบ">
                    <i class="fas fa-robot mr-1"></i>AI
                </button>
                <button onclick="togglePanel()" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm" title="ข้อมูลลูกค้า">
                    <i class="fas fa-user"></i>
                </button>
            </div>
        </div>

        <!-- Chat Messages -->
        <div id="chatBox" class="flex-1 overflow-y-auto p-4 space-y-3 chat-scroll">
            <?php foreach ($messages as $msg): 
                $isMe = ($msg['direction'] === 'outgoing');
                $content = $msg['content'];
                $type = $msg['message_type'];
            ?>
            <div class="message-item flex <?= $isMe ? 'justify-end' : 'justify-start' ?> group" data-msg-id="<?= $msg['id'] ?>">
                <?php if (!$isMe): ?>
                <img src="<?= $selectedUser['picture_url'] ?: 'https://via.placeholder.com/28' ?>" class="w-7 h-7 rounded-full self-end mr-2">
                <?php endif; ?>
                <div class="flex flex-col <?= $isMe ? 'items-end' : 'items-start' ?>" style="max-width:70%">
                    <?php if ($type === 'text'): ?>
                        <div class="chat-bubble px-4 py-2.5 text-sm shadow-sm <?= $isMe ? 'chat-outgoing' : 'chat-incoming' ?>">
                            <?= nl2br(htmlspecialchars($content ?? '')) ?>
                        </div>
                    <?php elseif ($type === 'image'): ?>
                        <?php 
                        $imgSrc = $content;
                        if (preg_match('/ID:\s*(\d+)/', $content, $m)) {
                            $imgSrc = 'api/line_content.php?id=' . $m[1];
                        }
                        ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>" class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90" onclick="openImage(this.src)">
                    <?php elseif ($type === 'sticker'): ?>
                        <?php 
                        $stickerId = '';
                        $json = json_decode($content, true);
                        if ($json && isset($json['stickerId'])) $stickerId = $json['stickerId'];
                        elseif (preg_match('/Sticker:\s*(\d+)/', $content, $m)) $stickerId = $m[1];
                        ?>
                        <?php if ($stickerId): ?>
                        <img src="https://stickershop.line-scdn.net/stickershop/v1/sticker/<?= $stickerId ?>/android/sticker.png" class="w-20">
                        <?php else: ?>
                        <div class="bg-white rounded-lg border p-2 text-xs text-gray-500">😊 Sticker</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="bg-white rounded-lg border p-3 text-xs text-gray-500"><i class="fas fa-file-alt mr-1"></i><?= ucfirst($type) ?></div>
                    <?php endif; ?>
                    <div class="text-[10px] text-gray-400 mt-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <?= date('H:i', strtotime($msg['created_at'])) ?>
                        <?php if ($isMe && !empty($msg['sent_by'])): ?>
                        <span class="ml-1"><?= htmlspecialchars($msg['sent_by']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Typing Indicator -->
            <div id="typingIndicator" class="hidden flex justify-start">
                <img src="<?= $selectedUser['picture_url'] ?: 'https://via.placeholder.com/28' ?>" class="w-7 h-7 rounded-full self-end mr-2">
                <div class="typing-indicator">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>

        <!-- AI Suggestion Panel -->
        <div id="aiPanel" class="hidden border-t bg-indigo-50 p-3">
            <div class="flex items-start gap-3">
                <div class="flex-1">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-robot text-indigo-600"></i>
                        <span class="text-sm font-medium text-indigo-700">AI แนะนำ:</span>
                        <button onclick="closeAIPanel()" class="ml-auto text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <p id="aiSuggestion" class="text-sm text-gray-700 bg-white rounded-lg p-3 border"></p>
                </div>
                <button onclick="useAISuggestion()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
                    <i class="fas fa-check mr-1"></i>ใช้
                </button>
            </div>
        </div>

        <!-- Input Area -->
        <div class="p-3 bg-white border-t">
            <form id="sendForm" class="flex gap-2 items-end" onsubmit="sendMessage(event)">
                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                <div class="flex-1 bg-gray-100 rounded-2xl px-4 py-2 focus-within:ring-2 focus-within:ring-emerald-500">
                    <textarea name="message" id="messageInput" rows="1" 
                              class="w-full bg-transparent border-0 outline-none text-sm resize-none max-h-24" 
                              placeholder="พิมพ์ข้อความ..." 
                              oninput="autoResize(this)"
                              onkeydown="handleKeyDown(event)"></textarea>
                </div>
                <button type="submit" id="sendBtn" class="bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white w-10 h-10 rounded-full flex items-center justify-center shadow-lg transition-all">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
        
        <?php else: ?>
        <div class="flex-1 flex flex-col items-center justify-center text-gray-400">
            <i class="far fa-comments text-6xl mb-4 text-gray-300"></i>
            <p class="text-lg font-medium">เลือกแชทเพื่อเริ่มสนทนา</p>
            <p class="text-sm">เลือกลูกค้าจากรายการด้านซ้าย</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Customer Panel -->
    <?php if ($selectedUser): ?>
    <div id="customerPanel" class="w-80 bg-white border-l flex-col hidden lg:flex">
        <div class="p-4 border-b bg-gray-50">
            <div class="flex items-center gap-3">
                <img src="<?= $selectedUser['picture_url'] ?: 'https://via.placeholder.com/60' ?>" class="w-14 h-14 rounded-full border-2 border-emerald-500">
                <div>
                    <h3 class="font-bold text-gray-800"><?= htmlspecialchars($selectedUser['display_name']) ?></h3>
                    <p class="text-xs text-gray-500"><?= $selectedUser['phone'] ?? '-' ?></p>
                </div>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto p-4 space-y-4 chat-scroll">
            <!-- Tags -->
            <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Tags</h4>
                <div id="tagList" class="flex flex-wrap gap-1">
                    <?php foreach ($userTags as $tag): ?>
                    <span class="tag-badge cursor-pointer" style="background-color: <?= htmlspecialchars($tag['color']) ?>20; color: <?= htmlspecialchars($tag['color']) ?>;" onclick="removeTag(<?= $tag['id'] ?>)"><?= htmlspecialchars($tag['name']) ?> ×</span>
                    <?php endforeach; ?>
                    <button onclick="showTagModal()" class="text-xs text-emerald-600 hover:text-emerald-700">+ เพิ่ม</button>
                </div>
            </div>
            
            <!-- Quick Info -->
            <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">ข้อมูล</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">สมาชิกตั้งแต่</span><span><?= date('d/m/Y', strtotime($selectedUser['created_at'])) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">แต้มสะสม</span><span class="font-medium text-emerald-600"><?= number_format($selectedUser['loyalty_points'] ?? 0) ?></span></div>
                </div>
            </div>
            
            <!-- Notes -->
            <div>
                <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">บันทึก</h4>
                <textarea id="noteInput" class="w-full p-2 border rounded-lg text-sm" rows="2" placeholder="เพิ่มบันทึก..."></textarea>
                <button onclick="saveNote()" class="mt-1 text-xs text-emerald-600 hover:text-emerald-700">บันทึก</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Tag Modal -->
<div id="tagModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl p-4 w-80 max-h-96 overflow-y-auto">
        <h3 class="font-bold mb-3">เลือก Tag</h3>
        <div class="space-y-2">
            <?php foreach ($allTags as $tag): ?>
            <button onclick="addTag(<?= $tag['id'] ?>)" class="w-full text-left px-3 py-2 rounded-lg hover:bg-gray-100 flex items-center gap-2">
                <span class="w-3 h-3 rounded-full" style="background-color: <?= htmlspecialchars($tag['color']) ?>"></span>
                <?= htmlspecialchars($tag['name']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <button onclick="closeTagModal()" class="mt-3 w-full py-2 bg-gray-100 rounded-lg text-sm">ปิด</button>
    </div>
</div>

<script>
const userId = <?= $selectedUser ? $selectedUser['id'] : 'null' ?>;
let lastMessageId = <?= !empty($messages) ? end($messages)['id'] ?? 0 : 0 ?>;
let pollingInterval = null;
let isPolling = false;

// ===== Real-time Polling =====
async function pollMessages() {
    if (!userId || isPolling) return;
    isPolling = true;
    
    try {
        const res = await fetch(`api/messages.php?action=poll&user_id=${userId}&last_id=${lastMessageId}`);
        const data = await res.json();
        
        if (data.success) {
            // Add new messages
            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    if (msg.id > lastMessageId) {
                        lastMessageId = msg.id;
                        appendMessage(msg);
                    }
                });
                scrollToBottom();
            }
            
            // Update sidebar unread counts
            if (data.unread_users) {
                data.unread_users.forEach(u => {
                    updateUserUnread(u.id, u.unread);
                });
            }
            
            // Update conversations order
            if (data.updated_conversations) {
                data.updated_conversations.forEach(conv => {
                    updateConversation(conv);
                });
            }
        }
    } catch (err) {
        console.error('Poll error:', err);
    }
    
    isPolling = false;
}

function appendMessage(msg) {
    const chatBox = document.getElementById('chatBox');
    const typingIndicator = document.getElementById('typingIndicator');
    const isMe = msg.direction === 'outgoing';
    
    const div = document.createElement('div');
    div.className = `message-item flex ${isMe ? 'justify-end' : 'justify-start'} group new-message-flash`;
    div.dataset.msgId = msg.id;
    
    let contentHtml = '';
    const content = msg.content || '';
    const type = msg.message_type || 'text';
    
    if (type === 'text') {
        contentHtml = `<div class="chat-bubble px-4 py-2.5 text-sm shadow-sm ${isMe ? 'chat-outgoing' : 'chat-incoming'}">${escapeHtml(content).replace(/\n/g, '<br>')}</div>`;
    } else if (type === 'image') {
        let imgSrc = content;
        const match = content.match(/ID:\s*(\d+)/);
        if (match) imgSrc = 'api/line_content.php?id=' + match[1];
        contentHtml = `<img src="${imgSrc}" class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90" onclick="openImage(this.src)">`;
    } else if (type === 'sticker') {
        let stickerId = '';
        try {
            const json = JSON.parse(content);
            if (json.stickerId) stickerId = json.stickerId;
        } catch(e) {
            const m = content.match(/Sticker:\s*(\d+)/);
            if (m) stickerId = m[1];
        }
        if (stickerId) {
            contentHtml = `<img src="https://stickershop.line-scdn.net/stickershop/v1/sticker/${stickerId}/android/sticker.png" class="w-20">`;
        } else {
            contentHtml = `<div class="bg-white rounded-lg border p-2 text-xs text-gray-500">😊 Sticker</div>`;
        }
    } else {
        contentHtml = `<div class="bg-white rounded-lg border p-3 text-xs text-gray-500"><i class="fas fa-file-alt mr-1"></i>${type}</div>`;
    }
    
    const time = msg.created_at ? new Date(msg.created_at).toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'}) : '';
    const sentBy = msg.sent_by ? `<span class="ml-1">${escapeHtml(msg.sent_by)}</span>` : '';
    
    div.innerHTML = `
        ${!isMe ? `<img src="<?= $selectedUser ? ($selectedUser['picture_url'] ?: 'https://via.placeholder.com/28') : '' ?>" class="w-7 h-7 rounded-full self-end mr-2">` : ''}
        <div class="flex flex-col ${isMe ? 'items-end' : 'items-start'}" style="max-width:70%">
            ${contentHtml}
            <div class="text-[10px] text-gray-400 mt-1 opacity-0 group-hover:opacity-100 transition-opacity">
                ${time}${isMe ? sentBy : ''}
            </div>
        </div>
    `;
    
    chatBox.insertBefore(div, typingIndicator);
    
    // Play sound for incoming
    if (!isMe) {
        playNotificationSound();
    }
}

function updateUserUnread(uid, count) {
    const item = document.querySelector(`[data-user-id="${uid}"]`);
    if (!item) return;
    
    let badge = item.querySelector('.unread-badge');
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('div');
            badge.className = 'unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold';
            item.querySelector('.relative').appendChild(badge);
        }
        badge.textContent = count > 9 ? '9+' : count;
        item.classList.add('new-message-flash');
    } else if (badge) {
        badge.remove();
    }
}

function updateConversation(conv) {
    const item = document.querySelector(`[data-user-id="${conv.id}"]`);
    if (!item) return;
    
    const lastMsg = item.querySelector('.last-msg');
    const lastTime = item.querySelector('.last-time');
    
    if (lastMsg && conv.last_message) {
        lastMsg.textContent = conv.last_message.length > 30 ? conv.last_message.substring(0, 30) + '...' : conv.last_message;
    }
    if (lastTime && conv.last_time) {
        lastTime.textContent = new Date(conv.last_time).toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'});
    }
    
    // Move to top
    const list = document.getElementById('userList');
    if (list.firstChild !== item) {
        list.insertBefore(item, list.firstChild);
    }
}

// ===== Send Message =====
async function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('messageInput');
    const message = input.value.trim();
    if (!message || !userId) return;
    
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('user_id', userId);
        formData.append('message', message);
        
        const res = await fetch('', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            input.value = '';
            autoResize(input);
            
            // Append immediately
            appendMessage({
                id: data.message_id || Date.now(),
                direction: 'outgoing',
                message_type: 'text',
                content: data.content,
                sent_by: data.sent_by,
                created_at: new Date().toISOString()
            });
            scrollToBottom();
        } else {
            alert('Error: ' + (data.error || 'ส่งไม่สำเร็จ'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i>';
}

// ===== AI Reply =====
async function generateAIReply() {
    const lastIncoming = [...document.querySelectorAll('.message-item')].reverse().find(el => {
        return el.querySelector('.chat-incoming');
    });
    
    let lastMessage = '';
    if (lastIncoming) {
        const bubble = lastIncoming.querySelector('.chat-bubble');
        if (bubble) lastMessage = bubble.textContent.trim();
    }
    
    if (!lastMessage) {
        alert('ไม่พบข้อความล่าสุดจากลูกค้า');
        return;
    }
    
    document.getElementById('aiPanel').classList.remove('hidden');
    document.getElementById('aiSuggestion').innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังคิด...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'ai_reply');
        formData.append('user_id', userId);
        formData.append('last_message', lastMessage);
        
        const res = await fetch('', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const data = await res.json();
        
        if (data.success && data.message) {
            document.getElementById('aiSuggestion').textContent = data.message;
        } else {
            document.getElementById('aiSuggestion').textContent = 'Error: ' + (data.error || 'ไม่สามารถสร้างคำตอบได้');
        }
    } catch (err) {
        document.getElementById('aiSuggestion').textContent = 'Error: ' + err.message;
    }
}

function useAISuggestion() {
    const suggestion = document.getElementById('aiSuggestion').textContent;
    if (suggestion && !suggestion.startsWith('Error:')) {
        document.getElementById('messageInput').value = suggestion;
        closeAIPanel();
    }
}

function closeAIPanel() {
    document.getElementById('aiPanel').classList.add('hidden');
}

// ===== Tags =====
async function addTag(tagId) {
    const formData = new FormData();
    formData.append('action', 'update_tags');
    formData.append('user_id', userId);
    formData.append('tag_id', tagId);
    formData.append('operation', 'add');
    
    await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
    location.reload();
}

async function removeTag(tagId) {
    const formData = new FormData();
    formData.append('action', 'update_tags');
    formData.append('user_id', userId);
    formData.append('tag_id', tagId);
    formData.append('operation', 'remove');
    
    await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
    location.reload();
}

function showTagModal() { document.getElementById('tagModal').classList.remove('hidden'); }
function closeTagModal() { document.getElementById('tagModal').classList.add('hidden'); }

// ===== Notes =====
async function saveNote() {
    const note = document.getElementById('noteInput').value.trim();
    if (!note) return;
    
    const formData = new FormData();
    formData.append('action', 'save_note');
    formData.append('user_id', userId);
    formData.append('note', note);
    
    await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
    document.getElementById('noteInput').value = '';
    alert('บันทึกแล้ว');
}

// ===== Utilities =====
function scrollToBottom() {
    const chatBox = document.getElementById('chatBox');
    if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 96) + 'px';
}

function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('sendForm').dispatchEvent(new Event('submit'));
    }
}

function filterUsers(query) {
    const items = document.querySelectorAll('.user-item');
    query = query.toLowerCase();
    items.forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(query) ? '' : 'none';
    });
}

function togglePanel() {
    const panel = document.getElementById('customerPanel');
    panel.classList.toggle('hidden');
    panel.classList.toggle('flex');
}

function openImage(src) {
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black/80 z-50 flex items-center justify-center p-4';
    modal.onclick = () => modal.remove();
    modal.innerHTML = `<img src="${src}" class="max-w-full max-h-[90vh] object-contain rounded-lg shadow-2xl">`;
    document.body.appendChild(modal);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function playNotificationSound() {
    try {
        const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2teleQAA');
        audio.volume = 0.3;
        audio.play().catch(() => {});
    } catch(e) {}
}

// ===== Initialize =====
document.addEventListener('DOMContentLoaded', () => {
    scrollToBottom();
    
    // Start polling every 1.5 seconds for real-time feel
    if (userId) {
        pollingInterval = setInterval(pollMessages, 1500);
        pollMessages(); // Initial poll
    }
});

// Cleanup on leave
window.addEventListener('beforeunload', () => {
    if (pollingInterval) clearInterval(pollingInterval);
});

// Click outside to close modals
document.getElementById('tagModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'tagModal') closeTagModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>
