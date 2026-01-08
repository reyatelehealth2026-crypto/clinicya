<?php
/**
 * CSV Import API
 * Import CSV file to cny_products and/or business_items tables
 */

header('Content-Type: application/json');

// Increase limits for large files
set_time_limit(600);
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Debug log
error_log("CSV Import - Action: " . $action);
error_log("CSV Import - POST: " . json_encode($_POST));
error_log("CSV Import - FILES: " . (isset($_FILES['csv_file']) ? $_FILES['csv_file']['name'] : 'none'));

if ($action === 'import_csv') {
    importCsv($db);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action', 'received_action' => $action, 'post_keys' => array_keys($_POST)]);
}

function importCsv($db) {
    // Check file upload
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'No file uploaded';
        if (isset($_FILES['csv_file'])) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (php.ini limit)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file selected',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            ];
            $errorMsg = $errors[$_FILES['csv_file']['error']] ?? 'Upload error';
        }
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        return;
    }
    
    $toCny = !empty($_POST['to_cny']);
    $toBusiness = !empty($_POST['to_business']);
    
    if (!$toCny && !$toBusiness) {
        echo json_encode(['success' => false, 'error' => 'No target table selected']);
        return;
    }
    
    $file = $_FILES['csv_file']['tmp_name'];
    
    // Read CSV
    $handle = fopen($file, 'r');
    if (!$handle) {
        echo json_encode(['success' => false, 'error' => 'Cannot read file']);
        return;
    }
    
    // Detect BOM and skip
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        echo json_encode(['success' => false, 'error' => 'Cannot read CSV header']);
        return;
    }
    
    // Clean header names
    $header = array_map(function($h) {
        return strtolower(trim(preg_replace('/[^\w]/', '_', $h)));
    }, $header);
    
    // Map common column names
    $columnMap = [
        'product_id' => 'id',
        'product_name' => 'name',
        'product_name_en' => 'name_en',
        'product_sku' => 'sku',
        'product_barcode' => 'barcode',
        'product_stock' => 'stock',
        'qty' => 'stock',
        'quantity' => 'stock',
        'photo' => 'photo_path',
        'image' => 'photo_path',
        'spec_name' => 'generic_name',
        'how_to_use' => 'usage_instructions',
        'category' => 'cny_category',
    ];
    
    $header = array_map(function($h) use ($columnMap) {
        return $columnMap[$h] ?? $h;
    }, $header);
    
    $cnyStats = ['inserted' => 0, 'updated' => 0, 'errors' => 0];
    $businessStats = ['inserted' => 0, 'updated' => 0, 'errors' => 0];
    
    $db->beginTransaction();
    
    try {
        $rowNum = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            
            // Skip empty rows
            if (empty(array_filter($row))) continue;
            
            // Create associative array
            $data = [];
            foreach ($header as $i => $col) {
                $data[$col] = $row[$i] ?? '';
            }
            
            // Skip if no SKU
            $sku = $data['sku'] ?? '';
            if (empty($sku)) continue;
            
            // Import to cny_products
            if ($toCny) {
                $result = importToCnyProducts($db, $data);
                if ($result === 'inserted') $cnyStats['inserted']++;
                elseif ($result === 'updated') $cnyStats['updated']++;
                else $cnyStats['errors']++;
            }
            
            // Import to business_items
            if ($toBusiness) {
                $result = importToBusinessItems($db, $data);
                if ($result === 'inserted') $businessStats['inserted']++;
                elseif ($result === 'updated') $businessStats['updated']++;
                else $businessStats['errors']++;
            }
            
            // Free memory periodically
            if ($rowNum % 100 === 0) {
                gc_collect_cycles();
            }
        }
        
        $db->commit();
        fclose($handle);
        
        $response = ['success' => true, 'total_rows' => $rowNum];
        if ($toCny) $response['cny_stats'] = $cnyStats;
        if ($toBusiness) $response['business_stats'] = $businessStats;
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        $db->rollBack();
        fclose($handle);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function importToCnyProducts($db, $data) {
    $sku = $data['sku'];
    
    // Check if exists
    $stmt = $db->prepare("SELECT id FROM cny_products WHERE sku = ?");
    $stmt->execute([$sku]);
    $existing = $stmt->fetch();
    
    // Prepare data
    $fields = [
        'sku' => $sku,
        'barcode' => $data['barcode'] ?? null,
        'name' => $data['name'] ?? '',
        'name_en' => $data['name_en'] ?? null,
        'description' => $data['description'] ?? null,
        'spec_name' => $data['generic_name'] ?? $data['spec_name'] ?? null,
        'how_to_use' => $data['usage_instructions'] ?? $data['how_to_use'] ?? null,
        'properties_other' => $data['properties_other'] ?? null,
        'photo_path' => $data['photo_path'] ?? $data['image_url'] ?? null,
        'qty' => (int)($data['stock'] ?? $data['qty'] ?? 0),
        'qty_incoming' => (int)($data['qty_incoming'] ?? 0),
        'category' => $data['cny_category'] ?? $data['category'] ?? null,
        'hashtag' => $data['hashtag'] ?? null,
        'enable' => isset($data['enable']) ? $data['enable'] : (isset($data['is_active']) ? $data['is_active'] : '1'),
        'product_price' => $data['product_price'] ?? null,
        'last_updated' => date('Y-m-d H:i:s'),
    ];
    
    // Handle ID from CNY
    if (!empty($data['id']) && !empty($data['cny_id'])) {
        $fields['id'] = $data['id'] ?: $data['cny_id'];
    }
    
    try {
        if ($existing) {
            // Update
            $sets = [];
            $values = [];
            foreach ($fields as $col => $val) {
                if ($col === 'id') continue;
                $sets[] = "{$col} = ?";
                $values[] = $val;
            }
            $values[] = $sku;
            
            $sql = "UPDATE cny_products SET " . implode(', ', $sets) . " WHERE sku = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            return 'updated';
        } else {
            // Insert
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            
            $sql = "INSERT INTO cny_products (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($fields));
            return 'inserted';
        }
    } catch (Exception $e) {
        return 'error';
    }
}

function importToBusinessItems($db, $data) {
    $sku = $data['sku'];
    
    // Check if exists
    $stmt = $db->prepare("SELECT id FROM business_items WHERE sku = ?");
    $stmt->execute([$sku]);
    $existing = $stmt->fetch();
    
    // Get price from product_price JSON or price field
    $price = 0;
    $productPriceJson = $data['product_price'] ?? '';
    
    // Debug: log what we're getting
    error_log("SKU: {$sku} - product_price raw: " . substr($productPriceJson, 0, 200));
    
    if (!empty($productPriceJson)) {
        // Handle double-encoded JSON (CSV might escape quotes)
        $priceData = json_decode($productPriceJson, true);
        
        // If still a string, try decoding again
        if (is_string($priceData)) {
            $priceData = json_decode($priceData, true);
        }
        
        if (is_array($priceData) && !empty($priceData)) {
            // Try to find GEN price first
            foreach ($priceData as $p) {
                $group = $p['customer_group'] ?? '';
                if (strpos($group, 'GEN') !== false) {
                    $price = floatval($p['price'] ?? 0);
                    error_log("SKU: {$sku} - Found GEN price: {$price}");
                    break;
                }
            }
            // Fallback to first price
            if ($price == 0 && isset($priceData[0]['price'])) {
                $price = floatval($priceData[0]['price']);
                error_log("SKU: {$sku} - Using first price: {$price}");
            }
        }
    }
    
    // Fallback to direct price field
    if ($price == 0 && !empty($data['price'])) {
        $price = (float)$data['price'];
        error_log("SKU: {$sku} - Using direct price field: {$price}");
    }
    
    // Get unit from product_price
    $unit = '';
    $baseUnit = '';
    if (!empty($productPriceJson)) {
        $priceData = json_decode($productPriceJson, true);
        if (is_string($priceData)) {
            $priceData = json_decode($priceData, true);
        }
        if (is_array($priceData) && !empty($priceData[0]['unit'])) {
            $unit = $priceData[0]['unit'];
            if (preg_match('/^([^\[\s]+)/', $unit, $matches)) {
                $baseUnit = trim($matches[1]);
            }
        }
    }
    if (empty($unit) && !empty($data['unit'])) {
        $unit = $data['unit'];
    }
    
    // Prepare data
    $fields = [
        'sku' => $sku,
        'barcode' => $data['barcode'] ?? null,
        'name' => $data['name'] ?? '',
        'name_en' => $data['name_en'] ?? null,
        'description' => $data['description'] ?? null,
        'generic_name' => $data['generic_name'] ?? $data['spec_name'] ?? null,
        'usage_instructions' => $data['usage_instructions'] ?? $data['how_to_use'] ?? null,
        'properties_other' => $data['properties_other'] ?? null,
        'manufacturer' => $data['manufacturer'] ?? null,
        'price' => $price,
        'stock' => (int)($data['stock'] ?? $data['qty'] ?? 0),
        'image_url' => $data['photo_path'] ?? $data['image_url'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'unit' => $unit,
        'base_unit' => $baseUnit ?: ($data['base_unit'] ?? null),
        'product_price' => $data['product_price'] ?? null,
        'cny_id' => $data['cny_id'] ?? $data['id'] ?? null,
        'cny_category' => $data['cny_category'] ?? $data['category'] ?? null,
        'hashtag' => $data['hashtag'] ?? null,
        'qty_incoming' => (int)($data['qty_incoming'] ?? 0),
        'enable' => isset($data['enable']) ? (int)$data['enable'] : (isset($data['is_active']) ? (int)$data['is_active'] : 1),
        'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : (isset($data['enable']) ? (int)$data['enable'] : 1),
        'last_synced_at' => date('Y-m-d H:i:s'),
    ];
    
    // Check which columns exist
    static $existingCols = null;
    if ($existingCols === null) {
        $existingCols = [];
        $stmt = $db->query("SHOW COLUMNS FROM business_items");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingCols[] = $row['Field'];
        }
    }
    
    // Filter to only existing columns
    $fields = array_filter($fields, function($key) use ($existingCols) {
        return in_array($key, $existingCols);
    }, ARRAY_FILTER_USE_KEY);
    
    try {
        if ($existing) {
            // Update
            $sets = [];
            $values = [];
            foreach ($fields as $col => $val) {
                $sets[] = "{$col} = ?";
                $values[] = $val;
            }
            $values[] = $sku;
            
            $sql = "UPDATE business_items SET " . implode(', ', $sets) . " WHERE sku = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            return 'updated';
        } else {
            // Insert
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            
            $sql = "INSERT INTO business_items (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($fields));
            return 'inserted';
        }
    } catch (Exception $e) {
        error_log("Import error for SKU {$sku}: " . $e->getMessage());
        return 'error';
    }
}