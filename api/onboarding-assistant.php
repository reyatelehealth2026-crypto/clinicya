<?php
/**
 * API: Onboarding Assistant
 * Endpoints for AI Onboarding Assistant
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../modules/Core/Database.php';
require_once __DIR__ . '/../modules/Onboarding/OnboardingAssistant.php';

use Modules\Core\Database;
use Modules\Onboarding\OnboardingAssistant;

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['line_account_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$adminUserId = $_SESSION['admin_user_id'];
$lineAccountId = $_SESSION['line_account_id'];
$adminName = $_SESSION['admin_name'] ?? 'User';

try {
    $db = Database::getInstance()->getConnection();
    $assistant = new OnboardingAssistant($db, $lineAccountId, $adminUserId);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

// Handle requests
switch ($method) {
    case 'GET':
        handleGetRequest($action, $assistant, $adminName);
        break;
    case 'POST':
        handlePostRequest($action, $assistant);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

/**
 * Handle GET requests
 */
function handleGetRequest(string $action, OnboardingAssistant $assistant, string $adminName): void {
    switch ($action) {
        case 'status':
            // Get setup status
            $status = $assistant->getSetupStatus();
            echo json_encode([
                'success' => true,
                'data' => $status
            ]);
            break;
            
        case 'checklist':
            // Get checklist with progress
            $checklist = $assistant->getChecklist();
            echo json_encode([
                'success' => true,
                'data' => $checklist
            ]);
            break;
            
        case 'welcome':
            // Get welcome message
            $message = $assistant->getWelcomeMessage($adminName);
            echo json_encode([
                'success' => true,
                'message' => $message
            ]);
            break;
            
        case 'suggestions':
            // Get contextual suggestions
            $currentPage = $_GET['page'] ?? null;
            $suggestions = $assistant->getSuggestions($currentPage);
            echo json_encode([
                'success' => true,
                'data' => $suggestions
            ]);
            break;
            
        case 'history':
            // Get conversation history
            $history = $assistant->getConversationHistory();
            echo json_encode([
                'success' => true,
                'data' => $history
            ]);
            break;
            
        case 'health':
            // Run health check
            $result = $assistant->runHealthCheck();
            echo json_encode($result);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

/**
 * Handle POST requests
 */
function handlePostRequest(string $action, OnboardingAssistant $assistant): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    switch ($action) {
        case 'chat':
            // Chat with AI
            $message = $input['message'] ?? '';
            $context = $input['context'] ?? [];
            
            if (empty($message)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Message is required']);
                return;
            }
            
            $response = $assistant->chat($message, $context);
            echo json_encode($response);
            break;
            
        case 'execute':
            // Execute quick action
            $actionName = $input['action_name'] ?? '';
            $params = $input['params'] ?? [];
            
            if (empty($actionName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Action name is required']);
                return;
            }
            
            $result = $assistant->executeAction($actionName, $params);
            echo json_encode($result);
            break;
            
        case 'clear_history':
            // Clear conversation history
            $success = $assistant->clearHistory();
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'History cleared' : 'Failed to clear history'
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}
