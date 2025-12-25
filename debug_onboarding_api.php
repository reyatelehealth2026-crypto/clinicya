<?php
/**
 * Debug Onboarding API
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

echo "=== Debug Onboarding API ===\n\n";

// Step 1: Load config
echo "Step 1: Loading config...\n";
try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    echo "Config loaded OK\n";
} catch (Exception $e) {
    echo "Config ERROR: " . $e->getMessage() . "\n";
    exit;
}

// Step 2: Check session
echo "\nStep 2: Checking session...\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Session ID: " . session_id() . "\n";
echo "Session data: " . json_encode($_SESSION, JSON_PRETTY_PRINT) . "\n";

// Step 3: Check auth
echo "\nStep 3: Checking auth...\n";
$adminUserId = $_SESSION['admin_user']['id'] ?? null;
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
echo "Admin User ID: " . ($adminUserId ?? 'NULL') . "\n";
echo "Line Account ID: " . ($lineAccountId ?? 'NULL') . "\n";

if (!$adminUserId || !$lineAccountId) {
    echo "ERROR: Not authenticated\n";
    exit;
}

// Step 4: Check modules
echo "\nStep 4: Checking modules...\n";
$modulePath = __DIR__ . '/modules/Onboarding/OnboardingAssistant.php';
echo "Module path: $modulePath\n";
echo "Module exists: " . (file_exists($modulePath) ? 'YES' : 'NO') . "\n";

if (file_exists($modulePath)) {
    try {
        require_once $modulePath;
        echo "Module loaded OK\n";
    } catch (Exception $e) {
        echo "Module ERROR: " . $e->getMessage() . "\n";
        exit;
    }
}

// Step 5: Create assistant
echo "\nStep 5: Creating assistant...\n";
try {
    $db = Database::getInstance()->getConnection();
    echo "Database connected OK\n";
    
    $assistant = new \Modules\Onboarding\OnboardingAssistant($db, $lineAccountId, $adminUserId);
    echo "Assistant created OK\n";
    
    // Step 6: Test methods
    echo "\nStep 6: Testing methods...\n";
    
    echo "\n--- getSetupStatus() ---\n";
    $status = $assistant->getSetupStatus();
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    echo "\n--- getWelcomeMessage() ---\n";
    $welcome = $assistant->getWelcomeMessage('Test');
    echo $welcome . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack: " . $e->getTraceAsString() . "\n";
}
