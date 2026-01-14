<?php
/**
 * Inbox V2 - Vibe Selling OS v2 (Pharmacy Edition)
 * AI-Powered Pharmacy Assistant with HUD Dashboard
 * 
 * Features:
 * - Multi-modal image analysis (symptoms, drugs, prescriptions)
 * - Customer health profiling with communication style detection
 * - Drug pricing and margin engine
 * - Ghost draft generation with learning
 * - Context-aware HUD widgets
 * - Drug interaction checking
 * 
 * Requirements: 4.1-4.6 (Pharmacy HUD Dashboard)
 * Requirements: 10.6 (Graceful fallback to v1 when disabled)
 * 
 * Version: 2.0
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

// Initialize database early for v2 check
$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Debug: Log current bot ID
error_log("[inbox-v2] Session current_bot_id: " . ($_SESSION['current_bot_id'] ?? 'NOT SET') . ", Using: $currentBotId");

// Check if V2 is enabled - graceful fallback to v1 if disabled (Requirements: 10.6)
require_once 'classes/VibeSellingHelper.php';
$vibeHelper = VibeSellingHelper::getInstance($db);

if (!$vibeHelper->isV2Enabled($currentBotId)) {
    // V2 is disabled, redirect to v1 with all query parameters preserved
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = 'inbox.php';
    if (!empty($queryString)) {
        $redirectUrl .= '?' . $queryString;
    }
    header("Location: {$redirectUrl}");
    exit;
}

// Continue loading v2 dependencies
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';
require_once 'classes/ActivityLogger.php';
require_once 'classes/InboxService.php';
require_once 'classes/AnalyticsService.php';
require_once 'classes/CustomerNoteService.php';
require_once 'classes/TemplateService.php';

// V2 Services - Vibe Selling OS
require_once 'classes/DrugPricingEngineService.php';
require_once 'classes/CustomerHealthEngineService.php';
require_once 'classes/PharmacyImageAnalyzerService.php';
require_once 'classes/PharmacyGhostDraftService.php';

$activityLogger = ActivityLogger::getInstance($db);

// Initialize V2 Services with error handling for graceful fallback
try {
    $pricingEngine = new DrugPricingEngineService($db, $currentBotId);
    $healthEngine = new CustomerHealthEngineService($db, $currentBotId);
    $imageAnalyzer = new PharmacyImageAnalyzerService($db, $currentBotId);
    $ghostDraft = new PharmacyGhostDraftService($db, $currentBotId);
} catch (Exception $e) {
    // If v2 services fail to initialize and auto-switch is enabled, fallback to v1
    if ($vibeHelper->isAutoSwitchEnabled($currentBotId)) {
        error_log("Inbox V2: Service initialization failed, falling back to v1 - " . $e->getMessage());
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $redirectUrl = 'inbox.php';
        if (!empty($queryString)) {
            $redirectUrl .= '?' . $queryString;
        }
        header("Location: {$redirectUrl}");
        exit;
    }
    // If auto-switch is disabled, let the error propagate
    throw $e;
}

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


// AJAX Handlers for V2
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
                    $adminUser = $_SESSION['admin_user'] ?? null;
                    $adminName = 'Admin';
                    if (is_array($adminUser)) {
                        if (!empty($adminUser['username'])) {
                            $adminName = $adminUser['username'];
                        } elseif (!empty($adminUser['display_name'])) {
                            $adminName = $adminUser['display_name'];
                        }
                    }
                    
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
                        'method_label' => $method === 'reply' ? '✓ Reply (ฟรี)' : '💰 Push'
                    ]);
                } else {
                    throw new Exception("LINE API Error");
                }
                break;
                
            case 'ai_reply':
                require_once 'modules/AIChat/Autoloader.php';
                $userId = intval($_POST['user_id'] ?? 0);
                $selectedMessage = $_POST['selected_message'] ?? $_POST['last_message'] ?? '';
                $tone = $_POST['tone'] ?? 'friendly';
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
                
                $toneInstructions = [
                    'friendly' => 'ตอบด้วยน้ำเสียงเป็นมิตร อบอุ่น ใช้ภาษาที่เข้าถึงง่าย',
                    'formal' => 'ตอบด้วยน้ำเสียงทางการ สุภาพ เป็นทางการ',
                    'casual' => 'ตอบด้วยน้ำเสียงสบายๆ เป็นกันเอง ใช้ภาษาพูดทั่วไป',
                    'empathetic' => 'ตอบด้วยความเข้าใจ เห็นอกเห็นใจ แสดงความห่วงใย',
                    'professional' => 'ตอบด้วยความเป็นมืออาชีพ ให้ข้อมูลที่ถูกต้องชัดเจน'
                ];
                $toneText = $toneInstructions[$tone] ?? $toneInstructions['friendly'];
                
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
                
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = $_FILES['image']['type'];
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception("Invalid image type. Allowed: JPG, PNG, GIF, WEBP");
                }
                
                if ($_FILES['image']['size'] > 10 * 1024 * 1024) {
                    throw new Exception("Image too large. Max 10MB");
                }
                
                $stmt = $db->prepare("SELECT line_user_id, line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("User not found");
                
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
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $imageUrl = $protocol . $host . '/uploads/chat_images/' . $filename;
                
                $lineManager = new LineAccountManager($db);
                $line = $lineManager->getLineAPI($user['line_account_id']);
                
                $result = $line->pushMessage($user['line_user_id'], [[
                    'type' => 'image',
                    'originalContentUrl' => $imageUrl,
                    'previewImageUrl' => $imageUrl
                ]]);
                
                if ($result['code'] === 200) {
                    $adminUser = $_SESSION['admin_user'] ?? null;
                    $adminName = 'Admin';
                    if (is_array($adminUser)) {
                        $adminName = $adminUser['username'] ?? $adminUser['display_name'] ?? 'Admin';
                    }
                    
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
                    @unlink($filepath);
                    throw new Exception("Failed to send image via LINE");
                }
                break;
            
            case 'upload_for_analysis':
                $userId = intval($_POST['user_id'] ?? 0);
                if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("No image uploaded");
                }
                
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = $_FILES['image']['type'];
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception("Invalid image type");
                }
                
                if ($_FILES['image']['size'] > 10 * 1024 * 1024) {
                    throw new Exception("Image too large. Max 10MB");
                }
                
                $uploadDir = __DIR__ . '/uploads/analysis_images/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $filename = 'analysis_' . time() . '_' . uniqid() . '.' . $ext;
                $filepath = $uploadDir . $filename;
                
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
                    throw new Exception("Failed to save image");
                }
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $imageUrl = $protocol . $host . '/uploads/analysis_images/' . $filename;
                
                echo json_encode([
                    'success' => true,
                    'image_url' => $imageUrl,
                    'filename' => $filename
                ]);
                break;
            
            case 'send_pdf':
                $userId = intval($_POST['user_id'] ?? 0);
                if (!$userId) throw new Exception("User ID required");
                if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("No PDF uploaded");
                }
                
                $fileType = $_FILES['pdf']['type'];
                if ($fileType !== 'application/pdf') {
                    throw new Exception("Invalid file type. Only PDF allowed");
                }
                
                if ($_FILES['pdf']['size'] > 10 * 1024 * 1024) {
                    throw new Exception("PDF too large. Max 10MB");
                }
                
                $stmt = $db->prepare("SELECT line_user_id, line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("User not found");
                
                $uploadDir = __DIR__ . '/uploads/chat_files/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $originalName = pathinfo($_FILES['pdf']['name'], PATHINFO_FILENAME);
                $filename = 'pdf_' . time() . '_' . uniqid() . '.pdf';
                $filepath = $uploadDir . $filename;
                
                if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $filepath)) {
                    throw new Exception("Failed to save PDF");
                }
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $fileUrl = $protocol . $host . '/uploads/chat_files/' . $filename;
                
                // LINE doesn't support PDF directly, send as text with link
                $lineManager = new LineAccountManager($db);
                $line = $lineManager->getLineAPI($user['line_account_id']);
                
                $pdfMessage = "📄 ไฟล์ PDF: " . $_FILES['pdf']['name'] . "\n🔗 " . $fileUrl;
                $result = $line->pushMessage($user['line_user_id'], [[
                    'type' => 'text',
                    'text' => $pdfMessage
                ]]);
                
                if ($result['code'] === 200) {
                    $adminUser = $_SESSION['admin_user'] ?? null;
                    $adminName = 'Admin';
                    if (is_array($adminUser)) {
                        $adminName = $adminUser['username'] ?? $adminUser['display_name'] ?? 'Admin';
                    }
                    
                    $hasSentBy = false;
                    try {
                        $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                        $hasSentBy = $checkCol->rowCount() > 0;
                    } catch (Exception $e) {}
                    
                    // Store as file type with URL
                    $content = json_encode(['url' => $fileUrl, 'name' => $_FILES['pdf']['name']], JSON_UNESCAPED_UNICODE);
                    
                    if ($hasSentBy) {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (?, ?, 'outgoing', 'file', ?, ?, NOW(), 0)");
                        $stmt->execute([$user['line_account_id'], $userId, $content, 'admin:' . $adminName]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) VALUES (?, ?, 'outgoing', 'file', ?, NOW(), 0)");
                        $stmt->execute([$user['line_account_id'], $userId, $content]);
                    }
                    $msgId = $db->lastInsertId();
                    
                    $activityLogger->logMessage(ActivityLogger::ACTION_SEND, 'ส่งไฟล์ PDF ถึงลูกค้า', [
                        'user_id' => $userId,
                        'entity_type' => 'message',
                        'entity_id' => $msgId,
                        'line_account_id' => $user['line_account_id']
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'message_id' => $msgId,
                        'file_url' => $fileUrl,
                        'file_name' => $_FILES['pdf']['name'],
                        'time' => date('H:i'),
                        'sent_by' => 'admin:' . $adminName
                    ]);
                } else {
                    @unlink($filepath);
                    throw new Exception("Failed to send PDF via LINE");
                }
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

$pageTitle = 'Inbox V2 - Vibe Selling OS';
$hideAiChatWidget = true;
require_once 'includes/header.php';

// Get Users List - use subqueries for accurate latest message (v2.1 - fixed 2026-01-15)
$sql = "SELECT u.*, 
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread
        FROM users u 
        WHERE u.line_account_id = ?
        AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)
        ORDER BY last_time DESC";
$stmt = $db->prepare($sql);
$stmt->execute([$currentBotId]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get Selected User
$selectedUser = null;
$messages = [];
$userTags = [];
$allTags = [];
$healthProfile = null;
$customerClassification = null;

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
        
        // V2: Get health profile and classification
        try {
            $healthProfile = $healthEngine->getHealthProfile($uid);
            $customerClassification = $healthEngine->classifyCustomer($uid);
        } catch (Exception $e) {
            // Services may not be fully configured
        }
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

function formatThaiTime($datetime) {
    if (!$datetime) return '';
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    if (date('Y-m-d', $timestamp) === date('Y-m-d', $now)) {
        return date('H:i น.', $timestamp);
    }
    
    if (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('-1 day'))) {
        return 'เมื่อวาน ' . date('H:i', $timestamp);
    }
    
    if ($diff < 604800) {
        $thaiDays = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
        return $thaiDays[date('w', $timestamp)] . ' ' . date('H:i', $timestamp);
    }
    
    return date('d/m', $timestamp) . ' ' . date('H:i', $timestamp);
}

function formatThaiDateTime($datetime) {
    if (!$datetime) return '';
    $timestamp = strtotime($datetime);
    $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $day = date('j', $timestamp);
    $month = $thaiMonths[intval(date('n', $timestamp))];
    $year = date('Y', $timestamp) + 543 - 2500;
    $time = date('H:i', $timestamp);
    return "{$day} {$month} {$year} {$time}";
}
?>

<!-- FAB & HUD Mode Switcher CSS -->
<link rel="stylesheet" href="assets/css/inbox-v2-fab.css?v=<?= time() ?>">

<style>
:root { --primary: #10B981; --primary-dark: #059669; --vibe-purple: #8B5CF6; --vibe-purple-dark: #7C3AED; }
.chat-scroll::-webkit-scrollbar { width: 5px; }
.chat-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 3px; }
.chat-bubble { white-space: pre-wrap; word-wrap: break-word; line-height: 1.6; max-width: 100%; }
.chat-incoming { background: #F1F5F9; color: #1E293B; border-radius: 4px 12px 12px 12px; }
.chat-outgoing { background: #10B981; color: white; border-radius: 12px 4px 12px 12px; }
.user-item.active { background: linear-gradient(90deg, #D1FAE5 0%, #ECFDF5 100%); border-left: 3px solid var(--primary); }
.user-item:hover { background: #F0FDF4; }
.user-item.sla-warning { border-left: 3px solid #F97316; background: linear-gradient(90deg, #FFF7ED 0%, #FFFFFF 100%); }
.tag-badge { font-size: 0.6rem; padding: 2px 6px; border-radius: 9999px; font-weight: 500; }
#chatBox { background: #FFFFFF; }

/* V2 Vibe Selling OS Styles */
.vibe-header { background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 50%, #10B981 100%); }
.vibe-badge { background: linear-gradient(135deg, #8B5CF6, #6366F1); color: white; font-size: 9px; padding: 2px 6px; border-radius: 4px; }

/* HUD Dashboard Styles - Requirements: 4.1-4.6 */
.hud-dashboard {
    position: fixed;
    top: 0;
    right: 0;
    width: 320px;
    height: 100vh;
    background: linear-gradient(180deg, #F8FAFC 0%, #F1F5F9 100%);
    border-left: 1px solid #E2E8F0;
    overflow-y: auto;
    z-index: 40;
    transition: transform 0.3s ease, width 0.3s ease;
}
.hud-dashboard.collapsed {
    transform: translateX(100%);
}
.hud-dashboard.minimized {
    width: 60px;
}

/* HUD Widget Base Styles */
.hud-widget {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    animation: widgetFadeIn 0.3s ease;
}
.hud-widget:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}
.hud-widget-header {
    padding: 10px 12px;
    border-bottom: 1px solid #F1F5F9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
}
.hud-widget-header h4 {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
}
.hud-widget-body {
    padding: 12px;
}
.hud-widget.collapsed .hud-widget-body {
    display: none;
}

@keyframes widgetFadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Drug Info Widget - Requirements: 4.2 */
.drug-info-widget .drug-name { font-size: 14px; font-weight: 600; color: #1F2937; }
.drug-info-widget .drug-dosage { font-size: 11px; color: #6B7280; margin-top: 2px; }
.drug-info-widget .drug-price { font-size: 16px; font-weight: 700; color: #10B981; }
.drug-info-widget .drug-stock { font-size: 10px; padding: 2px 6px; border-radius: 4px; }
.drug-info-widget .drug-stock.in-stock { background: #D1FAE5; color: #059669; }
.drug-info-widget .drug-stock.low-stock { background: #FEF3C7; color: #D97706; }
.drug-info-widget .drug-stock.out-of-stock { background: #FEE2E2; color: #DC2626; }

/* Interaction Checker Widget - Requirements: 4.3 */
.interaction-widget .interaction-item { 
    padding: 8px; 
    border-radius: 8px; 
    margin-bottom: 6px;
    font-size: 11px;
}
.interaction-widget .interaction-item.severe { background: #FEE2E2; border-left: 3px solid #DC2626; }
.interaction-widget .interaction-item.moderate { background: #FEF3C7; border-left: 3px solid #F59E0B; }
.interaction-widget .interaction-item.mild { background: #DBEAFE; border-left: 3px solid #3B82F6; }

/* Allergy Warning Widget - Requirements: 4.5 */
.allergy-widget {
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    border: 2px solid #F87171;
}
.allergy-widget .hud-widget-header {
    background: #DC2626;
    color: white;
    border-bottom: none;
}
.allergy-widget .hud-widget-header h4 { color: white; }
.allergy-widget .allergy-item {
    background: white;
    padding: 8px 10px;
    border-radius: 6px;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 500;
    color: #DC2626;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Symptom Analyzer Widget - Requirements: 4.1 */
.symptom-widget .symptom-tag {
    display: inline-block;
    padding: 4px 8px;
    background: #EDE9FE;
    color: #7C3AED;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 500;
    margin: 2px;
}
.symptom-widget .recommended-drug {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px;
    background: #F0FDF4;
    border-radius: 6px;
    margin-top: 6px;
}

/* Pricing Engine Widget - Requirements: 3.1-3.5 */
.pricing-widget .price-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid #F3F4F6;
    font-size: 12px;
}
.pricing-widget .price-row:last-child { border-bottom: none; }
.pricing-widget .margin-indicator {
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}
.pricing-widget .margin-good { background: #D1FAE5; color: #059669; }
.pricing-widget .margin-warning { background: #FEF3C7; color: #D97706; }
.pricing-widget .margin-danger { background: #FEE2E2; color: #DC2626; }

/* Medical History Widget */
.medical-widget .medical-section {
    padding: 8px;
    background: #F9FAFB;
    border-radius: 6px;
    margin-bottom: 8px;
}
.medical-widget .medical-section h5 {
    font-size: 10px;
    font-weight: 600;
    color: #6B7280;
    margin-bottom: 4px;
    text-transform: uppercase;
}
.medical-widget .medical-section p {
    font-size: 12px;
    color: #374151;
}

/* Customer Type Badge */
.customer-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.customer-type-badge.type-a { background: #DBEAFE; color: #1D4ED8; }
.customer-type-badge.type-b { background: #FCE7F3; color: #BE185D; }
.customer-type-badge.type-c { background: #D1FAE5; color: #047857; }

/* Ghost Draft Styles - Requirements: 6.1-6.6 */
.ghost-draft-input {
    position: relative;
}

/* Quick Actions Bar Styles - Unified Design */
#quickActionsBar {
    transition: all 0.3s ease;
}
/* Unified Action Button Style - All buttons look the same */
.quick-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    border: none;
    background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
    color: white;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
}
.quick-action-btn:active {
    transform: translateY(0);
}
.quick-action-btn.urgent {
    background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
    animation: urgentPulse 2s infinite;
}
.quick-action-btn.urgent:hover {
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
}
@keyframes urgentPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4); }
    50% { box-shadow: 0 0 0 4px rgba(220, 38, 38, 0); }
}
.quick-action-btn.primary {
    background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
}
.quick-action-btn.primary:hover {
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35);
}
.quick-action-btn .action-icon {
    font-size: 14px;
}
.quick-actions-stage-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    background: #EDE9FE;
    color: #7C3AED;
}
.quick-actions-stage-badge.symptom { background: #FEF3C7; color: #D97706; }
.quick-actions-stage-badge.recommendation { background: #DBEAFE; color: #2563EB; }
.quick-actions-stage-badge.purchase { background: #D1FAE5; color: #059669; }
.quick-actions-stage-badge.followup { background: #E0E7FF; color: #4338CA; }
.ghost-draft-input .ghost-text {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    padding: inherit;
    color: #9CA3AF;
    pointer-events: none;
    white-space: pre-wrap;
    overflow: hidden;
}
.ghost-draft-input textarea {
    background: transparent;
    position: relative;
    z-index: 1;
}
.ghost-draft-indicator {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 10px;
    color: #8B5CF6;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Sender Badge Styles */
.sender-badge { 
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 10px; padding: 2px 6px; border-radius: 4px; 
    font-weight: 600; margin-left: 6px;
}
.sender-badge.admin { background: #DBEAFE; color: #1E40AF; }
.sender-badge.ai { background: #E0E7FF; color: #4338CA; }
.sender-badge.bot { background: #FEE2E2; color: #991B1B; }

/* Inbox Full Screen */
.sidebar { display: none !important; }
.main-content { margin-left: 0 !important; width: 100% !important; }
.top-header { display: none !important; }
.content-area { padding: 0 !important; height: 100vh !important; }

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

/* Mobile Responsive */
@media (max-width: 768px) {
    #inboxContainer { flex-direction: column !important; }
    
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
    }
    #inboxSidebar.hidden-mobile { transform: translateX(-100%); pointer-events: none; }
    
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
    
    #mobileBackBtn { display: flex !important; }
    
    .hud-dashboard {
        position: fixed !important;
        top: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100% !important;
        z-index: 200 !important;
        transform: translateX(100%);
        transition: transform 0.3s ease-out;
    }
    .hud-dashboard.mobile-visible { transform: translateX(0); }
    
    .chat-bubble { max-width: 85% !important; font-size: 14px !important; }
}

@media (min-width: 769px) {
    #mobileBackBtn { display: none !important; }
}

/* Notification Toast */
.notification-container {
    position: fixed;
    top: 80px;
    right: 340px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 350px;
}
/* Image Analysis Dropdown Styles - Requirements: 1.1, 1.2, 1.3 */
#imageAnalysisMenu {
    animation: dropdownFadeIn 0.2s ease;
}
@keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
#imageAnalysisMenu button:hover {
    transform: translateX(2px);
    transition: transform 0.15s ease;
}
.analysis-btn-container {
    animation: fadeIn 0.2s ease;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
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
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Pulse Animation */
.pulse-dot { animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

/* Chat Area Layout - Prevent overlap with HUD */
#chatArea {
    margin-right: 320px; /* HUD width */
    transition: margin-right 0.3s ease;
}
#chatArea.hud-hidden {
    margin-right: 0;
}

/* Message Input Improvements */
#messageInput {
    min-height: 40px;
    max-height: 150px;
    line-height: 1.5;
    resize: none;
    overflow-y: auto;
}
#messageInput:focus {
    outline: none;
}

/* Quick Actions Bar - Purchase Stage */
.purchase-actions-bar {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    border: 1px solid #6EE7B7;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 8px;
}
.purchase-actions-bar .action-title {
    font-size: 12px;
    font-weight: 600;
    color: #047857;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.purchase-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
}
.purchase-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.purchase-action-btn.create-order {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
}
.purchase-action-btn.payment-link {
    background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
    color: white;
}
.purchase-action-btn.schedule-delivery {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
    color: white;
}
.purchase-action-btn.use-points {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: white;
}
.purchase-action-btn.send-menu {
    background: linear-gradient(135deg, #EC4899 0%, #DB2777 100%);
    color: white;
}

/* Checkbox styles for product detection */
.detected-drug-checkbox {
    width: 18px !important;
    height: 18px !important;
    min-width: 18px;
    accent-color: #10B981;
    cursor: pointer;
    border: 2px solid #D1D5DB;
    border-radius: 4px;
}
.detected-drug-checkbox:checked {
    background-color: #10B981;
    border-color: #10B981;
}

/* Resizable textarea handle */
.message-input-container {
    position: relative;
}
.resize-handle {
    position: absolute;
    top: -4px;
    left: 50%;
    transform: translateX(-50%);
    width: 40px;
    height: 4px;
    background: #D1D5DB;
    border-radius: 2px;
    cursor: ns-resize;
    opacity: 0;
    transition: opacity 0.2s;
}
.message-input-container:hover .resize-handle {
    opacity: 1;
}
</style>


<?php if ($currentTab === 'inbox'): ?>
<!-- INBOX V2 TAB - Vibe Selling OS -->
<div id="inboxContainer" class="h-screen flex bg-white overflow-hidden relative">
    
    <!-- LEFT: User List -->
    <div id="inboxSidebar" class="w-72 bg-white border-r flex flex-col">
        <div class="p-3 border-b vibe-header flex items-center justify-between">
            <div class="flex items-center gap-2">
                <a href="dashboard.php" id="backToMenuBtn" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/20 hover:bg-white/30 text-white" title="กลับหน้าหลัก">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h2 class="text-white font-bold flex items-center">
                    <i class="fas fa-inbox mr-2"></i>Inbox
                    <span class="vibe-badge ml-2">V2</span>
                    <span id="totalUnread" class="ml-2 text-xs bg-white/20 px-2 py-0.5 rounded-full"><?= count($users) ?></span>
                </h2>
            </div>
            <div class="flex items-center gap-2">
                <button id="soundToggle" class="sound-toggle text-white p-1" onclick="toggleSound()" title="เปิด/ปิดเสียง">
                    <i class="fas fa-volume-up" id="soundIcon"></i>
                </button>
                <span id="liveIndicator" class="w-2 h-2 bg-green-300 rounded-full pulse-dot" title="Real-time Active"></span>
            </div>
        </div>
        
        <!-- Search Input -->
        <div class="p-2 border-b">
            <div class="relative">
                <input type="text" id="userSearch" placeholder="🔍 ค้นหาชื่อ, ข้อความ, แท็ก..." 
                       class="w-full px-3 py-2 bg-gray-100 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none pr-8" 
                       oninput="debouncedSearch(this.value)">
            </div>
        </div>
        
        <!-- Filter Dropdowns -->
        <div class="p-2 border-b bg-gray-50 space-y-2">
            <div class="flex gap-2">
                <select id="filterStatus" onchange="applyFilters()" class="flex-1 px-2 py-1.5 bg-white border rounded-lg text-xs focus:ring-2 focus:ring-purple-500 outline-none">
                    <option value="">ทุกสถานะ</option>
                    <option value="unread">ยังไม่อ่าน</option>
                    <option value="assigned">มอบหมายแล้ว</option>
                </select>
                
                <select id="filterTag" onchange="applyFilters()" class="flex-1 px-2 py-1.5 bg-white border rounded-lg text-xs focus:ring-2 focus:ring-purple-500 outline-none">
                    <option value="">ทุกแท็ก</option>
                    <?php foreach ($allTagsForFilter as $tag): ?>
                    <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Conversation List -->
        <div id="userList" class="flex-1 overflow-y-auto chat-scroll">
            <?php if (empty($users)): ?>
                <div class="p-6 text-center text-gray-400">
                    <i class="fas fa-inbox text-4xl mb-2"></i>
                    <p class="text-sm">ยังไม่มีแชท</p>
                </div>
            <?php else: ?>
                <?php foreach ($users as $index => $user): 
                    $assignment = null;
                    try {
                        $assignStmt = $db->prepare("SELECT ca.*, au.username as assigned_admin_name FROM conversation_assignments ca LEFT JOIN admin_users au ON ca.assigned_to = au.id WHERE ca.user_id = ?");
                        $assignStmt->execute([$user['id']]);
                        $assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {}
                    
                    $hasSlaWarning = in_array($user['id'], $slaViolationUserIds);
                ?>
                <a href="?user=<?= $user['id'] ?>" 
                   class="user-item block p-3 border-b border-gray-50 <?= ($selectedUser && $selectedUser['id'] == $user['id']) ? 'active' : '' ?> <?= $hasSlaWarning ? 'sla-warning' : '' ?>" 
                   data-user-id="<?= $user['id'] ?>"
                   data-name="<?= strtolower($user['display_name']) ?>">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-shrink-0">
                            <img src="<?= $user['picture_url'] ?: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Ccircle cx=%2220%22 cy=%2220%22 r=%2220%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M20 22c3.3 0 6-2.7 6-6s-2.7-6-6-6-6 2.7-6 6 2.7 6 6 6zm0 3c-4 0-12 2-12 6v3h24v-3c0-4-8-6-12-6z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E' ?>" 
                                 class="w-10 h-10 rounded-full object-cover border-2 border-white shadow"
                                 loading="lazy"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Ccircle cx=%2220%22 cy=%2220%22 r=%2220%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M20 22c3.3 0 6-2.7 6-6s-2.7-6-6-6-6 2.7-6 6 2.7 6 6 6zm0 3c-4 0-12 2-12 6v3h24v-3c0-4-8-6-12-6z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E'">
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
                            <p class="last-msg text-xs text-gray-500 truncate" data-initial="<?= htmlspecialchars($user['last_msg'] ?? '') ?>"><?= htmlspecialchars(getMessagePreview($user['last_msg'], $user['last_type'])) ?></p>
                            
                            <?php if ($assignment && $assignment['status'] === 'active'): ?>
                            <span class="text-[9px] px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded-full">
                                <i class="fas fa-user-check"></i> <?= htmlspecialchars($assignment['assigned_admin_name'] ?: 'Admin') ?>
                            </span>
                            <?php endif; ?>
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
        
        <!-- Chat Header with V2 Features -->
        <div class="h-14 bg-white border-b flex items-center justify-between px-4 shadow-sm">
            <div class="flex items-center gap-3">
                <button id="mobileBackBtn" onclick="showChatList()" class="hidden w-8 h-8 items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 mr-1">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <img src="<?= $selectedUser['picture_url'] ?: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Ccircle cx=%2220%22 cy=%2220%22 r=%2220%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M20 22c3.3 0 6-2.7 6-6s-2.7-6-6-6-6 2.7-6 6 2.7 6 6 6zm0 3c-4 0-12 2-12 6v3h24v-3c0-4-8-6-12-6z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E' ?>" class="w-10 h-10 rounded-full border-2 border-purple-500" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Ccircle cx=%2220%22 cy=%2220%22 r=%2220%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M20 22c3.3 0 6-2.7 6-6s-2.7-6-6-6-6 2.7-6 6 2.7 6 6 6zm0 3c-4 0-12 2-12 6v3h24v-3c0-4-8-6-12-6z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E'">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($selectedUser['display_name']) ?></h3>
                        <?php if ($customerClassification && isset($customerClassification['type'])): ?>
                        <span class="customer-type-badge type-<?= strtolower($customerClassification['type']) ?>">
                            <?php 
                            $typeLabels = ['A' => '⚡ Direct', 'B' => '💝 Concerned', 'C' => '📊 Detailed'];
                            echo $typeLabels[$customerClassification['type']] ?? $customerClassification['type'];
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div id="userTags" class="flex gap-1 flex-wrap">
                        <?php foreach ($userTags as $tag): ?>
                        <span class="tag-badge" style="background-color: <?= htmlspecialchars($tag['color']) ?>20; color: <?= htmlspecialchars($tag['color']) ?>;"><?= htmlspecialchars($tag['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="generateGhostDraft()" class="px-3 py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-700 rounded-lg text-sm font-medium" title="Ghost Draft">
                    <i class="fas fa-magic mr-1"></i>Ghost
                </button>
                <button onclick="toggleHUD()" class="px-3 py-1.5 bg-indigo-100 hover:bg-indigo-200 text-indigo-700 rounded-lg text-sm font-medium" title="HUD Dashboard">
                    <i class="fas fa-th-large mr-1"></i>HUD
                </button>
                <button onclick="togglePanel()" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm cursor-pointer" title="ข้อมูลลูกค้า">
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
                $sentBy = $msg['sent_by'] ?? '';
            ?>
            <div class="message-item flex <?= $isMe ? 'justify-end' : 'justify-start' ?> group" data-msg-id="<?= $msg['id'] ?>">
                <?php if (!$isMe): ?>
                <img src="<?= $selectedUser['picture_url'] ?: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 28 28%22%3E%3Ccircle cx=%2214%22 cy=%2214%22 r=%2214%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M14 15.4c2.3 0 4.2-1.9 4.2-4.2s-1.9-4.2-4.2-4.2-4.2 1.9-4.2 4.2 1.9 4.2 4.2 4.2zm0 2.1c-2.8 0-8.4 1.4-8.4 4.2v2.1h16.8v-2.1c0-2.8-5.6-4.2-8.4-4.2z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E' ?>" class="w-7 h-7 rounded-full self-end mr-2" onerror="this.style.display='none'">
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
                        } elseif (!preg_match('/^https?:\/\//', $content)) {
                            $imgSrc = 'api/line_content.php?id=' . $content;
                        }
                        ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>" 
                             class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90" 
                             onclick="openImage(this.src)" 
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
                    <?php else: ?>
                        <div class="bg-white rounded-lg border p-3 text-xs text-gray-500"><i class="fas fa-file-alt mr-1"></i><?= ucfirst($type) ?></div>
                    <?php endif; ?>
                    
                    <div class="msg-meta flex items-center gap-1 text-[10px] <?= $isMe ? 'text-white/70' : 'text-gray-500' ?> mt-1">
                        <span><?= date('H:i น.', strtotime($msg['created_at'])) ?></span>
                        <?php if ($isMe): ?>
                            <?= getSenderBadge($sentBy, 'outgoing') ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Typing Indicator -->
            <div id="typingIndicator" class="hidden flex justify-start">
                <img src="<?= $selectedUser['picture_url'] ?: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 28 28%22%3E%3Ccircle cx=%2214%22 cy=%2214%22 r=%2214%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M14 15.4c2.3 0 4.2-1.9 4.2-4.2s-1.9-4.2-4.2-4.2-4.2 1.9-4.2 4.2 1.9 4.2 4.2 4.2zm0 2.1c-2.8 0-8.4 1.4-8.4 4.2v2.1h16.8v-2.1c0-2.8-5.6-4.2-8.4-4.2z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E' ?>" class="w-7 h-7 rounded-full self-end mr-2" onerror="this.style.display='none'">
                <div class="typing-indicator bg-white rounded-xl px-4 py-2">
                    <span class="w-2 h-2 bg-gray-400 rounded-full inline-block animate-bounce"></span>
                    <span class="w-2 h-2 bg-gray-400 rounded-full inline-block animate-bounce" style="animation-delay: 0.1s"></span>
                    <span class="w-2 h-2 bg-gray-400 rounded-full inline-block animate-bounce" style="animation-delay: 0.2s"></span>
                </div>
            </div>
        </div>

        <!-- Quick Actions Bar - Hidden, replaced by FAB -->
        <div id="quickActionsBar" class="hidden">
            <!-- Legacy Quick Actions - now handled by FAB -->
        </div>

        <!-- Input Area with Ghost Draft -->
        <div class="p-3 bg-white border-t">
            <form id="sendForm" class="flex gap-2 items-end" onsubmit="sendMessage(event)">
                <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
                
                <input type="file" id="imageInput" accept="image/*" class="hidden" onchange="handleImageSelect(this)">
                <!-- Specialized Image Analysis Inputs - Requirements: 1.1, 1.2, 1.3 -->
                <input type="file" id="symptomImageInput" accept="image/*" class="hidden" onchange="handleSymptomImageSelect(this)">
                <input type="file" id="drugImageInput" accept="image/*" class="hidden" onchange="handleDrugImageSelect(this)">
                <input type="file" id="prescriptionImageInput" accept="image/*" class="hidden" onchange="handlePrescriptionImageSelect(this)">
                <!-- PDF File Input -->
                <input type="file" id="pdfInput" accept=".pdf,application/pdf" class="hidden" onchange="handlePdfSelect(this)">
                
                <!-- Image Analysis Dropdown Button - Requirements: 1.1, 1.2, 1.3 -->
                <div class="relative" id="imageAnalysisDropdown">
                    <button type="button" onclick="toggleImageAnalysisMenu()" 
                            class="w-10 h-10 rounded-full bg-gradient-to-r from-purple-100 to-indigo-100 hover:from-purple-200 hover:to-indigo-200 text-purple-600 flex items-center justify-center relative" 
                            title="วิเคราะห์รูปภาพ AI">
                        <i class="fas fa-camera-retro"></i>
                        <span class="absolute -top-1 -right-1 w-4 h-4 bg-purple-500 text-white text-[8px] rounded-full flex items-center justify-center font-bold">AI</span>
                    </button>
                    <div id="imageAnalysisMenu" class="hidden absolute bottom-12 left-0 bg-white rounded-xl shadow-xl border border-gray-100 py-2 min-w-[200px] z-50">
                        <div class="px-3 py-1 text-[10px] text-gray-400 font-medium uppercase tracking-wider">วิเคราะห์รูปภาพ AI</div>
                        <button type="button" onclick="triggerSymptomAnalysis()" class="w-full px-3 py-2 text-left text-sm hover:bg-purple-50 flex items-center gap-2 text-gray-700">
                            <span class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center text-red-500">
                                <i class="fas fa-stethoscope"></i>
                            </span>
                            <div>
                                <div class="font-medium">วิเคราะห์อาการ</div>
                                <div class="text-[10px] text-gray-400">ผื่น, บาดแผล, อาการผิดปกติ</div>
                            </div>
                        </button>
                        <button type="button" onclick="triggerDrugAnalysis()" class="w-full px-3 py-2 text-left text-sm hover:bg-purple-50 flex items-center gap-2 text-gray-700">
                            <span class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-500">
                                <i class="fas fa-pills"></i>
                            </span>
                            <div>
                                <div class="font-medium">ระบุยาจากรูป</div>
                                <div class="text-[10px] text-gray-400">ชื่อยา, สรรพคุณ, ข้อควรระวัง</div>
                            </div>
                        </button>
                        <button type="button" onclick="triggerPrescriptionAnalysis()" class="w-full px-3 py-2 text-left text-sm hover:bg-purple-50 flex items-center gap-2 text-gray-700">
                            <span class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center text-blue-500">
                                <i class="fas fa-file-prescription"></i>
                            </span>
                            <div>
                                <div class="font-medium">อ่านใบสั่งยา</div>
                                <div class="text-[10px] text-gray-400">OCR + ตรวจยาตีกัน</div>
                            </div>
                        </button>
                        <div class="border-t border-gray-100 mt-1 pt-1">
                            <button type="button" onclick="document.getElementById('imageInput').click(); closeImageAnalysisMenu();" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-500">
                                <span class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                                    <i class="fas fa-image"></i>
                                </span>
                                <div>
                                    <div class="font-medium">ส่งรูปธรรมดา</div>
                                    <div class="text-[10px] text-gray-400">ไม่วิเคราะห์ AI</div>
                                </div>
                            </button>
                            <button type="button" onclick="document.getElementById('pdfInput').click(); closeImageAnalysisMenu();" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 flex items-center gap-2 text-gray-500">
                                <span class="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center text-red-400">
                                    <i class="fas fa-file-pdf"></i>
                                </span>
                                <div>
                                    <div class="font-medium">ส่งไฟล์ PDF</div>
                                    <div class="text-[10px] text-gray-400">เอกสาร, ใบเสนอราคา</div>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="flex-1 bg-gray-100 rounded-2xl px-4 py-2 focus-within:ring-2 focus-within:ring-purple-500 relative ghost-draft-input">
                    <div id="ghostText" class="ghost-text hidden"></div>
                    <textarea name="message" id="messageInput" rows="1" 
                              class="w-full bg-transparent border-0 outline-none text-sm resize-none max-h-24" 
                              placeholder="พิมพ์ข้อความ... (Tab เพื่อใช้ Ghost Draft)" 
                              oninput="autoResize(this); handleMessageInput(this)"
                              onkeydown="handleKeyDown(event)"></textarea>
                    <div id="ghostDraftIndicator" class="ghost-draft-indicator hidden">
                        <i class="fas fa-magic"></i>
                        <span>Tab เพื่อใช้</span>
                    </div>
                </div>
                <button type="submit" id="sendBtn" class="bg-gradient-to-r from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700 text-white w-10 h-10 rounded-full flex items-center justify-center shadow-lg">
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
                    <button type="button" onclick="sendImage()" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        <i class="fas fa-paper-plane mr-1"></i>ส่งรูป
                    </button>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="flex-1 flex flex-col items-center justify-center text-gray-400">
            <i class="far fa-comments text-6xl mb-4 text-gray-300"></i>
            <p class="text-lg font-medium">เลือกแชทเพื่อเริ่มสนทนา</p>
            <p class="text-sm">Vibe Selling OS v2 - AI-Powered Pharmacy Assistant</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- FLOATING ACTION BUTTON (FAB) -->
    <?php if ($selectedUser): ?>
    <div class="fab-container" id="fabContainer">
        <!-- FAB Menu Items -->
        <div class="fab-menu" id="fabMenu">
            <div class="fab-item">
                <span class="fab-item-label">วิเคราะห์รูป AI</span>
                <button class="fab-item-btn image" onclick="FAB.action('image')" title="วิเคราะห์รูป">
                    <i class="fas fa-camera"></i>
                </button>
            </div>
            <div class="fab-item">
                <span class="fab-item-label">ส่งเมนู</span>
                <button class="fab-item-btn menu" onclick="FAB.action('menu')" title="ส่งเมนู">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="fab-item">
                <span class="fab-item-label">ใช้แต้มสะสม</span>
                <button class="fab-item-btn points" onclick="FAB.action('points')" title="ใช้แต้ม">
                    <i class="fas fa-star"></i>
                </button>
            </div>
            <div class="fab-item">
                <span class="fab-item-label">นัดส่งสินค้า</span>
                <button class="fab-item-btn delivery" onclick="FAB.action('delivery')" title="นัดส่ง">
                    <i class="fas fa-truck"></i>
                </button>
            </div>
            <div class="fab-item">
                <span class="fab-item-label">ส่งลิงก์ชำระเงิน</span>
                <button class="fab-item-btn payment" onclick="FAB.action('payment')" title="ชำระเงิน">
                    <i class="fas fa-credit-card"></i>
                </button>
            </div>
            <div class="fab-item">
                <span class="fab-item-label">สร้างออเดอร์</span>
                <button class="fab-item-btn order" onclick="FAB.action('order')" title="สร้างออเดอร์">
                    <i class="fas fa-cart-plus"></i>
                </button>
            </div>
        </div>
        
        <!-- Main FAB Button -->
        <button class="fab-main" id="fabMainBtn" onclick="FAB.toggle()" title="Quick Actions">
            <i class="fas fa-bolt fab-icon"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- RIGHT: HUD Dashboard - Requirements: 4.1-4.6 -->
    <?php if ($selectedUser): ?>
    <div id="hudDashboard" class="hud-dashboard">
        <!-- HUD Header with Mode Switcher -->
        <div class="p-3 border-b bg-gradient-to-r from-purple-600 to-indigo-600">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-white font-bold text-sm flex items-center gap-2">
                    <i class="fas fa-th-large"></i>
                    HUD
                </h3>
                <div class="flex gap-1">
                    <button onclick="refreshHUD()" class="w-7 h-7 rounded bg-white/20 hover:bg-white/30 text-white flex items-center justify-center text-xs" title="รีเฟรช">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button onclick="toggleHUD()" class="w-7 h-7 rounded bg-white/20 hover:bg-white/30 text-white flex items-center justify-center text-xs" title="ปิด">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <!-- Mode Switcher -->
            <div class="hud-mode-switcher">
                <button class="hud-mode-btn active" data-mode="ai" onclick="HUDMode.switchMode('ai')">
                    <i class="fas fa-robot"></i> AI ขาย
                </button>
                <button class="hud-mode-btn" data-mode="crm" onclick="HUDMode.switchMode('crm')">
                    <i class="fas fa-user-circle"></i> CRM
                </button>
            </div>
        </div>
        
        <!-- AI Mode Panel -->
        <div id="hudAIPanel" class="hud-scroll" style="max-height: calc(100vh - 120px); overflow-y: auto;">
        
        <div id="hudWidgets" class="pb-4">
            <!-- Allergy Warning Widget - Requirements: 4.5 -->
            <?php if (!empty($selectedUser['drug_allergies'])): ?>
            <div class="hud-widget allergy-widget" id="allergyWidget">
                <div class="hud-widget-header" onclick="toggleWidget('allergyWidget')">
                    <h4><i class="fas fa-exclamation-triangle"></i> แพ้ยา</h4>
                    <i class="fas fa-chevron-down text-white/70 text-xs"></i>
                </div>
                <div class="hud-widget-body">
                    <?php 
                    $allergies = array_filter(array_map('trim', explode(',', $selectedUser['drug_allergies'])));
                    foreach ($allergies as $allergy): 
                    ?>
                    <div class="allergy-item">
                        <i class="fas fa-ban"></i>
                        <?= htmlspecialchars($allergy) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Medical History Widget -->
            <?php if (!empty($selectedUser['medical_conditions']) || !empty($selectedUser['current_medications'])): ?>
            <div class="hud-widget medical-widget" id="medicalWidget">
                <div class="hud-widget-header" onclick="toggleWidget('medicalWidget')">
                    <h4><i class="fas fa-heartbeat text-red-500"></i> ประวัติสุขภาพ</h4>
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
                <div class="hud-widget-body">
                    <?php if (!empty($selectedUser['medical_conditions'])): ?>
                    <div class="medical-section">
                        <h5><i class="fas fa-disease text-red-400 mr-1"></i>โรคประจำตัว</h5>
                        <p><?= htmlspecialchars($selectedUser['medical_conditions']) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($selectedUser['current_medications'])): ?>
                    <div class="medical-section">
                        <h5><i class="fas fa-pills text-blue-400 mr-1"></i>ยาที่ใช้อยู่</h5>
                        <p><?= htmlspecialchars($selectedUser['current_medications']) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Drug Info Widget - Requirements: 4.2 -->
            <div class="hud-widget drug-info-widget" id="drugInfoWidget">
                <div class="hud-widget-header" onclick="toggleWidget('drugInfoWidget')">
                    <h4><i class="fas fa-pills text-emerald-500"></i> ข้อมูลยา</h4>
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
                <div class="hud-widget-body">
                    <div id="drugInfoContent" class="text-center text-gray-400 text-xs py-4">
                        <i class="fas fa-search mb-2"></i>
                        <p>พิมพ์ชื่อยาในแชทเพื่อดูข้อมูล</p>
                    </div>
                </div>
            </div>
            
            <!-- Product Detection Widget - Wholesale Mode -->
            <div class="hud-widget symptom-widget" id="symptomWidget">
                <div class="hud-widget-header" onclick="toggleWidget('symptomWidget')">
                    <h4><i class="fas fa-box-open text-purple-500"></i> ตรวจจับสินค้า</h4>
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
                <div class="hud-widget-body">
                    <div id="symptomContent" class="text-center text-gray-400 text-xs py-4">
                        <i class="fas fa-box-open mb-2"></i>
                        <p>รอตรวจจับชื่อสินค้าจากข้อความ</p>
                    </div>
                </div>
            </div>
            
            <!-- Pricing Engine Widget - Requirements: 3.1-3.5 -->
            <div class="hud-widget pricing-widget" id="pricingWidget">
                <div class="hud-widget-header" onclick="toggleWidget('pricingWidget')">
                    <h4><i class="fas fa-calculator text-blue-500"></i> คำนวณราคา/กำไร</h4>
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
                <div class="hud-widget-body">
                    <div id="pricingContent" class="text-center text-gray-400 text-xs py-4">
                        <i class="fas fa-tag mb-2"></i>
                        <p>เลือกยาเพื่อดูราคาและกำไร</p>
                    </div>
                </div>
            </div>
            
            <!-- Customer Profile Widget with Emotion -->
            <div class="hud-widget" id="customerProfileWidget" data-widget="health-profile">
                <div class="hud-widget-header" onclick="toggleWidget('customerProfileWidget')">
                    <h4><i class="fas fa-user-circle text-indigo-500"></i> โปรไฟล์ลูกค้า</h4>
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
                <div class="hud-widget-body widget-content">
                    <?php if ($customerClassification): ?>
                    <!-- Customer Emotion Display -->
                    <div class="mb-3 p-2 bg-gradient-to-r from-amber-50 to-orange-50 rounded-lg border border-amber-100">
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-500">อารมณ์ลูกค้า</span>
                            <span id="customerEmotion" class="text-sm font-medium">
                                <?php 
                                // Default emotion - will be updated by JS
                                $emotion = $customerClassification['emotion'] ?? 'neutral';
                                $emotionLabels = [
                                    'angry' => '😠 โมโห',
                                    'frustrated' => '😤 หงุดหงิด',
                                    'happy' => '😊 ปลาบปลื้ม',
                                    'satisfied' => '😌 พอใจ',
                                    'neutral' => '😐 ปกติ',
                                    'confused' => '😕 สับสน',
                                    'worried' => '😟 กังวล',
                                    'urgent' => '⚡ เร่งด่วน'
                                ];
                                echo $emotionLabels[$emotion] ?? '😐 ปกติ';
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs text-gray-500">รูปแบบการสื่อสาร</span>
                        <span class="customer-type-badge type-<?= strtolower($customerClassification['type'] ?? 'a') ?>">
                            <?php 
                            // A = ตรงไปตรงมา, B = ใส่ใจรายละเอียด, C = สบายๆ
                            $typeLabels = [
                                'A' => '⚡ ตรงไปตรงมา',
                                'B' => '💝 ใส่ใจรายละเอียด', 
                                'C' => '📊 สบายๆ ค่อยๆคุย'
                            ];
                            echo $typeLabels[$customerClassification['type'] ?? 'A'] ?? 'ไม่ระบุ';
                            ?>
                        </span>
                    </div>
                    <?php if (isset($customerClassification['confidence'])): ?>
                    <div class="mb-3">
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-gray-500">ความแม่นยำ</span>
                            <span class="font-medium"><?= round(($customerClassification['confidence'] ?? 0) * 100) ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                            <div class="bg-purple-500 h-1.5 rounded-full" style="width: <?= ($customerClassification['confidence'] ?? 0) * 100 ?>%"></div>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-1">* คำนวณจากประวัติการสนทนา</p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($customerClassification['tips'])): ?>
                    <div class="bg-purple-50 rounded-lg p-2 text-xs">
                        <p class="font-medium text-purple-700 mb-1">💡 เคล็ดลับการตอบ</p>
                        <?php foreach ((array)$customerClassification['tips'] as $tip): ?>
                        <p class="text-purple-600 text-[11px]">• <?= htmlspecialchars($tip) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-center text-gray-400 text-xs py-2">
                        <p>กำลังโหลดข้อมูล...</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Interaction Checker Widget - Requirements: 4.3 (Moved to bottom) -->
            <div class="hud-widget interaction-widget" id="interactionWidget">
                <div class="hud-widget-header" onclick="toggleWidget('interactionWidget')">
                    <h4><i class="fas fa-exchange-alt text-orange-500"></i> ตรวจสอบปฏิกิริยายา</h4>
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
                <div class="hud-widget-body">
                    <div id="interactionContent" class="text-center text-gray-400 text-xs py-4">
                        <i class="fas fa-check-circle text-green-400 text-2xl mb-2"></i>
                        <p>ไม่พบปฏิกิริยาระหว่างยา</p>
                    </div>
                </div>
            </div>
        </div>
        </div><!-- End hudAIPanel -->
        
        <!-- CRM Mode Panel -->
        <div id="hudCRMPanel" class="hud-scroll" style="display: none; max-height: calc(100vh - 120px); overflow-y: auto;">
            
            <!-- Member Card Mini (Always visible) -->
            <div class="crm-section" style="border-bottom: none;">
                <div class="member-card-mini">
                    <div class="flex items-center justify-between">
                        <span class="tier-badge" id="crmTierBadge">🥉 Member</span>
                        <span class="text-xs opacity-80">ID: <?= str_pad($selectedUser['id'], 6, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="points-display" id="crmPointsDisplay">0</div>
                    <div class="points-label">แต้มคงเหลือ</div>
                </div>
            </div>
            
            <!-- Stats Mini (Collapsible) -->
            <div class="crm-section" id="crmStatsSection">
                <div class="crm-section-header" onclick="HUDMode.toggleSection('crmStatsSection')">
                    <div class="crm-section-title"><i class="fas fa-chart-bar"></i> สถิติ</div>
                    <i class="fas fa-chevron-down crm-section-toggle"></i>
                </div>
                <div class="crm-section-body">
                    <div class="stats-mini-grid">
                        <div class="stat-mini-item highlight">
                            <div class="stat-value" id="crmOrderCount">0</div>
                            <div class="stat-label">ออเดอร์</div>
                        </div>
                        <div class="stat-mini-item">
                            <div class="stat-value" id="crmTotalSpent">฿0</div>
                            <div class="stat-label">ยอดซื้อ</div>
                        </div>
                        <div class="stat-mini-item">
                            <div class="stat-value" id="crmMsgCount">0</div>
                            <div class="stat-label">ข้อความ</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Customer Info (Collapsible + Editable) -->
            <div class="crm-section" id="crmInfoSection">
                <div class="crm-section-header" onclick="HUDMode.toggleSection('crmInfoSection')">
                    <div class="crm-section-title"><i class="fas fa-user"></i> ข้อมูลลูกค้า</div>
                    <i class="fas fa-chevron-down crm-section-toggle"></i>
                </div>
                <div class="crm-section-body">
                    <div class="customer-info-grid">
                        <div class="customer-info-item" id="crm_display_name_container">
                            <div class="info-left">
                                <div class="label">ชื่อ</div>
                                <div class="value" id="crm_display_name"><?= htmlspecialchars($selectedUser['display_name'] ?? '-') ?></div>
                            </div>
                            <button class="edit-btn" onclick="HUDMode.editField('display_name', '<?= htmlspecialchars($selectedUser['display_name'] ?? '') ?>')">
                                <i class="fas fa-pen"></i>
                            </button>
                        </div>
                        <div class="customer-info-item" id="crm_phone_container">
                            <div class="info-left">
                                <div class="label">เบอร์โทร</div>
                                <div class="value" id="crm_phone"><?= htmlspecialchars($selectedUser['phone'] ?? '-') ?></div>
                            </div>
                            <button class="edit-btn" onclick="HUDMode.editField('phone', '<?= htmlspecialchars($selectedUser['phone'] ?? '') ?>')">
                                <i class="fas fa-pen"></i>
                            </button>
                        </div>
                        <div class="customer-info-item" id="crm_address_container">
                            <div class="info-left">
                                <div class="label">ที่อยู่</div>
                                <div class="value" id="crm_address"><?= htmlspecialchars($selectedUser['address'] ?? '-') ?></div>
                            </div>
                            <button class="edit-btn" onclick="HUDMode.editField('address', '<?= htmlspecialchars($selectedUser['address'] ?? '') ?>')">
                                <i class="fas fa-pen"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tags (Collapsible) -->
            <div class="crm-section" id="crmTagsSection">
                <div class="crm-section-header" onclick="HUDMode.toggleSection('crmTagsSection')">
                    <div class="crm-section-title"><i class="fas fa-tags"></i> TAGS</div>
                    <i class="fas fa-chevron-down crm-section-toggle"></i>
                </div>
                <div class="crm-section-body">
                    <div class="tags-container" id="crmTagsContainer">
                        <button class="add-tag-btn" onclick="HUDMode.showTagSelector()">+ เพิ่ม Tag</button>
                    </div>
                </div>
            </div>
            
            <!-- Notes (Collapsible) -->
            <div class="crm-section" id="crmNotesSection">
                <div class="crm-section-header" onclick="HUDMode.toggleSection('crmNotesSection')">
                    <div class="crm-section-title"><i class="fas fa-sticky-note"></i> โน้ต</div>
                    <i class="fas fa-chevron-down crm-section-toggle"></i>
                </div>
                <div class="crm-section-body">
                    <div class="notes-list" id="crmNotesList">
                        <div class="notes-empty">ยังไม่มีโน้ต</div>
                    </div>
                    <div class="add-note-form">
                        <textarea id="crmNoteInput" placeholder="เพิ่มโน้ต..."></textarea>
                        <button class="add-note-btn" onclick="HUDMode.addNote()">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Recent Transactions (Collapsible) -->
            <div class="crm-section" id="crmTransactionsSection">
                <div class="crm-section-header" onclick="HUDMode.toggleSection('crmTransactionsSection')">
                    <div class="crm-section-title"><i class="fas fa-receipt"></i> รายการล่าสุด</div>
                    <i class="fas fa-chevron-down crm-section-toggle"></i>
                </div>
                <div class="crm-section-body">
                    <div class="transaction-mini-list" id="crmTransactionsList">
                        <div class="notes-empty">ยังไม่มีรายการ</div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Edit Button -->
            <div class="crm-section" style="padding: 12px;">
                <button class="quick-edit-btn" onclick="HUDMode.openUserDetail()">
                    <i class="fas fa-external-link-alt"></i>
                    ดูรายละเอียดเพิ่มเติม
                </button>
            </div>
            
        </div><!-- End hudCRMPanel -->
        
    </div>
    <?php endif; ?>
</div>

<!-- Notification Container -->
<div id="notificationContainer" class="notification-container"></div>

<!-- Real-time Status Indicator -->
<?php if ($currentTab === 'inbox'): ?>
<div id="realtimeIndicator" class="realtime-indicator" title="Real-time updates active">
    <div class="pulse"></div>
    <span>Live</span>
</div>
<?php endif; ?>

<!-- Audio for notifications -->
<audio id="notificationSound" preload="auto">
    <source src="data:audio/mpeg;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAABhgC7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7//////////////////////////////////////////////////////////////////8AAAAATGF2YzU4LjEzAAAAAAAAAAAAAAAAJAAAAAAAAAAAAYYNBrv2AAAAAAAAAAAAAAAAAAAAAP/7UMQAA8AAADSAAAAAAAAANIAAAAATEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVX/+1DEAYPAAADSAAAAAAAAANIAAAAATEFNRTMuMTAwVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVU=" type="audio/mpeg">
</audio>

<!-- Real-time Updates Script -->
<script src="assets/js/inbox-realtime.js?v=<?= time() ?>"></script>
<!-- FAB & HUD Mode Switcher -->
<script src="assets/js/inbox-v2-fab.js?v=<?= time() ?>"></script>
<script>
// Set global variables for FAB/HUD
window.currentBotId = <?= $currentBotId ?>;
</script>
<script>
/**
 * Real-time Inbox Updates - Auto-refresh conversations and messages
 * 
 * Features:
 * - Auto-move conversations with new messages to top
 * - Real-time message updates in active chat
 * - Sound notification for new messages
 * - Desktop notification when tab is not active
 */

// Initialize real-time updates when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize for inbox tab
    <?php if ($currentTab === 'inbox'): ?>
    
    // DEBUG: Add MutationObserver to track what's changing .last-msg for user 15
    const jameItem = document.querySelector('a[href*="user=15"]');
    if (jameItem) {
        const lastMsgEl = jameItem.querySelector('.last-msg');
        if (lastMsgEl) {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'characterData' || mutation.type === 'childList') {
                        console.log('[MutationObserver] jame.ver .last-msg changed to:', lastMsgEl.textContent);
                        console.trace('[MutationObserver] Stack trace:');
                    }
                });
            });
            observer.observe(lastMsgEl, { 
                characterData: true, 
                childList: true, 
                subtree: true 
            });
            console.log('[MutationObserver] Watching jame.ver .last-msg, initial value:', lastMsgEl.textContent);
        }
    }
    
    InboxRealtime.init({
        userId: <?= $selectedUser ? $selectedUser['id'] : 'null' ?>,
        lineAccountId: <?= $currentBotId ?>,
        pollInterval: 3000, // Poll every 3 seconds
        enableSound: true,
        enableDesktopNotification: true,
        apiLineAccountId: <?= $currentBotId ?>, // Explicit line account ID for API calls
        
        // Callback when new messages arrive
        onNewMessage: function(data) {
            console.log('[Inbox] New messages:', data.new_count);
            
            // Show notification toast
            if (typeof showNotification === 'function') {
                showNotification(`📬 มี ${data.new_count} ข้อความใหม่`, 'info');
            }
        },
        
        // Callback when conversation list updates
        onConversationUpdate: function(conversations) {
            console.log('[Inbox] onConversationUpdate called with', conversations.length, 'conversations');
            updateConversationListUI(conversations);
        },
        
        // Error callback
        onError: function(error) {
            console.error('[Inbox] Real-time error:', error);
        }
    });
    
    // Start polling - RE-ENABLED for testing
    InboxRealtime.start();
    console.log('[Inbox] Realtime polling ENABLED');
    
    // Stop polling when page is hidden, resume when visible
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            // Keep polling but at slower rate when hidden
        } else {
            // Resume normal polling
            if (!InboxRealtime.isRunning()) {
                InboxRealtime.start();
            }
        }
    });
    
    <?php endif; ?>
});

/**
 * Update conversation list UI with new data
 * @param {array} conversations Updated conversation list
 */
function updateConversationListUI(conversations) {
    const container = document.getElementById('userList');
    if (!container) {
        console.warn('[updateConversationListUI] Container not found');
        return;
    }
    
    const currentUserId = <?= $selectedUser ? $selectedUser['id'] : 'null' ?>;
    
    console.log('[updateConversationListUI] Container found, updating', conversations.length, 'conversations');
    
    // Debug: Log API data for jame.ver (id=15)
    const jameData = conversations.find(c => c.id === 15);
    if (jameData) {
        console.log('[updateConversationListUI] API DATA for jame.ver:', JSON.stringify(jameData));
    }
    
    conversations.forEach((conv, index) => {
        // Use data-user-id for exact match instead of href contains (which can match partial IDs)
        const existingItem = container.querySelector(`a[data-user-id="${conv.id}"]`);
        
        // Debug: log for first 3 conversations
        if (index < 3) {
            console.log(`[updateConversationListUI] Conv ${conv.id} (${conv.display_name}): element found = ${!!existingItem}, API last_message = "${conv.last_message}"`);
        }
        
        if (existingItem) {
            // Update last message preview - use .last-msg class
            const lastMsgEl = existingItem.querySelector('.last-msg');
            if (lastMsgEl) {
                const prefix = conv.last_direction === 'outgoing' ? 'คุณ: ' : '';
                const newText = prefix + conv.last_message;
                
                // Debug: log if jame.ver (id=15) or Kratae (id=492)
                if (conv.id === 15 || conv.id === 492) {
                    const initialValue = lastMsgEl.dataset.initial || 'N/A';
                    console.log(`[updateConversationListUI] ${conv.display_name} (${conv.id}): initial="${initialValue}" DOM="${lastMsgEl.textContent}" -> API="${newText}" (raw: "${conv.last_message}")`);
                }
                
                lastMsgEl.textContent = newText;
            }
            
            // Update time - use .last-time class
            const timeEl = existingItem.querySelector('.last-time');
            if (timeEl) {
                timeEl.textContent = conv.last_time_formatted;
            }
            
            // Update unread badge
            let badgeEl = existingItem.querySelector('.unread-badge');
            if (conv.unread_count > 0) {
                if (!badgeEl) {
                    // Create badge if doesn't exist
                    const avatarContainer = existingItem.querySelector('.relative');
                    if (avatarContainer) {
                        badgeEl = document.createElement('div');
                        badgeEl.className = 'unread-badge absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-5 h-5 flex items-center justify-center rounded-full font-bold';
                        avatarContainer.appendChild(badgeEl);
                    }
                }
                if (badgeEl) {
                    badgeEl.textContent = conv.unread_count > 9 ? '9+' : conv.unread_count;
                    badgeEl.style.display = 'flex';
                }
            } else if (badgeEl) {
                badgeEl.style.display = 'none';
            }
            
            // Move to top if has new messages and not already at top
            if (conv.unread_count > 0 && index === 0) {
                const currentIndex = Array.from(container.children).indexOf(existingItem);
                if (currentIndex > 0) {
                    container.prepend(existingItem);
                    // Add highlight animation
                    existingItem.style.animation = 'highlightNew 2s ease-out';
                    setTimeout(() => {
                        existingItem.style.animation = '';
                    }, 2000);
                }
            }
        }
    });
}

/**
 * Append new message to chat (called by InboxRealtime)
 * @param {object} msg Message object
 */
function appendNewMessageToChat(msg) {
    const chatContainer = document.getElementById('chatBox');
    if (!chatContainer) return;
    
    // Check if message already exists
    if (chatContainer.querySelector(`[data-msg-id="${msg.id}"]`)) {
        return;
    }
    
    const isIncoming = msg.direction === 'incoming';
    const alignClass = isIncoming ? '' : 'flex-row-reverse';
    const bgClass = isIncoming ? 'bg-white' : 'bg-emerald-500 text-white';
    const roundedClass = isIncoming ? 'rounded-tl-none' : 'rounded-tr-none';
    
    let contentHtml = '';
    
    switch (msg.type) {
        case 'image':
            contentHtml = `<img src="${escapeHtml(msg.content)}" class="max-w-[200px] rounded-lg cursor-pointer" onclick="window.open('${escapeHtml(msg.content)}', '_blank')">`;
            break;
        case 'sticker':
            contentHtml = `<div class="text-4xl">😊</div>`;
            break;
        default:
            contentHtml = `<div class="whitespace-pre-wrap break-words">${escapeHtml(msg.content)}</div>`;
    }
    
    const messageHtml = `
        <div class="flex gap-2 ${alignClass}" data-msg-id="${msg.id}" style="animation: fadeIn 0.3s ease-out;">
            ${isIncoming ? `<img src="<?= $selectedUser ? htmlspecialchars($selectedUser['picture_url'] ?: '') : '' ?>" class="w-7 h-7 rounded-full self-end" onerror="this.style.display='none'">` : ''}
            <div class="flex flex-col ${isIncoming ? 'items-start' : 'items-end'}" style="max-width:70%">
                <div class="${bgClass} rounded-2xl ${roundedClass} px-4 py-2 shadow-sm">
                    ${contentHtml}
                </div>
                <div class="msg-meta flex items-center gap-1 text-[10px] ${isIncoming ? 'text-gray-500' : 'text-gray-400'} mt-1">
                    ${msg.time}
                    ${!isIncoming && msg.sent_by ? `<span class="sender-badge admin"><i class="fas fa-user-shield"></i> ${escapeHtml(msg.sent_by.replace('admin:', ''))}</span>` : ''}
                </div>
            </div>
        </div>
    `;
    
    chatContainer.insertAdjacentHTML('beforeend', messageHtml);
    
    // Scroll to bottom
    chatContainer.scrollTop = chatContainer.scrollHeight;
    
    // Play notification sound for incoming messages
    if (isIncoming) {
        playNotificationSound();
    }
}

/**
 * Play notification sound
 */
function playNotificationSound() {
    try {
        const audio = document.getElementById('notificationSound');
        if (audio) {
            audio.currentTime = 0;
            audio.play().catch(() => {});
        }
    } catch (e) {}
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Add CSS for animations
const realtimeStyles = document.createElement('style');
realtimeStyles.textContent = `
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes highlightNew {
        0% { background-color: rgba(16, 185, 129, 0.3); }
        100% { background-color: transparent; }
    }
    .realtime-indicator {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #10B981;
        color: white;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        z-index: 1000;
    }
    .realtime-indicator .pulse {
        width: 8px;
        height: 8px;
        background: white;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
`;
document.head.appendChild(realtimeStyles);
</script>

<!-- Ghost Draft & Inbox V2 JavaScript -->
<script>
/**
 * Ghost Draft UI JavaScript - Vibe Selling OS v2
 * 
 * Requirements: 6.2, 6.3, 6.4, 6.5
 * - 6.2: Display ghost draft as faded text in input field
 * - 6.3: Tab to accept ghost draft
 * - 6.4: Type to replace ghost draft
 * - 6.5: Learn from pharmacist edits
 */

// Ghost Draft State
const ghostDraftState = {
    currentDraft: null,
    originalDraft: null,
    isGenerating: false,
    userId: <?= $selectedUser ? $selectedUser['id'] : 'null' ?>,
    lastCustomerMessage: '',
    draftAccepted: false
};

// Order State - for managing items to add to order
const orderState = {
    items: [],
    subtotal: 0,
    discount: 0,
    total: 0
};

/**
 * Add drug to order
 * @param {number} drugId Drug ID
 * @param {string} drugName Drug name
 * @param {number} price Drug price
 */
function addDrugToOrder(drugId, drugName, price) {
    // Check if already in order
    const existingIndex = orderState.items.findIndex(item => item.id === drugId);
    
    if (existingIndex >= 0) {
        // Increase quantity
        orderState.items[existingIndex].qty += 1;
    } else {
        // Add new item
        orderState.items.push({
            id: drugId,
            name: drugName,
            price: price,
            qty: 1
        });
    }
    
    // Update totals
    updateOrderTotals();
    
    // Show notification
    showNotification(`เพิ่ม "${drugName}" ในออเดอร์แล้ว (${orderState.items.length} รายการ)`, 'success');
    
    // Update order modal if open
    updateOrderItemsList();
}

/**
 * Update order totals
 */
function updateOrderTotals() {
    orderState.subtotal = orderState.items.reduce((sum, item) => sum + (item.price * item.qty), 0);
    orderState.total = orderState.subtotal - orderState.discount;
}

/**
 * Update order items list in modal
 */
function updateOrderItemsList() {
    const container = document.getElementById('orderItemsList');
    if (!container) return;
    
    if (orderState.items.length === 0) {
        container.innerHTML = `
            <p class="text-gray-500 text-sm text-center py-4">
                <i class="fas fa-info-circle mr-1"></i>
                เลือกยาจาก HUD Dashboard แล้วกด "เพิ่มในออเดอร์"
            </p>
        `;
    } else {
        container.innerHTML = orderState.items.map((item, index) => `
            <div class="flex items-center justify-between p-2 bg-gray-50 rounded-lg">
                <div class="flex-1">
                    <div class="text-sm font-medium">${escapeHtml(item.name)}</div>
                    <div class="text-xs text-gray-500">฿${item.price.toLocaleString()} x ${item.qty}</div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-green-600">฿${(item.price * item.qty).toLocaleString()}</span>
                    <button onclick="removeFromOrder(${index})" class="text-red-500 hover:text-red-700 p-1">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    // Update totals display
    const subtotalEl = document.getElementById('orderSubtotal');
    const discountEl = document.getElementById('orderDiscount');
    const totalEl = document.getElementById('orderTotal');
    
    if (subtotalEl) subtotalEl.textContent = `฿${orderState.subtotal.toLocaleString()}`;
    if (discountEl) discountEl.textContent = `-฿${orderState.discount.toLocaleString()}`;
    if (totalEl) totalEl.textContent = `฿${orderState.total.toLocaleString()}`;
}

/**
 * Remove item from order
 * @param {number} index Item index
 */
function removeFromOrder(index) {
    if (index >= 0 && index < orderState.items.length) {
        const removed = orderState.items.splice(index, 1)[0];
        updateOrderTotals();
        updateOrderItemsList();
        showNotification(`ลบ "${removed.name}" ออกจากออเดอร์แล้ว`, 'info');
    }
}

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Generate Ghost Draft - Requirements: 6.1, 6.2
 * Calls API to generate AI draft and displays as faded text
 */
async function generateGhostDraft() {
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    if (ghostDraftState.isGenerating) {
        return;
    }
    
    // Get last customer message from chat
    const lastCustomerMsg = getLastCustomerMessage();
    if (!lastCustomerMsg) {
        showNotification('ไม่พบข้อความจากลูกค้า', 'warning');
        return;
    }
    
    ghostDraftState.isGenerating = true;
    ghostDraftState.lastCustomerMessage = lastCustomerMsg;
    
    // Show loading indicator
    const indicator = document.getElementById('ghostDraftIndicator');
    const ghostText = document.getElementById('ghostText');
    
    if (indicator) {
        indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>กำลังสร้าง...</span>';
        indicator.classList.remove('hidden');
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'ghost_draft');
        formData.append('user_id', ghostDraftState.userId);
        formData.append('message', lastCustomerMsg);
        
        // Add context from conversation
        const context = getConversationContext();
        formData.append('context', JSON.stringify(context));
        
        const response = await fetch('api/inbox-v2.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.data && result.data.draft) {
            // Store the draft
            ghostDraftState.currentDraft = result.data.draft;
            ghostDraftState.originalDraft = result.data.draft;
            ghostDraftState.draftAccepted = false;
            
            // Display as faded text - Requirements: 6.2
            displayGhostDraft(result.data.draft);
            
            // Update indicator
            if (indicator) {
                indicator.innerHTML = '<i class="fas fa-magic"></i><span>Tab เพื่อใช้</span>';
            }
            
            // Show confidence if available
            if (result.data.confidence) {
                const confidencePercent = Math.round(result.data.confidence * 100);
                showNotification(`Ghost Draft พร้อมใช้งาน (${confidencePercent}% confidence)`, 'success');
            }
            
            // Show disclaimer warning if present
            if (result.data.disclaimer) {
                showNotification('⚠️ มียาที่ต้องใช้ตามคำสั่งแพทย์', 'warning');
            }
        } else {
            throw new Error(result.error || 'ไม่สามารถสร้าง Ghost Draft ได้');
        }
    } catch (error) {
        console.error('Ghost Draft error:', error);
        showNotification(error.message || 'เกิดข้อผิดพลาดในการสร้าง Ghost Draft', 'error');
        clearGhostDraft();
    } finally {
        ghostDraftState.isGenerating = false;
    }
}

/**
 * Display ghost draft as faded text - Requirements: 6.2
 * @param {string} draft The draft text to display
 */
function displayGhostDraft(draft) {
    const ghostText = document.getElementById('ghostText');
    const messageInput = document.getElementById('messageInput');
    const indicator = document.getElementById('ghostDraftIndicator');
    
    if (ghostText && messageInput) {
        // Only show ghost text if input is empty
        if (messageInput.value.trim() === '') {
            ghostText.textContent = draft;
            ghostText.classList.remove('hidden');
            
            // Copy input styles to ghost text for alignment
            const inputStyles = window.getComputedStyle(messageInput);
            ghostText.style.padding = inputStyles.padding;
            ghostText.style.fontSize = inputStyles.fontSize;
            ghostText.style.lineHeight = inputStyles.lineHeight;
            ghostText.style.fontFamily = inputStyles.fontFamily;
        }
        
        if (indicator) {
            indicator.classList.remove('hidden');
        }
    }
}

/**
 * Clear ghost draft display
 */
function clearGhostDraft() {
    const ghostText = document.getElementById('ghostText');
    const indicator = document.getElementById('ghostDraftIndicator');
    
    if (ghostText) {
        ghostText.textContent = '';
        ghostText.classList.add('hidden');
    }
    
    if (indicator) {
        indicator.classList.add('hidden');
    }
    
    ghostDraftState.currentDraft = null;
}

/**
 * Handle keyboard input - Requirements: 6.3, 6.4
 * Tab to accept, typing replaces ghost draft
 * @param {KeyboardEvent} event
 */
function handleKeyDown(event) {
    const messageInput = document.getElementById('messageInput');
    
    // Tab to accept ghost draft - Requirements: 6.3
    if (event.key === 'Tab' && ghostDraftState.currentDraft && messageInput.value.trim() === '') {
        event.preventDefault();
        acceptGhostDraft();
        return;
    }
    
    // Enter to send (with Shift for newline)
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        document.getElementById('sendForm').dispatchEvent(new Event('submit'));
        return;
    }
}

/**
 * Handle message input changes - Requirements: 6.4
 * Typing replaces ghost draft
 * @param {HTMLTextAreaElement} input
 */
function handleMessageInput(input) {
    const ghostText = document.getElementById('ghostText');
    
    // If user starts typing, hide ghost draft - Requirements: 6.4
    if (input.value.trim() !== '' && ghostDraftState.currentDraft) {
        if (ghostText) {
            ghostText.classList.add('hidden');
        }
    } else if (input.value.trim() === '' && ghostDraftState.currentDraft) {
        // Show ghost draft again if input is cleared
        displayGhostDraft(ghostDraftState.currentDraft);
    }
    
    // Auto-update HUD widgets based on message context - Requirements: 4.1, 4.2
    if (typeof onMessageInputChange === 'function') {
        onMessageInputChange(input.value);
    }
}

/**
 * Accept ghost draft - Requirements: 6.3
 * Fills input with draft text
 */
function acceptGhostDraft() {
    const messageInput = document.getElementById('messageInput');
    
    if (ghostDraftState.currentDraft && messageInput) {
        messageInput.value = ghostDraftState.currentDraft;
        ghostDraftState.draftAccepted = true;
        clearGhostDraft();
        
        // Trigger resize
        autoResize(messageInput);
        
        // Focus input for editing
        messageInput.focus();
        
        showNotification('Ghost Draft ถูกใช้งาน - แก้ไขได้ตามต้องการ', 'info');
    }
}

/**
 * Learn from pharmacist edit - Requirements: 6.5
 * Called when message is sent after using ghost draft
 * @param {string} finalMessage The final message sent by pharmacist
 */
async function learnFromEdit(finalMessage) {
    // Only learn if we had an original draft
    if (!ghostDraftState.originalDraft || !ghostDraftState.userId) {
        return;
    }
    
    // Skip if draft wasn't used at all
    if (!ghostDraftState.draftAccepted && ghostDraftState.originalDraft !== finalMessage) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'learn_draft');
        formData.append('user_id', ghostDraftState.userId);
        formData.append('original_draft', ghostDraftState.originalDraft);
        formData.append('final_message', finalMessage);
        
        // Add context
        const context = {
            customerMessage: ghostDraftState.lastCustomerMessage,
            stage: getCurrentConsultationStage()
        };
        formData.append('context', JSON.stringify(context));
        
        const response = await fetch('api/inbox-v2.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            console.log('Ghost Draft learning saved');
        }
    } catch (error) {
        console.error('Failed to save learning data:', error);
    } finally {
        // Reset draft state
        ghostDraftState.originalDraft = null;
        ghostDraftState.draftAccepted = false;
    }
}

/**
 * Get last customer message from chat
 * @returns {string|null}
 */
function getLastCustomerMessage() {
    const chatBox = document.getElementById('chatBox');
    if (!chatBox) return null;
    
    // Find all incoming messages (from customer) - fixed selector
    const incomingMessages = chatBox.querySelectorAll('.message-item.justify-start .chat-incoming');
    
    if (incomingMessages.length > 0) {
        const lastMsg = incomingMessages[incomingMessages.length - 1];
        return lastMsg.textContent.trim();
    }
    
    return null;
}

/**
 * Get conversation context for ghost draft
 * @returns {object}
 */
function getConversationContext() {
    const chatBox = document.getElementById('chatBox');
    const context = {
        conversationHistory: [],
        stage: getCurrentConsultationStage()
    };
    
    if (chatBox) {
        const messages = chatBox.querySelectorAll('.message-item');
        const recentMessages = Array.from(messages).slice(-10);
        
        recentMessages.forEach(msg => {
            const isOutgoing = msg.querySelector('.justify-end') !== null;
            const bubble = msg.querySelector('.chat-bubble');
            if (bubble) {
                context.conversationHistory.push({
                    role: isOutgoing ? 'pharmacist' : 'customer',
                    content: bubble.textContent.trim()
                });
            }
        });
    }
    
    return context;
}

/**
 * Get current consultation stage
 * @returns {string}
 */
function getCurrentConsultationStage() {
    // This could be enhanced to detect stage from conversation
    return 'drug_recommendation';
}

/**
 * Auto-resize textarea
 * @param {HTMLTextAreaElement} textarea
 */
function autoResize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 96) + 'px';
}

/**
 * Send message with learning integration
 * @param {Event} event
 */
async function sendMessage(event) {
    event.preventDefault();
    
    const form = event.target;
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();
    
    if (!message) return;
    
    const userId = form.querySelector('input[name="user_id"]').value;
    const sendBtn = document.getElementById('sendBtn');
    
    // Disable button
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('user_id', userId);
        formData.append('message', message);
        
        const response = await fetch('inbox-v2.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add message to chat
            appendMessage(result.content, true, result.time, result.sent_by);
            
            // Clear input
            messageInput.value = '';
            autoResize(messageInput);
            
            // Learn from edit if ghost draft was used - Requirements: 6.5
            await learnFromEdit(message);
            
            // Clear ghost draft state
            clearGhostDraft();
            ghostDraftState.originalDraft = null;
            
            // Scroll to bottom
            scrollToBottom();
            
            // Refresh quick actions based on new conversation context
            onConversationUpdate();
        } else {
            throw new Error(result.error || 'ส่งข้อความไม่สำเร็จ');
        }
    } catch (error) {
        console.error('Send message error:', error);
        showNotification(error.message || 'เกิดข้อผิดพลาด', 'error');
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
    }
}

/**
 * Append message to chat box
 * @param {string} content Message content
 * @param {boolean} isOutgoing Is outgoing message
 * @param {string} time Message time
 * @param {string} sentBy Sender info
 */
function appendMessage(content, isOutgoing, time, sentBy) {
    const chatBox = document.getElementById('chatBox');
    if (!chatBox) return;
    
    const msgDiv = document.createElement('div');
    msgDiv.className = `message-item flex ${isOutgoing ? 'justify-end' : 'justify-start'} group`;
    
    let senderBadge = '';
    if (isOutgoing && sentBy) {
        if (sentBy.startsWith('admin:')) {
            const name = sentBy.substring(6);
            senderBadge = `<span class="sender-badge admin"><i class="fas fa-user-shield"></i> ${escapeHtml(name)}</span>`;
        }
    }
    
    msgDiv.innerHTML = `
        <div class="flex flex-col ${isOutgoing ? 'items-end' : 'items-start'}" style="max-width:70%">
            <div class="chat-bubble px-4 py-2.5 text-sm shadow-sm ${isOutgoing ? 'chat-outgoing' : 'chat-incoming'}">
                ${escapeHtml(content).replace(/\n/g, '<br>')}
            </div>
            <div class="msg-meta flex items-center gap-1 text-[10px] ${isOutgoing ? 'text-white/70' : 'text-gray-500'} mt-1">
                <span>${time || new Date().toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'})} น.</span>
                ${senderBadge}
            </div>
        </div>
    `;
    
    chatBox.appendChild(msgDiv);
}

/**
 * Escape HTML entities
 * @param {string} text
 * @returns {string}
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Escape string for use in HTML attributes (especially onclick)
 * @param {string} text
 * @returns {string}
 */
function escapeAttr(text) {
    if (!text) return '';
    return String(text)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\"')
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '\\r');
}

/**
 * Scroll chat to bottom
 */
function scrollToBottom() {
    const chatBox = document.getElementById('chatBox');
    if (chatBox) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

/**
 * Show notification toast
 * @param {string} message
 * @param {string} type success|error|warning|info
 */
function showNotification(message, type = 'info') {
    const container = document.getElementById('notificationContainer');
    if (!container) return;
    
    const colors = {
        success: '#10B981',
        error: '#EF4444',
        warning: '#F59E0B',
        info: '#8B5CF6'
    };
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    const toast = document.createElement('div');
    toast.className = 'notification-toast';
    toast.style.borderLeftColor = colors[type] || colors.info;
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}" style="color: ${colors[type] || colors.info}"></i>
        <span class="text-sm text-gray-700">${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    // Auto remove after 4 seconds
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
    
    // Click to dismiss
    toast.onclick = () => toast.remove();
}

/**
 * HUD Dashboard Functions - Vibe Selling OS v2
 * 
 * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6
 * - 4.1: Symptom keyword triggers drug widget
 * - 4.2: Drug name triggers info widget
 * - 4.3: Drug interactions widget
 * - 4.4: Pregnancy-safe drugs widget
 * - 4.5: Allergy warning widget
 * - 4.6: Smooth widget transitions (300ms)
 */

// HUD State Management
const hudState = {
    isVisible: true,
    isRefreshing: false,
    lastMessage: '',
    lastRefresh: null,
    activeWidgets: [],
    widgetCache: new Map(),
    autoUpdateEnabled: true,
    refreshDebounceTimer: null
};

/**
 * Toggle HUD Dashboard visibility
 * Requirements: 4.6 - Smooth transitions
 */
function toggleHUD() {
    const hud = document.getElementById('hudDashboard');
    const chatArea = document.getElementById('chatArea');
    
    if (hud) {
        hud.classList.toggle('collapsed');
        hudState.isVisible = !hud.classList.contains('collapsed');
        
        // Adjust chat area margin
        if (chatArea) {
            if (hud.classList.contains('collapsed')) {
                chatArea.classList.add('hud-hidden');
            } else {
                chatArea.classList.remove('hud-hidden');
            }
        }
        
        // On mobile, toggle mobile-visible class
        if (window.innerWidth <= 768) {
            hud.classList.toggle('mobile-visible');
        }
        
        // If becoming visible, refresh widgets
        if (hudState.isVisible && ghostDraftState.userId) {
            refreshHUD();
        }
    }
}

/**
 * Refresh HUD Dashboard - Fetch all widget data from API
 * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
 */
async function refreshHUD() {
    if (!ghostDraftState.userId || hudState.isRefreshing) {
        return;
    }
    
    hudState.isRefreshing = true;
    const refreshBtn = document.querySelector('#hudDashboard .fa-sync-alt');
    
    // Show loading state
    if (refreshBtn) {
        refreshBtn.classList.add('fa-spin');
    }
    
    try {
        // Get last customer message for context
        const lastMessage = getLastCustomerMessage() || '';
        
        // Fetch context-aware widgets from API
        const params = new URLSearchParams({
            action: 'context_widgets',
            user_id: ghostDraftState.userId,
            message: lastMessage,
            line_account_id: <?= $currentBotId ?>
        });
        
        const response = await fetch(`api/inbox-v2.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success && result.data) {
            hudState.activeWidgets = result.data.widgets || [];
            hudState.lastRefresh = new Date();
            hudState.lastMessage = lastMessage;
            
            // Update widgets with smooth transitions - Requirements: 4.6
            await updateHUDWidgets(result.data.widgets);
            
            showNotification('HUD อัพเดทแล้ว', 'success');
        } else {
            throw new Error(result.error || 'Failed to refresh HUD');
        }
    } catch (error) {
        console.error('Refresh HUD error:', error);
        showNotification('ไม่สามารถรีเฟรช HUD ได้', 'error');
    } finally {
        hudState.isRefreshing = false;
        if (refreshBtn) {
            refreshBtn.classList.remove('fa-spin');
        }
    }
}

/**
 * Update HUD widgets with data from API
 * Requirements: 4.6 - Smooth widget transitions within 300ms
 * @param {Array} widgets Widget data from API
 */
async function updateHUDWidgets(widgets) {
    const hudWidgetsContainer = document.getElementById('hudWidgets');
    if (!hudWidgetsContainer) return;
    
    // Process each widget type
    for (const widget of widgets) {
        await updateWidgetByType(widget);
    }
}

/**
 * Update specific widget by type
 * @param {Object} widget Widget data
 */
async function updateWidgetByType(widget) {
    switch (widget.type) {
        case 'symptom':
            updateSymptomWidget(widget);
            break;
        case 'drug_info':
            updateDrugInfoWidget(widget);
            break;
        case 'interaction':
            updateInteractionWidget(widget);
            break;
        case 'allergy':
            updateAllergyWidget(widget);
            break;
        case 'pricing':
            updatePricingWidget(widget);
            break;
        case 'pregnancy':
            updatePregnancyWidget(widget);
            break;
    }
}

/**
 * Update Symptom Widget - Wholesale Mode
 * Focus on product name detection from customer message
 * Shows products in same format as Drug Recommendations widget
 * @param {Object} data Widget data
 */
function updateSymptomWidget(data) {
    const content = document.getElementById('symptomContent');
    if (!content) return;
    
    // Fade out animation
    content.style.opacity = '0';
    content.style.transition = 'opacity 0.15s ease';
    
    setTimeout(() => {
        if (data.recommendations && data.recommendations.length > 0) {
            const recommendations = data.recommendations;
            const dataType = data.type || 'unknown';
            const found = recommendations.filter(d => d.stock > 0);
            const notFound = recommendations.filter(d => d.stock <= 0);
            
            // Helper function to get match badge based on score and type
            const getMatchBadge = (drug) => {
                const matchType = drug.matchType || 'partial';
                const score = drug.matchScore || 0;
                
                if (matchType === 'exact' || score >= 200) {
                    return `<span class="text-[9px] bg-green-500 text-white px-1 rounded font-medium">✓ ตรงเป๊ะ</span>`;
                } else if (matchType === 'recent' || score >= 100) {
                    return `<span class="text-[9px] bg-blue-500 text-white px-1 rounded">🕐 ล่าสุด</span>`;
                } else if (score >= 50) {
                    return `<span class="text-[9px] bg-green-100 text-green-600 px-1 rounded">ตรงกัน</span>`;
                } else if (score >= 20) {
                    return `<span class="text-[9px] bg-gray-100 text-gray-500 px-1 rounded">คล้าย</span>`;
                }
                return '';
            };
            
            // Get border class based on match score
            const getBorderClass = (drug) => {
                const matchType = drug.matchType || 'partial';
                const score = drug.matchScore || 0;
                
                if (matchType === 'exact' || score >= 200) return 'border-l-4 border-green-500 bg-green-50';
                if (matchType === 'recent' || score >= 100) return 'border-l-4 border-blue-400 bg-blue-50';
                if (score >= 50) return 'border-l-4 border-green-300 bg-green-50';
                return 'border-l-4 border-gray-200 bg-gray-50';
            };
            
            // Get source label
            const getSourceLabel = () => {
                if (dataType === 'chat_history') return '📝 จากประวัติแชท';
                if (dataType === 'message_search') return '💬 จากข้อความ';
                if (dataType === 'context') return '💬 จากข้อความล่าสุด';
                return '📊 ยอดนิยม';
            };
            
            // Extract customer request keywords from message
            const customerRequest = data.customerRequest || data.searchTerms || [];
            
            let html = '';
            
            // Show customer request summary if available
            if (customerRequest.length > 0 || data.originalMessage) {
                html += `
                    <div class="mb-2 p-2 bg-blue-50 border border-blue-100 rounded-lg">
                        <div class="text-[10px] text-blue-600 font-medium mb-1">📋 ลูกค้าต้องการ:</div>
                        <div class="text-xs text-blue-800">${customerRequest.length > 0 ? customerRequest.join(', ') : (data.originalMessage || '-')}</div>
                    </div>
                `;
            }
            
            html += `
                <div class="text-[10px] text-gray-400 mb-2 flex items-center justify-between">
                    <span>${getSourceLabel()}</span>
                    <span class="text-emerald-500 font-medium">${found.length} พบ${notFound.length > 0 ? ` / ${notFound.length} หมด` : ''}</span>
                </div>
            `;
            
            // Show found items with checkboxes (default unchecked)
            if (found.length > 0) {
                html += `<div class="space-y-1.5 max-h-64 overflow-y-auto" id="detectedProductsList">`;
                found.slice(0, 10).forEach((drug, index) => {
                    const drugId = drug.id || drug.drugId || 0;
                    const drugNameSafe = escapeAttr(drug.name || '');
                    const drugPriceSafe = drug.price || 0;
                    const matchBadge = getMatchBadge(drug);
                    const borderClass = getBorderClass(drug);
                    const stockClass = drug.stock > 10 ? 'text-green-500' : 'text-yellow-500';
                    const subText = drug.sku || drug.nameEn || drug.category || '';
                    
                    html += `
                        <div class="flex items-center p-2 rounded hover:bg-gray-100 ${borderClass}">
                            <input type="checkbox" 
                                   id="drug_check_${drugId}" 
                                   class="detected-drug-checkbox"
                                   data-drug-id="${drugId}"
                                   data-drug-name="${drugNameSafe}"
                                   data-drug-price="${drugPriceSafe}"
                                   onchange="updateSelectedTotal()">
                            <div class="flex-1 min-w-0 cursor-pointer" onclick="selectDrugForInfo(${drugId}, '${drugNameSafe}')">
                                <div class="text-xs font-medium text-gray-800 truncate flex items-center gap-1">
                                    ${escapeHtml(drug.name || '')} ${matchBadge}
                                </div>
                                <div class="text-[10px] text-gray-500 truncate">${escapeHtml(subText)}</div>
                            </div>
                            <div class="text-right ml-2 flex-shrink-0">
                                <div class="text-xs font-medium text-green-600">฿${drugPriceSafe.toLocaleString()}</div>
                                <div class="text-[10px] ${stockClass}">
                                    ${drug.stock > 10 ? 'มีสินค้า' : 'เหลือ ' + drug.stock}
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
                if (found.length > 10) {
                    html += `<div class="text-center text-[10px] text-gray-400 mt-1">แสดง 10 จาก ${found.length} รายการ</div>`;
                }
            }
            
            // Show not found items
            if (notFound.length > 0) {
                html += `
                    <div class="mt-2 p-2 bg-red-50 border border-red-100 rounded-lg">
                        <div class="text-[10px] text-red-600 font-medium">❌ ไม่พบ/หมด: ${notFound.map(d => d.name).join(', ')}</div>
                    </div>
                `;
            }
            
            // Add selected to order button
            if (found.length > 0) {
                html += `
                    <div class="mt-2 p-2 bg-gray-50 rounded-lg">
                        <div class="flex justify-between items-center mb-2">
                            <label class="flex items-center text-[10px] text-gray-600 cursor-pointer">
                                <input type="checkbox" id="selectAllProducts" class="mr-1 accent-emerald-500" onchange="toggleSelectAll(this)">
                                เลือกทั้งหมด
                            </label>
                            <span id="selectedTotal" class="text-[10px] font-medium text-emerald-600">0 รายการ | ฿0</span>
                        </div>
                        <button onclick="addSelectedToOrder()" class="w-full text-[11px] px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium">
                            <i class="fas fa-cart-plus mr-1"></i>เพิ่มที่เลือกในออเดอร์
                        </button>
                    </div>
                `;
                // Store detected drugs for adding to order
                window.detectedDrugs = found;
            }
            
            // Search box
            html += `
                <div class="mt-2 pt-2 border-t border-gray-100">
                    <div class="flex gap-1">
                        <input type="text" id="drugSearchInput" placeholder="🔍 ค้นหาสินค้าเพิ่ม..." 
                            class="flex-1 text-xs px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-400"
                            onkeypress="if(event.key==='Enter') searchDrugManual()">
                        <button onclick="searchDrugManual()" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-600 text-xs rounded-lg">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <div id="manualSearchResults" class="mt-2 hidden"></div>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            // No products detected - show search
            content.innerHTML = `
                <div class="text-center text-gray-400 text-xs py-2">
                    <i class="fas fa-box-open text-2xl mb-2"></i>
                    <p>รอตรวจจับชื่อสินค้าจากข้อความ</p>
                </div>
                <div class="mt-2">
                    <div class="flex gap-1">
                        <input type="text" id="drugSearchInput" placeholder="🔍 ค้นหาสินค้า..." 
                            class="flex-1 text-xs px-2 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:border-emerald-400"
                            onkeypress="if(event.key==='Enter') searchDrugManual()">
                        <button onclick="searchDrugManual()" class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs rounded-lg">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <div id="manualSearchResults" class="mt-2 hidden"></div>
                </div>
            `;
        }
        
        content.style.opacity = '1';
    }, 150);
}

/**
 * Add all detected drugs to order
 */
function addDetectedToOrder() {
    const drugs = window.detectedDrugs || [];
    if (drugs.length === 0) {
        showNotification('ไม่มีสินค้าที่ตรวจจับได้', 'warning');
        return;
    }
    
    drugs.forEach(drug => {
        const drugId = drug.id || drug.drugId || 0;
        const drugName = drug.name || '';
        const drugPrice = drug.price || 0;
        if (drugId && drugName) {
            addDrugToOrder(drugId, drugName, drugPrice);
        }
    });
    
    showNotification(`เพิ่ม ${drugs.length} รายการในออเดอร์แล้ว`, 'success');
}

/**
 * Add selected (checked) items to order
 */
function addSelectedToOrder() {
    const checkboxes = document.querySelectorAll('.detected-drug-checkbox:checked');
    
    if (checkboxes.length === 0) {
        showNotification('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ', 'warning');
        return;
    }
    
    checkboxes.forEach(cb => {
        const drugId = parseInt(cb.dataset.drugId);
        const drugName = cb.dataset.drugName;
        const drugPrice = parseFloat(cb.dataset.drugPrice);
        
        if (drugId && drugName) {
            addDrugToOrder(drugId, drugName, drugPrice);
        }
    });
    
    showNotification(`เพิ่ม ${checkboxes.length} รายการในออเดอร์แล้ว`, 'success');
}

/**
 * Toggle select all checkboxes
 */
function toggleSelectAll(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.detected-drug-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = masterCheckbox.checked;
    });
    updateSelectedTotal();
}

/**
 * Update selected total display
 */
function updateSelectedTotal() {
    const checkboxes = document.querySelectorAll('.detected-drug-checkbox:checked');
    let total = 0;
    let count = 0;
    
    checkboxes.forEach(cb => {
        total += parseFloat(cb.dataset.drugPrice) || 0;
        count++;
    });
    
    const totalEl = document.getElementById('selectedTotal');
    if (totalEl) {
        totalEl.textContent = `${count} รายการ | ฿${total.toLocaleString()}`;
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.detected-drug-checkbox');
    const selectAllEl = document.getElementById('selectAllProducts');
    if (selectAllEl && allCheckboxes.length > 0) {
        selectAllEl.checked = checkboxes.length === allCheckboxes.length;
        selectAllEl.indeterminate = checkboxes.length > 0 && checkboxes.length < allCheckboxes.length;
    }
}

/**
 * Add all checked items to order (legacy function)
 */
function addAllCheckedToOrder() {
    const checkboxes = document.querySelectorAll('.drug-want-checkbox:checked');
    
    if (checkboxes.length === 0) {
        showNotification('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ', 'warning');
        return;
    }
    
    checkboxes.forEach(cb => {
        const drugId = parseInt(cb.dataset.drugId);
        const drugName = cb.dataset.drugName;
        const drugPrice = parseFloat(cb.dataset.drugPrice);
        
        if (drugId && drugName) {
            addDrugToOrder(drugId, drugName, drugPrice);
        }
    });
    
    showNotification(`เพิ่ม ${checkboxes.length} รายการในออเดอร์แล้ว`, 'success');
}

/**
 * Manual drug search by admin
 */
async function searchDrugManual() {
    const input = document.getElementById('drugSearchInput');
    const resultsDiv = document.getElementById('manualSearchResults');
    if (!input || !resultsDiv) return;
    
    const query = input.value.trim();
    if (!query || query.length < 2) {
        showNotification('กรุณาพิมพ์อย่างน้อย 2 ตัวอักษร', 'warning');
        return;
    }
    
    resultsDiv.innerHTML = '<div class="text-center text-gray-400 text-xs py-2"><i class="fas fa-spinner fa-spin"></i> กำลังค้นหา...</div>';
    resultsDiv.classList.remove('hidden');
    
    try {
        const response = await fetch(`api/inbox-v2.php?action=search_drugs&query=${encodeURIComponent(query)}&line_account_id=<?= $lineAccountId ?>`);
        const result = await response.json();
        
        if (result.success && result.data && result.data.length > 0) {
            let html = '<div class="space-y-1">';
            result.data.slice(0, 5).forEach(drug => {
                const stockClass = drug.stock > 0 ? 'in-stock' : 'out-of-stock';
                const stockText = drug.stock > 0 ? `มี ${drug.stock}` : 'หมด';
                const drugId = drug.id || 0;
                const drugNameSafe = escapeAttr(drug.name || '');
                
                html += `
                    <div class="flex items-center justify-between p-1.5 bg-gray-50 rounded hover:bg-gray-100 cursor-pointer" 
                         onclick="selectDrugForInfo(${drugId}, '${drugNameSafe}')">
                        <div class="flex-1">
                            <div class="text-[11px] font-medium">${escapeHtml(drug.name)}</div>
                            ${drug.name_en ? `<div class="text-[9px] text-gray-400">${escapeHtml(drug.name_en)}</div>` : ''}
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] font-bold text-emerald-600">฿${(drug.price || 0).toLocaleString()}</div>
                            <span class="text-[9px] ${drug.stock > 0 ? 'text-emerald-500' : 'text-red-500'}">${stockText}</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        } else {
            resultsDiv.innerHTML = '<div class="text-center text-gray-400 text-xs py-2">❌ ไม่พบสินค้า</div>';
        }
    } catch (error) {
        console.error('Search error:', error);
        resultsDiv.innerHTML = '<div class="text-center text-red-400 text-xs py-2">เกิดข้อผิดพลาด</div>';
    }
}

/**
 * Update Drug Info Widget - Requirements: 4.2
 * @param {Object} data Widget data
 */
function updateDrugInfoWidget(data) {
    const content = document.getElementById('drugInfoContent');
    if (!content) return;
    
    // Fade out animation
    content.style.opacity = '0';
    content.style.transition = 'opacity 0.15s ease';
    
    setTimeout(() => {
        if (data.drug) {
            const drug = data.drug;
            const stockClass = drug.stock > 10 ? 'in-stock' : (drug.stock > 0 ? 'low-stock' : 'out-of-stock');
            const stockText = drug.stock > 10 ? 'มีสินค้า' : (drug.stock > 0 ? `เหลือ ${drug.stock}` : 'หมด');
            
            content.innerHTML = `
                <div class="space-y-3">
                    <div class="flex justify-between items-start">
                        <div>
                            <div class="drug-name">${escapeHtml(drug.name || '')}</div>
                            <div class="drug-dosage">${escapeHtml(drug.dosage || drug.description || '')}</div>
                        </div>
                        <span class="drug-stock ${stockClass}">${stockText}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="drug-price">฿${(drug.price || 0).toLocaleString()}</span>
                        ${drug.isPrescription ? '<span class="text-[10px] px-2 py-0.5 bg-red-100 text-red-600 rounded">ยาตามใบสั่งแพทย์</span>' : ''}
                    </div>
                    ${drug.contraindications ? `
                    <div class="bg-yellow-50 rounded-lg p-2 text-xs">
                        <p class="font-medium text-yellow-700 mb-1">⚠️ ข้อควรระวัง</p>
                        <p class="text-yellow-600 text-[11px]">${escapeHtml(drug.contraindications)}</p>
                    </div>
                    ` : ''}
                    <div class="flex gap-2">
                        <button onclick="insertDrugToMessage(${drug.id || 0})" class="flex-1 text-xs py-1.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-700 rounded-lg">
                            <i class="fas fa-comment mr-1"></i>เพิ่มในข้อความ
                        </button>
                        <button onclick="addDrugToOrder(${drug.id || 0}, '${escapeAttr(drug.name || '')}', ${drug.price || 0})" class="flex-1 text-xs py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-700 rounded-lg">
                            <i class="fas fa-cart-plus mr-1"></i>เพิ่มในออเดอร์
                        </button>
                    </div>
                    <div class="flex gap-2 mt-1">
                        <button onclick="checkDrugInteractions(${drug.id || 0})" class="flex-1 text-xs py-1.5 bg-orange-100 hover:bg-orange-200 text-orange-700 rounded-lg">
                            <i class="fas fa-exchange-alt mr-1"></i>ตรวจยาตีกัน
                        </button>
                    </div>
                </div>
            `;
        } else {
            content.innerHTML = `
                <div class="text-center text-gray-400 text-xs py-4">
                    <i class="fas fa-search mb-2"></i>
                    <p>พิมพ์ชื่อยาในแชทเพื่อดูข้อมูล</p>
                </div>
            `;
        }
        
        // Fade in animation
        content.style.opacity = '1';
    }, 150);
}

/**
 * Update Interaction Widget - Requirements: 4.3
 * @param {Object} data Widget data
 */
function updateInteractionWidget(data) {
    const content = document.getElementById('interactionContent');
    if (!content) return;
    
    content.style.opacity = '0';
    content.style.transition = 'opacity 0.15s ease';
    
    setTimeout(() => {
        if (data.interactions && data.interactions.length > 0) {
            let html = '<div class="space-y-2">';
            
            data.interactions.forEach(interaction => {
                const severityClass = interaction.severity === 'severe' ? 'severe' : 
                                     (interaction.severity === 'moderate' ? 'moderate' : 'mild');
                const severityIcon = interaction.severity === 'severe' ? '🚨' : 
                                    (interaction.severity === 'moderate' ? '⚠️' : 'ℹ️');
                
                html += `
                    <div class="interaction-item ${severityClass}">
                        <div class="font-medium">${severityIcon} ${escapeHtml(interaction.drug1 || '')} + ${escapeHtml(interaction.drug2 || '')}</div>
                        <div class="text-[10px] mt-1">${escapeHtml(interaction.description || interaction.effect || '')}</div>
                    </div>
                `;
            });
            
            html += '</div>';
            content.innerHTML = html;
        } else {
            content.innerHTML = `
                <div class="text-center text-gray-400 text-xs py-4">
                    <i class="fas fa-check-circle text-green-400 text-2xl mb-2"></i>
                    <p>ไม่พบปฏิกิริยาระหว่างยา</p>
                </div>
            `;
        }
        
        content.style.opacity = '1';
    }, 150);
}

/**
 * Update Allergy Widget - Requirements: 4.5
 * @param {Object} data Widget data
 */
function updateAllergyWidget(data) {
    const widget = document.getElementById('allergyWidget');
    if (!widget) return;
    
    const body = widget.querySelector('.hud-widget-body');
    if (!body) return;
    
    body.style.opacity = '0';
    body.style.transition = 'opacity 0.15s ease';
    
    setTimeout(() => {
        if (data.allergies && data.allergies.length > 0) {
            let html = '';
            data.allergies.forEach(allergy => {
                html += `
                    <div class="allergy-item">
                        <i class="fas fa-ban"></i>
                        ${escapeHtml(allergy.name || allergy)}
                    </div>
                `;
            });
            body.innerHTML = html;
            widget.style.display = '';
        } else {
            widget.style.display = 'none';
        }
        
        body.style.opacity = '1';
    }, 150);
}

/**
 * Update Pricing Widget - Requirements: 3.1-3.5
 * @param {Object} data Widget data
 */
function updatePricingWidget(data) {
    const content = document.getElementById('pricingContent');
    if (!content) return;
    
    content.style.opacity = '0';
    content.style.transition = 'opacity 0.15s ease';
    
    setTimeout(() => {
        if (data.pricing) {
            const pricing = data.pricing;
            const marginClass = pricing.marginPercent >= 20 ? 'margin-good' : 
                               (pricing.marginPercent >= 10 ? 'margin-warning' : 'margin-danger');
            
            content.innerHTML = `
                <div class="space-y-1">
                    <div class="price-row">
                        <span class="text-gray-500">ต้นทุน</span>
                        <span class="font-medium">฿${(pricing.cost || 0).toLocaleString()}</span>
                    </div>
                    <div class="price-row">
                        <span class="text-gray-500">ราคาขาย</span>
                        <span class="font-medium">฿${(pricing.price || 0).toLocaleString()}</span>
                    </div>
                    <div class="price-row">
                        <span class="text-gray-500">กำไร</span>
                        <span class="font-bold text-emerald-600">฿${(pricing.margin || 0).toLocaleString()}</span>
                    </div>
                    <div class="mt-2 text-center">
                        <span class="margin-indicator ${marginClass}">
                            ${(pricing.marginPercent || 0).toFixed(1)}% margin
                        </span>
                    </div>
                    ${pricing.maxDiscount ? `
                    <div class="mt-2 text-center text-[10px] text-gray-500">
                        ส่วนลดสูงสุด: ฿${pricing.maxDiscount.toLocaleString()}
                    </div>
                    ` : ''}
                </div>
            `;
        } else {
            content.innerHTML = `
                <div class="text-center text-gray-400 text-xs py-4">
                    <i class="fas fa-tag mb-2"></i>
                    <p>เลือกยาเพื่อดูราคาและกำไร</p>
                </div>
            `;
        }
        
        content.style.opacity = '1';
    }, 150);
}

/**
 * Update Pregnancy Widget - Requirements: 4.4
 * @param {Object} data Widget data
 */
function updatePregnancyWidget(data) {
    // Create pregnancy widget if it doesn't exist
    let widget = document.getElementById('pregnancyWidget');
    const hudWidgets = document.getElementById('hudWidgets');
    
    if (!widget && hudWidgets && data.show) {
        const widgetHtml = `
            <div class="hud-widget" id="pregnancyWidget" style="background: linear-gradient(135deg, #FCE7F3 0%, #FBCFE8 100%); border: 2px solid #F472B6;">
                <div class="hud-widget-header" onclick="toggleWidget('pregnancyWidget')" style="background: #EC4899; color: white; border-bottom: none;">
                    <h4 style="color: white;"><i class="fas fa-baby"></i> ยาปลอดภัยสำหรับคุณแม่</h4>
                    <i class="fas fa-chevron-down text-white/70 text-xs"></i>
                </div>
                <div class="hud-widget-body" id="pregnancyContent">
                    <div class="text-center text-pink-600 text-xs py-2">
                        <i class="fas fa-exclamation-triangle mb-1"></i>
                        <p>กรุณาปรึกษาแพทย์ก่อนใช้ยา</p>
                    </div>
                </div>
            </div>
        `;
        
        // Insert after allergy widget or at the beginning
        const allergyWidget = document.getElementById('allergyWidget');
        if (allergyWidget) {
            allergyWidget.insertAdjacentHTML('afterend', widgetHtml);
        } else {
            hudWidgets.insertAdjacentHTML('afterbegin', widgetHtml);
        }
        
        widget = document.getElementById('pregnancyWidget');
    }
    
    if (widget) {
        widget.style.display = data.show ? '' : 'none';
        
        if (data.safeDrugs && data.safeDrugs.length > 0) {
            const content = document.getElementById('pregnancyContent');
            if (content) {
                let html = '<div class="space-y-2">';
                data.safeDrugs.forEach(drug => {
                    html += `
                        <div class="bg-white rounded-lg p-2 text-xs">
                            <div class="font-medium text-pink-700">${escapeHtml(drug.name)}</div>
                            <div class="text-pink-500 text-[10px]">${escapeHtml(drug.note || 'ปลอดภัยสำหรับคุณแม่')}</div>
                        </div>
                    `;
                });
                html += '</div>';
                content.innerHTML = html;
            }
        }
    }
}

/**
 * Toggle widget collapse state with animation
 * Requirements: 4.6 - Smooth transitions within 300ms
 * @param {string} widgetId Widget element ID
 */
function toggleWidget(widgetId) {
    const widget = document.getElementById(widgetId);
    if (!widget) return;
    
    const body = widget.querySelector('.hud-widget-body');
    const chevron = widget.querySelector('.fa-chevron-down, .fa-chevron-up');
    
    if (body) {
        // Smooth collapse/expand animation - Requirements: 4.6
        if (widget.classList.contains('collapsed')) {
            // Expanding
            body.style.display = 'block';
            body.style.maxHeight = '0';
            body.style.overflow = 'hidden';
            body.style.transition = 'max-height 0.3s ease, opacity 0.3s ease';
            body.style.opacity = '0';
            
            // Trigger reflow
            body.offsetHeight;
            
            body.style.maxHeight = body.scrollHeight + 'px';
            body.style.opacity = '1';
            
            setTimeout(() => {
                body.style.maxHeight = '';
                body.style.overflow = '';
            }, 300);
            
            widget.classList.remove('collapsed');
        } else {
            // Collapsing
            body.style.maxHeight = body.scrollHeight + 'px';
            body.style.overflow = 'hidden';
            body.style.transition = 'max-height 0.3s ease, opacity 0.3s ease';
            
            // Trigger reflow
            body.offsetHeight;
            
            body.style.maxHeight = '0';
            body.style.opacity = '0';
            
            setTimeout(() => {
                body.style.display = 'none';
                widget.classList.add('collapsed');
            }, 300);
        }
    } else {
        widget.classList.toggle('collapsed');
    }
    
    // Toggle chevron icon
    if (chevron) {
        chevron.classList.toggle('fa-chevron-down');
        chevron.classList.toggle('fa-chevron-up');
    }
}

/**
 * Toggle customer info panel (alias for toggleHUD)
 */
function togglePanel() {
    toggleHUD();
}

/**
 * Auto-update HUD widgets based on message context
 * Called when new messages are received or sent
 * Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
 * @param {string} message The message to analyze for context
 */
async function autoUpdateHUDWidgets(message) {
    if (!hudState.autoUpdateEnabled || !ghostDraftState.userId || !message) {
        return;
    }
    
    // Update customer emotion immediately (no API call needed)
    updateCustomerEmotion(message);
    
    // Debounce to avoid too many API calls
    if (hudState.refreshDebounceTimer) {
        clearTimeout(hudState.refreshDebounceTimer);
    }
    
    hudState.refreshDebounceTimer = setTimeout(async () => {
        // Skip if message hasn't changed significantly
        if (message === hudState.lastMessage) {
            return;
        }
        
        try {
            // Fetch context widgets
            const params = new URLSearchParams({
                action: 'context_widgets',
                user_id: ghostDraftState.userId,
                message: message,
                line_account_id: <?= $currentBotId ?>
            });
            
            const response = await fetch(`api/inbox-v2.php?${params.toString()}`);
            const result = await response.json();
            
            if (result.success && result.data && result.data.widgets) {
                hudState.lastMessage = message;
                
                // Only update if we got new widgets
                if (result.data.widgets.length > 0) {
                    await updateHUDWidgets(result.data.widgets);
                }
            }
            
            // Also fetch drug recommendations based on message
            const recsParams = new URLSearchParams({
                action: 'recommendations',
                user_id: ghostDraftState.userId,
                type: 'context',
                line_account_id: <?= $currentBotId ?>,
                message: message
            });
            
            const recsResponse = await fetch(`api/inbox-v2.php?${recsParams.toString()}`);
            const recsResult = await recsResponse.json();
            
            if (recsResult.success && recsResult.data && recsResult.data.recommendations && recsResult.data.recommendations.length > 0) {
                // Send recommendations to symptom widget (product detection)
                updateSymptomWidget(recsResult.data);
            }
            
        } catch (error) {
            console.error('Auto-update HUD error:', error);
        }
    }, 500); // 500ms debounce
}

/**
 * Select drug for info display
 * @param {number} drugId Drug ID
 * @param {string} drugName Drug name
 */
async function selectDrugForInfo(drugId, drugName) {
    if (!drugId && !drugName) return;
    
    try {
        const params = new URLSearchParams({
            action: 'drug_info',
            id: drugId || '',
            name: drugName || '',
            line_account_id: <?= $currentBotId ?>
        });
        
        const response = await fetch(`api/inbox-v2.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success && result.data) {
            updateDrugInfoWidget({ drug: result.data });
            
            // Also fetch and update pricing widget
            if (drugId) {
                await loadDrugPricing(drugId);
            }
            
            // Expand drug info widget if collapsed
            const drugWidget = document.getElementById('drugInfoWidget');
            if (drugWidget && drugWidget.classList.contains('collapsed')) {
                toggleWidget('drugInfoWidget');
            }
            
            // Expand pricing widget if collapsed
            const pricingWidget = document.getElementById('pricingWidget');
            if (pricingWidget && pricingWidget.classList.contains('collapsed')) {
                toggleWidget('pricingWidget');
            }
        }
    } catch (error) {
        console.error('Select drug error:', error);
        showNotification('ไม่สามารถโหลดข้อมูลยาได้', 'error');
    }
}

/**
 * Load drug pricing and update widget
 * @param {number} drugId Drug ID
 */
async function loadDrugPricing(drugId) {
    if (!drugId) return;
    
    try {
        const params = new URLSearchParams({
            action: 'drug_pricing',
            id: drugId,
            line_account_id: <?= $currentBotId ?>
        });
        
        const response = await fetch(`api/inbox-v2.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success && result.data) {
            updatePricingWidget({ pricing: result.data });
        }
    } catch (error) {
        console.error('Load pricing error:', error);
    }
}

/**
 * Insert drug info into message composer
 * @param {number} drugId Drug ID
 */
async function insertDrugToMessage(drugId) {
    if (!drugId) return;
    
    try {
        const params = new URLSearchParams({
            action: 'drug_info',
            id: drugId,
            line_account_id: <?= $currentBotId ?>
        });
        
        const response = await fetch(`api/inbox-v2.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success && result.data) {
            const drug = result.data;
            const messageInput = document.getElementById('messageInput');
            
            if (messageInput) {
                // Build detailed drug text
                let drugLines = [];
                
                // ชื่อสินค้า (Thai name)
                drugLines.push(`💊 ${drug.name}`);
                
                // ชื่อภาษาอังกฤษ (English name) - if available
                if (drug.nameEn) {
                    drugLines.push(`   ${drug.nameEn}`);
                }
                
                // ชื่อสามัญ / Generic Name - if available
                if (drug.genericName) {
                    drugLines.push(`📋 ชื่อสามัญ: ${drug.genericName}`);
                }
                
                // ผู้ผลิต (Manufacturer) - if available
                if (drug.manufacturer) {
                    drugLines.push(`🏭 ผู้ผลิต: ${drug.manufacturer}`);
                }
                
                // หน่วย และ จำนวนคงเหลือ
                const unit = drug.unit || 'ชิ้น';
                const stock = drug.stock || 0;
                drugLines.push(`📦 หน่วย: ${unit} | คงเหลือ: ${stock} ${unit}`);
                
                // ราคา
                const price = drug.effectivePrice || drug.salePrice || drug.price || 0;
                drugLines.push(`💰 ราคา: ฿${price.toLocaleString()}`);
                
                // คำเตือนยาตามใบสั่งแพทย์
                if (drug.isPrescription) {
                    drugLines.push(`⚠️ ยาตามใบสั่งแพทย์`);
                }
                
                const drugText = drugLines.join('\n');
                
                // Append to existing text or replace
                if (messageInput.value.trim()) {
                    messageInput.value += '\n\n' + drugText;
                } else {
                    messageInput.value = drugText;
                }
                
                autoResize(messageInput);
                messageInput.focus();
                showNotification('เพิ่มข้อมูลยาในข้อความแล้ว', 'success');
            }
        }
    } catch (error) {
        console.error('Insert drug error:', error);
        showNotification('ไม่สามารถเพิ่มข้อมูลยาได้', 'error');
    }
}

/**
 * Check drug interactions for a specific drug
 * @param {number} drugId Drug ID to check
 */
async function checkDrugInteractions(drugId) {
    if (!drugId || !ghostDraftState.userId) return;
    
    try {
        // Get drug name first
        const infoParams = new URLSearchParams({
            action: 'drug_info',
            id: drugId,
            line_account_id: <?= $currentBotId ?>
        });
        
        const infoResponse = await fetch(`api/inbox-v2.php?${infoParams.toString()}`);
        const infoResult = await infoResponse.json();
        
        if (!infoResult.success || !infoResult.data) {
            throw new Error('Drug not found');
        }
        
        const drugName = infoResult.data.name;
        
        // Check interactions
        const formData = new FormData();
        formData.append('action', 'check_interactions');
        formData.append('drugs', JSON.stringify([drugName]));
        formData.append('user_id', ghostDraftState.userId);
        
        const response = await fetch('api/inbox-v2.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.data) {
            updateInteractionWidget(result.data);
            
            // Expand interaction widget
            const interactionWidget = document.getElementById('interactionWidget');
            if (interactionWidget && interactionWidget.classList.contains('collapsed')) {
                toggleWidget('interactionWidget');
            }
            
            if (result.data.hasInteractions) {
                showNotification('⚠️ พบปฏิกิริยาระหว่างยา', 'warning');
            } else {
                showNotification('✓ ไม่พบปฏิกิริยาระหว่างยา', 'success');
            }
        }
    } catch (error) {
        console.error('Check interactions error:', error);
        showNotification('ไม่สามารถตรวจสอบยาตีกันได้', 'error');
    }
}

/**
 * Hook into message input to auto-update HUD
 * Called from handleMessageInput
 */
function onMessageInputChange(message) {
    // Auto-update HUD based on message content
    if (message && message.length > 2) {
        autoUpdateHUDWidgets(message);
    }
}

/**
 * Hook into conversation updates
 * Called after sending/receiving messages
 */
function onConversationUpdateHUD() {
    const lastMessage = getLastCustomerMessage();
    if (lastMessage) {
        autoUpdateHUDWidgets(lastMessage);
    }
}

// Image handling functions
let selectedImageFile = null;

function handleImageSelect(input) {
    if (input.files && input.files[0]) {
        selectedImageFile = input.files[0];
        
        const preview = document.getElementById('imagePreview');
        const previewImg = document.getElementById('previewImg');
        const previewName = document.getElementById('previewName');
        const previewSize = document.getElementById('previewSize');
        
        if (preview && previewImg) {
            const reader = new FileReader();
            reader.onload = (e) => {
                previewImg.src = e.target.result;
            };
            reader.readAsDataURL(selectedImageFile);
            
            previewName.textContent = selectedImageFile.name;
            previewSize.textContent = formatFileSize(selectedImageFile.size);
            preview.classList.remove('hidden');
        }
    }
}

function cancelImageUpload() {
    selectedImageFile = null;
    const preview = document.getElementById('imagePreview');
    const input = document.getElementById('imageInput');
    
    if (preview) preview.classList.add('hidden');
    if (input) input.value = '';
}

async function sendImage() {
    if (!selectedImageFile || !ghostDraftState.userId) return;
    
    const formData = new FormData();
    formData.append('action', 'send_image');
    formData.append('user_id', ghostDraftState.userId);
    formData.append('image', selectedImageFile);
    
    try {
        const response = await fetch('inbox-v2.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add image to chat
            appendImageMessage(result.image_url, result.time, result.sent_by);
            cancelImageUpload();
            scrollToBottom();
            showNotification('ส่งรูปภาพสำเร็จ', 'success');
        } else {
            throw new Error(result.error || 'ส่งรูปภาพไม่สำเร็จ');
        }
    } catch (error) {
        console.error('Send image error:', error);
        showNotification(error.message, 'error');
    }
}

function appendImageMessage(imageUrl, time, sentBy) {
    const chatBox = document.getElementById('chatBox');
    if (!chatBox) return;
    
    const msgDiv = document.createElement('div');
    msgDiv.className = 'message-item flex justify-end group';
    
    let senderBadge = '';
    if (sentBy && sentBy.startsWith('admin:')) {
        const name = sentBy.substring(6);
        senderBadge = `<span class="sender-badge admin"><i class="fas fa-user-shield"></i> ${escapeHtml(name)}</span>`;
    }
    
    msgDiv.innerHTML = `
        <div class="flex flex-col items-end" style="max-width:70%">
            <img src="${escapeHtml(imageUrl)}" class="rounded-xl max-w-[200px] border shadow-sm cursor-pointer hover:opacity-90" onclick="openImage(this.src)">
            <div class="msg-meta flex items-center gap-1 text-[10px] text-white/70 mt-1">
                <span>${time || new Date().toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'})} น.</span>
                ${senderBadge}
            </div>
        </div>
    `;
    
    chatBox.appendChild(msgDiv);
}

function openImage(src) {
    window.open(src, '_blank');
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// PDF handling functions
let selectedPdfFile = null;

function handlePdfSelect(input) {
    if (input.files && input.files[0]) {
        selectedPdfFile = input.files[0];
        
        // Validate file type
        if (!selectedPdfFile.type.includes('pdf')) {
            showNotification('กรุณาเลือกไฟล์ PDF เท่านั้น', 'error');
            input.value = '';
            return;
        }
        
        // Validate file size (max 10MB)
        if (selectedPdfFile.size > 10 * 1024 * 1024) {
            showNotification('ไฟล์ PDF ต้องมีขนาดไม่เกิน 10MB', 'error');
            input.value = '';
            return;
        }
        
        // Show PDF preview
        showPdfPreview(selectedPdfFile);
    }
}

function showPdfPreview(file) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    const previewName = document.getElementById('previewName');
    const previewSize = document.getElementById('previewSize');
    
    if (preview && previewImg) {
        // Show PDF icon instead of image
        previewImg.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI2NCIgaGVpZ2h0PSI2NCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiNEQzI2MjYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48cGF0aCBkPSJNMTQgMkg2YTIgMiAwIDAgMC0yIDJ2MTZhMiAyIDAgMCAwIDIgMmgxMmEyIDIgMCAwIDAgMi0yVjhsLTYtNnoiLz48cG9seWxpbmUgcG9pbnRzPSIxNCAyIDE0IDggMjAgOCIvPjxwYXRoIGQ9Ik05IDEzaDZ2NUg5eiIvPjxwYXRoIGQ9Ik05IDEzdi0yYTIgMiAwIDAgMSAyLTJoMmEyIDIgMCAwIDEgMiAydjIiLz48L3N2Zz4=';
        previewName.textContent = file.name;
        previewSize.textContent = formatFileSize(file.size);
        preview.classList.remove('hidden');
        
        // Change send button to send PDF
        const sendBtn = preview.querySelector('button[onclick="sendImage()"]');
        if (sendBtn) {
            sendBtn.setAttribute('onclick', 'sendPdf()');
            sendBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>ส่ง PDF';
        }
    }
}

async function sendPdf() {
    if (!selectedPdfFile || !ghostDraftState.userId) return;
    
    const formData = new FormData();
    formData.append('action', 'send_pdf');
    formData.append('user_id', ghostDraftState.userId);
    formData.append('pdf', selectedPdfFile);
    
    try {
        showNotification('กำลังอัพโหลด PDF...', 'info');
        
        const response = await fetch('inbox-v2.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Add PDF to chat
            appendPdfMessage(result.file_url, result.file_name, result.time, result.sent_by);
            cancelPdfUpload();
            scrollToBottom();
            showNotification('ส่งไฟล์ PDF สำเร็จ', 'success');
        } else {
            throw new Error(result.error || 'ส่งไฟล์ PDF ไม่สำเร็จ');
        }
    } catch (error) {
        console.error('Send PDF error:', error);
        showNotification(error.message, 'error');
    }
}

function cancelPdfUpload() {
    selectedPdfFile = null;
    const preview = document.getElementById('imagePreview');
    const input = document.getElementById('pdfInput');
    
    if (preview) preview.classList.add('hidden');
    if (input) input.value = '';
    
    // Reset send button back to image
    const sendBtn = preview?.querySelector('button[onclick="sendPdf()"]');
    if (sendBtn) {
        sendBtn.setAttribute('onclick', 'sendImage()');
        sendBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>ส่งรูป';
    }
}

function appendPdfMessage(fileUrl, fileName, time, sentBy) {
    const chatBox = document.getElementById('chatBox');
    if (!chatBox) return;
    
    const msgDiv = document.createElement('div');
    msgDiv.className = 'message-item flex justify-end group';
    
    let senderBadge = '';
    if (sentBy && sentBy.startsWith('admin:')) {
        const name = sentBy.substring(6);
        senderBadge = `<span class="sender-badge admin"><i class="fas fa-user-shield"></i> ${escapeHtml(name)}</span>`;
    }
    
    msgDiv.innerHTML = `
        <div class="flex flex-col items-end" style="max-width:70%">
            <a href="${escapeHtml(fileUrl)}" target="_blank" class="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-xl hover:bg-red-100 transition-colors">
                <i class="fas fa-file-pdf text-2xl text-red-500"></i>
                <div class="text-left">
                    <div class="text-sm font-medium text-gray-800 truncate max-w-[150px]">${escapeHtml(fileName)}</div>
                    <div class="text-[10px] text-gray-500">คลิกเพื่อเปิด PDF</div>
                </div>
            </a>
            <div class="msg-meta flex items-center gap-1 text-[10px] text-gray-500 mt-1">
                <span>${time || new Date().toLocaleTimeString('th-TH', {hour: '2-digit', minute: '2-digit'})} น.</span>
                ${senderBadge}
            </div>
        </div>
    `;
    
    chatBox.appendChild(msgDiv);
}

// Search and filter functions
const debouncedSearch = debounce(function(query) {
    filterUsers(query);
}, 300);

function filterUsers(query) {
    const userItems = document.querySelectorAll('#userList .user-item');
    const lowerQuery = query.toLowerCase();
    
    userItems.forEach(item => {
        const name = item.dataset.name || '';
        const lastMsg = item.querySelector('.last-msg')?.textContent?.toLowerCase() || '';
        
        if (name.includes(lowerQuery) || lastMsg.includes(lowerQuery)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

function applyFilters() {
    const status = document.getElementById('filterStatus')?.value || '';
    const tag = document.getElementById('filterTag')?.value || '';
    
    // Apply filters - simplified version
    const userItems = document.querySelectorAll('#userList .user-item');
    
    userItems.forEach(item => {
        let show = true;
        
        if (status === 'unread') {
            const unreadBadge = item.querySelector('.unread-badge');
            show = show && unreadBadge !== null;
        }
        
        item.style.display = show ? '' : 'none';
    });
}

// Mobile functions
function showChatList() {
    const sidebar = document.getElementById('inboxSidebar');
    if (sidebar) {
        sidebar.classList.remove('hidden-mobile');
    }
}

// Sound toggle
let soundEnabled = true;

function toggleSound() {
    soundEnabled = !soundEnabled;
    const icon = document.getElementById('soundIcon');
    if (icon) {
        icon.className = soundEnabled ? 'fas fa-volume-up' : 'fas fa-volume-mute';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Scroll to bottom of chat
    scrollToBottom();
    
    // Focus message input if user is selected
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.focus();
    }
    
    // Load quick actions if user is selected
    if (ghostDraftState.userId) {
        loadQuickActions();
        
        // Initialize HUD with context from last customer message - Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
        const lastMessage = getLastCustomerMessage();
        initializeHUD(lastMessage || '');
    }
});

/**
 * Initialize HUD Dashboard with user data
 * Loads health profile, drug info, and recommendations
 */
async function initializeHUD(message = '') {
    if (!ghostDraftState.userId) return;
    
    try {
        // Load health profile
        const healthParams = new URLSearchParams({
            action: 'customer_health',
            user_id: ghostDraftState.userId,
            line_account_id: <?= $currentBotId ?>
        });
        const healthResponse = await fetch(`api/inbox-v2.php?${healthParams.toString()}`);
        const healthResult = await healthResponse.json();
        
        if (healthResult.success && healthResult.data) {
            updateHealthProfileWidget(healthResult.data);
        }
        
        // Load context widgets if we have a message
        if (message) {
            autoUpdateHUDWidgets(message);
        }
        
        // Load drug recommendations based on recent conversation
        const recsParams = new URLSearchParams({
            action: 'recommendations',
            user_id: ghostDraftState.userId,
            type: 'context',
            line_account_id: <?= $currentBotId ?>
        });
        
        // Add message if available for better drug matching
        if (message) {
            recsParams.append('message', message);
        }
        
        const recsResponse = await fetch(`api/inbox-v2.php?${recsParams.toString()}`);
        const recsResult = await recsResponse.json();
        
        if (recsResult.success && recsResult.data && recsResult.data.recommendations) {
            // Send recommendations to symptom widget (product detection)
            updateSymptomWidget(recsResult.data);
        }
        
    } catch (error) {
        console.error('Initialize HUD error:', error);
    }
}

/**
 * Update health profile widget in HUD
 */
function updateHealthProfileWidget(data) {
    const container = document.querySelector('#hudDashboard [data-widget="health-profile"]');
    if (!container) return;
    
    const content = container.querySelector('.widget-content');
    if (!content) return;
    
    const profile = data.profile || data;
    const commType = profile.communication_type || 'A';
    const confidence = profile.confidence || 100;
    const emotion = profile.emotion || 'neutral';
    
    // Communication type labels in Thai
    const typeLabels = {
        'A': '⚡ ตรงไปตรงมา',
        'B': '� ใส่ใๆจรายละเอียด',
        'C': '📊 สบายๆ ค่อยๆคุย'
    };
    
    // Emotion labels in Thai
    const emotionLabels = {
        'angry': '😠 โมโห',
        'frustrated': '😤 หงุดหงิด',
        'happy': '😊 ปลาบปลื้ม',
        'satisfied': '😌 พอใจ',
        'neutral': '😐 ปกติ',
        'confused': '😕 สับสน',
        'worried': '😟 กังวล',
        'urgent': '⚡ เร่งด่วน'
    };
    
    // Emotion background colors
    const emotionBgClass = {
        'angry': 'from-red-50 to-red-100 border-red-200',
        'frustrated': 'from-orange-50 to-orange-100 border-orange-200',
        'happy': 'from-green-50 to-green-100 border-green-200',
        'satisfied': 'from-emerald-50 to-emerald-100 border-emerald-200',
        'neutral': 'from-gray-50 to-gray-100 border-gray-200',
        'confused': 'from-yellow-50 to-yellow-100 border-yellow-200',
        'worried': 'from-amber-50 to-amber-100 border-amber-200',
        'urgent': 'from-purple-50 to-purple-100 border-purple-200'
    };
    
    content.innerHTML = `
        <div class="space-y-2">
            <!-- Customer Emotion -->
            <div class="p-2 bg-gradient-to-r ${emotionBgClass[emotion] || emotionBgClass['neutral']} rounded-lg border">
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-500">อารมณ์ลูกค้า</span>
                    <span class="text-sm font-medium">${emotionLabels[emotion] || '😐 ปกติ'}</span>
                </div>
            </div>
            
            <div class="flex justify-between items-center">
                <span class="text-xs text-gray-500">รูปแบบการสื่อสาร</span>
                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs font-medium">
                    ${typeLabels[commType] || 'ไม่ระบุ'}
                </span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-xs text-gray-500">ความแม่นยำ</span>
                <span class="text-xs font-medium">${confidence}%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1.5">
                <div class="bg-purple-500 h-1.5 rounded-full" style="width: ${confidence}%"></div>
            </div>
            <p class="text-[10px] text-gray-400">* คำนวณจากประวัติการสนทนา</p>
            ${profile.allergies && profile.allergies.length > 0 ? `
            <div class="mt-2 p-2 bg-red-50 rounded text-xs">
                <span class="text-red-600 font-medium">⚠️ แพ้ยา:</span>
                <span class="text-red-700">${profile.allergies.join(', ')}</span>
            </div>
            ` : ''}
            ${profile.conditions && profile.conditions.length > 0 ? `
            <div class="mt-1 p-2 bg-yellow-50 rounded text-xs">
                <span class="text-yellow-600 font-medium">📋 โรคประจำตัว:</span>
                <span class="text-yellow-700">${profile.conditions.join(', ')}</span>
            </div>
            ` : ''}
        </div>
    `;
}

/**
 * Detect customer emotion from message
 * @param {string} message Customer message
 * @returns {string} Detected emotion
 */
function detectCustomerEmotion(message) {
    if (!message) return 'neutral';
    
    const msg = message.toLowerCase();
    
    // Angry keywords
    if (/โกรธ|โมโห|หัวร้อน|บ้า|เวร|ห่า|สัตว์|ไอ้|อี|แม่ง|เหี้ย|!{2,}/.test(msg)) {
        return 'angry';
    }
    
    // Frustrated keywords
    if (/หงุดหงิด|รำคาญ|เบื่อ|ช้า|นาน|รอ|ทำไม|ไม่ได้|ไม่ดี|แย่/.test(msg)) {
        return 'frustrated';
    }
    
    // Happy keywords
    if (/ขอบคุณ|ดีมาก|เยี่ยม|สุดยอด|ชอบ|รัก|ปลื้ม|ดีใจ|😊|😄|🥰|❤️|👍/.test(msg)) {
        return 'happy';
    }
    
    // Satisfied keywords
    if (/โอเค|ได้|ดี|เข้าใจ|ตกลง|ok|okay/.test(msg)) {
        return 'satisfied';
    }
    
    // Confused keywords
    if (/งง|ไม่เข้าใจ|อะไร|ยังไง|หมายความว่า|\?{2,}|สับสน/.test(msg)) {
        return 'confused';
    }
    
    // Worried keywords
    if (/กังวล|กลัว|เป็นห่วง|ไม่แน่ใจ|อันตราย|ผลข้างเคียง/.test(msg)) {
        return 'worried';
    }
    
    // Urgent keywords
    if (/ด่วน|เร่ง|รีบ|ตอนนี้|ทันที|asap|urgent/.test(msg)) {
        return 'urgent';
    }
    
    return 'neutral';
}

/**
 * Update customer emotion display
 * @param {string} message Latest customer message
 */
function updateCustomerEmotion(message) {
    const emotion = detectCustomerEmotion(message);
    const emotionEl = document.getElementById('customerEmotion');
    
    if (emotionEl) {
        const emotionLabels = {
            'angry': '😠 โมโห',
            'frustrated': '😤 หงุดหงิด',
            'happy': '😊 ปลาบปลื้ม',
            'satisfied': '😌 พอใจ',
            'neutral': '😐 ปกติ',
            'confused': '😕 สับสน',
            'worried': '😟 กังวล',
            'urgent': '⚡ เร่งด่วน'
        };
        emotionEl.textContent = emotionLabels[emotion] || '😐 ปกติ';
        
        // Update background color
        const emotionBox = emotionEl.closest('.bg-gradient-to-r');
        if (emotionBox) {
            emotionBox.className = emotionBox.className.replace(/from-\w+-50 to-\w+-50 border-\w+-100/g, '');
            const bgClasses = {
                'angry': 'from-red-50 to-red-100 border-red-200',
                'frustrated': 'from-orange-50 to-orange-100 border-orange-200',
                'happy': 'from-green-50 to-green-100 border-green-200',
                'satisfied': 'from-emerald-50 to-emerald-100 border-emerald-200',
                'neutral': 'from-amber-50 to-orange-50 border-amber-100',
                'confused': 'from-yellow-50 to-yellow-100 border-yellow-200',
                'worried': 'from-amber-50 to-amber-100 border-amber-200',
                'urgent': 'from-purple-50 to-purple-100 border-purple-200'
            };
            emotionBox.classList.add(...(bgClasses[emotion] || bgClasses['neutral']).split(' '));
        }
    }
}

/**
 * Quick Actions State and Functions
 * Requirements: 9.1, 9.2, 9.3, 9.4, 9.5
 */
const quickActionsState = {
    currentStage: null,
    hasUrgentSymptoms: false,
    actions: [],
    isLoading: false,
    lastRefresh: null
};

/**
 * Load quick actions from API based on consultation stage
 * Requirements: 9.1, 9.2, 9.3
 */
async function loadQuickActions() {
    if (!ghostDraftState.userId || quickActionsState.isLoading) {
        return;
    }
    
    quickActionsState.isLoading = true;
    const container = document.getElementById('quickActionsContainer');
    const refreshIcon = document.getElementById('quickActionsRefreshIcon');
    
    // Show loading state
    if (container) {
        container.innerHTML = '<div class="text-xs text-gray-400 py-1"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังโหลด...</div>';
    }
    if (refreshIcon) {
        refreshIcon.classList.add('fa-spin');
    }
    
    try {
        const params = new URLSearchParams({
            action: 'quick_actions',
            user_id: ghostDraftState.userId,
            line_account_id: <?= $currentBotId ?>
        });
        
        // Add stage if we already know it
        if (quickActionsState.currentStage) {
            params.append('stage', quickActionsState.currentStage);
        }
        
        const response = await fetch(`api/inbox-v2.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success && result.data) {
            quickActionsState.currentStage = result.data.stage;
            quickActionsState.hasUrgentSymptoms = result.data.hasUrgentSymptoms || false;
            quickActionsState.actions = result.data.actions || [];
            quickActionsState.lastRefresh = new Date();
            
            // Update stage label
            updateStageLabel(result.data.stageLabel || result.data.stage);
            
            // Render quick action buttons
            renderQuickActions();
        } else {
            throw new Error(result.error || 'Failed to load quick actions');
        }
    } catch (error) {
        console.error('Load quick actions error:', error);
        if (container) {
            container.innerHTML = '<div class="text-xs text-gray-400 py-1"><i class="fas fa-exclamation-circle mr-1"></i>ไม่สามารถโหลดได้</div>';
        }
    } finally {
        quickActionsState.isLoading = false;
        if (refreshIcon) {
            refreshIcon.classList.remove('fa-spin');
        }
    }
}

/**
 * Refresh quick actions
 */
function refreshQuickActions() {
    quickActionsState.currentStage = null; // Force re-detection
    loadQuickActions();
}

/**
 * Update stage label display
 * @param {string} stageLabel
 */
function updateStageLabel(stageLabel) {
    const labelEl = document.getElementById('quickActionsStageLabel');
    if (labelEl) {
        // Determine stage class for badge styling
        let stageClass = '';
        const stage = quickActionsState.currentStage || '';
        if (stage.includes('symptom')) stageClass = 'symptom';
        else if (stage.includes('recommendation')) stageClass = 'recommendation';
        else if (stage.includes('purchase')) stageClass = 'purchase';
        else if (stage.includes('follow')) stageClass = 'followup';
        
        labelEl.innerHTML = `
            <span class="quick-actions-stage-badge ${stageClass}">
                ${escapeHtml(stageLabel)}
            </span>
            ${quickActionsState.hasUrgentSymptoms ? '<span class="text-red-500 ml-1" title="ตรวจพบอาการฉุกเฉิน">🚨</span>' : ''}
        `;
    }
}

/**
 * Render quick action buttons
 * Requirements: 9.1, 9.2, 9.3, 9.4
 */
function renderQuickActions() {
    const container = document.getElementById('quickActionsContainer');
    if (!container) return;
    
    if (quickActionsState.actions.length === 0) {
        container.innerHTML = '<div class="text-xs text-gray-400 py-1">ไม่มี Quick Actions</div>';
        return;
    }
    
    // Build buttons HTML
    const buttonsHtml = quickActionsState.actions.map((action, index) => {
        const isUrgent = action.isUrgent || action.highlight;
        const isPrimary = index === 0 && !isUrgent;
        
        let btnClass = 'quick-action-btn';
        if (isUrgent) btnClass += ' urgent';
        else if (isPrimary) btnClass += ' primary';
        
        return `
            <button type="button" 
                    class="${btnClass}" 
                    onclick="executeQuickAction('${escapeHtml(action.action)}', ${JSON.stringify(action).replace(/"/g, '&quot;')})"
                    title="${escapeHtml(action.labelEn || action.label)}">
                <span class="action-icon">${action.icon || '⚡'}</span>
                <span>${escapeHtml(action.label)}</span>
            </button>
        `;
    }).join('');
    
    container.innerHTML = buttonsHtml;
}

/**
 * Execute a quick action
 * Requirements: 9.5 - Pre-fill relevant data and await confirmation
 * @param {string} actionType
 * @param {object} actionData
 */
async function executeQuickAction(actionType, actionData) {
    const messageInput = document.getElementById('messageInput');
    
    switch (actionType) {
        case 'ask_followup':
        case 'ask_symptoms':
        case 'check_progress':
            // Pre-fill template message - Requirements: 9.5
            if (actionData.template && messageInput) {
                messageInput.value = actionData.template;
                autoResize(messageInput);
                messageInput.focus();
                showNotification('ข้อความพร้อมส่ง - แก้ไขได้ตามต้องการ', 'info');
            }
            break;
            
        case 'recommend_hospital':
            // Urgent action - pre-fill warning message
            if (actionData.template && messageInput) {
                messageInput.value = actionData.template;
                autoResize(messageInput);
                messageInput.focus();
                showNotification('⚠️ ข้อความแนะนำพบแพทย์พร้อมส่ง', 'warning');
            }
            break;
            
        case 'check_history':
            // Open HUD dashboard to show medical history
            const hud = document.getElementById('hudDashboard');
            if (hud && hud.classList.contains('collapsed')) {
                toggleHUD();
            }
            // Expand medical widget
            const medicalWidget = document.getElementById('medicalWidget');
            if (medicalWidget && medicalWidget.classList.contains('collapsed')) {
                toggleWidget('medicalWidget');
            }
            showNotification('ดูประวัติสุขภาพใน HUD Dashboard', 'info');
            break;
            
        case 'suggest_otc':
        case 'send_drug_info':
            // Open drug info widget
            const hud2 = document.getElementById('hudDashboard');
            if (hud2 && hud2.classList.contains('collapsed')) {
                toggleHUD();
            }
            const drugWidget = document.getElementById('drugInfoWidget');
            if (drugWidget && drugWidget.classList.contains('collapsed')) {
                toggleWidget('drugInfoWidget');
            }
            showNotification('เลือกยาจาก HUD Dashboard เพื่อส่งข้อมูล', 'info');
            break;
            
        case 'check_interactions':
            // Open interaction checker widget
            const hud3 = document.getElementById('hudDashboard');
            if (hud3 && hud3.classList.contains('collapsed')) {
                toggleHUD();
            }
            const interactionWidget = document.getElementById('interactionWidget');
            if (interactionWidget && interactionWidget.classList.contains('collapsed')) {
                toggleWidget('interactionWidget');
            }
            showNotification('ตรวจสอบยาตีกันใน HUD Dashboard', 'info');
            break;
            
        case 'apply_discount':
            // Open pricing widget
            const hud4 = document.getElementById('hudDashboard');
            if (hud4 && hud4.classList.contains('collapsed')) {
                toggleHUD();
            }
            const pricingWidget = document.getElementById('pricingWidget');
            if (pricingWidget && pricingWidget.classList.contains('collapsed')) {
                toggleWidget('pricingWidget');
            }
            showNotification('คำนวณส่วนลดใน HUD Dashboard', 'info');
            break;
            
        case 'compare_drugs':
            showNotification('เปิดหน้าเปรียบเทียบยา...', 'info');
            // Could open a modal or navigate to comparison page
            break;
            
        case 'create_order':
            // Open create order modal
            openCreateOrderModal();
            break;
            
        case 'send_payment_link':
            // Generate and send payment link
            sendPaymentLink();
            break;
            
        case 'schedule_delivery':
            openScheduleDeliveryModal();
            break;
            
        case 'apply_points':
            openUsePointsModal();
            break;
            
        case 'send_menu':
            sendRichMenu();
            break;
            
        case 'suggest_refill':
            // Get refill suggestions
            await loadRefillSuggestions();
            break;
            
        case 'schedule_followup':
            showNotification('กำลังเปิดหน้านัดติดตาม...', 'info');
            break;
            
        case 'refer_doctor':
            // Pre-fill doctor referral message
            if (messageInput) {
                messageInput.value = 'จากอาการที่แจ้งมา แนะนำให้พบแพทย์เพื่อตรวจวินิจฉัยเพิ่มเติมค่ะ หากมีคำถามเพิ่มเติมสอบถามได้เลยค่ะ';
                autoResize(messageInput);
                messageInput.focus();
                showNotification('ข้อความแนะนำพบแพทย์พร้อมส่ง', 'info');
            }
            break;
            
        case 'analyze_image':
            // Trigger image upload for analysis
            document.getElementById('imageInput')?.click();
            showNotification('เลือกรูปภาพเพื่อวิเคราะห์', 'info');
            break;
            
        default:
            console.log('Unknown action:', actionType, actionData);
            showNotification(`Action: ${actionData.label}`, 'info');
    }
}

/**
 * Generate payment link for customer
 */
async function generatePaymentLink() {
    if (!ghostDraftState.userId) return;
    
    try {
        // This would call an API to generate payment link
        // For now, show a placeholder
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.value = '💳 ลิงก์ชำระเงิน: [กำลังสร้าง...]\n\nกรุณาชำระเงินผ่านลิงก์ด้านบนค่ะ';
            autoResize(messageInput);
            messageInput.focus();
        }
    } catch (error) {
        console.error('Generate payment link error:', error);
        showNotification('ไม่สามารถสร้างลิงก์ชำระเงินได้', 'error');
    }
}

/**
 * Load refill suggestions for customer
 */
async function loadRefillSuggestions() {
    if (!ghostDraftState.userId) return;
    
    try {
        const response = await fetch(`api/inbox-v2.php?action=recommendations&user_id=${ghostDraftState.userId}&type=refill&line_account_id=<?= $currentBotId ?>`);
        const result = await response.json();
        
        if (result.success && result.data && result.data.length > 0) {
            const drugs = result.data.map(d => d.name || d.drugName).join(', ');
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.value = `สวัสดีค่ะ ยาที่ซื้อไปก่อนหน้านี้น่าจะใกล้หมดแล้วค่ะ:\n- ${drugs}\n\nต้องการสั่งเติมไหมคะ?`;
                autoResize(messageInput);
                messageInput.focus();
                showNotification('ข้อความแนะนำเติมยาพร้อมส่ง', 'info');
            }
        } else {
            showNotification('ไม่พบยาที่ต้องเติม', 'info');
        }
    } catch (error) {
        console.error('Load refill suggestions error:', error);
        showNotification('ไม่สามารถโหลดข้อมูลได้', 'error');
    }
}

// ============================================
// PURCHASE STAGE ACTIONS - ตัดสินใจซื้อ
// ============================================

/**
 * Open Create Order Modal
 */
function openCreateOrderModal() {
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    // Create modal HTML
    const modalHtml = `
        <div id="createOrderModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999]" onclick="if(event.target===this)closeModal('createOrderModal')">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-hidden">
                <div class="bg-gradient-to-r from-green-500 to-emerald-600 p-4 text-white">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-lg flex items-center gap-2">
                            <i class="fas fa-cart-plus"></i>
                            สร้างออเดอร์
                        </h3>
                        <button onclick="closeModal('createOrderModal')" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="p-4 overflow-y-auto max-h-[60vh]">
                    <div id="orderItemsList" class="space-y-2 mb-4">
                        <p class="text-gray-500 text-sm text-center py-4">
                            <i class="fas fa-info-circle mr-1"></i>
                            เลือกยาจาก HUD Dashboard แล้วกด "เพิ่มในออเดอร์"
                        </p>
                    </div>
                    <div class="border-t pt-4">
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600">รวมสินค้า:</span>
                            <span id="orderSubtotal" class="font-medium">฿0</span>
                        </div>
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600">ส่วนลด:</span>
                            <span id="orderDiscount" class="font-medium text-red-500">-฿0</span>
                        </div>
                        <div class="flex justify-between text-lg font-bold border-t pt-2">
                            <span>รวมทั้งหมด:</span>
                            <span id="orderTotal" class="text-green-600">฿0</span>
                        </div>
                    </div>
                </div>
                <div class="p-4 bg-gray-50 border-t flex gap-2">
                    <button onclick="closeModal('createOrderModal')" class="flex-1 py-2 px-4 bg-gray-200 hover:bg-gray-300 rounded-lg font-medium">
                        ยกเลิก
                    </button>
                    <button onclick="confirmCreateOrder()" class="flex-1 py-2 px-4 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium">
                        <i class="fas fa-check mr-1"></i>สร้างออเดอร์
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Update order items list to show existing items
    updateOrderItemsList();
    
    if (orderState.items.length === 0) {
        showNotification('เลือกยาจาก HUD แล้วเพิ่มในออเดอร์', 'info');
    }
}

/**
 * Send Payment Link
 */
async function sendPaymentLink() {
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    const messageInput = document.getElementById('messageInput');
    
    // Generate LIFF checkout URL - using unified liff/index.php
    const checkoutUrl = `${window.location.origin}/liff/index.php?page=checkout`;
    
    if (messageInput) {
        messageInput.value = `💳 ลิงก์ชำระเงิน\n\n${checkoutUrl}\n\n✅ กดลิงก์ด้านบนเพื่อดูรายการสินค้าและชำระเงินค่ะ\n📦 หลังชำระเงินจะจัดส่งให้ภายใน 1-3 วันทำการค่ะ`;
        autoResize(messageInput);
        messageInput.focus();
        showNotification('ลิงก์ชำระเงินพร้อมส่ง', 'success');
    }
}

/**
 * Open Schedule Delivery Modal
 */
function openScheduleDeliveryModal() {
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const minDate = tomorrow.toISOString().split('T')[0];
    
    const modalHtml = `
        <div id="scheduleDeliveryModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999]" onclick="if(event.target===this)closeModal('scheduleDeliveryModal')">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4">
                <div class="bg-gradient-to-r from-blue-500 to-indigo-600 p-4 text-white">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-lg flex items-center gap-2">
                            <i class="fas fa-truck"></i>
                            นัดส่งสินค้า
                        </h3>
                        <button onclick="closeModal('scheduleDeliveryModal')" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">วันที่ส่ง</label>
                        <input type="date" id="deliveryDate" min="${minDate}" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">ช่วงเวลา</label>
                        <select id="deliveryTime" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="09:00-12:00">เช้า (09:00-12:00)</option>
                            <option value="13:00-17:00">บ่าย (13:00-17:00)</option>
                            <option value="17:00-20:00">เย็น (17:00-20:00)</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
                        <textarea id="deliveryNote" rows="2" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="เช่น โทรก่อนส่ง, ฝากไว้ที่รปภ."></textarea>
                    </div>
                </div>
                <div class="p-4 bg-gray-50 border-t flex gap-2">
                    <button onclick="closeModal('scheduleDeliveryModal')" class="flex-1 py-2 px-4 bg-gray-200 hover:bg-gray-300 rounded-lg font-medium">
                        ยกเลิก
                    </button>
                    <button onclick="confirmScheduleDelivery()" class="flex-1 py-2 px-4 bg-blue-500 hover:bg-blue-600 text-white rounded-lg font-medium">
                        <i class="fas fa-check mr-1"></i>ยืนยัน
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

/**
 * Confirm Schedule Delivery
 */
function confirmScheduleDelivery() {
    const date = document.getElementById('deliveryDate')?.value;
    const time = document.getElementById('deliveryTime')?.value;
    const note = document.getElementById('deliveryNote')?.value || '';
    
    if (!date) {
        showNotification('กรุณาเลือกวันที่ส่ง', 'warning');
        return;
    }
    
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        const thaiDate = new Date(date).toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        messageInput.value = `🚚 นัดส่งสินค้า\n\n📅 วันที่: ${thaiDate}\n⏰ เวลา: ${time}\n${note ? '📝 หมายเหตุ: ' + note + '\n' : ''}\n✅ ยืนยันการนัดส่งค่ะ`;
        autoResize(messageInput);
        messageInput.focus();
    }
    
    closeModal('scheduleDeliveryModal');
    showNotification('ข้อความนัดส่งพร้อมส่ง', 'success');
}

/**
 * Open Use Points Modal
 */
async function openUsePointsModal() {
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    // Fetch user points
    let points = 0;
    try {
        const response = await fetch(`api/inbox-v2.php?action=customer_loyalty&user_id=${ghostDraftState.userId}&line_account_id=<?= $currentBotId ?>`);
        const result = await response.json();
        if (result.success && result.data) {
            points = result.data.points || result.data.totalPoints || 0;
        }
    } catch (e) {
        console.error('Fetch points error:', e);
    }
    
    const modalHtml = `
        <div id="usePointsModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999]" onclick="if(event.target===this)closeModal('usePointsModal')">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4">
                <div class="bg-gradient-to-r from-yellow-500 to-orange-500 p-4 text-white">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-lg flex items-center gap-2">
                            <i class="fas fa-star"></i>
                            ใช้แต้มสะสม
                        </h3>
                        <button onclick="closeModal('usePointsModal')" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <div class="text-center mb-4">
                        <div class="text-4xl font-bold text-yellow-500">${points.toLocaleString()}</div>
                        <div class="text-sm text-gray-500">แต้มสะสมปัจจุบัน</div>
                    </div>
                    <div class="bg-yellow-50 rounded-lg p-3 mb-4">
                        <div class="text-sm text-yellow-800">
                            <i class="fas fa-info-circle mr-1"></i>
                            อัตราแลก: 100 แต้ม = ส่วนลด ฿10
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนแต้มที่ต้องการใช้</label>
                        <input type="number" id="pointsToUse" min="0" max="${points}" step="100" value="0" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div class="text-center">
                        <span class="text-sm text-gray-600">ส่วนลดที่ได้รับ: </span>
                        <span id="pointsDiscount" class="text-lg font-bold text-green-600">฿0</span>
                    </div>
                </div>
                <div class="p-4 bg-gray-50 border-t flex gap-2">
                    <button onclick="closeModal('usePointsModal')" class="flex-1 py-2 px-4 bg-gray-200 hover:bg-gray-300 rounded-lg font-medium">
                        ยกเลิก
                    </button>
                    <button onclick="confirmUsePoints()" class="flex-1 py-2 px-4 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg font-medium">
                        <i class="fas fa-check mr-1"></i>ใช้แต้ม
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Add event listener for points input
    document.getElementById('pointsToUse')?.addEventListener('input', function() {
        const points = parseInt(this.value) || 0;
        const discount = Math.floor(points / 100) * 10;
        document.getElementById('pointsDiscount').textContent = '฿' + discount.toLocaleString();
    });
}

/**
 * Confirm Use Points
 */
function confirmUsePoints() {
    const points = parseInt(document.getElementById('pointsToUse')?.value) || 0;
    const discount = Math.floor(points / 100) * 10;
    
    if (points <= 0) {
        showNotification('กรุณาระบุจำนวนแต้ม', 'warning');
        return;
    }
    
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.value = `⭐ ใช้แต้มสะสม\n\n🎁 ใช้แต้ม: ${points.toLocaleString()} แต้ม\n💰 ส่วนลด: ฿${discount.toLocaleString()}\n\n✅ ระบบบันทึกการใช้แต้มเรียบร้อยค่ะ`;
        autoResize(messageInput);
        messageInput.focus();
    }
    
    closeModal('usePointsModal');
    showNotification('ข้อความใช้แต้มพร้อมส่ง', 'success');
}

/**
 * Send Rich Menu to customer
 */
async function sendRichMenu() {
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    const messageInput = document.getElementById('messageInput');
    const baseUrl = window.location.origin;
    const liffBase = `${baseUrl}/liff/index.php`;
    
    if (messageInput) {
        messageInput.value = `📋 เมนูบริการ\n\n🛒 สั่งซื้อสินค้า: ${liffBase}?page=shop\n💊 ปรึกษาเภสัชกร: ${liffBase}?page=consult\n📦 ติดตามออเดอร์: ${liffBase}?page=orders\n⭐ แต้มสะสม: ${liffBase}?page=points\n\n✨ กดลิงก์เพื่อใช้บริการได้เลยค่ะ`;
        autoResize(messageInput);
        messageInput.focus();
        showNotification('เมนูพร้อมส่ง', 'success');
    }
}

/**
 * Ask followup symptoms
 */
function askFollowupSymptoms() {
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.value = 'อาการเป็นมานานแค่ไหนแล้วคะ? มีอาการอื่นร่วมด้วยไหมคะ เช่น ไข้ ปวดหัว คลื่นไส้?';
        autoResize(messageInput);
        messageInput.focus();
        showNotification('ข้อความพร้อมส่ง', 'info');
    }
}

/**
 * Suggest OTC drugs
 */
function suggestOTC() {
    // Open HUD and focus on drug recommendations
    const hud = document.getElementById('hudDashboard');
    if (hud && hud.classList.contains('collapsed')) {
        toggleHUD();
    }
    const drugWidget = document.getElementById('drugInfoWidget');
    if (drugWidget && drugWidget.classList.contains('collapsed')) {
        toggleWidget('drugInfoWidget');
    }
    showNotification('เลือกยาจาก HUD Dashboard', 'info');
}

/**
 * View customer history
 */
function viewCustomerHistory() {
    // Open HUD and focus on medical history
    const hud = document.getElementById('hudDashboard');
    if (hud && hud.classList.contains('collapsed')) {
        toggleHUD();
    }
    const medicalWidget = document.getElementById('medicalWidget');
    if (medicalWidget && medicalWidget.classList.contains('collapsed')) {
        toggleWidget('medicalWidget');
    }
    showNotification('ดูประวัติสุขภาพใน HUD Dashboard', 'info');
}

/**
 * Confirm Create Order
 */
function confirmCreateOrder() {
    if (orderState.items.length === 0) {
        showNotification('กรุณาเพิ่มสินค้าในออเดอร์ก่อน', 'warning');
        return;
    }
    
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        // Build order summary message
        let orderMsg = `🛒 สรุปรายการสั่งซื้อ\n\n`;
        orderState.items.forEach((item, i) => {
            orderMsg += `${i + 1}. ${item.name}\n   ฿${item.price.toLocaleString()} x ${item.qty} = ฿${(item.price * item.qty).toLocaleString()}\n`;
        });
        orderMsg += `\n💰 รวมทั้งหมด: ฿${orderState.total.toLocaleString()}\n`;
        orderMsg += `\n✅ ตอบ "ยืนยัน" เพื่อสร้างออเดอร์ค่ะ`;
        
        messageInput.value = orderMsg;
        autoResize(messageInput);
        messageInput.focus();
        
        // Save pending order to user state via API
        savePendingOrder();
    }
    
    closeModal('createOrderModal');
    showNotification('สรุปออเดอร์พร้อมส่ง - รอลูกค้าตอบ "ยืนยัน"', 'success');
}

/**
 * Save pending order to user state
 */
async function savePendingOrder() {
    if (!ghostDraftState.userId || orderState.items.length === 0) return;
    
    try {
        const response = await fetch('api/inbox-v2.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_pending_order',
                user_id: ghostDraftState.userId,
                items: orderState.items,
                subtotal: orderState.subtotal,
                discount: orderState.discount,
                total: orderState.total
            })
        });
        
        const result = await response.json();
        if (result.success) {
            console.log('Pending order saved:', result);
        } else {
            console.error('Failed to save pending order:', result.error);
        }
    } catch (error) {
        console.error('Save pending order error:', error);
    }
}

/**
 * Close Modal
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.remove();
    }
}

/**
 * Toggle HUD visibility and adjust chat area
 */
function toggleHUDLayout() {
    const chatArea = document.getElementById('chatArea');
    const hud = document.getElementById('hudDashboard');
    
    if (hud && chatArea) {
        if (hud.classList.contains('collapsed')) {
            chatArea.classList.add('hud-hidden');
        } else {
            chatArea.classList.remove('hud-hidden');
        }
    }
}

// Call on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleHUDLayout();
});

/**
 * Auto-refresh quick actions when conversation context changes
 * Called after sending a message
 */
function onConversationUpdate() {
    // Debounce refresh to avoid too many API calls
    if (quickActionsState.lastRefresh) {
        const timeSinceRefresh = new Date() - quickActionsState.lastRefresh;
        if (timeSinceRefresh < 5000) { // Don't refresh more than once per 5 seconds
            return;
        }
    }
    
    // Refresh quick actions after a short delay
    setTimeout(() => {
        loadQuickActions();
    }, 1000);
    
    // Also refresh HUD widgets based on new conversation context - Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
    if (typeof onConversationUpdateHUD === 'function') {
        onConversationUpdateHUD();
    }
}

/**
 * ============================================
 * Specialized Image Analysis Functions
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
 * ============================================
 */

// Image Analysis State
const imageAnalysisState = {
    isAnalyzing: false,
    currentType: null, // 'symptom', 'drug', 'prescription'
    selectedFile: null,
    lastResult: null
};

/**
 * Toggle image analysis dropdown menu
 */
function toggleImageAnalysisMenu() {
    const menu = document.getElementById('imageAnalysisMenu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

/**
 * Show quick image analysis menu from Quick Actions bar
 * @param {Event} event Click event
 */
function showQuickImageAnalysisMenu(event) {
    event.stopPropagation();
    
    // Create floating menu near the button
    let menu = document.getElementById('quickImageAnalysisMenu');
    if (!menu) {
        menu = document.createElement('div');
        menu.id = 'quickImageAnalysisMenu';
        menu.className = 'fixed bg-white rounded-xl shadow-2xl border border-gray-100 py-2 min-w-[200px] z-50';
        menu.innerHTML = `
            <div class="px-3 py-1 text-[10px] text-gray-400 font-medium uppercase tracking-wider">วิเคราะห์รูปภาพ AI</div>
            <button type="button" onclick="triggerSymptomAnalysis(); closeQuickImageMenu();" class="w-full px-3 py-2 text-left text-sm hover:bg-purple-50 flex items-center gap-2 text-gray-700">
                <span class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center text-red-500">
                    <i class="fas fa-stethoscope"></i>
                </span>
                <div>
                    <div class="font-medium">วิเคราะห์อาการ</div>
                    <div class="text-[10px] text-gray-400">ผื่น, บาดแผล, อาการผิดปกติ</div>
                </div>
            </button>
            <button type="button" onclick="triggerDrugAnalysis(); closeQuickImageMenu();" class="w-full px-3 py-2 text-left text-sm hover:bg-purple-50 flex items-center gap-2 text-gray-700">
                <span class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-500">
                    <i class="fas fa-pills"></i>
                </span>
                <div>
                    <div class="font-medium">ระบุยาจากรูป</div>
                    <div class="text-[10px] text-gray-400">ชื่อยา, สรรพคุณ, ข้อควรระวัง</div>
                </div>
            </button>
            <button type="button" onclick="triggerPrescriptionAnalysis(); closeQuickImageMenu();" class="w-full px-3 py-2 text-left text-sm hover:bg-purple-50 flex items-center gap-2 text-gray-700">
                <span class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center text-blue-500">
                    <i class="fas fa-file-prescription"></i>
                </span>
                <div>
                    <div class="font-medium">อ่านใบสั่งยา</div>
                    <div class="text-[10px] text-gray-400">OCR + ตรวจยาตีกัน</div>
                </div>
            </button>
        `;
        document.body.appendChild(menu);
        
        // Close when clicking outside
        document.addEventListener('click', function closeHandler(e) {
            if (!menu.contains(e.target) && e.target.closest('.quick-action-btn') === null) {
                menu.classList.add('hidden');
            }
        });
    }
    
    // Position menu above the button
    const btn = event.currentTarget;
    const rect = btn.getBoundingClientRect();
    menu.style.left = rect.left + 'px';
    menu.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
    menu.classList.remove('hidden');
}

/**
 * Close quick image analysis menu
 */
function closeQuickImageMenu() {
    const menu = document.getElementById('quickImageAnalysisMenu');
    if (menu) {
        menu.classList.add('hidden');
    }
}

/**
 * Close image analysis menu
 */
function closeImageAnalysisMenu() {
    const menu = document.getElementById('imageAnalysisMenu');
    if (menu) {
        menu.classList.add('hidden');
    }
}

// Close menu when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('imageAnalysisDropdown');
    const menu = document.getElementById('imageAnalysisMenu');
    if (dropdown && menu && !dropdown.contains(e.target)) {
        menu.classList.add('hidden');
    }
});

/**
 * Trigger symptom image analysis - Requirements: 1.1, 1.5
 */
function triggerSymptomAnalysis() {
    closeImageAnalysisMenu();
    imageAnalysisState.currentType = 'symptom';
    showCustomerImagePicker('symptom');
}

/**
 * Trigger drug photo recognition - Requirements: 1.2
 */
function triggerDrugAnalysis() {
    closeImageAnalysisMenu();
    imageAnalysisState.currentType = 'drug';
    showCustomerImagePicker('drug');
}

/**
 * Trigger prescription OCR - Requirements: 1.3, 1.4
 */
function triggerPrescriptionAnalysis() {
    closeImageAnalysisMenu();
    imageAnalysisState.currentType = 'prescription';
    showCustomerImagePicker('prescription');
}

/**
 * Show modal to pick customer images from chat
 * @param {string} type Analysis type
 */
function showCustomerImagePicker(type) {
    // Get all images from chat that customer sent (incoming)
    const chatBox = document.getElementById('chatBox');
    const messageItems = chatBox.querySelectorAll('.message-item');
    const customerImages = [];
    
    messageItems.forEach(item => {
        // Check if it's incoming (customer) message
        if (item.classList.contains('justify-start')) {
            const img = item.querySelector('img.rounded-xl');
            if (img && img.src) {
                customerImages.push({
                    src: img.src,
                    msgId: item.dataset.msgId
                });
            }
        }
    });
    
    if (customerImages.length === 0) {
        showNotification('ไม่พบรูปภาพจากลูกค้าในแชท', 'warning');
        return;
    }
    
    const typeLabels = {
        'symptom': 'วิเคราะห์อาการ',
        'drug': 'ระบุยาจากรูป',
        'prescription': 'อ่านใบสั่งยา'
    };
    
    // Create modal
    let modal = document.getElementById('customerImagePickerModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'customerImagePickerModal';
        modal.className = 'fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden';
        modal.onclick = (e) => {
            if (e.target === modal) closeCustomerImagePicker();
        };
        document.body.appendChild(modal);
    }
    
    modal.innerHTML = `
        <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 max-h-[80vh] overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-800">เลือกรูปภาพเพื่อ${typeLabels[type]}</h3>
                    <p class="text-xs text-gray-500 mt-1">คลิกที่รูปภาพที่ลูกค้าส่งมา</p>
                </div>
                <button onclick="closeCustomerImagePicker()" class="text-gray-400 hover:text-gray-600 p-2">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="p-4 overflow-y-auto max-h-[60vh]">
                <div class="grid grid-cols-3 gap-3">
                    ${customerImages.map((img, idx) => `
                        <div class="relative group cursor-pointer" onclick="selectCustomerImage('${img.src}', '${type}')">
                            <img src="${img.src}" class="w-full h-24 object-cover rounded-lg border-2 border-transparent group-hover:border-purple-500 transition-all">
                            <div class="absolute inset-0 bg-purple-500/0 group-hover:bg-purple-500/20 rounded-lg transition-all flex items-center justify-center">
                                <i class="fas fa-search-plus text-white opacity-0 group-hover:opacity-100 text-xl"></i>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
            <div class="p-4 border-t border-gray-100 bg-gray-50">
                <p class="text-xs text-gray-500 text-center">
                    <i class="fas fa-info-circle mr-1"></i>
                    หรือ <button onclick="closeCustomerImagePicker(); document.getElementById('${type}ImageInput').click();" class="text-purple-600 hover:underline">อัพโหลดรูปใหม่</button>
                </p>
            </div>
        </div>
    `;
    
    modal.classList.remove('hidden');
}

/**
 * Close customer image picker modal
 */
function closeCustomerImagePicker() {
    const modal = document.getElementById('customerImagePickerModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

/**
 * Select customer image for analysis
 * @param {string} imageUrl Image URL
 * @param {string} type Analysis type
 */
async function selectCustomerImage(imageUrl, type) {
    closeCustomerImagePicker();
    
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    imageAnalysisState.isAnalyzing = true;
    showNotification('กำลังวิเคราะห์รูปภาพ...', 'info');
    
    try {
        let result;
        switch (type) {
            case 'symptom':
                result = await analyzeSymptomImage(imageUrl);
                break;
            case 'drug':
                result = await analyzeDrugImage(imageUrl);
                break;
            case 'prescription':
                result = await analyzePrescriptionImage(imageUrl);
                break;
            default:
                throw new Error('Unknown analysis type');
        }
        
        imageAnalysisState.lastResult = result;
        
        // Display results in HUD widget (don't send image to chat)
        displayAnalysisResults(type, result);
        
        showNotification('วิเคราะห์รูปภาพสำเร็จ', 'success');
        
    } catch (error) {
        console.error('Image analysis error:', error);
        showNotification(error.message || 'เกิดข้อผิดพลาดในการวิเคราะห์', 'error');
    } finally {
        imageAnalysisState.isAnalyzing = false;
    }
}

/**
 * Handle symptom image selection - Requirements: 1.1, 1.5
 * @param {HTMLInputElement} input
 */
function handleSymptomImageSelect(input) {
    if (input.files && input.files[0]) {
        imageAnalysisState.selectedFile = input.files[0];
        showImageAnalysisPreview(input.files[0], 'symptom');
    }
}

/**
 * Handle drug image selection - Requirements: 1.2
 * @param {HTMLInputElement} input
 */
function handleDrugImageSelect(input) {
    if (input.files && input.files[0]) {
        imageAnalysisState.selectedFile = input.files[0];
        showImageAnalysisPreview(input.files[0], 'drug');
    }
}

/**
 * Handle prescription image selection - Requirements: 1.3, 1.4
 * @param {HTMLInputElement} input
 */
function handlePrescriptionImageSelect(input) {
    if (input.files && input.files[0]) {
        imageAnalysisState.selectedFile = input.files[0];
        showImageAnalysisPreview(input.files[0], 'prescription');
    }
}

/**
 * Show image analysis preview with analysis type indicator
 * @param {File} file
 * @param {string} type 'symptom', 'drug', or 'prescription'
 */
function showImageAnalysisPreview(file, type) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    const previewName = document.getElementById('previewName');
    const previewSize = document.getElementById('previewSize');
    
    if (preview && previewImg) {
        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = e.target.result;
        };
        reader.readAsDataURL(file);
        
        // Update preview with analysis type
        const typeLabels = {
            'symptom': '🔬 วิเคราะห์อาการ',
            'drug': '💊 ระบุยา',
            'prescription': '📋 อ่านใบสั่งยา'
        };
        
        const typeColors = {
            'symptom': 'text-red-600',
            'drug': 'text-emerald-600',
            'prescription': 'text-blue-600'
        };
        
        previewName.innerHTML = `<span class="${typeColors[type]} font-medium">${typeLabels[type]}</span> - ${escapeHtml(file.name)}`;
        previewSize.textContent = formatFileSize(file.size);
        
        // Update preview buttons for analysis
        updatePreviewButtonsForAnalysis(type);
        
        preview.classList.remove('hidden');
    }
}

/**
 * Update preview buttons for image analysis
 * @param {string} type
 */
function updatePreviewButtonsForAnalysis(type) {
    const preview = document.getElementById('imagePreview');
    if (!preview) return;
    
    // Find or create the button container
    let btnContainer = preview.querySelector('.analysis-btn-container');
    if (!btnContainer) {
        const existingBtns = preview.querySelector('.flex.items-center.gap-2');
        if (existingBtns) {
            // Replace the send button with analysis buttons
            const cancelBtn = existingBtns.querySelector('button[onclick*="cancelImageUpload"]');
            const sendBtn = existingBtns.querySelector('button[onclick*="sendImage"]');
            
            if (sendBtn) {
                sendBtn.remove();
            }
            
            btnContainer = document.createElement('div');
            btnContainer.className = 'analysis-btn-container flex gap-2';
            existingBtns.appendChild(btnContainer);
        }
    }
    
    if (btnContainer) {
        const typeLabels = {
            'symptom': 'วิเคราะห์อาการ',
            'drug': 'ระบุยา',
            'prescription': 'อ่านใบสั่งยา'
        };
        
        const typeColors = {
            'symptom': 'bg-red-500 hover:bg-red-600',
            'drug': 'bg-emerald-500 hover:bg-emerald-600',
            'prescription': 'bg-blue-500 hover:bg-blue-600'
        };
        
        btnContainer.innerHTML = `
            <button type="button" onclick="analyzeImage('${type}')" 
                    class="${typeColors[type]} text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                <i class="fas fa-magic"></i>
                ${typeLabels[type]}
            </button>
            <button type="button" onclick="sendImageWithoutAnalysis()" 
                    class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded-lg text-sm">
                <i class="fas fa-paper-plane"></i>
            </button>
        `;
    }
}

/**
 * Analyze image based on type - Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
 * @param {string} type 'symptom', 'drug', or 'prescription'
 */
async function analyzeImage(type) {
    if (!imageAnalysisState.selectedFile || imageAnalysisState.isAnalyzing) {
        return;
    }
    
    if (!ghostDraftState.userId) {
        showNotification('กรุณาเลือกลูกค้าก่อน', 'warning');
        return;
    }
    
    imageAnalysisState.isAnalyzing = true;
    showNotification('กำลังวิเคราะห์รูปภาพ...', 'info');
    
    try {
        // First, upload the image to get URL
        const imageUrl = await uploadImageForAnalysis(imageAnalysisState.selectedFile);
        
        if (!imageUrl) {
            throw new Error('ไม่สามารถอัพโหลดรูปภาพได้');
        }
        
        // Call the appropriate analysis API
        let result;
        switch (type) {
            case 'symptom':
                result = await analyzeSymptomImage(imageUrl);
                break;
            case 'drug':
                result = await analyzeDrugImage(imageUrl);
                break;
            case 'prescription':
                result = await analyzePrescriptionImage(imageUrl);
                break;
            default:
                throw new Error('Unknown analysis type');
        }
        
        imageAnalysisState.lastResult = result;
        
        // Display results in HUD widget (don't send image to chat)
        displayAnalysisResults(type, result);
        
        // Clear the preview
        cancelImageUpload();
        
        showNotification('วิเคราะห์รูปภาพสำเร็จ', 'success');
        
    } catch (error) {
        console.error('Image analysis error:', error);
        showNotification(error.message || 'เกิดข้อผิดพลาดในการวิเคราะห์', 'error');
    } finally {
        imageAnalysisState.isAnalyzing = false;
        imageAnalysisState.selectedFile = null;
    }
}

/**
 * Upload image and get URL for analysis
 * @param {File} file
 * @returns {Promise<string>} Image URL
 */
async function uploadImageForAnalysis(file) {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('action', 'upload_for_analysis');
    formData.append('user_id', ghostDraftState.userId);
    
    // Upload to server
    const uploadDir = 'uploads/analysis_images/';
    
    // Use the existing image upload mechanism
    const response = await fetch('inbox-v2.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    });
    
    // If the upload endpoint doesn't exist, create a data URL
    if (!response.ok) {
        // Fallback: convert to base64 data URL
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }
    
    const result = await response.json();
    return result.image_url || result.url;
}

/**
 * Analyze symptom image - Requirements: 1.1, 1.5
 * @param {string} imageUrl
 * @returns {Promise<object>}
 */
async function analyzeSymptomImage(imageUrl) {
    const formData = new FormData();
    formData.append('action', 'analyze_symptom');
    formData.append('image_url', imageUrl);
    formData.append('user_id', ghostDraftState.userId);
    
    const response = await fetch('api/inbox-v2.php', {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.error || 'การวิเคราะห์อาการล้มเหลว');
    }
    
    return result.data;
}

/**
 * Analyze drug image - Requirements: 1.2
 * @param {string} imageUrl
 * @returns {Promise<object>}
 */
async function analyzeDrugImage(imageUrl) {
    const formData = new FormData();
    formData.append('action', 'analyze_drug');
    formData.append('image_url', imageUrl);
    
    const response = await fetch('api/inbox-v2.php', {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.error || 'การระบุยาล้มเหลว');
    }
    
    return result.data;
}

/**
 * Analyze prescription image - Requirements: 1.3, 1.4
 * @param {string} imageUrl
 * @returns {Promise<object>}
 */
async function analyzePrescriptionImage(imageUrl) {
    const formData = new FormData();
    formData.append('action', 'analyze_prescription');
    formData.append('image_url', imageUrl);
    formData.append('user_id', ghostDraftState.userId);
    
    const response = await fetch('api/inbox-v2.php', {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.error || 'การอ่านใบสั่งยาล้มเหลว');
    }
    
    return result.data;
}

/**
 * Display analysis results in HUD widget - Requirements: 1.6
 * @param {string} type
 * @param {object} result
 */
function displayAnalysisResults(type, result) {
    // Open HUD if closed
    const hud = document.getElementById('hudDashboard');
    if (hud && hud.classList.contains('collapsed')) {
        toggleHUD();
    }
    
    switch (type) {
        case 'symptom':
            displaySymptomAnalysisResult(result);
            break;
        case 'drug':
            displayDrugAnalysisResult(result);
            break;
        case 'prescription':
            displayPrescriptionAnalysisResult(result);
            break;
    }
}

/**
 * Display symptom analysis result in HUD - Requirements: 1.1, 1.5
 * @param {object} result
 */
function displaySymptomAnalysisResult(result) {
    const content = document.getElementById('symptomContent');
    if (!content) return;
    
    // Expand symptom widget
    const widget = document.getElementById('symptomWidget');
    if (widget && widget.classList.contains('collapsed')) {
        toggleWidget('symptomWidget');
    }
    
    // Check for urgency - Requirements: 1.5
    const isUrgent = result.urgency || result.isUrgent || result.severity === 'severe';
    
    let html = '';
    
    if (isUrgent) {
        html += `
            <div class="bg-red-100 border-l-4 border-red-500 p-3 mb-3 rounded-r-lg">
                <div class="flex items-center gap-2 text-red-700 font-bold">
                    <i class="fas fa-exclamation-triangle"></i>
                    ⚠️ แนะนำพบแพทย์
                </div>
                <p class="text-red-600 text-xs mt-1">${escapeHtml(result.urgencyReason || result.recommendation || 'อาการรุนแรง ควรพบแพทย์')}</p>
            </div>
        `;
    }
    
    html += `
        <div class="space-y-2">
            <div class="bg-purple-50 rounded-lg p-2">
                <div class="text-xs font-medium text-purple-700 mb-1">🔬 ผลการวิเคราะห์</div>
                <div class="text-sm text-gray-800">${escapeHtml(result.condition || result.analysis || 'ไม่สามารถระบุได้')}</div>
            </div>
            
            ${result.severity ? `
            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-500">ความรุนแรง:</span>
                <span class="text-xs px-2 py-0.5 rounded-full ${
                    result.severity === 'severe' ? 'bg-red-100 text-red-700' :
                    result.severity === 'moderate' ? 'bg-yellow-100 text-yellow-700' :
                    'bg-green-100 text-green-700'
                }">${escapeHtml(result.severity)}</span>
            </div>
            ` : ''}
            
            ${result.recommendations && result.recommendations.length > 0 ? `
            <div class="mt-2">
                <div class="text-xs font-medium text-gray-600 mb-1">💊 ยาแนะนำ</div>
                ${result.recommendations.map(drug => `
                    <div class="recommended-drug" onclick="selectDrugForInfo(${drug.id || 0}, '${escapeHtml(drug.name || '')}')">
                        <div>
                            <div class="text-xs font-medium text-gray-800">${escapeHtml(drug.name || drug)}</div>
                            ${drug.dosage ? `<div class="text-[10px] text-gray-500">${escapeHtml(drug.dosage)}</div>` : ''}
                        </div>
                        ${drug.price ? `<div class="text-xs font-bold text-emerald-600">฿${drug.price.toLocaleString()}</div>` : ''}
                    </div>
                `).join('')}
            </div>
            ` : ''}
        </div>
    `;
    
    content.innerHTML = html;
    
    // Show notification
    if (isUrgent) {
        showNotification('🚨 ตรวจพบอาการที่ต้องพบแพทย์', 'warning');
    } else {
        showNotification('✓ วิเคราะห์อาการเสร็จสิ้น', 'success');
    }
}

/**
 * Display drug analysis result in HUD - Requirements: 1.2
 * @param {object} result
 */
function displayDrugAnalysisResult(result) {
    const content = document.getElementById('drugInfoContent');
    if (!content) return;
    
    // Expand drug info widget
    const widget = document.getElementById('drugInfoWidget');
    if (widget && widget.classList.contains('collapsed')) {
        toggleWidget('drugInfoWidget');
    }
    
    const drug = result.drug || result;
    
    let html = `
        <div class="space-y-3">
            <div class="bg-emerald-50 rounded-lg p-2 mb-2">
                <div class="text-xs text-emerald-600 mb-1">📷 ระบุจากรูปภาพ</div>
            </div>
            
            <div class="flex justify-between items-start">
                <div>
                    <div class="drug-name">${escapeHtml(drug.drugName || drug.name || 'ไม่ทราบชื่อยา')}</div>
                    ${drug.genericName ? `<div class="text-xs text-gray-500">${escapeHtml(drug.genericName)}</div>` : ''}
                    <div class="drug-dosage">${escapeHtml(drug.dosage || drug.description || '')}</div>
                </div>
            </div>
            
            ${drug.usage ? `
            <div class="bg-blue-50 rounded-lg p-2 text-xs">
                <p class="font-medium text-blue-700 mb-1">📋 วิธีใช้</p>
                <p class="text-blue-600">${escapeHtml(drug.usage)}</p>
            </div>
            ` : ''}
            
            ${drug.contraindications && drug.contraindications.length > 0 ? `
            <div class="bg-yellow-50 rounded-lg p-2 text-xs">
                <p class="font-medium text-yellow-700 mb-1">⚠️ ข้อควรระวัง</p>
                <ul class="text-yellow-600 text-[11px] list-disc list-inside">
                    ${(Array.isArray(drug.contraindications) ? drug.contraindications : [drug.contraindications]).map(c => `<li>${escapeHtml(c)}</li>`).join('')}
                </ul>
            </div>
            ` : ''}
            
            ${drug.matchedProductId ? `
            <div class="flex gap-2 mt-2">
                <button onclick="selectDrugForInfo(${drug.matchedProductId}, '')" class="flex-1 text-xs py-1.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-700 rounded-lg">
                    <i class="fas fa-info-circle mr-1"></i>ดูข้อมูลในระบบ
                </button>
                <button onclick="insertDrugToMessage(${drug.matchedProductId})" class="flex-1 text-xs py-1.5 bg-purple-100 hover:bg-purple-200 text-purple-700 rounded-lg">
                    <i class="fas fa-plus mr-1"></i>เพิ่มในข้อความ
                </button>
            </div>
            ` : ''}
        </div>
    `;
    
    content.innerHTML = html;
    showNotification('✓ ระบุยาจากรูปเสร็จสิ้น', 'success');
}

/**
 * Display prescription analysis result in HUD - Requirements: 1.3, 1.4
 * @param {object} result
 */
function displayPrescriptionAnalysisResult(result) {
    // Create or update prescription widget
    let widget = document.getElementById('prescriptionWidget');
    const hudWidgets = document.getElementById('hudWidgets');
    
    if (!widget && hudWidgets) {
        const widgetHtml = `
            <div class="hud-widget" id="prescriptionWidget" style="background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%); border: 2px solid #60A5FA;">
                <div class="hud-widget-header" onclick="toggleWidget('prescriptionWidget')" style="background: #3B82F6; color: white; border-bottom: none;">
                    <h4 style="color: white;"><i class="fas fa-file-prescription"></i> ใบสั่งยา OCR</h4>
                    <i class="fas fa-chevron-down text-white/70 text-xs"></i>
                </div>
                <div class="hud-widget-body" id="prescriptionContent"></div>
            </div>
        `;
        
        // Insert at the top of HUD widgets
        hudWidgets.insertAdjacentHTML('afterbegin', widgetHtml);
        widget = document.getElementById('prescriptionWidget');
    }
    
    const content = document.getElementById('prescriptionContent');
    if (!content) return;
    
    // Expand widget
    if (widget && widget.classList.contains('collapsed')) {
        toggleWidget('prescriptionWidget');
    }
    
    const drugs = result.drugs || result.extractedDrugs || [];
    const hasInteractions = result.interactions && result.interactions.length > 0;
    
    let html = `
        <div class="space-y-2">
            ${result.doctorName || result.hospitalName ? `
            <div class="bg-white rounded-lg p-2 text-xs">
                ${result.doctorName ? `<div><span class="text-gray-500">แพทย์:</span> ${escapeHtml(result.doctorName)}</div>` : ''}
                ${result.hospitalName ? `<div><span class="text-gray-500">โรงพยาบาล:</span> ${escapeHtml(result.hospitalName)}</div>` : ''}
                ${result.prescriptionDate ? `<div><span class="text-gray-500">วันที่:</span> ${escapeHtml(result.prescriptionDate)}</div>` : ''}
            </div>
            ` : ''}
            
            <div class="text-xs font-medium text-blue-700 mt-2">📋 รายการยา (${drugs.length} รายการ)</div>
            
            ${drugs.map((drug, index) => `
                <div class="bg-white rounded-lg p-2 text-xs border-l-2 ${drug.hasInteraction ? 'border-red-400' : 'border-blue-400'}">
                    <div class="font-medium text-gray-800">${index + 1}. ${escapeHtml(drug.name || drug.drugName || drug)}</div>
                    ${drug.dosage ? `<div class="text-gray-500">${escapeHtml(drug.dosage)}</div>` : ''}
                    ${drug.quantity ? `<div class="text-gray-500">จำนวน: ${escapeHtml(drug.quantity)}</div>` : ''}
                    ${drug.hasInteraction ? `<div class="text-red-500 mt-1"><i class="fas fa-exclamation-triangle"></i> มียาตีกัน</div>` : ''}
                </div>
            `).join('')}
            
            ${hasInteractions ? `
            <div class="bg-red-50 border-l-4 border-red-500 p-2 rounded-r-lg mt-2">
                <div class="text-xs font-bold text-red-700 mb-1">⚠️ พบปฏิกิริยาระหว่างยา</div>
                ${result.interactions.map(interaction => `
                    <div class="text-[11px] text-red-600 mb-1">
                        • ${escapeHtml(interaction.drug1 || '')} + ${escapeHtml(interaction.drug2 || '')}: ${escapeHtml(interaction.effect || interaction.description || '')}
                    </div>
                `).join('')}
            </div>
            ` : `
            <div class="bg-green-50 border-l-4 border-green-500 p-2 rounded-r-lg mt-2">
                <div class="text-xs text-green-700">
                    <i class="fas fa-check-circle"></i> ไม่พบปฏิกิริยาระหว่างยา
                </div>
            </div>
            `}
            
            ${result.ocrConfidence ? `
            <div class="text-[10px] text-gray-400 mt-2 text-right">
                ความแม่นยำ OCR: ${Math.round(result.ocrConfidence * 100)}%
            </div>
            ` : ''}
        </div>
    `;
    
    content.innerHTML = html;
    
    // Also update interaction widget if there are interactions
    if (hasInteractions) {
        updateInteractionWidget({ interactions: result.interactions });
        showNotification('⚠️ พบปฏิกิริยาระหว่างยาในใบสั่ง', 'warning');
    } else {
        showNotification('✓ อ่านใบสั่งยาเสร็จสิ้น', 'success');
    }
}

/**
 * Send analyzed image to chat with results summary
 * @param {string} imageUrl
 * @param {string} type
 * @param {object} result
 */
async function sendAnalyzedImage(imageUrl, type, result) {
    if (!ghostDraftState.userId) return;
    
    try {
        // First send the image
        const formData = new FormData();
        formData.append('action', 'send_image');
        formData.append('user_id', ghostDraftState.userId);
        
        // If we have the original file, use it
        if (imageAnalysisState.selectedFile) {
            formData.append('image', imageAnalysisState.selectedFile);
        }
        
        const response = await fetch('inbox-v2.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const imgResult = await response.json();
        
        if (imgResult.success) {
            // Add image to chat
            appendImageMessage(imgResult.image_url, imgResult.time, imgResult.sent_by);
            scrollToBottom();
        }
    } catch (error) {
        console.error('Failed to send analyzed image:', error);
    }
}

/**
 * Send image without analysis (fallback)
 */
async function sendImageWithoutAnalysis() {
    if (!imageAnalysisState.selectedFile) {
        // Use the regular image upload
        sendImage();
        return;
    }
    
    // Reset state and use regular send
    selectedImageFile = imageAnalysisState.selectedFile;
    imageAnalysisState.selectedFile = null;
    imageAnalysisState.currentType = null;
    
    await sendImage();
}
</script>
<?php endif; ?>

<?php if ($currentTab === 'analytics'): ?>
<!-- ANALYTICS TAB - Consultation Analytics Dashboard -->
<!-- Requirements: 8.1, 8.2, 8.3, 8.4, 8.5 -->
<div id="analyticsContainer" class="min-h-screen bg-gray-50 p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="inbox-v2.php" class="w-10 h-10 flex items-center justify-center rounded-full bg-white shadow hover:bg-gray-50 text-gray-600">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-purple-600"></i>
                        สถิติการให้คำปรึกษา
                        <span class="vibe-badge">V2</span>
                    </h1>
                    <p class="text-sm text-gray-500">Consultation Analytics - Vibe Selling OS</p>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 bg-white rounded-lg shadow px-3 py-2">
                    <label class="text-sm text-gray-600">ช่วงเวลา:</label>
                    <input type="date" id="analyticsStartDate" class="border rounded px-2 py-1 text-sm" 
                           value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                    <span class="text-gray-400">-</span>
                    <input type="date" id="analyticsEndDate" class="border rounded px-2 py-1 text-sm" 
                           value="<?= date('Y-m-d') ?>">
                    <button onclick="loadAnalyticsData()" class="bg-purple-600 text-white px-3 py-1 rounded text-sm hover:bg-purple-700">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading State -->
    <div id="analyticsLoading" class="hidden">
        <div class="flex items-center justify-center py-20">
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-purple-600 mb-4"></i>
                <p class="text-gray-500">กำลังโหลดข้อมูล...</p>
            </div>
        </div>
    </div>
    
    <!-- Analytics Content -->
    <div id="analyticsContent">
        <!-- Summary Cards - Requirements: 8.1, 8.2, 8.5 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Total Consultations -->
            <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 mb-1">การให้คำปรึกษาทั้งหมด</p>
                        <p class="text-3xl font-bold text-gray-800" id="totalConsultations">-</p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-comments text-purple-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- Success Rate - Requirements: 8.1 -->
            <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 mb-1">อัตราความสำเร็จ</p>
                        <p class="text-3xl font-bold text-gray-800" id="successRate">-</p>
                        <p class="text-xs text-gray-400" id="successCount">- จาก - รายการ</p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- Average Response Time - Requirements: 8.2 -->
            <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 mb-1">เวลาตอบเฉลี่ย</p>
                        <p class="text-3xl font-bold text-gray-800" id="avgResponseTime">-</p>
                        <p class="text-xs text-gray-400">วินาที</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-clock text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- AI Acceptance Rate - Requirements: 8.5 -->
            <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-amber-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 mb-1">อัตราการใช้ AI</p>
                        <p class="text-3xl font-bold text-gray-800" id="aiAcceptanceRate">-</p>
                        <p class="text-xs text-gray-400" id="aiAcceptanceCount">- จาก - คำแนะนำ</p>
                    </div>
                    <div class="w-12 h-12 bg-amber-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-robot text-amber-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Success Rate by Communication Type - Requirements: 8.1 -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-users text-purple-600"></i>
                    อัตราความสำเร็จตามประเภทลูกค้า
                </h3>
                <div id="typeBreakdownChart" class="space-y-4">
                    <!-- Type A -->
                    <div class="flex items-center gap-3">
                        <div class="w-20 text-sm font-medium text-gray-600">Type A</div>
                        <div class="flex-1 bg-gray-100 rounded-full h-6 overflow-hidden">
                            <div id="typeABar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-full rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="w-16 text-right text-sm font-semibold" id="typeARate">-</div>
                    </div>
                    <p class="text-xs text-gray-400 ml-20 -mt-2">Direct - ต้องการคำตอบรวดเร็ว</p>
                    
                    <!-- Type B -->
                    <div class="flex items-center gap-3">
                        <div class="w-20 text-sm font-medium text-gray-600">Type B</div>
                        <div class="flex-1 bg-gray-100 rounded-full h-6 overflow-hidden">
                            <div id="typeBBar" class="bg-gradient-to-r from-green-500 to-green-600 h-full rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="w-16 text-right text-sm font-semibold" id="typeBRate">-</div>
                    </div>
                    <p class="text-xs text-gray-400 ml-20 -mt-2">Concerned - ต้องการความมั่นใจ</p>
                    
                    <!-- Type C -->
                    <div class="flex items-center gap-3">
                        <div class="w-20 text-sm font-medium text-gray-600">Type C</div>
                        <div class="flex-1 bg-gray-100 rounded-full h-6 overflow-hidden">
                            <div id="typeCBar" class="bg-gradient-to-r from-purple-500 to-purple-600 h-full rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                        <div class="w-16 text-right text-sm font-semibold" id="typeCRate">-</div>
                    </div>
                    <p class="text-xs text-gray-400 ml-20 -mt-2">Detailed - ต้องการข้อมูลละเอียด</p>
                </div>
            </div>
            
            <!-- Response Time Impact - Requirements: 8.2 -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-tachometer-alt text-blue-600"></i>
                    ผลกระทบของเวลาตอบต่อความสำเร็จ
                </h3>
                <div id="responseTimeImpact" class="space-y-4">
                    <!-- Fast Response -->
                    <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-green-700">
                                <i class="fas fa-bolt mr-1"></i> ตอบเร็ว (&lt; 30 วินาที)
                            </span>
                            <span class="text-lg font-bold text-green-700" id="fastResponseRate">-</span>
                        </div>
                        <div class="w-full bg-green-200 rounded-full h-2">
                            <div id="fastResponseBar" class="bg-green-500 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                    </div>
                    
                    <!-- Medium Response -->
                    <div class="p-4 bg-amber-50 rounded-lg border border-amber-200">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-amber-700">
                                <i class="fas fa-clock mr-1"></i> ตอบปานกลาง (30-120 วินาที)
                            </span>
                            <span class="text-lg font-bold text-amber-700" id="mediumResponseRate">-</span>
                        </div>
                        <div class="w-full bg-amber-200 rounded-full h-2">
                            <div id="mediumResponseBar" class="bg-amber-500 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                    </div>
                    
                    <!-- Slow Response -->
                    <div class="p-4 bg-red-50 rounded-lg border border-red-200">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-red-700">
                                <i class="fas fa-hourglass-half mr-1"></i> ตอบช้า (&gt; 120 วินาที)
                            </span>
                            <span class="text-lg font-bold text-red-700" id="slowResponseRate">-</span>
                        </div>
                        <div class="w-full bg-red-200 rounded-full h-2">
                            <div id="slowResponseBar" class="bg-red-500 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Additional Stats Row -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Revenue Stats -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-coins text-yellow-600"></i>
                    รายได้จากการให้คำปรึกษา
                </h3>
                <div class="text-center py-4">
                    <p class="text-4xl font-bold text-gray-800" id="totalRevenue">฿0</p>
                    <p class="text-sm text-gray-500 mt-2">รายได้รวมในช่วงเวลาที่เลือก</p>
                </div>
            </div>
            
            <!-- Average Messages -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-envelope text-indigo-600"></i>
                    ข้อความเฉลี่ยต่อการให้คำปรึกษา
                </h3>
                <div class="text-center py-4">
                    <p class="text-4xl font-bold text-gray-800" id="avgMessages">-</p>
                    <p class="text-sm text-gray-500 mt-2">ข้อความ</p>
                </div>
            </div>
            
            <!-- AI Suggestions Stats - Requirements: 8.5 -->
            <div class="bg-white rounded-xl shadow-sm p-5">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-magic text-pink-600"></i>
                    สถิติ AI Suggestions
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">แสดงทั้งหมด</span>
                        <span class="font-semibold" id="totalAiSuggestions">-</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">ถูกใช้งาน</span>
                        <span class="font-semibold text-green-600" id="acceptedAiSuggestions">-</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">ไม่ถูกใช้</span>
                        <span class="font-semibold text-gray-400" id="rejectedAiSuggestions">-</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- No Data State -->
        <div id="noDataState" class="hidden">
            <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-chart-bar text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">ยังไม่มีข้อมูลสถิติ</h3>
                <p class="text-gray-500 mb-4">เริ่มให้คำปรึกษาลูกค้าเพื่อเก็บข้อมูลสถิติ</p>
                <a href="inbox-v2.php" class="inline-flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700">
                    <i class="fas fa-inbox"></i>
                    ไปที่ Inbox
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Analytics Tab Styles */
#analyticsContainer {
    min-height: calc(100vh - 60px);
}
.vibe-badge {
    background: linear-gradient(135deg, #8B5CF6, #6366F1);
    color: white;
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 4px;
}
</style>

<script>
/**
 * Analytics Tab JavaScript
 * Requirements: 8.1, 8.2, 8.3, 8.4, 8.5
 */

// Load analytics data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadAnalyticsData();
});

/**
 * Load analytics data from API
 * Fetches data from /analytics endpoint
 */
async function loadAnalyticsData() {
    const startDate = document.getElementById('analyticsStartDate').value;
    const endDate = document.getElementById('analyticsEndDate').value;
    
    // Show loading state
    document.getElementById('analyticsLoading').classList.remove('hidden');
    document.getElementById('analyticsContent').classList.add('opacity-50');
    document.getElementById('noDataState').classList.add('hidden');
    
    try {
        const response = await fetch(`api/inbox-v2.php?action=analytics&start_date=${startDate}&end_date=${endDate}&line_account_id=<?= $currentBotId ?>`);
        const result = await response.json();
        
        if (result.success && result.data) {
            updateAnalyticsUI(result.data);
        } else {
            showNoDataState();
        }
    } catch (error) {
        console.error('Failed to load analytics:', error);
        showNoDataState();
    } finally {
        // Hide loading state
        document.getElementById('analyticsLoading').classList.add('hidden');
        document.getElementById('analyticsContent').classList.remove('opacity-50');
    }
}

/**
 * Update analytics UI with data
 * @param {object} data Analytics data from API
 */
function updateAnalyticsUI(data) {
    const summary = data.summary || {};
    const byType = data.byType || [];
    
    // Check if we have any data
    if (!summary.totalConsultations || summary.totalConsultations === 0) {
        showNoDataState();
        return;
    }
    
    // Hide no data state
    document.getElementById('noDataState').classList.add('hidden');
    
    // Update summary cards
    document.getElementById('totalConsultations').textContent = formatNumber(summary.totalConsultations || 0);
    document.getElementById('successRate').textContent = (summary.successRate || 0) + '%';
    document.getElementById('successCount').textContent = 
        `${formatNumber(summary.successfulConsultations || 0)} จาก ${formatNumber(summary.totalConsultations || 0)} รายการ`;
    
    document.getElementById('avgResponseTime').textContent = formatNumber(Math.round(summary.avgResponseTime || 0));
    document.getElementById('aiAcceptanceRate').textContent = (summary.aiAcceptanceRate || 0) + '%';
    
    // Calculate AI suggestion counts
    const totalAi = summary.totalAiSuggestions || 0;
    const acceptedAi = summary.acceptedAiSuggestions || 0;
    document.getElementById('aiAcceptanceCount').textContent = `${formatNumber(acceptedAi)} จาก ${formatNumber(totalAi)} คำแนะนำ`;
    
    // Update revenue
    document.getElementById('totalRevenue').textContent = '฿' + formatNumber(summary.totalRevenue || 0);
    
    // Update average messages
    document.getElementById('avgMessages').textContent = (summary.avgMessagesPerConsultation || 0).toFixed(1);
    
    // Update AI suggestions stats
    document.getElementById('totalAiSuggestions').textContent = formatNumber(totalAi);
    document.getElementById('acceptedAiSuggestions').textContent = formatNumber(acceptedAi);
    document.getElementById('rejectedAiSuggestions').textContent = formatNumber(totalAi - acceptedAi);
    
    // Update type breakdown chart
    updateTypeBreakdown(byType);
    
    // Update response time impact (simulated based on avg response time)
    updateResponseTimeImpact(summary.avgResponseTime || 0, summary.successRate || 0);
}

/**
 * Update type breakdown chart
 * @param {array} byType Data by communication type
 */
function updateTypeBreakdown(byType) {
    const typeData = {
        'A': { count: 0, purchases: 0, rate: 0 },
        'B': { count: 0, purchases: 0, rate: 0 },
        'C': { count: 0, purchases: 0, rate: 0 }
    };
    
    // Process data
    byType.forEach(item => {
        const type = item.communication_type;
        if (type && typeData[type]) {
            typeData[type].count = parseInt(item.count) || 0;
            typeData[type].purchases = parseInt(item.purchases) || 0;
            typeData[type].rate = typeData[type].count > 0 
                ? Math.round((typeData[type].purchases / typeData[type].count) * 100) 
                : 0;
        }
    });
    
    // Update UI
    ['A', 'B', 'C'].forEach(type => {
        const rate = typeData[type].rate;
        document.getElementById(`type${type}Bar`).style.width = rate + '%';
        document.getElementById(`type${type}Rate`).textContent = rate + '%';
    });
}

/**
 * Update response time impact visualization
 * @param {number} avgResponseTime Average response time in seconds
 * @param {number} successRate Overall success rate
 */
function updateResponseTimeImpact(avgResponseTime, successRate) {
    // Simulate response time distribution based on average
    // In a real implementation, this would come from the API
    let fastRate, mediumRate, slowRate;
    
    if (avgResponseTime < 30) {
        fastRate = Math.min(95, successRate + 10);
        mediumRate = Math.max(50, successRate - 5);
        slowRate = Math.max(30, successRate - 20);
    } else if (avgResponseTime < 120) {
        fastRate = Math.min(90, successRate + 5);
        mediumRate = successRate;
        slowRate = Math.max(40, successRate - 15);
    } else {
        fastRate = Math.min(85, successRate);
        mediumRate = Math.max(50, successRate - 10);
        slowRate = Math.max(35, successRate - 25);
    }
    
    // Update UI
    document.getElementById('fastResponseRate').textContent = Math.round(fastRate) + '%';
    document.getElementById('fastResponseBar').style.width = fastRate + '%';
    
    document.getElementById('mediumResponseRate').textContent = Math.round(mediumRate) + '%';
    document.getElementById('mediumResponseBar').style.width = mediumRate + '%';
    
    document.getElementById('slowResponseRate').textContent = Math.round(slowRate) + '%';
    document.getElementById('slowResponseBar').style.width = slowRate + '%';
}

/**
 * Show no data state
 */
function showNoDataState() {
    document.getElementById('noDataState').classList.remove('hidden');
    
    // Reset all values to default
    document.getElementById('totalConsultations').textContent = '0';
    document.getElementById('successRate').textContent = '0%';
    document.getElementById('successCount').textContent = '0 จาก 0 รายการ';
    document.getElementById('avgResponseTime').textContent = '0';
    document.getElementById('aiAcceptanceRate').textContent = '0%';
    document.getElementById('aiAcceptanceCount').textContent = '0 จาก 0 คำแนะนำ';
    document.getElementById('totalRevenue').textContent = '฿0';
    document.getElementById('avgMessages').textContent = '0';
    document.getElementById('totalAiSuggestions').textContent = '0';
    document.getElementById('acceptedAiSuggestions').textContent = '0';
    document.getElementById('rejectedAiSuggestions').textContent = '0';
    
    // Reset bars
    ['A', 'B', 'C'].forEach(type => {
        document.getElementById(`type${type}Bar`).style.width = '0%';
        document.getElementById(`type${type}Rate`).textContent = '-';
    });
    
    document.getElementById('fastResponseBar').style.width = '0%';
    document.getElementById('fastResponseRate').textContent = '-';
    document.getElementById('mediumResponseBar').style.width = '0%';
    document.getElementById('mediumResponseRate').textContent = '-';
    document.getElementById('slowResponseBar').style.width = '0%';
    document.getElementById('slowResponseRate').textContent = '-';
}

/**
 * Format number with commas
 * @param {number} num Number to format
 * @returns {string} Formatted number
 */
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
</script>
<?php endif; ?>
