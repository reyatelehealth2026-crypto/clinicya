<?php
/**
 * Inbox API - Handle inbox actions
 * 
 * Endpoints:
 * - GET  /conversations - Get paginated conversations with filters
 * - GET  /messages      - Get paginated messages for a conversation
 * - POST /templates     - CRUD operations for quick reply templates
 * - POST /assignments   - Assign conversations to admins
 * - POST /notes         - CRUD operations for customer notes
 * - GET  /analytics     - Get response time statistics
 * - POST /send          - Send message (with analytics recording)
 * 
 * Requirements: 2.4, 3.1, 4.5, 5.1, 5.2, 5.3, 5.4, 6.1, 6.2, 6.4, 11.3
 */

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/InboxService.php';
    require_once __DIR__ . '/../classes/TemplateService.php';
    require_once __DIR__ . '/../classes/AnalyticsService.php';
    require_once __DIR__ . '/../classes/CustomerNoteService.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to load dependencies: ' . $e->getMessage()]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Get LINE account ID from session or request
$lineAccountId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1;
$adminId = $_SESSION['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ?? null;

// Initialize services
try {
    $inboxService = new InboxService($db, (int)$lineAccountId);
    $templateService = new TemplateService($db, (int)$lineAccountId);
    $analyticsService = new AnalyticsService($db, (int)$lineAccountId);
    $noteService = new CustomerNoteService($db);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to initialize services: ' . $e->getMessage()]);
    exit;
}

try {
    // Route based on action parameter
    switch ($action) {
        // ============================================
        // GET /conversations - Paginated list with filters
        // Requirements: 5.1, 5.2, 5.3, 5.4
        // ============================================
        case 'get_conversations':
        case 'conversations':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $filters = [];
            
            // Status filter (unread, assigned, resolved)
            if (!empty($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            
            // Tag filter
            if (!empty($_GET['tag_id'])) {
                $filters['tag_id'] = (int)$_GET['tag_id'];
            }
            
            // Assigned to filter
            if (!empty($_GET['assigned_to'])) {
                $filters['assigned_to'] = (int)$_GET['assigned_to'];
            }
            
            // Search filter
            if (!empty($_GET['search'])) {
                $filters['search'] = $_GET['search'];
            }
            
            // Date range filters
            if (!empty($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (!empty($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }
            
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
            
            $result = $inboxService->getConversations($filters, $page, $limit);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /messages - Paginated messages for conversation
        // Requirements: 11.3
        // ============================================
        case 'get_messages':
        case 'messages':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_GET['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
            
            $result = $inboxService->getMessages($userId, $page, $limit);
            
            // Mark messages as read when fetching
            $inboxService->markAsRead($userId);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        // ============================================
        // Search messages across conversations
        // Requirements: 5.1
        // ============================================
        case 'search':
        case 'search_messages':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $query = $_GET['query'] ?? $_GET['q'] ?? '';
            if (empty(trim($query))) {
                throw new Exception('Search query is required');
            }
            
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
            
            $results = $inboxService->searchMessages($query, $limit);
            
            echo json_encode([
                'success' => true,
                'data' => $results
            ]);
            break;

        // ============================================
        // POST /templates - CRUD operations
        // Requirements: 2.4
        // ============================================
        case 'get_templates':
        case 'templates':
            if ($method === 'GET') {
                $search = $_GET['search'] ?? '';
                $templates = $templateService->getTemplates($search);
                
                echo json_encode([
                    'success' => true,
                    'data' => $templates
                ]);
            } else {
                throw new Exception('Use specific template actions for POST operations');
            }
            break;
            
        case 'create_template':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $name = $_POST['name'] ?? '';
            $content = $_POST['content'] ?? '';
            $category = $_POST['category'] ?? '';
            
            if (empty($name) || empty($content)) {
                throw new Exception('Template name and content are required');
            }
            
            $templateId = $templateService->createTemplate($name, $content, $category, $adminId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Template created successfully',
                'data' => ['id' => $templateId]
            ]);
            break;
            
        case 'update_template':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $templateId = (int)($_POST['id'] ?? 0);
            if (!$templateId) {
                throw new Exception('Template ID is required');
            }
            
            $data = [];
            if (isset($_POST['name'])) $data['name'] = $_POST['name'];
            if (isset($_POST['content'])) $data['content'] = $_POST['content'];
            if (isset($_POST['category'])) $data['category'] = $_POST['category'];
            
            $success = $templateService->updateTemplate($templateId, $data);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Template updated successfully' : 'Template not found'
            ]);
            break;
            
        case 'delete_template':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $templateId = (int)($_POST['id'] ?? 0);
            if (!$templateId) {
                throw new Exception('Template ID is required');
            }
            
            $success = $templateService->deleteTemplate($templateId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Template deleted successfully' : 'Template not found'
            ]);
            break;
            
        case 'use_template':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $templateId = (int)($_POST['id'] ?? 0);
            if (!$templateId) {
                throw new Exception('Template ID is required');
            }
            
            // Get template
            $template = $templateService->getById($templateId);
            if (!$template) {
                throw new Exception('Template not found');
            }
            
            // Fill placeholders if customer data provided
            $content = $template['content'];
            if (!empty($_POST['customer_data'])) {
                $customerData = is_array($_POST['customer_data']) 
                    ? $_POST['customer_data'] 
                    : json_decode($_POST['customer_data'], true);
                $content = $templateService->fillPlaceholders($content, $customerData ?? []);
            }
            
            // Record usage
            $templateService->recordUsage($templateId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'content' => $content,
                    'original' => $template['content']
                ]
            ]);
            break;

        // ============================================
        // POST /assignments - Assign conversations
        // Requirements: 3.1
        // ============================================
        case 'assign':
        case 'assign_conversation':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_POST['user_id'] ?? 0);
            $assignTo = (int)($_POST['assign_to'] ?? $_POST['admin_id'] ?? 0);
            
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            if (!$assignTo) {
                throw new Exception('Admin ID to assign is required');
            }
            
            $success = $inboxService->assignConversation($userId, $assignTo, $adminId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Conversation assigned successfully' : 'Failed to assign conversation'
            ]);
            break;
            
        case 'unassign':
        case 'unassign_conversation':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $success = $inboxService->unassignConversation($userId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Conversation unassigned successfully' : 'Failed to unassign conversation'
            ]);
            break;
            
        case 'resolve':
        case 'resolve_conversation':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $success = $inboxService->resolveConversation($userId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Conversation resolved successfully' : 'Failed to resolve conversation'
            ]);
            break;
            
        case 'get_assignment':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_GET['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $assignment = $inboxService->getAssignment($userId);
            
            echo json_encode([
                'success' => true,
                'data' => $assignment
            ]);
            break;
            
        case 'get_assigned':
        case 'my_assignments':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $targetAdminId = (int)($_GET['admin_id'] ?? $adminId ?? 0);
            if (!$targetAdminId) {
                throw new Exception('Admin ID is required');
            }
            
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
            
            $result = $inboxService->getAssignedConversations($targetAdminId, $page, $limit);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /notes - Customer notes CRUD
        // Requirements: 4.5
        // ============================================
        case 'get_notes':
        case 'notes':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_GET['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $notes = $noteService->getNotes($userId);
            
            echo json_encode([
                'success' => true,
                'data' => $notes
            ]);
            break;
            
        case 'add_note':
        case 'create_note':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_POST['user_id'] ?? 0);
            $note = $_POST['note'] ?? '';
            $isPinned = filter_var($_POST['is_pinned'] ?? false, FILTER_VALIDATE_BOOLEAN);
            
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            if (empty(trim($note))) {
                throw new Exception('Note content is required');
            }
            if (!$adminId) {
                throw new Exception('Admin ID is required');
            }
            
            $noteId = $noteService->addNote($userId, (int)$adminId, $note, $isPinned);
            
            echo json_encode([
                'success' => true,
                'message' => 'Note added successfully',
                'data' => ['id' => $noteId]
            ]);
            break;
            
        case 'update_note':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $noteId = (int)($_POST['id'] ?? 0);
            if (!$noteId) {
                throw new Exception('Note ID is required');
            }
            
            $data = [];
            if (isset($_POST['note'])) $data['note'] = $_POST['note'];
            if (isset($_POST['is_pinned'])) $data['is_pinned'] = filter_var($_POST['is_pinned'], FILTER_VALIDATE_BOOLEAN);
            
            $success = $noteService->updateNote($noteId, $data);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Note updated successfully' : 'Note not found'
            ]);
            break;
            
        case 'delete_note':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $noteId = (int)($_POST['id'] ?? 0);
            if (!$noteId) {
                throw new Exception('Note ID is required');
            }
            
            $success = $noteService->deleteNote($noteId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Note deleted successfully' : 'Note not found'
            ]);
            break;
            
        case 'toggle_pin':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $noteId = (int)($_POST['id'] ?? 0);
            if (!$noteId) {
                throw new Exception('Note ID is required');
            }
            
            $success = $noteService->togglePin($noteId);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Pin status toggled successfully' : 'Note not found'
            ]);
            break;

        // ============================================
        // GET /analytics - Response time stats
        // Requirements: 6.1, 6.2
        // ============================================
        case 'get_analytics':
        case 'analytics':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $period = $_GET['period'] ?? 'day';
            $slaSeconds = (int)($_GET['sla_seconds'] ?? 3600); // Default 1 hour SLA
            
            $avgResponseTime = $analyticsService->getAverageResponseTime($period);
            $stats = $analyticsService->getResponseTimeStats($period);
            $slaCompliance = $analyticsService->getSLAComplianceRate($slaSeconds, $period);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'average_response_time' => $avgResponseTime,
                    'stats' => $stats,
                    'sla_compliance' => $slaCompliance
                ]
            ]);
            break;
            
        case 'get_sla_violations':
        case 'sla_violations':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $slaSeconds = (int)($_GET['sla_seconds'] ?? 3600); // Default 1 hour SLA
            
            $violations = $analyticsService->getConversationsExceedingSLA($slaSeconds);
            
            echo json_encode([
                'success' => true,
                'data' => $violations
            ]);
            break;
            
        case 'get_response_trends':
        case 'response_trends':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
            
            $trends = $analyticsService->getResponseTimeTrends($days);
            
            echo json_encode([
                'success' => true,
                'data' => $trends
            ]);
            break;
            
        case 'get_messages_per_day':
        case 'messages_per_day':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
            
            $messagesPerDay = $analyticsService->getMessagesPerDay($days);
            
            echo json_encode([
                'success' => true,
                'data' => $messagesPerDay
            ]);
            break;
            
        case 'get_admin_performance':
        case 'admin_performance':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $period = $_GET['period'] ?? 'day';
            
            $performance = $analyticsService->getAdminPerformance($period);
            
            echo json_encode([
                'success' => true,
                'data' => $performance
            ]);
            break;
            
        case 'get_time_since_last':
        case 'time_since_last':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_GET['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $seconds = $analyticsService->getTimeSinceLastMessage($userId);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'seconds' => $seconds,
                    'formatted' => formatTimeSince($seconds)
                ]
            ]);
            break;

        // ============================================
        // Send message with analytics recording
        // Requirements: 6.4
        // ============================================
        case 'send_message':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_POST['user_id'] ?? 0);
            $content = $_POST['content'] ?? '';
            $messageType = $_POST['message_type'] ?? 'text';
            
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            if (empty(trim($content))) {
                throw new Exception('Message content is required');
            }
            
            // Insert the message
            $stmt = $db->prepare("
                INSERT INTO messages 
                (line_account_id, user_id, direction, message_type, content, sent_by, created_at)
                VALUES (?, ?, 'outgoing', ?, ?, ?, NOW())
            ");
            $stmt->execute([$lineAccountId, $userId, $messageType, $content, $adminId]);
            $messageId = (int)$db->lastInsertId();
            
            // Record response time for analytics
            // Requirements: 6.4 - Record response time when admin responds
            if ($messageId && $adminId) {
                $analyticsService->recordResponseTime($messageId, $userId, (int)$adminId);
            }
            
            // Update user's last interaction
            $updateStmt = $db->prepare("UPDATE users SET last_interaction = NOW() WHERE id = ?");
            $updateStmt->execute([$userId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => ['id' => $messageId]
            ]);
            break;

        // ============================================
        // Utility endpoints
        // ============================================
        case 'get_counts':
        case 'counts':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $counts = $inboxService->getConversationCounts();
            
            echo json_encode([
                'success' => true,
                'data' => $counts
            ]);
            break;
            
        case 'mark_read':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }
            
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                throw new Exception('User ID is required');
            }
            
            $success = $inboxService->markAsRead($userId);
            
            echo json_encode([
                'success' => $success,
                'message' => 'Messages marked as read'
            ]);
            break;
            
        case 'get_unread_count':
        case 'unread_count':
            if ($method !== 'GET') {
                throw new Exception('Method not allowed', 405);
            }
            
            $count = $inboxService->getUnreadCount();
            
            echo json_encode([
                'success' => true,
                'data' => ['count' => $count]
            ]);
            break;

        // ============================================
        // Legacy actions (backward compatibility)
        // ============================================
        case 'toggle_notifications':
        case 'toggle_notification':
            $userId = intval($_POST['user_id'] ?? 0);
            $enabled = filter_var($_POST['enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            
            if (!$userId) throw new Exception('User ID required');
            
            // Check if column exists, if not create it
            try {
                $db->query("SELECT notifications_enabled FROM users LIMIT 1");
            } catch (PDOException $e) {
                $db->exec("ALTER TABLE users ADD COLUMN notifications_enabled TINYINT(1) DEFAULT 1");
            }
            
            $stmt = $db->prepare("UPDATE users SET notifications_enabled = ? WHERE id = ?");
            $stmt->execute([$enabled, $userId]);
            
            echo json_encode(['success' => true, 'enabled' => (bool)$enabled]);
            break;
            
        case 'toggle_mute':
            $userId = intval($_POST['user_id'] ?? 0);
            $muted = filter_var($_POST['muted'] ?? '0', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            
            if (!$userId) throw new Exception('User ID required');
            
            // Check if column exists, if not create it
            try {
                $db->query("SELECT is_muted FROM users LIMIT 1");
            } catch (PDOException $e) {
                $db->exec("ALTER TABLE users ADD COLUMN is_muted TINYINT(1) DEFAULT 0");
            }
            
            $stmt = $db->prepare("UPDATE users SET is_muted = ? WHERE id = ?");
            $stmt->execute([$muted, $userId]);
            
            echo json_encode(['success' => true, 'muted' => (bool)$muted]);
            break;
            
        case 'block_user':
            $userId = intval($_POST['user_id'] ?? 0);
            
            if (!$userId) throw new Exception('User ID required');
            
            // Check if column exists, if not create it
            try {
                $db->query("SELECT is_blocked FROM users LIMIT 1");
            } catch (PDOException $e) {
                $db->exec("ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) DEFAULT 0");
            }
            
            $stmt = $db->prepare("UPDATE users SET is_blocked = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            
            echo json_encode(['success' => true, 'message' => 'User blocked']);
            break;
            
        case 'unblock_user':
            $userId = intval($_POST['user_id'] ?? 0);
            
            if (!$userId) throw new Exception('User ID required');
            
            $stmt = $db->prepare("UPDATE users SET is_blocked = 0 WHERE id = ?");
            $stmt->execute([$userId]);
            
            echo json_encode(['success' => true, 'message' => 'User unblocked']);
            break;
        
        // ============================================
        // Product Search - /p command
        // ============================================
        case 'search_products':
            $query = trim($_GET['q'] ?? '');
            
            if (strlen($query) < 2) {
                echo json_encode(['success' => false, 'error' => 'Query too short']);
                break;
            }
            
            $searchTerm = '%' . $query . '%';
            
            try {
                $stmt = $db->prepare("
                    SELECT 
                        id, name, sku, price, 
                        description, image_url
                    FROM business_items 
                    WHERE is_active = 1 
                    AND (line_account_id = ? OR line_account_id IS NULL)
                    AND (
                        name LIKE ? 
                        OR sku LIKE ?
                    )
                    ORDER BY 
                        CASE WHEN sku = ? THEN 0 ELSE 1 END,
                        name ASC
                    LIMIT 20
                ");
                $stmt->execute([
                    $lineAccountId, 
                    $searchTerm, 
                    $searchTerm,
                    $query
                ]);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'products' => $products,
                    'count' => count($products)
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    $code = $e->getCode();
    if ($code >= 400 && $code < 600) {
        http_response_code($code);
    } else {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Format seconds into human-readable time
 * 
 * @param int $seconds
 * @return string
 */
function formatTimeSince(int $seconds): string {
    if ($seconds < 0) {
        return 'N/A';
    }
    
    if ($seconds < 60) {
        return $seconds . ' วินาที';
    }
    
    if ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        return $minutes . ' นาที';
    }
    
    if ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        return $hours . ' ชั่วโมง';
    }
    
    $days = floor($seconds / 86400);
    return $days . ' วัน';
}
