<?php
/**
 * Test Code Flow - ทดสอบว่า code flow ใน webhook.php ทำงานถูกต้องหรือไม่
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Test Code Flow</h2>";

// Simulate checkAIChatbot function
$text = "/test hello";
$lineAccountId = 3;
$userId = 15;

echo "<h3>Input:</h3>";
echo "<pre>";
echo "text: $text\n";
echo "lineAccountId: $lineAccountId\n";
echo "userId: $userId\n";
echo "</pre>";

echo "<h3>Code Flow:</h3>";

$textLower = mb_strtolower(trim($text));
$originalText = trim($text);
$cleanText = preg_replace('/^[`\'"\s]+/', '', $originalText);

echo "1. cleanText: $cleanText<br>";

$commandMode = null;
$commandMessage = $originalText;

// Check command pattern
if (preg_match('/^[\/\@]([\w\p{Thai}]+)\s*(.*)/u', $cleanText, $matches)) {
    $command = mb_strtolower($matches[1]);
    $commandMessage = trim($matches[2]);
    
    echo "2. Command matched: $command<br>";
    echo "3. commandMessage: $commandMessage<br>";
    
    $commandMap = [
        'ai' => 'auto',
        'sales' => 'sales',
        'test' => null, // unknown command
    ];
    
    if (isset($commandMap[$command])) {
        $commandMode = $commandMap[$command];
        echo "4. commandMode from map: $commandMode<br>";
    } else {
        // Unknown command
        try {
            $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
            $stmt->execute([$lineAccountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $commandMode = ($result && $result['ai_mode']) ? $result['ai_mode'] : 'sales';
        } catch (Exception $e) {
            $commandMode = 'sales';
        }
        $commandMessage = $command . ($commandMessage ? ' ' . $commandMessage : '');
        echo "4. Unknown command - commandMode from DB: $commandMode<br>";
        echo "5. commandMessage updated: $commandMessage<br>";
    }
} else {
    echo "2. No command pattern matched<br>";
}

echo "<br><strong>After command parsing:</strong><br>";
echo "commandMode: " . ($commandMode ?? 'null') . "<br>";
echo "commandMessage: $commandMessage<br>";

// Check AI mode from user
echo "<br><strong>Check saved AI mode:</strong><br>";
if (!$commandMode && $userId) {
    try {
        $stmt = $db->prepare("SELECT ai_mode FROM ai_user_mode WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['ai_mode']) {
            $commandMode = $result['ai_mode'];
            echo "Using saved AI mode: $commandMode<br>";
        } else {
            echo "No saved AI mode<br>";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "<br>";
    }
} else {
    echo "commandMode already set or no userId<br>";
}

echo "<br><strong>Final commandMode:</strong> " . ($commandMode ?? 'null') . "<br>";

// Check which AI to use
echo "<br><h3>AI Selection:</h3>";

$currentAIMode = 'sales'; // default

if (in_array($commandMode, ['sales', 'support'])) {
    $currentAIMode = $commandMode;
    echo "Using command mode: $currentAIMode<br>";
} else {
    try {
        $stmt = $db->prepare("SELECT ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['ai_mode']) {
            $currentAIMode = $result['ai_mode'];
        }
    } catch (Exception $e) {}
    echo "Using AI mode from settings: $currentAIMode<br>";
}

echo "<br><strong>Final currentAIMode:</strong> $currentAIMode<br>";

// Check condition
echo "<br><h3>Condition Check:</h3>";
$isSalesOrSupport = ($currentAIMode === 'sales' || $currentAIMode === 'support');
$geminiExists = file_exists(__DIR__ . '/../classes/GeminiChat.php');

echo "currentAIMode === 'sales' || 'support': " . ($isSalesOrSupport ? 'YES' : 'NO') . "<br>";
echo "GeminiChat.php exists: " . ($geminiExists ? 'YES' : 'NO') . "<br>";

if ($isSalesOrSupport && $geminiExists) {
    echo "<br><strong style='color:green'>→ Should use GeminiChat (Sales Mode)</strong><br>";
} else {
    echo "<br><strong style='color:red'>→ Will use PharmacyAI</strong><br>";
}

// Check PharmacyAI condition
$isPharmacist = ($currentAIMode === 'pharmacist' || $currentAIMode === 'pharmacy');
$pharmacyExists = file_exists(__DIR__ . '/../modules/AIChat/Adapters/PharmacyAIAdapter.php');

echo "<br>currentAIMode === 'pharmacist' || 'pharmacy': " . ($isPharmacist ? 'YES' : 'NO') . "<br>";
echo "PharmacyAIAdapter.php exists: " . ($pharmacyExists ? 'YES' : 'NO') . "<br>";

if ($isPharmacist && $pharmacyExists) {
    echo "<br><strong style='color:blue'>→ Should use PharmacyAI</strong><br>";
}

echo "<br><h3>Done</h3>";
