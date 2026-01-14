<?php
/**
 * Unified Inbox - Real-time Chat System
 * รวมระบบแชททั้งหมดเป็นหนึ่งเดียว พร้อม Real-time Updates
 * 
 * Tabs:
 * - (default): Chat inbox
 * - templates: Quick Reply Templates
 * - analytics: Chat Analytics
 * 
 * Version: 2.1 - Fixed duplicate touchStartX
 */

// Prevent browser caching for development
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

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
require_once 'classes/InboxService.php';
require_once 'classes/AnalyticsService.php';
require_once 'classes/CustomerNoteService.php';
require_once 'classes/TemplateService.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$activityLogger = ActivityLogger::getInstance($db);

// Get current tab
$currentTab = $_GET['tab'] ?? 'inbox';
$validTabs = ['inbox', 'templates', 'analytics'];
if (!in_array($currentTab, $validTabs)) {
    $currentTab = 'inbox';
}

// Initialize services for conversation list
$inboxService = new InboxService($db, $currentBotId);
$analyticsService = new AnalyticsService($db, $currentBotId);
$templateService = new TemplateService($db, $currentBotId);

// Get all tags for filter dropdown
$allTagsForFilter = [];
try {
    $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name ASC");
    $stmt->execute([$currentBotId]);
    $allTagsForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Get all admins for assignment filter
$allAdmins = [];
try {
    $stmt = $db->prepare("SELECT id, username, display_name FROM admin_users ORDER BY username ASC");
    $stmt->execute();
    $allAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Get SLA threshold from settings (default 1 hour = 3600 seconds)
$slaThreshold = 3600;

// Get conversations exceeding SLA for warning indicators
$slaViolations = [];
try {
    $slaViolations = $analyticsService->getConversationsExceedingSLA($slaThreshold);
    $slaViolationUserIds = array_column($slaViolations, 'user_id');
} catch (Exception $e) {
    $slaViolationUserIds = [];
}

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
                $selectedMessage = $_POST['selected_message'] ?? $_POST['last_message'] ?? '';
                $tone = $_POST['tone'] ?? 'friendly'; // friendly, formal, casual, empathetic, professional
                $customInstruction = trim($_POST['custom_instruction'] ?? '');
                $context = $_POST['context'] ?? '';
                
                if (!$userId) throw new Exception("User ID required");
                if (empty($selectedMessage)) throw new Exception("กรุณาเลือกข้อความที่ต้องการให้ AI ช่วยคิดคำตอบ");
                
                $stmt = $db->prepare("SELECT line_user_id, line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("User not found");
                
                $adapter = new \Modules\AIChat\Adapters\GeminiChatAdapter($db, $user['line_account_id']);
                if (!$adapter->isEnabled()) throw new Exception("AI ยังไม่ได้เปิดใช้งาน");
                
                // Build tone instruction
                $toneInstructions = [
                    'friendly' => 'ตอบด้วยน้ำเสียงเป็นมิตร อบอุ่น ใช้ภาษาที่เข้าถึงง่าย',
                    'formal' => 'ตอบด้วยน้ำเสียงทางการ สุภาพ เป็นทางการ',
                    'casual' => 'ตอบด้วยน้ำเสียงสบายๆ เป็นกันเอง ใช้ภาษาพูดทั่วไป',
                    'empathetic' => 'ตอบด้วยความเข้าใจ เห็นอกเห็นใจ แสดงความห่วงใย',
                    'professional' => 'ตอบด้วยความเป็นมืออาชีพ ให้ข้อมูลที่ถูกต้องชัดเจน'
                ];
                $toneText = $toneInstructions[$tone] ?? $toneInstructions['friendly'];
                
                // Build enhanced prompt
                $enhancedPrompt = "ข้อความจากลูกค้า: \"{$selectedMessage}\"\n\n";
                $enhancedPrompt .= "คำแนะนำ: {$toneText}";
                
                if (!empty($customInstruction)) {
                    $enhancedPrompt .= "\nคำแนะนำเพิ่มเติม: {$customInstruction}";
                }
                
                if (!empty($context)) {
                    $contextData = json_decode($context, true);
                    if ($contextData && is_array($contextData)) {
                        $enhancedPrompt .= "\n\nบริบทการสนทนา:\n";
                        foreach (array_slice($contextData, -5) as $msg) {
                            $role = $msg['role'] === 'customer' ? 'ลูกค้า' : 'เรา';
                            $enhancedPrompt .= "- {$role}: {$msg['text']}\n";
                        }
                    }
                }
                
                $response = $adapter->generateResponse($enhancedPrompt, $userId);
                echo json_encode([
                    'success' => true, 
                    'message' => $response,
                    'tone' => $tone,
                    'selected_message' => $selectedMessage
                ], JSON_UNESCAPED_UNICODE);
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
$hideAiChatWidget = true; // ซ่อน AI Chat Widget ในหน้า Inbox
require_once 'includes/header.php';

// Get Users List - only users with messages, sorted by last message time
$sql = "SELECT u.*, 
        m_last.content as last_msg,
        m_last.message_type as last_type,
        m_last.created_at as last_time,
        (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread
        FROM users u 
        INNER JOIN (
            SELECT user_id, MAX(id) as max_id
            FROM messages
            GROUP BY user_id
        ) m_max ON u.id = m_max.user_id
        INNER JOIN messages m_last ON m_max.max_id = m_last.id
        WHERE u.line_account_id = ? 
        ORDER BY m_last.created_at DESC";
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
.chat-bubble { white-space: pre-wrap; word-wrap: break-word; line-height: 1.6; max-width: 100%; }
.chat-incoming { background: #F1F5F9; color: #1E293B; border-radius: 4px 12px 12px 12px; }
.chat-outgoing { background: #10B981; color: white; border-radius: 12px 4px 12px 12px; }
.user-item.active { background: linear-gradient(90deg, #D1FAE5 0%, #ECFDF5 100%); border-left: 3px solid var(--primary); }
.user-item:hover { background: #F0FDF4; }
.user-item.sla-warning { border-left: 3px solid #F97316; background: linear-gradient(90deg, #FFF7ED 0%, #FFFFFF 100%); }
.user-item.sla-warning:hover { background: linear-gradient(90deg, #FFEDD5 0%, #FFF7ED 100%); }
.sla-badge { animation: pulse-warning 2s infinite; }
@keyframes pulse-warning { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
.assignment-badge { white-space: nowrap; }
.time-since { white-space: nowrap; }
.tag-badge { font-size: 0.6rem; padding: 2px 6px; border-radius: 9999px; font-weight: 500; }
#chatBox { background: #FFFFFF; }
/* Flex Message Preview */
.flex-preview { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; max-width: 280px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.flex-preview-header { background: linear-gradient(135deg, #10B981, #059669); color: white; padding: 12px; }
.flex-preview-body { padding: 12px; font-size: 13px; }
.flex-preview-footer { padding: 8px 12px; border-top: 1px solid #E5E7EB; background: #F9FAFB; }
.flex-preview-btn { display: block; text-align: center; padding: 8px 12px; background: #10B981; color: white; border-radius: 6px; font-size: 12px; text-decoration: none; margin-top: 8px; }
.flex-preview-item { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #F3F4F6; }
.flex-preview-item:last-child { border-bottom: none; }
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

/* AI Panel - Draggable & Resizable Floating Card */
#aiPanel {
    position: fixed;
    bottom: 80px;
    right: 20px;
    width: 320px;
    min-width: 280px;
    max-width: 500px;
    z-index: 100;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    overflow: hidden;
    animation: popIn 0.25s ease-out;
    resize: both;
    min-height: 200px;
    max-height: 80vh;
}
#aiPanel.dragging {
    opacity: 0.9;
    cursor: grabbing;
}
#aiPanel.minimized {
    width: 200px !important;
    height: auto !important;
    min-height: auto;
}
#aiPanel.minimized #aiConfigSection,
#aiPanel.minimized #aiResultSection {
    display: none;
}
@keyframes popIn {
    from { opacity: 0; transform: scale(0.9) translateY(20px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
#aiPanel.closing {
    animation: popOut 0.2s ease-in forwards;
}
@keyframes popOut {
    from { opacity: 1; transform: scale(1); }
    to { opacity: 0; transform: scale(0.9) translateY(20px); }
}
#aiConfigSection {
    padding: 12px !important;
    max-height: calc(100% - 40px);
    overflow-y: auto;
}
#aiResultSection {
    padding: 12px !important;
    max-height: calc(100% - 40px);
    overflow-y: auto;
}
#aiSuggestion {
    min-height: 80px;
    resize: vertical;
    font-size: 13px;
}
.tone-btn {
    transition: all 0.15s ease;
    padding: 6px 8px !important;
    font-size: 10px !important;
}
.tone-btn:hover {
    transform: scale(1.02);
}
.tone-btn.active {
    box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3);
}
#aiSelectedMessage {
    transition: all 0.2s ease;
    max-height: 50px;
    overflow-y: auto;
    font-size: 12px;
}
#aiSelectedMessage:hover {
    border-color: #818CF8;
}
.message-picker-item {
    transition: all 0.15s ease;
}
.message-picker-item:hover {
    background: #EEF2FF;
}
/* AI Panel Header - Draggable */
.ai-panel-header {
    padding: 8px 10px;
    background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: grab;
    user-select: none;
}
.ai-panel-header:active {
    cursor: grabbing;
}
.ai-panel-header h3 {
    font-size: 12px;
    font-weight: 600;
    margin: 0;
    flex: 1;
}
.ai-panel-controls {
    display: flex;
    gap: 4px;
}
.ai-panel-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 22px;
    height: 22px;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    font-size: 10px;
}
.ai-panel-btn:hover {
    background: rgba(255,255,255,0.3);
}
/* Backdrop for mobile */
@media (max-width: 768px) {
    #aiPanel {
        width: 100%;
        max-width: 100%;
        border-radius: 0;
    }
}

/* Quick Reply Modal Styles - Requirements: 2.1, 2.2 */
#quickReplyModal {
    animation: slideUp 0.2s ease-out;
}
@keyframes slideUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.quick-reply-item {
    padding: 10px 12px;
    border-bottom: 1px solid #F3F4F6;
    cursor: pointer;
    transition: background 0.15s;
}
.quick-reply-item:hover, .quick-reply-item.selected {
    background: #F0FDF4;
}
.quick-reply-item.selected {
    border-left: 3px solid #10B981;
}
.quick-reply-item:last-child {
    border-bottom: none;
}
.quick-reply-name {
    font-weight: 600;
    font-size: 13px;
    color: #1F2937;
}
.quick-reply-preview {
    font-size: 12px;
    color: #6B7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}
.quick-reply-meta {
    display: flex;
    gap: 8px;
    margin-top: 4px;
    font-size: 10px;
    color: #9CA3AF;
}
.quick-reply-category {
    background: #E5E7EB;
    padding: 1px 6px;
    border-radius: 4px;
}

/* Image Error Placeholder - Requirements: 7.2 */
.chat-image.image-error {
    background: #F3F4F6;
    border: 2px dashed #D1D5DB;
    min-width: 150px;
    min-height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.image-error-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #9CA3AF;
    text-align: center;
}
.image-error-placeholder i {
    font-size: 24px;
    margin-bottom: 8px;
}
.image-error-placeholder span {
    font-size: 11px;
}

/* Mobile Image Optimization - Requirements: 9.4 */
.chat-image {
    /* Use object-fit for better image display */
    object-fit: cover;
    /* Add loading placeholder background */
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: image-loading 1.5s infinite;
}
.chat-image.loaded {
    animation: none;
    background: transparent;
}
@keyframes image-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Thumbnail container for mobile */
.chat-image-container {
    position: relative;
    display: inline-block;
}
.chat-image-container .image-loading-spinner {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #9CA3AF;
    display: none;
}
.chat-image-container.loading .image-loading-spinner {
    display: block;
}

/* Progressive image loading */
.chat-image.blur-load {
    filter: blur(10px);
    transition: filter 0.3s ease-out;
}
.chat-image.blur-load.loaded {
    filter: blur(0);
}

/* Load More Button */
#loadMoreContainer {
    position: sticky;
    top: 0;
    z-index: 10;
    background: linear-gradient(to bottom, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0) 100%);
    padding-bottom: 20px;
}

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

/* Inbox Full Screen - Hide main sidebar */
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

/* Mobile Responsive - Requirements: 9.1, 9.2, 9.4 */

/* Mobile: Single-column layout - Requirements: 9.1 */
@media (max-width: 768px) {
    /* Hide desktop three-column layout, show single column */
    #inboxContainer {
        flex-direction: column !important;
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
        transition: transform 0.3s ease-out;
        background: white;
        border-right: none !important;
    }
    #inboxSidebar.hidden-mobile {
        transform: translateX(-100%);
        pointer-events: none;
    }
    
    /* Chat area - full screen when visible - Requirements: 9.2 */
    #chatArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        bottom: 0 !important;
        right: 0 !important;
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        z-index: 50;
    }
    
    /* Mobile back button - always visible on mobile */
    #mobileBackBtn {
        display: flex !important;
    }
    
    /* Customer panel - full screen overlay with slide animation */
    #customerPanel {
        position: fixed !important;
        top: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100% !important;
        z-index: 200 !important;
        transform: translateX(100%);
        transition: transform 0.3s ease-out;
        background: white;
    }
    #customerPanel.mobile-visible {
        transform: translateX(0);
    }
    #customerPanel.hidden {
        transform: translateX(100%);
    }
    
    /* Mobile close button for customer panel */
    #mobilePanelClose {
        display: flex !important;
    }
    
    /* Optimize chat bubbles for mobile */
    .chat-bubble {
        max-width: 85% !important;
        font-size: 14px !important;
    }
    
    /* Optimize images for mobile - Requirements: 9.4 */
    .chat-image {
        max-width: 180px !important;
        max-height: 200px !important;
    }
    
    /* Mobile-optimized message input */
    #messageInput {
        font-size: 16px !important; /* Prevent iOS zoom */
    }
    
    /* Mobile header adjustments */
    #chatArea > div:first-child {
        padding: 8px 12px !important;
    }
    
    /* Quick reply modal - full width on mobile */
    #quickReplyModal {
        left: 8px !important;
        right: 8px !important;
        bottom: 70px !important;
        max-height: 60vh !important;
    }
    
    /* Filter dropdowns - stack vertically on small screens */
    #inboxSidebar .p-2.border-b.bg-gray-50 .flex.gap-2 {
        flex-wrap: wrap;
    }
    
    /* User item - more touch-friendly */
    .user-item {
        padding: 12px !important;
        min-height: 70px;
    }
    
    /* Hide some elements on mobile for cleaner UI */
    .time-since {
        display: none !important;
    }
    
    /* Swipe indicator for mobile */
    .mobile-swipe-indicator {
        display: block !important;
    }
}

/* Small mobile screens (< 480px) */
@media (max-width: 480px) {
    /* Even more compact layout */
    .user-item {
        padding: 10px !important;
    }
    
    .user-item img {
        width: 36px !important;
        height: 36px !important;
    }
    
    .chat-bubble {
        max-width: 90% !important;
        padding: 8px 12px !important;
    }
    
    /* Smaller images on very small screens */
    .chat-image {
        max-width: 150px !important;
        max-height: 150px !important;
    }
    
    /* Compact header */
    #chatArea > div:first-child {
        height: 50px !important;
    }
    
    #chatArea > div:first-child img {
        width: 32px !important;
        height: 32px !important;
    }
}

/* Desktop styles */
@media (min-width: 769px) {
    #mobileBackBtn {
        display: none !important;
    }
    
    #mobilePanelClose {
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
    
    .mobile-swipe-indicator {
        display: none !important;
    }
}

/* Touch-friendly improvements for all mobile */
@media (hover: none) and (pointer: coarse) {
    /* Larger touch targets */
    button, .user-item, .quick-reply-item {
        min-height: 44px;
    }
    
    /* Remove hover effects that don't work on touch */
    .user-item:hover {
        background: inherit;
    }
    
    .user-item.active {
        background: linear-gradient(90deg, #D1FAE5 0%, #ECFDF5 100%);
    }
}
</style>

<?php if ($currentTab === 'inbox'): ?>
<!-- INBOX TAB -->
<div id="inboxContainer" class="h-screen flex bg-white overflow-hidden relative">
    
    <!-- LEFT: User List -->
    <div id="inboxSidebar" class="w-72 bg-white border-r flex flex-col">
        <div class="p-3 border-b bg-gradient-to-r from-emerald-500 to-green-600 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <!-- Back to dashboard -->
                <a href="dashboard.php" id="backToMenuBtn" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/20 hover:bg-white/30 text-white" title="กลับหน้าหลัก">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h2 class="text-white font-bold flex items-center">
                    <i class="fas fa-inbox mr-2"></i>Inbox
                    <span id="totalUnread" class="ml-2 text-xs bg-white/20 px-2 py-0.5 rounded-full"><?= count($users) ?></span>
                </h2>
            </div>
            <div class="flex items-center gap-2">
                <!-- Polling Settings Button - Requirements: 1.1 -->
                <button id="pollingSettingsBtn" class="sound-toggle text-white" onclick="openPollingSettings()" title="ตั้งค่าการอัพเดท">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <!-- Desktop Notification Toggle - Requirements: 1.2 -->
                <button id="desktopNotifyToggle" class="sound-toggle text-white" onclick="toggleDesktopNotifications()" title="เปิด/ปิดการแจ้งเตือน Desktop">
                    <i class="fas fa-bell" id="desktopNotifyIcon"></i>
                </button>
                <button id="soundToggle" class="sound-toggle text-white" onclick="toggleSound()" title="เปิด/ปิดเสียง">
                    <i class="fas fa-volume-up" id="soundIcon"></i>
                </button>
                <span id="liveIndicator" class="w-2 h-2 bg-green-300 rounded-full pulse-dot" title="Real-time Active"></span>
            </div>
        </div>
        
        <!-- Search Input with Debounce - Requirements: 5.1, 11.7 -->
        <div class="p-2 border-b">
            <div class="relative">
                <input type="text" id="userSearch" placeholder="🔍 ค้นหาชื่อ, ข้อความ, แท็ก..." 
                       class="w-full px-3 py-2 bg-gray-100 rounded-lg text-sm focus:ring-2 focus:ring-emerald-500 outline-none pr-8" 
                       oninput="debouncedSearch(this.value)">
                <span id="searchSpinner" class="hidden absolute right-2 top-1/2 -translate-y-1/2">
                    <i class="fas fa-spinner fa-spin text-gray-400 text-sm"></i>
                </span>
            </div>
        </div>
        
        <!-- Filter Dropdowns - Requirements: 5.2, 5.3, 5.4 -->
        <div class="p-2 border-b bg-gray-50 space-y-2">
            <div class="flex gap-2">
                <!-- Status Filter -->
                <select id="filterStatus" onchange="applyFilters()" class="flex-1 px-2 py-1.5 bg-white border rounded-lg text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                    <option value="">ทุกสถานะ</option>
                    <option value="unread">ยังไม่อ่าน</option>
                    <option value="assigned">มอบหมายแล้ว</option>
                    <option value="resolved">แก้ไขแล้ว</option>
                </select>
                
                <!-- Tag Filter -->
                <select id="filterTag" onchange="applyFilters()" class="flex-1 px-2 py-1.5 bg-white border rounded-lg text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                    <option value="">ทุกแท็ก</option>
                    <?php foreach ($allTagsForFilter as $tag): ?>
                    <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex gap-2">
                <!-- Assignment Filter -->
                <select id="filterAssigned" onchange="applyFilters()" class="flex-1 px-2 py-1.5 bg-white border rounded-lg text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                    <option value="">ทุกคน</option>
                    <option value="me">มอบหมายให้ฉัน</option>
                    <?php foreach ($allAdmins as $admin): ?>
                    <option value="<?= $admin['id'] ?>"><?= htmlspecialchars($admin['username'] ?: $admin['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <!-- Date Range Filter -->
                <button onclick="toggleDateFilter()" class="px-2 py-1.5 bg-white border rounded-lg text-xs hover:bg-gray-50 flex items-center gap-1" id="dateFilterBtn">
                    <i class="fas fa-calendar-alt text-gray-500"></i>
                    <span id="dateFilterLabel">วันที่</span>
                </button>
            </div>
            
            <!-- Date Range Picker (Hidden by default) -->
            <div id="dateRangePicker" class="hidden bg-white border rounded-lg p-2 space-y-2">
                <div class="flex gap-2">
                    <input type="date" id="filterDateFrom" onchange="applyFilters()" class="flex-1 px-2 py-1 border rounded text-xs" placeholder="จาก">
                    <input type="date" id="filterDateTo" onchange="applyFilters()" class="flex-1 px-2 py-1 border rounded text-xs" placeholder="ถึง">
                </div>
                <button onclick="clearDateFilter()" class="w-full text-xs text-red-500 hover:text-red-700">ล้างวันที่</button>
            </div>
            
            <!-- Active Filters Display -->
            <div id="activeFilters" class="hidden flex flex-wrap gap-1">
                <!-- Active filter badges will be inserted here -->
            </div>
        </div>
        
        <!-- Conversation List with Virtual Scrolling - Requirements: 11.2 -->
        <div id="userList" class="flex-1 overflow-y-auto chat-scroll">
            <?php if (empty($users)): ?>
                <div class="p-6 text-center text-gray-400">
                    <i class="fas fa-inbox text-4xl mb-2"></i>
                    <p class="text-sm">ยังไม่มีแชท</p>
                </div>
            <?php else: ?>
                <?php foreach ($users as $index => $user): 
                    // Get assignment info
                    $assignment = null;
                    try {
                        $assignStmt = $db->prepare("SELECT ca.*, au.username as assigned_admin_name FROM conversation_assignments ca LEFT JOIN admin_users au ON ca.assigned_to = au.id WHERE ca.user_id = ?");
                        $assignStmt->execute([$user['id']]);
                        $assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {}
                    
                    // Check if this user has SLA violation
                    $hasSlaWarning = in_array($user['id'], $slaViolationUserIds);
                    
                    // Calculate time since last message
                    $timeSinceLastMsg = '';
                    if ($user['last_time']) {
                        $lastMsgTime = strtotime($user['last_time']);
                        $secondsAgo = time() - $lastMsgTime;
                        if ($secondsAgo < 60) {
                            $timeSinceLastMsg = $secondsAgo . ' วินาที';
                        } elseif ($secondsAgo < 3600) {
                            $timeSinceLastMsg = floor($secondsAgo / 60) . ' นาที';
                        } elseif ($secondsAgo < 86400) {
                            $timeSinceLastMsg = floor($secondsAgo / 3600) . ' ชม.';
                        } else {
                            $timeSinceLastMsg = floor($secondsAgo / 86400) . ' วัน';
                        }
                    }
                ?>
                <a href="?user=<?= $user['id'] ?>" 
                   class="user-item block p-3 border-b border-gray-50 <?= ($selectedUser && $selectedUser['id'] == $user['id']) ? 'active' : '' ?> <?= $hasSlaWarning ? 'sla-warning' : '' ?>" 
                   data-user-id="<?= $user['id'] ?>"
                   data-name="<?= strtolower($user['display_name']) ?>"
                   data-index="<?= $index ?>">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-shrink-0">
                            <img src="<?= $user['picture_url'] ?: 'https://via.placeholder.com/40' ?>" 
                                 class="w-10 h-10 rounded-full object-cover border-2 border-white shadow"
                                 loading="lazy">
                            <?php if ($user['unread'] > 0): ?>
                            <div class="unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold">
                                <?= $user['unread'] > 9 ? '9+' : $user['unread'] ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasSlaWarning): ?>
                            <div class="sla-badge absolute -bottom-1 -right-1 bg-orange-500 text-white text-[8px] w-4 h-4 flex items-center justify-center rounded-full" title="เกิน SLA">
                                <i class="fas fa-exclamation"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline">
                                <h3 class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($user['display_name']) ?></h3>
                                <span class="last-time text-[10px] text-gray-400"><?= formatThaiTime($user['last_time']) ?></span>
                            </div>
                            <p class="last-msg text-xs text-gray-500 truncate"><?= htmlspecialchars(getMessagePreview($user['last_msg'], $user['last_type'])) ?></p>
                            
                            <!-- Assignment Indicator & Time Since Last Message - Requirements: 3.2, 6.5 -->
                            <div class="flex items-center gap-2 mt-1">
                                <?php if ($assignment && $assignment['status'] === 'active'): ?>
                                <span class="assignment-badge text-[9px] px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded-full flex items-center gap-1">
                                    <i class="fas fa-user-check"></i>
                                    <?= htmlspecialchars($assignment['assigned_admin_name'] ?: 'Admin') ?>
                                </span>
                                <?php endif; ?>
                                
                                <?php if ($timeSinceLastMsg): ?>
                                <span class="time-since text-[9px] text-gray-400 flex items-center gap-1" title="เวลาตั้งแต่ข้อความล่าสุด">
                                    <i class="fas fa-clock"></i>
                                    <?= $timeSinceLastMsg ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                
                <!-- Virtual Scroll Sentinel -->
                <div id="scrollSentinel" class="h-10 flex items-center justify-center">
                    <span id="loadingMore" class="hidden text-xs text-gray-400">
                        <i class="fas fa-spinner fa-spin mr-1"></i>กำลังโหลด...
                    </span>
                </div>
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
            <!-- Load More Button - Requirements: 11.3 -->
            <div id="loadMoreContainer" class="text-center py-2 hidden">
                <button id="loadMoreBtn" onclick="loadMoreMessages()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-sm transition-all">
                    <i class="fas fa-arrow-up mr-1"></i>โหลดข้อความเก่า
                </button>
                <span id="loadMoreSpinner" class="hidden">
                    <i class="fas fa-spinner fa-spin text-gray-400"></i>
                </span>
            </div>
            
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
                        // ถ้าเป็น URL ให้ใช้ตรงๆ ถ้าเป็น LINE message ID ให้ใช้ line_content.php
                        if (preg_match('/ID:\s*(\d+)/', $content, $m)) {
                            $imgSrc = 'api/line_content.php?id=' . $m[1];
                        } elseif (!preg_match('/^https?:\/\//', $content)) {
                            // ถ้าไม่ใช่ URL และไม่ใช่ ID format ให้ลองใช้เป็น message ID
                            $imgSrc = 'api/line_content.php?id=' . $content;
                        }
                        ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>" 
                             class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90 chat-image" 
                             onclick="openImage(this.src)" 
                             onerror="handleImageError(this)"
                             data-original-src="<?= htmlspecialchars($imgSrc) ?>"
                             loading="lazy">
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
                    <?php elseif ($type === 'flex'): ?>
                        <?php 
                        $flexData = json_decode($content, true);
                        $flexTitle = '';
                        $flexBody = '';
                        $flexItems = [];
                        $flexBtn = '';
                        
                        // Parse flex message content
                        if ($flexData) {
                            // Try to extract title from header
                            if (isset($flexData['contents']['header']['contents'][0]['text'])) {
                                $flexTitle = $flexData['contents']['header']['contents'][0]['text'];
                            } elseif (isset($flexData['altText'])) {
                                $flexTitle = $flexData['altText'];
                            }
                            
                            // Try to extract body content
                            if (isset($flexData['contents']['body']['contents'])) {
                                foreach ($flexData['contents']['body']['contents'] as $item) {
                                    if (isset($item['text'])) {
                                        $flexBody .= $item['text'] . "\n";
                                    } elseif (isset($item['contents'])) {
                                        // Box with multiple items (like price list)
                                        $label = '';
                                        $value = '';
                                        foreach ($item['contents'] as $subItem) {
                                            if (isset($subItem['text'])) {
                                                if (empty($label)) $label = $subItem['text'];
                                                else $value = $subItem['text'];
                                            }
                                        }
                                        if ($label) $flexItems[] = ['label' => $label, 'value' => $value];
                                    }
                                }
                            }
                            
                            // Try to extract button
                            if (isset($flexData['contents']['footer']['contents'][0]['action']['label'])) {
                                $flexBtn = $flexData['contents']['footer']['contents'][0]['action']['label'];
                            }
                        }
                        ?>
                        <div class="flex-preview">
                            <?php if ($flexTitle): ?>
                            <div class="flex-preview-header">
                                <div class="font-bold text-sm"><?= htmlspecialchars($flexTitle) ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="flex-preview-body">
                                <?php if ($flexBody): ?>
                                <p class="text-gray-700 text-sm mb-2"><?= nl2br(htmlspecialchars(trim($flexBody))) ?></p>
                                <?php endif; ?>
                                <?php foreach ($flexItems as $item): ?>
                                <div class="flex-preview-item">
                                    <span class="text-gray-600"><?= htmlspecialchars($item['label']) ?></span>
                                    <span class="font-medium"><?= htmlspecialchars($item['value']) ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($flexBody) && empty($flexItems)): ?>
                                <p class="text-gray-500 text-xs">📋 Flex Message</p>
                                <?php endif; ?>
                            </div>
                            <?php if ($flexBtn): ?>
                            <div class="flex-preview-footer">
                                <span class="flex-preview-btn"><?= htmlspecialchars($flexBtn) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
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

        <!-- AI Suggestion Panel - Draggable & Resizable -->
        <div id="aiPanel" class="hidden bg-white">
            <!-- Panel Header - Drag Handle -->
            <div class="ai-panel-header" id="aiPanelHeader">
                <h3><i class="fas fa-robot mr-1"></i>AI ช่วยคิดคำตอบ</h3>
                <div class="ai-panel-controls">
                    <button onclick="toggleAIPanelSize()" class="ai-panel-btn" title="ย่อ/ขยาย"><i class="fas fa-compress-alt" id="aiSizeIcon"></i></button>
                    <button onclick="closeAIPanel()" class="ai-panel-btn" title="ปิด"><i class="fas fa-times"></i></button>
                </div>
            </div>
            
            <!-- AI Configuration Section -->
            <div id="aiConfigSection" class="p-3 bg-gray-50">
                <!-- Selected Message Display -->
                <div class="mb-3">
                    <label class="text-[10px] font-medium text-gray-500 mb-1 block">ข้อความที่เลือก:</label>
                    <div id="aiSelectedMessage" class="bg-white rounded-lg p-2 border border-dashed border-gray-300 text-xs text-gray-700 min-h-[36px] cursor-pointer hover:border-indigo-400 transition-all" onclick="openMessagePicker()">
                        <span class="text-gray-400">คลิกเพื่อเลือก...</span>
                    </div>
                </div>
                
                <!-- Tone Selection - Compact -->
                <div class="mb-3">
                    <label class="text-[10px] font-medium text-gray-500 mb-1 block">โทนเสียง:</label>
                    <div class="flex flex-wrap gap-1" id="toneSelector">
                        <button type="button" data-tone="friendly" class="tone-btn active px-2 py-1 rounded text-[10px] font-medium bg-indigo-600 text-white">😊 เป็นมิตร</button>
                        <button type="button" data-tone="formal" class="tone-btn px-2 py-1 rounded text-[10px] font-medium bg-white border text-gray-600">👔 ทางการ</button>
                        <button type="button" data-tone="casual" class="tone-btn px-2 py-1 rounded text-[10px] font-medium bg-white border text-gray-600">☕ สบายๆ</button>
                        <button type="button" data-tone="empathetic" class="tone-btn px-2 py-1 rounded text-[10px] font-medium bg-white border text-gray-600">❤️ เข้าใจ</button>
                        <button type="button" data-tone="professional" class="tone-btn px-2 py-1 rounded text-[10px] font-medium bg-white border text-gray-600">💼 มืออาชีพ</button>
                    </div>
                </div>
                
                <!-- Custom Instruction -->
                <div class="mb-3">
                    <input type="text" id="aiCustomInstruction" placeholder="คำแนะนำเพิ่มเติม (ไม่บังคับ)..." 
                           class="w-full px-2 py-1.5 bg-white rounded border text-xs focus:ring-1 focus:ring-indigo-500 outline-none">
                </div>
                
                <!-- Generate Button -->
                <button onclick="generateAIReplyEnhanced()" id="aiGenerateBtn" class="w-full px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold">
                    <i class="fas fa-magic mr-1"></i>สร้างคำตอบ
                </button>
            </div>
            
            <!-- AI Result Section -->
            <div id="aiResultSection" class="hidden p-3 bg-white">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-xs font-semibold text-gray-700">💡 AI แนะนำ:</span>
                    <button onclick="regenerateAIReply()" class="ml-auto text-[10px] text-indigo-600 hover:text-indigo-700">
                        <i class="fas fa-redo"></i> ใหม่
                    </button>
                </div>
                <textarea id="aiSuggestion" class="w-full text-xs text-gray-700 bg-gray-50 rounded p-2 border focus:ring-1 focus:ring-indigo-500 outline-none resize-none"></textarea>
                <div class="flex gap-2 mt-2">
                    <button onclick="useAISuggestion()" class="flex-1 px-3 py-1.5 bg-green-500 hover:bg-green-600 text-white rounded text-xs font-semibold">
                        <i class="fas fa-check mr-1"></i>ใช้
                    </button>
                    <button onclick="regenerateAIReply()" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs">
                        <i class="fas fa-redo"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Message Picker Modal -->
        <div id="messagePickerModal" class="hidden fixed inset-0 bg-black/50 z-[110] flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm max-h-[60vh] flex flex-col">
                <div class="p-2 border-b flex items-center justify-between bg-indigo-600 rounded-t-xl">
                    <span class="text-white text-sm font-medium"><i class="fas fa-comments mr-1"></i>เลือกข้อความ</span>
                    <button onclick="closeMessagePicker()" class="text-white/80 hover:text-white p-1">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="messagePickerList" class="flex-1 overflow-y-auto chat-scroll p-2">
                    <!-- Messages will be populated here -->
                </div>
            </div>
        </div>

        <!-- Quick Reply Template Selector Modal - Requirements: 2.1, 2.2 -->
        <div id="quickReplyModal" class="hidden absolute bottom-20 left-4 right-4 bg-white rounded-xl shadow-2xl border max-h-80 flex flex-col z-50">
            <div class="p-3 border-b flex items-center justify-between bg-gray-50 rounded-t-xl">
                <div class="flex items-center gap-2">
                    <i class="fas fa-bolt text-emerald-500"></i>
                    <span class="font-medium text-gray-700">Quick Reply Templates</span>
                </div>
                <button onclick="closeQuickReplyModal()" class="text-gray-400 hover:text-gray-600 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-2 border-b">
                <input type="text" id="quickReplySearch" placeholder="🔍 ค้นหา template..." 
                       class="w-full px-3 py-2 bg-gray-100 rounded-lg text-sm focus:ring-2 focus:ring-emerald-500 outline-none"
                       oninput="filterQuickReplies(this.value)"
                       onkeydown="handleQuickReplyKeydown(event)">
            </div>
            <div id="quickReplyList" class="flex-1 overflow-y-auto chat-scroll">
                <div class="p-4 text-center text-gray-400 text-sm">
                    <i class="fas fa-spinner fa-spin mr-1"></i>กำลังโหลด...
                </div>
            </div>
            <div class="p-2 border-t bg-gray-50 rounded-b-xl">
                <button onclick="openTemplateManager()" class="w-full text-xs text-emerald-600 hover:text-emerald-700 py-1">
                    <i class="fas fa-cog mr-1"></i>จัดการ Templates
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
                
                <div class="flex-1 bg-gray-100 rounded-2xl px-4 py-2 focus-within:ring-2 focus-within:ring-emerald-500 relative">
                    <textarea name="message" id="messageInput" rows="1" 
                              class="w-full bg-transparent border-0 outline-none text-sm resize-none max-h-24" 
                              placeholder="พิมพ์ข้อความ... (กด / เพื่อเปิด Quick Reply)" 
                              oninput="autoResize(this); handleMessageInput(this)"
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
    // Initialize CustomerNoteService for proper note management - Requirements: 4.4, 4.5
    $customerNoteService = new CustomerNoteService($db);
    
    // Get user notes using CustomerNoteService - Requirements: 4.4, 4.5
    $userNotes = [];
    try {
        $userNotes = $customerNoteService->getNotes($selectedUser['id']);
    } catch (Exception $e) {
        // Fallback to old table if customer_notes doesn't exist
        try {
            $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$selectedUser['id']]);
            $userNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {}
    }
    
    // Get user orders with more details - Requirements: 4.2
    $userOrders = [];
    try {
        $stmt = $db->prepare("SELECT t.*, 
            (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count
            FROM transactions t 
            WHERE t.user_id = ? 
            ORDER BY t.created_at DESC LIMIT 5");
        $stmt->execute([$selectedUser['id']]);
        $userOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    
    // Get conversation assignment - Requirements: 3.1
    $currentAssignment = $inboxService->getAssignment($selectedUser['id']);
    
    // Get average response time for this customer - Requirements: 6.1
    $customerAvgResponseTime = 0;
    try {
        $stmt = $db->prepare("
            SELECT AVG(ma.response_time_seconds) as avg_time
            FROM message_analytics ma
            WHERE ma.user_id = ?
            AND ma.response_time_seconds IS NOT NULL
            AND ma.response_time_seconds > 0
        ");
        $stmt->execute([$selectedUser['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $customerAvgResponseTime = (float)($result['avg_time'] ?? 0);
    } catch (PDOException $e) {}
    
    // Get time since last message - Requirements: 6.5
    $timeSinceLastMsg = $analyticsService->getTimeSinceLastMessage($selectedUser['id']);
    
    // Get current admin ID
    $currentAdminId = $_SESSION['admin_id'] ?? $_SESSION['admin_user']['id'] ?? null;
    ?>
    <div id="customerPanel" class="w-72 bg-white border-l flex-col transition-all duration-300 overflow-hidden hidden lg:flex">
        <div class="p-3 border-b bg-gray-50 flex items-center justify-between flex-shrink-0">
            <!-- Mobile: Back button -->
            <button id="mobilePanelClose" onclick="closePanelMobile()" class="hidden w-8 h-8 items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 mr-2">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h3 class="text-sm font-bold text-gray-700 flex-1"><i class="fas fa-user text-emerald-500 mr-2"></i>รายละเอียดลูกค้า</h3>
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
                    <!-- Average Response Time - Requirements: 6.1 -->
                    <div class="flex justify-between">
                        <span class="text-gray-500">เวลาตอบเฉลี่ย</span>
                        <span class="font-medium <?= $customerAvgResponseTime > $slaThreshold ? 'text-orange-500' : 'text-emerald-600' ?>">
                            <?php 
                            if ($customerAvgResponseTime > 0) {
                                if ($customerAvgResponseTime < 60) {
                                    echo round($customerAvgResponseTime) . ' วินาที';
                                } elseif ($customerAvgResponseTime < 3600) {
                                    echo round($customerAvgResponseTime / 60) . ' นาที';
                                } else {
                                    echo round($customerAvgResponseTime / 3600, 1) . ' ชม.';
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </span>
                    </div>
                    <!-- Time Since Last Message -->
                    <?php if ($timeSinceLastMsg >= 0): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-500">ข้อความล่าสุด</span>
                        <span class="font-medium <?= $timeSinceLastMsg > $slaThreshold ? 'text-orange-500' : 'text-gray-700' ?>">
                            <?php 
                            if ($timeSinceLastMsg < 60) {
                                echo $timeSinceLastMsg . ' วินาทีที่แล้ว';
                            } elseif ($timeSinceLastMsg < 3600) {
                                echo round($timeSinceLastMsg / 60) . ' นาทีที่แล้ว';
                            } elseif ($timeSinceLastMsg < 86400) {
                                echo round($timeSinceLastMsg / 3600) . ' ชม.ที่แล้ว';
                            } else {
                                echo round($timeSinceLastMsg / 86400) . ' วันที่แล้ว';
                            }
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Conversation Assignment Section - Requirements: 3.1 -->
            <div class="panel-section border-t" data-section="assignment">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('assignment')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-user-tag text-purple-500 mr-1"></i>มอบหมายงาน</h5>
                    <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                </div>
                <div class="section-content space-y-2 text-xs">
                    <div class="flex items-center gap-2">
                        <select id="assignmentSelect" onchange="assignConversation(this.value)" 
                                class="flex-1 border rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-purple-500 outline-none">
                            <option value="">-- ไม่ได้มอบหมาย --</option>
                            <?php foreach ($allAdmins as $admin): ?>
                            <option value="<?= $admin['id'] ?>" <?= ($currentAssignment && $currentAssignment['assigned_to'] == $admin['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($admin['display_name'] ?: $admin['username']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($currentAssignment): ?>
                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-2">
                        <div class="flex justify-between items-center">
                            <span class="text-purple-700">
                                <i class="fas fa-user-check mr-1"></i>
                                <?= htmlspecialchars($currentAssignment['assigned_admin_name'] ?? 'Admin') ?>
                            </span>
                            <span class="text-[9px] text-purple-500">
                                <?= date('d/m H:i', strtotime($currentAssignment['assigned_at'])) ?>
                            </span>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <button onclick="resolveConversation()" class="flex-1 bg-green-500 hover:bg-green-600 text-white text-[10px] py-1 rounded">
                                <i class="fas fa-check mr-1"></i>เสร็จสิ้น
                            </button>
                            <button onclick="unassignConversation()" class="flex-1 bg-gray-400 hover:bg-gray-500 text-white text-[10px] py-1 rounded">
                                <i class="fas fa-times mr-1"></i>ยกเลิก
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
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
            
            <!-- Notes Section - Collapsible - Requirements: 4.4, 4.5 -->
            <div class="panel-section border-t" data-section="notes">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('notes')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-sticky-note text-yellow-500 mr-1"></i>โน๊ต (<?= count($userNotes) ?>)</h5>
                    <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                </div>
                <div class="section-content">
                    <form onsubmit="saveCustomerNote(event)" class="mb-2">
                        <textarea id="noteInput" rows="2" class="w-full border rounded-lg px-2 py-1.5 text-xs focus:ring-1 focus:ring-emerald-500 outline-none resize-none" placeholder="เพิ่มโน๊ตเกี่ยวกับลูกค้า..."></textarea>
                        <div class="flex gap-2 mt-1">
                            <label class="flex items-center gap-1 text-[10px] text-gray-500">
                                <input type="checkbox" id="notePinned" class="w-3 h-3">
                                <i class="fas fa-thumbtack"></i> ปักหมุด
                            </label>
                            <button type="submit" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white text-xs py-1.5 rounded-lg">บันทึกโน๊ต</button>
                        </div>
                    </form>
                    <div id="notesList" class="space-y-2 max-h-48 overflow-y-auto">
                        <?php foreach ($userNotes as $note): ?>
                        <div class="note-item <?= !empty($note['is_pinned']) ? 'bg-yellow-100 border-yellow-300' : 'bg-yellow-50 border-yellow-200' ?> border rounded-lg p-2 text-xs relative group" data-note-id="<?= $note['id'] ?>">
                            <?php if (!empty($note['is_pinned'])): ?>
                            <span class="absolute -top-1 -left-1 text-yellow-500 text-[10px]"><i class="fas fa-thumbtack"></i></span>
                            <?php endif; ?>
                            <p class="text-gray-700 note-content pr-12"><?= nl2br(htmlspecialchars($note['note'])) ?></p>
                            <div class="flex justify-between items-center mt-1">
                                <p class="text-[9px] text-gray-400">
                                    <?php if (!empty($note['admin_name'])): ?>
                                    <span class="text-purple-500"><?= htmlspecialchars($note['admin_name']) ?></span> • 
                                    <?php endif; ?>
                                    <?= date('d/m/Y H:i', strtotime($note['created_at'])) ?>
                                </p>
                            </div>
                            <div class="absolute top-1 right-1 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="togglePinNote(<?= $note['id'] ?>, this)" class="text-yellow-500 hover:text-yellow-600 p-1" title="<?= !empty($note['is_pinned']) ? 'เลิกปักหมุด' : 'ปักหมุด' ?>">
                                    <i class="fas fa-thumbtack text-[10px]"></i>
                                </button>
                                <button onclick="editNote(<?= $note['id'] ?>, this)" class="text-blue-400 hover:text-blue-600 p-1" title="แก้ไข">
                                    <i class="fas fa-edit text-[10px]"></i>
                                </button>
                                <button onclick="deleteCustomerNote(<?= $note['id'] ?>, this)" class="text-red-400 hover:text-red-600 p-1" title="ลบ">
                                    <i class="fas fa-times text-[10px]"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($userNotes)): ?><p id="noNotesMsg" class="text-gray-400 text-xs text-center py-2">ยังไม่มีโน๊ต</p><?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Orders - Collapsible - Requirements: 4.2 -->
            <div class="panel-section border-t" data-section="orders">
                <div class="flex items-center justify-between cursor-pointer py-2" onclick="toggleSection('orders')">
                    <h5 class="text-xs font-bold text-gray-700"><i class="fas fa-shopping-bag text-blue-500 mr-1"></i>ออเดอร์ล่าสุด (<?= count($userOrders) ?>)</h5>
                    <i class="fas fa-chevron-down text-gray-400 text-xs section-icon transition-transform"></i>
                </div>
                <div class="section-content space-y-1.5">
                    <?php if (!empty($userOrders)): ?>
                    <?php foreach (array_slice($userOrders, 0, 5) as $order): ?>
                    <div class="bg-gray-50 rounded-lg p-2 text-xs hover:bg-gray-100 cursor-pointer transition-colors" onclick="viewOrder(<?= $order['id'] ?>)">
                        <div class="flex justify-between items-center">
                            <span class="font-medium text-gray-800">#<?= $order['order_number'] ?? $order['id'] ?></span>
                            <span class="text-emerald-600 font-semibold">฿<?= number_format($order['grand_total'] ?? 0) ?></span>
                        </div>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-[9px] text-gray-400"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></span>
                            <?php 
                            $statusColors = [
                                'pending' => 'bg-yellow-100 text-yellow-700',
                                'confirmed' => 'bg-blue-100 text-blue-700',
                                'processing' => 'bg-purple-100 text-purple-700',
                                'shipped' => 'bg-cyan-100 text-cyan-700',
                                'delivered' => 'bg-green-100 text-green-700',
                                'completed' => 'bg-green-100 text-green-700',
                                'cancelled' => 'bg-red-100 text-red-700',
                            ];
                            $status = $order['status'] ?? 'pending';
                            $statusClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-700';
                            $statusLabels = [
                                'pending' => 'รอดำเนินการ',
                                'confirmed' => 'ยืนยันแล้ว',
                                'processing' => 'กำลังจัดเตรียม',
                                'shipped' => 'จัดส่งแล้ว',
                                'delivered' => 'ส่งถึงแล้ว',
                                'completed' => 'เสร็จสิ้น',
                                'cancelled' => 'ยกเลิก',
                            ];
                            ?>
                            <span class="text-[9px] px-1.5 py-0.5 rounded <?= $statusClass ?>"><?= $statusLabels[$status] ?? $status ?></span>
                        </div>
                        <?php if (!empty($order['item_count'])): ?>
                        <div class="text-[9px] text-gray-500 mt-1">
                            <i class="fas fa-box mr-1"></i><?= $order['item_count'] ?> รายการ
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($userOrders) > 5): ?>
                    <a href="shop/orders.php?user_id=<?= $selectedUser['id'] ?>" class="block text-center text-[10px] text-blue-500 hover:text-blue-600 py-1">
                        ดูทั้งหมด <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-gray-400 text-xs text-center py-2">ยังไม่มีออเดอร์</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="pt-3 border-t space-y-1.5">
                <button onclick="createDispenseSession()" class="block w-full text-center bg-emerald-500 hover:bg-emerald-600 text-white text-xs py-2 rounded-lg cursor-pointer"><i class="fas fa-pills mr-1"></i>จ่ายยาให้ผู้ใช้</button>
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

<!-- Dispense Modal -->
<?php if ($selectedUser): ?>
<div id="dispenseModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-emerald-50 flex-shrink-0">
            <h3 class="font-bold text-emerald-700"><i class="fas fa-pills mr-2"></i>จ่ายยาให้ <?= htmlspecialchars($selectedUser['display_name']) ?></h3>
            <button onclick="closeDispenseModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-4">
            <!-- Drug Allergies Warning -->
            <?php if (!empty($selectedUser['drug_allergies'])): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-red-600 font-medium text-sm"><i class="fas fa-exclamation-triangle mr-1"></i>แพ้ยา: <?= htmlspecialchars($selectedUser['drug_allergies']) ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Drug Search -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">ค้นหายา/สินค้า</label>
                <div class="relative">
                    <input type="text" id="dispenseSearch" placeholder="พิมพ์ชื่อยาหรือสินค้า..." 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                           autocomplete="off">
                    <div id="dispenseSearchResults" class="absolute top-full left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto z-50 hidden"></div>
                </div>
            </div>
            
            <!-- Selected Items -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">รายการที่เลือก</label>
                <div id="dispenseSelectedItems" class="space-y-2">
                    <p class="text-gray-400 text-center py-4 text-sm">ยังไม่ได้เลือกรายการ</p>
                </div>
            </div>
            
            <!-- Total -->
            <div id="dispenseTotalSection" class="hidden border-t pt-3 mb-4">
                <div class="flex justify-between items-center text-lg font-bold">
                    <span>รวมทั้งหมด</span>
                    <span class="text-emerald-600" id="dispenseTotal">฿0</span>
                </div>
            </div>
            
            <!-- Note -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">หมายเหตุ</label>
                <textarea id="dispenseNote" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-1 focus:ring-emerald-500 text-sm" 
                          placeholder="คำแนะนำเพิ่มเติม..."></textarea>
            </div>
        </div>
        
        <div class="p-4 border-t bg-gray-50 flex gap-3 flex-shrink-0">
            <button onclick="closeDispenseModal()" class="flex-1 py-2.5 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg font-medium">ยกเลิก</button>
            <button onclick="submitDispense()" class="flex-1 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium">
                <i class="fas fa-cart-plus mr-1"></i>เพิ่มลงตะกร้า
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Notification Container -->
<div id="notificationContainer" class="notification-container"></div>

<!-- Polling Settings Modal - Requirements: 1.1 -->
<div id="pollingSettingsModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="p-3 border-b flex justify-between items-center bg-emerald-50">
            <h3 class="font-bold text-sm text-emerald-700"><i class="fas fa-sync-alt mr-1"></i>ตั้งค่าการอัพเดท Real-time</h3>
            <button onclick="closePollingSettings()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-2">ความถี่ในการอัพเดท (วินาที)</label>
                <div class="flex items-center gap-3">
                    <input type="range" id="pollingIntervalSlider" min="2" max="30" step="1" value="5" 
                           class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                           oninput="updatePollingIntervalDisplay(this.value)">
                    <span id="pollingIntervalDisplay" class="text-sm font-medium text-emerald-600 w-12 text-center">5 วิ</span>
                </div>
                <p class="text-[10px] text-gray-500 mt-1">ค่าน้อย = อัพเดทเร็วขึ้น แต่ใช้ทรัพยากรมากขึ้น</p>
            </div>
            
            <div class="border-t pt-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="desktopNotifyCheckbox" class="w-4 h-4 text-emerald-600 rounded focus:ring-emerald-500"
                           onchange="toggleDesktopNotificationsFromCheckbox(this.checked)">
                    <span class="text-xs text-gray-700">เปิดการแจ้งเตือน Desktop</span>
                </label>
                <p class="text-[10px] text-gray-500 mt-1 ml-6">แสดงการแจ้งเตือนเมื่อมีข้อความใหม่ (แม้ไม่ได้อยู่หน้านี้)</p>
            </div>
            
            <div class="border-t pt-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="soundEnabledCheckbox" class="w-4 h-4 text-emerald-600 rounded focus:ring-emerald-500"
                           onchange="toggleSoundFromCheckbox(this.checked)">
                    <span class="text-xs text-gray-700">เปิดเสียงแจ้งเตือน</span>
                </label>
            </div>
            
            <div class="border-t pt-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">สถานะการเชื่อมต่อ:</span>
                    <span id="connectionStatus" class="flex items-center gap-1">
                        <span class="w-2 h-2 bg-green-500 rounded-full pulse-dot"></span>
                        <span class="text-green-600">เชื่อมต่อแล้ว</span>
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs mt-1">
                    <span class="text-gray-500">Poll ล่าสุด:</span>
                    <span id="lastPollDisplay" class="text-gray-600">-</span>
                </div>
            </div>
        </div>
        <div class="p-3 border-t bg-gray-50 flex gap-2">
            <button onclick="resetPollingSettings()" class="flex-1 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg text-xs font-medium">
                รีเซ็ต
            </button>
            <button onclick="savePollingSettings()" class="flex-1 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs font-medium">
                บันทึก
            </button>
        </div>
    </div>
</div>

<!-- Audio for notifications -->
<audio id="notificationSound" preload="auto">
    <source src="data:audio/mpeg;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAABhgC7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7//////////////////////////////////////////////////////////////////8AAAAATGF2YzU4LjEzAAAAAAAAAAAAAAAAJAAAAAAAAAAAAYYNBrv2AAAAAAAAAAAAAAAAAAAAAP/7UMQAA8AAADSAAAAAAAAANIAAAAATEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVX/+1DEAYPAAADSAAAAAAAAANIAAAAATEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVU=" type="audio/mpeg">
</audio>

<script>
const userId = <?= $selectedUser ? $selectedUser['id'] : 'null' ?>;
const currentUserName = '<?= $selectedUser ? htmlspecialchars($selectedUser['display_name']) : '' ?>';
const currentUserPic = '<?= $selectedUser ? ($selectedUser['picture_url'] ?: 'https://via.placeholder.com/40') : '' ?>';
const lineAccountId = <?= $currentBotId ?>;
let lastMessageId = <?= !empty($messages) ? end($messages)['id'] ?? 0 : 0 ?>;
let pollingInterval = null;
let isPolling = false;
let sentMessageIds = new Set();
let soundEnabled = localStorage.getItem('inboxSoundEnabled') !== 'false'; // default true

// Update sound icon on load
document.addEventListener('DOMContentLoaded', () => {
    updateSoundIcon();
    updateDesktopNotifyIcon();
    scrollToBottom();
    
    // Initialize notification permission - Requirements: 1.2
    initNotificationPermission();
    
    // Start polling with configurable interval - Requirements: 1.1
    startPolling();
    
    // Cache current conversation for instant switching - Requirements: 11.6
    if (userId) {
        fetchAndCacheConversation(userId);
        
        // Preload adjacent conversations after a short delay
        setTimeout(preloadAdjacentConversations, 2000);
    }
    
    // Initial poll - ทำทันที
    if (userId) {
        pollMessages();
    } else {
        pollSidebar();
    }
    
    // Global keyboard shortcuts - Requirements: 8.3
    document.addEventListener('keydown', function(e) {
        // Ctrl+/ to open quick reply menu - Requirements: 8.3
        if (e.ctrlKey && e.key === '/') {
            e.preventDefault();
            if (userId) {
                openQuickReplyModal();
            }
        }
    });
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

// ===== Polling Settings Modal Functions - Requirements: 1.1 =====
function openPollingSettings() {
    const modal = document.getElementById('pollingSettingsModal');
    modal.classList.remove('hidden');
    
    // Set current values
    const currentInterval = getPollingInterval() / 1000;
    document.getElementById('pollingIntervalSlider').value = currentInterval;
    document.getElementById('pollingIntervalDisplay').textContent = currentInterval + ' วิ';
    document.getElementById('desktopNotifyCheckbox').checked = desktopNotificationsEnabled;
    document.getElementById('soundEnabledCheckbox').checked = soundEnabled;
    
    // Update last poll time
    updateLastPollDisplay();
}

function closePollingSettings() {
    document.getElementById('pollingSettingsModal').classList.add('hidden');
}

function updatePollingIntervalDisplay(value) {
    document.getElementById('pollingIntervalDisplay').textContent = value + ' วิ';
}

function savePollingSettings() {
    const interval = parseInt(document.getElementById('pollingIntervalSlider').value) * 1000;
    setPollingInterval(interval);
    closePollingSettings();
    showToast('บันทึกการตั้งค่าแล้ว', '', '', 2000);
}

function resetPollingSettings() {
    document.getElementById('pollingIntervalSlider').value = 5;
    document.getElementById('pollingIntervalDisplay').textContent = '5 วิ';
    setPollingInterval(POLLING_CONFIG.defaultInterval);
    showToast('รีเซ็ตเป็นค่าเริ่มต้นแล้ว', '', '', 2000);
}

function toggleDesktopNotificationsFromCheckbox(checked) {
    desktopNotificationsEnabled = checked;
    localStorage.setItem('inboxDesktopNotifications', checked);
    
    if (checked && notificationPermission === 'default') {
        initNotificationPermission();
    }
    
    updateDesktopNotifyIcon();
}

function toggleSoundFromCheckbox(checked) {
    soundEnabled = checked;
    localStorage.setItem('inboxSoundEnabled', checked);
    updateSoundIcon();
}

function updateDesktopNotifyIcon() {
    const icon = document.getElementById('desktopNotifyIcon');
    if (icon) {
        icon.className = desktopNotificationsEnabled ? 'fas fa-bell' : 'fas fa-bell-slash';
    }
}

function updateLastPollDisplay() {
    const display = document.getElementById('lastPollDisplay');
    if (display && lastPollTime) {
        const secondsAgo = Math.floor((Date.now() - lastPollTime) / 1000);
        display.textContent = secondsAgo + ' วินาทีที่แล้ว';
    }
}

// Update last poll display periodically when modal is open
setInterval(() => {
    if (!document.getElementById('pollingSettingsModal').classList.contains('hidden')) {
        updateLastPollDisplay();
        
        // Update connection status
        const statusEl = document.getElementById('connectionStatus');
        if (statusEl) {
            if (pollErrorCount > 3) {
                statusEl.innerHTML = '<span class="w-2 h-2 bg-red-500 rounded-full"></span><span class="text-red-600">มีปัญหา</span>';
            } else if (pollErrorCount > 0) {
                statusEl.innerHTML = '<span class="w-2 h-2 bg-yellow-500 rounded-full"></span><span class="text-yellow-600">ไม่เสถียร</span>';
            } else {
                statusEl.innerHTML = '<span class="w-2 h-2 bg-green-500 rounded-full pulse-dot"></span><span class="text-green-600">เชื่อมต่อแล้ว</span>';
            }
        }
    }
}, 1000);

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

// ===== Real-time Polling System =====
// Requirements: 1.1, 1.2, 11.4, 11.6

// Polling Configuration - Requirements: 1.1, 11.4
const POLLING_CONFIG = {
    defaultInterval: 5000,      // Default 5 seconds - Requirements: 1.1
    minInterval: 2000,          // Minimum 2 seconds
    maxInterval: 30000,         // Maximum 30 seconds
    errorBackoffMultiplier: 2,  // Double interval on errors
    maxErrorCount: 10           // Max errors before stopping
};

let pollErrorCount = 0;
let lastPollTime = Date.now();
let globalLastMsgId = <?= !empty($messages) ? end($messages)['id'] ?? 0 : 0 ?>; // Track globally for sidebar
let currentPollInterval = POLLING_CONFIG.defaultInterval;

// Conversation Cache - Requirements: 11.6
const conversationCache = new Map();
const CACHE_MAX_SIZE = 50;
const CACHE_TTL = 5 * 60 * 1000; // 5 minutes

// Desktop Notification Settings - Requirements: 1.2
let desktopNotificationsEnabled = localStorage.getItem('inboxDesktopNotifications') !== 'false';
let notificationPermission = 'default';

// Initialize notification permission
async function initNotificationPermission() {
    if ('Notification' in window) {
        notificationPermission = Notification.permission;
        if (notificationPermission === 'default' && desktopNotificationsEnabled) {
            try {
                notificationPermission = await Notification.requestPermission();
            } catch (e) {
                console.log('Notification permission request failed:', e);
            }
        }
    }
}

// Toggle desktop notifications - Requirements: 1.2
function toggleDesktopNotifications() {
    desktopNotificationsEnabled = !desktopNotificationsEnabled;
    localStorage.setItem('inboxDesktopNotifications', desktopNotificationsEnabled);
    
    if (desktopNotificationsEnabled && notificationPermission === 'default') {
        initNotificationPermission();
    }
    
    showToast(desktopNotificationsEnabled ? '🔔 เปิดการแจ้งเตือน Desktop' : '🔕 ปิดการแจ้งเตือน Desktop', '', '', 2000);
}

// Get configurable polling interval from localStorage or use default
function getPollingInterval() {
    const stored = localStorage.getItem('inboxPollingInterval');
    if (stored) {
        const interval = parseInt(stored);
        if (interval >= POLLING_CONFIG.minInterval && interval <= POLLING_CONFIG.maxInterval) {
            return interval;
        }
    }
    return POLLING_CONFIG.defaultInterval;
}

// Set polling interval - Requirements: 1.1
function setPollingInterval(interval) {
    interval = Math.max(POLLING_CONFIG.minInterval, Math.min(POLLING_CONFIG.maxInterval, interval));
    localStorage.setItem('inboxPollingInterval', interval);
    currentPollInterval = interval;
    
    // Restart polling with new interval
    if (pollingInterval) {
        stopPolling();
        startPolling();
    }
}

// Efficient delta polling - Requirements: 11.4
async function pollMessages() {
    if (!userId || isPolling) {
        return;
    }
    isPolling = true;
    
    // Update live indicator
    const indicator = document.getElementById('liveIndicator');
    if (indicator) indicator.style.background = '#FCD34D'; // yellow = polling
    
    try {
        // Delta update: only fetch messages since last check - Requirements: 11.4
        const url = `api/messages.php?action=poll&user_id=${userId}&last_id=${lastMessageId}&_t=${Date.now()}`;
        
        const msgRes = await fetch(url);
        
        if (!msgRes.ok) {
            throw new Error(`HTTP ${msgRes.status}`);
        }
        
        const data = await msgRes.json();
        
        if (data.success) {
            pollErrorCount = 0;
            lastPollTime = Date.now();
            currentPollInterval = getPollingInterval();
            if (indicator) indicator.style.background = '#86EFAC'; // green = success
            
            // Add new messages to chat (delta update)
            if (data.messages && data.messages.length > 0) {
                console.log('📨 New messages (delta):', data.messages.length);
                data.messages.forEach(msg => {
                    const msgId = parseInt(msg.id);
                    const isIncoming = msg.direction === 'incoming';
                    
                    if (msgId > lastMessageId && !sentMessageIds.has(msgId) && !document.querySelector(`[data-msg-id="${msgId}"]`)) {
                        appendMessage(msg);
                        updateConversationCache(userId, msg);
                        
                        if (isIncoming) {
                            showInboxNotification(
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
            
            // Update sidebar unread counts
            if (data.unread_users) {
                data.unread_users.forEach(u => updateUserUnread(u.id, u.unread));
            }
            
            // Handle updated conversations - move to top of sidebar
            if (data.updated_conversations && data.updated_conversations.length > 0) {
                // Sort by last_time (newest first)
                const sortedConvs = data.updated_conversations.sort((a, b) => {
                    const timeA = a.last_time ? new Date(a.last_time).getTime() : 0;
                    const timeB = b.last_time ? new Date(b.last_time).getTime() : 0;
                    return timeB - timeA;
                });
                
                // Move updated conversations to top of sidebar
                reorderSidebar(sortedConvs);
                
                sortedConvs.forEach(conv => {
                    updateSidebarUser(conv);
                    
                    // Show notification for other users
                    if (conv.id != userId && conv.last_message) {
                        showInboxNotification(conv.display_name || 'ลูกค้า', conv.last_message, conv.picture_url, conv.id);
                    }
                });
            }
        }
    } catch (err) {
        pollErrorCount++;
        console.error('Poll error:', err, 'Count:', pollErrorCount);
        if (indicator) indicator.style.background = '#FCA5A5'; // red = error
        
        // Exponential backoff on errors
        if (pollErrorCount > 3) {
            currentPollInterval = Math.min(
                currentPollInterval * POLLING_CONFIG.errorBackoffMultiplier,
                POLLING_CONFIG.maxInterval
            );
            console.warn('Backing off polling to:', currentPollInterval, 'ms');
            
            // Restart with new interval
            if (pollingInterval && pollErrorCount <= POLLING_CONFIG.maxErrorCount) {
                stopPolling();
                startPolling();
            }
        }
        
        // Stop polling if too many errors
        if (pollErrorCount > POLLING_CONFIG.maxErrorCount) {
            console.error('Too many poll errors, stopping polling');
            stopPolling();
            showToast('การเชื่อมต่อมีปัญหา กรุณารีเฟรชหน้า', 'error');
        }
    }
    
    isPolling = false;
}

// Enhanced notification function - Requirements: 1.2
function showInboxNotification(name, message, pictureUrl, notifyUserId) {
    // Show in-app toast notification
    showToast(name, message, pictureUrl, 5000, notifyUserId);
    
    // Play notification sound - Requirements: 1.2
    if (soundEnabled) {
        playNotificationSound();
    }
    
    // Desktop notification - Requirements: 1.2
    if (desktopNotificationsEnabled && 
        !document.hasFocus() && 
        'Notification' in window && 
        Notification.permission === 'granted') {
        
        try {
            const notification = new Notification('ข้อความใหม่จาก ' + name, {
                body: message.substring(0, 100),
                icon: pictureUrl || 'https://via.placeholder.com/40',
                tag: 'inbox-' + notifyUserId,
                requireInteraction: false,
                silent: true // We handle sound separately
            });
            
            notification.onclick = () => {
                window.focus();
                if (notifyUserId) {
                    window.location.href = '?user=' + notifyUserId;
                }
                notification.close();
            };
            
            // Auto close after 5 seconds
            setTimeout(() => notification.close(), 5000);
        } catch (e) {
            console.log('Desktop notification error:', e);
        }
    }
}

// Start polling with configurable interval - Requirements: 1.1, 11.4
function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    
    currentPollInterval = getPollingInterval();
    
    if (userId) {
        pollingInterval = setInterval(pollMessages, currentPollInterval);
    } else {
        // Poll sidebar only when no user selected
        pollingInterval = setInterval(pollSidebar, currentPollInterval);
    }
}

// Poll sidebar only (when no user selected)
async function pollSidebar() {
    try {
        const res = await fetch(`api/messages.php?action=get_conversations&_t=${Date.now()}`);
        const data = await res.json();
        
        if (data.success && data.conversations) {
            // Sort conversations by last_time (newest first)
            const sortedConvs = data.conversations.sort((a, b) => {
                const timeA = a.last_time ? new Date(a.last_time).getTime() : 0;
                const timeB = b.last_time ? new Date(b.last_time).getTime() : 0;
                return timeB - timeA;
            });
            
            // Reorder sidebar to match sorted order
            reorderSidebar(sortedConvs);
            
            // Update sidebar with new data
            sortedConvs.forEach(conv => {
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

// Move updated conversations to top of sidebar (efficient - only moves changed items)
function reorderSidebar(updatedConvs) {
    const userList = document.getElementById('userList');
    if (!userList || !updatedConvs || updatedConvs.length === 0) {
        return;
    }
    
    // Move each updated conversation to top (in reverse order so newest ends up first)
    for (let i = updatedConvs.length - 1; i >= 0; i--) {
        const conv = updatedConvs[i];
        const item = userList.querySelector(`.user-item[data-user-id="${conv.id}"]`);
        if (item && userList.firstChild !== item) {
            userList.insertBefore(item, userList.firstChild);
        }
    }
}

// ===== Conversation Caching - Requirements: 11.6 =====

// Cache conversation messages for instant switching
function cacheConversation(convUserId, messages) {
    // Limit cache size
    if (conversationCache.size >= CACHE_MAX_SIZE) {
        // Remove oldest entry
        const oldestKey = conversationCache.keys().next().value;
        conversationCache.delete(oldestKey);
    }
    
    conversationCache.set(convUserId, {
        messages: messages,
        timestamp: Date.now(),
        lastMessageId: messages.length > 0 ? Math.max(...messages.map(m => parseInt(m.id))) : 0
    });
}

// Update cache with new message
function updateConversationCache(convUserId, newMessage) {
    const cached = conversationCache.get(convUserId);
    if (cached) {
        cached.messages.push(newMessage);
        cached.lastMessageId = Math.max(cached.lastMessageId, parseInt(newMessage.id));
        cached.timestamp = Date.now();
    }
}

// Get cached conversation
function getCachedConversation(convUserId) {
    const cached = conversationCache.get(convUserId);
    if (cached) {
        // Check if cache is still valid (within TTL)
        if (Date.now() - cached.timestamp < CACHE_TTL) {
            return cached;
        } else {
            // Cache expired
            conversationCache.delete(convUserId);
        }
    }
    return null;
}

// Clear conversation cache
function clearConversationCache() {
    conversationCache.clear();
}

// Preload adjacent conversations for faster switching
async function preloadAdjacentConversations() {
    const userItems = document.querySelectorAll('.user-item');
    const currentIndex = Array.from(userItems).findIndex(item => item.classList.contains('active'));
    
    if (currentIndex === -1) return;
    
    // Preload previous and next 2 conversations
    const indicesToPreload = [
        currentIndex - 2, currentIndex - 1,
        currentIndex + 1, currentIndex + 2
    ].filter(i => i >= 0 && i < userItems.length);
    
    for (const index of indicesToPreload) {
        const item = userItems[index];
        const preloadUserId = item.dataset.userId;
        
        if (preloadUserId && !getCachedConversation(preloadUserId)) {
            try {
                const res = await fetch(`api/messages.php?action=get_messages&user_id=${preloadUserId}&limit=50`);
                const data = await res.json();
                if (data.success && data.messages) {
                    cacheConversation(preloadUserId, data.messages);
                }
            } catch (e) {
                console.log('Preload failed for:', preloadUserId);
            }
            
            // Small delay between preloads to avoid overwhelming the server
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }
}

// Cache current conversation on load
function cacheCurrentConversation() {
    if (userId) {
        const messages = [];
        document.querySelectorAll('.message-item').forEach(item => {
            const msgId = item.dataset.msgId;
            if (msgId) {
                // Extract message data from DOM (simplified)
                messages.push({
                    id: msgId,
                    // Note: Full message data would need to be stored differently
                    // This is a simplified version for demonstration
                });
            }
        });
        
        // For now, we'll fetch fresh data to cache properly
        fetchAndCacheConversation(userId);
    }
}

// Fetch and cache conversation
async function fetchAndCacheConversation(convUserId) {
    try {
        const res = await fetch(`api/messages.php?action=get_messages&user_id=${convUserId}&limit=50`);
        const data = await res.json();
        if (data.success && data.messages) {
            cacheConversation(convUserId, data.messages);
        }
    } catch (e) {
        console.log('Failed to cache conversation:', convUserId);
    }
}

// Update sidebar user item and move to top if has new message
function updateSidebarUser(conv) {
    let item = document.querySelector(`[data-user-id="${conv.id}"]`);
    const userList = document.getElementById('userList');
    
    // If user doesn't exist in sidebar, create new item
    if (!item && userList) {
        item = createUserItem(conv);
        userList.insertBefore(item, userList.firstChild);
        item.classList.add('new-message-flash');
        setTimeout(() => item.classList.remove('new-message-flash'), 1000);
        return;
    }
    
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
    
    // Move to top of list if has new message (not current selected user)
    if (userList && conv.last_message) {
        const firstItem = userList.querySelector('.user-item');
        if (firstItem && firstItem !== item) {
            // Move item to top with animation
            item.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            item.style.opacity = '0.5';
            
            setTimeout(() => {
                userList.insertBefore(item, userList.firstChild);
                item.style.opacity = '1';
                item.classList.add('new-message-flash');
                setTimeout(() => item.classList.remove('new-message-flash'), 1000);
            }, 100);
        }
    }
    
    // Flash animation for unread
    if (conv.unread_count > 0) {
        item.classList.add('new-message-flash');
        setTimeout(() => item.classList.remove('new-message-flash'), 1000);
    }
}

// Create new user item for sidebar
function createUserItem(conv) {
    const a = document.createElement('a');
    a.href = `?user=${conv.id}`;
    a.className = 'user-item block p-3 border-b border-gray-50';
    a.dataset.userId = conv.id;
    a.dataset.name = (conv.display_name || '').toLowerCase();
    
    let preview = conv.last_message || '';
    if (conv.last_type === 'image') preview = '📷 รูปภาพ';
    else if (conv.last_type === 'sticker') preview = '😊 สติกเกอร์';
    else if (preview.length > 30) preview = preview.substring(0, 30) + '...';
    
    const unreadBadge = conv.unread_count > 0 
        ? `<div class="unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold">${conv.unread_count > 9 ? '9+' : conv.unread_count}</div>` 
        : '';
    
    a.innerHTML = `
        <div class="flex items-center gap-3">
            <div class="relative flex-shrink-0">
                <img src="${conv.picture_url || 'https://via.placeholder.com/40'}" 
                     class="w-10 h-10 rounded-full object-cover border-2 border-white shadow"
                     loading="lazy">
                ${unreadBadge}
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-baseline">
                    <h3 class="text-sm font-semibold text-gray-800 truncate">${escapeHtml(conv.display_name || 'Unknown')}</h3>
                    <span class="last-time text-[10px] text-gray-400">${conv.last_time ? formatThaiTimeJS(new Date(conv.last_time)) : ''}</span>
                </div>
                <p class="last-msg text-xs text-gray-500 truncate">${escapeHtml(preview)}</p>
            </div>
        </div>
    `;
    
    return a;
}

function stopPolling() {
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
        console.log('🔴 Polling stopped');
    }
}

// Pause polling when tab is hidden, resume when visible - Requirements: 11.4
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopPolling();
    } else {
        // Reset error count when tab becomes visible
        pollErrorCount = 0;
        currentPollInterval = getPollingInterval();
        
        startPolling();
        // Immediate poll when tab becomes visible
        if (userId) {
            pollMessages();
        } else {
            pollSidebar();
        }
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
        if (match) {
            imgSrc = 'api/line_content.php?id=' + match[1];
        } else if (!content.match(/^https?:\/\//)) {
            // ถ้าไม่ใช่ URL และไม่ใช่ ID format ให้ลองใช้เป็น message ID
            imgSrc = 'api/line_content.php?id=' + content;
        }
        // Updated image handling with error handler - Requirements: 7.2
        contentHtml = `<img src="${imgSrc}" class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90 chat-image" onclick="openImage(this.src)" onerror="handleImageError(this)" data-original-src="${imgSrc}" loading="lazy">`;
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

// ===== AI Reply - Enhanced with tone selection and message picker =====
let selectedAIMessage = '';
let selectedAITone = 'friendly';
let conversationContextForAI = [];

async function generateAIReply() {
    // Open AI Panel and show config section
    document.getElementById('aiPanel').classList.remove('hidden');
    document.getElementById('aiConfigSection').classList.remove('hidden');
    document.getElementById('aiResultSection').classList.add('hidden');
    
    // Get last customer messages for context
    const messageItems = [...document.querySelectorAll('.message-item')].slice(-15);
    conversationContextForAI = [];
    let lastCustomerMessage = '';
    
    messageItems.forEach(el => {
        const bubble = el.querySelector('.chat-bubble');
        if (bubble) {
            const text = bubble.textContent.trim();
            if (el.querySelector('.chat-incoming')) {
                conversationContextForAI.push({ role: 'customer', text });
                lastCustomerMessage = text;
            } else if (el.querySelector('.chat-outgoing')) {
                conversationContextForAI.push({ role: 'admin', text });
            }
        }
    });
    
    // Auto-select last customer message
    if (lastCustomerMessage) {
        selectMessageForAI(lastCustomerMessage);
    }
    
    // Scroll to AI panel
    document.getElementById('aiPanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function selectMessageForAI(message) {
    selectedAIMessage = message;
    const displayEl = document.getElementById('aiSelectedMessage');
    displayEl.innerHTML = `<span class="text-gray-700">${escapeHtml(message)}</span>`;
    displayEl.classList.add('border-indigo-300', 'bg-indigo-50');
}

function openMessagePicker() {
    const modal = document.getElementById('messagePickerModal');
    const listContainer = document.getElementById('messagePickerList');
    
    // Get all incoming messages
    const messageItems = [...document.querySelectorAll('.message-item')];
    const incomingMessages = [];
    
    messageItems.forEach(el => {
        const bubble = el.querySelector('.chat-bubble');
        if (bubble && el.querySelector('.chat-incoming')) {
            const text = bubble.textContent.trim();
            const timeEl = el.querySelector('.msg-meta span');
            const time = timeEl ? timeEl.textContent : '';
            incomingMessages.push({ text, time });
        }
    });
    
    // Render messages (newest first)
    listContainer.innerHTML = incomingMessages.reverse().map((msg, idx) => `
        <div class="message-picker-item p-3 border-b border-gray-100 cursor-pointer hover:bg-indigo-50 rounded-lg mb-1 ${selectedAIMessage === msg.text ? 'bg-indigo-100 border-indigo-300' : ''}" 
             onclick="pickMessage(this, '${escapeHtml(msg.text).replace(/'/g, "\\'")}')">
            <p class="text-sm text-gray-700">${escapeHtml(msg.text)}</p>
            <span class="text-xs text-gray-400 mt-1 block">${msg.time}</span>
        </div>
    `).join('') || '<p class="text-center text-gray-400 py-4">ไม่พบข้อความจากลูกค้า</p>';
    
    modal.classList.remove('hidden');
}

function pickMessage(el, message) {
    selectMessageForAI(message);
    closeMessagePicker();
}

function closeMessagePicker() {
    document.getElementById('messagePickerModal').classList.add('hidden');
}

// Tone selection
document.addEventListener('DOMContentLoaded', function() {
    const toneSelector = document.getElementById('toneSelector');
    if (toneSelector) {
        toneSelector.addEventListener('click', function(e) {
            const btn = e.target.closest('.tone-btn');
            if (btn) {
                // Remove active from all
                toneSelector.querySelectorAll('.tone-btn').forEach(b => {
                    b.classList.remove('active', 'bg-indigo-600', 'text-white');
                    b.classList.add('bg-white', 'text-gray-600', 'border');
                });
                // Add active to clicked
                btn.classList.add('active', 'bg-indigo-600', 'text-white');
                btn.classList.remove('bg-white', 'text-gray-600', 'border');
                selectedAITone = btn.dataset.tone;
            }
        });
    }
});

async function generateAIReplyEnhanced() {
    if (!selectedAIMessage) {
        alert('กรุณาเลือกข้อความที่ต้องการให้ AI ช่วยคิดคำตอบ');
        return;
    }
    
    const btn = document.getElementById('aiGenerateBtn');
    const originalBtnText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>AI กำลังคิด...';
    
    const customInstruction = document.getElementById('aiCustomInstruction').value.trim();
    
    try {
        const formData = new FormData();
        formData.append('action', 'ai_reply');
        formData.append('user_id', userId);
        formData.append('selected_message', selectedAIMessage);
        formData.append('tone', selectedAITone);
        formData.append('custom_instruction', customInstruction);
        formData.append('context', JSON.stringify(conversationContextForAI));
        
        const res = await fetch('', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const data = await res.json();
        
        if (data.success && data.message) {
            // Show result section
            document.getElementById('aiConfigSection').classList.add('hidden');
            document.getElementById('aiResultSection').classList.remove('hidden');
            document.getElementById('aiSuggestion').value = data.message;
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถสร้างคำตอบได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
    
    btn.disabled = false;
    btn.innerHTML = originalBtnText;
}

function regenerateAIReply() {
    // Go back to config section
    document.getElementById('aiConfigSection').classList.remove('hidden');
    document.getElementById('aiResultSection').classList.add('hidden');
}

function editAISuggestion() {
    // Focus on the textarea for editing
    const textarea = document.getElementById('aiSuggestion');
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
}

function useAISuggestion() {
    const suggestion = document.getElementById('aiSuggestion').value.trim();
    if (suggestion && !suggestion.startsWith('Error:')) {
        document.getElementById('messageInput').value = suggestion;
        autoResize(document.getElementById('messageInput'));
        closeAIPanel();
        document.getElementById('messageInput').focus();
    }
}

function closeAIPanel() {
    document.getElementById('aiPanel').classList.add('hidden');
    // Reset state
    document.getElementById('aiConfigSection').classList.remove('hidden');
    document.getElementById('aiResultSection').classList.add('hidden');
}

// ===== AI Panel Drag & Resize =====
let aiPanelMinimized = false;

function toggleAIPanelSize() {
    const panel = document.getElementById('aiPanel');
    const icon = document.getElementById('aiSizeIcon');
    aiPanelMinimized = !aiPanelMinimized;
    
    if (aiPanelMinimized) {
        panel.classList.add('minimized');
        icon.className = 'fas fa-expand-alt';
    } else {
        panel.classList.remove('minimized');
        icon.className = 'fas fa-compress-alt';
    }
}

// Drag functionality
(function() {
    let isDragging = false;
    let dragOffsetX = 0;
    let dragOffsetY = 0;
    
    document.addEventListener('DOMContentLoaded', function() {
        const header = document.getElementById('aiPanelHeader');
        const panel = document.getElementById('aiPanel');
        
        if (!header || !panel) return;
        
        header.addEventListener('mousedown', startDrag);
        header.addEventListener('touchstart', startDrag, { passive: false });
        
        function startDrag(e) {
            // Don't drag if clicking on buttons
            if (e.target.closest('.ai-panel-btn') || e.target.closest('.ai-panel-controls')) return;
            
            isDragging = true;
            panel.classList.add('dragging');
            
            const rect = panel.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            dragOffsetX = clientX - rect.left;
            dragOffsetY = clientY - rect.top;
            
            // Switch to top/left positioning
            panel.style.right = 'auto';
            panel.style.bottom = 'auto';
            panel.style.left = rect.left + 'px';
            panel.style.top = rect.top + 'px';
            
            e.preventDefault();
        }
        
        document.addEventListener('mousemove', drag);
        document.addEventListener('touchmove', drag, { passive: false });
        
        function drag(e) {
            if (!isDragging) return;
            
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            let newX = clientX - dragOffsetX;
            let newY = clientY - dragOffsetY;
            
            // Keep within viewport
            const maxX = window.innerWidth - panel.offsetWidth;
            const maxY = window.innerHeight - panel.offsetHeight;
            
            newX = Math.max(0, Math.min(newX, maxX));
            newY = Math.max(0, Math.min(newY, maxY));
            
            panel.style.left = newX + 'px';
            panel.style.top = newY + 'px';
            
            e.preventDefault();
        }
        
        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);
        
        function stopDrag() {
            if (isDragging) {
                isDragging = false;
                panel.classList.remove('dragging');
            }
        }
    });
})();

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

// ===== Customer Notes - Requirements: 4.4, 4.5 =====
async function saveCustomerNote(e) {
    if (e) e.preventDefault();
    const note = document.getElementById('noteInput').value.trim();
    const isPinned = document.getElementById('notePinned')?.checked || false;
    if (!note) return;
    
    const formData = new FormData();
    formData.append('action', 'add_note');
    formData.append('user_id', userId);
    formData.append('note', note);
    formData.append('is_pinned', isPinned ? '1' : '0');
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            document.getElementById('noteInput').value = '';
            if (document.getElementById('notePinned')) {
                document.getElementById('notePinned').checked = false;
            }
            
            // Remove empty message if exists
            const notesList = document.getElementById('notesList');
            const emptyMsg = document.getElementById('noNotesMsg');
            if (emptyMsg) emptyMsg.remove();
            
            // Add note to list
            const noteDiv = document.createElement('div');
            noteDiv.className = `note-item ${isPinned ? 'bg-yellow-100 border-yellow-300' : 'bg-yellow-50 border-yellow-200'} border rounded-lg p-2 text-xs relative group`;
            noteDiv.dataset.noteId = data.data.id;
            noteDiv.innerHTML = `
                ${isPinned ? '<span class="absolute -top-1 -left-1 text-yellow-500 text-[10px]"><i class="fas fa-thumbtack"></i></span>' : ''}
                <p class="text-gray-700 note-content pr-12">${escapeHtml(note)}</p>
                <div class="flex justify-between items-center mt-1">
                    <p class="text-[9px] text-gray-400">${new Date().toLocaleString('th-TH')}</p>
                </div>
                <div class="absolute top-1 right-1 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onclick="togglePinNote(${data.data.id}, this)" class="text-yellow-500 hover:text-yellow-600 p-1" title="${isPinned ? 'เลิกปักหมุด' : 'ปักหมุด'}">
                        <i class="fas fa-thumbtack text-[10px]"></i>
                    </button>
                    <button onclick="editNote(${data.data.id}, this)" class="text-blue-400 hover:text-blue-600 p-1" title="แก้ไข">
                        <i class="fas fa-edit text-[10px]"></i>
                    </button>
                    <button onclick="deleteCustomerNote(${data.data.id}, this)" class="text-red-400 hover:text-red-600 p-1" title="ลบ">
                        <i class="fas fa-times text-[10px]"></i>
                    </button>
                </div>
            `;
            
            // Insert at top if pinned, otherwise after pinned notes
            if (isPinned) {
                notesList.insertBefore(noteDiv, notesList.firstChild);
            } else {
                const firstUnpinned = notesList.querySelector('.note-item:not(.bg-yellow-100)');
                if (firstUnpinned) {
                    notesList.insertBefore(noteDiv, firstUnpinned);
                } else {
                    notesList.appendChild(noteDiv);
                }
            }
            
            showToast('บันทึกโน๊ตแล้ว', '', '', 2000);
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถบันทึกโน๊ตได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function deleteCustomerNote(noteId, btn) {
    if (!confirm('ลบโน๊ตนี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_note');
    formData.append('id', noteId);
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            btn.closest('.note-item').remove();
            
            // Show empty message if no notes left
            const notesList = document.getElementById('notesList');
            if (!notesList.querySelector('.note-item')) {
                notesList.innerHTML = '<p id="noNotesMsg" class="text-gray-400 text-xs text-center py-2">ยังไม่มีโน๊ต</p>';
            }
            
            showToast('ลบโน๊ตแล้ว', '', '', 2000);
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถลบโน๊ตได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function editNote(noteId, btn) {
    const noteItem = btn.closest('.note-item');
    const contentEl = noteItem.querySelector('.note-content');
    const currentText = contentEl.textContent.trim();
    
    const newText = prompt('แก้ไขโน๊ต:', currentText);
    if (newText === null || newText.trim() === '' || newText === currentText) return;
    
    const formData = new FormData();
    formData.append('action', 'update_note');
    formData.append('id', noteId);
    formData.append('note', newText.trim());
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            contentEl.innerHTML = escapeHtml(newText.trim()).replace(/\n/g, '<br>');
            showToast('อัพเดทโน๊ตแล้ว', '', '', 2000);
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถแก้ไขโน๊ตได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function togglePinNote(noteId, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_pin');
    formData.append('id', noteId);
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            // Reload to reorder notes
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถเปลี่ยนสถานะปักหมุดได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// Legacy note functions for backward compatibility
async function saveNote(e) {
    return saveCustomerNote(e);
}

async function deleteNote(noteId, btn) {
    return deleteCustomerNote(noteId, btn);
}

// ===== Conversation Assignment - Requirements: 3.1 =====
async function assignConversation(adminId) {
    if (!userId) return;
    
    const formData = new FormData();
    
    if (adminId) {
        formData.append('action', 'assign_conversation');
        formData.append('user_id', userId);
        formData.append('assign_to', adminId);
    } else {
        formData.append('action', 'unassign_conversation');
        formData.append('user_id', userId);
    }
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showToast(adminId ? 'มอบหมายงานแล้ว' : 'ยกเลิกการมอบหมายแล้ว', '', '', 2000);
            // Reload to update UI
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถมอบหมายงานได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function unassignConversation() {
    if (!confirm('ยกเลิกการมอบหมายงานนี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'unassign_conversation');
    formData.append('user_id', userId);
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showToast('ยกเลิกการมอบหมายแล้ว', '', '', 2000);
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถยกเลิกการมอบหมายได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

async function resolveConversation() {
    if (!confirm('ทำเครื่องหมายว่าเสร็จสิ้นการสนทนานี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'resolve_conversation');
    formData.append('user_id', userId);
    
    try {
        const res = await fetch('api/inbox.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showToast('ทำเครื่องหมายเสร็จสิ้นแล้ว', '', '', 2000);
            setTimeout(() => location.reload(), 500);
        } else {
            alert('Error: ' + (data.error || 'ไม่สามารถทำเครื่องหมายเสร็จสิ้นได้'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// ===== View Order =====
function viewOrder(orderId) {
    window.open('shop/order-detail.php?id=' + orderId, '_blank');
}

// ===== Medical Info =====
function openMedicalModal() {
    document.getElementById('medicalModal').classList.remove('hidden');
}

function closeMedicalModal() {
    document.getElementById('medicalModal').classList.add('hidden');
}

// ===== Dispense - Create Session and Go to Pharmacy =====
async function createDispenseSession() {
    if (!userId) {
        alert('ไม่พบข้อมูลผู้ใช้');
        return;
    }
    
    try {
        const res = await fetch('api/pharmacist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_dispense_session',
                user_id: userId
            })
        });
        const data = await res.json();
        
        if (data.success && data.session_id) {
            // Redirect to pharmacy dispense page with session
            window.location.href = `pharmacy.php?tab=dispense&session_id=${data.session_id}`;
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'ไม่สามารถสร้าง session ได้'));
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}

// ===== Dispense Modal (Legacy) =====
let dispenseItems = [];
let allDrugsLoaded = false;
let allDrugsData = [];

function openDispenseModal() {
    document.getElementById('dispenseModal').classList.remove('hidden');
    dispenseItems = [];
    renderDispenseItems();
    loadDrugsForDispense();
}

function closeDispenseModal() {
    document.getElementById('dispenseModal').classList.add('hidden');
    dispenseItems = [];
}

async function loadDrugsForDispense() {
    if (allDrugsLoaded) return;
    
    const searchInput = document.getElementById('dispenseSearch');
    searchInput.placeholder = 'กำลังโหลดรายการ...';
    searchInput.disabled = true;
    
    try {
        const res = await fetch('api/pharmacist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_drugs' })
        });
        const data = await res.json();
        if (data.success) {
            allDrugsData = data.drugs || [];
            allDrugsLoaded = true;
            searchInput.placeholder = `พิมพ์ชื่อยาหรือสินค้า... (${allDrugsData.length} รายการ)`;
            searchInput.disabled = false;
        }
    } catch (e) {
        searchInput.placeholder = 'เกิดข้อผิดพลาด';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('dispenseSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.trim().toLowerCase();
            const resultsDiv = document.getElementById('dispenseSearchResults');
            
            if (query.length < 1) {
                resultsDiv.classList.add('hidden');
                return;
            }
            
            const results = allDrugsData.filter(drug => {
                const name = (drug.name || '').toLowerCase();
                const genericName = (drug.generic_name || '').toLowerCase();
                return name.includes(query) || genericName.includes(query);
            }).slice(0, 10);
            
            if (results.length === 0) {
                resultsDiv.innerHTML = '<div class="p-3 text-gray-400 text-center text-sm">ไม่พบรายการ</div>';
            } else {
                resultsDiv.innerHTML = results.map(drug => `
                    <div class="p-3 hover:bg-gray-50 cursor-pointer border-b last:border-0" onclick='addDispenseItem(${JSON.stringify({id: drug.id, name: drug.name, price: drug.price || 0})})'>
                        <div class="font-medium text-sm">${drug.name}</div>
                        ${drug.generic_name ? `<div class="text-xs text-cyan-600">${drug.generic_name}</div>` : ''}
                        <div class="text-xs text-gray-500">฿${drug.price || 0}</div>
                    </div>
                `).join('');
            }
            resultsDiv.classList.remove('hidden');
        });
    }
});

function addDispenseItem(drug) {
    if (dispenseItems.find(d => d.id === drug.id)) {
        alert('รายการนี้ถูกเลือกแล้ว');
        return;
    }
    dispenseItems.push({ ...drug, quantity: 1 });
    document.getElementById('dispenseSearch').value = '';
    document.getElementById('dispenseSearchResults').classList.add('hidden');
    renderDispenseItems();
}

function removeDispenseItem(idx) {
    dispenseItems.splice(idx, 1);
    renderDispenseItems();
}

function updateDispenseQty(idx, qty) {
    dispenseItems[idx].quantity = Math.max(1, parseInt(qty) || 1);
    renderDispenseItems();
}

function renderDispenseItems() {
    const container = document.getElementById('dispenseSelectedItems');
    const totalSection = document.getElementById('dispenseTotalSection');
    
    if (dispenseItems.length === 0) {
        container.innerHTML = '<p class="text-gray-400 text-center py-4 text-sm">ยังไม่ได้เลือกรายการ</p>';
        totalSection.classList.add('hidden');
        return;
    }
    
    let total = 0;
    container.innerHTML = dispenseItems.map((item, idx) => {
        const subtotal = (item.price || 0) * item.quantity;
        total += subtotal;
        return `
            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                <div class="flex-1">
                    <div class="font-medium text-sm">${item.name}</div>
                    <div class="text-xs text-gray-500">฿${item.price} x ${item.quantity} = ฿${subtotal.toLocaleString()}</div>
                </div>
                <input type="number" min="1" value="${item.quantity}" onchange="updateDispenseQty(${idx}, this.value)" 
                       class="w-16 px-2 py-1 border rounded text-center text-sm">
                <button onclick="removeDispenseItem(${idx})" class="text-red-400 hover:text-red-600 p-1">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
    }).join('');
    
    document.getElementById('dispenseTotal').textContent = '฿' + total.toLocaleString();
    totalSection.classList.remove('hidden');
}

async function submitDispense() {
    if (dispenseItems.length === 0) {
        alert('กรุณาเลือกรายการอย่างน้อย 1 รายการ');
        return;
    }
    
    if (!confirm('ยืนยันเพิ่มรายการลงตะกร้าลูกค้า?')) return;
    
    try {
        const res = await fetch('api/pharmacist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_to_cart_direct',
                user_id: userId,
                items: dispenseItems.map(item => ({
                    product_id: item.id,
                    quantity: item.quantity
                })),
                note: document.getElementById('dispenseNote').value
            })
        });
        const data = await res.json();
        
        if (data.success) {
            alert('เพิ่มลงตะกร้าลูกค้าเรียบร้อยแล้ว');
            closeDispenseModal();
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}

// Close dispense search results when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('#dispenseSearch') && !e.target.closest('#dispenseSearchResults')) {
        document.getElementById('dispenseSearchResults')?.classList.add('hidden');
    }
});

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
    // Requirements: 8.1, 8.2, 8.4 - Keyboard shortcuts
    
    // Escape to close modals - Requirements: 8.4
    if (e.key === 'Escape') {
        e.preventDefault();
        closeQuickReplyModal();
        closeAIPanel();
        return;
    }
    
    // Enter to send (without Shift) - Requirements: 8.1
    if (e.key === 'Enter' && !e.shiftKey) {
        // If quick reply modal is open, select the highlighted item
        if (!document.getElementById('quickReplyModal').classList.contains('hidden')) {
            e.preventDefault();
            selectHighlightedQuickReply();
            return;
        }
        e.preventDefault();
        document.getElementById('sendForm').dispatchEvent(new Event('submit'));
        return;
    }
    
    // Shift+Enter for newline - Requirements: 8.2 (default behavior, no action needed)
    
    // Arrow keys for quick reply navigation
    if (!document.getElementById('quickReplyModal').classList.contains('hidden')) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            navigateQuickReply(1);
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            navigateQuickReply(-1);
            return;
        }
    }
}

// ===== Quick Reply Functions - Requirements: 2.1, 2.2, 2.3 =====
let quickReplyTemplates = [];
let filteredTemplates = [];
let selectedQuickReplyIndex = 0;
let quickReplyLoaded = false;

// ===== Product Search Variables =====
let productSearchResults = [];
let selectedProductIndex = 0;

// Handle message input for "/" trigger - Requirements: 2.1
function handleMessageInput(textarea) {
    const value = textarea.value;
    
    // Check for AI command: /ai or /ช่วย
    if (value.match(/^\/ai\s*$/i) || value.match(/^\/ช่วย\s*$/)) {
        textarea.value = '';
        generateAIReply();
        return;
    }
    
    // Check for Product command: /p or /สินค้า
    const productMatch = value.match(/^\/(p|สินค้า)\s+(.+)$/i);
    if (productMatch) {
        const searchQuery = productMatch[2].trim();
        if (searchQuery.length >= 2) {
            textarea.value = '';
            openProductSearchModal(searchQuery);
            return;
        }
    }
    
    // Open product modal with empty search
    if (value.match(/^\/(p|สินค้า)\s*$/i)) {
        textarea.value = '';
        openProductSearchModal('');
        return;
    }
    
    // Check if user typed "/" at the start or after a space/newline
    if (value === '/' || value.endsWith(' /') || value.endsWith('\n/')) {
        openQuickReplyModal();
    }
}

// Open quick reply modal - Requirements: 2.1
async function openQuickReplyModal() {
    const modal = document.getElementById('quickReplyModal');
    modal.classList.remove('hidden');
    
    // Focus search input
    setTimeout(() => {
        document.getElementById('quickReplySearch').focus();
    }, 100);
    
    // Load templates if not loaded
    if (!quickReplyLoaded) {
        await loadQuickReplyTemplates();
    } else {
        renderQuickReplyList(quickReplyTemplates);
    }
}

// Close quick reply modal
function closeQuickReplyModal() {
    document.getElementById('quickReplyModal').classList.add('hidden');
    document.getElementById('quickReplySearch').value = '';
    selectedQuickReplyIndex = 0;
    
    // Remove "/" from input if it was just typed
    const input = document.getElementById('messageInput');
    if (input.value === '/' || input.value.endsWith(' /') || input.value.endsWith('\n/')) {
        input.value = input.value.slice(0, -1);
    }
    
    // Focus back on message input
    input.focus();
}

// Load templates from API
async function loadQuickReplyTemplates() {
    const listContainer = document.getElementById('quickReplyList');
    listContainer.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังโหลด...</div>';
    
    try {
        const response = await fetch('api/inbox.php?action=get_templates');
        const data = await response.json();
        
        if (data.success && data.data) {
            quickReplyTemplates = data.data;
            filteredTemplates = [...quickReplyTemplates];
            quickReplyLoaded = true;
            renderQuickReplyList(quickReplyTemplates);
        } else {
            listContainer.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">ไม่พบ template</div>';
        }
    } catch (error) {
        console.error('Error loading templates:', error);
        listContainer.innerHTML = '<div class="p-4 text-center text-red-400 text-sm">เกิดข้อผิดพลาด</div>';
    }
}

// Filter quick replies - Requirements: 2.2
function filterQuickReplies(query) {
    query = query.toLowerCase().trim();
    
    if (!query) {
        filteredTemplates = [...quickReplyTemplates];
    } else {
        filteredTemplates = quickReplyTemplates.filter(t => 
            t.name.toLowerCase().includes(query) || 
            t.content.toLowerCase().includes(query) ||
            (t.category && t.category.toLowerCase().includes(query))
        );
    }
    
    selectedQuickReplyIndex = 0;
    renderQuickReplyList(filteredTemplates);
}

// Render quick reply list
function renderQuickReplyList(templates) {
    const listContainer = document.getElementById('quickReplyList');
    
    // Add AI option at the top
    let aiOption = `
        <div class="quick-reply-item ai-option ${selectedQuickReplyIndex === -1 ? 'selected' : ''}" 
             data-index="-1"
             onclick="triggerAIReply()"
             onmouseenter="highlightQuickReply(-1)"
             style="background: linear-gradient(135deg, #EEF2FF 0%, #E0E7FF 100%); border-left: 3px solid #6366F1;">
            <div class="quick-reply-name" style="color: #4F46E5;"><i class="fas fa-robot mr-2"></i>AI ช่วยคิดคำตอบ</div>
            <div class="quick-reply-preview" style="color: #6B7280;">ให้ AI วิเคราะห์ข้อความลูกค้าและแนะนำคำตอบที่เหมาะสม</div>
            <div class="quick-reply-meta">
                <span style="color: #6366F1;"><i class="fas fa-magic"></i> พิมพ์ /ai หรือ /ช่วย</span>
            </div>
        </div>
    `;
    
    // Add Product Search option
    let productOption = `
        <div class="quick-reply-item product-option ${selectedQuickReplyIndex === -2 ? 'selected' : ''}" 
             data-index="-2"
             onclick="openProductSearchModal('')"
             onmouseenter="highlightQuickReply(-2)"
             style="background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-left: 3px solid #10B981;">
            <div class="quick-reply-name" style="color: #059669;"><i class="fas fa-box mr-2"></i>ค้นหาสินค้า</div>
            <div class="quick-reply-preview" style="color: #6B7280;">ค้นหาสินค้าด้วย SKU หรือชื่อ แล้วส่งข้อมูลพร้อมราคาให้ลูกค้า</div>
            <div class="quick-reply-meta">
                <span style="color: #10B981;"><i class="fas fa-search"></i> พิมพ์ /p หรือ /สินค้า</span>
            </div>
        </div>
    `;
    
    if (templates.length === 0) {
        listContainer.innerHTML = aiOption + productOption + '<div class="p-4 text-center text-gray-400 text-sm">ไม่พบ template ที่ตรงกัน</div>';
        return;
    }
    
    listContainer.innerHTML = aiOption + productOption + templates.map((t, index) => `
        <div class="quick-reply-item ${index === selectedQuickReplyIndex ? 'selected' : ''}" 
             data-index="${index}"
             onclick="selectQuickReply(${index})"
             onmouseenter="highlightQuickReply(${index})">
            <div class="quick-reply-name">${escapeHtml(t.name)}</div>
            <div class="quick-reply-preview">${escapeHtml(t.content.substring(0, 80))}${t.content.length > 80 ? '...' : ''}</div>
            <div class="quick-reply-meta">
                ${t.category ? `<span class="quick-reply-category">${escapeHtml(t.category)}</span>` : ''}
                <span><i class="fas fa-chart-bar"></i> ${t.usage_count || 0} ครั้ง</span>
                ${t.last_used_at ? `<span><i class="fas fa-clock"></i> ${formatThaiTimeJS(new Date(t.last_used_at))}</span>` : ''}
            </div>
        </div>
    `).join('');
}

// Trigger AI reply from quick reply modal
function triggerAIReply() {
    closeQuickReplyModal();
    generateAIReply();
}

// ===== Product Search Functions =====
async function openProductSearchModal(initialQuery = '') {
    closeQuickReplyModal();
    
    // Create modal if not exists
    let modal = document.getElementById('productSearchModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'productSearchModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-end sm:items-center justify-center';
        modal.onclick = function(e) {
            if (e.target === modal) closeProductSearchModal();
        };
        modal.innerHTML = `
            <div class="bg-white w-full sm:w-[500px] sm:max-h-[70vh] max-h-[60vh] rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col" onclick="event.stopPropagation()">
                <div class="p-4 border-b flex items-center justify-between bg-emerald-50 rounded-t-2xl">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-box text-emerald-600"></i>
                        <span class="font-semibold text-emerald-800">ค้นหาสินค้า</span>
                    </div>
                    <button type="button" class="text-gray-400 hover:text-gray-600 close-product-modal">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
                <div class="p-3 border-b">
                    <input type="text" id="productSearchInput" 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                           placeholder="พิมพ์ SKU หรือชื่อสินค้า...">
                </div>
                <div id="productSearchResults" class="flex-1 overflow-y-auto p-2">
                    <div class="p-4 text-center text-gray-400 text-sm">พิมพ์เพื่อค้นหาสินค้า</div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add event listeners
        modal.querySelector('.close-product-modal').addEventListener('click', closeProductSearchModal);
        modal.querySelector('#productSearchInput').addEventListener('input', function(e) {
            searchProducts(e.target.value);
        });
        modal.querySelector('#productSearchInput').addEventListener('keydown', handleProductSearchKeydown);
        
        // Setup event delegation for results
        setupProductResultsEvents();
    }
    
    modal.classList.remove('hidden');
    
    setTimeout(() => {
        const input = document.getElementById('productSearchInput');
        input.value = initialQuery;
        input.focus();
        if (initialQuery) {
            searchProducts(initialQuery);
        }
    }, 100);
}

function closeProductSearchModal() {
    const modal = document.getElementById('productSearchModal');
    if (modal) {
        modal.classList.add('hidden');
    }
    document.getElementById('messageInput')?.focus();
}

let productSearchTimeout = null;
async function searchProducts(query) {
    clearTimeout(productSearchTimeout);
    
    const resultsContainer = document.getElementById('productSearchResults');
    
    if (query.length < 2) {
        resultsContainer.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">พิมพ์อย่างน้อย 2 ตัวอักษร</div>';
        return;
    }
    
    resultsContainer.innerHTML = '<div class="p-4 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-2"></i>กำลังค้นหา...</div>';
    
    productSearchTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`api/inbox.php?action=search_products&q=${encodeURIComponent(query)}`);
            const data = await res.json();
            
            if (data.success && data.products && data.products.length > 0) {
                productSearchResults = data.products;
                selectedProductIndex = 0;
                renderProductResults();
            } else {
                resultsContainer.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">ไม่พบสินค้าที่ตรงกัน</div>';
            }
        } catch (err) {
            resultsContainer.innerHTML = '<div class="p-4 text-center text-red-400 text-sm">เกิดข้อผิดพลาด</div>';
        }
    }, 300);
}

function renderProductResults() {
    const resultsContainer = document.getElementById('productSearchResults');
    
    if (!productSearchResults || productSearchResults.length === 0) {
        resultsContainer.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">ไม่พบสินค้า</div>';
        return;
    }
    
    resultsContainer.innerHTML = productSearchResults.map((p, index) => {
        const name = p.name || '';
        const sku = p.sku || '';
        const price = Number(p.price || 0).toLocaleString();
        const imgUrl = p.image_url || '';
        
        return `
        <div class="product-item p-3 rounded-lg cursor-pointer hover:bg-emerald-50 ${index === selectedProductIndex ? 'bg-emerald-100' : ''}"
             data-product-index="${index}" style="pointer-events: auto;">
            <div class="flex gap-3" style="pointer-events: none;">
                <div class="w-16 h-16 bg-gray-100 rounded-lg flex-shrink-0 overflow-hidden">
                    ${imgUrl ? `<img src="${imgUrl}" class="w-full h-full object-cover" onerror="this.style.display='none'">` : '<div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-box text-2xl"></i></div>'}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-gray-800 truncate">${name}</div>
                    <div class="text-xs text-gray-500">${sku ? `SKU: ${sku}` : ''}</div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-emerald-600 font-semibold">฿${price}</span>
                    </div>
                </div>
            </div>
        </div>
        `;
    }).join('');
}

// Setup event delegation for product results (call once)
function setupProductResultsEvents() {
    const resultsContainer = document.getElementById('productSearchResults');
    if (!resultsContainer || resultsContainer.dataset.eventsAttached) return;
    
    resultsContainer.dataset.eventsAttached = 'true';
    
    resultsContainer.addEventListener('click', function(e) {
        const item = e.target.closest('.product-item');
        if (item) {
            const idx = parseInt(item.dataset.productIndex);
            if (!isNaN(idx)) {
                doSelectProduct(idx);
            }
        }
    });
    
    resultsContainer.addEventListener('mouseover', function(e) {
        const item = e.target.closest('.product-item');
        if (item) {
            const idx = parseInt(item.dataset.productIndex);
            if (!isNaN(idx) && idx !== selectedProductIndex) {
                selectedProductIndex = idx;
                // Just update highlight without re-rendering
                resultsContainer.querySelectorAll('.product-item').forEach((el, i) => {
                    el.classList.toggle('bg-emerald-100', i === idx);
                });
            }
        }
    });
}

function handleProductSearchKeydown(e) {
    if (e.key === 'Escape') {
        closeProductSearchModal();
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedProductIndex = Math.min(selectedProductIndex + 1, productSearchResults.length - 1);
        updateProductHighlight();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedProductIndex = Math.max(selectedProductIndex - 1, 0);
        updateProductHighlight();
    } else if (e.key === 'Enter' && productSearchResults.length > 0) {
        e.preventDefault();
        doSelectProduct(selectedProductIndex);
    }
}

function updateProductHighlight() {
    const resultsContainer = document.getElementById('productSearchResults');
    if (!resultsContainer) return;
    resultsContainer.querySelectorAll('.product-item').forEach((el, i) => {
        el.classList.toggle('bg-emerald-100', i === selectedProductIndex);
    });
}

function doSelectProduct(index) {
    const product = productSearchResults[index];
    if (!product) return;
    
    closeProductSearchModal();
    
    // Show send options modal
    showProductSendOptions(product);
}

function showProductSendOptions(product) {
    // Create modal if not exists
    let modal = document.getElementById('productSendModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'productSendModal';
        modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center';
        modal.onclick = function(e) {
            if (e.target === modal) closeProductSendModal();
        };
        document.body.appendChild(modal);
    }
    
    const imgUrl = product.image_url || '';
    const price = Number(product.price || 0).toLocaleString();
    
    modal.innerHTML = `
        <div class="bg-white w-[90%] max-w-md rounded-2xl shadow-2xl overflow-hidden" onclick="event.stopPropagation()">
            <div class="p-4 border-b bg-emerald-50">
                <div class="flex items-center justify-between">
                    <span class="font-semibold text-emerald-800"><i class="fas fa-paper-plane mr-2"></i>ส่งข้อมูลสินค้า</span>
                    <button onclick="closeProductSendModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Product Preview -->
            <div class="p-4 border-b">
                <div class="flex gap-3">
                    <div class="w-20 h-20 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                        ${imgUrl ? `<img src="${imgUrl}" class="w-full h-full object-cover">` : '<div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-box text-3xl"></i></div>'}
                    </div>
                    <div>
                        <div class="font-medium">${product.name}</div>
                        <div class="text-sm text-gray-500">${product.sku || ''}</div>
                        <div class="text-emerald-600 font-semibold mt-1">฿${price}</div>
                    </div>
                </div>
            </div>
            
            <!-- Send Options -->
            <div class="p-4 space-y-3">
                <button onclick="sendProductAsText()" class="w-full p-3 border-2 border-gray-200 rounded-xl hover:border-emerald-500 hover:bg-emerald-50 transition flex items-center gap-3">
                    <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-font text-gray-600"></i>
                    </div>
                    <div class="text-left">
                        <div class="font-medium">ส่งเป็นข้อความ</div>
                        <div class="text-xs text-gray-500">ข้อความธรรมดา ไม่มีรูป</div>
                    </div>
                </button>
                
                ${imgUrl ? `
                <button onclick="sendProductAsFlex()" class="w-full p-3 border-2 border-emerald-500 bg-emerald-50 rounded-xl hover:bg-emerald-100 transition flex items-center gap-3">
                    <div class="w-10 h-10 bg-emerald-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-image text-white"></i>
                    </div>
                    <div class="text-left">
                        <div class="font-medium text-emerald-700">ส่งเป็น Flex (แนะนำ)</div>
                        <div class="text-xs text-emerald-600">รูป + ข้อมูล + ปุ่มสั่งซื้อ ใน 1 ข้อความ</div>
                    </div>
                </button>
                ` : ''}
            </div>
        </div>
    `;
    
    // Store product for later use
    window.selectedProductToSend = product;
    modal.classList.remove('hidden');
}

function closeProductSendModal() {
    const modal = document.getElementById('productSendModal');
    if (modal) modal.classList.add('hidden');
}

function sendProductAsText() {
    const product = window.selectedProductToSend;
    if (!product) return;
    
    closeProductSendModal();
    
    const message = `📦 ${product.name}\n` +
        (product.sku ? `รหัส: ${product.sku}\n` : '') +
        `💰 ราคา: ฿${Number(product.price || 0).toLocaleString()}` +
        (product.description ? `\n📝 ${product.description.substring(0, 100)}${product.description.length > 100 ? '...' : ''}` : '');
    
    const input = document.getElementById('messageInput');
    if (input) {
        input.value = message;
        input.focus();
        autoResize(input);
    }
}

async function sendProductAsFlex() {
    const product = window.selectedProductToSend;
    if (!product || !userId) return;
    
    closeProductSendModal();
    
    // Show sending indicator
    showToast('กำลังส่ง Flex Message...', 'info');
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_product_flex');
        formData.append('user_id', userId);
        formData.append('product_id', product.id);
        
        const res = await fetch('api/inbox.php', {
            method: 'POST',
            body: formData
        });
        
        // Debug: get raw text first
        const text = await res.text();
        console.log('Flex API Response:', text);
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            showToast('API Error: ' + text.substring(0, 100), 'error');
            return;
        }
        
        if (data.success) {
            showToast('ส่ง Flex Message สำเร็จ', 'success');
            // Refresh messages
            if (typeof pollMessages === 'function') pollMessages();
        } else {
            showToast('Error: ' + (data.error || 'ส่งไม่สำเร็จ'), 'error');
        }
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

// Keep for backward compatibility
window.selectProduct = doSelectProduct;
// Highlight quick reply on hover
function highlightQuickReply(index) {
    selectedQuickReplyIndex = index;
    updateQuickReplyHighlight();
}

// Navigate quick reply with arrow keys
function navigateQuickReply(direction) {
    const maxIndex = filteredTemplates.length - 1;
    selectedQuickReplyIndex += direction;
    
    if (selectedQuickReplyIndex < 0) selectedQuickReplyIndex = maxIndex;
    if (selectedQuickReplyIndex > maxIndex) selectedQuickReplyIndex = 0;
    
    updateQuickReplyHighlight();
    
    // Scroll into view
    const selectedItem = document.querySelector('.quick-reply-item.selected');
    if (selectedItem) {
        selectedItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Update highlight styling
function updateQuickReplyHighlight() {
    document.querySelectorAll('.quick-reply-item').forEach((item, index) => {
        item.classList.toggle('selected', index === selectedQuickReplyIndex);
    });
}

// Select highlighted quick reply (on Enter)
function selectHighlightedQuickReply() {
    if (filteredTemplates.length > 0 && selectedQuickReplyIndex < filteredTemplates.length) {
        selectQuickReply(selectedQuickReplyIndex);
    }
}

// Select quick reply and fill placeholders - Requirements: 2.2, 2.3
async function selectQuickReply(index) {
    const template = filteredTemplates[index];
    if (!template) return;
    
    // Get customer data for placeholder filling - Requirements: 2.3
    const customerData = {
        name: currentUserName || '',
        phone: '<?= $selectedUser ? ($selectedUser['phone'] ?? '') : '' ?>',
        email: '<?= $selectedUser ? ($selectedUser['email'] ?? '') : '' ?>',
        order_id: '' // Could be filled from recent orders
    };
    
    try {
        // Call API to fill placeholders and record usage
        const formData = new FormData();
        formData.append('action', 'use_template');
        formData.append('id', template.id);
        formData.append('customer_data', JSON.stringify(customerData));
        
        const response = await fetch('api/inbox.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success && data.data) {
            // Insert filled content into message input
            const input = document.getElementById('messageInput');
            
            // Remove the "/" trigger if present
            let currentValue = input.value;
            if (currentValue === '/' || currentValue.endsWith(' /') || currentValue.endsWith('\n/')) {
                currentValue = currentValue.slice(0, -1);
            }
            
            input.value = currentValue + data.data.content;
            autoResize(input);
            
            // Update usage count in local cache
            template.usage_count = (template.usage_count || 0) + 1;
            template.last_used_at = new Date().toISOString();
        } else {
            // Fallback: use original content
            const input = document.getElementById('messageInput');
            let currentValue = input.value;
            if (currentValue === '/' || currentValue.endsWith(' /') || currentValue.endsWith('\n/')) {
                currentValue = currentValue.slice(0, -1);
            }
            input.value = currentValue + template.content;
            autoResize(input);
        }
    } catch (error) {
        console.error('Error using template:', error);
        // Fallback: use original content
        const input = document.getElementById('messageInput');
        let currentValue = input.value;
        if (currentValue === '/' || currentValue.endsWith(' /') || currentValue.endsWith('\n/')) {
            currentValue = currentValue.slice(0, -1);
        }
        input.value = currentValue + template.content;
        autoResize(input);
    }
    
    closeQuickReplyModal();
}

// Handle keydown in quick reply search
function handleQuickReplyKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        navigateQuickReply(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        navigateQuickReply(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        selectHighlightedQuickReply();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        closeQuickReplyModal();
    }
}

// Open template manager (placeholder for future implementation)
function openTemplateManager() {
    // Could open a modal or redirect to template management page
    showToast('จัดการ Templates - Coming soon!', 'info');
}

// ===== Image Error Handling - Requirements: 7.2, 9.4 =====
function handleImageError(img) {
    // Mark as error
    img.classList.add('image-error');
    img.style.display = 'none';
    
    // Create placeholder
    const placeholder = document.createElement('div');
    placeholder.className = 'image-error-placeholder rounded-xl max-w-[200px] border shadow-sm';
    placeholder.innerHTML = `
        <i class="fas fa-image"></i>
        <span>รูปภาพหมดอายุ<br>หรือไม่สามารถโหลดได้</span>
    `;
    
    // Insert placeholder after the image
    img.parentNode.insertBefore(placeholder, img.nextSibling);
}

// Mobile-optimized image loading - Requirements: 9.4
function optimizeImageForMobile(img) {
    // Add loading class for animation
    img.classList.add('blur-load');
    
    // Create intersection observer for lazy loading
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const image = entry.target;
                const src = image.dataset.src || image.src;
                
                // For mobile, try to load thumbnail first if available
                if (isMobileDevice() && src.includes('line_content.php')) {
                    // Add thumbnail parameter for mobile
                    const thumbnailSrc = src + (src.includes('?') ? '&' : '?') + 'thumb=1';
                    
                    // Try thumbnail first
                    const tempImg = new Image();
                    tempImg.onload = function() {
                        image.src = thumbnailSrc;
                        image.classList.add('loaded');
                        image.classList.remove('blur-load');
                    };
                    tempImg.onerror = function() {
                        // Fallback to original
                        image.src = src;
                        image.classList.add('loaded');
                        image.classList.remove('blur-load');
                    };
                    tempImg.src = thumbnailSrc;
                } else {
                    // Desktop or external images - load normally
                    image.onload = function() {
                        image.classList.add('loaded');
                        image.classList.remove('blur-load');
                    };
                    if (image.dataset.src) {
                        image.src = image.dataset.src;
                    }
                }
                
                observer.unobserve(image);
            }
        });
    }, {
        rootMargin: '50px 0px',
        threshold: 0.1
    });
    
    observer.observe(img);
}

// Initialize lazy loading for all chat images
function initializeLazyImages() {
    document.querySelectorAll('.chat-image').forEach(img => {
        if (!img.classList.contains('loaded') && !img.classList.contains('image-error')) {
            optimizeImageForMobile(img);
        }
    });
}

// Call on page load and after new messages
document.addEventListener('DOMContentLoaded', initializeLazyImages);

// Re-initialize when new messages are added
const chatBoxObserver = new MutationObserver((mutations) => {
    mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
            if (node.nodeType === 1) {
                const images = node.querySelectorAll ? node.querySelectorAll('.chat-image') : [];
                images.forEach(img => optimizeImageForMobile(img));
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const chatBox = document.getElementById('chatBox');
    if (chatBox) {
        chatBoxObserver.observe(chatBox, { childList: true, subtree: true });
    }
});

// ===== Message Pagination - Requirements: 11.3 =====
let messageCurrentPage = 1;
let messageHasMore = true;
let isLoadingMessages = false;
const messagesPerPage = 50;

// Initialize pagination on page load
function initMessagePagination() {
    // Check if there might be more messages
    const chatBox = document.getElementById('chatBox');
    const messageCount = chatBox.querySelectorAll('.message-item').length;
    
    if (messageCount >= messagesPerPage) {
        messageHasMore = true;
        document.getElementById('loadMoreContainer').classList.remove('hidden');
    }
}

// Load more messages
async function loadMoreMessages() {
    if (isLoadingMessages || !messageHasMore || !userId) return;
    
    isLoadingMessages = true;
    const btn = document.getElementById('loadMoreBtn');
    const spinner = document.getElementById('loadMoreSpinner');
    
    btn.classList.add('hidden');
    spinner.classList.remove('hidden');
    
    try {
        messageCurrentPage++;
        const response = await fetch(`api/inbox.php?action=get_messages&user_id=${userId}&page=${messageCurrentPage}&limit=${messagesPerPage}`);
        const data = await response.json();
        
        if (data.success && data.data) {
            const messages = data.data.messages || [];
            messageHasMore = data.data.has_more;
            
            if (messages.length > 0) {
                // Get the first message element to scroll to after prepending
                const chatBox = document.getElementById('chatBox');
                const firstMessage = chatBox.querySelector('.message-item');
                const scrollHeightBefore = chatBox.scrollHeight;
                
                // Prepend messages (they come in DESC order, so reverse)
                messages.reverse().forEach(msg => {
                    if (!document.querySelector(`[data-msg-id="${msg.id}"]`)) {
                        prependMessage(msg);
                    }
                });
                
                // Maintain scroll position
                const scrollHeightAfter = chatBox.scrollHeight;
                chatBox.scrollTop = scrollHeightAfter - scrollHeightBefore;
            }
            
            if (!messageHasMore) {
                document.getElementById('loadMoreContainer').classList.add('hidden');
            }
        }
    } catch (error) {
        console.error('Error loading more messages:', error);
        showToast('เกิดข้อผิดพลาดในการโหลดข้อความ', 'error');
    } finally {
        isLoadingMessages = false;
        btn.classList.remove('hidden');
        spinner.classList.add('hidden');
    }
}

// Prepend message to chat (for pagination)
function prependMessage(msg) {
    const chatBox = document.getElementById('chatBox');
    const loadMoreContainer = document.getElementById('loadMoreContainer');
    const isMe = msg.direction === 'outgoing';
    
    const div = document.createElement('div');
    div.className = `message-item flex ${isMe ? 'justify-end' : 'justify-start'} group`;
    div.dataset.msgId = msg.id;
    
    let contentHtml = '';
    const content = msg.content || '';
    const type = msg.message_type || 'text';
    
    if (type === 'text') {
        contentHtml = `<div class="chat-bubble px-4 py-2.5 text-sm shadow-sm ${isMe ? 'chat-outgoing' : 'chat-incoming'}">${escapeHtml(content).replace(/\n/g, '<br>')}</div>`;
    } else if (type === 'image') {
        let imgSrc = content;
        const match = content.match(/ID:\s*(\d+)/);
        if (match) {
            imgSrc = 'api/line_content.php?id=' + match[1];
        } else if (!content.match(/^https?:\/\//)) {
            imgSrc = 'api/line_content.php?id=' + content;
        }
        contentHtml = `<img src="${imgSrc}" class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90 chat-image" onclick="openImage(this.src)" onerror="handleImageError(this)" data-original-src="${imgSrc}" loading="lazy">`;
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
    
    // Build sender badge
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
        ${!isMe ? `<img src="${currentUserPic}" class="w-7 h-7 rounded-full self-end mr-2">` : ''}
        <div class="flex flex-col ${isMe ? 'items-end' : 'items-start'}" style="max-width:70%">
            ${contentHtml}
            <div class="msg-meta ${isMe ? '' : 'incoming'}">
                <span>${time}</span>
                ${senderBadge}
                ${readStatus}
            </div>
        </div>
    `;
    
    // Insert after load more container
    if (loadMoreContainer && loadMoreContainer.nextSibling) {
        chatBox.insertBefore(div, loadMoreContainer.nextSibling);
    } else {
        chatBox.insertBefore(div, chatBox.firstChild);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (userId) {
        initMessagePagination();
    }
});

// ===== Search & Filter Functions - Requirements: 5.1, 5.2, 5.3, 5.4, 11.7 =====
let searchDebounceTimer = null;
let currentFilters = {
    search: '',
    status: '',
    tag_id: '',
    assigned_to: '',
    date_from: '',
    date_to: ''
};
let currentPage = 1;
let isLoadingMore = false;
let hasMoreConversations = true;
const adminId = <?= $_SESSION['admin_id'] ?? 'null' ?>;

// Debounced search function (300ms delay) - Requirements: 11.7
function debouncedSearch(query) {
    const spinner = document.getElementById('searchSpinner');
    
    // Clear previous timer
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }
    
    // Show spinner
    if (query.length > 0) {
        spinner.classList.remove('hidden');
    }
    
    // Set new timer with 300ms delay
    searchDebounceTimer = setTimeout(() => {
        currentFilters.search = query;
        currentPage = 1;
        loadConversations(true);
    }, 300);
}

// Apply filters from dropdowns
function applyFilters() {
    currentFilters.status = document.getElementById('filterStatus').value;
    currentFilters.tag_id = document.getElementById('filterTag').value;
    
    const assignedValue = document.getElementById('filterAssigned').value;
    if (assignedValue === 'me' && adminId) {
        currentFilters.assigned_to = adminId;
    } else {
        currentFilters.assigned_to = assignedValue;
    }
    
    currentFilters.date_from = document.getElementById('filterDateFrom').value;
    currentFilters.date_to = document.getElementById('filterDateTo').value;
    
    currentPage = 1;
    loadConversations(true);
    updateActiveFiltersDisplay();
}

// Toggle date filter picker
function toggleDateFilter() {
    const picker = document.getElementById('dateRangePicker');
    picker.classList.toggle('hidden');
}

// Clear date filter
function clearDateFilter() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('dateFilterLabel').textContent = 'วันที่';
    applyFilters();
}

// Update active filters display
function updateActiveFiltersDisplay() {
    const container = document.getElementById('activeFilters');
    const badges = [];
    
    if (currentFilters.status) {
        const statusLabels = { unread: 'ยังไม่อ่าน', assigned: 'มอบหมายแล้ว', resolved: 'แก้ไขแล้ว' };
        badges.push(`<span class="text-[9px] px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded-full flex items-center gap-1">${statusLabels[currentFilters.status] || currentFilters.status}<button onclick="clearFilter('status')" class="ml-1 hover:text-red-500">&times;</button></span>`);
    }
    
    if (currentFilters.tag_id) {
        const tagSelect = document.getElementById('filterTag');
        const tagName = tagSelect.options[tagSelect.selectedIndex].text;
        badges.push(`<span class="text-[9px] px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full flex items-center gap-1">${tagName}<button onclick="clearFilter('tag_id')" class="ml-1 hover:text-red-500">&times;</button></span>`);
    }
    
    if (currentFilters.assigned_to) {
        const assignSelect = document.getElementById('filterAssigned');
        const assignName = assignSelect.options[assignSelect.selectedIndex].text;
        badges.push(`<span class="text-[9px] px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full flex items-center gap-1">${assignName}<button onclick="clearFilter('assigned_to')" class="ml-1 hover:text-red-500">&times;</button></span>`);
    }
    
    if (currentFilters.date_from || currentFilters.date_to) {
        const dateLabel = currentFilters.date_from && currentFilters.date_to 
            ? `${currentFilters.date_from} - ${currentFilters.date_to}`
            : currentFilters.date_from || currentFilters.date_to;
        badges.push(`<span class="text-[9px] px-2 py-0.5 bg-orange-100 text-orange-700 rounded-full flex items-center gap-1">${dateLabel}<button onclick="clearFilter('date')" class="ml-1 hover:text-red-500">&times;</button></span>`);
        document.getElementById('dateFilterLabel').textContent = dateLabel.length > 10 ? dateLabel.substring(0, 10) + '...' : dateLabel;
    }
    
    if (badges.length > 0) {
        container.innerHTML = badges.join('');
        container.classList.remove('hidden');
    } else {
        container.classList.add('hidden');
    }
}

// Clear specific filter
function clearFilter(filterName) {
    if (filterName === 'status') {
        document.getElementById('filterStatus').value = '';
        currentFilters.status = '';
    } else if (filterName === 'tag_id') {
        document.getElementById('filterTag').value = '';
        currentFilters.tag_id = '';
    } else if (filterName === 'assigned_to') {
        document.getElementById('filterAssigned').value = '';
        currentFilters.assigned_to = '';
    } else if (filterName === 'date') {
        clearDateFilter();
        return;
    }
    
    currentPage = 1;
    loadConversations(true);
    updateActiveFiltersDisplay();
}

// Load conversations from API
async function loadConversations(replace = false) {
    const userList = document.getElementById('userList');
    const spinner = document.getElementById('searchSpinner');
    const loadingMore = document.getElementById('loadingMore');
    
    if (isLoadingMore) return;
    isLoadingMore = true;
    
    if (loadingMore) loadingMore.classList.remove('hidden');
    
    try {
        const params = new URLSearchParams({
            action: 'get_conversations',
            page: currentPage,
            limit: 50,
            line_account_id: lineAccountId
        });
        
        if (currentFilters.search) params.append('search', currentFilters.search);
        if (currentFilters.status) params.append('status', currentFilters.status);
        if (currentFilters.tag_id) params.append('tag_id', currentFilters.tag_id);
        if (currentFilters.assigned_to) params.append('assigned_to', currentFilters.assigned_to);
        if (currentFilters.date_from) params.append('date_from', currentFilters.date_from);
        if (currentFilters.date_to) params.append('date_to', currentFilters.date_to);
        
        const response = await fetch(`api/inbox.php?${params.toString()}`);
        const data = await response.json();
        
        if (data.success && data.data) {
            const conversations = data.data.conversations || [];
            hasMoreConversations = currentPage < data.data.total_pages;
            
            if (replace) {
                // Clear existing items except sentinel
                const sentinel = document.getElementById('scrollSentinel');
                userList.innerHTML = '';
                if (sentinel) userList.appendChild(sentinel);
            }
            
            if (conversations.length === 0 && replace) {
                userList.innerHTML = `
                    <div class="p-6 text-center text-gray-400">
                        <i class="fas fa-search text-4xl mb-2"></i>
                        <p class="text-sm">ไม่พบผลลัพธ์</p>
                    </div>
                `;
            } else {
                const sentinel = document.getElementById('scrollSentinel');
                conversations.forEach((conv, index) => {
                    const item = createConversationItem(conv, (currentPage - 1) * 50 + index);
                    if (sentinel) {
                        userList.insertBefore(item, sentinel);
                    } else {
                        userList.appendChild(item);
                    }
                });
                
                // Re-add sentinel if needed
                if (!document.getElementById('scrollSentinel')) {
                    const newSentinel = document.createElement('div');
                    newSentinel.id = 'scrollSentinel';
                    newSentinel.className = 'h-10 flex items-center justify-center';
                    newSentinel.innerHTML = '<span id="loadingMore" class="hidden text-xs text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังโหลด...</span>';
                    userList.appendChild(newSentinel);
                    setupIntersectionObserver();
                }
            }
            
            // Update total count
            document.getElementById('totalUnread').textContent = data.data.total || 0;
        }
    } catch (error) {
        console.error('Error loading conversations:', error);
    } finally {
        isLoadingMore = false;
        if (spinner) spinner.classList.add('hidden');
        if (loadingMore) loadingMore.classList.add('hidden');
    }
}

// Create conversation item HTML element
function createConversationItem(conv, index) {
    const a = document.createElement('a');
    a.href = `?user=${conv.id}`;
    a.className = `user-item block p-3 border-b border-gray-50 ${conv.unread_count > 0 ? '' : ''} ${conv.sla_warning ? 'sla-warning' : ''}`;
    a.dataset.userId = conv.id;
    a.dataset.name = (conv.display_name || '').toLowerCase();
    a.dataset.index = index;
    
    // Calculate time since last message
    let timeSince = '';
    if (conv.last_message_at) {
        const lastTime = new Date(conv.last_message_at);
        const now = new Date();
        const seconds = Math.floor((now - lastTime) / 1000);
        
        if (seconds < 60) timeSince = seconds + ' วินาที';
        else if (seconds < 3600) timeSince = Math.floor(seconds / 60) + ' นาที';
        else if (seconds < 86400) timeSince = Math.floor(seconds / 3600) + ' ชม.';
        else timeSince = Math.floor(seconds / 86400) + ' วัน';
    }
    
    // Format last message time
    const lastTimeFormatted = conv.last_message_at ? formatThaiTimeJS(new Date(conv.last_message_at)) : '';
    
    // Get message preview
    let msgPreview = conv.last_message_content || '';
    if (conv.last_message_direction === 'image') msgPreview = '📷 รูปภาพ';
    else if (conv.last_message_direction === 'sticker') msgPreview = '😊 สติกเกอร์';
    else if (msgPreview.length > 30) msgPreview = msgPreview.substring(0, 30) + '...';
    
    a.innerHTML = `
        <div class="flex items-center gap-3">
            <div class="relative flex-shrink-0">
                <img src="${conv.picture_url || 'https://via.placeholder.com/40'}" 
                     class="w-10 h-10 rounded-full object-cover border-2 border-white shadow"
                     loading="lazy">
                ${conv.unread_count > 0 ? `
                <div class="unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold">
                    ${conv.unread_count > 9 ? '9+' : conv.unread_count}
                </div>` : ''}
                ${conv.sla_warning ? `
                <div class="sla-badge absolute -bottom-1 -right-1 bg-orange-500 text-white text-[8px] w-4 h-4 flex items-center justify-center rounded-full" title="เกิน SLA">
                    <i class="fas fa-exclamation"></i>
                </div>` : ''}
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-baseline">
                    <h3 class="text-sm font-semibold text-gray-800 truncate">${escapeHtml(conv.display_name || 'Unknown')}</h3>
                    <span class="last-time text-[10px] text-gray-400">${lastTimeFormatted}</span>
                </div>
                <p class="last-msg text-xs text-gray-500 truncate">${escapeHtml(msgPreview)}</p>
                <div class="flex items-center gap-2 mt-1">
                    ${conv.assigned_admin_name ? `
                    <span class="assignment-badge text-[9px] px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded-full flex items-center gap-1">
                        <i class="fas fa-user-check"></i>
                        ${escapeHtml(conv.assigned_admin_name)}
                    </span>` : ''}
                    ${timeSince ? `
                    <span class="time-since text-[9px] text-gray-400 flex items-center gap-1" title="เวลาตั้งแต่ข้อความล่าสุด">
                        <i class="fas fa-clock"></i>
                        ${timeSince}
                    </span>` : ''}
                </div>
            </div>
        </div>
    `;
    
    return a;
}

// Virtual Scrolling with Intersection Observer - Requirements: 11.2
let intersectionObserver = null;

function setupIntersectionObserver() {
    if (intersectionObserver) {
        intersectionObserver.disconnect();
    }
    
    const sentinel = document.getElementById('scrollSentinel');
    if (!sentinel) return;
    
    intersectionObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && hasMoreConversations && !isLoadingMore) {
                currentPage++;
                loadConversations(false);
            }
        });
    }, {
        root: document.getElementById('userList'),
        rootMargin: '100px',
        threshold: 0.1
    });
    
    intersectionObserver.observe(sentinel);
}

// Initialize intersection observer on page load
document.addEventListener('DOMContentLoaded', function() {
    setupIntersectionObserver();
});

// Legacy filter function (for backward compatibility)
function filterUsers(query) {
    debouncedSearch(query);
}

function togglePanel() {
    const panel = document.getElementById('customerPanel');
    
    // Use mobile-specific toggle on mobile devices
    if (window.innerWidth <= 768) {
        togglePanelMobile();
        return;
    }
    
    // Desktop behavior
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

// Mobile: Show chat list (hide chat area) - Requirements: 9.1, 9.2
function showChatList() {
    const sidebar = document.getElementById('inboxSidebar');
    const customerPanel = document.getElementById('customerPanel');
    
    sidebar.classList.remove('hidden-mobile');
    
    // Also hide customer panel if visible
    if (customerPanel) {
        customerPanel.classList.remove('mobile-visible');
        customerPanel.classList.add('hidden');
    }
}

// Mobile: Hide chat list when user is selected - Requirements: 9.2
function hideChatListOnMobile() {
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('inboxSidebar');
        sidebar.classList.add('hidden-mobile');
    }
}

// Mobile: Toggle customer panel with animation - Requirements: 9.1
function togglePanelMobile() {
    const panel = document.getElementById('customerPanel');
    
    if (window.innerWidth <= 768) {
        // Mobile: slide in/out
        if (panel.classList.contains('mobile-visible')) {
            panel.classList.remove('mobile-visible');
            panel.classList.add('hidden');
        } else {
            panel.classList.remove('hidden');
            panel.classList.add('mobile-visible');
        }
    } else {
        // Desktop: toggle visibility
        togglePanel();
    }
}

// Mobile: Close customer panel
function closePanelMobile() {
    const panel = document.getElementById('customerPanel');
    panel.classList.remove('mobile-visible');
    panel.classList.add('hidden');
}

// Detect if device is mobile
function isMobileDevice() {
    return window.innerWidth <= 768 || 
           ('ontouchstart' in window) || 
           (navigator.maxTouchPoints > 0);
}

// Handle window resize - adjust layout
function handleResize() {
    const sidebar = document.getElementById('inboxSidebar');
    const panel = document.getElementById('customerPanel');
    
    if (window.innerWidth > 768) {
        // Desktop: reset mobile classes
        sidebar.classList.remove('hidden-mobile');
        if (panel) {
            panel.classList.remove('mobile-visible');
        }
    } else {
        // Mobile: hide sidebar if user is selected
        <?php if ($selectedUser): ?>
        sidebar.classList.add('hidden-mobile');
        <?php endif; ?>
    }
}

// Debounced resize handler
let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(handleResize, 150);
});

// Mobile: Handle swipe gestures for navigation
let inboxTouchStartX = 0;
let inboxTouchEndX = 0;
const SWIPE_THRESHOLD = 50;

function handleTouchStart(e) {
    inboxTouchStartX = e.changedTouches[0].screenX;
}

function handleTouchEnd(e) {
    inboxTouchEndX = e.changedTouches[0].screenX;
    handleSwipeGesture();
}

function handleSwipeGesture() {
    if (window.innerWidth > 768) return; // Only on mobile
    
    const swipeDistance = inboxTouchEndX - inboxTouchStartX;
    
    // Swipe right to show chat list
    if (swipeDistance > SWIPE_THRESHOLD) {
        const panel = document.getElementById('customerPanel');
        if (panel && panel.classList.contains('mobile-visible')) {
            // Close customer panel first
            closePanelMobile();
        } else {
            // Show chat list
            showChatList();
        }
    }
    
    // Swipe left to show customer panel (if in chat)
    if (swipeDistance < -SWIPE_THRESHOLD) {
        const sidebar = document.getElementById('inboxSidebar');
        if (!sidebar.classList.contains('hidden-mobile')) {
            // Hide chat list if visible
            hideChatListOnMobile();
        } else if (userId) {
            // Show customer panel
            togglePanelMobile();
        }
    }
}

// Initialize swipe gestures on chat area
document.addEventListener('DOMContentLoaded', function() {
    const chatArea = document.getElementById('chatArea');
    if (chatArea && isMobileDevice()) {
        chatArea.addEventListener('touchstart', handleTouchStart, { passive: true });
        chatArea.addEventListener('touchend', handleTouchEnd, { passive: true });
    }
});

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

<?php endif; // End inbox tab ?>

<?php if ($currentTab === 'templates'): ?>
<!-- TEMPLATES TAB - Quick Reply Templates -->
<?php 
$templates = $templateService->getTemplates();
?>
<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="inbox.php" class="w-10 h-10 flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Quick Reply Templates</h1>
                <p class="text-gray-500 text-sm">จัดการข้อความสำเร็จรูปสำหรับตอบแชท</p>
            </div>
        </div>
        <button onclick="openCreateTemplateModal()" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium flex items-center gap-2">
            <i class="fas fa-plus"></i>
            สร้าง Template
        </button>
    </div>
    
    <!-- Templates Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php if (empty($templates)): ?>
        <div class="col-span-full text-center py-12 bg-gray-50 rounded-xl">
            <i class="fas fa-file-alt text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-500">ยังไม่มี Template</p>
            <button onclick="openCreateTemplateModal()" class="mt-3 text-emerald-600 hover:text-emerald-700 font-medium">
                <i class="fas fa-plus mr-1"></i> สร้าง Template แรก
            </button>
        </div>
        <?php else: ?>
        <?php foreach ($templates as $tpl): ?>
        <div class="bg-white border rounded-xl p-4 hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($tpl['name']) ?></h3>
                <div class="flex gap-1">
                    <button onclick="editTemplate(<?= $tpl['id'] ?>)" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteTemplate(<?= $tpl['id'] ?>)" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-red-50 text-red-500">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php if ($tpl['category']): ?>
            <span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded-full mb-2"><?= htmlspecialchars($tpl['category']) ?></span>
            <?php endif; ?>
            <p class="text-gray-600 text-sm line-clamp-3"><?= htmlspecialchars($tpl['content']) ?></p>
            <div class="mt-3 pt-3 border-t flex items-center justify-between text-xs text-gray-400">
                <span><i class="fas fa-chart-bar mr-1"></i> ใช้ <?= $tpl['usage_count'] ?> ครั้ง</span>
                <?php if ($tpl['last_used_at']): ?>
                <span>ใช้ล่าสุด <?= date('d/m/Y', strtotime($tpl['last_used_at'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Template Modal -->
<div id="templateModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg">
        <div class="p-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-lg" id="templateModalTitle">สร้าง Template</h3>
            <button onclick="closeTemplateModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="templateForm" class="p-4 space-y-4">
            <input type="hidden" id="templateId" value="">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อ Template</label>
                <input type="text" id="templateName" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="เช่น ทักทายลูกค้าใหม่" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่</label>
                <input type="text" id="templateCategory" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="เช่น greeting, order, support">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เนื้อหา</label>
                <textarea id="templateContent" rows="4" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="พิมพ์ข้อความ... ใช้ {name}, {phone}, {email} สำหรับ placeholder" required></textarea>
                <p class="text-xs text-gray-500 mt-1">Placeholders: {name}, {phone}, {email}, {order_id}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quick Reply Buttons (JSON)</label>
                <textarea id="templateQuickReply" rows="3" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 font-mono text-sm" placeholder='[{"type":"action","action":{"type":"message","label":"ดูสินค้า","text":"shop"}}]'></textarea>
                <p class="text-xs text-gray-500 mt-1">ตัวอย่าง: [{"type":"action","action":{"type":"message","label":"ดูสินค้า","text":"shop"}}]</p>
            </div>
        </form>
        <div class="p-4 border-t flex gap-2 justify-end">
            <button onclick="closeTemplateModal()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">ยกเลิก</button>
            <button onclick="saveTemplate()" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg">บันทึก</button>
        </div>
    </div>
</div>

<script>
function openCreateTemplateModal() {
    document.getElementById('templateModalTitle').textContent = 'สร้าง Template';
    document.getElementById('templateId').value = '';
    document.getElementById('templateName').value = '';
    document.getElementById('templateCategory').value = '';
    document.getElementById('templateContent').value = '';
    document.getElementById('templateQuickReply').value = '';
    document.getElementById('templateModal').classList.remove('hidden');
}

function closeTemplateModal() {
    document.getElementById('templateModal').classList.add('hidden');
}

async function editTemplate(id) {
    try {
        const res = await fetch(`api/inbox.php?action=get_template&id=${id}`);
        const data = await res.json();
        if (data.success) {
            document.getElementById('templateModalTitle').textContent = 'แก้ไข Template';
            document.getElementById('templateId').value = data.data.id;
            document.getElementById('templateName').value = data.data.name;
            document.getElementById('templateCategory').value = data.data.category || '';
            document.getElementById('templateContent').value = data.data.content;
            document.getElementById('templateQuickReply').value = data.data.quick_reply || '';
            document.getElementById('templateModal').classList.remove('hidden');
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด');
    }
}

async function saveTemplate() {
    const id = document.getElementById('templateId').value;
    const name = document.getElementById('templateName').value.trim();
    const category = document.getElementById('templateCategory').value.trim();
    const content = document.getElementById('templateContent').value.trim();
    const quickReply = document.getElementById('templateQuickReply').value.trim();
    
    if (!name || !content) {
        alert('กรุณากรอกชื่อและเนื้อหา');
        return;
    }
    
    // Validate Quick Reply JSON if provided
    if (quickReply) {
        try {
            const parsed = JSON.parse(quickReply);
            if (!Array.isArray(parsed)) {
                alert('Quick Reply ต้องเป็น Array');
                return;
            }
            // Validate structure
            for (const item of parsed) {
                if (item.type !== 'action' || !item.action || !item.action.type || !item.action.label) {
                    alert('Quick Reply format ไม่ถูกต้อง\nต้องมี: {"type":"action","action":{"type":"message","label":"...","text":"..."}}');
                    return;
                }
            }
        } catch (e) {
            alert('Quick Reply JSON ไม่ถูกต้อง: ' + e.message);
            return;
        }
    }
    
    try {
        const res = await fetch('api/inbox.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: id ? 'update_template' : 'create_template',
                id: id || undefined,
                name, category, content,
                quick_reply: quickReply || null
            })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'เกิดข้อผิดพลาด');
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด');
    }
}

async function deleteTemplate(id) {
    if (!confirm('ต้องการลบ Template นี้?')) return;
    
    try {
        const res = await fetch('api/inbox.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete_template', id })
        });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'เกิดข้อผิดพลาด');
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด');
    }
}
</script>
<?php endif; // End templates tab ?>

<?php if ($currentTab === 'analytics'): ?>
<!-- ANALYTICS TAB - Chat Analytics -->
<?php
$avgResponseTime = $analyticsService->getAverageResponseTime($currentBotId, 'week');
$slaThreshold = 300; // 5 minutes
$slaViolations = $analyticsService->getConversationsExceedingSLA($currentBotId, $slaThreshold);
?>
<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="inbox.php" class="w-10 h-10 flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Chat Analytics</h1>
            <p class="text-gray-500 text-sm">สถิติการตอบแชทและประสิทธิภาพ</p>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white border rounded-xl p-5">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-clock text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-gray-500 text-sm">เวลาตอบเฉลี่ย (7 วัน)</p>
                    <p class="text-2xl font-bold text-gray-800">
                        <?php 
                        if ($avgResponseTime < 60) {
                            echo round($avgResponseTime) . ' วินาที';
                        } elseif ($avgResponseTime < 3600) {
                            echo round($avgResponseTime / 60, 1) . ' นาที';
                        } else {
                            echo round($avgResponseTime / 3600, 1) . ' ชม.';
                        }
                        ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="bg-white border rounded-xl p-5">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-orange-100 flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-gray-500 text-sm">เกิน SLA (5 นาที)</p>
                    <p class="text-2xl font-bold text-gray-800"><?= count($slaViolations) ?> แชท</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white border rounded-xl p-5">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center">
                    <i class="fas fa-comments text-emerald-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-gray-500 text-sm">SLA Threshold</p>
                    <p class="text-2xl font-bold text-gray-800">5 นาที</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- SLA Violations List -->
    <?php if (!empty($slaViolations)): ?>
    <div class="bg-white border rounded-xl">
        <div class="p-4 border-b">
            <h3 class="font-bold text-gray-800">แชทที่เกิน SLA</h3>
        </div>
        <div class="divide-y">
            <?php foreach (array_slice($slaViolations, 0, 10) as $violation): ?>
            <a href="inbox.php?user=<?= $violation['user_id'] ?>" class="flex items-center gap-3 p-4 hover:bg-gray-50">
                <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden">
                    <?php if (!empty($violation['picture_url'])): ?>
                    <img src="<?= htmlspecialchars($violation['picture_url']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <i class="fas fa-user text-gray-400"></i>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <p class="font-medium text-gray-800"><?= htmlspecialchars($violation['display_name'] ?? 'ไม่ระบุชื่อ') ?></p>
                    <p class="text-sm text-gray-500">รอตอบ <?= round($violation['wait_time'] / 60) ?> นาที</p>
                </div>
                <span class="px-2 py-1 bg-orange-100 text-orange-700 text-xs rounded-full">เกิน SLA</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-white border rounded-xl p-8 text-center">
        <i class="fas fa-check-circle text-4xl text-emerald-500 mb-3"></i>
        <p class="text-gray-600">ไม่มีแชทที่เกิน SLA</p>
    </div>
    <?php endif; ?>
</div>
<?php endif; // End analytics tab ?>

<?php require_once 'includes/footer.php'; ?>
