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
require_once 'classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$activityLogger = ActivityLogger::getInstance($db);

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
                    // Get admin name from session - ใช้ username เป็นหลัก
                    $adminUser = $_SESSION['admin_user'] ?? null;
                    
                    // Debug: Log session data
                    error_log("INBOX DEBUG - admin_user: " . json_encode($adminUser));
                    error_log("INBOX DEBUG - full session: " . json_encode(array_keys($_SESSION)));
                    
                    // ดึงชื่อจาก session
                    $adminName = 'Admin'; // default
                    if (is_array($adminUser)) {
                        if (!empty($adminUser['username'])) {
                            $adminName = $adminUser['username'];
                        } elseif (!empty($adminUser['display_name'])) {
                            $adminName = $adminUser['display_name'];
                        }
                    }
                    
                    error_log("INBOX DEBUG - Final adminName: " . $adminName);
                    $hasSentBy = false;
                    try {
                        $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                        $hasSentBy = $checkCol->rowCount() > 0;
                    } catch (Exception $e) {}
                    
                    if ($hasSentBy) {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, ?, NOW(), 0)");
                        $stmt->execute([$user['line_account_id'], $userId, $message, 'admin:' . $adminName]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'text', ?, NOW(), 0)");
                        $stmt->execute([$user['line_account_id'], $userId, $message]);
                    }
                    $msgId = $db->lastInsertId();
                    $method = $result['method'] ?? 'push';
                    
                    // Log activity
                    $activityLogger->logMessage(ActivityLogger::ACTION_SEND, 'ส่งข้อความถึงลูกค้า', [
                        'user_id' => $userId,
                        'entity_type' => 'message',
                        'entity_id' => $msgId,
                        'new_value' => ['message' => mb_substr($message, 0, 100)],
                        'line_account_id' => $user['line_account_id']
                    ]);
                    
                    echo json_encode([
                        'success' => true, 
                        'message_id' => $msgId, 
                        'content' => $message, 
                        'time' => date('H:i'), 
                        'sent_by' => 'admin:' . $adminName, 
                        'method' => $method,
                        'method_label' => $method === 'reply' ? '✓ Reply (ฟรี)' : '💰 Push',
                        '_debug' => [
                            'session_id' => session_id(),
                            'has_admin_user' => isset($_SESSION['admin_user']),
                            'admin_username' => $adminUser['username'] ?? 'N/A',
                            'admin_display' => $adminUser['display_name'] ?? 'N/A',
                            'final_name' => $adminName
                        ]
                    ]);
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
                $noteId = $db->lastInsertId();
                
                // Log activity
                $activityLogger->logData(ActivityLogger::ACTION_CREATE, 'เพิ่มโน้ตลูกค้า', [
                    'user_id' => $userId,
                    'entity_type' => 'user_note',
                    'entity_id' => $noteId,
                    'new_value' => ['note' => mb_substr($note, 0, 100)]
                ]);
                
                echo json_encode(['success' => true, 'id' => $noteId]);
                break;
            
            case 'delete_note':
                $noteId = intval($_POST['note_id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM user_notes WHERE id = ?");
                $stmt->execute([$noteId]);
                
                // Log activity
                $activityLogger->logData(ActivityLogger::ACTION_DELETE, 'ลบโน้ตลูกค้า', [
                    'entity_type' => 'user_note',
                    'entity_id' => $noteId
                ]);
                
                echo json_encode(['success' => true]);
                break;
            
            case 'save_medical':
                $userId = intval($_POST['user_id'] ?? 0);
                $medicalConditions = trim($_POST['medical_conditions'] ?? '');
                $drugAllergies = trim($_POST['drug_allergies'] ?? '');
                $currentMedications = trim($_POST['current_medications'] ?? '');
                $stmt = $db->prepare("UPDATE users SET medical_conditions = ?, drug_allergies = ?, current_medications = ? WHERE id = ?");
                $stmt->execute([$medicalConditions, $drugAllergies, $currentMedications, $userId]);
                
                // Log activity
                $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'อัพเดทข้อมูลทางการแพทย์', [
                    'user_id' => $userId,
                    'entity_type' => 'user',
                    'entity_id' => $userId,
                    'new_value' => [
                        'medical_conditions' => $medicalConditions,
                        'drug_allergies' => $drugAllergies,
                        'current_medications' => $currentMedications
                    ]
                ]);
                
                echo json_encode(['success' => true]);
                break;
            
            case 'send_image':
                $userId = intval($_POST['user_id'] ?? 0);
                if (!$userId) throw new Exception("User ID required");
                if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("No image uploaded");
                }
                
                // Validate image
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = $_FILES['image']['type'];
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception("Invalid image type. Allowed: JPG, PNG, GIF, WEBP");
                }
                
                // Max 10MB
                if ($_FILES['image']['size'] > 10 * 1024 * 1024) {
                    throw new Exception("Image too large. Max 10MB");
                }
                
                // Get user info
                $stmt = $db->prepare("SELECT line_user_id, line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("User not found");
                
                // Upload image
                $uploadDir = __DIR__ . '/uploads/chat_images/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $filename = 'chat_' . time() . '_' . uniqid() . '.' . $ext;
                $filepath = $uploadDir . $filename;
                
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                    throw new Exception("Failed to save image");
                }
                
                // Get full URL
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $imageUrl = $protocol . $host . '/uploads/chat_images/' . $filename;
                
                // Send via LINE
                $lineManager = new LineAccountManager($db);
                $line = $lineManager->getLineAPI($user['line_account_id']);
                
                $result = $line->pushMessage($user['line_user_id'], [[
                    'type' => 'image',
                    'originalContentUrl' => $imageUrl,
                    'previewImageUrl' => $imageUrl
                ]]);
                
                if ($result['code'] === 200) {
                    // Get admin name
                    $adminUser = $_SESSION['admin_user'] ?? null;
                    $adminName = 'Admin';
                    if (is_array($adminUser)) {
                        $adminName = $adminUser['username'] ?? $adminUser['display_name'] ?? 'Admin';
                    }
                    
                    // Save to messages
                    $hasSentBy = false;
                    try {
                        $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                        $hasSentBy = $checkCol->rowCount() > 0;
                    } catch (Exception $e) {}
                    
                    if ($hasSentBy) {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'image', ?, ?, NOW(), 0)");
                        $stmt->execute([$user['line_account_id'], $userId, $imageUrl, 'admin:' . $adminName]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'image', ?, NOW(), 0)");
                        $stmt->execute([$user['line_account_id'], $userId, $imageUrl]);
                    }
                    $msgId = $db->lastInsertId();
                    
                    // Log activity
                    $activityLogger->logMessage(ActivityLogger::ACTION_SEND, 'ส่งรูปภาพถึงลูกค้า', [
                        'user_id' => $userId,
                        'entity_type' => 'message',
                        'entity_id' => $msgId,
                        'line_account_id' => $user['line_account_id']
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'message_id' => $msgId,
                        'image_url' => $imageUrl,
                        'time' => date('H:i'),
                        'sent_by' => 'admin:' . $adminName
                    ]);
                } else {
                    // Delete uploaded file on failure
                    @unlink($filepath);
                    throw new Exception("Failed to send image via LINE");
                }
                break;
            
            case 'test_session':
                // Debug: ทดสอบว่า session ถูกอ่านถูกต้องหรือไม่
                $adminUser = $_SESSION['admin_user'] ?? null;
                $adminName = 'Admin';
                if (is_array($adminUser)) {
                    if (!empty($adminUser['username'])) {
                        $adminName = $adminUser['username'];
                    } elseif (!empty($adminUser['display_name'])) {
                        $adminName = $adminUser['display_name'];
                    }
                }
                echo json_encode([
                    'success' => true,
                    'session_id' => session_id(),
                    'has_admin_user' => isset($_SESSION['admin_user']),
                    'admin_user' => $adminUser,
                    'calculated_name' => $adminName,
                    'sent_by' => 'admin:' . $adminName
                ], JSON_UNESCAPED_UNICODE);
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

function getSenderBadge($sentBy, $direction = 'outgoing') {
    // For outgoing messages without sent_by, show default "Admin"
    if (empty($sentBy) && $direction === 'outgoing') {
        return '<span class="sender-badge admin"><i class="fas fa-user-shield"></i> Admin</span>';
    }
    if (empty($sentBy)) return '';
    
    if (strpos($sentBy, 'admin:') === 0) {
        $name = substr($sentBy, 6);
        return '<span class="sender-badge admin"><i class="fas fa-user-shield"></i> ' . htmlspecialchars($name) . '</span>';
    }
    if ($sentBy === 'ai' || strpos($sentBy, 'ai:') === 0) {
        return '<span class="sender-badge ai"><i class="fas fa-robot"></i> AI</span>';
    }
    if ($sentBy === 'bot' || strpos($sentBy, 'bot:') === 0 || strpos($sentBy, 'system:') === 0) {
        return '<span class="sender-badge bot"><i class="fas fa-cog"></i> Bot</span>';
    }
    return '<span class="sender-badge">' . htmlspecialchars($sentBy) . '</span>';
}

// ฟังก์ชันแปลงเวลาเป็นภาษาไทย
function formatThaiTime($datetime) {
    if (!$datetime) return '';
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    // ถ้าเป็นวันนี้ แสดงเวลา
    if (date('Y-m-d', $timestamp) === date('Y-m-d', $now)) {
        return date('H:i น.', $timestamp);
    }
    
    // ถ้าเป็นเมื่อวาน
    if (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('-1 day'))) {
        return 'เมื่อวาน ' . date('H:i', $timestamp);
    }
    
    // ถ้าภายใน 7 วัน
    if ($diff < 604800) {
        $thaiDays = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
        return $thaiDays[date('w', $timestamp)] . ' ' . date('H:i', $timestamp);
    }
    
    // อื่นๆ แสดงวันที่
    return date('d/m', $timestamp) . ' ' . date('H:i', $timestamp);
}

function formatThaiDateTime($datetime) {
    if (!$datetime) return '';
    $timestamp = strtotime($datetime);
    $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $day = date('j', $timestamp);
    $month = $thaiMonths[intval(date('n', $timestamp))];
    $year = date('Y', $timestamp) + 543 - 2500; // แสดงเป็น พ.ศ. 2 หลัก
    $time = date('H:i', $timestamp);
    return "{$day} {$month} {$year} {$time}";
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

/* Collapsible Panel Sections */
.panel-section .section-content {
    max-height: 500px;
    overflow: hidden;
    transition: max-height 0.3s ease, opacity 0.3s ease, padding 0.3s ease;
    opacity: 1;
    padding-bottom: 8px;
}
.panel-section.collapsed .section-content {
    max-height: 0;
    opacity: 0;
    padding-bottom: 0;
}
.panel-section.collapsed .section-icon {
    transform: rotate(-90deg);
}
.section-icon {
    transition: transform 0.3s ease;
}

/* Toast Animation */
@keyframes fade-in { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fade-out { from { opacity: 1; } to { opacity: 0; } }
.animate-fade-in { animation: fade-in 0.3s ease; }
.animate-fade-out { animation: fade-out 0.3s ease; }

/* Sender Badge Styles */
.sender-badge { 
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 10px; padding: 2px 6px; border-radius: 4px; 
    font-weight: 600; margin-left: 6px;
}
.sender-badge.admin { background: #DBEAFE; color: #1E40AF; }
.sender-badge.ai { background: #E0E7FF; color: #4338CA; }
.sender-badge.bot { background: #FEE2E2; color: #991B1B; }

/* Read Status */
.read-status { font-size: 11px; margin-left: 4px; }
.read-status.sent { color: #94A3B8; }
.read-status.delivered { color: #94A3B8; }
.read-status.read { color: #10B981; }

/* Message Meta */
.msg-meta { 
    display: flex; align-items: center; gap: 4px; 
    font-size: 10px; color: rgba(255,255,255,0.7); 
    margin-top: 4px; 
}
.msg-meta.incoming { color: #64748B; }

/* Unread Divider */
.unread-divider {
    display: flex; align-items: center; gap: 10px;
    color: #EF4444; font-size: 12px; font-weight: 500;
    margin: 16px 0;
}
.unread-divider::before, .unread-divider::after {
    content: ''; flex: 1; height: 1px; background: #EF4444;
}

/* Notification Toast */
.notification-container {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 350px;
}
.notification-toast {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    padding: 12px 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    animation: slideIn 0.3s ease-out;
    border-left: 4px solid #10B981;
    cursor: pointer;
    transition: transform 0.2s, opacity 0.2s;
}
.notification-toast:hover {
    transform: translateX(-5px);
}
.notification-toast.closing {
    animation: slideOut 0.3s ease-in forwards;
}
.notification-toast img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}
.notification-toast .content {
    flex: 1;
    min-width: 0;
}
.notification-toast .name {
    font-weight: 600;
    color: #1E293B;
    font-size: 14px;
}
.notification-toast .message {
    color: #64748B;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.notification-toast .time {
    font-size: 11px;
    color: #94A3B8;
}
.notification-toast .close-btn {
    color: #94A3B8;
    cursor: pointer;
    padding: 4px;
}
.notification-toast .close-btn:hover {
    color: #64748B;
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

/* Sound Toggle Button */
.sound-toggle {
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.2s;
}
.sound-toggle:hover {
    background: rgba(255,255,255,0.2);
}

/* Mobile Responsive - Inbox Full Screen */
@media (max-width: 768px) {
    /* Hide main sidebar on inbox page */
    .sidebar {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
    }
    .top-header {
        display: none !important;
    }
    .content-area {
        padding: 0 !important;
        height: 100vh !important;
    }
    
    /* Inbox container - full screen */
    #inboxContainer {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        height: 100vh !important;
        border-radius: 0 !important;
        border: none !important;
        z-index: 50;
    }
    
    /* Chat list sidebar - full width, can slide out */
    #inboxSidebar {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        bottom: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        z-index: 100;
        transition: transform 0.3s ease;
        background: white;
    }
    #inboxSidebar.hidden-mobile {
        transform: translateX(-100%);
    }
    
    /* Chat area - full width */
    #chatArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        bottom: 0 !important;
        right: 0 !important;
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
    }
    
    /* Mobile back button */
    #mobileBackBtn {
        display: flex !important;
    }
    
    /* Mobile main menu button */
    #mainMenuBtn {
        display: flex !important;
    }
    
    /* Customer panel - full screen overlay */
    #customerPanel {
        position: fixed !important;
        top: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100% !important;
        z-index: 200 !important;
    }
}
@media (min-width: 769px) {
    #mobileBackBtn {
        display: none !important;
    }
    #mainMenuBtn {
        display: none !important;
    }
    
    /* Customer panel toggle - desktop */
    #customerPanel.hidden {
        display: none !important;
        width: 0 !important;
    }
    
    /* Chat area expands when panel is hidden */
    #chatArea {
        transition: flex 0.3s ease;
    }
}
</style>

<div id="inboxContainer" class="h-[calc(100vh-80px)] flex bg-white rounded-xl shadow-lg border overflow-hidden relative">
    
    <!-- LEFT: User List -->
    <div id="inboxSidebar" class="w-72 bg-white border-r flex flex-col">
        <div class="p-3 border-b bg-gradient-to-r from-emerald-500 to-green-600 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <!-- Mobile: Back to main menu -->
                <a href="dashboard.php" id="mainMenuBtn" class="hidden w-8 h-8 items-center justify-center rounded-full bg-white/20 hover:bg-white/30 text-white">
                    <i class="fas fa-bars"></i>
                </a>
                <h2 class="text-white font-bold flex items-center">
                    <i class="fas fa-inbox mr-2"></i>Inbox
                    <span id="totalUnread" class="ml-2 text-xs bg-white/20 px-2 py-0.5 rounded-full"><?= count($users) ?></span>
                </h2>
            </div>
            <div class="flex items-center gap-2">
                <button id="soundToggle" class="sound-toggle text-white" onclick="toggleSound()" title="เปิด/ปิดเสียง">
                    <i class="fas fa-volume-up" id="soundIcon"></i>
                </button>
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
                                <span class="last-time text-[10px] text-gray-400"><?= formatThaiTime($user['last_time']) ?></span>
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
    <div id="chatArea" class="flex-1 flex flex-col bg-slate-100 min-w-0">
        <?php if ($selectedUser): ?>
        
        <!-- Chat Header -->
        <div class="h-14 bg-white border-b flex items-center justify-between px-4 shadow-sm">
            <div class="flex items-center gap-3">
                <!-- Mobile: Back to chat list button -->
                <button id="mobileBackBtn" onclick="showChatList()" class="hidden w-8 h-8 items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 mr-1">
                    <i class="fas fa-arrow-left"></i>
                </button>
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
                <button onclick="toggleNotifications()" class="p-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg" title="เปิด/ปิดการแจ้งเตือน">
                    <i class="fas fa-bell" id="notifyIcon"></i>
                </button>
                <button onclick="toggleMute()" class="p-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg" title="ปิดเสียงแชทนี้">
                    <i class="fas fa-volume-up" id="muteIcon"></i>
                </button>
                <button onclick="blockUser()" class="p-2 bg-gray-100 hover:bg-red-100 text-gray-600 hover:text-red-600 rounded-lg" title="บล็อกผู้ใช้">
                    <i class="fas fa-user-slash"></i>
                </button>
                <button onclick="generateAIReply()" class="px-3 py-1.5 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 rounded-lg text-sm font-medium" title="AI ช่วยตอบ">
                    <i class="fas fa-robot mr-1"></i>AI
                </button>
                <button onclick="togglePanel()" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm cursor-pointer" title="ข้อมูลลูกค้า">
                    <i class="fas fa-user"></i>
                </button>
            </div>
        </div>

        <!-- Chat Messages -->
        <div id="chatBox" class="flex-1 overflow-y-auto p-4 space-y-3 chat-scroll">
            <?php 
            $hasUnread = false;
            foreach ($messages as $msg): 
                $isMe = ($msg['direction'] === 'outgoing');
                $content = $msg['content'];
                $type = $msg['message_type'];
                $sentBy = $msg['sent_by'] ?? '';
                $isRead = $msg['is_read'] ?? 0;
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
                    
                    <!-- Message Meta: Time + Sender + Read Status -->
                    <div class="msg-meta <?= $isMe ? '' : 'incoming' ?>">
                        <span><?= date('H:i น.', strtotime($msg['created_at'])) ?></span>
                        <?php if ($isMe): ?>
                            <?= getSenderBadge($sentBy, 'outgoing') ?>
                            <?php if ($isRead): ?>
                                <span class="read-status read" title="อ่านแล้ว">✓✓</span>
                            <?php else: ?>
                                <span class="read-status sent" title="ส่งแล้ว">✓</span>
                            <?php endif; ?>
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
                
                <!-- Image Upload Button -->
                <input type="file" id="imageInput" accept="image/*" class="hidden" onchange="handleImageSelect(this)">
                <button type="button" onclick="document.getElementById('imageInput').click()" 
                        class="w-10 h-10 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 flex items-center justify-center transition-all" 
                        title="แนบรูปภาพ">
                    <i class="fas fa-image"></i>
                </button>
                
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
            
            <!-- Image Preview -->
            <div id="imagePreview" class="hidden mt-2 p-2 bg-gray-50 rounded-lg">
                <div class="flex items-center gap-2">
                    <img id="previewImg" src="" class="w-16 h-16 object-cover rounded-lg">
                    <div class="flex-1">
                        <p id="previewName" class="text-sm text-gray-700 truncate"></p>
                        <p id="previewSize" class="text-xs text-gray-500"></p>
                    </div>
                    <button type="button" onclick="cancelImageUpload()" class="text-red-500 hover:text-red-700 p-2">
                        <i class="fas fa-times"></i>
                    </button>
                    <button type="button" onclick="sendImage()" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        <i class="fas fa-paper-plane mr-1"></i>ส่งรูป
                    </button>
                </div>
            </div>
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
    <?php 
    // Get user notes
    $userNotes = [];
    try {
        $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$selectedUser['id']]);
        $userNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    
    // Get user orders
    $userOrders = [];
    try {
        $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$selectedUser['id']]);
        $userOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    ?>
    <div id="customerPanel" class="w-72 bg-white border-l flex-col transition-all duration-300 overflow-hidden hidden lg:flex">
        <div class="p-3 border-b bg-gray-50 flex items-center justify-between flex-shrink-0">
            <h3 class="text-sm font-bold text-gray-700"><i class="fas fa-user text-emerald-500 mr-2"></i>รายละเอียดลูกค้า</h3>
            <button onclick="togglePanel()" class="text-gray-400 hover:text-gray-600 p-1 cursor-pointer"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="flex-1 overflow-y-auto chat-scroll p-3 space-y-4">
            <!-- Profile -->
            <div class="text-center pb-3 border-b">
                <img src="<?= $selectedUser['picture_url'] ?: 'https://via.placeholder.com/60' ?>" class="w-16 h-16 rounded-full mx-auto border-2 border-emerald-500 mb-2">
                <h4 class="font-bold text-gray-800"><?= htmlspecialchars($selectedUser['display_name']) ?></h4>
                <p class="text-xs text-gray-500"><?= $selectedUser['phone'] ?: 'ไม่มีเบอร์โทร' ?></p>
                <div class="flex justify-center gap-1 mt-2 flex-wrap">
                    <?php foreach ($userTags as $tag): ?>
                    <span class="tag-badge" style="background-color: <?= htmlspecialchars($tag['color']) ?>20; color: <?= htmlspecialchars($tag['color']) ?>;"><?= htmlspecialchars($tag['name']) ?></span>
                    <?php endforeach; ?>
                    <button onclick="showTagModal()" class="text-xs text-emerald-600 hover:text-emerald-700">+ เพิ่ม</button>
                </div>
            </div>
            
            <!-- Quick Info - Collapsible -->
            <div class="panel-section" data-section="quick-info">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('quick-info')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-info-circle text-blue-500 mr-1"></i>ข้อมูลทั่วไป</h5>
                    <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                </div>
                <div class="section-content space-y-2 text-xs">
                    <div class="flex justify-between"><span class="text-gray-500">สมาชิกตั้งแต่</span><span class="font-medium"><?= date('d/m/Y', strtotime($selectedUser['created_at'])) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">ออเดอร์ทั้งหมด</span><span class="font-medium"><?= count($userOrders) ?> รายการ</span></div>
                    <?php $totalSpent = array_sum(array_column($userOrders, 'grand_total')); ?>
                    <div class="flex justify-between"><span class="text-gray-500">ยอดซื้อรวม</span><span class="font-medium text-emerald-600">฿<?= number_format($totalSpent) ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">แต้มสะสม</span><span class="font-medium text-emerald-600"><?= number_format($selectedUser['loyalty_points'] ?? 0) ?></span></div>
                </div>
            </div>
            
            <!-- Medical Info Section - Collapsible -->
            <div class="panel-section border-t" data-section="medical-info">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('medical-info')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-heartbeat text-red-500 mr-1"></i>ข้อมูลสุขภาพ</h5>
                    <div class="flex items-center gap-2">
                        <button onclick="event.stopPropagation(); openMedicalModal()" class="text-blue-500 hover:text-blue-600 text-xs"><i class="fas fa-edit"></i></button>
                        <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                    </div>
                </div>
                <div class="section-content space-y-2 text-xs">
                    <div class="bg-red-50 border border-red-200 rounded-lg p-2">
                        <p class="text-red-600 font-medium text-[10px] mb-1"><i class="fas fa-disease mr-1"></i>โรคประจำตัว</p>
                        <p class="text-gray-700" id="medicalConditions"><?= htmlspecialchars($selectedUser['medical_conditions'] ?? '') ?: '<span class="text-gray-400">ไม่ระบุ</span>' ?></p>
                    </div>
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-2">
                        <p class="text-orange-600 font-medium text-[10px] mb-1"><i class="fas fa-allergies mr-1"></i>แพ้ยา</p>
                        <p class="text-gray-700" id="drugAllergies"><?= htmlspecialchars($selectedUser['drug_allergies'] ?? '') ?: '<span class="text-gray-400">ไม่ระบุ</span>' ?></p>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-2">
                        <p class="text-blue-600 font-medium text-[10px] mb-1"><i class="fas fa-pills mr-1"></i>ยาที่ใช้อยู่</p>
                        <p class="text-gray-700" id="currentMedications"><?= htmlspecialchars($selectedUser['current_medications'] ?? '') ?: '<span class="text-gray-400">ไม่ระบุ</span>' ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Notes Section - Collapsible -->
            <div class="panel-section border-t" data-section="notes">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('notes')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-sticky-note text-yellow-500 mr-1"></i>โน๊ต</h5>
                    <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                </div>
                <div class="section-content">
                    <form onsubmit="saveNote(event)" class="mb-2">
                        <textarea id="noteInput" rows="2" class="w-full border rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-emerald-500 outline-none resize-none" placeholder="เพิ่มโน๊ตเกี่ยวกับลูกค้า..."></textarea>
                        <button type="submit" class="w-full mt-1 bg-emerald-500 hover:bg-emerald-600 text-white text-xs py-1.5 rounded-lg">บันทึกโน๊ต</button>
                    </form>
                    <div id="notesList" class="space-y-2 max-h-40 overflow-y-auto">
                        <?php foreach ($userNotes as $note): ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-2 text-xs relative group">
                            <p class="text-gray-700"><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                            <p class="text-[9px] text-gray-400 mt-1"><?= date('d/m/Y H:i', strtotime($note['created_at'])) ?></p>
                            <button onclick="deleteNote(<?= $note['id'] ?>, this)" class="absolute top-1 right-1 text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100"><i class="fas fa-times text-[10px]"></i></button>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($userNotes)): ?><p class="text-gray-400 text-xs text-center py-2">ยังไม่มีโน๊ต</p><?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Orders - Collapsible -->
            <?php if (!empty($userOrders)): ?>
            <div class="panel-section border-t" data-section="orders">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('orders')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-shopping-bag text-blue-500 mr-1"></i>ออเดอร์ล่าสุด</h5>
                    <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                </div>
                <div class="section-content space-y-1.5">
                    <?php foreach (array_slice($userOrders, 0, 3) as $order): ?>
                    <div class="bg-gray-50 rounded-lg p-2 text-xs">
                        <div class="flex justify-between"><span class="font-medium">#<?= $order['order_number'] ?? $order['id'] ?></span><span class="text-emerald-600">฿<?= number_format($order['grand_total']) ?></span></div>
                        <div class="text-[9px] text-gray-400"><?= date('d/m/Y', strtotime($order['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="pt-3 border-t space-y-1.5">
                <a href="user-detail.php?id=<?= $selectedUser['id'] ?>" class="block w-full text-center bg-gray-500 hover:bg-gray-600 text-white text-xs py-2 rounded-lg"><i class="fas fa-external-link-alt mr-1"></i>ดูโปรไฟล์เต็ม</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Medical Info Modal -->
<?php if ($selectedUser): ?>
<div id="medicalModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="p-3 border-b flex justify-between items-center bg-red-50">
            <h3 class="font-bold text-sm text-red-700"><i class="fas fa-heartbeat mr-1"></i>ข้อมูลสุขภาพ</h3>
            <button onclick="closeMedicalModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form onsubmit="saveMedical(event)" class="p-4 space-y-3">
            <div>
                <label class="block text-xs font-medium text-red-600 mb-1"><i class="fas fa-disease mr-1"></i>โรคประจำตัว</label>
                <textarea id="inputMedicalConditions" rows="2" class="w-full border border-red-200 rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-red-500 outline-none resize-none" placeholder="เช่น เบาหวาน, ความดันโลหิตสูง..."><?= htmlspecialchars($selectedUser['medical_conditions'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-orange-600 mb-1"><i class="fas fa-allergies mr-1"></i>แพ้ยา</label>
                <textarea id="inputDrugAllergies" rows="2" class="w-full border border-orange-200 rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-orange-500 outline-none resize-none" placeholder="เช่น Penicillin, Aspirin..."><?= htmlspecialchars($selectedUser['drug_allergies'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="block text-xs font-medium text-blue-600 mb-1"><i class="fas fa-pills mr-1"></i>ยาที่ใช้อยู่</label>
                <textarea id="inputCurrentMedications" rows="2" class="w-full border border-blue-200 rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none resize-none" placeholder="เช่น Metformin 500mg วันละ 2 ครั้ง..."><?= htmlspecialchars($selectedUser['current_medications'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white text-xs py-2 rounded-lg font-medium">
                <i class="fas fa-save mr-1"></i>บันทึกข้อมูล
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

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

<!-- Notification Container -->
<div id="notificationContainer" class="notification-container"></div>

<!-- Audio for notifications -->
<audio id="notificationSound" preload="auto">
    <source src="data:audio/mpeg;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAABhgC7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7//////////////////////////////////////////////////////////////////8AAAAATGF2YzU4LjEzAAAAAAAAAAAAAAAAJAAAAAAAAAAAAYYNBrv2AAAAAAAAAAAAAAAAAAAAAP/7UMQAA8AAADSAAAAAAAAANIAAAAATEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVX/+1DEAYPAAADSAAAAAAAAANIAAAAATEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVU=" type="audio/mpeg">
</audio>

<script>
const userId = <?= $selectedUser ? $selectedUser['id'] : 'null' ?>;
const currentUserName = '<?= $selectedUser ? htmlspecialchars($selectedUser['display_name']) : '' ?>';
const currentUserPic = '<?= $selectedUser ? ($selectedUser['picture_url'] ?: 'https://via.placeholder.com/40') : '' ?>';
let lastMessageId = <?= !empty($messages) ? end($messages)['id'] ?? 0 : 0 ?>;
let pollingInterval = null;
let isPolling = false;
let sentMessageIds = new Set();
let soundEnabled = localStorage.getItem('inboxSoundEnabled') !== 'false'; // default true

// Debug: แสดงค่าเริ่มต้น
console.log('🚀 Inbox initialized:', { userId, lastMessageId, currentUserName });

// Update sound icon on load
document.addEventListener('DOMContentLoaded', () => {
    updateSoundIcon();
    scrollToBottom();
    
    // Start polling
    startPolling();
    
    // Request notification permission
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    
    // Initial poll - ทำทันที
    if (userId) {
        console.log('📡 Starting initial poll for user:', userId);
        pollMessages();
    } else {
        console.log('⚠️ No user selected, polling for sidebar only');
        pollSidebar();
    }
});

function updateSoundIcon() {
    const icon = document.getElementById('soundIcon');
    if (icon) {
        icon.className = soundEnabled ? 'fas fa-volume-up' : 'fas fa-volume-mute';
    }
}

function toggleSound() {
    soundEnabled = !soundEnabled;
    localStorage.setItem('inboxSoundEnabled', soundEnabled);
    updateSoundIcon();
    showToast(soundEnabled ? '🔊 เปิดเสียงแจ้งเตือน' : '🔇 ปิดเสียงแจ้งเตือน', '', '', 2000);
}

// ===== Notification Functions =====
function showNotification(name, message, pictureUrl, userId) {
    // Show toast notification
    showToast(name, message, pictureUrl, 5000, userId);
    
    // Play sound
    if (soundEnabled) {
        playNotificationSound();
    }
    
    // Browser notification (if permitted and tab not focused)
    if (!document.hasFocus() && 'Notification' in window && Notification.permission === 'granted') {
        const notification = new Notification('ข้อความใหม่จาก ' + name, {
            body: message.substring(0, 100),
            icon: pictureUrl || 'https://via.placeholder.com/40',
            tag: 'inbox-' + userId
        });
        notification.onclick = () => {
            window.focus();
            if (userId) window.location.href = '?user=' + userId;
            notification.close();
        };
        setTimeout(() => notification.close(), 5000);
    }
}

function showToast(name, message, pictureUrl, duration = 5000, clickUserId = null) {
    const container = document.getElementById('notificationContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'notification-toast';
    toast.innerHTML = `
        ${pictureUrl ? `<img src="${pictureUrl}" alt="">` : ''}
        <div class="content">
            <div class="name">${escapeHtml(name)}</div>
            ${message ? `<div class="message">${escapeHtml(message)}</div>` : ''}
            <div class="time">${formatThaiTimeJS(new Date())}</div>
        </div>
        <span class="close-btn" onclick="event.stopPropagation(); this.parentElement.remove();"><i class="fas fa-times"></i></span>
    `;
    
    if (clickUserId) {
        toast.onclick = () => {
            window.location.href = '?user=' + clickUserId;
        };
    }
    
    container.appendChild(toast);
    
    // Auto remove
    setTimeout(() => {
        toast.classList.add('closing');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

function formatThaiTimeJS(date) {
    const now = new Date();
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    
    if (date.toDateString() === now.toDateString()) {
        return `${hours}:${minutes} น.`;
    }
    
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return `เมื่อวาน ${hours}:${minutes}`;
    }
    
    const thaiDays = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
    const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24));
    if (diffDays < 7) {
        return `${thaiDays[date.getDay()]} ${hours}:${minutes}`;
    }
    
    return `${date.getDate()}/${date.getMonth() + 1} ${hours}:${minutes}`;
}

// ===== Real-time Polling =====
let pollErrorCount = 0;
let lastPollTime = Date.now();
let globalLastMsgId = <?= !empty($messages) ? end($messages)['id'] ?? 0 : 0 ?>; // Track globally for sidebar

async function pollMessages() {
    if (!userId || isPolling) {
        console.log('⏭️ Skip poll:', { userId, isPolling });
        return;
    }
    isPolling = true;
    
    // Update live indicator
    const indicator = document.getElementById('liveIndicator');
    if (indicator) indicator.style.background = '#FCD34D'; // yellow = polling
    
    try {
        const url = `api/messages.php?action=poll&user_id=${userId}&last_id=${lastMessageId}&_t=${Date.now()}`;
        console.log('📡 Polling:', url);
        
        const res = await fetch(url);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        
        const data = await res.json();
        console.log('📥 Poll response:', data);
        
        if (data.success) {
            pollErrorCount = 0;
            lastPollTime = Date.now();
            if (indicator) indicator.style.background = '#86EFAC'; // green = success
            
            // Add new messages to chat
            if (data.messages && data.messages.length > 0) {
                console.log('📨 New messages for current user:', data.messages.length);
                data.messages.forEach(msg => {
                    const msgId = parseInt(msg.id);
                    const isIncoming = msg.direction === 'incoming';
                    
                    console.log('Processing msg:', { msgId, lastMessageId, exists: !!document.querySelector(`[data-msg-id="${msgId}"]`) });
                    
                    if (msgId > lastMessageId && !sentMessageIds.has(msgId) && !document.querySelector(`[data-msg-id="${msgId}"]`)) {
                        console.log('✅ Appending message:', msgId);
                        appendMessage(msg);
                        
                        if (isIncoming) {
                            showNotification(
                                currentUserName || 'ลูกค้า',
                                msg.content || 'ส่งข้อความใหม่',
                                currentUserPic,
                                userId
                            );
                        }
                    }
                    if (msgId > lastMessageId) {
                        lastMessageId = msgId;
                    }
                });
                scrollToBottom();
            }
            
            // Update sidebar
            if (data.unread_users) {
                data.unread_users.forEach(u => updateUserUnread(u.id, u.unread));
            }
            
            // Notifications for other users + update sidebar
            if (data.updated_conversations) {
                data.updated_conversations.forEach(conv => {
                    // Update sidebar item
                    updateSidebarUser(conv);
                    
                    // Show notification for other users
                    if (conv.id != userId && conv.last_message) {
                        console.log('🔔 Notification for other user:', conv.display_name);
                        showNotification(conv.display_name || 'ลูกค้า', conv.last_message, conv.picture_url, conv.id);
                    }
                });
            }
        }
    } catch (err) {
        pollErrorCount++;
        console.error('Poll error:', err, 'Count:', pollErrorCount);
        if (indicator) indicator.style.background = '#FCA5A5'; // red = error
        
        // If too many errors, slow down polling
        if (pollErrorCount > 5) {
            console.warn('Too many poll errors, slowing down...');
        }
    }
    
    isPolling = false;
}

// Start polling with visibility check
function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    
    // Poll every 1.5 seconds for faster updates
    if (userId) {
        pollingInterval = setInterval(pollMessages, 1500);
        console.log('🟢 Polling started for user:', userId);
    } else {
        // ถ้าไม่ได้เลือก user ก็ poll sidebar อย่างเดียว
        pollingInterval = setInterval(pollSidebar, 3000);
        console.log('🟢 Sidebar polling started');
    }
}

// Poll sidebar only (when no user selected)
async function pollSidebar() {
    try {
        const res = await fetch(`api/messages.php?action=get_conversations&_t=${Date.now()}`);
        const data = await res.json();
        
        if (data.success && data.conversations) {
            // Update sidebar with new data
            data.conversations.forEach(conv => {
                updateSidebarUser(conv);
            });
            
            // Show notification for unread
            if (data.total_unread > 0) {
                document.getElementById('totalUnread').textContent = data.total_unread;
            }
        }
    } catch (err) {
        console.error('Sidebar poll error:', err);
    }
}

// Update sidebar user item
function updateSidebarUser(conv) {
    const item = document.querySelector(`[data-user-id="${conv.id}"]`);
    if (!item) return;
    
    // Update last message
    const lastMsgEl = item.querySelector('.last-msg');
    if (lastMsgEl && conv.last_message) {
        let preview = conv.last_message;
        if (conv.last_type === 'image') preview = '📷 รูปภาพ';
        else if (conv.last_type === 'sticker') preview = '😊 สติกเกอร์';
        else if (preview.length > 30) preview = preview.substring(0, 30) + '...';
        lastMsgEl.textContent = preview;
    }
    
    // Update time
    const timeEl = item.querySelector('.last-time');
    if (timeEl && conv.last_time) {
        timeEl.textContent = formatThaiTimeJS(new Date(conv.last_time));
    }
    
    // Update unread badge
    updateUserUnread(conv.id, conv.unread_count || 0);
    
    // Flash animation
    if (conv.unread_count > 0) {
        item.classList.add('new-message-flash');
        setTimeout(() => item.classList.remove('new-message-flash'), 1000);
    }
}

function stopPolling() {
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
        console.log('🔴 Polling stopped');
    }
}

// Pause polling when tab is hidden
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopPolling();
    } else {
        startPolling();
        // Immediate poll when tab becomes visible
        pollMessages();
    }
});

function appendMessage(msg) {
    const chatBox = document.getElementById('chatBox');
    const typingIndicator = document.getElementById('typingIndicator');
    const isMe = msg.direction === 'outgoing';
    
    // Check if message already exists
    if (document.querySelector(`[data-msg-id="${msg.id}"]`)) {
        return;
    }
    
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
    const sentBy = msg.sent_by || '';
    const isRead = msg.is_read == 1;
    
    // Build sender badge - show "Admin" as default for outgoing without sent_by
    let senderBadge = '';
    if (isMe) {
        if (sentBy && sentBy.startsWith('admin:')) {
            const name = sentBy.substring(6);
            senderBadge = `<span class="sender-badge admin"><i class="fas fa-user-shield"></i> ${escapeHtml(name)}</span>`;
        } else if (sentBy === 'ai' || (sentBy && sentBy.startsWith('ai:'))) {
            senderBadge = `<span class="sender-badge ai"><i class="fas fa-robot"></i> AI</span>`;
        } else if (sentBy === 'bot' || (sentBy && (sentBy.startsWith('bot:') || sentBy.startsWith('system:')))) {
            senderBadge = `<span class="sender-badge bot"><i class="fas fa-cog"></i> Bot</span>`;
        } else if (sentBy) {
            senderBadge = `<span class="sender-badge">${escapeHtml(sentBy)}</span>`;
        } else {
            // Default for outgoing without sent_by
            senderBadge = `<span class="sender-badge admin"><i class="fas fa-user-shield"></i> Admin</span>`;
        }
    }
    
    // Read status
    let readStatus = '';
    if (isMe) {
        readStatus = isRead 
            ? '<span class="read-status read" title="อ่านแล้ว">✓✓</span>'
            : '<span class="read-status sent" title="ส่งแล้ว">✓</span>';
    }
    
    div.innerHTML = `
        ${!isMe ? `<img src="<?= $selectedUser ? ($selectedUser['picture_url'] ?: 'https://via.placeholder.com/28') : '' ?>" class="w-7 h-7 rounded-full self-end mr-2">` : ''}
        <div class="flex flex-col ${isMe ? 'items-end' : 'items-start'}" style="max-width:70%">
            ${contentHtml}
            <div class="msg-meta ${isMe ? '' : 'incoming'}">
                <span>${time}</span>
                ${senderBadge}
                ${readStatus}
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
        
        // Debug: แสดง session info
        if (data._debug) {
            console.log('🔍 DEBUG Session:', data._debug);
        }
        
        if (data.success) {
            input.value = '';
            autoResize(input);
            
            const msgId = data.message_id || Date.now();
            sentMessageIds.add(msgId); // Track this message
            lastMessageId = Math.max(lastMessageId, msgId);
            
            // Show method used (reply = free, push = paid)
            if (data.method_label) {
                console.log('📤 Sent via:', data.method_label, '| Sent by:', data.sent_by);
            }
            
            // Append immediately
            appendMessage({
                id: msgId,
                direction: 'outgoing',
                message_type: 'text',
                content: data.content,
                sent_by: data.sent_by,
                is_read: 0,
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

// ===== Image Upload =====
let selectedImageFile = null;

function handleImageSelect(input) {
    const file = input.files[0];
    if (!file) return;
    
    // Validate
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        alert('รองรับเฉพาะไฟล์ JPG, PNG, GIF, WEBP');
        input.value = '';
        return;
    }
    
    if (file.size > 10 * 1024 * 1024) {
        alert('ไฟล์ใหญ่เกินไป (สูงสุด 10MB)');
        input.value = '';
        return;
    }
    
    selectedImageFile = file;
    
    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('previewName').textContent = file.name;
        document.getElementById('previewSize').textContent = formatFileSize(file.size);
        document.getElementById('imagePreview').classList.remove('hidden');
    };
    reader.readAsDataURL(file);
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function cancelImageUpload() {
    selectedImageFile = null;
    document.getElementById('imageInput').value = '';
    document.getElementById('imagePreview').classList.add('hidden');
}

async function sendImage() {
    if (!selectedImageFile || !userId) return;
    
    const preview = document.getElementById('imagePreview');
    const sendBtn = preview.querySelector('button:last-child');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังส่ง...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_image');
        formData.append('user_id', userId);
        formData.append('image', selectedImageFile);
        
        const res = await fetch('', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            const msgId = data.message_id || Date.now();
            sentMessageIds.add(msgId);
            lastMessageId = Math.max(lastMessageId, msgId);
            
            // Append image message
            appendMessage({
                id: msgId,
                direction: 'outgoing',
                message_type: 'image',
                content: data.image_url,
                sent_by: data.sent_by,
                is_read: 0,
                created_at: new Date().toISOString()
            });
            scrollToBottom();
            
            // Clear preview
            cancelImageUpload();
        } else {
            alert('Error: ' + (data.error || 'ส่งรูปไม่สำเร็จ'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
    
    sendBtn.disabled = false;
    sendBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>ส่งรูป';
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
async function saveNote(e) {
    if (e) e.preventDefault();
    const note = document.getElementById('noteInput').value.trim();
    if (!note) return;
    
    const formData = new FormData();
    formData.append('action', 'save_note');
    formData.append('user_id', userId);
    formData.append('note', note);
    
    const res = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
    const data = await res.json();
    
    if (data.success) {
        document.getElementById('noteInput').value = '';
        // Add note to list
        const notesList = document.getElementById('notesList');
        const emptyMsg = notesList.querySelector('.text-center');
        if (emptyMsg) emptyMsg.remove();
        
        const noteDiv = document.createElement('div');
        noteDiv.className = 'bg-yellow-50 border border-yellow-200 rounded-lg p-2 text-xs relative group';
        noteDiv.innerHTML = `
            <p class="text-gray-700">${escapeHtml(note)}</p>
            <p class="text-[9px] text-gray-400 mt-1">${new Date().toLocaleString('th-TH')}</p>
            <button onclick="deleteNote(${data.id}, this)" class="absolute top-1 right-1 text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100"><i class="fas fa-times text-[10px]"></i></button>
        `;
        notesList.insertBefore(noteDiv, notesList.firstChild);
    }
}

async function deleteNote(noteId, btn) {
    if (!confirm('ลบโน๊ตนี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_note');
    formData.append('note_id', noteId);
    
    const res = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
    const data = await res.json();
    
    if (data.success) {
        btn.closest('.bg-yellow-50').remove();
    }
}

// ===== Medical Info =====
function openMedicalModal() {
    document.getElementById('medicalModal').classList.remove('hidden');
}

function closeMedicalModal() {
    document.getElementById('medicalModal').classList.add('hidden');
}

async function saveMedical(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'save_medical');
    formData.append('user_id', userId);
    formData.append('medical_conditions', document.getElementById('inputMedicalConditions').value);
    formData.append('drug_allergies', document.getElementById('inputDrugAllergies').value);
    formData.append('current_medications', document.getElementById('inputCurrentMedications').value);
    
    const res = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
    const data = await res.json();
    
    if (data.success) {
        // Update display
        document.getElementById('medicalConditions').innerHTML = document.getElementById('inputMedicalConditions').value || '<span class="text-gray-400">ไม่ระบุ</span>';
        document.getElementById('drugAllergies').innerHTML = document.getElementById('inputDrugAllergies').value || '<span class="text-gray-400">ไม่ระบุ</span>';
        document.getElementById('currentMedications').innerHTML = document.getElementById('inputCurrentMedications').value || '<span class="text-gray-400">ไม่ระบุ</span>';
        closeMedicalModal();
        alert('บันทึกข้อมูลสุขภาพแล้ว');
    }
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
    
    // Check current visibility
    const computedStyle = window.getComputedStyle(panel);
    const isVisible = computedStyle.display !== 'none';
    
    if (isVisible) {
        // Hide panel
        panel.classList.add('hidden');
        panel.classList.remove('lg:flex');
        panel.style.display = 'none';
    } else {
        // Show panel
        panel.classList.remove('hidden');
        panel.classList.add('flex');
        panel.style.display = 'flex';
    }
}

// Toggle collapsible section in customer panel
function toggleSection(sectionName) {
    const section = document.querySelector(`.panel-section[data-section="${sectionName}"]`);
    if (section) {
        section.classList.toggle('collapsed');
        
        // Save state to localStorage
        const collapsedSections = JSON.parse(localStorage.getItem('inboxCollapsedSections') || '{}');
        collapsedSections[sectionName] = section.classList.contains('collapsed');
        localStorage.setItem('inboxCollapsedSections', JSON.stringify(collapsedSections));
    }
}

// Restore collapsed sections state on page load
function restoreCollapsedSections() {
    const collapsedSections = JSON.parse(localStorage.getItem('inboxCollapsedSections') || '{}');
    Object.keys(collapsedSections).forEach(sectionName => {
        if (collapsedSections[sectionName]) {
            const section = document.querySelector(`.panel-section[data-section="${sectionName}"]`);
            if (section) section.classList.add('collapsed');
        }
    });
}

// Call on page load
document.addEventListener('DOMContentLoaded', restoreCollapsedSections);

// Toggle notifications for this chat
function toggleNotifications() {
    const icon = document.getElementById('notifyIcon');
    const isEnabled = icon.classList.contains('fa-bell');
    
    if (isEnabled) {
        icon.classList.remove('fa-bell');
        icon.classList.add('fa-bell-slash');
        icon.parentElement.classList.add('text-gray-400');
        showToast('ปิดการแจ้งเตือนสำหรับแชทนี้', 'info');
    } else {
        icon.classList.remove('fa-bell-slash');
        icon.classList.add('fa-bell');
        icon.parentElement.classList.remove('text-gray-400');
        showToast('เปิดการแจ้งเตือนสำหรับแชทนี้', 'success');
    }
    
    // Save preference via API
    fetch('api/inbox.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle_notification&user_id=<?= $selectedUser ? $selectedUser['id'] : 0 ?>&enabled=${!isEnabled ? 1 : 0}`
    });
}

// Toggle mute for this chat
function toggleMute() {
    const icon = document.getElementById('muteIcon');
    const isMuted = icon.classList.contains('fa-volume-mute');
    
    if (isMuted) {
        icon.classList.remove('fa-volume-mute');
        icon.classList.add('fa-volume-up');
        icon.parentElement.classList.remove('text-red-500');
        showToast('เปิดเสียงแชทนี้', 'success');
    } else {
        icon.classList.remove('fa-volume-up');
        icon.classList.add('fa-volume-mute');
        icon.parentElement.classList.add('text-red-500');
        showToast('ปิดเสียงแชทนี้', 'info');
    }
    
    // Save preference via API
    fetch('api/inbox.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle_mute&user_id=<?= $selectedUser ? $selectedUser['id'] : 0 ?>&muted=${!isMuted ? 1 : 0}`
    });
}

// Block user
function blockUser() {
    if (!confirm('คุณต้องการบล็อกผู้ใช้นี้หรือไม่?\n\nผู้ใช้จะไม่สามารถส่งข้อความถึงคุณได้')) return;
    
    fetch('api/inbox.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=block_user&user_id=<?= $selectedUser ? $selectedUser['id'] : 0 ?>`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('บล็อกผู้ใช้สำเร็จ', 'success');
            setTimeout(() => location.href = 'inbox.php', 1000);
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', 'error');
        }
    });
}

// Show toast notification
function showToast(message, type = 'info') {
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        info: 'bg-blue-500',
        warning: 'bg-yellow-500'
    };
    
    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 ${colors[type]} text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-fade-in`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('animate-fade-out');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Mobile: Show chat list (hide chat area)
function showChatList() {
    const sidebar = document.getElementById('inboxSidebar');
    sidebar.classList.remove('hidden-mobile');
}

// Mobile: Hide chat list when user is selected
function hideChatListOnMobile() {
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('inboxSidebar');
        sidebar.classList.add('hidden-mobile');
    }
}

// Auto hide chat list on mobile when user is selected
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($selectedUser): ?>
    hideChatListOnMobile();
    <?php endif; ?>
    
    // Add click handler to user items for mobile
    document.querySelectorAll('.user-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                // Let the link work, but hide sidebar after navigation
                setTimeout(hideChatListOnMobile, 100);
            }
        });
    });
});

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
    if (!soundEnabled) return;
    try {
        // ใช้เสียงที่ดีกว่า
        const audio = document.getElementById('notificationSound');
        if (audio) {
            audio.currentTime = 0;
            audio.volume = 0.5;
            audio.play().catch(() => {
                // Fallback to simple beep
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = ctx.createOscillator();
                const gainNode = ctx.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(ctx.destination);
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                gainNode.gain.setValueAtTime(0.3, ctx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                oscillator.start(ctx.currentTime);
                oscillator.stop(ctx.currentTime + 0.3);
            });
        }
    } catch(e) {
        console.log('Sound error:', e);
    }
}

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
