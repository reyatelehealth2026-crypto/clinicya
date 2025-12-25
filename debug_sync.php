<?php
/**
 * Debug CNY Sync - ตรวจสอบปัญหา
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Debug Sync</title></head><body>";
echo "<h2>🔍 Debug CNY Sync</h2>";
echo "<pre>";

try {
    echo "=== 1. Loading Config ===\n";
    require_once __DIR__ . '/config/config.php';
    echo "✅ Config loaded\n";
    echo "BASE_URL: " . BASE_URL . "\n\n";
    
    echo "=== 2. Loading Database ===\n";
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance()->getConnection();
    echo "✅ Database connected\n\n";
    
    echo "=== 3. Loading CnyPharmacyAPI ===\n";
    require_once __DIR__ . '/classes/CnyPharmacyAPI.php';
    echo "✅ Class loaded\n\n";
    
    echo "=== 4. Creating API Instance ===\n";
    $cnyApi = new CnyPharmacyAPI($db);
    echo "✅ API instance created\n\n";
    
    echo "=== 5. Testing Connection ===\n";
    $testResult = $cnyApi->testConnection();
    if ($testResult['success']) {
        echo "✅ API Connection OK\n";
        echo "Message: " . ($testResult['message'] ?? 'Connected') . "\n\n";
    } else {
        echo "❌ API Connection Failed\n";
        echo "Error: " . ($testResult['error'] ?? $testResult['message'] ?? 'Unknown') . "\n\n";
        
        // Debug: ทดสอบ API โดยตรง
        echo "=== 5.1 Direct API Test ===\n";
        $apiUrl = 'https://manager.cnypharmacy.com/api/get_product_all';
        $token = '90xcKekelCqCAjmgkpI1saJF6N55eiNexcI4hdcYM2M';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        echo "HTTP Code: {$httpCode}\n";
        echo "Content-Type: {$contentType}\n";
        echo "Response (first 500 chars):\n";
        echo htmlspecialchars(substr($response, 0, 500)) . "\n\n";
        
        if (strpos($response, '<html') !== false || strpos($response, '<!doctype') !== false) {
            echo "<strong style='color:orange'>⚠️ API ตอบกลับเป็น HTML - อาจเป็นหน้า login หรือ error page</strong>\n";
            echo "กรุณาตรวจสอบ:\n";
            echo "1. Token ยังใช้งานได้หรือไม่\n";
            echo "2. API endpoint ถูกต้องหรือไม่\n";
            echo "3. ติดต่อผู้ดูแล CNY API\n\n";
        }
    }
    
    echo "=== 6. Checking Business Items Table ===\n";
    $count = $db->query("SELECT COUNT(*) FROM business_items")->fetchColumn();
    echo "Business items in database: {$count}\n\n";
    
    echo "=== 7. Sample Business Items ===\n";
    $stmt = $db->query("SELECT id, sku, name FROM business_items LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']} | SKU: {$row['sku']} | Name: {$row['name']}\n";
    }
    
    echo "\n<strong style='color:green'>✅ All checks passed!</strong>\n";
    
} catch (Exception $e) {
    echo "\n<strong style='color:red'>❌ Error: " . $e->getMessage() . "</strong>\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<hr>";
echo "<p><a href='sync_cny_batch.php'>Go to Batch Sync</a> | <a href='sync_cny_products.php'>Go to Simple Sync</a></p>";
echo "</body></html>";
?>
