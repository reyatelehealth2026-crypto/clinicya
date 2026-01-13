<?php
/**
 * Debug HUD API endpoints
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug HUD API</h2>";

// Test 1: Check business_items table
echo "<h3>1. Check business_items table</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM business_items WHERE is_active = 1 AND stock > 0");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Active items with stock: " . $result['cnt'] . "</p>";
    
    // Get sample items
    $stmt = $db->query("SELECT id, name, sku, price, stock FROM business_items WHERE is_active = 1 AND stock > 0 LIMIT 5");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($items, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 2: Test recommendations API directly
echo "<h3>2. Test recommendations API</h3>";
$testUserId = 1;

$sql = "
    SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, 
           bi.stock, bi.description, bi.image_url,
           ic.name as category
    FROM business_items bi
    LEFT JOIN item_categories ic ON bi.category_id = ic.id
    WHERE bi.is_active = 1 
    AND bi.stock > 0
    ORDER BY bi.stock DESC, bi.name ASC 
    LIMIT 5
";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $recommendations = [];
    foreach ($drugs as $drug) {
        $recommendations[] = [
            'id' => (int)$drug['id'],
            'drugId' => (int)$drug['id'],
            'name' => $drug['name'],
            'sku' => $drug['sku'],
            'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0),
            'originalPrice' => (float)($drug['price'] ?? 0),
            'stock' => (int)($drug['stock'] ?? 0),
            'category' => $drug['category'] ?? 'ยาทั่วไป',
            'description' => $drug['description'],
            'imageUrl' => $drug['image_url']
        ];
    }
    
    echo "<p>Found " . count($recommendations) . " recommendations</p>";
    echo "<pre>" . json_encode($recommendations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 3: Check dev_logs table structure
echo "<h3>3. Check dev_logs table</h3>";
try {
    $stmt = $db->query("DESCRIBE dev_logs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($columns, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 4: Check CustomerHealthEngineService
echo "<h3>4. Check CustomerHealthEngineService</h3>";
$classFile = __DIR__ . '/../classes/CustomerHealthEngineService.php';
if (file_exists($classFile)) {
    echo "<p style='color:green'>File exists</p>";
    require_once $classFile;
    if (class_exists('CustomerHealthEngineService')) {
        echo "<p style='color:green'>Class exists</p>";
        try {
            $service = new CustomerHealthEngineService($db, 1);
            $profile = $service->getHealthProfile($testUserId);
            echo "<pre>" . json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        } catch (Exception $e) {
            echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color:red'>Class not found</p>";
    }
} else {
    echo "<p style='color:red'>File not found</p>";
}

echo "<h3>5. Direct API Test URL</h3>";
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$apiUrl = $baseUrl . "/api/inbox-v2.php?action=recommendations&user_id=1&type=context";
echo "<p>Test URL: <a href='$apiUrl' target='_blank'>$apiUrl</a></p>";
