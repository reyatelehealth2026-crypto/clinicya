<?php
/**
 * Debug Inbox V2 API - Test all endpoints
 */

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = 1;

echo "<h1>🔍 Debug Inbox V2 API</h1>";
echo "<style>
body { font-family: sans-serif; padding: 20px; background: #f5f5f5; }
.test { background: white; padding: 15px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.success { border-left: 4px solid #10B981; }
.error { border-left: 4px solid #EF4444; }
.warning { border-left: 4px solid #F59E0B; }
pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
</style>";

// Get a test user
$stmt = $db->prepare("SELECT id, display_name FROM users WHERE line_account_id = ? LIMIT 1");
$stmt->execute([$lineAccountId]);
$testUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$testUser) {
    echo "<div class='test error'><h3>❌ No test user found</h3></div>";
    exit;
}

$userId = $testUser['id'];
echo "<div class='test'><h3>📋 Test User: {$testUser['display_name']} (ID: {$userId})</h3></div>";

// Test 1: Check Services
echo "<div class='test'><h3>1️⃣ Check Services</h3>";

$services = [
    'PharmacyGhostDraftService',
    'ConsultationAnalyzerService', 
    'CustomerHealthEngineService',
    'DrugPricingEngineService',
    'PharmacyImageAnalyzerService',
    'PharmacyIntegrationService'
];

foreach ($services as $service) {
    $file = __DIR__ . '/../classes/' . $service . '.php';
    if (file_exists($file)) {
        require_once $file;
        if (class_exists($service)) {
            try {
                $instance = new $service($db, $lineAccountId);
                $configured = method_exists($instance, 'isConfigured') ? ($instance->isConfigured() ? '✅ Configured' : '⚠️ Not configured') : '✅ Loaded';
                echo "<p>✅ {$service}: {$configured}</p>";
            } catch (Exception $e) {
                echo "<p>⚠️ {$service}: Error - " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p>❌ {$service}: Class not found</p>";
        }
    } else {
        echo "<p>❌ {$service}: File not found</p>";
    }
}
echo "</div>";

// Test 2: Context Widgets API
echo "<div class='test'><h3>2️⃣ Context Widgets API</h3>";

$testMessages = ['', 'ปวดหัว', 'ไข้', 'พาราเซตามอล'];

foreach ($testMessages as $msg) {
    $url = "http://localhost/api/inbox-v2.php?action=context_widgets&user_id={$userId}&message=" . urlencode($msg);
    
    // Direct test
    $_GET['action'] = 'context_widgets';
    $_GET['user_id'] = $userId;
    $_GET['message'] = $msg;
    
    echo "<p><strong>Message:</strong> '" . htmlspecialchars($msg ?: '(empty)') . "'</p>";
    
    try {
        require_once __DIR__ . '/../classes/ConsultationAnalyzerService.php';
        $analyzer = new ConsultationAnalyzerService($db, $lineAccountId);
        
        if (empty($msg)) {
            echo "<pre>Empty message - should return empty widgets</pre>";
        } else {
            $widgets = $analyzer->getContextWidgets($msg, $userId);
            echo "<pre>" . json_encode($widgets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
    }
}
echo "</div>";

// Test 3: Ghost Draft API
echo "<div class='test'><h3>3️⃣ Ghost Draft API</h3>";

try {
    require_once __DIR__ . '/../classes/PharmacyGhostDraftService.php';
    $ghostDraft = new PharmacyGhostDraftService($db, $lineAccountId);
    
    if (!$ghostDraft->isConfigured()) {
        echo "<p class='warning'>⚠️ Ghost Draft not configured (no API key)</p>";
        
        // Check AI settings
        $stmt = $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ? OR line_account_id IS NULL LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($aiSettings) {
            echo "<p>AI Settings found:</p>";
            echo "<pre>";
            echo "- gemini_api_key: " . (empty($aiSettings['gemini_api_key']) ? '❌ Empty' : '✅ Set (' . strlen($aiSettings['gemini_api_key']) . ' chars)') . "\n";
            echo "- model: " . ($aiSettings['model'] ?? 'not set') . "\n";
            echo "</pre>";
        } else {
            echo "<p>❌ No AI settings found in database</p>";
        }
        
        // Check config constant
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            echo "<p>✅ GEMINI_API_KEY constant is set</p>";
        } else {
            echo "<p>❌ GEMINI_API_KEY constant not set</p>";
        }
    } else {
        echo "<p>✅ Ghost Draft configured</p>";
        
        // Try generating a draft
        $testMessage = "ปวดหัวมาก";
        echo "<p>Testing with message: '{$testMessage}'</p>";
        
        $result = $ghostDraft->generateDraft($userId, $testMessage, []);
        echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
echo "</div>";

// Test 4: Customer Health Profile
echo "<div class='test'><h3>4️⃣ Customer Health Profile</h3>";

try {
    require_once __DIR__ . '/../classes/CustomerHealthEngineService.php';
    $healthEngine = new CustomerHealthEngineService($db, $lineAccountId);
    
    $profile = $healthEngine->getHealthProfile($userId);
    echo "<pre>" . json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 5: Drug Info
echo "<div class='test'><h3>5️⃣ Drug Info</h3>";

try {
    // Get a test drug
    $stmt = $db->prepare("SELECT id, name FROM business_items WHERE line_account_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $testDrug = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($testDrug) {
        echo "<p>Test Drug: {$testDrug['name']} (ID: {$testDrug['id']})</p>";
        
        require_once __DIR__ . '/../classes/DrugPricingEngineService.php';
        $pricingEngine = new DrugPricingEngineService($db, $lineAccountId);
        
        $pricing = $pricingEngine->calculateMargin($testDrug['id']);
        echo "<pre>" . json_encode($pricing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    } else {
        echo "<p>❌ No test drug found</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// Test 6: Vibe Selling Settings
echo "<div class='test'><h3>6️⃣ Vibe Selling Settings</h3>";

try {
    require_once __DIR__ . '/../classes/VibeSellingHelper.php';
    $vibeHelper = VibeSellingHelper::getInstance($db);
    
    $v2Enabled = $vibeHelper->isV2Enabled($lineAccountId);
    $autoSwitch = $vibeHelper->isAutoSwitchEnabled($lineAccountId);
    
    echo "<p>V2 Enabled: " . ($v2Enabled ? '✅ Yes' : '❌ No') . "</p>";
    echo "<p>Auto Switch: " . ($autoSwitch ? '✅ Yes' : '❌ No') . "</p>";
    
    // Check vibe_selling_settings table
    $stmt = $db->prepare("SELECT * FROM vibe_selling_settings WHERE line_account_id = ? OR line_account_id IS NULL");
    $stmt->execute([$lineAccountId]);
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($settings) {
        echo "<p>Settings:</p><pre>";
        foreach ($settings as $s) {
            echo "- {$s['setting_key']}: {$s['setting_value']}\n";
        }
        echo "</pre>";
    }
} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
}
echo "</div>";

echo "<hr><p><a href='/inbox-v2.php?user={$userId}'>→ Go to Inbox V2 with test user</a></p>";
echo "<p><a href='/dev-dashboard.php'>→ Go to Dev Dashboard</a></p>";
